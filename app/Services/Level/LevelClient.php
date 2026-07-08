<?php

namespace App\Services\Level;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class LevelClient
{
    private Client $http;

    public function __construct(
        private readonly array $config,
    ) {
        $this->http = new Client([
            'base_uri' => rtrim($this->config['base_url'] ?? 'https://api.level.io', '/'),
            'timeout' => $this->config['request_timeout'] ?? 30,
        ]);
    }

    /**
     * Make an authenticated GET request to the Level API.
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, [
            'query' => $params,
        ]);
    }

    /**
     * Check if the Level API is reachable with the configured API key.
     */
    public function isHealthy(): bool
    {
        try {
            $this->get('/v2/devices', ['limit' => 1]);

            return true;
        } catch (LevelClientException) {
            return false;
        }
    }

    /**
     * Fetch all groups (paginated).
     */
    public function getGroups(): array
    {
        $allGroups = [];
        $startingAfter = null;
        $maxPages = 200;

        for ($page = 0; $page < $maxPages; $page++) {
            $params = ['limit' => 100];

            if ($startingAfter !== null) {
                $params['starting_after'] = $startingAfter;
            }

            $response = $this->get('/v2/groups', $params);

            $groups = $response['data'] ?? $response;
            if (! is_array($groups) || empty($groups)) {
                break;
            }

            $allGroups = array_merge($allGroups, $groups);

            $hasMore = $response['has_more'] ?? false;
            if (! $hasMore) {
                break;
            }

            $lastGroup = end($groups);
            $startingAfter = $lastGroup['id'] ?? null;

            if ($startingAfter === null) {
                break;
            }
        }

        return $allGroups;
    }

    /**
     * Fetch devices, optionally filtered by ancestor group ID.
     * Uses ancestor_group_id to include devices in subgroups.
     * Handles cursor-based pagination automatically.
     * Includes hardware details (CPUs, memory, disks, network) in every call.
     */
    public function getDevices(?string $groupId = null, int $limit = 100): array
    {
        $allDevices = [];
        $startingAfter = null;
        $maxPages = 200;

        for ($page = 0; $page < $maxPages; $page++) {
            $params = [
                'limit' => $limit,
                'include_cpus' => 'true',
                'include_memory' => 'true',
                'include_disks' => 'true',
                'include_network_interfaces' => 'true',
            ];

            if ($groupId !== null) {
                $params['ancestor_group_id'] = $groupId;
            }

            if ($startingAfter !== null) {
                $params['starting_after'] = $startingAfter;
            }

            $response = $this->get('/v2/devices', $params);

            $devices = $response['data'] ?? $response;
            if (! is_array($devices) || empty($devices)) {
                break;
            }

            $allDevices = array_merge($allDevices, $devices);

            // Check for cursor-based pagination
            $hasMore = $response['has_more'] ?? false;
            if (! $hasMore) {
                break;
            }

            $lastDevice = end($devices);
            $startingAfter = $lastDevice['id'] ?? null;

            if ($startingAfter === null) {
                break;
            }
        }

        if ($page >= $maxPages) {
            Log::warning('[LevelClient] Pagination safety limit reached', [
                'group_id' => $groupId,
                'max_pages' => $maxPages,
                'devices_fetched' => count($allDevices),
            ]);
        }

        return $allDevices;
    }

    /**
     * Fetch a single device by ID with all hardware includes.
     */
    public function getDevice(string $deviceId): array
    {
        return $this->get("/v2/devices/{$deviceId}", [
            'include_cpus' => 'true',
            'include_memory' => 'true',
            'include_disks' => 'true',
            'include_network_interfaces' => 'true',
        ]);
    }

