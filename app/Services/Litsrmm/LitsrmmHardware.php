<?php

namespace App\Services\Litsrmm;

use DateTimeImmutable;
use DateTimeZone;

/**
 * What LITSRMM's agent reported about a machine's insides, as the asset
 * columns a Level-fed asset already carries. Pure: no I/O, no model.
 *
 * WHERE IT COMES FROM. `GET /v1/devices/{id}` carries an `inventory` object
 * keyed by category, each `{collectedAt, payload}`. Four categories are
 * served: hardware, disks, network, system. The list endpoint carries none of
 * this, which is why the detail call exists at all.
 *
 * ABSENT IS NOT NULL. A category that was never collected is left out of the
 * detail response entirely, and a device without the vendor's agent has no
 * inventory at all. columns() therefore returns ONLY the columns this read
 * actually knows, so a sync never overwrites a value the asset already holds
 * with a null that means "nobody asked".
 *
 * A malformed category is skipped rather than thrown: one bad payload must not
 * cost the asset the other three.
 */
final class LitsrmmHardware
{
    private const GIB = 1024 * 1024 * 1024;

    private function __construct(
        public readonly ?string $cpu,
        public readonly ?float $ramGb,
        public readonly ?string $diskSummary,
        public readonly ?string $ipAddress,
        public readonly ?DateTimeImmutable $lastBootAt,
        public readonly ?bool $needsReboot,
    ) {}

    public static function fromInventory(array $inventory): self
    {
        $hardware = self::payload($inventory, 'hardware');
        $disks = self::payload($inventory, 'disks');
        $network = self::payload($inventory, 'network');
        $system = self::payload($inventory, 'system');

        return new self(
            cpu: self::nonEmptyString($hardware['cpu'] ?? null),
            ramGb: self::ramGb($hardware['ramBytes'] ?? null),
            diskSummary: self::diskSummary($disks),
            ipAddress: self::ipAddress($network),
            lastBootAt: self::instant($system['bootTimeUtc'] ?? null),
            needsReboot: is_bool($system['pendingReboot'] ?? null) ? $system['pendingReboot'] : null,
        );
    }

    /**
     * Only the columns this read knows. Keys are asset column names.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return array_filter([
            'cpu' => $this->cpu,
            'ram_gb' => $this->ramGb,
            'disk_summary' => $this->diskSummary,
            'ip_address' => $this->ipAddress,
            'last_boot_at' => $this->lastBootAt,
            'needs_reboot' => $this->needsReboot,
        ], static fn ($value) => $value !== null);
    }

    /** The payload of one category, or null when absent or not a JSON object/list. */
    private static function payload(array $inventory, string $category): ?array
    {
        $entry = $inventory[$category] ?? null;

        if (! is_array($entry) || ! is_array($entry['payload'] ?? null)) {
            return null;
        }

        return $entry['payload'];
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function ramGb(mixed $bytes): ?float
    {
        if (! is_int($bytes) && ! is_float($bytes)) {
            return null;
        }

        return $bytes > 0 ? round($bytes / self::GIB, 2) : null;
    }

    /**
     * "C: 465 GB (18% free), D: 932 GB (50% free)". The same format and the
     * same rounding as LevelSyncService::resolveDiskSummary() (free percent is
     * taken from the ROUNDED gigabytes), so an asset moved from Level to
     * LITSRMM does not change its disk line for no reason.
     */
    private static function diskSummary(?array $volumes): ?string
    {
        if ($volumes === null || ! array_is_list($volumes)) {
            return null;
        }

        $parts = [];

        foreach ($volumes as $volume) {
            if (! is_array($volume)) {
                continue;
            }

            $total = $volume['totalBytes'] ?? null;

            if (! is_int($total) && ! is_float($total)) {
                continue;
            }

            $sizeGb = round($total / self::GIB);
            $part = "{$sizeGb} GB";

            $free = $volume['freeBytes'] ?? null;

            if ((is_int($free) || is_float($free)) && $sizeGb > 0) {
                $freeGb = round($free / self::GIB);
                $part .= ' ('.round(($freeGb / $sizeGb) * 100).'% free)';
            }

            $drive = self::nonEmptyString($volume['drive'] ?? null);
            $parts[] = $drive !== null ? "{$drive} {$part}" : $part;
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * The first IPv4 on an adapter that is up, then on any adapter. Never a
     * 169.254/16 link-local address: that is what Windows assigns when DHCP
     * failed, and it says nothing about where the machine is.
     */
    private static function ipAddress(?array $adapters): ?string
    {
        if ($adapters === null || ! array_is_list($adapters)) {
            return null;
        }

        $candidates = [];

        foreach ($adapters as $adapter) {
            if (! is_array($adapter) || ! is_array($adapter['ipv4'] ?? null)) {
                continue;
            }

            foreach ($adapter['ipv4'] as $ip) {
                if (is_string($ip)
                    && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                    && ! str_starts_with($ip, '169.254.')) {
                    $candidates[] = ['ip' => $ip, 'up' => ($adapter['up'] ?? null) === true];
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate['up']) {
                return $candidate['ip'];
            }
        }

        return $candidates[0]['ip'] ?? null;
    }

    /** ISO 8601 UTC ending in Z; anything else is dropped rather than guessed at. */
    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $value) !== 1) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
