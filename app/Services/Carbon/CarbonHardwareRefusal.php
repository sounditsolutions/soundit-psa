<?php

namespace App\Services\Carbon;

use Illuminate\Validation\ValidationException;

/**
 * Builds CarbonHardwareService refusals. The thrown exception is a plain ValidationException
 * under 'hardware' (its class name reaches staff text); the lang key is held beside it so a
 * caller can classify the refusal without comparing translated text.
 */
final class CarbonHardwareRefusal
{
    private static ?\WeakMap $keys = null;

    public static function forKey(string $key): ValidationException
    {
        $e = ValidationException::withMessages(['hardware' => __('carbon_hardware.'.$key)]);
        self::$keys ??= new \WeakMap;
        self::$keys[$e] = $key;

        return $e;
    }

    public static function keyOf(\Throwable $e): ?string
    {
        return self::$keys[$e] ?? null;
    }
}
