<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers\AuthController;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers\SubmitController;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers\WebhookController;

// Approach A: browser-based Telegram Login Widget
// {profileId} encodes which profile is authenticating so the callback can
// resolve the right bot token without session state.
Route::middleware('web')->group(function () {
    Route::get('/telegram-auth/{profileId}', [AuthController::class, 'redirect'])
        ->name('linkstack-shared-profiles.telegram.redirect');

    Route::get('/telegram-auth/{profileId}/callback', [AuthController::class, 'callback'])
        ->name('linkstack-shared-profiles.telegram.callback');
});

// Telegram Webhook — server-to-server; no session, no CSRF (HMAC secret is the auth)
Route::post('/telegram/webhook', [WebhookController::class, 'handle'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('linkstack-shared-profiles.telegram.webhook');

// Approach B: Telegram Mini App initData — needs sessions but no CSRF token
// (HMAC-signed initData is the security; Mini Apps cannot send a CSRF token)
Route::post('/telegram-login', [AuthController::class, 'initDataLogin'])
    ->middleware('web')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('linkstack-shared-profiles.telegram.initdata');

// Telegram Mini App views — contributor submission form and moderator auth wrapper.
// initData HMAC is the security layer; no session or CSRF token is needed.
Route::middleware('web')->group(function () {
    Route::get('/telegram-app/submit', [SubmitController::class, 'app'])
        ->name('linkstack-shared-profiles.telegram-app.submit');

    Route::get('/telegram-app/moderate', fn () => view('linkstack-shared-profiles::telegram-app.moderate'))
        ->name('linkstack-shared-profiles.telegram-app.moderate');

    Route::post('/telegram/submit', [SubmitController::class, 'store'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('linkstack-shared-profiles.telegram.submit');
});
