<?php

namespace App\Services\AssetSources;

use App\Models\Asset;
use App\Models\AssetSourceSync;
use App\Services\AutoElevate\AutoElevateReadService;
use App\Support\ScreenConnectConfig;
use App\Support\TacticalConfig;
use Carbon\CarbonInterface;

/**
 * The generic panel template's data for one source (design-r3 §2.1), built from
 * stored columns and the asset_source_syncs audit only. No vendor call.
 *
 * Every chip carries its kind (design-r3 §2.4):
 *   snapshot     "as of <age>", from a stored column or the source's synced_at
 *   live         "just now", from a read made while the panel is open (none in S0)
 *   vendor_claim the vendor's own statement, e.g. "AutoElevate last check-in"
 */
class SourcePanel
{
    public const KIND_SNAPSHOT = 'snapshot';

    public const KIND_LIVE = 'live';

    public const KIND_VENDOR_CLAIM = 'vendor_claim';

    /** Sources with no single-asset read (design.md "No single-asset read"); never a button. */
    public const NO_SINGLE_ASSET_READ = ['zorus', 'm365', 'servosity'];

    /**
     * Sources whose Sync now read is wired. Empty in S0: no source returns ok yet,
     * so no Sync now button renders; each source joins with its own slice.
     */
    public const SYNC_NOW_READY = [];

    /**
     * Stored device-detail columns per source: [column => human label]. Enum
     * columns with a known vocabulary are listed in ENUMS.
     */
    public const DETAILS = [
        'autoelevate' => [
            'autoelevate_computer_id' => 'Computer id',
            'autoelevate_elevation_mode' => 'Elevation mode',
            'autoelevate_agent_version' => 'Agent version',
            'autoelevate_last_checked_in_at' => 'Last check-in',
            'autoelevate_synced_at' => 'Synced',
        ],
        'litsrmm' => [
            'litsrmm_device_id' => 'Device id',
            'litsrmm_synced_at' => 'Synced',
        ],
        'tactical' => [
            'agent_id' => 'Agent id',
            'status' => 'Agent status',
            'agent_version' => 'Agent version',
            'os' => 'OS',
            'last_user' => 'Last user',
            'last_seen_at' => 'Last seen',
            'checks_failing' => 'Checks failing',
            'checks_total' => 'Checks total',
            'needs_reboot' => 'Needs reboot',
            'has_patches_pending' => 'Updates pending',
            'synced_at' => 'Synced',
        ],
        'level' => [
            'level_id' => 'Device id',
            'level_synced_at' => 'Synced',
        ],
        'ninja' => [
            'ninja_id' => 'Device id',
            'ninja_synced_at' => 'Synced',
        ],
        'comet' => [
            'comet_device_id' => 'Device id',
            'comet_username' => 'Username',
            'comet_backup_enabled' => 'Backup enabled',
        ],
        'controld' => [
            'controld_device_id' => 'Device id',
            'controld_profile_name' => 'Profile',
            'controld_status' => 'Device status',
            'controld_agent_status' => 'Agent status',
            'controld_agent_version' => 'Agent version',
            'controld_last_seen_at' => 'Last fetch',
            'controld_synced_at' => 'Synced',
        ],
        'zorus' => [
            'zorus_endpoint_id' => 'Endpoint id',
            'zorus_group_name' => 'Group',
            'zorus_filtering_enabled' => 'Filtering',
            'zorus_cybersight_enabled' => 'CyberSight',
            'zorus_agent_state' => 'Agent state',
            'zorus_agent_version' => 'Agent version',
            'zorus_last_seen_at' => 'Last seen',
            'zorus_synced_at' => 'Synced',
        ],
        'm365' => [
            'm365_device_id' => 'Device id',
            'm365_compliance_state' => 'Compliance state',
            'm365_is_compliant' => 'Compliant',
            'm365_defender_status' => 'Defender status',
            'm365_last_scan_at' => 'Last scan',
            'm365_last_sync_at' => 'Intune last sync',
            'm365_synced_at' => 'Synced',
        ],
        'screenconnect' => [
            'screenconnect_session_id' => 'Session id',
            'screenconnect_online' => 'Online',
            'screenconnect_client_version' => 'Client version',
            'screenconnect_last_seen_at' => 'Last event',
            'screenconnect_synced_at' => 'Synced',
        ],
        'servosity' => [
            'servosity_dr_backup_id' => 'Backup id',
            'servosity_backup_enabled' => 'Backup enabled',
        ],
    ];

