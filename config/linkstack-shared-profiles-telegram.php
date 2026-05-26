<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'bot_name' => env('TELEGRAM_BOT_NAME'),
    'auth_date_ttl' => 300, // seconds before initData is considered stale
    'default_button_id' => env('TELEGRAM_DEFAULT_BUTTON_ID'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
];
