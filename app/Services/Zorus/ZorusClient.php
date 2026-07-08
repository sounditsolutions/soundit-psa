<?php

namespace App\Services\Zorus;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class ZorusClient
{
    private Client $http;

    public function __construct(
        private readonly array $config,
    ) {
        $this->http = new Client([
            'base_uri' => 'https://developer.zorustech.com/',
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Impersonation '.($this->config['api_key'] ?? ''),
                'Zorus-Api-Version' => '1.0',
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Make an authenticated POST request to the Zorus API.
     * All Zorus search/list endpoints use POST with JSON body.
     */
    public function post(string $endpoint, array $body = []): array
    {
        try {
            $response = $this->http->request('POST', $endpoint, [
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            Log::error("[ZorusClient] POST {$endpoint} failed: {$e->getMessage()}");
            throw new ZorusClientException(
                "Zorus API error: {$e->getMessage()}", $e->getCode(), $e
            );
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }

    /**
     * Check if the Zorus API is reachable with the configured credentials.
     */
    public function isHealthy(): bool
    {
        try {
            $this->searchCustomers([], 1, 1);

            return true;
        } catch (ZorusClientException) {
            return false;
        }
    }

    /**
     * Search customers with deployment info.
     *
     * @return array Array of CustomerListItem
     */
    public function searchCustomers(array $filters = [], int $page = 1, int $pageSize = 100): array
    {
        return $this->post('api/customers/search', [
            'page' => $page,
            'pageSize' => $pageSize,
            'sortProperty' => 'name',
            'sortAscending' => true,
            'filters' => (object) $filters,
        ]);
    }

    /**
     * Search endpoints (devices) with status info.
     *
     * NOTE: customerUuid filter on this endpoint is unreliable as of 2026-02.
     * It is silently ignored and returns all endpoints. Fetch all and filter client-side.
     *
     * @return array Array of EndpointListItem
     */
    public function searchEndpoints(array $filters = [], int $page = 1, int $pageSize = 500): array
    {
        return $this->post('api/endpoints/search', [
            'page' => $page,
            'pageSize' => $pageSize,
            'sortProperty' => 'name',
            'sortAscending' => true,
            'filters' => (object) $filters,
        ]);
    }
}
