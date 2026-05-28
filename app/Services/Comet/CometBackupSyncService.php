<?php

namespace App\Services\Comet;

use App\Models\Asset;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\SyncResult;
use Illuminate\Support\Facades\Log;

class CometBackupSyncService
{
    // Cloud destination types per Comet SDK Def.php
    // 1000 = S3, 1003 = Comet Storage, 1005 = Azure Blob, 1008 = B2, 1009 = Storj
    private const CLOUD_DESTINATION_TYPES = [1000, 1003, 1005, 1008, 1009];

    // Human-readable labels for Comet backup engine types
    private const ENGINE_LABELS = [
        'engine1/file' => 'File',
        'engine1/windisk' => 'Disk Image',
        'engine1/windowssystem' => 'System State',
        'engine1/hyperv' => 'Hyper-V',
        'engine1/vmware' => 'VMware',
        'engine1/proxmox' => 'Proxmox',
        'engine1/mssql' => 'MS SQL',
        'engine1/mysql' => 'MySQL',
        'engine1/mongodb' => 'MongoDB',
        'engine1/exchangeedb' => 'Exchange',
        'engine1/vsswriter' => 'VSS Writer',
        'engine1/winmsofficemail' => 'MS Office',
        'engine1/stdout' => 'Command',
        'engine1/systemstate' => 'System State (Legacy)',
    ];

    public function __construct(
        private readonly CometClient $client,
    ) {}

