<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\TacticalWebhook;
use App\Services\Cipp\CippMcpCatalogSyncService;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphWebhookManager;
use App\Services\Level\LevelClient;
use App\Services\Ninja\NinjaBackupSyncService;
use App\Services\Ninja\NinjaClient;
use App\Support\AiConfig;
use App\Support\AppRiverConfig;
use App\Support\AppTimezone;
use App\Support\CippConfig;
use App\Support\ControlDConfig;
use App\Support\HuntressConfig;
use App\Support\LevelConfig;
use App\Support\MeshConfig;
use App\Support\PlivoConfig;
use App\Support\PrintixConfig;
use App\Support\ScreenConnectConfig;
use App\Support\ServosityConfig;
use App\Support\StripeConfig;
use App\Support\T2TConfig;
use App\Support\TacticalConfig;
use App\Support\TechnicianConfig;
use App\Support\TranscriptionConfig;
use App\Support\ZorusConfig;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class IntegrationsController extends Controller
{
    /**
     * The masked placeholder shown for an already-stored secret. A submit equal to
     * this (or blank) means "keep the existing value" — never overwrite the secret
     * with the mask. Matches the existing convention used elsewhere in this controller.
     */
    private const SECRET_MASK = '••••••••';

    public function index(NinjaClient $ninja, LevelClient $level)
    {
        // Helper: format a UTC timestamp string for display in the app timezone.
        $fmtTs = fn (?string $ts): ?string => $ts
            ? Carbon::parse($ts)->setTimezone(AppTimezone::get())->format('Y-m-d H:i T')
            : null;

        // QBO
        $qboClientId = Setting::getEncrypted('qbo_client_id');
        $qboHasSecret = (bool) Setting::getValue('qbo_client_secret');
        $qboEnvironment = Setting::getValue('qbo_environment', 'sandbox');
        $qboRealmId = Setting::getValue('qbo_realm_id');
        $qboConnected = (bool) $qboRealmId && (bool) Setting::getValue('qbo_access_token');
        $qboTokenExpiresAt = $fmtTs(Setting::getValue('qbo_token_expires_at'));
        $qboAutoPush = Setting::getValue('qbo_auto_push_invoices') === '1';
        $qboHasWebhookToken = (bool) Setting::getEncrypted('qbo_webhook_verifier_token');
        $qboDefaultIncomeId = Setting::getValue('qbo_default_income_account_id');
        $qboDefaultExpenseId = Setting::getValue('qbo_default_expense_account_id');
        $qboIncomeAccounts = [];
        $qboExpenseAccounts = [];
        if ($qboConnected) {
            try {
                $qboSync = app(\App\Services\Qbo\QboSyncService::class);
                $qboIncomeAccounts = $qboSync->listIncomeAccounts();
                $qboExpenseAccounts = $qboSync->listExpenseAccounts();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Settings] Failed to list QBO accounts', ['error' => $e->getMessage()]);
            }
        }

        // Ninja
        $ninjaClientId = Setting::getValue('ninja_client_id');
        $ninjaConnected = $ninjaClientId ? $ninja->isHealthy() : false;
        $ninjaConnectedAt = $fmtTs(Setting::getValue('ninja_connected_at'));

        // Stripe
        $stripeConfigured = StripeConfig::isConfigured();
        $stripeMode = StripeConfig::get('mode');
        $stripeConnected = (bool) $fmtTs(Setting::getValue('stripe_connected_at'));
        $stripeAutoPush = Setting::getValue('stripe_auto_push_invoices') === '1';

        // Level
        $levelHasApiKey = (bool) (Setting::getValue('level_api_key') ?? config('services.level.api_key'));
        $levelConnected = $levelHasApiKey ? $level->isHealthy() : false;
        $levelConnectedAt = $fmtTs(Setting::getValue('level_connected_at'));
        $levelWebhookSecret = LevelConfig::get('webhook_secret');
        $levelHasInstallAccountToken = (bool) LevelConfig::get('install_account_token');

        // Mesh
        $meshHasApiKey = MeshConfig::isConfigured();
        $meshBaseUrl = MeshConfig::get('base_url');
        $meshConnected = (bool) $fmtTs(Setting::getValue('mesh_connected_at'));

        // Huntress
        $huntressConfigured = HuntressConfig::isConfigured();
        $huntressConnected = (bool) $fmtTs(Setting::getValue('huntress_connected_at'));

        // Servosity
        $servosityConfigured = ServosityConfig::isConfigured();
        $servosityConnected = (bool) $fmtTs(Setting::getValue('servosity_connected_at'));
        $servosityConnectedAt = $fmtTs(Setting::getValue('servosity_connected_at'));

        // Control D
        $controldConfigured = ControlDConfig::isConfigured();
        $controldConnected = (bool) $fmtTs(Setting::getValue('controld_connected_at'));

        // Zorus
        $zorusConfigured = ZorusConfig::isConfigured();
        $zorusConnected = (bool) $fmtTs(Setting::getValue('zorus_connected_at'));

        // AppRiver
        $appriverConfigured = AppRiverConfig::isConfigured();
        $appriverConnected = \App\Services\AppRiver\AppRiverClient::isConnected();
        $appriverConnectedAt = $fmtTs(Setting::getValue('appriver_connected_at'));
        $appriverEnabled = AppRiverConfig::isEnabled();

        // Printix
        $printixConfigured = PrintixConfig::isConfigured();
        $printixPartnerId = PrintixConfig::get('partner_id');
        $printixHasSecret = (bool) PrintixConfig::get('client_secret');
        $printixConnected = (bool) $fmtTs(Setting::getValue('printix_connected_at'));
        $printixEnabled = PrintixConfig::isEnabled();

        // CIPP
        $cippConfigured = CippConfig::isConfigured();
        $cippApiUrl = CippConfig::get('api_url');
        $cippTenantId = CippConfig::get('tenant_id');
        $cippClientId = CippConfig::get('client_id');
        $cippApplicationId = CippConfig::get('application_id');
        $cippHasSecret = (bool) Setting::getValue('cipp_client_secret');
        $cippMcpClientId = CippConfig::get('mcp_client_id');
        $cippMcpHasSecret = (bool) Setting::getValue('cipp_mcp_client_secret');
        $cippMcpConfigured = CippConfig::isMcpConfigured();
        $cippConnected = (bool) $fmtTs(Setting::getValue('cipp_connected_at'));

        // Plivo
        $plivoAuthId = Setting::settingOrConfig('plivo_auth_id', 'services.plivo.auth_id');
        $plivoDidNumber = Setting::settingOrConfig('plivo_did_number', 'services.plivo.did_number');
        $plivoAppId = Setting::settingOrConfig('plivo_app_id', 'services.plivo.app_id');
        $plivoHasToken = (bool) (Setting::getValue('plivo_auth_token') ?? config('services.plivo.auth_token'));
        $plivoHasWebhookSecret = (bool) (Setting::getValue('plivo_webhook_secret') ?? config('services.plivo.webhook_secret'));
        $plivoConnectedAt = $fmtTs(Setting::getValue('plivo_connected_at'));
        $plivoHoldMusicUrl = Setting::settingOrConfig('plivo_hold_music_url', 'services.plivo.hold_music_url');

        // Graph (Email)
        $graphMailbox = Setting::getValue('graph_mailbox');
        $graphConnectedAt = $fmtTs(Setting::getValue('graph_connected_at'));
        $graphEmailSignature = Setting::getValue('email_signature') ?? '';
        $emailAutoTicket = (bool) Setting::getValue('email_auto_ticket');

        // Ticket automation
        $autoCloseResolvedDays = (int) Setting::getValue('auto_close_resolved_days', 0);

        // Avatars
        $gravatarDefault = Setting::getValue('gravatar_default', '404');

        // AI Provider
        $aiProvider = AiConfig::provider();
        $aiHasKey = AiConfig::isConfigured();
        $aiModel = AiConfig::get('model');
        $aiConnectedAt = $fmtTs(Setting::getValue('ai_connected_at'));
        $aiReplyGuidelines = Setting::getValue('ai_reply_guidelines', '');

        // Transcription
        $transcriptionConfigured = TranscriptionConfig::isConfigured();
        $transcriptionHasKey = (bool) Setting::getEncrypted('openai_api_key');
        $transcriptionAutoEnabled = TranscriptionConfig::autoTranscribeEnabled();
        $transcriptionMinSeconds = TranscriptionConfig::minDurationSeconds();

        // Huntress CW Compat (Incident Tickets)
        $huntressCwConfigured = HuntressConfig::isCwCompatConfigured();
        $huntressCwHost = preg_replace('#^https?://#', '', rtrim(config('app.url'), '/').'/api/huntress');
        $huntressCwCompanyId = 'SoundPSA';
        $huntressCwPublicKey = Setting::getValue('huntress_cw_public_key', '');
        $huntressCwSystemUserId = HuntressConfig::get('system_user_id');
        $huntressCwUsers = \App\Models\User::active()->orderBy('name')->get(['id', 'name', 'is_active']);
        if ($huntressCwSystemUserId && ! $huntressCwUsers->contains('id', (int) $huntressCwSystemUserId)) {
            $inactive = \App\Models\User::find($huntressCwSystemUserId, ['id', 'name', 'is_active']);
            if ($inactive) {
                $huntressCwUsers->push($inactive)->sortBy('name')->values();
            }
        }

        // T2T / HelpDesk Buttons
        $t2tConfigured = T2TConfig::isConfigured();
        // T2T expects hostname/path without https:// — it prepends the protocol itself
        $t2tApiUrl = preg_replace('#^https?://#', '', url('/api/tier2tickets/v4_6_release'));
        $t2tCompanyId = Setting::getValue('t2t_company_id', 'SoundPSA');
        $t2tSystemUserId = T2TConfig::get('system_user_id');
        $t2tCallbackUrl = Setting::getValue('t2t_callback_url');
        $t2tUsers = \App\Models\User::active()->orderBy('name')->get(['id', 'name', 'is_active']);
        if ($t2tSystemUserId && ! $t2tUsers->contains('id', (int) $t2tSystemUserId)) {
            $inactive = \App\Models\User::find($t2tSystemUserId, ['id', 'name', 'is_active']);
            if ($inactive) {
                $t2tUsers->push($inactive)->sortBy('name')->values();
            }
        }

        // Integration enabled toggles
        $ninjaEnabled = \App\Support\NinjaConfig::isEnabled();
        $levelEnabled = LevelConfig::isEnabled();
        $meshEnabled = MeshConfig::isEnabled();
        $cippEnabled = CippConfig::isEnabled();
        $cippMcpEnabled = CippConfig::isMcpRelayEnabled();
        $cippContactSyncEnabled = CippConfig::isContactSyncEnabled();
        $cippDeviceSyncEnabled = CippConfig::isDeviceSyncEnabled();
        $cippMcpCatalogSyncEnabled = CippConfig::isMcpCatalogSyncEnabled();
        $huntressEnabled = HuntressConfig::isEnabled();
        $servosityEnabled = ServosityConfig::isEnabled();
        $controldEnabled = ControlDConfig::isEnabled();
        $zorusEnabled = ZorusConfig::isEnabled();
        $plivoEnabled = PlivoConfig::isEnabled();
        $graphEnabled = Setting::getValue('graph_enabled', '1') === '1';
        $stripeEnabled = StripeConfig::isEnabled();
        $t2tEnabled = T2TConfig::isEnabled();
        $aiEnabled = AiConfig::isEnabled();

        // Triage
        $triageEnabled = \App\Support\TriageConfig::isEnabled();
        $triageAutoNew = (bool) Setting::getValue('triage_auto_new_tickets');
        $triageAutoReview = (bool) Setting::getValue('triage_auto_review');
        $triageReviewAutoClose = (bool) Setting::getValue('triage_review_auto_close');
        $triageReviewFrequency = Setting::getValue('triage_review_frequency_minutes') ?? 60;
        $triageReviewThreshold = Setting::getValue('triage_review_auto_close_threshold') ?? 80;
        $triageDefaultAssignee = Setting::getValue('triage_default_assignee_id');
        $triageSystemUser = Setting::getValue('triage_system_user_id');
        $triageModel = Setting::getValue('triage_model') ?? '';
        $triageMaxTokens = Setting::getValue('triage_max_tokens_per_run') ?? 200000;
        $triageDailyTokens = Setting::getValue('triage_daily_token_limit') ?? 2000000;
        $triageBatchSize = Setting::getValue('triage_review_batch_size') ?? 20;
        $triageStages = [];
        foreach (['contact_resolution', 'junk_filter', 'classification', 'asset_assignment', 'technical_triage', 'conversation_review'] as $s) {
            $v = Setting::getValue("triage_stage_{$s}");
            $triageStages[$s] = $v === null || (bool) $v;
        }
        // ScreenConnect
        $screenconnectBaseUrl = Setting::getValue('screenconnect_base_url', '');
        $screenconnectWebhookSecret = Setting::getValue('screenconnect_webhook_secret', '');
        $screenconnectEnabled = Setting::getValue('screenconnect_enabled', '0') === '1';
        $screenconnectConfigured = ! empty($screenconnectBaseUrl) && ! empty($screenconnectWebhookSecret);

        // Tactical RMM
        $tacticalConfigured = TacticalConfig::isConfigured();
        $tacticalApiUrl = TacticalConfig::get('api_url');
        $tacticalWebUrl = TacticalConfig::webUrl(); // psa-6h5r: web dashboard base
        $tacticalConnected = (bool) Setting::getValue('tactical_connected_at');
        $tacticalEnabled = Setting::getValue('tactical_enabled', '1') === '1';

        // Webhook-health signal (P1 visible trust signal) — sourced from tactical_webhooks.
        $tacticalWebhookLastAt = $fmtTs(TacticalWebhook::max('created_at'));
        $tacticalWebhookProcessed24h = TacticalWebhook::where('status', 'processed')
            ->where('processed_at', '>=', now()->subDay())
            ->count();
        $tacticalWebhookFailed = TacticalWebhook::where('status', 'failed')->count();

        // AI Assistant settings
        $assistantEnabled = \App\Support\AssistantConfig::isEnabled();
        $assistantMaxMessages = Setting::getValue('assistant_max_messages') ?? 50;
        $assistantDailyTokens = Setting::getValue('assistant_daily_token_limit') ?? 500000;

        // AI Technician settings
        $technicianEnabled = \App\Support\TechnicianConfig::enabled();
        $technicianEmergencyEnabled = \App\Support\TechnicianConfig::emergencyEnabled();
        $technicianAutoAck = ((\App\Support\TechnicianConfig::tierMap()['send_ack'] ?? null) === 'auto');
        // psa-uvuy: the Teams webhook is a masked secret — expose only whether one is
        // stored (drives the "••••••••" placeholder), never the raw URL to the view.
        $technicianTeamsWebhookSet = \App\Support\TechnicianConfig::teamsWebhookUrl() !== null;
        $technicianNotifyEmail = \App\Support\TechnicianConfig::notifyEmail();
        $technicianDigestEnabled = \App\Support\TechnicianConfig::digestEnabled();
        $technicianDigestTime = \App\Support\TechnicianConfig::digestTimeLocal();
        $technicianHeartbeatInterval = \App\Support\TechnicianConfig::heartbeatIntervalMinutes();
        $allowArbitraryEmailRecipients = \App\Support\TechnicianConfig::allowArbitraryEmailRecipients();
        $directEmailNewRecipients = \App\Support\TechnicianConfig::directEmailNewRecipients();

        // Teams bot (Bot Framework) credentials — App ID + tenant are plain; the Entra
        // client secret is masked/write-only (only "is one stored?" reaches the view).
        $teamsBotAppId = \App\Support\TeamsBotConfig::appId();
        $teamsBotTenantId = \App\Support\TeamsBotConfig::tenantId();
        $teamsBotSecretSet = \App\Support\TeamsBotConfig::clientSecret() !== null;
        $teamsBotConfigured = \App\Support\TeamsBotConfig::configured();
        $teamsBotEnabled = \App\Support\TeamsBotConfig::enabled();
        // Ambient "culture" dials (psa-i4cf) — surfaced as operator controls, pre-populated.
        $teamsBotAmbientEnabled = \App\Support\TeamsBotConfig::ambientEnabled();
        $teamsBotEagerness = \App\Support\TeamsBotConfig::ambientEagerness();
        $teamsBotBanter = \App\Support\TeamsBotConfig::ambientBanter();
        $teamsBotCooldown = \App\Support\TeamsBotConfig::ambientCooldownSeconds();

        // AI Staff roster (Teams AI-Staff Personas P1 Task 5) — read-only stub.
        // SAFE fields only; bot_client_secret must never reach the view. Personas
        // are hand-registered scaffolds in P1 — the provisioning wizard (create/
        // edit/delete) arrives in P2.
        $teamsPersonas = \App\Models\TeamsPersona::orderBy('display_name')->get()
            ->map(fn (\App\Models\TeamsPersona $p) => [
                'id' => $p->id,
                'persona_key' => $p->persona_key,
                'display_name' => $p->display_name,
                'role_blurb' => $p->role_blurb,
                'enabled' => (bool) $p->enabled,
                'has_secret' => $p->hasSecret(),
                'mcp_token_label' => $p->mcp_token_label,
                'bot_app_id' => $p->bot_app_id,
                // Operator-lane binding (bd psa-3vr5). conversation_id is an opaque
                // Bot Framework id, not a secret — safe to surface so the operator
                // can confirm what a reset would clear.
                'conversation_id' => ($p->conversation_refs ?? [])['conversation_id'] ?? null,
                'conversation_bound' => filled(($p->conversation_refs ?? [])['conversation_id'] ?? null),
            ]);

        // Phase 2: emergency / escalation / availability / SMS view vars
        $technicianEscalationChain = \App\Support\TechnicianConfig::escalationChain();
        $technicianEscalationTimeout = \App\Support\TechnicianConfig::escalationTimeoutMinutes();
        $technicianEmergencyReping = \App\Support\TechnicianConfig::emergencyRepingMinutes();
        $technicianStormWindow = \App\Support\TechnicianConfig::stormWindowMinutes();
        $technicianMaxHoldMessage = \App\Support\TechnicianConfig::maxHoldMessage();
        $technicianMaxHoldAuto = ((\App\Support\TechnicianConfig::tierMap()['send_max_hold'] ?? null) === 'auto');
        $technicianEmergencyKeywords = implode("\n", \App\Support\TechnicianConfig::emergencyKeywords());
        $technicianEmergencyAge = [
            'p1' => \App\Support\TechnicianConfig::emergencyAgeMinutes(\App\Enums\TicketPriority::P1),
            'p2' => \App\Support\TechnicianConfig::emergencyAgeMinutes(\App\Enums\TicketPriority::P2),
            'p3' => \App\Support\TechnicianConfig::emergencyAgeMinutes(\App\Enums\TicketPriority::P3),
            'p4' => \App\Support\TechnicianConfig::emergencyAgeMinutes(\App\Enums\TicketPriority::P4),
        ];
        // Per-operator availability and phone maps (keyed by user id string)
        $technicianAvailability = (function () {
            $raw = \App\Models\Setting::getValue('technician_operator_availability');
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];

            return is_array($decoded) ? array_map('strval', $decoded) : [];
        })();
        $technicianOperatorPhones = (function () {
            $raw = \App\Models\Setting::getValue('technician_operator_phones');
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];

            return is_array($decoded) ? array_map('strval', $decoded) : [];
        })();
        $activeUsers = \App\Models\User::active()->orderBy('name')->get(['id', 'name']);

        $selectedIds = array_filter([(int) $triageDefaultAssignee, (int) $triageSystemUser]);
        $users = \App\Models\User::active()->orderBy('name')->get(['id', 'name', 'is_active']);
        if ($selectedIds) {
            $missing = \App\Models\User::whereIn('id', $selectedIds)
                ->whereNotIn('id', $users->pluck('id'))
                ->get(['id', 'name', 'is_active']);
            if ($missing->isNotEmpty()) {
                $users = $users->concat($missing)->sortBy('name')->values();
            }
        }

        return view('settings.integrations', compact(
            'qboClientId', 'qboHasSecret', 'qboEnvironment', 'qboRealmId', 'qboConnected', 'qboTokenExpiresAt', 'qboAutoPush', 'qboHasWebhookToken', 'qboDefaultIncomeId', 'qboDefaultExpenseId', 'qboIncomeAccounts', 'qboExpenseAccounts',
            'stripeConfigured', 'stripeMode', 'stripeConnected', 'stripeAutoPush', 'stripeEnabled',
            'ninjaClientId', 'ninjaConnected', 'ninjaConnectedAt', 'ninjaEnabled',
            'levelHasApiKey', 'levelConnected', 'levelConnectedAt', 'levelWebhookSecret', 'levelHasInstallAccountToken', 'levelEnabled',
            'meshHasApiKey', 'meshBaseUrl', 'meshConnected', 'meshEnabled',
            'huntressConfigured', 'huntressConnected', 'huntressEnabled',
            'servosityConfigured', 'servosityConnected', 'servosityConnectedAt', 'servosityEnabled',
            'controldConfigured', 'controldConnected', 'controldEnabled',
            'zorusConfigured', 'zorusConnected', 'zorusEnabled',
            'appriverConfigured', 'appriverConnected', 'appriverConnectedAt', 'appriverEnabled',
            'printixConfigured', 'printixPartnerId', 'printixHasSecret', 'printixConnected', 'printixEnabled',
            'cippConfigured', 'cippApiUrl', 'cippTenantId', 'cippClientId', 'cippApplicationId', 'cippHasSecret', 'cippMcpClientId', 'cippMcpHasSecret', 'cippMcpConfigured', 'cippConnected', 'cippEnabled', 'cippMcpEnabled', 'cippContactSyncEnabled', 'cippDeviceSyncEnabled', 'cippMcpCatalogSyncEnabled',
            'plivoAuthId', 'plivoDidNumber', 'plivoAppId', 'plivoHasToken', 'plivoHasWebhookSecret', 'plivoConnectedAt', 'plivoEnabled', 'plivoHoldMusicUrl',
            'graphMailbox', 'graphConnectedAt', 'graphEmailSignature', 'emailAutoTicket', 'graphEnabled', 'autoCloseResolvedDays', 'gravatarDefault',
            'aiProvider', 'aiHasKey', 'aiModel', 'aiConnectedAt', 'aiEnabled', 'aiReplyGuidelines',
            'transcriptionConfigured', 'transcriptionHasKey', 'transcriptionAutoEnabled', 'transcriptionMinSeconds',
            'huntressCwConfigured', 'huntressCwHost', 'huntressCwCompanyId', 'huntressCwPublicKey', 'huntressCwSystemUserId', 'huntressCwUsers',
            't2tConfigured', 't2tApiUrl', 't2tCompanyId', 't2tCallbackUrl', 't2tUsers', 't2tSystemUserId', 't2tEnabled',
            'triageEnabled', 'triageAutoNew', 'triageAutoReview', 'triageReviewFrequency', 'triageReviewAutoClose', 'triageReviewThreshold',
            'triageDefaultAssignee', 'triageSystemUser', 'triageModel', 'triageMaxTokens', 'triageDailyTokens', 'triageBatchSize', 'triageStages',
            'assistantEnabled', 'assistantMaxMessages', 'assistantDailyTokens',
            'technicianEnabled', 'technicianEmergencyEnabled', 'technicianAutoAck',
            'technicianTeamsWebhookSet', 'technicianNotifyEmail', 'technicianDigestEnabled', 'technicianDigestTime', 'technicianHeartbeatInterval',
            'allowArbitraryEmailRecipients', 'directEmailNewRecipients',
            'technicianEscalationChain', 'technicianEscalationTimeout', 'technicianEmergencyReping', 'technicianStormWindow',
            'technicianMaxHoldMessage', 'technicianMaxHoldAuto', 'technicianEmergencyKeywords', 'technicianEmergencyAge',
            'technicianAvailability', 'technicianOperatorPhones', 'activeUsers',
            'teamsBotAppId', 'teamsBotTenantId', 'teamsBotSecretSet', 'teamsBotConfigured', 'teamsBotEnabled',
            'teamsBotAmbientEnabled', 'teamsBotEagerness', 'teamsBotBanter', 'teamsBotCooldown',
            'teamsPersonas',
            'screenconnectBaseUrl', 'screenconnectWebhookSecret', 'screenconnectEnabled', 'screenconnectConfigured',
            'tacticalConfigured', 'tacticalApiUrl', 'tacticalWebUrl', 'tacticalConnected', 'tacticalEnabled',
            'tacticalWebhookLastAt', 'tacticalWebhookProcessed24h', 'tacticalWebhookFailed',
            'users',
        ));
    }

    public function toggleIntegration(Request $request)
    {
        $allowed = [
            'ninja', 'level', 'mesh', 'cipp', 'cipp_mcp', 'cipp_contact_sync', 'cipp_device_sync', 'cipp_mcp_catalog_sync', 'huntress', 'servosity', 'controld', 'zorus', 'appriver', 'printix',
            'plivo', 'graph', 'stripe', 't2t', 'ai', 'screenconnect', 'tactical',
        ];

        $request->validate([
            'integration' => ['required', 'string', 'in:'.implode(',', $allowed)],
        ]);

        $key = $request->input('integration').'_enabled';
        $enabled = $request->has('enabled') ? '1' : '0';

        if ($request->input('integration') === 'cipp_mcp' && $enabled === '1' && ! CippConfig::isMcpConfigured()) {
            Setting::setValue('cipp_mcp_enabled', '0');

            return redirect()->route('settings.integrations')
                ->with('error', 'CIPP MCP relay requires MCP Client ID and secret before it can be enabled.');
        }

        if ($request->input('integration') === 'cipp_mcp_catalog_sync' && $enabled === '1' && ! CippConfig::isMcpConfigured()) {
            Setting::setValue('cipp_mcp_catalog_sync_enabled', '0');

            return redirect()->route('settings.integrations')
                ->with('error', 'CIPP MCP catalog auto-sync requires MCP Client ID and secret before it can be enabled.');
        }

        Setting::setValue($key, $enabled);

        $label = $request->input('integration');
        $state = $enabled === '1' ? 'enabled' : 'disabled';

        return redirect()->route('settings.integrations')
            ->with('success', ucfirst($label)." integration {$state}.");
    }

    // --- QBO ---

    public function updateQbo(Request $request)
    {
        // Handle auto-push toggle (submitted alone via switch)
        if ($request->has('auto_push_invoices') && ! $request->has('client_id')) {
            Setting::setValue('qbo_auto_push_invoices', $request->input('auto_push_invoices'));

            $label = $request->input('auto_push_invoices') === '1' ? 'enabled' : 'disabled';

            return redirect()->route('settings.integrations')
                ->with('success', "Auto-push invoices to QBO {$label}.");
        }

        $validated = $request->validate([
            'client_id' => 'required|string|max:255',
            'client_secret' => 'nullable|string|min:1|max:500',
            'environment' => 'required|in:sandbox,production',
            'webhook_verifier_token' => 'nullable|string|min:1|max:500',
            'default_income_account_id' => 'nullable|string|max:50',
            'default_expense_account_id' => 'nullable|string|max:50',
        ]);

        // Only update client_id if a real value was provided (not the masked placeholder)
        if ($validated['client_id'] !== '********') {
            Setting::setEncrypted('qbo_client_id', $validated['client_id']);
        }
        Setting::setValue('qbo_environment', $validated['environment']);

        if (! empty($validated['client_secret'])) {
            Setting::setEncrypted('qbo_client_secret', $validated['client_secret']);
        }

        if (! empty($validated['webhook_verifier_token'])) {
            Setting::setEncrypted('qbo_webhook_verifier_token', $validated['webhook_verifier_token']);
        }

        Setting::setValue('qbo_default_income_account_id', $validated['default_income_account_id'] ?? '');
        Setting::setValue('qbo_default_expense_account_id', $validated['default_expense_account_id'] ?? '');

        return redirect()->route('settings.integrations')
            ->with('success', 'QuickBooks credentials saved.');
    }

    // --- Stripe ---

    public function updateStripe(Request $request)
    {
        // Handle auto-push toggle (submitted alone via switch)
        if ($request->has('auto_push_invoices') && ! $request->has('secret_key')) {
            Setting::setValue('stripe_auto_push_invoices', $request->input('auto_push_invoices'));
            $label = $request->input('auto_push_invoices') === '1' ? 'enabled' : 'disabled';

            return redirect()->route('settings.integrations')
                ->with('success', "Auto-push invoices to Stripe {$label}.");
        }

        $validated = $request->validate([
            'secret_key' => 'nullable|string|min:1|max:500',
            'mode' => 'required|in:test,live',
        ]);

        if (! empty($validated['secret_key'])) {
            Setting::setEncrypted('stripe_secret_key', $validated['secret_key']);
        }
        Setting::setValue('stripe_mode', $validated['mode']);

        return redirect()->route('settings.integrations')
            ->with('success', 'Stripe credentials saved.');
    }

    public function testStripe()
    {
        if (! StripeConfig::isConfigured()) {
            return response()->json(['success' => false, 'message' => 'Stripe API key not configured.']);
        }

        try {
            $client = new \App\Services\Stripe\StripeClient([
                'secret_key' => StripeConfig::get('secret_key'),
            ]);

            if ($client->isHealthy()) {
                Setting::setValue('stripe_connected_at', now()->toDateTimeString());

                return response()->json(['success' => true, 'message' => 'Connected to Stripe!']);
            }

            return response()->json(['success' => false, 'message' => 'Stripe API returned an error.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // --- Ninja ---

    public function updateNinja(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|string|max:255',
            'client_secret' => 'nullable|string|min:1|max:500',
        ]);

        Setting::setValue('ninja_client_id', $validated['client_id']);

        if (! empty($validated['client_secret'])) {
            Setting::setEncrypted('ninja_client_secret', $validated['client_secret']);
        }

        Cache::forget('ninja_api_token');

        return redirect()->route('settings.integrations')
            ->with('success', 'NinjaRMM credentials saved.');
    }

    public function testNinja()
    {
        $clientId = Setting::getValue('ninja_client_id');
        $clientSecret = Setting::getEncrypted('ninja_client_secret');

        if (! $clientId || ! $clientSecret) {
            return response()->json(['success' => false, 'message' => 'Credentials not configured.']);
        }

        $config = array_merge(config('services.ninja'), [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        $ninja = new NinjaClient($config, app(CacheInterface::class));

        if ($ninja->isHealthy()) {
            Setting::setValue('ninja_connected_at', now()->toDateTimeString());

            return response()->json(['success' => true, 'message' => 'Connected to NinjaRMM successfully!']);
        }

        return response()->json(['success' => false, 'message' => 'Could not connect to NinjaRMM. Check credentials.']);
    }

    // --- Level ---

    public function updateLevel(Request $request)
    {
        $validated = $request->validate([
            'api_key' => 'nullable|string|min:1|max:500',
            'webhook_secret' => 'nullable|string|min:1|max:500',
            'install_account_token' => 'nullable|string|min:1|max:500',
        ]);

        if (! empty($validated['api_key'])) {
            Setting::setEncrypted('level_api_key', $validated['api_key']);
        }
        if (! empty($validated['webhook_secret'])) {
            Setting::setEncrypted('level_webhook_secret', $validated['webhook_secret']);
        }
        if (! empty($validated['install_account_token'])) {
            Setting::setEncrypted('level_install_account_token', $validated['install_account_token']);
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'Level RMM credentials saved.');
    }

    public function testLevel()
    {
        $apiKey = LevelConfig::get('api_key');

        if (! $apiKey) {
            return response()->json(['success' => false, 'message' => 'API key not configured.']);
        }

        try {
            $client = new GuzzleClient(['timeout' => 5]);
            $response = $client->get('https://api.level.io/v2/devices', [
                'query' => ['limit' => 1],
                'headers' => [
                    'Authorization' => $apiKey,
                    'Accept' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                Setting::setValue('level_connected_at', now()->toDateTimeString());

                return response()->json(['success' => true, 'message' => 'Connected to Level RMM successfully!']);
            }
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not connect to Level: '.$e->getMessage()]);
        }

        return response()->json(['success' => false, 'message' => 'Could not connect to Level RMM. Check API key.']);
    }

    // --- ScreenConnect ---

    public function updateScreenConnect(Request $request)
    {
        $validated = $request->validate([
            'base_url' => 'nullable|url|max:255',
            'generate_secret' => 'nullable|boolean',
        ]);

        if (! empty($validated['base_url'])) {
            Setting::setValue('screenconnect_base_url', rtrim($validated['base_url'], '/'));
        }

        if ($request->boolean('generate_secret')) {
            Setting::setValue('screenconnect_webhook_secret', ScreenConnectConfig::generateSecret());
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'ScreenConnect settings saved.');
    }

    // --- Graph (Email) ---

    public function updateGraph(Request $request)
    {
        $validated = $request->validate([
            'mailbox' => 'required|email|max:255',
        ]);

        Setting::setValue('graph_mailbox', strtolower($validated['mailbox']));

        Cache::forget('graph_api_token');

        return redirect()->route('settings.integrations')
            ->with('success', 'Microsoft Graph mailbox saved.');
    }

    public function updateGraphSignature(Request $request)
    {
        $validated = $request->validate([
            'email_signature' => 'nullable|string|max:2000',
            'email_auto_ticket' => 'boolean',
        ]);

        Setting::setValue('email_signature', $validated['email_signature']);
        Setting::setValue('email_auto_ticket', $request->boolean('email_auto_ticket'));

        return redirect()->route('settings.integrations')
            ->with('success', 'Email signature saved.');
    }

    public function testGraph(GraphClient $graph, GraphWebhookManager $webhookManager)
    {
        $mailbox = Setting::getValue('graph_mailbox');

        if (! $mailbox) {
            return response()->json(['success' => false, 'message' => 'Mailbox address not configured.']);
        }

        if (! $graph->isHealthy()) {
            return response()->json(['success' => false, 'message' => 'Could not authenticate with Microsoft Graph. Check Entra app registration.']);
        }

        try {
            $graph->getMailboxMessages($mailbox, [
                '$select' => 'id,subject',
                '$top' => 1,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Auth OK but cannot read mailbox: '.$e->getMessage()]);
        }

        // Activate webhook subscription so emails flow in immediately
        $webhookMessage = '';
        try {
            $webhookManager->ensureSubscription();
            $webhookMessage = ' Webhook subscription active.';
        } catch (\Throwable $e) {
            $webhookMessage = ' Warning: webhook subscription failed — emails will still import via hourly poll.';
            Log::warning('[Graph] Webhook subscription failed during test', ['error' => $e->getMessage()]);
        }

        Setting::setValue('graph_connected_at', now()->toDateTimeString());

        return response()->json(['success' => true, 'message' => "Connected! Can read mailbox {$mailbox}.{$webhookMessage}"]);
    }

    // --- AI Provider ---

    public function updateAi(Request $request)
    {
        $validated = $request->validate([
            'provider' => 'required|in:anthropic,openai',
            'api_key' => 'nullable|string|min:1|max:500',
            'model' => 'nullable|string|max:100',
            'reply_guidelines' => 'nullable|string|max:2000',
        ]);

        Setting::setValue('ai_provider', $validated['provider']);
        Setting::setValue('ai_model', $validated['model'] ?: null);
        Setting::setValue('ai_reply_guidelines', $validated['reply_guidelines'] ?: null);

        if (! empty($validated['api_key'])) {
            Setting::setEncrypted('ai_api_key', $validated['api_key']);
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'AI provider settings saved.');
    }

    public function testAi()
    {
        $provider = AiConfig::provider();
        $apiKey = AiConfig::get('api_key');
        $model = AiConfig::model();

        if (! $apiKey) {
            return response()->json(['success' => false, 'message' => 'API key not configured.']);
        }

        try {
            $client = new GuzzleClient(['timeout' => 15]);

            if ($provider === 'anthropic') {
                $response = $client->post('https://api.anthropic.com/v1/messages', [
                    'headers' => [
                        'x-api-key' => $apiKey,
                        'anthropic-version' => '2023-06-01',
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $model,
                        'max_tokens' => 16,
                        'messages' => [['role' => 'user', 'content' => 'Hi']],
                    ],
                ]);
            } else {
                $response = $client->post('https://api.openai.com/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => "Bearer {$apiKey}",
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $model,
                        'max_tokens' => 16,
                        'messages' => [['role' => 'user', 'content' => 'Hi']],
                    ],
                ]);
            }

            if ($response->getStatusCode() === 200) {
                Setting::setValue('ai_connected_at', now()->toDateTimeString());
                $providerLabel = $provider === 'anthropic' ? 'Anthropic' : 'OpenAI';

                return response()->json(['success' => true, 'message' => "Connected to {$providerLabel}! Model: {$model}"]);
            }
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Connection failed: '.$e->getMessage()]);
        }

        return response()->json(['success' => false, 'message' => 'Could not connect. Check API key and model.']);
    }

    // --- Transcription ---

    public function updateTranscription(Request $request)
    {
        $validated = $request->validate([
            'openai_api_key' => 'nullable|string|min:1|max:500',
            'auto_transcribe' => 'boolean',
            'min_seconds' => 'nullable|integer|min:0|max:3600',
        ]);

        if (! empty($validated['openai_api_key'])) {
            Setting::setEncrypted('openai_api_key', $validated['openai_api_key']);
        }

        Setting::setValue('auto_transcribe_calls', $request->boolean('auto_transcribe'));
        Setting::setValue('auto_transcribe_min_seconds', $validated['min_seconds'] ?? 30);

        return redirect()->route('settings.integrations')
            ->with('success', 'Transcription settings saved.');
    }

    public function testTranscription()
    {
        $apiKey = TranscriptionConfig::whisperApiKey();

        if (! $apiKey) {
            return response()->json(['success' => false, 'message' => 'No OpenAI API key configured for Whisper.']);
        }

        try {
            $client = new GuzzleClient(['timeout' => 10]);
            $response = $client->get('https://api.openai.com/v1/models/whisper-1', [
                'headers' => [
                    'Authorization' => "Bearer {$apiKey}",
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                return response()->json(['success' => true, 'message' => 'Whisper API key is valid! Transcription ready.']);
            }
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Whisper API test failed: '.$e->getMessage()]);
        }

        return response()->json(['success' => false, 'message' => 'Could not verify Whisper API key.']);
    }

    // --- Mesh ---

    public function updateMesh(Request $request)
    {
        $validated = $request->validate([
            'api_key' => 'nullable|string|min:1|max:500',
            'base_url' => 'nullable|url|max:255',
        ]);

        if (! empty($validated['api_key'])) {
            Setting::setEncrypted('mesh_api_key', $validated['api_key']);
        }
        if (! empty($validated['base_url'])) {
            Setting::setValue('mesh_base_url', $validated['base_url']);
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'Mesh credentials saved.');
    }

    public function testMesh()
    {
        if (! MeshConfig::isConfigured()) {
            return response()->json(['success' => false, 'message' => 'API key not configured.']);
        }

        try {
            $client = new GuzzleClient(['timeout' => 10]);
            $baseUrl = rtrim(MeshConfig::get('base_url'), '/');
            $response = $client->get("{$baseUrl}/api/customers/", [
                'query' => ['_size' => 1],
                'headers' => [
                    'API-KEY' => MeshConfig::get('api_key'),
                    'Accept' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                Setting::setValue('mesh_connected_at', now()->toDateTimeString());

                return response()->json(['success' => true, 'message' => 'Connected to Mesh Email Security!']);
            }

            return response()->json(['success' => false, 'message' => "Unexpected status: {$response->getStatusCode()}"]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function syncMesh()
    {
        if (! MeshConfig::isConfigured()) {
            return back()->with('error', 'Mesh is not configured.');
        }

        try {
            $client = new \App\Services\Mesh\MeshClient([
                'api_key' => MeshConfig::get('api_key'),
                'base_url' => MeshConfig::get('base_url'),
            ]);
            $service = new \App\Services\Mesh\MeshLicenseSyncService($client);
            $result = $service->syncLicenses();

            return back()->with('success', "Mesh sync complete: {$result->created} created, {$result->updated} updated.");
        } catch (\Throwable $e) {
            return back()->with('error', "Mesh sync failed: {$e->getMessage()}");
        }
    }

    // --- CIPP ---

    public function updateCipp(Request $request)
    {
        $validated = $request->validate([
            'api_url' => 'nullable|url|max:255',
            'tenant_id' => 'nullable|string|max:255',
            'client_id' => 'nullable|string|max:255',
            'client_secret' => 'nullable|string|min:1|max:500',
            'application_id' => 'nullable|string|max:255',
            'mcp_client_id' => 'nullable|string|max:255',
            'mcp_client_secret' => 'nullable|string|min:1|max:500',
        ]);

        if (! empty($validated['api_url'])) {
            Setting::setValue('cipp_api_url', $validated['api_url']);
        }
        if (! empty($validated['tenant_id'])) {
            Setting::setValue('cipp_tenant_id', $validated['tenant_id']);
        }
        if (! empty($validated['client_id'])) {
            Setting::setValue('cipp_client_id', $validated['client_id']);
        }
        if (! empty($validated['client_secret'])) {
            Setting::setEncrypted('cipp_client_secret', $validated['client_secret']);
        }
        if (! empty($validated['application_id'])) {
            Setting::setValue('cipp_application_id', $validated['application_id']);
        }
        if (! empty($validated['mcp_client_id'])) {
            Setting::setValue('cipp_mcp_client_id', $validated['mcp_client_id']);
        }
        if (! empty($validated['mcp_client_secret'])) {
            Setting::setEncrypted('cipp_mcp_client_secret', $validated['mcp_client_secret']);
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'CIPP credentials saved.');
    }

    public function testCipp()
    {
        if (! CippConfig::isConfigured()) {
            return response()->json(['success' => false, 'message' => 'CIPP is not configured. Fill in all required fields.']);
        }

        try {
            $client = new \App\Services\Cipp\CippClient(
                [
                    'api_url' => CippConfig::get('api_url'),
                    'tenant_id' => CippConfig::get('tenant_id'),
                    'client_id' => CippConfig::get('client_id'),
                    'client_secret' => CippConfig::get('client_secret'),
                    'application_id' => CippConfig::get('application_id'),
                ],
                app(\Illuminate\Contracts\Cache\Repository::class),
            );

            $tenants = $client->listTenants();
            $count = is_array($tenants) ? count($tenants) : 0;

            Setting::setValue('cipp_connected_at', now()->toDateTimeString());

            return response()->json(['success' => true, 'message' => "Connected to CIPP! Found {$count} tenant(s)."]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function syncCipp()
    {
        if (! CippConfig::isConfigured()) {
            return back()->with('error', 'CIPP is not configured.');
        }

        try {
            $client = new \App\Services\Cipp\CippClient(
                [
                    'api_url' => CippConfig::get('api_url'),
                    'tenant_id' => CippConfig::get('tenant_id'),
                    'client_id' => CippConfig::get('client_id'),
                    'client_secret' => CippConfig::get('client_secret'),
                    'application_id' => CippConfig::get('application_id'),
                ],
                app(\Illuminate\Contracts\Cache\Repository::class),
            );
            $service = new \App\Services\Cipp\CippLicenseSyncService($client);
            $result = $service->syncLicenses();

            return back()->with('success', "CIPP sync complete: {$result->created} created, {$result->updated} updated.");
        } catch (\Throwable $e) {
            return back()->with('error', "CIPP sync failed: {$e->getMessage()}");
        }
    }

    public function syncCippMcpCatalog(CippMcpCatalogSyncService $service)
    {
        if (! CippConfig::isMcpConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'CIPP MCP is not configured. Save MCP Client ID and secret first.');
        }

        try {
            $result = $service->sync();

            return redirect()->route('settings.integrations')
                ->with('success', $result->summary());
        } catch (\Throwable $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "CIPP MCP catalog sync failed: {$e->getMessage()}");
        }
    }

    public function syncCippContacts(Request $request)
    {
        if (! CippConfig::isConfigured()) {
            return $request->expectsJson()
                ? response()->json(['error' => 'CIPP is not configured.'], 400)
                : back()->with('error', 'CIPP is not configured.');
        }

        // Dry-run mode — returns preview JSON
        if ($request->boolean('dry_run')) {
            $client = new \App\Services\Cipp\CippClient(
                [
                    'api_url' => CippConfig::get('api_url'),
                    'tenant_id' => CippConfig::get('tenant_id'),
                    'client_id' => CippConfig::get('client_id'),
                    'client_secret' => CippConfig::get('client_secret'),
                    'application_id' => CippConfig::get('application_id'),
                ],
                app(\Illuminate\Contracts\Cache\Repository::class),
            );
            $service = new \App\Services\Cipp\CippContactSyncService($client, app(\App\Services\PersonService::class));
            $result = $service->syncContacts(dryRun: true);

            return response()->json([
                'created' => $result->created,
                'updated' => $result->updated,
                'deactivated' => $result->deactivated,
                'errors' => $result->errors,
                'errorMessages' => $result->errorMessages,
                'summary' => $result->summary(),
                'details' => $result->details,
            ]);
        }

        $artisan = base_path('artisan');
        \Illuminate\Support\Facades\Process::path(base_path())
            ->timeout(600)
            ->start("php {$artisan} cipp:sync-contacts 2>&1 >> ".storage_path('logs/laravel.log').' &');

        return back()->with('success', 'CIPP contact sync started in the background. Check the People page shortly for results.');
    }

    public function syncCippDevices(Request $request)
    {
        if (! CippConfig::isConfigured()) {
            return $request->expectsJson()
                ? response()->json(['error' => 'CIPP is not configured.'], 400)
                : back()->with('error', 'CIPP is not configured.');
        }

        // Dry-run mode — returns preview JSON
        if ($request->boolean('dry_run')) {
            $client = new \App\Services\Cipp\CippClient(
                [
                    'api_url' => CippConfig::get('api_url'),
                    'tenant_id' => CippConfig::get('tenant_id'),
                    'client_id' => CippConfig::get('client_id'),
                    'client_secret' => CippConfig::get('client_secret'),
                    'application_id' => CippConfig::get('application_id'),
                ],
                app(\Illuminate\Contracts\Cache\Repository::class),
            );
            $service = new \App\Services\Cipp\CippDeviceSyncService($client);
            $result = $service->syncDevices(dryRun: true);

            return response()->json([
                'created' => $result->created,
                'updated' => $result->updated,
                'deactivated' => $result->deactivated,
                'errors' => $result->errors,
                'errorMessages' => $result->errorMessages,
                'summary' => $result->summary(),
                'details' => $result->details,
            ]);
        }

        $artisan = base_path('artisan');
        \Illuminate\Support\Facades\Process::path(base_path())
            ->timeout(600)
            ->start("php {$artisan} cipp:sync-devices 2>&1 >> ".storage_path('logs/laravel.log').' &');

        return back()->with('success', 'Intune device sync started in the background. Check asset detail pages for results.');
    }

    // --- Printix ---

    public function updatePrintix(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|string|max:255',
            'client_secret' => 'nullable|string|min:1|max:500',
            'partner_id' => 'required|string|max:36',
        ]);

        Setting::setEncrypted('printix_client_id', $validated['client_id']);
        if (! empty($validated['client_secret'])) {
            Setting::setEncrypted('printix_client_secret', $validated['client_secret']);
        }
        Setting::setValue('printix_partner_id', $validated['partner_id']);

        return redirect()->route('settings.integrations')
            ->with('success', 'Printix credentials saved.');
    }

    public function testPrintix()
    {
        if (! PrintixConfig::isConfigured()) {
            return response()->json(['success' => false, 'message' => 'Printix is not configured.']);
        }

        try {
            $client = new \App\Services\Printix\PrintixClient(
                [
                    'client_id' => PrintixConfig::get('client_id'),
                    'client_secret' => PrintixConfig::get('client_secret'),
                    'partner_id' => PrintixConfig::get('partner_id'),
                ],
                app(CacheInterface::class),
            );

            $tenants = $client->getTenants();
            $count = is_array($tenants) ? count($tenants) : 0;

            Setting::setValue('printix_connected_at', now()->toDateTimeString());

            return response()->json([
                'success' => true,
                'message' => "Connected. Found {$count} tenant(s).",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function syncPrintix()
    {
        if (! PrintixConfig::isConfigured()) {
            return back()->with('error', 'Printix is not configured.');
        }

        try {
            $client = new \App\Services\Printix\PrintixClient(
                [
                    'client_id' => PrintixConfig::get('client_id'),
                    'client_secret' => PrintixConfig::get('client_secret'),
                    'partner_id' => PrintixConfig::get('partner_id'),
                ],
                app(CacheInterface::class),
            );
            $service = new \App\Services\Printix\PrintixLicenseSyncService($client);
            $result = $service->syncLicenses();

            return back()->with('success', "Printix sync complete: {$result->created} created, {$result->updated} updated.");
        } catch (\Throwable $e) {
            return back()->with('error', "Printix sync failed: {$e->getMessage()}");
        }
    }

    // --- Huntress ---

    public function updateHuntress(Request $request)
    {
        $validated = $request->validate([
            'api_key' => 'nullable|string|min:1|max:500',
            'api_secret' => 'nullable|string|min:1|max:500',
        ]);

        if (! empty($validated['api_key'])) {
            Setting::setEncrypted('huntress_api_key', $validated['api_key']);
        }
        if (! empty($validated['api_secret'])) {
            Setting::setEncrypted('huntress_api_secret', $validated['api_secret']);
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'Huntress credentials saved.');
    }

    public function testHuntress()
    {
        if (! HuntressConfig::isConfigured()) {
            return response()->json(['success' => false, 'message' => 'API credentials not configured.']);
        }

        try {
            $client = new \App\Services\Huntress\HuntressClient([
                'api_key' => HuntressConfig::get('api_key'),
                'api_secret' => HuntressConfig::get('api_secret'),
            ]);

            if ($client->isHealthy()) {
                Setting::setValue('huntress_connected_at', now()->toDateTimeString());

                return response()->json(['success' => true, 'message' => 'Connected to Huntress!']);
            }

            return response()->json(['success' => false, 'message' => 'Huntress API returned an error.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function syncHuntress()
    {
        if (! HuntressConfig::isConfigured()) {
            return back()->with('error', 'Huntress is not configured.');
        }

        try {
            $client = new \App\Services\Huntress\HuntressClient([
                'api_key' => HuntressConfig::get('api_key'),
                'api_secret' => HuntressConfig::get('api_secret'),
            ]);
            $service = new \App\Services\Huntress\HuntressLicenseSyncService($client);
            $result = $service->syncLicenses();

            return back()->with('success', "Huntress sync complete: {$result->created} created, {$result->updated} updated.");
        } catch (\Throwable $e) {
            return back()->with('error', "Huntress sync failed: {$e->getMessage()}");
        }
    }

    public function updateHuntressCw(Request $request)
    {
        $validated = $request->validate([
            'system_user_id' => 'nullable|integer|exists:users,id',
        ]);

        if (array_key_exists('system_user_id', $validated)) {
            Setting::setValue('huntress_system_user_id', $validated['system_user_id'] ?? '');
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'Huntress incident settings saved.');
    }

    public function generateHuntressCwKey()
    {
        $key = HuntressConfig::generateApiKey();

        Setting::setEncrypted('huntress_cw_api_key', $key['private_key']);
        Setting::setValue('huntress_cw_public_key', $key['public_key']);

        return redirect()->route('settings.integrations')
            ->with('success', 'New Huntress CW credentials generated. Copy them into the Huntress portal — the private key will not be shown again.')
            ->with('huntress_cw_generated', $key);
    }

    // --- Servosity ---

    public function updateServosity(Request $request)
    {
        $validated = $request->validate([
            'api_token' => 'nullable|string|min:1|max:500',
            'base_url' => 'nullable|url|max:255',
            'totp_secret' => 'nullable|string|min:1|max:500',
        ]);

        if (! empty($validated['api_token'])) {
            Setting::setEncrypted('servosity_api_token', $validated['api_token']);
        }
        if (! empty($validated['base_url'])) {
            Setting::setValue('servosity_base_url', $validated['base_url']);
        }
        if (! empty($validated['totp_secret'])) {
            Setting::setEncrypted('servosity_totp_secret', $validated['totp_secret']);

            // Auto-detect TOTP enrollment ID from the Servosity API
            if (ServosityConfig::isConfigured()) {
                try {
                    $client = new \App\Services\Servosity\ServosityClient([
                        'api_token' => ServosityConfig::get('api_token'),
                        'base_url' => ServosityConfig::get('base_url'),
                    ]);
                    $enrollments = $client->get('current-user/verified-mfa/');
                    foreach ($enrollments as $enrollment) {
                        if (($enrollment['type'] ?? '') === 'TOTP') {
                            Setting::setValue('servosity_totp_enrollment_id', (string) $enrollment['id']);
                            break;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('[Servosity] Failed to auto-detect TOTP enrollment ID', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'Servosity credentials saved.');
    }

    public function testServosity()
    {
        if (! ServosityConfig::isConfigured()) {
            return response()->json(['success' => false, 'message' => 'API token not configured.']);
        }

        try {
            $client = new \App\Services\Servosity\ServosityClient([
                'api_token' => ServosityConfig::get('api_token'),
                'base_url' => ServosityConfig::get('base_url'),
            ]);

            if ($client->isHealthy()) {
                Setting::setValue('servosity_connected_at', now()->toDateTimeString());

                return response()->json(['success' => true, 'message' => 'Connected to Servosity!']);
            }

            return response()->json(['success' => false, 'message' => 'Servosity API returned an error.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function syncServosity()
    {
        if (! ServosityConfig::isConfigured()) {
            return back()->with('error', 'Servosity is not configured.');
        }

        try {
            $client = new \App\Services\Servosity\ServosityClient([
                'api_token' => ServosityConfig::get('api_token'),
                'base_url' => ServosityConfig::get('base_url'),
            ]);
            $service = new \App\Services\Servosity\ServosityLicenseSyncService($client);
            $result = $service->syncLicenses();

            return back()->with('success', "Servosity sync complete: {$result->created} created, {$result->updated} updated.");
        } catch (\Throwable $e) {
            return back()->with('error', "Servosity sync failed: {$e->getMessage()}");
        }
    }

    // --- Control D ---

    public function updateControlD(Request $request)
    {
        $validated = $request->validate([
            'api_key' => 'nullable|string|min:1|max:500',
        ]);

        if (! empty($validated['api_key'])) {
            Setting::setEncrypted('controld_api_key', $validated['api_key']);
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'Control D credentials saved.');
    }

    public function testControlD()
    {
        if (! ControlDConfig::isConfigured()) {
            return response()->json(['success' => false, 'message' => 'API credentials not configured.']);
        }

        try {
            $client = new \App\Services\ControlD\ControlDClient([
                'api_key' => ControlDConfig::get('api_key'),
            ]);

            if ($client->isHealthy()) {
                Setting::setValue('controld_connected_at', now()->toDateTimeString());

                // Auto-detect stats_endpoint from org API
                try {
                    $statsEndpoint = $client->getStatsEndpoint();
                    if ($statsEndpoint) {
                        Setting::setValue('controld_stats_endpoint', $statsEndpoint);
                    }
                } catch (\Throwable $e) {
                    Log::debug('[ControlD] Could not auto-detect stats endpoint', ['error' => $e->getMessage()]);
                }

                return response()->json(['success' => true, 'message' => 'Connected to Control D!']);
            }

            return response()->json(['success' => false, 'message' => 'Control D API returned an error.']);
        } catch (\Throwable $e) {
            Log::warning('[ControlD] Test connection failed', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Could not connect to Control D. Check your API key.']);
        }
    }

    public function syncControlD()
    {
        if (! ControlDConfig::isConfigured()) {
            return back()->with('error', 'Control D is not configured.');
        }

        try {
            $client = new \App\Services\ControlD\ControlDClient([
                'api_key' => ControlDConfig::get('api_key'),
            ]);
            $service = new \App\Services\ControlD\ControlDLicenseSyncService($client);
            $result = $service->syncLicenses();

            return back()->with('success', "Control D sync complete: {$result->summary()}");
        } catch (\Throwable $e) {
            return back()->with('error', "Control D sync failed: {$e->getMessage()}");
        }
    }

    public function syncControlDDevices()
    {
        if (! ControlDConfig::isConfigured()) {
            return back()->with('error', 'Control D is not configured.');
        }

        try {
            $client = new \App\Services\ControlD\ControlDClient([
                'api_key' => ControlDConfig::get('api_key'),
            ]);
            $service = new \App\Services\ControlD\ControlDDeviceSyncService($client);
            $result = $service->syncDevices();

            return back()->with('success', "Control D device sync complete: {$result->summary()}");
        } catch (\Throwable $e) {
            return back()->with('error', "Control D device sync failed: {$e->getMessage()}");
        }
    }

    // --- Zorus ---

    public function updateZorus(Request $request)
    {
        $validated = $request->validate([
            'api_key' => 'nullable|string|min:1|max:500',
        ]);

        if (! empty($validated['api_key'])) {
            Setting::setEncrypted('zorus_api_key', $validated['api_key']);
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'Zorus credentials saved.');
    }

    public function testZorus()
    {
        if (! ZorusConfig::isConfigured()) {
            return response()->json(['success' => false, 'message' => 'API credentials not configured.']);
        }

        try {
            $client = new \App\Services\Zorus\ZorusClient([
                'api_key' => ZorusConfig::get('api_key'),
            ]);

            if ($client->isHealthy()) {
                Setting::setValue('zorus_connected_at', now()->toDateTimeString());

                return response()->json(['success' => true, 'message' => 'Connected to Zorus!']);
            }

            return response()->json(['success' => false, 'message' => 'Zorus API returned an error.']);
        } catch (\Throwable $e) {
            Log::warning('[Zorus] Test connection failed', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Could not connect to Zorus. Check your API key.']);
        }
    }

    public function syncZorus()
    {
        if (! ZorusConfig::isConfigured()) {
            return back()->with('error', 'Zorus is not configured.');
        }

        try {
            $client = new \App\Services\Zorus\ZorusClient([
                'api_key' => ZorusConfig::get('api_key'),
            ]);
            $service = new \App\Services\Zorus\ZorusLicenseSyncService($client);
            $result = $service->syncLicenses();

            return back()->with('success', "Zorus sync complete: {$result->summary()}");
        } catch (\Throwable $e) {
            return back()->with('error', "Zorus sync failed: {$e->getMessage()}");
        }
    }

    public function syncZorusDevices()
    {
        if (! ZorusConfig::isConfigured()) {
            return back()->with('error', 'Zorus is not configured.');
        }

        try {
            $client = new \App\Services\Zorus\ZorusClient([
                'api_key' => ZorusConfig::get('api_key'),
            ]);
            $service = new \App\Services\Zorus\ZorusDeviceSyncService($client);
            $result = $service->syncDevices();

            return back()->with('success', "Zorus device sync complete: {$result->summary()}");
        } catch (\Throwable $e) {
            return back()->with('error', "Zorus device sync failed: {$e->getMessage()}");
        }
    }

    // --- AppRiver ---

    public function updateAppRiver(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'nullable|string|min:1|max:500',
            'client_secret' => 'nullable|string|min:1|max:500',
        ]);

        if (! empty($validated['client_id'])) {
            Setting::setEncrypted('appriver_client_id', $validated['client_id']);
        }
        if (! empty($validated['client_secret'])) {
            Setting::setEncrypted('appriver_client_secret', $validated['client_secret']);
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'AppRiver credentials saved.');
    }

    public function testAppRiver()
    {
        if (! \App\Services\AppRiver\AppRiverClient::isConnected()) {
            return response()->json(['success' => false, 'message' => 'Not connected. Click "Connect to AppRiver" first.']);
        }

        try {
            $client = new \App\Services\AppRiver\AppRiverClient;

            // Hit the API to verify the token works
            $customers = $client->get('customers', ['limit' => 1]);
            $count = $customers['TotalCount'] ?? count($customers['Customers'] ?? []);

            return response()->json(['success' => true, 'message' => "Connected to AppRiver! Found {$count} customer(s)."]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function syncAppRiver()
    {
        if (! \App\Services\AppRiver\AppRiverClient::isConnected()) {
            return back()->with('error', 'AppRiver is not connected. Click "Connect to AppRiver" first.');
        }

        \Illuminate\Support\Facades\Artisan::queue('appriver:sync-licenses');

        return back()->with('success', 'AppRiver license sync started in the background. Check the Licenses page shortly for results.');
    }

    // --- Plivo ---

    public function updatePlivo(Request $request)
    {
        $validated = $request->validate([
            'auth_id' => 'required|string|max:255',
            'auth_token' => 'nullable|string|min:1|max:500',
            'webhook_secret' => 'nullable|string|min:1|max:500',
            'did_number' => 'required|string|max:20',
            'app_id' => 'required|string|max:50',
            'hold_music_url' => 'nullable|url|max:2048',
        ]);

        Setting::setValue('plivo_auth_id', $validated['auth_id']);
        Setting::setValue('plivo_did_number', $validated['did_number']);
        Setting::setValue('plivo_app_id', $validated['app_id']);
        Setting::setValue('plivo_hold_music_url', $validated['hold_music_url'] ?? '');

        if (! empty($validated['auth_token'])) {
            Setting::setEncrypted('plivo_auth_token', $validated['auth_token']);
        }
        if (! empty($validated['webhook_secret'])) {
            Setting::setEncrypted('plivo_webhook_secret', $validated['webhook_secret']);
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'Plivo credentials saved.');
    }

    public function testPlivo()
    {
        $authId = PlivoConfig::get('auth_id');
        $authToken = PlivoConfig::get('auth_token');

        if (! $authId || ! $authToken) {
            return response()->json(['success' => false, 'message' => 'Credentials not configured.']);
        }

        try {
            $client = new GuzzleClient(['timeout' => 10]);
            $response = $client->get("https://api.plivo.com/v1/Account/{$authId}/", [
                'auth' => [$authId, $authToken],
            ]);

            if ($response->getStatusCode() === 200) {
                Setting::setValue('plivo_connected_at', now()->toDateTimeString());

                return response()->json(['success' => true, 'message' => 'Connected to Plivo successfully!']);
            }
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not connect to Plivo: '.$e->getMessage()]);
        }

        return response()->json(['success' => false, 'message' => 'Could not connect to Plivo. Check credentials.']);
    }

    // --- T2T / HelpDesk Buttons ---

    public function updateT2t(Request $request)
    {
        $validated = $request->validate([
            'api_key' => 'nullable|string|min:1|max:500',
            'company_id' => 'nullable|string|max:100',
            'callback_url' => 'nullable|url|max:500',
            'system_user_id' => 'nullable|integer|exists:users,id',
        ]);

        if (! empty($validated['api_key'])) {
            Setting::setEncrypted('t2t_api_key', $validated['api_key']);
        }

        if (! empty($validated['company_id'])) {
            Setting::setValue('t2t_company_id', $validated['company_id']);
        }

        Setting::setValue('t2t_callback_url', $validated['callback_url'] ?? '');

        if (array_key_exists('system_user_id', $validated)) {
            Setting::setValue('t2t_system_user_id', $validated['system_user_id'] ?? '');
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'Tier2Tickets settings saved.');
    }

    public function generateT2tKey()
    {
        $key = T2TConfig::generateApiKey();
        Setting::setEncrypted('t2t_api_key', $key['private']);

        return redirect()->route('settings.integrations')
            ->with('success', 'New API key generated. Copy the full key into T2T — it will not be shown again.')
            ->with('t2t_generated_key', $key['full']);
    }

    // --- Avatars ---

    public function updateAvatars(Request $request)
    {
        $validated = $request->validate([
            'gravatar_default' => ['required', 'string', 'in:'.implode(',', array_keys(\App\Support\AvatarHelper::GRAVATAR_DEFAULTS))],
        ]);

        Setting::setValue('gravatar_default', $validated['gravatar_default']);

        return redirect()->route('settings.integrations')
            ->with('success', 'Avatar settings saved.');
    }

    // --- Ticket Automation ---

    public function updateTicketSettings(Request $request)
    {
        $validated = $request->validate([
            'auto_close_resolved_days' => 'required|integer|min:0|max:365',
        ]);

        Setting::setValue('auto_close_resolved_days', (string) $validated['auto_close_resolved_days']);

        return redirect()->route('settings.integrations')
            ->with('success', 'Ticket settings saved.');
    }

    // --- Triage ---

    public function updateTriage(Request $request)
    {
        $validated = $request->validate([
            'triage_enabled' => 'nullable',
            'triage_auto_new_tickets' => 'nullable',
            'triage_auto_review' => 'nullable',
            'triage_review_frequency_minutes' => 'nullable|integer|min:5|max:1440',
            'triage_review_auto_close' => 'nullable',
            'triage_review_auto_close_threshold' => 'nullable|integer|min:50|max:100',
            'triage_default_assignee_id' => 'nullable|integer|exists:users,id',
            'triage_system_user_id' => 'nullable|integer|exists:users,id',
            'triage_model' => 'nullable|string|max:100',
            'triage_max_tokens_per_run' => 'nullable|integer|min:10000|max:1000000',
            'triage_daily_token_limit' => 'nullable|integer|min:100000|max:50000000',
            'triage_review_batch_size' => 'nullable|integer|min:1|max:100',
        ]);

        Setting::setValue('triage_enabled', $request->has('triage_enabled') ? '1' : '');
        Setting::setValue('triage_auto_new_tickets', $request->has('triage_auto_new_tickets') ? '1' : '');
        Setting::setValue('triage_auto_review', $request->has('triage_auto_review') ? '1' : '');
        Setting::setValue('triage_review_frequency_minutes', $validated['triage_review_frequency_minutes'] ?? '60');
        Setting::setValue('triage_review_auto_close', $request->has('triage_review_auto_close') ? '1' : '');
        Setting::setValue('triage_review_auto_close_threshold', $validated['triage_review_auto_close_threshold'] ?? '80');
        Setting::setValue('triage_default_assignee_id', $validated['triage_default_assignee_id'] ?? '');
        Setting::setValue('triage_system_user_id', $validated['triage_system_user_id'] ?? '');
        Setting::setValue('triage_model', $validated['triage_model'] ?? '');
        Setting::setValue('triage_max_tokens_per_run', $validated['triage_max_tokens_per_run'] ?? '200000');
        Setting::setValue('triage_daily_token_limit', $validated['triage_daily_token_limit'] ?? '2000000');
        Setting::setValue('triage_review_batch_size', $validated['triage_review_batch_size'] ?? '20');

        foreach (['contact_resolution', 'junk_filter', 'classification', 'asset_assignment', 'technical_triage', 'conversation_review'] as $stage) {
            Setting::setValue("triage_stage_{$stage}", $request->has("triage_stage_{$stage}") ? '1' : '0');
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'AI Triage settings saved.');
    }

    // --- AI Assistant ---

    public function updateAssistant(Request $request)
    {
        $validated = $request->validate([
            'assistant_enabled' => 'nullable',
            'assistant_max_messages' => 'nullable|integer|min:10|max:500',
            'assistant_daily_token_limit' => 'nullable|integer|min:50000|max:10000000',
        ]);

        Setting::setValue('assistant_enabled', $request->has('assistant_enabled') ? '1' : '0');
        Setting::setValue('assistant_max_messages', $validated['assistant_max_messages'] ?? '50');
        Setting::setValue('assistant_daily_token_limit', $validated['assistant_daily_token_limit'] ?? '500000');

        return redirect()->route('settings.integrations')
            ->with('success', 'AI Assistant settings saved.');
    }

    // --- Teams Bot (Bot Framework) credentials (Teams bridge E1) ---

    public function updateTeamsBot(Request $request)
    {
        $request->validate([
            'teams_bot_app_id' => ['nullable', 'string', 'max:255'],
            'teams_bot_tenant_id' => ['nullable', 'string', 'max:255'],
            'teams_bot_client_secret' => ['nullable', 'string', 'max:1024'],
        ]);

        // App ID + tenant ID are non-secret identifiers — stored as plain settings.
        Setting::setValue('teams_bot_app_id', trim((string) $request->input('teams_bot_app_id', '')));
        Setting::setValue('teams_bot_tenant_id', trim((string) $request->input('teams_bot_tenant_id', '')));
        Setting::setValue('teams_bot_enabled', $request->has('teams_bot_enabled') ? '1' : '0');

        // The Entra client secret is a MASKED, encrypted, write-only field: a blank
        // submit or the echoed mask placeholder means "keep the existing stored secret"
        // — only a freshly typed value is (re)saved. Parity with the encrypted Teams
        // webhook / stripe_secret_key, so an unrelated save never wipes the secret.
        $secret = trim((string) $request->input('teams_bot_client_secret', ''));
        if ($secret !== '' && $secret !== self::SECRET_MASK) {
            \App\Support\TeamsBotConfig::setClientSecret($secret);
        }

        // Ambient "culture" dials (psa-i4cf): operator-tunable so each MSP sets its own
        // chat culture. Toggles via has(); eagerness normalised to the known set (junk →
        // normal); cooldown clamped to a sane range (the reader applies its own floor).
        Setting::setValue('teams_ambient_enabled', $request->has('teams_ambient_enabled') ? '1' : '0');
        Setting::setValue('teams_ambient_banter', $request->has('teams_ambient_banter') ? '1' : '0');

        $eagerness = $request->input('teams_ambient_eagerness');
        Setting::setValue('teams_ambient_eagerness', in_array($eagerness, ['low', 'normal', 'high'], true) ? $eagerness : 'normal');

        $cooldown = max(0, min(3600, (int) $request->input('teams_ambient_cooldown_seconds', 60)));
        Setting::setValue('teams_ambient_cooldown_seconds', (string) $cooldown);

        return redirect()->route('settings.integrations')->with('success', 'Teams Bot settings saved.');
    }

    /**
     * Reset a persona's operator-conversation binding (bd psa-3vr5).
     *
     * Clears the write-once `conversation_refs` so the next allowlist-gated
     * inbound turn re-captures it — the ONLY sanctioned rebind path (there is
     * deliberately no manual-ref-entry path; that would be a second write to a
     * write-once column). Mutates via the query builder, the symmetric-inverse
     * of the capture path's `whereNull` bind: race-safe, returns the affected
     * count for a free idempotency guard, and it never re-runs the model
     * `saving` validations on a change that only touches conversation_refs.
     * Query-builder updates fire no model events, so the per-request
     * TeamsPersonaConfig memo is flushed explicitly on a successful clear.
     */
    public function unbindPersonaConversation(Request $request, \App\Models\TeamsPersona $persona)
    {
        $old = $persona->conversation_refs;

        $cleared = \App\Models\TeamsPersona::whereKey($persona->id)
            ->whereNotNull('conversation_refs')
            ->update(['conversation_refs' => null]);

        if ($cleared === 1) {
            \App\Support\TeamsPersonaConfig::flush();

            // Audit who/when/old->new on the settings-surface audit sink (parity
            // with McpTokensController::audit()). Fail-soft — an audit hiccup must
            // never sink the operator's reset.
            try {
                \App\Models\McpAuditLog::create([
                    'server_name' => 'staff',
                    'method' => 'persona/unbind_conversation',
                    'tool_name' => mb_substr($persona->persona_key, 0, 100),
                    'arguments' => [
                        'old_conversation_id' => $old['conversation_id'] ?? null,
                        'old_service_url' => $old['service_url'] ?? null,
                    ],
                    'status' => 'success',
                    'error_message' => null,
                    'duration_ms' => 0,
                    'actor_label' => mb_substr('web:'.((string) ($request->user()?->email ?? $request->user()?->id ?? 'unknown')), 0, 100),
                    'source_ip' => $request->ip(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[Settings/Integrations] Persona unbind audit write failed: '.$e->getMessage());
            }

            return redirect()->route('settings.integrations')
                ->with('success', "Reset {$persona->display_name}'s operator conversation binding — the next allowlisted contact will re-establish it.");
        }

        // Nothing to clear (already unbound, or a stale/duplicate submit). Safe
        // no-op — no flush, no audit; tell the operator the truth.
        return redirect()->route('settings.integrations')
            ->with('success', "{$persona->display_name} has no operator conversation binding to reset.");
    }

    // --- AI Technician ---

    public function updateTechnician(Request $request)
    {
        // psa-uvuy: the Teams webhook URL is an operator SECRET (the URL itself
        // authorises posting into the operator chat). It is a MASKED, encrypted
        // secret field like the other operator secrets in this controller: a
        // blank submit, or the masked placeholder echoed back, means "keep the
        // existing stored value" — only a freshly typed value is (re)validated
        // and saved. This avoids wiping the stored secret on an unrelated save.
        $webhookSubmitted = trim((string) $request->input('technician_teams_webhook_url', ''));
        $webhookIsNew = $webhookSubmitted !== '' && $webhookSubmitted !== self::SECRET_MASK;

        // psa-ncl1 / CO-7: SSRF guard on the operator-set Teams webhook URL —
        // https-only, no private/reserved/link-local/metadata targets (literal or
        // DNS-resolved). Only a NEW value is validated; blank/mask = keep, so the
        // mask placeholder is never run through SafeWebhookUrl. The request-time
        // peer-IP pin in TeamsNotifier closes the DNS-rebind TOCTOU this save-time
        // check cannot.
        if ($webhookIsNew) {
            $request->validate([
                'technician_teams_webhook_url' => ['required', 'string', new \App\Rules\SafeWebhookUrl],
            ]);
        }

        // psa-wmqp / psa-hb0l: anchor age-detection to the coverage window. Stamp
        // coverage_start on the OFF→ON transition for either the full Technician or the
        // deterministic emergency backstop, and clear it only when both are disabled.
        // An in-place save while either plane is already enabled must NOT move the anchor.
        $wasCoverageEnabled = TechnicianConfig::emergencyBackstopEnabled();
        $nowEnabled = $request->has('technician_enabled');
        $nowEmergencyEnabled = $request->has('technician_emergency_enabled');
        Setting::setValue('technician_enabled', $nowEnabled ? '1' : '0');
        Setting::setValue('technician_emergency_enabled', $nowEmergencyEnabled ? '1' : '0');

        $nowCoverageEnabled = $nowEnabled || $nowEmergencyEnabled;
        if ($nowCoverageEnabled && ! $wasCoverageEnabled) {
            TechnicianConfig::recordCoverageStart();
        } elseif (! $nowCoverageEnabled) {
            TechnicianConfig::clearCoverageStart();
        }

        // CO-4: rebuild tier map from checkboxes — both send_ack and send_max_hold are
        // operator-toggleable auto actions. Reconstruct from scratch so we never orphan
        // stale keys, and so unchecking either removes only that key.
        $tiers = [];
        if ($request->has('technician_auto_ack')) {
            $tiers['send_ack'] = 'auto';
        }
        if ($request->has('technician_max_hold_auto')) {
            $tiers['send_max_hold'] = 'auto';
        }
        Setting::setValue('technician_action_tiers', json_encode($tiers));

        // psa-uvuy: store the webhook ENCRYPTED at rest, and ONLY when a real new
        // value was typed — a blank/mask submit keeps the existing stored secret
        // (parity with stripe_secret_key / level_api_key et al. in this controller).
        if ($webhookIsNew) {
            Setting::setEncrypted('technician_teams_webhook_url', $webhookSubmitted);
        }
        Setting::setValue('technician_notify_email', trim((string) $request->input('technician_notify_email', '')));
        Setting::setValue('technician_digest_enabled', $request->has('technician_digest_enabled') ? '1' : '0');
        // psa-kt82: email recipient policy knobs (default off).
        Setting::setValue('allow_arbitrary_email_recipients', $request->has('allow_arbitrary_email_recipients') ? '1' : '0');
        Setting::setValue('direct_email_new_recipients', $request->has('direct_email_new_recipients') ? '1' : '0');
        $time = (string) $request->input('technician_digest_time', '08:00');
        Setting::setValue('technician_digest_time', preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '08:00');
        Setting::setValue('technician_heartbeat_interval', (string) max(10, (int) $request->input('technician_heartbeat_interval', 15)));

        // Phase 2: escalation chain (ordered user ID array)
        // If the operator submitted priority-order numbers via technician_escalation_order[userId],
        // sort the checked users by their order number (ascending); ties break by submission order.
        // When no order inputs are present the chain is stored in submission order (legacy behaviour).
        $chain = $request->input('technician_escalation_chain', []);
        $chain = is_array($chain) ? array_values(array_filter(array_map('intval', $chain))) : [];
        $orderMap = $request->input('technician_escalation_order', []);
        if (is_array($orderMap) && $orderMap !== []) {
            // Build a lookup of userId → numeric priority (default to PHP_INT_MAX so unset = last)
            $orderLookup = [];
            foreach ($orderMap as $uid => $ord) {
                $orderLookup[(int) $uid] = is_numeric($ord) ? (int) $ord : PHP_INT_MAX;
            }
            $sortIndex = 0;
            $tagged = array_map(static function (int $uid) use ($orderLookup, &$sortIndex): array {
                return ['uid' => $uid, 'ord' => $orderLookup[$uid] ?? PHP_INT_MAX, 'seq' => $sortIndex++];
            }, $chain);
            usort($tagged, static fn (array $a, array $b): int => $a['ord'] <=> $b['ord'] ?: $a['seq'] <=> $b['seq']);
            $chain = array_column($tagged, 'uid');
        }
        Setting::setValue('technician_escalation_chain', json_encode($chain));

        // Phase 2: per-operator availability map (user id → "1"/"0")
        $availability = $request->input('technician_operator_availability', []);
        if (is_array($availability)) {
            Setting::setValue('technician_operator_availability', json_encode(array_map('strval', $availability)));
        }

        // CO-3: per-operator phone map — blank entries are removed
        $phones = $request->input('technician_operator_phones', []);
        if (is_array($phones)) {
            $filtered = [];
            foreach ($phones as $uid => $phone) {
                $trimmed = trim((string) $phone);
                if ($trimmed !== '') {
                    $filtered[(string) $uid] = $trimmed;
                }
            }
            Setting::setValue('technician_operator_phones', json_encode($filtered));
        }

        // Phase 2: emergency age minutes per priority (p1–p4 JSON map)
        $ageInput = $request->input('technician_emergency_age_minutes', []);
        if (is_array($ageInput)) {
            $ageMap = [];
            foreach (['p1', 'p2', 'p3', 'p4'] as $p) {
                if (isset($ageInput[$p]) && is_numeric($ageInput[$p])) {
                    $ageMap[$p] = (string) max(1, (int) $ageInput[$p]);
                }
            }
            if ($ageMap !== []) {
                Setting::setValue('technician_emergency_age_minutes', json_encode($ageMap));
            }
        }

        // Phase 2: emergency keywords (textarea → trimmed JSON string array)
        if ($request->has('technician_emergency_keywords')) {
            $raw = (string) $request->input('technician_emergency_keywords', '');
            $keywords = array_values(array_filter(array_map('trim', explode("\n", $raw))));
            Setting::setValue('technician_emergency_keywords', json_encode($keywords));
        }

        // Phase 2: numeric timeout / reping / storm-window
        if ($request->has('technician_escalation_timeout')) {
            Setting::setValue('technician_escalation_timeout', (string) max(1, (int) $request->input('technician_escalation_timeout', 15)));
        }
        if ($request->has('technician_emergency_reping')) {
            Setting::setValue('technician_emergency_reping', (string) max(1, (int) $request->input('technician_emergency_reping', 30)));
        }
        if ($request->has('technician_storm_window')) {
            Setting::setValue('technician_storm_window', (string) max(1, (int) $request->input('technician_storm_window', 15)));
        }

        // Phase 2: max-hold message
        if ($request->has('technician_max_hold_message')) {
            Setting::setValue('technician_max_hold_message', (string) $request->input('technician_max_hold_message', ''));
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'AI Technician settings saved.');
    }

    // --- Tactical RMM ---

    public function updateTactical(Request $request)
    {
        $validated = $request->validate([
            // B2: SSRF guard — https-only, no private/reserved/link-local/metadata
            // targets (literal or DNS-resolved); the API key bypasses 2FA.
            'api_url' => ['required', 'string', 'max:255', new \App\Rules\SafeTacticalUrl],
            'api_key' => 'nullable|string|min:1|max:500',
            'alert_min_severity' => 'nullable|in:error,warning,info',
            // psa-6h5r / amendment L: the web dashboard URL — a SEPARATE, plain
            // (non-secret) setting, https://+host validated, NEVER derived from
            // api_url (spec §11). Optional; blank leaves the link hidden.
            'web_url' => ['nullable', 'string', 'max:255', new \App\Rules\SafeTacticalWebUrl],
        ]);

        Setting::setValue('tactical_api_url', $validated['api_url']);

        if ($request->has('web_url')) {
            Setting::setValue('tactical_web_url', $validated['web_url'] ?? '');
        }

        if (! empty($validated['alert_min_severity'])) {
            Setting::setValue('tactical_alert_min_severity', $validated['alert_min_severity']);
        }

        if (! empty($validated['api_key'])) {
            Setting::setEncrypted('tactical_api_key', $validated['api_key']);
        }

        if ($request->boolean('generate_webhook_key')) {
            $key = TacticalConfig::generateWebhookKey();
            Setting::setEncrypted('tactical_webhook_key', $key);

            return redirect()->route('settings.integrations')
                ->with('success', 'Tactical RMM credentials saved. Webhook key generated — copy it into Tactical\'s webhook configuration.')
                ->with('tactical_webhook_key', $key);
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'Tactical RMM credentials saved.');
    }

    public function testTactical()
    {
        if (! TacticalConfig::isConfigured()) {
            return response()->json(['success' => false, 'message' => 'API credentials not configured.']);
        }

        try {
            $client = app(\App\Services\Tactical\TacticalClient::class);

            if ($client->isHealthy()) {
                Setting::setValue('tactical_connected_at', now()->toDateTimeString());

                return response()->json(['success' => true, 'message' => 'Connected to Tactical RMM!']);
            }

            return response()->json(['success' => false, 'message' => 'Tactical RMM API returned an error.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function syncTacticalDevices()
    {
        if (! TacticalConfig::isConfigured()) {
            return back()->with('error', 'Tactical RMM is not configured.');
        }

        try {
            $client = app(\App\Services\Tactical\TacticalClient::class);
            $service = new \App\Services\Tactical\TacticalDeviceSyncService($client);
            $result = $service->syncDevices();

            $linked = $result->details['linked'] ?? 0;

            return back()->with('success', "Tactical device sync complete: {$result->created} created, {$result->updated} updated, {$linked} linked.");
        } catch (\Throwable $e) {
            return back()->with('error', "Tactical device sync failed: {$e->getMessage()}");
        }
    }

    public function syncTacticalScripts()
    {
        if (! TacticalConfig::isConfigured()) {
            return back()->with('error', 'Tactical RMM is not configured.');
        }

        try {
            $client = app(\App\Services\Tactical\TacticalClient::class);
            $service = new \App\Services\Tactical\TacticalScriptSyncService($client);
            $stats = $service->syncScripts();

            return back()->with('success', "Script sync complete: {$stats['synced']} synced, {$stats['created']} new, {$stats['removed']} removed.");
        } catch (\Throwable $e) {
            return back()->with('error', "Script sync failed: {$e->getMessage()}");
        }
    }

    /**
     * Auto-provision Tactical's URLAction + AlertTemplate for the alert→ticket
     * pipeline (P7 / G2–G5). Mirrors autoConfigureComet — returns JSON
     * {success, message, warning?}.
     *
     * The actor id is captured here (auth()->id()) so the service can audit it
     * without depending on the request context itself.
     */
    public function provisionTacticalAlerts(Request $request)
    {
        if (! TacticalConfig::isConfigured()) {
            return response()->json(['success' => false, 'message' => 'Tactical RMM API credentials are not configured.']);
        }

        try {
            $service = app(\App\Services\Tactical\TacticalProvisioningService::class);
            $result = $service->provision(auth()->id());

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('[TacticalProvisioning] Unexpected error', ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'An unexpected error occurred: '.$e->getMessage()]);
        }
    }

    public function syncNinjaBackup(NinjaBackupSyncService $service)
    {
        if (! \App\Support\NinjaConfig::isEnabled()) {
            return back()->with('error', 'NinjaRMM integration is disabled.');
        }

        try {
            $result = $service->syncBackupUsage();

            return back()->with('success', "Ninja backup sync complete: {$result->updated} devices updated, {$result->deactivated} stale cleared.");
        } catch (\Throwable $e) {
            return back()->with('error', "Ninja backup sync failed: {$e->getMessage()}");
        }
    }

    // --- Comet Backup ---

    public function updateComet(Request $request)
    {
        $validated = $request->validate([
            'comet_server_url' => 'nullable|url',
            'comet_admin_user' => 'nullable|string',
            'comet_admin_password' => 'nullable|string',
            'comet_alert_enabled' => 'nullable|boolean',
            'generate_webhook_key' => 'nullable|boolean',
        ]);

        if (isset($validated['comet_server_url'])) {
            Setting::setValue('comet_server_url', $validated['comet_server_url']);
        }
        if (isset($validated['comet_admin_user'])) {
            Setting::setEncrypted('comet_admin_user', $validated['comet_admin_user']);
        }
        if (isset($validated['comet_admin_password']) && $validated['comet_admin_password'] !== '••••••••' && $validated['comet_admin_password'] !== '') {
            Setting::setEncrypted('comet_admin_password', $validated['comet_admin_password']);
        }
        if ($request->has('comet_alert_enabled')) {
            Setting::setValue('comet_alert_enabled', $validated['comet_alert_enabled'] ? '1' : '0');
        }

        if ($request->input('generate_webhook_key')) {
            \App\Support\CometConfig::generateWebhookKey();
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'Comet Backup settings updated.');
    }

    public function autoConfigureComet(Request $request)
    {
        $token = $request->input('comet_account_token');
        if (! $token) {
            return response()->json(['success' => false, 'message' => 'Account API token is required']);
        }

        try {
            $config = \App\Support\CometConfig::autoConfigureFromPortal($token);

            // Store everything
            Setting::setEncrypted('comet_account_token', $token);
            Setting::setValue('comet_server_url', $config['server_url']);
            Setting::setEncrypted('comet_admin_user', $config['admin_user']);
            Setting::setEncrypted('comet_admin_password', $config['admin_password']);

            // Verify the discovered credentials actually work
            $serverUrl = rtrim($config['server_url'], '/').'/';
            $server = new \Comet\Server($serverUrl, $config['admin_user'], $config['admin_password']);
            $version = $server->AdminMetaVersion();
            Setting::setValue('comet_connected_at', now()->toDateTimeString());

            // Auto-configure webhook on the Comet server
            $webhookKey = \App\Support\CometConfig::get('comet_webhook_key');
            if (! $webhookKey) {
                $webhookKey = \App\Support\CometConfig::generateWebhookKey();
            }

            $webhookMessage = '';
            try {
                // Get existing webhooks so we don't overwrite them
                $getResponse = (new \GuzzleHttp\Client(['timeout' => 15]))->post($serverUrl.'api/v1/admin/meta/webhook-options/get', [
                    'form_params' => [
                        'Username' => $config['admin_user'],
                        'AuthType' => 'Password',
                        'Password' => $config['admin_password'],
                    ],
                ]);
                $existingWebhooks = json_decode((string) $getResponse->getBody(), true);

                // Remove any status/message wrapper
                unset($existingWebhooks['Status'], $existingWebhooks['Message']);

                // Add/update the PSA webhook
                $existingWebhooks['psa-webhook'] = [
                    'URL' => url('/api/webhooks/comet'),
                    'CustomHeaders' => [
                        'Authorization' => 'Bearer '.$webhookKey,
                    ],
                    'Level' => 'full',
                    'WhiteListedEventTypes' => [],
                ];

                // Save back to Comet server
                (new \GuzzleHttp\Client(['timeout' => 15]))->post($serverUrl.'api/v1/admin/meta/webhook-options/set', [
                    'form_params' => [
                        'Username' => $config['admin_user'],
                        'AuthType' => 'Password',
                        'Password' => $config['admin_password'],
                        'WebhookOptions' => json_encode($existingWebhooks),
                    ],
                ]);
                $webhookMessage = ' Webhook configured automatically.';
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('[Comet] Failed to auto-configure webhook: '.$e->getMessage());
                $webhookMessage = ' Webhook setup failed — configure manually.';
            }

            return response()->json([
                'success' => true,
                'message' => "Connected to {$config['server_name']} (v".($version->Version ?? 'unknown').").{$webhookMessage}",
                'server_url' => $config['server_url'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function testComet()
    {
        if (! \App\Support\CometConfig::isConfigured()) {
            return response()->json(['success' => false, 'message' => 'Comet is not configured']);
        }

        try {
            $server = new \Comet\Server(
                rtrim(\App\Support\CometConfig::serverUrl(), '/').'/',
                \App\Support\CometConfig::get('comet_admin_user'),
                \App\Support\CometConfig::get('comet_admin_password')
            );
            $version = $server->AdminMetaVersion();

            Setting::setValue('comet_connected_at', now()->toDateTimeString());

            return response()->json([
                'success' => true,
                'message' => 'Connected to Comet server (v'.($version->Version ?? 'unknown').')',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Connection failed: '.$e->getMessage()]);
        }
    }

    public function syncCometBackup()
    {
        if (! \App\Support\CometConfig::isConfigured()) {
            return back()->with('error', 'Comet is not configured.');
        }

        $client = new \App\Services\Comet\CometClient;
        $service = new \App\Services\Comet\CometBackupSyncService($client);
        $result = $service->syncBackupUsage();

        return back()->with('success', "Comet sync complete: {$result->summary()}");
    }

    // --- Client Portal ---

    public function updatePortal(Request $request)
    {
        $validated = $request->validate([
            'portal_enabled' => 'nullable|in:0,1',
            'portal_company_name' => 'nullable|string|max:100',
            'portal_logo_url' => 'nullable|url|max:500',
            'portal_billing_url' => 'nullable|url|max:500',
            'portal_billing_label' => 'nullable|string|max:100',
            'portal_order_url' => 'nullable|string|max:500',
        ]);

        Setting::setValue('portal_enabled', $validated['portal_enabled'] ?? '0');
        Setting::setValue('portal_company_name', $validated['portal_company_name'] ?? '');
        Setting::setValue('portal_logo_url', $validated['portal_logo_url'] ?? '');
        Setting::setValue('portal_billing_url', $validated['portal_billing_url'] ?? '');
        Setting::setValue('portal_billing_label', $validated['portal_billing_label'] ?? '');
        Setting::setValue('portal_order_url', $validated['portal_order_url'] ?? '');

        return redirect()->route('settings.integrations')
            ->with('success', 'Client portal settings saved.');
    }
}
