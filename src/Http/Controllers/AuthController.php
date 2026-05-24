<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Models\Manager;

class AuthController extends Controller
{
    /**
     * Approach A: initiate the Telegram Login Widget OAuth redirect.
     *
     * The profileId is a users.id — encoding it in the URL keeps the callback
     * stateless so we can resolve the right bot token without session storage.
     */
    public function redirect(int $profileId): SymfonyRedirectResponse
    {
        $this->applySocialiteConfig($profileId);

        return Socialite::driver('telegram')->redirect();
    }

    /**
     * Approach A: handle the callback after the user authorises via the Login Widget.
     */
    public function callback(int $profileId): RedirectResponse
    {
        $this->applySocialiteConfig($profileId);

        $social = Socialite::driver('telegram')->user();

        $manager = Manager::where('telegram_id', (string) $social->getId())->first();

        if (! $manager) {
            return redirect()->route('login')->withErrors(['telegram' => 'Not authorised.']);
        }

        Auth::loginUsingId($manager->profile_id);

        return redirect('/studio/index');
    }

    /**
     * Approach B: authenticate via Telegram Mini App initData.
     *
     * The Mini App sends Telegram.WebApp.initData as the `init_data` field.
     * We verify the HMAC, check the auth_date freshness, then log in as the
     * mapped shared-profile user and return a redirect URL for the client to follow.
     *
     * Manager lookup intentionally precedes HMAC so we can resolve the per-profile
     * token. Pre-reading telegram_id is safe: an attacker who fabricates an ID that
     * exists in our system still cannot forge a valid HMAC without the bot token.
     */
    public function initDataLogin(Request $request): JsonResponse
    {
        $initData = $request->validate(['init_data' => 'required|string'])['init_data'];

        parse_str($initData, $rawParams);

        // Narrow parse_str output (array<string, array|string>) to array<string, string>
        $params = [];
        foreach ($rawParams as $key => $value) {
            if (is_string($value)) {
                $params[$key] = $value;
            }
        }

        $userJson = $params['user'] ?? '{}';

        /** @var array{id?: int|string}|null $tgUser */
        $tgUser = json_decode($userJson, true);

        $telegramId = (string) ($tgUser['id'] ?? '');

        $manager = Manager::where('telegram_id', $telegramId)->first();

        if (! $manager) {
            return response()->json(['error' => 'Not authorised'], 403);
        }

        $botToken = $this->resolveToken($manager->profile_id);

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
        $ttl = config('linkstack-shared-profiles-telegram.auth_date_ttl', 300);

        $authDate = isset($params['auth_date']) ? (int) $params['auth_date'] : 0;

        if (time() - $authDate > $ttl) {
            return response()->json(['error' => 'Token expired'], 403);
        }

        Auth::loginUsingId($manager->profile_id);
        $request->session()->regenerate();

        return response()->json(['redirect' => '/studio/moderation']);
    }

    /**
     * Resolve the bot token for a profile: use the per-profile value when set,
     * fall back to the global config otherwise.
     */
    private function resolveToken(int $profileId): string
    {
        $perProfile = DB::table('users')->where('id', $profileId)->value('telegram_bot_token');

        /** @var string $token */
        $token = is_string($perProfile) && $perProfile !== ''
            ? $perProfile
            : config('linkstack-shared-profiles-telegram.bot_token');

        return $token;
    }

    /**
     * Override the Socialite telegram driver config for the given profile.
     *
     * socialiteproviders/telegram reads services.telegram.client_secret at
     * both redirect() and user() time. We set it here so the right bot token
     * is used for HMAC signing and verification on a per-profile basis.
     */
    private function applySocialiteConfig(int $profileId): void
    {
        $botToken = $this->resolveToken($profileId);

        Config::set('services.telegram.client_secret', $botToken);
        Config::set(
            'services.telegram.redirect',
            route('linkstack-shared-profiles.telegram.callback', ['profileId' => $profileId])
        );
    }
}
