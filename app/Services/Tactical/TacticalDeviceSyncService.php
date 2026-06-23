<?php

namespace App\Services\Tactical;

use App\Models\Asset;
use App\Models\Client;
use App\Models\TacticalAsset;
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
        if (isset($agent['last_seen'])) {
            $update['last_seen_at'] = Carbon::parse($agent['last_seen']);
        }
        if (isset($agent['needs_reboot'])) {
            $update['needs_reboot'] = (bool) $agent['needs_reboot'];
        }

        // getAgent `checks` is a SUMMARY DICT
        // ({total, passing, failing, warning, info, has_failing_checks}), NOT a
        // list of checks — read failing/total off it directly. (The DETAILED
        // failing-check list is a separate getAgentChecks read.)
        if (isset($agent['checks']) && is_array($agent['checks']) && isset($agent['checks']['total'])) {
            $update['checks_failing'] = (int) ($agent['checks']['failing'] ?? 0);
            $update['checks_total'] = (int) $agent['checks']['total'];
        }

        $ta->update($update);

        return DetailSyncResult::success($ta->status, $ta->synced_at);
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

            // Link to PSA asset if not already linked
            if (! $tacticalAsset->asset_id) {
                $this->linkToAsset($tacticalAsset, $psaClientId, $agent['hostname'] ?? null, $result);
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
        // Persist failing/total so the card health line is snapshot-fresh from the
        // DAILY sync (zero per-agent fan-out) — not detail-only. Read defensively:
        // leave the columns untouched if a payload ever omits the dict.
        $checks = $agent['checks'] ?? null;
        if (is_array($checks) && isset($checks['total'])) {
            $mapped['checks_total'] = (int) $checks['total'];
            $mapped['checks_failing'] = (int) ($checks['failing'] ?? 0);
        }

        return $mapped;
    }

    /**
     * Attempt to link a TacticalAsset to an existing PSA Asset by hostname match.
     */
    private function linkToAsset(TacticalAsset $tacticalAsset, int $psaClientId, ?string $hostname, SyncResult $result): void
    {
        if (! $hostname) {
            return;
        }

        $lowerHostname = strtolower($hostname);

        $asset = Asset::where('client_id', $psaClientId)
            ->whereNull('tactical_asset_id')
            ->where(function ($q) use ($lowerHostname) {
                $q->whereRaw('LOWER(hostname) = ?', [$lowerHostname])
                    ->orWhereRaw('LOWER(name) = ?', [$lowerHostname]);
            })
            ->first();

        if (! $asset) {
            return;
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
}
