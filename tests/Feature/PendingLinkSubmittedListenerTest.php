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
use WerdsWords\LinkStack\SharedProfiles\Providers\Contracts\NotifierContract;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\ServiceProvider;
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
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

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

        Schema::create('provider_managers', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('external_id');
            $table->unsignedBigInteger('profile_id');
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

        $this->beforeApplicationDestroyed(function () {
            Schema::dropIfExists('provider_settings');
            Schema::dropIfExists('provider_managers');
            Schema::dropIfExists('users');
        });
    }

    protected function tearDown(): void
    {
        CoreServiceProvider::flushNotifiers();
        parent::tearDown();
    }

    public function testListenerCallsNotifyModeratorsWhenEventDispatched(): void
    {
        // Replace the notifier registered during boot with a mock so we can
        // assert the fanout mechanism calls through correctly.
        CoreServiceProvider::flushNotifiers();

        $mock = Mockery::mock(NotifierContract::class);
        $mock->shouldReceive('notifyModerators')
            ->once()
            ->with(1, 42, 'https://example.com', 'My Link');
        CoreServiceProvider::registerNotifier($mock);

        event(new PendingLinkSubmitted(1, 42, 'https://example.com', 'My Link'));
    }

    public function testListenerIsNotCalledWhenEventIsNotDispatched(): void
    {
        CoreServiceProvider::flushNotifiers();

        $mock = Mockery::mock(NotifierContract::class);
        $mock->shouldReceive('notifyModerators')->never();
        CoreServiceProvider::registerNotifier($mock);

        $this->assertTrue(true);
    }
}
