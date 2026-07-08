<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    public static function setValue(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function getEncrypted(string $key, mixed $default = null): mixed
    {
        $value = static::getValue($key);
        if ($value === null) {
            return $default;
        }

        return Crypt::decryptString($value);
    }

    public static function setEncrypted(string $key, mixed $value): void
    {
        static::setValue($key, Crypt::encryptString($value));
    }

    /**
     * Read from Settings first, fall back to config() (which reads .env).
     * Catches exceptions for fresh deploys where the settings table may not exist.
     */
    public static function settingOrConfig(string $settingKey, string $configKey, bool $encrypted = false): mixed
    {
        try {
            $value = $encrypted
                ? static::getEncrypted($settingKey)
                : static::getValue($settingKey);

            return $value ?? config($configKey);
        } catch (\Throwable) {
            return config($configKey);
        }
    }
}
