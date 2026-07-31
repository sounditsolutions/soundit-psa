<?php

namespace App\Services\Tactical;

use App\Enums\TechnicianRunState;
use App\Jobs\SweepQueuedActionsForAgent;
use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalAsset;
use App\Models\TechnicianRun;
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

            return DetailSyncResult::degraded(
                'Could not reach the agent — showing the last sync.',
                status: $ta->status,
                freshAsOf: $ta->synced_at,
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
        if (! $wasOnline && $ta->status === 'online') {
            $this->dispatchSweepIfQueued((string) $ta->agent_id);
        }

        return DetailSyncResult::success($ta->status, $ta->synced_at);
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
     * Operator-safe rendering of a per-agent write failure.
     *
     * A QueryException's getMessage() embeds the failed statement AND its
     * bindings, and the bindings here are the asset row we were writing —
     * hostname, serial, logged-in username, LAN address. That string does not
     * stay in one place: SyncResult::$errorMessages is rendered by the
     * integrations settings view, returned verbatim by
     * StaffTacticalAdminToolExecutor (an MCP surface), and written to
     * storage/logs/laravel.log, which has no rotation configured. So the raw
     * message never leaves this method.
     *
     * What an operator can actually act on is the failure class and the
     * driver's SQLSTATE, which is what they get. agent_id is already carried
     * alongside, so the failing row stays identifiable without reproducing its
     * contents.
     */
    private function safeWriteFailure(\Throwable $e): string
    {
        if ($e instanceof QueryException) {
            $sqlState = (string) $e->getCode();

            return 'database write failed'.($sqlState !== '' ? " (SQLSTATE {$sqlState})" : '');
        }

        return class_basename($e).' during write';
    }

    public function syncDevices(?int $clientId = null): SyncResult
    {
        $result = new SyncResult;

        // Build client mapping: tactical site key ("ClientName|SiteName") → PSA client_id
        $clientMap = Client::whereNotNull('tactical_site_id')
            ->operational()
            ->pluck('id', 'tactical_site_id')
            ->all();

        if (empty($clientMap)) {
            Log::info('[TacticalSync] No clients mapped to Tactical RMM sites');

            return $result;
        }

        $fetchSucceeded = false;

        try {
            $agents = $this->client->getAgents();
            $fetchSucceeded = true;
        } catch (\Throwable $e) {
            Log::warning("[TacticalSync] Failed to fetch agents: {$e->getMessage()}");
            $result->recordError("Failed to fetch agents: {$e->getMessage()}");

            return $result;
        }

        $seenAgentIds = [];

        // Pre-sync status of agents that have queued offline actions, so an
        // offline→online flip in this run can dispatch their queue (bd psa-xr84)
        // without a per-agent lookup inside the loop.
        $queuedAgentStatus = TacticalAsset::query()
            ->whereIn('agent_id', TechnicianRun::query()
                ->where('state', TechnicianRunState::QueuedOffline->value)
                ->where('expires_at', '>', now())
                ->whereNotNull('queued_agent_id')
                ->distinct()
                ->pluck('queued_agent_id'))
            ->pluck('status', 'agent_id');

        foreach ($agents as $agent) {
            $agentId = $agent['agent_id'] ?? null;
            if (! $agentId) {
                continue;
            }

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
                    SweepQueuedActionsForAgent::dispatch((string) $agentId);
                }

                // Link to PSA asset if not already linked — creating the asset
                // when Tactical is the discovery source for this device.
                if (! $tacticalAsset->asset_id) {
                    $this->linkOrCreateAsset($tacticalAsset, $psaClientId, $agent, $result);
                }

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
            } catch (\Throwable $e) {
                $safe = $this->safeWriteFailure($e);
                Log::warning('[TacticalSync] Agent skipped after a write failure', [
                    'agent_id' => $agentId,
                    'error' => $safe,
                ]);
                $result->recordError("Agent {$agentId}: {$safe}");
            }
        }

        // Mark agents not seen in this sync as offline (only on full sync, not client-scoped)
        if (! $clientId && $fetchSucceeded) {
            $stale = TacticalAsset::whereNotIn('agent_id', $seenAgentIds)
                ->where('status', '!=', 'offline');

            // The AGENT snapshot goes offline — that row is exactly "what
            // Tactical last told us about this agent_id", and Tactical stopped
            // telling us. The linked ASSET is deliberately left alone: "absent
            // from this run's payload" is UNKNOWN, not offline.
            //
            // The not-seen set is much wider than "the machine is off". It also
            // holds every agent whose siteKey no longer maps to an operational
            // client (skipped above by `continue`, before $seenAgentIds is
            // appended — a site rename in Tactical, or a client leaving
            // stage=Active, silently moves a whole fleet into it), and the stale
            // row an agent REINSTALL leaves behind once the new agent_id cannot
            // claim the hostname. Those are live, reporting machines.
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
            $staleCount = $stale->update(['status' => 'offline', 'synced_at' => now()]);

            if ($staleCount > 0) {
                $result->deactivated += $staleCount;
                Log::info("[TacticalSync] Marked {$staleCount} agent(s) as offline (not seen in API response)");
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
            $result->recordError("Could not acquire the asset lock for {$hostname}: {$e->getMessage()}");

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
    private function rmmOnlineFromStatus(?string $status): ?bool
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
