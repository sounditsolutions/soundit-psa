<?php

use App\Http\Controllers\Portal\PortalAccountController;
use App\Http\Controllers\Portal\PortalAssetController;
use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalContractController;
use App\Http\Controllers\Portal\PortalDashboardController;
use App\Http\Controllers\Portal\PortalInvoiceController;
use App\Http\Controllers\Portal\PortalPrepayController;
use App\Http\Controllers\Portal\PortalTicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Client Portal Routes
|--------------------------------------------------------------------------
|
| Routes for the client-facing portal. All routes use the 'portal.' name
| prefix and are loaded under the /portal URL prefix via bootstrap/app.php.
|
*/

// ── Guest routes (login, password reset) ──

Route::middleware(['portal.enabled', 'throttle:6,1'])->group(function () {
    Route::get('/login', [PortalAuthController::class, 'showLogin'])->name('portal.login');
    Route::post('/login', [PortalAuthController::class, 'login']);

    Route::get('/forgot-password', [PortalAuthController::class, 'showForgotPassword'])->name('portal.password.request');
    Route::post('/forgot-password', [PortalAuthController::class, 'sendResetLink'])->name('portal.password.email');

    Route::get('/reset-password/{token}', [PortalAuthController::class, 'showResetForm'])->name('portal.password.reset');
    Route::post('/reset-password', [PortalAuthController::class, 'resetPassword'])->name('portal.password.update');

    Route::get('/request-access', [PortalAuthController::class, 'showRequestAccess'])->name('portal.request-access');
    Route::post('/request-access', [PortalAuthController::class, 'sendAccessLink']);
    Route::get('/verify-access/{person}', [PortalAuthController::class, 'verifyAccess'])->name('portal.verify-access');
});

Route::middleware('portal.enabled')->group(function () {
    Route::post('/logout', [PortalAuthController::class, 'logout'])->name('portal.logout');
});

// ── Authenticated portal routes ──

Route::middleware(['portal.enabled', 'portal.auth', 'portal.scope'])->group(function () {
    Route::get('/', fn () => redirect()->route('portal.dashboard'));

    // Dashboard
    Route::get('/dashboard', [PortalDashboardController::class, 'index'])->name('portal.dashboard');

    // Tickets
    Route::get('/tickets', [PortalTicketController::class, 'index'])->name('portal.tickets.index');
    Route::get('/tickets/create', [PortalTicketController::class, 'create'])->name('portal.tickets.create');
    Route::post('/tickets', [PortalTicketController::class, 'store'])->name('portal.tickets.store');
    Route::get('/tickets/{ticket}', [PortalTicketController::class, 'show'])->name('portal.tickets.show');
    Route::post('/tickets/{ticket}/reply', [PortalTicketController::class, 'reply'])->name('portal.tickets.reply')->middleware('throttle:10,1');
    Route::post('/tickets/{ticket}/attachments', [PortalTicketController::class, 'uploadAttachment'])->name('portal.tickets.attachments.store');
    Route::post('/tickets/{ticket}/confirm-resolved', [PortalTicketController::class, 'confirmResolved'])->name('portal.tickets.confirm-resolved');
    Route::post('/tickets/{ticket}/reopen', [PortalTicketController::class, 'reopen'])->name('portal.tickets.reopen');

    // Attachments
    Route::get('/attachments/{attachment}/{filename}', function (
        \App\Models\Attachment $attachment,
        string $filename,
        \Illuminate\Http\Request $request,
    ) {
        if ($attachment->filename !== $filename) {
            abort(404);
        }

        $clientId = $request->attributes->get('portal_client_id');
        $allowed = false;

        if ($attachment->attachable_type === 'App\\Models\\Ticket') {
            $allowed = \App\Models\Ticket::where('id', $attachment->attachable_id)
                ->where('client_id', $clientId)->exists();
        } elseif ($attachment->attachable_type === 'App\\Models\\TicketNote') {
            $note = \App\Models\TicketNote::find($attachment->attachable_id);
            $allowed = $note && \App\Models\Ticket::where('id', $note->ticket_id)
                ->where('client_id', $clientId)->exists();
        }

        if (!$allowed) {
            abort(403);
        }

        if (!\Illuminate\Support\Facades\Storage::disk('local')->exists($attachment->storage_path)) {
            abort(404);
        }

        $disposition = $attachment->isImage() ? 'inline' : 'attachment';

        return \Illuminate\Support\Facades\Storage::disk('local')->response(
            $attachment->storage_path,
            $attachment->original_filename,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => "{$disposition}; filename=\"" . str_replace(['"', "\r", "\n"], '', $attachment->original_filename) . "\"",
            ],
        );
    })->name('portal.attachments.show');

    // Invoices
    Route::get('/invoices', [PortalInvoiceController::class, 'index'])->name('portal.invoices.index');
    Route::get('/invoices/{invoice}', [PortalInvoiceController::class, 'show'])->name('portal.invoices.show');

    // Devices (Assets)
    Route::get('/devices', [PortalAssetController::class, 'index'])->name('portal.assets.index');
    Route::get('/devices/{asset}', [PortalAssetController::class, 'show'])->name('portal.assets.show');

    // Service Agreements (Contracts)
    Route::get('/agreements', [PortalContractController::class, 'index'])->name('portal.contracts.index');
    Route::get('/agreements/{contract}', [PortalContractController::class, 'show'])->name('portal.contracts.show');

    // Prepaid Time Purchase
    Route::get('/prepaid/purchase', [PortalPrepayController::class, 'selectContract'])->name('portal.prepaid.select');
    Route::get('/prepaid/purchase/{contract}', [PortalPrepayController::class, 'showPurchaseForm'])->name('portal.prepaid.form');
    Route::post('/prepaid/purchase/{contract}', [PortalPrepayController::class, 'store'])->name('portal.prepaid.store')->middleware('throttle:3,5');
    Route::get('/prepaid/confirmation/{invoice}', [PortalPrepayController::class, 'confirmation'])->name('portal.prepaid.confirmation');
    Route::get('/prepaid/payment-status/{invoice}', [PortalPrepayController::class, 'paymentStatus'])->name('portal.prepaid.payment-status');
    Route::put('/prepaid/{contract}/alert-settings', [PortalPrepayController::class, 'updateAlertSettings'])->name('portal.prepaid.update-alert-settings');

    // Impersonation
    Route::post('/stop-impersonating', [PortalAuthController::class, 'stopImpersonating'])->name('portal.stop-impersonating');

    // Account
    Route::get('/account', [PortalAccountController::class, 'edit'])->name('portal.account');
    Route::put('/account', [PortalAccountController::class, 'update'])->name('portal.account.update');
    Route::put('/account/password', [PortalAccountController::class, 'updatePassword'])->name('portal.account.password');
});
