<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\SocialiteServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WerdsWords\LinkStack\SharedProfiles\Events\PendingLinkSubmitted;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\ServiceProvider;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Services\NotificationService;
use WerdsWords\LinkStack\SharedProfiles\ServiceProvider as CoreServiceProvider;

#[CoversClass(ServiceProvider::class)]
final class PendingLinkSubmittedListenerTest extends TestCase
{
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
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('services.telegram.client_id', 'test-bot-id');
        $app['config']->set('services.telegram.client_secret', 'test-bot-token');
        $app['config']->set('services.telegram.redirect', 'https://example.com/callback');

        $app['config']->set('linkstack-shared-profiles-telegram.bot_token', 'test-token');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('telegram_managers', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_id')->unique();
            $table->unsignedBigInteger('profile_id');
            $table->enum('role', ['owner', 'moderator'])->default('moderator');
            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $this->beforeApplicationDestroyed(function () {
            Schema::dropIfExists('telegram_managers');
            Schema::dropIfExists('users');
        });
    }

    public function testListenerCallsNotifyModeratorsWhenEventDispatched(): void
    {
        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('notifyModerators')
            ->once()
            ->with(1, 42, 'https://example.com', 'My Link');
        $this->app->instance(NotificationService::class, $mock);

        event(new PendingLinkSubmitted(1, 42, 'https://example.com', 'My Link'));
    }

    public function testListenerIsNotCalledWhenEventIsNotDispatched(): void
    {
        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('notifyModerators')->never();
        $this->app->instance(NotificationService::class, $mock);

        $this->assertTrue(true);
    }
}
