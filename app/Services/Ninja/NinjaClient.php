<?php

namespace App\Services\Ninja;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Support\Facades\Log;

class NinjaClient
{
    private const TOKEN_CACHE_KEY = 'ninja_api_token';

    private const TOKEN_SAFETY_MARGIN = 60; // seconds before expiry to refresh

    private Client $http;

    public function __construct(
        private readonly array $config,
        private readonly CacheInterface $cache,
    ) {
        $this->http = new Client([
            'base_uri' => rtrim($this->config['base_url'] ?? 'https://app.ninjarmm.com', '/'),
            'timeout' => $this->config['request_timeout'] ?? 30,
        ]);
    }

    /**
     * Make an authenticated GET request to the NinjaRMM API.
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, [
            'query' => $params,
        ]);
    }

    /**
     * Check if the NinjaRMM API is reachable and we can authenticate.
     */
    public function isHealthy(): bool
    {
        try {
            $this->getToken();

            return true;
        } catch (NinjaClientException) {
            return false;
        }
    }

    /**
     * Fetch all organizations.
     */
    public function getOrganizations(): array
    {
        return $this->get('/v2/organizations');
    }

    /**
     * Fetch all devices for a NinjaRMM organization, handling cursor pagination.
     */
    public function getOrganizationDevices(int $orgId, int $pageSize = 200): array
    {
        $allDevices = [];
        $after = null;

        do {
            $params = [
                'df' => "org={$orgId}",
                'pageSize' => $pageSize,
            ];
            if ($after !== null) {
                $params['after'] = $after;
            }

            $response = $this->get('/v2/devices', $params);

            $allDevices = array_merge($allDevices, $response);

            // If we got fewer than pageSize results, we're done
            if (count($response) < $pageSize) {
                break;
            }

            // Use the last device's ID as the cursor for the next page
            $lastDevice = end($response);
            $after = $lastDevice['id'] ?? null;

            if ($after === null) {
                break;
            }
        } while (true);

        return $allDevices;
    }

    /**
     * Fetch a single device by ID.
     */
    public function getDevice(int $deviceId, ?int $timeout = null): array
    {
        $options = $timeout ? ['timeout' => $timeout] : [];

        return $this->request('GET', "/v2/device/{$deviceId}", $options);
    }

    /**
     * Fetch custom fields for a single device (warranty, purchase date, etc.).
     */
    public function getDeviceCustomFields(int $deviceId): array
    {
        return $this->get("/v2/device/{$deviceId}/custom-fields");
    }

