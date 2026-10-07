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
 *  - A FAILED LIST READ CHANGES NOTHING. A degraded read is not evidence
 *    that machines are gone. A failed DETAIL read (getDevice) costs only that
 *    device's hardware: its list facts are still written, its hardware is
 *    left as it was, and the run records an error. A detail read answered
 *    404 means the device left between the list and the detail read, so its
 *    asset is left exactly as it was (no write, its link kept) and it is
 *    reported as skipped, not as an error. It still counts for seats, which
 *    come from the list. A hardware category in a shape we do not
 *    read is drift: it is logged and recorded as an error.
 *  - A SHORT LIST IS NOT THE TRUTH (#5343). A client is refused, and nothing
 *    of it is read in detail or written (no asset, no link released, no
 *    retirement, no seat), when either:
 *      (a) the read lists none of its devices while it has an asset carrying
 *          a LITSRMM link or a LITSRMM seat with a quantity above 0; or
 *      (b) more than RELEASE_FLOOR of its linked assets, AND more than half
 *          of them, name a device id the read does not list at all.
 *    The refusal is an error on the result (so the command exits FAILURE)
 *    and a log line carrying the PSA client id and the counts only. A normal
 *    shrink inside that bound still releases.
 *  - A DEVICE THAT LEAVES loses only our link (litsrmm_device_id,
 *    litsrmm_synced_at), except on an asset the sync retired (below). The
 *    asset, its hardware facts and every other vendor's link stay:
 *    offboarding is a deliberate operator action (psa-u97k).
 *  - A RETIRED DEVICE also marks its asset inactive, REVERSIBLY: is_active
 *    false and litsrmm_retired_at set, so it no longer looks live, and the
 *    link kept. Those assets are counted in details['retired'], never in
 *    `deactivated`. An asset that still carries another RMM's link
 *    (level_id, ninja_id or tactical_asset_id) is not marked inactive: it
 *    keeps is_active, gets no litsrmm_retired_at, keeps our link, and every
 *    run that reads its device retired reports it as skipped (#5368). The same device coming back, or the machine re-enrolled under
 *    a new device id (matched by its real serial, or by hostname while the
 *    asset still carries the old link), reactivates it. A person changing
 *    is_active ends the sync's claim (Asset clears litsrmm_retired_at and the
 *    link), and an asset a person made inactive is never reactivated, never
 *    stamped retired, and never matched by hostname (a real serial still
 *    links it, leaving it inactive).
 *  - A LINK TO A DEVICE THIS READ DOES NOT LIST LIVE does not hold its asset:
 *    a machine re-enrolled under a new device id takes its own asset back
 *    instead of creating a second, billable one.
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

    /**
     * #5343: a read may release at most this many of a client's links to
     * device ids it does not list at all, or at most half of them, whichever
     * is larger. Past both, the read is treated as short and the client is
     * refused (see the class docblock).
     */
    public const RELEASE_FLOOR = 3;

    /** Other RMMs' link columns on assets (#5368): a link here keeps an asset active. */
    public const OTHER_RMM_LINKS = ['level_id', 'ninja_id', 'tactical_asset_id'];

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

        if (($result->details['retired'] ?? 0) > 0) {
            $message .= " {$result->details['retired']} asset(s) marked inactive because their device is retired.";
        }

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

        $result->details['retired'] = 0;

        foreach ($clients as $client) {
            $rows = $byVendorClient[strtolower($client->litsrmm_client_id)] ?? [];

            if ($this->refuseShortRead($client, $rows, $result)) {
                continue;
            }

            [$hardware, $left] = $this->readHardware($client, $rows, $result);

            DB::transaction(function () use ($client, $rows, $hardware, $left, $result) {
                $this->syncClient($client, $rows, $hardware, $left, $result);
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
     * #5343: whether this successful read is too short to act on for $client
     * (rule (a) or (b) in the class docblock). A refused client is recorded as
     * an error and logged with its PSA id and the counts, and nothing of it is
     * read in detail or written. Only links that the release step could clear
     * count: an asset the sync retired keeps its link whatever the read says.
     *
     * @param  list<LitsrmmDevice>  $rows
     */
    private function refuseShortRead(Client $client, array $rows, SyncResult $result): bool
    {
        $linkIds = Asset::where('client_id', $client->id)
            ->whereNotNull('litsrmm_device_id')
            ->whereNull('litsrmm_retired_at')
            ->pluck('litsrmm_device_id')
            ->map(fn ($id) => strtolower($id))
            ->all();

        $listed = [];
        foreach ($rows as $device) {
            $listed[strtolower($device->id)] = true;
        }

        $unlisted = count(array_filter($linkIds, fn ($id) => ! isset($listed[$id])));

        if ($rows === []) {
            $hasSeats = License::where('client_id', $client->id)
                ->where('quantity', '>', 0)
                ->whereHas('licenseType', fn ($q) => $q->where('vendor', self::LICENSE_VENDOR))
                ->exists();
            $refuse = $linkIds !== [] || $hasSeats;
        } else {
            $refuse = $unlisted > self::RELEASE_FLOOR && $unlisted * 2 > count($linkIds);
        }

        if (! $refuse) {
            return false;
        }

        Log::warning('[LitsrmmAssetSync] client refused: device list too short to act on', [
            'client_id' => $client->id,
            'listed' => count($rows),
            'linked' => count($linkIds),
            'unlisted' => $unlisted,
        ]);
        $result->details['short_read_refused'] = ($result->details['short_read_refused'] ?? 0) + 1;
        $result->recordError(
            "{$client->name}: the device list is too short to act on (it lists ".count($rows)
            ." device(s); {$unlisted} of its ".count($linkIds).' linked asset(s) name a device it does not list);'
            .' nothing of this client was changed'
        );

        return true;
    }

    /**
     * A refused guess or a device deliberately left alone: on the result for
     * the operator, and in the log by PSA client id and a fixed reason only,
     * so the scheduled path keeps it too (#5360).
     */
    private function recordSkip(SyncResult $result, Client $client, string $reason, string $message): void
    {
        Log::warning('[LitsrmmAssetSync] device not written', [
            'client_id' => $client->id,
            'reason' => $reason,
        ]);
        $result->recordSkipped($message);
    }

    /**
     * One detail read per device that runs the vendor's agent. Agentless
     * devices have nothing collected, retired ones are not written, and a
     * half-retired one is left alone, so none of them is read.
     *
     * A 404 is the device leaving between the list and this read: it goes in
     * $left, and syncClient() leaves that device alone. Any other failure is
     * an error and is logged with the PSA client id and the HTTP status only;
     * the device's list facts are still written. A malformed category is
     * drift: logged, and recorded as an error.
     *
     * @param  list<LitsrmmDevice>  $rows
     * @return array{0: array<string, LitsrmmHardware>, 1: array<string, true>} device id => hardware; device ids that answered 404
     */
    private function readHardware(Client $client, array $rows, SyncResult $result): array
    {
        $hardware = [];
        $left = [];

        foreach ($rows as $device) {
            if ($device->agentVersion === null || $device->isRetired() || $device->hasSplitRetiredState()) {
                continue;
            }

            try {
                $hardware[$device->id] = LitsrmmHardware::fromInventory($this->litsrmm->getDevice($device->id)['inventory']);

                // Drift: the vendor sent a category in a shape we do not read.
                if ($hardware[$device->id]->malformed !== []) {
                    Log::warning('[LitsrmmAssetSync] device detail has malformed inventory categories (drift)', [
                        'client_id' => $client->id,
                        'device' => $device->id,
                        'categories' => $hardware[$device->id]->malformed,
                    ]);
                    $result->recordError("{$device->hostname}: inventory categories in a shape this sync does not read, not refreshed: "
                        .implode(', ', $hardware[$device->id]->malformed));
                }
            } catch (LitsrmmClientException $e) {
                if ($e->getCode() === 404) {
                    $left[$device->id] = true;

                    continue;
                }

                Log::warning('[LitsrmmAssetSync] device detail read failed', [
                    'client_id' => $client->id,
                    'status' => $e->getCode(),
                ]);
                // The list facts are still written; only the hardware is stale.
                $result->recordError("{$device->hostname}: hardware not refreshed ({$e->getMessage()})");
            }
        }

        return [$hardware, $left];
    }

    /**
     * @param  list<LitsrmmDevice>  $rows
     * @param  array<string, LitsrmmHardware>  $hardware
     * @param  array<string, true>  $left  device ids whose detail read answered 404
     */
    private function syncClient(Client $client, array $rows, array $hardware, array $left, SyncResult $result): void
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

        // Two devices answering to one name, or one real serial, in one
        // client cannot both be the asset that carries it. $liveIds: the
        // devices this read lists that are not retired.
        $nameCounts = [];
        $serialCounts = [];
        $liveIds = [];
        foreach ($rows as $device) {
            if (! $device->isRetired()) {
                $key = self::normalizeHostname($device->hostname);
                $nameCounts[$key] = ($nameCounts[$key] ?? 0) + 1;
                $serial = LitsrmmSerial::identity($device->serial);
                if ($serial !== null) {
                    $serialCounts[$serial] = ($serialCounts[$serial] ?? 0) + 1;
                }
                $liveIds[strtolower($device->id)] = true;
            }
        }

        /** @var array<int, true> $kept asset ids whose link this run confirmed or made */
        $kept = [];

        foreach ($rows as $device) {
            $asset = $linked[strtolower($device->id)] ?? null;

            if ($device->hasSplitRetiredState()) {
                $this->recordSkip($result, $client, 'split_retired_state', "{$device->hostname}: only one of its two states reads retired; left as it was");
                if ($asset !== null) {
                    $kept[$asset->id] = true;
                }

                continue;
            }

            if ($device->isRetired()) {
                // Its asset, unless a live device took it back, is dealt with below.
                continue;
            }

            if (isset($left[$device->id])) {
                // Its detail read answered 404: it left between the list and
                // that read. Nothing is written for it and its link is kept.
                $this->recordSkip($result, $client, 'left_before_detail_read', "{$device->hostname}: no longer known to LITSRMM when its details were read; left as it was");
                if ($asset !== null) {
                    $kept[$asset->id] = true;
                }

                continue;
            }

            if ($asset === null) {
                $serial = LitsrmmSerial::identity($device->serial);
                if ($serial !== null && ($serialCounts[$serial] ?? 0) > 1) {
                    $this->recordSkip($result, $client, 'shared_serial', "{$device->hostname}: shares its serial with another device; not linked, not created");

                    continue;
                }

                $match = $this->matchUnlinked($device, $assets, $kept, $nameCounts, $liveIds);

                if ($match === false) {
                    $this->recordSkip($result, $client, 'ambiguous_match', "{$device->hostname}: more than one asset or device could be this machine; not linked, not created");

                    continue;
                }

                $asset = $match;
            }

            if ($asset !== null && isset($kept[$asset->id])) {
                // Defensive: never let a second device overwrite a link made this run.
                $this->recordSkip($result, $client, 'asset_already_claimed', "{$device->hostname}: its asset was already claimed by another device this run");

                continue;
            }

            $asset = $this->write($client, $asset, $device, $hardware[$device->id] ?? null, $result);
            $kept[$asset->id] = true;
        }

        // A retired device's asset: inactive, reversibly. An asset a live
        // device took back above no longer carries the retired id (write()
        // rewrote its link), so it is not in this set. Only an asset that is
        // active now is stamped, so one a person made inactive keeps a null
        // litsrmm_retired_at. It keeps its link, so the device coming back
        // under the same id finds it.
        $retiredIds = [];
        foreach ($rows as $device) {
            if ($device->isRetired()) {
                $retiredIds[] = $device->id;
            }
        }
        if ($retiredIds !== []) {
            // #5368: an asset another RMM still links is that RMM's to retire.
            // It stays active, keeps our link, and is reported.
            $held = Asset::where('client_id', $client->id)
                ->whereIn('litsrmm_device_id', $retiredIds)
                ->where('is_active', true)
                ->where(function ($q) {
                    foreach (self::OTHER_RMM_LINKS as $column) {
                        $q->orWhereNotNull($column);
                    }
                })
                ->get();
            foreach ($held as $asset) {
                $kept[$asset->id] = true;
                $this->recordSkip($result, $client, 'retired_but_other_rmm_link', "{$asset->hostname}: its LITSRMM device is retired but another RMM still links this asset; left active");
            }

            $retire = Asset::where('client_id', $client->id)
                ->whereIn('litsrmm_device_id', $retiredIds)
                ->where('is_active', true);
            foreach (self::OTHER_RMM_LINKS as $column) {
                $retire->whereNull($column);
            }
            $result->details['retired'] = ($result->details['retired'] ?? 0)
                + $retire->update(['is_active' => false, 'litsrmm_retired_at' => now()]);
        }

        // A successful read that did not return a linked device: release OUR
        // link and nothing else. Not on an asset the sync retired: its link is
        // what lets the same device coming back reactivate it.
        $result->deactivated += Asset::where('client_id', $client->id)
            ->whereNotNull('litsrmm_device_id')
            ->whereNull('litsrmm_retired_at')
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
     * @param  array<string, true>  $liveIds
     * @return Asset|null|false the asset; null for none (create one); false for ambiguous
     */
    private function matchUnlinked(LitsrmmDevice $device, Collection $assets, array $kept, array $nameCounts, array $liveIds): Asset|null|false
    {
        // Free: unlinked, or linked to a device this read does not list live
        // (retired, or gone), and not claimed earlier in this run.
        $free = $assets->filter(fn (Asset $a) => ! isset($kept[$a->id])
            && ($a->litsrmm_device_id === null || ! isset($liveIds[strtolower($a->litsrmm_device_id)])));

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

        // Never across two real serials that differ: a replacement PC reusing a
        // hostname is a new machine. An asset the sync retired matches only
        // while it still carries its retired device's link, as it would have in
        // the run that retired it; once that link is gone it does not.
        //
        // An asset a person made inactive (is_active false, no
        // litsrmm_retired_at) never matches by hostname (#5341): a new machine
        // reusing the name of a decommissioned one is a new machine.
        $byName = $free->filter(function (Asset $a) use ($name, $serial) {
            if (($a->litsrmm_retired_at !== null && $a->litsrmm_device_id === null)
                || (! $a->is_active && $a->litsrmm_retired_at === null)
                || self::normalizeHostname($a->hostname) !== $name) {
                return false;
            }
            $assetSerial = LitsrmmSerial::identity($a->serial_number);

            return $serial === null || $assetSerial === null || $assetSerial === $serial;
        })->values();

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

        // The same machine back after the sync retired its asset: undo that.
        if ($asset !== null && $asset->litsrmm_retired_at !== null) {
            $data['is_active'] = true;
            $data['litsrmm_retired_at'] = null;
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
