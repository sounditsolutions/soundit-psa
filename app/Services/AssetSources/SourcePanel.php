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
 * stored columns and the asset_source_syncs audit, plus the asset page's own Comet
 * job read when it is passed in. No vendor call of its own.
 *
 * Every chip carries its kind (design-r3 §2.4):
 *   snapshot     "as of <age>", from a stored column or the source's synced_at
 *   live         "just now" or "read from Comet <age>", from a read made for this
 *                page view (S4: the Comet job read the asset page already makes,
 *                passed in; never a second read)
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
        'tactical' => "Reads this computer's record from Tactical and updates its details here. If actions were queued while it was offline, this can start them.",
        'autoelevate' => "Reads this computer's record from AutoElevate. Changes nothing at AutoElevate.",
        'controld' => "Reads this client's device list from Control D and updates this computer only. Changes nothing at Control D.",
        'litsrmm' => "Reads this computer's record from LITSRMM and updates its details here. Changes nothing at LITSRMM.",
        'ninja' => "Reads this computer's record from Ninja and updates its details here. Changes nothing at Ninja.",
        'level' => "Reads this computer's record from Level and updates its details here. Changes nothing at Level.",
    ];

    /**
     * Comet backup posture labels, built from CometJobService::derivePosture's vocabulary
     * (job_state) verbatim. Never "OK" (M4B.1): only last_backup_succeeded means the
     * newest backup worked, and the read working is not the backup working.
     */
    public const COMET_POSTURE = [
        'last_backup_succeeded' => 'Last backup succeeded',
        'last_backup_failed' => 'Last backup failed',
        'last_backup_running' => 'Backup in progress',
        'last_backup_unknown' => 'Last backup result unknown',
        'no_backup_jobs_observed' => 'No backup jobs observed',
        'unavailable' => 'Unavailable',
    ];

    /** Servosity's Recent activity line: no run outcome is ever read or claimed (M4B.3). */
    public const SERVOSITY_NO_OUTCOME = "Job outcome is not available from Servosity's API.";

    /** ScreenConnect session events listed under Recent activity (newest first, design-r3 §2.2). */
    public const SC_EVENTS_SHOWN = 10;

    /** The existing ScreenConnect rule: no event for this many days needs a look. */
    public const SC_SILENT_DAYS = 7;

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
            'm365_enrollment_type' => 'Enrollment',
            'm365_os_version' => 'OS version',
            'm365_device_owner_type' => 'Ownership',
            'm365_defender_status' => 'Defender status',
            'm365_defender_version' => 'Defender version',
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

    /**
     * @param  ?array  $cometJobData  the asset page's own Comet job read for this render
     *                                (AssetController::show, CometJobService::getRecentJobs), or
     *                                null when this page made none (the tickets route). The
     *                                panel never reads Comet itself (M4B.2).
     */
    public static function build(Asset $asset, string $source, ?array $cometJobData = null): array
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
        if ($source === 'screenconnect' && $linked && $asset->screenconnect_synced_at?->lt(now()->subDays(self::SC_SILENT_DAYS))) {
            // The existing ScreenConnect rule, shown in the panel only: the rail marker counts
            // nothing for ScreenConnect (design-r3 §1).
            $attention[] = ['key' => 'events', 'title' => 'No events received since '.$asset->screenconnect_synced_at->diffForHumans(),
                'evidence' => 'Updated by ScreenConnect events'];
        }
        if ($source === 'm365' && $linked && $asset->m365_synced_at?->lt(now()->subDays(2))) {
            // The Security card's 2-day stale warning, moved here and shown in the panel only:
            // the rail marker never counted it.
            $attention[] = ['key' => 'stale', 'title' => 'Data may be stale (synced '.$asset->m365_synced_at->diffForHumans().')',
                'evidence' => 'updated by its scheduled sync'];
        }
        if ($source === 'comet' && $linked && ($cometJobData['job_state'] ?? null) === 'last_backup_failed') {
            // Panel-only, like the ScreenConnect rule above: the rail marker counts stored
            // Comet alerts only (design-r3 §1), never this page view's live posture.
            array_unshift($attention, ['key' => 'backup', 'title' => self::COMET_POSTURE['last_backup_failed'],
                'evidence' => 'reported by Comet']);
        }
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
            'chips' => self::chips($asset, $source, $state, $syncedAt, $cometJobData),
            'attention' => $attention,
            'all_alerts' => $moreAlerts,
            // Tactical: the failing-check list is read when the panel is opened (never on render).
            'checks_live' => $source === 'tactical' && $linked && filled($asset->tacticalAsset?->agent_id),
            // Local PSA history (no vendor call), so it shows whenever the asset is linked.
            'activity' => $source === 'tactical' && AssetSources::isLinked($asset, $source) ? self::tacticalActivity($asset) : null,
            // ScreenConnect: the stored session events (written by its webhook), no vendor call.
            'sc_events' => $source === 'screenconnect' && AssetSources::isLinked($asset, $source) ? self::screenConnectEvents($asset) : null,
            // Comet: a link to the Backup tab's job table (design-r3 §4); the table is not copied.
            'backup_jobs_link' => $source === 'comet' && $linked,
            // Servosity: no run outcome is read, so none is shown (M4B.3).
            'no_outcome_note' => $source === 'servosity' && $linked ? self::SERVOSITY_NO_OUTCOME : null,
            // Control D: the existing DNS activity read, offered as a button only; nothing is
            // read until it is clicked (M3S.1). The route refuses while switched off.
            'dns_activity' => $source === 'controld' && $linked && \App\Support\ControlDConfig::isAnalyticsConfigured(),
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
     *
     * Only Agent status takes "as of <synced_at>". synced_at is stamped by three
     * writers, and status is the one field all three write: the list sync, the
     * not-seen sweep (status + synced_at only) and the detail read / Sync now
     * (status + synced_at always; last_seen_at, needs_reboot and checks only when the
     * payload carries them; last_user and has_patches_pending never). So after a
     * sweep or a detail read, synced_at is not the age of last user, checks, reboot
     * or updates, and no column stores when each of those was last written. Those
     * chips show no age instead of a false one.
     */
    private static function tacticalChips(Asset $asset, ?CarbonInterface $syncedAt, bool $off): array
    {
        $ta = $asset->tacticalAsset;
        $kind = $syncedAt ? self::KIND_SNAPSHOT : 'none';
        $note = $syncedAt ? self::asOf($syncedAt) : 'no sync recorded';
        // A stored value with no truthful age: no "as of", and still marked as not
        // being updated when the source is switched off.
        $ageless = ['kind' => 'none', 'note' => $syncedAt ? ($off ? 'last known, not updated' : '') : 'no sync recorded'];
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
            ['label' => 'Last user', 'value' => filled($ta->last_user) ? (string) $ta->last_user : 'Not reported by Tactical'] + $ageless,
            ['label' => 'Checks', 'value' => $checks, 'key' => 'checks'] + $ageless,
            ['label' => 'Reboot', 'value' => $yesNo($ta->needs_reboot, 'Reboot needed', 'Not needed'), 'key' => 'reboot'] + $ageless,
            ['label' => 'Updates', 'value' => $yesNo($ta->has_patches_pending, 'Updates pending', 'None pending'), 'key' => 'updates'] + $ageless,
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

    private static function chips(Asset $asset, string $source, string $state, ?CarbonInterface $syncedAt, ?array $cometJobData = null): array
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
        if ($source === 'autoelevate' && $state !== AssetSources::STATE_NOT_CONFIGURED && AssetSources::isLinked($asset, $source)) {
            $chips = self::autoElevateChips($asset, $chips, $syncedAt, $off);
        }
        if ($source === 'comet' && $state !== AssetSources::STATE_NOT_CONFIGURED && AssetSources::isLinked($asset, $source)) {
            $chips = array_merge($chips, self::cometChips($cometJobData));
        }
        if ($source === 'litsrmm' && $state !== AssetSources::STATE_NOT_CONFIGURED && AssetSources::isLinked($asset, $source)) {
            $chips = array_merge($chips, self::litsrmmChips($asset, $off));
        }
        if (in_array($source, ['ninja', 'level'], true) && $state !== AssetSources::STATE_NOT_CONFIGURED && AssetSources::isLinked($asset, $source)) {
            $chips = array_merge($chips, self::ninjaLevelChips($asset, $source, $off));
        }
        if (in_array($source, ['controld', 'zorus', 'm365', 'screenconnect', 'servosity'], true)
            && $state !== AssetSources::STATE_NOT_CONFIGURED && AssetSources::isLinked($asset, $source)) {
            $own = match ($source) {
                'controld' => self::controlDChips($asset, $syncedAt),
                'zorus' => self::zorusChips($asset, $syncedAt),
                'm365' => self::m365Chips($asset, $syncedAt),
                'screenconnect' => self::screenConnectChips($asset),
                // Servosity stores no sync stamp, so its one flag carries no age.
                'servosity' => [self::agelessChip('Backup enabled', $asset->servosity_backup_enabled ? 'Yes' : 'No', 'enabled')],
            };
            foreach ($own as $i => $chip) {
                if ($off && $chip['kind'] === self::KIND_SNAPSHOT) {
                    $own[$i]['note'] .= '; '.$stale;
                } elseif ($off && $chip['kind'] === 'none' && $chip['note'] === '') {
                    $own[$i]['note'] = $stale;
                }
            }
            $chips = array_merge($chips, $own);
        }

        return $chips;
    }

    /**
     * Comet (design-r3 §2.2): backup posture, last success, last failure, all from the asset
     * page's own job read for this render (kind live). Their age is that read's
     * jobs_checked_at, never backup_synced_at, which the daily storage sync and Ninja backup
     * share and which dates none of these values.
     *
     * - No read on this page (null): no posture is claimed.
     * - A read that could not happen or failed: the posture is Unavailable with the Backup
     *   tab's reason copy for a missing username or an unreachable server, and no
     *   success/failure chips, so it never reads as "no jobs" (M5.3).
     *   A failed read wrote nothing, so it carries no age.
     *
     * @return list<array<string, mixed>>
     */
    private static function cometChips(?array $data): array
    {
        if ($data === null) {
            return [['label' => 'Backup posture', 'value' => 'Not read on this page', 'key' => 'posture', 'kind' => 'none', 'note' => '']];
        }
        $jobState = (string) ($data['job_state'] ?? 'unavailable');
        if ($jobState === 'unavailable' || ($data['state'] ?? null) === 'unavailable') {
            $note = match ($data['unavailable_reason'] ?? null) {
                // The Backup tab's wording (show.blade.php, Recent Backup Jobs, unavailable arm).
                'no_synced_username' => 'Job history cannot be looked up — this asset has no synced Comet username. ',
                'lookup_failed' => 'Backup job history unavailable — the Comet server could not be reached. ',
                default => '',
            }.'Backup state is unknown, not passing.';

            return [['label' => 'Backup posture', 'value' => self::COMET_POSTURE['unavailable'], 'key' => 'posture', 'kind' => 'none', 'note' => $note]];
        }

        $checked = filled($data['jobs_checked_at'] ?? null) ? \Illuminate\Support\Carbon::parse($data['jobs_checked_at']) : null;
        $live = $checked
            ? ['kind' => self::KIND_LIVE, 'note' => 'read from Comet '.($checked->diffInSeconds(now(), true) < 60 ? 'just now' : $checked->diffForHumans())]
            : ['kind' => 'none', 'note' => ''];
        $when = fn (?array $job) => filled($job['started'] ?? null)
            ? \Illuminate\Support\Carbon::parse($job['started'])->toAppTz()->format('M j, Y g:i A T')
            : 'Not reported by Comet';

        return [
            ['label' => 'Backup posture', 'value' => self::COMET_POSTURE[$jobState] ?? 'Unrecognised: '.$jobState, 'key' => 'posture'] + $live,
            ['label' => 'Last success', 'value' => $when($data['last_success'] ?? null), 'key' => 'success'] + $live,
            ['label' => 'Last failure', 'value' => $when($data['last_failure'] ?? null), 'key' => 'failure'] + $live,
        ];
    }

    /**
     * LITSRMM (design-r3 §2.2): online, last user, OS/build, last boot; last seen is the
     * shared Last contact chip. Every one of these is a SHARED assets column that LITSRMM,
     * Ninja, Level and others may write, so each note carries the inferred wording (Q-4 A,
     * M5L.1), never a statement that LITSRMM wrote the value.
     *
     * No chip takes an "as of" (S1-fix): litsrmm_synced_at is the age of none of these
     * values. rmm_online is also written by paths that never stamp litsrmm_synced_at
     * (the Tactical, Ninja and Level syncs, a Tactical release, a staff edit), and LITSRMM
     * writes os, last_user and last_seen_at only when its list carries them and
     * last_boot_at only when the inventory does.
     */
    private static function litsrmmChips(Asset $asset, bool $off): array
    {
        $nr = 'Not reported by LITSRMM';
        $inferred = AssetSources::INFERRED_WORDING;
        $stale = $off ? 'last known, not updated; ' : '';
        $online = $asset->rmm_online;
        $boot = $asset->last_boot_at;
        $ageless = fn (string $label, string $value, string $key) => ['label' => $label, 'value' => $value, 'key' => $key,
            'kind' => 'none', 'note' => $stale.$inferred];

        return [
            $ageless('Online', $online === null ? $nr : ($online ? 'Online' : 'Offline'), 'online'),
            $ageless('Last user', filled($asset->last_user) ? (string) $asset->last_user : $nr, 'last_user'),
            $ageless('OS', filled($asset->os) ? (string) $asset->os : $nr, 'os'),
            $ageless('Last boot', $boot instanceof CarbonInterface ? $boot->toAppTz()->format('M j, Y g:i A T') : $nr, 'last_boot'),
        ];
    }

    /**
     * Ninja and Level (design-r3 §2.2): Ninja online, last user, last boot; Level online,
     * last user. Last contact / last seen is the shared Last contact chip. Each is a SHARED
     * assets column that LITSRMM, Ninja, Level and others may write, so each note carries
     * the inferred wording (Q-4 A and its r3 extension, M4.3), never a statement that this
     * source wrote the value.
     *
     * No chip takes an "as of" (S1-fix): ninja_synced_at / level_synced_at is the age of
     * none of these values. rmm_online and last_user are also written by paths that never
     * stamp them (the other RMM syncs, the quick-look read, a staff edit), and Ninja and
     * Level keep the stored last_boot_at / last_user when their read does not carry one.
     */
    private static function ninjaLevelChips(Asset $asset, string $source, bool $off): array
    {
        $nr = 'Not reported by '.AssetSources::label($source);
        $stale = $off ? 'last known, not updated; ' : '';
        $ageless = fn (string $label, string $value, string $key) => ['label' => $label, 'value' => $value, 'key' => $key,
            'kind' => 'none', 'note' => $stale.AssetSources::INFERRED_WORDING];
        $online = $asset->rmm_online;
        $boot = $asset->last_boot_at;

        $chips = [
            $ageless('Online', $online === null ? $nr : ($online ? 'Online' : 'Offline'), 'online'),
            $ageless('Last user', filled($asset->last_user) ? (string) $asset->last_user : $nr, 'last_user'),
        ];
        if ($source === 'ninja') {
            $chips[] = $ageless('Last boot', $boot instanceof CarbonInterface ? $boot->toAppTz()->format('M j, Y g:i A T') : $nr, 'last_boot');
        }

        return $chips;
    }

    /**
     * A chip whose value was written together with the source's synced_at by every writer
     * of that column: "as of <synced_at>". With no synced_at there is no snapshot age.
     */
    private static function syncedChip(string $label, string $value, string $key, ?CarbonInterface $syncedAt): array
    {
        return ['label' => $label, 'value' => $value, 'key' => $key,
            'kind' => $syncedAt ? self::KIND_SNAPSHOT : 'none', 'note' => $syncedAt ? self::asOf($syncedAt) : 'no sync recorded'];
    }

    /** A stored value no stored time can truthfully date: no "as of". */
    private static function agelessChip(string $label, string $value, string $key): array
    {
        return ['label' => $label, 'value' => $value, 'key' => $key, 'kind' => 'none', 'note' => ''];
    }

    /**
     * Control D (design-r3 §2.2): device status, agent status, profile; last fetch is the
     * shared Last contact chip. Every Control D writer (the daily sync's
     * updateAssetFromDevice, its stale clear, link, unlink, and Sync now) writes all three
     * columns together with controld_synced_at, so each carries "as of <synced_at>".
     */
    private static function controlDChips(Asset $asset, ?CarbonInterface $syncedAt): array
    {
        $nr = 'Not reported by Control D';
        // The moved Security card's words: 0 Pending, 1 Active, 2/3 (documented) Disabled.
        $raw = $asset->controld_status;
        $status = $raw === null ? $nr : match ((string) $raw) {
            '0' => 'Pending',
            '1' => 'Active',
            '2', '3' => 'Disabled',
            default => 'Unrecognised: '.$raw,
        };
        $agentRaw = $asset->controld_agent_status;
        $agent = $agentRaw === null ? $nr : ((string) $agentRaw === '1' ? 'Connected' : 'Disconnected');

        return [
            self::syncedChip('Device status', $status, 'device', $syncedAt),
            self::syncedChip('Agent status', $agent, 'agent', $syncedAt),
            self::syncedChip('Profile', filled($asset->controld_profile_name) ? (string) $asset->controld_profile_name : $nr, 'profile', $syncedAt),
        ];
    }

    /**
     * Zorus (design-r3 §2.2): agent state, filtering, CyberSight, group; last seen is the
     * shared Last contact chip. Every Zorus writer (updateAssetFromEndpoint, the stale clear,
     * unlink) writes these columns together with zorus_synced_at.
     */
    private static function zorusChips(Asset $asset, ?CarbonInterface $syncedAt): array
    {
        $nr = 'Not reported by Zorus';
        $onOff = fn (?bool $v) => $v === null ? $nr : ($v ? 'Enabled' : 'Disabled');

        return [
            self::syncedChip('Agent state', filled($asset->zorus_agent_state) ? (string) $asset->zorus_agent_state : $nr, 'agent', $syncedAt),
            self::syncedChip('Filtering', $onOff($asset->zorus_filtering_enabled), 'filtering', $syncedAt),
            self::syncedChip('CyberSight', $onOff($asset->zorus_cybersight_enabled), 'cybersight', $syncedAt),
            self::syncedChip('Group', filled($asset->zorus_group_name) ? (string) $asset->zorus_group_name : $nr, 'group', $syncedAt),
        ];
    }

    /**
     * M365 / CIPP (design-r3 §2.2): compliance, Defender AV status (raw), last scan; Intune
     * last sync is the shared Last contact chip.
     *
     * Compliance is written with m365_synced_at by every CIPP writer, so it carries "as of
     * <synced_at>". A null m365_is_compliant is Unknown, never Compliant (M3S.4). Defender
     * status and last scan are written only when the read carried Defender data
     * (CippDeviceSyncService::updateAssetFromDevice), so synced_at is not their age: those
     * chips show no "as of".
     */
    private static function m365Chips(Asset $asset, ?CarbonInterface $syncedAt): array
    {
        $nr = 'Not reported by M365 (CIPP)';
        $compliant = $asset->m365_is_compliant;
        $compliance = match (true) {
            $compliant === true => 'Compliant',
            $compliant === false => $asset->m365_compliance_label ?? 'Non-Compliant',
            default => 'Unknown',
        };
        $scan = $asset->m365_last_scan_at;

        return [
            self::syncedChip('Compliance', $compliance, 'compliance', $syncedAt),
            self::agelessChip('Defender', filled($asset->m365_defender_status) ? (string) $asset->m365_defender_status : $nr, 'defender'),
            self::agelessChip('Last scan', $scan instanceof CarbonInterface ? $scan->diffForHumans() : $nr, 'scan'),
        ];
    }

    /**
     * ScreenConnect (design-r3 §2.2): online/offline and version. The online flag is written
     * only together with screenconnect_last_seen_at (a Connected or Disconnected event), so
     * the chip always shows that event's age beside it (psa-wedk, M6.2); a flag with no
     * event time is shown as Unknown. The version is written only when an event carries it,
     * so it shows no "as of".
     */
    private static function screenConnectChips(Asset $asset): array
    {
        $online = $asset->screenconnect_online;
        $at = $asset->screenconnect_last_seen_at;
        $onlineChip = $online === null || ! $at instanceof CarbonInterface
            ? ['label' => 'Online', 'value' => 'Unknown', 'key' => 'online', 'kind' => 'none', 'note' => 'last event not recorded']
            : ['label' => 'Online', 'value' => $online ? 'Online' : 'Offline', 'key' => 'online',
                'kind' => self::KIND_SNAPSHOT, 'note' => self::asOf($at)];

        return [
            $onlineChip,
            self::agelessChip('Client version', filled($asset->screenconnect_client_version) ? (string) $asset->screenconnect_client_version : 'Not reported by ScreenConnect', 'version'),
        ];
    }

    /**
     * The last SC_EVENTS_SHOWN stored session events (written by the ScreenConnect webhook),
     * newest first. No vendor call.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\ScreenConnectEvent>
     */
    private static function screenConnectEvents(Asset $asset): \Illuminate\Support\Collection
    {
        return \App\Models\ScreenConnectEvent::where('asset_id', $asset->id)
            ->orderByDesc('event_time')
            ->limit(self::SC_EVENTS_SHOWN)
            ->get();
    }

    /**
     * The AutoElevate health strip (design-r3 §2.2): elevation mode, "AutoElevate last
     * check-in" (the vendor's own claim, never "online") and link state.
     *
     * As-of ages follow what wrote them. Every AutoElevate writer stamps
     * autoelevate_synced_at together with BOTH the mode and the check-in (the hourly
     * link write and Sync now; the hourly clear nulls the link, so no chip is shown), so
     * only those two chips carry "as of <synced_at>". The link chip shows no age: the
     * link state comes from the link column and the hourly run's recorded outcome,
     * neither of which has a stored time of its own.
     *
     * @param  list<array<string, mixed>>  $chips  the shared chips (Last synced, Last contact)
     */
    private static function autoElevateChips(Asset $asset, array $chips, ?CarbonInterface $syncedAt, bool $off): array
    {
        $age = $syncedAt ? self::asOf($syncedAt) : 'no sync recorded';
        foreach ($chips as $i => $chip) {
            if ($chip['label'] === 'Last contact') {
                $chips[$i]['note'] .= $syncedAt ? ', '.$age : '';
            }
        }

        $mode = $asset->autoelevate_elevation_mode;
        $modeValue = match (true) {
            $mode === null => 'No mode reported',
            ! in_array($mode, self::ENUMS['autoelevate_elevation_mode'], true) => 'Unrecognised: '.$mode,
            $mode === 'audit' => 'AUDIT',
            default => $mode,
        };
        $link = \App\Services\AutoElevate\AutoElevateAssetSyncService::assetLinkState($asset);
        $linkNote = $link['state'] === 'linked' ? '' : $link['state'].($link['reason'] ? ' ('.$link['reason'].')' : '');

        $modeChip = ['label' => 'Elevation mode', 'value' => $modeValue, 'key' => 'mode',
            'kind' => $syncedAt ? self::KIND_SNAPSHOT : 'none', 'note' => $age];
        if ($off && $syncedAt) {
            $modeChip['note'] .= '; last known, not updated';
        }
        $linkChip = ['label' => 'Link', 'value' => $link['state'] === 'linked' ? 'Linked' : 'Not linked',
            'key' => 'link', 'kind' => 'none', 'note' => $linkNote];

        // Fixed order (design-r3 §2.1): state, last contact, then the source's own signals.
        return [...$chips, $modeChip, $linkChip];
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
                    'vendor_429', 'vendor_cooling_down', 'interactive_ceiling', 'user_throttled', 'recently_synced' => 'RATE LIMITED',
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