    /**
     * Fetch detailed hardware info for a device (device + processors + volumes).
     * Sequential calls — parallel Guzzle Pool is a future optimization.
     */
    public function getDeviceDetail(int $deviceId): array
    {
        try {
            $device = $this->get("/v2/device/{$deviceId}");
        } catch (NinjaClientException $e) {
            Log::warning('[NinjaClient] Failed to fetch device detail', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        try {
            $processors = $this->get("/v2/device/{$deviceId}/processors");
        } catch (NinjaClientException) {
            $processors = [];
        }

        try {
            $volumes = $this->get("/v2/device/{$deviceId}/volumes");
        } catch (NinjaClientException) {
            $volumes = [];
        }

        $device['_processors'] = $processors;
        $device['_volumes'] = $volumes;

        return $device;
    }

    /**
     * Fetch custom fields for all devices (warranty, purchase date, etc.).
     *
     * Endpoint: GET /v2/queries/custom-fields
     * Each result: deviceId, fields (object with warrantyExpirationDate, purchaseDate, etc.)
     * Timestamps are milliseconds or microseconds since epoch.
     */
    public function getCustomFields(int $pageSize = 500): array
    {
        $allRecords = [];
        $cursorName = null;

        do {
            $params = ['pageSize' => $pageSize];
            if ($cursorName !== null) {
                $params['cursor'] = $cursorName;
            }

            $response = $this->get('/v2/queries/custom-fields', $params);

            $results = $response['results'] ?? [];
            $allRecords = array_merge($allRecords, $results);

            if (count($results) < $pageSize) {
                break;
            }

            $cursorName = $response['cursor']['name'] ?? null;

            if ($cursorName === null) {
                break;
            }
        } while (true);

        return $allRecords;
    }

    /**
     * Fetch OS data for all devices (boot time, reboot status).
     *
     * Endpoint: GET /v2/queries/operating-systems
     * Each result: deviceId, lastBootTime (unix double), needsReboot (bool),
     *              name, manufacturer, buildNumber, releaseId, architecture.
     */
    public function getOperatingSystems(int $pageSize = 500): array
    {
        $allRecords = [];
        $cursorName = null;

        do {
            $params = ['pageSize' => $pageSize];
            if ($cursorName !== null) {
                $params['cursor'] = $cursorName;
            }

            $response = $this->get('/v2/queries/operating-systems', $params);

            $results = $response['results'] ?? [];
            $allRecords = array_merge($allRecords, $results);

            if (count($results) < $pageSize) {
                break;
            }

            $cursorName = $response['cursor']['name'] ?? null;

            if ($cursorName === null) {
                break;
            }
        } while (true);

        return $allRecords;
    }

    /**
     * Fetch backup usage for all devices, handling named cursor pagination.
     *
     * Endpoint: GET /v2/queries/backup/usage
     * Response: {"results": [...], "cursor": {"name": "...", "count": N}}
     * Pagination: pass cursor name back to get next page.
     */
    public function getBackupUsage(int $pageSize = 200): array
    {
        $allRecords = [];
        $cursorName = null;

        do {
            $params = ['pageSize' => $pageSize];
            if ($cursorName !== null) {
                $params['cursor'] = $cursorName;
            }

            $response = $this->get('/v2/queries/backup/usage', $params);

            $results = $response['results'] ?? [];
            $allRecords = array_merge($allRecords, $results);

            if (count($results) < $pageSize) {
                break;
            }

            $cursorName = $response['cursor']['name'] ?? null;

            if ($cursorName === null) {
                break;
            }
        } while (true);

        return $allRecords;
    }

    /**
     * Fetch recent backup jobs for a specific device.
     *
     * Endpoint: GET /v2/backup/jobs
     * Used on-demand for asset detail page — not stored in DB.
     */
    public function getBackupJobs(int $deviceId, int $limit = 10): array
    {
        $response = $this->request('GET', '/v2/backup/jobs', [
            'query' => [
                'df' => "id = {$deviceId}",
                'pageSize' => $limit,
            ],
            'timeout' => 8,
        ]);

        return $response['results'] ?? $response;
    }

    /**
     * Fetch recent backup integrity check jobs for a specific device.
     *
     * Endpoint: GET /v2/backup/integrity-check-jobs
     * Same parameter pattern as backup jobs.
     */
    public function getIntegrityCheckJobs(int $deviceId, int $limit = 5): array
    {
        $response = $this->request('GET', '/v2/backup/integrity-check-jobs', [
            'query' => [
                'df' => "id = {$deviceId}",
                'pageSize' => $limit,
            ],
            'timeout' => 8,
        ]);

        return $response['results'] ?? $response;
    }

    /**
     * Get installer info for a specific Ninja organization.
     *
     * Research (2026-04-18):
     * NinjaRMM's v2 API does expose installer endpoints, confirmed by inspecting
     * the community-maintained Homotechsual NinjaOne PowerShell module source
     * (github.com/homotechsual/NinjaOne/Source/Public/Management/{Get,New}/*.ps1):
     *
     *   GET  /v2/organization/{org_id}/location/{location_id}/installer/{installer_type}
     *   POST /v2/organization/generate-installer
     *        body: { organization_id, location_id, installer_type, content: { nodeRoleId } }
     *        response: { url: "..." }
     *
     * installer_type values: WINDOWS_MSI, MAC_DMG, MAC_PKG, LINUX_DEB, LINUX_RPM.
     *
     * Why this method returns null anyway:
     *   1. Both endpoints require a location_id, not just an organization_id.
     *      Our clients.ninja_org_id column only tracks the org — we have no
     *      per-client location ID column, and a Ninja org can hold multiple
     *      locations with no "primary" concept exposed through our schema.
     *   2. generate-installer likely requires the `management` OAuth scope.
     *      Our NinjaClient currently requests `monitoring` only (see
     *      config/services.php `NINJA_SCOPE` default and the bind in
     *      AppServiceProvider). Broadening the scope has downstream impact
     *      (token refresh, least-privilege posture) and should be a separate
     *      decision.
     *
     * To ship Ninja installer support later:
     *   - Add a clients.ninja_location_id column (or a ninja_location_map) and
     *     populate it from GET /v2/organization/{org_id}/locations.
     *   - Add `management` to NINJA_SCOPE (or a split token strategy).
     *   - Swap this stub for a call to /v2/organization/generate-installer,
     *     extract $response['url'], and wrap it in an InstallerInfo with a
     *     short instructions string.
     *
     * Until then, PortalInstallService receives null for Ninja-mapped clients
     * and falls back to the "not configured" landing page.
     *
     * @param  int  $orgId  The Ninja org ID from clients.ninja_org_id
     * @param  string  $platform  One of: 'windows', 'mac', 'linux'
     */
    public function getInstallerInfo(int $orgId, string $platform): ?\App\Services\Portal\InstallerInfo
    {
        // NinjaRMM installer endpoints require a location_id which we don't
        // currently track per-client, and likely the `management` OAuth scope
        // which our client doesn't request. See the block comment above for
        // the full research notes and upgrade path.
        return null;
    }

    /**
     * Execute an authenticated request with automatic token retry on 401
     * and rate-limit backoff on 429.
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $token = $this->getToken();

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer '.$token,
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

                // Retry once on 401 with a fresh token
                if ($statusCode === 401 && $attempt === 0) {
                    $this->cache->forget(self::TOKEN_CACHE_KEY);
                    $freshToken = $this->getToken();
                    $options['headers']['Authorization'] = 'Bearer '.$freshToken;

                    continue;
                }

                // Back off and retry on 429 (rate limited)
                if ($statusCode === 429 && $attempt < $maxRetries) {
                    $retryAfter = $e->getResponse()?->getHeaderLine('Retry-After');
                    $waitSeconds = $retryAfter && is_numeric($retryAfter) ? (int) $retryAfter : (10 * ($attempt + 1));

                    Log::warning('[NinjaClient] Rate limited, backing off', [
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
            throw new NinjaClientException(
                "Invalid JSON response from NinjaRMM API: {$method} {$endpoint}",
                $response->getStatusCode(),
            );
        }

        return $decoded;
    }

    /**
     * Get an OAuth2 access token, cached across requests.
     */
    private function getToken(): string
    {
        $cached = $this->cache->get(self::TOKEN_CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        $clientId = $this->config['client_id'] ?? null;
        $clientSecret = $this->config['client_secret'] ?? null;

        if (! $clientId || ! $clientSecret) {
            throw new NinjaClientException('NinjaRMM API credentials not configured');
        }

        try {
            $response = $this->http->post('/ws/oauth/token', [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => $this->config['scope'] ?? 'monitoring',
                ],
                'timeout' => $this->config['token_timeout'] ?? 10,
            ]);
        } catch (GuzzleException $e) {
            Log::error('NinjaRMM API token request failed', [
                'error' => $e->getMessage(),
            ]);
            throw new NinjaClientException(
                'Failed to obtain NinjaRMM API token: '.$e->getMessage(),
            );
        }

        $data = json_decode((string) $response->getBody(), true);
        $token = $data['access_token'] ?? null;

        if (! $token) {
            throw new NinjaClientException(
                'NinjaRMM API token response did not contain access_token',
            );
        }

        $ttl = ($data['expires_in'] ?? 3600) - self::TOKEN_SAFETY_MARGIN;
        $this->cache->put(self::TOKEN_CACHE_KEY, $token, max($ttl, 60));

        return $token;
    }

    /**
     * Convert a Guzzle exception into a NinjaClientException.
     *
     * @throws NinjaClientException
     */
    private function throwFromGuzzle(GuzzleException $e, string $method, string $endpoint): never
    {
        $statusCode = 0;
        $responseBody = null;

        if (method_exists($e, 'getResponse') && $e->getResponse()) {
            $statusCode = $e->getResponse()->getStatusCode();
            $responseBody = json_decode((string) $e->getResponse()->getBody(), true);
        }

        Log::error('NinjaRMM API request failed', [
            'method' => $method,
            'endpoint' => $endpoint,
            'status' => $statusCode,
            'error' => $e->getMessage(),
        ]);

        throw new NinjaClientException(
            "NinjaRMM API error: {$method} {$endpoint} returned {$statusCode}",
            $statusCode,
            $responseBody,
        );
    }
}
