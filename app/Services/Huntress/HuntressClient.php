<?php

namespace App\Services\Huntress;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class HuntressClient
{
    private Client $http;

    /**
     * @param  Client|null  $http  Injectable transport (test seam). When null the
     *                             default Basic-auth Guzzle client is built from config.
     */
    public function __construct(
        private readonly array $config,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
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
     * Get a single escalation by id.
     *
     * NOTE: GET /escalations/{id} returns the escalation object DIRECTLY — there is
     * NO {"escalation": {...}} wrapper (unlike incident_reports / organizations,
     * which do wrap). We defensively unwrap a wrapper key in case the API ever adds one.
     * The object carries status + resolved_at (resolve state), subject, subtype, type,
     * an organizations[] array, and (on the by-id view) entities.
     */
    public function getEscalation(int $id): array
    {
        $response = $this->get("escalations/{$id}");

        return $response['escalation'] ?? $response;
    }

    /**
     * List escalations (auto-paginates). Pass `organization_id` to scope to one org's
     * escalations; omit it for account-level escalations (integration-health, e.g.
     * "Failed to Deliver", which carry no organization association).
     *
     * Each row carries id, status {open,sent,resolved}, resolved_at, severity, subject,
     * type, subtype, created_at, updated_at, and an organizations[] array. `resolved_at`
     * set (or status `resolved`) means the escalation has been handled upstream.
     */
    public function getEscalations(array $params = []): array
    {
        return $this->getAllPages('escalations', $params);
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

        // The Huntress account is rate-limited (60 req/min). A 429 is transient —
        // honor Retry-After (falling back to exponential backoff) and retry a bounded
        // number of times rather than surfacing the first bump as an error.
        $maxAttempts = 3;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->http->request($method, $endpoint, $options);
                break;
            } catch (GuzzleException $e) {
                $status = $e instanceof RequestException && $e->getResponse() !== null
                    ? $e->getResponse()->getStatusCode()
                    : 0;

                if ($status === 429 && $attempt < $maxAttempts) {
                    $retryAfter = 2 ** $attempt;
                    // Distinguish an absent Retry-After from a present "0" — PHP's ?:
                    // treats the string "0" as falsy, which would wrongly ignore a
                    // server asking us to retry immediately.
                    if ($e instanceof RequestException && $e->getResponse() !== null) {
                        $header = $e->getResponse()->getHeaderLine('Retry-After');
                        if (is_numeric($header)) {
                            $retryAfter = (int) $header;
                        }
                    }
                    Log::info("[HuntressClient] Rate limited on {$endpoint}, retrying in {$retryAfter}s");
                    if ($retryAfter > 0) {
                        sleep($retryAfter);
                    }

                    continue;
                }

                Log::error("[HuntressClient] {$method} {$endpoint} failed: {$e->getMessage()}");
                throw new HuntressClientException(
                    "Huntress API error: {$e->getMessage()}", $e->getCode(), $e
                );
            }
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }
}
