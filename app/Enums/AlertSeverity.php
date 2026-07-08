<?php

namespace App\Enums;

enum AlertSeverity: string
{
    case Critical = 'critical';
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::Error => 'Error',
            self::Warning => 'Warning',
            self::Info => 'Info',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Critical => 'bg-danger',
            self::Error => 'bg-danger',
            self::Warning => 'bg-warning text-dark',
            self::Info => 'bg-info text-dark',
        };
    }

    public function toTicketPriority(): \App\Enums\TicketPriority
    {
        return match ($this) {
            self::Critical => \App\Enums\TicketPriority::P1,
            self::Error => \App\Enums\TicketPriority::P2,
            self::Warning => \App\Enums\TicketPriority::P3,
            self::Info => \App\Enums\TicketPriority::P4,
        };
    }

    /**
     * Map vendor-specific severity strings to the unified enum.
     */
    public static function fromVendor(AlertSource $source, ?string $vendorSeverity): self
    {
        $normalized = strtolower(trim($vendorSeverity ?? ''));

        return match ($source) {
            AlertSource::Tactical => match ($normalized) {
                'error' => self::Error,
                'warning' => self::Warning,
                'info', 'informational' => self::Info,
                default => self::Warning,
            },
            AlertSource::Ninja => match ($normalized) {
                'critical' => self::Critical,
                'major' => self::Error,
                'moderate' => self::Warning,
                'minor' => self::Info,
                default => self::Warning,
            },
            AlertSource::Comet => self::Error, // Comet failures are always errors
            AlertSource::Huntress => match ($normalized) {
                'critical' => self::Critical,
                'high' => self::Error,
                'low' => self::Warning,
                default => self::Error,
            },
        };
    }
}
