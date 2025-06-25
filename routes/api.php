<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WebhookController;



// Single webhook route
Route::post('/webhook', [WebhookController::class, 'handle'])->name('webhook.handle');