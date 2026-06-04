<?php

namespace App\Services\Ninja;

use App\Models\Asset;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\SyncResult;
use Illuminate\Support\Facades\Log;

class NinjaBackupSyncService
{
    public function __construct(
        private readonly NinjaClient $ninja,
    ) {}

    /**
     * Sync backup usage from NinjaRMM into assets table and upsert license counts.
     *
     * Addresses review flags:
     * - Filters to mapped orgs only (shared NinjaOne instance safety)
     * - Clears stale backup data for devices no longer in API response
     * - Presence in API response = backup enabled (not gated on bytes > 0)
     */
    public function syncBackupUsage(): SyncResult
    {
        $result = new SyncResult;

        // Load mapped ninja_org_ids so we can filter the global response
        $mappedOrgIds = Client::whereNotNull('ninja_org_id')
            ->pluck('ninja_org_id')
            ->all();

        if (empty($mappedOrgIds)) {
            Log::info('[NinjaBackupSync] No clients mapped to Ninja organizations');

            return $result;
        }

        try {
            $backupRecords = $this->ninja->getBackupUsage();
        } catch (NinjaClientException $e) {
            Log::error("[NinjaBackupSync] Failed to fetch backup usage: {$e->getMessage()}");
            $result->recordError("Failed to fetch backup usage: {$e->getMessage()}");

            return $result;
        }

        // Build lookup: ninja_id -> asset (only assets belonging to mapped orgs)
        $assets = Asset::whereNotNull('ninja_id')
            ->whereNotNull('client_id')
            ->whereHas('client', fn ($q) => $q->whereNotNull('ninja_org_id'))
            ->get()
            ->keyBy('ninja_id');

        // Track which ninja_ids we saw in this sync run
        $seenNinjaIds = [];

        // Per-client device counts and cloud bytes for license upsert
        $clientServerCounts = [];
        $clientWorkstationCounts = [];
        $clientCloudBytes = [];

        $skipped = 0;

        foreach ($backupRecords as $record) {
            $ninjaDeviceId = $record['id'] ?? $record['deviceId'] ?? null;
            if (! $ninjaDeviceId) {
                continue;
            }

            $asset = $assets->get($ninjaDeviceId);
            if (! $asset) {
                // Device not in our mapped assets — skip silently (shared instance)
                $skipped++;

                continue;
            }

            $seenNinjaIds[] = $ninjaDeviceId;

            try {
                $backupUsage = $record['references']['backupUsage'] ?? $record['backupUsage'] ?? $record;

                $asset->update([
                    'backup_cloud_bytes' => $backupUsage['cloudTotalSize'] ?? null,
                    'backup_local_bytes' => $backupUsage['localTotalSize'] ?? null,
                    'backup_revisions_bytes' => $backupUsage['revisionsTotalSize'] ?? null,
                    'backup_synced_at' => now(),
                ]);

                $result->updated++;

                // Only count toward license if device has actual backup data
                $clientId = $asset->client_id;
                $cloudBytes = $backupUsage['cloudTotalSize'] ?? 0;
                $localBytes = $backupUsage['localTotalSize'] ?? 0;

                if ($cloudBytes > 0 || $localBytes > 0) {
                    // Split device counts by asset type (server vs workstation)
                    $assetType = mb_strtolower($asset->asset_type ?? 'workstation');
                    $isServer = str_contains($assetType, 'server');

                    if ($isServer) {
                        $clientServerCounts[$clientId] = ($clientServerCounts[$clientId] ?? 0) + 1;
                    } else {
                        $clientWorkstationCounts[$clientId] = ($clientWorkstationCounts[$clientId] ?? 0) + 1;
                    }

                    if ($cloudBytes > 0) {
                        $clientCloudBytes[$clientId] = ($clientCloudBytes[$clientId] ?? 0) + $cloudBytes;
                    }
                }
            } catch (\Throwable $e) {
                $result->recordError("Device {$ninjaDeviceId}: {$e->getMessage()}");
            }
        }

        // Clear stale backup data: assets that had backup previously but are no longer
        // in the API response. Set to NULL (not 0) — NULL = no backup, 0 = empty backup.
        if (! empty($seenNinjaIds)) {
            $staleAssets = Asset::whereNotNull('ninja_id')
                ->whereNotNull('backup_synced_at')
                ->whereNotIn('ninja_id', $seenNinjaIds)
                ->whereHas('client', fn ($q) => $q->whereNotNull('ninja_org_id'))
                ->get();

            foreach ($staleAssets as $stale) {
                $stale->update([
                    'backup_cloud_bytes' => null,
                    'backup_local_bytes' => null,
                    'backup_revisions_bytes' => null,
                    'backup_synced_at' => null,
                ]);
                $result->deactivated++;
            }
        }

        // Upsert license counts per client (server + workstation + cloud usage)
        $this->syncLicenseCounts($clientServerCounts, $clientWorkstationCounts, $clientCloudBytes, $result);

        if ($skipped > 0) {
            Log::info("[NinjaBackupSync] Skipped {$skipped} devices from unmapped organizations");
        }

        // Sync RMM device licenses (from local asset counts — no API calls)
        $this->syncRmmLicenseCounts($result);

        // Deactivate licenses on clients that no longer have a Ninja mapping
        $result->deactivated += License::deactivateOrphaned('ninjaone', 'ninja_org_id');
        $result->deactivated += License::deactivateOrphaned('ninjaone', 'ninja_org_id');

        $totalClients = count(array_unique(array_merge(array_keys($clientServerCounts), array_keys($clientWorkstationCounts))));
        Log::info('[NinjaBackupSync] Sync complete', [
            'updated' => $result->updated,
            'stale_cleared' => $result->deactivated,
            'licenses_synced' => $totalClients,
            'unmapped_skipped' => $skipped,
        ]);

        return $result;
    }

