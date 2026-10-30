<?php

namespace App\Services\AssetSources;

use App\Models\Asset;
use App\Services\AssetHealthService;
use App\Services\AutoElevate\AutoElevateAssetSyncService;
use App\Support\AutoElevateConfig;
use App\Support\CippConfig;
use App\Support\CometConfig;
use App\Support\ControlDConfig;
use App\Support\LevelConfig;
use App\Support\LitsrmmConfig;
use App\Support\NinjaConfig;
use App\Support\ScreenConnectConfig;
use App\Support\ServosityConfig;
use App\Support\TacticalConfig;
use App\Support\ZorusConfig;
use Carbon\CarbonInterface;

/**
 * The asset page's Sources tab: which integrations know this asset, in what
 * state, and what their STORED data says needs a look (design-r3 §1).
 *
 * Everything here reads local columns and Settings only. No method makes a
 * vendor call: the rail, its attention markers and every panel render from the
 * database (design-r3 §1 "computed from stored columns only").
 */
class AssetSources
{
    /** Fixed rail order (design.md "Rail order and visibility"); never sorted by state. */
    public const ORDER = [
        'autoelevate',
        'litsrmm',
        'tactical',
        'level',
        'ninja',
        'comet',
        'controld',
        'zorus',
        'm365',
        'screenconnect',
        'servosity',
    ];

    public const LABELS = [
        'autoelevate' => 'AutoElevate',
        'litsrmm' => 'LITSRMM',
        'tactical' => 'Tactical',
        'level' => 'Level',
        'ninja' => 'Ninja',
        'comet' => 'Comet',
        'controld' => 'Control D',
        'zorus' => 'Zorus',
        'm365' => 'M365 (CIPP)',
        'screenconnect' => 'ScreenConnect',
        'servosity' => 'Servosity',
    ];

    /** Sources that write the shared assets RMM columns (provenance is inferred, Q-4 A). */
    public const SHARED_COLUMN_SOURCES = ['litsrmm', 'ninja', 'level'];

    /** Sources with no sync stamp of their own (syncedAt() is null by design, not "never"). */
    public const UNATTRIBUTED_SYNC_SOURCES = ['comet', 'servosity'];

    public const INFERRED_WORDING = 'most likely written by the newest sync';

    public const STATE_LINKED = 'linked';

    public const STATE_NOT_LINKED = 'not_linked';

    public const STATE_OFF = 'off';

    public const STATE_NOT_CONFIGURED = 'not_configured';

    public static function label(string $source): string
    {
        return self::LABELS[$source] ?? $source;
    }

    public static function isKnown(string $source): bool
    {
        return in_array($source, self::ORDER, true);
    }

    /** Configured in Settings (credentials present). */
    public static function isConfigured(string $source): bool
    {
        return match ($source) {
            'autoelevate' => AutoElevateConfig::isConfigured(),
            'litsrmm' => LitsrmmConfig::isConfigured(),
            'tactical' => TacticalConfig::isConfigured(),
            'level' => LevelConfig::isConfigured(),
            'ninja' => NinjaConfig::isConfigured(),
            'comet' => (bool) CometConfig::isConfigured(),
            'controld' => ControlDConfig::isConfigured(),
            'zorus' => ZorusConfig::isConfigured(),
            'm365' => CippConfig::isConfigured(),
            'screenconnect' => ScreenConnectConfig::isConfigured(),
            'servosity' => ServosityConfig::isConfigured(),
            default => false,
        };
    }

    /**
     * The source's on/off switch. AutoElevate has none yet (its switch ships with
     * its panel, Q-5 A) and Comet's switch IS "configured" (CometConfig::isEnabled),
     * so both answer true here and are governed by isConfigured() alone.
     */
    public static function isSwitchedOn(string $source): bool
    {
        return match ($source) {
            'autoelevate' => true,
            'litsrmm' => LitsrmmConfig::isEnabled(),
            'tactical' => TacticalConfig::isEnabled(),
            'level' => LevelConfig::isEnabled(),
            'ninja' => NinjaConfig::isEnabled(),
            'comet' => (bool) CometConfig::isEnabled(),
            'controld' => ControlDConfig::isEnabled(),
            'zorus' => ZorusConfig::isEnabled(),
            'm365' => CippConfig::isDeviceSyncEnabled(),
            'screenconnect' => ScreenConnectConfig::isEnabled(),
            'servosity' => ServosityConfig::isEnabled(),
            default => false,
        };
    }

    /** Does this asset carry the source's link? */
    public static function isLinked(Asset $asset, string $source): bool
    {
        $filled = fn ($v) => $v !== null && $v !== '';

        return match ($source) {
            'autoelevate' => $filled($asset->autoelevate_computer_id),
            'litsrmm' => $filled($asset->litsrmm_device_id),
            'tactical' => $asset->tacticalAsset !== null,
            'level' => $filled($asset->level_id),
            'ninja' => $filled($asset->ninja_id),
            'comet' => $filled($asset->comet_device_id),
            'controld' => $filled($asset->controld_device_id),
            'zorus' => $filled($asset->zorus_endpoint_id),
            'm365' => $filled($asset->m365_device_id),
            'screenconnect' => $filled($asset->screenconnect_session_id),
            'servosity' => $filled($asset->servosity_dr_backup_id),
            default => false,
        };
    }

