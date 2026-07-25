<?php

namespace App\Services\Comet;

use App\Enums\AlertSource;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\Client;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Support\CometConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Comet Backup read tools for the staff MCP surface (psa-z30dv).
 *
 * Closes the agent's backup blind spot for Comet-protected clients: is backup
 * configured on this client's devices, did each device's last backup run, did
 * that run SUCCEED, and how fresh is every part of that answer — without a
 * human opening the Comet console. Read-only; any backup write/trigger surface
 * is a separate bead and a separate operator decision.
 *
 * TWO DATA PLANES, EACH WITH ITS OWN FRESHNESS (canonical psa-47vxh idiom):
 *  - SYNCED columns (comet_username/comet_device_id/comet_backup_enabled,
 *    backup_*_bytes, backup_synced_at) land daily via CometBackupSyncService.
 *    The payload carries the canonical trio: data_as_of = the OLDEST
 *    trustworthy sync stamp across the fleet (the freshest the whole reading
 *    can honestly claim), data_stale = true when that is beyond the threshold
 *    OR any device's stamp is unknown (missing or FUTURE-dated — a future
 *    stamp is bad data, never fresh), and an always-present freshness_note.
 *    Every row is self-describing (synced_at + stale).
 *  - LIVE job history comes from the Comet server API at call time
 *    (jobs_checked_at = the oldest real fetch time backing the answer; a
 *    short-lived cache bounds vendor request volume, and a cache-served answer
 *    never claims to be fresher than its fetch). A failed lookup NEVER
 *    degrades to an empty job list — a confident "no failures" an agent cannot
 *    tell from a real all-clear is the exact false-clear CLAUDE.md's
 *    vendor-shape rules exist to prevent. Affected devices scream
 *    job_state=unavailable instead, and an unrecognised vendor status code is
 *    a first-class last_backup_unknown — never silently counted as failed,
 *    never as passing.
 *
 * VENDOR SHAPE — read from the SDK source, not guessed (CLAUDE.md rule):
 * job objects are \Comet\BackupJobDetail (vendor/cometbackup/comet-php-sdk/
 * Comet/BackupJobDetail.php): Username, DeviceID, Classification, Status,
 * StartTime/EndTime (unix seconds; EndTime 0 while running), TotalSize,
 * UploadSize, TotalFiles. There is NO FileErrors property on the SDK object.
 * Classifications and statuses are RANGES per Comet/Def.php:610-841 —
 * backup = JOB_CLASSIFICATION_BACKUP (4001), success = 5000-5999, running =
 * 6000-6999, failed = 7000-7999 (timeout/warning/error/quota/missed-schedule/
 * cancelled/skipped/abandoned). Do not copy the legacy exact-match constants
 * that CometJobService/CometAlertService used historically — they predate this
 * reading of the vendor source (corrected separately under psa-enpew).
 *
 * DATA BOUNDARY: the Comet admin API is server-wide (AdminGetJobsForUser has
 * no per-client scoping), so OUR scoping IS the boundary — usernames are taken
 * only from the resolved client's synced asset rows, and returned jobs are
 * kept only when their DeviceID matches one of that client's registered
 * devices. Scope resolves from clients.comet_group_id via the PSA client row,
 * never from tool input. Vendor account identifiers stay minimized: Comet
 * usernames and the group id are used internally but never echoed into the
 * payload or logs.
 */
class CometReadOnlyToolset
{
    private const CLIENT_TOOL_NAMES = [
        'comet_get_backup_posture',
        'comet_list_backup_jobs',
    ];

    /**
     * Synced data older than this is flagged stale. The backup sync runs daily,
     * so >48h means at least one full cycle has been missed — the same
     * threshold the Zorus/CIPP surfaces use.
     */
    private const STALE_AFTER_HOURS = 48;

    /**
     * Live job lookups are one API call per distinct backup username. Clients
     * normally run one or two usernames; this cap bounds the pathological case
     * and is reported loudly when hit (affected devices become not_queried,
     * never a silent skip).
     */
    private const MAX_USERNAME_LOOKUPS = 25;

    /**
     * After this many CONSECUTIVE failed username lookups the remaining
     * usernames are not attempted (their devices read not_queried, loudly) —
     * a dark Comet server must cost two bounded timeouts, not 25 of them.
     */
    private const BREAK_AFTER_CONSECUTIVE_FAILURES = 2;

