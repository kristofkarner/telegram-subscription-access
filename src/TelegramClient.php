<?php

declare(strict_types=1);

namespace KK\TelegramAccess;

/**
 * A thin wrapper over the Telegram Bot API.
 *
 * It contains only the four calls paid access needs. Deliberately
 * dependency-free, so it can be dropped into anything — it is not tied
 * to a framework or to WordPress.
 *
 * Not final, so it can be replaced in tests. Trying the removal path on
 * a live group is not a good way to find out whether it works.
 */
class TelegramClient
{
    private const BASE = 'https://api.telegram.org/bot';

    public function __construct(
        private readonly string $token,
        private readonly int $timeout = 15,
    ) {
    }

    /**
     * A single-use invite link.
     *
     * `member_limit = 1` is the point: the link is good for exactly one
     * join, so there is nothing to pass on. A shared, reusable invite
     * opens the entire channel with a single screenshot — it has no
     * place in paid access.
     */
    public function createSingleUseInvite(
        string $chatId,
        int $expiresInSeconds = 86400,
        ?string $name = null,
    ): string {
        $result = $this->call('createChatInviteLink', array_filter([
            'chat_id' => $chatId,
            'member_limit' => 1,
            'expire_date' => time() + $expiresInSeconds,
            'name' => $name,
        ], static fn ($v) => $v !== null));

        return $result['invite_link'];
    }

    /**
     * Removal that still lets the member come back.
     *
     * These two calls together are what "please leave" means.
     * `banChatMember` on its own means "leave, and don't come back" — a
     * banned user cannot rejoin, not even with a fresh invite. It is a
     * default nobody notices, and it closes the door on every returning
     * customer.
     *
     * `only_if_banned = true` on the second call is not decoration: if
     * `unbanChatMember` is ever called on someone who is currently a
     * member, it removes them. With the flag, it does nothing unless a
     * ban is actually in force.
     */
    public function removeButAllowReturn(string $chatId, int $userId): void
    {
        $this->call('banChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            // A short until_date is not enough on its own: ban expiry
            // does not behave identically across chat types, so the ban
            // is lifted explicitly as well.
            'until_date' => time() + 60,
        ]);

        $this->call('unbanChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'only_if_banned' => true,
        ]);
    }

    /**
     * Tells you whether one specific person is a member.
     *
     * The Bot API does not hand out the full member list — there is no
     * call for it. That is why the system needs its own record of who
     * joined and until when: "who is in right now?" is answered by that
     * table, not by Telegram.
     */
    public function isMember(string $chatId, int $userId): bool
    {
        $result = $this->call('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);

        return in_array(
            $result['status'] ?? '',
            ['creator', 'administrator', 'member', 'restricted'],
            true
        );
    }

    /** @param array<string,mixed> $params */
    private function call(string $method, array $params): array
    {
        $ch = curl_init(self::BASE . $this->token . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_POSTFIELDS => http_build_query($params),
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new TelegramException("Telegram is unreachable: {$error}");
        }
        curl_close($ch);

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new TelegramException('Unreadable response from Telegram.');
        }
        if (($decoded['ok'] ?? false) !== true) {
            throw new TelegramException(sprintf(
                'Telegram error (%s): %s',
                $decoded['error_code'] ?? '?',
                $decoded['description'] ?? 'unknown'
            ));
        }

        $result = $decoded['result'] ?? null;

        // Most calls return an object; a few return just `true`.
        return is_array($result) ? $result : ['result' => $result];
    }
}
