<?php

namespace App\Enums;

enum QuantityType: string
{
    case Fixed = 'fixed';
    case PerWorkstation = 'per_workstation';
    case PerServer = 'per_server';
    case PerWorkstationAndServer = 'per_workstation_and_server';
    case PerUser = 'per_user';
    case PerLicense = 'per_license';
    case PerLicenseType = 'per_license_type';
    case PerResellerLicenseType = 'per_reseller_license_type';
    case Overage = 'overage';
    case PerBackupStorageGb = 'per_backup_storage_gb';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed',
            self::PerWorkstation => 'Per Workstation',
            self::PerServer => 'Per Server',
            self::PerWorkstationAndServer => 'All Workstations + Servers',
            self::PerUser => 'Per User',
            self::PerLicense => 'Per License (all)',
            self::PerLicenseType => 'Per License Type',
            self::PerResellerLicenseType => 'Per Reseller License Type',
            self::Overage => 'Overage',
            self::PerBackupStorageGb => 'Backup Storage (GB)',
        };
    }

    /**
     * What this quantity type counts, as a plural noun for a client-facing
     * invoice line ("1–100 GB @ $1.00", "1–3 workstations @ $50.00").
     *
     * Used by graduated band labels, which are printed on the invoice the client
     * actually reads — so where the domain is known, name it. "units" is the
     * honest fallback where it is not: a Fixed line counts whatever the operator
     * decided it counts, and an Overage line counts divisor-scaled billing units.
     */
    public function unitNoun(): string
    {
        return match ($this) {
            self::Fixed => 'units',
            self::PerWorkstation => 'workstations',
            self::PerServer => 'servers',
            self::PerWorkstationAndServer => 'devices',
            self::PerUser => 'users',
            self::PerLicense => 'licenses',
            self::PerLicenseType => 'licenses',
            self::PerResellerLicenseType => 'licenses',
            self::Overage => 'units',
            self::PerBackupStorageGb => 'GB',
        };
    }

    public function isDynamic(): bool
    {
        return $this !== self::Fixed;
    }

    public function requiresLicenseType(): bool
    {
        return $this === self::PerLicenseType || $this === self::PerResellerLicenseType;
    }

    public function requiresOverageConfig(): bool
    {
        return $this === self::Overage;
    }
}
