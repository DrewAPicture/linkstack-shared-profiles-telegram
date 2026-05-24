<?php

namespace WerdsWords\LinkStack\SharedProfiles;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Laravel\Socialite\Contracts\Factory as Socialite;
use SocialiteProviders\Telegram\Provider as TelegramProvider;
use WerdsWords\LinkStack\SharedProfiles\Events\PendingLinkSubmitted;
use WerdsWords\LinkStack\SharedProfiles\Services\TelegramMessagingService;
use WerdsWords\LinkStack\SharedProfiles\Services\TelegramNotificationService;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/linkstack-shared-profiles.php', 'linkstack-shared-profiles'
        );

        $this->app->singleton(TelegramMessagingService::class, fn () => new TelegramMessagingService);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'linkstack-shared-profiles');

        $this->publishes([
            __DIR__.'/../config/linkstack-shared-profiles.php' => config_path('linkstack-shared-profiles.php'),
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'linkstack-shared-profiles');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/linkstack-shared-profiles'),
        ], 'linkstack-shared-profiles-views');

        // UserController::littlelink() uses DB::table() not Eloquent, so a global
        // scope cannot intercept it. This view composer fires just before the Blade
        // template renders and strips non-published links from the $links collection.
        View::composer('linkstack.linkstack', function ($view) {
            $links = collect((array) ($view->getData()['links'] ?? []));
            $view->with('links', $links->filter(
                fn ($link) => ! isset($link->status) || $link->status === 'published'
            ));
        });

        $this->app->make(Socialite::class)->extend(
            'telegram',
            fn ($app) => $app->make(TelegramProvider::class)
        );

        $this->app->singleton(TelegramNotificationService::class, fn ($app) => new TelegramNotificationService($app->make(TelegramMessagingService::class)));

        Event::listen(PendingLinkSubmitted::class, function (PendingLinkSubmitted $event): void {
            $this->app->make(TelegramNotificationService::class)->notifyModerators(
                $event->profileId,
                $event->linkId,
                $event->link,
                $event->title,
            );
        });
    }
}
