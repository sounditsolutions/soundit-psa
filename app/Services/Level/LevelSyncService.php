<?php

namespace App\Services\Level;

use App\Models\Asset;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\SyncResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class LevelSyncService
{
    public function __construct(
        private readonly LevelClient $level,
    ) {}

    /**
     * Sync all devices for a single client from Level RMM.
     */
    public function syncDevicesForClient(Client $client, ?array &$serverCounts = null, ?array &$workstationCounts = null): SyncResult
    {
        $result = new SyncResult;

        if (! $client->level_group_id) {
            $result->recordError("Client {$client->name} has no level_group_id");

            return $result;
        }

        try {
            $devices = $this->level->getDevices($client->level_group_id);
        } catch (LevelClientException $e) {
            $result->recordError("Failed to fetch devices for group {$client->level_group_id}: {$e->getMessage()}");

            return $result;
        }

        // Count device types from API response for license tracking
        if ($serverCounts !== null || $workstationCounts !== null) {
            foreach ($devices as $device) {
                $role = mb_strtolower($device['role'] ?? '');
                if (str_contains($role, 'server')) {
                    $serverCounts[$client->id] = ($serverCounts[$client->id] ?? 0) + 1;
                } else {
                    $workstationCounts[$client->id] = ($workstationCounts[$client->id] ?? 0) + 1;
                }
            }
        }

        foreach ($devices as $device) {
            try {
                $levelId = $device['id'] ?? null;
                if (! $levelId) {
                    continue;
                }

                $existing = $this->upsertDeviceFromData($device, $client);
                $existing ? $result->updated++ : $result->created++;
            } catch (\Throwable $e) {
                $deviceId = $device['id'] ?? 'unknown';
                $result->recordError("Device {$deviceId}: {$e->getMessage()}");
            }
        }

        // psa-u97k: a device leaving Level must NEVER delete/deactivate the shared PSA Asset — it may still be
        // managed by another RMM (Tactical etc.), and offboarding is a DELIBERATE operator action. Clear ONLY
        // Level's own fields; the Asset (and its other RMM links + hardware data) persists. Mirrors the
        // Cipp/ControlD/Zorus stale-clear pattern. The non-empty-remote guard prevents wiping on an empty fetch.
        $remoteIds = collect($devices)->pluck('id')->filter()->all();
        if (! empty($remoteIds)) {
            $cleared = Asset::where('client_id', $client->id)
                ->whereNotNull('level_id')
                ->whereNotIn('level_id', $remoteIds)
                ->update([
                    'level_id' => null,
                    'level_url' => null,
                    'level_synced_at' => null,
                ]);

            if ($cleared > 0) {
                $result->deactivated += $cleared;
                Log::info("[LevelSync] {$cleared} device(s) left Level — unlinked from Level, asset retained", [
                    'client' => $client->name,
                ]);
            }
        }

        return $result;
    }

    /**
     * Upsert a single device from Level data into the assets table.
     * Used by both batch sync and webhook processing.
     *
     * @return bool True if an existing asset was updated, false if a new one was created.
     */
    public function upsertDeviceFromData(array $device, Client $client): bool
    {
        $levelId = $device['id'] ?? null;
        if (! $levelId) {
            throw new \InvalidArgumentException('Device data missing "id" field');
        }

        // Match by level_id first, then serial_number
        $asset = Asset::withTrashed()->where('level_id', $levelId)->first();

        if (! $asset && ! empty($device['serial_number'])) {
            $asset = Asset::withTrashed()
                ->where('serial_number', $device['serial_number'])
                ->whereNull('level_id')
                ->first();
        }

        $hostname = $device['hostname'] ?? null;
        $existing = (bool) $asset;

        $serialNumber = ! empty($device['serial_number']) ? $device['serial_number'] : null;

        $lastSeen = $this->resolveLastSeen($device);

        $data = [
            'level_id' => $levelId,
            'client_id' => $client->id,
            'serial_number' => $serialNumber ?: ($asset?->serial_number),
            'hostname' => $hostname,
            'name' => $hostname ?: ($asset?->name ?? 'Unknown'),
            'os' => $device['operating_system']['full_operating_system'] ?? null,
            'rmm_online' => ! empty($device['online']),
            'ip_address' => $this->resolveIpAddress($device),
            'last_user' => $device['last_logged_in_user'] ?? null,
            'cpu' => $this->resolveCpu($device),
            'ram_gb' => $this->resolveRamGb($device),
            'disk_summary' => $this->resolveDiskSummary($device),
            'level_url' => "https://app.level.io/devices/{$levelId}",
            'is_active' => true,
            'level_synced_at' => now(),
            'last_boot_at' => ! empty($device['last_reboot_time'])
                ? Carbon::parse($device['last_reboot_time'])
                : ($asset?->last_boot_at),
            'deleted_at' => null,
        ];

        // Only set last_seen_at when non-null to prevent overwriting
        // existing timestamps for offline devices without last_reboot_time
        if ($lastSeen !== null) {
            $data['last_seen_at'] = $lastSeen;
        }

        if ($asset) {
            $asset->update($data);
        } else {
            $data['asset_type'] = $this->mapDeviceRole($device);
            Asset::create($data);
        }

        return $existing;
    }

    /**
     * Fetch detailed hardware info for a single device and update the asset.
     */
    public function syncDeviceDetail(Asset $asset): void
    {
        if (! $asset->level_id) {
            return;
        }

        try {
            $device = $this->level->getDevice($asset->level_id);
        } catch (LevelClientException $e) {
            Log::warning('[LevelSync] Failed to fetch device detail', [
                'level_id' => $asset->level_id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $asset->update($this->detailData($device, $asset) + ['level_synced_at' => now()]);
    }

    /**
     * The asset columns syncDeviceDetail() writes from one GET /v2/devices/{id}
     * body, level_synced_at aside: hostname and name (kept when the body has no
     * hostname), os and last_user (kept when absent), cpu, ram_gb, disk_summary
     * and ip_address (as the body has them, null included), rmm_online, and
     * last_seen_at only when resolveLastSeen() finds one. Shared by
     * syncDeviceDetail() and syncNow().
     */
    private function detailData(array $device, Asset $asset): array
    {
        $hostname = $device['hostname'] ?? null;
        $lastSeen = $this->resolveLastSeen($device);

        $data = [
            'hostname' => $hostname ?: $asset->hostname,
            'name' => $hostname ?: $asset->name,
            'os' => $device['operating_system']['full_operating_system'] ?? $asset->os,
            'cpu' => $this->resolveCpu($device),
            'ram_gb' => $this->resolveRamGb($device),
            'disk_summary' => $this->resolveDiskSummary($device),
            'ip_address' => $this->resolveIpAddress($device),
            'last_user' => $device['last_logged_in_user'] ?? $asset->last_user,
            'rmm_online' => ! empty($device['online']),
        ];

        if ($lastSeen !== null) {
            $data['last_seen_at'] = $lastSeen;
        }

        return $data;
    }

    /** The columns syncNow() may write, on its one asset only. */
    public const SYNC_NOW_WRITES = [
        'hostname', 'name', 'os', 'cpu', 'ram_gb', 'disk_summary', 'ip_address', 'last_user', 'rmm_online',
        'last_seen_at', 'level_synced_at',
    ];

    /**
     * Asset Sources "Sync now" (nfuBmADp S6): ONE `GET /v2/devices/{id}` with the
     * hardware includes (LevelClient::getDevice, single attempt: no retry and no
     * sleep on 429) for this asset's linked device, the read syncDeviceDetail()
     * makes.
     *
     * ok writes THIS asset only, the columns syncDeviceDetail() writes
     * (detailData(): hostname, name, os, cpu, ram_gb, disk_summary, ip_address,
     * last_user, rmm_online, last_seen_at when found) and level_synced_at. It
     * never writes serial_number, level_id, level_url, client_id or is_active,
     * never creates an asset and never releases a link. The save is an ordinary
     * model save, as syncDeviceDetail()'s update() is, so when last_user changes
     * AssetObserver links that user to the asset
     * (AssetUserAssignmentService::assignForAsset).
     *
     * The device's group is not checked against the client's level_group_id: the
     * body carries only its parent group_id, and the mapping is an ancestor group
     * (getDevices() filters by ancestor_group_id), so one read cannot prove it.
     *
     * Every other answer writes nothing (level_synced_at included):
     *   drift         a body that is not one device object, about another
     *                 device, without a boolean online flag (Level's
     *                 OpenAPI device schema types it boolean), or with a value the columns
     *                 cannot take;
     *   failureReason(status) for an HTTP status (404 not_found_at_vendor, the
     *                 link kept; 429 vendor_429 with retry_after_s from
     *                 Retry-After; any other http_NNN);
     *   transport     no response came back;
     *   switched_off / not_configured  the client refused before any request.
     * The exception's message is never read (C-56): only the chained response.
     *
     * @return array{reason: string, http_status: ?int, retry_after_s: ?int, changed: list<string>, this_read: array<string, mixed>}
     */
    public function syncNow(Asset $asset): array
    {
        $answer = fn (string $reason, ?int $status = null, ?int $retryAfter = null, array $changed = [], array $thisRead = []) => [
            'reason' => $reason, 'http_status' => $status, 'retry_after_s' => $retryAfter, 'changed' => $changed, 'this_read' => $thisRead,
        ];

        $levelId = (string) $asset->level_id;
        try {
            $device = $this->level->getDevice($levelId, singleAttempt: true);
        } catch (LevelClientException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof \GuzzleHttp\Exception\RequestException && $previous->getResponse() !== null) {
                $status = $previous->getResponse()->getStatusCode();
                $retryAfter = $status === 429
                    ? \App\Services\AssetSources\SourceSyncPipeline::retryAfterSeconds($previous->getResponse()->getHeaderLine('Retry-After'))
                    : null;

                return $answer(\App\Services\AssetSources\SourceSyncPipeline::failureReason($status), $status, $retryAfter);
            }
            if ($previous instanceof \GuzzleHttp\Exception\GuzzleException) {
                return $answer('transport');
            }
            if (! \App\Support\LevelConfig::isEnabled()) {
                return $answer('switched_off');
            }
            if (! \App\Support\LevelConfig::isConfigured()) {
                return $answer('not_configured');
            }

            // A 2xx body that is not JSON.
            return $answer('drift');
        } catch (\TypeError) {
            // A JSON body that is not an object or list (getDevice()'s array return type).
            return $answer('drift');
        }

        if (! is_array($device) || array_is_list($device) || ($device['id'] ?? null) !== $levelId
            || ! is_bool($device['online'] ?? null)) {
            return $answer('drift');
        }

        try {
            $data = $this->detailData($device, $asset);
        } catch (\Throwable) {
            return $answer('drift');
        }
        if (! self::fitsColumns($data)) {
            return $answer('drift');
        }

        $asset->forceFill($data + ['level_synced_at' => now()]);
        // getDirty() compares through the casts, so null and false differ on a boolean column.
        $changed = array_values(array_intersect(self::SYNC_NOW_WRITES, array_keys($asset->getDirty())));
        $asset->save();

        return $answer('ok', changed: $changed, thisRead: ['online' => $data['rmm_online']]);
    }

    /**
     * Every value detailData() built fits its assets column (MariaDB strict mode
     * refuses what SQLite stores): strings within their lengths (name, hostname,
     * os, cpu, last_user 255; ip_address 45; disk_summary 500), ram_gb within
     * decimal(8,2), last_seen_at a date.
     */
    private static function fitsColumns(array $data): bool
    {
        $str = fn ($v, int $max) => $v === null || (is_string($v) && mb_strlen($v) <= $max);
        foreach (['hostname', 'name', 'os', 'cpu', 'last_user'] as $column) {
            if (! $str($data[$column], 255)) {
                return false;
            }
        }

        return $str($data['ip_address'], 45) && $str($data['disk_summary'], 500)
            && ($data['ram_gb'] === null || (is_float($data['ram_gb']) && $data['ram_gb'] >= 0 && $data['ram_gb'] < 1000000))
            && (! array_key_exists('last_seen_at', $data) || $data['last_seen_at'] instanceof \DateTimeInterface);
    }

    /**
     * Sync devices for all clients with level_group_id set.
     */
    public function syncAllDevices(?callable $onProgress = null): SyncResult
    {
        $result = new SyncResult;
        $clients = Client::whereNotNull('level_group_id')->get();
        $total = $clients->count();

        $clientServerCounts = [];
        $clientWorkstationCounts = [];

        foreach ($clients as $i => $client) {
            $clientResult = $this->syncDevicesForClient($client, $clientServerCounts, $clientWorkstationCounts);
            $result->created += $clientResult->created;
            $result->updated += $clientResult->updated;
            $result->errors += $clientResult->errors;
            $result->errorMessages = array_merge($result->errorMessages, $clientResult->errorMessages);

            if ($onProgress) {
                $onProgress($i + 1, $total, $client->name);
            }

            usleep(250_000); // 250ms between clients
        }

        // Sync RMM license counts from API device data
        $this->syncRmmLicenseCounts($clientServerCounts, $clientWorkstationCounts);

        // Deactivate orphaned licenses for unmapped clients
        $result->deactivated += License::deactivateOrphaned('level_rmm', 'level_group_id');

        return $result;
    }

    /**
     * Upsert Level RMM license types from API device counts.
     */
    private function syncRmmLicenseCounts(array $clientServerCounts, array $clientWorkstationCounts): void
    {
        $serverType = LicenseType::updateOrCreate(
            ['vendor' => 'level_rmm', 'vendor_sku_id' => 'rmm_server'],
            ['name' => 'Level RMM — Server', 'is_active' => true],
        );

        $workstationType = LicenseType::updateOrCreate(
            ['vendor' => 'level_rmm', 'vendor_sku_id' => 'rmm_workstation'],
            ['name' => 'Level RMM — Workstation', 'is_active' => true],
        );

        $allClientIds = array_unique(array_merge(array_keys($clientServerCounts), array_keys($clientWorkstationCounts)));

        foreach ($allClientIds as $clientId) {
            $client = Client::find($clientId);
            if (! $client) {
                continue;
            }

            $orgId = (string) $client->level_group_id;

            $serverCount = $clientServerCounts[$clientId] ?? 0;
            License::updateOrCreate(
                ['license_type_id' => $serverType->id, 'client_id' => $clientId, 'vendor_ref' => $orgId],
                ['quantity' => $serverCount, 'status' => $serverCount > 0 ? 'active' : 'suspended', 'synced_at' => now()],
            );

            $wsCount = $clientWorkstationCounts[$clientId] ?? 0;
            License::updateOrCreate(
                ['license_type_id' => $workstationType->id, 'client_id' => $clientId, 'vendor_ref' => $orgId],
                ['quantity' => $wsCount, 'status' => $wsCount > 0 ? 'active' : 'suspended', 'synced_at' => now()],
            );
        }

        // Zero out for clients no longer reporting devices
        foreach ([$serverType->id, $workstationType->id] as $typeId) {
            License::where('license_type_id', $typeId)
                ->where('quantity', '>', 0)
                ->whereNotIn('client_id', $allClientIds)
                ->update(['quantity' => 0, 'status' => 'suspended', 'synced_at' => now()]);
        }
    }

    /**
     * Resolve last_seen_at from Level device data.
     * If online, use now(). Otherwise fall back to last_reboot_time.
     */
    private function resolveLastSeen(array $device): ?Carbon
    {
        if (! empty($device['online'])) {
            return now();
        }

        if (! empty($device['last_reboot_time'])) {
            try {
                return Carbon::parse($device['last_reboot_time']);
            } catch (\Throwable) {
                // Invalid date format
            }
        }

        return null;
    }

    /**
     * Extract first IP address from network interfaces.
     */
    private function resolveIpAddress(array $device): ?string
    {
        $interfaces = $device['network_interfaces'] ?? [];

        foreach ($interfaces as $iface) {
            $ips = $iface['ip_addresses'] ?? [];
            if (! empty($ips[0])) {
                return $ips[0];
            }
        }

        return null;
    }

    /**
     * Format CPU string from first CPU entry.
     */
    private function resolveCpu(array $device): ?string
    {
        $cpus = $device['cpus'] ?? [];
        if (empty($cpus)) {
            return null;
        }

        $cpu = $cpus[0];
        $model = $cpu['model'] ?? 'Unknown CPU';
        $cores = $cpu['cores'] ?? null;

        return $cores ? "{$model} ({$cores} cores)" : $model;
    }

    /**
     * Convert total_memory (bytes) to GB.
     */
    private function resolveRamGb(array $device): ?float
    {
        $totalMemory = $device['total_memory'] ?? null;
        if ($totalMemory === null) {
            return null;
        }

        return round($totalMemory / (1024 * 1024 * 1024), 2);
    }

    /**
     * Build disk summary from disk_partitions.
     */
    private function resolveDiskSummary(array $device): ?string
    {
        $partitions = $device['disk_partitions'] ?? [];
        if (empty($partitions)) {
            return null;
        }

        $parts = [];
        foreach ($partitions as $partition) {
            $mountPoint = $partition['mount_point'] ?? '';
            $sizeBytes = $partition['size'] ?? null;
            $freeBytes = $partition['free_space'] ?? null;

            if ($sizeBytes === null) {
                continue;
            }

            $sizeGb = round($sizeBytes / (1024 * 1024 * 1024));
            $part = "{$sizeGb} GB";

            if ($freeBytes !== null && $sizeGb > 0) {
                $freeGb = round($freeBytes / (1024 * 1024 * 1024));
                $freePercent = round(($freeGb / $sizeGb) * 100);
                $part .= " ({$freePercent}% free)";
            }

            if ($mountPoint) {
                $part = "{$mountPoint}: {$part}";
            }

            $parts[] = $part;
        }

        return $parts ? implode(', ', $parts) : null;
    }

    /**
     * Map Level device platform + role to a friendly asset type.
     * e.g. "Windows" + "workstation" → "Windows Workstation"
     */
    private function mapDeviceRole(array $device): ?string
    {
        $platform = $device['platform'] ?? null;
        $role = $device['role'] ?? null;

        if (! $platform && ! $role) {
            return null;
        }

        $parts = array_filter([
            $platform,
            $role ? ucfirst($role) : null,
        ]);

        return implode(' ', $parts) ?: null;
    }
}
