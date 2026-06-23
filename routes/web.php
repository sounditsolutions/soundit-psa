<?php

use App\Http\Controllers\Web\AboutController;
use App\Http\Controllers\Web\AlertController;
use App\Http\Controllers\Web\AssetController;
use App\Http\Controllers\Web\AssistantController;
use App\Http\Controllers\Web\AttachmentController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CallController;
use App\Http\Controllers\Web\CippTenantController;
use App\Http\Controllers\Web\ClientController;
use App\Http\Controllers\Web\ClientIntegrationController;
use App\Http\Controllers\Web\ContractAssignmentController;
use App\Http\Controllers\Web\ContractController;
use App\Http\Controllers\Web\ContractDocumentController;
use App\Http\Controllers\Web\ContractorTimePoolController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\EmailController;
use App\Http\Controllers\Web\GeneralSettingsController;
use App\Http\Controllers\Web\IntegrationsController;
use App\Http\Controllers\Web\InvoiceController;
use App\Http\Controllers\Web\LevelGroupController;
use App\Http\Controllers\Web\LicenseController;
use App\Http\Controllers\Web\LicenseTypeController;
use App\Http\Controllers\Web\MeshCustomerController;
use App\Http\Controllers\Web\NinjaOrgController;
use App\Http\Controllers\Web\PersonController;
use App\Http\Controllers\Web\PortalManagementController;
use App\Http\Controllers\Web\PreferencesController;
use App\Http\Controllers\Web\PrepayController;
use App\Http\Controllers\Web\ProfitabilityController;
use App\Http\Controllers\Web\QboClientMatchController;
use App\Http\Controllers\Web\QboController;
use App\Http\Controllers\Web\QuickSearchController;
use App\Http\Controllers\Web\RecurringProfileController;
use App\Http\Controllers\Web\ResellerReportController;
use App\Http\Controllers\Web\SkuController;
use App\Http\Controllers\Web\StaffController;
use App\Http\Controllers\Web\TacticalSiteController;
use App\Http\Controllers\Web\TicketController;
use App\Http\Controllers\Web\TicketNoteController;
use App\Http\Controllers\Web\TimeReportController;
use App\Http\Controllers\Web\WikiController;
use App\Http\Controllers\Web\WikiFactController;
use Illuminate\Support\Facades\Route;

// Public legal pages (required by Intuit for QBO production app)
Route::get('/legal/eula', fn () => view('legal.eula'))->name('legal.eula');
Route::get('/legal/privacy', fn () => view('legal.privacy'))->name('legal.privacy');

