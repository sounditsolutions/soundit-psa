<?php

namespace App\Services\AssetSources;

use App\Models\Asset;
use App\Models\AssetSourceSync;
use App\Models\User;
use App\Services\Tactical\TacticalDeviceSyncService;
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
 *                          (getAgent), which writes this asset's tactical_assets row only.
 *                          A degraded read leaves the snapshot (synced_at included) as it
 *                          was and is answered failed/transport. Every other source that
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
    public const WIRED = ['tactical'];

    public function __construct(
        private readonly TacticalDeviceSyncService $tacticalSync,
    ) {}

    public const USER_LIMIT_PER_MINUTE = 6;

    public const ASSET_SOURCE_COOLDOWN_S = 30;

    /** Closed reason vocabulary (design.md "Reply shape and reason vocabulary"), plus no_single_asset_read. */
    public const REASONS = [
        'ok', 'switched_off', 'not_configured', 'not_linked', 'client_not_mapped',
        'user_throttled', 'recently_synced', 'vendor_cooling_down', 'vendor_429',
        'not_found_at_vendor', 'other_client', 'run_in_progress', 'transport', 'drift',
        'no_single_asset_read',
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
        'vendor_429' => 'The vendor is rate-limiting requests.',
        'not_found_at_vendor' => 'The vendor did not return this device; the link is kept.',
        'other_client' => "The vendor's device belongs to a different client; nothing was written.",
        'run_in_progress' => 'A scheduled sync for this source is running; try again when it finishes.',
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

        AssetSourceSync::create([
            'asset_id' => $asset->id,
            'source' => $source,
            'user_id' => $user->id,
            'outcome' => $reply['outcome'],
            'reason' => $reply['reason'],
            'http_status' => null,
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
            || ($source === 'tactical' && blank($asset->tacticalAsset?->agent_id))) {
            if ($source === 'autoelevate' && blank($asset->client?->autoelevate_company_id)) {
                return self::refused('client_not_mapped');
            }

            return self::refused('not_linked');
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

        // 7-9. The read, for a wired source only; every other source makes no vendor call.
        $reply = in_array($source, self::WIRED, true)
            ? $this->read($asset, $source)
            : self::refused('no_single_asset_read');
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
        };
    }

    /**
     * Tactical: the existing single-agent detail read (no new vendor read, Q-9 A).
     * syncDeviceDetail never throws on a vendor failure; it answers degraded and leaves
     * the snapshot untouched, which is reported as failed/transport (M3.2).
     */
    private function readTactical(Asset $asset): array
    {
        $ta = $asset->tacticalAsset;
        $before = $ta->getAttributes();
        $result = $this->tacticalSync->syncDeviceDetail($asset);

        if (! $result->ok) {
            return ['outcome' => 'failed', 'reason' => 'transport', 'synced_at' => $ta->synced_at?->toIso8601String(),
                'changed' => [], 'this_read' => []];
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
