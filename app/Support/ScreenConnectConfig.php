<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Str;

class ScreenConnectConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'base_url' => Setting::getValue('screenconnect_base_url'),
            'webhook_secret' => Setting::getValue('screenconnect_webhook_secret'),
            default => null,
        };
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('screenconnect_enabled', '0') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('base_url')) && ! empty(self::get('webhook_secret'));
    }

    /**
     * Both the switch and the config — the predicate the AI tool surface gates on.
     * OFF=OFF (CLAUDE.md): switching ScreenConnect off withdraws its screenconnect_*
     * tools from the model, not just the webhook intake.
     */
    public static function isAvailable(): bool
    {
        return self::isEnabled() && self::isConfigured();
    }

    public static function baseUrl(): ?string
    {
        return self::get('base_url');
    }

    public static function webhookSecret(): ?string
    {
        return self::get('webhook_secret');
    }

    public static function sessionUrl(string $sessionId): ?string
    {
        $base = self::baseUrl();
        if (! $base) {
            return null;
        }

        return rtrim($base, '/').'/Host#Access/All%20Machines/'.$sessionId;
    }

    public static function generateSecret(): string
    {
        return Str::random(48);
    }
}
