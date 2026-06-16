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
     * Summarize a getAgentChecks LIST to {failing, total} counts.
     *
     * This is for the getAgentChecks endpoint, which returns a LIST of checks each
     * carrying a rich `check_result.status` (with a flat `status` fallback). It is
     * NOT for the getAgent DETAIL `checks` field — that is already a pre-computed
     * summary dict ({total, passing, failing, …}) read off directly by the caller.
     *
     * @param  array<int, array<string, mixed>>  $checks
     * @return array{failing: int, total: int}
     */
    public static function checksSummary(array $checks): array
    {
        $failing = 0;

        foreach ($checks as $check) {
            if (! is_array($check)) {
                continue;
            }

            if (self::checkStatus($check) === 'failing') {
                $failing++;
            }
        }

        return ['failing' => $failing, 'total' => count($checks)];
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
     * @param  array<int, array<string, mixed>>  $disks
     * @return array<int, array{drive: ?string, total_gb: ?float, free_gb: ?float, percent_used: int|float|null}>
     */
    public static function mapDiskVolumes(array $disks): array
    {
        return collect($disks)->take(self::DISK_VOLUME_LIMIT)->map(fn ($d) => [
            'drive' => is_array($d) ? ($d['device'] ?? null) : null,
            'total_gb' => is_array($d) ? self::diskSizeToGb($d['total'] ?? null) : null,
            'free_gb' => is_array($d) ? self::diskSizeToGb($d['free'] ?? null) : null,
            'percent_used' => is_array($d) ? ($d['percent'] ?? null) : null,
        ])->values()->all();
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