    /** One explicit state per source: not configured, off, linked or not linked. */
    public static function state(Asset $asset, string $source): string
    {
        if (! self::isConfigured($source)) {
            return self::STATE_NOT_CONFIGURED;
        }
        if (! self::isSwitchedOn($source)) {
            return self::STATE_OFF;
        }

        return self::isLinked($asset, $source) ? self::STATE_LINKED : self::STATE_NOT_LINKED;
    }

    /**
     * The rail: every source linked on this asset OR configured in Settings, in
     * the fixed order. A never-configured, unlinked vendor is left out.
     *
     * `attention` is the rail marker count (every attention item). `default_attention`
     * is the same count without AutoElevate link-state items (key 'link'): a link row
     * marks the rail but never picks the default selection.
     *
     * @return list<array{source: string, label: string, state: string, attention: int, default_attention: int, synced_at: ?CarbonInterface}>
     */
    public static function rail(Asset $asset): array
    {
        $rail = [];
        foreach (self::ORDER as $source) {
            if (! self::isLinked($asset, $source) && ! self::isConfigured($source)) {
                continue;
            }
            $items = self::attention($asset, $source);
            $rail[] = [
                'source' => $source,
                'label' => self::label($source),
                'state' => self::state($asset, $source),
                'attention' => count($items),
                'default_attention' => count(array_filter($items, fn ($i) => $i['key'] !== 'link')),
                'synced_at' => self::syncedAt($asset, $source),
            ];
        }

        return $rail;
    }

    /** The tab label count: linked sources only. */
    public static function linkedCount(array $rail): int
    {
        return count(array_filter($rail, fn ($r) => $r['state'] === self::STATE_LINKED));
    }

    /**
     * Default selection (design-r3 §1): the first source in rail order with an
     * attention count not counting link-state items (default_attention); else the
     * most recently synced; else the first entry.
     * A #tab-sources/<source> deep link overrides this in the browser.
     */
    public static function defaultSource(array $rail): ?string
    {
        foreach ($rail as $r) {
            if ($r['default_attention'] > 0) {
                return $r['source'];
            }
        }
        $best = null;
        foreach ($rail as $r) {
            if ($r['synced_at'] !== null && ($best === null || $r['synced_at']->greaterThan($best['synced_at']))) {
                $best = $r;
            }
        }

        return $best['source'] ?? ($rail[0]['source'] ?? null);
    }

    /** The source's own last-sync stamp for this asset, or null when none is attributable. */
    public static function syncedAt(Asset $asset, string $source): ?CarbonInterface
    {
        return match ($source) {
            'autoelevate' => $asset->autoelevate_synced_at,
            'litsrmm' => $asset->litsrmm_synced_at,
            'tactical' => $asset->tacticalAsset?->synced_at,
            'level' => $asset->level_synced_at,
            'ninja' => $asset->ninja_synced_at,
            'controld' => $asset->controld_synced_at,
            'zorus' => $asset->zorus_synced_at,
            'm365' => $asset->m365_synced_at,
            'screenconnect' => $asset->screenconnect_synced_at,
            // Comet's stored stamp (backup_synced_at) is shared with Ninja backup, and
            // Servosity stores none: neither can be attributed to the source alone.
            default => null,
        };
    }

    /**
     * Stored items a technician should look at (design-r3 §1 marker table).
     * Sources must be LINKED except AutoElevate, which also reports link state.
     * Each item: ['key', 'title', 'evidence'].
     *
     * @return list<array{key: string, title: string, evidence: string}>
     */
    public static function attention(Asset $asset, string $source): array
    {
        if ($source !== 'autoelevate' && self::state($asset, $source) !== self::STATE_LINKED) {
            return [];
        }

        return match ($source) {
            'tactical' => self::tacticalAttention($asset),
            'autoelevate' => self::autoElevateAttention($asset),
            'comet' => self::alertItems($asset, 'comet'),
            'controld' => self::controlDAttention($asset),
            'zorus' => self::zorusAttention($asset),
            'm365' => self::m365Attention($asset),
            'litsrmm', 'ninja', 'level' => self::sharedRmmAttention($asset, $source),
            default => [],
        };
    }

