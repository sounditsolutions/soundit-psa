<?php

namespace App\Support;

use App\Models\Setting;

class PlivoConfig
{
    /**
     * Fallback hold music played to the remote caller when an operator has not
     * configured their own. Plivo's public sample track — operators should point
     * `plivo_hold_music_url` at their own branded audio in Settings.
     */
    public const DEFAULT_HOLD_MUSIC_URL = 'https://s3.amazonaws.com/plivocloud/music.mp3';

    public static function isEnabled(): bool
    {
        return Setting::getValue('plivo_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('auth_id')) && ! empty(self::get('auth_token'));
    }

    /**
     * URL of the audio file played to the caller while a softphone call is on
     * hold. Falls back to Plivo's sample track when unset so hold works out of
     * the box. Plivo's servers fetch this URL, so it must be publicly reachable.
     */
    public static function holdMusicUrl(): string
    {
        $url = trim((string) self::get('hold_music_url'));

        return $url !== '' ? $url : self::DEFAULT_HOLD_MUSIC_URL;
    }

    private static array $map = [
        'auth_id' => ['plivo_auth_id', 'services.plivo.auth_id', false],
        'auth_token' => ['plivo_auth_token', 'services.plivo.auth_token', true],
        'webhook_secret' => ['plivo_webhook_secret', 'services.plivo.webhook_secret', true],
        'did_number' => ['plivo_did_number', 'services.plivo.did_number', false],
        'app_id' => ['plivo_app_id', 'services.plivo.app_id', false],
        'hold_music_url' => ['plivo_hold_music_url', 'services.plivo.hold_music_url', false],
    ];

    public static function get(string $key): ?string
    {
        if (! isset(self::$map[$key])) {
            return config("services.plivo.{$key}");
        }

        [$settingKey, $configKey, $encrypted] = self::$map[$key];

        return Setting::settingOrConfig($settingKey, $configKey, $encrypted);
    }
}
