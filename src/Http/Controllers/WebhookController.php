<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use WerdsWords\LinkStack\SharedProfiles\Helpers\DataGetter;
use WerdsWords\LinkStack\SharedProfiles\Providers\Controllers\AbstractWebhookController;
use WerdsWords\LinkStack\SharedProfiles\Providers\Models\ProviderManager;
use WerdsWords\LinkStack\SharedProfiles\Providers\Models\ProviderSetting;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Enums\ChatType;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Exceptions\ChatAlreadyBoundException;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Exceptions\ManagerNotFoundException;
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
        $message = DataGetter::arrayFromArray($payload, 'message');
        $text = explode('@', DataGetter::stringFromArray($message, 'text'))[0];

        if ($text === '/auth') {
            $this->handleAuthCommand($message);
        } elseif ($text === '/setup') {
            $this->handleSetupCommand($message);
        }
    }

    /** @param array<string, mixed> $message */
    private function handleAuthCommand(array $message): void
    {
        $telegramId = DataGetter::stringFromArray($message, 'from.id');

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

    /** @param array<string, mixed> $message */
    private function handleSetupCommand(array $message): void
    {
        // Only valid in group or supergroup chats — not private DMs or channels.
        // tryFrom() returns null for unknown types, which falls through the in_array check naturally.
        $chatType = ChatType::tryFrom(DataGetter::stringFromArray($message, 'chat.type'));
        if (! in_array($chatType, [ChatType::Group, ChatType::SuperGroup], true)) {
            return;
        }

        $telegramId = DataGetter::stringFromArray($message, 'from.id');
        $chatId = DataGetter::stringFromArray($message, 'chat.id');

        try {
            $manager = $this->resolveManager($telegramId);
            $existing = $this->resolveGroupChat($chatId, $manager->profile_id);
        } catch (ManagerNotFoundException|ChatAlreadyBoundException) {
            return;
        }

        if (! $existing) {
            DB::table('telegram_group_chats')->insert([
                'profile_id' => $manager->profile_id,
                'chat_id' => $chatId,
                'created_at' => now(),
            ]);
        }

        $botToken = $this->resolveToken($manager->profile_id);
        $appUrl = config('app.url').'/telegram-app/submit';

        $this->messagingService->sendMessageWithWebAppButton(
            $botToken,
            $chatId,
            'Use the button below to submit a link to this profile.',
            'Submit a Link',
            $appUrl
        );
    }

    /**
     * Resolve the owner ProviderManager for the given Telegram user ID.
     *
     * @throws ManagerNotFoundException
     */
    protected function resolveManager(string $telegramId): ProviderManager
    {
        $manager = ProviderManager::forProvider('telegram')
            ->where('external_id', $telegramId)
            ->first();

        if (! $manager || ! $manager->isOwner()) {
            throw new ManagerNotFoundException(
                "No owner manager found for Telegram ID {$telegramId}."
            );
        }

        return $manager;
    }

    /**
     * Look up the telegram_group_chats record for the given chat.
     *
     * Returns the record if already bound to this profile (idempotent re-setup),
     * null if never set up, or throws if bound to a different profile.
     *
     * @throws ChatAlreadyBoundException
     */
    protected function resolveGroupChat(string $chatId, int $profileId): ?\stdClass
    {
        /** @var \stdClass|null $record */
        $record = DB::table('telegram_group_chats')->where('chat_id', $chatId)->first();

        if ($record !== null && (int) $record->profile_id !== $profileId) {
            throw new ChatAlreadyBoundException(
                "Chat {$chatId} is already bound to a different profile."
            );
        }

        return $record;
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