    /** The shared RMM columns any of LITSRMM / Ninja / Level may have written (Q-4 A). */
    public const SHARED_DETAILS = [
        'rmm_online' => 'Online',
        'last_seen_at' => 'Last seen',
        'last_user' => 'Last user',
        'os' => 'OS',
        'last_boot_at' => 'Last boot',
        'needs_reboot' => 'Needs reboot',
    ];

    /** Known value vocabularies: anything else renders "Unrecognised: <value>". */
    public const ENUMS = [
        'autoelevate_elevation_mode' => AutoElevateReadService::ELEVATION_MODES,
        'status' => ['online', 'offline', 'overdue'],
    ];

    /** The vendor's own last-contact column per source, with its wording. */
    private const LAST_CONTACT = [
        'autoelevate' => ['autoelevate_last_checked_in_at', 'AutoElevate last check-in'],
        'tactical' => ['last_seen_at', 'Tactical last seen'],
        'controld' => ['controld_last_seen_at', 'Control D last fetch'],
        'zorus' => ['zorus_last_seen_at', 'Zorus last seen'],
        'm365' => ['m365_last_sync_at', 'Intune last sync'],
        'screenconnect' => ['screenconnect_last_seen_at', 'ScreenConnect last event'],
        'litsrmm' => ['last_seen_at', 'last seen'],
        'ninja' => ['last_seen_at', 'last seen'],
        'level' => ['last_seen_at', 'last seen'],
    ];

    public static function build(Asset $asset, string $source): array
    {
        $state = AssetSources::state($asset, $source);
        $linked = $state === AssetSources::STATE_LINKED;
        $off = $state === AssetSources::STATE_OFF;
        $syncedAt = AssetSources::syncedAt($asset, $source);
        $history = AssetSourceSync::with('user:id,name')
            ->where('asset_id', $asset->id)
            ->where('source', $source)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return [
            'source' => $source,
            'label' => AssetSources::label($source),
            'state' => $state,
            'open_in' => $linked ? self::openIn($asset, $source) : null,
            'chips' => self::chips($asset, $source, $state, $syncedAt),
            'attention' => AssetSources::attention($asset, $source),
            // A linked asset keeps its stored details even when the source is not configured.
            'details' => ($off || AssetSources::isLinked($asset, $source)) ? self::details($asset, $source) : [],
            'footer' => self::footer($asset, $source, $state, $syncedAt, $history->first()),
            'history' => $history,
            'sync_now' => $linked && in_array($source, self::SYNC_NOW_READY, true),
        ];
    }

    /** "Open in" only where a builder exists today; never a greyed or N/A element. */
    public static function openIn(Asset $asset, string $source): ?array
    {
        $url = match ($source) {
            'ninja' => $asset->ninja_url ?: null,
            'level' => $asset->level_url ?: null,
            'screenconnect' => $asset->screenconnect_session_id ? ScreenConnectConfig::sessionUrl($asset->screenconnect_session_id) : null,
            'tactical' => TacticalConfig::webUrl() ? rtrim(TacticalConfig::webUrl(), '/') : null,
            default => null,
        };
        if ($url === null) {
            return null;
        }

        return [
            'url' => $url,
            'label' => $source === 'tactical' ? 'Open Tactical dashboard' : 'Open in '.AssetSources::label($source),
        ];
    }

    private static function chips(Asset $asset, string $source, string $state, ?CarbonInterface $syncedAt): array
    {
        $off = $state === AssetSources::STATE_OFF;
        $stale = 'last known, not updated';
        $shared = in_array($source, AssetSources::SHARED_COLUMN_SOURCES, true);
        $chips = [];

        // A snapshot chip always states its age (design-r3 §2.4). With no sync
        // recorded there is no snapshot, so the chip says so instead.
        $chips[] = $syncedAt
            ? [
                'label' => 'Last synced',
                'value' => $syncedAt->toAppTz()->format('M j, Y g:i A T'),
                'kind' => self::KIND_SNAPSHOT,
                'note' => self::asOf($syncedAt),
            ]
            : (in_array($source, AssetSources::UNATTRIBUTED_SYNC_SOURCES, true)
                ? [
                    'label' => 'Last synced',
                    'value' => 'Not tracked',
                    'kind' => 'none',
                    'note' => 'no sync stamp of its own is stored',
                ]
                : [
                    'label' => 'Last synced',
                    'value' => 'Never',
                    'kind' => 'none',
                    'note' => 'no sync recorded',
                ]);

        if (isset(self::LAST_CONTACT[$source]) && $state !== AssetSources::STATE_NOT_LINKED && $state !== AssetSources::STATE_NOT_CONFIGURED) {
            [$column, $wording] = self::LAST_CONTACT[$source];
            $value = self::raw($asset, $source, $column);
            $note = $wording;
            if ($shared) {
                $note .= '; '.AssetSources::INFERRED_WORDING;
            }
            if ($off) {
                $note = $stale.'; '.$note;
            }
            $chips[] = [
                'label' => 'Last contact',
                'value' => $value instanceof CarbonInterface ? $value->diffForHumans() : 'Not reported by '.AssetSources::label($source),
                'kind' => self::KIND_VENDOR_CLAIM,
                'note' => $note,
            ];
        }

        if ($off) {
            foreach ($chips as $i => $chip) {
                if ($chip['kind'] === self::KIND_SNAPSHOT) {
                    $chips[$i]['note'] .= '; '.$stale;
                }
            }
        }

        return $chips;
    }

