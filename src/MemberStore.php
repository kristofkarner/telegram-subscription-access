<?php

declare(strict_types=1);

namespace KK\TelegramAccess;

/**
 * The membership record, on MySQL/MariaDB.
 *
 * Not a convenience extra but the backbone of the system: the Bot API
 * does not hand out the full member list, so "who is in and until
 * when?" can only be answered here.
 *
 * The SQL is MySQL dialect (NOW(), INTERVAL, ON DUPLICATE KEY UPDATE).
 * On another database, implement MemberStorage instead — the rest of
 * the code does not know what lives in here.
 */
final class MemberStore implements MemberStorage
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function savePairingCode(string $code, string $paymentReference, \DateTimeImmutable $paidUntil): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pairing_codes (code, payment_reference, paid_until, created_at)
             VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$code, $paymentReference, $paidUntil->format('Y-m-d H:i:s')]);
    }

    /**
     * The code is single-use: read it and delete it in one pass.
     *
     * The transaction and the row lock matter. Without them, two
     * requests arriving at the same moment could both read the code
     * before either deleted it — and one payment would let two accounts
     * in.
     *
     * @return array{payment_reference:string,paid_until:\DateTimeImmutable}|null
     */
    public function consumePairingCode(string $code): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT payment_reference, paid_until FROM pairing_codes
                 WHERE code = ? AND created_at > (NOW() - INTERVAL 7 DAY) FOR UPDATE'
            );
            $stmt->execute([$code]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row === false) {
                $this->pdo->commit();
                return null;
            }

            $this->pdo->prepare('DELETE FROM pairing_codes WHERE code = ?')->execute([$code]);
            $this->pdo->commit();

            return [
                'payment_reference' => $row['payment_reference'],
                'paid_until' => new \DateTimeImmutable($row['paid_until']),
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function upsertMember(int $telegramUserId, string $paymentReference, \DateTimeImmutable $paidUntil): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO members (telegram_user_id, payment_reference, paid_until, joined_at, removed_at)
             VALUES (?, ?, ?, NOW(), NULL)
             ON DUPLICATE KEY UPDATE
                payment_reference = VALUES(payment_reference),
                paid_until = VALUES(paid_until),
                removed_at = NULL'
        );
        $stmt->execute([$telegramUserId, $paymentReference, $paidUntil->format('Y-m-d H:i:s')]);
    }

    public function extendByReference(string $paymentReference, \DateTimeImmutable $paidUntil): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE members SET paid_until = ? WHERE payment_reference = ?'
        );
        $stmt->execute([$paidUntil->format('Y-m-d H:i:s'), $paymentReference]);
    }

    /** @return list<array{telegram_user_id:int}> */
    public function expiredBefore(\DateTimeImmutable $deadline): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT telegram_user_id FROM members
             WHERE paid_until < ? AND removed_at IS NULL'
        );
        $stmt->execute([$deadline->format('Y-m-d H:i:s')]);

        return array_map(
            static fn (array $r): array => ['telegram_user_id' => (int) $r['telegram_user_id']],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function markRemoved(int $telegramUserId): void
    {
        $stmt = $this->pdo->prepare('UPDATE members SET removed_at = NOW() WHERE telegram_user_id = ?');
        $stmt->execute([$telegramUserId]);
    }
}
