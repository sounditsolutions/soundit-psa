<?php

namespace App\Services\Mesh;

use App\Support\MeshConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Log;

class MeshClient
{
    /**
     * The fixed label for an endpoint PSR-7 cannot parse (#6049): what
     * preflight() logs, and what both logPath()s return for such an
     * endpoint whoever calls them (#6217 on the write side, #6286 here).
     */
    public const UNPARSEABLE_ENDPOINT = '[unparseable endpoint]';

    /**
     * #6225: what logPath() logs for an endpoint whose user-info it cannot
     * cut off safely (#5761). Not UNPARSEABLE_ENDPOINT: many of these
     * endpoints parse ('licenses?filter=a@b' does), and the line is
     * withheld because of an '@' logPath() could not place, not because
     * the endpoint did not parse.
     */
    public const WITHHELD_ENDPOINT = '[endpoint withheld: possible credentials]';

    /** A host (bracketed IPv6 literal, or no ':', '[' or ']') with an optional ':' and one or more digits. */
    private const HOST_PORT = '#^(?:\[[^\]]*\]|[^:\[\]]*)(?::[0-9]+)?$#';

    /** Null when PSR-7 rejected the base URL; request() then refuses (#5991). */
    private ?Client $http;

    /**
     * #5991: a base_url PSR-7 cannot parse makes new Client() throw a
     * MalformedUriException whose message is the raw URL (user-info
     * included). It is not thrown here: every call then fails in request()
     * with a status-only, client-detected MeshClientException, inside the
     * callers' existing catch. #6214, #6221: $config holds the API key,
     * so it is #[\SensitiveParameter]: a throw from inside this constructor
     * (a base_url that is not a string makes rtrim() throw a TypeError,
     * which is not caught here) shows a SensitiveParameterValue in this
     * frame (MeshTraceFrameHardeningTest).
     */
    public function __construct(
        #[\SensitiveParameter] private readonly array $config,
    ) {
        try {
            $this->http = new Client([
                'base_uri' => rtrim($this->config['base_url'] ?? 'https://hub-us.emailsecurity.app', '/').'/',
                'timeout' => 90,
                // #6105: a redirect is never followed (request() also sets
                // it per request); see request().
                'allow_redirects' => false,
            ]);
        } catch (\InvalidArgumentException) {
            $this->http = null;
        }
    }

