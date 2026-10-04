<?php

namespace App\Services\Litsrmm;

use App\Models\Asset;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\SyncResult;
use App\Support\LitsrmmSerial;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LITSRMM stage 3: devices become PSA assets.
 *
 * LITSRMM is an RMM of record, so unlike AutoElevate or Control D this sync
 * CREATES assets, as LevelSyncService does, and writes the hardware facts a
 * Level-fed asset carries (CPU, RAM, disks, IP, last boot, pending reboot).
 * The per-client vendor mapping (clients.litsrmm_client_id) is the handover
 * switch: a client is fed by one RMM, never both.
 *
 * ITS SAFEGUARDS COME FROM AutoElevateAssetSyncService, NOT FROM LEVEL:
 *
 *  - SCOPED. A device is only ever matched against live assets of the ONE
 *    client whose litsrmm_client_id it reports. LevelSyncService matches
 *    serials across the whole estate; this never does. A device of a vendor
 *    client nobody mapped is not read, created or matched: unmapped means not
 *    ours.
 *  - NO GUESSES. Match order is our own link, then a real serial, then the
 *    hostname. When two assets or two devices could be the same machine the
 *    device is REPORTED as skipped and nothing is written, and no asset is
 *    created either, because a new one would duplicate one of the candidates.
 *    Placeholder serials ("System Serial Number" and family, see
 *    LitsrmmSerial) never match and are never written.
 *  - A FAILED READ CHANGES NOTHING. A degraded read is not evidence that
 *    machines are gone.
 *  - A DEVICE THAT LEAVES loses only our link (litsrmm_device_id,
 *    litsrmm_synced_at). The asset, its hardware facts and every other
 *    vendor's link stay: offboarding is a deliberate operator action
 *    (psa-u97k). A retired device counts as having left.
 *  - A DELETED ASSET IS NEVER REVIVED. Soft-deleted rows can neither match
 *    nor block a match; a deletion is a person's decision.
 *  - A NAME A PERSON CHOSE IS KEPT. `name` is set from the hostname only when
 *    the asset is created.
 *  - ABSENT IS NOT NULL. A hardware category the vendor never collected
 *    writes nothing, so it cannot erase what the asset already knows.
 *
 * Every vendor call happens OUTSIDE the per-client transaction.
 */
class LitsrmmAssetSyncService
{
    /**
     * What the vendor's agent reports as lastUser when the machine sits at the
     * sign-in or lock screen (InteractiveUser.NobodySignedIn in their agent).
     * It is a fact about NOW, not a user, while an asset's last_user means the
     * last person who used the machine. So it never overwrites a real name and
     * is never written to a new asset.
     */
    public const NOBODY_SIGNED_IN = '(none)';

    /** license_types.vendor for LITSRMM seats; also the registry's licenseVendor. */
    public const LICENSE_VENDOR = 'litsrmm';

    public function __construct(private readonly LitsrmmClient $litsrmm) {}

    /**
     * Hostname match key: trimmed, lowercased, first DNS label. Empty is null
     * and never matches. Same rule as AutoElevateAssetSyncService.
     */
    public static function normalizeHostname(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $short = explode('.', mb_strtolower(trim($name)))[0];

        return $short === '' ? null : $short;
    }

    /**
     * One line for an operator after a button press: the counts, then every
     * error and every refused guess, so a flash message never hides why a
     * machine was not linked.
     */
    public static function describe(SyncResult $result): string
    {
        $message = "LITSRMM device sync: {$result->summary()}.";

        if ($result->errorMessages !== []) {
            $message .= ' Errors: '.implode('; ', $result->errorMessages).'.';
        }

        if ($result->skippedMessages !== []) {
            $message .= ' Skipped: '.implode('; ', $result->skippedMessages).'.';
        }

        return $message;
    }

