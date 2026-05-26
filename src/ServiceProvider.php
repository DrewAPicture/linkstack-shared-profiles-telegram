<?php

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram;

use Laravel\Socialite\Contracts\Factory as Socialite;
use SocialiteProviders\Manager\Config as SocialiteConfig;
use SocialiteProviders\Telegram\Provider as TelegramProvider;
use WerdsWords\LinkStack\SharedProfiles\Providers\ServiceProvider as CoreProviderServiceProvider;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers\AuthController;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers\SubmitController;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers\WebhookController;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Services\MessagingService;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Services\NotificationService;
use WerdsWords\LinkStack\SharedProfiles\ServiceProvider as CoreServiceProvider;

class ServiceProvider extends CoreProviderServiceProvider
{
    public function getProviderName(): string
    {
        return 'telegram';
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/linkstack-shared-profiles-telegram.php', 'linkstack-shared-profiles-telegram'
        );

        /** @var \Illuminate\Config\Repository $config */
        $config = $this->app->make('config');
        $config->set('logging.channels.telegram-webhook', [
            'driver' => 'single',
            'path' => storage_path('logs/telegram-webhook.log'),
            'level' => 'debug',
        ]);

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

        $this->registerInteractionRoute(
            '/telegram-login',
            [AuthController::class, 'initDataLogin'],
            'linkstack-shared-profiles.telegram.initdata'
        );

        $this->registerInteractionRoute(
            '/telegram/submit',
            [SubmitController::class, 'store'],
            'linkstack-shared-profiles.telegram.submit'
        );

        $this->registerInteractionRoute(
            '/telegram/webhook',
            [WebhookController::class, 'handle'],
            'linkstack-shared-profiles.telegram.webhook'
        );

        /** @var \Laravel\Socialite\SocialiteManager $socialiteManager */
        $socialiteManager = $this->app->make(Socialite::class);
        $socialiteManager->extend(
            'telegram',
            function ($app) use ($socialiteManager) {
                $raw = (array) config('services.telegram', []);

                /** @var TelegramProvider $provider */
                $provider = $socialiteManager->buildProvider(TelegramProvider::class, $raw);
                $clientId = $raw['client_id'] ?? '';
                $clientSecret = $raw['client_secret'] ?? '';
                $redirect = $raw['redirect'] ?? '';
                $bot = $raw['bot'] ?? '';

                $provider->setConfig(new SocialiteConfig(
                    is_string($clientId) ? $clientId : '',
                    is_string($clientSecret) ? $clientSecret : '',
                    is_string($redirect) ? $redirect : '',
                    ['bot' => is_string($bot) ? $bot : ''],
                ));

                return $provider;
            }
        );

        CoreServiceProvider::registerNotifier(
            new NotificationService($this->app->make(MessagingService::class))
        );
    }
}
