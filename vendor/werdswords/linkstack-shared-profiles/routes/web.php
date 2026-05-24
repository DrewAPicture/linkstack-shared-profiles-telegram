<?php

use Illuminate\Support\Facades\Route;
use WerdsWords\LinkStack\SharedProfiles\Http\Controllers\ModerationController;

// Moderation queue — mirrors LinkStack studio middleware stack
Route::middleware(['web', 'auth', 'blocked'])->prefix('studio')->group(function () {
    Route::get('/moderation', [ModerationController::class, 'index'])
        ->name('linkstack-shared-profiles.moderation');

    Route::post('/moderation/{id}/approve', [ModerationController::class, 'approve'])
        ->name('linkstack-shared-profiles.approve');

    Route::post('/moderation/{id}/reject', [ModerationController::class, 'reject'])
        ->name('linkstack-shared-profiles.reject');
});
