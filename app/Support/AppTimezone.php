<?php

namespace App\Support;

use App\Models\Setting;

class AppTimezone
{
    /**
     * Return the configured display timezone (default: UTC).
     *
     * DB always stores UTC. This timezone is applied only at display time.
     * Use $carbon->toAppTz()->format(...) in Blade views.
     */
    public static function get(): string
    {
        try {
            return Setting::getValue('app_timezone') ?? 'UTC';
        } catch (\Throwable) {
            // Settings table not yet created (fresh deploy before first migrate).
            return 'UTC';
        }
    }
}
