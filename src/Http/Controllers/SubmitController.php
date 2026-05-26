<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use WerdsWords\LinkStack\SharedProfiles\Events\PendingLinkSubmitted;
use WerdsWords\LinkStack\SharedProfiles\Providers\Models\ProviderSetting;
use WerdsWords\LinkStack\SharedProfiles\Providers\Support\AuthReplayGuard;

class SubmitController extends Controller
{
    /**
     * Serve the contributor Mini App view.
     */
    public function app(): View
    {
        return view('linkstack-shared-profiles::telegram-app.submit');
    }

    /**
     * Receive a link submission from the contributor Mini App.
     *
     * Authentication is provided by Telegram's HMAC-signed initData. The chat.id
     * field identifies which LinkStack profile to file the link under — read before
     * HMAC verification only to resolve the per-profile bot token. A spoofed chat.id
     * cannot pass verification because it is part of the signed payload.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'init_data' => 'required|string',
            'link' => 'required|url|max:2048',
            'title' => 'required|string|max:255',
        ]);

        parse_str($validated['init_data'], $rawParams);

        // Narrow parse_str output (array<string, array|string>) to array<string, string>
        $params = [];
        foreach ($rawParams as $key => $value) {
            if (is_string($value)) {
                $params[$key] = $value;
            }
        }

        $chatId = $params['start_param'] ?? '';

        Log::channel('telegram-webhook')->info('Submit store', [
            'init_data_length' => strlen($validated['init_data']),
            'param_keys' => array_keys($params),
            'chat_id' => $chatId,
        ]);

        $rawProfileId = DB::table('telegram_group_chats')->where('chat_id', $chatId)->value('profile_id');

        Log::channel('telegram-webhook')->info('Profile lookup', ['profile_id' => $rawProfileId]);

        if (! is_numeric($rawProfileId)) {
            abort(404);
        }

        $profileId = (int) $rawProfileId;

        $setting = ProviderSetting::forProvider('telegram')
            ->where('profile_id', $profileId)
            ->first();

        $settings = $setting?->settings ?? [];
        $rawToken = $settings['bot_token'] ?? null;

        /** @var string $botToken */
        $botToken = (is_string($rawToken) && $rawToken !== '')
            ? $rawToken
            : config('linkstack-shared-profiles-telegram.bot_token');

        $secret = hash_hmac('sha256', 'WebAppData', $botToken, true);

        $hash = $params['hash'] ?? '';
        unset($params['hash'], $params['signature']);
        ksort($params);

        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }
        $checkStr = implode("\n", $pairs);
        $computed = hash_hmac('sha256', $checkStr, $secret);

        Log::channel('telegram-webhook')->info('HMAC check', ['match' => hash_equals($computed, $hash)]);

        if (! hash_equals($computed, $hash)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        /** @var int $ttl */
        $ttl = config('linkstack-shared-profiles-telegram.auth_date_ttl', 300);
        $authDate = isset($params['auth_date']) ? (int) $params['auth_date'] : 0;

        if (AuthReplayGuard::isStale($authDate, $ttl)) {
            return response()->json(['error' => 'Token expired'], 403);
        }

        $perUser = DB::table('users')->where('id', $profileId)->value('auto_approve');
        /** @var bool $autoApprove */
        $autoApprove = $perUser !== null
            ? $perUser
            : config('linkstack-shared-profiles.auto_approve');

        $status = $autoApprove ? 'published' : 'pending';

        /** @var int $defaultButtonId */
        $defaultButtonId = config('linkstack-shared-profiles-telegram.default_button_id');

        $linkId = DB::table('links')->insertGetId([
            'user_id' => $profileId,
            'link' => $validated['link'],
            'title' => $validated['title'],
            'button_id' => $defaultButtonId,
            'type' => 'predefined',
            'type_params' => null,
            'status' => $status,
            'order' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($status === 'pending') {
            event(new PendingLinkSubmitted($profileId, $linkId, $validated['link'], $validated['title']));
        }

        return response()->json(['status' => 'queued'], 201);
    }
}
