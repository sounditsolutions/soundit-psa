<?php

namespace App\Services\Cipp;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Support\Facades\Log;

class CippClient
{
    private Client $http;

    private const TOKEN_CACHE_KEY = 'cipp_oauth_token';

    public function __construct(
        private readonly array $config,
        private readonly CacheInterface $cache,
    ) {
        $this->http = new Client([
            'base_uri' => rtrim($this->config['api_url'] ?? '', '/') . '/',
            'timeout' => 60,
        ]);
    }

    /**
     * Make an authenticated GET request to the CIPP API.
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
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
     * Returns: [{skuId, skuPartNumber, totalLicenses, consumedLicenses, ...}, ...]
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
     * Internal request method with OAuth2 Bearer token.
     * Auto-retries on 401 (token expired) and 429 (rate limited).
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            $attempts++;

            $token = $this->getToken();
            $options['headers'] = [
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ];

            try {
                $response = $this->http->request($method, $endpoint, $options);
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

            $body = (string) $response->getBody();
            $data = json_decode($body, true) ?? [];

            // CIPP wraps list results in {"Results": [...]} — unwrap
            if (is_array($data) && isset($data['Results'])) {
                return $data['Results'];
            }

            return $data;
        }

        throw new CippClientException('CIPP request failed after max retries');
    }
}
