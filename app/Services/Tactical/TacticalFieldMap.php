<?php

namespace App\Services\Tactical;

use Illuminate\Support\Carbon;

/**
 * Shared field mappers for Tactical agent telemetry (amendment E).
 *
 * total_ram->GB, boot_time->uptime, and the checks failing/total summary were
 * inline-duplicated across TriageToolExecutor, ContextBuilder, and quickLook,
 * which risked the AI seeing one ram_gb/check-status in a tool result and a
 * different one in the context block. This is the single source of truth,
 * consumed by TriageToolExecutor + TacticalInsightService +
 * TacticalDeviceSyncService.
 *
 * RAM convention: Tactical's agent `total_ram` is an INTEGER COUNT OF GIGABYTES
 * (source v1.5.0 + live VM 105), NOT a byte count — so it maps DIRECTLY to a GB
 * float, no 1073741824 division. Disk total/used/free are a SEPARATE shape —
 * formatted strings ("19.3 GB"/TB/MB) — handled by diskSizeToGb() below, also a
 * direct parse with no byte division.
 */
class TacticalFieldMap
{
    /**
     * Map an agent `total_ram` reading to a GB float. `total_ram` is already a
     * gigabyte COUNT (e.g. 4 => 4.0 GB), so this is a direct cast, not a bytes->GB
     * conversion. A null or zero reading => null (not "0 GB" — we don't know the
     * value, we shouldn't assert the box has no RAM).
     */
    public static function ramGb(int|float|string|null $totalRam): ?float
    {
        if ($totalRam === null || $totalRam === '' || ! is_numeric($totalRam)) {
            return null;
        }

        $gb = (float) $totalRam;

        return $gb > 0 ? $gb : null;
    }

    /**
     * Parse a Tactical disk size STRING ("19.3 GB" / "2.0 TB" / "512.0 MB") to a
     * GB float, rounded to one decimal. The agent `disks` total/used/free fields
     * are pre-formatted strings (source v1.5.0 + live VM 105), NOT byte counts. A
     * bare unitless number is read as GB. null/empty/unparseable => null.
     */
    public static function diskSizeToGb(?string $size): ?float
    {
        if ($size === null) {
            return null;
        }

        if (! preg_match('/(-?\d+(?:\.\d+)?)\s*([KMGTP]?B)?/i', trim($size), $m)) {
            return null;
        }

        $value = (float) $m[1];
        $unit = strtoupper($m[2] ?? 'GB');

        $gb = match ($unit) {
            'PB' => $value * 1024 * 1024,
            'TB' => $value * 1024,
            'GB', '' => $value,
            'MB' => $value / 1024,
            'KB' => $value / (1024 * 1024),
            'B' => $value / (1024 * 1024 * 1024),
            default => $value,
        };

        return round($gb, 1);
    }

    /**
     * Format a human uptime string ("3d 5h", "42m") from a boot time.
     *
     * Accepts a unix timestamp (the agent detail's `boot_time` is epoch seconds)
     * or any Carbon-parseable value. Returns null when absent/unparseable.
     * Format matches TriageToolExecutor: days+hours when up >= 1h, else minutes;
     * a zero-component is omitted (so "5h" not "0d 5h").
     */
    public static function uptimeFromBootTime(int|string|null $bootTime): ?string
    {
        if ($bootTime === null || $bootTime === '' || $bootTime === 0 || $bootTime === '0') {
            return null;
        }

        try {
            $boot = is_int($bootTime) ? Carbon::createFromTimestamp($bootTime) : Carbon::parse($bootTime);
        } catch (\Throwable) {
            return null;
        }

        $diff = $boot->diff(Carbon::now());

        $parts = [];
        if ($diff->days > 0) {
            $parts[] = $diff->days.'d';
        }
        if ($diff->h > 0) {
            $parts[] = $diff->h.'h';
        }
        if (empty($parts)) {
            $parts[] = $diff->i.'m';
        }

        return implode(' ', $parts);
    }

