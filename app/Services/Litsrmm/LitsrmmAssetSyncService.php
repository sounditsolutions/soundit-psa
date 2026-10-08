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
 *    left as it was, and the run records an error. A single detail read
 *    answered 404 among reads that succeed is most likely the device leaving
 *    between the list and the detail read, though a 404 does not prove that
 *    (#5583), so its asset is left exactly as it was (no write, its link
 *    kept) and it is reported as skipped, with the reason
 *    'detail_read_not_found', not as an error. It still counts for seats,
 *    which come from the list. A hardware category in a shape we do not
 *    read is drift: it is logged and recorded as an error.
 *  - MANY 404s ARE A DEGRADED READ, NOT DEVICES LEAVING (#5578). A client's
 *    detail read is degraded when at least one read answers 404 and either
 *    every read answers 404 (a one-device client whose only read answers
 *    404 included, #5685), or more than one read failed (404 or any other
 *    failure, #5691) AND more than half of them did. A degraded client gets
 *    an error on the result (so the command exits FAILURE), a log line
 *    carrying the PSA client id and the counts only, and nothing of it is
 *    written (no asset, no link released, no retirement, no seat). One 404
 *    among reads that otherwise succeed stays a skip. So does exactly half
 *    of the reads failing, two 404s of four reads included (#5795): "more
 *    than half" is the ruled bound, and each such 404 is still reported as
 *    a skip with its own log line, its link kept and nothing of it
 *    written, so it does not pass silently; the run then exits SUCCESS
 *    unless something else failed. Failures with no 404
 *    among them are not a degraded read: they cost only those devices'
 *    hardware, as above.
 *  - A SHORT LIST IS NOT THE TRUTH (#5343, #5575, #5576). Only links on
 *    assets the sync has not retired count as "linked" here: a retired asset
 *    keeps its link whatever the read says. A linked asset is "unlisted" when
 *    the read does not list its device id AND no live device of the read
 *    carries the asset's real serial (that would be the same machine
 *    re-enrolled under a new id, #5577). That exemption is one-to-one
 *    (#5683): the serial must be carried by exactly one listed device that
 *    is not retired, and that one runs the vendor's agent, is not
 *    half-retired and is not already linked to an asset; and by exactly one
 *    asset of the client that is not linked to a live listed device. It is
 *    narrower than syncClient(), which relinks an agentless device by its
 *    serial too (#5796): such a link still counts as unlisted here, so the
 *    bound may refuse a client whose accepted run then releases less than
 *    the refusal counted. That errs towards refusing. A
 *    client is refused, and nothing
 *    of it is read in detail or written (no asset, no link released, no
 *    retirement, no seat), when any of these holds, checked in this order:
 *      (a) the read lists none of its devices while it has a linked asset or
 *          a LITSRMM seat with a quantity above 0;
 *      (b) more than RELEASE_FLOOR of its linked assets, AND more than half
 *          of them, are unlisted;
 *      (c) it has at least one linked asset and every one of them is
 *          unlisted (this is what protects a client with RELEASE_FLOOR or
 *          fewer links, which (b) never refuses);
 *      (d) its LITSRMM seats (the total of its two seat quantities) are
 *          above 0, and either the seats this read would write are 0
 *          (the zero arm, whatever the size of the drop), or they fall
 *          short of the seats held by more than RELEASE_FLOOR AND by more
 *          than half of them. A device the read lists as retired or
 *          half-retired makes up none of that shortfall: a device retired
 *          on an earlier run backs none of the seats held yet stays on the
 *          list, and this sync cannot tell it from one retired since the
 *          last run, so counting it would let a short read cut seats. So
 *          ANY retirement that leaves a client 0 seats is refused, a
 *          single-device client's decommission (1 seat to 0) included, as
 *          is a retirement wave past the bound (#5692).
 *    Each run checks its own read (#5787): a short read that persists is
 *    refused on every run, scheduled ones included (each exits FAILURE and
 *    logs the refusal), until an admin passes it with --accept-short-read
 *    (below); a transient one clears when a later read is whole; and a
 *    read whose counts changed is refused with a new code.
 *    The refusal is an error on the result (so the command exits FAILURE),
 *    counted in details['short_read_refused'], and a log line carrying the
 *    PSA client id, the rule and the counts only, the count of listed
 *    retired or half-retired devices included (#5687), and the refusal code
 *    itself under 'code'. Its message ends with that code on a line of its
 *    own, nothing after it, so the line pasted as it is passes
 *    isRefusalCode() (#5786); describe() keeps nothing after a code. The
 *    error message is what the command prints and what a Sync button
 *    flashes (as its last text: the page shows the line break as a space);
 *    a scheduled run's output goes nowhere, so its code is in the log. A refusal
 *    that would also leave the client 0 seats says so whatever its rule
 *    (#5797). A normal shrink
 *    inside that bound still releases and still writes the seats.
 *  - A REAL SHRINK THE BOUND REFUSES HAS ONE WAY THROUGH (#5577). An admin
 *    who has checked that a refused client's change is real (a re-enrollment
 *    wave, a decommission) re-runs `litsrmm:sync-devices
 *    --accept-short-read=<value>`, where <value> is the one the refusal
 *    message printed: the PSA client id, the rule and the counts the admin
 *    checked (refusalCode()). That run passes a refusal only for that client
 *    and only when this run's read refuses it with exactly that rule and
 *    those counts (#5680): a read with another rule or other counts (an
 *    empty page after a truncated one, say) is refused as usual and its
 *    message says the accept named another refusal. The code binds counts,
 *    not which devices were read (#5785): another read with the same rule
 *    and the same counts (for rule (a), any empty page of that client)
 *    passes, so an accept is for a run the admin starts and watches. A passed client is synced as if the bound had not fired, and
 *    only once its transaction has committed is it counted in
 *    details['short_read_accepted'] and logged with the PSA client id
 *    (#5681, #5783). A code that passed nothing (another client than
 *    --client, an id not mapped, no such refusal, a client then refused as
 *    a degraded detail read, a device list read that failed so no client
 *    was examined, or a second code for a client another code passed) is
 *    listed by its PSA client id in details['short_read_accept_unused']
 *    (#5688, #5788, #5789). Nothing stores it: the
 *    next run applies the bound again. The schedule and the Sync buttons
 *    never pass it. It does not override a degraded detail read.
 *  - A DEVICE THAT LEAVES loses only our link (litsrmm_device_id,
 *    litsrmm_synced_at), except on an asset the sync retired (below). The
 *    asset, its hardware facts and every other vendor's link stay:
 *    offboarding is a deliberate operator action (psa-u97k).
 *  - A RETIRED DEVICE also marks its asset inactive, REVERSIBLY: is_active
 *    false and litsrmm_retired_at set, so it no longer looks live, and the
 *    link kept. Those assets are counted in details['retired'], never in
 *    `deactivated`. An active asset that carries a LIVE link of another RMM
 *    is not marked inactive: it keeps is_active, gets no litsrmm_retired_at,
 *    keeps our link, and every run that reads its device retired reports it
 *    as skipped (#5368). A link is live when its RMM is available for the
 *    asset's client (Client::availableRmms(): the client is mapped to it and
 *    the integration is switched on and configured, #5581) and the asset
 *    carries that RMM's link: a non-empty level_id, a ninja_id, or a
 *    Tactical link in either direction (assets.tactical_asset_id, or a
 *    tactical_assets row whose asset_id names the asset, #5591). A column
 *    left behind by an RMM the client no longer uses does not hold the
 *    asset. A Tactical agent row holds whatever its status, offline
 *    included: TacticalDeviceSyncService only marks an agent it stops
 *    seeing offline and keeps the row, because absent from its payload is
 *    unknown, not gone. So a retired asset whose Tactical agent was
 *    uninstalled stays active and is reported as skipped on every run
 *    until an operator resolves it by hand (unlink or remove the Tactical
 *    agent row, or mark the asset inactive). This is accepted, ruled on
 *    #5689. The same device coming back, or the machine re-enrolled under
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
     * #5343 / #5575: a read may release at most this many of a client's links
     * to device ids it does not list at all, or at most half of them,
     * whichever is larger; and may lower a client's seats by at most this
     * many, or at most half, whichever is larger, but never to 0: a read that
     * would write 0 seats for a client holding any is refused whatever the
     * drop (rule (d)'s zero arm, #5686). Past these, the read is treated as
     * short and the client is refused (see the class docblock).
     */
    public const RELEASE_FLOOR = 3;

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

        if (($result->details['short_read_accepted'] ?? 0) > 0) {
            $message .= " {$result->details['short_read_accepted']} client(s) synced past the short-read bound because the operator accepted it for this run.";
        }

        if (($result->details['short_read_accept_unused'] ?? []) !== []) {
            $message .= ' For each of PSA client ID(s) '
                .implode(', ', $result->details['short_read_accept_unused']).', an --accept-short-read code given for it passed nothing.';
        }

        if ($result->skippedMessages !== []) {
            $message .= ' Skipped: '.implode('; ', $result->skippedMessages).'.';
        }

        if ($result->errorMessages !== []) {
            $message .= ' Errors: '.self::joinErrors($result->errorMessages);
        }

        return $message;
    }

    /**
     * The errors joined by '; ' and closed with '.', with every refusal
     * last (#5786): a refusal message ends with its code on a line of its
     * own, and nothing is added after a code but a line break before the
     * next refusal. So the last refusal's code ends the text a Sync button
     * flashes (the page shows the line break as a space), and every code
     * line can be pasted as it is into --accept-short-read.
     *
     * @param  list<string>  $messages
     */
    private static function joinErrors(array $messages): string
    {
        $refusals = array_values(array_filter($messages, fn (string $m) => self::refusalCodeLine($m) !== null));
        $others = array_values(array_filter($messages, fn (string $m) => self::refusalCodeLine($m) === null));

        $joined = $others === [] ? '' : implode('; ', $others).($refusals === [] ? '.' : '; ');

        return $joined.implode("\n", $refusals);
    }

    /** The refusal code $message ends with on a line of its own, or null. */
    public static function refusalCodeLine(string $message): ?string
    {
        $lines = explode("\n", $message);
        $last = end($lines);

        return count($lines) > 1 && self::isRefusalCode($last) ? $last : null;
    }

    /**
     * Sync every mapped, operational client, or only $only (the client page's
     * Sync button). A single-client run writes nothing outside that client:
     * the estate-wide sweeps (links and seats of clients no longer mapped)
     * belong to a full run only.
     *
     * $acceptShortRead: refusal codes (refusalCode()) an admin passed with
     * --accept-short-read for this run only. Each passes at most the one
     * refusal it names; a client with any code that passed nothing (a second
     * code for a client another code passed included, #5789) is listed by
     * PSA client id in details['short_read_accept_unused'].
     *
     * @param  list<string>  $acceptShortRead
     */
    public function sync(?Client $only = null, array $acceptShortRead = []): SyncResult
    {
        $result = new SyncResult;
        $accepted = [];
        foreach ($acceptShortRead as $code) {
            $accepted[(int) strtok((string) $code, ':')][] = (string) $code;
        }
        $used = [];

        try {
            $this->syncAll($only, $accepted, $used, $result);
        } finally {
            $unused = array_keys(array_filter(
                $accepted,
                fn (array $codes) => array_diff($codes, array_keys($used)) !== [],
            ));
            if ($unused !== []) {
                sort($unused);
                $result->details['short_read_accept_unused'] = $unused;
                Log::warning('[LitsrmmAssetSync] for each of these PSA client ids, an --accept-short-read code given for it passed nothing', ['client_ids' => $unused]);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, list<string>>  $accepted  PSA client id => accepted refusal codes
     * @param  array<string, true>  $used  refusal codes that passed a refusal whose client was then synced and committed
     */
    private function syncAll(?Client $only, array $accepted, array &$used, SyncResult $result): void
    {

        $clients = Client::query()
            ->whereNotNull('litsrmm_client_id')
            ->operational()
            ->when($only !== null, fn ($q) => $q->whereKey($only->id))
            ->orderBy('id')
            ->get();

        if ($only !== null && $clients->isEmpty()) {
            $result->recordError("client {$only->id} is not mapped to LITSRMM, or is not an active client; nothing was read.");

            return;
        }

        if ($clients->isEmpty()) {
            $this->sweepUnmapped([], $result);

            return;
        }

        try {
            $devices = $this->litsrmm->getDevices();
        } catch (LitsrmmClientException $e) {
            // Nothing is touched: not the links, not the seats, not the sweeps.
            // Status-only (C-56): the kind of failure and the HTTP status.
            $failure = self::failure($e);
            Log::warning('[LitsrmmAssetSync] device list read failed', $failure);
            $result->recordError('Failed to read LITSRMM devices ('.self::describeFailure($failure).'); nothing was changed');

            return;
        }

        $byVendorClient = [];
        foreach ($devices as $device) {
            $byVendorClient[strtolower($device->clientId)][] = $device;
        }

        $result->details['retired'] = 0;

        foreach ($clients as $client) {
            $rows = $byVendorClient[strtolower($client->litsrmm_client_id)] ?? [];

            [$refused, $acceptedRefusal] = $this->refuseShortRead($client, $rows, $result, $accepted[$client->id] ?? []);

            if ($refused) {
                continue;
            }

            [$hardware, $left, $degraded, $failed] = $this->readHardware($client, $rows, $result);

            if ($degraded) {
                // An accepted refusal is not counted or logged as accepted:
                // nothing of this client was synced (#5681).
                continue;
            }

            $notes = DB::transaction(function () use ($client, $rows, $hardware, $left, $failed, $result) {
                $notes = $this->syncClient($client, $rows, $hardware, $left, $failed, $result);
                $this->syncSeats($client, $rows);

                return $notes;
            });

            // Only once the client's transaction has committed: a throw inside
            // it rolls the client back and reaches none of this (#5681, #5682).
            foreach ($notes as $note) {
                $result->recordError($note);
            }

            if ($acceptedRefusal !== null) {
                Log::warning('[LitsrmmAssetSync] client short read accepted by the operator for this run', $acceptedRefusal);
                $result->details['short_read_accepted'] = ($result->details['short_read_accepted'] ?? 0) + 1;
                $used[self::refusalCode($acceptedRefusal)] = true;
            }
        }

        if ($only === null) {
            $this->sweepUnmapped($clients->pluck('id')->all(), $result);
        }
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
        foreach (self::seatCounts($rows) as $sku => $quantity) {
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
     * The seats a read holds, by SKU (see syncSeats()). refuseShortRead()
     * compares their total with the seats already held (rule (d)).
     *
     * @param  list<LitsrmmDevice>  $rows
     * @return array{rmm_server: int, rmm_workstation: int}
     */
    private static function seatCounts(array $rows): array
    {
        $counts = ['rmm_server' => 0, 'rmm_workstation' => 0];

        foreach ($rows as $device) {
            if ($device->agentVersion === null || $device->isRetired() || $device->hasSplitRetiredState()) {
                continue;
            }

            $isServer = str_contains(mb_strtolower($device->osName ?? ''), 'server');
            $counts[$isServer ? 'rmm_server' : 'rmm_workstation']++;
        }

        return $counts;
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
     * The refusal code of a short-read refusal: the PSA client id, the rule
     * and every count the refusal message shows, joined by ':'. It is what
     * --accept-short-read takes (#5680), so an accept passes only a refusal
     * with exactly the rule and counts the admin checked. Ids and counts
     * only (C-56).
     *
     * @param  array{client_id: int, rule: string, listed: int, linked: int, unlisted: int, seats_held: int, seats_read: int, retired_listed: int}  $context
     */
    public static function refusalCode(array $context): string
    {
        return implode(':', [
            $context['client_id'], $context['rule'], $context['listed'], $context['linked'],
            $context['unlisted'], $context['seats_held'], $context['seats_read'], $context['retired_listed'],
        ]);
    }

    /**
     * Whether $code has the shape refusalCode() writes: plain integers, so a
     * leading zero, which no refusal prints and none could match, is
     * rejected before anything is read (#5793).
     */
    public static function isRefusalCode(string $code): bool
    {
        return preg_match('/^[1-9]\d*:[abcd](:(0|[1-9]\d*)){6}$/D', $code) === 1;
    }

    /**
     * Whether this successful read is too short to act on for $client: rules
     * (a)-(d) in the class docblock, checked in that order. A refused client
     * is recorded as an error, counted in details['short_read_refused'] and
     * logged with its PSA id, the rule and the counts, and nothing of it is
     * read in detail or written. Only links that the release step could clear
     * count: an asset the sync retired keeps its link whatever the read says.
     *
     * $acceptCodes are the refusal codes the admin passed for this client
     * with --accept-short-read (#5577, #5680). A refusal whose code is among
     * them is not refused: its context comes back as the second element, and
     * sync() counts and logs it as accepted only once the client is synced
     * (#5681). Any other refusal is refused as usual, and its message says
     * the accept named a different one.
     *
     * @param  list<LitsrmmDevice>  $rows
     * @param  list<string>  $acceptCodes
     * @return array{0: bool, 1: array<string, int|string>|null} refused; the accepted refusal's log context
     */
    private function refuseShortRead(Client $client, array $rows, SyncResult $result, array $acceptCodes = []): array
    {
        $assets = Asset::where('client_id', $client->id)
            ->get(['id', 'litsrmm_device_id', 'litsrmm_retired_at', 'serial_number']);
        $links = $assets->filter(fn (Asset $a) => $a->litsrmm_device_id !== null && $a->litsrmm_retired_at === null);

        $linkedIds = [];
        foreach ($assets as $asset) {
            if ($asset->litsrmm_device_id !== null) {
                $linkedIds[strtolower($asset->litsrmm_device_id)] = true;
            }
        }

        // #5683: a serial exempts an unlisted link only one-to-one: carried
        // by exactly one listed device that is not retired, and that one
        // runs the agent, is not half-retired and is not linked to an asset
        // already; and by exactly one asset not linked to a live listed
        // device. syncClient() then relinks that asset (or keeps its link,
        // when that device's detail read answers 404). It would also relink
        // by an agentless device's serial, which exempts nothing here
        // (#5796): that errs towards refusing.
        $listed = [];
        $liveIds = [];
        $deviceSerials = [];
        $eligible = [];
        $retiredListed = 0;
        foreach ($rows as $device) {
            $id = strtolower($device->id);
            $listed[$id] = true;
            if ($device->isRetired() || $device->hasSplitRetiredState()) {
                $retiredListed++;
            }
            if ($device->isRetired()) {
                continue;
            }
            $liveIds[$id] = true;
            $serial = LitsrmmSerial::identity($device->serial);
            if ($serial === null) {
                continue;
            }
            $deviceSerials[$serial] = ($deviceSerials[$serial] ?? 0) + 1;
            if ($device->agentVersion !== null && ! $device->hasSplitRetiredState() && ! isset($linkedIds[$id])) {
                $eligible[$serial] = true;
            }
        }

        $assetSerials = [];
        foreach ($assets as $asset) {
            $serial = LitsrmmSerial::identity($asset->serial_number);
            if ($serial !== null && ($asset->litsrmm_device_id === null || ! isset($liveIds[strtolower($asset->litsrmm_device_id)]))) {
                $assetSerials[$serial] = ($assetSerials[$serial] ?? 0) + 1;
            }
        }

        $linked = $links->count();
        $unlisted = $links->filter(function (Asset $a) use ($listed, $eligible, $deviceSerials, $assetSerials) {
            if (isset($listed[strtolower($a->litsrmm_device_id)])) {
                return false;
            }
            $serial = LitsrmmSerial::identity($a->serial_number);

            return $serial === null || ! isset($eligible[$serial])
                || $deviceSerials[$serial] !== 1 || ($assetSerials[$serial] ?? 0) !== 1;
        })->count();

        $seatsHeld = (int) License::where('client_id', $client->id)
            ->whereHas('licenseType', fn ($q) => $q->where('vendor', self::LICENSE_VENDOR))
            ->sum('quantity');
        $seatsRead = array_sum(self::seatCounts($rows));
        // No allowance for devices the read lists as retired (rule (d)): one
        // retired on an earlier run backs none of $seatsHeld. $retiredListed
        // is reported only, so a rule (d) decision can be read (#5687).
        $seatDrop = $seatsHeld - $seatsRead;

        $rule = match (true) {
            $rows === [] && ($linked > 0 || $seatsHeld > 0) => 'a',
            $unlisted > self::RELEASE_FLOOR && $unlisted * 2 > $linked => 'b',
            $linked > 0 && $unlisted === $linked => 'c',
            $seatsHeld > 0 && ($seatsRead === 0 || ($seatDrop > self::RELEASE_FLOOR && $seatDrop * 2 > $seatsHeld)) => 'd',
            default => null,
        };

        if ($rule === null) {
            return [false, null];
        }

        $context = [
            'client_id' => $client->id,
            'rule' => $rule,
            'listed' => count($rows),
            'linked' => $linked,
            'unlisted' => $unlisted,
            'seats_held' => $seatsHeld,
            'seats_read' => $seatsRead,
            'retired_listed' => $retiredListed,
        ];
        $code = self::refusalCode($context);

        if (in_array($code, $acceptCodes, true)) {
            return [false, $context];
        }

        // The joined code is in the log too: a scheduled run's output goes
        // nowhere, so the log is where its refusal code is found (#5786).
        // The second line's message ends with the code on a line of its own
        // and a line break, so the file line formatter writes its context on
        // the line after and the code line can be pasted as it is.
        Log::warning('[LitsrmmAssetSync] client refused: short read', $context + ['code' => $code]);
        Log::warning("[LitsrmmAssetSync] refusal code for --accept-short-read, on the next line:\n{$code}\n", ['client_id' => $client->id]);
        $result->details['short_read_refused'] = ($result->details['short_read_refused'] ?? 0) + 1;
        $result->recordError(
            "client {$client->id}: refused as a short read (rule {$rule}: the read lists ".count($rows)
            ." device(s); {$unlisted} of {$linked} linked asset(s) unlisted and not exempted as re-enrolled by serial;"
            ." seats {$seatsHeld} held, {$seatsRead} read; {$retiredListed} listed device(s) retired or half-retired, backing no seat)"
            .self::zeroSeatsClause($rule, $seatsHeld, $seatsRead)
            .'; nothing of this client was changed. Each run checks its own read, so a later read refused the same way'
            .' is refused again, with a code of its own when its counts differ.'
            .($acceptCodes !== [] ? ' The --accept-short-read given for this client named a different refusal, so it was not passed.' : '')
            .' If the change is real, re-run litsrmm:sync-devices with --accept-short-read set to the code that follows, pasted exactly'
            ." (a bare client id is rejected):\n{$code}"
        );

        return [true, null];
    }

    /**
     * The zero-arm warning of a refusal whose read would leave the client 0
     * LITSRMM seats while it holds some (#5692, #5797). Rule (d) fires on that
     * whatever the drop; a rule (a), (b) or (c) refusal names only its own
     * rule, so the warning says that accepting it also zeroes the seats.
     */
    private static function zeroSeatsClause(string $rule, int $seatsHeld, int $seatsRead): string
    {
        if ($seatsHeld === 0 || $seatsRead !== 0) {
            return '';
        }

        return $rule === 'd'
            ? ', and a read leaving 0 seats is refused whatever the drop, a single-device decommission included'
            : ', and this read would also leave 0 seats, so accepting it sets the client\'s LITSRMM seats to 0';
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
     * A 404 (most likely the device leaving between the list and this read,
     * though not proven so, #5583) goes in $left, and syncClient() leaves that
     * device alone. It is not taken as devices leaving but as a degraded read (#5578) when at least one read answers 404
     * and either every read does (a one-device client's only read included,
     * #5685), or more than one read failed, a 404 or any other failure, AND
     * more than half of them did (#5691): an error, a log line with the PSA
     * client id and the counts, and $degraded true, so sync() writes nothing
     * of the client. Any other failure is logged with the PSA client id and
     * the kind of failure only (an HTTP status, or none); when the client is
     * not degraded it comes back in the fourth element, and sync() records
     * it as an error once the client's transaction has committed, saying
     * whether syncClient() wrote that device's list facts (#5682). A malformed category is
     * drift: logged with the PSA client id and a count, and recorded as an
     * error.
     *
     * @param  list<LitsrmmDevice>  $rows
     * @return array{0: array<string, LitsrmmHardware>, 1: array<string, true>, 2: bool, 3: array<string, array{kind: string, status?: int}>} device id => hardware; device ids that answered 404; whether the read is degraded; device id => its failure, for reads that failed other than with a 404
     */
    private function readHardware(Client $client, array $rows, SyncResult $result): array
    {
        $hardware = [];
        $left = [];
        $reads = 0;
        /** @var array<string, array{kind: string, status?: int}> $failed device id => failure, for detail reads that failed other than with a 404 */
        $failed = [];

        foreach ($rows as $device) {
            if ($device->agentVersion === null || $device->isRetired() || $device->hasSplitRetiredState()) {
                continue;
            }

            $reads++;

            try {
                $hardware[$device->id] = LitsrmmHardware::fromInventory($this->litsrmm->getDevice($device->id)['inventory']);

                // Drift: the vendor sent a category in a shape we do not read.
                if ($hardware[$device->id]->malformed !== []) {
                    $count = count($hardware[$device->id]->malformed);
                    Log::warning('[LitsrmmAssetSync] device detail has malformed inventory categories (drift)', [
                        'client_id' => $client->id,
                        'malformed_categories' => $count,
                    ]);
                    $result->recordError("client {$client->id}: a device's detail read sent {$count} inventory "
                        .'categor'.($count === 1 ? 'y' : 'ies').' in a shape this sync does not read; those were not refreshed');
                }
            } catch (LitsrmmClientException $e) {
                if ($e->getCode() === 404) {
                    $left[$device->id] = true;

                    continue;
                }

                $failure = self::failure($e);
                Log::warning('[LitsrmmAssetSync] device detail read failed', ['client_id' => $client->id] + $failure);
                $failed[$device->id] = $failure;
            }
        }

        $notFound = count($left);
        $other = count($failed);
        $failedAll = $notFound + $other;
        $degraded = $notFound > 0
            && ($notFound === $reads || ($failedAll > 1 && $failedAll * 2 > $reads));

        if ($degraded) {
            Log::warning('[LitsrmmAssetSync] client refused: degraded detail read', [
                'client_id' => $client->id,
                'reads' => $reads,
                'not_found' => $notFound,
                'failed_other' => $other,
            ]);
            $result->details['degraded_detail_read'] = ($result->details['degraded_detail_read'] ?? 0) + 1;
            $result->recordError("client {$client->id}: {$notFound} of {$reads} device detail reads answered 404"
                .($other > 0 ? " and {$other} failed otherwise" : '')
                .'; treated as a degraded read, so nothing of this client was changed');

            return [$hardware, $left, true, $failed];
        }

        return [$hardware, $left, false, $failed];
    }

    /**
     * The error for a device whose detail read failed other than with a 404,
     * once syncClient() knows whether it wrote the device's list facts
     * (#5682): it may still skip the device (a shared serial, an ambiguous
     * match, an asset already claimed this run).
     *
     * @param  array{kind: string, status?: int}  $failure
     */
    private static function detailFailureNote(Client $client, array $failure, bool $written): string
    {
        return "client {$client->id}: a device's hardware was not refreshed (detail read failed: "
            .self::describeFailure($failure).')'
            .($written ? '; its list facts were still written' : '; it was also skipped, so its list facts were not written either');
    }

    /**
     * Status-only facts of a failed vendor call (C-56): never its message.
     * 'kind' is 'http' with the HTTP status the client saw, or
     * 'no_http_status' for a failure that carries none (a transport failure,
     * or a response the client refused for its shape).
     *
     * @return array{kind: string, status?: int}
     */
    private static function failure(LitsrmmClientException $e): array
    {
        $code = $e->getCode();

        return $code >= 100 && $code <= 599
            ? ['kind' => 'http', 'status' => $code]
            : ['kind' => 'no_http_status'];
    }

    /** @param  array{kind: string, status?: int}  $failure */
    private static function describeFailure(array $failure): string
    {
        return $failure['kind'] === 'http' ? "HTTP {$failure['status']}" : 'no HTTP status';
    }

    /**
     * @param  list<LitsrmmDevice>  $rows
     * @param  array<string, LitsrmmHardware>  $hardware
     * @param  array<string, true>  $left  device ids whose detail read answered 404
     * @param  array<string, array{kind: string, status?: int}>  $failed  device id => failure, for detail reads that failed other than with a 404
     * @return list<string> one error per device in $failed, saying whether its list facts were written; sync() records them once the transaction commits
     */
    private function syncClient(Client $client, array $rows, array $hardware, array $left, array $failed, SyncResult $result): array
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
        /** @var array<string, true> $written device ids whose list facts write() wrote */
        $written = [];

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
                // Its detail read answered 404, most likely because it left
                // between the list and that read; a 404 does not prove which.
                // Nothing is written for it and its link is kept.
                $this->recordSkip($result, $client, 'detail_read_not_found', "{$device->hostname}: its detail read answered 404 (not found); left as it was");
                if ($asset !== null) {
                    $kept[$asset->id] = true;
                }

                // #5683: the one asset refuseShortRead() did not count as
                // unlisted because this device carries its serial keeps its
                // link too, so this 404 releases nothing rule (b) did not count.
                $serial = LitsrmmSerial::identity($device->serial);
                if ($asset === null && $serial !== null && ($serialCounts[$serial] ?? 0) === 1) {
                    $spared = $assets->filter(fn (Asset $a) => ($a->litsrmm_device_id === null || ! isset($liveIds[strtolower($a->litsrmm_device_id)]))
                        && LitsrmmSerial::identity($a->serial_number) === $serial);
                    if ($spared->count() === 1 && $spared->first()->litsrmm_device_id !== null) {
                        $kept[$spared->first()->id] = true;
                    }
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
            $written[$device->id] = true;
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
            // It stays active, keeps our link, and is reported. Only a live
            // link counts (#5581, #5591; see heldByAnotherRmm()).
            $held = $this->heldByAnotherRmm($client, $retiredIds);
            foreach ($held as $asset) {
                $kept[$asset->id] = true;
                $this->recordSkip($result, $client, 'retired_but_other_rmm_link', "{$asset->hostname}: its LITSRMM device is retired but another RMM still links this asset; left active");
            }

            $retire = Asset::where('client_id', $client->id)
                ->whereIn('litsrmm_device_id', $retiredIds)
                ->where('is_active', true)
                ->when($held->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $held->modelKeys()));
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

        $notes = [];
        foreach ($failed as $deviceId => $failure) {
            $notes[] = self::detailFailureNote($client, $failure, isset($written[$deviceId]));
        }

        return $notes;
    }

    /**
     * #5368: active assets of $client behind a retired device ($retiredIds)
     * that another RMM still links LIVE. An RMM's link is live only while
     * that RMM is available for the client (Client::availableRmms(): mapped,
     * switched on and configured, #5581); a column an RMM the client no
     * longer uses left behind does not count. Level: a non-empty level_id.
     * Ninja: a ninja_id. Tactical: assets.tactical_asset_id, or a
     * tactical_assets row whose asset_id names the asset, since the two
     * directions populate independently (#5591).
     *
     * @param  list<string>  $retiredIds
     * @return Collection<int, Asset>
     */
    private function heldByAnotherRmm(Client $client, array $retiredIds): Collection
    {
        $live = $client->availableRmms();

        if ($live === []) {
            return new Collection;
        }

        return Asset::where('client_id', $client->id)
            ->whereIn('litsrmm_device_id', $retiredIds)
            ->where('is_active', true)
            ->where(function ($q) use ($live) {
                if (in_array('level', $live, true)) {
                    $q->orWhere(fn ($l) => $l->whereNotNull('level_id')->where('level_id', '!=', ''));
                }
                if (in_array('ninja', $live, true)) {
                    $q->orWhereNotNull('ninja_id');
                }
                if (in_array('tactical', $live, true)) {
                    $q->orWhereNotNull('tactical_asset_id')
                        ->orWhereExists(fn ($t) => $t->select(DB::raw(1))
                            ->from('tactical_assets')
                            ->whereColumn('tactical_assets.asset_id', 'assets.id'));
                }
            })
            ->get();
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
