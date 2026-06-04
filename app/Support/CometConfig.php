<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Str;

class CometConfig
{
    /** Tactical RMM custom field ID for CometInstallToken (agent-scoped) */
    public const TACTICAL_TOKEN_FIELD_ID = 27;

    private static array $encryptedFields = [
        'comet_account_token',
        'comet_admin_user',
        'comet_admin_password',
        'comet_webhook_key',
    ];

    public static function get(string $key): ?string
    {
        return match (true) {
            in_array($key, self::$encryptedFields) => Setting::getEncrypted($key),
            default => Setting::getValue($key),
        };
    }

    public static function isConfigured(): bool
    {
        return self::get('comet_server_url')
            && self::get('comet_admin_user')
            && self::get('comet_admin_password');
    }

    public static function isEnabled(): bool
    {
        return self::isConfigured();
    }

    public static function serverUrl(): ?string
    {
        return self::get('comet_server_url');
    }

    public static function alertsEnabled(): bool
    {
        if (! self::isConfigured()) {
            return false;
        }

        return (bool) (self::get('comet_alert_enabled') ?? true);
    }

    public static function generateWebhookKey(): string
    {
        $key = Str::random(64);
        Setting::setEncrypted('comet_webhook_key', $key);

        return $key;
    }

    /**
     * Call the Comet Account Portal API to auto-discover hosted server credentials.
     * Uses the account API token to find the hosted instance and retrieve admin creds.
     *
     * @return array{server_url: string, admin_user: string, admin_password: string, server_name: string}
     *
     * @throws \RuntimeException
     */
    public static function autoConfigureFromPortal(string $accountToken): array
    {
        $http = new \GuzzleHttp\Client(['base_uri' => 'https://account.cometbackup.com/', 'timeout' => 15]);

        // List hosted instances — response includes admin credentials directly
        $response = $http->post('api/v1/hosted_comet/list', [
            'headers' => ['Authorization' => 'Bearer '.$accountToken],
            'form_params' => [],
        ]);
        $body = json_decode((string) $response->getBody(), true);

        // Response is {"status":"ok","code":200,"data":[...]}
        $instances = $body['data'] ?? $body;
        if (empty($instances)) {
            throw new \RuntimeException('No hosted Comet instances found for this account.');
        }

        // Take the first instance (most MSPs have one)
        $instance = is_array($instances) ? reset($instances) : $instances;

        $serverUrl = $instance['user_custom_dns'] ?? $instance['user_dns'] ?? null;
        $adminUser = $instance['admin_username'] ?? null;
        $adminPassword = $instance['admin_password'] ?? null;

        if (! $serverUrl || ! $adminUser || ! $adminPassword) {
            throw new \RuntimeException('Could not retrieve server credentials from Comet portal. Response keys: '.implode(', ', array_keys($instance)));
        }

        // Ensure URL has protocol
        if (! str_starts_with($serverUrl, 'http')) {
            $serverUrl = 'https://'.$serverUrl;
        }

        return [
            'server_url' => $serverUrl,
            'admin_user' => $adminUser,
            'admin_password' => $adminPassword,
            'server_name' => $instance['server_name'] ?? $instance['id'] ?? 'Comet Server',
        ];
    }
}
