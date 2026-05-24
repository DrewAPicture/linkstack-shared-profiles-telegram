<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\SocialiteServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use SensitiveParameter;
use WerdsWords\LinkStack\SharedProfiles\Events\PendingLinkSubmitted;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers\TelegramSubmitController;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\ServiceProvider;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Tests\Support\Models\User;
use WerdsWords\LinkStack\SharedProfiles\ServiceProvider as CoreServiceProvider;

#[CoversClass(TelegramSubmitController::class)]
final class TelegramSubmitControllerTest extends TestCase
{
    private const BOT_TOKEN = 'test-bot-token';

    private const PER_USER_BOT_TOKEN = 'per-user-bot-token';

    private const GROUP_CHAT_ID = '-1001234567890';

    private const DEFAULT_BUTTON_ID = 5;

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
        $app['config']->set('services.telegram.redirect', 'https://example.com/callback');

        $app['config']->set('linkstack-shared-profiles-telegram.bot_token', self::BOT_TOKEN);
        $app['config']->set('linkstack-shared-profiles-telegram.auth_date_ttl', 300);
        $app['config']->set('linkstack-shared-profiles-telegram.default_button_id', self::DEFAULT_BUTTON_ID);
        $app['config']->set('linkstack-shared-profiles.auto_approve', false);
        // Keep in sync with linkstack-shared-profiles-telegram.bot_token so the
        // core package's transitional listener (present until Phase 2 lands on main)
        // does not throw a TypeError when resolving the fallback token.
        $app['config']->set('linkstack-shared-profiles.bot_token', self::BOT_TOKEN);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('telegram_bot_token')->nullable();
            $table->string('telegram_group_chat_id')->nullable()->unique();
            $table->boolean('auto_approve')->nullable();
            $table->timestamps();
        });

        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('link', 2048);
            $table->string('title');
            $table->unsignedBigInteger('button_id');
            $table->string('type')->default('predefined');
            $table->text('type_params')->nullable();
            $table->enum('status', ['pending', 'published', 'rejected'])->default('published');
            $table->integer('order')->default(999);
            $table->string('up_link')->nullable();
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
            Schema::dropIfExists('links');
            Schema::dropIfExists('users');
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createUser(?string $groupChatId = self::GROUP_CHAT_ID, ?string $botToken = null, ?bool $autoApprove = null): User
    {
        return User::create(array_filter([
            'name' => 'Test Profile',
            'email' => 'profile@example.com',
            'telegram_group_chat_id' => $groupChatId,
            'telegram_bot_token' => $botToken,
            'auto_approve' => $autoApprove,
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
     * Build a properly HMAC-signed initData string that includes a chat field,
     * matching the payload Telegram injects when a Mini App is opened from a group.
     */
    private function buildValidInitData(
        string $chatId = self::GROUP_CHAT_ID,
        int $authDate = 0,
        #[SensitiveParameter] string $signingToken = self::BOT_TOKEN,
    ): string {
        if ($authDate === 0) {
            $authDate = time();
        }

        $params = [
            'auth_date' => (string) $authDate,
            'chat' => json_encode(['id' => (int) $chatId, 'type' => 'supergroup', 'title' => 'Test Group']),
            'user' => json_encode(['id' => 99999, 'first_name' => 'Contributor']),
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

    private function validPayload(string $chatId = self::GROUP_CHAT_ID): array
    {
        return [
            'init_data' => $this->buildValidInitData($chatId),
            'link' => 'https://twitter.com/example',
            'title' => 'My Twitter',
        ];
    }

    // -------------------------------------------------------------------------
    // app() — GET /telegram-app/submit
    // -------------------------------------------------------------------------

    public function testAppRouteReturns200(): void
    {
        $this->get('/telegram-app/submit')->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // store() — validation
    // -------------------------------------------------------------------------

    public function testStoreRequiresInitData(): void
    {
        $this->postJson('/telegram/submit', ['link' => 'https://example.com', 'title' => 'Test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['init_data']);
    }

    public function testStoreRequiresLink(): void
    {
        $this->postJson('/telegram/submit', ['init_data' => 'x', 'title' => 'Test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['link']);
    }

    public function testStoreRequiresTitle(): void
    {
        $this->postJson('/telegram/submit', ['init_data' => 'x', 'link' => 'https://example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function testStoreRejectsInvalidUrl(): void
    {
        $this->postJson('/telegram/submit', ['init_data' => 'x', 'link' => 'not-a-url', 'title' => 'Test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['link']);
    }

    // -------------------------------------------------------------------------
    // store() — group / profile lookup
    // -------------------------------------------------------------------------

    public function testStoreReturns404ForUnknownGroup(): void
    {
        $this->createUser(); // registered for GROUP_CHAT_ID

        $this->postJson('/telegram/submit', $this->validPayload('-9999999999'))
            ->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // store() — HMAC and auth_date
    // -------------------------------------------------------------------------

    public function testStoreRejectsInvalidSignature(): void
    {
        $this->createUser();

        $payload = $this->validPayload();
        $payload['init_data'] = http_build_query([
            'auth_date' => (string) time(),
            'chat' => json_encode(['id' => (int) self::GROUP_CHAT_ID, 'type' => 'supergroup']),
            'user' => json_encode(['id' => 99999]),
            'hash' => 'deadbeef',
        ]);

        $this->postJson('/telegram/submit', $payload)
            ->assertStatus(403)
            ->assertJson(['error' => 'Invalid signature']);
    }

    public function testStoreRejectsExpiredAuthDate(): void
    {
        $this->createUser();

        $expiredDate = time() - 400; // beyond the 300 s TTL
        $payload = $this->validPayload();
        $payload['init_data'] = $this->buildValidInitData(self::GROUP_CHAT_ID, $expiredDate);

        $this->postJson('/telegram/submit', $payload)
            ->assertStatus(403)
            ->assertJson(['error' => 'Token expired']);
    }

    // -------------------------------------------------------------------------
    // store() — link insertion
    // -------------------------------------------------------------------------

    public function testStoreInsertsPendingLink(): void
    {
        $user = $this->createUser();

        $this->postJson('/telegram/submit', $this->validPayload())
            ->assertStatus(201)
            ->assertJson(['status' => 'queued']);

        $this->assertDatabaseHas('links', [
            'user_id' => $user->id,
            'link' => 'https://twitter.com/example',
            'title' => 'My Twitter',
            'status' => 'pending',
        ]);
    }

    public function testStoreInsertsPublishedLinkWhenAutoApproveEnabled(): void
    {
        $user = $this->createUser(autoApprove: true);

        $this->postJson('/telegram/submit', $this->validPayload())
            ->assertStatus(201);

        $this->assertDatabaseHas('links', [
            'user_id' => $user->id,
            'status' => 'published',
        ]);
    }

    public function testStoreUsesDefaultButtonId(): void
    {
        $user = $this->createUser();

        $this->postJson('/telegram/submit', $this->validPayload())
            ->assertStatus(201);

        $this->assertDatabaseHas('links', [
            'user_id' => $user->id,
            'button_id' => self::DEFAULT_BUTTON_ID,
        ]);
    }

    public function testStoreUsesPerProfileTokenWhenSet(): void
    {
        $this->createUser(botToken: self::PER_USER_BOT_TOKEN);

        $initData = $this->buildValidInitData(signingToken: self::PER_USER_BOT_TOKEN);

        $this->postJson('/telegram/submit', [
            'init_data' => $initData,
            'link' => 'https://twitter.com/example',
            'title' => 'My Twitter',
        ])->assertStatus(201);
    }

    public function testStoreRejectsGlobalTokenWhenPerProfileTokenIsSet(): void
    {
        $this->createUser(botToken: self::PER_USER_BOT_TOKEN);

        // Signed with the global token, not the per-profile one
        $this->postJson('/telegram/submit', $this->validPayload())
            ->assertStatus(403)
            ->assertJson(['error' => 'Invalid signature']);
    }

    // -------------------------------------------------------------------------
    // store() — event dispatch
    // -------------------------------------------------------------------------

    public function testStoreFiresPendingLinkSubmittedEventOnPendingLink(): void
    {
        Event::fake();
        $user = $this->createUser();

        $this->postJson('/telegram/submit', $this->validPayload())
            ->assertStatus(201);

        Event::assertDispatched(PendingLinkSubmitted::class, function (PendingLinkSubmitted $event) use ($user) {
            return $event->profileId === $user->id
                && $event->link === 'https://twitter.com/example'
                && $event->title === 'My Twitter';
        });
    }

    public function testStoreDoesNotFireEventWhenAutoApproved(): void
    {
        Event::fake();
        $user = $this->createUser(autoApprove: true);

        $this->postJson('/telegram/submit', $this->validPayload())
            ->assertStatus(201);

        Event::assertNotDispatched(PendingLinkSubmitted::class);
    }
}
