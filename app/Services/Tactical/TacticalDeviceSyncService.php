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
    public function __construct(
        private readonly TacticalClient $client,
    ) {}

    public function syncDevices(?int $clientId = null): SyncResult
    {
        $result = new SyncResult;

        // Build client mapping: tactical site key ("ClientName|SiteName") → PSA client_id
        $clientMap = Client::whereNotNull('tactical_site_id')
            ->where('is_active', true)
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

        return [
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
