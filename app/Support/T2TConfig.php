<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

class T2TConfig
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('t2t_api_key'),
            'callback_url' => Setting::getValue('t2t_callback_url'),
            'system_user_id' => Setting::getValue('t2t_system_user_id'),
            default => null,
        };
    }

    public static function isEnabled(): bool
    {
        return Setting::getValue('t2t_enabled', '1') === '1';
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_key'));
    }

    /**
     * Get the user ID for audit trail attribution on API-created actions.
     * Falls back to the first admin user if not configured.
     */
    public static function systemUserId(): ?int
    {
        $configured = self::get('system_user_id');

        if ($configured) {
            return (int) $configured;
        }

        return User::orderBy('id')->value('id');
    }

    /**
     * Generate a cryptographically secure private key (hex, colon-free).
     */
    public static function generatePrivateKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate the full CW-format API key string for T2T.
     *
     * Format: CompanyId+PublicKey:PrivateKey
     * Example: SoundIT+t2tpub8a3f:9c4e...
     *
     * T2T pastes this entire string into its "API Key" field.
     * Our middleware validates only the PrivateKey portion.
     *
     * @return array{full: string, private: string}
     */
    public static function generateApiKey(): array
    {
        $companyId = Setting::getValue('t2t_company_id', 'SoundPSA');
        $publicKey = bin2hex(random_bytes(6));
        $privateKey = self::generatePrivateKey();

        return [
            'full' => "{$companyId}+{$publicKey}:{$privateKey}",
            'private' => $privateKey,
        ];
    }
}