    /**
     * Sync every mapped, operational client, or only $only (the client page's
     * Sync button). A single-client run writes nothing outside that client:
     * the estate-wide sweeps (links and seats of clients no longer mapped)
     * belong to a full run only.
     */
    public function sync(?Client $only = null): SyncResult
    {
        $result = new SyncResult;

        $clients = Client::query()
            ->whereNotNull('litsrmm_client_id')
            ->operational()
            ->when($only !== null, fn ($q) => $q->whereKey($only->id))
            ->orderBy('id')
            ->get();

        if ($only !== null && $clients->isEmpty()) {
            $result->recordError("{$only->name} is not mapped to LITSRMM, or is not an active client; nothing was read.");

            return $result;
        }

        if ($clients->isEmpty()) {
            $this->sweepUnmapped([], $result);

            return $result;
        }

        try {
            $devices = $this->litsrmm->getDevices();
        } catch (LitsrmmClientException $e) {
            // Nothing is touched: not the links, not the seats, not the sweeps.
            Log::warning('[LitsrmmAssetSync] device list read failed', ['error' => $e->getMessage()]);
            $result->recordError("Failed to read LITSRMM devices: {$e->getMessage()}");

            return $result;
        }

        $byVendorClient = [];
        foreach ($devices as $device) {
            $byVendorClient[strtolower($device->clientId)][] = $device;
        }

        foreach ($clients as $client) {
            $rows = $byVendorClient[strtolower($client->litsrmm_client_id)] ?? [];
            $hardware = $this->readHardware($rows, $result);

            DB::transaction(function () use ($client, $rows, $hardware, $result) {
                $this->syncClient($client, $rows, $hardware, $result);
                $this->syncSeats($client, $rows);
            });
        }

        if ($only === null) {
            $this->sweepUnmapped($clients->pluck('id')->all(), $result);
        }

        return $result;
    }

    /**
     * License seats, the same two SKUs LevelSyncService keeps for Level: one
     * seat per device the RMM actually MANAGES, which is a device running the
     * vendor's agent that is not retired (the owner's ruling). The vendor's
     * list also carries machines known only from Huntress or Control D, and
     * those are not LITSRMM seats. A half-retired device is not counted
     * either: nobody has said what it means.
     *
     * Keyed on (type, client), not on vendor_ref, so a client re-mapped to
     * another LITSRMM client updates its one row rather than leaving the old
     * row billing alongside a new one. The license types are created once and
     * never renamed, so an operator's name or pricing on them survives.
     *
     * @param  list<LitsrmmDevice>  $rows
     */
    private function syncSeats(Client $client, array $rows): void
    {
        $counts = ['rmm_server' => 0, 'rmm_workstation' => 0];

        foreach ($rows as $device) {
            if ($device->agentVersion === null || $device->isRetired() || $device->hasSplitRetiredState()) {
                continue;
            }

            $isServer = str_contains(mb_strtolower($device->osName ?? ''), 'server');
            $counts[$isServer ? 'rmm_server' : 'rmm_workstation']++;
        }

        foreach ($counts as $sku => $quantity) {
            $type = LicenseType::firstOrCreate(
                ['vendor' => self::LICENSE_VENDOR, 'vendor_sku_id' => $sku],
                ['name' => $sku === 'rmm_server' ? 'LITSRMM — Server' : 'LITSRMM — Workstation', 'is_active' => true],
            );

            License::updateOrCreate(
                ['license_type_id' => $type->id, 'client_id' => $client->id],
                [
                    'vendor_ref' => $client->litsrmm_client_id,
                    'quantity' => $quantity,
                    'status' => $quantity > 0 ? 'active' : 'suspended',
                    'synced_at' => now(),
                ],
            );
        }
    }

    /**
     * Full runs only: links on assets, and seats, of clients that are no
     * longer mapped (or no longer operational, for links).
     */
    private function sweepUnmapped(array $mappedClientIds, SyncResult $result): void
    {
        $this->clearUnmappedClients($mappedClientIds, $result);
        $result->deactivated += License::deactivateOrphaned(self::LICENSE_VENDOR, 'litsrmm_client_id');
    }

