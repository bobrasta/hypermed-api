<?php

use Hypermed\Messaging\Calls\Http\Controllers\CallController;
use Hypermed\Messaging\Calls\Http\Controllers\LiveKitWebhookController;
use Illuminate\Support\Facades\Route;

$prefix = config('messaging.route_prefix', 'api/messaging');

Route::prefix($prefix)
    ->middleware(config('messaging.middleware', ['api', 'auth:sanctum']))
    ->name('messaging.calls.')
    ->group(function () {
        Route::post('conversations/{conversation}/calls', [CallController::class, 'store'])
            ->whereNumber('conversation')->name('store');
        Route::get('calls', [CallController::class, 'index'])->name('index');
        Route::get('calls/{call}', [CallController::class, 'show'])->whereNumber('call')->name('show');
        Route::post('calls/{call}/accept', [CallController::class, 'accept'])->whereNumber('call')->name('accept');
        Route::post('calls/{call}/decline', [CallController::class, 'decline'])->whereNumber('call')->name('decline');
        Route::post('calls/{call}/cancel', [CallController::class, 'cancel'])->whereNumber('call')->name('cancel');
        Route::post('calls/{call}/leave', [CallController::class, 'leave'])->whereNumber('call')->name('leave');
    });

// LiveKit → server webhook: no sanctum, authenticated by signature (WebhookVerifier).
Route::prefix($prefix)
    ->middleware(config('messaging-calls.webhook_middleware', ['api']))
    ->post('livekit/webhook', LiveKitWebhookController::class)
    ->name('messaging.calls.livekit-webhook');
