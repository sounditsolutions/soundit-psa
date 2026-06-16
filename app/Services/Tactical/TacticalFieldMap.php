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
 * GiB convention: 1 GiB = 1073741824 bytes (binary), rounded to 1 decimal —
 * matching the pre-extraction behaviour the triage tests pin.
 */
class TacticalFieldMap
{
    private const BYTES_PER_GIB = 1073741824;

    /**
     * Convert a byte count (e.g. agent `total_ram`, disk `total`/`free`) to GiB,
     * rounded to one decimal. A null or zero reading => null (not "0 GB" — we
     * don't know the value, we shouldn't assert the box has no RAM).
     */
    public static function ramGbFromBytes(?int $bytes): ?float
    {
        if ($bytes === null || $bytes <= 0) {
            return null;
        }

        return round($bytes / self::BYTES_PER_GIB, 1);
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
     * Summarize a checks array to {failing, total} counts.
     *
     * Handles both Tactical shapes: the rich `getAgentChecks` row
     * (`check_result.status`) and the flat checks embedded in a `getAgent`
     * detail object (`status`). A check counts as failing when either reads
     * "failing".
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
