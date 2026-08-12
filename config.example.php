<?php
/**
 * Copy this as config.php and fill it in. config.php is in .gitignore —
 * a token must never end up in version control.
 */
return [
    // BotFather → /newbot → the token you get back
    'bot_token' => '123456789:AA...',

    // The chat ID of the channel or group. Supergroups start with -100.
    // You can read it off an update once the bot is an admin there.
    'chat_id' => '-1001234567890',

    // The secret_token passed to setWebhook. Telegram returns it in a
    // header on every request — without it, anyone who knows the
    // endpoint URL can forge a /start.
    'webhook_secret' => 'a-long-random-string',

    // The bot's username, for building the pairing link:
    // https://t.me/<bot_username>?start=<code>
    'bot_username' => 'your_bot',

    'dsn'     => 'mysql:host=localhost;dbname=example;charset=utf8mb4',
    'db_user' => 'example',
    'db_pass' => '',
];
