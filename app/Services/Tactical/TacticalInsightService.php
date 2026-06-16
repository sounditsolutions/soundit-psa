<?php

namespace App\Services\Tactical;

use App\Models\Alert;
use App\Models\Asset;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * The single Endpoint Insight read layer (spec §5.3), consumed by the P4 UI and
 * (later) the P5 TacticalContextProvider — one source, two consumers, no
 * duplicated client calls.
 *
 * Freshness is hybrid:
 *   - The tactical_assets SNAPSHOT (+ local-DB open alerts + recent action logs)
 *     is the instant base. forAsset($asset) makes ZERO live calls.
 *   - forAsset($asset, live: true) opportunistically refreshes the CHEAP signals
 *     (status + checks) through the generic bounded wrapper (a short ~2-3s
 *     timeout, degrade-to-snapshot, never throw). Software/patches stay lazy
 *     (loaded on demand by the panel layer, chunk 2).
 *
 * The degrade classifier lives HERE, not in TacticalClient::get() — the action
 * bus depends on get()/post() throwing on non-2xx. read() is the one bounded
 * code path; the panel reads + P5 reuse it.
 */
class TacticalInsightService
{
    /** Per-request live-read timeout in seconds (§11.5 ~2-3s bound). */
    public const LIVE_TIMEOUT_SECONDS = 3;

    /** How many recent action-log rows the insight carries (newest first). */
    public const RECENT_ACTIONS_LIMIT = 10;

    /** How many open alerts the small in-insight list carries. */
    public const OPEN_ALERT_LIST_LIMIT = 5;

    public function __construct(
        private readonly TacticalClient $client,
    ) {}

    public function forAsset(Asset $asset, bool $live = false): EndpointInsight
    {
        $ta = $asset->tacticalAsset;

        if (! $ta) {
            return EndpointInsight::notLinked();
        }

        // ── Snapshot base (zero live cost) ──
        $status = $ta->status;
        $statusState = SignalState::Snapshot;
        $maintenance = false; // not persisted on the snapshot; only known live
        $userLoggedIn = ! empty($ta->last_user);
        $needsReboot = (bool) $ta->needs_reboot;

        $checksFailing = $ta->checks_failing;
        $checksTotal = $ta->checks_total;
        $checksState = $checksFailing !== null
            ? SignalState::Snapshot
            : SignalState::Unavailable;
        /** @var FailingCheck[] $failingChecks */
        $failingChecks = []; // the detailed list is a live/panel read, not on the snapshot

        $diskVolumes = [];     // structured volumes only come from a live detail read
        $lowDisk = false;      // unknown without structured disk data
        $freshAsOf = $ta->synced_at;

        // ── Opportunistic bounded live refresh of the cheap signals ──
        if ($live) {
            $agentRead = $this->read(
                fn () => $this->client->getAgent($ta->agent_id, timeout: self::LIVE_TIMEOUT_SECONDS),
                fallback: null,
                signal: 'status',
                agentId: $ta->agent_id,
            );

            if ($agentRead->isLive() && is_array($agentRead->value)) {
                $agent = $agentRead->value;
                $status = $agent['status'] ?? $status;
                $statusState = SignalState::Live;
                $maintenance = (bool) ($agent['maintenance_mode'] ?? false);
                $userLoggedIn = ! empty($agent['logged_in_username']);
                $needsReboot = (bool) ($agent['needs_reboot'] ?? $needsReboot);
                $freshAsOf = now();

                // Disk volumes are free from the detail we already fetched — use
                // them for the deterministic lowDisk flag.
                $diskVolumes = $this->mapDiskVolumes($agent['disks'] ?? []);
                $lowDisk = $this->computeLowDisk($diskVolumes);
            }

            $checksRead = $this->read(
                fn () => $this->client->getAgentChecks($ta->agent_id, timeout: self::LIVE_TIMEOUT_SECONDS),
                fallback: null,
                signal: 'checks',
                agentId: $ta->agent_id,
            );

            if ($checksRead->isLive() && is_array($checksRead->value)) {
                $checks = $checksRead->value;
                $failingChecks = $this->mapFailingChecks($checks);
                $counts = TacticalFieldMap::checksSummary($checks);
                $checksFailing = $counts['failing'];
                $checksTotal = $counts['total'];
                $checksState = SignalState::Live;
                $freshAsOf = now();
            } elseif ($checksFailing === null) {
                // Live fetch failed AND no snapshot count => genuinely unavailable
                // (NOT "0 clean"). Keep Unavailable.
                $checksState = SignalState::Unavailable;
            }
        }

        return new EndpointInsight(
            linked: true,
            agentId: $ta->agent_id,
            hostname: $ta->hostname,
            status: $status,
            statusState: $statusState,
            lastSeen: $ta->last_seen_at,
            uptime: $this->snapshotUptime($ta),
            cpu: $ta->cpu,
            ramGb: $ta->ram_gb !== null ? (float) $ta->ram_gb : null,
            diskSummary: $ta->disk_summary,
            diskVolumes: $diskVolumes,
            needsReboot: $needsReboot,
            lowDisk: $lowDisk,
            longOffline: $this->computeLongOffline($ta),
            stale: $this->computeStale($freshAsOf, $statusState),
            maintenance: $maintenance,
            userLoggedIn: $userLoggedIn,
            failingChecks: $failingChecks,
            checksState: $checksState,
            checksFailing: $checksFailing,
            checksTotal: $checksTotal,
            openAlerts: $this->openAlertCount($asset),
            openAlertList: $this->openAlertList($asset),
            pendingPatchCount: $this->pendingPatchCount($ta),
            hasPendingPatches: (bool) $ta->has_patches_pending,
            recentActions: $this->recentActions($asset),
            freshAsOf: $freshAsOf,
        );
    }

