<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OauthController;
use App\Http\Controllers\WebhookController;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/oauth/initiate', [OauthController::class, 'initiate'])->name('oauth.initiate');
Route::get('/oauth/callback', [OauthController::class, 'callback'])->name('oauth.callback');

