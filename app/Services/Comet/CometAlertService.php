<?php

namespace App\Services\Comet;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\Setting;
use App\Services\AlertService;
use App\Support\CometConfig;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns Comet job-completion webhook events into PSA alerts.
 *
 * Code/range reading and the dual-scale backup predicate live in
 * CometJobCodes (single shared seam with CometJobService) — see its docblock
 * for the honest limit: prod zero-alert evidence proves the old pipeline
 * matched nothing, NOT which of its guards (classification scale vs exact
 * status code) was responsible. Everything here is robust to either.
 *
 * SERIES IDENTITY IS REQUIRED, NEVER DEGRADED (psa-enpew.6): one alert row
 * per device + protected item (SourceGUID) + storage vault (DestinationGUID),
 * classification-scale-free ('backup' token). A payload missing ANY identity
 * field is dropped loudly — silently falling back to a device-wide key would
 * let an unrelated success resolve a different backup's failure, which is the
 * original defect wearing a new key.
 *
 * EVENT ORDERING (psa-enpew.5/.6): Comet documents webhook delivery as
 * parallel and possibly re-ordered/retried, so state changes are ordered by
 * the job's vendor EndTime, never by arrival:
 * - an event with no orderable EndTime (missing/zero/non-integer) is dropped
 *   loudly — substituting receipt time would let an unorderable success
 *   masquerade as newest and resolve a real failure;
 * - a success resolves only when STRICTLY newer than the recorded watermark
 *   (equal = replay, rejected); a failure refires/reopens only when strictly
 *   newer; a repeated job GUID is a replay regardless of timestamp;
 * - a success that finds its series already resolved still ADVANCES the
 *   watermark, so a delayed older failure cannot reopen a series that a newer
 *   success has vouched for (no row at all = nothing to advance: watermarks
 *   exist once a series has alerted once);
 * - each series' read-decide-write runs in a transaction holding a
 *   lockForUpdate() on the series row (no-op on SQLite, real row lock on
 *   MariaDB), and the create race under the unique (source, source_alert_id)
 *   key is retried once as an update.
 *
 * Every drop path is deliberate and visible: unrouteable payloads log at
 * WARNING (silent non-matching is the exact defect this repairs) and stamp
 * comet_webhook_last_unmatched_at for the settings card; recognised
 * non-backup classifications (restore/retention/…) are routine and log at
 * DEBUG by design — warning there would flood at thousands of events/day.
 * The caller receives a CometJobEventOutcome and must report its disposition
 * honestly — never "processed" for a drop.
 */
class CometAlertService
{
    public function __construct(
        private readonly AlertService $alertService,
    ) {}

    /**
     * Route a completed job (webhook Data payload) by its vendor status range:
     * failed range raises/refreshes an alert, success range resolves one.
     * Single public entry point — the operator proof-of-life stamps derive
     * from the outcome here, so "recognized" can only advance on a real route.
     */
    public function handleJobCompleted(array $data): CometJobEventOutcome
    {
        $outcome = $this->routeJobCompleted($data);

        if ($outcome->disposition->wasRouted()) {
            Setting::setValue('comet_webhook_last_recognized_at', now()->toIso8601String());
        }
        if ($outcome->disposition->raisedAlert()) {
            Setting::setValue('comet_webhook_last_alert_at', now()->toIso8601String());
        }

        return $outcome;
    }

    /**
     * The loud unmatched-event record (psa-enpew review): WARNING with
     * bounded, safe structural fields only — no payload bodies, no customer
     * identifiers — plus the operator-card stamp, so a matcher breaking again
     * is visible on the settings screen, not only in a log nobody greps.
     * Public because the webhook controller shares it for the missing-Data
     * shape it drops before this service is reached.
     */
    public function recordUnmatchedEvent(string $reason, array $context = []): void
    {
        Log::warning('[Comet Alert] Job-completed event matched no route — dropped', ['reason' => $reason] + $context);

        Setting::setValue('comet_webhook_last_unmatched_at', now()->toIso8601String());
        Setting::setValue('comet_webhook_last_unmatched_reason', $reason);
    }

