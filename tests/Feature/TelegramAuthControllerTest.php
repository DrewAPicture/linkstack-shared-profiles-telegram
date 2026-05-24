<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use SensitiveParameter;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers\TelegramAuthController;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\ServiceProvider;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Tests\Support\Models\User;
use WerdsWords\LinkStack\SharedProfiles\ServiceProvider as CoreServiceProvider;

#[CoversClass(TelegramAuthController::class)]
final class TelegramAuthControllerTest extends TestCase
{
    private const BOT_TOKEN = 'test-bot-token';

    private const PER_USER_BOT_TOKEN = 'per-user-bot-token';

    protected function getPackageProviders($app): array
    {
        return [
            SocialiteServiceProvider::class,
            CoreServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('auth.providers.users.model', User::class);

        $app['config']->set('services.telegram.client_id', 'test-bot-id');
        $app['config']->set('services.telegram.client_secret', self::BOT_TOKEN);
        $app['config']->set('services.telegram.redirect', 'https://example.com/telegram-auth/1/callback');

        $app['config']->set('linkstack-shared-profiles-telegram.bot_token', self::BOT_TOKEN);
        $app['config']->set('linkstack-shared-profiles-telegram.auth_date_ttl', 300);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => 'login')->name('login');
        $router->get('/studio/index', fn () => 'studio')->name('studio.index');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('remember_token')->nullable();
            $table->string('telegram_bot_token')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_managers', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_id')->unique();
            $table->unsignedBigInteger('profile_id');
            $table->foreign('profile_id')->references('id')->on('users')->onDelete('cascade');
            $table->enum('role', ['owner', 'moderator'])->default('moderator');
            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $this->beforeApplicationDestroyed(function () {
            Schema::dropIfExists('telegram_managers');
            Schema::dropIfExists('users');
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createUser(?string $telegramBotToken = null): User
    {
        return User::create(array_filter([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'telegram_bot_token' => $telegramBotToken,
        ], fn ($v) => $v !== null));
    }

    private function createManager(int $profileId, string $telegramId, string $role = 'moderator'): void
    {
        DB::table('telegram_managers')->insert([
            'telegram_id' => $telegramId,
            'profile_id' => $profileId,
            'role' => $role,
        ]);
    }

    /**
     * Build a properly HMAC-signed initData string matching Telegram's Mini App spec.
     */
    private function buildValidInitData(int|string $telegramId, int $authDate = 0, #[SensitiveParameter] string $signingToken = self::BOT_TOKEN): string
    {
        if ($authDate === 0) {
            $authDate = time();
        }

        $params = [
            'auth_date' => (string) $authDate,
            'user' => json_encode(['id' => $telegramId, 'first_name' => 'Test']),
        ];

        ksort($params);

        $checkStr = implode("\n", array_map(
            fn ($k, $v) => "{$k}={$v}",
            array_keys($params),
            $params
        ));

        $secret = hash_hmac('sha256', 'WebAppData', $signingToken, true);
        $hash = hash_hmac('sha256', $checkStr, $secret);

        return http_build_query([...$params, 'hash' => $hash]);
    }

    // -------------------------------------------------------------------------
    // Moderator Mini App — GET /telegram-app/moderate
    // -------------------------------------------------------------------------

    public function testModerateAppRouteReturns200(): void
    {
        $this->get('/telegram-app/moderate')->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // Approach A — redirect()
    // -------------------------------------------------------------------------

    public function testRedirectInitiatesTelegramAuth(): void
    {
        $user = $this->createUser();

        $mockProvider = Mockery::mock(SocialiteProvider::class);
        $mockProvider->shouldReceive('redirect')
            ->once()
            ->andReturn(redirect('https://oauth.telegram.org/auth?bot_id=123'));

        Socialite::shouldReceive('driver')
            ->with('telegram')
            ->andReturn($mockProvider);

        $this->get("/telegram-auth/{$user->id}")
            ->assertRedirect('https://oauth.telegram.org/auth?bot_id=123');
    }

    public function testRedirectAppliesPerProfileTokenToSocialiteConfig(): void
    {
        $user = $this->createUser(self::PER_USER_BOT_TOKEN);
        $capturedSecret = null;

        Socialite::shouldReceive('driver')
            ->with('telegram')
            ->andReturnUsing(function () use (&$capturedSecret) {
                $capturedSecret = config('services.telegram.client_secret');
                $mock = Mockery::mock(SocialiteProvider::class);
                $mock->shouldReceive('redirect')
                    ->andReturn(redirect('https://oauth.telegram.org'));

                return $mock;
            });

        $this->get("/telegram-auth/{$user->id}");

        $this->assertSame(self::PER_USER_BOT_TOKEN, $capturedSecret);
    }

    // -------------------------------------------------------------------------
    // Approach A — callback()
    // -------------------------------------------------------------------------

    public function testCallbackWithUnknownTelegramIdRedirectsToLogin(): void
    {
        $user = $this->createUser();

        $mockUser = Mockery::mock(SocialiteUser::class);
        $mockUser->shouldReceive('getId')->andReturn('9999999');

        $mockProvider = Mockery::mock(SocialiteProvider::class);
        $mockProvider->shouldReceive('user')->andReturn($mockUser);

        Socialite::shouldReceive('driver')
            ->with('telegram')
            ->andReturn($mockProvider);

        $this->get("/telegram-auth/{$user->id}/callback")
            ->assertRedirect('/login');
    }

    public function testCallbackWithKnownManagerLogsInAndRedirects(): void
    {
        $user = $this->createUser();
        $this->createManager($user->id, '12345678');

        $mockUser = Mockery::mock(SocialiteUser::class);
        $mockUser->shouldReceive('getId')->andReturn('12345678');

        $mockProvider = Mockery::mock(SocialiteProvider::class);
        $mockProvider->shouldReceive('user')->andReturn($mockUser);

        Socialite::shouldReceive('driver')
            ->with('telegram')
            ->andReturn($mockProvider);

        $this->get("/telegram-auth/{$user->id}/callback")
            ->assertRedirect('/studio/index');

        $this->assertAuthenticated();
    }

    public function testCallbackAuthenticatesAsSharedProfileUser(): void
    {
        $user = $this->createUser();
        $this->createManager($user->id, '12345678');

        $mockUser = Mockery::mock(SocialiteUser::class);
        $mockUser->shouldReceive('getId')->andReturn('12345678');

        $mockProvider = Mockery::mock(SocialiteProvider::class);
        $mockProvider->shouldReceive('user')->andReturn($mockUser);

        Socialite::shouldReceive('driver')
            ->with('telegram')
            ->andReturn($mockProvider);

        $this->get("/telegram-auth/{$user->id}/callback");

        $this->assertAuthenticatedAs($user);
    }

    public function testCallbackAppliesPerProfileTokenToSocialiteConfig(): void
    {
        $user = $this->createUser(self::PER_USER_BOT_TOKEN);
        $this->createManager($user->id, '12345678');
        $capturedSecret = null;

        Socialite::shouldReceive('driver')
            ->with('telegram')
            ->andReturnUsing(function () use (&$capturedSecret) {
                $capturedSecret = config('services.telegram.client_secret');
                $mockUser = Mockery::mock(SocialiteUser::class);
                $mockUser->shouldReceive('getId')->andReturn('12345678');
                $mock = Mockery::mock(SocialiteProvider::class);
                $mock->shouldReceive('user')->andReturn($mockUser);

                return $mock;
            });

        $this->get("/telegram-auth/{$user->id}/callback");

        $this->assertSame(self::PER_USER_BOT_TOKEN, $capturedSecret);
    }

    // -------------------------------------------------------------------------
    // Approach B — initDataLogin()
    // -------------------------------------------------------------------------

    public function testInitDataLoginRequiresInitData(): void
    {
        $this->postJson('/telegram-login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['init_data']);
    }

    public function testInitDataLoginRejectsInvalidSignature(): void
    {
        $user = $this->createUser();
        $this->createManager($user->id, '123');

        $params = http_build_query([
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => 123]),
            'hash' => 'deadbeef',
        ]);

        $this->postJson('/telegram-login', ['init_data' => $params])
            ->assertStatus(403)
            ->assertJson(['error' => 'Invalid signature']);
    }

    public function testInitDataLoginRejectsExpiredAuthDate(): void
    {
        $user = $this->createUser();
        $this->createManager($user->id, '12345678');

        $expiredDate = time() - 400; // beyond the 300 s TTL
        $initData = $this->buildValidInitData('12345678', $expiredDate);

        $this->postJson('/telegram-login', ['init_data' => $initData])
            ->assertStatus(403)
            ->assertJson(['error' => 'Token expired']);
    }

    public function testInitDataLoginWithUnknownTelegramIdReturns403(): void
    {
        $initData = $this->buildValidInitData('9999999');

        $this->postJson('/telegram-login', ['init_data' => $initData])
            ->assertStatus(403)
            ->assertJson(['error' => 'Not authorised']);
    }

    public function testInitDataLoginWithValidDataAuthenticatesUser(): void
    {
        $user = $this->createUser();
        $this->createManager($user->id, '12345678');

        $initData = $this->buildValidInitData('12345678');

        $this->postJson('/telegram-login', ['init_data' => $initData])
            ->assertStatus(200)
            ->assertJson(['redirect' => '/studio/moderation']);

        $this->assertAuthenticated();
    }

    public function testInitDataLoginAuthenticatesAsSharedProfileUser(): void
    {
        $user = $this->createUser();
        $this->createManager($user->id, '12345678');

        $initData = $this->buildValidInitData('12345678');

        $this->postJson('/telegram-login', ['init_data' => $initData]);

        $this->assertAuthenticatedAs($user);
    }

    public function testInitDataLoginUsesPerProfileTokenWhenSet(): void
    {
        $user = $this->createUser(self::PER_USER_BOT_TOKEN);
        $this->createManager($user->id, '12345678');

        $initData = $this->buildValidInitData('12345678', 0, self::PER_USER_BOT_TOKEN);

        $this->postJson('/telegram-login', ['init_data' => $initData])
            ->assertStatus(200)
            ->assertJson(['redirect' => '/studio/moderation']);

        $this->assertAuthenticated();
    }

    public function testInitDataLoginRejectsGlobalTokenWhenPerProfileTokenIsSet(): void
    {
        $user = $this->createUser(self::PER_USER_BOT_TOKEN);
        $this->createManager($user->id, '12345678');

        $initData = $this->buildValidInitData('12345678'); // signed with self::BOT_TOKEN

        $this->postJson('/telegram-login', ['init_data' => $initData])
            ->assertStatus(403)
            ->assertJson(['error' => 'Invalid signature']);
    }
}
