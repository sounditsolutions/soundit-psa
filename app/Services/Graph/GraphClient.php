<?php

namespace App\Services\Graph;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

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

    /**
     * #5823: the largest expires_in the token is cached against (one day). A larger value is
     * clamped to this, not refused: the token itself is still good. Without the ceiling an
     * oversized value (400000000, or a numeric string that saturates to PHP_INT_MAX) overflowed
     * the database cache store's 32-bit expiration column on the cache write.
     */
    private const TOKEN_EXPIRES_IN_CEILING = 86400;

    /**
     * #5825: the longest single 429 back-off wait, in seconds. A larger Retry-After is clamped
     * to it, so three back-offs wait at most three minutes in all.
     */
    private const RETRY_AFTER_CEILING_SECONDS = 60;

    private Client $http;

    private Client $authHttp;

    public function __construct(
        private readonly array $config,
        private readonly CacheInterface $cache,
    ) {
        // #5824: the Graph data clients never follow a redirect either. None of the Graph
        // endpoints this client calls is documented to answer 3xx, and a followed redirect
        // replays a POST body to the Location of a 307/308 (another host included) and, on an
        // unparseable Location, throws psr7's MalformedUriException, whose message is the
        // Location and which is not a GuzzleException. A 3xx is refused by refuseRedirect()
        // from its status alone; its Location is never read.
        $httpOptions = [
            'base_uri' => 'https://graph.microsoft.com/v1.0/',
            'timeout' => $this->config['request_timeout'],
            'allow_redirects' => false,
        ];

        // Optional Guzzle handler injection for wire-level tests (mirrors ServosityClient's
        // config `handler` seam). Production config never sets it; a test injects a MockHandler
        // + history stack to capture the OUTGOING request URI and prove a caller-controlled path
        // segment cannot escape the allowlisted mailbox path (psa-abl0i.1 security spine).
        if (isset($this->config['handler'])) {
            $httpOptions['handler'] = $this->config['handler'];
        }

        $this->http = new Client($httpOptions);

        // The token leg honours the SAME `handler` seam as the three Graph clients above.
        // It is the only request that carries `client_secret`, so leaving it unseamed made
        // the one credential-bearing call the one no test could observe or refuse. Production
        // config never sets `handler`; the seam is test-only in effect (see :35-36).
        // #5736: the token POST never follows a redirect. Guzzle's default replays the form body,
        // client_secret included, to the Location of a 307/308, which can be another host. A 3xx
        // is refused in getToken() by its status alone; its Location is never parsed (#5739).
        $authOptions = [
            'base_uri' => 'https://login.microsoftonline.com/',
            'timeout' => $this->config['token_timeout'],
            'allow_redirects' => false,
        ];

        if (isset($this->config['handler'])) {
            $authOptions['handler'] = $this->config['handler'];
        }

        $this->authHttp = new Client($authOptions);
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
     * Raw contents of one message attachment:
     * GET /users/{mailbox}/messages/{messageId}/attachments/{attachmentId}/$value.
     *
     * Vendor contract (https://learn.microsoft.com/en-us/graph/api/attachment-get, "Get the raw
     * contents of a file or item attachment"): for an itemAttachment Graph returns the item in
     * MIME format for a message, vCard for a contact and iCal for an event; a
     * referenceAttachment answers 405. Every caller-controlled segment goes through seg(), as
     * the calendar reads do. Errors are GraphClientException from authenticatedRequest (one
     * token retry on 401, backoff on 429, the configured request_timeout). A 2xx is returned
     * as-is, including an empty body; the caller decides what an empty body means.
     *
     * throwFromGuzzle writes no error record for a failed request here (#5144): the only caller
     * treats it as a soft skip and reports it itself, with ids, a reason token and either the
     * HTTP status or the exception class, never the exception message. Other Graph calls keep
     * throwFromGuzzle's error record. getToken()'s and the back-off's shared records can still
     * be written on this path (#5837), none with a value: a 429 back-off WARNING (status, attempt
     * and wait only, no endpoint; #5672); the cached-token-malformed WARNING (type and reason;
     * #5720, #5840); when the token request fails, the token-request ERROR (status and
     * exception class on a 4xx/5xx or when no response arrived, status null then; status only
     * on a 3xx; #5397, #5736); the malformed-token-response ERROR (status, field, type and reason;
     * #5659); and the token-cache-write ERROR (exception class only; #5823). A token response
     * without access_token writes no record. A 401 whose token refresh then fails is thrown as
     * GraphTokenRefreshFailedException, status 401 (#5398).
     */
    public function getMessageAttachmentRaw(string $mailbox, string $messageId, string $attachmentId): string
    {
        $endpoint = 'users/'.self::seg($mailbox)
            .'/messages/'.self::seg($messageId)
            .'/attachments/'.self::seg($attachmentId).'/$value';

        return (string) $this->authenticatedRequest('GET', $endpoint, logFailure: false)->getBody();
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
     * Tool-level calendar response ('accept'|'decline'|'tentative') → the MS Graph event action
     * segment. 'tentative' is the Graph `tentativelyAccept` action (a trap: the tool vocabulary and
     * the Graph vocabulary differ). Source: MS Graph v1.0 —
     * https://learn.microsoft.com/en-us/graph/api/event-accept (and event-decline / event-tentativelyaccept).
     */
    private const RESPOND_ACTIONS = [
        'accept' => 'accept',
        'decline' => 'decline',
        'tentative' => 'tentativelyAccept',
    ];

    /**
     * Create an event on an owner's calendar (POST /users/{upn}/events → 201 + event resource).
     * $event is the Graph event-resource body (camelCase), built by the executor from validated
     * tool args. Field shape: MS Graph v1.0 event resource —
     * https://learn.microsoft.com/en-us/graph/api/user-post-events and .../resources/event.
     *
     * FAIL-LOUD (CLAUDE.md vendor rule): the created event is proven through CalendarGraphShapes —
     * a 2xx carrying a bodyless/malformed event (no string id) throws GraphShapeDriftException. A
     * write whose success we cannot confirm must SCREAM, never read as "done". $upn is percent-
     * encoded at the transport seam (seg()) — defence in depth behind the executor allowlist.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function createEvent(string $upn, array $event): array
    {
        return CalendarGraphShapes::assertEvent(
            $this->requestJson('POST', 'users/'.self::seg($upn).'/events', ['json' => $event])
        );
    }

    /**
     * Update an event on an owner's calendar (PATCH /users/{upn}/events/{eventId} → 200 + event).
     * $patch carries ONLY the fields to change (partial event resource). Returns the updated,
     * shape-proven event. Field shape: https://learn.microsoft.com/en-us/graph/api/event-update.
     * Both caller-controlled segments (upn, eventId) are seg()-encoded — the same traversal control
     * proven for the reads (GraphClientPathSafetyTest).
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function updateEvent(string $upn, string $eventId, array $patch): array
    {
        return CalendarGraphShapes::assertEvent(
            $this->requestJson('PATCH', 'users/'.self::seg($upn).'/events/'.self::seg($eventId), ['json' => $patch])
        );
    }

    /**
     * Cancel a meeting the owner organizes (POST /users/{upn}/events/{eventId}/cancel → 202, no
     * body). ORGANIZER-ONLY upstream: an attendee calling this gets HTTP 400 (which surfaces as a
     * GraphClientException, not a silent success). Body param `comment` — the documented
     * parameter-table name (the doc's HTTP example shows `Comment`, but Graph action params are
     * case-insensitive and the table is normative). With no comment we post `{}` (an empty JSON
     * object), never a stray key. https://learn.microsoft.com/en-us/graph/api/event-cancel
     */
    public function cancelEvent(string $upn, string $eventId, ?string $comment = null): void
    {
        $payload = [];
        if ($comment !== null) {
            $payload['comment'] = $comment;
        }

        $this->requestJson('POST', 'users/'.self::seg($upn).'/events/'.self::seg($eventId).'/cancel', [
            'json' => (object) $payload,
        ]);
    }

    /**
     * Respond to a meeting invite as the owner mailbox (POST /users/{upn}/events/{eventId}/{action}
     * → 202, no body), where {action} is accept / decline / tentativelyAccept. $response is the
     * tool-level verb ('accept'|'decline'|'tentative'); an unknown value is refused before any call
     * (defence in depth behind the executor enum). `sendResponse` (Graph default true) and the
     * optional `comment` are the documented body params.
     * https://learn.microsoft.com/en-us/graph/api/event-accept
     */
    public function respondEvent(string $upn, string $eventId, string $response, ?string $comment = null, bool $sendResponse = true): void
    {
        $action = self::RESPOND_ACTIONS[$response] ?? null;
        if ($action === null) {
            throw new \InvalidArgumentException("Unknown calendar response '{$response}'; expected accept, decline, or tentative.");
        }

        $payload = ['sendResponse' => $sendResponse];
        if ($comment !== null) {
            $payload['comment'] = $comment;
        }

        $this->requestJson('POST', 'users/'.self::seg($upn).'/events/'.self::seg($eventId).'/'.$action, [
            'json' => $payload,
        ]);
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
                "Invalid JSON response from Graph API: {$method} returned {$response->getStatusCode()}",
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
                "Invalid JSON response from Graph API: {$method} returned {$response->getStatusCode()}",
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

        $clientOptions = ['timeout' => $this->config['request_timeout'], 'allow_redirects' => false];
        if (isset($this->config['handler'])) {
            $clientOptions['handler'] = $this->config['handler'];
        }

        try {
            // #6120: the bearer rides on the Request object only, never in an options array.
            $response = (new Client($clientOptions))->send((new Request('GET', $url))->withHeader('Authorization', 'Bearer '.$token));
        } catch (GuzzleException $e) {
            $this->throwFromGuzzle($e, 'GET', $url);
        }
        $this->refuseRedirect($response, 'GET', $url);

        $body = (string) $response->getBody();
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new GraphClientException(
                "Invalid JSON response from Graph API: GET returned {$response->getStatusCode()}",
                $response->getStatusCode(),
            );
        }

        return $decoded;
    }

    /**
     * Core authenticated request with token retry on 401 and rate-limit backoff on 429.
     * Returns the raw Guzzle response. $logFailure false skips throwFromGuzzle's error record
     * for a caller that reports the failure itself.
     */
    private function authenticatedRequest(string $method, string $endpoint, array $options = [], bool $logFailure = true): \Psr\Http\Message\ResponseInterface
    {
        // #6023 / #6120: the bearer is held in the local $token only. At each send (the first,
        // the 401 retry with the refreshed token and every 429 retry) it is set on a PSR-7
        // Request built in place and handed to Client::send() with $options, which never holds
        // it. So no string or array argument of this frame, of Guzzle's send/sendAsync/transfer
        // frames or of the middleware frames holds the bearer; GraphClientBearerFrameTest
        // measures exactly those frames, with a scripted handler. #6188: that is not a claim
        // about the transport handler below them. Guzzle's CurlFactory copies the request
        // headers into its $conf array, and a throwable raised while $conf is a frame argument
        // (for example the InvalidArgumentException applyHandlerOptions() raises for a missing
        // CA bundle) carries the bearer in that array when zend.exception_ignore_args is Off.
        // StreamHandler builds the header line into its stream-context array too; that is read
        // from Guzzle's source and not measured by any test here. Such a throwable is not a
        // GuzzleException, so this method's catch does not take it (also source-read only). withHeader() and the PSR-7
        // header validation it calls also take it as a string (withHeader() throws only for a
        // value tokenShapeFault() already refuses, #5738). Guzzle applies $options ('query',
        // 'json') and the base URI to the Request exactly as request() did.
        $token = $this->getToken();

        $maxRetries = 3;

        // #5734: no loop condition. Every iteration returns, throws (throwFromGuzzle is never), or
        // continues on a 401 at attempt 0 or a 429 below $maxRetries, so the loop ends by the
        // fourth request at the latest and nothing after it can run. #5825: in time it is bounded
        // by those requests' own timeouts plus at most three back-off waits, each at most
        // RETRY_AFTER_CEILING_SECONDS.
        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->http->send((new Request($method, $endpoint))->withHeader('Authorization', 'Bearer '.$token), $options);
                $this->refuseRedirect($response, $method, $endpoint, $logFailure);

                return $response;
            } catch (GuzzleException $e) {
                $statusCode = method_exists($e, 'getResponse') && $e->getResponse()
                    ? $e->getResponse()->getStatusCode()
                    : 0;

                // Retry once on 401 with a fresh token
                if ($statusCode === 401 && $attempt === 0) {
                    $this->cache->forget(self::TOKEN_CACHE_KEY);
                    try {
                        $freshToken = $this->getToken();
                    } catch (GraphClientException $tokenFailure) {
                        // #5398: Graph answered 401 and the refresh failed. getToken() writes its
                        // own record when the token request failed or answered a 3xx, or its
                        // response was malformed (#5659, #5729); a response with no access_token
                        // or a null one writes none. The cache key was forgotten just above, so
                        // getToken() finds no cached value and writes no cached-token record on
                        // this call unless another process cached a value in between (#5830).
                        // This
                        // arm never goes through throwFromGuzzle. With $logFailure its record
                        // carries exactly four fields: method, operation (operationLabel(), as on
                        // the other two arms; #6075), status (401) and token_refresh ('failed').
                        if ($logFailure) {
                            Log::error('Graph API request failed', [
                                'method' => $method,
                                'operation' => self::operationLabel($endpoint),
                                'status' => 401,
                                'token_refresh' => 'failed',
                            ]);
                        }

                        throw new GraphTokenRefreshFailedException($method, $tokenFailure);
                    }
                    $token = $freshToken;

                    continue;
                }

                // Back off and retry on 429 (rate limited)
                if ($statusCode === 429 && $attempt < $maxRetries) {
                    $waitSeconds = self::backoffSeconds((string) $e->getResponse()?->getHeaderLine('Retry-After'), $attempt);

                    // #5672 / C-56: status, attempt and wait only. The endpoint is never logged:
                    // besides the users/ segment (#5391) it holds message, chat, attachment and
                    // subscription ids.
                    Log::warning('[GraphClient] Rate limited, backing off', [
                        'status' => 429,
                        'attempt' => $attempt + 1,
                        'wait_seconds' => $waitSeconds,
                    ]);

                    Sleep::sleep($waitSeconds);

                    continue;
                }

                $this->throwFromGuzzle($e, $method, $endpoint, $logFailure);
            }
        }
    }

    /**
     * #5825: the seconds to wait before retrying a 429. A numeric Retry-After above zero is used,
     * clamped to RETRY_AFTER_CEILING_SECONDS. A missing, non-numeric, zero or negative one takes
     * the default back-off of 10, 20 and 30 seconds for the first, second and third retry. The
     * result is never negative (sleep() throws ValueError on a negative value). The HTTP-date
     * form of Retry-After is not numeric and takes the default.
     */
    private static function backoffSeconds(string $retryAfter, int $attempt): int
    {
        $retryAfter = trim($retryAfter);
        if (is_numeric($retryAfter) && (float) $retryAfter > 0) {
            return (int) ceil(min((float) $retryAfter, self::RETRY_AFTER_CEILING_SECONDS));
        }

        return 10 * ($attempt + 1);
    }

    /**
     * Execute an authenticated request to an absolute URL (for @odata.nextLink pagination).
     */
    private function requestAbsolute(string $method, string $url): array
    {
        $token = $this->getToken();

        $clientOptions = ['timeout' => $this->config['request_timeout'], 'allow_redirects' => false];
        if (isset($this->config['handler'])) {
            $clientOptions['handler'] = $this->config['handler'];
        }

        try {
            // #6120: as requestJsonAbsolute(), the bearer is on the Request object only.
            $response = (new Client($clientOptions))->send((new Request($method, $url))->withHeader('Authorization', 'Bearer '.$token));
        } catch (GuzzleException $e) {
            $this->throwFromGuzzle($e, $method, $url);
        }
        $this->refuseRedirect($response, $method, $url);

        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new GraphClientException(
                "Invalid JSON response from Graph API: {$method} returned {$response->getStatusCode()}",
                $response->getStatusCode(),
            );
        }

        return $decoded;
    }

    /**
     * Get an OAuth2 access token, cached across requests.
     *
     * Every failure to obtain a token is a GraphTokenException (#5728) on one of four arms: the
     * token endpoint answered a 3xx or a 4xx/5xx (message "(HTTP n)"), no response arrived
     * (message names the Guzzle exception class), the response had no access_token or a null
     * one, or the response carried a malformed access_token or expires_in (see
     * tokenShapeFault()). A failure to write the new token to the cache is not one of them
     * (#5823): it is recorded by exception class only and the token is returned uncached. A
     * failure of the cache read or forget is the cache store's own exception and is not caught
     * here; neither call is given the token.
     */
    private function getToken(): string
    {
        $cached = $this->cache->get(self::TOKEN_CACHE_KEY);
        if ($cached !== null) {
            // #5720: a cached value passes the same access_token check as a fresh one before it
            // is sent. One that fails it (an older build cached it, or the store was written by
            // hand) is dropped and a new token is requested. The record names the PHP type and
            // tokenShapeFault()'s fixed reason (#5840), never the value.
            $cachedFault = self::tokenShapeFault($cached);
            if ($cachedFault === null) {
                return $cached;
            }
            Log::warning('Graph API cached token malformed, requesting a new one', [
                'type' => get_debug_type($cached),
                'reason' => $cachedFault,
            ]);
            $this->cache->forget(self::TOKEN_CACHE_KEY);
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
            // #5397 / C-56: Guzzle's message carries the token URL (tenant id) and the identity
            // provider's error body. Neither the record nor the exception message carries it:
            // the HTTP status when the token endpoint answered (null when it did not) and the
            // exception class only.
            $status = method_exists($e, 'getResponse') && $e->getResponse()
                ? $e->getResponse()->getStatusCode()
                : null;
            Log::error('Graph API token request failed', [
                'status' => $status,
                'exception' => $e::class,
            ]);
            throw new GraphTokenException(
                'Failed to obtain Graph API token'.($status !== null ? " (HTTP {$status})" : ' ('.$e::class.')'),
            );
        }

        // #5736 / #5739: redirects are off on this client, so Guzzle returns a 3xx instead of
        // following it. It is refused by its status alone; the Location header is never read,
        // parsed or recorded.
        $status = $response->getStatusCode();
        if ($status >= 300) {
            Log::error('Graph API token request failed', [
                'status' => $status,
            ]);
            throw new GraphTokenException("Failed to obtain Graph API token (HTTP {$status})");
        }

        $data = json_decode((string) $response->getBody(), true);
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;

        if ($token === null) {
            throw new GraphTokenException(
                'Graph API token response did not contain access_token',
            );
        }

        // #5659 / #5729 / #5730 / #5738: prove the shape before anything is cached. A present
        // access_token that tokenShapeFault() refuses, and an expires_in that is null, not
        // numeric, or under one second, take this arm and nothing is cached. An absent
        // expires_in is read as 3600. The record names the field, the type PHP decoded it as and
        // a fixed reason (#5840: tokenShapeFault()'s, or 'not numeric' / 'under one second' for
        // expires_in), never its value (C-56).
        // #5823: a numeric expires_in is clamped to TOKEN_EXPIRES_IN_CEILING before it is cast,
        // so an oversized value (a float or a saturating numeric string) is cached for one day
        // rather than refused or overflowing the cache store.
        $expiresIn = array_key_exists('expires_in', $data) ? $data['expires_in'] : 3600;
        $seconds = is_numeric($expiresIn) ? (int) max(0, min((float) $expiresIn, self::TOKEN_EXPIRES_IN_CEILING)) : 0;
        $tokenFault = self::tokenShapeFault($token);
        $malformed = match (true) {
            $tokenFault !== null => ['access_token', $token, $tokenFault],
            ! is_numeric($expiresIn) => ['expires_in', $expiresIn, 'not numeric'],
            $seconds < 1 => ['expires_in', $expiresIn, 'under one second'],
            default => null,
        };
        if ($malformed !== null) {
            Log::error('Graph API token response malformed', [
                'status' => $status,
                'field' => $malformed[0],
                'type' => get_debug_type($malformed[1]),
                'reason' => $malformed[2],
            ]);
            throw new GraphTokenException("Graph API token response carried a malformed {$malformed[0]}");
        }

        // The token is cached for expires_in less the safety margin, but never under 60 seconds
        // and never for longer than expires_in seconds from when the response arrived (#5735).
        // #5827: so the margin is the full 60 seconds only from expires_in 120 up; from 61 to
        // 120 it is expires_in - 60, and from 1 to 60 it is none. The token's lifetime started
        // when the identity provider issued it, before the response arrived, so at those values
        // the cached token can be sent for up to that latency after it expired. Graph then
        // answers 401 and authenticatedRequest's attempt-0 refresh requests a new one.
        // #5823: a failing cache write (a database store throws an exception whose message holds
        // the bindings, the token among them) is recorded by its class only, never its message,
        // and the token is returned uncached.
        try {
            $this->cache->put(self::TOKEN_CACHE_KEY, $token, min(max($seconds - self::TOKEN_SAFETY_MARGIN, 60), $seconds));
        } catch (\Throwable $e) {
            Log::error('Graph API token cache write failed', [
                'exception' => $e::class,
            ]);
        }

        return $token;
    }

    /**
     * Why $token cannot be sent as a bearer, or null when it can. It must be a string, not '' or
     * '0' (both were refused before #5729 as a missing token, and neither is a bearer), with no
     * control character (#5738: psr7 refuses a header value holding any control character
     * other than TAB with an exception whose message holds the whole value, the token included;
     * #5835: psr7 accepts TAB, which is refused here only because no bearer carries one). Returns
     * a fixed reason, never the value.
     */
    private static function tokenShapeFault(mixed $token): ?string
    {
        return match (true) {
            ! is_string($token) => 'not a string',
            $token === '' || $token === '0' => 'empty',
            preg_match('/[\x00-\x1F\x7F]/', $token) === 1 => 'control character',
            default => null,
        };
    }

    /**
     * #5824: a Graph data request answered 3xx. Redirects are off, so Guzzle returned it as a
     * response rather than following it or throwing. It is refused by its status alone: the
     * record (when $log) carries the method, the operation label (operationLabel() of the
     * request path or nextLink, as throwFromGuzzle's does; #6075) and the status, and the
     * GraphClientException message the method and the status. The Location header is never
     * read, parsed or recorded, and the endpoint is read only by operationLabel().
     */
    private function refuseRedirect(\Psr\Http\Message\ResponseInterface $response, string $method, string $endpoint, bool $log = true): void
    {
        $status = $response->getStatusCode();
        if ($status < 300) {
            return;
        }
        if ($log) {
            Log::error('Graph API request failed', [
                'method' => $method,
                'operation' => self::operationLabel($endpoint),
                'status' => $status,
            ]);
        }

        throw new GraphClientException("Graph API error: {$method} returned {$status}", $status);
    }

    /**
     * #5671: the Graph resource nouns and actions ('sendMail', 'reply') that may name a failed
     * request's operation in a log record, each in its canonical spelling. The record's
     * 'operation' is one of these literals or the fallback 'other' (#6070).
     */
    private const OPERATION_NOUNS = [
        'messages', 'attachments', 'mailFolders', 'sendMail', 'reply', 'events', 'calendar',
        'calendarView', 'subscriptions', 'chats', 'channels', 'teams', 'hostedContents', 'photo',
        'users', 'groups',
    ];

    /**
     * #5671 / C-56: an endpoint-free operation label for a request path or an absolute URL. The
     * query and fragment are cut off. For an absolute URL the scheme and the whole authority
     * (user-info, host and port) are dropped, and so is the authority of a protocol-relative
     * one ('//host/...', #6079). The remaining path segments are compared against
     * OPERATION_NOUNS case-insensitively (#6068: Graph paths are case-insensitive and change
     * notifications deliver 'Users/{id}/Messages/{id}'), each after percent-decoding (#6126:
     * 'm%65ssages' is 'messages', as Graph reads it; an encoded '/' inside a segment stays inside
     * it), and the canonical spelling of the last one that matches is returned. With no match
     * the label is 'other'. A leading '///' strips only the empty authority '//' and the path
     * after it is read as usual. The returned value is always one of those literals; it is never
     * copied from the endpoint, but the rule does not know which segments are ids: an id (a
     * folder, chat, message or event id) whose decoded text equals a noun in any letter case
     * ('teams', 'Events', 'MESSAGES') is matched like the noun, and as the last match it becomes
     * the label (#6078, #6126). Telling ids from nouns needs each endpoint's shape, which this
     * label deliberately does not encode.
     */
    private static function operationLabel(string $endpoint): string
    {
        $path = preg_split('/[?#]/', $endpoint, 2)[0];
        $path = preg_replace('#^(?:[A-Za-z][A-Za-z0-9+.\-]*:)?//[^/]*#', '', $path);

        $label = 'other';
        foreach (explode('/', $path) as $segment) {
            foreach (self::OPERATION_NOUNS as $noun) {
                if (strcasecmp(rawurldecode($segment), $noun) === 0) {
                    $label = $noun;
                }
            }
        }

        return $label;
    }

    /**
     * Convert a Guzzle exception into a GraphClientException.
     *
     * @throws GraphClientException
     */
    private function throwFromGuzzle(GuzzleException $e, string $method, string $endpoint, bool $log = true): never
    {
        $statusCode = 0;
        $responseBody = null;

        if (method_exists($e, 'getResponse') && $e->getResponse()) {
            $statusCode = $e->getResponse()->getStatusCode();
            $responseBody = json_decode((string) $e->getResponse()->getBody(), true);
        }

        // #5533 / #5679 / #5671 / C-56: the record carries the method, the status and
        // 'operation', a label from operationLabel() (one of OPERATION_NOUNS or 'other'; the
        // refuseRedirect and 401-refresh records carry it too, #6075), and the exception message
        // the method and either the status or that no response arrived. Neither carries the
        // endpoint (it holds the mailbox on users/ paths, and the nextLink URL on
        // requestAbsolute) or Guzzle's message (the request URI and the start of Graph's response
        // body). Graph's decoded body stays on getResponseBody() for a caller that reads it on
        // purpose.
        // #5673 / #5723: with no response (status 0) the record also carries the Guzzle exception
        // class, the only cause it can carry; it is a class name, never Guzzle's text.
        if ($log) {
            Log::error('Graph API request failed', [
                'method' => $method,
                'operation' => self::operationLabel($endpoint),
                'status' => $statusCode,
            ] + ($statusCode === 0 ? ['exception' => $e::class] : []));
        }

        // #5722: status 0 means nothing answered, so the message says that, not "returned 0".
        throw new GraphClientException(
            $statusCode === 0
                ? "Graph API error: {$method} received no response"
                : "Graph API error: {$method} returned {$statusCode}",
            $statusCode,
            $responseBody,
        );
    }
}