    private function routeJobCompleted(array $data): CometJobEventOutcome
    {
        $status = $data['Status'] ?? null;

        if (! is_int($status)) {
            return $this->dropUnmatched('missing_or_non_integer_status', $data);
        }

        if (CometJobCodes::isFailedStatus($status)) {
            return $this->handleJobFailure($data);
        }

        if (CometJobCodes::isSuccessStatus($status)) {
            return $this->handleJobSuccess($data);
        }

        if (CometJobCodes::isRunningStatus($status)) {
            // A running-range status inside a job-COMPLETED event is a
            // contradiction — completed jobs carry stop codes. It matches no
            // completion route, so it must be loud (psa-enpew.8), not an INFO
            // line that prod's LOG_LEVEL=warning swallows. If real traffic
            // ever proves Comet emits this pairing routinely, the unmatched
            // stamp + warning is exactly the signal that will say so.
            return $this->dropUnmatched('running_status_on_completed_event', $data);
        }

        return $this->dropUnmatched('status_outside_known_ranges', $data);
    }

    /** Dispatcher contract: only called with a verified failed-range status. */
    private function handleJobFailure(array $data): CometJobEventOutcome
    {
        if (! CometConfig::alertsEnabled()) {
            Log::debug('[Comet Alert] Alerts disabled, ignoring');

            return CometJobEventOutcome::alertsDisabled();
        }

        $nonBackup = $this->routeBackupClassification($data);
        if ($nonBackup !== null) {
            return $nonBackup;
        }

        $identity = $this->extractSeriesIdentity($data);
        if ($identity instanceof CometJobEventOutcome) {
            return $identity;
        }

        return $this->withSeriesRow(
            $identity['series_key'],
            fn () => $this->applyJobFailure($data, $identity),
        );
    }

    /** Dispatcher contract: only called with a verified success-range status. */
    private function handleJobSuccess(array $data): CometJobEventOutcome
    {
        $nonBackup = $this->routeBackupClassification($data);
        if ($nonBackup !== null) {
            return $nonBackup;
        }

        $identity = $this->extractSeriesIdentity($data);
        if ($identity instanceof CometJobEventOutcome) {
            return $identity;
        }

        return $this->withSeriesRow(
            $identity['series_key'],
            fn () => $this->applyJobSuccess($data, $identity),
        );
    }

    /**
     * Run one series' read-decide-write atomically. The closure re-reads the
     * series row under lockForUpdate(), so two parallel deliveries cannot both
     * act on the same stale watermark. When NO row exists there is nothing to
     * lock — two parallel first-failures can race the insert, the unique
     * (source, source_alert_id) key fails the loser, and one retry takes the
     * now-existing row through the locked update path instead of 500ing.
     */
    private function withSeriesRow(string $seriesKey, \Closure $apply): CometJobEventOutcome
    {
        try {
            return DB::transaction($apply);
        } catch (QueryException $e) {
            $rowNowExists = Alert::where('source', AlertSource::Comet)
                ->where('source_alert_id', $seriesKey)
                ->exists();

            if (! $rowNowExists) {
                throw $e;
            }

            return DB::transaction($apply);
        }
    }

