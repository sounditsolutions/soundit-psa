<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;

final class HdbApiConfig
{
    public const BASE_URL = 'https://cyl.helpdeskbuttons.com/api/v1';

    public static function apiKey(): ?string
    {
        try {
            $key = trim((string) Setting::getEncrypted('hdb_api_key'));
        } catch (DecryptException) {
            return null;
        }

        return $key === '' ? null : $key;
    }

    public static function isConfigured(): bool
    {
        return self::apiKey() !== null;
    }
}
