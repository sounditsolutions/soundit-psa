<?php

namespace App\Services\Carbon;

use Illuminate\Validation\ValidationException;

/**
 * A CarbonHardwareService refusal. Callers see an ordinary ValidationException under
 * 'hardware'; $key carries the lang key so a caller can classify the refusal without
 * comparing translated text.
 */
class CarbonHardwareRefusal extends ValidationException
{
    public string $key = '';

    public static function forKey(string $key): self
    {
        $e = static::withMessages(['hardware' => __('carbon_hardware.'.$key)]);
        $e->key = $key;

        return $e;
    }
}
