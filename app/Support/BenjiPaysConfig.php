<?php

namespace App\Support;

use App\Models\Setting;

class BenjiPaysConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('benjipays_api_key'),
            default => null,
        };
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_key'));
    }
}
