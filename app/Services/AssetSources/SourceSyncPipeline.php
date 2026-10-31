<?php

namespace App\Services\AssetSources;

use App\Models\Asset;
use App\Models\AssetSourceSync;
use App\Models\User;
use App\Services\AutoElevate\AutoElevateAssetSyncService;
use App\Services\AutoElevate\AutoElevateClient;
use App\Services\AutoElevate\AutoElevateReadException;
use App\Services\AutoElevate\AutoElevateReadService;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDClientException;
use App\Services\ControlD\ControlDDeviceSyncService;
use App\Services\Level\LevelSyncService;
use App\Services\Litsrmm\LitsrmmAssetSyncService;
use App\Services\Ninja\NinjaSyncService;
use App\Services\Tactical\TacticalDeviceSyncService;
use App\Support\ControlDConfig;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The one "Sync now" pipeline every Sources panel shares (design.md Item 3).
 * Each step refuses BEFORE any network call, in this order:
 *
 *   1. auth                (the route's `auth` group; the one staff role)
 *   2. switch              not_configured / switched_off
 *   3. link                not_linked / client_not_mapped
 *   4. per-user limit      6 per user per minute across sources -> user_throttled
 *   5. per asset+source    30 s, app-wide -> recently_synced (+ the first attempt's result)
 *   6. vendor cooldown     read of source-sync:vendor:<source>:cooldown-until -> vendor_cooling_down
 *   7-9. the read + write  only for a source in WIRED. Tactical (S1) reads the EXISTING
 *                          single-agent read, TacticalDeviceSyncService::syncDeviceDetail
 *                          (getAgent). It writes this asset's tactical_assets row and asset
 *                          fields (incl. assets.last_boot_at), and when the read finds the
 *                          agent back online after being offline it may dispatch the
 *                          queued-offline sweep
 *                          (SweepQueuedActionsForAgent::dispatchIfQueued), which starts
 *                          TechnicianRuns queued while the agent was offline.
 *                          A degraded read leaves the snapshot (synced_at included) as it
 *                          was and is answered failed, with the reason taken from the
 *                          vendor's HTTP status (failureReason) or transport when no
 *                          response came back.
 *                          AutoElevate (S2) first refuses, with no request, while the hourly
 *                          autoelevate:sync-assets run holds its withoutOverlapping mutex
 *                          (run_in_progress, M1.10), then past an app-wide ceiling of
 *                          AUTOELEVATE_PER_MINUTE interactive reads (interactive_ceiling,
 *                          M1.8). It then makes ONE GET /api/v1/computers/{id}, proved
 *                          against the asset client's autoelevate_company_id
 *                          (AutoElevateReadService::computer), and writes only this asset's
 *                          elevation mode, last check-in and autoelevate_synced_at. It never
 *                          writes the link, never calls recordClientOutcome, writes nothing
 *                          at AutoElevate, and leaves the snapshot untouched on any failure.
 *                          A 429 starts the shared vendor cooldown, which the hourly run
 *                          also honours.
 *                          Control D (S3) refuses a linked asset whose client has no
 *                          controld_org_id (client_not_mapped) before any request. It then
 *                          makes the one per-org read the code has, ControlDClient::getDevices
 *                          (GET devices for the client's sub-organization), picks the row whose
 *                          PK is this asset's controld_device_id, and writes it to THIS asset
 *                          only through ControlDDeviceSyncService::updateAssetFromDevice. It
 *                          never runs the device sync's stale-clear branch, so no other asset
 *                          of the client is touched, and it writes nothing at Control D.
 *                          A device absent from the list is not_found_at_vendor (link kept,
 *                          nothing written); any other status from the list GET but 429,
 *                          404 included, is http_NNN, and a row value the asset's Control D
 *                          columns cannot store is drift (nothing written). A 429 starts the
 *                          Control D vendor cooldown.
 *                          LITSRMM (S5) refuses a linked asset whose client has no
 *                          litsrmm_client_id (client_not_mapped) before any request, and
 *                          refuses with run_in_progress while the per-client run's lock
 *                          (litsrmm-asset-sync) is held. Otherwise it takes that lock and
 *                          makes ONE GET /v1/devices/{id}
 *                          (LitsrmmAssetSyncService::syncDeviceDetail). A device of another
 *                          LITSRMM client is other_client and a device the vendor reports
 *                          retired is retired_at_vendor, both with nothing written. ok
 *                          writes only this asset's hostname, online flag, OS, last user,
 *                          last seen, hardware columns and litsrmm_synced_at. It never
 *                          creates, retires, un-retires or unlinks an asset, never writes
 *                          its serial, and writes nothing at LITSRMM. A 429 starts the
 *                          LITSRMM vendor cooldown.
 *                          Ninja and Level (S6) make the GET their existing device-detail
 *                          refresh makes, ONE attempt with no retry and no sleep on 429
 *                          (NinjaSyncService::syncNow: GET /v2/device/{id}, preceded by the
 *                          client's OAuth token POST when no token is cached;
 *                          LevelSyncService::syncNow: GET /v2/devices/{id}). ok writes only
 *                          this asset's columns the existing device-detail refresh derives
 *                          from that body, by its rules, and the source's synced_at (each
 *                          service's SYNC_NOW_WRITES; Level's include hostname and name).
 *                          Ninja's reads no processors, volumes or custom fields, so it
 *                          never writes cpu, disk_summary, the serial or warranty dates.
 *                          Neither creates or unlinks an asset, changes its link or client,
 *                          or writes anything at the vendor. A Ninja device of another
 *                          organization is other_client with nothing written. Neither
 *                          scheduled run's withoutOverlapping mutex is consulted (the
 *                          Overview refresh this replaces never consulted it either), so a
 *                          Sync now can overlap a run; each save writes only the columns it
 *                          changed. A 429 starts that source's vendor cooldown.
 *                          Every other source that
 *                          passes 1-6 is refused with no_single_asset_read and makes no
 *                          vendor call
 *   10. audit              one append-only asset_source_syncs row per attempt, refusals included
 *
 * The route also carries throttle:30,1 (keyed per user, prefix source-sync), which answers
 * 429 before this class runs, so refusals cannot grow the audit table without limit.
 */
class SourceSyncPipeline
{
    /** Sources whose single-asset read is wired; each joins with its own slice. */
    public const WIRED = ['tactical', 'autoelevate', 'controld', 'litsrmm', 'ninja', 'level'];

    /** Retry-After bounds for a vendor 429 this pipeline reads itself (Q-2 B). */
    public const RETRY_AFTER_CAP_S = 300;

    public const RETRY_AFTER_FALLBACK_S = 60;

    /** The Control D columns a Sync now may write (updateAssetFromDevice), on this asset only. */
    public const CONTROLD_WRITES = [
        'controld_device_id', 'controld_profile_name', 'controld_status', 'controld_agent_status',
        'controld_agent_version', 'controld_last_seen_at', 'controld_synced_at',
    ];

    /**
     * AutoElevate's app-wide interactive ceiling (Q-2 B): the vendor allows 20 reads a minute
     * per route and the hourly run uses the same budget, so Sync now may spend at most this many.
     */
    public const AUTOELEVATE_PER_MINUTE = 8;

    public const AUTOELEVATE_CEILING_KEY = 'source-sync:vendor:autoelevate:interactive';

    /** The AutoElevate columns a Sync now writes, on this asset only. Never the link. */
    public const AUTOELEVATE_WRITES = ['autoelevate_elevation_mode', 'autoelevate_last_checked_in_at', 'autoelevate_synced_at'];

    public function __construct(
        private readonly TacticalDeviceSyncService $tacticalSync,
        private readonly AutoElevateReadService $autoElevateReads,
        private readonly LitsrmmAssetSyncService $litsrmmSync,
        private readonly NinjaSyncService $ninjaSync,
        private readonly LevelSyncService $levelSync,
    ) {}

    public const USER_LIMIT_PER_MINUTE = 6;

    public const ASSET_SOURCE_COOLDOWN_S = 30;

    /**
     * Closed reason vocabulary (design.md "Reply shape and reason vocabulary"), plus no_single_asset_read
     * and interactive_ceiling (this PSA's own AutoElevate ceiling; no vendor answer is implied).
     */
    public const REASONS = [
        'ok', 'switched_off', 'not_configured', 'not_linked', 'client_not_mapped',
        'user_throttled', 'recently_synced', 'vendor_cooling_down', 'vendor_429',
        'not_found_at_vendor', 'retired_at_vendor', 'other_client', 'run_in_progress', 'transport', 'drift',
        'no_single_asset_read', 'interactive_ceiling',
    ];

    /** The fixed sentence the UI shows for each reason (never "ok" for a refusal). */
    public const SENTENCES = [
        'ok' => 'Read from the vendor.',
        'switched_off' => 'Switched off in Settings; not being updated.',
        'not_configured' => 'Not configured in Settings.',
        'not_linked' => 'This asset is not linked to this source.',
        'client_not_mapped' => "This asset's client is not mapped to this source.",
        'user_throttled' => 'You have made too many Sync now requests in the last minute.',
        'recently_synced' => 'Sync now was already tried for this source on this asset moments ago.',
        'vendor_cooling_down' => 'This vendor asked us to slow down; no request was sent.',
        'interactive_ceiling' => "Sync now has reached this PSA's per-minute limit for this vendor; no request was sent.",
        'vendor_429' => 'The vendor is rate-limiting requests.',
        'not_found_at_vendor' => 'The vendor did not return this device; the link is kept.',
        'retired_at_vendor' => 'The vendor lists this device as retired; nothing was written and the link is kept.',
        'other_client' => "The vendor's device belongs to a different client; nothing was written.",
        'run_in_progress' => 'A sync for this source is running; try again when it finishes.',
        'transport' => 'Could not reach the vendor.',
        'drift' => 'The vendor answered in a shape this PSA does not recognise; nothing was written.',
        'no_single_asset_read' => "Sync now can't read this source for a single asset. Its data arrives with the source's own updates.",
    ];

    public static function sentence(string $reason): string
    {
        if (preg_match('/^http_(\d{3})$/', $reason, $m)) {
            return "The vendor answered HTTP {$m[1]}.";
        }

        return self::SENTENCES[$reason] ?? 'Unrecognised: '.$reason;
    }

    /**
     * The reason for a failed vendor read that carried an HTTP status: the vendor
     * was reached and refused, so it is never transport. 404 and 429 have their own
     * reasons; any other status is http_NNN, which sentence() renders.
     */
    public static function failureReason(int $httpStatus): string
    {
        return match ($httpStatus) {
            404 => 'not_found_at_vendor',
            429 => 'vendor_429',
            default => 'http_'.$httpStatus,
        };
    }

    public static function userKey(int $userId): string
    {
        return 'source-sync:user:'.$userId;
    }

    public static function assetKey(int $assetId, string $source): string
    {
        return 'source-sync:asset:'.$assetId.':'.$source;
    }

    public static function vendorCooldownKey(string $source): string
    {
        return 'source-sync:vendor:'.$source.':cooldown-until';
    }

    /**
     * @return array{outcome: string, reason: string, retry_after_s?: int, synced_at?: ?string, changed: list<string>, this_read: array<string, mixed>, previous?: array{outcome: string, reason: string}}
     */
    public function run(Asset $asset, string $source, User $user): array
    {
        $startedAt = now();
        $t0 = hrtime(true);

        $reply = $this->decide($asset, $source, $user);
        // The vendor's status goes on the audit row only; the reply shape stays closed.
        $httpStatus = $reply['http_status'] ?? null;
        unset($reply['http_status']);

        AssetSourceSync::create([
            'asset_id' => $asset->id,
            'source' => $source,
            'user_id' => $user->id,
            'outcome' => $reply['outcome'],
            'reason' => $reply['reason'],
            'http_status' => $httpStatus,
            'retry_after_s' => $reply['retry_after_s'] ?? null,
            'started_at' => $startedAt,
            'duration_ms' => (int) intdiv(hrtime(true) - $t0, 1_000_000),
        ]);

        return $reply;
    }

    private function decide(Asset $asset, string $source, User $user): array
    {
        // 2. Switch.
        if (! AssetSources::isConfigured($source)) {
            return self::refused('not_configured');
        }
        if (! AssetSources::isSwitchedOn($source)) {
            return self::refused('switched_off');
        }

        // 3. Link (Q-6 B: linked assets only; link/unlink stays with the scheduled sync).
        // A Tactical row with no agent id has nothing to read (the old refresh refused it too).
        if (! AssetSources::isLinked($asset, $source)
            || ($source === 'tactical' && blank($asset->tacticalAsset?->agent_id))
            || ($source === 'autoelevate' && ! self::isUuid((string) $asset->autoelevate_computer_id))) {
            if ($source === 'autoelevate' && blank($asset->client?->autoelevate_company_id)) {
                return self::refused('client_not_mapped');
            }

            return self::refused('not_linked');
        }
        // A linked AutoElevate asset whose client has no (usable) company mapping cannot be
        // scope-proven, so it is refused before any request.
        if ($source === 'autoelevate' && ! self::isUuid((string) $asset->client?->autoelevate_company_id)) {
            return self::refused('client_not_mapped');
        }
        // Control D's only read is per sub-organization: without the client's org there is
        // nothing to read, so it is refused before any request.
        if ($source === 'controld' && blank($asset->client?->controld_org_id)) {
            return self::refused('client_not_mapped');
        }
        // LITSRMM: a device can only be scope-proven against the client's own LITSRMM client.
        if ($source === 'litsrmm' && blank($asset->client?->litsrmm_client_id)) {
            return self::refused('client_not_mapped');
        }

        // 4. Per-user limit, across all sources.
        $userKey = self::userKey($user->id);
        if (RateLimiter::tooManyAttempts($userKey, self::USER_LIMIT_PER_MINUTE)) {
            return self::refused('user_throttled', max(1, RateLimiter::availableIn($userKey)));
        }
        RateLimiter::hit($userKey, 60);

        // 5. Per asset+source, app-wide: a second click inside the window gets the first one's result.
        $assetKey = self::assetKey($asset->id, $source);
        $first = Cache::get($assetKey);
        if (is_array($first) && ($first['until'] ?? 0) > now()->getTimestamp()) {
            $reply = self::refused('recently_synced', max(1, $first['until'] - now()->getTimestamp()));
            $reply['previous'] = ['outcome' => $first['outcome'], 'reason' => $first['reason']];

            return $reply;
        }

        // 6. Shared vendor budget: read the cooldown a vendor 429 wrote.
        $until = (int) Cache::get(self::vendorCooldownKey($source), 0);
        $remaining = $until - now()->getTimestamp();
        if ($remaining > 0) {
            return self::refused('vendor_cooling_down', $remaining);
        }

        if ($source === 'autoelevate') {
            // M1.10: the hourly run is reading the same route; never overlap it.
            if (AutoElevateAssetSyncService::runInProgress()) {
                return self::refused('run_in_progress');
            }
            // M1.8: the app-wide interactive ceiling, counted only for a request about to be sent.
            if (RateLimiter::tooManyAttempts(self::AUTOELEVATE_CEILING_KEY, self::AUTOELEVATE_PER_MINUTE)) {
                // This PSA's own limit, not a vendor 429: never reported as vendor_cooling_down.
                return self::refused('interactive_ceiling', max(1, RateLimiter::availableIn(self::AUTOELEVATE_CEILING_KEY)));
            }
            RateLimiter::hit(self::AUTOELEVATE_CEILING_KEY, 60);
        }

        // 7-9. The read, for a wired source only; every other source makes no vendor call.
        $reply = in_array($source, self::WIRED, true)
            ? $this->read($asset, $source)
            : self::refused('no_single_asset_read');
        // A read refused because a run holds the source's lock read nothing, so it opens no
        // 30 s window, as AutoElevate's run_in_progress refusal above opens none.
        if ($reply['reason'] === 'run_in_progress') {
            return $reply;
        }
        Cache::put($assetKey, [
            'until' => now()->getTimestamp() + self::ASSET_SOURCE_COOLDOWN_S,
            'outcome' => $reply['outcome'],
            'reason' => $reply['reason'],
        ], self::ASSET_SOURCE_COOLDOWN_S);

        return $reply;
    }

    /** The wired single-asset reads. */
    private function read(Asset $asset, string $source): array
    {
        return match ($source) {
            'tactical' => $this->readTactical($asset),
            'autoelevate' => $this->readAutoElevate($asset),
            'controld' => $this->readControlD($asset),
            'litsrmm' => $this->readLitsrmm($asset),
            'ninja' => $this->readSharedRmm($asset, 'ninja', $this->ninjaSync->syncNow($asset)),
            'level' => $this->readSharedRmm($asset, 'level', $this->levelSync->syncNow($asset)),
        };
    }

    /**
     * Ninja / Level: the result of NinjaSyncService::syncNow or LevelSyncService::syncNow,
     * each ONE single-attempt GET of the device (no retry, no sleep). ok writes this
     * asset's shared device columns and the source's synced_at only. Every failure leaves
     * the snapshot (synced_at included) as it was; a 429 starts the source's vendor
     * cooldown with the vendor's Retry-After, or the 60 s fallback (Q-2 B).
     *
     * @param  array{reason: string, http_status: ?int, retry_after_s: ?int, changed: list<string>, this_read: array<string, mixed>}  $result
     */
    private function readSharedRmm(Asset $asset, string $source, array $result): array
    {
        $syncedAt = fn () => AssetSources::syncedAt($asset, $source)?->toIso8601String();
        $reason = $result['reason'];
        if (in_array($reason, ['switched_off', 'not_configured'], true)) {
            return self::refused($reason);
        }
        if ($reason !== 'ok') {
            if ($reason === 'vendor_429') {
                self::startVendorCooldown($source, $result['retry_after_s']);
            }
            $reply = ['outcome' => 'failed', 'reason' => $reason];
            if ($result['retry_after_s'] !== null) {
                $reply['retry_after_s'] = $result['retry_after_s'];
            }

            return $reply + ['synced_at' => $syncedAt(), 'changed' => [], 'this_read' => [], 'http_status' => $result['http_status']];
        }

        return ['outcome' => 'ok', 'reason' => 'ok', 'synced_at' => $syncedAt(),
            'changed' => $result['changed'], 'this_read' => $result['this_read']];
    }

    /**
     * LITSRMM: LitsrmmAssetSyncService::syncDeviceDetail, ONE GET /v1/devices/{id}
     * (the detail read the per-client run already makes), taken under the run lock
     * (litsrmm-asset-sync), so it is refused with run_in_progress and no request while
     * the run, or another Sync now, holds it. ok writes THIS asset's LITSRMM list facts,
     * hardware columns and litsrmm_synced_at only. Every failure leaves the snapshot
     * (synced_at included) as it was; a 429 starts the LITSRMM vendor cooldown with the
     * vendor's Retry-After, or the 60 s fallback when it sends none (Q-2 B).
     */
    private function readLitsrmm(Asset $asset): array
    {
        $result = $this->litsrmmSync->syncDeviceDetail($asset);
        $reason = $result['reason'];
        if (in_array($reason, ['run_in_progress', 'client_not_mapped', 'switched_off', 'not_configured'], true)) {
            return self::refused($reason);
        }
        if ($reason !== 'ok') {
            if ($reason === 'vendor_429') {
                self::startVendorCooldown('litsrmm', $result['retry_after_s']);
            }
            $reply = ['outcome' => 'failed', 'reason' => $reason];
            if ($result['retry_after_s'] !== null) {
                $reply['retry_after_s'] = $result['retry_after_s'];
            }

            return $reply + ['synced_at' => $asset->litsrmm_synced_at?->toIso8601String(),
                'changed' => [], 'this_read' => [], 'http_status' => $result['http_status']];
        }

        return ['outcome' => 'ok', 'reason' => 'ok', 'synced_at' => $asset->litsrmm_synced_at?->toIso8601String(),
            'changed' => $result['changed'], 'this_read' => ['availability' => $result['device']->availabilityState]];
    }

    /**
     * Control D: the one per-org GET that ControlDClient::getDevices makes (GET devices with the
     * client's X-Force-Org-Id), read through ControlDClient::getForOrg so its envelope can be
     * checked, then THIS asset's row only.
     * ok writes that row through updateAssetFromDevice; the device sync's stale-clear branch
     * (syncDevicesForClient) is never called, so no other asset is written. Every failure
     * leaves the snapshot (synced_at included) as it was:
     *   a vendor answer with a status: vendor_429 + the Control D cooldown, otherwise http_NNN (a 404
     *   too: the list is per organization, so it says nothing about this device),
     *   no response: transport,
     *   a row list or a matching row that is not the shape updateAssetFromDevice reads, or that
     *   holds a value the asset's Control D columns cannot store: drift,
     *   no row with this PK: not_found_at_vendor (the link is kept; unlinking stays with the
     *   daily sync and the Security tab).
     * The ControlDClientException message embeds vendor text (C-56), so it is never read here:
     * only the HTTP status and Retry-After of the response it carries.
     */
    private function readControlD(Asset $asset): array
    {
        $failed = function (string $reason, ?int $status = null, ?int $retryAfter = null) use ($asset): array {
            $reply = ['outcome' => 'failed', 'reason' => $reason];
            if ($retryAfter !== null) {
                $reply['retry_after_s'] = $retryAfter;
            }

            return $reply + ['synced_at' => $asset->controld_synced_at?->toIso8601String(),
                'changed' => [], 'this_read' => [], 'http_status' => $status];
        };

        $vendor = app()->make(ControlDClient::class, ['config' => ['api_key' => ControlDConfig::get('api_key')]]);
        try {
            // The same GET getDevices() makes (getForOrg('devices', org)), read through its
            // envelope so a body without body.devices is drift, never "device not found".
            $response = $vendor->getForOrg('devices', (string) $asset->client->controld_org_id);
        } catch (ControlDClientException $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof RequestException && $previous->getResponse() !== null) {
                $status = $previous->getResponse()->getStatusCode();
                if ($status === 429) {
                    $wait = self::retryAfterSeconds($previous->getResponse()->getHeaderLine('Retry-After'));
                    self::startVendorCooldown('controld', $wait);

                    return $failed('vendor_429', 429, $wait);
                }

                // The list is per organization, so a 404 says nothing about this device: it is
                // http_404, never not_found_at_vendor.
                return $failed($status === 404 ? 'http_404' : self::failureReason($status), $status);
            }

            // No response came back (connect failure, timeout): the vendor was not reached.
            return $failed('transport');
        } catch (\TypeError) {
            // A JSON body that is not an object or list (getForOrg's array return type).
            return $failed('drift');
        }

        $devices = $response['body']['devices'] ?? null;
        if (! is_array($devices) || ! array_is_list($devices)) {
            return $failed('drift');
        }
        $device = null;
        foreach ($devices as $row) {
            if (! is_array($row)) {
                return $failed('drift');
            }
            if (($row['PK'] ?? null) === $asset->controld_device_id) {
                $device = $row;
                break;
            }
        }
        if ($device === null) {
            return $failed('not_found_at_vendor');
        }
        if (! self::isControlDDeviceShape($device)) {
            return $failed('drift');
        }

        $before = $asset->only(self::CONTROLD_WRITES);
        (new ControlDDeviceSyncService($vendor))->updateAssetFromDevice($asset, $device);
        $asset->refresh();
        $after = $asset->only(self::CONTROLD_WRITES);

        $changed = [];
        foreach (self::CONTROLD_WRITES as $column) {
            if ((string) $before[$column] !== (string) $after[$column]) {
                $changed[] = $column;
            }
        }

        return ['outcome' => 'ok', 'reason' => 'ok', 'synced_at' => $asset->controld_synced_at?->toIso8601String(),
            'changed' => $changed, 'this_read' => [
                'status' => $asset->controld_status,
                'agent_status' => $asset->controld_agent_status,
            ]];
    }

    /**
     * A matching row is written only when every value fits its asset column, so
     * updateAssetFromDevice cannot fail on it (MariaDB strict mode refuses what SQLite
     * stores). Anything else is drift and nothing is written. Each value may be absent or null:
     *   profile, ctrld: an object;
     *   status, ctrld.status (signed tinyint): a bool, or an integer -128..127 given as a
     *   number or a plain decimal string;
     *   profile.name (string 100), ctrld.version (string 20): a scalar of at most that many
     *   characters;
     *   ctrld.last_fetch (TIMESTAMP): a number or a digit string kept a day inside the
     *   1970..2038 range, whatever the connection's time zone (Carbon misreads any other string).
     */
    private static function isControlDDeviceShape(array $device): bool
    {
        $profile = $device['profile'] ?? null;
        $ctrld = $device['ctrld'] ?? null;
        if (($profile !== null && ! is_array($profile)) || ($ctrld !== null && ! is_array($ctrld))) {
            return false;
        }
        $tinyint = fn ($v) => $v === null || is_bool($v)
            || ((is_int($v) || (is_string($v) && preg_match('/\A-?\d{1,3}\z/', $v) === 1)) && (int) $v >= -128 && (int) $v <= 127);
        $chars = fn ($v, int $max) => $v === null || (is_scalar($v) && mb_strlen((string) $v) <= $max);
        $lastFetch = $ctrld['last_fetch'] ?? null;
        if (is_string($lastFetch) && preg_match('/\A\d{1,10}\z/', $lastFetch) === 1) {
            $lastFetch = (int) $lastFetch;
        }

        return $tinyint($device['status'] ?? null)
            && $tinyint($ctrld['status'] ?? null)
            && $chars($profile['name'] ?? null, 100)
            && $chars($ctrld['version'] ?? null, 20)
            && ($lastFetch === null || ((is_int($lastFetch) || is_float($lastFetch))
                && $lastFetch >= 86400 && $lastFetch <= 2147483647 - 86400));
    }

    /** Retry-After as a plain integer 0..RETRY_AFTER_CAP_S, otherwise RETRY_AFTER_FALLBACK_S. */
    public static function retryAfterSeconds(string $retryAfter): int
    {
        $retryAfter = trim($retryAfter);
        if ($retryAfter !== '' && strlen($retryAfter) <= 3 && ctype_digit($retryAfter)
            && (int) $retryAfter <= self::RETRY_AFTER_CAP_S) {
            return (int) $retryAfter;
        }

        return self::RETRY_AFTER_FALLBACK_S;
    }

    /** Start (or keep the longer of) a source's vendor cooldown, read by step 6. */
    private static function startVendorCooldown(string $source, int $seconds): void
    {
        $key = self::vendorCooldownKey($source);
        $until = max((int) Cache::get($key, 0), now()->getTimestamp() + $seconds);
        Cache::put($key, $until, max(1, $until - now()->getTimestamp()));
    }

    /**
     * AutoElevate: one GET /api/v1/computers/{id}, scope-proven against the asset client's
     * company. ok writes this asset's mode, check-in and synced_at only. Every failure leaves
     * the snapshot (synced_at included) as it was and writes nothing:
     *   404 not_found_at_vendor (the link is kept; the vendor's 404 is ambiguous),
     *   429 vendor_429 + retry_after_s and the shared vendor cooldown,
     *   other status http_NNN, no response transport, a row of another company other_client,
     *   any other shape drift.
     */
    private function readAutoElevate(Asset $asset): array
    {
        $failed = function (string $reason, ?int $status = null, ?int $retryAfter = null) use ($asset): array {
            $reply = ['outcome' => 'failed', 'reason' => $reason];
            if ($retryAfter !== null) {
                $reply['retry_after_s'] = $retryAfter;
            }

            return $reply + ['synced_at' => $asset->autoelevate_synced_at?->toIso8601String(),
                'changed' => [], 'this_read' => [], 'http_status' => $status];
        };

        try {
            $row = $this->autoElevateReads->computer($asset->autoelevate_computer_id, $asset->client->autoelevate_company_id);
        } catch (AutoElevateReadException $e) {
            if (preg_match('/^http_(\d{3})$/', $e->reason, $m)) {
                $status = (int) $m[1];
                if ($status === 429) {
                    $wait = $e->retryAfterS ?? AutoElevateClient::COOLDOWN_FALLBACK_SECONDS;
                    AutoElevateAssetSyncService::startVendorCooldown($wait);

                    return $failed('vendor_429', 429, $wait);
                }

                return $failed(self::failureReason($status), $status);
            }

            return match ($e->reason) {
                'transport' => $failed('transport'),
                'other_company' => $failed('other_client'),
                'configuration' => self::refused('not_configured'),
                default => $failed('drift'),
            };
        }

        $before = $asset->only(self::AUTOELEVATE_WRITES);
        $asset->forceFill([
            'autoelevate_elevation_mode' => $row['elevation_mode'],
            'autoelevate_last_checked_in_at' => $row['last_checked_in_at'],
            'autoelevate_synced_at' => now(),
        ])->save();
        $after = $asset->only(self::AUTOELEVATE_WRITES);

        $changed = [];
        foreach (self::AUTOELEVATE_WRITES as $column) {
            if ((string) $before[$column] !== (string) $after[$column]) {
                $changed[] = $column;
            }
        }

        return ['outcome' => 'ok', 'reason' => 'ok', 'synced_at' => $asset->autoelevate_synced_at?->toIso8601String(),
            'changed' => $changed, 'this_read' => [
                'elevation_mode' => $row['elevation_mode'],
                'last_checked_in_at' => $row['last_checked_in_at']?->toIso8601String(),
            ]];
    }

    private static function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    /**
     * Tactical: the existing single-agent detail read (no new vendor read, Q-9 A).
     * syncDeviceDetail never throws on a vendor failure; it answers degraded and leaves
     * the snapshot untouched (M3.2). The failure is classified by the HTTP status the
     * read carried back: with one, failureReason() (Tactical answered and refused);
     * without one, transport (no response came back, so the vendor was not reached).
     */
    private function readTactical(Asset $asset): array
    {
        $ta = $asset->tacticalAsset;
        $before = $ta->getAttributes();
        $result = $this->tacticalSync->syncDeviceDetail($asset);

        if (! $result->ok) {
            return ['outcome' => 'failed',
                'reason' => $result->httpStatus !== null ? self::failureReason($result->httpStatus) : 'transport',
                'synced_at' => $ta->synced_at?->toIso8601String(),
                'changed' => [], 'this_read' => [], 'http_status' => $result->httpStatus];
        }

        $changed = [];
        foreach ($ta->getAttributes() as $column => $value) {
            if ($column === 'updated_at') {
                continue;
            }
            if (! array_key_exists($column, $before) || (string) $before[$column] !== (string) $value) {
                $changed[] = $column;
            }
        }

        return ['outcome' => 'ok', 'reason' => 'ok', 'synced_at' => $ta->synced_at?->toIso8601String(),
            'changed' => $changed, 'this_read' => ['status' => $result->status]];
    }

    private static function refused(string $reason, ?int $retryAfter = null): array
    {
        $reply = ['outcome' => 'refused', 'reason' => $reason];
        if ($retryAfter !== null) {
            $reply['retry_after_s'] = $retryAfter;
        }
        $reply['changed'] = [];
        $reply['this_read'] = [];

        return $reply;
    }
}
