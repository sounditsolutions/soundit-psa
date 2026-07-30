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
            if (! $tacticalAsset->asset_id) {
                $this->linkOrCreateAsset($tacticalAsset, $psaClientId, $agent, $result);
            }

            // Update the linked asset's last_user if available
            if ($tacticalAsset->asset_id && ($agent['logged_username'] ?? null)) {
                Asset::where('id', $tacticalAsset->asset_id)
                    ->update(['last_user' => $agent['logged_username']]);
            }
        }

        // Mark agents not seen in this sync as offline (only on full sync, not client-scoped)
        if (! $clientId && $fetchSucceeded) {
            $staleCount = TacticalAsset::whereNotIn('agent_id', $seenAgentIds)
                ->where('status', '!=', 'offline')
                ->update(['status' => 'offline', 'synced_at' => now()]);

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

        // Deterministic pick: an exact hostname match outranks a name-only match,
        // and ties break on id. The OR below can match several live unlinked
        // assets for one client, and a bare ->first() let the winner vary between
        // runs — so which asset a device adopted was luck, not a rule.
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

            $result->details['assets_created'] = ($result->details['assets_created'] ?? 0) + 1;
        }

        $asset->update(['tactical_asset_id' => $tacticalAsset->id]);
        $tacticalAsset->update(['asset_id' => $asset->id]);

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

        $localIps = $mapped['local_ips'] ?? null;

        $asset = Asset::create([
            'client_id' => $psaClientId,
            'name' => $hostname,
            'hostname' => $hostname,
            'asset_type' => $this->mapAssetType($mapped['plat'] ?? null, $agent['monitoring_type'] ?? null),
            'os' => $mapped['os'] ?? null,
            'serial_number' => $this->sanitizeSerialNumber($mapped['serial_number'] ?? null),
            'cpu' => $mapped['cpu'] ?? null,
            'disk_summary' => $mapped['disk_summary'] ?? null,
            'ip_address' => is_array($localIps) ? ($localIps[0] ?? null) : $localIps,
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
     * Matching is case- and space-insensitive because the same placeholder
     * arrives punctuated differently across vendors ("O.E.M." vs "OEM").
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
