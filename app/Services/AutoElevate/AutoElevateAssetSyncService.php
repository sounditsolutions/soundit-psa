<?php

namespace App\Services\AutoElevate;

use App\Models\Asset;
use App\Models\Client;
use App\Services\AssetSources\SourceSyncPipeline;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * AutoElevate stage 3a: link vendor computers to PSA assets. Pattern: ControlDDeviceSyncService.
 *
 * SCOPING IS THE WHOLE POINT. Hostnames repeat across clients (measured 2026-09: 566 assets,
 * many shared names), so a computer is ONLY ever matched against live assets of the ONE client
 * whose `autoelevate_company_id` it was read under. Company scope itself is proven upstream by
 * AutoElevateReadService::normalizeComputer() (a row from another company is `row_drift`), so
 * this layer does not re-implement it; it composes computersForCompany() per mapped client.
 *
 * Never creates an asset. A computer with no hostname match in its client is REPORTED
 * (count + per client); one whose name fits 2+ live assets, or whose name 2+ computers share,
 * is reported as ambiguous and left unlinked rather than guessed.
 *
 * A failed read for a client changes NOTHING on that client's assets (C-56: a degraded read
 * is not evidence the machines are gone). Links are cleared only after a SUCCESSFUL read that
 * did not return the computer, or when the asset's client is no longer mapped.
 *
 * Written values: the vendor's elevationMode VERBATIM (null stays null — never defaulted to
 * "audit"; an unrecognised value is kept and flagged at render), lastCheckedInAt (already a UTC
 * instant from normalizeComputer), synced_at. `autoelevate_agent_version` is NOT written: the
 * Partner API 1.0.0 Computer schema has no such field (verified 2026-09-22).
 *
 * PACING AND EARLY STOP (#3420, #3414). The vendor rate-limits reads per route (see RATE
 * LIMITS in AutoElevateReadService's class docblock). Measured 2026-09-23: back-to-back reads of 57
 * clients gave 33 × http_429; reads 4-8 s apart gave 8 of 8 ok. So sync() waits
 * CLIENT_SPACING_SECONDS before every client read except the first. It stops reading once
 * STOP_AFTER_CONSECUTIVE_429 clients IN A ROW fail with http_429 (the limiter is not
 * recovering, and every further client would spend its retries for the same failure), or once
 * MAX_RUN_SECONDS have passed; the deadline is checked between clients, so a read already in
 * progress runs to its own bound. Clients left unread are reported as SKIPPED: no read was
 * made, so exactly as for a failed read their links and recorded outcome are left unchanged,
 * and clearUnmappedClients() still treats them as mapped.
 */
class AutoElevateAssetSyncService
{
    /** Wait before each client read after the first (vendor refill is about one request per 3 s). */
    public const CLIENT_SPACING_SECONDS = 4;

    /** Consecutive clients failing with http_429 after which the run stops reading. */
    public const STOP_AFTER_CONSECUTIVE_429 = 3;

    /** Run deadline, checked before each client read. */
    public const MAX_RUN_SECONDS = 1800;

    /**
     * The hourly schedule's own withoutOverlapping mutex name (routes/console.php sets it with
     * createMutexNameUsing). Named here so the asset page's Sync now can ask whether the
     * scheduled run holds it (runInProgress) without inventing a second lock (Q-2 B, M1.10).
     */
    public const RUN_MUTEX = 'framework/schedule-autoelevate-sync-assets';

    /** The schedule's overlap lock lifetime in minutes (withoutOverlapping(55)). */
    public const RUN_MUTEX_MINUTES = 55;

    public function __construct(private readonly AutoElevateReadService $reads) {}

    /**
     * Is the scheduled hourly run holding its withoutOverlapping mutex right now? Asked of the
     * scheduler's own EventMutex (the same resolution Schedule uses: a bound EventMutex, else
     * CacheEventMutex) under the event's own name, RUN_MUTEX. A run started by hand with
     * `php artisan autoelevate:sync-assets` takes no scheduler mutex, so it is not seen here.
     */
    public static function runInProgress(): bool
    {
        $mutex = app()->bound(EventMutex::class) ? app(EventMutex::class) : app(CacheEventMutex::class);
        $event = new ScheduleEvent($mutex, 'autoelevate:sync-assets');
        $event->createMutexNameUsing(self::RUN_MUTEX);
        $event->expiresAt = self::RUN_MUTEX_MINUTES;

        return $mutex->exists($event);
    }

    /**
     * Start (or extend) the shared AutoElevate vendor cooldown that Sync now reads
     * (SourceSyncPipeline::vendorCooldownKey). Written by every 429 that ends a read, from
     * Sync now and from the scheduled run alike; an earlier, longer cooldown is never shortened.
     */
    public static function startVendorCooldown(int $seconds): void
    {
        $key = SourceSyncPipeline::vendorCooldownKey('autoelevate');
        $until = max((int) Cache::get($key, 0), now()->getTimestamp() + $seconds);
        Cache::put($key, $until, max(1, $until - now()->getTimestamp()));
    }

    /** Seconds left on the shared AutoElevate vendor cooldown; 0 when none is live. */
    public static function vendorCooldownRemaining(): int
    {
        return max(0, (int) Cache::get(SourceSyncPipeline::vendorCooldownKey('autoelevate'), 0) - now()->getTimestamp());
    }

    /**
     * Hostname match key: trimmed, lowercased, first DNS label (so "WS-01.corp.local" and
     * "ws-01" meet, as Control D's matcher allows). Empty → null (never matchable).
     */
    public static function normalizeHostname(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $short = explode('.', mb_strtolower(trim($name)))[0];

        return $short === '' ? null : $short;
    }

    public const OUTCOME_CACHE_PREFIX = 'autoelevate_asset_sync_client_';

    /**
     * The asset page's empty state must say WHY an asset is unlinked (C-56), and "the last
     * read for this client failed" is not visible in the asset row. The per-client outcome of
     * the last run is kept in the cache; if it is evicted the page says "no sync recorded",
     * which is still true, rather than guessing "no vendor match". The outcome also records WHICH
     * company was read and every asset the read considered (id => hostname key). An asset added
     * or renamed since, or a client re-mapped since, is therefore "not synced", never "no match".
     *
     * @param  array<int, ?string>  $considered  asset id => normalizeHostname() at read time (ok reads only)
     */
    public static function recordClientOutcome(int $clientId, string $companyId, bool $ok, ?string $reason, array $considered = []): void
    {
        Cache::forever(self::OUTCOME_CACHE_PREFIX.$clientId, [
            'ok' => $ok,
            'reason' => $reason,
            'company_id' => strtolower($companyId),
            'considered' => $considered,
            'at' => now()->toIso8601String(),
        ]);
    }

    public static function forgetClientOutcome(int $clientId): void
    {
        Cache::forget(self::OUTCOME_CACHE_PREFIX.$clientId);
    }

    /** @return array{ok: bool, reason: ?string, company_id: string, considered: array<int, ?string>, at: string}|null */
    public static function lastClientOutcome(int $clientId): ?array
    {
        $outcome = Cache::get(self::OUTCOME_CACHE_PREFIX.$clientId);

        return is_array($outcome) ? $outcome : null;
    }

    /**
     * Which of the asset page's explicit states applies (C-56 — an unlinked asset says WHY):
     *   linked        the asset carries an AutoElevate computer id
     *   not_mapped    its client has no AutoElevate company, so nothing was ever read
     *   read_failed   the client's last sync read failed (reason = the fixed read label)
     *   no_match      the last read of the client's CURRENT company succeeded, considered this
     *                 asset under its current hostname, and no computer matched it
     *   not_synced    mapped, but no recorded outcome covers this asset: never synced, evicted,
     *                 re-mapped since, or the asset was added/renamed after the last read
     *
     * @return array{state: string, reason: ?string}
     */
    public static function assetLinkState(Asset $asset): array
    {
        if ($asset->autoelevate_computer_id !== null && $asset->autoelevate_computer_id !== '') {
            return ['state' => 'linked', 'reason' => null];
        }
        $companyId = $asset->client?->autoelevate_company_id;
        if ($companyId === null || $companyId === '') {
            return ['state' => 'not_mapped', 'reason' => null];
        }
        $outcome = $asset->client_id ? self::lastClientOutcome($asset->client_id) : null;
        // An outcome read under another company says nothing about the current mapping.
        if ($outcome === null || ($outcome['company_id'] ?? null) !== strtolower($companyId)) {
            return ['state' => 'not_synced', 'reason' => null];
        }
        if (! $outcome['ok']) {
            return ['state' => 'read_failed', 'reason' => $outcome['reason']];
        }
        // "No match" only for an asset that read actually considered, under its current hostname.
        $considered = $outcome['considered'] ?? [];
        if (! array_key_exists($asset->id, $considered)
            || $considered[$asset->id] !== self::normalizeHostname($asset->hostname)) {
            return ['state' => 'not_synced', 'reason' => null];
        }

        return ['state' => 'no_match', 'reason' => null];
    }

    public function sync(): AutoElevateAssetSyncReport
    {
        $report = new AutoElevateAssetSyncReport;

        $clients = Client::query()
            // No `!= ''` clause: the column is Laravel ->uuid(), a native `uuid` type on MariaDB
            // 10.7+, which cannot hold '' — and there `uuid_col != ''` is false for EVERY row, so
            // that clause selected zero clients on production while the SQLite suite stayed green.
            ->whereNotNull('autoelevate_company_id')
            ->operational()
            ->orderBy('id')
            ->get();

        $deadline = now()->addSeconds(self::MAX_RUN_SECONDS);
        $consecutive429 = 0;

        foreach ($clients->values() as $i => $client) {
            if ($report->stoppedEarly === null) {
                if ($consecutive429 >= self::STOP_AFTER_CONSECUTIVE_429) {
                    $report->stoppedEarly = AutoElevateAssetSyncReport::STOP_CONSECUTIVE_429;
                } elseif ($i > 0 && now()->greaterThanOrEqualTo($deadline)) {
                    $report->stoppedEarly = AutoElevateAssetSyncReport::STOP_DEADLINE;
                }
                if ($report->stoppedEarly !== null) {
                    Log::warning('[AutoElevateAssetSync] stopped early', ['reason' => $report->stoppedEarly]);
                }
            }
            if ($report->stoppedEarly !== null) {
                // Not read: links and recorded outcome stay exactly as they were.
                $report->recordSkipped($client->id);

                continue;
            }

            if ($i > 0) {
                Sleep::for(self::CLIENT_SPACING_SECONDS)->seconds();
            }

            $report->forClient($client->id);
            try {
                $computers = $this->reads->computersForCompany($client->autoelevate_company_id);
            } catch (AutoElevateReadException $e) {
                // Client id + fixed reason label only: no client name, no vendor text.
                Log::warning('[AutoElevateAssetSync] read failed', ['client_id' => $client->id, 'reason' => $e->reason]);
                $report->recordFailure($client->id, $e->reason);
                self::recordClientOutcome($client->id, $client->autoelevate_company_id, false, $e->reason);
                if ($e->reason === 'http_429') {
                    // Shared budget, both ways (M1.6): a 429 that ends this read cools Sync now too.
                    self::startVendorCooldown($e->retryAfterS ?? AutoElevateClient::COOLDOWN_FALLBACK_SECONDS);
                }
                $consecutive429 = $e->reason === 'http_429' ? $consecutive429 + 1 : 0;

                continue;
            }
            $consecutive429 = 0;

            $considered = DB::transaction(fn () => $this->syncClient($client, $computers, $report));
            self::recordClientOutcome($client->id, $client->autoelevate_company_id, true, null, $considered);
        }

        // Every MAPPED client, skipped ones included: a skipped client is still mapped, and
        // clearing its links here would turn "not read" into "links cleared".

        $this->clearUnmappedClients($clients->pluck('id')->all(), $report);

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $computers  rows from AutoElevateReadService::normalizeComputer()
     * @return array<int, ?string> asset id => hostname key of every live asset this read considered
     */
    public function syncClient(Client $client, array $computers, AutoElevateAssetSyncReport $report): array
    {
        // Live assets of THIS client only. SoftDeletes' default scope excludes trashed rows,
        // so a soft-deleted asset can neither be matched nor block a match.
        $assets = Asset::where('client_id', $client->id)->get();

        $byHostname = [];
        $considered = [];
        foreach ($assets as $asset) {
            $key = self::normalizeHostname($asset->hostname);
            $considered[$asset->id] = $key;
            if ($key !== null) {
                $byHostname[$key][] = $asset;
            }
        }

        // Two computers answering to one name in one company cannot both be the same asset.
        $nameCounts = [];
        foreach ($computers as $computer) {
            $key = self::normalizeHostname($computer['machine_name']);
            if ($key !== null) {
                $nameCounts[$key] = ($nameCounts[$key] ?? 0) + 1;
            }
        }

        $seenAssetIds = [];
        foreach ($computers as $computer) {
            $label = $computer['machine_name'] ?? '(no machine name)';

            // 1. An existing link inside this client survives a hostname change (re-sync).
            $asset = $assets->first(fn (Asset $a) => $a->autoelevate_computer_id !== null
                && strcasecmp($a->autoelevate_computer_id, $computer['id']) === 0);

            // 2. Otherwise, by hostname within this client.
            if ($asset === null) {
                $key = self::normalizeHostname($computer['machine_name']);
                if ($key === null) {
                    $report->recordUnmatched($client->id, $label);

                    continue;
                }
                if (($nameCounts[$key] ?? 0) > 1) {
                    $report->recordAmbiguous($client->id, $label);

                    continue;
                }
                // Skip assets already holding a different computer's link.
                $candidates = array_values(array_filter(
                    $byHostname[$key] ?? [],
                    fn (Asset $a) => $a->autoelevate_computer_id === null
                        || strcasecmp($a->autoelevate_computer_id, $computer['id']) === 0,
                ));
                if (count($candidates) > 1) {
                    $report->recordAmbiguous($client->id, $label);

                    continue;
                }
                if ($candidates === []) {
                    $report->recordUnmatched($client->id, $label);

                    continue;
                }
                $asset = $candidates[0];
            }

            if (isset($seenAssetIds[$asset->id])) {
                // Defensive: never let a second computer overwrite a link made this run.
                $report->recordAmbiguous($client->id, $label);

                continue;
            }

            $this->writeLink($asset, $computer);
            $seenAssetIds[$asset->id] = true;
            $report->recordLinked($client->id);
        }
        // No cross-client "release" step here, deliberately: this method never writes outside
        // $client, and a company re-mapped to another client leaves the old client unmapped,
        // which clearUnmappedClients() handles. A cross-client clear would also MASK a scoping
        // regression (it undid exactly such a mutant's cross-link during the red check).

        // Successful read that did not return a linked computer: release that link.
        $report->cleared += Asset::where('client_id', $client->id)
            ->whereNotNull('autoelevate_computer_id')
            ->when($seenAssetIds !== [], fn ($q) => $q->whereNotIn('id', array_keys($seenAssetIds)))
            ->update($this->clearedColumns());

        return $considered;
    }

    /**
     * @param  array<string, mixed>  $computer
     */
    private function writeLink(Asset $asset, array $computer): void
    {
        $asset->forceFill([
            'autoelevate_computer_id' => $computer['id'],
            'autoelevate_elevation_mode' => $computer['elevation_mode'],
            'autoelevate_last_checked_in_at' => $computer['last_checked_in_at'],
            'autoelevate_synced_at' => now(),
        ])->save();
    }

    /** Links on live assets whose client is no longer mapped (or no longer operational). */
    private function clearUnmappedClients(array $mappedClientIds, AutoElevateAssetSyncReport $report): void
    {
        $report->cleared += Asset::whereNotNull('autoelevate_computer_id')
            ->when($mappedClientIds !== [], fn ($q) => $q->whereNotIn('client_id', $mappedClientIds))
            ->update($this->clearedColumns());

        // Their recorded outcome no longer describes them. Without this, a client that lost its
        // mapping or operational status would keep claiming "no match" from an old read.
        Client::query()
            ->when($mappedClientIds !== [], fn ($q) => $q->whereNotIn('id', $mappedClientIds))
            ->pluck('id')
            ->each(fn ($id) => self::forgetClientOutcome((int) $id));
    }

    /** @return array<string, mixed> */
    private function clearedColumns(): array
    {
        return [
            'autoelevate_computer_id' => null,
            'autoelevate_elevation_mode' => null,
            'autoelevate_agent_version' => null,
            'autoelevate_last_checked_in_at' => null,
            'autoelevate_synced_at' => now(),
        ];
    }
}
