<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Models\Manager;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Services\MessagingService;

class WebhookController extends Controller
{
    public function __construct(private readonly MessagingService $messagingService) {}

    public function handle(Request $request): JsonResponse
    {
        /** @var string|null $secret */
        $secret = config('linkstack-shared-profiles-telegram.webhook_secret');

        if ($request->header('X-Telegram-Bot-Api-Secret-Token') !== $secret) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        /** @var array<string, mixed> $update */
        $update = $request->all();

        if (isset($update['message']) && is_array($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleMessage(array $message): void
    {
        if (($message['text'] ?? '') !== '/auth') {
            return;
        }

        /** @var array{id?: int|string, username?: string} $from */
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $telegramId = (string) ($from['id'] ?? '');

        $manager = Manager::where('telegram_id', $telegramId)->first();
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

    /**
     * @param  array<string, mixed>  $query
     */
    private function handleCallbackQuery(array $query): void
    {
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

        $manager = Manager::where('telegram_id', $telegramId)
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

    private function resolveToken(#[SensitiveParameter] int $profileId): string
    {
        $perProfile = DB::table('users')->where('id', $profileId)->value('telegram_bot_token');

        /** @var string $token */
        $token = is_string($perProfile) && $perProfile !== ''
            ? $perProfile
            : config('linkstack-shared-profiles-telegram.bot_token');

        return $token;
    }
}
