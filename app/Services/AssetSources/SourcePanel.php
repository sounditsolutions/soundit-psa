<?php

namespace App\Services\AssetSources;

use App\Models\Asset;
use App\Models\AssetSourceSync;
use App\Models\TacticalActionLog;
use App\Services\AutoElevate\AutoElevateReadService;
use App\Services\Tactical\TacticalFieldMap;
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
     * Sources whose Sync now read is wired (SourceSyncPipeline::WIRED). Only these
     * render a Sync now button; each source joins with its own slice.
     */
    public const SYNC_NOW_READY = SourceSyncPipeline::WIRED;

    /** The Sync now button's title and accessible description, per wired source (design-r3 §2.3). */
    public const SYNC_NOW_DESCRIPTION = [
        'tactical' => "Reads this computer's record from Tactical. Changes nothing at Tactical.",
    ];

    /** Open Tactical alerts listed in the panel before the "all N alerts" link (design-r3 §4). */
    public const ALERTS_SHOWN = 3;

    /** Tactical actions listed under Recent activity (newest first, design-r3 §2.2). */
    public const RECENT_ACTIONS_SHOWN = 10;

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
            // Moved from the Overview Tactical card's details table (design-r3 §4 A).
            'public_ip' => 'Public IP',
            'local_ips' => 'Local IPs',
            'make_model' => 'Make/Model',
            'cpu' => 'CPU',
            'ram_gb' => 'RAM (GB)',
            'disk_summary' => 'Disk',
            'serial_number' => 'Serial number',
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
        $syncedAt = AssetSources::syncedAt($asset, $source);
        $history = AssetSourceSync::with('user:id,name')
            ->where('asset_id', $asset->id)
            ->where('source', $source)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $attention = AssetSources::attention($asset, $source);
        $moreAlerts = null;
        if ($source === 'tactical') {
            // Top 3 open Tactical alerts in the panel; the rest behind "all N alerts" (the marker still counts all).
            $alerts = array_values(array_filter($attention, fn ($i) => $i['key'] === 'alert'));
            if (count($alerts) > self::ALERTS_SHOWN) {
                $moreAlerts = count($alerts);
                $kept = 0;
                $attention = array_values(array_filter($attention, function ($i) use (&$kept) {
                    return $i['key'] !== 'alert' || ++$kept <= self::ALERTS_SHOWN;
                }));
            }
        }

        return [
            'source' => $source,
            'label' => AssetSources::label($source),
            'state' => $state,
            // Carries the asset's link, whatever the state (a not-configured source can still be linked).
            'linked' => AssetSources::isLinked($asset, $source),
            'open_in' => $linked ? self::openIn($asset, $source) : null,
            'chips' => self::chips($asset, $source, $state, $syncedAt),
            'attention' => $attention,
            'all_alerts' => $moreAlerts,
            // Tactical: the failing-check list is read when the panel is opened (never on render).
            'checks_live' => $source === 'tactical' && $linked && filled($asset->tacticalAsset?->agent_id),
            // Local PSA history (no vendor call), so it shows whenever the asset is linked.
            'activity' => $source === 'tactical' && AssetSources::isLinked($asset, $source) ? self::tacticalActivity($asset) : null,
            // Details only for a linked asset, whatever the state (OFF and not configured included):
            // an unlinked source has no device of its own to describe.
            'details' => AssetSources::isLinked($asset, $source) ? self::details($asset, $source) : [],
            'footer' => self::footer($asset, $source, $state, $syncedAt, $history->first()),
            'history' => $history,
            'sync_now' => $linked && in_array($source, self::SYNC_NOW_READY, true),
            'sync_now_description' => self::SYNC_NOW_DESCRIPTION[$source] ?? null,
        ];
    }

    /**
     * "Open in" only where a builder exists today; never a greyed or N/A element.
     * Every URL, stored or built, must be absolute http(s) with a host: a
     * javascript:, data:, scheme-relative or other value renders no link.
     */
    public static function openIn(Asset $asset, string $source): ?array
    {
        $url = match ($source) {
            'ninja' => $asset->ninja_url ?: null,
            'level' => $asset->level_url ?: null,
            'screenconnect' => $asset->screenconnect_session_id ? ScreenConnectConfig::sessionUrl($asset->screenconnect_session_id) : null,
            'tactical' => TacticalConfig::webUrl() ? rtrim(TacticalConfig::webUrl(), '/') : null,
            default => null,
        };
        $url = self::httpUrlOrNull($url);
        if ($url === null) {
            return null;
        }

        return [
            'url' => $url,
            'label' => $source === 'tactical' ? 'Open Tactical dashboard' : 'Open in '.AssetSources::label($source),
        ];
    }

    /** The URL itself when its scheme is http or https and it names a host; otherwise null. */
    public static function httpUrlOrNull(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true) ? $url : null;
    }

    /**
     * Recent Tactical actions (local action log, already redacted at write), newest
     * first, ticket-linked. No vendor call.
     *
     * @return array{total: int, rows: list<array{action: string, actor: string, result_status: string, ticket_id: ?int, when: ?CarbonInterface}>}
     */
    private static function tacticalActivity(Asset $asset): array
    {
        $rows = TacticalActionLog::where('asset_id', $asset->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_ACTIONS_SHOWN)
            ->get()
            ->map(fn (TacticalActionLog $log) => [
                'action' => (string) $log->action_key,
                'actor' => (string) $log->actor_label,
                'result_status' => (string) $log->result_status,
                'ticket_id' => $log->ticket_id,
                'when' => $log->created_at,
            ])
            ->all();

        return ['total' => TacticalActionLog::where('asset_id', $asset->id)->count(), 'rows' => $rows];
    }

    /**
     * The Tactical health strip after the shared chips (design-r3 §2.2): status, last
     * user, checks, reboot, updates and open Tactical alerts. Every chip here comes
     * from tactical_assets (never the shared assets columns) or the PSA's own alerts.
     *
     * The checks chip keeps the psa-0pb9m snapshot rules verbatim: a stored count is a
     * snapshot, which carries no passing evidence, so it can never read "all passing";
     * unknown is never green; zero checks reads "unmonitored".
     */
    private static function tacticalChips(Asset $asset, ?CarbonInterface $syncedAt, bool $off): array
    {
        $ta = $asset->tacticalAsset;
        $kind = $syncedAt ? self::KIND_SNAPSHOT : 'none';
        $note = $syncedAt ? self::asOf($syncedAt) : 'no sync recorded';
        $failing = $ta->checks_failing;
        $total = $ta->checks_total;

        $checks = match (true) {
            $failing === null => 'checks unknown — health not verified',
            $total === 0 => 'no checks — unmonitored',
            TacticalFieldMap::checksCoverage($total, $failing, null) === TacticalFieldMap::COVERAGE_UNVERIFIED => $failing >= $total
                ? "all checks failing — monitoring unverified ({$failing} of {$total})"
                : "no check passing — monitoring unverified ({$failing} failing of {$total})",
            $failing > 0 => "monitoring coverage unknown — {$failing} of {$total} checks failing",
            default => "{$total} checks — coverage unknown",
        };

        $status = $ta->status;
        $statusValue = $status === null ? 'Not reported by Tactical'
            : (in_array($status, self::ENUMS['status'], true) ? ucfirst($status) : 'Unrecognised: '.$status);
        $alerts = $asset->activeAlerts->filter(fn ($a) => $a->source?->value === 'tactical')->count();
        $yesNo = fn (?bool $v, string $yes, string $no) => $v === null ? 'Not reported by Tactical' : ($v ? $yes : $no);

        $chips = [
            ['label' => 'Agent status', 'value' => $statusValue, 'kind' => $kind, 'note' => $note],
            ['label' => 'Last user', 'value' => filled($ta->last_user) ? (string) $ta->last_user : 'Not reported by Tactical', 'kind' => $kind, 'note' => $note],
            ['label' => 'Checks', 'value' => $checks, 'kind' => $kind, 'note' => $note, 'key' => 'checks'],
            ['label' => 'Reboot', 'value' => $yesNo($ta->needs_reboot, 'Reboot needed', 'Not needed'), 'kind' => $kind, 'note' => $note, 'key' => 'reboot'],
            ['label' => 'Updates', 'value' => $yesNo($ta->has_patches_pending, 'Updates pending', 'None pending'), 'kind' => $kind, 'note' => $note, 'key' => 'updates'],
            ['label' => 'Open Tactical alerts', 'value' => (string) $alerts, 'kind' => 'psa', 'note' => 'open in this PSA now', 'key' => 'alerts'],
        ];
        if ($off) {
            foreach ($chips as $i => $chip) {
                if ($chip['kind'] === self::KIND_SNAPSHOT) {
                    $chips[$i]['note'] .= '; last known, not updated';
                }
            }
        }

        return $chips;
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

        // Last contact belongs to the linked device; an unlinked source (OFF included) has none.
        if (isset(self::LAST_CONTACT[$source]) && $state !== AssetSources::STATE_NOT_CONFIGURED && AssetSources::isLinked($asset, $source)) {
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

        if ($source === 'tactical' && $state !== AssetSources::STATE_NOT_CONFIGURED && AssetSources::isLinked($asset, $source)) {
            $chips = array_merge($chips, self::tacticalChips($asset, $syncedAt, $off));
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
        if (is_array($raw)) {
            $raw = $raw === [] ? null : implode(', ', array_map('strval', $raw));
        }
        $note = null;
        if ($source === 'tactical' && $column === 'os' && $asset->hasTacticalOsConflict()) {
            // psa-sp30: the conflict flag moved with the Overview details table.
            $note = 'Device record: '.$asset->os;
        }
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

        return ['label' => $label, 'column' => $column, 'value' => $value, 'shared' => $shared, 'note' => $note];
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
            default => $synced,
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
