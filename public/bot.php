<?php

declare(strict_types=1);

/*
 * The bot's webhook endpoint: this is where /start arrives with the
 * pairing code.
 *
 * The order matters here as it does in any webhook: verify the origin
 * FIRST, and only then do anything else. Telegram sends back the secret
 * given to setWebhook in a header on every request; without checking
 * it, anyone who guesses the endpoint URL can send a forged "I paid"
 * message.
 */

require __DIR__ . '/../src/TelegramException.php';
require __DIR__ . '/../src/TelegramClient.php';
require __DIR__ . '/../src/MemberStorage.php';
require __DIR__ . '/../src/MemberStore.php';
require __DIR__ . '/../src/AccessManager.php';

use KK\TelegramAccess\{AccessManager, MemberStore, TelegramClient, TelegramException};

$config = require __DIR__ . '/../config.php';

// 1 · Verify the origin, before anything else
$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals($config['webhook_secret'], $secret)) {
    http_response_code(403);
    exit;
}

$update = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($update)) {
    http_response_code(400);
    exit;
}

$message = $update['message'] ?? null;
$text = $message['text'] ?? '';
$userId = (int) ($message['from']['id'] ?? 0);

// Everything else — group chatter, button presses — is dropped
// quietly. Telegram treats a non-200 response as a failure and resends.
if ($userId === 0 || !str_starts_with($text, '/start')) {
    http_response_code(200);
    exit;
}

$pdo = new PDO($config['dsn'], $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$telegram = new TelegramClient($config['bot_token']);
$access = new AccessManager($telegram, new MemberStore($pdo), $config['chat_id']);

$code = trim(substr($text, strlen('/start')));

try {
    if ($code === '') {
        throw new TelegramException('No code in the link.');
    }

    $invite = $access->redeemPairingCode($code, $userId);
    $reply = "You're in. Here is your link:\n{$invite}\n\n"
        . 'It is good for a single join and it expires. Please do not pass it on.';
} catch (TelegramException $e) {
    // The reason is not passed on to the user: it would reveal whether
    // a given code exists.
    error_log('Pairing failed (user ' . $userId . '): ' . $e->getMessage());
    $reply = 'That link is not valid. If you have just paid, check your '
        . 'confirmation email, or get in touch.';
}

// The reply to the user — a webhook response can carry it directly.
header('Content-Type: application/json');
echo json_encode([
    'method' => 'sendMessage',
    'chat_id' => $userId,
    'text' => $reply,
    'disable_web_page_preview' => true,
]);
