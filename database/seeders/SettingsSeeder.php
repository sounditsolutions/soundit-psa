<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsSeeder extends Seeder
{
    /**
     * Ensure all known settings keys exist in the table.
     *
     * Uses insertOrIgnore so existing values (e.g. a configured API key) are
     * never overwritten — only missing rows are added with a null default.
     */
    public function run(): void
    {
        $now = now();

        $keys = [
            // General
            'app_timezone',

            // AI
            'ai_provider',
            'ai_api_key',
            'ai_model',
            'ai_connected_at',

            // Microsoft Graph / Email
            'graph_mailbox',
            'graph_connected_at',
            'graph_last_poll_at',
            'graph_subscription_id',
            'graph_subscription_expiry',
            'graph_webhook_client_state',
            'email_signature',

            // Plivo (voice/SMS)
            'plivo_auth_id',
            'plivo_auth_token',
            'plivo_webhook_secret',
            'plivo_did_number',
            'plivo_app_id',
            'plivo_connected_at',

            // Level
            'level_api_key',
            'level_webhook_secret',
            'level_connected_at',

            // NinjaRMM
            'ninja_client_id',
            'ninja_client_secret',
            'ninja_connected_at',

            // QuickBooks Online
            'qbo_client_id',
            'qbo_client_secret',
            'qbo_realm_id',
            'qbo_environment',
            'qbo_access_token',
            'qbo_refresh_token',
            'qbo_token_expires_at',
        ];

        DB::table('settings')->insertOrIgnore(
            array_map(
                fn (string $key) => ['key' => $key, 'value' => null, 'created_at' => $now, 'updated_at' => $now],
                $keys
            )
        );

        $this->command->info('Settings: '.count($keys).' keys registered (existing values preserved).');
    }
}
