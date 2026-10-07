<?php

namespace App\Services\Mesh;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
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
     * API-KEY header is added here — never logged. A failure is logged by
     * method, endpoint path (see logPath(): no scheme, authority or query, and
     * everything after a customers/ segment replaced by <customer>), HTTP
     * status and exception class only: Guzzle's message quotes the request
     * URI and a summary of the vendor's response body (C-56), and the path
     * after customers/ carries a client's Mesh customer id (#5298/#5305,
     * #5323). Only that log line is redacted. The request URI Guzzle builds
     * is not (that is what MeshClientLogPathTest observes, at a handler; what
     * a transport then puts on the wire is not observed there). There the
     * request path keeps the customer id as built: a well-formed uuid
     * unchanged, a newline in an id as %0A. Read in the vendored Guzzle,
     * not driven: that encoding is done when $endpoint is parsed as a PSR-7
     * Uri, before and apart from its RFC 3986 resolution against base_uri.
     * In that test (base_uri's path is '/') a relative endpoint takes
     * base_uri's scheme and host under a '/'-rooted path; an absolute
     * endpoint brings its own scheme, user-info, host and port; a
     * scheme-relative one (//host/...) brings its user-info, host and port
     * but takes base_uri's scheme. Query: get(), today this method's only
     * caller, always sets $options['query'] ([] by default). Measured in
     * that test with get()'s default []: a query written into a relative
     * or an absolute $endpoint does not reach the request URI, whose query
     * is empty (#5418, #5427, #5474). Read in the vendored Guzzle, not
     * driven: Client::applyOptions replaces the URI's query whenever the
     * 'query' option is set, so a non-empty $params replaces an endpoint's
     * query the same way (#5484). The
     * rethrown MeshClientException is
     * NOT redacted: its message is 'Mesh API error: ' followed by Guzzle's
     * message, which can quote the full request URI (customer id included)
     * and vendor body text, so never log $e->getMessage() from it or from
     * its previous exception.
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
            $status = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 0) : 0;
            Log::error("[MeshClient] {$method} ".self::logPath($endpoint).' failed with '
                .($status > 0 ? "HTTP {$status}" : 'no HTTP status').' ('.$e::class.')');
            throw new MeshClientException("Mesh API error: {$e->getMessage()}", $e->getCode(), $e);
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }

    /**
     * The endpoint as logged. Cut at the first '?' or '#' (strcspn, not
     * strtok, so a leading '?' still drops the query), strip any scheme and
     * authority (or a scheme-relative //authority: user-info, host and port
     * alike), then replace EVERYTHING after the
     * first customers/ segment, at any depth (api/customers/,
     * api/v2/customers/, api/partners/x/customers/, an absolute URL), with
     * the literal <customer>: the Mesh customer id and every segment after
     * it, so an id containing '/' and sub-resources are covered too (#5323).
     * <customer> is not PSR-3 {placeholder} syntax, so a channel with
     * replace_placeholders or PsrLogMessageProcessor cannot substitute a
     * context value into it (#5329). A bare api/customers/ is kept as is.
     * Only the log line uses this; the request URI is built from $endpoint
     * unredacted (see request() for how it is resolved, and when the query
     * option replaces the endpoint's query).
     */
    private static function logPath(string $endpoint): string
    {
        $cut = strcspn($endpoint, '?#');
        $path = substr($endpoint, 0, $cut);
        $path = (string) preg_replace('#^(?:[a-z][a-z0-9+.\-]*:)?//[^/]*#i', '', $path);

        return (string) preg_replace('#(^|/)(customers/).+$#is', '$1$2<customer>', $path);
    }
}
