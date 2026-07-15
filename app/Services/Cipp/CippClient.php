<?php

namespace App\Services\Cipp;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

class CippClient
{
    private Client $http;

    private const TOKEN_CACHE_KEY = 'cipp_oauth_token';

    public function __construct(
        private readonly array $config,
        private readonly CacheInterface $cache,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim($this->config['api_url'] ?? '', '/').'/',
            'timeout' => 60,
        ]);
    }

    /**
     * Make an authenticated GET request to the CIPP API, returning decoded JSON.
     */
    public function get(string $endpoint, array $params = []): array
    {
        $response = $this->sendRequest('GET', $endpoint, ['query' => $params], 'application/json');

        $data = json_decode((string) $response->getBody(), true) ?? [];

        if (is_array($data)) {
            // "Still loading" is not "nothing found" — see CippQueueGuard for the
            // vendor shapes and why this must throw rather than unwrap to [].
            CippQueueGuard::assertNotQueueBacked($data);
        }

        // CIPP wraps list results in {"Results": [...]} — unwrap
        if (is_array($data) && isset($data['Results'])) {
            return $data['Results'];
        }

        return $data;
    }

    /**
     * Make an authenticated GET request and return the raw response bytes.
     *
     * Used for endpoints that return binary payloads (e.g. profile photos).
     * CIPP's api/ListUserPhoto returns raw image bytes when a photo exists, or a
     * JSON error object ({"error": {...}}) when it doesn't — the caller inspects
     * the content type / body to tell them apart.
     *
     * @return array{status: int, contentType: string, body: string}
     */
    public function getRaw(string $endpoint, array $params = [], string $accept = '*/*'): array
    {
        $response = $this->sendRequest('GET', $endpoint, ['query' => $params], $accept);

        return [
            'status' => $response->getStatusCode(),
            'contentType' => $response->getHeaderLine('Content-Type'),
            'body' => (string) $response->getBody(),
        ];
    }

    /**
     * Fetch a user's M365 profile photo. Returns the raw response — image bytes
     * when a photo is set, or a JSON error payload when none exists.
     *
     * @return array{status: int, contentType: string, body: string}
     */
    public function getUserPhoto(string $tenantFilter, string $userId): array
    {
        return $this->getRaw('api/ListUserPhoto', [
            'TenantFilter' => $tenantFilter,
            'userId' => $userId,
        ]);
    }

    /**
     * Check if the CIPP API is reachable.
     */
    public function isHealthy(): bool
    {
        try {
            $this->get('api/ListTenants');

            return true;
        } catch (CippClientException) {
            return false;
        }
    }

    /**
     * List all tenants managed in CIPP.
     * Returns: [{customerId, defaultDomainName, displayName}, ...]
     */
    public function listTenants(): array
    {
        return $this->get('api/ListTenants');
    }

    /**
     * List M365 license assignments for a tenant.
     *
     * CIPP's Get-CIPPLicenseOverview hand-builds each row (NOT raw Graph
     * subscribedSkus): [{skuId, skuPartNumber, License, CountUsed, CountAvailable,
     * TotalLicenses, TermInfo, ...}, ...], with the seat counts as STRINGS. There is
     * no consumedLicenses key at this layer (psa-d6mf, verified against CIPP-API).
     */
    public function listLicenses(string $tenantFilter): array
    {
        return $this->get('api/ListLicenses', ['TenantFilter' => $tenantFilter]);
    }

    /**
     * List all M365 users for a tenant.
     */
    public function listUsers(string $tenantFilter): array
    {
        return $this->get('api/ListUsers', ['TenantFilter' => $tenantFilter]);
    }

    /**
     * List all security/M365 groups for a tenant.
     */
    public function listGroups(string $tenantFilter): array
    {
        return $this->get('api/ListGroups', ['TenantFilter' => $tenantFilter]);
    }

    /**
     * List groups that a specific user belongs to.
     */
    public function listUserGroups(string $tenantFilter, string $userId): array
    {
        return $this->get('api/ListUserGroups', [
            'TenantFilter' => $tenantFilter,
            'userId' => $userId,
        ]);
    }

    /**
     * List Exchange mailboxes for a tenant.
     */
    public function listMailboxes(string $tenantFilter): array
    {
        return $this->get('api/ListMailboxes', ['TenantFilter' => $tenantFilter]);
    }

    /**
     * List per-user MFA registration status for a tenant.
     */
    public function listMFAUsers(string $tenantFilter): array
    {
        return $this->get('api/ListMFAUsers', ['TenantFilter' => $tenantFilter]);
    }

    /**
     * List Intune managed devices for a tenant.
     */
    public function listDevices(string $tenantFilter): array
    {
        return $this->get('api/ListDevices', ['TenantFilter' => $tenantFilter]);
    }

    /**
     * List Defender state for devices in a tenant.
     */
    public function listDefenderState(string $tenantFilter): array
    {
        return $this->get('api/ListDefenderState', ['TenantFilter' => $tenantFilter]);
    }

    public function listTransportRules(string $tenantFilter): array
    {
        return $this->get('api/ListTransportRules', ['TenantFilter' => $tenantFilter]);
    }

    public function listSafeLinksPolicy(string $tenantFilter): array
    {
        return $this->get('api/ListSafeLinksPolicy', ['TenantFilter' => $tenantFilter]);
    }

    public function listSafeAttachmentsFilters(string $tenantFilter): array
    {
        return $this->get('api/ListSafeAttachmentsFilters', ['TenantFilter' => $tenantFilter]);
    }

    public function listConditionalAccessPolicies(string $tenantFilter): array
    {
        return $this->get('api/ListConditionalAccessPolicies', ['TenantFilter' => $tenantFilter]);
    }

    public function listCompliancePolicies(string $tenantFilter): array
    {
        return $this->get('api/ListCompliancePolicies', ['TenantFilter' => $tenantFilter]);
    }

    public function listInactiveAccounts(string $tenantFilter): array
    {
        return $this->get('api/ListInactiveAccounts', ['TenantFilter' => $tenantFilter]);
    }

    /**
     * Get an OAuth2 access token via client_credentials flow.
     * Token is cached in Laravel Cache with a 55-minute TTL (tokens last 60 min).
     */
    private function getToken(): string
    {
        $cached = $this->cache->get(self::TOKEN_CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        $tenantId = $this->config['tenant_id'] ?? '';
        $clientId = $this->config['client_id'] ?? '';
        $clientSecret = $this->config['client_secret'] ?? '';
        $applicationId = $this->config['application_id'] ?? $clientId;

        $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";

        try {
            $tokenClient = new Client(['timeout' => 15]);
            $response = $tokenClient->post($tokenUrl, [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => "api://{$applicationId}/.default",
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::error("[CippClient] Token request failed: {$e->getMessage()}");
            throw new CippClientException("CIPP OAuth token request failed: {$e->getMessage()}", $e->getCode(), $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        $token = $data['access_token'] ?? null;

        if (! $token) {
            throw new CippClientException('CIPP OAuth response missing access_token');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        // Cache with 5-minute buffer
        $this->cache->put(self::TOKEN_CACHE_KEY, $token, max(60, $expiresIn - 300));

        return $token;
    }

    /**
     * Internal request method with OAuth2 Bearer token. Returns the raw PSR-7
     * response so callers can decode JSON or read binary bytes as needed.
     * Auto-retries on 401 (token expired) and 429 (rate limited).
     */
    private function sendRequest(string $method, string $endpoint, array $options, string $accept): ResponseInterface
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            $attempts++;

            $token = $this->getToken();
            $options['headers'] = [
                'Authorization' => "Bearer {$token}",
                'Accept' => $accept,
            ];

            try {
                return $this->http->request($method, $endpoint, $options);
            } catch (GuzzleException $e) {
                $code = $e->getCode();

                // On 401, clear cached token and retry once
                if ($code === 401 && $attempts < $maxAttempts) {
                    Log::info('[CippClient] 401 received, refreshing token and retrying');
                    $this->cache->forget(self::TOKEN_CACHE_KEY);

                    continue;
                }

                // On 429, wait and retry with exponential backoff
                if ($code === 429 && $attempts < $maxAttempts) {
                    $retryAfter = 2 ** $attempts; // 2s, 4s
                    if (method_exists($e, 'getResponse') && $e->getResponse()) {
                        $retryAfter = (int) ($e->getResponse()->getHeaderLine('Retry-After') ?: $retryAfter);
                    }
                    Log::info("[CippClient] Rate limited on {$endpoint}, retrying in {$retryAfter}s");
                    sleep($retryAfter);

                    continue;
                }

                Log::error("[CippClient] {$method} {$endpoint} failed: {$e->getMessage()}");
                throw new CippClientException("CIPP API error: {$e->getMessage()}", $code, $e);
            }
        }

        throw new CippClientException('CIPP request failed after max retries');
    }
}
