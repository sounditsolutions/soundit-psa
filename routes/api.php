<?php

use App\Http\Controllers\Api\HuntressController;
use App\Http\Controllers\Api\QboWebhookController;
use App\Http\Controllers\Api\T2TController;
use App\Http\Controllers\Api\GraphWebhookController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LevelWebhookController;
use App\Http\Controllers\Api\NinjaWebhookController;
use App\Http\Controllers\Api\PlivoWebhookController;
use App\Http\Controllers\Api\ScreenConnectWebhookController;
use App\Http\Controllers\Api\CometWebhookController;
use App\Http\Controllers\Api\TacticalWebhookController;
use App\Http\Middleware\VerifyHuntressApiKey;
use App\Http\Middleware\VerifyCometWebhookKey;
use App\Http\Middleware\VerifyTacticalWebhookKey;
use App\Http\Middleware\VerifyQboWebhookSignature;
use App\Http\Middleware\VerifyT2TApiKey;
use App\Http\Middleware\VerifyLevelWebhookSignature;
use App\Http\Middleware\VerifyPlivoWebhookSecret;
use App\Http\Middleware\VerifyScreenConnectSecret;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

// NinjaRMM webhooks — no auth available from Ninja's side
Route::post('webhooks/ninja', [NinjaWebhookController::class, 'handle']);

// Level RMM webhooks — HMAC-SHA-256 signature verification
Route::post('webhooks/level', [LevelWebhookController::class, 'handle'])
    ->middleware(VerifyLevelWebhookSignature::class);

// QuickBooks Online webhooks — HMAC-SHA-256 signature verification
Route::post('webhooks/qbo', [QboWebhookController::class, 'handle'])
    ->middleware([VerifyQboWebhookSignature::class, 'throttle:120,1']);

// Microsoft Graph webhooks — clientState verification in controller
Route::post('webhooks/graph/mail', [GraphWebhookController::class, 'handle']);

// Plivo webhooks — secret token in URL for authentication
Route::post('plivo/{secret}/webhook', [PlivoWebhookController::class, 'handle'])
    ->middleware(VerifyPlivoWebhookSecret::class);

Route::post('plivo/{secret}/browser-answer', [PlivoWebhookController::class, 'browserAnswer'])
    ->middleware(VerifyPlivoWebhookSecret::class);

// Caller identification lookup for Plivo PHLO HTTP-request nodes during IVR
Route::post('plivo/{secret}/resolve-caller', [PlivoWebhookController::class, 'resolveCaller'])
    ->middleware(VerifyPlivoWebhookSecret::class);

// MCP server (Claude Teams Teammate via Anthropic MCP connector) — staff tool surface
Route::post('mcp/staff', [\App\Http\Controllers\Api\McpStaffController::class, 'handle'])
    ->middleware([\App\Http\Middleware\VerifyMcpStaffToken::class, 'throttle:120,1']);

// ScreenConnect webhooks — secret token in URL for authentication
Route::post('webhooks/screenconnect/{secret}', [ScreenConnectWebhookController::class, 'handle'])
    ->middleware([VerifyScreenConnectSecret::class, 'throttle:120,1']);

// Tactical RMM alert webhooks — Bearer token or X-Webhook-Key header
Route::post('webhooks/tactical', [TacticalWebhookController::class, 'handle'])
    ->middleware([VerifyTacticalWebhookKey::class, 'throttle:120,1']);

// Comet Backup webhooks — Bearer token or X-Webhook-Key header
Route::post('webhooks/comet', [CometWebhookController::class, 'handle'])
    ->middleware([VerifyCometWebhookKey::class, 'throttle:120,1']);

