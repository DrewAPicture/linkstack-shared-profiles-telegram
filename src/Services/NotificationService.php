<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Services;

use WerdsWords\LinkStack\SharedProfiles\Providers\Contracts\NotifierContract;
use WerdsWords\LinkStack\SharedProfiles\Providers\Models\ProviderManager;
use WerdsWords\LinkStack\SharedProfiles\Providers\Models\ProviderSetting;

class NotificationService implements NotifierContract
{
    public function __construct(private readonly MessagingService $messagingService) {}

    public function notifyModerators(int $profileId, int $linkId, string $link, string $title): void
    {
        $managers = ProviderManager::forProvider('telegram')
            ->where('profile_id', $profileId)
            ->get();

        $setting = ProviderSetting::forProvider('telegram')
            ->where('profile_id', $profileId)
            ->first();

        $settings = $setting?->settings ?? [];
        $rawToken = $settings['bot_token'] ?? null;

        /** @var string $botToken */
        $botToken = (is_string($rawToken) && $rawToken !== '')
            ? $rawToken
            : config('linkstack-shared-profiles-telegram.bot_token');

        foreach ($managers as $manager) {
            $this->messagingService->sendMessageWithKeyboard(
                $botToken,
                $manager->external_id,
                "New pending link:\n{$title}\n{$link}",
                [[
                    ['text' => '✅ Approve', 'callback_data' => "approve:{$linkId}"],
                    ['text' => '❌ Reject', 'callback_data' => "reject:{$linkId}"],
                ]]
            );
        }
    }
}