    /**
     * Sync Comet Backup usage into assets and upsert license counts.
     *
     * @param int|null $clientId  Restrict sync to a single client (by PSA client ID).
     * @param bool     $dryRun    Log changes without writing to the database.
     */
    public function syncBackupUsage(?int $clientId = null, bool $dryRun = false): SyncResult
    {
        $result = new SyncResult();

        // Load mapped clients (those with comet_group_id set)
        $clientQuery = Client::whereNotNull('comet_group_id');
        if ($clientId !== null) {
            $clientQuery->where('id', $clientId);
        }
        $mappedClients = $clientQuery->get();

        if ($mappedClients->isEmpty()) {
            Log::info('[CometBackupSync] No clients mapped to Comet organizations');

            return $result;
        }

        // Build group_id → client lookup
        $orgToClient = $mappedClients->keyBy('comet_group_id');

        // Fetch all user profiles in one API call
        try {
            $userProfiles = $this->client->listUsersFull();
        } catch (CometClientException $e) {
            Log::error("[CometBackupSync] Failed to fetch user profiles: {$e->getMessage()}");
            $result->recordError("Failed to fetch user profiles: {$e->getMessage()}");

            return $result;
        }

        // Track seen device IDs for stale cleanup
        $seenDeviceIds = [];

        // Per-client, per-engine license counts: $engineCounts[client_id][engine_short][device_type] = count
        $engineCounts = [];
        $clientCloudBytes = [];

        $skipped = 0;

        // $userProfiles is array<string, \Comet\UserProfileConfig> (username → profile)
        foreach ($userProfiles as $username => $profile) {
            $groupId = $profile->GroupID ?? null;

            // Skip users not belonging to a mapped group
            if (!$groupId || !$orgToClient->has($groupId)) {
                $skipped++;
                continue;
            }

            $client = $orgToClient->get($groupId);
            $cid = $client->id;

            // Calculate storage totals across all destinations for this user.
            // Note: Comet storage vaults (Destinations) are per-user, not per-device.
            // A user's total storage is distributed across all their registered devices,
            // so we attribute the full vault total to the matched device. For reporting
            // accuracy this is a known limitation — Comet does not expose per-device storage.
            $cloudBytes = 0;
            $localBytes = 0;

            foreach ($profile->Destinations as $destId => $destination) {
                $type = $destination->DestinationType;
                $stats = $destination->Statistics;
                $bytes = ($stats !== null && $stats->ClientProvidedSize !== null)
                    ? (int) $stats->ClientProvidedSize->Size
                    : 0;

                if (in_array($type, self::CLOUD_DESTINATION_TYPES, true)) {
                    $cloudBytes += $bytes;
                } else {
                    $localBytes += $bytes;
                }
            }

            // Build device ID → PSA asset lookup for this user
            $deviceAssets = [];
            foreach ($profile->Devices as $deviceId => $device) {
                $hostname = $device->FriendlyName;
                if (!$hostname) {
                    continue;
                }

                $seenDeviceIds[] = $deviceId;

                $asset = Asset::where('client_id', $cid)
                    ->whereRaw('LOWER(hostname) = ?', [mb_strtolower($hostname)])
                    ->first();

                if (!$asset) {
                    Log::debug("[CometBackupSync] No asset matched for device '{$hostname}' (client: {$client->name})");
                } else {
                    try {
                        if (!$dryRun) {
                            $asset->update([
                                'comet_username' => $username,
                                'comet_device_id' => $deviceId,
                                'backup_cloud_bytes' => $cloudBytes ?: null,
                                'backup_local_bytes' => $localBytes ?: null,
                                'backup_revisions_bytes' => null,
                                'backup_synced_at' => now(),
                            ]);
                        }

                        $result->updated++;
                    } catch (\Throwable $e) {
                        $result->recordError("Device '{$hostname}' (id: {$deviceId}): {$e->getMessage()}");
                        continue;
                    }
                }

                $deviceAssets[$deviceId] = $asset;
            }

            // Count licenses from Sources (protection items) — each has an engine type and OwnerDevice
            // A device with multiple sources of the same engine type counts as 1.
            // A device with sources of different engine types counts once per engine type.
            $deviceEngines = []; // $deviceEngines[deviceId] = [engine1, engine2, ...]
            foreach ($profile->Sources as $sourceId => $source) {
                $engine = $source->Engine ?? null;
                $ownerDevice = $source->OwnerDevice ?? null;
                if (!$engine || !$ownerDevice) {
                    continue;
                }

                $deviceEngines[$ownerDevice][$engine] = true;
            }

            // Also count devices that have no sources yet (registered but no protection items configured)
            foreach ($profile->Devices as $deviceId => $device) {
                if (!($device->FriendlyName ?? null)) {
                    continue;
                }
                if (!isset($deviceEngines[$deviceId])) {
                    // Device exists but has no protection items — count as untyped
                    $deviceEngines[$deviceId] = [];
                }
            }

            // Tally engine counts per client
            foreach ($deviceEngines as $deviceId => $engines) {
                $asset = $deviceAssets[$deviceId] ?? null;
                $assetType = mb_strtolower($asset?->asset_type ?? 'workstation');
                $deviceType = str_contains($assetType, 'server') ? 'server' : 'workstation';

                if (empty($engines)) {
                    // Device with no protection items — count toward a generic type
                    $engineCounts[$cid]['unprotected'][$deviceType] = ($engineCounts[$cid]['unprotected'][$deviceType] ?? 0) + 1;
                } else {
                    foreach ($engines as $engine => $_) {
                        $engineShort = $this->engineShort($engine);
                        $engineCounts[$cid][$engineShort][$deviceType] = ($engineCounts[$cid][$engineShort][$deviceType] ?? 0) + 1;
                    }
                }
            }

            if ($cloudBytes > 0) {
                $clientCloudBytes[$cid] = ($clientCloudBytes[$cid] ?? 0) + $cloudBytes;
            }
        }

        // Stale cleanup: null out comet fields for assets with comet_device_id not seen this run
        if (!empty($seenDeviceIds) && !$dryRun) {
            $staleQuery = Asset::whereNotNull('comet_device_id')
                ->whereNotIn('comet_device_id', $seenDeviceIds);

            if ($clientId !== null) {
                $staleQuery->where('client_id', $clientId);
            } else {
                $staleQuery->whereHas('client', fn ($q) => $q->whereNotNull('comet_group_id'));
            }

            $staleAssets = $staleQuery->get();

            foreach ($staleAssets as $stale) {
                $stale->update([
                    'comet_username' => null,
                    'comet_device_id' => null,
                    'backup_cloud_bytes' => null,
                    'backup_local_bytes' => null,
                    'backup_revisions_bytes' => null,
                    'backup_synced_at' => null,
                ]);
                $result->deactivated++;
            }
        }

        if ($skipped > 0) {
            Log::info("[CometBackupSync] Skipped {$skipped} users from unmapped organizations");
        }

        // Only upsert license counts on full (non-client-scoped) syncs to avoid partial zeroing
        if ($clientId === null && !$dryRun) {
            $this->syncLicenseCounts($engineCounts, $clientCloudBytes, $result);
            $result->deactivated += License::deactivateOrphaned('comet', 'comet_group_id');
        }

        Log::info('[CometBackupSync] Sync complete', [
            'updated' => $result->updated,
            'stale_cleared' => $result->deactivated,
            'clients_with_licenses' => count($engineCounts),
            'unmapped_skipped' => $skipped,
            'dry_run' => $dryRun,
        ]);

        return $result;
    }

