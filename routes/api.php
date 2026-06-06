<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailAccountController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\HospitalController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\MachineController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RevenueController;
use App\Http\Controllers\Api\SalesLeadController;
use App\Http\Controllers\Api\ServiceTicketController;
use App\Http\Controllers\Api\TicketAttachmentController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Auth
    Route::post('auth/login', [AuthController::class, 'login']);

    // License (no auth — must work pre-login for trial gate)
    Route::get('license/check',    [LicenseController::class, 'check']);
    Route::post('license/request', [LicenseController::class, 'store']);

    Route::middleware('auth:sanctum')->group(function () {

        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // Dashboard
        Route::get('dashboard', [DashboardController::class, 'index']);

        // ── Semi-static read endpoints — clients may cache for 60 s ──────────
        // CDN NOTE (Task 14): In production, put Cloudflare (free tier) in front
        // of the production domain. It will honour these Cache-Control headers and
        // serve cached responses from an edge node in Nairobi/Johannesburg,
        // cutting round-trip latency for East-Africa clients from ~120 ms to ~15 ms.
        Route::middleware('cache.headers.api:60')->group(function () {
            Route::get('machines/map', [MachineController::class, 'map']);
            Route::get('revenue/summary', [RevenueController::class, 'summary']);
            Route::get('revenue/by-hospital', [RevenueController::class, 'byHospital']);
            Route::get('reports', [ReportController::class, 'index']);
        });

        // Machines (map is registered above in cache group)
        Route::apiResource('machines', MachineController::class);

        // Hospitals
        Route::apiResource('hospitals', HospitalController::class);

        // Service Tickets
        Route::post('tickets/{ticket}/resolve', [ServiceTicketController::class, 'resolve']);
        Route::post('tickets/{ticket}/checklist/{item}', [ServiceTicketController::class, 'toggleChecklist']);
        Route::get('tickets/{ticket}/attachments', [TicketAttachmentController::class, 'index']);
        Route::post('tickets/{ticket}/attachments', [TicketAttachmentController::class, 'store']);
        Route::delete('tickets/{ticket}/attachments/{attachment}', [TicketAttachmentController::class, 'destroy']);
        Route::apiResource('tickets', ServiceTicketController::class);

        // Invoices & Revenue
        Route::apiResource('invoices', InvoiceController::class);

        // Sales Leads
        Route::patch('leads/{lead}/stage', [SalesLeadController::class, 'updateStage']);
        Route::apiResource('leads', SalesLeadController::class);

        // Inventory
        Route::patch('inventory/{inventoryItem}/adjust', [InventoryController::class, 'adjust']);
        Route::apiResource('inventory', InventoryController::class);

        // Contacts (CRM)
        Route::post('contacts/{contact}/interactions', [ContactController::class, 'addInteraction']);
        Route::apiResource('contacts', ContactController::class);

        // Staff
        Route::get('staff', [StaffController::class, 'index']);
        Route::post('staff', [StaffController::class, 'store']);
        Route::get('staff/{user}', [StaffController::class, 'show']);
        Route::put('staff/{user}', [StaffController::class, 'update']);
        Route::delete('staff/{user}', [StaffController::class, 'destroy']);
        Route::patch('staff/{user}/avail_status', [StaffController::class, 'updateAvailStatus']);

        // Tasks (general — separate from service tickets)
        Route::apiResource('tasks', TaskController::class)->except(['show']);

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);

        // Email accounts (IMAP/SMTP config)
        Route::post('email-accounts/{emailAccount}/test', [EmailAccountController::class, 'test']);
        Route::apiResource('email-accounts', EmailAccountController::class);

        // Email (must be ordered: specific paths before {id})
        Route::get('emails/inbox',        [EmailController::class, 'inbox']);
        Route::get('emails/sent',         [EmailController::class, 'sent']);
        Route::get('emails/drafts',       [EmailController::class, 'drafts']);
        Route::get('emails/unread-count', [EmailController::class, 'unreadCount']);
        Route::get('emails/folders',      [EmailController::class, 'folders']);
        Route::get('emails/folder/{folderName}', [EmailController::class, 'folder']);
        Route::post('emails/sync',        [EmailController::class, 'sync']);
        Route::post('emails/compose',     [EmailController::class, 'compose']);
        Route::get('emails/{syncedEmail}',                    [EmailController::class, 'show']);
        Route::post('emails/{syncedEmail}/reply',             [EmailController::class, 'reply']);
        Route::post('emails/{syncedEmail}/forward',           [EmailController::class, 'forward']);
        Route::patch('emails/{syncedEmail}/read',             [EmailController::class, 'markRead']);
        Route::patch('emails/{syncedEmail}/flag',             [EmailController::class, 'flag']);
        Route::delete('emails/{syncedEmail}',                 [EmailController::class, 'destroy']);
    });
});