// Public self-service RMM installer landing — no auth, no session required.
Route::get('/setup/{token}', [\App\Http\Controllers\Portal\PortalInstallController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('portal.install.show')
    ->where('token', '[A-Za-z0-9]{16,64}');

Route::get('/setup/{token}/download', [\App\Http\Controllers\Portal\PortalInstallController::class, 'download'])
    ->middleware('throttle:60,1')
    ->name('portal.install.download')
    ->where('token', '[A-Za-z0-9]{16,64}');

// QBO disconnect landing page — Intuit redirects users here when they disconnect from QBO
Route::get('/auth/quickbooks/disconnected', [QboController::class, 'disconnected'])->name('auth.qbo.disconnected');

// CW Manage companyinfo — T2T calls GET /login/companyinfo/{companyId} at the root
// domain (not under /api) to discover the API version before making real API calls.
Route::get('/login/companyinfo/{companyId}', function (string $companyId) {
    // Match real CW companyinfo format exactly. T2T validates the Codebase
    // format and IsCloud type. T2T strips /v4_6_release from the user-entered
    // endpoint to get the base path, then appends Codebase from here.
    // User enters: your-psa-domain/api/tier2tickets/v4_6_release
    // T2T base:    your-psa-domain/api/tier2tickets
    // API URL:     your-psa-domain/api/tier2tickets/v4_6_release/apis/3.0/...
    return response()->json([
        'CompanyName' => $companyId,
        'CompanyID' => $companyId,
        'Codebase' => 'v4_6_release/',
        'VersionCode' => 'v2021.3',
        'VersionNumber' => 'v4.6.1000',
        'IsCloud' => 'True',
        'SiteUrl' => preg_replace('#^https?://#', '', url('/api/tier2tickets')),
    ]);
})->withoutMiddleware([
    \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
]);

// Auth routes — Entra ID SSO
Route::get('/login', fn () => view('auth.login'))->name('login');
Route::get('/auth/microsoft', [AuthController::class, 'redirectToMicrosoft'])->name('auth.microsoft');
Route::get('/auth/microsoft/callback', [AuthController::class, 'handleMicrosoftCallback']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Dev-only login bypass — only registered when APP_ENV=local
if (app()->environment('local')) {
    Route::get('/dev/login/{user?}', function (int $user = 1) {
        $u = \App\Models\User::findOrFail($user);
        \Illuminate\Support\Facades\Auth::login($u);

        return request()->expectsJson()
            ? response()->json(['message' => "Logged in as {$u->name}", 'user_id' => $u->id])
            : redirect()->intended(route('dashboard'));
    })->name('dev.login');
}

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/activity', [DashboardController::class, 'activity'])->name('dashboard.activity');
    Route::post('/dashboard/refresh-profitability', [DashboardController::class, 'refreshProfitability'])->name('dashboard.refresh-profitability');
    Route::get('/search/quick', [QuickSearchController::class, 'index'])->name('search.quick');
    // Clients
    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('/clients/create', [ClientController::class, 'create'])->name('clients.create');
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
    Route::get('/clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
    Route::patch('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
    Route::patch('/clients/{client}/site-notes', [ClientController::class, 'updateSiteNotes'])->name('clients.site-notes.update');
    Route::patch('/clients/{client}/credentials', [ClientController::class, 'updateCredentials'])->name('clients.credentials.update');
    Route::post('/clients/{client}/install-link/generate', [ClientController::class, 'generateInstallLink'])->name('clients.install-link.generate');
    Route::post('/clients/{client}/install-link/rotate', [ClientController::class, 'rotateInstallLink'])->name('clients.install-link.rotate');
    Route::post('/clients/{client}/install-link/disable', [ClientController::class, 'disableInstallLink'])->name('clients.install-link.disable');
    Route::patch('/clients/{client}/portal-primary-rmm', [ClientController::class, 'updatePortalPrimaryRmm'])->name('clients.portal-primary-rmm.update');
    Route::get('/clients/{client}/activity', [ClientController::class, 'activity'])->name('clients.activity');
    Route::get('/clients/{client}/tickets', [ClientController::class, 'tickets'])->name('clients.tickets');
    Route::get('/clients/{client}/people', [ClientController::class, 'people'])->name('clients.people');
    Route::get('/clients/{client}/licenses', [ClientController::class, 'licenses'])->name('clients.licenses');

    // Client Integrations (per-client link/unlink)
    Route::get('/clients/{client}/integrations/{vendor}/entities', [ClientIntegrationController::class, 'entities'])->name('clients.integrations.entities')
        ->whereIn('vendor', \App\Services\ClientIntegrationService::VENDORS);
    Route::post('/clients/{client}/integrations/{vendor}/link', [ClientIntegrationController::class, 'link'])->name('clients.integrations.link')
        ->whereIn('vendor', \App\Services\ClientIntegrationService::VENDORS);
    Route::post('/clients/{client}/integrations/{vendor}/unlink', [ClientIntegrationController::class, 'unlink'])->name('clients.integrations.unlink')
        ->whereIn('vendor', \App\Services\ClientIntegrationService::VENDORS);
    Route::post('/clients/{client}/comet/provision', [ClientIntegrationController::class, 'provisionComet'])->name('clients.comet.provision');
    Route::post('/clients/{client}/comet/provision-user', [ClientIntegrationController::class, 'provisionCometUser'])->name('clients.comet.provision-user');
    Route::post('/clients/{client}/tactical/provision', [ClientIntegrationController::class, 'provisionTactical'])->name('clients.tactical.provision');

    // Client Portal Management
    Route::get('/clients/{client}/portal', [PortalManagementController::class, 'index'])->name('clients.portal');
    Route::post('/clients/{client}/portal/invite', [PortalManagementController::class, 'invite'])->name('clients.portal.invite');
    Route::post('/clients/{client}/portal/toggle', [PortalManagementController::class, 'toggle'])->name('clients.portal.toggle');
    Route::post('/clients/{client}/portal/toggle-access', [PortalManagementController::class, 'toggleAccess'])->name('clients.portal.toggle-access');
    Route::post('/clients/{client}/portal/reset-password', [PortalManagementController::class, 'resetPassword'])->name('clients.portal.reset-password');
    Route::post('/clients/{client}/portal/impersonate', [PortalManagementController::class, 'impersonate'])->name('clients.portal.impersonate');

    // People
    Route::get('/people', [PersonController::class, 'index'])->name('people.index');
    Route::post('/people/bulk-type', [PersonController::class, 'bulkUpdateType'])->name('people.bulk-type');
    Route::get('/people/create', [PersonController::class, 'create'])->name('people.create');
    Route::post('/people', [PersonController::class, 'store'])->name('people.store');
    Route::get('/people/{person}', [PersonController::class, 'show'])->name('people.show');
    Route::get('/people/{person}/edit', [PersonController::class, 'edit'])->name('people.edit');
    Route::patch('/people/{person}', [PersonController::class, 'update'])->name('people.update');
    Route::post('/people/{person}/merge', [PersonController::class, 'merge'])->name('people.merge');
    Route::get('/people/{person}/tickets', [PersonController::class, 'tickets'])->name('people.tickets');
    Route::get('/people/{person}/activity', [PersonController::class, 'activity'])->name('people.activity');
    Route::delete('/people/{person}', [PersonController::class, 'destroy'])->name('people.destroy');

    // Tickets
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::post('/tickets/bulk-action', [TicketController::class, 'bulkAction'])->name('tickets.bulk-action');
    Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
    Route::patch('/tickets/{ticket}/status', [TicketController::class, 'updateStatus'])->name('tickets.update-status');
    Route::post('/tickets/{ticket}/assets', [TicketController::class, 'linkAsset'])->name('tickets.linkAsset');
    Route::delete('/tickets/{ticket}/assets/{asset}', [TicketController::class, 'unlinkAsset'])->name('tickets.unlinkAsset');
    Route::patch('/tickets/{ticket}/move', [TicketController::class, 'move'])->name('tickets.move');
    Route::post('/tickets/{ticket}/merge', [TicketController::class, 'merge'])->name('tickets.merge');
    Route::post('/tickets/{ticket}/triage', [TicketController::class, 'triggerTriage'])->name('tickets.triage');
    Route::post('/tickets/{ticket}/review', [TicketController::class, 'triggerReview'])->name('tickets.review');
    Route::post('/tickets/{ticket}/draft-reply', [TicketController::class, 'draftReply'])->name('tickets.draft-reply');
    Route::post('/tickets/{ticket}/draft-resolution', [TicketController::class, 'draftResolution'])->name('tickets.draft-resolution');
    Route::post('/tickets/{ticket}/tactical/run-script', [TicketController::class, 'runTacticalScript'])->name('tickets.run-tactical-script');
    Route::post('/tickets/{ticket}/tactical/command', [TicketController::class, 'runTacticalCommand'])->name('tickets.run-tactical-command');
    Route::post('/triage-runs/{triageRun}/feedback', [TicketController::class, 'storeFeedback'])->name('triage-runs.feedback');
    Route::delete('/triage-runs/{triageRun}/feedback', [TicketController::class, 'clearFeedback'])->name('triage-runs.feedback.clear');
    Route::post('/tickets/{ticket}/notes', [TicketNoteController::class, 'store'])->name('tickets.notes.store');
    Route::put('/tickets/{ticket}/notes/{note}', [TicketNoteController::class, 'update'])->name('tickets.notes.update');
    Route::delete('/tickets/{ticket}/notes/{note}', [TicketNoteController::class, 'destroy'])->name('tickets.notes.destroy');

    // Alerts
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('/alerts/bulk-acknowledge', [AlertController::class, 'bulkAcknowledge'])->name('alerts.bulk-acknowledge');
    Route::post('/alerts/bulk-create-tickets', [AlertController::class, 'bulkCreateTickets'])->name('alerts.bulk-create-tickets');
    Route::post('/alerts/bulk-resolve', [AlertController::class, 'bulkResolve'])->name('alerts.bulk-resolve');
    Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');
    Route::post('/alerts/{alert}/create-ticket', [AlertController::class, 'createTicket'])->name('alerts.create-ticket');
    Route::post('/alerts/{alert}/attach-ticket', [AlertController::class, 'attachTicket'])->name('alerts.attach-ticket');
    Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve'])->name('alerts.resolve');

    // Attachments
    Route::post('/tickets/{ticket}/attachments', [AttachmentController::class, 'store'])->name('tickets.attachments.store');
    Route::get('/attachments/{attachment}/{filename}', [AttachmentController::class, 'show'])->name('attachments.show');

    // Call log
    Route::get('/calls', [CallController::class, 'index'])->name('calls.index');
    Route::get('/calls/latest', [CallController::class, 'latest'])->name('calls.latest');
    Route::get('/calls/{call}', [CallController::class, 'show'])->name('calls.show');
    Route::get('/calls/{call}/create-ticket', [CallController::class, 'createTicket'])->name('calls.create-ticket');
    Route::post('/calls/{call}/create-ticket', [CallController::class, 'storeTicket'])->name('calls.store-ticket');
    Route::patch('/calls/{call}/notes', [CallController::class, 'updateNotes'])->name('calls.update-notes');
    Route::post('/calls/{call}/link-ticket', [CallController::class, 'linkTicket'])->name('calls.link-ticket');
    Route::delete('/calls/{call}/unlink-ticket', [CallController::class, 'unlinkTicket'])->name('calls.unlink-ticket');
    Route::post('/calls/{call}/toggle-billable', [CallController::class, 'toggleBillable'])->name('calls.toggle-billable');
    Route::post('/calls/{call}/mark-followed-up', [CallController::class, 'markFollowedUp'])->name('calls.mark-followed-up');
    Route::post('/calls/{call}/transcribe', [CallController::class, 'transcribe'])->name('calls.transcribe');
    Route::get('/calls/{call}/recording', [CallController::class, 'recording'])->name('calls.recording');
    Route::post('/calls/{call}/append-transcription', [CallController::class, 'appendTranscriptionToTicket'])->name('calls.append-transcription');
    Route::patch('/calls/{call}/person', [CallController::class, 'updatePerson'])->name('calls.update-person');
    Route::post('/calls/{call}/block-caller', [\App\Http\Controllers\Web\PhoneDirectoryController::class, 'blockFromCall'])->name('calls.block-caller');
    Route::post('/calls/{call}/allow-caller', [\App\Http\Controllers\Web\PhoneDirectoryController::class, 'allowFromCall'])->name('calls.allow-caller');

    // Prospect intake
    Route::post('/prospects', [\App\Http\Controllers\Web\ProspectController::class, 'store'])->name('prospects.store');
    Route::post('/calls/{call}/dismiss', [\App\Http\Controllers\Web\ProspectController::class, 'dismiss'])->name('prospects.dismiss');
    Route::post('/prospects/{prospect}/convert', [\App\Http\Controllers\Web\ProspectController::class, 'convert'])->name('prospects.convert');
    Route::get('/prospects/{client}/converted', [\App\Http\Controllers\Web\ProspectController::class, 'converted'])->name('prospects.converted');

    // Phone Directory (IVR caller block + allow lists)
    Route::get('/phone-directory', [\App\Http\Controllers\Web\PhoneDirectoryController::class, 'index'])->name('phone-directory.index');
    Route::post('/phone-directory', [\App\Http\Controllers\Web\PhoneDirectoryController::class, 'store'])->name('phone-directory.store');
    Route::delete('/phone-directory/bulk', [\App\Http\Controllers\Web\PhoneDirectoryController::class, 'bulkDestroy'])->name('phone-directory.bulk-destroy');
    Route::delete('/phone-directory/{entry}', [\App\Http\Controllers\Web\PhoneDirectoryController::class, 'destroy'])->name('phone-directory.destroy');

    // Emails (compose MUST come before {email} wildcard)
    Route::get('/emails', [EmailController::class, 'index'])->name('emails.index');
    Route::post('/emails/bulk-action', [EmailController::class, 'emailBulkAction'])->name('emails.bulk-action');
    Route::get('/emails/compose', [EmailController::class, 'compose'])->name('emails.compose');
    Route::post('/emails/compose', [EmailController::class, 'send'])->name('emails.send');
    Route::get('/emails/{email}', [EmailController::class, 'show'])->name('emails.show');
    Route::post('/emails/{email}/reply', [EmailController::class, 'reply'])->name('emails.reply');
    Route::get('/emails/{email}/create-ticket', [EmailController::class, 'createTicket'])->name('emails.create-ticket');
    Route::post('/emails/{email}/create-ticket', [EmailController::class, 'storeTicket'])->name('emails.store-ticket');
    Route::post('/emails/{email}/link-ticket', [EmailController::class, 'linkTicket'])->name('emails.link-ticket');
    Route::delete('/emails/{email}/link-ticket', [EmailController::class, 'unlinkTicket'])->name('emails.unlink-ticket');
    Route::post('/emails/{email}/link-client', [EmailController::class, 'linkClient'])->name('emails.link-client');
    Route::patch('/emails/{email}/reassign-client', [EmailController::class, 'reassignClient'])->name('emails.reassign-client');
    Route::post('/emails/{email}/dismiss', [EmailController::class, 'dismiss'])->name('emails.dismiss');
    Route::post('/emails/{email}/undismiss', [EmailController::class, 'undismiss'])->name('emails.undismiss');
    Route::post('/emails/{email}/create-contact', [EmailController::class, 'createContact'])->name('emails.create-contact');
    Route::post('/emails/{email}/link-contact', [EmailController::class, 'linkContact'])->name('emails.link-contact');

    // Preferences
    Route::get('/preferences', [PreferencesController::class, 'edit'])->name('preferences.edit');
    Route::post('/preferences', [PreferencesController::class, 'update'])->name('preferences.update');
    Route::post('/preferences/notifications', [PreferencesController::class, 'updateNotifications'])->name('preferences.notifications.update');
    Route::post('/preferences/signature', [PreferencesController::class, 'updateSignature'])->name('preferences.signature.update');
    Route::post('/preferences/avatar', [PreferencesController::class, 'updateAvatar'])->middleware('throttle:6,1')->name('preferences.avatar.update');
    Route::delete('/preferences/avatar', [PreferencesController::class, 'destroyAvatar'])->name('preferences.avatar.destroy');

    // Settings — General
    Route::get('/settings/general', [GeneralSettingsController::class, 'index'])->name('settings.general');
    Route::post('/settings/general', [GeneralSettingsController::class, 'update'])->name('settings.general.update');
    Route::post('/settings/general/billing-types', [GeneralSettingsController::class, 'updateBillingTypes'])->name('settings.general.billing-types');
    Route::post('/settings/general/billing-numbering', [GeneralSettingsController::class, 'updateBillingNumbering'])->name('settings.general.billing-numbering');
    Route::post('/settings/general/billing-skip-zero', [GeneralSettingsController::class, 'updateBillingSkipZero'])->name('settings.general.billing-skip-zero');
    Route::post('/settings/general/wiki', [GeneralSettingsController::class, 'updateWiki'])->name('settings.general.wiki');

    // Settings — Staff
    Route::get('/settings/staff', [StaffController::class, 'index'])->name('settings.staff.index');
    Route::get('/settings/staff/create', [StaffController::class, 'create'])->name('settings.staff.create');
    Route::post('/settings/staff', [StaffController::class, 'store'])->name('settings.staff.store');
    Route::get('/settings/staff/{user}/edit', [StaffController::class, 'edit'])->name('settings.staff.edit');
    Route::patch('/settings/staff/{user}', [StaffController::class, 'update'])->name('settings.staff.update');
    Route::patch('/settings/staff/{user}/notifications', [StaffController::class, 'updateNotifications'])->name('settings.staff.notifications.update');
    Route::patch('/settings/staff/{user}/toggle-active', [StaffController::class, 'toggleActive'])->name('settings.staff.toggle-active');
    Route::post('/settings/staff/{user}/avatar', [StaffController::class, 'updateAvatar'])->middleware('throttle:6,1')->name('settings.staff.avatar.update');
    Route::delete('/settings/staff/{user}/avatar', [StaffController::class, 'destroyAvatar'])->name('settings.staff.avatar.destroy');

    // Contractor Time Pool
    Route::get('/contractors/{user}/time-pool', [ContractorTimePoolController::class, 'show'])->name('contractors.time-pool');
    Route::post('/contractors/{user}/time-pool', [ContractorTimePoolController::class, 'store'])->name('contractors.time-pool.store');

    // Settings — Integrations
    Route::get('/settings/integrations', [IntegrationsController::class, 'index'])->name('settings.integrations');
    Route::post('/settings/integrations/toggle', [IntegrationsController::class, 'toggleIntegration'])->name('settings.integrations.toggle');
    Route::post('/settings/integrations/ninja', [IntegrationsController::class, 'updateNinja'])->name('settings.integrations.ninja.update');
    Route::post('/settings/integrations/ninja/test', [IntegrationsController::class, 'testNinja'])->name('settings.integrations.ninja.test');
    Route::post('/settings/integrations/ninja/sync-backup', [IntegrationsController::class, 'syncNinjaBackup'])->name('settings.integrations.ninja.sync-backup');
    Route::post('/settings/integrations/level', [IntegrationsController::class, 'updateLevel'])->name('settings.integrations.level.update');
    Route::post('/settings/integrations/level/test', [IntegrationsController::class, 'testLevel'])->name('settings.integrations.level.test');
    Route::post('/settings/integrations/screenconnect', [IntegrationsController::class, 'updateScreenConnect'])->name('settings.integrations.screenconnect.update');
    Route::post('/settings/integrations/tactical', [IntegrationsController::class, 'updateTactical'])->name('settings.integrations.tactical.update');
    Route::post('/settings/integrations/tactical/test', [IntegrationsController::class, 'testTactical'])->name('settings.integrations.tactical.test');
    Route::post('/settings/integrations/tactical/sync-devices', [IntegrationsController::class, 'syncTacticalDevices'])->name('settings.integrations.tactical.sync-devices');
    Route::post('/settings/integrations/tactical/sync-scripts', [IntegrationsController::class, 'syncTacticalScripts'])->name('settings.integrations.tactical.sync-scripts');
    Route::post('/settings/integrations/tactical/provision-alerts', [IntegrationsController::class, 'provisionTacticalAlerts'])->name('settings.integrations.tactical.provision-alerts');
    Route::post('/settings/integrations/comet', [IntegrationsController::class, 'updateComet'])->name('settings.integrations.comet.update');
    Route::post('/settings/integrations/comet/auto-configure', [IntegrationsController::class, 'autoConfigureComet'])->name('settings.integrations.comet.auto-configure');
    Route::post('/settings/integrations/comet/test', [IntegrationsController::class, 'testComet'])->name('settings.integrations.comet.test');
    Route::post('/settings/integrations/comet/sync-backup', [IntegrationsController::class, 'syncCometBackup'])->name('settings.integrations.comet.sync-backup');
    Route::post('/settings/integrations/plivo', [IntegrationsController::class, 'updatePlivo'])->name('settings.integrations.plivo.update');
    Route::post('/settings/integrations/plivo/test', [IntegrationsController::class, 'testPlivo'])->name('settings.integrations.plivo.test');
    Route::post('/settings/integrations/graph', [IntegrationsController::class, 'updateGraph'])->name('settings.integrations.graph.update');
    Route::post('/settings/integrations/graph/signature', [IntegrationsController::class, 'updateGraphSignature'])->name('settings.integrations.graph.update-signature');
    Route::post('/settings/integrations/graph/test', [IntegrationsController::class, 'testGraph'])->name('settings.integrations.graph.test');
    Route::post('/settings/integrations/ai', [IntegrationsController::class, 'updateAi'])->name('settings.integrations.ai.update');
    Route::post('/settings/integrations/ai/test', [IntegrationsController::class, 'testAi'])->name('settings.integrations.ai.test');
    Route::post('/settings/integrations/transcription', [IntegrationsController::class, 'updateTranscription'])->name('settings.integrations.transcription.update');
    Route::post('/settings/integrations/transcription/test', [IntegrationsController::class, 'testTranscription'])->name('settings.integrations.transcription.test');

    // Settings — NinjaRMM Org Mapping
    Route::get('/settings/integrations/ninja/orgs', [NinjaOrgController::class, 'index'])->name('settings.ninja-orgs.index');
    Route::post('/settings/integrations/ninja/orgs', [NinjaOrgController::class, 'update'])->name('settings.ninja-orgs.update');

    // Settings — Tactical RMM Site Mapping
    Route::get('/settings/integrations/tactical/sites', [TacticalSiteController::class, 'index'])->name('settings.tactical-sites.index');
    Route::post('/settings/integrations/tactical/sites', [TacticalSiteController::class, 'update'])->name('settings.tactical-sites.update');

    // Settings — Level RMM Group Mapping
    Route::get('/settings/integrations/level/groups', [LevelGroupController::class, 'index'])->name('settings.level-groups.index');
    Route::post('/settings/integrations/level/groups', [LevelGroupController::class, 'update'])->name('settings.level-groups.update');

    // Settings — Mesh Customer Mapping
    Route::get('/settings/integrations/mesh/customers', [MeshCustomerController::class, 'index'])->name('settings.mesh-customers.index');
    Route::post('/settings/integrations/mesh/customers', [MeshCustomerController::class, 'update'])->name('settings.mesh-customers.update');
    Route::post('/settings/integrations/mesh', [IntegrationsController::class, 'updateMesh'])->name('settings.integrations.mesh.update');
    Route::post('/settings/integrations/mesh/test', [IntegrationsController::class, 'testMesh'])->name('settings.integrations.mesh.test');
    Route::post('/settings/integrations/mesh/sync', [IntegrationsController::class, 'syncMesh'])->name('settings.integrations.mesh.sync');

    // Settings — CIPP Tenant Mapping
    Route::get('/settings/integrations/cipp/tenants', [CippTenantController::class, 'index'])->name('settings.cipp-tenants.index');
    Route::post('/settings/integrations/cipp/tenants', [CippTenantController::class, 'update'])->name('settings.cipp-tenants.update');
    Route::post('/settings/integrations/cipp', [IntegrationsController::class, 'updateCipp'])->name('settings.integrations.cipp.update');
    Route::post('/settings/integrations/cipp/test', [IntegrationsController::class, 'testCipp'])->name('settings.integrations.cipp.test');
    Route::post('/settings/integrations/cipp/sync', [IntegrationsController::class, 'syncCipp'])->name('settings.integrations.cipp.sync');
    Route::post('/settings/integrations/cipp/sync-contacts', [IntegrationsController::class, 'syncCippContacts'])->name('settings.integrations.cipp.sync-contacts');
    Route::get('/settings/integrations/cipp/tenants/{domain}/groups', [CippTenantController::class, 'groups'])->where('domain', '.*')->name('settings.cipp-tenants.groups');
    Route::post('/settings/integrations/cipp/sync-devices', [IntegrationsController::class, 'syncCippDevices'])->name('settings.integrations.cipp.sync-devices');

    // Settings — Huntress Organization Mapping
    Route::get('/settings/integrations/huntress/organizations', [\App\Http\Controllers\Web\HuntressOrganizationController::class, 'index'])->name('settings.huntress-orgs.index');
    Route::post('/settings/integrations/huntress/organizations', [\App\Http\Controllers\Web\HuntressOrganizationController::class, 'update'])->name('settings.huntress-orgs.update');
    Route::get('/settings/integrations/huntress/organizations/auto-match', [\App\Http\Controllers\Web\HuntressOrganizationController::class, 'autoMatch'])->name('settings.huntress-orgs.auto-match');
    Route::post('/settings/integrations/huntress', [IntegrationsController::class, 'updateHuntress'])->name('settings.integrations.huntress.update');
    Route::post('/settings/integrations/huntress/test', [IntegrationsController::class, 'testHuntress'])->name('settings.integrations.huntress.test');
    Route::post('/settings/integrations/huntress/sync', [IntegrationsController::class, 'syncHuntress'])->name('settings.integrations.huntress.sync');
    Route::post('/settings/integrations/huntress/cw', [IntegrationsController::class, 'updateHuntressCw'])->name('settings.integrations.huntress-cw.update');
    Route::post('/settings/integrations/huntress/cw/generate-key', [IntegrationsController::class, 'generateHuntressCwKey'])->name('settings.integrations.huntress-cw.generate-key');

    // Settings — Servosity
    Route::post('/settings/integrations/servosity', [IntegrationsController::class, 'updateServosity'])->name('settings.integrations.servosity.update');
    Route::post('/settings/integrations/servosity/test', [IntegrationsController::class, 'testServosity'])->name('settings.integrations.servosity.test');
    Route::post('/settings/integrations/servosity/sync', [IntegrationsController::class, 'syncServosity'])->name('settings.integrations.servosity.sync');
    Route::get('/settings/integrations/servosity/companies', [\App\Http\Controllers\Web\ServosityCompanyController::class, 'index'])->name('settings.servosity-companies.index');
    Route::post('/settings/integrations/servosity/companies', [\App\Http\Controllers\Web\ServosityCompanyController::class, 'update'])->name('settings.servosity-companies.update');
    Route::get('/settings/integrations/servosity/companies/auto-match', [\App\Http\Controllers\Web\ServosityCompanyController::class, 'autoMatch'])->name('settings.servosity-companies.auto-match');

    // Settings — Control D Organization Mapping
    Route::get('/settings/integrations/controld/organizations', [\App\Http\Controllers\Web\ControlDOrganizationController::class, 'index'])->name('settings.controld-orgs.index');
    Route::post('/settings/integrations/controld/organizations', [\App\Http\Controllers\Web\ControlDOrganizationController::class, 'update'])->name('settings.controld-orgs.update');
    Route::get('/settings/integrations/controld/organizations/auto-match', [\App\Http\Controllers\Web\ControlDOrganizationController::class, 'autoMatch'])->name('settings.controld-orgs.auto-match');
    Route::post('/settings/integrations/controld', [IntegrationsController::class, 'updateControlD'])->name('settings.integrations.controld.update');
    Route::post('/settings/integrations/controld/test', [IntegrationsController::class, 'testControlD'])->name('settings.integrations.controld.test');
    Route::post('/settings/integrations/controld/sync', [IntegrationsController::class, 'syncControlD'])->name('settings.integrations.controld.sync');
    Route::post('/settings/integrations/controld/sync-devices', [IntegrationsController::class, 'syncControlDDevices'])->name('settings.integrations.controld.sync-devices');

    // Settings — Zorus
    Route::get('/settings/integrations/zorus/customers', [\App\Http\Controllers\Web\ZorusCustomerController::class, 'index'])->name('settings.zorus-customers.index');
    Route::post('/settings/integrations/zorus/customers', [\App\Http\Controllers\Web\ZorusCustomerController::class, 'update'])->name('settings.zorus-customers.update');
    Route::get('/settings/integrations/zorus/customers/auto-match', [\App\Http\Controllers\Web\ZorusCustomerController::class, 'autoMatch'])->name('settings.zorus-customers.auto-match');
    Route::post('/settings/integrations/zorus', [IntegrationsController::class, 'updateZorus'])->name('settings.integrations.zorus.update');
    Route::post('/settings/integrations/zorus/test', [IntegrationsController::class, 'testZorus'])->name('settings.integrations.zorus.test');
    Route::post('/settings/integrations/zorus/sync', [IntegrationsController::class, 'syncZorus'])->name('settings.integrations.zorus.sync');
    Route::post('/settings/integrations/zorus/sync-devices', [IntegrationsController::class, 'syncZorusDevices'])->name('settings.integrations.zorus.sync-devices');

    // Settings — AppRiver Customer Mapping
    Route::get('/settings/integrations/appriver/customers', [\App\Http\Controllers\Web\AppRiverCustomerController::class, 'index'])->name('settings.appriver-customers.index');
    Route::post('/settings/integrations/appriver/customers', [\App\Http\Controllers\Web\AppRiverCustomerController::class, 'update'])->name('settings.appriver-customers.update');
    Route::get('/settings/integrations/appriver/customers/auto-match', [\App\Http\Controllers\Web\AppRiverCustomerController::class, 'autoMatch'])->name('settings.appriver-customers.auto-match');
    Route::post('/settings/integrations/appriver', [IntegrationsController::class, 'updateAppRiver'])->name('settings.integrations.appriver.update');
    Route::post('/settings/integrations/appriver/test', [IntegrationsController::class, 'testAppRiver'])->name('settings.integrations.appriver.test');
    Route::post('/settings/integrations/appriver/sync', [IntegrationsController::class, 'syncAppRiver'])->name('settings.integrations.appriver.sync');

    // Settings — Printix Tenant Mapping
    Route::get('/settings/integrations/printix/tenants', [\App\Http\Controllers\Web\PrintixTenantController::class, 'index'])->name('settings.printix-tenants.index');
    Route::post('/settings/integrations/printix/tenants', [\App\Http\Controllers\Web\PrintixTenantController::class, 'update'])->name('settings.printix-tenants.update');
    Route::get('/settings/integrations/printix/tenants/auto-match', [\App\Http\Controllers\Web\PrintixTenantController::class, 'autoMatch'])->name('settings.printix-tenants.auto-match');
    Route::post('/settings/integrations/printix', [IntegrationsController::class, 'updatePrintix'])->name('settings.integrations.printix.update');
    Route::post('/settings/integrations/printix/test', [IntegrationsController::class, 'testPrintix'])->name('settings.integrations.printix.test');
    Route::post('/settings/integrations/printix/sync', [IntegrationsController::class, 'syncPrintix'])->name('settings.integrations.printix.sync');

    // Settings — Stripe
    Route::post('/settings/integrations/stripe', [IntegrationsController::class, 'updateStripe'])->name('settings.integrations.stripe.update');
    Route::post('/settings/integrations/stripe/test', [IntegrationsController::class, 'testStripe'])->name('settings.integrations.stripe.test');
    Route::get('/settings/integrations/stripe/customers', [\App\Http\Controllers\Web\StripeCustomerController::class, 'index'])->name('settings.stripe-customers.index');
    Route::post('/settings/integrations/stripe/customers', [\App\Http\Controllers\Web\StripeCustomerController::class, 'update'])->name('settings.stripe-customers.update');
    Route::get('/settings/integrations/stripe/customers/auto-match', [\App\Http\Controllers\Web\StripeCustomerController::class, 'autoMatch'])->name('settings.stripe-customers.auto-match');

    // Settings — Avatars
    Route::post('/settings/integrations/avatars', [IntegrationsController::class, 'updateAvatars'])->name('settings.integrations.avatars.update');

    // Settings — Ticket Automation
    Route::post('/settings/integrations/tickets', [IntegrationsController::class, 'updateTicketSettings'])->name('settings.integrations.tickets.update');

    // Settings — AI Triage
    Route::post('/settings/integrations/triage', [IntegrationsController::class, 'updateTriage'])->name('settings.integrations.triage.update');

    // Settings — AI Assistant
    Route::post('/settings/integrations/assistant', [IntegrationsController::class, 'updateAssistant'])->name('settings.integrations.assistant.update');

    // Settings — AI Technician
    Route::post('/settings/integrations/technician', [IntegrationsController::class, 'updateTechnician'])->name('settings.integrations.technician.update');

    // Settings — T2T / HelpDesk Buttons
    Route::post('/settings/integrations/t2t', [IntegrationsController::class, 'updateT2t'])->name('settings.integrations.t2t.update');
    Route::post('/settings/integrations/t2t/generate-key', [IntegrationsController::class, 'generateT2tKey'])->name('settings.integrations.t2t.generate-key');

    // Settings — QBO
    Route::post('/settings/integrations/qbo', [IntegrationsController::class, 'updateQbo'])->name('settings.integrations.qbo.update');
    Route::post('/settings/integrations/qbo/disconnect', [QboController::class, 'disconnect'])->name('settings.qbo.disconnect');
    Route::get('/settings/integrations/qbo/clients', [QboClientMatchController::class, 'index'])->name('settings.qbo-clients.index');
    Route::post('/settings/integrations/qbo/clients', [QboClientMatchController::class, 'update'])->name('settings.qbo-clients.update');
    Route::get('/settings/integrations/qbo/clients/auto-match', [QboClientMatchController::class, 'autoMatch'])->name('settings.qbo-clients.auto-match');

    // Settings — Client Portal
    Route::post('/settings/integrations/portal', [IntegrationsController::class, 'updatePortal'])->name('settings.integrations.portal.update');

    // QBO OAuth
    Route::get('/auth/quickbooks', [QboController::class, 'redirect'])->name('auth.qbo');
    Route::get('/auth/quickbooks/callback', [QboController::class, 'callback'])->name('auth.qbo.callback');

    // AppRiver OAuth
    Route::get('/auth/appriver', [\App\Http\Controllers\Web\AppRiverOAuthController::class, 'redirect'])->name('auth.appriver');
    Route::get('/auth/appriver/callback', [\App\Http\Controllers\Web\AppRiverOAuthController::class, 'callback'])->name('auth.appriver.callback');

    // AJAX endpoints (must be in web.php for session auth, NOT api.php)
    Route::get('/api/qbo/customers', [QboController::class, 'customers'])->name('api.qbo.customers');
    Route::get('/api/ninja/orgs', [NinjaOrgController::class, 'apiOrgs'])->name('api.ninja.orgs');
    Route::get('/api/level/groups', [LevelGroupController::class, 'apiGroups'])->name('api.level.groups');
    Route::get('/api/clients/search', [NinjaOrgController::class, 'apiClientSearch'])->name('api.clients.search');
    Route::get('/api/clients/search-all', [\App\Http\Controllers\Web\ProspectController::class, 'search'])->name('clients.search');
    Route::get('/api/tickets/search', [TicketController::class, 'apiSearch'])->name('api.tickets.search');
    Route::get('/api/clients/{client}/contacts', [ClientController::class, 'contacts'])->name('api.clients.contacts');
    Route::get('/api/clients/{client}/assets', [ClientController::class, 'assets'])->name('api.clients.assets');

    // Assets (top-level)
    Route::get('/assets', [AssetController::class, 'indexAll'])->name('assets.index');
    Route::get('/assets/create', [AssetController::class, 'create'])->name('assets.create');
    Route::post('/assets', [AssetController::class, 'store'])->name('assets.store');
    Route::get('/assets/{asset}', [AssetController::class, 'show'])->name('assets.show');
    Route::get('/assets/{asset}/edit', [AssetController::class, 'edit'])->name('assets.edit');
    Route::patch('/assets/{asset}', [AssetController::class, 'update'])->name('assets.update');
    Route::delete('/assets/{asset}', [AssetController::class, 'destroy'])->name('assets.destroy');
    Route::post('/assets/{asset}/restore', [AssetController::class, 'restore'])->name('assets.restore');
    Route::post('/assets/{asset}/refresh', [AssetController::class, 'refresh'])->name('assets.refresh');
    Route::post('/assets/{asset}/controld/link', [AssetController::class, 'linkControlD'])->name('assets.controld.link');
    Route::post('/assets/{asset}/controld/unlink', [AssetController::class, 'unlinkControlD'])->name('assets.controld.unlink');
    Route::get('/assets/{asset}/controld/activity', [AssetController::class, 'controldActivity'])->name('assets.controld.activity');
    Route::post('/assets/{asset}/zorus/link', [AssetController::class, 'linkZorus'])->name('assets.zorus.link');
    Route::post('/assets/{asset}/zorus/unlink', [AssetController::class, 'unlinkZorus'])->name('assets.zorus.unlink');
    Route::post('/assets/{asset}/comet/toggle-backup', [AssetController::class, 'toggleCometBackup'])->name('assets.comet.toggle-backup');
    Route::post('/assets/{asset}/servosity/toggle-backup', [AssetController::class, 'toggleServosityBackup'])->name('assets.servosity.toggle-backup');
    Route::post('/assets/{asset}/tactical/run-script', [AssetController::class, 'runTacticalScript'])->name('assets.run-tactical-script');
    Route::post('/assets/{asset}/tactical/reboot', [AssetController::class, 'rebootTacticalAgent'])->name('assets.reboot-tactical');
    Route::post('/assets/{asset}/tactical/recover', [AssetController::class, 'recoverTacticalAgent'])->name('assets.recover-tactical');
    Route::post('/assets/{asset}/tactical/maintenance', [AssetController::class, 'setTacticalMaintenance'])->name('assets.maintenance-tactical');
    Route::post('/assets/{asset}/tactical/refresh', [AssetController::class, 'refreshTactical'])->name('assets.tactical-refresh');
    Route::post('/assets/{asset}/tactical/command', [AssetController::class, 'runTacticalCommand'])->name('assets.run-tactical-command');
    Route::post('/assets/{asset}/tactical/shutdown', [AssetController::class, 'shutdownTacticalAgent'])->name('assets.shutdown-tactical');
    Route::post('/assets/{asset}/tactical/meshcentral', [AssetController::class, 'openTacticalMeshCentral'])
        ->middleware('throttle:30,1')->name('assets.tactical-meshcentral');
    Route::post('/assets/{asset}/users', [AssetController::class, 'addUser'])->name('assets.add-user');
    Route::delete('/assets/{asset}/users/{person}', [AssetController::class, 'removeUser'])->name('assets.remove-user');
    Route::post('/assets/{asset}/users/{person}/primary', [AssetController::class, 'setPrimaryUser'])->name('assets.set-primary-user');
    Route::get('/assets/{asset}/tickets', [AssetController::class, 'tickets'])->name('assets.tickets');
    Route::get('/assets/{asset}/quick-look', [AssetController::class, 'quickLook'])->name('assets.quickLook');
    Route::get('/assets/{asset}/device-data/{section}', [AssetController::class, 'deviceData'])->name('assets.deviceData');

    // Assets (per-client)
    Route::get('/clients/{client}/assets', [ClientController::class, 'assetList'])->name('clients.assets');

    // Contracts (top-level index + nested under clients)
    Route::get('/contracts', [ContractController::class, 'indexAll'])->name('contracts.index-all');
    Route::post('/contracts/bulk-action', [ContractController::class, 'bulkAction'])->name('contracts.bulk-action');
    Route::get('/clients/{client}/contracts', [ClientController::class, 'contracts'])->name('clients.contracts');
    Route::get('/clients/{client}/contracts/create', [ContractController::class, 'create'])->name('contracts.create');
    Route::post('/clients/{client}/contracts', [ContractController::class, 'store'])->name('contracts.store');
    Route::get('/contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show');
    Route::get('/contracts/{contract}/tickets', [ContractController::class, 'tickets'])->name('contracts.tickets');
    Route::get('/contracts/{contract}/invoices', [ContractController::class, 'invoices'])->name('contracts.invoices');
    Route::patch('/contracts/{contract}', [ContractController::class, 'update'])->name('contracts.update');
    Route::post('/contracts/{contract}/prepay/adjust', [ContractController::class, 'prepayAdjust'])->name('contracts.prepay-adjust');
    Route::post('/contracts/{contract}/prepay/initialize', [ContractController::class, 'initializePrepay'])->name('contracts.initialize-prepay');
    Route::put('/contracts/{contract}/portal-sku', [ContractController::class, 'updatePortalSku'])->name('contracts.update-portal-sku');
    Route::put('/contracts/{contract}/alert-settings', [ContractController::class, 'updateAlertSettings'])->name('contracts.update-alert-settings');
    Route::put('/contracts/{contract}/sla-terms', [ContractController::class, 'updateSlaTerms'])->name('contracts.update-sla-terms');

    // Contract Documents
    Route::post('/contracts/{contract}/documents', [ContractDocumentController::class, 'store'])->name('contracts.upload-document');
    Route::get('/contracts/{contract}/documents/{document}/download', [ContractDocumentController::class, 'download'])->name('contracts.download-document');
    Route::delete('/contracts/{contract}/documents/{document}', [ContractDocumentController::class, 'destroy'])->name('contracts.delete-document');
    Route::post('/contracts/{contract}/documents/{document}/resummarize', [ContractDocumentController::class, 'resummarize'])->name('contracts.resummarize-document');
    Route::get('/contracts/{contract}/documents/{document}/status', [ContractDocumentController::class, 'status'])->name('contracts.document-status');

    // Contract Assignments
    Route::post('/contracts/{contract}/assets', [ContractAssignmentController::class, 'assignAsset'])->name('contracts.assign-asset');
    Route::delete('/contracts/{contract}/assets/{asset}', [ContractAssignmentController::class, 'unassignAsset'])->name('contracts.unassign-asset');
    Route::post('/contracts/{contract}/people', [ContractAssignmentController::class, 'assignPerson'])->name('contracts.assign-person');
    Route::delete('/contracts/{contract}/people/{person}', [ContractAssignmentController::class, 'unassignPerson'])->name('contracts.unassign-person');
    Route::post('/contracts/{contract}/rules', [ContractAssignmentController::class, 'storeRule'])->name('contracts.store-rule');
    Route::delete('/rules/{rule}', [ContractAssignmentController::class, 'destroyRule'])->name('rules.destroy');
    Route::post('/contracts/{contract}/evaluate-rules', [ContractAssignmentController::class, 'evaluateRules'])->name('contracts.evaluate-rules');

    // Recurring Profiles (top-level index + nested under contracts)
    Route::get('/profiles', [RecurringProfileController::class, 'index'])->name('profiles.index');
    Route::post('/profiles/bulk-action', [RecurringProfileController::class, 'bulkAction'])->name('profiles.bulk-action');
    Route::get('/contracts/{contract}/profiles/create', [RecurringProfileController::class, 'create'])->name('profiles.create');
    Route::post('/contracts/{contract}/profiles', [RecurringProfileController::class, 'store'])->name('profiles.store');
    Route::get('/profiles/{profile}', [RecurringProfileController::class, 'show'])->name('profiles.show');
    Route::patch('/profiles/{profile}', [RecurringProfileController::class, 'update'])->name('profiles.update');
    Route::get('/profiles/{profile}/preview', [RecurringProfileController::class, 'preview'])->name('profiles.preview');
    Route::post('/profiles/{profile}/generate', [RecurringProfileController::class, 'generate'])->name('profiles.generate');

    // Prepay Dashboard
    Route::get('/prepay', [PrepayController::class, 'index'])->name('prepay.index');

    // Invoices
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::post('/invoices/bulk-action', [InvoiceController::class, 'bulkAction'])->name('invoices.bulk-action');
    Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::post('/invoices/import-stripe', [InvoiceController::class, 'importFromStripe'])->name('invoices.import-stripe');
    Route::post('/invoices/{invoice}/push-qbo', [InvoiceController::class, 'pushToQbo'])->name('invoices.push-qbo');
    Route::post('/invoices/{invoice}/sync-qbo', [InvoiceController::class, 'syncFromQbo'])->name('invoices.sync-qbo');
    Route::post('/invoices/{invoice}/push-stripe', [InvoiceController::class, 'pushToStripe'])->name('invoices.push-stripe');
    Route::post('/invoices/{invoice}/sync-stripe', [InvoiceController::class, 'syncFromStripe'])->name('invoices.sync-stripe');
    Route::post('/invoices/{invoice}/send-stripe', [InvoiceController::class, 'sendFromStripe'])->name('invoices.send-stripe');
    Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');

    // Profitability
    Route::get('/profitability', [ProfitabilityController::class, 'index'])->name('profitability.index');
    Route::get('/profitability/clients/{client}', [ProfitabilityController::class, 'client'])->name('profitability.client');
    Route::get('/profitability/contracts/{contract}', [ProfitabilityController::class, 'contract'])->name('profitability.contract');

    // Reports
    Route::get('/reseller-report', [ResellerReportController::class, 'index'])->name('reseller-report.index');
    Route::get('/reports/time', [TimeReportController::class, 'index'])->name('reports.time');

    // License Types
    Route::get('/license-types', [LicenseTypeController::class, 'index'])->name('license-types.index');
    Route::get('/license-types/create', [LicenseTypeController::class, 'create'])->name('license-types.create');
    Route::post('/license-types', [LicenseTypeController::class, 'store'])->name('license-types.store');
    Route::get('/license-types/{licenseType}', [LicenseTypeController::class, 'show'])->name('license-types.show');
    Route::get('/license-types/{licenseType}/edit', [LicenseTypeController::class, 'edit'])->name('license-types.edit');
    Route::patch('/license-types/{licenseType}', [LicenseTypeController::class, 'update'])->name('license-types.update');

    // Licenses
    Route::get('/licenses', [LicenseController::class, 'index'])->name('licenses.index');
    Route::get('/licenses/create', [LicenseController::class, 'create'])->name('licenses.create');
    Route::post('/licenses', [LicenseController::class, 'store'])->name('licenses.store');
    Route::patch('/licenses/{license}', [LicenseController::class, 'update'])->name('licenses.update');
    Route::delete('/licenses/{license}', [LicenseController::class, 'destroy'])->name('licenses.destroy');
    Route::patch('/licenses/{license}/quantity', [LicenseController::class, 'updateQuantity'])->name('licenses.update-quantity');
    Route::post('/contracts/{contract}/licenses', [LicenseController::class, 'assignToContract'])->name('contracts.assign-license');
    Route::post('/contracts/{contract}/licenses/assign-all', [LicenseController::class, 'assignAllToContract'])->name('contracts.assign-all-licenses');
    Route::delete('/contracts/{contract}/licenses/{license}', [LicenseController::class, 'unassignFromContract'])->name('contracts.unassign-license');

    // SKUs
    Route::get('/skus', [SkuController::class, 'index'])->name('skus.index');
    Route::get('/skus/create', [SkuController::class, 'create'])->name('skus.create');
    Route::post('/skus', [SkuController::class, 'store'])->name('skus.store');
    Route::get('/skus/{sku}/edit', [SkuController::class, 'edit'])->name('skus.edit');
    Route::patch('/skus/{sku}', [SkuController::class, 'update'])->name('skus.update');
    Route::delete('/skus/{sku}', [SkuController::class, 'destroy'])->name('skus.destroy');
    Route::post('/skus/bulk-action', [SkuController::class, 'bulkAction'])->name('skus.bulk-action');
    Route::post('/skus/import-qbo', [SkuController::class, 'importFromQbo'])->name('skus.import-qbo');
    Route::post('/skus/{sku}/push-qbo', [SkuController::class, 'pushToQbo'])->name('skus.push-qbo');
    Route::post('/skus/import-stripe', [SkuController::class, 'importFromStripe'])->name('skus.import-stripe');
    Route::post('/skus/{sku}/push-stripe', [SkuController::class, 'pushToStripe'])->name('skus.push-stripe');
    Route::get('/api/skus/search', [SkuController::class, 'apiSearch'])->name('api.skus.search');

    // Softphone (opens as popup window, not iframe)
    Route::get('/softphone', fn () => view('softphone.index'))
        ->name('softphone');

    // About
    Route::get('/about', [AboutController::class, 'index'])->name('about');
    Route::post('/about/check-updates', [AboutController::class, 'checkForUpdates'])->name('about.check-updates');

    // Client Wiki (spec docs/superpowers/specs/2026-06-12-client-wiki-design.md §8)
    // search and CRUD live at /wiki-search and /wiki-pages/* to stay ahead of the wiki/{slug} catch-all
    Route::post('/wiki-facts/{fact}/confirm', [WikiFactController::class, 'confirm'])->name('wiki.facts.confirm');
    Route::post('/wiki-facts/{fact}/retire', [WikiFactController::class, 'retire'])->name('wiki.facts.retire');
    Route::patch('/wiki-facts/{fact}/correct', [WikiFactController::class, 'correct'])->name('wiki.facts.correct');
    Route::post('/wiki-facts/{fact}/resolve', [WikiFactController::class, 'resolve'])->name('wiki.facts.resolve');
    Route::get('/wiki', [WikiController::class, 'index'])->name('wiki.index');
    Route::get('/wiki-search', [WikiController::class, 'search'])->name('wiki.search');
    Route::get('/wiki-pages/create', [WikiController::class, 'create'])->name('wiki.create');
    Route::post('/wiki-pages', [WikiController::class, 'store'])->name('wiki.store');
    Route::get('/wiki-pages/{page}/edit', [WikiController::class, 'edit'])->name('wiki.edit');
    Route::patch('/wiki-pages/{page}', [WikiController::class, 'update'])->name('wiki.update');
    Route::get('/wiki-pages/{page}/history', [WikiController::class, 'history'])->name('wiki.history');
    Route::post('/wiki-pages/{page}/archive', [WikiController::class, 'archive'])->name('wiki.archive');
    Route::get('/wiki/{slug}', [WikiController::class, 'show'])
        ->where('slug', '.*')->name('wiki.show');
    Route::get('/clients/{client}/wiki', [WikiController::class, 'clientIndex'])->name('clients.wiki.index');
    Route::get('/clients/{client}/wiki/{slug}', [WikiController::class, 'clientShow'])
        ->where('slug', '.*')->name('clients.wiki.show');

    // AI Assistant
    Route::post('/assistant/conversations', [AssistantController::class, 'createConversation'])->name('assistant.create');
    Route::get('/assistant/conversations/for-ticket/{ticket}', [AssistantController::class, 'forTicket'])->name('assistant.for-ticket');
    Route::get('/assistant/conversations/{conversation}', [AssistantController::class, 'getMessages'])->name('assistant.show');
    Route::post('/assistant/conversations/{conversation}/messages', [AssistantController::class, 'sendMessage'])
        ->middleware('throttle:10,1')
        ->name('assistant.message');
    Route::post('/assistant/conversations/{conversation}/save-note', [AssistantController::class, 'saveNote'])->name('assistant.save-note');
    Route::get('/assistant/general', [AssistantController::class, 'general'])->name('assistant.general');
});