    private function applyJobFailure(array $data, array $identity): CometJobEventOutcome
    {
        $status = $data['Status'];
        $username = $data['Username'] ?? null;
        $classification = $data['Classification'] ?? null;
        $startTime = $data['StartTime'] ?? null;
        $totalSize = $data['TotalSize'] ?? null;
        $eventTime = $identity['event_time'];

        $asset = Asset::where('comet_device_id', $identity['device_id'])->first();
        $clientId = $asset?->client_id;

        if (! $clientId) {
            Log::info('[Comet Alert] No client match for device, creating unlinked alert', [
                'username' => $username,
                'device_id' => $identity['device_id'],
            ]);
        }

        $hostname = $asset?->hostname ?? $username ?? 'Unknown';
        $jobType = CometJobCodes::classificationLabel(is_int($classification) ? $classification : null);
        $statusLabel = CometJobCodes::statusLabel($status);
        $title = mb_substr("Backup {$statusLabel} on {$hostname}", 0, 255);

        $msgLines = [
            "Device: {$hostname}",
            "Job type: {$jobType}",
            "Status: {$statusLabel}",
            "Protected item: {$identity['source_guid']}",
            "Storage vault: {$identity['destination_guid']}",
        ];
        if (is_int($startTime) && $startTime > 0) {
            $msgLines[] = 'Started: '.date('Y-m-d H:i:s', $startTime).' UTC';
        }
        $msgLines[] = 'Ended: '.date('Y-m-d H:i:s', $eventTime).' UTC';
        if (is_numeric($totalSize) && $totalSize > 0) {
            $msgLines[] = 'Total size: '.number_format($totalSize / (1024 ** 3), 2).' GB';
        }
        $message = implode("\n", $msgLines);

        $metadata = [
            'device_id' => $identity['device_id'],
            'username' => $username,
            'classification' => $classification,
            'status' => $status,
            'start_time' => $startTime,
            'end_time' => $eventTime,
            'total_size' => $totalSize,
            'source_guid' => $identity['source_guid'],
            'destination_guid' => $identity['destination_guid'],
            'last_event_time' => $eventTime,
            'last_event_guid' => $identity['guid'],
        ];

        // One row per series regardless of status (unique source + source_alert_id).
        $existing = Alert::where('source', AlertSource::Comet)
            ->where('source_alert_id', $identity['series_key'])
            ->lockForUpdate()
            ->first();

        if (! $existing) {
            $alert = Alert::create([
                'asset_id' => $asset?->id,
                'client_id' => $clientId,
                'source' => AlertSource::Comet,
                'source_alert_id' => $identity['series_key'],
                'severity' => AlertSeverity::fromVendor(AlertSource::Comet, null),
                'status' => AlertStatus::Active,
                'title' => $title,
                'message' => $message,
                'hostname' => $hostname,
                'metadata' => $metadata,
                'fired_at' => Carbon::createFromTimestamp($eventTime),
            ]);

            Log::info('[Comet Alert] Alert created', [
                'alert_id' => $alert->id,
                'source_alert_id' => $identity['series_key'],
                'hostname' => $hostname,
                'status' => $status,
                'client_id' => $clientId,
            ]);

            return CometJobEventOutcome::alertCreated($alert);
        }

        $stale = $this->staleAgainst($existing, $identity);
        if ($stale !== null) {
            // No reason is stated in the message: staleAgainst() refuses for
            // two reasons and only one of them is a time comparison, so any
            // clause about recorded state would be false on a replayed GUID
            // (#3232). stale_because carries the reason and is accurate on
            // both arms; the watermark fields it was judged against travel
            // beside it so a reader can check the refusal from the record
            // alone rather than trusting the label.
            Log::info('[Comet Alert] Stale/replayed failure event ignored', [
                'alert_id' => $existing->id,
                'source_alert_id' => $identity['series_key'],
                'event_time' => $eventTime,
                'stale_because' => $stale,
                'last_event_time' => $existing->metadata['last_event_time'] ?? null,
                'last_event_guid' => $existing->metadata['last_event_guid'] ?? null,
                'event_guid' => $identity['guid'],
            ]);

            return CometJobEventOutcome::staleIgnored($existing);
        }

        if ($existing->status === AlertStatus::Resolved) {
            // The series failed again after a resolving success: reopen the
            // row (creating would violate the unique key). The prior ack
            // belonged to the previous incident, so it is cleared.
            $existing->update([
                'status' => AlertStatus::Active,
                'resolved_at' => null,
                'acknowledged_by' => null,
                'acknowledged_at' => null,
                'title' => $title,
                'message' => $message,
                'fired_at' => Carbon::createFromTimestamp($eventTime),
                'refired_count' => $existing->refired_count + 1,
                'metadata' => array_merge($existing->metadata ?? [], $metadata),
            ]);

            Log::info('[Comet Alert] Resolved alert reopened by new failure', [
                'alert_id' => $existing->id,
                'source_alert_id' => $identity['series_key'],
                'status' => $status,
            ]);

            return CometJobEventOutcome::alertReopened($existing);
        }

        // Re-fired while open: refresh title too, so a later quota/timeout
        // failure cannot keep presenting an older outcome's title.
        $existing->update([
            'title' => $title,
            'message' => $message,
            'fired_at' => Carbon::createFromTimestamp($eventTime),
            'refired_count' => $existing->refired_count + 1,
            'metadata' => array_merge($existing->metadata ?? [], $metadata),
        ]);

        Log::info('[Comet Alert] Alert re-fired', [
            'alert_id' => $existing->id,
            'source_alert_id' => $identity['series_key'],
            'status' => $status,
            'refired_count' => $existing->refired_count,
        ]);

        return CometJobEventOutcome::alertRefired($existing);
    }

