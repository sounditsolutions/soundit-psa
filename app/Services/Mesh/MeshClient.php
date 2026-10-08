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

    /** A host (bracketed IPv6 literal, or no ':', '[' or ']') with an optional ':' and one or more digits. */
    private const HOST_PORT = '#^(?:\[[^\]]*\]|[^:\[\]]*)(?::[0-9]+)?$#';

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
     * so does a scheme the strip does not take (' https://', '1https://':
     * the user-info after its '//' is cut, the scheme and host are kept);
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
     * query the same way (#5484). The rethrown MeshClientException's
     * message is 'Mesh API error: ' followed by the same text as the log
     * line after its '[MeshClient] ' (method, logPath(), status, class),
     * never Guzzle's message (#5761); its code is Guzzle's. The Guzzle
     * exception chained as its previous one is NOT redacted: its message
     * quotes the request URI (user-info, host and customer id included;
     * Guzzle masks only a parsed password) or names the user as a refused
     * scheme, plus vendor body text, and it holds the request with its
     * API-KEY header, so never log getPrevious() or its message. An
     * endpoint PSR-7 cannot parse (MalformedUriException, an
     * InvalidArgumentException thrown before any handler runs) gets the
     * same line and message with 'no HTTP status', code 0 and NO previous
     * exception, because that exception's message is the raw endpoint
     * (#5878).
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
            // One text for the log line and the exception message: method,
            // logPath() (no user-info, query, fragment or customer id), HTTP
            // status and exception class. Never Guzzle's message: it quotes
            // the request URI with its user-info (a real handler's "The
            // scheme '<user>' is not supported." names the user with no URI
            // around it) and a summary of the vendor's body (C-56, #5761).
            $failure = "{$method} ".self::logPath($endpoint).' failed with '
                .($status > 0 ? "HTTP {$status}" : 'no HTTP status').' ('.$e::class.')';
            Log::error("[MeshClient] {$failure}");
            throw new MeshClientException("Mesh API error: {$failure}", $e->getCode(), $e);
        } catch (\InvalidArgumentException $e) {
            // #5878: an endpoint parse_url rejects makes PSR-7 throw
            // MalformedUriException ("Unable to parse URI: <the endpoint>",
            // user-info and password included) before any handler runs. It
            // is an InvalidArgumentException, not a GuzzleException, so the
            // catch above never sees it. Same status-only line and message,
            // and it is NOT chained: its message is the raw endpoint, and no
            // report() or renderer that walks getPrevious() may reach it.
            $failure = "{$method} ".self::logPath($endpoint).' failed with no HTTP status ('.$e::class.')';
            Log::error("[MeshClient] {$failure}");
            throw new MeshClientException("Mesh API error: {$failure}");
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }

    /**
     * The endpoint as logged. First the user-info is dropped, user and
     * password alike (#5761). The authority starts after the endpoint's
     * first '//' if no '/', '?', '#' or '@' comes before that '//'
     * (whatever the bytes before it are, so ' https://' and '1https://',
     * which PSR-7 accepts as schemes, count), else at the endpoint's
     * start. If the bytes from there to the first '/', '?' or '#' hold an
     * '@', everything up to the LAST '@' before the first '?' or '#' is
     * user-info and is cut (so a raw or percent-encoded '@' in the user
     * or password goes too, and so does a password holding an '@' and
     * then a '/'), and the host[:port] is what follows that '@' up to
     * the next '/', '?' or '#'. An '@' after the first '?' or '#' is not
     * user-info and is left alone, as is an '@' in the path when the
     * authority holds none; when it holds one, an '@' later in the path
     * ends the user-info ('user:pw@host/a@b/' logs 'b/': byte for byte a
     * password holding '/', so bytes are dropped, never added). If that
     * host[:port] is not a host (a
     * bracketed IPv6 literal, or no ':', '[' or ']') with an optional
     * ':' and one or more digits (so a first segment 'https:' is not
     * one) and the endpoint holds an '@' anywhere, or if it ends at a
     * '?' or '#' and an '@' follows, the whole line path is the fixed
     * '[unparseable endpoint]' instead (a user or password holding '?'
     * or '#', or with no '@' before the '?' or '#', a ':' with no port
     * digits, would otherwise be logged; for '?' and '#' this holds even
     * when the bytes before it read as a host and port, so an endpoint
     * with no '/' before an '@' in its query or fragment loses its path
     * on the line). Not caught: user-info holding a '/' whose bytes
     * before that '/' still read as a host and port and that holds no
     * '@' before the '/' ('user/x@...', 'user:1234/x@...'), which is
     * byte for byte a host, a port and an '@' in the path. And read as
     * user-info although PSR-7 reads it as path: an '@' in the FIRST
     * segment of a relative endpoint ('api@v2/...' logs 'v2/...'), which
     * drops bytes and never adds any. Then strip: when the bytes before
     * that '//' are empty or end in ':' (any scheme PSR-7 takes, RFC 3986
     * or not), they, the '//' and the host[:port] go; otherwise (' //')
     * they stay with the host[:port] and only the user-info went. When
     * those kept bytes are not blank and the endpoint holds an '@'
     * anywhere, the whole line path is '[unparseable endpoint]' instead:
     * they may be user-info holding '//' ('user:pass//@host/...', which
     * no '@' search reaches), so they are never logged with an '@'. The
     * strip needs '//', so a scheme-less 'host:port/...' endpoint keeps
     * its host and port on the line (#5608, #5705). Then cut at the
     * first '?' or '#' (strcspn, not strtok, so a leading '?' still drops
     * the query), then replace EVERYTHING after the
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
        // The leading authority: after the first '//' when no '/', '?',
        // '#' or '@' comes before it (whatever the other bytes before it
        // are, so ' https://', '1https://' and ' //' count), else the
        // endpoint's first segment; it runs to the first '/', '?' or '#'.
        // If it holds an
        // '@', the user-info runs to the LAST '@' before the first '?' or
        // '#' (a '/' does not end it: a password may hold '@' and then
        // '/'), and the host[:port] is what follows that '@' up to the
        // next '/', '?' or '#' (#5761).
        $slashes = strpos($endpoint, '//');
        $head = $slashes === false ? null : substr($endpoint, 0, $slashes);
        $start = $head !== null && strpbrk($head, '/?#@') === false ? $slashes + 2 : 0;
        $end = $start + strcspn($endpoint, '/?#', $start);
        $hostPort = substr($endpoint, $start, $end - $start);
        if (str_contains($hostPort, '@')) {
            $at = $start + strrpos(substr($endpoint, $start, strcspn($endpoint, '?#', $start)), '@');
            $end = $at + 1 + strcspn($endpoint, '/?#', $at + 1);
            $hostPort = substr($endpoint, $at + 1, $end - $at - 1);
        }
        // An authority that ends at a '?' or '#' with an '@' after it may
        // be user-info cut short there: the bytes before it would be
        // logged (#5761).
        $rest = substr($endpoint, $end);
        $atAfterQuery = $rest !== '' && $rest[0] !== '/' && str_contains($rest, '@');
        // The strip: when nothing, or bytes ending in ':' (read as a
        // scheme, RFC 3986 or not), come before that '//', they go with
        // the '//' and the host[:port]; otherwise they are kept and only
        // the user-info went. Kept bytes that are not blank may be
        // user-info holding '//' ('user:pass//@host'): no '@' search
        // reaches them, so with an '@' anywhere they would be logged
        // (#5761).
        $strip = $start > 0 && ($head === '' || str_ends_with($head, ':'));
        $keptHead = $start > 0 && ! $strip && trim($head) !== '';
        if ($atAfterQuery || ($keptHead && str_contains($endpoint, '@')) || (preg_match(self::HOST_PORT, $hostPort) !== 1 && str_contains($endpoint, '@'))) {
            return self::UNPARSEABLE_ENDPOINT;
        }
        $endpoint = ($strip ? '' : substr($endpoint, 0, $start).$hostPort).substr($endpoint, $end);

        // Then the cut at the first '?' or '#' (strcspn, not strtok, so a
        // leading '?' still drops the query).
        $path = substr($endpoint, 0, strcspn($endpoint, '?#'));

        return (string) preg_replace('#(^|/)(customers/).+$#is', '$1$2<customer>', $path);
    }
}