    /** @return list<array{key: string, title: string, evidence: string}> */
    private static function tacticalAttention(Asset $asset): array
    {
        $ta = $asset->tacticalAsset;
        $items = [];
        $failing = $ta->checks_failing;
        $total = $ta->checks_total;
        if ($failing !== null && $failing > 0) {
            $items[] = ['key' => 'checks', 'title' => "{$failing} failing check".($failing === 1 ? '' : 's'),
                'evidence' => 'reported by Tactical'.($total ? ", of {$total} checks" : '')];
        } elseif ($failing === null && $total !== null && $total > 0) {
            $items[] = ['key' => 'checks', 'title' => 'Check results not reported',
                'evidence' => "Tactical reports {$total} checks but no failing count"];
        }
        if ($ta->needs_reboot === true) {
            $items[] = ['key' => 'reboot', 'title' => 'Reboot needed', 'evidence' => 'reported by Tactical'];
        }
        if ($ta->has_patches_pending === true) {
            $items[] = ['key' => 'updates', 'title' => 'Updates pending', 'evidence' => 'reported by Tactical'];
        }
        if (in_array($ta->status, ['offline', 'overdue'], true)) {
            $items[] = ['key' => 'agent', 'title' => 'Agent '.$ta->status,
                'evidence' => 'Tactical agent status'];
        }

        return array_merge($items, self::alertItems($asset, 'tactical'));
    }

    /** @return list<array{key: string, title: string, evidence: string}> */
    private static function autoElevateAttention(Asset $asset): array
    {
        $items = [];
        $link = AutoElevateAssetSyncService::assetLinkState($asset);
        if ($link['state'] !== 'linked') {
            $items[] = ['key' => 'link', 'title' => 'Link state: '.$link['state'],
                'evidence' => $link['reason'] ?? 'Stored AutoElevate link state'];
        }
        // Keep elevation-mode markers restricted to linked, configured panels.
        if (self::state($asset, 'autoelevate') !== self::STATE_LINKED) {
            return $items;
        }
        $mode = $asset->autoelevate_elevation_mode;
        if ($mode === 'audit') {
            $items[] = ['key' => 'mode', 'title' => 'Audit mode',
                'evidence' => 'elevation requests are only logged on this machine, not enforced'];
        }
        if ($mode !== null && ! in_array($mode, \App\Services\AutoElevate\AutoElevateReadService::ELEVATION_MODES, true)) {
            $items[] = ['key' => 'mode', 'title' => 'Unrecognised: '.$mode, 'evidence' => 'elevation mode reported by AutoElevate'];
        }

        return $items;
    }

    /** @return list<array{key: string, title: string, evidence: string}> */
    private static function controlDAttention(Asset $asset): array
    {
        $status = $asset->controld_agent_status;
        if ($status !== null && (int) $status !== 1) {
            return [['key' => 'agent', 'title' => 'Agent not active', 'evidence' => 'Control D agent status '.$status]];
        }

        return [];
    }

    /** @return list<array{key: string, title: string, evidence: string}> */
    private static function zorusAttention(Asset $asset): array
    {
        $items = [];
        if ($asset->zorus_agent_state !== null && $asset->zorus_agent_state !== 'Online') {
            $items[] = ['key' => 'agent', 'title' => 'Agent not online', 'evidence' => 'Zorus agent state '.$asset->zorus_agent_state];
        }
        if ($asset->zorus_filtering_enabled === false) {
            $items[] = ['key' => 'filtering', 'title' => 'Filtering off', 'evidence' => 'reported by Zorus'];
        }

        return $items;
    }

    /** @return list<array{key: string, title: string, evidence: string}> */
    private static function m365Attention(Asset $asset): array
    {
        $items = [];
        if ($asset->m365_is_compliant === false) {
            $items[] = ['key' => 'compliance', 'title' => 'Not compliant',
                'evidence' => 'Intune compliance state '.($asset->m365_compliance_state ?? 'not reported')];
        }
        $defender = $asset->m365_defender_status;
        // The same deny-list predicate the health score uses (one shared reader).
        if ($defender !== null && AssetHealthService::defenderLooksBad($defender)) {
            $items[] = ['key' => 'defender', 'title' => 'Defender: '.$defender,
                'evidence' => 'Defender AV status reported by Intune'];
        }

        return $items;
    }

    /** @return list<array{key: string, title: string, evidence: string}> */
    private static function sharedRmmAttention(Asset $asset, string $source): array
    {
        $items = [];
        if ($asset->needs_reboot === true) {
            $items[] = ['key' => 'reboot', 'title' => 'Reboot needed', 'evidence' => self::INFERRED_WORDING];
        }
        if ($asset->rmm_online === false) {
            $items[] = ['key' => 'offline', 'title' => 'Offline', 'evidence' => self::INFERRED_WORDING];
        }

        return $items;
    }

    /** @return list<array{key: string, title: string, evidence: string}> */
    private static function alertItems(Asset $asset, string $alertSource): array
    {
        $items = [];
        foreach ($asset->activeAlerts as $alert) {
            if ($alert->source?->value !== $alertSource) {
                continue;
            }
            $items[] = ['key' => 'alert', 'title' => 'Open alert: '.$alert->title,
                'evidence' => ($alert->severity?->value ?? 'alert').($alert->fired_at ? ', fired '.$alert->fired_at->diffForHumans() : '')];
        }

        return $items;
    }
}
