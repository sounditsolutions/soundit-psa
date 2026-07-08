<?php

namespace App\Services\Graph;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Support\Facades\Log;

/**
 * Microsoft Graph API client using OAuth2 client credentials flow.
 *
 * Uses two Guzzle clients because the OAuth2 token endpoint (login.microsoftonline.com)
 * is on a different host than the Graph API (graph.microsoft.com).
 */
class GraphClient
{
    private const TOKEN_CACHE_KEY = 'graph_api_token';

    private const TOKEN_SAFETY_MARGIN = 60; // seconds before expiry to refresh

    private Client $http;

    private Client $authHttp;

    public function __construct(
        private readonly array $config,
        private readonly CacheInterface $cache,
    ) {
        $this->http = new Client([
            'base_uri' => 'https://graph.microsoft.com/v1.0/',
            'timeout' => $this->config['request_timeout'],
        ]);

        $this->authHttp = new Client([
            'base_uri' => 'https://login.microsoftonline.com/',
            'timeout' => $this->config['token_timeout'],
        ]);
    }

    /**
     * Make an authenticated GET request to the Graph API.
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
    }

    /**
     * Make an authenticated POST request to the Graph API.
     */
    public function post(string $endpoint, array $data): array
    {
        return $this->request('POST', $endpoint, ['json' => $data]);
    }

    /**
     * Make an authenticated PATCH request to the Graph API.
     */
    public function patch(string $endpoint, array $data): array
    {
        return $this->request('PATCH', $endpoint, ['json' => $data]);
    }

    /**
     * Make an authenticated DELETE request to the Graph API.
     */
    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Fetch all pages following @odata.nextLink, returning a flat array of results.
     *
     * Pagination is internal — callers get a simple array back.
     */
    public function getAllPages(string $endpoint, array $params = [], int $maxPages = 50): array
    {
        $results = [];
        $url = null;
        $page = 0;

        while ($page < $maxPages) {
            if ($url !== null) {
                // Follow absolute @odata.nextLink URL
                $data = $this->requestAbsolute('GET', $url);
            } else {
                $data = $this->get($endpoint, $params);
            }

            $items = $data['value'] ?? [];
            foreach ($items as $item) {
                $results[] = $item;
            }

            $page++;

            if (empty($data['@odata.nextLink'])) {
                break;
            }

            $url = $data['@odata.nextLink'];
        }

        return $results;
    }

    /**
     * Convenience: get messages from a mailbox inbox.
     */
    public function getMailboxMessages(string $mailbox, array $params = [], int $maxPages = 50): array
    {
        $endpoint = "users/{$mailbox}/mailFolders/inbox/messages";

        return $this->getAllPages($endpoint, $params, $maxPages);
    }

    /**
     * Fetch attachments for a message. Returns array of attachment metadata + content.
     * Graph returns base64-encoded contentBytes for file attachments.
     */
    public function getMessageAttachments(string $mailbox, string $messageId): array
    {
        // Counter-intuitive Graph behavior: when a message has only inline
        // attachments, hasAttachments is false AND /messages/{id}/attachments
        // returns 400. But /messages/{id}?$expand=attachments works in both
        // cases and returns the full attachment objects (including
        // contentBytes). Use $expand exclusively to cover both shapes.
        $response = $this->get(
            "users/{$mailbox}/messages/{$messageId}",
            ['$expand' => 'attachments'],
        );

        return $response['attachments'] ?? [];
    }

    /**
     * Check if the Graph API is reachable and we can authenticate.
     */
    public function isHealthy(): bool
    {
        try {
            $this->getToken();

            return true;
        } catch (GraphClientException) {
            return false;
        }
    }

    /**
     * Make an authenticated GET request and return the raw response body.
     * Returns null on 404 (e.g. user has no photo).
     */
    public function getRaw(string $endpoint): ?string
    {
        try {
            $response = $this->authenticatedRequest('GET', $endpoint);
        } catch (GraphClientException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }

        return (string) $response->getBody();
    }

    /**
     * Execute an authenticated request with automatic token retry on 401
     * and rate-limit backoff on 429.
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $response = $this->authenticatedRequest($method, $endpoint, $options);

        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new GraphClientException(
                "Invalid JSON response from Graph API: {$method} {$endpoint}",
                $response->getStatusCode(),
            );
        }

        return $decoded;
    }

    /**
     * Core authenticated request with token retry on 401 and rate-limit backoff on 429.
     * Returns the raw Guzzle response.
     */
    private function authenticatedRequest(string $method, string $endpoint, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        $token = $this->getToken();

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $maxRetries = 3;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->http->request($method, $endpoint, $options);

                return $response;
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

                    Log::warning('[GraphClient] Rate limited, backing off', [
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

        // Should never reach here, but satisfy static analysis
        throw new GraphClientException("Max retries exceeded: {$method} {$endpoint}");
    }

    /**
     * Execute an authenticated request to an absolute URL (for @odata.nextLink pagination).
     */
    private function requestAbsolute(string $method, string $url): array
    {
        $token = $this->getToken();

        try {
            $response = (new Client(['timeout' => $this->config['request_timeout']]))->request($method, $url, [
                'headers' => ['Authorization' => 'Bearer '.$token],
            ]);
        } catch (GuzzleException $e) {
            $this->throwFromGuzzle($e, $method, $url);
        }

        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new GraphClientException(
                "Invalid JSON response from Graph API: {$method} {$url}",
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

        $tenantId = $this->config['tenant_id'];

        try {
            $response = $this->authHttp->post("{$tenantId}/oauth2/v2.0/token", [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                    'scope' => 'https://graph.microsoft.com/.default',
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::error('Graph API token request failed', [
                'error' => $e->getMessage(),
            ]);
            throw new GraphClientException(
                'Failed to obtain Graph API token: '.$e->getMessage(),
            );
        }

        $data = json_decode((string) $response->getBody(), true);
        $token = $data['access_token'] ?? null;

        if (! $token) {
            throw new GraphClientException(
                'Graph API token response did not contain access_token',
            );
        }

        $ttl = ($data['expires_in'] ?? 3600) - self::TOKEN_SAFETY_MARGIN;
        $this->cache->put(self::TOKEN_CACHE_KEY, $token, max($ttl, 60));

        return $token;
    }

    /**
     * Convert a Guzzle exception into a GraphClientException.
     *
     * @throws GraphClientException
     */
    private function throwFromGuzzle(GuzzleException $e, string $method, string $endpoint): never
    {
        $statusCode = 0;
        $responseBody = null;

        if (method_exists($e, 'getResponse') && $e->getResponse()) {
            $statusCode = $e->getResponse()->getStatusCode();
            $responseBody = json_decode((string) $e->getResponse()->getBody(), true);
        }

        Log::error('Graph API request failed', [
            'method' => $method,
            'endpoint' => $endpoint,
            'status' => $statusCode,
            'error' => $e->getMessage(),
        ]);

        throw new GraphClientException(
            "Graph API error: {$method} {$endpoint} returned {$statusCode}",
            $statusCode,
            $responseBody,
        );
    }
}
