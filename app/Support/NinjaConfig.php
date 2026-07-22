<?php

namespace App\Support;

use App\Models\Setting;

class NinjaConfig
{
    /**
     * NinjaRMM is OFF by default (psa-u97k): Charlie is offboarding Ninja. Set the `ninja_enabled`
     * setting to '1' to re-enable. While disabled, no Ninja sync command, schedule, or webhook runs —
     * and, since psa-wzjzz, no Ninja tool is published to any AI surface either.
     */
    public static function isEnabled(): bool
    {
        return Setting::getValue('ninja_enabled', '0') === '1';
    }

    /**
     * Mirrors how the NinjaClient singleton actually resolves its credentials
     * (AppServiceProvider.php:69-75): the settings row wins, and `config('services.ninja')`
     * (i.e. .env) is the fallback. Reading only the settings table here would report an
     * env-configured deployment as unconfigured and silently withhold its Ninja tools.
     */
    public static function get(string $key): ?string
    {
        return match ($key) {
            'client_id' => Setting::getValue('ninja_client_id') ?: config('services.ninja.client_id'),
            'client_secret' => Setting::getValue('ninja_client_secret')
                ? Setting::getEncrypted('ninja_client_secret')
                : config('services.ninja.client_secret'),
            'instance_url' => Setting::getValue('ninja_instance_url') ?: config('services.ninja.instance_url'),
            default => null,
        };
    }

    /**
     * The exact pair NinjaClient::getToken() (NinjaClient.php:429-433) throws on when absent.
     * Keeping this in step with that guard is what preserves the "never-configured deployment
     * publishes zero Ninja tools" property that the old live health probe used to provide.
     */
    public static function isConfigured(): bool
    {
        return ! empty(self::get('client_id')) && ! empty(self::get('client_secret'));
    }
}