    /**
     * The ONE bounded-read primitive (amendment C). Runs a live read closure
     * (which carries its own short timeout), classifying any failure to a
     * SignalState and falling back to $fallback instead of throwing. Reused by
     * the panel reads (chunk 2) and P5 — signal-agnostic.
     *
     *   - success            => {value: <live>, state: Live}
     *   - transport failure  => {value: <fallback>, state: Snapshot|Unavailable}
     *   - HTTP error / other => {value: <fallback>, state: Snapshot|Unavailable}
     *
     * The returned state is Snapshot when a fallback exists, Unavailable when it
     * is null (nothing to fall back to). The caller decides the final state for
     * its signal (e.g. checks with a snapshot count stays Snapshot even though
     * the closure here returns null).
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @param  T  $fallback
     * @return BoundedRead<T>
     */
    public function read(Closure $read, mixed $fallback = null, string $signal = 'signal', ?string $agentId = null): BoundedRead
    {
        try {
            return new BoundedRead($read(), SignalState::Live);
        } catch (TacticalClientException $e) {
            // Record offline-vs-error for the live-verify pass (debug, not error —
            // an offline agent is a normal, expected outcome on a read path).
            Log::debug('[TacticalInsight] bounded read degraded', [
                'signal' => $signal,
                'agent_id' => $agentId,
                'transport_failure' => $e->isTransportFailure(),
                'status_code' => $e->statusCode(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('[TacticalInsight] bounded read failed', [
                'signal' => $signal,
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);
        }

        $state = $fallback !== null ? SignalState::Snapshot : SignalState::Unavailable;

        return new BoundedRead($fallback, $state);
    }

    /**
     * Map Tactical's failing checks (getAgentChecks shape) to FailingCheck value
     * objects with RAW un-clipped stdout. Only failing checks are carried (the
     * passing ones are noise for both the panel and the AI).
     *
     * @param  array<int, array<string, mixed>>  $checks
     * @return FailingCheck[]
     */
    private function mapFailingChecks(array $checks): array
    {
        $failing = [];

        foreach ($checks as $check) {
            if (! is_array($check) || TacticalFieldMap::checkStatus($check) !== 'failing') {
                continue;
            }

            $result = $check['check_result'] ?? [];

            $failing[] = new FailingCheck(
                name: $check['name'] ?? $check['readable_desc'] ?? 'Unknown',
                status: 'failing',
                retcode: isset($result['retcode']) ? (int) $result['retcode'] : null,
                stdout: (string) ($result['stdout'] ?? ''),
            );
        }

        return $failing;
    }

    /**
     * Map Tactical's disk volumes (getAgent `disks`) to a structured shape for
     * the lowDisk flag + the UI.
     *
     * @param  array<int, array<string, mixed>>  $disks
     * @return array<int, array{drive: ?string, total_gb: ?float, free_gb: ?float, percent_used: int|float|null}>
     */
    private function mapDiskVolumes(array $disks): array
    {
        return collect($disks)->take(10)->map(fn ($d) => [
            'drive' => $d['device'] ?? null,
            'total_gb' => TacticalFieldMap::ramGbFromBytes($d['total'] ?? null),
            'free_gb' => TacticalFieldMap::ramGbFromBytes($d['free'] ?? null),
            'percent_used' => $d['percent'] ?? null,
        ])->values()->all();
    }

    /**
     * Deterministic lowDisk (§11.4): any volume at/above LOW_DISK_PERCENT_USED OR
     * below LOW_DISK_FREE_GB free. Computed only from structured live volumes.
     *
     * @param  array<int, array{drive: ?string, total_gb: ?float, free_gb: ?float, percent_used: int|float|null}>  $volumes
     */
    private function computeLowDisk(array $volumes): bool
    {
        foreach ($volumes as $v) {
            if ($v['percent_used'] !== null && $v['percent_used'] >= EndpointInsight::LOW_DISK_PERCENT_USED) {
                return true;
            }
            if ($v['free_gb'] !== null && $v['free_gb'] < EndpointInsight::LOW_DISK_FREE_GB) {
                return true;
            }
        }

        return false;
    }

    private function computeLongOffline(TacticalAsset $ta): bool
    {
        if (! $ta->last_seen_at) {
            return false;
        }

        return $ta->last_seen_at->lt(now()->subDays(EndpointInsight::LONG_OFFLINE_AFTER_DAYS));
    }

    private function computeStale(?\Illuminate\Support\Carbon $freshAsOf, SignalState $statusState): bool
    {
        // A live-refreshed signal is never stale.
        if ($statusState === SignalState::Live || $freshAsOf === null) {
            return false;
        }

        return $freshAsOf->lt(now()->subMinutes(EndpointInsight::STALE_AFTER_MINUTES));
    }

    private function snapshotUptime(TacticalAsset $ta): ?string
    {
        // The snapshot doesn't persist boot_time; uptime is a live-only signal.
        // Left null on the snapshot path (the live detail read could fill it, but
        // the cheap-signal contract is status/checks — keep uptime null here to
        // avoid implying a freshness we don't have).
        return null;
    }

    private function pendingPatchCount(TacticalAsset $ta): ?int
    {
        // The AI-facing member is a COUNT, not a list (§11.3). The snapshot carries
        // only a has_patches_pending BOOLEAN (surfaced separately as
        // hasPendingPatches); the PRECISE count is a live/panel read (chunk 2).
        // Return null (unknown) here rather than fabricating "1" from the boolean —
        // a box 47 behind must not serialize to the P5 snapshot as "1 pending".
        // Mirrors the checksFailing-null precedent (Unavailable ≠ "0 clean").
        return null;
    }

    private function openAlertCount(Asset $asset): int
    {
        return Alert::where('asset_id', $asset->id)->open()->count();
    }

    /**
     * @return array<int, array{title: string, severity: ?string, source: ?string}>
     */
    private function openAlertList(Asset $asset): array
    {
        return Alert::where('asset_id', $asset->id)
            ->open()
            ->latest('fired_at')
            ->limit(self::OPEN_ALERT_LIST_LIMIT)
            ->get()
            ->map(fn (Alert $a) => [
                'title' => $a->title,
                'severity' => $a->severity?->value,
                'source' => $a->source?->value,
            ])
            ->all();
    }

    /**
     * Recent Tactical actions for the asset, newest first, capped. These rows
     * were already redacted at write (P2/P3) — no re-leak here.
     *
     * @return array<int, array{action: string, actor: string, result_status: string, ticket_id: ?int, when: ?string}>
     */
    private function recentActions(Asset $asset): array
    {
        return TacticalActionLog::where('asset_id', $asset->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id') // stable newest-first when timestamps tie
            ->limit(self::RECENT_ACTIONS_LIMIT)
            ->get()
            ->map(fn (TacticalActionLog $log) => [
                'action' => $log->action_key,
                'actor' => $log->actor_label,
                'result_status' => $log->result_status,
                'ticket_id' => $log->ticket_id,
                'when' => $log->created_at?->toDateTimeString(),
            ])
            ->all();
    }
}
