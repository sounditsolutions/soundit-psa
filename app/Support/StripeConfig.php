<?php

namespace App\Support;

use App\Models\Setting;

class StripeConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'secret_key' => Setting::getEncrypted('stripe_secret_key'),
            'mode' => Setting::getValue('stripe_mode', 'test'),
            default => null,
        };
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('stripe_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('secret_key'));
    }

    public static function baseUrl(): string
    {
        return 'https://api.stripe.com/v1';
    }
}
