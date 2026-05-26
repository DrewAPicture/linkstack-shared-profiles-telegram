<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\SocialiteServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Exceptions\ChatAlreadyBoundException;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Exceptions\ManagerNotFoundException;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers\WebhookController;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\ServiceProvider;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Services\MessagingService;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Tests\Support\Models\User;
use WerdsWords\LinkStack\SharedProfiles\ServiceProvider as CoreServiceProvider;

#[CoversClass(WebhookController::class)]
final class WebhookControllerTest extends TestCase
{
    private const WEBHOOK_SECRET = 'test-webhook-secret';

    private const BOT_TOKEN = 'test-bot-token';

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
        $app['config']->set('app.url', 'https://example.com');

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
        $app['config']->set('linkstack-shared-profiles-telegram.webhook_secret', self::WEBHOOK_SECRET);
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
            $table->string('api_token', 64)->unique()->nullable();
            $table->timestamps();
        });

        Schema::create('provider_managers', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('external_id');
            $table->unsignedBigInteger('profile_id');
            $table->foreign('profile_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('role')->default('moderator');
            $table->string('added_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('provider_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id');
            $table->string('provider');
            $table->text('settings');
            $table->unique(['profile_id', 'provider']);
        });

        Schema::create('telegram_group_chats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('profile_id');
            $table->foreign('profile_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('chat_id')->unique();
            $table->timestamp('created_at')->useCurrent();
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

        $this->beforeApplicationDestroyed(function () {
            Schema::dropIfExists('links');
            Schema::dropIfExists('telegram_group_chats');
            Schema::dropIfExists('provider_settings');
            Schema::dropIfExists('provider_managers');
            Schema::dropIfExists('users');
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createUser(string $email = 'test@example.com'): User
    {
        return User::create(['name' => 'Test User', 'email' => $email]);
    }

    private function createManager(int $profileId, string $telegramId): void
    {
        DB::table('provider_managers')->insert([
            'provider' => 'telegram',
            'external_id' => $telegramId,
            'profile_id' => $profileId,
            'role' => 'moderator',
            'created_at' => now(),
        ]);
    }

    private function createOwner(int $profileId, string $telegramId): void
    {
        DB::table('provider_managers')->insert([
            'provider' => 'telegram',
            'external_id' => $telegramId,
            'profile_id' => $profileId,
            'role' => 'owner',
            'created_at' => now(),
        ]);
    }

    private function groupMessageUpdate(
        string $text,
        string $telegramId = '12345678',
        string $chatId = '-100987654321'
    ): array {
        return [
            'message' => [
                'message_id' => 1,
                'from' => ['id' => (int) $telegramId, 'username' => 'testuser'],
                'chat' => ['id' => (int) $chatId, 'type' => 'supergroup'],
                'text' => $text,
            ],
        ];
    }

    private function createLink(int $profileId, string $status = 'pending'): int
    {
        return (int) DB::table('links')->insertGetId([
            'user_id' => $profileId,
            'link' => 'https://example.com',
            'title' => 'Test Link',
            'button_id' => 1,
            'type' => 'predefined',
            'status' => $status,
            'order' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function postWebhook(array $payload, string $secret = self::WEBHOOK_SECRET): TestResponse
    {
        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => $secret])
            ->postJson('/telegram/webhook', $payload);
    }

    private function messageUpdate(string $text, string $telegramId = '12345678'): array
    {
        return [
            'message' => [
                'message_id' => 1,
                'from' => ['id' => (int) $telegramId, 'username' => 'testuser'],
                'chat' => ['id' => (int) $telegramId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }

    private function callbackUpdate(string $callbackData, string $telegramId = '12345678', int $messageId = 99): array
    {
        return [
            'callback_query' => [
                'id' => 'callback-query-id',
                'from' => ['id' => (int) $telegramId, 'username' => 'testuser'],
                'message' => [
                    'message_id' => $messageId,
                    'chat' => ['id' => (int) $telegramId],
                ],
                'data' => $callbackData,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // handle() — secret verification
    // -------------------------------------------------------------------------

    public function testMissingSecretHeaderReturns403(): void
    {
        $this->postJson('/telegram/webhook', [])->assertStatus(403);
    }

    public function testWrongSecretHeaderReturns403(): void
    {
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret'])
            ->postJson('/telegram/webhook', [])
            ->assertStatus(403);
    }

    public function testValidSecretWithUnknownUpdateTypeReturns200(): void
    {
        $this->postWebhook([])->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // handleMessage() — /auth command
    // -------------------------------------------------------------------------

    public function testAuthCommandFromKnownManagerSendsDm(): void
    {
        $user = $this->createUser();
        $this->createManager($user->id, '12345678');

        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->withArgs(fn ($token, $chatId, $text, $keyboard) => $chatId === '12345678'
                && str_contains($keyboard[0][0]['url'] ?? '', '/telegram-auth/'.$user->id));
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->messageUpdate('/auth'))->assertStatus(200);
    }

    public function testAuthCommandButtonLabelUsesAppName(): void
    {
        $this->app['config']->set('app.name', 'MyStack');
        $user = $this->createUser();
        $this->createManager($user->id, '12345678');

        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendMessageWithKeyboard')
            ->once()
            ->withArgs(fn ($token, $chatId, $text, $keyboard) => ($keyboard[0][0]['text'] ?? '') === 'Log in to MyStack');
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->messageUpdate('/auth'))->assertStatus(200);
    }

    public function testAuthCommandFromUnknownUserIsIgnored(): void
    {
        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendMessageWithKeyboard')->never();
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->messageUpdate('/auth', '9999999'))->assertStatus(200);
    }

    public function testNonAuthTextIsIgnored(): void
    {
        $user = $this->createUser();
        $this->createManager($user->id, '12345678');

        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendMessageWithKeyboard')->never();
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->messageUpdate('hello'))->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // handleInteraction() — approve / reject callback queries
    // -------------------------------------------------------------------------

    public function testApproveCallbackPublishesLink(): void
    {
        $user = $this->createUser();
        $this->createManager($user->id, '12345678');
        $linkId = $this->createLink($user->id);

        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('answerCallbackQuery')->once()->andReturn(true);
        $mock->shouldReceive('editMessageText')
            ->once()
            ->withArgs(fn ($token, $chatId, $messageId, $text) => str_contains($text, '✅'));
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->callbackUpdate("approve:{$linkId}"))->assertStatus(200);

        $this->assertDatabaseHas('links', ['id' => $linkId, 'status' => 'published']);
    }

    public function testRejectCallbackDeletesLink(): void
    {
        $user = $this->createUser();
        $this->createManager($user->id, '12345678');
        $linkId = $this->createLink($user->id);

        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('answerCallbackQuery')->once()->andReturn(true);
        $mock->shouldReceive('editMessageText')
            ->once()
            ->withArgs(fn ($token, $chatId, $messageId, $text) => str_contains($text, '❌'));
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->callbackUpdate("reject:{$linkId}"))->assertStatus(200);

        $this->assertDatabaseMissing('links', ['id' => $linkId]);
    }

    public function testCallbackFromDifferentProfileModeratorIsIgnored(): void
    {
        $user = $this->createUser();
        $other = $this->createUser('other@example.com');
        $this->createManager($other->id, '12345678'); // moderator for OTHER profile
        $linkId = $this->createLink($user->id);       // link belongs to $user

        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('answerCallbackQuery')->once()->andReturn(true);
        $mock->shouldReceive('editMessageText')->never();
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->callbackUpdate("approve:{$linkId}"))->assertStatus(200);

        $this->assertDatabaseHas('links', ['id' => $linkId, 'status' => 'pending']);
    }

    public function testUnknownCallbackFormatAnswersAndReturns(): void
    {
        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('answerCallbackQuery')->once()->andReturn(true);
        $mock->shouldReceive('editMessageText')->never();
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->callbackUpdate('unknown:format'))->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // handleMessage() — /setup command
    // -------------------------------------------------------------------------

    public function testSetupCommandFromOwnerInGroupRecordsGroupChat(): void
    {
        $user = $this->createUser();
        $this->createOwner($user->id, '12345678');

        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendMessageWithWebAppButton')->once()->andReturn(true);
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->groupMessageUpdate('/setup'))->assertStatus(200);

        $this->assertDatabaseHas('telegram_group_chats', [
            'profile_id' => $user->id,
            'chat_id' => '-100987654321',
        ]);
    }

    public function testSetupCommandFromOwnerInGroupSendsSubmitButton(): void
    {
        $user = $this->createUser();
        $this->createOwner($user->id, '12345678');

        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendMessageWithWebAppButton')
            ->once()
            ->withArgs(fn ($token, $chatId, $text, $label, $url) => $label === 'Submit a Link'
                && str_contains($url, '/telegram-app/submit'));
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->groupMessageUpdate('/setup'))->assertStatus(200);
    }

    public function testSetupCommandFromModeratorIsIgnored(): void
    {
        $user = $this->createUser();
        $this->createManager($user->id, '12345678');

        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendMessageWithWebAppButton')->never();
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->groupMessageUpdate('/setup'))->assertStatus(200);

        $this->assertDatabaseMissing('telegram_group_chats', ['profile_id' => $user->id]);
    }

    public function testSetupCommandFromUnknownUserIsIgnored(): void
    {
        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendMessageWithWebAppButton')->never();
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->groupMessageUpdate('/setup', '9999999'))->assertStatus(200);

        $this->assertDatabaseEmpty('telegram_group_chats');
    }

    public function testSetupCommandInPrivateChatIsIgnored(): void
    {
        $user = $this->createUser();
        $this->createOwner($user->id, '12345678');

        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendMessageWithWebAppButton')->never();
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->messageUpdate('/setup'))->assertStatus(200);

        $this->assertDatabaseMissing('telegram_group_chats', ['profile_id' => $user->id]);
    }

    public function testSetupCommandIsIdempotentForSameProfile(): void
    {
        $user = $this->createUser();
        $this->createOwner($user->id, '12345678');

        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendMessageWithWebAppButton')->twice()->andReturn(true);
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->groupMessageUpdate('/setup'))->assertStatus(200);
        $this->postWebhook($this->groupMessageUpdate('/setup'))->assertStatus(200);

        $this->assertSame(1, DB::table('telegram_group_chats')->count());
    }

    public function testSetupCommandIgnoredWhenChatBoundToDifferentProfile(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser('b@example.com');
        $this->createOwner($userA->id, '12345678');

        DB::table('telegram_group_chats')->insert([
            'profile_id' => $userB->id,
            'chat_id' => '-100987654321',
            'created_at' => now(),
        ]);

        $mock = Mockery::mock(MessagingService::class);
        $mock->shouldReceive('sendMessageWithWebAppButton')->never();
        $this->app->instance(MessagingService::class, $mock);

        $this->postWebhook($this->groupMessageUpdate('/setup'))->assertStatus(200);

        $this->assertSame(1, DB::table('telegram_group_chats')->count());
    }

    // -------------------------------------------------------------------------
    // resolveManager() — direct unit tests via anonymous subclass
    // -------------------------------------------------------------------------

    public function testResolveManagerReturnsManagerForKnownOwner(): void
    {
        $user = $this->createUser();
        $this->createOwner($user->id, '12345678');

        $controller = new class($this->app->make(MessagingService::class)) extends WebhookController
        {
            public function exposeResolveManager(string $telegramId): \WerdsWords\LinkStack\SharedProfiles\Providers\Models\ProviderManager
            {
                return $this->resolveManager($telegramId);
            }
        };

        $manager = $controller->exposeResolveManager('12345678');

        $this->assertSame($user->id, $manager->profile_id);
    }

    public function testResolveManagerThrowsForUnknownTelegramId(): void
    {
        $this->expectException(ManagerNotFoundException::class);

        $controller = new class($this->app->make(MessagingService::class)) extends WebhookController
        {
            public function exposeResolveManager(string $telegramId): \WerdsWords\LinkStack\SharedProfiles\Providers\Models\ProviderManager
            {
                return $this->resolveManager($telegramId);
            }
        };

        $controller->exposeResolveManager('9999999');
    }

    public function testResolveManagerThrowsForNonOwner(): void
    {
        $user = $this->createUser();
        $this->createManager($user->id, '12345678');

        $this->expectException(ManagerNotFoundException::class);

        $controller = new class($this->app->make(MessagingService::class)) extends WebhookController
        {
            public function exposeResolveManager(string $telegramId): \WerdsWords\LinkStack\SharedProfiles\Providers\Models\ProviderManager
            {
                return $this->resolveManager($telegramId);
            }
        };

        $controller->exposeResolveManager('12345678');
    }

    // -------------------------------------------------------------------------
    // resolveGroupChat() — direct unit tests via anonymous subclass
    // -------------------------------------------------------------------------

    public function testResolveGroupChatReturnsNullWhenChatNotFound(): void
    {
        $controller = new class($this->app->make(MessagingService::class)) extends WebhookController
        {
            public function exposeResolveGroupChat(string $chatId, int $profileId): ?\stdClass
            {
                return $this->resolveGroupChat($chatId, $profileId);
            }
        };

        $result = $controller->exposeResolveGroupChat('-100987654321', 1);

        $this->assertNull($result);
    }

    public function testResolveGroupChatReturnsRecordWhenBoundToSameProfile(): void
    {
        $user = $this->createUser();

        DB::table('telegram_group_chats')->insert([
            'profile_id' => $user->id,
            'chat_id' => '-100987654321',
            'created_at' => now(),
        ]);

        $controller = new class($this->app->make(MessagingService::class)) extends WebhookController
        {
            public function exposeResolveGroupChat(string $chatId, int $profileId): ?\stdClass
            {
                return $this->resolveGroupChat($chatId, $profileId);
            }
        };

        $record = $controller->exposeResolveGroupChat('-100987654321', $user->id);

        $this->assertNotNull($record);
        $this->assertSame((string) $user->id, (string) $record->profile_id);
    }

    public function testResolveGroupChatThrowsWhenBoundToDifferentProfile(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser('b@example.com');

        DB::table('telegram_group_chats')->insert([
            'profile_id' => $userB->id,
            'chat_id' => '-100987654321',
            'created_at' => now(),
        ]);

        $this->expectException(ChatAlreadyBoundException::class);

        $controller = new class($this->app->make(MessagingService::class)) extends WebhookController
        {
            public function exposeResolveGroupChat(string $chatId, int $profileId): ?\stdClass
            {
                return $this->resolveGroupChat($chatId, $profileId);
            }
        };

        $controller->exposeResolveGroupChat('-100987654321', $userA->id);
    }
}
