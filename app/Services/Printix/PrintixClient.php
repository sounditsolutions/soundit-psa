<?php

namespace App\Services\Printix;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Support\Facades\Log;

class PrintixClient
{
    private Client $http;

    private const TOKEN_CACHE_KEY = 'printix_oauth_token';

    private const AUTH_URL = 'https://auth.printix.net';

    private const API_URL = 'https://api.printix.net';

    public function __construct(
        private readonly array $config,
        private readonly CacheInterface $cache,
    ) {
        $this->http = new Client([
            'base_uri' => self::API_URL . '/',
            'timeout' => 30,
        ]);
    }

    /**
     * Check if the API is reachable.
     */
    public function isHealthy(): bool
    {
        try {
            $this->getTenants();

            return true;
        } catch (PrintixClientException) {
            return false;
        }
    }

    /**
     * List all tenants for the partner.
     */
    public function getTenants(): array
    {
        $partnerId = $this->config['partner_id'] ?? '';
        $response = $this->get("public/partners/{$partnerId}/tenants");

        // HAL+JSON format — tenants may be in _embedded or directly in response
        $tenants = $response['_embedded']['tenants'] ?? $response['tenants'] ?? $response;

        // Extract tenant ID from _links.self.href (no explicit id field in response)
        foreach ($tenants as &$tenant) {
            if (empty($tenant['id']) && ! empty($tenant['_links']['self']['href'])) {
                $parts = explode('/', rtrim($tenant['_links']['self']['href'], '/'));
                $tenant['id'] = end($parts);
            }
        }

        return $tenants;
    }

    /**
     * Get billing info for a specific tenant.
     */
    public function getBillingInfo(string $tenantId): array
    {
        $partnerId = $this->config['partner_id'] ?? '';

        return $this->get("public/partners/{$partnerId}/tenants/{$tenantId}/billing-info");
    }

    /**
     * Get a single tenant.
     */
    public function getTenant(string $tenantId): array
    {
        $partnerId = $this->config['partner_id'] ?? '';

        return $this->get("public/partners/{$partnerId}/tenants/{$tenantId}");
    }

    // ── Internal ──

    private function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint);
    }

    private function getToken(): string
    {
        $cached = $this->cache->get(self::TOKEN_CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        $clientId = $this->config['client_id'] ?? '';
        $clientSecret = $this->config['client_secret'] ?? '';

        try {
            $authClient = new Client(['timeout' => 15]);
            $response = $authClient->post(self::AUTH_URL . '/oauth/token', [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::error("[Printix] Token request failed: {$e->getMessage()}");
            throw new PrintixClientException("Printix OAuth token request failed: {$e->getMessage()}", $e->getCode(), $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        $token = $data['access_token'] ?? null;

        if (! $token) {
            throw new PrintixClientException('Printix OAuth response missing access_token');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        $this->cache->put(self::TOKEN_CACHE_KEY, $token, max(60, $expiresIn - 300));

        return $token;
    }

    private function request(string $method, string $endpoint, array $options = []): array
    {
        $attempts = 0;
        $maxAttempts = 2;

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

                if ($code === 401 && $attempts < $maxAttempts) {
                    Log::info('[Printix] 401 received, refreshing token and retrying');
                    $this->cache->forget(self::TOKEN_CACHE_KEY);
                    continue;
                }

                Log::error("[Printix] {$method} {$endpoint} failed: {$e->getMessage()}");
                throw new PrintixClientException("Printix API error: {$e->getMessage()}", $code, $e);
            }

            return json_decode((string) $response->getBody(), true) ?? [];
        }

        throw new PrintixClientException('Printix request failed after max retries');
    }
}
