-- Pairing codes: what connects a payment to a Telegram account.
-- Single-use, and it expires — for the same reason the invite does.
CREATE TABLE pairing_codes (
    code              CHAR(32)     NOT NULL PRIMARY KEY,
    payment_reference VARCHAR(128) NOT NULL,
    paid_until        DATETIME     NOT NULL,
    created_at        DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The membership record. It exists because the Bot API does not hand
-- out the full member list: "who is in and until when?" can only be
-- answered here.
CREATE TABLE members (
    telegram_user_id  BIGINT       NOT NULL PRIMARY KEY,
    payment_reference VARCHAR(128) NOT NULL,
    paid_until        DATETIME     NOT NULL,
    joined_at         DATETIME     NOT NULL,
    removed_at        DATETIME     NULL,
    INDEX idx_paid_until (paid_until),
    INDEX idx_reference (payment_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
