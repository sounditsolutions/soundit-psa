<?php

namespace App\Services\Cipp;

use App\Models\Asset;
use App\Models\Client;
use App\Services\SyncResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CippDeviceSyncService
{
    public function __construct(
        private readonly CippClient $client,
    ) {}

    /**
     * Sync Intune managed devices from CIPP for all mapped clients.
     */
    public function syncDevices(?callable $onProgress = null, bool $dryRun = false): SyncResult
    {
        $clients = Client::whereNotNull('cipp_tenant_domain')
            ->operational()
            ->get();

        $result = new SyncResult;

        foreach ($clients as $client) {
            try {
                $this->syncDevicesForClient($client, $result, $dryRun);
            } catch (\Throwable $e) {
                Log::warning("[CippDeviceSync] Failed for {$client->name}: {$e->getMessage()}");
                $result->recordError("Client {$client->name}: {$e->getMessage()}");
            }

            if ($onProgress) {
                $onProgress($result);
            }
        }

        return $result;
    }

    /**
     * Sync devices for a single client.
     */
    public function syncDevicesForClient(Client $client, SyncResult $result, bool $dryRun = false): void
    {
        $lock = CippContactSyncService::acquireLock("cipp-device-sync:{$client->id}");

        if (! $lock) {
            Log::info("[CippDeviceSync] Skipping {$client->name} — sync already in progress");

            return;
        }

        try {
            $this->doSyncDevicesForClient($client, $result, $dryRun);
        } finally {
            $lock->release();
        }
    }

    private function doSyncDevicesForClient(Client $client, SyncResult $result, bool $dryRun): void
    {
        $tenantDomain = $client->cipp_tenant_domain;

        // Fetch Intune devices
        $devices = $this->client->listDevices($tenantDomain);

        if (! is_array($devices) || empty($devices)) {
            Log::info("[CippDeviceSync] No devices returned for {$client->name}");

            return;
        }

        $fetchSucceeded = true;

        // Fetch Defender state and index by device ID for merge
        $defenderByDeviceId = [];
        try {
            $defenderData = $this->client->listDefenderState($tenantDomain);
            if (is_array($defenderData)) {
                foreach ($defenderData as $ds) {
                    // Try to match by managedDeviceId or azureADDeviceId
                    $did = $ds['managedDeviceId'] ?? $ds['ManagedDeviceId']
                        ?? $ds['azureADDeviceId'] ?? $ds['AzureADDeviceId'] ?? null;
                    // Also index by device name as fallback
                    $dName = mb_strtolower($ds['deviceName'] ?? $ds['DeviceName'] ?? $ds['managedDeviceName'] ?? '');

                    if ($did) {
                        $defenderByDeviceId[$did] = $ds;
                    }
                    if ($dName) {
                        $defenderByDeviceId["name:{$dName}"] = $ds;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[CippDeviceSync] Defender state unavailable for {$client->name}: {$e->getMessage()}");
            // Continue without Defender data — not a blocker
        }

        $matchedAssetIds = [];

        foreach ($devices as $device) {
            $deviceId = $device['id'] ?? $device['Id'] ?? $device['managedDeviceId'] ?? null;
            $deviceName = $device['deviceName'] ?? $device['DeviceName'] ?? null;

            if (! $deviceId) {
                continue;
            }

            // Match to existing asset: by m365_device_id first, hostname second
            $asset = Asset::where('client_id', $client->id)
                ->where('m365_device_id', $deviceId)
                ->first();

            if (! $asset && $deviceName) {
                $lowerName = mb_strtolower($deviceName);
                $shortName = mb_strtolower(explode('.', $deviceName)[0]);

                $asset = Asset::where('client_id', $client->id)
                    ->whereNull('m365_device_id')
                    ->where(function ($q) use ($lowerName, $shortName) {
                        $q->whereRaw('LOWER(hostname) = ?', [$lowerName]);
                        if ($lowerName !== $shortName) {
                            $q->orWhereRaw('LOWER(hostname) = ?', [$shortName]);
                        }
                    })
                    ->first();
            }

            if (! $asset) {
                Log::debug("[CippDeviceSync] No asset match for device '{$deviceName}' (ID: {$deviceId})", [
                    'client' => $client->name,
                ]);

                continue;
            }

            if ($dryRun) {
                $matchedAssetIds[] = $asset->id;
                $result->updated++;
                $result->details[] = [
                    'action' => 'update',
                    'client' => $client->name,
                    'name' => $deviceName,
                    'email' => $asset->hostname ?? '',
                ];

                continue;
            }

            // Find Defender data by device ID or name
            $defender = $defenderByDeviceId[$deviceId]
                ?? $defenderByDeviceId['name:'.mb_strtolower($deviceName ?? '')] ?? null;

            $this->updateAssetFromDevice($asset, $device, $defender);
            $matchedAssetIds[] = $asset->id;
            $result->updated++;
        }

        // Stale cleanup — only if fetch succeeded, only synced assets
        if ($fetchSucceeded && ! $dryRun) {
            $staleCleared = Asset::where('client_id', $client->id)
                ->whereNotNull('m365_device_id')
                ->when(! empty($matchedAssetIds), fn ($q) => $q->whereNotIn('id', $matchedAssetIds))
                ->update([
                    'm365_device_id' => null,
                    'm365_compliance_state' => null,
                    'm365_is_compliant' => null,
                    'm365_enrollment_type' => null,
                    'm365_os_version' => null,
                    'm365_last_sync_at' => null,
                    'm365_device_owner_type' => null,
                    'm365_defender_status' => null,
                    'm365_defender_version' => null,
                    'm365_last_scan_at' => null,
                    'm365_synced_at' => now(),
                ]);

            if ($staleCleared > 0) {
                $result->deactivated += $staleCleared;
                Log::info("[CippDeviceSync] Cleared stale Intune data from {$staleCleared} asset(s) for {$client->name}");
            }
        }
    }

    /**
     * Update a single asset from Intune device + optional Defender data.
     */
    private function updateAssetFromDevice(Asset $asset, array $device, ?array $defender = null): void
    {
        $lastSyncRaw = $device['lastSyncDateTime'] ?? $device['LastSyncDateTime'] ?? null;
        $lastSync = $lastSyncRaw ? Carbon::parse($lastSyncRaw) : null;

        $updates = [
            'm365_device_id' => $device['id'] ?? $device['Id'] ?? $device['managedDeviceId'] ?? null,
            'm365_compliance_state' => $device['complianceState'] ?? $device['ComplianceState'] ?? null,
            'm365_is_compliant' => $this->parseCompliant($device),
            'm365_enrollment_type' => $device['deviceEnrollmentType'] ?? $device['DeviceEnrollmentType']
                ?? $device['managedDeviceOwnerType'] ?? null,
            'm365_os_version' => $device['osVersion'] ?? $device['OsVersion'] ?? null,
            'm365_last_sync_at' => $lastSync,
            'm365_device_owner_type' => $device['managedDeviceOwnerType'] ?? $device['ManagedDeviceOwnerType']
                ?? $device['ownerType'] ?? null,
            'm365_synced_at' => now(),
        ];

        // Merge Defender state if available
        if ($defender) {
            $lastScan = null;
            $scanDate = $defender['lastQuickScanDateTime'] ?? $defender['LastQuickScanDateTime']
                ?? $defender['lastFullScanDateTime'] ?? $defender['LastFullScanDateTime'] ?? null;
            if ($scanDate) {
                try {
                    $lastScan = Carbon::parse($scanDate);
                } catch (\Throwable) {
                }
            }

            $updates['m365_defender_status'] = $defender['antiVirusStatus'] ?? $defender['AntiVirusStatus']
                ?? $defender['antivirusStatus'] ?? null;
            $updates['m365_defender_version'] = $defender['antiVirusSignatureVersion'] ?? $defender['AntiVirusSignatureVersion']
                ?? $defender['avSignatureVersion'] ?? null;
            $updates['m365_last_scan_at'] = $lastScan;
        }

        $asset->update($updates);
    }

    private function parseCompliant(array $device): ?bool
    {
        $val = $device['isCompliant'] ?? $device['IsCompliant']
            ?? $device['complianceGracePeriodExpirationDateTime'] ?? null;

        if (is_bool($val)) {
            return $val;
        }

        $state = $device['complianceState'] ?? $device['ComplianceState'] ?? null;

        return $state !== null ? strtolower($state) === 'compliant' : null;
    }
}
