<?php

use Illuminate\Support\Facades\Route;
use WerdsWords\LinkStack\SharedProfiles\Http\Controllers\ApiLinkController;

Route::middleware('throttle:60,1')->group(function () {
    Route::get('/api/links', [ApiLinkController::class, 'index'])
        ->name('linkstack-shared-profiles.api.links.index');

    Route::post('/api/links', [ApiLinkController::class, 'store'])
        ->name('linkstack-shared-profiles.api.links');

    Route::post('/api/links/{id}/approve', [ApiLinkController::class, 'approve'])
        ->name('linkstack-shared-profiles.api.links.approve');

    Route::delete('/api/links/{id}', [ApiLinkController::class, 'deny'])
        ->name('linkstack-shared-profiles.api.links.deny');
});
