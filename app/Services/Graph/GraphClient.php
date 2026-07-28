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
        $httpOptions = [
            'base_uri' => 'https://graph.microsoft.com/v1.0/',
            'timeout' => $this->config['request_timeout'],
        ];

        // Optional Guzzle handler injection for wire-level tests (mirrors ServosityClient's
        // config `handler` seam). Production config never sets it; a test injects a MockHandler
        // + history stack to capture the OUTGOING request URI and prove a caller-controlled path
        // segment cannot escape the allowlisted mailbox path (psa-abl0i.1 security spine).
        if (isset($this->config['handler'])) {
            $httpOptions['handler'] = $this->config['handler'];
        }

        $this->http = new Client($httpOptions);

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
     * Calendar events in a time window (GET /users/{upn}/calendarView). Returns the flat list of
     * event resources; @odata.nextLink pagination is handled internally. Times come back in UTC
     * unless a `Prefer: outlook.timezone` header is sent — we send none (the repo stores + renders
     * UTC via toAppTz()). Field shape: the MS Graph v1.0 event resource (camelCase) —
     * https://learn.microsoft.com/en-us/graph/api/user-list-calendarview and
     * https://learn.microsoft.com/en-us/graph/api/resources/event
     */
    public function calendarView(string $upn, string $start, string $end, int $maxPages = 50): array
    {
        return $this->fetchCalendarPages('users/'.self::seg($upn).'/calendarView', [
            'startDateTime' => $start,
            'endDateTime' => $end,
        ], $maxPages);
    }

    /**
     * Strict, identity-preserving paginated GET for calendar collections. Unlike the shared
     * getAllPages(), this NEVER returns a silently-truncated, malformed, or object-collapsed result
     * (psa-abl0i.2/.5, the CLAUDE.md "degraded read must SCREAM" rule): each page is OBJECT-mode
     * decoded and proven (value must be a genuine JSON list — a "{}" object is drift, not an empty
     * calendar — and every event is proven), the @odata.nextLink is proven a non-empty https
     * graph.microsoft.com URL before it is followed with the app bearer or read as the end of the
     * list, and a page-cap hit with a cursor still pending is TRUNCATION (throws). Kept separate
     * from getAllPages() so other (lenient) consumers are unchanged.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchCalendarPages(string $endpoint, array $params, int $maxPages): array
    {
        $results = [];
        $url = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $data = $url !== null
                ? $this->requestJsonAbsolute($url)
                : $this->requestJson('GET', $endpoint, ['query' => $params]);

            foreach (CalendarGraphShapes::assertCalendarPage($data) as $event) {
                $results[] = $event;
            }

            $next = CalendarGraphShapes::provenNextLink($data);
            if ($next === null) {
                return $results;
            }
            $url = $next;
        }

        throw new GraphShapeDriftException("Microsoft Graph calendarView exceeded the {$maxPages}-page cap while more results remained — refusing to present a truncated calendar window as complete.");
    }

    /**
     * Percent-encode a single caller-controlled path segment before it is interpolated into a
     * Graph URL. THE SECURITY SPINE at the transport seam (psa-abl0i.1 security review): a raw
     * segment such as event_id = "../../../users/other@x/events/ID" would otherwise let Guzzle's
     * RFC 3986 dot-segment resolution walk OUT of the allowlisted mailbox path and re-target a
     * different (non-allowlisted) mailbox — bypassing the sole control, now that the Azure
     * Application Access Policy is dropped. rawurlencode turns every "/" into "%2F" and leaves no
     * bare "." segment delimited by real separators, so the value can only ever occupy its own
     * segment. Applied to EVERY caller-controlled segment (the owner UPN and any id): the
     * allowlist gate proves WHICH UPN is permitted; this proves the wire target IS that UPN and
     * nothing else. (It also correctly encodes legitimate opaque Graph event ids, which may
     * contain "/", "+" or "=".)
     */
    private static function seg(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * A single calendar event (GET /users/{upn}/events/{eventId}) — same event-resource shape as
     * calendarView. https://learn.microsoft.com/en-us/graph/api/event-get
     */
    public function getEvent(string $upn, string $eventId): array
    {
        return CalendarGraphShapes::assertEvent(
            $this->requestJson('GET', 'users/'.self::seg($upn).'/events/'.self::seg($eventId))
        );
    }

    /**
     * Free/busy availability for a set of mailboxes over a window
     * (POST /users/{upn}/calendar/getSchedule). {upn} is the calendar the getSchedule action is
     * invoked THROUGH; $schedules are the mailbox UPNs whose free/busy to return. Returns the flat
     * scheduleInformation list (the OData `value` envelope is unwrapped). startTime/endTime are
     * Graph dateTimeTimeZone objects — the repo works in UTC, so we send timeZone=UTC and the
     * caller passes UTC ISO-8601 datetimes.
     *
     * Field shape verified against a captured LIVE app-token payload (scheduleInformation:
     * scheduleId, availabilityView, scheduleItems[], workingHours) — MS Graph v1.0:
     * https://learn.microsoft.com/en-us/graph/api/calendar-getschedule
     *
     * @param  list<string>  $schedules
     * @return array<int, array<string, mixed>>
     */
    public function getSchedule(string $upn, array $schedules, string $start, string $end, int $interval = 30): array
    {
        // Object-mode decode (requestJson) so a malformed "value": {} cannot collapse to [] and read
        // as an empty/all-free grid. Fail loud on drift: a swallowed error, a dropped mailbox, or a
        // row with no availability data reads as "that person is FREE" — prove the envelope, the
        // availability-bearing fields, and every REQUESTED mailbox 1:1 before returning.
        $response = $this->requestJson('POST', 'users/'.self::seg($upn).'/calendar/getSchedule', [
            'json' => [
                'schedules' => array_values($schedules),
                'startTime' => ['dateTime' => $start, 'timeZone' => 'UTC'],
                'endTime' => ['dateTime' => $end, 'timeZone' => 'UTC'],
                'availabilityViewInterval' => $interval,
            ],
        ]);

        return CalendarGraphShapes::assertScheduleCollection($response, array_values($schedules));
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
     * Identity-preserving variant of request() for CALENDAR reads: decodes with json_decode() in
     * OBJECT mode (a JSON object → stdClass, a JSON list → array), so a `{}` and a `[]` stay
     * distinguishable and a malformed object envelope cannot collapse to an empty result
     * (psa-abl0i.5). CalendarGraphShapes proves the returned shape. Only calendar reads use this;
     * every other consumer keeps request()'s assoc decode unchanged.
     */
    private function requestJson(string $method, string $endpoint, array $options = []): mixed
    {
        $response = $this->authenticatedRequest($method, $endpoint, $options);

        $body = (string) $response->getBody();
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new GraphClientException(
                "Invalid JSON response from Graph API: {$method} {$endpoint}",
                $response->getStatusCode(),
            );
        }

        return $decoded;
    }

    /**
     * Object-mode fetch of an absolute @odata.nextLink URL for the strict calendar paginator. The
     * caller (CalendarGraphShapes::provenNextLink) has already proven the URL is a non-empty https
     * graph.microsoft.com URL before this attaches the tenant app bearer to it.
     */
    private function requestJsonAbsolute(string $url): mixed
    {
        $token = $this->getToken();

        $clientOptions = ['timeout' => $this->config['request_timeout']];
        if (isset($this->config['handler'])) {
            $clientOptions['handler'] = $this->config['handler'];
        }

        try {
            $response = (new Client($clientOptions))->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer '.$token],
            ]);
        } catch (GuzzleException $e) {
            $this->throwFromGuzzle($e, 'GET', $url);
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new GraphClientException(
                "Invalid JSON response from Graph API: GET {$url}",
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

        $clientOptions = ['timeout' => $this->config['request_timeout']];
        if (isset($this->config['handler'])) {
            $clientOptions['handler'] = $this->config['handler'];
        }

        try {
            $response = (new Client($clientOptions))->request($method, $url, [
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
