<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Static config helper for the PandaDoc integration (agreements + e-signatures).
 * Mirrors the StripeConfig / T2TConfig pattern: reads from the encrypted
 * settings table, never from hardcoded tenant values.
 */
class PandaDocConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('pandadoc_api_key'),
            'webhook_secret' => Setting::getEncrypted('pandadoc_webhook_secret'),
            default => null,
        };
    }

    public static function apiKey(): ?string
    {
        return self::get('api_key');
    }

    public static function webhookSecret(): ?string
    {
        return self::get('webhook_secret');
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('pandadoc_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::apiKey());
    }

    public static function baseUrl(): string
    {
        return 'https://api.pandadoc.com';
    }
}