    /**
     * Summarize a getAgentChecks LIST to explicit per-status counts.
     *
     * This is for the getAgentChecks endpoint, which returns a LIST of checks each
     * carrying a rich `check_result.status` (with a flat `status` fallback). It is
     * NOT for the getAgent DETAIL `checks` field — that is a pre-computed summary
     * dict mapped by checksFromAgentSummary() below.
     *
     * Status vocabulary is the vendor's own (CheckStatus, tacticalrmm
     * constants.py:181-184): passing | failing | pending. A check that has NEVER
     * reported serializes `check_result: {}` (checks/serializers.py:30-34 —
     * Check.check_result defaults to {} at checks/models.py:160), which resolves
     * to "unknown" here. Every count is EXPLICIT — `passing` is only ever a
     * counted status=passing, never inferred by subtraction: a pending or
     * never-reporting check is evidence of NOTHING (psa-0pb9m revise).
     *
     * @param  array<int, array<string, mixed>>  $checks
     * @return array{failing: int, passing: int, pending: int, unknown: int, total: int}
     */
    public static function checksSummary(array $checks): array
    {
        $counts = ['failing' => 0, 'passing' => 0, 'pending' => 0, 'unknown' => 0, 'total' => 0];

        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }

            $counts['total']++;
            $status = self::checkStatus($check);
            match ($status) {
                'passing' => $counts['passing']++,
                'failing' => $counts['failing']++,
                'pending' => $counts['pending']++,
                default => $counts['unknown']++,
            };
        }

        return $counts;
    }

    /**
     * Map the getAgent DETAIL / agents-list `checks` SUMMARY DICT to the same
     * {total, failing, passing} triple the coverage classifier consumes.
     *
     * Producer: calculate_agent_checks (amidaware/tacticalrmm
     * api/tacticalrmm/agents/utils.py:146-184 @ 632a37a4, 2026-07-24) emits
     * {total, passing, failing, warning, info, has_failing_checks} where
     * `failing`/`warning`/`info` are the SEVERITY SPLIT of status=failing
     * results — so the list-endpoint "failing" count equals failing+warning+info
     * here, and we sum them so dict-derived and list-derived counts agree.
     *
     * `passing` is NEVER mapped — it is always null (psa-0pb9m R2, all four
     * review lenses). The producer counts a check with NO CheckResult row at
     * all as passing (`not hasattr(check.check_result, "status")`,
     * utils.py:150-155), so the vendor aggregate MANUFACTURES a pass for a
     * never-reporting check — the exact device this bead exists for (a Mac
     * whose one check never runs) arrives as {total: 1, passing: 1, failing: 0}
     * and would classify VERIFIED. A vendor claim that cannot distinguish
     * "passed" from "never ran" is not passing evidence; dict-derived reads
     * classify UNKNOWN (or unverified when failing >= total proves nothing
     * passes), and VERIFIED stays reserved for per-check reads
     * (checksSummary() over getAgentChecks, where every count is an explicit
     * status).
     *
     * Also documented (verified in source @ 632a37a4): the AGENTS-LIST
     * serializer reads this dict from a periodic-task cache
     * (agents/serializers.py AgentTableSerializer.get_checks); a cold cache
     * yields an all-zeros dict, which classifies as "none"/UNMONITORED —
     * over-alarming, never false-clean.
     *
     * @param  array<string, mixed>|null  $dict
     * @return array{total: ?int, failing: ?int, passing: null}
     */
    public static function checksFromAgentSummary(?array $dict): array
    {
        if (! is_array($dict) || ! isset($dict['total']) || ! is_numeric($dict['total'])) {
            return ['total' => null, 'failing' => null, 'passing' => null];
        }

        $failing = (int) ($dict['failing'] ?? 0)
            + (int) ($dict['warning'] ?? 0)
            + (int) ($dict['info'] ?? 0);

        return [
            'total' => (int) $dict['total'],
            'failing' => $failing,
            'passing' => null,
        ];
    }

    /**
     * The status of a single check, normalized across both shapes. Prefers the
     * rich `check_result.status` (getAgentChecks), falls back to a flat `status`
     * (getAgent detail), else "unknown".
     *
     * @param  array<string, mixed>  $check
     */
    public static function checkStatus(array $check): string
    {
        return $check['check_result']['status'] ?? $check['status'] ?? 'unknown';
    }

    // ── checks coverage (psa-0pb9m) ──────────────────────────────────────────

    /**
     * The one coverage-semantics note, shared by every AI-facing Tactical
     * surface (MCP read toolset + triage tool loop) — the sibling of the
     * psa-47vxh freshness_note. Presence in RMM is NOT coverage, and coverage
     * is never inferred by subtraction: "verified" requires an explicitly
     * counted passing check.
     */
    public const COVERAGE_NOTE = 'checks_coverage semantics: "verified" = at least one check is EXPLICITLY passing right now (monitoring demonstrably works) — only the live per-check read can prove this; "unverified" = checks exist but NONE is currently passing (all failing, or none reporting — a real incident or a broken/wrong-platform/never-running check; needs inspection); "none" = ZERO checks configured, the device is UNMONITORED (do not read it as healthy); "unknown" = a passing check can neither be demonstrated nor ruled out — snapshot and agent-summary reads are ALWAYS at best unknown, because Tactical\'s aggregate counts a never-reporting check as passing and so carries no passing evidence. "none" means unmonitored; "unverified" means coverage cannot currently be demonstrated — they are different facts. Only "verified" is coverage. Use tactical_get_device_checks for the authoritative per-check view (status, last_run, platform_mismatch reason).';

    /** At least one check is EXPLICITLY passing — monitoring demonstrably works. */
    public const COVERAGE_VERIFIED = 'verified';

    /**
     * Checks exist but NONE is currently passing (all failing, or nothing
     * reporting) — indistinguishable from a broken or wrong-platform check.
     * Nothing currently demonstrates working monitoring.
     */
    public const COVERAGE_UNVERIFIED = 'unverified';

    /** Zero checks configured — nothing verifies this device at all. */
    public const COVERAGE_NONE = 'none';

    /**
     * A passing check can neither be demonstrated nor ruled out — the checks
     * signal was never read, or the read carries no passing evidence (e.g. a
     * snapshot from before passing-count sync). NOT coverage.
     */
    public const COVERAGE_UNKNOWN = 'unknown';

    /**
     * Classify check counts into a coverage state (psa-0pb9m). The point:
     * "RMM shows the device" must never be readable as "something verifies the
     * device". VERIFIED requires an explicitly counted passing check —
     * failing < total is NOT evidence (the gap can be pending / never-reporting
     * / warning-severity failures, per the vendor shapes in checksSummary()).
     * A Mac whose ONE check always fails is UNVERIFIED, not unhealthy-but-
     * covered; a Mac with zero checks is UNMONITORED, not clean. Single source
     * of truth for the MCP payloads, EndpointInsight, the AI context provider,
     * and the asset-page panels.
     *
     * $passing null means "no passing evidence available" (legacy snapshot,
     * malformed payload): classified UNKNOWN — except when every check is
     * failing, which PROVES nothing passes → UNVERIFIED.
     */
    public static function checksCoverage(?int $total, ?int $failing, ?int $passing = null): string
    {
        if ($total === null) {
            return self::COVERAGE_UNKNOWN;
        }

        if ($total <= 0) {
            return self::COVERAGE_NONE;
        }

        if ($passing !== null) {
            return $passing > 0 ? self::COVERAGE_VERIFIED : self::COVERAGE_UNVERIFIED;
        }

        // No passing evidence. All-failing still proves nothing passes;
        // anything else is honestly unknown — never verified-by-subtraction.
        if ($failing !== null && $failing >= $total) {
            return self::COVERAGE_UNVERIFIED;
        }

        return self::COVERAGE_UNKNOWN;
    }

    /**
     * Human/AI-facing one-line summary for a checks count triple. Keeps the
     * legacy "{failing} failing / {total} total" spine for verified coverage
     * (now annotated with the explicit passing count); makes every dangerous
     * shape explicit instead of clean-looking: zero checks → UNMONITORED,
     * all failing → coverage unverified, none passing → coverage unverified,
     * no passing evidence → coverage unknown.
     */
    public static function checksSummaryLine(?int $total, ?int $failing, ?int $passing = null): ?string
    {
        $coverage = self::checksCoverage($total, $failing, $passing);

        if ($coverage === self::COVERAGE_UNKNOWN) {
            if ($total === null || $total <= 0) {
                return null;
            }

            // Read the signal, but no passing evidence — say so rather than
            // rendering a clean-looking count.
            return "{$total} configured - passing count unavailable (coverage unknown: only the live per-check read proves a pass)";
        }

        if ($coverage === self::COVERAGE_NONE) {
            return 'no checks configured - UNMONITORED (nothing verifies this device)';
        }

        if ($coverage === self::COVERAGE_UNVERIFIED) {
            if ($failing !== null && $failing >= $total) {
                return "{$failing} failing / {$total} total - ALL checks failing (coverage unverified: real incident or broken/wrong-platform check)";
            }

            $notReporting = max(0, $total - (int) $failing - (int) $passing);

            return "0 passing / {$total} total ({$failing} failing, {$notReporting} not reporting) - NO check currently passing (coverage unverified)";
        }

        $line = "{$failing} failing / {$total} total";
        if ($passing !== null) {
            // A partial read must name its gap: checks that are neither
            // failing nor explicitly passing are NOT reporting, and "1 failing
            // / 8 total (5 passing)" silently hiding 2 such checks is the
            // false-clean lead psa-0pb9m R2 blocked.
            $notReporting = max(0, $total - (int) $failing - $passing);
            $line .= $notReporting > 0
                ? " ({$passing} passing, {$notReporting} not reporting)"
                : " ({$passing} passing)";
        }

        return $line;
    }

    /**
     * Normalize a getSoftware (GET software/{agent}/) payload to a flat list of
     * software rows.
     *
     * Tactical serializes the per-agent inventory as a WRAPPER OBJECT —
     * {id, agent, software: [...]} — with the rows under the `software` key
     * (the same shape TacticalPanelData::software() unwraps). Mapping the
     * wrapper directly yields exactly three phantom {name: "Unknown"} rows,
     * one per wrapper key. A bare list of rows passes through; an agent with
     * no inventory record returns [] upstream; any other object shape is
     * treated as no inventory rather than mapped into placeholder rows. This
     * is the single source of truth, consumed by TriageToolExecutor and
     * TacticalReadOnlyToolset.
     *
     * @param  array<mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public static function softwareRows(array $payload): array
    {
        if (isset($payload['software']) && is_array($payload['software'])) {
            $payload = $payload['software'];
        }

        if (! array_is_list($payload)) {
            return [];
        }

        return array_values(array_filter($payload, 'is_array'));
    }

    /**
     * psa-843: the truncation dialect for the per-device list reads.
     *
     * These reads used to return a bare array cut at a fixed size with no
     * marker and no total. The software read is the worst case because it
     * sorts ALPHABETICALLY first: on a machine with more than 50 packages the
     * list stops mid-alphabet, so a package below the cut is indistinguishable
     * from a package that is not installed. That is a WRONG answer, not a
     * short one — the same class of defect as a truncated check set
     * misstating coverage (psa-0pb9m), and the reason getDeviceChecks()
     * already counts over the full set before its slice.
     *
     * The contract every caller of listEnvelope() gets: `total` counted over
     * the full set BEFORE the slice, `count` actually returned, an explicit
     * `truncated`, the `limit` in force, a `truncation_note` in plain words
     * when and only when rows were dropped, and the rows under their own key.
     * Shared verbatim by TacticalReadOnlyToolset (the Chet data surface) and
     * TriageToolExecutor (the triage loop) so the two copies cannot drift
     * again — they were byte-identical duplicates when this was filed.
     */
    public static function truncationNote(string $subject): string
    {
        return "This list of {$subject} is TRUNCATED — `total` is the real number on the device and `count` is how many are shown. "
            .'An entry that does not appear here MAY STILL BE PRESENT: absence from a truncated list is NOT evidence of absence on the device. '
            .'Raise `limit` (or narrow the query) and read again before reporting anything as missing.';
    }

    /**
     * Wrap mapped rows in the psa-843 truncation envelope.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function listEnvelope(string $key, array $rows, int $total, int $limit, string $subject): array
    {
        $count = count($rows);

        return [
            'count' => $count,
            'total' => $total,
            'limit' => $limit,
            'truncated' => $total > $count,
            'truncation_note' => $total > $count ? self::truncationNote($subject) : null,
            $key => $rows,
        ];
    }

    /**
     * psa-843: the caller-settable bound for the per-device list reads,
     * clamped to [1, $max]. Semantics are deliberately IDENTICAL to
     * TacticalReadOnlyToolset::limit() (the house idiom for that class's paged
     * tools) — this is that function hoisted, not a second dialect. It lives
     * here because the triage loop needs the same answer for the same input
     * and the two device-read implementations were byte-identical duplicates
     * when this was filed; a shared function is the only thing that keeps them
     * from drifting.
     *
     * Absent / blank / non-numeric / zero / negative all fall back to
     * $default. Notably 0 must NOT clamp up to 1 or down to an empty list: an
     * empty list reads as "nothing installed", which is the exact wrong answer
     * this issue exists to stop.
     */
    public static function listLimit(mixed $raw, int $default, int $max): int
    {
        $limit = null;

        if ($raw !== null && $raw !== '') {
            if (is_int($raw) && $raw > 0) {
                $limit = $raw;
            } elseif (is_numeric($raw) && (int) $raw > 0) {
                $limit = (int) $raw;
            }
        }

        return min($limit ?? $default, $max);
    }

    /** Cap the per-agent mapped volume/adapter lists so a pathological agent can't blow the response. */
    public const DISK_VOLUME_LIMIT = 10;

    public const NETWORK_ADAPTER_LIMIT = 20;

    /**
     * Map Tactical's disk volumes (getAgent `disks`) to a structured shape for the
     * lowDisk flag + the UI. total/used/free arrive as FORMATTED STRINGS
     * ("X.Y GB"/TB/MB) and percent as an INT (source v1.5.0 + live VM 105). This is
     * the single source of truth, consumed by TacticalInsightService (the lowDisk
     * flag) and TacticalPanelData (the storage panel).
     *
     * psa-843: $limit is the caller-settable bound for the device READ surfaces,
     * which must be able to hand back the rows past the cut — a volume the
     * caller cannot reach is the same wrong answer as a package below the
     * software cut. It defaults to DISK_VOLUME_LIMIT so the UI callers above
     * keep the exact list they have always had; only the two MCP device reads
     * pass anything else.
     *
     * @param  array<int, array<string, mixed>>  $disks
     * @return array<int, array<string, mixed>>
     */
    public static function mapDiskVolumes(array $disks, bool $includeFilesystemType = false, ?int $limit = null): array
    {
        return collect($disks)->take($limit ?? self::DISK_VOLUME_LIMIT)->map(function ($d) use ($includeFilesystemType) {
            $volume = [
                'drive' => is_array($d) ? ($d['device'] ?? null) : null,
                'total_gb' => is_array($d) ? self::diskSizeToGb($d['total'] ?? null) : null,
                'free_gb' => is_array($d) ? self::diskSizeToGb($d['free'] ?? null) : null,
                'percent_used' => is_array($d) ? ($d['percent'] ?? null) : null,
            ];

            if ($includeFilesystemType) {
                $volume['fstype'] = is_array($d) ? ($d['fstype'] ?? null) : null;
            }

            return $volume;
        })->values()->all();
    }

    /**
     * Map a getAgent payload's network telemetry to {public_ip, local_ips,
     * adapters[]}. wmi_detail.network_config is a list of Windows adapter dicts;
     * only the IP-ENABLED ones (non-empty IPAddress) are carried (a disabled/
     * virtual/loopback adapter has no IPAddress and is noise). This is the single
     * source of truth, consumed by TriageToolExecutor (the AI network tool) and
     * TacticalPanelData (the network panel). Field shape: source v1.5.0 + live read.
     *
     * @param  array<string, mixed>  $agent
     * @return array{public_ip: ?string, local_ips: ?string, adapters: array<int, array{caption: string, ip_addresses: array<int, mixed>, subnets: array<int, mixed>, gateway: array<int, mixed>, dns_servers: array<int, mixed>, dhcp_enabled: mixed, mac_address: ?string}>}
     */
    public static function mapNetwork(array $agent): array
    {
        $networkConfigs = $agent['wmi_detail']['network_config'] ?? [];

        $adapters = collect(is_array($networkConfigs) ? $networkConfigs : [])
            ->filter(fn ($n) => is_array($n) && ! empty($n['IPAddress']))
            ->take(self::NETWORK_ADAPTER_LIMIT)
            ->map(fn ($n) => [
                'caption' => $n['Caption'] ?? $n['Description'] ?? 'Unknown',
                'ip_addresses' => $n['IPAddress'] ?? [],
                'subnets' => $n['IPSubnet'] ?? [],
                'gateway' => $n['DefaultIPGateway'] ?? [],
                'dns_servers' => $n['DNSServerSearchOrder'] ?? [],
                'dhcp_enabled' => $n['DHCPEnabled'] ?? false,
                'mac_address' => $n['MACAddress'] ?? null,
            ])
            ->values()
            ->toArray();

        return [
            'public_ip' => $agent['public_ip'] ?? null,
            'local_ips' => $agent['local_ips'] ?? null,
            'adapters' => $adapters,
        ];
    }
}
