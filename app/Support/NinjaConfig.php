<?php

namespace App\Support;

use App\Models\Setting;

class NinjaConfig
{
    /**
     * NinjaRMM is OFF by default (psa-u97k): Charlie is offboarding Ninja. Set the `ninja_enabled`
     * setting to '1' to re-enable. While disabled, no Ninja sync command, schedule, or webhook runs.
     */
    public static function isEnabled(): bool
    {
        return Setting::getValue('ninja_enabled', '0') === '1';
    }
}