    /**
     * One detail read per device that runs the vendor's agent. Agentless
     * devices have nothing collected, retired ones are not written, and a
     * half-retired one is left alone, so none of them is read.
     *
     * @param  list<LitsrmmDevice>  $rows
     * @return array<string, LitsrmmHardware> device id => hardware
     */
    private function readHardware(array $rows, SyncResult $result): array
    {
        $hardware = [];

        foreach ($rows as $device) {
            if ($device->agentVersion === null || $device->isRetired() || $device->hasSplitRetiredState()) {
                continue;
            }

            try {
                $hardware[$device->id] = LitsrmmHardware::fromInventory($this->litsrmm->getDevice($device->id)['inventory']);
            } catch (LitsrmmClientException $e) {
                // The list facts are still written; only the hardware is stale.
                $result->recordError("{$device->hostname}: hardware not refreshed ({$e->getMessage()})");
            }
        }

        return $hardware;
    }

    /**
     * @param  list<LitsrmmDevice>  $rows
     * @param  array<string, LitsrmmHardware>  $hardware
     */
    private function syncClient(Client $client, array $rows, array $hardware, SyncResult $result): void
    {
        // Live assets of THIS client only. SoftDeletes' default scope excludes
        // trashed rows, so a deleted asset can neither match nor block a match.
        $assets = Asset::where('client_id', $client->id)->get();

        $linked = [];
        foreach ($assets as $asset) {
            if ($asset->litsrmm_device_id !== null) {
                $linked[strtolower($asset->litsrmm_device_id)] ??= $asset;
            }
        }

        // Two devices answering to one name in one client cannot both be the
        // asset that carries it.
        $nameCounts = [];
        foreach ($rows as $device) {
            if (! $device->isRetired()) {
                $key = self::normalizeHostname($device->hostname);
                $nameCounts[$key] = ($nameCounts[$key] ?? 0) + 1;
            }
        }

        /** @var array<int, true> $kept asset ids whose link this run confirmed or made */
        $kept = [];

        foreach ($rows as $device) {
            $asset = $linked[strtolower($device->id)] ?? null;

            if ($device->hasSplitRetiredState()) {
                $result->recordSkipped("{$device->hostname}: only one of its two states reads retired; left as it was");
                if ($asset !== null) {
                    $kept[$asset->id] = true;
                }

                continue;
            }

            if ($device->isRetired()) {
                // Its link, if any, is released below with the devices that left.
                continue;
            }

            if ($asset === null) {
                $match = $this->matchUnlinked($device, $assets, $kept, $nameCounts);

                if ($match === false) {
                    $result->recordSkipped("{$device->hostname}: more than one asset or device could be this machine; not linked, not created");

                    continue;
                }

                $asset = $match;
            }

            if ($asset !== null && isset($kept[$asset->id])) {
                // Defensive: never let a second device overwrite a link made this run.
                $result->recordSkipped("{$device->hostname}: its asset was already claimed by another device this run");

                continue;
            }

            $asset = $this->write($client, $asset, $device, $hardware[$device->id] ?? null, $result);
            $kept[$asset->id] = true;
        }

        // A successful read that did not return a linked device: release OUR
        // link and nothing else.
        $result->deactivated += Asset::where('client_id', $client->id)
            ->whereNotNull('litsrmm_device_id')
            ->when($kept !== [], fn ($q) => $q->whereNotIn('id', array_keys($kept)))
            ->update(self::clearedColumns());
    }