    /**
     * Make an authenticated GET request to the Mesh API.
     */
    public function get(#[\SensitiveParameter] string $endpoint, #[\SensitiveParameter] array $params = []): array
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
    public function getCustomers(#[\SensitiveParameter] ?string $filter = null, int $size = 100): array
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
    public function getCustomer(#[\SensitiveParameter] string $uuid): array
    {
        return $this->get("api/customers/{$uuid}/");
    }

    /**
     * Internal request method with auth header.
     * API-KEY header is added here — never logged. A failure is logged by
     * method, endpoint path (see logPath(): UNPARSEABLE_ENDPOINT for an
     * endpoint PSR-7 cannot parse (#6286); never any user-info, neither
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
     * WITHHELD_ENDPOINT, #6225), HTTP
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
     * exception is NOT chained as its previous one (#5978): its message
     * quotes the request URI (user-info, host and customer id included;
     * Guzzle masks only a parsed password) or names the user as a refused
     * scheme, plus vendor body text, and it holds the request with its
     * API-KEY header, and the console renderer and (string) $e (which a
     * queue worker stores in failed_jobs) both print every previous
     * exception's message ((string) $e also prints the stack trace, whose
     * frames show arguments when zend.exception_ignore_args is Off, #6051;
     * #6108, #6154: $endpoint, the id and filter parameters, get()'s
     * $params and this method's $options (which holds the API-KEY header
     * and the query once set) are #[\SensitiveParameter], so with
     * ignore_args Off these methods' own frames show a
     * SensitiveParameterValue for each of them (MeshTraceArgumentRedactionTest
     * measures each one); callers' frames are not covered). Before Guzzle is called, preflight() refuses an endpoint
     * PSR-7 cannot parse (#5878, #6049, #6060: code 0, nothingSent, #5990,
     * and no endpoint text at all), a base URL it could not parse (#5991)
     * and an API key an HTTP header cannot carry (#5979). A status-less
     * failure names the cURL errno when the handler recorded one (#6050:
     * the number only). Any other InvalidArgumentException from the HTTP
     * client is reported by class only, not as a Mesh status, and makes
     * no claim about what was sent (#6061). #6214: any other throwable
     * from the HTTP client (a RuntimeException, TypeError or Error from a
     * handler or middleware) is not caught and leaves this method as
     * thrown; this frame shows $options as a SensitiveParameterValue
     * (MeshTraceFrameHardeningTest), but Guzzle's own frames below it are
     * not marked, and read in the vendored Guzzle, not driven, they take
     * the options array with the API-KEY header. #6105: redirects are never
     * followed. Guzzle's RedirectMiddleware strips only Authorization and
     * Cookie on a cross-origin hop, so a followed redirect would carry the
     * API-KEY header to whatever host Location names. A 3xx is a failure
     * by its status: logged and thrown like any other status, the
     * Location's value never logged or quoted (#6209, #6298:
     * redirectNote() reads the Location's values only to test whether
     * any of them is non-empty).
     */
    private function request(string $method, #[\SensitiveParameter] string $endpoint, #[\SensitiveParameter] array $options = []): array
    {
        $this->preflight($method, $endpoint);

        $options['headers'] = [
            'API-KEY' => $this->config['api_key'],
            'Accept' => 'application/json',
        ];
        // #6105: per request too, so a transport built elsewhere (a test
        // seam, a future factory) cannot follow one either.
        $options['allow_redirects'] = false;

        try {
            $response = $this->http->request($method, $endpoint, $options);
        } catch (GuzzleException $e) {
            $status = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 0) : 0;
            // One text for the log line and the exception message: method,
            // logPath() (no user-info, query, fragment or customer id), HTTP
            // status, or with none the cURL errno as a number (#6050), and
            // exception class. Never Guzzle's message: it quotes
            // the request URI with its user-info (a real handler's "The
            // scheme '<user>' is not supported." names the user with no URI
            // around it) and a summary of the vendor's body (C-56, #5761).
            $failure = "{$method} ".self::logPath($endpoint).' failed with '
                .($status > 0 ? "HTTP {$status}" : 'no HTTP status').' ('.self::errnoPrefix($e, $status).$e::class.')';
            Log::error("[MeshClient] {$failure}");
            // #5978: not chained. The console renderer and (string) $e walk
            // getPrevious(), and Guzzle's message is exactly what this hides.
            throw new MeshClientException("Mesh API error: {$failure}", $e->getCode());
        } catch (\InvalidArgumentException $e) {
            // #5979: endpoint and API key were checked in preflight(), so
            // this is something else the HTTP client threw. #6061 measured
            // one such route after a send: RedirectMiddleware parsing an
            // unparseable Location. #6105 turned redirects off, so that
            // route now ends in the 3xx arm below with its status
            // (MeshRedirectRefusalTest). Whether any other route can follow
            // a send is not measured. Its message may quote a URI or a
            // header value, so class only, unchained, and no claim about
            // what was sent: nothingSent stays unset.
            $failure = "{$method} ".self::logPath($endpoint).' failed in the HTTP client with no Mesh status recorded ('.$e::class.')';
            Log::error("[MeshClient] {$failure}");
            throw new MeshClientException("Mesh API error: {$failure}", noStatusRecorded: true);
        }

        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            // #6105: the configured host (Mesh or something in front of
            // it) answered with a 3xx, which is not followed. A failure by
            // status, like any other: the Location is neither followed,
            // logged nor quoted, and the answered status is the code.
            // #6158, #6224: '(redirect not followed)' only for a redirect
            // status that carries a non-empty Location (redirectNote()).
            $failure = "{$method} ".self::logPath($endpoint)." failed with HTTP {$status}".self::redirectNote($response);
            Log::error("[MeshClient] {$failure}");
            throw new MeshClientException("Mesh API error: {$failure}", $status);
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }

    /**
     * Refusals decided before Guzzle is called, so nothing was sent (#5878,
     * #5979, #5990, #5991). The endpoint is parsed FIRST, and its refusal
     * logs the fixed '[unparseable endpoint]' (#6049), so the base-URL and
     * key arms log logPath() only of an endpoint PSR-7 parsed. All three
     * are client-detected (#6060): the PSA decided them, not Mesh. None
     * quotes the base URL or the key; logPath() is what the line shows of
     * the endpoint.
     */
    private function preflight(string $method, #[\SensitiveParameter] string $endpoint): void
    {
        try {
            // The same parse Guzzle's Utils::uriFor() does first; its
            // message is the raw endpoint, so it is neither logged nor chained.
            new Uri($endpoint);
        } catch (\InvalidArgumentException $e) {
            Log::error("[MeshClient] {$method} ".self::UNPARSEABLE_ENDPOINT.' refused: the endpoint could not be parsed ('.$e::class.'); nothing was sent');
            throw new MeshClientException('The Mesh request endpoint could not be parsed; nothing was sent.', clientDetected: true, nothingSent: true);
        }

        if ($this->http === null) {
            Log::error("[MeshClient] {$method} ".self::logPath($endpoint).' refused: the Mesh base URL could not be parsed; nothing was sent');
            throw new MeshClientException('The Mesh base URL could not be parsed; nothing was sent.', clientDetected: true, nothingSent: true);
        }

        $keyRefusal = self::apiKeyRefusal($this->config['api_key'] ?? null);
        if ($keyRefusal !== null) {
            Log::error("[MeshClient] {$method} ".self::logPath($endpoint)." refused: the Mesh API key {$keyRefusal}; nothing was sent");
            throw new MeshClientException("The Mesh API key {$keyRefusal}; nothing was sent.", clientDetected: true, nothingSent: true);
        }
    }

    /**
     * #6158: the 3xx statuses whose Location is a redirect target (RFC 9110
     * 15.4): 300's preferred choice, and 301, 302, 303, 307 and 308. Not
     * 304 (Not Modified), 305 (Use Proxy, deprecated) or 306 (unused).
     */
    private const REDIRECT_STATUSES = [300, 301, 302, 303, 307, 308];

    /**
     * #6158: ' (redirect not followed)' for a 3xx that offered a redirect:
     * one of REDIRECT_STATUSES with a non-empty Location header (#6224: a
     * 'Location:' with no value names no target; #6284: nor do several
     * of them, which getHeaderLine() would join as ', '). Any other 3xx
     * (304, 305, 306, or any status with no or only empty Location
     * values) offered nothing to follow, so its line names the status
     * only: ''. Each Location value is read only to test whether it is
     * empty once trimmed of spaces and tabs; no value is logged or quoted.
     */
    public static function redirectNote(\Psr\Http\Message\ResponseInterface $response): string
    {
        if (! in_array($response->getStatusCode(), self::REDIRECT_STATUSES, true)) {
            return '';
        }
        foreach ($response->getHeader('Location') as $value) {
            if (trim($value, " \t") !== '') {
                return ' (redirect not followed)';
            }
        }

        return '';
    }

    /**
     * '(cURL errno N, ' for a status-less failure whose handler recorded an
     * errno (#6050), else ''. The number only: never the handler's message,
     * which quotes the URI.
     */
    public static function errnoPrefix(GuzzleException $e, int $status): string
    {
        if ($status > 0 || ! ($e instanceof ConnectException || $e instanceof RequestException)) {
            return '';
        }
        $errno = $e->getHandlerContext()['errno'] ?? null;

        return is_int($errno) && $errno > 0 ? "cURL errno {$errno}, " : '';
    }

    /** #6161: apiKeyRefusal()'s text for a stored value the PSA does not send as a key. */
    public const UNUSABLE_KEY = 'is set, but to zero or true, which the PSA does not send as a key';

    /**
     * Why the PSA will not send $key as the API-KEY header, or null when it
     * will, as the end of 'the Mesh API key …'. #6343: the missing and
     * unusable sets are MeshConfig's, which MeshConfig::isConfigured() and
     * MeshWriteClient::isConfigured() also read. Only MeshConfig's also
     * applies the header rule (headerValueRefusal()) to the stored key
     * (#6288). That rule refuses every byte outside
     * [\x20\x09\x21-\x7E\x80-\xFF]: NUL, CR, LF, every other C0 control
     * but tab, and DEL. So MeshWriteClient::isConfigured() is the broader
     * of the two: it accepts an isSendableKey() key holding any of those
     * bytes, which MeshConfig::isConfigured() refuses. A row that does
     * not decrypt is not a difference: AppServiceProvider builds MeshWriteClient with
     * MeshConfig::get('api_key'), null for that row, so both refuse it
     * (#6161, #6162, #6163). 'is not configured' only for a key that is
     * really absent (MeshConfig::apiKeyMissing(): null, false, '' or only
     * spaces and tabs, which PSR-7 trims to ''). A stored '0', 0, 0.0 or
     * true (which would be sent as '1') is MeshConfig::apiKeyUnusable()
     * and gets UNUSABLE_KEY, which says a value is set. Then the header
     * rule (headerValueRefusal()).
     */
    public static function apiKeyRefusal(mixed $key): ?string
    {
        if (MeshConfig::apiKeyMissing($key)) {
            return 'is not configured';
        }

        return MeshConfig::apiKeyUnusable($key) ? self::UNUSABLE_KEY : self::headerValueRefusal($key);
    }

    /** #6161: MeshConfig::apiKeyMissing(), the only 'is not configured' set of apiKeyRefusal(). */
    public static function apiKeyMissing(mixed $key): bool
    {
        return MeshConfig::apiKeyMissing($key);
    }

    /**
     * What PSR-7's MessageTrait accepts as a header value, whose refusal
     * quotes the value (#5979, #5985): a scalar or null, cast to a string
     * and trimmed of spaces and tabs (#6054: an int or float key is
     * accepted, as PSR-7 accepts it), holding only these bytes.
     */
    public static function isHeaderValue(mixed $value): bool
    {
        return self::headerValueRefusal($value) === null;
    }

    /**
     * Why PSR-7 would refuse $value as a header value, as the end of 'the
     * Mesh API key …', or null when it would not (#6054: the reason names
     * what was measured). One exception, stricter than PSR-7: an array,
     * which PSR-7 reads as a list of header values, is refused because
     * the PSA sends one key as one value (#6111: the reason says so).
     * #6113: NAN, INF and -INF need no branch of their own: (string)
     * gives 'NAN', 'INF' and '-INF', which the byte rule accepts, as
     * PSR-7 does (MeshHeaderValueBoundaryTest rows).
     */
    public static function headerValueRefusal(mixed $value): ?string
    {
        if (is_array($value)) {
            return 'is a list of values, and the PSA sends one key as one header value';
        }
        if (! is_scalar($value) && $value !== null) {
            return 'is not a value an HTTP header can carry';
        }

        return preg_match('/^[\x20\x09\x21-\x7E\x80-\xFF]*$/D', (string) $value) === 1
            ? null
            : 'holds a character an HTTP header cannot carry';
    }

    /**
     * The endpoint as logged. #6286: an endpoint PSR-7 cannot parse is
     * the fixed UNPARSEABLE_ENDPOINT, whoever calls this, so the label
     * does not depend on preflight() running first (as on the write side,
     * #6217). Otherwise first the user-info is dropped, user and
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
     * WITHHELD_ENDPOINT instead (#6225: it may well parse) (a user or password holding '?'
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
     * anywhere, the whole line path is WITHHELD_ENDPOINT instead:
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
        try {
            new Uri($endpoint);
        } catch (\InvalidArgumentException) {
            return self::UNPARSEABLE_ENDPOINT;
        }

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
            return self::WITHHELD_ENDPOINT;
        }
        $endpoint = ($strip ? '' : substr($endpoint, 0, $start).$hostPort).substr($endpoint, $end);

        // Then the cut at the first '?' or '#' (strcspn, not strtok, so a
        // leading '?' still drops the query).
        $path = substr($endpoint, 0, strcspn($endpoint, '?#'));

        return (string) preg_replace('#(^|/)(customers/).+$#is', '$1$2<customer>', $path);
    }
}
