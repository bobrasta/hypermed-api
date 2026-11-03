<?php

use Hypermed\Messaging\Http\Controllers\AttachmentController;
use Hypermed\Messaging\Http\Controllers\AuditController;
use Hypermed\Messaging\Http\Controllers\ConversationController;
use Hypermed\Messaging\Http\Controllers\DeliveredController;
use Hypermed\Messaging\Http\Controllers\DeviceController;
use Hypermed\Messaging\Http\Controllers\MessageController;
use Hypermed\Messaging\Http\Controllers\ParticipantController;
use Hypermed\Messaging\Http\Controllers\ReadController;
use Hypermed\Messaging\Http\Controllers\SettingsController;
use Hypermed\Messaging\Http\Controllers\UserController;
use Hypermed\Messaging\Http\Middleware\EnsureConversationParticipant;
use Hypermed\Messaging\Http\Middleware\EnsureMessageParticipant;
use Illuminate\Support\Facades\Route;

$prefix = config('messaging.route_prefix', 'api/messaging');

Route::prefix($prefix)
    ->middleware(config('messaging.middleware', ['api', 'auth:sanctum']))
    ->name('messaging.')
    ->group(function () {
        Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::post('conversations/direct', [ConversationController::class, 'storeDirect'])->name('conversations.direct');
        Route::post('conversations/group', [ConversationController::class, 'storeGroup'])->name('conversations.group');

        Route::middleware(EnsureConversationParticipant::class)
            ->prefix('conversations/{conversation}')
            ->name('conversations.')
            ->group(function () {
                Route::get('/', [ConversationController::class, 'show'])->whereNumber('conversation')->name('show');
                Route::post('mute', [ConversationController::class, 'mute'])->whereNumber('conversation')->name('mute');
                Route::post('participants', [ParticipantController::class, 'store'])->whereNumber('conversation')->name('participants.store');
                Route::delete('participants/{user}', [ParticipantController::class, 'destroy'])
                    ->whereNumber(['conversation', 'user'])->name('participants.destroy');
                Route::get('messages', [MessageController::class, 'index'])->whereNumber('conversation')->name('messages.index');
                Route::post('messages', [MessageController::class, 'store'])->whereNumber('conversation')->name('messages.store');
                Route::post('attachments', [AttachmentController::class, 'store'])->whereNumber('conversation')->name('attachments.store');
                Route::post('read', [ReadController::class, 'store'])->whereNumber('conversation')->name('read');
                Route::post('delivered', [DeliveredController::class, 'store'])->whereNumber('conversation')->name('delivered');
            });

        Route::middleware(EnsureMessageParticipant::class)->group(function () {
            Route::patch('messages/{message}', [MessageController::class, 'update'])->whereNumber('message')->name('messages.update');
            Route::delete('messages/{message}', [MessageController::class, 'destroy'])->whereNumber('message')->name('messages.destroy');
        });

        Route::get('settings', [SettingsController::class, 'show'])->name('settings');
        Route::get('audit/users/{user}/conversations', [AuditController::class, 'conversations'])
            ->whereNumber('user')->name('audit.conversations');
        Route::get('audit/conversations/{conversation}/messages', [AuditController::class, 'messages'])
            ->whereNumber('conversation')->name('audit.messages');
        Route::get('unread-summary', [ReadController::class, 'summary'])->name('unread-summary');
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
        Route::delete('devices', [DeviceController::class, 'destroy'])->name('devices.destroy');
    });

// Signed-URL download: no bearer token (images load directly in clients).
Route::prefix($prefix)
    ->get('attachments/{attachment}/download', [AttachmentController::class, 'download'])
    ->whereNumber('attachment')
    ->name('messaging.attachments.download');
