<?php

namespace App\Services\Tactical;

use Illuminate\Support\Carbon;

/**
 * Normalized endpoint telemetry for a Tactical-linked asset — the SINGLE read
 * layer for two consumers (amendment A, spec §5.3):
 *
 *   - P4 UI: the asset-page panels + the eager card health line.
 *   - P5 AI: TacticalContextProvider serializes a token-budgeted, secret-scrubbed
 *     subset of THIS object without any second client call.
 *
 * Design rules this object enforces (binding):
 *   - Readonly + plain-text-friendly. Secret-bearing free-text (check stdout,
 *     hostname, software names) are plain-string members so P5 can redact
 *     FLATTENED PLAIN TEXT (toPlainText()), never json_encode (§11.1: JSON
 *     escaping slips PEM/connection-strings past WikiRedactor).
 *   - Deterministic flags (needsReboot/lowDisk/longOffline/stale) are computed
 *     in TacticalInsightService against the documented thresholds below — the
 *     model never invents thresholds (§11.4).
 *   - userLoggedIn is a BOOLEAN, not the raw logged_in_username (§11.6 PII;
 *     redact() won't strip a bare username). There is intentionally NO public
 *     username accessor for the P5 serializer to reach.
 *   - failingChecks carry RAW un-clipped stdout (each consumer clips to its own
 *     budget).
 *   - Per-section SignalState (Live|Snapshot|Unavailable) so "couldn't fetch"
 *     never reads as "clean/empty" (§11.7).
 *   - freshAsOf is the FRESHEST-SIGNAL stamp (synced_at when all-snapshot, now()
 *     once any signal refreshes live). On a mixed read it can read now() while a
 *     degraded signal is stale — so per-signal freshness is the SignalState, not
 *     freshAsOf. Gate each signal on its own statusState/checksState (§11.7).
 *
 * THRESHOLD CONSTANTS (documented — the deterministic-flag definitions):
 *   - STALE_AFTER_MINUTES (60): a snapshot whose freshAsOf is older than this is
 *     `stale` — and an "online" status older than this is the dangerous misread
 *     (amendment H amber treatment). Mirrors the live-refresh honesty window.
 *   - LONG_OFFLINE_AFTER_DAYS (7): lastSeen older than this => `longOffline`
 *     (a genuinely abandoned/decommissioned-looking endpoint, not a flap).
 *   - LOW_DISK_PERCENT_USED (90) / LOW_DISK_FREE_GB (10): a volume at/above 90%
 *     used OR below 10 GB free => `lowDisk`. Either trigger fires (a 2TB disk at
 *     88% still has >200GB free; a 64GB SSD at 85% is nearly full — the GB floor
 *     and the percent ceiling cover both regimes). Computed only from STRUCTURED
 *     live disk volumes (the snapshot's disk_summary string isn't parseable);
 *     false/unknown when no structured disk data is present.
 */
