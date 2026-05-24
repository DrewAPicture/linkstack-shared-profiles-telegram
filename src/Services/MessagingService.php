<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Services;

use Illuminate\Support\Facades\Http;
use SensitiveParameter;

class MessagingService
{
    private const API_BASE = 'https://api.telegram.org';

    /**
     * Send a text message via the Telegram Bot API.
     *
     * @param  int|string  $chatId  Telegram chat ID (numeric user ID or @username)
     */
    public function sendMessage(#[SensitiveParameter] string $botToken, int|string $chatId, string $text): bool
    {
        $response = Http::post(
            self::API_BASE."/bot{$botToken}/sendMessage",
            ['chat_id' => $chatId, 'text' => $text]
        );

        return $response->successful();
    }

    /**
     * Send a text message with an inline keyboard via the Telegram Bot API.
     *
     * @param  int|string  $chatId  Telegram chat ID (numeric user ID or @username)
     * @param  array<int, array<int, array<string, string>>>  $inlineKeyboard  Array of button rows
     */
    public function sendMessageWithKeyboard(#[SensitiveParameter] string $botToken, int|string $chatId, string $text, array $inlineKeyboard): bool
    {
        $response = Http::post(
            self::API_BASE."/bot{$botToken}/sendMessage",
            [
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => ['inline_keyboard' => $inlineKeyboard],
            ]
        );

        return $response->successful();
    }

    /**
     * Edit the text of a sent message and remove its inline keyboard.
     *
     * @param  int|string  $chatId  Telegram chat ID (numeric user ID or @username)
     */
    public function editMessageText(#[SensitiveParameter] string $botToken, int|string $chatId, int $messageId, string $text): bool
    {
        $response = Http::post(
            self::API_BASE."/bot{$botToken}/editMessageText",
            [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => ['inline_keyboard' => []],
            ]
        );

        return $response->successful();
    }

    /**
     * Answer a callback query to dismiss the loading spinner on an inline button tap.
     */
    public function answerCallbackQuery(#[SensitiveParameter] string $botToken, string $callbackQueryId, string $text = ''): bool
    {
        $response = Http::post(
            self::API_BASE."/bot{$botToken}/answerCallbackQuery",
            ['callback_query_id' => $callbackQueryId, 'text' => $text]
        );

        return $response->successful();
    }
}