    /**
     * Upsert engine-aware LicenseTypes + Licenses for each client, plus cloud usage GB.
     */
    private function syncLicenseCounts(
        array $engineCounts,
        array $clientCloudBytes,
        SyncResult $result,
    ): void {
        $allClientIds = array_unique(array_merge(
            array_keys($engineCounts),
            array_keys($clientCloudBytes),
        ));

        $clients = Client::whereNotNull('comet_group_id')
            ->where('is_active', true)
            ->whereIn('id', $allClientIds)
            ->get()
            ->keyBy('id');

        // Track all license type IDs we touch this run, for stale zeroing
        $touchedTypeIds = [];

        foreach ($clients as $cid => $client) {
            $orgId = (string) $client->comet_group_id;

            // Engine-typed device licenses
            foreach ($engineCounts[$cid] ?? [] as $engineShort => $deviceTypes) {
                foreach ($deviceTypes as $deviceType => $count) {
                    $skuId = "{$engineShort}_{$deviceType}";
                    $label = $this->engineLabel($engineShort);
                    $name = "Comet {$label} — " . ucfirst($deviceType);

                    $type = LicenseType::updateOrCreate(
                        ['vendor' => 'comet', 'vendor_sku_id' => $skuId],
                        ['name' => $name, 'is_active' => true],
                    );

                    $touchedTypeIds[$type->id] = true;

                    License::updateOrCreate(
                        ['license_type_id' => $type->id, 'client_id' => $cid, 'vendor_ref' => $orgId],
                        ['quantity' => $count, 'status' => $count > 0 ? 'active' : 'suspended', 'synced_at' => now()],
                    );
                }
            }
        }

        // Cloud usage in GB (not engine-specific)
        $usageType = LicenseType::updateOrCreate(
            ['vendor' => 'comet', 'vendor_sku_id' => 'cloud_usage_gb'],
            ['name' => 'Comet Backup Usage (GB)', 'is_active' => true],
        );
        $touchedTypeIds[$usageType->id] = true;

        foreach ($clients as $cid => $client) {
            $orgId = (string) $client->comet_group_id;
            $bytes = $clientCloudBytes[$cid] ?? 0;
            $gb = (int) round($bytes / (1024 ** 3));

            License::updateOrCreate(
                ['license_type_id' => $usageType->id, 'client_id' => $cid, 'vendor_ref' => $orgId],
                ['quantity' => $gb, 'status' => $gb > 0 ? 'active' : 'suspended', 'synced_at' => now()],
            );
        }

        // Zero out licenses for touched types where clients no longer have data
        foreach (array_keys($touchedTypeIds) as $typeId) {
            License::where('license_type_id', $typeId)
                ->where('quantity', '>', 0)
                ->whereNotIn('client_id', $allClientIds)
                ->update(['quantity' => 0, 'status' => 'suspended', 'synced_at' => now()]);
        }
    }

    /**
     * Convert a Comet engine string to a short identifier for vendor_sku_id.
     * e.g., "engine1/windisk" → "windisk"
     */
    private function engineShort(string $engine): string
    {
        // Strip the "engine1/" prefix if present
        if (str_starts_with($engine, 'engine1/')) {
            return substr($engine, 8);
        }

        return $engine;
    }

    /**
     * Get a human-readable label for an engine short name or full engine string.
     */
    private function engineLabel(string $engineShort): string
    {
        // Check the full engine string first, then the short form
        if (isset(self::ENGINE_LABELS["engine1/{$engineShort}"])) {
            return self::ENGINE_LABELS["engine1/{$engineShort}"];
        }

        if (isset(self::ENGINE_LABELS[$engineShort])) {
            return self::ENGINE_LABELS[$engineShort];
        }

        // Special case for unprotected devices
        if ($engineShort === 'unprotected') {
            return 'Unprotected';
        }

        // Fallback: title-case the short name
        return ucfirst(str_replace(['_', '-', '/'], ' ', $engineShort));
    }
}
