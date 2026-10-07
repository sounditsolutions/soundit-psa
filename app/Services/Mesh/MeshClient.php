<?php

namespace App\Services\Mesh;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class MeshClient
{
    /** What logPath() logs for an endpoint whose user-info it cannot cut off safely (#5761). */
    private const UNPARSEABLE_ENDPOINT = '[unparseable endpoint]';

    /** A host (bracketed IPv6 literal, or no ':', '[' or ']') with an optional ':' and digits. */
    private const HOST_PORT = '#^(?:\[[^\]]*\]|[^:\[\]]*)(?::[0-9]*)?$#';

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
     * method, endpoint path (see logPath(): never any user-info, neither
     * user nor password (#5761); no query or fragment; a scheme and '//'
     * at the very START of the endpoint are stripped with the authority
     * that follows the '//' (a '//' later in the path strips nothing);
     * and everything after a customers/ segment replaced by <customer>.
     * With no leading '//' only the user-info is cut from the first
     * segment, so a scheme-less '[user[:password]@]host:port/...' endpoint
     * keeps its host and port on the line, and 'https:/...' its scheme;
     * an endpoint whose user-info cannot be cut safely is logged as
     * '[unparseable endpoint]'), HTTP
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
     * but takes base_uri's scheme; so does a scheme-less
     * '[user@]host:port/...' one, which PSR-7 parses as user-info, host and
     * port (#5608, #5705); a 'user:password@host:port/...' one PSR-7 parses
     * with the user as its scheme and no host, the rest as its path, and
     * Guzzle's Curl and Stream handlers refuse that scheme before any
     * connection, with no response (#5761, measured). Query: get(), today this method's only
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
     * The endpoint as logged. First the user-info is dropped, user and
     * password alike (#5761): the leading authority is the bytes after a
     * leading '<scheme>://' or '//', or with neither the endpoint's first
     * segment, up to the first '/', '?' or '#'; everything in it up to its
     * LAST '@' is cut (so a raw or percent-encoded '@' in the user or
     * password goes too), and an '@' after that authority, in the path,
     * query or fragment, is not user-info and is left alone. If what is
     * left of the authority is not a host (a bracketed IPv6 literal, or
     * no ':', '[' or ']') with an optional ':' and digits, and the
     * endpoint holds an '@' anywhere, the whole line path is the fixed
     * '[unparseable endpoint]' instead (a password holding '/', '?' or
     * '#' ends the segment early, so it would otherwise be logged as
     * path). Not caught: user-info holding a '/' whose bytes before that
     * '/' still read as a host and port ('user/x@...', 'user:1234/x@...'),
     * which is byte for byte a host, a port and an '@' in the path. And
     * read as user-info although PSR-7 reads it as path: an '@' in the
     * FIRST segment of a relative endpoint ('api@v2/...' logs 'v2/...'),
     * which drops bytes and never adds any. Then cut at
     * the first '?' or '#' (strcspn, not
     * strtok, so a leading '?' still drops the query), strip any scheme and
     * authority (or a scheme-relative //authority: host and port; the
     * strip needs '//', so a scheme-less 'host:port/...' endpoint keeps
     * its host and port on the line, #5608, #5705), then replace EVERYTHING after the
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
        // The leading authority: after '<scheme>://' or '//', else the
        // endpoint's first segment (up to the first '/', '?' or '#'). Its
        // user-info, everything up to its LAST '@', is dropped (#5761).
        $start = preg_match('#^(?:[a-z][a-z0-9+.\-]*:)?//#i', $endpoint, $m) === 1 ? strlen($m[0]) : 0;
        $end = $start + strcspn($endpoint, '/?#', $start);
        $authority = substr($endpoint, $start, $end - $start);
        $at = strrpos($authority, '@');
        $hostPort = $at === false ? $authority : substr($authority, $at + 1);
        if (preg_match(self::HOST_PORT, $hostPort) !== 1 && str_contains($endpoint, '@')) {
            return self::UNPARSEABLE_ENDPOINT;
        }
        $endpoint = substr($endpoint, 0, $start).$hostPort.substr($endpoint, $end);

        $cut = strcspn($endpoint, '?#');
        $path = substr($endpoint, 0, $cut);
        $path = (string) preg_replace('#^(?:[a-z][a-z0-9+.\-]*:)?//[^/]*#i', '', $path);

        return (string) preg_replace('#(^|/)(customers/).+$#is', '$1$2<customer>', $path);
    }
}
