<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use WerdsWords\LinkStack\SharedProfiles\Providers\Controllers\AbstractWebhookController;
use WerdsWords\LinkStack\SharedProfiles\Providers\Models\ProviderManager;
use WerdsWords\LinkStack\SharedProfiles\Providers\Models\ProviderSetting;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Services\MessagingService;

class WebhookController extends AbstractWebhookController
{
    public function __construct(private readonly MessagingService $messagingService) {}

    protected function verifySignature(Request $request): bool
    {
        $secret = config('linkstack-shared-profiles-telegram.webhook_secret');

        return $request->header('X-Telegram-Bot-Api-Secret-Token') === $secret;
    }

    /** @param array<string, mixed> $payload */
    protected function isMessage(array $payload): bool
    {
        return isset($payload['message']) && is_array($payload['message']);
    }

    /** @param array<string, mixed> $payload */
    protected function handleMessage(array $payload): void
    {
        /** @var array<string, mixed> $message */
        $message = $payload['message'];

        if (($message['text'] ?? '') !== '/auth') {
            return;
        }

        /** @var array{id?: int|string, username?: string} $from */
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $telegramId = (string) ($from['id'] ?? '');

        $manager = ProviderManager::forProvider('telegram')
            ->where('external_id', $telegramId)
            ->first();

        if (! $manager) {
            return;
        }

        $loginUrl = config('app.url').'/telegram-auth/'.$manager->profile_id;
        $botToken = $this->resolveToken($manager->profile_id);

        /** @var string $appName */
        $appName = config('app.name', 'LinkStack');
        $buttonLabel = sprintf('Log in to %s', $appName);

        $this->messagingService->sendMessageWithKeyboard(
            $botToken,
            $telegramId,
            'Use the button below to log in to your LinkStack profile.',
            [[['text' => $buttonLabel, 'url' => $loginUrl]]]
        );
    }

    /** @param array<string, mixed> $payload */
    protected function handleInteraction(array $payload): void
    {
        if (! isset($payload['callback_query']) || ! is_array($payload['callback_query'])) {
            return;
        }

        /** @var array<string, mixed> $query */
        $query = $payload['callback_query'];

        /** @var string $globalToken */
        $globalToken = config('linkstack-shared-profiles-telegram.bot_token');
        $rawQueryId = $query['id'] ?? '';
        $this->messagingService->answerCallbackQuery($globalToken, is_string($rawQueryId) ? $rawQueryId : '');

        $rawData = $query['data'] ?? '';
        $data = is_string($rawData) ? $rawData : '';
        if (! preg_match('/^(approve|reject):(\d+)$/', $data, $matches)) {
            return;
        }

        $action = $matches[1];
        $linkId = (int) $matches[2];

        /** @var array{id?: int|string, username?: string} $from */
        $from = is_array($query['from'] ?? null) ? $query['from'] : [];
        $telegramId = (string) ($from['id'] ?? '');
        $rawUsername = $from['username'] ?? null;
        $username = is_string($rawUsername) ? $rawUsername : 'unknown';

        $userId = DB::table('links')->where('id', $linkId)->value('user_id');
        if (! is_numeric($userId)) {
            return;
        }

        $profileId = (int) $userId;

        $manager = ProviderManager::forProvider('telegram')
            ->where('external_id', $telegramId)
            ->where('profile_id', $profileId)
            ->first();

        if (! $manager) {
            return;
        }

        $botToken = $this->resolveToken($profileId);

        if ($action === 'approve') {
            DB::table('links')
                ->where('id', $linkId)
                ->where('status', 'pending')
                ->update(['status' => 'published']);
            $statusText = "✅ Approved by @{$username}";
        } else {
            DB::table('links')
                ->where('id', $linkId)
                ->where('status', 'pending')
                ->delete();
            $statusText = "❌ Rejected by @{$username}";
        }

        /** @var array{message_id?: int, chat?: array{id?: int|string}} $message */
        $message = is_array($query['message'] ?? null) ? $query['message'] : [];
        $chatId = $message['chat']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;

        if ($chatId !== null && $messageId !== null) {
            $this->messagingService->editMessageText($botToken, $chatId, (int) $messageId, $statusText);
        }
    }

    private function resolveToken(int $profileId): string
    {
        $setting = ProviderSetting::forProvider('telegram')
            ->where('profile_id', $profileId)
            ->first();

        $settings = $setting?->settings ?? [];
        $rawToken = $settings['bot_token'] ?? null;

        /** @var string $token */
        $token = (is_string($rawToken) && $rawToken !== '')
            ? $rawToken
            : config('linkstack-shared-profiles-telegram.bot_token');

        return $token;
    }
}
