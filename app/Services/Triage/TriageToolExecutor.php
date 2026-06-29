<?php

namespace App\Services\Triage;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Asset;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Cipp\CippClient;
use App\Services\Comet\CometClient;
use App\Services\Comet\CometJobService;
use App\Services\ControlD\ControlDAnalyticsClient;
use App\Services\ControlD\ControlDClient;
use App\Services\Level\LevelClient;
use App\Services\Mesh\MeshClient;
use App\Services\Ninja\NinjaClient;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalFieldMap;
use App\Services\Wiki\HandlesWikiTools;
use App\Support\ControlDConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Executes tool calls from the technical triage agentic loop.
 * All queries are scoped to the ticket's client_id for data isolation.
 */
class TriageToolExecutor
{
    use HandlesWikiTools;

    private Ticket $ticket;

    // Read by HandlesWikiTools; always set from the ticket (triage wiki scope is never global-only).
    protected int $clientId;

    public function __construct(Ticket $ticket)
    {
        if (! $ticket->client_id) {
            throw new \RuntimeException("Cannot execute triage tools — ticket {$ticket->id} has no client");
        }

        $this->ticket = $ticket;
        $this->clientId = $ticket->client_id;
    }

    /**
     * Execute a tool call. Returns the result as a string or array.
     */
    public function execute(string $toolName, array $input): mixed
    {
        Log::debug('[Triage] Tool call', [
            'tool' => $toolName,
            'ticket_id' => $this->ticket->id,
            'input' => $input,
        ]);

        return match ($toolName) {
            // PSA tools
            'search_tickets' => $this->searchTickets($input),
            'get_ticket_notes' => $this->getTicketNotes($input),
            'list_client_tickets' => $this->listClientTickets($input), // agent-only (readTools + READ_TOOLS)
            'list_client_calls' => $this->listClientCalls($input),     // agent-only (readTools + READ_TOOLS)
            'set_ticket_priority' => $this->setTicketPriority($input),
            'set_ticket_status' => $this->setTicketStatus($input),
            'set_ticket_category' => $this->setTicketCategory($input),
            'set_ticket_keywords' => $this->setTicketKeywords($input),

            // NinjaRMM tools
            'ninja_search_devices' => $this->ninjaSearchDevices($input),
            'ninja_get_device' => $this->ninjaGetDevice($input),
            'ninja_get_device_volumes' => $this->ninjaGetDeviceSub($input, 'volumes'),
            'ninja_get_device_alerts' => $this->ninjaGetDeviceSub($input, 'alerts'),
            'ninja_get_device_os_patches' => $this->ninjaGetDeviceSub($input, 'os-patches'),
            'ninja_get_device_software' => $this->ninjaGetDeviceSub($input, 'software'),
            'ninja_get_device_processors' => $this->ninjaGetDeviceSub($input, 'processors'),
            'ninja_get_device_disk_drives' => $this->ninjaGetDeviceSub($input, 'disks'),
            'ninja_get_device_network_interfaces' => $this->ninjaGetDeviceSub($input, 'network-interfaces'),
            'ninja_get_device_windows_services' => $this->ninjaGetDeviceSub($input, 'windows-services'),
            'ninja_get_device_last_user' => $this->ninjaGetDeviceSub($input, 'last-logged-on-user'),

            // Level RMM tools
            'level_get_device' => $this->levelGetDevice($input),

            // Mesh tools
            'mesh_search_email_logs' => $this->meshSearchLogs($input),
            'mesh_get_email_events' => $this->meshGetEvents($input),

            // CIPP tools
            'cipp_list_users' => $this->cippQuery('api/ListUsers'),
            'cipp_list_mailboxes' => $this->cippQuery('api/ListMailboxes'),
            'cipp_list_licenses' => $this->cippQuery('api/ListLicenses'),
            'cipp_list_devices' => $this->cippQuery('api/ListDevices'),
            'cipp_list_sign_ins' => $this->cippListSignIns($input),
            'cipp_list_groups' => $this->cippQuery('api/ListGroups'),
            'cipp_list_user_groups' => $this->cippQueryWithUser($input, 'api/ListUserGroups'),
            'cipp_list_mailbox_permissions' => $this->cippQueryWithUser($input, 'api/ListmailboxPermissions'),
            'cipp_list_mailbox_rules' => $this->cippQueryWithUser($input, 'api/ListMailboxRules'),
            'cipp_list_defender_state' => $this->cippQuery('api/ListDefenderState'),
            'cipp_list_conditional_access_policies' => $this->cippQuery('api/ListConditionalAccessPolicies'),
            'cipp_list_user_conditional_access' => $this->cippQueryWithUser($input, 'api/ListUserConditionalAccessPolicies'),
            'cipp_list_audit_logs' => $this->cippListAuditLogs($input),
            'cipp_list_message_trace' => $this->cippListMessageTrace($input),
            'cipp_list_mail_quarantine' => $this->cippListMailQuarantine($input),
            'cipp_list_user_mfa_methods' => $this->cippListUserMfaMethods($input),
            'cipp_list_oauth_apps' => $this->cippListOauthApps($input),

            // Control D tools
            'controld_get_devices' => $this->controldGetDevices(),
            'controld_dns_queries' => $this->controldDnsQueries($input),

            // Zorus tools
            'zorus_get_endpoints' => $this->zorusGetEndpoints(),

            // Tactical RMM tools
            'tactical_get_device' => $this->tacticalGetDevice($input),
            'tactical_get_device_checks' => $this->tacticalGetDeviceChecks($input),
            'tactical_get_device_network' => $this->tacticalGetDeviceNetwork($input),
            'tactical_get_device_software' => $this->tacticalGetDeviceSoftware($input),
            'tactical_get_device_services' => $this->tacticalGetDeviceServices($input),
            'tactical_get_device_disks' => $this->tacticalGetDeviceDisks($input),
            'tactical_run_diagnostic' => $this->tacticalRunDiagnostic($input),

            // Comet Backup tools
            'comet_get_backup_status' => $this->executeCometGetBackupStatus($input),
            'comet_get_backup_jobs' => $this->executeCometGetBackupJobs($input),

            // DNS tools (always available, no client scope required)
            'dns_lookup' => \App\Services\Dns\DnsToolkit::lookup($input['hostname'] ?? '', $input['type'] ?? ''),
            'dns_email_health' => \App\Services\Dns\DnsToolkit::emailHealth($input['domain'] ?? ''),

            // Wiki retrieval tools (client-scoped + global; spec §6)
            'wiki_list_pages' => $this->wikiListPages(),
            'wiki_search' => $this->wikiSearch($input),
            'wiki_get_page' => $this->wikiGetPage($input),

            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    // ── PSA Tools ──

    private function searchTickets(array $input): array
    {
        $query = $input['query'] ?? '';
        $limit = min($input['limit'] ?? 10, 20);

        // CLIENT-SCOPED: only search tickets for this client
        $tickets = Ticket::where('client_id', $this->clientId)
            ->search($query)
            ->where('id', '!=', $this->ticket->id) // Exclude current ticket
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'subject', 'status', 'priority', 'resolution', 'created_at', 'resolved_at']);

        return $tickets->map(fn (Ticket $t) => [
            'id' => $t->id,
            'display_id' => $t->display_id,
            'subject' => $t->subject,
            'status' => $t->status->value,
            'priority' => $t->priority->value,
            'resolution' => $t->resolution ? mb_substr($t->resolution, 0, 500) : null,
            'created_at' => $t->created_at?->toDateTimeString(),
            'resolved_at' => $t->resolved_at?->toDateTimeString(),
        ])->toArray();
    }