    private function applyJobSuccess(array $data, array $identity): CometJobEventOutcome
    {
        $status = $data['Status'];
        $eventTime = $identity['event_time'];

        $existing = Alert::where('source', AlertSource::Comet)
            ->where('source_alert_id', $identity['series_key'])
            ->lockForUpdate()
            ->first();

        if (! $existing) {
            // Nothing has ever alerted for this series, so there is no
            // watermark to advance and nothing to resolve.
            return CometJobEventOutcome::noOpenAlert();
        }

        $isOpen = $existing->status !== AlertStatus::Resolved;

        $stale = $this->staleAgainst($existing, $identity);
        if ($stale !== null) {
            if ($isOpen) {
                // The mirror image of the original defect: a stale/replayed
                // success must never present a broken backup as recovered.
                // Reason deliberately unstated in the message — see
                // staleAgainst() and #3232 — and carried in stale_because with
                // its comparands, because a replayed GUID is refused without
                // any timestamp comparison and can postdate the failure.
                Log::warning('[Comet Alert] Stale/replayed success rejected, alert stays open', [
                    'alert_id' => $existing->id,
                    'source_alert_id' => $identity['series_key'],
                    'event_time' => $eventTime,
                    'stale_because' => $stale,
                    'last_event_time' => $existing->metadata['last_event_time'] ?? null,
                    'last_event_guid' => $existing->metadata['last_event_guid'] ?? null,
                    'event_guid' => $identity['guid'],
                ]);
            } else {
                Log::info('[Comet Alert] Stale/replayed success ignored', [
                    'alert_id' => $existing->id,
                    'source_alert_id' => $identity['series_key'],
                    'event_time' => $eventTime,
                    'stale_because' => $stale,
                    'last_event_time' => $existing->metadata['last_event_time'] ?? null,
                    'last_event_guid' => $existing->metadata['last_event_guid'] ?? null,
                    'event_guid' => $identity['guid'],
                ]);
            }

            return CometJobEventOutcome::staleIgnored($existing);
        }

        $watermark = [
            'last_event_time' => $eventTime,
            'last_event_guid' => $identity['guid'],
        ];
        if ($isOpen) {
            $watermark['resolved_by_status'] = $status;
            $watermark['resolved_by_end_time'] = $eventTime;
        }

        $existing->update([
            'metadata' => array_merge($existing->metadata ?? [], $watermark),
        ]);

        if (! $isOpen) {
            // Already resolved — but the watermark above still advanced, so a
            // delayed older failure arriving after this success is provably
            // stale instead of reopening a series this success vouched for.
            Log::debug('[Comet Alert] Success for an already-resolved series — watermark advanced', [
                'alert_id' => $existing->id,
                'source_alert_id' => $identity['series_key'],
                'event_time' => $eventTime,
            ]);

            return CometJobEventOutcome::noOpenAlert();
        }

        $this->alertService->resolve($existing, 'Backup completed successfully.');

        Log::info('[Comet Alert] Alert resolved on job success', [
            'alert_id' => $existing->id,
            'source_alert_id' => $identity['series_key'],
        ]);

        return CometJobEventOutcome::alertResolved($existing);
    }

