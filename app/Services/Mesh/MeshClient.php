<?php

namespace App\Services\Mesh;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class MeshClient
{
    private Client $http;

    public function __construct(
        private readonly array $config,
    ) {
        $this->http = new Client([
            'base_uri' => rtrim($this->config['base_url'] ?? 'https://hub-us.emailsecurity.app', '/').'/',
            'timeout' => 90,
        ]);
    }

    /**
     * Make an authenticated GET request to the Mesh API.
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
    }

    /**
     * Check if the Mesh API is reachable with the configured API key.
     */
    public function isHealthy(): bool
    {
        try {
            $this->get('api/customers/', ['_size' => 1]);

            return true;
        } catch (MeshClientException) {
            return false;
        }
    }

    /**
     * Search Mesh customers. Returns paginated results.
     */
    public function getCustomers(?string $filter = null, int $size = 100): array
    {
        $params = ['_from' => 0, '_size' => $size];
        if ($filter) {
            $params['filter'] = $filter;
        }

        $response = $this->get('api/customers/', $params);

        return $response['results'] ?? $response;
    }

    /**
     * Get a single Mesh customer by UUID.
     */
    public function getCustomer(string $uuid): array
    {
        return $this->get("api/customers/{$uuid}/");
    }

    /**
     * Internal request method with auth header.
     * API-KEY header is added here — never logged.
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $options['headers'] = [
            'API-KEY' => $this->config['api_key'] ?? '',
            'Accept' => 'application/json',
        ];

        try {
            $response = $this->http->request($method, $endpoint, $options);
        } catch (GuzzleException $e) {
            Log::error("[MeshClient] {$method} {$endpoint} failed: {$e->getMessage()}");
            throw new MeshClientException("Mesh API error: {$e->getMessage()}", $e->getCode(), $e);
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }
}
