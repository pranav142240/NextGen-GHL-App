<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\TestController;
use App\Http\Controllers\Api\ttController;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/


Route::prefix('v1')->name('v1.')->group(function () {
    // Webhook routes
    Route::prefix('webhook')->name('webhook.')->group(function () {
        Route::post('/upsertContact', [WebhookController::class, 'handle'])->name('handle');
        Route::post('/', [WebhookController::class, 'processHandle'])->name('processHandle');
    });

    // Test routes
    Route::prefix('test')->name('test.')->group(function () {
        Route::post('/webhook', [TestController::class, 'handle'])->name('webhook');
    });
});
