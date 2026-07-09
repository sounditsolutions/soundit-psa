<?php

namespace App\Enums;

/**
 * Coarse health band derived from an asset's 0-100 health score.
 *
 * Unknown is a first-class state: an asset with no monitoring signals we can
 * read (no RMM link, no alerts, no backup, no M365 data) has a null score and
 * grades Unknown rather than a misleading 100. See AssetHealthService.
 */
enum AssetHealthGrade: string
{
    case Good = 'good';
    case Fair = 'fair';
    case Poor = 'poor';
    case Unknown = 'unknown';

    /** Score at/above which a device is considered healthy. */
    public const GOOD_THRESHOLD = 85;

    /** Score below which a device is considered unhealthy (the "unhealthy" filter). */
    public const FAIR_THRESHOLD = 60;

    public static function fromScore(?int $score): self
    {
        if ($score === null) {
            return self::Unknown;
        }

        return match (true) {
            $score >= self::GOOD_THRESHOLD => self::Good,
            $score >= self::FAIR_THRESHOLD => self::Fair,
            default => self::Poor,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Good => 'Good',
            self::Fair => 'Fair',
            self::Poor => 'Poor',
            self::Unknown => 'Unknown',
        };
    }

    /** Bootstrap badge class for the score pill. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Good => 'bg-success',
            self::Fair => 'bg-warning text-dark',
            self::Poor => 'bg-danger',
            self::Unknown => 'bg-secondary',
        };
    }

    /** Hex colour for the score ring / inline accents. */
    public function color(): string
    {
        return match ($this) {
            self::Good => '#198754',
            self::Fair => '#fd7e14',
            self::Poor => '#dc3545',
            self::Unknown => '#6c757d',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Good => 'bi-heart-pulse-fill',
            self::Fair => 'bi-exclamation-circle-fill',
            self::Poor => 'bi-exclamation-triangle-fill',
            self::Unknown => 'bi-question-circle-fill',
        };
    }
}
