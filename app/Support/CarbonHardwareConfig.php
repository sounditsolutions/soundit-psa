<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Validation\ValidationException;

final class CarbonHardwareConfig
{
    public static function isEnabled(): bool
    {
        return Setting::getValue('carbon_hardware_ordering_enabled', '0') === '1' && CarbonMcpConfig::isLive();
    }

    public static function country(): string
    {
        return (string) Setting::getValue('carbon_hardware_country', 'USA');
    }

    public static function save(bool $enabled, string $country): void
    {
        if ($enabled && ! CarbonMcpConfig::isLive()) {
            throw ValidationException::withMessages(['enabled' => __('carbon_hardware.not_live')]);
        }
        Setting::setValue('carbon_hardware_ordering_enabled', $enabled ? '1' : '0');
        Setting::setValue('carbon_hardware_country', $country);
    }
}