    /** Live results are cached briefly so an agent loop cannot fan out into unbounded vendor request volume. */
    private const JOBS_CACHE_SECONDS = 60;

    /** Failures are cached even shorter — loud unknowns, but no re-hammering a struggling server. */
    private const JOBS_FAILURE_CACHE_SECONDS = 30;

    private const MAX_DEVICE_ROWS = 100;

    private const MAX_JOB_ROWS = 50;

    private const MAX_ALERT_ROWS = 10;

    /** Canonical always-present freshness_note (psa-47vxh idiom). */
    private const SYNCED_FRESHNESS_NOTE = 'data_as_of/data_stale cover the SYNCED columns (registration, enabled flags, storage bytes — refreshed by the daily Comet backup sync): data_as_of is the OLDEST trustworthy sync stamp across the fleet, and data_stale is true when any device\'s stamp is missing, future-dated, or older than '.self::STALE_AFTER_HOURS.'h (each row carries its own synced_at + stale). Job outcomes are LIVE from the Comet server as of jobs_checked_at (the oldest fetch time backing this answer; null when no job lookup ran).';

    public function __construct(
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return self::clientDefinitions();
    }

    /** @return array<int, array<string, mixed>> */
    public static function clientDefinitions(): array
    {
        return [
            [
                'name' => 'comet_get_backup_posture',
                'description' => "Get a PSA client's Comet Backup posture, per device: whether backup is enabled and the device is registered with the Comet server, the LIVE outcome and time of each device's most recent backup job (succeeded / failed / running / unknown / no jobs observed), days since last success, storage usage, active backup-failure alerts, and how fresh every part of the answer is. Start here for any 'are this client's backups OK' question. Devices whose job history could not be fetched are reported as unavailable, and an unrecognised vendor status code is reported as last_backup_unknown — treat both as UNKNOWN, never as passing and never as a confirmed failure; absence of a failure here is not evidence of success.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID. The client must be mapped to a Comet organization (group).'],
                    ],
                    'required' => ['client_id'],
                ],
            ],
            [
                'name' => 'comet_list_backup_jobs',
                'description' => "List one device's recent Comet backup job history, LIVE from the Comet server: per-job classification (backup / restore / retention), outcome with failure subtype (error, timeout, quota, missed schedule, cancelled), start/end times, duration and sizes — plus the device's last successful and last failed backup across all time. Use after comet_get_backup_posture to dig into why a specific device's backups are failing. The device is matched by exact hostname among the client's Comet-registered assets.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'PSA client ID. The client must be mapped to a Comet organization (group).'],
                        'hostname' => ['type' => 'string', 'description' => 'Device hostname (exact, case-insensitive) of a Comet-registered asset for this client.'],
                        'days' => ['type' => 'integer', 'description' => 'Days of job history to list (default 7, max 90). Last success/failure are computed across all available history regardless.'],
                    ],
                    'required' => ['client_id', 'hostname'],
                ],
            ],
        ];
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::CLIENT_TOOL_NAMES, true);
    }

    public static function requiresClient(string $toolName): bool
    {
        return in_array($toolName, self::CLIENT_TOOL_NAMES, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $input, ?int $clientId = null): array
    {
        // OFF=OFF: for Comet, configured IS the master switch (CometConfig::isEnabled()
        // = isConfigured(); there is no separate toggle) — unconfigured means withdrawn.
        if (! CometConfig::isEnabled()) {
            return ['error' => 'Comet Backup is not available in this deployment — no Comet server URL and admin credentials are configured.'];
        }

        return match ($toolName) {
            'comet_get_backup_posture' => $this->getBackupPosture($input, $clientId),
            'comet_list_backup_jobs' => $this->listBackupJobs($input, $clientId),
            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getBackupPosture(array $input, ?int $clientId): array
    {
        $client = $this->resolveMappedClient($input, $clientId);
        if (is_array($client)) {
            return $client; // error payload
        }

        $devices = $this->deviceQuery($client)
            ->orderByRaw("LOWER(COALESCE(hostname, ''))")
            ->get([
                'id', 'hostname', 'name', 'comet_username', 'comet_device_id',
                'comet_backup_enabled', 'backup_cloud_bytes', 'backup_local_bytes',
                'backup_synced_at',
            ]);

        $result = $this->header($client);
        $result = array_merge($result, $this->syncedFreshness($devices));
        $result['device_count'] = $devices->count();

        if ($devices->isEmpty()) {
            $result['note'] = "{$client->name} is mapped to a Comet group but no PSA assets carry Comet backup state (no device is registered or flagged backup-enabled). "
                .'Possible causes: the daily Comet backup sync has not run yet, no Comet devices report under this group, or Comet devices did not match any PSA asset by hostname. Verify in the Comet console before treating this as no coverage.';
            $result['active_backup_alerts'] = $this->activeAlerts($client);

            return $result;
        }

        $registered = $devices->filter(fn (Asset $asset): bool => ! empty($asset->comet_device_id));

        // LIVE job lookup: one AdminGetJobsForUser per distinct username, jobs
        // partitioned per device afterwards. Deterministic order + a loud cap.
        $usernames = $registered->pluck('comet_username')
            ->filter(fn ($username): bool => is_string($username) && trim($username) !== '')
            ->unique()->sort()->values();
        $queriedUsernames = $usernames->take(self::MAX_USERNAME_LOOKUPS);

        $lookup = $this->fetchBackupJobsByDevice($queriedUsernames->all(), backupOnly: true);

        $rows = [];
        $counts = [
            'last_backup_succeeded' => 0, 'last_backup_failed' => 0, 'last_backup_running' => 0,
            'last_backup_unknown' => 0, 'no_backup_jobs_observed' => 0, 'job_data_unavailable' => 0,
            'not_queried' => 0, 'pending_registration' => 0,
        ];

        // Counts cover the WHOLE fleet; only the emitted rows are capped —
        // a truncated listing must not shrink the summary.
        foreach ($devices as $asset) {
            $syncedAt = $this->trustworthyPastTime($asset->backup_synced_at);
            $row = [
                'asset_id' => $asset->id,
                'hostname' => $asset->hostname,
                'asset_name' => $asset->name,
                'backup_enabled_flag' => (bool) $asset->comet_backup_enabled,
                'registered' => ! empty($asset->comet_device_id),
                'cloud_bytes' => $asset->backup_cloud_bytes,
                'local_bytes' => $asset->backup_local_bytes,
                'synced_at' => $syncedAt?->toIso8601ZuluString(),
                'stale' => $this->isStale($syncedAt),
            ];

            if (empty($asset->comet_device_id)) {
                // Enable was staged (or sync cleared the link) but no agent reports
                // under this hostname — "configured but not running" is a finding.
                $row['job_state'] = 'pending_registration';
                $row['job_state_note'] = 'Backup is flagged enabled but the device is not registered with the Comet server — no jobs can exist yet. Check agent deployment.';
                $counts['pending_registration']++;
            } elseif (! is_string($asset->comet_username) || trim($asset->comet_username) === '') {
                $row['job_state'] = 'unavailable';
                $row['job_state_note'] = 'Device is registered but carries no synced Comet username, so its job history cannot be looked up. Re-run the Comet backup sync.';
                $counts['job_data_unavailable']++;
            } elseif (in_array($asset->comet_username, $lookup['failed'], true)) {
                $row['job_state'] = 'unavailable';
                $counts['job_data_unavailable']++;
            } elseif (! $queriedUsernames->contains($asset->comet_username) || in_array($asset->comet_username, $lookup['skipped'], true)) {
                $row['job_state'] = 'not_queried';
                $counts['not_queried']++;
            } else {
                $row = array_merge($row, $this->devicePosture($lookup['jobs_by_device'][$asset->comet_device_id] ?? []));
                $counts[$row['job_state']]++;
            }

            if (count($rows) < self::MAX_DEVICE_ROWS) {
                $rows[] = $row;
            }
        }

        $result['devices'] = $rows;
        $result['devices_truncated'] = $devices->count() > count($rows);
        $result['summary'] = array_merge(['devices_total' => $devices->count(), 'registered' => $registered->count()], $counts);
        $result['storage_totals'] = [
            'cloud_bytes' => (int) $registered->sum(fn (Asset $asset): int => (int) $asset->backup_cloud_bytes),
            'local_bytes' => (int) $registered->sum(fn (Asset $asset): int => (int) $asset->backup_local_bytes),
        ];

        $result['jobs_checked_at'] = $lookup['oldest_fetched_at'];
        if ($usernames->count() > $queriedUsernames->count()) {
            $result['job_lookup_truncated'] = true;
            $result['job_lookup_note'] = 'This client has '.$usernames->count().' distinct Comet usernames; only the first '.self::MAX_USERNAME_LOOKUPS
                .' were queried. Devices under the rest are marked not_queried — their backup state is UNKNOWN, not passing.';
        }
        if ($lookup['failed'] !== []) {
            $result['job_lookup_failed_count'] = count($lookup['failed']);
            $result['job_lookup_failure_note'] = 'Job history could not be fetched for '.count($lookup['failed'])
                .' Comet backup account(s). Devices under them are marked unavailable — their backup state is UNKNOWN, not passing. Check the Comet server and retry, or verify in the Comet console.';
        }
        if ($lookup['skipped'] !== []) {
            $result['job_lookup_skipped_count'] = count($lookup['skipped']);
            $result['job_lookup_skipped_note'] = 'After '.self::BREAK_AFTER_CONSECUTIVE_FAILURES
                .' consecutive lookup failures the remaining '.count($lookup['skipped'])
                .' backup account(s) were not attempted (the Comet server appears unreachable). Their devices are marked not_queried — UNKNOWN, not passing.';
        }

        $result['active_backup_alerts'] = $this->activeAlerts($client);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function listBackupJobs(array $input, ?int $clientId): array
    {
        $client = $this->resolveMappedClient($input, $clientId);
        if (is_array($client)) {
            return $client;
        }

        $hostname = trim((string) ($input['hostname'] ?? ''));
        if ($hostname === '') {
            return ['error' => 'hostname is required'];
        }

        $days = min($this->positiveInt($input['days'] ?? null) ?? 7, 90);

        $asset = $this->deviceQuery($client)
            ->whereNotNull('comet_device_id')
            ->whereRaw('LOWER(hostname) = ?', [mb_strtolower($hostname)])
            ->first();

        if (! $asset) {
            return ['error' => $this->hostnameMissError($client, $hostname)];
        }

        if (! is_string($asset->comet_username) || trim($asset->comet_username) === '') {
            return ['error' => "'{$asset->hostname}' is registered with Comet but carries no synced Comet username, so its job history cannot be looked up. Re-run the Comet backup sync."];
        }

        $fetch = $this->fetchJobsForUsername($asset->comet_username);
        if ($fetch['status'] !== 'ok') {
            // Never degrade a failed lookup into an empty job list — an agent
            // cannot tell that apart from "no jobs", which reads as all-clear.
            return ['error' => "Comet job lookup failed for '{$asset->hostname}' — job history is UNAVAILABLE, not empty. Treat this device's backup state as unknown and verify in the Comet console."];
        }

        $jobs = collect($fetch['jobs'])
            ->filter(fn (array $job): bool => $job['device_id'] === $asset->comet_device_id)
            ->sortByDesc(fn (array $job): int => $job['start_ts'])
            ->values();

        $backupJobs = $jobs->filter(fn (array $job): bool => $job['is_backup']);
        $lastSuccess = $backupJobs->first(fn (array $job): bool => $job['category'] === 'success');
        $lastFailure = $backupJobs->first(fn (array $job): bool => $job['category'] === 'failed');

        $cutoff = now()->subDays($days)->timestamp;
        $recent = $jobs->filter(fn (array $job): bool => $job['start_ts'] >= $cutoff);

        $result = $this->header($client);
        $result['hostname'] = $asset->hostname;
        $result['asset_id'] = $asset->id;
        $result['jobs_checked_at'] = $fetch['fetched_at'];
        $result['days'] = $days;
        $result['job_count'] = $recent->count();
        $result['truncated'] = $recent->count() > self::MAX_JOB_ROWS;
        $result['jobs'] = $recent->take(self::MAX_JOB_ROWS)
            ->map(fn (array $job): array => $this->jobRow($job))
            ->values()->all();
        $result['last_backup_success'] = $lastSuccess !== null ? $this->jobRow($lastSuccess) : null;
        $result['last_backup_failure'] = $lastFailure !== null ? $this->jobRow($lastFailure) : null;

        if ($recent->isEmpty()) {
            $result['note'] = $backupJobs->isEmpty()
                ? "The Comet server returned no backup jobs at all for '{$asset->hostname}'. Either backups have never run on this device or history has been pruned — treat its backup state as unknown, not passing."
                : "No jobs in the last {$days} days for '{$asset->hostname}'. The most recent backup activity is older — see last_backup_success / last_backup_failure.";
        }

        return $result;
    }

    // ── live job fetch + classification (vendor shape: see class docblock) ─────

    /**
     * One username's normalized job rows, briefly cached (JOBS_CACHE_SECONDS)
     * so an agent loop cannot fan out into unbounded AdminGetJobsForUser
     * volume; failures are cached shorter (JOBS_FAILURE_CACHE_SECONDS) — still
     * loud, no re-hammering. fetched_at is stamped at real fetch time so a
     * cache-served answer never claims to be fresher than it is. Logs are
     * redacted: exception class + code only — no vendor message (may embed the
     * server URL or response text) and no username (vendor account
     * identifier).
     *
     * @return array{status: 'ok'|'failed', jobs?: array<int, array<string, mixed>>, fetched_at: string}
     */
    private function fetchJobsForUsername(string $username): array
    {
        $key = 'comet_reads:jobs:'.sha1($username);
        $cached = Cache::get($key);
        if (is_array($cached) && isset($cached['status'])) {
            return $cached;
        }

        try {
            $jobs = app(CometClient::class)->getJobsForUser($username);
        } catch (\Throwable $e) {
            Log::warning('[Comet reads] job lookup failed', ['exception' => $e::class, 'code' => $e->getCode()]);
            $result = ['status' => 'failed', 'fetched_at' => now()->toIso8601ZuluString()];
            Cache::put($key, $result, self::JOBS_FAILURE_CACHE_SECONDS);

            return $result;
        }

        $normalized = [];
        foreach ($jobs as $job) {
            $deviceId = (string) ($job->DeviceID ?? '');
            if ($deviceId === '') {
                continue;
            }

            $classification = (int) ($job->Classification ?? 0);
            $status = (int) ($job->Status ?? 0);
            $normalized[] = [
                'device_id' => $deviceId,
                'classification' => $classification,
                'is_backup' => $classification === \Comet\Def::JOB_CLASSIFICATION_BACKUP,
                'status' => $status,
                'category' => $this->statusCategory($status),
                'start_ts' => (int) ($job->StartTime ?? 0),
                'end_ts' => (int) ($job->EndTime ?? 0),
                'total_size' => (int) ($job->TotalSize ?? 0),
                'upload_size' => (int) ($job->UploadSize ?? 0),
                'total_files' => (int) ($job->TotalFiles ?? 0),
            ];
        }

        $result = ['status' => 'ok', 'jobs' => $normalized, 'fetched_at' => now()->toIso8601ZuluString()];
        Cache::put($key, $result, self::JOBS_CACHE_SECONDS);

        return $result;
    }

    /**
     * Fetch jobs for the given usernames and partition them per DeviceID.
     * A username whose fetch fails lands in `failed` — callers must surface
     * that loudly, never as an empty list. After BREAK_AFTER_CONSECUTIVE_FAILURES
     * consecutive failures the rest are `skipped` without an attempt (a dark
     * server costs two bounded timeouts, not 25). oldest_fetched_at is the
     * oldest real fetch time backing the answer — the freshest the whole
     * reading can honestly claim under caching.
     *
     * @param  array<int, string>  $usernames
     * @return array{jobs_by_device: array<string, array<int, array<string, mixed>>>, failed: array<int, string>, skipped: array<int, string>, oldest_fetched_at: ?string}
     */
    private function fetchBackupJobsByDevice(array $usernames, bool $backupOnly): array
    {
        $jobsByDevice = [];
        $failed = [];
        $skipped = [];
        $oldestFetchedAt = null;
        $consecutiveFailures = 0;

        foreach ($usernames as $username) {
            if ($consecutiveFailures >= self::BREAK_AFTER_CONSECUTIVE_FAILURES) {
                $skipped[] = $username;

                continue;
            }

            $fetch = $this->fetchJobsForUsername($username);
            // ISO-8601 Zulu strings compare chronologically as strings.
            if ($oldestFetchedAt === null || $fetch['fetched_at'] < $oldestFetchedAt) {
                $oldestFetchedAt = $fetch['fetched_at'];
            }

            if ($fetch['status'] !== 'ok') {
                $failed[] = $username;
                $consecutiveFailures++;

                continue;
            }
            $consecutiveFailures = 0;

            foreach ($fetch['jobs'] as $job) {
                if ($backupOnly && ! $job['is_backup']) {
                    // Posture is about BACKUP outcomes; a successful retention or
                    // restore pass must not read as "backup succeeded".
                    continue;
                }
                $jobsByDevice[$job['device_id']][] = $job;
            }
        }

        return ['jobs_by_device' => $jobsByDevice, 'failed' => $failed, 'skipped' => $skipped, 'oldest_fetched_at' => $oldestFetchedAt];
    }

    /**
     * Derive one registered device's posture from its backup-classification jobs.
     *
     * @param  array<int, array<string, mixed>>  $jobs
     * @return array<string, mixed>
     */
    private function devicePosture(array $jobs): array
    {
        if ($jobs === []) {
            return [
                'job_state' => 'no_backup_jobs_observed',
                'job_state_note' => 'The Comet server returned no backup jobs for this device — backups may never have run. Unknown is not passing.',
            ];
        }

        $sorted = collect($jobs)->sortByDesc(fn (array $job): int => $job['start_ts'])->values();
        $last = $sorted->first();
        $lastSuccess = $sorted->first(fn (array $job): bool => $job['category'] === 'success');
        $lastFailure = $sorted->first(fn (array $job): bool => $job['category'] === 'failed');

        $daysSinceSuccess = null;
        if ($lastSuccess !== null) {
            // Sign-safe (psa-lqlu): past->diffInDays(now()) is positive.
            $daysSinceSuccess = (int) $this->timestamp($lastSuccess['start_ts'])?->diffInDays(now());
        }

        $posture = [
            // UNKNOWN is a first-class outcome: an unrecognised vendor status
            // code must not be asserted as a failure the vendor never reported
            // (and never as passing) — the agent relays it as unknown.
            'job_state' => match ($last['category']) {
                'success' => 'last_backup_succeeded',
                'failed' => 'last_backup_failed',
                'running' => 'last_backup_running',
                default => 'last_backup_unknown',
            },
            'last_backup_at' => $this->timestamp($last['start_ts'])?->toIso8601ZuluString(),
            'last_backup_status' => $this->statusLabel($last['status']),
            'last_backup_status_code' => $last['status'],
            'last_backup_success_at' => $lastSuccess !== null ? $this->timestamp($lastSuccess['start_ts'])?->toIso8601ZuluString() : null,
            'last_backup_failure_at' => $lastFailure !== null ? $this->timestamp($lastFailure['start_ts'])?->toIso8601ZuluString() : null,
            'days_since_last_success' => $daysSinceSuccess,
        ];

        if ($posture['job_state'] === 'last_backup_unknown') {
            $posture['job_state_note'] = 'The most recent backup job reports a status code this integration does not recognise — its outcome is UNKNOWN: not passing, and not a confirmed failure either. Verify this device in the Comet console.';
        }

        return $posture;
    }

    /** @param array<string, mixed> $job */
    private function jobRow(array $job): array
    {
        $start = $this->timestamp($job['start_ts']);
        $end = $this->timestamp($job['end_ts']);

        return [
            'classification' => $this->classificationLabel($job['classification']),
            'status' => $this->statusLabel($job['status']),
            'status_code' => $job['status'],
            'category' => $job['category'],
            'started_at' => $start?->toIso8601ZuluString(),
            'ended_at' => $end?->toIso8601ZuluString(),
            'duration_seconds' => ($start !== null && $end !== null) ? max(0, $job['end_ts'] - $job['start_ts']) : null,
            'total_size_bytes' => $job['total_size'],
            'upload_size_bytes' => $job['upload_size'],
            'total_files' => $job['total_files'],
        ];
    }

    /**
     * Status RANGES per vendor Comet/Def.php:708-841. An unrecognised code maps
     * to 'unknown', which surfaces as a first-class last_backup_unknown state —
     * never success, never an asserted failure.
     */
    private function statusCategory(int $status): string
    {
        return match (true) {
            $status >= \Comet\Def::JOB_STATUS_STOP_SUCCESS__MIN && $status <= \Comet\Def::JOB_STATUS_STOP_SUCCESS__MAX => 'success',
            $status >= \Comet\Def::JOB_STATUS_RUNNING__MIN && $status <= \Comet\Def::JOB_STATUS_RUNNING__MAX => 'running',
            $status >= \Comet\Def::JOB_STATUS_FAILED__MIN && $status <= \Comet\Def::JOB_STATUS_FAILED__MAX => 'failed',
            default => 'unknown',
        };
    }

    private function statusLabel(int $status): string
    {
        return match (true) {
            $status === \Comet\Def::JOB_STATUS_STOP_SUCCESS => 'Success',
            $status === \Comet\Def::JOB_STATUS_FAILED_TIMEOUT => 'Failed (timeout)',
            $status === \Comet\Def::JOB_STATUS_FAILED_WARNING => 'Completed with warnings',
            $status === \Comet\Def::JOB_STATUS_FAILED_ERROR => 'Failed (error)',
            $status === \Comet\Def::JOB_STATUS_FAILED_QUOTA => 'Failed (quota exceeded)',
            $status === \Comet\Def::JOB_STATUS_FAILED_SCHEDULEMISSED => 'Failed (missed schedule)',
            $status === \Comet\Def::JOB_STATUS_FAILED_CANCELLED => 'Cancelled',
            $status === \Comet\Def::JOB_STATUS_FAILED_SKIPALREADYRUNNING => 'Skipped (already running)',
            $status === \Comet\Def::JOB_STATUS_FAILED_ABANDONED => 'Abandoned',
            default => match ($this->statusCategory($status)) {
                'success' => "Success (code {$status})",
                'running' => 'Running',
                'failed' => "Failed (code {$status})",
                default => "Unknown (code {$status})",
            },
        };
    }

    /** Classification values per vendor Comet/Def.php:610-701. */
    private function classificationLabel(int $classification): string
    {
        return match ($classification) {
            \Comet\Def::JOB_CLASSIFICATION_BACKUP => 'Backup',
            \Comet\Def::JOB_CLASSIFICATION_RESTORE => 'Restore',
            \Comet\Def::JOB_CLASSIFICATION_RETENTION => 'Retention',
            \Comet\Def::JOB_CLASSIFICATION_DEEPVERIFY => 'Deep verify',
            default => "Other ({$classification})",
        };
    }

    // ── local corroboration: webhook-driven backup alerts ──────────────────────

    /**
     * Open Comet backup-failure alerts for this client (webhook-fed, resolved on
     * the next success). Corroboration only — phrased so an empty list is never
     * read as verified-good.
     *
     * @return array<string, mixed>
     */
    private function activeAlerts(Client $client): array
    {
        $alerts = Alert::where('client_id', $client->id)
            ->where('source', AlertSource::Comet)
            ->open()
            ->orderByDesc('fired_at')
            ->limit(self::MAX_ALERT_ROWS)
            ->get(['hostname', 'title', 'status', 'fired_at']);

        return [
            'count' => $alerts->count(),
            'alerts' => $alerts->map(fn (Alert $alert): array => [
                'hostname' => $alert->hostname,
                'title' => $this->textSanitizer->sanitizeNullable('Comet alert title', $alert->title, 300),
                'status' => $alert->status?->value,
                'fired_at' => $alert->fired_at?->toIso8601ZuluString(),
            ])->values()->all(),
            'note' => 'Webhook-fed failure alerts; an empty list is NOT evidence backups are healthy — use each device\'s live job_state above.',
        ];
    }

    // ── scoping helpers ────────────────────────────────────────────────────────

    /**
     * Resolve the PSA client for a tool call and prove it is Comet-mapped.
     * The vendor-scope question ("which Comet group is this?") is answered ONLY
     * by clients.comet_group_id on the resolved row — tool input picks which
     * PSA client to ask about, never which Comet group to read.
     *
     * @param  array<string, mixed>  $input
     * @return Client|array<string, mixed>
     */
    private function resolveMappedClient(array $input, ?int $clientId): Client|array
    {
        $id = $clientId ?? $this->positiveInt($input['client_id'] ?? null);
        if ($id === null) {
            return ['error' => 'client_id is required'];
        }

        $client = Client::find($id);
        if ($client === null) {
            return ['error' => "PSA client {$id} was not found."];
        }

        if (empty($client->comet_group_id)) {
            $error = "{$client->name} is not mapped to a Comet organization, so Comet backup state cannot be read for this client. "
                .'Map the client in Settings, or treat this client as not covered by Comet Backup.';

            // An unmapped client drops out of the sync loop, so leftover comet
            // columns stop being refreshed forever. Refuse rather than serve rot.
            if ($this->deviceQuery($client)->exists()) {
                $error .= ' Note: this client still carries leftover Comet backup data from a previous mapping; it is ignored because it is no longer being refreshed.';
            }

            return ['error' => $error];
        }

        return $client;
    }

    /**
     * The one query seam every read goes through: this client's rows, nothing
     * else. Registered devices (comet_device_id) plus enable-pending ones
     * (comet_backup_enabled with no registration) — both are backup posture.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Asset>
     */
    private function deviceQuery(Client $client): \Illuminate\Database\Eloquent\Builder
    {
        return Asset::where('client_id', $client->id)
            ->where(function ($query) {
                $query->whereNotNull('comet_device_id')
                    ->orWhere('comet_backup_enabled', true);
            });
    }

    private function hostnameMissError(Client $client, string $hostname): string
    {
        // A miss must disambiguate, not read as "nothing to see".
        $pending = Asset::where('client_id', $client->id)
            ->whereNull('comet_device_id')
            ->where('comet_backup_enabled', true)
            ->whereRaw('LOWER(hostname) = ?', [mb_strtolower($hostname)])
            ->exists();
        if ($pending) {
            return "'{$hostname}' has backup flagged enabled but is not registered with the Comet server, so no job history exists yet. Check agent deployment (comet_get_backup_posture shows it as pending_registration).";
        }

        $unlinked = Asset::where('client_id', $client->id)
            ->whereRaw('LOWER(hostname) = ?', [mb_strtolower($hostname)])
            ->exists();
        if ($unlinked) {
            return "'{$hostname}' exists as a PSA asset for this client but is not linked to a Comet device — either the Comet agent is not installed, it has not synced yet, or it reports to Comet under a different hostname. Its backup state is unknown, not passing.";
        }

        return "No Comet-registered asset with hostname '{$hostname}' exists for this client. Check the spelling, or call comet_get_backup_posture to see this client's Comet devices.";
    }

    // ── freshness (canonical psa-47vxh idiom) ──────────────────────────────────

    /** @return array<string, mixed> */
    private function header(Client $client): array
    {
        // Minimized on purpose: no vendor group id or usernames — PSA ids and
        // hostnames are the working identifiers on this surface.
        return [
            'psa_client_id' => $client->id,
            'psa_client_name' => $client->name,
        ];
    }

    /**
     * Freshness of the SYNCED columns: data_as_of is the OLDEST trustworthy
     * sync stamp across the fleet — the freshest the whole reading can honestly
     * claim — and data_stale is true when that is beyond the threshold OR any
     * device's stamp is unknown (missing or future-dated). freshness_note is
     * always present (canonical envelope).
     *
     * @param  \Illuminate\Support\Collection<int, Asset>  $devices
     * @return array{data_as_of: ?string, data_stale: bool, freshness_note: string}
     */
    private function syncedFreshness(\Illuminate\Support\Collection $devices): array
    {
        $oldest = null;
        $hasUnknown = $devices->isEmpty();
        foreach ($devices as $device) {
            $syncedAt = $this->trustworthyPastTime($device->backup_synced_at);
            if ($syncedAt === null) {
                $hasUnknown = true;

                continue;
            }
            if ($oldest === null || $syncedAt->lt($oldest)) {
                $oldest = $syncedAt;
            }
        }

        return [
            'data_as_of' => $oldest?->toIso8601ZuluString(),
            'data_stale' => $hasUnknown || $this->isStale($oldest),
            'freshness_note' => self::SYNCED_FRESHNESS_NOTE,
        ];
    }

    /**
     * A DB sync stamp is trustworthy only when it is a real PAST time — a
     * future-dated stamp (writer bug or clock skew) must read as unknown and
     * therefore stale, never fresh (canonical psa-47vxh rule;
     * UnifiReadOnlyToolset::parseTimestamp is the string-input sibling).
     */
    private function trustworthyPastTime(?Carbon $timestamp): ?Carbon
    {
        return ($timestamp === null || $timestamp->gt(now())) ? null : $timestamp;
    }

    private function isStale(?Carbon $timestamp): bool
    {
        return $timestamp === null || $timestamp->lt(now()->subHours(self::STALE_AFTER_HOURS));
    }

    // ── plumbing ───────────────────────────────────────────────────────────────

    private function timestamp(int $unixSeconds): ?Carbon
    {
        return $unixSeconds > 0 ? Carbon::createFromTimestamp($unixSeconds, 'UTC') : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
