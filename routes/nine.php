<?php

use Illuminate\Support\Facades\Route;
use Nine\Laravel\Http\Controllers\AuthController;
use Nine\Laravel\Http\Controllers\WebhookController;
use Nine\Laravel\Http\Middleware\VerifyWebhookSignature;

$prefix = config('nine.routes.prefix', 'nine');

Route::group(['prefix' => $prefix, 'middleware' => config('nine.routes.middleware', ['web'])], function () {
    Route::get('login', [AuthController::class, 'login'])->name('nine.login');
    Route::get('callback', [AuthController::class, 'callback'])->name('nine.callback');
    Route::match(['GET', 'POST'], 'logout', [AuthController::class, 'logout'])->name('nine.logout');
});

// Outside the `web` group: no session or CSRF; authenticated by the 9in-signature header.
if (config('nine.webhook.secret')) {
    Route::post($prefix . '/webhook', WebhookController::class)
        ->middleware(VerifyWebhookSignature::class)
        ->name('nine.webhook');
}
