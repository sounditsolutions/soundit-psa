<?php

namespace App\Services\Tactical;

use App\Enums\TechnicianRunState;
use App\Jobs\SweepQueuedActionsForAgent;
use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalAsset;
use App\Models\TechnicianRun;
use App\Services\Assets\AssetWatchEvaluator;
use App\Services\AssetSources\SourceSyncPipeline;
use App\Services\SyncResult;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TacticalDeviceSyncService
{
    /** Per-request timeout for the on-demand detail read (~3s, §11.5). */
    public const DETAIL_TIMEOUT_SECONDS = 3;

    /**
     * Smallest epoch we will believe as a real boot time: 2001-09-09.
     *
     * Below this a value is a sentinel or a garbled read (0, 1, -1, a truncated
     * epoch), not a machine that has been up since 1970. No PSA-managed device
     * booted before this and never rebooted.
     */
    private const BOOT_TIME_EPOCH_FLOOR = 1_000_000_000;

    /**
     * How many retired ASSETS are itemised in details['assets_skipped_retired'].
     * The list holds one row per retired asset, not per skip, and is a sample
     * for the operator, not the count. Two totals stay true past the cap:
     * details['assets_skipped_reasons']['soft_deleted_conflict'] counts skipped
     * agents (devices), and details['assets_skipped_retired_total'] counts the
     * distinct retired assets that blocked them, so a consumer that sees more
     * assets than rows says the list is partial.
     */
    public const RETIRED_SKIP_LIST_LIMIT = 20;

    /**
     * Retired asset ids already seen in a run, per SyncResult, so the asset
     * total keeps counting distinct assets after the list is full.
     *
     * @var \WeakMap<SyncResult, array<int, true>>|null
     */
    private ?\WeakMap $retiredSeen = null;

    /**
     * The widest real UTC offset is +14:00. createFromFormat's 'P' accepts far more
     * than that without complaint and builds a Carbon whose timezone name carries a
     * NUL byte, which then throws a ValueError on the next timezone-sensitive call.
     */
    private const BOOT_TIME_MAX_OFFSET_SECONDS = 14 * 3600;

    /**
     * The ONLY date-string spellings accepted for boot_time, tried in order.
     *
     * The vendor's documented type is a float epoch; this list exists because the
     * payload is JSON and a deployment has been seen to stringify it. It is a
     * closed enumeration on purpose: anything outside it is refused rather than
     * handed to Carbon's relative-expression parser. Each is parsed with a leading
     * '!' so unspecified fields reset to zero instead of defaulting to NOW —
     * without it, a format that omits a time component silently adopts the current
     * time and fabricates part of the observation.
     */
    private const BOOT_TIME_STRING_FORMATS = [
        'Y-m-d H:i:s',
        'Y-m-d\TH:i:s',
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s.uP',
        'Y-m-d\TH:i:s\Z',
        'Y-m-d',
    ];

    public function __construct(
        private readonly TacticalClient $client,
    ) {}

    /**
     * On-demand DETAIL read for one linked asset (amendment B). Reads getAgent
     * and writes the columns the daily list-sync leaves unfilled — ram_gb (from
     * total_ram, a GB count) and os_version — plus refreshes status/last_seen_at and
     * the checks_failing/checks_total summary, stamping synced_at.
     *
     * This is the trigger behind "refresh now". It is a READ: a fetch failure
     * (offline agent / Tactical unreachable) is a NORMAL outcome — it leaves the
     * prior snapshot intact and returns a degraded DetailSyncResult, never
     * throwing. ram_gb/os_version populate here (and via the daily sync only if
     * the list payload grows a checks dict — see mapAgentToTacticalAsset).
     */
    public function syncDeviceDetail(Asset $asset): DetailSyncResult
    {
        $ta = $asset->tacticalAsset;

        if (! $ta) {
            return DetailSyncResult::degraded('Asset is not linked to a Tactical agent.');
        }

        try {
            $agent = $this->client->getAgent($ta->agent_id, timeout: self::DETAIL_TIMEOUT_SECONDS);
        } catch (TacticalClientException $e) {
            // Offline vs HTTP error — both leave the snapshot intact. Debug, not
            // error: an unreachable agent is an expected read outcome.
            Log::debug('[TacticalDetailSync] detail read degraded', [
                'agent_id' => $ta->agent_id,
                'transport_failure' => $e->isTransportFailure(),
                'status_code' => $e->statusCode(),
            ]);

            // A 4xx/5xx status means Tactical WAS reached and refused (401, 404, 429,
            // 5xx), so "could not reach" would be false: the message is the fixed
            // Sync now sentence for that status. A failure with no response, or one
            // that still carries a 2xx/3xx (Guzzle raises RequestException with the
            // headers already received when the transfer breaks mid-body), is not a
            // refusal and keeps the could-not-reach text.
            $httpStatus = $e->statusCode() !== null && $e->statusCode() >= 400 ? $e->statusCode() : null;

            return DetailSyncResult::degraded(
                $httpStatus !== null
                    ? SourceSyncPipeline::sentence(SourceSyncPipeline::failureReason($httpStatus))
                    : 'Could not reach the agent — showing the last sync.',
                status: $ta->status,
                freshAsOf: $ta->synced_at,
                httpStatus: $httpStatus,
            );
        }

        $update = [
            'status' => $agent['status'] ?? $ta->status,
            'synced_at' => now(),
        ];

        if (($ramGb = TacticalFieldMap::ramGb($agent['total_ram'] ?? null)) !== null) {
            $update['ram_gb'] = $ramGb;
        }
        if (! empty($agent['operating_system'])) {
            $update['os_version'] = $agent['operating_system'];
        }
        if (($plat = TacticalPlatform::fromAgentPayload($agent['plat'] ?? null, $agent['operating_system'] ?? null)) !== null) {
            $update['plat'] = $plat;
        }
        if (isset($agent['last_seen'])) {
            $update['last_seen_at'] = Carbon::parse($agent['last_seen']);
        }
        if (isset($agent['needs_reboot'])) {
            $update['needs_reboot'] = (bool) $agent['needs_reboot'];
        }

        // getAgent `checks` is a SUMMARY DICT
        // ({total, passing, failing, warning, info, has_failing_checks}), NOT a
        // list of checks — TacticalFieldMap::checksFromAgentSummary owns the
        // shape (failing = failing+warning+info). Its `passing` is ALWAYS null
        // (psa-0pb9m R2: the vendor aggregate counts never-reporting checks as
        // passing), so this write also scrubs any pre-R2 manufactured value
        // still on the row. (The DETAILED failing-check list is a separate
        // getAgentChecks read.)
        $checks = TacticalFieldMap::checksFromAgentSummary(
            is_array($agent['checks'] ?? null) ? $agent['checks'] : null,
        );
        if ($checks['total'] !== null) {
            $update['checks_total'] = $checks['total'];
            $update['checks_failing'] = $checks['failing'];
            $update['checks_passing'] = $checks['passing'];
        }

        $wasOnline = $ta->status === 'online';
        $ta->update($update);

        // Offline→online: run any actions queued for this device (bd psa-xr84).
        //
        // This runs BEFORE the boot-time write on purpose: the sweep is unrelated
        // work and must not be suppressed by a failure in an opportunistic column
        // refresh (review 01a0b1a7 contract:9).
        if (! $wasOnline && $ta->status === 'online') {
            $this->dispatchSweepIfQueued((string) $ta->agent_id);
        }

        // Without this write, assets.last_boot_at keeps whatever the original import
        // wrote and reads as a months-old uptime forever, which AssetHealthService::
        // patchFactor() then scores as "up {N}d (patches may be pending)".
        //
        // Both detail and scheduled list sync consume a live boot_time observation
        // through this same guard. Neither path backfills unobserved history.
        $this->refreshAssetBootTime($ta, $agent['boot_time'] ?? null);

        return DetailSyncResult::success($ta->status, $ta->synced_at);
    }

    /**
     * Write the observed boot time onto the linked PSA asset.
     *
     * Mirrors the last_seen_at guard in the list refresh: an asset may have been
     * ADOPTED from Ninja or Level, and both of those write last_boot_at from their
     * own device payloads. So this never drags the column BACKWARDS — it writes only
     * when the observed boot time is strictly newer than what is already stored (or
     * when the column is empty). A machine that genuinely has not rebooted reports
     * the same boot time every sync and is left alone.
     *
     * An absent/unparseable boot_time is no observation: leave the column untouched
     * rather than blanking a value another integration is maintaining.
     *
     * $bootTime is mixed on purpose: it comes straight from a decoded vendor payload,
     * so a non-scalar would raise an uncaught TypeError at this boundary if the
     * parameter were narrowly typed — and this method's own try/catch cannot catch
     * its own signature (review 01a0b1a7 contract:1). Refusal happens in parseBootTime.
     */
    private function refreshAssetBootTime(TacticalAsset $ta, mixed $bootTime): void
    {
        if (! $ta->asset_id) {
            return;
        }

        $observed = $this->parseBootTime($bootTime);

        if (! $observed) {
            // Not an error: an absent boot_time is the normal shape for a payload
            // that carries none. A PRESENT but unusable one is worth a trace, so a
            // degraded vendor read is diagnosable instead of silent (C-56).
            if ($bootTime !== null) {
                Log::debug('Tactical boot_time ignored: not a usable observation.', [
                    'agent_id' => $ta->agent_id,
                    'type' => get_debug_type($bootTime),
                ]);
            }

            return;
        }

        // A boot time in the future is not a reboot we can believe; a clock-skewed
        // agent must not park the column ahead of every real observation.
        if ($observed->isFuture()) {
            Log::debug('Tactical boot_time refused: future value.', [
                'agent_id' => $ta->agent_id,
                'observed' => $observed->toDateTimeString(),
            ]);

            return;
        }

        // The ENTIRE read-modify-write is OPPORTUNISTIC: the detail sync has already
        // succeeded by the time we get here, so nothing below may fail the sync or
        // escape. refreshTactical() has no try/catch and syncDeviceDetail()'s catch
        // takes TacticalClientException only, so anything thrown here reaches a
        // user-facing surface carrying the statement with its bindings INTERPOLATED
        // (measured: "SQL: update `assets` set `last_boot_at` = ... where `id` = 42").
        // That is the psa #359 leak class this class already routes around via
        // safeFailure().
        //
        // The boundary deliberately starts at the SELECT, not at the UPDATE. An
        // earlier revision wrapped only the UPDATE, which left TWO escapes that the
        // r3 panel caught and that are measured in the guard tests: the Asset::find()
        // query itself, and — reached without any DB fault at all — Laravel's datetime
        // CAST of an existing corrupt/legacy last_boot_at in the never-backwards
        // comparison, which throws Carbon InvalidFormatException. A containment
        // boundary has to cover every statement that can throw, not just the one whose
        // failure was first imagined.
        try {
            $asset = Asset::find($ta->asset_id);

            if (! $asset) {
                return;
            }

            // Never drag the column backwards. NOTE this is deliberately
            // one-directional and therefore cannot repair a wrong stored value written
            // by another integration. "Strictly newer wins" is deliberate on both
            // detail and scheduled list paths, including Ninja/Level co-maintenance;
            // neither most-recent-observation arbitration nor a drift tolerance is
            // introduced by widening the caller.
            if ($asset->last_boot_at && ! $observed->gt($asset->last_boot_at)) {
                return;
            }

            Asset::where('id', $asset->id)->update(['last_boot_at' => $observed]);
        } catch (\Throwable $e) {
            Log::warning('Tactical boot_time refresh failed; sync result unaffected.', [
                'agent_id' => $ta->agent_id,
                'asset_id' => $ta->asset_id,
                'reason' => $this->safeFailure($e, 'boot time refresh'),
            ]);
        }
    }

    /**
     * Parse a vendor boot_time into a believable instant, or null for "no observation".
     *
     * Tactical serialises psutil's boot_time, which is a FLOAT epoch, and a payload
     * that has round-tripped through a JSON encoder can present the same value as a
     * numeric STRING. Both are real observations and both are handled here as epochs.
     *
     * Everything else is refused rather than guessed. In particular Carbon::parse('')
     * and Carbon::parse(' ') return NOW, so a near-empty string would otherwise
     * fabricate a boot time of this instant and — being newer than anything stored —
     * would always win the never-backwards guard (review 01a0b1a7 contract:4).
     *
     * That hazard is a property of Carbon::parse(), not of the blank string: 'now',
     * 'today', 'midnight' and '+0 seconds' all resolve to THIS INSTANT too, so the
     * date-string branch accepts only strings whose SHAPE is an absolute date. The
     * plausibility floor likewise belongs to the parsed INSTANT and not to the numeric
     * input shape — '1970-01-01' is the same garbled read as epoch 0 — so both branches
     * enforce it.
     */
    private function parseBootTime(mixed $bootTime): ?Carbon
    {
        if (is_bool($bootTime) || $bootTime === null) {
            return null;
        }

        // Numeric epoch in any of the three shapes the vendor/JSON can deliver:
        // int, float (psutil's native type) or a numeric string.
        if (is_int($bootTime) || is_float($bootTime)
            || (is_string($bootTime) && is_numeric(trim($bootTime)))) {
            $epoch = (float) (is_string($bootTime) ? trim($bootTime) : $bootTime);

            // INF and NAN are not orderable, so EVERY comparison below is false and
            // they would sail through the floor. Measured (r3 context:2): the string
            // '1e999' casts to INF, `INF < FLOOR` and `NAN < FLOOR` are both false, and
            // Carbon::createFromTimestamp(INF) returns 1970-01-01 — which is not future,
            // so on an empty column it is WRITTEN. That is the same 1970 fabrication the
            // floor exists to stop, arriving through a value the floor cannot rank.
            // Reject non-finite input before any ordering is attempted.
            if (! is_finite($epoch)) {
                return null;
            }

            // Preserve refusal of small/negative input before an out-of-range cast
            // can wrap it upward. This is not sufficient to validate the instant.
            if ($epoch < self::BOOT_TIME_EPOCH_FLOOR) {
                return null;
            }

            // The floor bounds the input from BELOW only, and the modular wrap this
            // class of bug turns on maps an out-of-range float onto the WHOLE int
            // range — not only onto values under the floor. 2^64 + 1789616128 (a
            // finite double the producer's FloatField can carry) clears the floor,
            // casts to 1789616128, is a plausible past instant, and would be written
            // as a fabricated boot time that also wins the never-backwards
            // arbitration — which a populated column cannot mask (r4 diff:1,
            // context:1). A value PHP's int cannot carry is not an observation of
            // any instant, so refuse it BEFORE the cast rather than ranking whatever
            // the wrap happens to produce. (float) PHP_INT_MAX is exactly 2^63, so
            // everything reaching the cast below truncates exactly.
            if ($epoch >= (float) PHP_INT_MAX) {
                return null;
            }

            try {
                // Truncate to whole seconds. The column is second-precision, so a
                // FRACTIONAL epoch is written as ...:00 and re-read as ...:00.000000,
                // while the next sync's in-memory value still carries .726743 — making
                // `$observed->gt($stored)` TRUE on every single sync for a machine that
                // never rebooted (r3 diff:1, measured). psutil's boot_time is a float,
                // so that is the NORMAL case, not an edge one: the "only when newer"
                // write was in fact an unconditional write of the whole Tactical fleet,
                // bumping assets.updated_at forever. Comparing at the precision the
                // column actually stores is what makes the guard's promise true.
                $parsed = Carbon::createFromTimestamp((int) $epoch);

                // Validate the instant we will write, just as the string branch does.
                // A finite float outside PHP's int range can cast below the floor
                // (2^64 casts to 0 here); checking the pre-cast float admitted 1970
                // on an empty column, where never-backwards cannot mask it (#2492).
                if ($parsed->getTimestamp() < self::BOOT_TIME_EPOCH_FLOOR) {
                    return null;
                }

                return $parsed;
            } catch (\Throwable) {
                return null;
            }
        }

        // A genuine date string is still accepted, but the accepted grammar is
        // ENUMERATED, not guessed at by a shape pattern. An earlier version anchored
        // a leading YYYY-MM-DD, which blocks 'now'/'today' but NOT a date prefix
        // carrying relative arithmetic: measured at this tip, '1970-01-01 +56 years'
        // matched that anchor, resolved to 2026-01-01, and so cleared the plausibility
        // floor that the literal 1970 date is refused by. The floor tests the RESOLVED
        // instant, so any relative suffix launders an implausible value into a
        // plausible one. A prefix pattern cannot express "contains no relative
        // arithmetic"; only a strict parse of the WHOLE string can.
        if (is_string($bootTime)) {
            $trimmed = trim($bootTime);

            foreach (self::BOOT_TIME_STRING_FORMATS as $format) {
                // The try must span EVERY call made on $parsed, not just its
                // construction (r3 diff:4). The whole bug class being guarded against
                // here is a Carbon that constructs cleanly and then throws on the next
                // timezone-sensitive call, so wrapping only createFromFormat() leaves
                // the guard's own getOffset()/utc()/getTimestamp() calls exposed to the
                // very thing they are checking for. Measured: getOffset() and utc()
                // happen to survive the '+9999' object — "happen to" is not a contract,
                // and parseBootTime has no outer catch to fall back on.
                try {
                    $parsed = Carbon::createFromFormat('!'.$format, $trimmed);

                    // createFromFormat does not throw on trailing junk; it records it.
                    // Without this check '2026-09-17 +56 years' parses as the date and
                    // silently discards the suffix, which is the same laundering hazard
                    // arriving by a different door.
                    $errors = Carbon::getLastErrors();

                    if ($parsed === false
                    || ($errors && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
                        continue;
                    }

                    // The offset-bearing formats accept an offset PHP itself cannot hold.
                    // Measured at the previous tip: '+9999' parsed with no warning or error,
                    // produced a 362340-second offset and a Carbon whose timezone name
                    // contains a NUL byte, so the very next call — isFuture() — threw a
                    // ValueError out of refreshAssetBootTime, past syncDeviceDetail's
                    // TacticalClientException-only catch, and killed the whole sync.
                    // Real offsets are within +/- 14:00 (RFC 9557 / IANA); anything beyond
                    // it is not a timezone, it is a malformed reading.
                    // REFUSE the value outright rather than `continue`. A recognised shape
                    // carrying an impossible offset is a malformed reading, not a reason to
                    // re-offer the same string to the laxer formats later in the list (r3
                    // diff:5). Today nothing leaks through that path only because the one
                    // remaining prefix-matching format always leaves trailing data and is
                    // killed by the getLastErrors() check — a coincidence of list order, not
                    // a property this code states. A zone-less format matching a full
                    // datetime prefix would silently DISCARD the offset and write the wall
                    // clock as if UTC, reintroducing the seven-hour-error class that the
                    // ->utc() normalisation was added to fix.
                    if (abs($parsed->getOffset()) > self::BOOT_TIME_MAX_OFFSET_SECONDS) {
                        return null;
                    }

                    // Truncate to whole seconds here too, for the same reason as the
                    // numeric branch: the microseconds spelling can otherwise re-trigger
                    // the never-backwards comparison on every sync.
                    $parsed = $parsed->startOfSecond();

                    // Normalise to UTC before the floor and the write. The numeric branch
                    // yields UTC, the column is cast to app timezone UTC, and the stored
                    // value is compared against other integrations' writes — so an offset
                    // string must be converted, not have its offset silently dropped.
                    // Measured: '2026-09-16T10:00:00-07:00' stored 10:00 instead of 17:00,
                    // a seven-hour error in the uptime the health score reads.
                    $parsed = $parsed->utc();

                    // Same floor as the numeric branch: a claim about the instant, not
                    // about how the vendor spelled it, so a 1970 date string is refused
                    // rather than written onto an empty column as a 56-year uptime.
                    if ($parsed->getTimestamp() < self::BOOT_TIME_EPOCH_FLOOR) {
                        return null;
                    }

                    return $parsed;
                } catch (\Throwable) {
                    continue;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Dispatch a reconnect sweep for an agent that just came online (only if it has
     * unexpired queued actions). Delegates to the shared guard on the job.
     */
    private function dispatchSweepIfQueued(string $agentId): void
    {
        SweepQueuedActionsForAgent::dispatchIfQueued($agentId);
    }

    /**
     * Operator-safe rendering of a caught failure.
     *
     * A QueryException's getMessage() embeds the failed statement AND its
     * bindings, and the bindings here are the asset row we were writing —
     * hostname, serial, logged-in username, LAN address. TacticalClient's SSRF
     * pin leaks by the same mechanism, naming the Tactical host and the address
     * it resolved to. That string does not stay in one place:
     * SyncResult::$errorMessages is rendered by the integrations settings view,
     * returned verbatim by StaffTacticalAdminToolExecutor (an MCP surface), and
     * written to storage/logs/laravel.log, which has no rotation configured.
     *
     * SCOPE — this covers exactly the six catches in this class that
     * recordError() into SyncResult::$errorMessages: the client-map read, the
     * agent fetch, the queued-action pre-scan, the per-agent write and the
     * not-seen offline sweep in syncDevices(), and the asset lock in
     * linkOrCreateAsset(). $context names
     * which one, so the message stays truthful about what was attempted
     * without reproducing what it was attempted with.
     *
     * The first and third were added for psa #359. They are the reason this
     * scope note is worth keeping accurate: before them, two unguarded reads
     * sat ABOVE every catch in this method, so a QueryException from either
     * left syncDevices() entirely and was flashed raw by the controller. This
     * helper was not incomplete from those reads — it was unreachable. A stale
     * "three catches" here would have described a boundary that no longer
     * holds, which is the failure mode this note exists to prevent.
     *
     * TWO THINGS IT DOES NOT COVER. Read this as the boundary, not as a
     * class-wide or surface-wide guarantee:
     *
     *  - syncDeviceDetail()'s catch returns DetailSyncResult::degraded() to the
     *    refresh-now JSON and does not route through here. It leaks nothing
     *    today because that message is one of a fixed set of sentences chosen
     *    by the HTTP status code alone — a property of those strings, NOT of
     *    this helper. Routing it is ticketed.
     *  - The log surface is NOT closed for the SSRF pin.
     *    TacticalClient::resolveAndPin() writes the host, and on the
     *    private-address path the resolved IP, to that same unrotated log
     *    unconditionally BEFORE it throws. Redacting the message here closes
     *    the settings view and the MCP executor; it does not and cannot close
     *    the log. Ticketed separately.
     *
     * What an operator can actually act on is the failure class, the driver's
     * SQLSTATE and its errno, which is what they get. The driver's own message
     * is withheld: it is the part that embeds values (a duplicate-key error
     * quotes the offending binding back verbatim). The agent_id or client_id is
     * carried alongside at each call site, so the failing record stays
     * identifiable without reproducing its contents.
     */
    private function safeFailure(\Throwable $e, string $context): string
    {
        if ($e instanceof QueryException) {
            // getCode() is int 0 when the driver reports no SQLSTATE, and
            // (string) 0 is '0' — a bare !== '' guard lets that through and
            // presents "(SQLSTATE 0)" as if it were a real driver code.
            $sqlState = (string) $e->getCode();
            $parts = $sqlState !== '' && $sqlState !== '0' ? ["SQLSTATE {$sqlState}"] : [];

            // errorInfo is [SQLSTATE, driver errno, driver message]. The errno
            // is a non-sensitive integer and is what actually distinguishes a
            // deadlock from a too-long column; the message is not taken.
            $errno = $e->errorInfo[1] ?? null;
            if (is_int($errno) || (is_string($errno) && $errno !== '')) {
                $parts[] = "driver error {$errno}";
            }

            return "database {$context} failed".($parts !== [] ? ' ('.implode(', ', $parts).')' : '');
        }

        return class_basename($e)." during {$context}";
    }

    public function syncDevices(?int $clientId = null): SyncResult
    {
        $result = new SyncResult;

        // Build client mapping: tactical site key ("ClientName|SiteName") → PSA client_id
        //
        // GUARDED because it is not optional. safeFailure() covers the catches
        // in this class that recordError() into SyncResult (see its SCOPE note —
        // do not restate the count here, it has been wrong twice), but this read sits ABOVE
        // all of them: a QueryException here left the method entirely, reached
        // IntegrationsController::syncTacticalDevices()'s `catch (\Throwable)`,
        // and was interpolated raw into a flashed message — statement and bound
        // values to the operator's browser (psa #359). safeFailure() was not
        // incomplete from this read; it was UNREACHABLE from it.
        try {
            $clientMap = Client::whereNotNull('tactical_site_id')
                ->operational()
                ->pluck('id', 'tactical_site_id')
                ->all();
        } catch (\Throwable $e) {
            $safe = $this->safeFailure($e, 'client map read');
            Log::warning('[TacticalSync] Failed to read the client/site mapping', ['error' => $safe]);
            $result->recordError("Failed to read the client/site mapping: {$safe}");

            return $result;
        }

        if (empty($clientMap)) {
            Log::info('[TacticalSync] No clients mapped to Tactical RMM sites');

            return $result;
        }

        $fetchSucceeded = false;

        // When the agent list was read: asset watches order observations by it,
        // because the poller can read an agent live while this run is still
        // working through the list (card K3VEcxtw).
        $agentsReadAt = Carbon::now();

        try {
            $agents = $this->client->getAgents();
            $fetchSucceeded = true;
        } catch (\Throwable $e) {
            // TacticalClientException::fromGuzzle() is already body-free, so the
            // HTTP path was never the leak here. The SSRF pin is: resolveAndPin()
            // throws "Tactical API host '<host>' resolved to a private or
            // reserved address (<ip>); refused", and that went verbatim into
            // errorMessages. The catch is on \Throwable and does not get to
            // assume what a future throw carries either.
            $safe = $this->safeFailure($e, 'agent fetch');
            Log::warning('[TacticalSync] Failed to fetch agents', ['error' => $safe]);
            $result->recordError("Failed to fetch agents: {$safe}");

            return $result;
        }

        $seenAgentIds = [];

        // Every agent_id the UPSTREAM payload carried, regardless of whether we
        // could map it to an operational client or whether this run is scoped to
        // one. getAgents() is a FULL fetch — $clientId filters in PHP below, it is
        // not pushed to Tactical — so this set is the honest answer to "does this
        // agent still exist upstream", which is the only question the not-seen
        // sweep is entitled to ask. $seenAgentIds cannot answer it: an agent is
        // dropped from that set by BOTH `continue`s below, so an unmapped site or
        // a client-scoped run makes a live, reporting machine look absent.
        $seenAllAgentIds = [];

        // Pre-sync status of agents that have queued offline actions, so an
        // offline→online flip in this run can dispatch their queue (bd psa-xr84)
        // without a per-agent lookup inside the loop.
        // Guarded for the same reason as the client map above, but it degrades
        // rather than aborts: this lookup only decides whether an offline→online
        // flip can dispatch a queued action. Losing it costs a deferred dispatch,
        // not the sync — so record the failure and carry on with an empty map
        // instead of discarding a run that can still reconcile every agent.
        try {
            $queuedAgentStatus = TacticalAsset::query()
                ->whereIn('agent_id', TechnicianRun::query()
                    ->where('state', TechnicianRunState::QueuedOffline->value)
                    ->where('expires_at', '>', now())
                    ->whereNotNull('queued_agent_id')
                    ->distinct()
                    ->pluck('queued_agent_id'))
                ->pluck('status', 'agent_id');
        } catch (\Throwable $e) {
            $safe = $this->safeFailure($e, 'queued-action pre-scan');
            Log::warning('[TacticalSync] Failed to pre-scan queued offline actions', ['error' => $safe]);
            $result->recordError("Failed to pre-scan queued offline actions: {$safe}");
            $queuedAgentStatus = collect();
        }

        // Assets with an armed watch (card K3VEcxtw), read once so an unwatched
        // fleet costs one query. observe() never throws; this read is guarded the
        // same way as the pre-scan above because losing it costs a fire, not the sync.
        try {
            $watchedAssetIds = app(AssetWatchEvaluator::class)->watchedAssetIds();
        } catch (\Throwable $e) {
            Log::warning('[TacticalSync] Failed to read asset watches', ['error' => $this->safeFailure($e, 'asset watch read')]);
            $watchedAssetIds = [];
        }

        foreach ($agents as $agent) {
            $agentId = $agent['agent_id'] ?? null;
            if (! $agentId) {
                continue;
            }

            $seenAllAgentIds[] = $agentId;

            // Map Tactical client+site to PSA client
            $siteKey = ($agent['client_name'] ?? '').'|'.($agent['site_name'] ?? '');
            $psaClientId = $clientMap[$siteKey] ?? null;

            if (! $psaClientId) {
                continue;
            }

            if ($clientId && $psaClientId !== $clientId) {
                continue;
            }

            $seenAgentIds[] = $agentId;

            // Per-agent isolation (mirrors NinjaSyncService's device loop, which
            // wraps its ENTIRE per-device body): one agent's write failure must
            // not abort the run. Without it a single bad row skips every later
            // agent AND the not-seen→offline sweep below, so decommissioned
            // agents keep reading "online".
            //
            // The boundary opens HERE, ABOVE the tactical_assets upsert, not
            // below it. That upsert is the widest write in the loop and the most
            // likely thrower: unbounded vendor strings (cpu/os/graphics/
            // make_model) land in varchar(255) columns under 'strict' => true,
            // and it is a deadlock candidate whenever the scheduled run and the
            // operator's "Sync devices" button touch the same agent_id. Leaving
            // it outside would exempt the exact failure this isolation exists to
            // contain.
            //
            // $seenAgentIds is appended BEFORE the try on purpose: the agent WAS
            // in the payload, so our failure to write it must not let the sweep
            // below call the machine offline.
            try {
                // Upsert into tactical_assets
                $tacticalAsset = TacticalAsset::updateOrCreate(
                    ['agent_id' => $agentId],
                    $this->mapAgentToTacticalAsset($agent),
                );

                if ($tacticalAsset->wasRecentlyCreated) {
                    $result->created++;
                } else {
                    $result->updated++;
                }

                // Offline→online flip for an agent with queued actions → run its queue.
                if (isset($queuedAgentStatus[$agentId]) && $queuedAgentStatus[$agentId] !== 'online' && $tacticalAsset->status === 'online') {
                    SweepQueuedActionsForAgent::dispatchIfQueued((string) $agentId);
                }

                // The link keeps its OWN transaction — linkOrCreateAsset already
                // takes the same client row lock inside it, so the serialization the
                // rebind lock matches is unchanged. It must NOT be nested in the
                // refresh transaction below: nesting demotes its commit to a
                // savepoint, and a throw in the refresh UPDATE would then roll the
                // new asset back while assets_created/linked — incremented after
                // that inner commit precisely so a rolled-back create can never be
                // reported — stayed raised, with errors bumped for the same agent.
                $tacticalAsset->refresh();
                if (! $tacticalAsset->asset_id) {
                    // Link to PSA asset if not already linked — creating the asset
                    // when Tactical is the discovery source for this device.
                    $this->linkOrCreateAsset($tacticalAsset, $psaClientId, $agent, $result);
                }

                DB::transaction(function () use ($tacticalAsset, $agent, $psaClientId) {
                    // Match rebind's client lock, then reread the binding, so a
                    // rebind committed since the upsert cannot make this run refresh
                    // the old asset. The snapshot upsert stays outside: a failed
                    // refresh must preserve it. The read above the link call is NOT a
                    // substitute: it is taken outside this transaction and under no
                    // lock, so a rebind committing after it would send this agent's
                    // rmm_online/last_seen_at/last_user back onto the asset the
                    // rebind just released, with no later writer to correct it.
                    DB::table('clients')->where('id', $psaClientId)->lockForUpdate()->first();
                    $tacticalAsset->refresh();

                    // Refresh the linked asset from THIS run's snapshot. rmm_online
                    // and last_seen_at are read as CURRENT truth by the Assets list
                    // badge and AssetHealthService::connectivityFactor(), so writing
                    // them once at creation and never again would assert a frozen
                    // connectivity state forever (psa-wedk: never present synced
                    // state as current truth).
                    //
                    // Three limits on what this run may assert, because the asset is
                    // not necessarily ours alone: linkOrCreateAsset() ADOPTS an
                    // existing asset by hostname/name, and that asset may already be
                    // maintained by NinjaSyncService or LevelSyncService, which write
                    // these same two columns on their own cadence.
                    //
                    //  - Only Tactical's contact vocabulary moves rmm_online:
                    //    'online' to true, 'offline' AND 'overdue' to false.
                    //    'overdue' is the LONGER out-of-contact state, not a softer
                    //    one (see rmmOnlineFromStatus), so a machine that stays down
                    //    is still recorded as down. Any other value is not an
                    //    observation of connectivity and leaves the flag untouched.
                    //  - A false is never written over another RMM's link. The false
                    //    branch has no staleness escape anywhere (isRmmDataStale
                    //    gates only a TRUE flag), so a broken Tactical agent would
                    //    otherwise re-assert Offline every interval on a device Ninja
                    //    or Level is actively reporting online. A TRUE still writes:
                    //    it is something we did observe, and every reader gates a
                    //    true on last_seen_at freshness.
                    //  - last_seen_at only ever moves FORWARD. Tactical's snapshot
                    //    can be older than the other RMM's heartbeat, and writing it
                    //    unconditionally would drag the asset's freshness backwards
                    //    and make current data read as stale.
                    $linkedAsset = $tacticalAsset->asset_id
                        ? Asset::find($tacticalAsset->asset_id)
                        : null;

                    if ($linkedAsset) {
                        $refresh = [];
                        $otherRmmMaintains = $linkedAsset->ninja_id !== null || $linkedAsset->level_id !== null;
                        $online = $this->rmmOnlineFromStatus($tacticalAsset->status);

                        if ($online === true || ($online === false && ! $otherRmmMaintains)) {
                            $refresh['rmm_online'] = $online;
                        }

                        $observed = $tacticalAsset->last_seen_at;

                        if ($observed && (! $linkedAsset->last_seen_at || $observed->gt($linkedAsset->last_seen_at))) {
                            $refresh['last_seen_at'] = $observed;
                        }

                        if ($agent['logged_username'] ?? null) {
                            $refresh['last_user'] = $agent['logged_username'];
                        }

                        if ($refresh !== []) {
                            Asset::where('id', $linkedAsset->id)->update($refresh);
                        }
                    }

                });

                // Asset watches (card K3VEcxtw) see the SAME value this run wrote to
                // rmm_online, after the refresh committed, through the one evaluator
                // the poller also uses.
                if ($tacticalAsset->asset_id && isset($watchedAssetIds[(int) $tacticalAsset->asset_id])) {
                    app(AssetWatchEvaluator::class)->observe(
                        (int) $tacticalAsset->asset_id,
                        $this->rmmOnlineFromStatus($tacticalAsset->status),
                        $tacticalAsset->last_seen_at,
                        'sync',
                        $agentsReadAt,
                    );
                }

                DB::transaction(function () use ($tacticalAsset, $agent, $psaClientId) {
                    DB::table('clients')->where('id', $psaClientId)->lockForUpdate()->first();
                    $tacticalAsset->refresh();
                    // AgentTableSerializer.Meta.fields in upstream agents/serializers.py
                    // (pinned in upstream_producers.json) includes boot_time on GET agents/.
                    // Keep opportunistic boot work AFTER connectivity, as on the detail
                    // path: an escaping prologue must not suppress this agent's refresh.
                    // Reuse the parser and guards unchanged, inside per-agent containment.
                    $this->refreshAssetBootTime($tacticalAsset, $agent['boot_time'] ?? null);
                });
            } catch (\Throwable $e) {
                $safe = $this->safeFailure($e, 'write');
                Log::warning('[TacticalSync] Agent skipped after a write failure', [
                    'agent_id' => $agentId,
                    'error' => $safe,
                ]);
                $result->recordError("Agent {$agentId}: {$safe}");
            }
        }

        // Mark agents ABSENT FROM THE UPSTREAM PAYLOAD offline. This now runs on a
        // client-scoped sync too (#842). The sweep used to be gated `! $clientId`,
        // and the only MCP caller — StaffTacticalAdminToolExecutor::syncDevices() —
        // always passes a server-derived client id, so the sweep was structurally
        // unreachable from the tool: `deactivated` was a hard 0 on every invocation
        // and an offboarded device kept its last observed status indefinitely while
        // the tool reported that zero as a finding.
        //
        // What made the gate necessary was the PREDICATE, not the scope.
        // $seenAgentIds holds only the agents this run mapped AND kept in scope, so
        // sweeping against it on a client-scoped run would have called every OTHER
        // client's fleet offline. $seenAllAgentIds is the full upstream payload, so
        // the predicate now means what the sweep always claimed it meant — "Tactical
        // stopped telling us about this agent_id" — and the sweep needs BOUNDING
        // rather than disabling.
        //
        // Widening the predicate also fixes the full sync, and this is a behaviour
        // change worth stating: the note below lists "every agent whose siteKey no
        // longer maps to an operational client" as a KNOWN member of the not-seen
        // set. Those agents are present upstream. Calling them offline was asserting
        // the opposite of something we did observe — the inverse of the psa-wedk
        // principle this method cites twice. A site rename or a client leaving
        // stage=Active no longer moves a live fleet to Offline.
        $sweepSiteKeys = null;

        if ($clientId) {
            // Bound a client-scoped run's blast radius to that client's own rows,
            // matched on the (client_name, site_name) pair the sync itself writes —
            // the same siteKey $clientMap is keyed by. A row whose pair is unknown
            // to the map is NOT swept on a scoped run: a scoped run has no standing
            // to speak for it, and skipping is the safe failure (the full daily sync
            // still reaches it). Likewise a client with no mapped site sweeps
            // nothing rather than everything.
            $sweepSiteKeys = array_keys(array_filter(
                $clientMap,
                static fn ($mappedId) => $mappedId === $clientId
            ));
        }

        if ($fetchSucceeded && $sweepSiteKeys !== []) {
            $stale = TacticalAsset::whereNotIn('agent_id', $seenAllAgentIds)
                ->where('status', '!=', 'offline');

            if ($sweepSiteKeys !== null) {
                $stale->where(function ($q) use ($sweepSiteKeys) {
                    foreach ($sweepSiteKeys as $siteKey) {
                        [$clientName, $siteName] = array_pad(explode('|', (string) $siteKey, 2), 2, '');
                        $q->orWhere(function ($w) use ($clientName, $siteName) {
                            $w->where('client_name', $clientName)->where('site_name', $siteName);
                        });
                    }
                });
            }

            // The AGENT snapshot goes offline — that row is exactly "what
            // Tactical last told us about this agent_id", and Tactical stopped
            // telling us. The linked ASSET is deliberately left alone: "absent
            // from this run's payload" is UNKNOWN, not offline.
            //
            // The not-seen set is still wider than "the machine is off": it holds
            // the stale row an agent REINSTALL leaves behind once the new agent_id
            // cannot claim the hostname, which is a live, reporting machine under a
            // different id. (It NO LONGER holds agents whose siteKey stopped mapping
            // to an operational client — those are present in $seenAllAgentIds and
            // are skipped. That was the site-rename / stage=Active fleet-wide false
            // positive; it is fixed, not merely tolerated.)
            //
            // It also does NOT mean "confirmed gone upstream", and must not be read
            // as that (#842): absent from the payload is still UNKNOWN. A device
            // proved deleted at the vendor — a 404 on the per-device read — has no
            // distinct state anywhere in this schema, and inventing one by widening
            // 'offline' would destroy the distinction this block exists to keep.
            //
            // Writing rmm_online = false would state a flat operator-facing
            // "Offline" for them and charge AssetHealthService's offline penalty
            // indefinitely, with no way back: Asset::getStatusBadgeAttribute()
            // has no staleness escape on the false branch (isRmmDataStale gates
            // only a TRUE rmm_online), this sweep's `status != offline` filter
            // fires once and never re-evaluates, and for a Tactical-only asset —
            // the population this change creates — no other writer restores the
            // flag. Keeping the last value we actually OBSERVED degrades
            // honestly instead — but ONLY because every reader of rmm_online now
            // gates a TRUE flag on last_seen_at freshness, which stops advancing
            // here: Asset::getStatusBadgeAttribute() reads "Stale",
            // AssetHealthService::connectivityFactor() drops the penalty-free
            // "Online per RMM" and scores the machine off last_seen_at instead,
            // and the Assets "offline" filter still finds it. Without all three
            // this sweep would trade a false "Offline" for an equally unobserved,
            // permanent "Online" — so a NEW reader of rmm_online must gate on
            // Asset::isRmmDataStale() or this decision has to be revisited. The
            // per-agent refresh above corrects the flag the moment the agent is
            // seen again. Same psa-wedk principle the refresh cites, the other
            // way round: never assert current truth we did not observe.
            //
            // GUARDED, and this is the one the ticket was actually reported against.
            // It is a WRITE, and it sits below the per-agent loop's own catch with no
            // try of its own — so a QueryException here escaped syncDevices() entirely,
            // reached IntegrationsController::syncTacticalDevices()'s `catch (\Throwable)`
            // and was flashed with its statement and bindings. Guarding the two reads
            // above it does not close #359; enumerating reads never would have, because
            // the reported failure came through here.
            //
            // DEGRADES, like the pre-scan: losing the sweep leaves agents showing their
            // last observed status for one cycle, which the next run corrects. Aborting
            // would discard a run that has already reconciled every agent.
            try {
                $staleCount = $stale->update(['status' => 'offline', 'synced_at' => now()]);
            } catch (\Throwable $e) {
                $safe = $this->safeFailure($e, 'not-seen offline sweep');
                Log::warning('[TacticalSync] Failed to sweep not-seen agents offline', ['error' => $safe]);
                $result->recordError("Failed to sweep not-seen agents offline: {$safe}");
                $staleCount = 0;
            }

            if ($staleCount > 0) {
                $result->deactivated += $staleCount;
                Log::info("[TacticalSync] Marked {$staleCount} agent(s) as offline (not seen in API response)", [
                    'scope' => $clientId ? "client:{$clientId}" : 'full',
                ]);
            }
        }

        Log::info('[TacticalSync] Device sync complete', [
            'created' => $result->created,
            'updated' => $result->updated,
            'linked' => $result->details['linked'] ?? 0,
            'deactivated' => $result->deactivated,
        ]);

        return $result;
    }

    /**
     * Map a Tactical RMM agent API response to TacticalAsset fillable fields.
     */
    private function mapAgentToTacticalAsset(array $agent): array
    {
        // cpu_model comes as an array from the API — join for storage
        $cpu = $agent['cpu_model'] ?? null;
        if (is_array($cpu)) {
            $cpu = implode(', ', $cpu);
        }

        // physical_disks comes as an array from the API — join for storage
        $diskSummary = $agent['physical_disks'] ?? null;
        if (is_array($diskSummary)) {
            $diskSummary = implode(', ', $diskSummary);
        }

        // local_ips may be a string or array — normalize to array for JSON cast
        $localIps = $agent['local_ips'] ?? null;
        if (is_string($localIps)) {
            $localIps = array_map('trim', explode(',', $localIps));
        }

        $mapped = [
            'hostname' => $agent['hostname'] ?? null,
            'os' => $agent['operating_system'] ?? null,
            'plat' => TacticalPlatform::fromAgentPayload($agent['plat'] ?? null, $agent['operating_system'] ?? null),
            'public_ip' => $agent['public_ip'] ?? null,
            'local_ips' => $localIps,
            'last_user' => $agent['logged_username'] ?? null,
            'cpu' => $cpu,
            'make_model' => $agent['make_model'] ?? null,
            'disk_summary' => $diskSummary,
            'serial_number' => $agent['serial_number'] ?? null,
            'status' => $agent['status'] ?? 'offline',
            'agent_version' => $agent['version'] ?? null,
            'last_seen_at' => isset($agent['last_seen']) ? Carbon::parse($agent['last_seen']) : null,
            'client_name' => $agent['client_name'] ?? null,
            'site_name' => $agent['site_name'] ?? null,
            'needs_reboot' => $agent['needs_reboot'] ?? false,
            'has_patches_pending' => $agent['has_patches_pending'] ?? false,
            'graphics' => $agent['graphics'] ?? null,
            'monitoring_type' => $agent['monitoring_type'] ?? null,
            'synced_at' => now(),
        ];

        // Eager checks-summary (amendment B): the Tactical AgentTable serializer
        // embeds a `checks` SUMMARY DICT
        // ({total, passing, failing, warning, info, has_failing_checks}) per agent
        // in the LIST payload too (confirmed against source v1.5.0 + live VM 105).
        // Persist failing/total so the card health line and the coverage verdict
        // are snapshot-fresh from the DAILY sync (zero per-agent fan-out).
        // TacticalFieldMap::checksFromAgentSummary owns the dict shape: failing =
        // failing+warning+info (the severity split of status=failing, so snapshot
        // and live-list counts agree), and its passing is ALWAYS null (psa-0pb9m
        // R2: the vendor aggregate counts never-reporting checks as passing —
        // never evidence), so this write also scrubs pre-R2 manufactured values.
        // Read defensively: leave the columns untouched if a payload ever omits
        // the dict.
        $checks = TacticalFieldMap::checksFromAgentSummary(
            is_array($agent['checks'] ?? null) ? $agent['checks'] : null,
        );
        if ($checks['total'] !== null) {
            $mapped['checks_total'] = $checks['total'];
            $mapped['checks_failing'] = $checks['failing'];
            $mapped['checks_passing'] = $checks['passing'];
        }

        return $mapped;
    }

    /**
     * Link a TacticalAsset to the mapped client's PSA Asset by hostname match,
     * CREATING that asset when the client has no record of the device yet.
     *
     * Tactical is a discovery source, not only an enricher — same posture as the
     * Level and Ninja syncs, which both seed assets. Without the create, an agent
     * on a mapped site with no matching asset left a tactical_assets row with
     * asset_id = NULL, and since every tactical UI surface hangs off Asset, the
     * device was invisible: the sync said "N created, 0 linked" and the operator
     * saw nothing.
     *
     * @param  array<string, mixed>  $agent
     */
    private function linkOrCreateAsset(TacticalAsset $tacticalAsset, int $psaClientId, array $agent, SyncResult $result): void
    {
        $hostname = $agent['hostname'] ?? null;

        if (! $hostname) {
            $this->countSkippedAsset($result, 'no_hostname');

            Log::info('[TacticalSync] Skipped asset link — agent reports no hostname', [
                'agent_id' => $tacticalAsset->agent_id,
            ]);

            return;
        }

        $lowerHostname = strtolower($hostname);

        // Resolve-or-create is a check-then-write, and syncDevices() runs from
        // BOTH the scheduler and the operator's "Sync devices" button — the
        // command's withoutOverlapping() does not cover the web path. Two runs
        // racing the same host would each see "no match" and each create an
        // asset, forking one device into two billable, client-facing rows.
        // There is no unique index on (client_id, hostname) to fall back on, so
        // serialize per (client, hostname): the loser of the race re-runs the
        // lookup inside the lock and LINKS to the row the winner just created.
        $lock = Cache::lock('tactical-sync:asset:'.$psaClientId.':'.sha1($lowerHostname), 60);

        try {
            $acquired = $lock->get();
        } catch (\Throwable $e) {
            // Fail CLOSED and loudly: without the lock we cannot promise we are
            // not duplicating a device, and a silent skip would stop discovery
            // with nothing but a log line.
            // The hostname is the client's device name and the driver message
            // quotes the lock key, which digests it. agent_id is Tactical-opaque
            // and is what an operator needs to find the device, so it takes the
            // hostname's place.
            $safe = $this->safeFailure($e, 'asset lock');
            Log::warning('[TacticalSync] Could not acquire the asset lock', [
                'agent_id' => $tacticalAsset->agent_id,
                'client_id' => $psaClientId,
                'error' => $safe,
            ]);
            $result->recordError("Could not acquire the asset lock for agent {$tacticalAsset->agent_id}: {$safe}");

            return;
        }

        if (! $acquired) {
            Log::info('[TacticalSync] Another sync holds this host — deferring the link to the next run', [
                'agent' => $hostname,
                'client_id' => $psaClientId,
            ]);

            return;
        }

        $asset = null;
        $created = false;

        try {
            // ONE transaction for the create AND both back-links. A failure
            // between them (deploy restart, DB timeout, AssetObserver::created
            // throwing) would otherwise leave assets.tactical_asset_id set with
            // tactical_assets.asset_id NULL — a state no later run can heal,
            // because the link query skips linked assets while the conflict
            // query refuses creation on them. That is permanent invisibility
            // needing a manual DB repair.
            DB::transaction(function () use ($tacticalAsset, $psaClientId, $agent, $lowerHostname, $result, &$asset, &$created) {
                // The DB-level half of the guarantee. Cache::lock above is a fast
                // path, not a promise: CACHE_STORE=file here, and FileStore's
                // lock is a read-then-write add() with a real race window, so two
                // processes can both believe they hold it. With no unique index
                // on (client_id, hostname) to fall back on, take a row lock on
                // the client for the length of this transaction — the same
                // pessimistic-locking idiom PersonService::merge() and
                // InvoiceService use where a check-then-write must not double up.
                // The loser enters only after the winner has COMMITTED, so the
                // lookup below sees the winner's row and LINKS to it instead of
                // forking one device into two billable, client-facing assets.
                // Read through the query builder, not the model, so no global
                // scope can drop the row and quietly skip the lock.
                //
                // On SQLite lockForUpdate compiles away — the engine serializes
                // writers with its own database-level write lock inside the
                // transaction instead, which yields the same ordering here.
                DB::table('clients')->where('id', $psaClientId)->lockForUpdate()->first();

                // Deterministic pick: an exact hostname match outranks a
                // name-only match, and ties break on id. The OR below can match
                // several live unlinked assets for one client, and a bare
                // ->first() let the winner vary between runs — so which asset a
                // device adopted was luck, not a rule.
                $asset = Asset::where('client_id', $psaClientId)
                    ->whereNull('tactical_asset_id')
                    ->where(function ($q) use ($lowerHostname) {
                        $q->whereRaw('LOWER(hostname) = ?', [$lowerHostname])
                            ->orWhereRaw('LOWER(name) = ?', [$lowerHostname]);
                    })
                    ->orderByRaw('CASE WHEN LOWER(hostname) = ? THEN 0 ELSE 1 END', [$lowerHostname])
                    ->orderBy('id')
                    ->first();

                if (! $asset) {
                    $asset = $this->createAssetFromAgent($psaClientId, $agent, $result);

                    if (! $asset) {
                        return;
                    }

                    $created = true;
                }

                $asset->update(['tactical_asset_id' => $tacticalAsset->id]);
                $tacticalAsset->update(['asset_id' => $asset->id]);
            });
        } finally {
            $lock->release();
        }

        if (! $asset) {
            return;
        }

        // Counters move only after the commit, so a rolled-back create can never
        // be reported to the operator as an asset they can go find.
        if ($created) {
            $result->details['assets_created'] = ($result->details['assets_created'] ?? 0) + 1;
        }

        if (! isset($result->details['linked'])) {
            $result->details['linked'] = 0;
        }
        $result->details['linked']++;

        Log::debug('[TacticalSync] Linked agent to asset', [
            'agent' => $hostname,
            'asset_id' => $asset->id,
        ]);
    }

    /**
     * Create the PSA asset for a discovered agent, or null when creating one
     * would fork an existing device into two records.
     *
     * The link query above only considers LIVE, UNLINKED assets, so "no match"
     * is not the same as "this client has never seen this hostname". Two cases
     * must NOT create:
     *
     *  - Hostname already owned by another TacticalAsset — an agent reinstall
     *    issues a new agent_id for the same box while the stale row keeps the
     *    link. Creating would fork the device; leave the new row unlinked so the
     *    stale one can be reconciled (or removed) first.
     *  - Hostname belongs to a soft-deleted asset — the operator retired that
     *    record deliberately. Resurrecting it (or shipping a second copy) is a
     *    decision for a human, not for a read-driven sync.
     *
     * Every early return here counts into details['assets_skipped'] and is
     * reported to the operator. A device the sync deliberately refuses to create
     * is still a device the operator cannot see, and silence about it recreates
     * the original complaint one layer up.
     *
     * @param  array<string, mixed>  $agent
     */
    private function createAssetFromAgent(int $psaClientId, array $agent, SyncResult $result): ?Asset
    {
        $hostname = (string) $agent['hostname'];
        $lowerHostname = strtolower($hostname);

        // Deterministic pick, for the same reason the link query above orders:
        // one client can hold both a live and a soft-deleted match, and a bare
        // ->first() let the reported reason — and so the remedy the operator is
        // sent to — flip between runs on unchanged data. A LIVE conflict outranks
        // a trashed one (it is the record actually blocking the link), then an
        // exact hostname match outranks a name-only one, then id breaks ties.
        $conflict = Asset::withTrashed()
            ->where('client_id', $psaClientId)
            ->where(function ($q) use ($lowerHostname) {
                $q->whereRaw('LOWER(hostname) = ?', [$lowerHostname])
                    ->orWhereRaw('LOWER(name) = ?', [$lowerHostname]);
            })
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN LOWER(hostname) = ? THEN 0 ELSE 1 END', [$lowerHostname])
            ->orderBy('id')
            ->first();

        if ($conflict) {
            $this->countSkippedAsset($result, $conflict->trashed() ? 'soft_deleted_conflict' : 'hostname_conflict');

            if ($conflict->trashed()) {
                $this->recordRetiredSkip($result, $conflict, $hostname);
            }

            Log::info('[TacticalSync] Skipped asset creation — hostname already exists for this client', [
                'agent' => $hostname,
                'asset_id' => $conflict->id,
                'trashed' => $conflict->trashed(),
                'linked_tactical_asset_id' => $conflict->tactical_asset_id,
            ]);

            return null;
        }

        // Reuse the tactical_assets mapping so the asset and its agent snapshot
        // agree on every shared field (cpu/disk joining, plat sniffing, IP
        // normalization) instead of re-deriving them from the payload here.
        $mapped = $this->mapAgentToTacticalAsset($agent);

        $asset = Asset::create([
            'client_id' => $psaClientId,
            'name' => $hostname,
            'hostname' => $hostname,
            'asset_type' => $this->mapAssetType($mapped['plat'] ?? null, $agent['monitoring_type'] ?? null),
            'os' => $mapped['os'] ?? null,
            'serial_number' => $this->sanitizeSerialNumber($mapped['serial_number'] ?? null),
            'cpu' => $mapped['cpu'] ?? null,
            // tactical_assets.disk_summary is TEXT while assets.disk_summary is
            // varchar(500), and mysql/mariadb run with 'strict' => true — a
            // many-disk server would raise SQLSTATE 22001, not truncate.
            'disk_summary' => $this->fit($mapped['disk_summary'] ?? null, 500),
            'ip_address' => $this->primaryIpAddress($mapped['local_ips'] ?? null),
            'last_user' => $mapped['last_user'] ?? null,
            // Same rule the per-run refresh applies: 'offline' and 'overdue' both
            // seed false, and a status that is no observation of connectivity at
            // all seeds NULL (unknown) rather than a hard false.
            'rmm_online' => $this->rmmOnlineFromStatus($mapped['status'] ?? null),
            'last_seen_at' => $mapped['last_seen_at'] ?? null,
            'needs_reboot' => (bool) ($mapped['needs_reboot'] ?? false),
            'is_active' => true,
        ]);

        Log::info('[TacticalSync] Created PSA asset for discovered agent', [
            'agent' => $hostname,
            'asset_id' => $asset->id,
            'client_id' => $psaClientId,
        ]);

        return $asset;
    }

    /**
     * Count a device the sync chose not to create an asset for, by reason.
     *
     * details['assets_skipped'] is the total; details['assets_skipped_reasons']
     * breaks it down so the log line and the operator-facing count can never
     * disagree about why.
     */
    private function countSkippedAsset(SyncResult $result, string $reason): void
    {
        $result->details['assets_skipped'] = ($result->details['assets_skipped'] ?? 0) + 1;

        $reasons = $result->details['assets_skipped_reasons'] ?? [];
        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        $result->details['assets_skipped_reasons'] = $reasons;
    }

    /**
     * Name the retired (soft-deleted) asset that blocked a create, so a run that
     * reports "0 assets" says which record is in the way.
     *
     * One row per retired asset, in first-skip order, capped at
     * RETIRED_SKIP_LIST_LIMIT. A second agent blocked by an asset already listed
     * bumps that row's skipped_agents instead of adding a row, so repeats cannot
     * use up the cap. details['assets_skipped_retired_total'] counts distinct
     * retired assets, listed or not; the soft_deleted_conflict counter counts
     * agents. Two hostnames are kept because they can differ: the conflict
     * lookup matches the agent's hostname against the asset's hostname OR its
     * name, case-insensitively.
     *  - agent_hostname: what Tactical reported for the FIRST agent skipped
     *    against this asset;
     *  - asset_hostname: the retired asset's own hostname column (may be null
     *    when the match was on name).
     * restorable is Asset::restoreRefusal() === null, the rule the restore_asset
     * tool applies; a merge tombstone is retired but is not restorable, and
     * carries the survivor in merged_into_asset_id.
     * still_linked is whether the retired asset still carries a
     * tactical_asset_id. The link query above only adopts assets with no
     * tactical_asset_id, so restoring such an asset does not link the skipped
     * agent to it: the next sync finds it live and skips as hostname_conflict.
     */
    private function recordRetiredSkip(SyncResult $result, Asset $conflict, string $agentHostname): void
    {
        $this->retiredSeen ??= new \WeakMap;
        $seen = $this->retiredSeen[$result] ?? [];
        $firstSkip = ! isset($seen[$conflict->id]);
        $seen[$conflict->id] = true;
        $this->retiredSeen[$result] = $seen;
        $result->details['assets_skipped_retired_total'] = count($seen);

        $rows = $result->details['assets_skipped_retired'] ?? [];

        if (! $firstSkip) {
            foreach ($rows as $i => $row) {
                if ($row['asset_id'] === $conflict->id) {
                    $rows[$i]['skipped_agents']++;
                    $result->details['assets_skipped_retired'] = $rows;
                }
            }

            return;
        }

        if (count($rows) >= self::RETIRED_SKIP_LIST_LIMIT) {
            return;
        }

        $rows[] = [
            'asset_id' => $conflict->id,
            'agent_hostname' => $agentHostname,
            'asset_hostname' => $conflict->hostname,
            'restorable' => $conflict->restoreRefusal() === null,
            'merged_into_asset_id' => $conflict->merged_into_asset_id,
            'still_linked' => $conflict->tactical_asset_id !== null,
            'skipped_agents' => 1,
        ];

        $result->details['assets_skipped_retired'] = $rows;
    }

    /**
     * One operator-facing line for an assets_skipped_retired row, shared by the
     * Integrations banner and tactical:sync-devices so the two cannot word the
     * same row differently. Restorability is read from the row's own flag
     * (Asset::restoreRefusal() at collection time), never re-derived here.
     *
     * @param  array{asset_id: int, agent_hostname: string, asset_hostname: ?string, restorable: bool, merged_into_asset_id: ?int, still_linked?: bool, skipped_agents?: int}  $row
     */
    public static function describeRetiredSkip(array $row): string
    {
        $line = "asset #{$row['asset_id']} (agent hostname {$row['agent_hostname']}";

        $others = (int) ($row['skipped_agents'] ?? 1) - 1;
        if ($others > 0) {
            $line .= " and {$others} more agent".($others === 1 ? '' : 's');
        }

        $assetHostname = $row['asset_hostname'] ?? null;
        if ($assetHostname !== null && $assetHostname !== '' && strcasecmp($assetHostname, $row['agent_hostname']) !== 0) {
            $line .= ", asset hostname {$assetHostname}";
        }
        $line .= ')';

        if ($row['restorable']) {
            if ($row['still_linked'] ?? false) {
                return $line.' — restorable from its asset page, but it still carries a Tactical agent link, so restoring it alone will not link '
                    .($others > 0 ? 'these agents' : 'this agent').' to it';
            }

            return $line.' — restorable from its asset page';
        }

        return $row['merged_into_asset_id'] !== null
            ? $line." — merged into asset #{$row['merged_into_asset_id']}, cannot be restored"
            : $line.' — cannot be restored';
    }

    /**
     * Drop OEM placeholder serials to NULL before they reach an Asset record.
     *
     * Tactical reports "To be filled by O.E.M.", "Default string", "System Serial
     * Number" and friends verbatim for whole fleets of machines. Nothing matches
     * on serial today — #333 deliberately kept matching on hostname for exactly
     * this reason — but the create path was writing these strings onto real Asset
     * records, so the first person to add serial matching would inherit a column
     * where hundreds of unrelated devices share a value. NULL is the honest
     * representation of "the hardware did not report a serial".
     *
     * The retro sharpened the stakes: assets.serial_number is already a GLOBAL
     * match key for OTHER syncs. NinjaSyncService and LevelSyncService each look
     * an incoming device up by serial_number with NO client_id scope and then
     * rewrite client_id/hostname/name on whatever they find, so seeding a
     * placeholder at fleet scale hands one client's asset — with its tickets,
     * contracts and notes — to another client's device. This is not a
     * future-tense hazard; it is live cross-client contamination.
     *
     * Matching is case- and space-insensitive because the same placeholder
     * arrives punctuated differently across vendors ("O.E.M." vs "OEM"), and the
     * list is a superset of NinjaSyncService::resolveSerial()'s junk values so
     * the two discovery paths cannot disagree about what counts as a serial.
     */
    private function sanitizeSerialNumber(?string $serial): ?string
    {
        if ($serial === null) {
            return null;
        }

        $trimmed = trim($serial);

        if ($trimmed === '') {
            return null;
        }

        $normalized = preg_replace('/[^a-z0-9]/', '', strtolower($trimmed));

        $placeholders = [
            'tobefilledbyoem',
            'defaultstring',
            // From NinjaSyncService::resolveSerial() — kept in step deliberately.
            'standard',
            'systemserialnumber',
            'chassisserialnumber',
            'baseboardserialnumber',
            'serialnumber',
            'notspecified',
            'notapplicable',
            'na',
            'none',
            'null',
            'unknown',
            'invalid',
            'default',
            '0',
            '00000000',
        ];

        return in_array($normalized, $placeholders, true) ? null : $trimmed;
    }

    /**
     * Tactical's status vocabulary → rmm_online, or NULL when the status is not
     * an observation of connectivity.
     *
     * 'online', 'offline' and 'overdue' are all observations of contact, and
     * they come off ONE clock. Tactical's Agent.status reads 'offline' once
     * last_seen is older than offline_time (default 4 min) but newer than
     * overdue_time (default 30 min), and 'overdue' once it is older than BOTH.
     * 'overdue' is therefore the longest out-of-contact state — a box powered
     * off for a week reports 'overdue', not 'offline' — so it records the same
     * hard false. Mapping it to NULL would rank Tactical's strongest
     * down-evidence below the state it outranks, and because a machine that
     * stays down never re-enters the 4–30 minute 'offline' window, nothing would
     * ever write the false: the asset would sit under "unknown" and drop out of
     * the Assets Offline filter permanently.
     *
     * Any value Tactical adds later is NOT assumed to be a contact observation
     * and yields NULL — the honest "we do not know", which every reader already
     * scores off last_seen_at instead. That matters because a false has no
     * staleness escape anywhere (isRmmDataStale gates only a TRUE flag), so it
     * is only ever written for a status we know means out of contact.
     */
    public function rmmOnlineFromStatus(?string $status): ?bool
    {
        return match ($status) {
            'online' => true,
            'offline', 'overdue' => false,
            default => null,
        };
    }

    /**
     * Fit a value to an asset column's width. The agent snapshot's columns are
     * wider than the asset's, and strict SQL mode errors rather than truncating.
     */
    private function fit(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * The one address a technician can actually reach the machine on, or null.
     *
     * local_ips is every adapter the agent sees, in the order the agent listed
     * them — a workstation with Hyper-V/VirtualBox/VPN adapters commonly reports
     * a host-only address first, so element 0 is not "the IP". Loopback,
     * link-local/APIPA and malformed entries are dropped, then:
     *
     *  - exactly one IPv4 survives → that is the address. IPv6 is on by default
     *    on current Windows and macOS builds, so an ordinary dual-stack endpoint
     *    reports an unambiguous IPv4 alongside one or more IPv6 addresses.
     *    Requiring a single surviving candidate would blank the field for most
     *    of the fleet — worse than the element-0 pick it replaced — and the
     *    column is written at creation only, so it would never heal.
     *  - several IPv4s survive → genuinely ambiguous (the virtual-adapter case
     *    this method exists for), so the operator-visible field stays empty
     *    rather than publishing an address that may not answer.
     *  - no IPv4 at all → a single surviving IPv6 is the address; more than one
     *    is ambiguous by the same rule.
     *
     * The full list remains on the tactical_assets snapshot either way.
     *
     * @param  array<int, mixed>|string|null  $localIps
     */
    private function primaryIpAddress(array|string|null $localIps): ?string
    {
        $candidates = [];

        foreach (is_array($localIps) ? $localIps : [$localIps] as $ip) {
            $ip = is_string($ip) ? trim($ip) : '';

            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $lower = strtolower($ip);

            if ($lower === '0.0.0.0' || $lower === '::' || $lower === '::1'
                || str_starts_with($lower, '127.')
                || str_starts_with($lower, '169.254.')
                || str_starts_with($lower, 'fe80:')) {
                continue;
            }

            $candidates[$ip] = true;
        }

        $candidates = array_keys($candidates);

        $ipv4 = array_values(array_filter(
            $candidates,
            static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
        ));

        if ($ipv4 !== []) {
            return count($ipv4) === 1 ? $ipv4[0] : null;
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * asset_type for a discovered device, in the same vocabulary the Ninja sync
     * writes ("Windows Workstation", "Windows Server", "Mac", "Linux Server", …)
     * so the Assets type filter stays one list instead of two dialects.
     *
     * Both inputs are honestly optional: an unknown platform yields null rather
     * than a guessed type, matching TacticalPlatform's "never assume" contract.
     */
    private function mapAssetType(?string $plat, ?string $monitoringType): ?string
    {
        // macOS agents are just "Mac" in the Ninja vocabulary — no server split.
        if ($plat === TacticalPlatform::DARWIN) {
            return 'Mac';
        }

        $os = match ($plat) {
            TacticalPlatform::WINDOWS => 'Windows',
            TacticalPlatform::LINUX => 'Linux',
            default => null,
        };

        if ($os === null) {
            return null;
        }

        return match ($monitoringType) {
            'server' => "{$os} Server",
            'workstation' => "{$os} Workstation",
            default => $os,
        };
    }
}
