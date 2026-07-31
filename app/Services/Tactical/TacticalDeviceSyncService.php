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

            // Link to PSA asset if not already linked — creating the asset when
            // Tactical is the discovery source for this device.
            //
            // Per-agent isolation (mirrors NinjaSyncService's device loop): one
            // agent's write failure must not abort the run. Without it a single
            // bad row skips every later agent AND the not-seen→offline sweep
            // below, so decommissioned agents keep reading "online".
            try {
                if (! $tacticalAsset->asset_id) {
                    $this->linkOrCreateAsset($tacticalAsset, $psaClientId, $agent, $result);
                }

                // Refresh the linked asset from THIS run's snapshot. rmm_online
                // and last_seen_at are read as CURRENT truth by the Assets list
                // badge and AssetHealthService::connectivityFactor(), so writing
                // them once at creation and never again would assert a frozen
                // connectivity state forever (psa-wedk: never present synced
                // state as current truth).
                if ($tacticalAsset->asset_id) {
                    $refresh = ['rmm_online' => $tacticalAsset->status === 'online'];

                    if ($tacticalAsset->last_seen_at) {
                        $refresh['last_seen_at'] = $tacticalAsset->last_seen_at;
                    }

                    if ($agent['logged_username'] ?? null) {
                        $refresh['last_user'] = $agent['logged_username'];
                    }

                    Asset::where('id', $tacticalAsset->asset_id)->update($refresh);
                }
            } catch (\Throwable $e) {
                Log::warning('[TacticalSync] Agent skipped after a write failure', [
                    'agent_id' => $agentId,
                    'error' => $e->getMessage(),
                ]);
                $result->recordError("Agent {$agentId}: {$e->getMessage()}");
            }
        }

        // Mark agents not seen in this sync as offline (only on full sync, not client-scoped)
        if (! $clientId && $fetchSucceeded) {
            $stale = TacticalAsset::whereNotIn('agent_id', $seenAgentIds)
                ->where('status', '!=', 'offline');

            // Capture the linked assets BEFORE flipping the agents: their
            // rmm_online has to go offline with the agent, or the Assets list
            // keeps asserting "Online per RMM" for a device this run never saw.
            $staleAssetIds = (clone $stale)->whereNotNull('asset_id')->pluck('asset_id')->all();

            $staleCount = $stale->update(['status' => 'offline', 'synced_at' => now()]);

            if (! empty($staleAssetIds)) {
                Asset::whereIn('id', $staleAssetIds)->update(['rmm_online' => false]);
            }

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
            DB::transaction(function () use ($tacticalAsset, $psaClientId, $agent, $lowerHostname, &$asset, &$created) {
                $asset = Asset::where('client_id', $psaClientId)
                    ->whereNull('tactical_asset_id')
                    ->where(function ($q) use ($lowerHostname) {
                        $q->whereRaw('LOWER(hostname) = ?', [$lowerHostname])
                            ->orWhereRaw('LOWER(name) = ?', [$lowerHostname]);
                    })
                    ->first();

                if (! $asset) {
                    $asset = $this->createAssetFromAgent($psaClientId, $agent);

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
     * @param  array<string, mixed>  $agent
     */
    private function createAssetFromAgent(int $psaClientId, array $agent): ?Asset
    {
        $hostname = (string) $agent['hostname'];
        $lowerHostname = strtolower($hostname);

        $conflict = Asset::withTrashed()
            ->where('client_id', $psaClientId)
            ->where(function ($q) use ($lowerHostname) {
                $q->whereRaw('LOWER(hostname) = ?', [$lowerHostname])
                    ->orWhereRaw('LOWER(name) = ?', [$lowerHostname]);
            })
            ->first();

        if ($conflict) {
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
            'serial_number' => $this->realSerial($mapped['serial_number'] ?? null),
            'cpu' => $mapped['cpu'] ?? null,
            // tactical_assets.disk_summary is TEXT while assets.disk_summary is
            // varchar(500), and mysql/mariadb run with 'strict' => true — a
            // many-disk server would raise SQLSTATE 22001, not truncate.
            'disk_summary' => $this->fit($mapped['disk_summary'] ?? null, 500),
            'ip_address' => $this->primaryIpAddress($mapped['local_ips'] ?? null),
            'last_user' => $mapped['last_user'] ?? null,
            'rmm_online' => ($mapped['status'] ?? null) === 'online',
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
     * The agent's serial, or null when it is a known OEM placeholder.
     *
     * assets.serial_number is a GLOBAL match key for OTHER syncs: NinjaSyncService
     * and LevelSyncService each look an incoming device up by serial_number with
     * NO client_id scope and then rewrite client_id/hostname/name on whatever they
     * find. Seeding "To Be Filled By O.E.M." at fleet scale would therefore hand
     * one client's asset — with its tickets, contracts and notes — to another
     * client's device. Same junk list as NinjaSyncService::resolveSerial(), which
     * exists for exactly this reason; the raw value stays on the agent snapshot.
     */
    private function realSerial(mixed $serial): ?string
    {
        $junkValues = ['standard', 'default string', 'to be filled by o.e.m.', 'none', 'not specified', 'system serial number'];

        $serial = is_string($serial) ? trim($serial) : '';

        if ($serial === '' || in_array(strtolower($serial), $junkValues, true)) {
            return null;
        }

        return $serial;
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
     * link-local/APIPA and malformed entries are dropped; if more than one
     * candidate survives, the payload does not say which one is the machine's,
     * so the operator-visible field stays empty rather than publishing an address
     * that may not answer. The full list remains on the tactical_assets snapshot
     * either way.
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