    public static function asOf(CarbonInterface $at): string
    {
        return 'as of '.$at->diffForHumans();
    }

    private static function raw(Asset $asset, string $source, string $column): mixed
    {
        if ($source === 'tactical') {
            return $asset->tacticalAsset?->{$column};
        }

        return $asset->{$column};
    }

    /** @return list<array{label: string, column: string, value: string, shared: bool}> */
    private static function details(Asset $asset, string $source): array
    {
        $vendor = AssetSources::label($source);
        $rows = [];
        $columns = self::DETAILS[$source] ?? [];
        $shared = in_array($source, AssetSources::SHARED_COLUMN_SOURCES, true);
        foreach ($columns as $column => $label) {
            $rows[] = self::detailRow($asset, $source, $column, $label, $vendor, false);
        }
        if ($shared) {
            foreach (self::SHARED_DETAILS as $column => $label) {
                $rows[] = self::detailRow($asset, $source, $column, $label, $vendor, true);
            }
        }

        return $rows;
    }

    private static function detailRow(Asset $asset, string $source, string $column, string $label, string $vendor, bool $shared): array
    {
        $raw = self::raw($asset, $source, $column);
        if ($raw === null || $raw === '') {
            $value = 'Not reported by '.$vendor;
        } elseif ($raw instanceof CarbonInterface) {
            $value = $raw->toAppTz()->format('M j, Y g:i A T');
        } elseif (is_bool($raw)) {
            $value = $raw ? 'Yes' : 'No';
        } elseif (isset(self::ENUMS[$column]) && ! in_array($raw, self::ENUMS[$column], true)) {
            $value = 'Unrecognised: '.$raw;
        } else {
            $value = (string) $raw;
        }

        return ['label' => $label, 'column' => $column, 'value' => $value, 'shared' => $shared];
    }

    /** The one-line footer strip (design-r3 §2.3). */
    private static function footer(Asset $asset, string $source, string $state, ?CarbonInterface $syncedAt, ?AssetSourceSync $last): array
    {
        $vendor = AssetSources::label($source);
        $synced = $syncedAt
            ? 'Synced '.$syncedAt->diffForHumans().' · '.$syncedAt->toAppTz()->format('M j, Y g:i A T')
            : (in_array($source, AssetSources::UNATTRIBUTED_SYNC_SOURCES, true) ? 'Sync time not tracked for this source' : 'No sync recorded');

        $text = match (true) {
            $state === AssetSources::STATE_NOT_CONFIGURED => 'Not configured in Settings',
            $state === AssetSources::STATE_OFF => 'Switched off in Settings; not being updated',
            $state === AssetSources::STATE_NOT_LINKED => "This asset is not linked to {$vendor}",
            $source === 'screenconnect' => 'Updated by ScreenConnect events; last event '
                .($asset->screenconnect_last_seen_at ? $asset->screenconnect_last_seen_at->diffForHumans() : 'not recorded'),
            in_array($source, self::NO_SINGLE_ASSET_READ, true) => $synced.' · updated by its scheduled sync; this source cannot be read for one asset',
            default => $synced.' · scheduled',
        };

        $outcome = null;
        if ($last !== null && in_array($state, [AssetSources::STATE_LINKED], true)) {
            $outcome = [
                'badge' => match ($last->reason) {
                    'ok' => 'OK',
                    'vendor_429', 'vendor_cooling_down', 'user_throttled', 'recently_synced' => 'RATE LIMITED',
                    default => $last->outcome === 'failed' ? 'FAILED' : 'REFUSED',
                },
                'sentence' => SourceSyncPipeline::sentence($last->reason),
                'by' => $last->user?->name,
                'at' => $last->started_at,
            ];
        }

        return ['text' => $text, 'outcome' => $outcome];
    }
}
