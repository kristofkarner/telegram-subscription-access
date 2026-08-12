<?php

declare(strict_types=1);

namespace KK\TelegramAccess;

/**
 * The storage seam.
 *
 * This is an interface because it is the one place where the sample is
 * guaranteed to collide with your own system: on WordPress you use
 * $wpdb, on Laravel you use Eloquent. Nothing else in the codebase
 * cares what sits behind it — MemberStore is only one possible answer.
 */
interface MemberStorage
{
    public function savePairingCode(string $code, string $paymentReference, \DateTimeImmutable $paidUntil): void;

    /** @return array{payment_reference:string,paid_until:\DateTimeImmutable}|null */
    public function consumePairingCode(string $code): ?array;

    public function upsertMember(int $telegramUserId, string $paymentReference, \DateTimeImmutable $paidUntil): void;

    public function extendByReference(string $paymentReference, \DateTimeImmutable $paidUntil): void;

    /** @return list<array{telegram_user_id:int}> */
    public function expiredBefore(\DateTimeImmutable $deadline): array;

    public function markRemoved(int $telegramUserId): void;
}
