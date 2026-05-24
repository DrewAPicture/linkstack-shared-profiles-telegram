<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Services;

use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use WerdsWords\LinkStack\SharedProfiles\Models\TelegramManager;

class TelegramNotificationService
{
    public function __construct(private readonly TelegramMessagingService $messagingService) {}

    public function notifyModerators(int $profileId, int $linkId, string $link, string $title): void
    {
        $managers = TelegramManager::where('profile_id', $profileId)->get();
        $botToken = $this->resolveToken($profileId);

        foreach ($managers as $manager) {
            $this->messagingService->sendMessageWithKeyboard(
                $botToken,
                $manager->telegram_id,
                "New pending link:\n{$title}\n{$link}",
                [[
                    ['text' => '✅ Approve', 'callback_data' => "approve:{$linkId}"],
                    ['text' => '❌ Reject', 'callback_data' => "reject:{$linkId}"],
                ]]
            );
        }
    }

    private function resolveToken(#[SensitiveParameter] int $profileId): string
    {
        $perProfile = DB::table('users')->where('id', $profileId)->value('telegram_bot_token');

        /** @var string $token */
        $token = is_string($perProfile) && $perProfile !== ''
            ? $perProfile
            : config('linkstack-shared-profiles.bot_token');

        return $token;
    }
}