    /**
     * Replay/ordering guard against the series row's recorded watermark.
     * Returns the reason a strictly-newer requirement failed, or null when
     * the event is genuinely newer and may act. Equal timestamps are replays
     * (one series never completes twice in the same second — Comet skips an
     * already-running job), and a repeated job GUID is a replay regardless of
     * what its timestamp claims.
     */
    private function staleAgainst(Alert $existing, array $identity): ?string
    {
        $lastEventGuid = $existing->metadata['last_event_guid'] ?? null;
        if ($identity['guid'] !== null && $identity['guid'] === $lastEventGuid) {
            return 'same_job_guid';
        }

        $lastEventTime = (int) ($existing->metadata['last_event_time'] ?? 0);
        if ($identity['event_time'] <= $lastEventTime) {
            return 'not_strictly_newer';
        }

        return null;
    }

    /**
     * True when the payload's classification is backup (either scale) — the
     * caller proceeds. Recognised non-backup classifications are deliberately
     * ignored at DEBUG (retention/restore completions are routine traffic);
     * an unrecognised value is a WARNING — it means our reading of the vendor
     * codes has drifted, which must never be silent.
     */
    private function routeBackupClassification(array $data): ?CometJobEventOutcome
    {
        $classification = $data['Classification'] ?? null;

        if (CometJobCodes::isBackupClassification($classification)) {
            return null;
        }

        if (CometJobCodes::isKnownNonBackupClassification($classification)) {
            Log::debug('[Comet Alert] Non-backup classification, deliberately ignoring', [
                'classification' => $classification,
            ]);

            return CometJobEventOutcome::ignoredNonBackup();
        }

        return $this->dropUnmatched('unrecognized_classification', $data);
    }

    /**
     * The full series identity + orderable event time, or a loud drop.
     *
     * SourceGUID and DestinationGUID are REQUIRED alongside DeviceID
     * (psa-enpew.6): coercing a missing one to '' would silently degrade the
     * series key to device-wide and let an unrelated success resolve a
     * different backup's failure. EndTime must be a positive integer —
     * substituting receipt time would make an unorderable event look newest.
     *
     * @return array{device_id: string, source_guid: string, destination_guid: string, series_key: string, event_time: int, guid: ?string}|CometJobEventOutcome
     */
    private function extractSeriesIdentity(array $data): array|CometJobEventOutcome
    {
        $deviceId = $data['DeviceID'] ?? null;
        if (! is_string($deviceId) || trim($deviceId) === '') {
            return $this->dropUnmatched('missing_device_identity', $data);
        }

        $sourceGuid = $data['SourceGUID'] ?? null;
        $destinationGuid = $data['DestinationGUID'] ?? null;
        if (! is_string($sourceGuid) || trim($sourceGuid) === ''
            || ! is_string($destinationGuid) || trim($destinationGuid) === '') {
            return $this->dropUnmatched('missing_series_identity', $data);
        }

        $endTime = $data['EndTime'] ?? null;
        if (! is_int($endTime) || $endTime <= 0) {
            return $this->dropUnmatched('unorderable_end_time', $data);
        }

        $guid = $data['GUID'] ?? null;

        return [
            'device_id' => $deviceId,
            'source_guid' => $sourceGuid,
            'destination_guid' => $destinationGuid,
            'series_key' => CometJobCodes::backupSeriesKey($deviceId, $sourceGuid, $destinationGuid),
            'event_time' => $endTime,
            'guid' => (is_string($guid) && $guid !== '') ? $guid : null,
        ];
    }

    private function dropUnmatched(string $reason, array $data): CometJobEventOutcome
    {
        $classification = $data['Classification'] ?? null;
        $status = $data['Status'] ?? null;

        $this->recordUnmatchedEvent($reason, [
            'classification' => is_scalar($classification) ? $classification : gettype($classification),
            'status' => is_scalar($status) ? $status : gettype($status),
            'has_device_id' => is_string($data['DeviceID'] ?? null) && trim($data['DeviceID']) !== '',
            'has_source_guid' => is_string($data['SourceGUID'] ?? null) && trim($data['SourceGUID']) !== '',
            'has_destination_guid' => is_string($data['DestinationGUID'] ?? null) && trim($data['DestinationGUID']) !== '',
        ]);

        return CometJobEventOutcome::droppedUnmatched($reason);
    }
}