    /**
     * Upsert LicenseType + License for each client's backup counts (server + workstation) and cloud usage.
     */
    private function syncLicenseCounts(array $clientServerCounts, array $clientWorkstationCounts, array $clientCloudBytes, SyncResult $result): void
    {
        $serverLicenseType = LicenseType::updateOrCreate(
            ['vendor' => 'ninjaone', 'vendor_sku_id' => 'cloud_backup_server'],
            ['name' => 'NinjaOne Cloud Backup — Server', 'is_active' => true],
        );

        $workstationLicenseType = LicenseType::updateOrCreate(
            ['vendor' => 'ninjaone', 'vendor_sku_id' => 'cloud_backup_workstation'],
            ['name' => 'NinjaOne Cloud Backup — Workstation', 'is_active' => true],
        );

        $usageLicenseType = LicenseType::updateOrCreate(
            ['vendor' => 'ninjaone', 'vendor_sku_id' => 'cloud_usage_gb'],
            ['name' => 'NinjaOne Backup Usage (GB)', 'is_active' => true],
        );

        // Deactivate the old combined license type if it exists
        LicenseType::where('vendor', 'ninjaone')
            ->where('vendor_sku_id', 'cloud_backup')
            ->update(['is_active' => false]);

        $allClientIds = array_unique(array_merge(
            array_keys($clientServerCounts),
            array_keys($clientWorkstationCounts),
        ));

        $clients = Client::whereNotNull('ninja_org_id')
            ->where('is_active', true)
            ->whereIn('id', $allClientIds)
            ->get()
            ->keyBy('id');

        foreach ($clients as $clientId => $client) {
            $orgId = (string) $client->ninja_org_id;

            // Server backup count
            $serverCount = $clientServerCounts[$clientId] ?? 0;
            License::updateOrCreate(
                ['license_type_id' => $serverLicenseType->id, 'client_id' => $clientId, 'vendor_ref' => $orgId],
                ['quantity' => $serverCount, 'status' => $serverCount > 0 ? 'active' : 'suspended', 'synced_at' => now()],
            );

            // Workstation backup count
            $wsCount = $clientWorkstationCounts[$clientId] ?? 0;
            License::updateOrCreate(
                ['license_type_id' => $workstationLicenseType->id, 'client_id' => $clientId, 'vendor_ref' => $orgId],
                ['quantity' => $wsCount, 'status' => $wsCount > 0 ? 'active' : 'suspended', 'synced_at' => now()],
            );

            // Cloud usage in GB (bytes → GB, rounded)
            $bytes = $clientCloudBytes[$clientId] ?? 0;
            $gb = (int) round($bytes / (1024 ** 3));
            License::updateOrCreate(
                ['license_type_id' => $usageLicenseType->id, 'client_id' => $clientId, 'vendor_ref' => $orgId],
                ['quantity' => $gb, 'status' => $gb > 0 ? 'active' : 'suspended', 'synced_at' => now()],
            );
        }

        // Zero out licenses for mapped clients that no longer have backup data
        foreach ([$serverLicenseType->id, $workstationLicenseType->id] as $typeId) {
            License::where('license_type_id', $typeId)
                ->where('quantity', '>', 0)
                ->whereNotIn('client_id', $allClientIds)
                ->update(['quantity' => 0, 'status' => 'suspended', 'synced_at' => now()]);
        }

        License::where('license_type_id', $usageLicenseType->id)
            ->where('quantity', '>', 0)
            ->whereNotIn('client_id', array_keys($clientCloudBytes))
            ->update(['quantity' => 0, 'status' => 'suspended', 'synced_at' => now()]);
    }

    /**
     * Count Ninja-managed devices per client by type via API and sync as RMM license types.
     */
    private function syncRmmLicenseCounts(SyncResult $result): void
    {
        $rmmType = LicenseType::updateOrCreate(
            ['vendor' => 'ninjaone', 'vendor_sku_id' => 'rmm_devices'],
            ['name' => 'NinjaOne RMM', 'is_active' => true],
        );

        // Deactivate old split types if they exist
        LicenseType::where('vendor', 'ninjaone')
            ->whereIn('vendor_sku_id', ['rmm_server', 'rmm_workstation'])
            ->update(['is_active' => false]);

        $clients = Client::whereNotNull('ninja_org_id')
            ->where('is_active', true)
            ->get();

        $allClientIds = [];

        foreach ($clients as $client) {
            try {
                $devices = $this->ninja->getOrganizationDevices((int) $client->ninja_org_id);
            } catch (\Throwable $e) {
                Log::warning("[NinjaBackupSync] Failed to fetch RMM devices for {$client->name}: {$e->getMessage()}");

                continue;
            }

            $deviceCount = count($devices);
            $orgId = (string) $client->ninja_org_id;
            $allClientIds[] = $client->id;

            License::updateOrCreate(
                ['license_type_id' => $rmmType->id, 'client_id' => $client->id, 'vendor_ref' => $orgId],
                ['quantity' => $deviceCount, 'status' => $deviceCount > 0 ? 'active' : 'suspended', 'synced_at' => now()],
            );
        }

        // Zero out for clients no longer mapped
        License::where('license_type_id', $rmmType->id)
            ->where('quantity', '>', 0)
            ->whereNotIn('client_id', $allClientIds)
            ->update(['quantity' => 0, 'status' => 'suspended', 'synced_at' => now()]);
    }
}
