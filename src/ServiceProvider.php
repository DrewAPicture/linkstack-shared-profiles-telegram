<?php

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Laravel\Socialite\Contracts\Factory as Socialite;
use SocialiteProviders\Telegram\Provider as TelegramProvider;
use WerdsWords\LinkStack\SharedProfiles\Events\PendingLinkSubmitted;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Services\MessagingService;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Services\NotificationService;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/linkstack-shared-profiles-telegram.php', 'linkstack-shared-profiles-telegram'
        );

        $this->app->singleton(MessagingService::class, fn () => new MessagingService);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'linkstack-shared-profiles');

        $this->publishes([
            __DIR__.'/../config/linkstack-shared-profiles-telegram.php' => config_path('linkstack-shared-profiles-telegram.php'),
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'linkstack-shared-profiles-telegram');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/linkstack-shared-profiles'),
        ], 'linkstack-shared-profiles-telegram-views');

        $this->loadRoutesFrom(__DIR__.'/../routes/telegram.php');

        $this->app->make(Socialite::class)->extend(
            'telegram',
            fn ($app) => $app->make(TelegramProvider::class)
        );

        $this->app->singleton(NotificationService::class, fn ($app) => new NotificationService($app->make(MessagingService::class)));

        Event::listen(PendingLinkSubmitted::class, function (PendingLinkSubmitted $event): void {
            $this->app->make(NotificationService::class)->notifyModerators(
                $event->profileId,
                $event->linkId,
                $event->link,
                $event->title,
            );
        });
    }
}
