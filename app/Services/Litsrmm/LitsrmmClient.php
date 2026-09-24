<?php

namespace App\Services\Litsrmm;

use App\Support\LitsrmmConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Log;

/**
 * Read-only client for the LITSRMM API (Leif IT Solutions RMM).
 *
 * WHAT IS SPECIFIED AND WHAT IS ASSUMED
 * -------------------------------------
 * The vendor proposal (attached to card 6ab46339, 23 Sep 2026) specifies the
 * endpoint paths, the bearer-token auth, and that `/v1/clients` returns `id`
 * and `name`. It does NOT document the response envelope, the pagination
 * mechanism, or any device field below `hostname / OS / online / last seen`.
 *
 * So this class reads `id` and `name` and NOTHING ELSE, and every shape below
 * that is handled defensively rather than guessed at:
 *
 *  - The envelope is read as `data` if present, else the top-level array. That
 *    covers both conventions without asserting which one the vendor uses.
 *    Anything else (another wrapper key, an error object) is not a list and
 *    is logged, as is a non-empty list in which no row is mappable: a wrong
 *    shape must not look like a vendor with no clients.
 *  - Pagination is NOT implemented. Level's cursor scheme (`has_more` +
 *    `starting_after`) is a LEVEL convention, and copying it would encode an
 *    assumption as though it were the contract. getClients() therefore fetches
 *    one page and logs a warning if the response signals more, so an operator
 *    sees a truncated list rather than silently getting one.
 *
 * Deliberately NOT built here: device sync (needs a redacted real /v1/devices
 * response, which is Charlie's ask to make) and webhook alerts (their own PR).
 *
 * AUTH DIFFERS FROM LEVEL ON PURPOSE. LevelClient sends the key raw in the
 * Authorization header; this sends `Bearer <token>`, because the proposal says
 * "Bearer-token auth". Mirroring Level's raw form would contradict the one
 * auth statement the spec actually makes.
 */
class LitsrmmClient
{
    private ?Client $http = null;

    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * Built lazily: a self-hosted vendor has no default base_url, and Guzzle
     * would otherwise be constructed with an empty base_uri at boot for every
     * request in the application, configured or not.
     */
    /**
     * Hosts for which plain HTTP carries no network exposure.
     *
     * Matched on the parsed host, never on the raw string: a substring test
     * would accept http://localhost.attacker.example, which is a different
     * machine with a reassuring name.
     */
    private static function hostIsLoopback(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        // 127.0.0.0/8 in full, not just 127.0.0.1.
        return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && str_starts_with($host, '127.');
    }

    /**
     * Refuse a base URL that would send the bearer token in cleartext.
     *
     * There is deliberately NO override: an operator who needs plain HTTP
     * across a network is asking for a credential-policy decision, not for a
     * flag. A malformed URL is refused too, rather than assumed safe, because
     * an unparseable host is one nobody has checked.
     */
    public static function assertTransportIsSafe(string $baseUrl): void
    {
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);

        if ($scheme === 'https') {
            return;
        }

        if ($host === '') {
            Log::warning('[LitsrmmClient] refusing a base URL with no parseable host', [
                'scheme' => $scheme,
            ]);

            throw new LitsrmmClientException('LITSRMM base URL is not a valid absolute URL');
        }

        if ($scheme === 'http' && self::hostIsLoopback($host)) {
            return;
        }

        Log::warning('[LitsrmmClient] refusing a plaintext request: the API key would cross the network in cleartext', [
            'scheme' => $scheme,
            'host' => $host,
        ]);