    /**
     * An unlinked live asset of this client for $device: by real serial, then
     * by hostname.
     *
     * @param  Collection<int, Asset>  $assets
     * @param  array<int, true>  $kept
     * @param  array<string, int>  $nameCounts
     * @return Asset|null|false the asset; null for none (create one); false for ambiguous
     */
    private function matchUnlinked(LitsrmmDevice $device, Collection $assets, array $kept, array $nameCounts): Asset|null|false
    {
        $free = $assets->filter(fn (Asset $a) => $a->litsrmm_device_id === null && ! isset($kept[$a->id]));

        $serial = LitsrmmSerial::identity($device->serial);
        if ($serial !== null) {
            $bySerial = $free->filter(fn (Asset $a) => LitsrmmSerial::identity($a->serial_number) === $serial)->values();

            if ($bySerial->count() > 1) {
                return false;
            }
            if ($bySerial->count() === 1) {
                return $bySerial->first();
            }
        }

        $name = self::normalizeHostname($device->hostname);
        if ($name === null) {
            return null;
        }

        $byName = $free->filter(fn (Asset $a) => self::normalizeHostname($a->hostname) === $name)->values();

        if ($byName->isEmpty()) {
            return null;
        }

        if ($byName->count() > 1 || ($nameCounts[$name] ?? 0) > 1) {
            return false;
        }

        return $byName->first();
    }

    private function write(Client $client, ?Asset $asset, LitsrmmDevice $device, ?LitsrmmHardware $hardware, SyncResult $result): Asset
    {
        $data = [
            'litsrmm_device_id' => $device->id,
            'litsrmm_synced_at' => now(),
            'hostname' => $device->hostname,
            'rmm_online' => $device->availabilityState === 'online',
        ];

        // Null on the list means the vendor's agent is not there to say, not
        // that the machine has no OS or user: keep what the asset knows.
        // The build is what tells 22H2 from 23H2 and Windows 10 from 11, and a
        // Level-fed asset carried it, so a migrated asset keeps it.
        if ($device->osName !== null) {
            $data['os'] = $device->osBuild !== null && $device->osBuild !== ''
                ? "{$device->osName} (build {$device->osBuild})"
                : $device->osName;
        }
        if ($device->lastUser !== null && $device->lastUser !== self::NOBODY_SIGNED_IN) {
            $data['last_user'] = $device->lastUser;
        }
        if ($device->lastSeen !== null) {
            $data['last_seen_at'] = Carbon::instance($device->lastSeen);
        }

        // Never a placeholder, and never over a serial the asset already has.
        $serial = $device->matchableSerial();
        if ($serial !== null && ($asset === null || blank($asset->serial_number))) {
            $data['serial_number'] = $serial;
        }

        if ($hardware !== null) {
            $data = array_merge($data, $hardware->columns());
        }

        if ($asset !== null) {
            $asset->forceFill($data)->save();
            $result->updated++;

            return $asset;
        }

        $result->created++;

        return Asset::forceCreate(array_merge($data, [
            'client_id' => $client->id,
            'name' => $device->hostname,
            'asset_type' => self::assetType($device->osName),
            'is_active' => true,
        ]));
    }

    /** "Windows Workstation" / "Windows Server", the form LevelSyncService writes. */
    private static function assetType(?string $osName): ?string
    {
        if ($osName === null) {
            return null;
        }

        $os = mb_strtolower($osName);

        if (str_contains($os, 'server')) {
            return 'Windows Server';
        }

        return str_starts_with($os, 'win') ? 'Windows Workstation' : null;
    }

    /** Links on live assets whose client is no longer mapped or no longer operational. */
    private function clearUnmappedClients(array $mappedClientIds, SyncResult $result): void
    {
        $result->deactivated += Asset::whereNotNull('litsrmm_device_id')
            ->when($mappedClientIds !== [], fn ($q) => $q->whereNotIn('client_id', $mappedClientIds))
            ->update(self::clearedColumns());
    }

    /** @return array<string, null> */
    private static function clearedColumns(): array
    {
        return [
            'litsrmm_device_id' => null,
            'litsrmm_synced_at' => null,
        ];
    }
}
