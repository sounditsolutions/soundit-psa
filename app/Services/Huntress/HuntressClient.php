<?php

namespace App\Services\Huntress;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class HuntressClient
{
    private Client $http;

    public function __construct(
        private readonly array $config,
    ) {
        $this->http = new Client([
            'base_uri' => 'https://api.huntress.io/v1/',
            'timeout' => 30,
            'auth' => [
                $this->config['api_key'] ?? '',
                $this->config['api_secret'] ?? '',
            ],
        ]);
    }

    /**
     * Make an authenticated GET request to the Huntress API.
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
    }

    /**
     * Check if the Huntress API is reachable with the configured credentials.
     */
    public function isHealthy(): bool
    {
        try {
            $this->get('account');

            return true;
        } catch (HuntressClientException) {
            return false;
        }
    }

    /**
     * Get all organizations (auto-paginates through all pages).
     *
     * @param  array|null  $fields  If provided, only keep these keys per org (saves memory).
     */
    public function getOrganizations(?array $fields = null): array
    {
        return $this->getAllPages('organizations', [], $fields);
    }

    /**
     * Get a single organization by ID.
     */
    public function getOrganization(int $id): array
    {
        return $this->get("organizations/{$id}");
    }

    /**
     * Get all agents (auto-paginates through all pages).
     */
    public function getAgents(array $params = []): array
    {
        return $this->getAllPages('agents', $params);
    }

    /**
     * Get a single incident report by id.
     *
     * Response is wrapped: {"incident_report": {...}}. The report carries
     * status ∈ {sent, dismissed, closed} — `closed`/`dismissed` mean the incident
     * has been resolved/handled upstream.
     */
    public function getIncidentReport(int $id): array
    {
        $response = $this->get("incident_reports/{$id}");

        return $response['incident_report'] ?? $response;
    }

    /**
     * List incident reports (auto-paginates). Pass `organization_id` to scope to one org.
     * Each row carries id, agent_id, organization_id, status {sent,dismissed,closed},
     * sent_at, closed_at, severity.
     */
    public function getIncidentReports(array $params = []): array
    {
        return $this->getAllPages('incident_reports', $params);
    }

    /**
     * Get account info.
     */
    public function getAccount(): array
    {
        return $this->get('account');
    }

    /**
     * Auto-paginate through all pages of a list endpoint.
     * Huntress uses token-based pagination: pass page_token from the previous response.
     */
    private function getAllPages(string $endpoint, array $params = [], ?array $fields = null): array
    {
        $allItems = [];
        $params['limit'] = 50;

        do {
            $response = $this->get($endpoint, $params);

            // Huntress wraps list results under the endpoint name key
            $items = $response[$endpoint] ?? $response['data'] ?? $response;

            if (! is_array($items) || empty($items)) {
                break;
            }

            // Strip to requested fields immediately to avoid OOM on large responses
            if ($fields) {
                $items = array_map(
                    fn ($item) => array_intersect_key($item, array_flip($fields)),
                    $items,
                );
            }

            $allItems = array_merge($allItems, $items);

            // Huntress token-based pagination: next_page_token is present when more pages exist
            $nextToken = $response['pagination']['next_page_token'] ?? null;
            $params['page_token'] = $nextToken;
        } while ($nextToken);

        return $allItems;
    }

    /**
     * Internal request method. Auth is configured in the Guzzle constructor.
     * Credentials are never logged.
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Accept' => 'application/json',
        ]);

        try {
            $response = $this->http->request($method, $endpoint, $options);
        } catch (GuzzleException $e) {
            Log::error("[HuntressClient] {$method} {$endpoint} failed: {$e->getMessage()}");
            throw new HuntressClientException(
                "Huntress API error: {$e->getMessage()}", $e->getCode(), $e
            );
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }
}
