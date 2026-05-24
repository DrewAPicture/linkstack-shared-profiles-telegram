<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use WerdsWords\LinkStack\SharedProfiles\Events\PendingLinkSubmitted;

class TelegramSubmitController extends Controller
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

        $chatJson = $params['chat'] ?? '{}';

        /** @var array{id?: int|string}|null $chatData */
        $chatData = json_decode($chatJson, true);
        $chatId = (string) ($chatData['id'] ?? '');

        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $profile = $userModel::where('telegram_group_chat_id', $chatId)->first();

        if (! $profile) {
            abort(404);
        }

        /** @var int $profileId */
        $profileId = $profile->getKey();

        $botToken = $this->resolveToken($profileId);

        $secret = hash_hmac('sha256', 'WebAppData', $botToken, true);

        $hash = $params['hash'] ?? '';
        unset($params['hash']);
        ksort($params);

        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }
        $checkStr = implode("\n", $pairs);
        $computed = hash_hmac('sha256', $checkStr, $secret);

        if (! hash_equals($computed, $hash)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        /** @var int $ttl */
        $ttl = config('linkstack-shared-profiles.auth_date_ttl', 300);
        $authDate = isset($params['auth_date']) ? (int) $params['auth_date'] : 0;

        if (time() - $authDate > $ttl) {
            return response()->json(['error' => 'Token expired'], 403);
        }

        $perUser = DB::table('users')->where('id', $profileId)->value('auto_approve');
        /** @var bool $autoApprove */
        $autoApprove = $perUser !== null
            ? $perUser
            : config('linkstack-shared-profiles.auto_approve');

        $status = $autoApprove ? 'published' : 'pending';

        /** @var int $defaultButtonId */
        $defaultButtonId = config('linkstack-shared-profiles.default_button_id');

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

    /**
     * Resolve the bot token for a profile: use the per-profile value when set,
     * fall back to the global config otherwise.
     */
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
