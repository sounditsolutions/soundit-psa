<?php

namespace App\Services\Tactical;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Alert;
use App\Models\Ticket;
use App\Models\TacticalAsset;
use App\Services\AlertService;
use App\Services\TicketService;
use App\Support\TacticalConfig;
use App\Support\TriageConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TacticalAlertService
{
    /**
     * Max auto-created alert-tickets for a single client within BURST_WINDOW_MINUTES
     * before switching to a single "alert storm" consolidation ticket.
     */
    public const int BURST_CAP = 10;

    /**
     * Rolling window (minutes) for burst-guard counting.
     */
    public const int BURST_WINDOW_MINUTES = 5;

    public function __construct(
        private readonly AlertService $alertService,
        private readonly TicketService $ticketService,
    ) {}

    /**
     * Handle an alert failure webhook from Tactical RMM.
     * Creates or updates a unified Alert record.
     */
    public function handleAlertFailure(array $data): ?Alert
    {
        $agentId = $data['agent_id'] ?? null;
        $hostname = $data['hostname'] ?? 'Unknown';
        $clientName = $data['client_name'] ?? null;
        $siteName = $data['site_name'] ?? null;
        $alertMessage = $data['alert_message'] ?? 'Alert triggered';
        $alertType = $data['alert_type'] ?? 'check';
        $severity = $data['severity'] ?? null;
        $checkName = $data['check_name'] ?? null;
        $checkOutput = $data['check_output'] ?? null;
        $monitoringType = $data['monitoring_type'] ?? null;
        $alertId = $data['alert_id'] ?? null;
        $alertTime = $data['alert_time'] ?? null;
        $publicIp = $data['public_ip'] ?? null;
        $loggedInUser = $data['logged_in_user'] ?? null;
        $operatingSystem = $data['operating_system'] ?? null;
        $needsReboot = $data['needs_reboot'] ?? null;

        // Check severity threshold — treat null/empty/none as below any threshold
        $severityLevels = ['error' => 3, 'warning' => 2, 'info' => 1, 'informational' => 1];
        $normalizedSeverity = strtolower(trim($severity ?? ''));
        $alertLevel = $severityLevels[$normalizedSeverity] ?? 0;
        $minLevel = $severityLevels[TacticalConfig::alertMinSeverity()] ?? 2;

        if ($alertLevel < $minLevel) {
            Log::debug('[Tactical Alert] Below severity threshold, ignoring', [
                'severity' => $severity,
                'hostname' => $hostname,
                'check_name' => $checkName,
            ]);

            return null;
        }

        // Skip transient errors (device waking up, PowerShell not ready, etc.)
        $transientPatterns = [
            'The operation could not be completed. A retry should be performed',
            'fork/exec',
        ];
        $alertText = ($alertMessage ?? '').' '.($checkOutput ?? '');
        foreach ($transientPatterns as $pattern) {
            if (stripos($alertText, $pattern) !== false) {
                Log::debug('[Tactical Alert] Transient error, ignoring', [
                    'hostname' => $hostname,
                    'pattern' => $pattern,
                ]);

                return null;
            }
        }

        // Skip alerts with no check output (transient failures where script didn't run)
        $normalizedOutput = strtolower(trim($checkOutput ?? ''));
        if ($alertType === 'check' && ($normalizedOutput === '' || $normalizedOutput === 'none' || $normalizedOutput === 'null')) {
            Log::debug('[Tactical Alert] Empty check output, ignoring', [
                'hostname' => $hostname,
                'check_name' => $checkName,
            ]);

            return null;
        }

        // Skip overdue/availability alerts for workstations (only alert for servers)
        if ($alertType === 'availability' && strtolower($monitoringType ?? '') !== 'server') {
            Log::debug('[Tactical Alert] Skipping overdue alert for non-server', [
                'hostname' => $hostname,
                'monitoring_type' => $monitoringType,
            ]);

            return null;
        }

        // Resolve tactical asset -> PSA asset -> client
        $tacticalAsset = $agentId ? TacticalAsset::where('agent_id', $agentId)->first() : null;
        $asset = $tacticalAsset?->asset;
        $clientId = $asset?->client_id;

        if (! $clientId) {
            Log::info('[Tactical Alert] No client match for agent, creating unlinked alert', [
                'agent_id' => $agentId,
                'hostname' => $hostname,
                'client_name' => $clientName,
            ]);
        }

        // Map severity to unified AlertSeverity
        $unifiedSeverity = AlertSeverity::fromVendor(AlertSource::Tactical, $severity);

        // Build title
        $severityLabel = strtoupper($normalizedSeverity ?: 'UNKNOWN');
        $checkLabel = $checkName ?? $alertType;
        $title = "{$severityLabel} - {$checkLabel} on {$hostname}";

        // Build message body
        $msgLines = [];
        if ($clientName) {
            $msgLines[] = "Client: {$clientName}";
        }
        if ($siteName) {
            $msgLines[] = "Site: {$siteName}";
        }
        if ($operatingSystem) {
            $msgLines[] = "OS: {$operatingSystem}";
        }
        if ($publicIp) {
            $msgLines[] = "Public IP: {$publicIp}";
        }
        if ($loggedInUser) {
            $msgLines[] = "Logged-in user: {$loggedInUser}";
        }
        $msgLines[] = "Alert type: {$alertType}";
        $msgLines[] = "Severity: {$severityLabel}";
        if ($checkName) {
            $msgLines[] = "Check: {$checkName}";
        }
        $msgLines[] = "Message: {$alertMessage}";
        if ($alertTime) {
            $msgLines[] = "Alert time: {$alertTime}";
        }
        if ($needsReboot && strtolower($needsReboot) === 'true') {
            $msgLines[] = 'Needs reboot: Yes';
        }
        if ($checkOutput) {
            $msgLines[] = '';
            $msgLines[] = 'Check Output:';
            $msgLines[] = substr($checkOutput, 0, 2000);
        }

        // source_alert_id: use alert_id if available, otherwise synthesize from hostname+check
        $sourceAlertId = $alertId ? (string) $alertId : md5("{$hostname}:{$checkLabel}");

        $firedAt = $alertTime ? Carbon::parse($alertTime) : now();

        $alert = $this->alertService->upsert(
            AlertSource::Tactical,
            $sourceAlertId,
            [
                'asset_id' => $asset?->id,
                'client_id' => $clientId,
                'severity' => $unifiedSeverity,
                'title' => mb_substr($title, 0, 255),
                'message' => implode("\n", $msgLines),
                'hostname' => $hostname,
                'fired_at' => $firedAt,
                'metadata' => [
                    'agent_id' => $agentId,
                    'alert_type' => $alertType,
                    'monitoring_type' => $monitoringType,
                    'public_ip' => $publicIp,
                    'logged_in_user' => $loggedInUser,
                    'needs_reboot' => $needsReboot,
                ],
            ],
        );

        Log::info('[Tactical Alert] Alert upserted', [
            'alert_id' => $alert->id,
            'source_alert_id' => $sourceAlertId,
            'hostname' => $hostname,
            'client_id' => $clientId,
        ]);

        // G6 — opt-in auto-ticket (Tactical-scoped, not in shared AlertService)
        $this->maybeAutoTicket($alert, $clientId, $alertLevel);

        return $alert;
    }

    /**
     * G6 — auto-ticket gate (Tactical-scoped).
     *
     * Creates a ticket only when ALL hold:
     *   1. tactical_auto_ticket setting is ON (default OFF)
     *   2. alert severity level ≥ auto_ticket_min_severity threshold (default 'error')
     *   3. alert has no existing ticket_id (re-fires keep their ticket, no second one)
     *   4. burst cap not exceeded — if exceeded, ensures a single consolidation
     *      "alert storm" ticket exists instead of creating another normal ticket.
     */
    private function maybeAutoTicket(Alert $alert, ?int $clientId, int $alertLevel): void
    {
        // Gate 1: feature flag OFF (default) → do nothing
        if (! TacticalConfig::autoTicket()) {
            return;
        }

        // Gate 2: severity threshold
        $severityLevels = ['error' => 3, 'warning' => 2, 'info' => 1, 'informational' => 1];
        $minAutoLevel = $severityLevels[TacticalConfig::autoTicketMinSeverity()] ?? 3; // default error=3
        if ($alertLevel < $minAutoLevel) {
            return;
        }

        // Gate 3: already ticketed (re-fire dedup — createTicket also guards, but check early)
        if ($alert->ticket_id) {
            return;
        }

        // Burst guard: count recent auto-created alert-tickets for this client
        $recentCount = Ticket::where('source', TicketSource::Alert->value)
            ->where('client_id', $clientId)
            ->where('created_at', '>=', now()->subMinutes(self::BURST_WINDOW_MINUTES))
            ->where('subject', 'not like', '%Alert Storm%') // don't count the storm ticket itself
            ->count();

        if ($recentCount >= self::BURST_CAP) {
            // Burst exceeded: ensure exactly one open storm ticket for this client
            $this->ensureStormTicket($clientId);

            Log::warning('[Tactical Alert] Burst cap exceeded — skipping normal auto-ticket, storm ticket ensured', [
                'alert_id' => $alert->id,
                'client_id' => $clientId,
                'recent_count' => $recentCount,
                'cap' => self::BURST_CAP,
            ]);

            return;
        }

        // All gates pass — create the ticket attributed to the system user
        $systemUserId = TriageConfig::systemUserId();
        $this->alertService->createTicket($alert, $systemUserId);

        Log::info('[Tactical Alert] Auto-ticket created', [
            'alert_id' => $alert->id,
            'ticket_id' => $alert->fresh()->ticket_id,
            'created_by' => $systemUserId,
        ]);
    }

    /**
     * Ensure exactly one open "alert storm" consolidation ticket exists for a client.
     * Idempotent: does nothing if an open storm ticket is already present.
     */
    private function ensureStormTicket(?int $clientId): void
    {
        $existing = Ticket::where('source', TicketSource::Alert->value)
            ->where('client_id', $clientId)
            ->where('subject', 'like', '%Alert Storm%')
            ->whereIn('status', [
                TicketStatus::New->value,
                TicketStatus::InProgress->value,
                TicketStatus::PendingClient->value,
                TicketStatus::PendingThirdParty->value,
            ])
            ->first();

        if ($existing) {
            // Storm ticket already open — dedup, do not create another
            return;
        }

        $systemUserId = TriageConfig::systemUserId();
        $this->ticketService->createTicket([
            'subject' => 'Alert Storm — Multiple Tactical RMM alerts fired',
            'description' => "More than ".self::BURST_CAP." Tactical RMM alerts fired within ".self::BURST_WINDOW_MINUTES." minutes for this client.\n\nPlease investigate the root cause.",
            'client_id' => $clientId,
            'contact_id' => null,
            'priority' => TicketPriority::P2->value,
            'type' => TicketType::Incident->value,
            'source' => TicketSource::Alert->value,
            'source_ref' => null,
        ], $systemUserId);

        Log::warning('[Tactical Alert] Alert storm consolidation ticket created', [
            'client_id' => $clientId,
        ]);
    }

    /**
     * Handle an alert resolved webhook from Tactical RMM.
     * Resolves the matching open Alert record.
     */
    public function handleAlertResolved(array $data): ?Alert
    {
        $agentId = $data['agent_id'] ?? null;
        $hostname = $data['hostname'] ?? null;
        $severity = $data['severity'] ?? null;
        $checkName = $data['check_name'] ?? null;
        $alertType = $data['alert_type'] ?? 'check';
        $alertId = $data['alert_id'] ?? null;
        $actionStdout = $data['action_stdout'] ?? null;
        $actionStderr = $data['action_stderr'] ?? null;
        $actionRetcode = $data['action_retcode'] ?? null;

        // Try matching by alert_id first
        $alert = null;
        if ($alertId) {
            $alert = Alert::where('source', AlertSource::Tactical)
                ->where('source_alert_id', (string) $alertId)
                ->whereIn('status', [\App\Enums\AlertStatus::Active, \App\Enums\AlertStatus::Acknowledged, \App\Enums\AlertStatus::Ticketed])
                ->first();
        }

        // Fall back to synthesized key for backwards compatibility
        if (! $alert && $hostname) {
            $normalizedSeverity = strtolower(trim($severity ?? ''));
            $checkLabel = $checkName ?? $alertType;
            $fallbackId = md5("{$hostname}:{$checkLabel}");
            $alert = Alert::where('source', AlertSource::Tactical)
                ->where('source_alert_id', $fallbackId)
                ->whereIn('status', [\App\Enums\AlertStatus::Active, \App\Enums\AlertStatus::Acknowledged, \App\Enums\AlertStatus::Ticketed])
                ->first();
        }

        if (! $alert) {
            Log::debug('[Tactical Alert] No open alert found for resolved event', [
                'alert_id' => $alertId,
                'hostname' => $hostname,
                'check_name' => $checkName,
            ]);

            return null;
        }

        // Build resolution reason with action results if available
        $reason = 'Alert resolved automatically by Tactical RMM.';
        if ($actionStdout || $actionStderr || $actionRetcode !== null) {
            $reason .= "\n\nResponse Action Results:";
            if ($actionRetcode !== null) {
                $reason .= "\n- Return code: {$actionRetcode}";
            }
            if ($actionStdout) {
                $reason .= "\n\nOutput:\n".substr($actionStdout, 0, 3000);
            }
            if ($actionStderr) {
                $reason .= "\n\nErrors:\n".substr($actionStderr, 0, 1000);
            }
        }

        $this->alertService->resolve($alert, $reason);

        // G7 — Tactical-scoped auto-resolve (NOT in shared AlertService::resolve()).
        // After AlertService::resolve() has added its NoteType::System note, check whether
        // the linked ticket is an auto-created (TicketSource::Alert), still-open, untouched
        // ticket. If so, resolve it (not close) with a resolution string so the
        // GenerateTicketResolution LLM draft job does not fire. CloseResolvedTickets later
        // closes it after the confirmation window.
        $this->maybeAutoResolveTicket($alert, $reason);

        Log::info('[Tactical Alert] Alert resolved', [
            'alert_id' => $alert->id,
            'source_alert_id' => $alertId,
            'hostname' => $hostname,
        ]);

        return $alert;
    }

    /**
     * G7 — auto-RESOLVE (not close) the linked ticket on Tactical alert resolve,
     * but ONLY when ALL hold:
     *   1. Alert has a linked ticket
     *   2. Ticket source is TicketSource::Alert (auto-created from an alert)
     *   3. Ticket is still open
     *   4. Ticket::isUntouchedByHuman() — no human notes/replies/portal-replies,
     *      responded_at null, status still New
     *
     * Passes a resolution string to TicketService::changeStatus() so the
     * TicketObserver does NOT dispatch GenerateTicketResolution (empty resolution = LLM draft).
     *
     * The resolve note added by AlertService::resolve() is NoteType::System, which is
     * excluded from the isUntouchedByHuman() check — ordering is safe.
     */
    private function maybeAutoResolveTicket(Alert $alert, string $reason): void
    {
        // Gate 1: must have a linked ticket
        if (! $alert->ticket_id) {
            return;
        }

        $ticket = $alert->ticket;
        if (! $ticket) {
            return;
        }

        // Gate 2: must be auto-created from an alert (not manually-created or other source)
        if ($ticket->source !== TicketSource::Alert) {
            return;
        }

        // Gate 3: must still be open
        if (! $ticket->isOpen()) {
            return;
        }

        // Gate 4: must be untouched by a human (freshly re-query to pick up the resolve note
        // that AlertService::resolve() just added — that note is NoteType::System, which
        // isUntouchedByHuman() correctly ignores)
        $ticket->refresh();
        if (! $ticket->isUntouchedByHuman()) {
            return;
        }

        $systemUserId = TriageConfig::systemUserId();
        if (! $systemUserId) {
            Log::warning('[Tactical Alert] Cannot auto-resolve ticket — no system user', [
                'ticket_id' => $ticket->id,
            ]);

            return;
        }

        // Truncate the resolution string to a reasonable length
        $resolution = mb_substr($reason, 0, 1000);

        $this->ticketService->changeStatus(
            $ticket,
            TicketStatus::Resolved,
            $systemUserId,
            'Ticket auto-resolved: Tactical RMM alert cleared.',
            $resolution,
        );

        Log::info('[Tactical Alert] Auto-resolved untouched auto-ticket', [
            'alert_id' => $alert->id,
            'ticket_id' => $ticket->id,
        ]);
    }
}