    /**
     * AGENT-ONLY situation drill-down: list THIS client's tickets by status with NO
     * keyword — the gap search_tickets (which requires a keyword) cannot close.
     *
     * Client scope is the ctor's $this->clientId (the ctor throws on a clientless ticket),
     * so the tool is implicitly client-bound: no client/ticket id is accepted and
     * cross-client reads are impossible. The current ticket is excluded; limit is hard-capped
     * at 20. Every free-text field passes through the shared ClientSituationContextBuilder::
     * scrub() (homoglyph-fold → strip_tags → withhold credential/injection hits → cap), the
     * same control the situation digest uses. status=closed additionally returns the
     * (scrubbed) resolution for verbatim fix-reuse. Any failure returns a generic error —
     * never $e->getMessage() — so no internal detail leaks to the model.
     */
    private function listClientTickets(array $input): array
    {
        try {
            $status = $input['status'] ?? 'open';
            $limit = max(1, min((int) ($input['limit'] ?? 20), 20));

            $query = Ticket::where('client_id', $this->clientId)
                ->where('id', '!=', $this->ticket->id);

            // Status map (there is intentionally NO scopePending): open() already includes
            // the pending statuses, so 'pending' narrows via an explicit whereIn.
            $query = match ($status) {
                'pending' => $query->whereIn('status', [TicketStatus::PendingClient, TicketStatus::PendingThirdParty]),
                'closed' => $query->closed(),
                'all' => $query,
                default => $query->open(), // 'open' and any unexpected value → the safe default
            };

            $tickets = $query->orderByDesc('opened_at')
                ->limit($limit)
                ->get(['id', 'halo_id', 'subject', 'status', 'priority', 'opened_at', 'resolution']);

            $includeResolution = $status === 'closed';

            return $tickets->map(function (Ticket $t) use ($includeResolution): array {
                $row = [
                    'id' => $t->id,
                    'display_id' => $t->display_id,
                    'subject' => ClientSituationContextBuilder::scrub($t->subject, 120),
                    'status' => $t->status->value,
                    'priority' => $t->priority->value,
                    'opened_at' => $t->opened_at?->toIso8601String(),
                ];

                if ($includeResolution) {
                    $row['resolution'] = ClientSituationContextBuilder::scrub($t->resolution, 600);
                }

                return $row;
            })->toArray();
        } catch (\Throwable) {
            return ['error' => 'lookup failed'];
        }
    }

    /**
     * AGENT-ONLY situation drill-down: list THIS client's recent phone calls with
     * summaries + sentiment — no keyword needed, scoped to $this->clientId.
     *
     * Data-minimisation boundary: the column allowlist EXCLUDES the three raw
     * transcript columns (transcription, transcription_summary, cleaned_transcript),
     * matching the recentCalls() allowlist in ClientSituationContextBuilder. Transcripts
     * stay on the existing per-ticket call tool only.
     *
     * All free-text (call_summary, next_steps) passes through ClientSituationContextBuilder::
     * scrub(). Nullable enums (charge_classification) and nullable int (sentiment_score)
     * are null-guarded. Hard-cap: ≤ 20 calls. Generic error only.
     */
    private function listClientCalls(array $input): array
    {
        try {
            $limit = min((int) ($input['limit'] ?? 10), 20);

            $calls = \App\Models\PhoneCall::forClient($this->clientId)
                ->recent($limit)
                ->get(['id', 'direction', 'started_at', 'call_summary', 'next_steps', 'charge_classification', 'sentiment_score']);

            return $calls->map(fn (\App\Models\PhoneCall $call): array => [
                'id' => $call->id,
                'direction' => $call->direction?->value,
                'date' => $call->started_at?->toDateString(),
                'summary' => ClientSituationContextBuilder::scrub($call->call_summary, 400),
                'next_steps' => ClientSituationContextBuilder::scrub($call->next_steps, 400),
                'charge' => $call->charge_classification?->value,
                'sentiment' => $call->sentiment_score,
            ])->toArray();
        } catch (\Throwable) {
            return ['error' => 'lookup failed'];
        }
    }

    private function getTicketNotes(array $input): array
    {
        $ticketId = $input['ticket_id'] ?? null;
        if (! $ticketId) {
            return ['error' => 'ticket_id is required'];
        }

        // CLIENT-SCOPED: verify the ticket belongs to this client
        $ticket = Ticket::where('id', $ticketId)
            ->where('client_id', $this->clientId)
            ->first();

        if (! $ticket) {
            return ['error' => 'Ticket not found or belongs to a different client'];
        }

        $notes = TicketNote::where('ticket_id', $ticketId)
            ->orderBy('noted_at')
            ->limit(20)
            ->get();

        return $notes->map(fn (TicketNote $n) => [
            'type' => $n->note_type?->value,
            'author' => $n->author?->name ?? $n->author_name ?? 'System',
            'body' => mb_substr(strip_tags($n->body ?? ''), 0, 1000),
            'date' => $n->noted_at?->toDateTimeString(),
            'is_private' => $n->is_private,
        ])->toArray();
    }

    private function setTicketPriority(array $input): array
    {
        $level = $input['priority'] ?? null;
        if (! $level || ! in_array($level, [1, 2, 3, 4])) {
            return ['error' => 'priority must be 1, 2, 3, or 4'];
        }

        $priorityMap = [1 => 'p1', 2 => 'p2', 3 => 'p3', 4 => 'p4'];
        $priority = TicketPriority::from($priorityMap[$level]);

        $this->ticket->update([
            'priority' => $priority,
            'priority_order' => $priority->sortOrder(),
        ]);

        Log::info('[Triage] AI set ticket priority', [
            'ticket_id' => $this->ticket->id,
            'priority' => $priority->value,
        ]);

        return ['success' => true, 'priority' => $priority->value];
    }

