<?php

namespace App\Services\Huntress;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class HuntressClient
{
    /**
     * Hard ceiling on any single 429 back-off sleep, mirroring
     * HuntressWriteClient::RETRY_AFTER_CEILING_SECONDS. Retry-After is an
     * UPSTREAM-CONTROLLED value, and this is not only a background sync
     * client: the staged escalation-resolve path re-reads the escalation LIVE
     * inside the synchronous cockpit approve request, holding that run's
     * execution claim while it sleeps. An unclamped `Retry-After: 3600` would
     * park the PHP worker — and the claim — for an hour, which is exactly the
     * bound StaffHuntressActionToolExecutor::STALE_CLAIM_SECONDS is measured
     * against. Two retries at the ceiling bound the added wall time at 20 s.
     */
    public const RETRY_AFTER_CEILING_SECONDS = 10;

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
        // honor Retry-After up to RETRY_AFTER_CEILING_SECONDS (falling back to
        // exponential backoff) and retry a bounded number of times rather than
        // surfacing the first bump as an error.
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
                    $header = $e instanceof RequestException && $e->getResponse() !== null
                        ? $e->getResponse()->getHeaderLine('Retry-After')
                        : '';
                    $retryAfter = self::retryDelaySeconds($header, $attempt);
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

    /**
     * The 429 back-off for one attempt: the upstream Retry-After when it is a
     * numeric value, else exponential (2s, 4s) — ALWAYS clamped to
     * RETRY_AFTER_CEILING_SECONDS, because the header is upstream-controlled
     * and this sleep can run inside a synchronous approval that is holding a
     * run's execution claim. Mirrors HuntressWriteClient::retryDelaySeconds().
     *
     * A present "0" stays distinguished from an absent header — PHP's ?: treats
     * the string "0" as falsy, which would wrongly ignore a server asking us to
     * retry immediately; a negative value likewise means no wait (the caller
     * sleeps only on a positive delay).
     */
    public static function retryDelaySeconds(string $retryAfterHeader, int $attempt): int
    {
        $delay = 2 ** max(1, $attempt);
        if (is_numeric($retryAfterHeader)) {
            $delay = (int) $retryAfterHeader;
        }

        return min($delay, self::RETRY_AFTER_CEILING_SECONDS);
    }
}
