<?php

declare(strict_types=1);

/*
 * A check that needs neither a network nor a database.
 *
 * What is under test is the lifecycle of access: pairing, single use,
 * grace period, removal. The store is kept in memory and the Telegram
 * calls are merely recorded — trying the removal path on a live group
 * is not a good way to find out whether it works.
 *
 *   php test.php
 */

require __DIR__ . '/src/TelegramException.php';
require __DIR__ . '/src/TelegramClient.php';
require __DIR__ . '/src/MemberStorage.php';
require __DIR__ . '/src/MemberStore.php';
require __DIR__ . '/src/AccessManager.php';

use KK\TelegramAccess\{AccessManager, MemberStorage, TelegramClient, TelegramException};

/** MemberStore in memory — same surface, no SQL. */
final class ArrayStore implements MemberStorage
{
    /** @var array<string,array{ref:string,until:DateTimeImmutable}> */
    public array $codes = [];
    /** @var array<int,array{ref:string,until:DateTimeImmutable,removed:bool}> */
    public array $members = [];

    public function savePairingCode(string $code, string $ref, DateTimeImmutable $until): void
    {
        $this->codes[$code] = ['ref' => $ref, 'until' => $until];
    }

    public function consumePairingCode(string $code): ?array
    {
        if (!isset($this->codes[$code])) {
            return null;
        }
        $row = $this->codes[$code];
        unset($this->codes[$code]);   // single use

        return ['payment_reference' => $row['ref'], 'paid_until' => $row['until']];
    }

    public function upsertMember(int $userId, string $ref, DateTimeImmutable $until): void
    {
        $this->members[$userId] = ['ref' => $ref, 'until' => $until, 'removed' => false];
    }

    public function extendByReference(string $ref, DateTimeImmutable $until): void
    {
        foreach ($this->members as $id => $m) {
            if ($m['ref'] === $ref) {
                $this->members[$id]['until'] = $until;
            }
        }
    }

    public function expiredBefore(DateTimeImmutable $deadline): array
    {
        $out = [];
        foreach ($this->members as $id => $m) {
            if (!$m['removed'] && $m['until'] < $deadline) {
                $out[] = ['telegram_user_id' => $id];
            }
        }
        return $out;
    }

    public function markRemoved(int $userId): void
    {
        $this->members[$userId]['removed'] = true;
    }
}

/** Telegram stand-in: records what would have been called. */
final class FakeTelegram extends TelegramClient
{
    /** @var list<int> */
    public array $removed = [];

    public function __construct()
    {
        parent::__construct('test-token');
    }

    public function createSingleUseInvite(string $chatId, int $expiresInSeconds = 86400, ?string $name = null): string
    {
        return 'https://t.me/+test-' . substr(md5((string) $name), 0, 8);
    }

    public function removeButAllowReturn(string $chatId, int $userId): void
    {
        $this->removed[] = $userId;
    }
}

$fail = static function (string $message): never {
    fwrite(STDERR, "FAILED: {$message}\n");
    exit(1);
};

$tg = new FakeTelegram();
$store = new ArrayStore();
$access = new AccessManager($tg, $store, '-1001234567890', graceDays: 3);

// 1 · Pairing: the code connects the payment to the Telegram account
$code = $access->createPairingCode('pay_abc123', new DateTimeImmutable('+30 days'));
strlen($code) === 32 || $fail('the code is not 32 characters');
$invite = $access->redeemPairingCode($code, 111222333);
str_starts_with($invite, 'https://t.me/') || $fail('no invite link returned');
isset($store->members[111222333]) || $fail('the member was not recorded');
echo "pairing OK — invite: {$invite}\n";

// 2 · The code is single-use: passing the link on opens no door
try {
    $access->redeemPairingCode($code, 999888777);
    $fail('the code worked a second time');
} catch (TelegramException) {
    echo "second use rejected OK\n";
}

// 3 · A paying member is not removed
$access->removeExpired() === 0 || $fail('removed a valid member');
echo "valid member stays OK\n";

// 4 · Expired but WITHIN the grace period: still stays
$store->upsertMember(444555666, 'pay_yesterday', new DateTimeImmutable('-1 day'));
$access->removeExpired() === 0 || $fail('removed inside the grace period');
echo "grace period respected OK\n";

// 5 · Grace period is over: now they go
$store->upsertMember(777888999, 'pay_old', new DateTimeImmutable('-10 days'));
$access->removeExpired() === 1 || $fail('the expired member was not removed');
$tg->removed === [777888999] || $fail('removed the wrong member');
echo "expired member removed OK (user {$tg->removed[0]})\n";

// 6 · Once removed, we do not try again every night
$access->removeExpired() === 0 || $fail('removed the same member twice');
echo "no repeated removal OK\n";

// 7 · Renewal: the date moves, no invite needed — they are in
$access->extend('pay_yesterday', new DateTimeImmutable('+30 days'));
$access->removeExpired() === 0 || $fail('removed a renewed member');
echo "renewed member stays OK\n";

// 8 · The grace period can be zero, if you want none
$strict = new AccessManager($tg, $store, '-1001234567890', graceDays: 0);
$store->upsertMember(123123123, 'pay_today', new DateTimeImmutable('-1 hour'));
$strict->removeExpired() === 1 || $fail('nothing removed with graceDays: 0');
echo "zero grace period OK\n";

echo "\nall checks passed\n";