    private function setTicketStatus(array $input): array
    {
        $statusValue = $input['status'] ?? null;
        $status = TicketStatus::tryFrom($statusValue);

        if (! $status) {
            return ['error' => "Invalid status: {$statusValue}"];
        }

        $allowed = $this->ticket->status->allowedTransitions();
        if (! in_array($status, $allowed, true)) {
            return ['error' => "Cannot transition from {$this->ticket->status->label()} to {$status->label()}"];
        }

        $oldStatus = $this->ticket->status;
        $this->ticket->status = $status;

        if ($status === TicketStatus::Resolved) {
            $this->ticket->resolved_at = now();
        }
        if ($status === TicketStatus::Closed) {
            $this->ticket->closed_at = now();
            if (! $this->ticket->resolved_at) {
                $this->ticket->resolved_at = now();
            }
        }

        $this->ticket->save();

        Log::info('[Triage] AI set ticket status', [
            'ticket_id' => $this->ticket->id,
            'from' => $oldStatus->value,
            'to' => $status->value,
        ]);

        return ['success' => true, 'status' => $status->value];
    }

    private function setTicketCategory(array $input): array
    {
        $category = $input['category'] ?? null;
        $subcategory = $input['subcategory'] ?? null;

        if (! $category) {
            return ['error' => 'category is required'];
        }

        // Validate category exists in config
        $validCategories = config('tickets.categories', []);
        if (! array_key_exists($category, $validCategories)) {
            return ['error' => "Invalid category: {$category}. Valid: ".implode(', ', array_keys($validCategories))];
        }

        // Validate subcategory if provided
        if ($subcategory && ! in_array($subcategory, $validCategories[$category])) {
            return ['error' => "Invalid subcategory '{$subcategory}' for {$category}. Valid: ".implode(', ', $validCategories[$category])];
        }

        $updates = ['category' => $category];
        if ($subcategory) {
            $updates['subcategory'] = $subcategory;
        }

        $this->ticket->update($updates);

        Log::info('[Triage] AI set ticket category', [
            'ticket_id' => $this->ticket->id,
            'category' => $category,
            'subcategory' => $subcategory,
        ]);

        return ['success' => true, 'category' => $category, 'subcategory' => $subcategory];
    }

    private function setTicketKeywords(array $input): array
    {
        $keywords = $input['keywords'] ?? null;

        if (! is_array($keywords) || empty($keywords)) {
            return ['error' => 'keywords must be a non-empty array'];
        }

        // Normalize: lowercase, strip punctuation, drop very short tokens, dedupe.
        $normalized = [];
        foreach ($keywords as $kw) {
            if (! is_string($kw)) {
                continue;
            }
            $clean = mb_strtolower(trim($kw), 'UTF-8');
            $clean = preg_replace('/[^\p{L}\p{N}\s_-]+/u', '', $clean) ?? '';
            $clean = trim((string) $clean);
            if ($clean === '' || mb_strlen($clean) < 2) {
                continue;
            }
            $normalized[$clean] = true;
        }

        if (empty($normalized)) {
            return ['error' => 'no usable keywords after normalization'];
        }

        $stored = implode(' ', array_keys($normalized));
        $this->ticket->update(['search_keywords' => $stored]);

        Log::info('[Triage] AI set ticket keywords', [
            'ticket_id' => $this->ticket->id,
            'keyword_count' => count($normalized),
        ]);

        return ['success' => true, 'keywords' => array_keys($normalized)];
    }

    // ── NinjaRMM Tools ──

