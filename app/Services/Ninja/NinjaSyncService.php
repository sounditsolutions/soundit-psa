<?php

namespace App\Services\Ninja;

use App\Models\Asset;
use App\Models\Client;
use App\Services\SyncResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class NinjaSyncService
{
    /** Heartbeat freshness threshold for determining online status. */
    private const ONLINE_THRESHOLD_MINUTES = 15;

    public function __construct(
        private readonly NinjaClient $ninja,
    ) {}

    /**
     * Sync all devices for a single client from NinjaRMM.
     */
    public function syncDevicesForClient(Client $client): SyncResult
    {
        $result = new SyncResult;

        if (! $client->ninja_org_id) {
            $result->recordError("Client {$client->name} has no ninja_org_id");

            return $result;
        }

        try {
            $devices = $this->ninja->getOrganizationDevices($client->ninja_org_id);
        } catch (NinjaClientException $e) {
            $result->recordError("Failed to fetch devices for org {$client->ninja_org_id}: {$e->getMessage()}");

            return $result;
        }

        foreach ($devices as $device) {
            try {
                $ninjaId = $device['id'] ?? null;
                if (! $ninjaId) {
                    continue;
                }

                // Match by ninja_id first, then serial_number
                $asset = Asset::withTrashed()->where('ninja_id', $ninjaId)->first();

                if (! $asset && ! empty($device['system']['serialNumber'])) {
                    $asset = Asset::withTrashed()
                        ->where('serial_number', $device['system']['serialNumber'])
                        ->whereNull('ninja_id')
                        ->first();
                }

                $existing = (bool) $asset;
                $hostname = $device['systemName'] ?? $device['dnsName'] ?? null;

                $mappedType = $this->mapDeviceRole($device['nodeRoleId'] ?? null, $device['nodeClass'] ?? null);

                // Create/update base asset from list data
                $baseData = [
                    'ninja_id' => $ninjaId,
                    'client_id' => $client->id,
                    'hostname' => $hostname,
                    'name' => $hostname ?: ($asset?->name ?? 'Unknown'),
                    'ninja_url' => "https://app.ninjarmm.com/#/deviceDashboard/{$ninjaId}/overview",
                    'is_active' => true,
                    'deleted_at' => null,
                ];

                if ($asset) {
                    if ($mappedType && (! $asset->asset_type || $asset->asset_type === 'Unknown')) {
                        $baseData['asset_type'] = $mappedType;
                    }
                    $asset->update($baseData);
                } else {
                    $baseData['asset_type'] = $mappedType;
                    $asset = Asset::create($baseData);
                }

                // Full enrichment from device detail API
                $this->enrichFromDetail($asset, $ninjaId);

                $existing ? $result->updated++ : $result->created++;
            } catch (\Throwable $e) {
                $deviceId = $device['id'] ?? 'unknown';
                $result->recordError("Device {$deviceId}: {$e->getMessage()}");
            }

            usleep(100_000); // 100ms between devices to avoid API rate limits
        }

        // psa-u97k: a device leaving Ninja must NEVER delete/deactivate the shared PSA Asset — it may still be
        // managed by another RMM (Tactical etc.), and offboarding is a DELIBERATE operator action. Clear ONLY
        // Ninja's own fields; the Asset (and its other RMM links + hardware data) persists. Mirrors the
        // Cipp/ControlD/Zorus stale-clear pattern. The non-empty-remote guard prevents wiping on an empty fetch.
        $remoteIds = collect($devices)->pluck('id')->filter()->all();
        if (! empty($remoteIds)) {
            $cleared = Asset::where('client_id', $client->id)
                ->whereNotNull('ninja_id')
                ->whereNotIn('ninja_id', $remoteIds)
                ->update([
                    'ninja_id' => null,
                    'ninja_url' => null,
                    'ninja_synced_at' => null,
                ]);

            if ($cleared > 0) {
                $result->deactivated += $cleared;
                Log::info("[NinjaSync] {$cleared} device(s) left Ninja — unlinked from Ninja, asset retained", [
                    'client' => $client->name,
                ]);
            }
        }

        // Wiki Phase 2: deterministic environment facts from this sync (never breaks the sync).
        app(\App\Services\Wiki\SyncFactWriter::class)->safeWriteAssetFacts($client);

        return $result;
    }

    /**
     * Fetch detailed hardware info for a single device and update the asset.
     * Used by the asset page view and webhook processing — throws on failure.
     */
    public function syncDeviceDetail(Asset $asset): void
    {
        if (! $asset->ninja_id) {
            return;
        }

        $this->enrichFromDetail($asset, $asset->ninja_id, throw: true);
        $this->enrichWarrantyForDevice($asset);
    }

    /**
     * Fetch device detail from Ninja API and enrich the asset with hardware data.
     */
    private function enrichFromDetail(Asset $asset, int $ninjaId, bool $throw = false): void
    {
        try {
            $detail = $this->ninja->getDeviceDetail($ninjaId);
        } catch (NinjaClientException $e) {
            Log::warning('[NinjaSync] Failed to fetch device detail', [
                'ninja_id' => $ninjaId,
                'error' => $e->getMessage(),
            ]);
            if ($throw) {
                throw $e;
            }

            return;
        }

        // Parse processors
        $processors = $detail['_processors'] ?? [];
        $cpu = null;
        if (! empty($processors)) {
            $proc = $processors[0];
            $name = $proc['name'] ?? 'Unknown CPU';
            $cores = $proc['coreCount'] ?? null;
            $cpu = $cores ? "{$name} ({$cores} cores)" : $name;
        }

        // Parse volumes
        $volumes = $detail['_volumes'] ?? [];
        $diskParts = [];
        foreach ($volumes as $vol) {
            $name = $vol['name'] ?? '';
            $capacityGb = isset($vol['capacity']) ? round($vol['capacity'] / (1024 * 1024 * 1024)) : null;
            $freeGb = isset($vol['freeSpace']) ? round($vol['freeSpace'] / (1024 * 1024 * 1024)) : null;

            if ($capacityGb) {
                $freePercent = $freeGb !== null ? round(($freeGb / $capacityGb) * 100) : null;
                $part = "{$capacityGb} GB";
                if ($freePercent !== null) {
                    $part .= " ({$freePercent}% free)";
                }
                if ($name) {
                    $part = "{$name}: {$part}";
                }
                $diskParts[] = $part;
            }
        }

        $serial = $this->resolveSerial($detail);
        $facts = self::deviceFacts($detail, $asset);

        $asset->update([
            'serial_number' => $serial ?: $asset->serial_number,
            'os' => $facts['os'],
            'cpu' => $cpu,
            'ram_gb' => $facts['ram_gb'],
            'disk_summary' => $diskParts ? implode(', ', $diskParts) : null,
            'last_user' => $facts['last_user'],
            'ip_address' => $facts['ip_address'],
            'last_seen_at' => $facts['last_seen_at'],
            'rmm_online' => $facts['rmm_online'],
            'last_boot_at' => $facts['last_boot_at'],
            'needs_reboot' => $facts['needs_reboot'],
            'ninja_synced_at' => now(),
        ]);
    }

    /** The columns syncNow() may write, on its one asset only. */
    public const SYNC_NOW_WRITES = [
        'os', 'ram_gb', 'last_user', 'ip_address', 'last_seen_at', 'rmm_online', 'last_boot_at', 'needs_reboot',
        'ninja_synced_at',
    ];

    /**
     * Asset Sources "Sync now" (nfuBmADp S6): ONE `GET /v2/device/{id}`
     * (NinjaClient::getDevice, single attempt: no retry and no sleep on 429)
     * for this asset's linked device. When no OAuth token is cached the client
     * first makes its token POST, as every NinjaClient call does.
     *
     * ok writes THIS asset only, the columns enrichFromDetail() derives from
     * that body (deviceFacts(): os, ram_gb, last_user, ip_address,
     * last_seen_at, rmm_online, last_boot_at, needs_reboot) and
     * ninja_synced_at. It does not read processors, volumes or custom fields,
     * so it never writes cpu, disk_summary or the warranty dates; it never
     * writes serial_number, ninja_id, ninja_url, client_id, is_active or the
     * name, never creates an asset and never releases a link. The save is an
     * ordinary model save, as enrichFromDetail()'s update() is, so when
     * last_user changes AssetObserver links that user to the asset
     * (AssetUserAssignmentService::assignForAsset).
     *
     * Every other answer writes nothing (ninja_synced_at included):
     *   other_client  the device's organizationId is not the asset client's
     *                 ninja_org_id (checked only when both are known);
     *   drift         a body that is not one device object, about another
     *                 device, or with a value the columns cannot take, or a
     *                 token reply without a token;
     *   failureReason(status) for an HTTP status from the GET or the token
     *                 POST (404 not_found_at_vendor, the link kept; 429
     *                 vendor_429 with retry_after_s from Retry-After; any other
     *                 http_NNN);
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

        $ninjaId = (int) $asset->ninja_id;
        try {
            $detail = $this->ninja->getDevice($ninjaId, singleAttempt: true);
        } catch (NinjaClientException $e) {
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
            if (! \App\Support\NinjaConfig::isEnabled()) {
                return $answer('switched_off');
            }
            if (! \App\Support\NinjaConfig::isConfigured()) {
                return $answer('not_configured');
            }

            // A 2xx body that is not JSON, or a token reply without a token.
            return $answer('drift');
        } catch (\TypeError) {
            // A JSON body that is not an object or list (getDevice()'s array return type).
            return $answer('drift');
        }

        if (! is_array($detail) || array_is_list($detail) || ! is_int($detail['id'] ?? null) || $detail['id'] !== $ninjaId) {
            return $answer('drift');
        }
        $org = $detail['organizationId'] ?? null;
        $mapped = $asset->client?->ninja_org_id;
        if ($org !== null && ! is_int($org)) {
            return $answer('drift');
        }
        if ($org !== null && $mapped !== null && $org !== (int) $mapped) {
            return $answer('other_client');
        }

        try {
            $facts = self::deviceFacts($detail, $asset);
        } catch (\Throwable) {
            return $answer('drift');
        }
        if (! self::fitsColumns($facts)) {
            return $answer('drift');
        }

        $asset->forceFill($facts + ['ninja_synced_at' => now()]);
        // getDirty() compares through the casts, so null and false differ on a boolean column.
        $changed = array_values(array_intersect(self::SYNC_NOW_WRITES, array_keys($asset->getDirty())));
        $asset->save();

        return $answer('ok', changed: $changed, thisRead: ['online' => $facts['rmm_online']]);
    }

    /**
     * Every value deviceFacts() built fits its assets column (MariaDB strict mode
     * refuses what SQLite stores): strings of at most 255 characters (ip_address
     * 45), ram_gb within decimal(8,2), dates as dates, flags as bools or null.
     */
    private static function fitsColumns(array $facts): bool
    {
        $str = fn ($v, int $max) => $v === null || (is_string($v) && mb_strlen($v) <= $max);
        $date = fn ($v) => $v === null || $v instanceof \DateTimeInterface;

        return $str($facts['os'], 255) && $str($facts['last_user'], 255) && $str($facts['ip_address'], 45)
            && ($facts['ram_gb'] === null || (is_float($facts['ram_gb']) && $facts['ram_gb'] >= 0 && $facts['ram_gb'] < 1000000))
            && $date($facts['last_seen_at']) && $date($facts['last_boot_at'])
            && is_bool($facts['rmm_online']) && ($facts['needs_reboot'] === null || is_bool($facts['needs_reboot']));
    }

    /**
     * The asset columns enrichFromDetail() derives from the GET /v2/device/{id} body
     * alone (no processors, volumes or custom fields), with its rules: a value the
     * body does not carry keeps the stored one for os, ip_address, last_seen_at,
     * last_boot_at and needs_reboot; ram_gb and last_user are written as the body
     * has them (null included); rmm_online is a last contact within
     * ONLINE_THRESHOLD_MINUTES. Shared by enrichFromDetail() and syncNow().
     *
     * @return array{os: mixed, ram_gb: ?float, last_user: mixed, ip_address: mixed, last_seen_at: mixed, rmm_online: bool, last_boot_at: mixed, needs_reboot: ?bool}
     */
    private static function deviceFacts(array $detail, Asset $asset): array
    {
        $ramGb = null;
        if (isset($detail['system']['memory']['capacity'])) {
            $ramGb = round($detail['system']['memory']['capacity'] / (1024 * 1024 * 1024), 2);
        }
        $lastContact = isset($detail['lastContact']) ? Carbon::createFromTimestamp($detail['lastContact']) : null;

        // Parse OS boot/reboot data
        $lastBootAt = ! empty($detail['os']['lastBootTime'])
            ? Carbon::createFromTimestamp((int) $detail['os']['lastBootTime'])
            : null;
        $needsReboot = isset($detail['os']['needsReboot']) ? (bool) $detail['os']['needsReboot'] : null;

        return [
            'os' => $detail['os']['name'] ?? $asset->os,
            'ram_gb' => $ramGb,
            'last_user' => $detail['lastLoggedInUser'] ?? null,
            'ip_address' => $detail['ipAddresses'][0] ?? $asset->ip_address,
            'last_seen_at' => $lastContact ?? $asset->last_seen_at,
            'rmm_online' => $lastContact && $lastContact->diffInMinutes(now()) <= self::ONLINE_THRESHOLD_MINUTES,
            'last_boot_at' => $lastBootAt ?? $asset->last_boot_at,
            'needs_reboot' => $needsReboot ?? $asset->needs_reboot,
        ];
    }

    /**
     * Sync devices for all clients with ninja_org_id set.
     */
    public function syncAllDevices(?callable $onProgress = null): SyncResult
    {
        $result = new SyncResult;
        $clients = Client::whereNotNull('ninja_org_id')->get();
        $total = $clients->count();

        foreach ($clients as $i => $client) {
            $clientResult = $this->syncDevicesForClient($client);
            $result->created += $clientResult->created;
            $result->updated += $clientResult->updated;
            $result->errors += $clientResult->errors;
            $result->errorMessages = array_merge($result->errorMessages, $clientResult->errorMessages);

            if ($onProgress) {
                $onProgress($i + 1, $total, $client->name);
            }
        }

        // Warranty sync from custom fields (single paginated API call for all devices)
        $this->syncWarrantyData();

        return $result;
    }

    /**
     * Sync warranty and purchase dates from Ninja custom fields.
     * Fetches all custom fields in one paginated call and updates matching assets.
     */
    public function syncWarrantyData(): void
    {
        try {
            $records = $this->ninja->getCustomFields();
        } catch (NinjaClientException $e) {
            Log::warning('[NinjaSync] Failed to fetch custom fields for warranty sync: '.$e->getMessage());

            return;
        }

        $lookup = collect($records)->keyBy('deviceId');

        Asset::whereNotNull('ninja_id')
            ->chunk(200, function ($assets) use ($lookup) {
                foreach ($assets as $asset) {
                    $record = $lookup->get($asset->ninja_id);
                    if (! $record) {
                        continue;
                    }

                    $fields = $record['fields'] ?? [];
                    $updates = [];

                    $warrantyEnd = $this->parseNinjaTimestamp($fields['warrantyExpirationDate'] ?? null);
                    if ($warrantyEnd) {
                        $updates['warranty_end'] = $warrantyEnd->toDateString();
                    }

                    $purchaseDate = $this->parseNinjaTimestamp($fields['purchaseDate'] ?? null);
                    if ($purchaseDate) {
                        $updates['warranty_start'] = $purchaseDate->toDateString();
                    }

                    if (! empty($updates)) {
                        $asset->update($updates);
                    }
                }
            });
    }

    /**
     * Enrich warranty data for a single device from Ninja custom fields.
     */
    private function enrichWarrantyForDevice(Asset $asset): void
    {
        try {
            $fields = $this->ninja->getDeviceCustomFields($asset->ninja_id);
        } catch (\Throwable $e) {
            Log::debug('[NinjaSync] Failed to fetch custom fields for device', [
                'ninja_id' => $asset->ninja_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $updates = [];

        $warrantyEnd = $this->parseNinjaTimestamp($fields['warrantyExpirationDate'] ?? null);
        if ($warrantyEnd) {
            $updates['warranty_end'] = $warrantyEnd->toDateString();
        }

        $purchaseDate = $this->parseNinjaTimestamp($fields['purchaseDate'] ?? null);
        if ($purchaseDate) {
            $updates['warranty_start'] = $purchaseDate->toDateString();
        }

        if (! empty($updates)) {
            $asset->update($updates);
        }
    }

    /**
     * Parse a Ninja custom field timestamp (milliseconds or microseconds since epoch).
     */
    private function parseNinjaTimestamp($value): ?Carbon
    {
        if (! $value || ! is_numeric($value)) {
            return null;
        }

        $ts = (int) $value;

        // Microseconds (>= year 2100 in milliseconds, ~33 trillion)
        if ($ts > 4_000_000_000_000) {
            $ts = (int) ($ts / 1_000);
        }

        // Now in milliseconds — convert to seconds
        if ($ts > 4_000_000_000) {
            $ts = (int) ($ts / 1_000);
        }

        try {
            return Carbon::createFromTimestamp($ts);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fetch backup job history and integrity check results for an asset.
     * On-demand for the asset detail page — never blocks page render.
     */
    public function getBackupJobData(Asset $asset): ?array
    {
        if (! $asset->ninja_id) {
            return null;
        }

        try {
            $jobs = $this->ninja->getBackupJobs($asset->ninja_id);
            $integrityChecks = $this->ninja->getIntegrityCheckJobs($asset->ninja_id);

            return [
                'jobs' => $jobs,
                'integrityChecks' => $integrityChecks,
            ];
        } catch (\Throwable $e) {
            Log::debug('[NinjaSync] Backup job data fetch failed', [
                'ninja_id' => $asset->ninja_id,
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Sync a single device from a webhook event (new device not yet in local DB).
     * Fetches the device from Ninja API, resolves the client from org ID, creates/updates
     * the asset, then enriches with full hardware detail.
     */
    public function syncDeviceFromWebhook(int $ninjaDeviceId): void
    {
        $device = $this->ninja->getDevice($ninjaDeviceId);

        $orgId = $device['organizationId'] ?? null;
        $client = $orgId ? Client::where('ninja_org_id', $orgId)->first() : null;

        $hostname = $device['systemName'] ?? $device['dnsName'] ?? null;

        $data = [
            'ninja_id' => $ninjaDeviceId,
            'client_id' => $client?->id,
            'hostname' => $hostname,
            'name' => $hostname ?: 'Unknown',
            'ninja_url' => "https://app.ninjarmm.com/#/deviceDashboard/{$ninjaDeviceId}/overview",
            'asset_type' => $this->mapDeviceRole($device['nodeRoleId'] ?? null, $device['nodeClass'] ?? null),
            'is_active' => true,
        ];

        $asset = Asset::withTrashed()->where('ninja_id', $ninjaDeviceId)->first();

        if ($asset) {
            $asset->update(array_merge($data, ['deleted_at' => null]));
        } else {
            $asset = Asset::create($data);
        }

        // Full enrichment (serial, CPU, RAM, disk, status, warranty, boot data)
        $this->enrichFromDetail($asset, $ninjaDeviceId);

        Log::info('[NinjaSync] Device synced from webhook', [
            'ninja_id' => $ninjaDeviceId,
            'client' => $client?->name ?? 'unassigned',
        ]);
    }

    /**
     * Extract a meaningful serial number from the Ninja device detail.
     * Prefers biosSerialNumber (real hardware serial), falls back to serialNumber.
     * Filters out junk values like "Standard", "Default string", "To Be Filled By O.E.M.", etc.
     */
    private function resolveSerial(array $detail): ?string
    {
        $junkValues = ['standard', 'default string', 'to be filled by o.e.m.', 'none', 'not specified', 'system serial number'];

        $bios = trim($detail['system']['biosSerialNumber'] ?? '');
        if ($bios !== '' && ! in_array(strtolower($bios), $junkValues, true)) {
            return $bios;
        }

        $serial = trim($detail['system']['serialNumber'] ?? '');
        if ($serial !== '' && ! in_array(strtolower($serial), $junkValues, true)) {
            return $serial;
        }

        return null;
    }

    /**
     * Map Ninja device to a friendly asset type.
     * Uses nodeRoleId for built-in roles, falls back to nodeClass for custom roles.
     */
    private function mapDeviceRole(?int $roleId, ?string $nodeClass = null): ?string
    {
        return match ($roleId) {
            1 => 'Windows Workstation',
            2 => 'Windows Server',
            3 => 'Mac',
            4 => 'Linux Workstation',
            5 => 'Linux Server',
            7 => 'VMware Host',
            default => match ($nodeClass) {
                'WINDOWS_WORKSTATION' => 'Windows Workstation',
                'WINDOWS_SERVER' => 'Windows Server',
                'MAC' => 'Mac',
                'LINUX_WORKSTATION' => 'Linux Workstation',
                'LINUX_SERVER' => 'Linux Server',
                'VMWARE_HOST' => 'VMware Host',
                default => null,
            },
        };
    }
}
