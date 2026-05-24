<?php

use Illuminate\Support\Facades\Route;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers\AuthController;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Http\Controllers\SubmitController;

// Approach A: browser-based Telegram Login Widget
// {profileId} encodes which profile is authenticating so the callback can
// resolve the right bot token without session state.
Route::middleware('web')->group(function () {
    Route::get('/telegram-auth/{profileId}', [AuthController::class, 'redirect'])
        ->name('linkstack-shared-profiles.telegram.redirect');

    Route::get('/telegram-auth/{profileId}/callback', [AuthController::class, 'callback'])
        ->name('linkstack-shared-profiles.telegram.callback');
});

// Telegram Mini App views — served via standard web middleware.
Route::middleware('web')->group(function () {
    Route::get('/telegram-app/submit', [SubmitController::class, 'app'])
        ->name('linkstack-shared-profiles.telegram-app.submit');

    Route::get('/telegram-app/moderate', fn () => view('linkstack-shared-profiles::telegram-app.moderate'))
        ->name('linkstack-shared-profiles.telegram-app.moderate');
});
