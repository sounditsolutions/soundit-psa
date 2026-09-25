<?php

namespace App\Support;

use App\Models\Setting;

/** No environment fallback: deleting/revoking a key must not restore an old one. */
final class ContactIntakeConfig
{
    public static function enabled(): bool
    {
        return Setting::getValue('contact_intake_enabled', '0') === '1';
    }

    public static function ownerId(): ?int
    {
        $configured = Setting::getValue('contact_intake_owner_id');
        if ($configured !== null && $configured !== '') {
            return \App\Models\User::whereKey($configured)->where('is_active', true)->value('id');
        }
        // Default Chet is resolved only when unique; never select an arbitrary user.
        $users = \App\Models\User::where('name', 'Chet')->where('is_active', true)->pluck('id');

        return $users->count() === 1 ? $users->sole() : null;
    }

    public static function signingKey(string $keyId): ?string
    {
        $keys = json_decode(Setting::getEncrypted('contact_intake_keys', '{}'), true, 16, JSON_THROW_ON_ERROR);
        $key = $keys[$keyId] ?? null;
        if (! is_array($key) || ($key['revoked'] ?? true) !== false) {
            return null;
        }
        $secret = $key['secret'] ?? null;

        return is_string($secret) && strlen($secret) >= 32 ? $secret : null;
    }
}