    private function ninjaSearchDevices(array $input): array
    {
        $query = $input['query'] ?? '';
        $limit = min($input['limit'] ?? 20, 50);

        if (! $query) {
            return ['error' => 'query is required'];
        }

        // CLIENT-SCOPED: check org mapping before making API call
        $ninjaOrgId = $this->ticket->client?->ninja_org_id;
        if (! $ninjaOrgId) {
            return ['error' => 'Client has no NinjaRMM organization mapping'];
        }

        try {
            $results = app(NinjaClient::class)->get('/v2/devices/search', ['q' => $query, 'limit' => $limit]);
        } catch (\Throwable $e) {
            Log::warning('[Triage] NinjaRMM search failed', ['error' => $e->getMessage()]);

            return ['error' => 'NinjaRMM search failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // Search endpoint wraps results: {"query": "...", "devices": [...]}
        $deviceList = $results['devices'] ?? $results;

        $devices = array_values(array_filter($deviceList, function ($device) use ($ninjaOrgId) {
            return ($device['organizationId'] ?? null) == $ninjaOrgId;
        }));

        return array_slice($devices, 0, $limit);
    }

    private function ninjaGetDevice(array $input): array
    {
        $deviceId = $input['device_id'] ?? null;
        if (! $deviceId) {
            return ['error' => 'device_id is required'];
        }

        // CLIENT-SCOPED: verify device belongs to this client's org
        if (! $this->verifyNinjaDeviceScope($deviceId)) {
            return ['error' => 'Device not found or belongs to a different client'];
        }

        try {
            return app(NinjaClient::class)->getDeviceDetail($deviceId);
        } catch (\Throwable $e) {
            Log::warning('[Triage] NinjaRMM device query failed', ['device_id' => $deviceId, 'error' => $e->getMessage()]);

            return ['error' => 'NinjaRMM query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function ninjaGetDeviceSub(array $input, string $subResource): array
    {
        $deviceId = $input['device_id'] ?? null;
        if (! $deviceId) {
            return ['error' => 'device_id is required'];
        }

        if (! $this->verifyNinjaDeviceScope($deviceId)) {
            return ['error' => 'Device not found or belongs to a different client'];
        }

        try {
            return app(NinjaClient::class)->get("/v2/device/{$deviceId}/{$subResource}");
        } catch (\Throwable $e) {
            Log::warning('[Triage] NinjaRMM sub-resource query failed', ['device_id' => $deviceId, 'sub' => $subResource, 'error' => $e->getMessage()]);

            return ['error' => 'NinjaRMM query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /**
     * Verify a NinjaRMM device belongs to this client by checking local assets.
     */
    private function verifyNinjaDeviceScope(int $ninjaDeviceId): bool
    {
        return \App\Models\Asset::where('client_id', $this->clientId)
            ->where('ninja_id', $ninjaDeviceId)
            ->exists();
    }

    // ── Level RMM Tools ──

    private function levelGetDevice(array $input): array
    {
        $deviceId = $input['device_id'] ?? null;
        if (! $deviceId) {
            return ['error' => 'device_id is required'];
        }

        // CLIENT-SCOPED: verify device belongs to this client
        if (! $this->verifyLevelDeviceScope($deviceId)) {
            return ['error' => 'Device not found or belongs to a different client'];
        }

        try {
            return app(LevelClient::class)->getDevice($deviceId);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Level device query failed', ['device_id' => $deviceId, 'error' => $e->getMessage()]);

            return ['error' => 'Level query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /**
     * Verify a Level RMM device belongs to this client by checking local assets.
     */
    private function verifyLevelDeviceScope(string $levelDeviceId): bool
    {
        return \App\Models\Asset::where('client_id', $this->clientId)
            ->where('level_id', $levelDeviceId)
            ->exists();
    }

    // ── Mesh Tools ──

    private function meshSearchLogs(array $input): array
    {
        $size = min($input['size'] ?? 20, 50);

        $client = $this->ticket->client;
        $meshCustomerId = $client?->mesh_customer_id;

        if (! $meshCustomerId) {
            return ['error' => 'Client has no Mesh customer mapping'];
        }

        // Date range is required — Mesh returns empty without it
        $end = gmdate('Y-m-d\TH:i:s');
        $start = gmdate('Y-m-d\TH:i:s', strtotime('-7 days'));

        $params = [
            '_from' => 0,
            '_size' => $size,
            'start' => $start,
            'end' => $end,
        ];

        // Add optional filters
        foreach (['from', 'to', 'subject', 'status'] as $field) {
            if (! empty($input[$field])) {
                $params[$field] = $input[$field];
            }
        }

        try {
            $result = app(MeshClient::class)->get('api/emaillogs/', $params);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Mesh log search failed', ['error' => $e->getMessage()]);

            return ['error' => 'Mesh query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // Filter to this client's customer ID (API returns all customers)
        $logs = $result['list'] ?? [];
        $logs = array_values(array_filter($logs, function ($entry) use ($meshCustomerId) {
            $ids = $entry['Customer-ID'] ?? [$entry['Customer Id'] ?? null];

            return in_array($meshCustomerId, (array) $ids);
        }));

        return [
            'total' => count($logs),
            'emails' => array_slice($logs, 0, $size),
        ];
    }

    private function meshGetEvents(array $input): array
    {
        $queueId = $input['queue_id'] ?? null;
        if (! $queueId) {
            return ['error' => 'queue_id is required'];
        }

        try {
            return app(MeshClient::class)->get('api/emaillogs/events', [
                'queue_id' => $queueId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Mesh events query failed', ['queue_id' => $queueId, 'error' => $e->getMessage()]);

            return ['error' => 'Mesh query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    // ── CIPP Tools ──

    private function cippQuery(string $endpoint): array
    {
        $tenantDomain = $this->ticket->client?->cipp_tenant_domain;

        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        try {
            return app(CippClient::class)->get($endpoint, ['TenantFilter' => $tenantDomain]);
        } catch (\Throwable $e) {
            Log::warning('[Triage] CIPP query failed', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function cippListSignIns(array $input): array
    {
        $tenantDomain = $this->ticket->client?->cipp_tenant_domain;

        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $userId = ! empty($input['user_id']) ? trim((string) $input['user_id']) : null;
        $days = isset($input['days']) && is_numeric($input['days'])
            ? (int) min(max(1, $input['days']), 30)
            : null;

        // CIPP has a per-user endpoint (api/ListUserSigninLogs) and a tenant-wide
        // endpoint (api/ListSignIns). Route to the per-user one when filtering by
        // user — it's authoritative and not subject to the tenant-wide window cap.
        // CIPP's userId param requires an Azure AD object ID (GUID), not a UPN —
        // translate via our synced Person record before calling.
        $endpoint = $userId ? 'api/ListUserSigninLogs' : 'api/ListSignIns';
        $params = ['TenantFilter' => $tenantDomain];
        if ($userId) {
            $params['userId'] = $this->resolveCippUserId($userId);
        }

        try {
            $events = app(CippClient::class)->get($endpoint, $params);
        } catch (\Throwable $e) {
            Log::warning('[Triage] CIPP sign-in query failed', [
                'endpoint' => $endpoint,
                'params' => $params,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($events)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($events);

        // Days filter applied client-side — CIPP doesn't document a window param.
        if ($days !== null) {
            $cutoff = now()->subDays($days);
            $events = array_values(array_filter($events, function ($e) use ($cutoff) {
                $createdAt = $e['createdDateTime'] ?? null;
                if (! $createdAt) {
                    return false;
                }
                try {
                    return \Illuminate\Support\Carbon::parse($createdAt)->gte($cutoff);
                } catch (\Throwable) {
                    return false;
                }
            }));
        }

        // Cap to keep the AI context window manageable.
        $capped = array_slice($events, 0, 50);

        return [
            'count' => count($capped),
            'endpoint' => $endpoint,
            'filtered_by_user' => $userId,
            'filtered_by_days' => $days,
            'total_returned_by_cipp' => $totalReturned,
            'events' => $capped,
        ];
    }

    private function cippListAuditLogs(array $input): array
    {
        $tenantDomain = $this->ticket->client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $userId = ! empty($input['user_id']) ? trim((string) $input['user_id']) : null;
        $days = isset($input['days']) && is_numeric($input['days'])
            ? (int) min(max(1, $input['days']), 30)
            : null;

        $params = ['TenantFilter' => $tenantDomain];
        if ($userId) {
            $params['userId'] = $this->resolveCippUserId($userId);
        }

        try {
            $events = app(CippClient::class)->get('api/ListAuditLogs', $params);
        } catch (\Throwable $e) {
            Log::warning('[Triage] CIPP ListAuditLogs failed', ['params' => $params, 'error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($events)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($events);

        if ($days !== null) {
            $cutoff = now()->subDays($days);
            $events = array_values(array_filter($events, fn ($e) => $this->eventWithinCutoff($e, $cutoff, ['createdDateTime', 'CreationTime', 'Date'])));
        }

        if ($userId) {
            $needle = mb_strtolower($userId);
            $events = array_values(array_filter($events, function ($e) use ($needle) {
                foreach (['userId', 'UserId', 'userPrincipalName', 'UserPrincipalName', 'initiatedBy'] as $key) {
                    if (isset($e[$key]) && mb_strtolower((string) $e[$key]) === $needle) {
                        return true;
                    }
                }

                return false;
            }));
        }

        return [
            'count' => count($events),
            'filtered_by_user' => $userId,
            'filtered_by_days' => $days,
            'total_returned_by_cipp' => $totalReturned,
            'events' => array_slice($events, 0, 50),
        ];
    }

    private function cippListMessageTrace(array $input): array
    {
        $tenantDomain = $this->ticket->client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $sender = ! empty($input['sender']) ? trim((string) $input['sender']) : null;
        $recipient = ! empty($input['recipient']) ? trim((string) $input['recipient']) : null;
        $days = isset($input['days']) && is_numeric($input['days'])
            ? (int) min(max(1, $input['days']), 10)
            : 2;

        $params = ['TenantFilter' => $tenantDomain, 'days' => $days];
        if ($sender) {
            $params['sender'] = $sender;
        }
        if ($recipient) {
            $params['recipient'] = $recipient;
        }

        try {
            $messages = app(CippClient::class)->get('api/ListMessageTrace', $params);
        } catch (\Throwable $e) {
            Log::warning('[Triage] CIPP ListMessageTrace failed', ['params' => $params, 'error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($messages)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($messages);

        // Client-side filter — Message Trace upstream filtering is unreliable across CIPP versions.
        if ($sender) {
            $needle = mb_strtolower($sender);
            $messages = array_values(array_filter($messages, fn ($m) => mb_strtolower((string) ($m['SenderAddress'] ?? $m['senderAddress'] ?? '')) === $needle));
        }
        if ($recipient) {
            $needle = mb_strtolower($recipient);
            $messages = array_values(array_filter($messages, fn ($m) => mb_strtolower((string) ($m['RecipientAddress'] ?? $m['recipientAddress'] ?? '')) === $needle));
        }

        return [
            'count' => count($messages),
            'filtered_by_sender' => $sender,
            'filtered_by_recipient' => $recipient,
            'window_days' => $days,
            'total_returned_by_cipp' => $totalReturned,
            'messages' => array_slice($messages, 0, 50),
        ];
    }

    private function cippListMailQuarantine(array $input): array
    {
        $tenantDomain = $this->ticket->client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $recipient = ! empty($input['recipient']) ? trim((string) $input['recipient']) : null;

        try {
            $entries = app(CippClient::class)->get('api/ListMailQuarantine', ['TenantFilter' => $tenantDomain]);
        } catch (\Throwable $e) {
            Log::warning('[Triage] CIPP ListMailQuarantine failed', ['error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($entries)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($entries);

        if ($recipient) {
            $needle = mb_strtolower($recipient);
            $entries = array_values(array_filter($entries, function ($e) use ($needle) {
                foreach (['RecipientAddress', 'recipientAddress', 'recipients'] as $key) {
                    $val = $e[$key] ?? null;
                    if (is_string($val) && mb_strtolower($val) === $needle) {
                        return true;
                    }
                    if (is_array($val)) {
                        foreach ($val as $r) {
                            if (is_string($r) && mb_strtolower($r) === $needle) {
                                return true;
                            }
                        }
                    }
                }

                return false;
            }));
        }

        return [
            'count' => count($entries),
            'filtered_by_recipient' => $recipient,
            'total_returned_by_cipp' => $totalReturned,
            'entries' => array_slice($entries, 0, 50),
        ];
    }

    private function cippListUserMfaMethods(array $input): array
    {
        $tenantDomain = $this->ticket->client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $userId = $input['user_id'] ?? null;
        if (! $userId) {
            return ['error' => 'user_id is required'];
        }

        // CIPP's ListMFAUsers returns the user-level MFA picture: registration
        // status, method types, and which enforcement mechanism (CA / Security
        // Defaults / per-user) currently covers them. ListPerUserMFA is the
        // legacy per-user-toggle list and doesn't reflect modern MFA at all.
        try {
            $rows = app(CippClient::class)->get('api/ListMFAUsers', ['TenantFilter' => $tenantDomain]);
        } catch (\Throwable $e) {
            Log::warning('[Triage] CIPP ListMFAUsers failed', ['error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($rows)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $objectId = $this->resolveCippUserId($userId);
        $upnNeedle = str_contains($userId, '@') ? mb_strtolower($userId) : null;

        foreach ($rows as $row) {
            $rowUpn = mb_strtolower((string) ($row['UPN'] ?? $row['userPrincipalName'] ?? ''));
            $rowId = (string) ($row['ID'] ?? $row['Id'] ?? $row['userId'] ?? '');
            if ($rowId === $objectId || ($upnNeedle && $rowUpn === $upnNeedle)) {
                return self::summarizeMfaRow($row);
            }
        }

        return [
            'error' => "No MFA record found for {$userId} in this tenant",
            'searched_user_id' => $userId,
            'resolved_object_id' => $objectId,
        ];
    }

    /**
     * Add a derived `enforcement` summary to a raw CIPP ListMFAUsers row so
     * downstream consumers (AI tools) don't have to interpret PerUser/SD/CA
     * fields, which are easy to misread. Returns the row unchanged when an
     * existing `enforcement` key is present.
     *
     * Enforcement values: "conditional_access", "security_defaults",
     * "per_user_legacy", "none".
     */
    public static function summarizeMfaRow(array $row): array
    {
        $caState = mb_strtolower((string) ($row['CoveredByCA'] ?? ''));
        $sd = (bool) ($row['CoveredBySD'] ?? false);
        $perUser = mb_strtolower((string) ($row['PerUser'] ?? 'disabled'));

        $sources = [];
        if ($caState !== '' && $caState !== 'not enforced') {
            $sources[] = 'conditional_access';
        }
        if ($sd) {
            $sources[] = 'security_defaults';
        }
        if (in_array($perUser, ['enabled', 'enforced'], true)) {
            $sources[] = 'per_user_legacy';
        }

        $row['enforcement'] = [
            'sources' => $sources ?: ['none'],
            'primary' => $sources[0] ?? 'none',
            'note' => match (true) {
                $sources === [] => 'No MFA enforcement detected. User can sign in without MFA.',
                $sources === ['security_defaults'] => 'Protected by Security Defaults (tenant-wide). Cannot be tuned per-user or per-app.',
                in_array('conditional_access', $sources, true) => 'Protected by Conditional Access. Check CoveredByCA / CAPolicies for which policy.',
                $sources === ['per_user_legacy'] => 'On the legacy per-user MFA toggle. Modern tenants should migrate to Conditional Access.',
                default => 'Multiple enforcement sources — see sources array.',
            },
        ];

        return $row;
    }

    private function cippListOauthApps(array $input): array
    {
        $tenantDomain = $this->ticket->client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        try {
            $apps = app(CippClient::class)->get('api/ListOAuthApps', ['TenantFilter' => $tenantDomain]);
        } catch (\Throwable $e) {
            Log::warning('[Triage] CIPP ListOAuthApps failed', ['error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($apps)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($apps);
        $userId = ! empty($input['user_id']) ? trim((string) $input['user_id']) : null;

        if ($userId) {
            $objectId = $this->resolveCippUserId($userId);
            $upnNeedle = str_contains($userId, '@') ? mb_strtolower($userId) : null;

            $apps = array_values(array_filter($apps, function ($app) use ($objectId, $upnNeedle) {
                // Match any field that may carry user identity for the consent grant.
                foreach (['principalId', 'consentedBy', 'userId', 'userPrincipalName'] as $key) {
                    $val = $app[$key] ?? null;
                    if (is_string($val) && $val !== '') {
                        if ($val === $objectId) {
                            return true;
                        }
                        if ($upnNeedle && mb_strtolower($val) === $upnNeedle) {
                            return true;
                        }
                    }
                }

                return false;
            }));
        }

        return [
            'count' => count($apps),
            'filtered_by_user' => $userId,
            'total_returned_by_cipp' => $totalReturned,
            'apps' => array_slice($apps, 0, 50),
        ];
    }

    private function eventWithinCutoff(array $e, \Illuminate\Support\Carbon $cutoff, array $dateKeys): bool
    {
        foreach ($dateKeys as $key) {
            if (! empty($e[$key])) {
                try {
                    return \Illuminate\Support\Carbon::parse($e[$key])->gte($cutoff);
                } catch (\Throwable) {
                    return false;
                }
            }
        }

        return false;
    }

    private function cippQueryWithUser(array $input, string $endpoint): array
    {
        $userId = $input['user_id'] ?? null;
        if (! $userId) {
            return ['error' => 'user_id is required'];
        }

        $tenantDomain = $this->ticket->client?->cipp_tenant_domain;

        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        try {
            return app(CippClient::class)->get($endpoint, [
                'TenantFilter' => $tenantDomain,
                'userId' => $this->resolveCippUserId($userId),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Triage] CIPP query failed', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /**
     * Translate a UPN (email) to the Azure AD object ID expected by CIPP's
     * per-user endpoints. Looks up the synced Person record for the current
     * ticket's client. Pass-through if input already looks like a GUID, or
     * if no Person match exists (caller will see CIPP's response on that).
     */
    private function resolveCippUserId(string $input): string
    {
        // Already a GUID — pass through.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $input)) {
            return $input;
        }

        // Looks like an email — try to resolve via Person.cipp_upn → cipp_user_id.
        if (str_contains($input, '@')) {
            $objectId = \App\Models\Person::where('client_id', $this->clientId)
                ->whereRaw('LOWER(cipp_upn) = ?', [mb_strtolower($input)])
                ->value('cipp_user_id');

            if ($objectId) {
                return $objectId;
            }
        }

        return $input;
    }

    // ── Control D Tools ──

    private function controldGetDevices(): array
    {
        $orgId = $this->ticket->client?->controld_org_id;

        if (! $orgId) {
            return ['error' => 'Client has no Control D organization mapping'];
        }

        try {
            $client = new ControlDClient(['api_key' => ControlDConfig::get('api_key')]);
            $devices = $client->getDevices($orgId);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Control D device query failed', ['error' => $e->getMessage()]);

            return ['error' => 'Control D query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        return array_map(fn ($d) => [
            'name' => $d['name'] ?? null,
            'status' => match ($d['status'] ?? null) {
                0 => 'pending',
                1 => 'active',
                2 => 'soft_disabled',
                3 => 'hard_disabled',
                default => 'unknown',
            },
            'profile' => $d['profile']['name'] ?? null,
            'agent_connected' => ($d['ctrld']['status'] ?? 0) === 1,
            'agent_version' => $d['ctrld']['version'] ?? null,
            'agent_last_seen' => isset($d['ctrld']['last_fetch'])
                ? Carbon::createFromTimestamp($d['ctrld']['last_fetch'])->toDateTimeString()
                : null,
        ], $devices);
    }

    private function controldDnsQueries(array $input): array
    {
        if (! ControlDConfig::isAnalyticsConfigured()) {
            return ['error' => 'Control D analytics is not configured'];
        }

        $orgId = $this->ticket->client?->controld_org_id;

        if (! $orgId) {
            return ['error' => 'Client has no Control D organization mapping'];
        }

        $deviceName = $input['device_name'] ?? null;

        if (! $deviceName) {
            return ['error' => 'device_name is required'];
        }

        // Resolve device name to endpointId via local asset record (client-scoped)
        $asset = Asset::where('client_id', $this->ticket->client_id)
            ->whereNotNull('controld_device_id')
            ->whereRaw('LOWER(hostname) = ?', [mb_strtolower($deviceName)])
            ->first();

        $endpointId = $asset?->controld_device_id;

        $hours = min(max((int) ($input['hours'] ?? 24), 1), 168);
        $endTime = now();
        $startTime = $endTime->copy()->subHours($hours);

        try {
            $analyticsClient = new ControlDAnalyticsClient(
                ControlDConfig::get('api_key'),
                ControlDConfig::get('stats_endpoint'),
            );

            $queries = $analyticsClient->getActivityLog(
                $orgId,
                $startTime->toIso8601ZuluString(),
                $endTime->toIso8601ZuluString(),
                $endpointId,
            );
        } catch (\Throwable $e) {
            Log::warning('[Triage] Control D analytics query failed', ['error' => $e->getMessage()]);

            return ['error' => 'Control D analytics query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        return array_map(fn ($q) => [
            'domain' => $q['question'] ?? null,
            'action' => match ($q['action'] ?? null) {
                1 => 'allowed',
                0 => 'blocked',
                -1 => 'nxdomain',
                default => 'unknown',
            },
            'trigger' => $q['trigger'] ?? null,
            'timestamp' => $q['timestamp'] ?? null,
            'type' => $q['rrType'] ?? null,
        ], array_slice($queries, 0, 100));
    }

    // ── Zorus Tools ──

    private function zorusGetEndpoints(): array
    {
        $customerId = $this->ticket->client?->zorus_customer_id;

        if (! $customerId) {
            return ['error' => 'Client has no Zorus customer mapping'];
        }

        // Read from local assets table (not live API call)
        $assets = Asset::where('client_id', $this->clientId)
            ->whereNotNull('zorus_endpoint_id')
            ->orderBy('hostname')
            ->limit(100)
            ->get([
                'hostname', 'name', 'zorus_group_name', 'zorus_filtering_enabled',
                'zorus_cybersight_enabled', 'zorus_agent_version', 'zorus_agent_state',
                'zorus_last_seen_at',
            ]);

        return $assets->map(fn (Asset $a) => [
            'name' => $a->hostname ?? $a->name,
            'group' => $a->zorus_group_name,
            'filtering_enabled' => $a->zorus_filtering_enabled,
            'cybersight_enabled' => $a->zorus_cybersight_enabled,
            'agent_version' => $a->zorus_agent_version,
            'agent_state' => $a->zorus_agent_state,
            'last_seen' => $a->zorus_last_seen_at?->toDateTimeString(),
        ])->toArray();
    }

    // ── Tactical RMM Tools ──

    /**
     * Resolve a hostname to a Tactical agent, enforcing client scoping.
     */
    private function resolveTacticalAgent(string $hostname): ?array
    {
        $tacticalAsset = TacticalAsset::whereRaw('LOWER(hostname) = ?', [strtolower($hostname)])
            ->first();

        if (! $tacticalAsset) {
            return null;
        }

        // Client scoping: the linked asset must belong to this client
        if ($tacticalAsset->asset && $tacticalAsset->asset->client_id !== $this->clientId) {
            return null;
        }

        return ['agent_id' => $tacticalAsset->agent_id, 'tactical_asset' => $tacticalAsset];
    }

    private function tacticalGetDevice(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $resolved = $this->resolveTacticalAgent($hostname);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Tactical device query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // Format uptime from boot_time (shared mapper — amendment E)
        $uptime = TacticalFieldMap::uptimeFromBootTime($agent['boot_time'] ?? null);

        // getAgent `checks` is a SUMMARY DICT ({total, passing, failing, …}) —
        // read failing/total off it directly (NOT the getAgentChecks list helper).
        $checksSummary = null;
        $checks = $agent['checks'] ?? null;
        if (is_array($checks) && isset($checks['total'])) {
            $failing = (int) ($checks['failing'] ?? 0);
            $total = (int) $checks['total'];
            $checksSummary = "{$failing} failing / {$total} total";
        }

        return [
            'hostname' => $agent['hostname'] ?? $hostname,
            'status' => $agent['status'] ?? null,
            'os' => $agent['operating_system'] ?? null,
            'cpu' => $agent['cpu_model'] ?? null,
            'ram_gb' => TacticalFieldMap::ramGb($agent['total_ram'] ?? null),
            'make_model' => $agent['make_model'] ?? null,
            'public_ip' => $agent['public_ip'] ?? null,
            'local_ips' => $agent['local_ips'] ?? null,
            // Tactical's "None" (and empty) means no user is logged in — surface
            // null to the AI, not the literal sentinel string.
            'logged_in_user' => in_array($agent['logged_in_username'] ?? null, [null, '', 'None'], true)
                ? null
                : $agent['logged_in_username'],
            'needs_reboot' => $agent['needs_reboot'] ?? false,
            'uptime' => $uptime,
            'checks_summary' => $checksSummary,
        ];
    }

    private function tacticalGetDeviceChecks(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $resolved = $this->resolveTacticalAgent($hostname);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        try {
            $checks = app(TacticalClient::class)->getAgentChecks($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Tactical checks query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        return array_map(fn ($c) => [
            'name' => $c['name'] ?? $c['readable_desc'] ?? 'Unknown',
            'status' => $c['check_result']['status'] ?? $c['status'] ?? 'unknown',
            'retcode' => $c['check_result']['retcode'] ?? null,
            'stdout' => mb_substr($c['check_result']['stdout'] ?? '', 0, 500),
        ], array_slice($checks, 0, 50));
    }

    private function tacticalGetDeviceNetwork(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $resolved = $this->resolveTacticalAgent($hostname);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Tactical network query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // Shared IP-enabled-adapter mapping (the storage/network asset panels reuse
        // the same TacticalFieldMap mapper — one shape, two consumers).
        return TacticalFieldMap::mapNetwork($agent);
    }

    private function tacticalGetDeviceSoftware(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $resolved = $this->resolveTacticalAgent($hostname);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        try {
            $software = app(TacticalClient::class)->getSoftware($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Tactical software query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // Sort alphabetically, limit to 50
        usort($software, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        return array_map(fn ($s) => [
            'name' => $s['name'] ?? 'Unknown',
            'version' => $s['version'] ?? null,
            'publisher' => $s['publisher'] ?? null,
        ], array_slice($software, 0, 50));
    }

    private function tacticalGetDeviceServices(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $resolved = $this->resolveTacticalAgent($hostname);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Tactical services query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $services = collect($agent['services'] ?? []);
        $filter = $input['filter'] ?? null;

        if ($filter) {
            $filterLower = strtolower($filter);
            $services = $services->filter(function ($s) use ($filterLower) {
                if ($filterLower === 'running') {
                    return strtolower($s['status'] ?? '') === 'running';
                }
                if ($filterLower === 'stopped') {
                    return strtolower($s['status'] ?? '') === 'stopped';
                }

                // Search by name
                return str_contains(strtolower($s['display_name'] ?? $s['name'] ?? ''), $filterLower)
                    || str_contains(strtolower($s['name'] ?? ''), $filterLower);
            });
        }

        return $services->take(50)->map(fn ($s) => [
            'name' => $s['name'] ?? null,
            'display_name' => $s['display_name'] ?? null,
            'status' => $s['status'] ?? null,
            'start_type' => $s['start_type'] ?? null,
        ])->values()->toArray();
    }

    private function tacticalGetDeviceDisks(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $resolved = $this->resolveTacticalAgent($hostname);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        try {
            $agent = app(TacticalClient::class)->getAgent($resolved['agent_id']);
        } catch (\Throwable $e) {
            Log::warning('[Triage] Tactical disks query failed', ['hostname' => $hostname, 'error' => $e->getMessage()]);

            return ['error' => 'Tactical query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // Logical volumes — getAgent `disks` total/used/free are FORMATTED STRINGS
        // ("X.Y GB"/TB/MB) and percent is an INT (source v1.5.0 + live VM 105), so
        // parse the strings to GB; do NOT byte-divide.
        $volumes = collect($agent['disks'] ?? [])->take(10)->map(fn ($d) => [
            'drive' => $d['device'] ?? null,
            'total_gb' => TacticalFieldMap::diskSizeToGb($d['total'] ?? null),
            'free_gb' => TacticalFieldMap::diskSizeToGb($d['free'] ?? null),
            'percent_used' => $d['percent'] ?? null,
            'fstype' => $d['fstype'] ?? null,
        ])->toArray();

        // Physical disks
        $physicalDisks = collect($agent['physical_disks'] ?? [])->take(10)->map(fn ($d) => [
            'model' => $d['caption'] ?? $d['model'] ?? null,
            'size_gb' => isset($d['size']) ? round($d['size'] / 1073741824, 1) : null,
            'interface' => $d['interface_type'] ?? null,
            'status' => $d['status'] ?? null,
        ])->toArray();

        // WMI disk detail if available
        $wmiDisks = collect($agent['wmi_detail']['disk'] ?? [])->take(10)->map(fn ($d) => [
            'caption' => $d['Caption'] ?? null,
            'size_gb' => isset($d['Size']) ? round($d['Size'] / 1073741824, 1) : null,
            'free_gb' => isset($d['FreeSpace']) ? round($d['FreeSpace'] / 1073741824, 1) : null,
        ])->toArray();

        return [
            'volumes' => $volumes,
            'physical_disks' => $physicalDisks,
            'wmi_disk' => $wmiDisks,
        ];
    }

    // ── Comet Backup Tools ──

    private function resolveCometAsset(string $hostname): ?\App\Models\Asset
    {
        return \App\Models\Asset::where('client_id', $this->clientId)
            ->whereNotNull('comet_device_id')
            ->whereRaw('LOWER(hostname) = ?', [strtolower($hostname)])
            ->first();
    }

    private function executeCometGetBackupStatus(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $asset = $this->resolveCometAsset($hostname);
        if (! $asset) {
            return ['error' => "No Comet-linked asset found for hostname '{$hostname}' in this client"];
        }

        $client = new CometClient;
        $jobService = new CometJobService($client);
        $jobData = $jobService->getRecentJobs($asset, 30);

        $daysSinceBackup = null;
        if ($jobData['last_success']) {
            // Sign-safe (psa-lqlu): $past->diffInDays(now()) is positive; the now()-first form
            // is NEGATIVE in Carbon 3 and reported a negative "days since backup" to the agent.
            $daysSinceBackup = (int) \Carbon\Carbon::parse($jobData['last_success']['started'])->diffInDays(now());
        }

        return [
            'hostname' => $asset->hostname,
            'comet_username' => $asset->comet_username,
            'cloud_storage_bytes' => $asset->backup_cloud_bytes,
            'local_storage_bytes' => $asset->backup_local_bytes,
            'last_synced' => $asset->backup_synced_at?->toDateTimeString(),
            'last_success' => $jobData['last_success']['started'] ?? null,
            'last_failure' => $jobData['last_failure']['started'] ?? null,
            'days_since_last_success' => $daysSinceBackup,
        ];
    }

    private function executeCometGetBackupJobs(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }

        $asset = $this->resolveCometAsset($hostname);
        if (! $asset) {
            return ['error' => "No Comet-linked asset found for hostname '{$hostname}' in this client"];
        }

        $days = $input['days'] ?? 7;
        $client = new CometClient;
        $jobService = new CometJobService($client);
        $jobData = $jobService->getRecentJobs($asset, $days);

        return [
            'hostname' => $asset->hostname,
            'job_count' => count($jobData['jobs']),
            'jobs' => array_slice($jobData['jobs'], 0, 20),
        ];
    }

    private function tacticalRunDiagnostic(array $input): array
    {
        $hostname = $input['hostname'] ?? null;
        $diagnostic = $input['diagnostic'] ?? null;

        if (! $hostname) {
            return ['error' => 'hostname is required'];
        }
        if (! $diagnostic) {
            return ['error' => 'diagnostic is required'];
        }

        $diagnosticScripts = [
            'event_log_errors' => 201,
            'top_processes' => 202,
            'network_test' => 203,
            'windows_update_history' => 204,
            'printer_status' => 205,
            'startup_programs' => 206,
            'uptime_detail' => 207,
            'dns_config' => 208,
            'firewall_status' => 209,
            'disk_health' => 210,
        ];

        if (! isset($diagnosticScripts[$diagnostic])) {
            return ['error' => "Invalid diagnostic '{$diagnostic}'. Available: ".implode(', ', array_keys($diagnosticScripts))];
        }

        // Client-scoping is enforced here: resolveTacticalAgent() rejects a
        // hostname whose linked asset belongs to a different client.
        $resolved = $this->resolveTacticalAgent($hostname);
        if (! $resolved) {
            return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
        }

        /** @var \App\Models\TacticalAsset $tacticalAsset */
        $tacticalAsset = $resolved['tactical_asset'];
        $asset = $tacticalAsset->asset;

        // The bus resolves the agent FROM a PSA Asset. A Tactical agent with no
        // linked PSA asset can't be dispatched through the bus; surface a clear
        // error rather than silently bypassing the audited pipeline.
        if (! $asset) {
            return ['error' => "Device '{$hostname}' is not linked to a PSA asset"];
        }

        // Dispatch through the audited bus as the AI actor (M1: no User; attribute
        // via actor_label='ai-triage', actor_id null). The bus catches/classifies
        // transport vs HTTP errors and audits the run.
        $result = app(\App\Services\Tactical\TacticalActionService::class)->dispatch(
            new \App\Services\Tactical\Actions\RunScriptAction,
            $asset,
            null,
            [
                'tactical_script_id' => $diagnosticScripts[$diagnostic],
                'args' => '',
                'timeout' => 30,
            ],
            actorLabel: 'ai-triage',
            ticketId: $this->ticket->id,
        );

        if (! $result->isOk()) {
            Log::warning('[Triage] Tactical diagnostic did not succeed', [
                'hostname' => $hostname,
                'diagnostic' => $diagnostic,
                'status' => $result->status,
            ]);

            return ['error' => 'Tactical diagnostic failed: '.mb_substr($result->message ?? $result->status, 0, 200)];
        }

        return [
            'diagnostic' => $diagnostic,
            'retcode' => $result->retcode,
            'stdout' => mb_substr($result->stdout ?? '', 0, 3000),
            // Carry stderr through (the bus result captures it): diagnostics like
            // disk_health/network_test write signal to stderr the AI reasons over.
            'stderr' => mb_substr($result->stderr ?? '', 0, 1000),
        ];
    }
}
