<?php

namespace App\Services\ControlD;

use App\Models\Asset;
use App\Models\Client;
use App\Services\SyncResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ControlDDeviceSyncService
{
    public function __construct(
        private readonly ControlDClient $client,
    ) {}

    public function syncDevices(?callable $onProgress = null): SyncResult
    {
        $clients = Client::whereNotNull('controld_org_id')
            ->operational()
            ->get();

        $result = new SyncResult;

        foreach ($clients as $client) {
            try {
                $this->syncDevicesForClient($client, $result);
            } catch (\Throwable $e) {
                Log::warning("[ControlDSync] Device sync failed for {$client->name}: {$e->getMessage()}");
                $result->recordError("Client {$client->name}: {$e->getMessage()}");
            }

            if ($onProgress) {
                $onProgress($result);
            }

            usleep(250_000); // 250ms between clients for rate limiting
        }

        return $result;
    }

    public function syncDevicesForClient(Client $client, SyncResult $result): void
    {
        $orgPk = $client->controld_org_id;
        $devices = $this->client->getDevices($orgPk);

        $matchedAssetIds = [];

        foreach ($devices as $device) {
            $devicePk = $device['PK'] ?? null;
            $deviceName = $device['name'] ?? null;

            if (! $devicePk) {
                continue;
            }

            // Match: first by controld_device_id (re-sync), then by hostname
            $asset = Asset::where('client_id', $client->id)
                ->where('controld_device_id', $devicePk)
                ->first();

            if (! $asset && $deviceName) {
                $lowerName = mb_strtolower($deviceName);
                $shortName = mb_strtolower(explode('.', $deviceName)[0]);

                $asset = Asset::where('client_id', $client->id)
                    ->whereNull('controld_device_id')
                    ->where(function ($q) use ($lowerName, $shortName) {
                        $q->whereRaw('LOWER(hostname) = ?', [$lowerName]);
                        if ($lowerName !== $shortName) {
                            $q->orWhereRaw('LOWER(hostname) = ?', [$shortName]);
                        }
                    })
                    ->first();
            }

            if (! $asset) {
                Log::debug("[ControlDSync] No asset match for device '{$deviceName}' (PK: {$devicePk})", [
                    'client' => $client->name,
                ]);

                continue;
            }

            $this->updateAssetFromDevice($asset, $device);
            $matchedAssetIds[] = $asset->id;
            $result->updated++;
        }

        // Clear stale: assets with controld_device_id that weren't seen this sync
        $staleCleared = Asset::where('client_id', $client->id)
            ->whereNotNull('controld_device_id')
            ->when(! empty($matchedAssetIds), fn ($q) => $q->whereNotIn('id', $matchedAssetIds))
            ->update([
                'controld_device_id' => null,
                'controld_profile_name' => null,
                'controld_status' => null,
                'controld_agent_status' => null,
                'controld_agent_version' => null,
                'controld_last_seen_at' => null,
                'controld_synced_at' => now(),
            ]);

        if ($staleCleared > 0) {
            $result->deactivated += $staleCleared;
            Log::info("[ControlDSync] Cleared stale Control D data from {$staleCleared} asset(s)", [
                'client' => $client->name,
            ]);
        }
    }

    /**
     * Update a single asset from a Control D device API response.
     */
    public function updateAssetFromDevice(Asset $asset, array $device): void
    {
        $ctrld = $device['ctrld'] ?? [];
        $profile = $device['profile'] ?? [];
        $lastFetch = isset($ctrld['last_fetch'])
            ? Carbon::createFromTimestamp($ctrld['last_fetch'])
            : null;

        $asset->update([
            'controld_device_id' => $device['PK'],
            'controld_profile_name' => $profile['name'] ?? null,
            'controld_status' => $device['status'] ?? null,
            'controld_agent_status' => $ctrld['status'] ?? null,
            'controld_agent_version' => $ctrld['version'] ?? null,
            'controld_last_seen_at' => $lastFetch,
            'controld_synced_at' => now(),
        ]);
    }
}
