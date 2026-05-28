<?php

namespace App\Support;

use App\Models\Setting;

class PrintixConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'client_id' => Setting::getEncrypted('printix_client_id'),
            'client_secret' => Setting::getEncrypted('printix_client_secret'),
            'partner_id' => Setting::getValue('printix_partner_id'),
            default => null,
        };
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('printix_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('client_id'))
            && ! empty(self::get('client_secret'))
            && ! empty(self::get('partner_id'));
    }
}
