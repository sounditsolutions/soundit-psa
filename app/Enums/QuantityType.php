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
