<?php

declare(strict_types=1);

namespace KK\TelegramAccess;

/**
 * The lifecycle of access: payment → invite → member → expiry →
 * removal → return.
 *
 * The payment provider does not appear in this class. Its only job is
 * to say "this customer is entitled until this date" — from there on,
 * access expires or renews according to logic that lives here.
 */
final class AccessManager
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly MemberStorage $store,
        private readonly string $chatId,
        /** Grace period after expiry. A payment provider rarely calls a
         *  failed renewal on the first try — it retries the charge for
         *  days, and that buys time. */
        private readonly int $graceDays = 3,
    ) {
    }

    /**
     * A pairing code for a payment.
     *
     * This is the least obvious part of the system: at the payment
     * provider the customer has an email address, in Telegram they have
     * a numeric ID, and nothing connects the two on its own. The
     * customer starts the bot with this code
     * (https://t.me/BOTNAME?start=CODE), and on start the bot receives
     * the code AND the user's ID together.
     */
    public function createPairingCode(string $paymentReference, \DateTimeImmutable $paidUntil): string
    {
        $code = bin2hex(random_bytes(16));
        $this->store->savePairingCode($code, $paymentReference, $paidUntil);
        return $code;
    }

    /**
     * Called by the bot's /start handler when the customer opens the link.
     *
     * @return string the personal invite link
     */
    public function redeemPairingCode(string $code, int $telegramUserId): string
    {
        $pairing = $this->store->consumePairingCode($code);
        if ($pairing === null) {
            throw new TelegramException('Unknown or already used code.');
        }

        $this->store->upsertMember(
            $telegramUserId,
            $pairing['payment_reference'],
            $pairing['paid_until']
        );

        return $this->telegram->createSingleUseInvite(
            $this->chatId,
            name: 'sub-' . substr($pairing['payment_reference'], -8)
        );
    }

    /** On renewal only the date moves — no invite needed, they are in. */
    public function extend(string $paymentReference, \DateTimeImmutable $paidUntil): void
    {
        $this->store->extendByReference($paymentReference, $paidUntil);
    }

    /**
     * Remove expired members. Run it on a schedule, once a day.
     *
     * @return int how many members were removed
     */
    public function removeExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): int
    {
        $deadline = $now->modify("-{$this->graceDays} days");
        $removed = 0;

        foreach ($this->store->expiredBefore($deadline) as $member) {
            try {
                $this->telegram->removeButAllowReturn($this->chatId, $member['telegram_user_id']);
                $this->store->markRemoved($member['telegram_user_id']);
                $removed++;
            } catch (TelegramException $e) {
                // One failed removal must not stop the rest. Log it; the
                // next run tries again.
                error_log(sprintf(
                    'Removal failed (user %d): %s',
                    $member['telegram_user_id'],
                    $e->getMessage()
                ));
            }
        }

        return $removed;
    }
}
