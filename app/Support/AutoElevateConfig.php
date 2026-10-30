<?php

namespace App\Support;

use App\Models\Setting;

class AutoElevateConfig
{
    /** The integration's on/off switch (Q-5 A). Default '1', so an install that never set it keeps today's behaviour. */
    public const ENABLED_SETTING = 'autoelevate_enabled';

    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('autoelevate_api_key'),
            default => null,
        };
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_key'));
    }

    /**
     * The switch alone (OFF means OFF). It does not ask whether a key is stored: the
     * hourly schedule keeps running without a key so that a missing key still fails
     * loudly, and only this switch stops it.
     */
    public static function isSwitchedOn(): bool
    {
        return Setting::getValue(self::ENABLED_SETTING, '1') === '1';
    }
}