    /**
     * Get installer info for a Level group.
     *
     * Level's install key has a deterministic format: "{account_token}:{group_numeric_id}".
     * The account token is tenant-wide (same for every group in one Level account) and
     * must be configured once via the `level_install_account_token` setting — it is
     * visible in the dashboard's "Add Device" modal for any group (the part before the
     * colon). The group numeric id is extracted from our stored base64-encoded Relay gid.
     *
     * The /graphql `installKey` resolver is user-session-only (rejects server-side API
     * keys with UNAUTHENTICATED), so we construct the key deterministically instead.
     *
     * @param  string  $groupId  base64-encoded Relay gid from clients.level_group_id
     * @param  string  $platform  One of: 'windows', 'mac', 'linux'
     */
    public function getInstallerInfo(string $groupId, string $platform): ?\App\Services\Portal\InstallerInfo
    {
        if (empty($groupId)) {
            return null;
        }

        // Level only publishes a Windows one-liner at downloads.level.io today.
        // Mac/Linux install flows use different distribution channels.
        if ($platform !== 'windows') {
            return null;
        }

        $accountToken = \App\Support\LevelConfig::get('install_account_token');
        if (empty($accountToken)) {
            return null;
        }

        $numericId = self::extractNumericGroupId($groupId);
        if ($numericId === null) {
            Log::warning('[LevelClient] Could not extract numeric group id from level_group_id', [
                'group_id' => $groupId,
            ]);

            return null;
        }

        $key = "{$accountToken}:{$numericId}";

        $script = "-ExecutionPolicy Bypass; \$env:LEVEL_API_KEY = '{$key}'; "
            .'Set-ExecutionPolicy RemoteSigned -Scope Process -Force; '
            .'[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; '
            .'iwr -useb https://downloads.level.io/install_windows.ps1 | iex';

        return new \App\Services\Portal\InstallerInfo(
            downloadUrl: 'https://downloads.level.io/install_windows.ps1',
            registrationKey: $key,
            installScript: $script,
            instructions: 'Open Windows PowerShell as Administrator, paste the command below, and press Enter. The installer runs silently and your device will appear in the dashboard within a few minutes.',
        );
    }

    /**
     * Extract the numeric group id from Level's base64-encoded Relay gid.
     * Input:  "Z2lkOi8vbGV2ZWwvRGV2aWNlR3JvdXAvNDAxMjM" → decodes to "gid://level/DeviceGroup/40123"
     * Output: "40123"
     */
    private static function extractNumericGroupId(string $groupId): ?string
    {
        $decoded = base64_decode($groupId, true);
        if ($decoded === false) {
            return null;
        }

        // Expected shape: gid://level/DeviceGroup/<numeric>
        if (! preg_match('#/(\d+)$#', $decoded, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Execute an authenticated request with rate-limit backoff on 429.
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (! $apiKey) {
            throw new LevelClientException('Level API key not configured');
        }

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => $apiKey,
            'Accept' => 'application/json',
        ]);

        $maxRetries = 3;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->http->request($method, $endpoint, $options);
                break; // success
            } catch (GuzzleException $e) {
                $statusCode = method_exists($e, 'getResponse') && $e->getResponse()
                    ? $e->getResponse()->getStatusCode()
                    : 0;

                // Back off and retry on 429 (rate limited)
                if ($statusCode === 429 && $attempt < $maxRetries) {
                    $retryAfter = $e->getResponse()?->getHeaderLine('Retry-After');
                    $waitSeconds = $retryAfter && is_numeric($retryAfter) ? (int) $retryAfter : (10 * ($attempt + 1));

                    Log::warning('[LevelClient] Rate limited, backing off', [
                        'endpoint' => $endpoint,
                        'attempt' => $attempt + 1,
                        'wait_seconds' => $waitSeconds,
                    ]);

                    sleep($waitSeconds);

                    continue;
                }

                $this->throwFromGuzzle($e, $method, $endpoint);
            }
        }

        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new LevelClientException(
                "Invalid JSON response from Level API: {$method} {$endpoint}",
                $response->getStatusCode(),
            );
        }

        return $decoded;
    }

    /**
     * Convert a Guzzle exception into a LevelClientException.
     *
     * @throws LevelClientException
     */
    private function throwFromGuzzle(GuzzleException $e, string $method, string $endpoint): never
    {
        $statusCode = 0;
        $responseBody = null;

        if (method_exists($e, 'getResponse') && $e->getResponse()) {
            $statusCode = $e->getResponse()->getStatusCode();
            $responseBody = json_decode((string) $e->getResponse()->getBody(), true);
        }

        Log::error('Level API request failed', [
            'method' => $method,
            'endpoint' => $endpoint,
            'status' => $statusCode,
            'error' => $e->getMessage(),
        ]);

        throw new LevelClientException(
            "Level API error: {$method} {$endpoint} returned {$statusCode}",
            $statusCode,
            $responseBody,
        );
    }
}