        throw new LitsrmmClientException(
            'LITSRMM base URL must use https (plain http is allowed only for a loopback host)'
        );
    }

    /**
     * Resolve an endpoint against the base URL the way Guzzle will, and refuse
     * anything that lands off the configured host.
     *
     * WHY THIS EXISTS SEPARATELY FROM assertTransportIsSafe() (#3337 diff:2).
     * That method judges the CONFIGURED base_url. Guzzle does not request the
     * base_url; it requests base_uri resolved against $endpoint under RFC 3986,
     * and under those rules an absolute endpoint replaces the whole authority
     * while a protocol-relative one (`//host/path`) replaces the host and
     * INHERITS the base scheme. So a guard that only reads the configured
     * string is checking a URL that is not the one the credential crosses.
     *
     * Measured on the unfixed code, all of these left the process with the
     * Authorization header attached:
     *   base https://rmm.example.com + 'http://rmm.example.com/v1/clients'
     *      -> plaintext, same host
     *   base https://rmm.example.com + '//evil.example/v1/x'
     *      -> https://evil.example/v1/x
     *   base http://127.0.0.1:8080  + '//evil.example/v1/x'
     *      -> http://evil.example/v1/x, i.e. the loopback exemption carried
     *         onto a remote host in cleartext
     *
     * The host equality check is what makes this hold for a future caller that
     * follows a vendor-supplied `next` link, which is exactly how stage 2's
     * paging will be written. Scheme safety is re-asserted on the RESOLVED URI
     * rather than inferred, because the loopback exemption is a statement about
     * a host and must not survive a change of host.
     */
    private function assertEndpointStaysOnTheConfiguredHost(string $baseUrl, string $endpoint): void
    {
        // UriResolver::resolve, not Uri::resolve: the latter was removed in
        // guzzlehttp/psr7 v2. This is the SAME resolver Guzzle's own
        // RedirectMiddleware and base_uri handling call, so the URL judged here
        // is the URL that will be requested, not a second implementation of
        // RFC 3986 that could drift from it.
        $baseUri = new Uri(rtrim($baseUrl, '/').'/');
        $resolvedUri = UriResolver::resolve($baseUri, new Uri($endpoint));
        $resolved = (string) $resolvedUri;

        // ONE PARSER, ONE OBJECT (#3500). An earlier draft read the hosts with
        // parse_url() on the resolved STRING, which put a second parser in
        // front of a URL Guzzle builds from a Uri object -- the exact drift
        // this method's docblock argues against. Both sides are now read off
        // the Uri objects themselves.
        //
        // AND THE ORIGIN INCLUDES THE PORT. Host equality alone lets
        // https://rmm.example.com:8443/ pass a guard configured for
        // https://rmm.example.com/ and carry the Bearer to a different service
        // on the same machine. Guzzle's own UriComparator::isCrossOrigin
        // compares host, scheme AND port, so matching that rule keeps this
        // guard and the one inside the redirect middleware in agreement.
        // The scheme is part of the key and the port is the EFFECTIVE one
        // (#3500 diff:1). getPort() is null for the default port of the URI's
        // OWN scheme, so a host:getPort() key read https://localhost and
        // http://localhost as one origin -- ports 443 and 80 -- and the
        // loopback exemption in assertTransportIsSafe() then admitted the
        // plain-http side. Filling the default back in keeps an explicit :443
        // and no port equal, which is what UriComparator::isCrossOrigin
        // computes too.
        $origin = static function (\Psr\Http\Message\UriInterface $u): string {
            $scheme = strtolower($u->getScheme());
            $port = $u->getPort() ?? match ($scheme) {
                'https' => 443,
                'http' => 80,
                default => null,
            };

            return $scheme.'://'.strtolower($u->getHost()).':'.($port ?? '');
        };

        if ($origin($resolvedUri) !== $origin($baseUri)) {
            // The endpoint is logged, the credential is not, and the throw
            // happens before any Authorization header is built.
            Log::warning('[LitsrmmClient] refusing an endpoint that resolves off the configured origin', [
                'configured_origin' => $origin($baseUri),
                'resolved_origin' => $origin($resolvedUri),
            ]);

            throw new LitsrmmClientException(
                'LITSRMM endpoint resolves to a different scheme, host or port than the configured base URL'
            );
        }

        // The origin key already pins the scheme; the transport rule is still
        // re-asserted on the URL that will actually be requested.
        self::assertTransportIsSafe($resolved);
    }

    private function http(): Client
    {
        if ($this->http === null) {
            $options = [
                'base_uri' => rtrim((string) ($this->config['base_url'] ?? ''), '/').'/',
                'timeout' => $this->config['request_timeout'] ?? 30,
                // Every other vendor client here does this (Tactical, Comet,
                // CIPP x2, Teams, the sinks). A vendor API has no reason to
                // redirect, and following one sends the REQUEST BODY to the
                // other host even though Guzzle strips the credential on a
                // cross-origin hop. Refusing to follow is narrower than
                // relying on that stripping.
                'allow_redirects' => false,
            ];

            // A test must be able to observe what left the process rather than
            // reach a real host. Honouring config['handler'] is the same seam
            // GraphClient exposes (card 3EUxsP13): without it, a suite passing
            // a MockHandler is silently ignored and every assertion about the
            // composed request grades a live network attempt instead.
            if (isset($this->config['handler'])) {
                $options['handler'] = $this->config['handler'];
            }

            $this->http = new Client($options);
        }

        return $this->http;
    }

    /**
     * Whether the API answers with the configured credentials.
     *
     * Returns false rather than throwing so the settings screen can report a
     * failed Test Connection without a 500. Gated on isAvailable(): a disabled
     * integration makes no outbound request at all, which is the property the
     * Tactical bug in the vendor's own proposal describes losing.
     */
    public function isHealthy(): bool
    {
        if (! LitsrmmConfig::isAvailable()) {
            return false;
        }

        try {
            $this->get('v1/health');

            return true;
        } catch (LitsrmmClientException) {
            return false;
        }
    }

    /**
     * The mappable entities: what a technician picks a client from.
     *
     * Only `id` and `name` are read, because only those two are specified.
     */
    public function getClients(): array
    {
        if (! LitsrmmConfig::isAvailable()) {
            return [];
        }

        $response = $this->get('v1/clients', ['limit' => 100]);

        $clients = $response['data'] ?? $response;

        // THE GUARD TESTS EACH VALUE AGAINST ITS KEY (#3326 c1:v2:1).
        //
        // The defect this guard exists to catch is a wrapper we do not
        // recognise -- {"clients": [...]}, {"clients": []}, or a 200 carrying
        // {"error": {"id": ...}} -- being mapped over as though its values were
        // rows. That yields [] and reads as "no clients", or it builds a
        // phantom client from the wrapper's own id.
        //
        // Keying on array_is_list() answered a different question. json_decode
        // turns {"0":{...},"2":{...}} into a gapped array and {"c1":{...}} into
        // an id-keyed map. Both are row collections, and both carry every row.
        // It also let a list of scalars through, because ["a","b"] IS a list.
        //
        // A row is a JSON object, so it decodes to an array that is not itself
        // a non-empty list. Under an integer key that is enough. Under a string
        // key the row must also carry that key as its id, because that is what
        // an id-keyed map is. A wrapper's key names a field, not the row
        // beneath it.
        //
        // WHY THE KEY AND NOT THE EMPTINESS: under assoc:true, json_decode
        // gives {} and [] the SAME value. json_decode('{}', true) ===
        // json_decode('[]', true) is true, and array_is_list() returns true
        // for both. So an empty object and an empty list are indistinguishable
        // here and no predicate can separate them -- which is why {"clients":[]}
        // defeated an emptiness test. (Decoding without assoc would keep them
        // apart, as {} becomes a stdClass, but this client decodes at :467 with
        // assoc:true and every caller expects arrays.) Asking whether a value
        // belongs under its key sidesteps the ambiguity instead of losing to it.
        //
        // Only the values that pass are mapped. So one null or scalar element
        // drops that element and keeps the rest of the collection. The envelope
        // is refused only when it is non-empty and nothing in it is a row. An
        // empty collection means "no clients", which is a legitimate answer.
        $candidates = is_array($clients)
            ? array_filter($clients, fn ($row, $key) => $this->looksLikeARow($row, $key), ARRAY_FILTER_USE_BOTH)
            : [];

        if (! is_array($clients) || ($clients !== [] && $candidates === [])) {
            Log::warning('[LitsrmmClient] /v1/clients did not return client rows', [
                'type' => gettype($clients),
                'keys' => is_array($clients) ? array_slice(array_keys($clients), 0, 10) : [],
            ]);

            return [];
        }

        // The proposal does not document pagination. If the vendor signals more
        // pages by any of the conventions its sibling integrations use, say so
        // out loud: a silently truncated entity list is a mapping screen that
        // omits clients without admitting it.
        $more = $response['has_more'] ?? $response['next_page'] ?? $response['next'] ?? null;
        if (! empty($more)) {
            Log::warning('[LitsrmmClient] /v1/clients signalled more results than one page returned; pagination is not implemented because the API contract does not document it', [
                'returned' => count($clients),
            ]);
        }

        $rows = array_values(array_filter(
            array_map(static fn ($c) => [
                'id' => (string) ($c['id'] ?? ''),
                'name' => (string) ($c['name'] ?? ''),
            ], $candidates),
            static fn ($c) => $c['id'] !== '',
        ));

        if ($candidates !== [] && $rows === []) {
            Log::warning('[LitsrmmClient] /v1/clients returned rows but none were mappable', [
                'received' => count($candidates),
            ]);
        }

        return $rows;
    }

    /**
     * Is this value, at this key, a decoded client row?
     *
     * A non-empty list is a nested collection, not a row. Under a string key
     * the value must carry that key as its id. This is what separates an
     * id-keyed map from {"clients": [...]}, {"clients": []} or
     * {"error": {"id": "rate_limited"}}, whose key names a field.
     */
    private function looksLikeARow(mixed $row, int|string $key): bool
    {
        if (! is_array($row) || ($row !== [] && array_is_list($row))) {
            return false;
        }

        return is_int($key)
            || (isset($row['id']) && is_scalar($row['id']) && (string) $row['id'] === $key);
    }

    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
    }

    private function request(string $method, string $endpoint, array $options = []): array
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (! $apiKey) {
            throw new LitsrmmClientException('LITSRMM API key not configured');
        }

        // No default host: refuse rather than aim a credentialed request at
        // whatever answers on a guessed address.
        if (empty($this->config['base_url'])) {
            throw new LitsrmmClientException('LITSRMM base URL not configured');
        }

        // THE AVAILABILITY CHOKE POINT (#3298 diff:2, C-47).
        //
        // getClients() and isHealthy() each gated themselves, which held only
        // because they were the only callers. The public get() reaches this
        // method directly, so an operator's OFF switch depended on every future
        // caller remembering to ask; stage 2's device sync is that caller.
        // Gating here makes OFF=OFF a property of the transport rather than a
        // convention the next author has to know about. The two methods keep
        // their own checks because they return a value rather than throwing.
        //
        // isAvailable() is checked as its TWO halves, each with its own
        // refusal. The two credential checks above read $this->config, the
        // values captured when this object was built. isConfigured() re-reads
        // LIVE Settings/config. A long-lived singleton, or a client built with
        // explicit config, can pass the first pair and fail the second while
        // the switch is ON. One combined check would then say "switched off"
        // on a path where the switch is not off (#3500 context:3).
        // Both halves still refuse before the network, so OFF=OFF and the
        // stale-credential refusal are unchanged.
        if (! LitsrmmConfig::isEnabled()) {
            throw new LitsrmmClientException('LITSRMM integration is switched off');
        }

        if (! LitsrmmConfig::isConfigured()) {
            throw new LitsrmmClientException('LITSRMM API key or base URL is not currently configured');
        }

        // Refuse plaintext BEFORE the Authorization header exists, so a refused
        // request cannot have carried the credential. The form validates the
        // same rule, but env and config bypass the form entirely, which is why
        // the real guarantee has to live here.
        //
        // Loopback is the one exception, and it is the case the vendor's
        // proposal describes: their own deployment runs the RMM and the PSA on
        // one machine, so there is no network for a bearer token to cross. Any
        // other host is remote from us whatever the vendor's topology is.
        self::assertTransportIsSafe((string) $this->config['base_url']);

        // ...and then the URI Guzzle will ACTUALLY request, which is not the
        // same string. See the method's docblock.
        $this->assertEndpointStaysOnTheConfiguredHost((string) $this->config['base_url'], $endpoint);

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer '.$apiKey,
            'Accept' => 'application/json',
        ]);

        try {
            $response = $this->http()->request($method, $endpoint, $options);
        } catch (GuzzleException $e) {
            $status = method_exists($e, 'getResponse') && $e->getResponse()
                ? $e->getResponse()->getStatusCode()
                : 0;

            // The message is logged and re-thrown WITHOUT the request options:
            // those carry the Authorization header.
            Log::warning('[LitsrmmClient] request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $status,
            ]);

            throw new LitsrmmClientException(
                "LITSRMM API error: {$method} {$endpoint} returned {$status}",
                $status,
                $e,
            );
        }

        // allow_redirects is false (see http()) and http_errors only throws
        // from 400, so a 3xx arrives here as an ordinary response. Decoding it
        // would turn an empty redirect body into [] -- a healthy vendor with no
        // clients, the silent degraded read C-56 forbids (#3500 diff:4).
        $status = $response->getStatusCode();

        if ($status >= 300 && $status < 400) {
            Log::warning('[LitsrmmClient] refusing a redirect response: redirects are not followed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $status,
            ]);

            throw new LitsrmmClientException(
                "LITSRMM API redirected {$method} {$endpoint} ({$status}); redirects are not followed",
                $status,
            );
        }

        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new LitsrmmClientException("LITSRMM API returned a non-JSON body for {$method} {$endpoint}");
        }

        return $decoded;
    }
}
