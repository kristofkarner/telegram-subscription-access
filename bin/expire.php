<?php

declare(strict_types=1);

/*
 * Remove expired members. Run once a day, from cron:
 *
 *   15 4 * * * /usr/bin/php /path/to/bin/expire.php >> /path/to/expire.log 2>&1
 *
 * Why not in real time, at the moment the subscription lapses? Because
 * a payment provider does not call a failed renewal on the first try:
 * it retries the charge for days. Anyone removed at the minute of
 * expiry is removed over an expired card — and may not come back.
 */

require __DIR__ . '/../src/TelegramException.php';
require __DIR__ . '/../src/TelegramClient.php';
require __DIR__ . '/../src/MemberStorage.php';
require __DIR__ . '/../src/MemberStore.php';
require __DIR__ . '/../src/AccessManager.php';

use KK\TelegramAccess\{AccessManager, MemberStore, TelegramClient};

$config = require __DIR__ . '/../config.php';

$pdo = new PDO($config['dsn'], $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$access = new AccessManager(
    new TelegramClient($config['bot_token']),
    new MemberStore($pdo),
    $config['chat_id'],
    graceDays: 3,
);

$count = $access->removeExpired();
printf("[%s] removed: %d\n", date('Y-m-d H:i:s'), $count);