// ConnectWise Manage API compatibility layer (for Tier2Tickets / HelpDeskButtons)
// T2T's connectivity test hits /service/boards without auth — allow it through
// so the test button passes. All data-bearing endpoints require auth.
Route::prefix('tier2tickets/v4_6_release/apis/3.0')
    ->middleware(['throttle:120,1'])
    ->group(function () {
        // Unauthenticated: static metadata endpoints (T2T connectivity/config test)
        Route::get('service/boards', [T2TController::class, 'listBoards']);
        Route::get('service/boards/{boardId}/statuses', [T2TController::class, 'listBoardStatuses']);
        Route::get('service/boards/{boardId}/types', [T2TController::class, 'listBoardTypes']);
        Route::get('service/boards/{boardId}/teams', [T2TController::class, 'listBoardTeams']);
        Route::get('service/boards/{boardId}/subtypes', [T2TController::class, 'listBoardSubTypes']);
        Route::get('service/priorities', [T2TController::class, 'listPriorities']);
        Route::get('service/severities', [T2TController::class, 'listSeverities']);
        Route::get('service/impacts', [T2TController::class, 'listImpacts']);
        Route::get('service/sources', [T2TController::class, 'listSources']);
        Route::get('system/info', [T2TController::class, 'systemInfo']);

        // Authenticated: all data-bearing endpoints
        Route::middleware(VerifyT2TApiKey::class)->group(function () {
            // Company
            Route::get('company/companies/{id}', [T2TController::class, 'getCompany']);
            Route::get('company/contacts', [T2TController::class, 'listContacts']);
            Route::post('company/contacts', [T2TController::class, 'createContact']);
            Route::get('company/configurations', [T2TController::class, 'listConfigurations']);

            // Service
            Route::get('service/tickets', [T2TController::class, 'listTickets']);
            Route::post('service/tickets', [T2TController::class, 'createTicket']);
            Route::patch('service/tickets/{id}', [T2TController::class, 'updateTicket']);
            Route::post('service/tickets/{id}/notes', [T2TController::class, 'addTicketNote']);

            // System
            Route::post('system/callbacks', [T2TController::class, 'registerCallback']);
        });

        // Catch-all for unmapped CW endpoints — returns 501 JSON
        Route::any('{path}', [T2TController::class, 'catchAll'])->where('path', '.*');
    });

// ConnectWise Manage API compatibility layer (for Huntress incident reports)
// Huntress sends incident reports via CW-format webhooks.
Route::prefix('huntress/v4_6_release/apis/3.0')
    ->middleware(['throttle:120,1'])
    ->group(function () {
        // Unauthenticated: static metadata endpoints (Huntress connectivity/config test)
        Route::get('service/boards', [HuntressController::class, 'listBoards']);
        Route::get('service/boards/{boardId}/statuses/{statusId}', [HuntressController::class, 'getBoardStatus']);
        Route::get('service/boards/{boardId}/statuses', [HuntressController::class, 'listBoardStatuses']);
        Route::get('service/boards/{boardId}/types', [HuntressController::class, 'listBoardTypes']);
        Route::get('service/boards/{boardId}/subtypes', [HuntressController::class, 'listBoardSubTypes']);
        Route::get('service/boards/{boardId}/items', [HuntressController::class, 'listBoardItems']);
        Route::get('service/priorities', [HuntressController::class, 'listPriorities']);
        Route::get('service/sources', [HuntressController::class, 'listSources']);
        Route::get('system/info', [HuntressController::class, 'systemInfo']);

        // Authenticated: company & ticket operations
        Route::middleware(VerifyHuntressApiKey::class)->group(function () {
            Route::get('company/companies/count', [HuntressController::class, 'countCompanies']);
            Route::get('company/companies', [HuntressController::class, 'listCompanies']);
            Route::get('service/tickets/{id}', [HuntressController::class, 'getTicket']);
            Route::post('service/tickets', [HuntressController::class, 'createTicket']);
            Route::patch('service/tickets/{id}', [HuntressController::class, 'updateTicket']);
        });

        // Catch-all for unmapped CW endpoints — returns 501 JSON
        Route::any('{path}', [HuntressController::class, 'catchAll'])->where('path', '.*');
    });

