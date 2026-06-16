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
}