final readonly class EndpointInsight
{
    public const STALE_AFTER_MINUTES = 60;

    public const LONG_OFFLINE_AFTER_DAYS = 7;

    public const LOW_DISK_PERCENT_USED = 90;

    public const LOW_DISK_FREE_GB = 10;

    /**
     * @param  FailingCheck[]  $failingChecks  RAW un-clipped stdout per check
     * @param  array<int, array{drive: ?string, total_gb: ?float, free_gb: ?float, percent_used: int|float|null}>  $diskVolumes  Structured volumes (live only)
     * @param  array<int, array{title: string, severity: ?string, source: ?string}>  $openAlertList  Small list (local DB)
     * @param  ?int  $pendingPatchCount  PRECISE pending-update count, or null when unknown. The snapshot path knows only the boolean (see $hasPendingPatches); the exact count is a live/panel read. NEVER fabricate a count from the boolean (§11.3): "1 pending" for a box 47 behind would lie to the P5 snapshot. Mirrors the $checksFailing-null precedent (Unavailable ≠ "0 clean").
     * @param  bool  $hasPendingPatches  The honest snapshot boolean ("updates pending" vs "up to date") the eager card chip uses — true even when the exact $pendingPatchCount is null/unknown.
     * @param  array<int, array{action: string, actor: string, result_status: string, ticket_id: ?int, when: ?string}>  $recentActions  Newest-first, capped
     * @param  ?Carbon  $freshAsOf  The FRESHEST-SIGNAL stamp only (synced_at for an all-snapshot read; now() once ANY signal refreshes live). On a MIXED read (status Live, checks Snapshot) it reads now() while checks are stale — so it is NOT a per-signal freshness oracle. Consumers MUST gate a given signal's freshness on its own SignalState ($statusState / $checksState), never on freshAsOf alone (§11.7).
     */
    public function __construct(
        public bool $linked,
        public ?string $agentId,
        public ?string $hostname,
        public ?string $status,
        public SignalState $statusState,
        public ?Carbon $lastSeen,
        public ?string $uptime,
        public ?string $cpu,
        public ?float $ramGb,
        public ?string $diskSummary,
        public array $diskVolumes,
        public bool $needsReboot,
        public bool $lowDisk,
        public bool $longOffline,
        public bool $stale,
        public bool $maintenance,
        public bool $userLoggedIn,
        public array $failingChecks,
        public SignalState $checksState,
        public ?int $checksFailing,
        public ?int $checksTotal,
        public int $openAlerts,
        public array $openAlertList,
        public ?int $pendingPatchCount,
        public bool $hasPendingPatches,
        public array $recentActions,
        public ?Carbon $freshAsOf,
    ) {}

    /**
     * The insight for an asset that is not linked to a Tactical agent. A clear,
     * no-throw shape: every signal Unavailable (nothing was read), zero counts.
     */
    public static function notLinked(): self
    {
        return new self(
            linked: false,
            agentId: null,
            hostname: null,
            status: null,
            statusState: SignalState::Unavailable,
            lastSeen: null,
            uptime: null,
            cpu: null,
            ramGb: null,
            diskSummary: null,
            diskVolumes: [],
            needsReboot: false,
            lowDisk: false,
            longOffline: false,
            stale: false,
            maintenance: false,
            userLoggedIn: false,
            failingChecks: [],
            checksState: SignalState::Unavailable,
            checksFailing: null,
            checksTotal: null,
            openAlerts: 0,
            openAlertList: [],
            pendingPatchCount: null,
            hasPendingPatches: false,
            recentActions: [],
            freshAsOf: null,
        );
    }

    /**
     * True only when the checks signal was actually READ (Live or Snapshot) AND
     * reports zero failing. An Unavailable checks state is NOT clean — we simply
     * don't know (§11.7). Guards the UI/AI from rendering a fetch failure as
     * "✓ all checks passing".
     */
    public function checksKnownClean(): bool
    {
        return $this->checksState !== SignalState::Unavailable
            && $this->checksFailing === 0;
    }

    /**
     * Flatten the secret-bearing, AI-relevant telemetry to PLAIN TEXT for P5
     * redaction (§11.1 — never json_encode). One fact per line; free-text
     * (hostname, check name + raw stdout) appears verbatim so the redactor's
     * patterns can see contiguous secrets. This is the redaction *input* shape;
     * P5 owns clipping/budgeting/fencing on top.
     */
    public function toPlainText(): string
    {
        $lines = [];

        if ($this->hostname !== null) {
            $lines[] = "Host: {$this->hostname}";
        }
        if ($this->status !== null) {
            $lines[] = "Status: {$this->status}";
        }
        if ($this->uptime !== null) {
            $lines[] = "Uptime: {$this->uptime}";
        }
        if ($this->cpu !== null) {
            $lines[] = "CPU: {$this->cpu}";
        }
        if ($this->ramGb !== null) {
            $lines[] = "RAM: {$this->ramGb} GB";
        }

        foreach ($this->failingChecks as $check) {
            $retcode = $check->retcode !== null ? " (rc={$check->retcode})" : '';
            $lines[] = "Failing check: {$check->name}{$retcode}: {$check->stdout}";
        }

        return implode("\n", $lines);
    }
}
