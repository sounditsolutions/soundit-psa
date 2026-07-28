<?php

namespace App\Services\Tactical;

use App\Support\SafeUrlInspector;
use App\Support\TacticalConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

class TacticalClient
{
    private Client $http;

    /**
     * The Guzzle base_uri this client resolves relative endpoints against
     * (null when an injected client carries none). Captured ONCE at
     * construction from the same value Guzzle's own buildUri() consults, so
     * the check-creation guard below decides from the URL genuinely
     * requested — never a second derivation that could drift from it
     * (psa-uw2o's TOCTOU lesson; mirrors ServosityClient::$baseUri).
     */
    private readonly ?UriInterface $baseUri;

    /**
     * @param  \GuzzleHttp\Client|null  $http  When null (the zero-arg
     *                                         AppServiceProvider singleton path), a config-driven client is built
     *                                         from the encrypted Settings (X-API-KEY, base_uri, 30s timeout) with
     *                                         redirect-following disabled (P2 §11/B2 outbound hardening: a followed
     *                                         redirect could exfiltrate the 2FA-bypassing key to a metadata host).
     *                                         When provided (the test/bus seam), it is used AS-IS — the injected
     *                                         client owns its own headers; config is NOT consulted.
     * @param  callable|null  $resolver  Host-resolution seam for the request-time
     *                                   SSRF pin (psa-rkf6): host => string[]|false.
     *                                   Defaults to gethostbynamel in production;
     *                                   injected by tests for determinism. Only
     *                                   consulted on the config-driven path.
     */
    public function __construct(?Client $http = null, ?callable $resolver = null)
    {
        if ($http !== null) {
            $this->http = $http;
            $base = $http->getConfig('base_uri');
            $this->baseUri = $base instanceof UriInterface
                ? $base
                : (is_string($base) && $base !== '' ? Utils::uriFor($base) : null);

            return;
        }

        $baseUrl = rtrim(TacticalConfig::apiUrl(), '/').'/';
        $this->baseUri = Utils::uriFor($baseUrl);

        // Request-time peer-IP pin (psa-rkf6): validate + pin the target IP on
        // every request, closing the DNS-rebinding TOCTOU the save-time
        // SafeUrlInspector check leaves open.
        $stack = HandlerStack::create();
        $stack->push(self::ssrfPinMiddleware($resolver ?? 'gethostbynamel'), 'tactical_ssrf_pin');

        $this->http = new Client([
            'base_uri' => $baseUrl,
            'handler' => $stack,
            'timeout' => 30,
            'allow_redirects' => false,
            'headers' => [
                'X-API-KEY' => TacticalConfig::get('api_key'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Guzzle middleware factory for the request-time SSRF pin (psa-rkf6).
     *
     * Before every outbound request it resolves the target host through
     * $resolver (host => string[] addresses, or false for NXDOMAIN), validates
     * that EVERY resolved address is public/routable via the shared
     * SafeUrlInspector::ipIsSafe() checker, and pins the connection to the
     * validated address(es) with CURLOPT_RESOLVE so curl performs no second DNS
     * lookup. Validate-and-pin are atomic, closing the DNS-rebinding TOCTOU the
     * save-time check cannot: a host that passed save-time validation can never
     * rebind to a private/metadata address at connect time. Fails CLOSED — an
     * unsafe or unresolvable host throws TacticalClientException before connect.
     *
     * Exposed (not private) so it is unit-testable against a mock transport with
     * an injected resolver; production wires it via the constructor above.
     */
    public static function ssrfPinMiddleware(callable $resolver): callable
    {
        return static function (callable $handler) use ($resolver): callable {
            return static function (RequestInterface $request, array $options) use ($handler, $resolver) {
                $uri = $request->getUri();
                $host = $uri->getHost();
                $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);

                // Throws (fail closed) before connect if any resolved IP is unsafe.
                $resolveLine = self::resolveAndPin($host, $port, $resolver);

                // Force CURLOPT_RESOLVE: a security control must not be
                // overridable by caller-supplied curl options.
                $curl = $options['curl'] ?? [];
                $curl[CURLOPT_RESOLVE] = $resolveLine;
                $options['curl'] = $curl;

                return $handler($request, $options);
            };
        };
    }

    /**
     * Resolve $host and return a single CURLOPT_RESOLVE entry
     * ("host:port:ip[,ip...]") pinning it to its validated address(es); throw
     * TacticalClientException when the host does not resolve or ANY resolved
     * address is private/reserved. Pre-connect — the request never leaves the
     * box when this throws.
     *
     * @return list<string>
     */
    private static function resolveAndPin(string $host, int $port, callable $resolver): array
    {
        $ips = $resolver($host);
        if ($ips === false || ! is_array($ips) || $ips === []) {
            Log::warning("[TacticalClient] SSRF pin: host '{$host}' did not resolve — refusing.");
            throw new TacticalClientException("Tactical API host '{$host}' did not resolve (refused for safety).");
        }

        foreach ($ips as $ip) {
            if (! SafeUrlInspector::ipIsSafe($ip)) {
                Log::warning("[TacticalClient] SSRF pin: host '{$host}' resolved to non-public {$ip} — refusing.");
                throw new TacticalClientException(
                    "Tactical API host '{$host}' resolved to a private or reserved address ({$ip}); refused."
                );
            }
        }

        return [$host.':'.$port.':'.implode(',', $ips)];
    }

    /**
     * GET a Tactical endpoint.
     *
     * `$timeout` (amendment C) is an optional per-request override in seconds,
     * merged into the Guzzle call — mirroring NinjaClient::getDevice(timeout:).
     * It exists for the cheap LIVE reads (status/checks/software/patches), which
     * want a short ~2-3s bound rather than the 30s client default the action
     * bus's NATS-blocking writes need. When null, no per-request option is set
     * and the client default governs (unchanged behaviour). NOTE: this method
     * still throws on any non-2xx / transport failure — the bound-and-degrade
     * classification lives in TacticalInsightService (the action bus depends on
     * get()/post() throwing), NOT here.
     */
    public function get(string $endpoint, ?int $timeout = null): array
    {
        $options = $timeout !== null ? ['timeout' => $timeout] : [];

        try {
            $response = $this->http->request('GET', $endpoint, $options);
        } catch (GuzzleException $e) {
            Log::error("[TacticalClient] GET {$endpoint} failed", [
                'status' => ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse())
                    ? $e->getResponse()->getStatusCode()
                    : null,
            ]);
            throw TacticalClientException::fromGuzzle("Tactical API error (HTTP GET {$endpoint})", $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * POST a Tactical endpoint.
     *
     * THE MANDATORY CHECK-CREATION PLATFORM GUARD IS ENFORCED HERE (psa-0pb9m
     * R5). Every POST whose endpoint resolves to a check-CREATION route —
     * the checks/ collection or either vendor alias that publishes the same
     * GetAddChecks view (see targetsCheckCreation) — passes
     * TacticalCheckPlatformGuard::assertSafe() before any HTTP is attempted —
     * whichever wrapper, subclass, or future caller composed the request. R5
     * proved that guarding only the named createCheck() wrapper left this
     * generic transport as a second public write seam: a raw
     * post('checks/', …) reached HTTP with no catalog/platform evidence. The
     * transport is where every check-creating write CONVERGES (the psa-mocr
     * choke-point rule), so enforcing the invariant here is what makes
     * "a future caller cannot bypass it" mechanically true instead of a
     * caller convention. The guard call sits OUTSIDE the try block: a refusal
     * is a refusal, never re-wrapped as an HTTP failure.
     */
    public function post(string $endpoint, array $body = []): mixed
    {
        if ($this->targetsCheckCreation($endpoint)) {
            TacticalCheckPlatformGuard::assertSafe($body, $this);
        }

        try {
            $response = $this->http->request('POST', $endpoint, [
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            Log::error("[TacticalClient] POST {$endpoint} failed", [
                'status' => ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse())
                    ? $e->getResponse()->getStatusCode()
                    : null,
            ]);
            throw TacticalClientException::fromGuzzle("Tactical API error (HTTP POST {$endpoint})", $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * Whether $endpoint resolves to a Tactical CHECK-CREATION endpoint, for
     * the transport-seam platform guard above.
     *
     * THREE upstream URLs reach the check-creation view, and they are all the
     * same view object — `checks.views.GetAddChecks`. Read from the vendor
     * source at the commit this repo pins in
     * tests/Fixtures/tactical/upstream_producers.json (amidaware/tacticalrmm
     * 632a37a4, 2026-07-24) — cite, don't guess:
     *   - `checks/`                              api/tacticalrmm/checks/urls.py:6
     *   - `agents/{agent_id}/checks/`            api/tacticalrmm/agents/urls.py:21
     *   - `automation/policies/{policy}/checks/` api/tacticalrmm/automation/urls.py:14
     * The vendor comments both aliases as "alias for checks get view", but
     * `as_view()` publishes the WHOLE class-based view — the comment states
     * intent, not a constraint, and POST is dispatched there like any other
     * verb. Today `GetAddChecks.post(self, request)` accepts no URL kwargs, so
     * a POST to either alias raises TypeError upstream (500) rather than
     * creating: the alias gap is LATENT, not live. It is matched anyway
     * because this seam's whole claim is that no route reaches a check create
     * without server-derived platform evidence, and that claim must not rest
     * on an upstream handler signature. Both aliases are already in this
     * client's vocabulary as reads (getAgentChecks / getPolicyChecks), so a
     * future `post()` written by symmetry with them is exactly the mistake the
     * seam exists to make impossible (psa-y9ae5).
     *
     * TWO match levels, either sufficient (psa-ou9pe fix-forward — the
     * psa-0pb9m R5 raw-path matcher alone was bypassable):
     *
     *  1. SPELLING: the endpoint's own path normalizes to one of the creation
     *     shapes — query/fragment stripped, dot segments removed,
     *     percent-encoding decoded, duplicate slashes collapsed, surrounding
     *     slashes trimmed — so relative spellings ('checks', '/checks/',
     *     'checks/?dry=1', 'foo/../checks/', '%63hecks/',
     *     'agents/{id}/checks/') cannot carry an unguarded creation past the
     *     seam regardless of base_uri.
     *  2. RESOLUTION: the endpoint resolved against the configured base_uri —
     *     the EXACT UriResolver::resolve() Guzzle's buildUri() applies when
     *     it builds the request — lands on a creation shape underneath the
     *     API root. This is what closes the psa-ou9pe.1 STILL-PRESENT bypass:
     *     with base_uri https://host/api/v3/, a fully-resolved
     *     https://host/api/v3/checks/ (or absolute-path /api/v3/checks/, or
     *     ../v3/checks/) has raw path api/v3/checks — no spelling match — yet
     *     Guzzle sends the POST to the checks collection.
     *
     * The resolution comparison deliberately ignores scheme/host/port: origin
     * comparison is the classic allowlist-bypass zoo (host casing, explicit
     * default ports, trailing-dot FQDNs, IP-literal aliases of the same
     * server), and every miss there is an unguarded write. Matching the
     * resolved PATH alone over-guards a same-path request aimed at a foreign
     * origin — fail-closed the cheap way round: no legitimate caller posts a
     * check-creation path anywhere but the configured Tactical.
     *
     * The normalization (decode + slash-collapse) mirrors what the upstream
     * stack does before routing — WSGI PATH_INFO arrives percent-decoded and
     * fronting proxies merge duplicate slashes — so a spelling the SERVER
     * would route to a creation view cannot read as a different path here.
     * Casing is NOT folded: Django's `path()` routes are case-sensitive, so a
     * cased spelling ('CHECKS/') reaches no view at all, while folding would
     * refuse a legitimate POST to a same-named non-check path.
     *
     * Sub-paths (checks/{id}/…) are not creation and do not match. An
     * endpoint neither parse_url nor PSR-7 can parse cannot resolve to a
     * creation route at all — and no request is buildable from it either, so
     * nothing unguarded can be sent.
     */
    private function targetsCheckCreation(string $endpoint): bool
    {
        $rawPath = parse_url($endpoint, PHP_URL_PATH);
        if (is_string($rawPath) && $rawPath !== '' && self::isCheckCreationShape(self::normalizedRequestPath($rawPath))) {
            return true;
        }

        try {
            $endpointUri = Utils::uriFor($endpoint);
        } catch (\InvalidArgumentException) {
            return false; // unparseable: Guzzle cannot build a request from it either
        }

        if ($this->baseUri !== null) {
            $resolved = self::normalizedRequestPath(
                UriResolver::resolve($this->baseUri, $endpointUri)->getPath()
            );
            // The API root exactly as Guzzle roots a RELATIVE endpoint against
            // base_uri: './' reproduces the same merge (a base without a
            // trailing slash roots relative references at its PARENT
            // directory), so the root can never drift from the request that is
            // actually built.
            $root = self::normalizedRequestPath(
                UriResolver::resolve($this->baseUri, new Uri('./'))->getPath()
            );

            $relative = self::pathUnderRoot($resolved, $root);

            return $relative !== null && self::isCheckCreationShape($relative);
        }

        // No configured base: the API root is unknowable, so any absolute
        // endpoint whose path ENDS at a checks segment is guarded — a
        // deliberate over-approximation of the three creation shapes (fail
        // closed; only injected clients can lack a base, the config path
        // always sets one).
        if ($endpointUri->getHost() !== '') {
            $path = self::normalizedRequestPath($endpointUri->getPath());

            return $path === 'checks' || str_ends_with($path, '/checks');
        }

        return false;
    }

    /**
     * $path expressed relative to the API root, or null when it does not live
     * under that root at all — a same-origin path outside the configured API
     * prefix is not a Tactical route, so it cannot be a creation route.
     */
    private static function pathUnderRoot(string $path, string $root): ?string
    {
        if ($root === '') {
            return $path;
        }

        if ($path === $root) {
            return '';
        }

        return str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : null;
    }

    /**
     * Whether a normalized, API-root-relative path is one of the three
     * upstream check-CREATION routes cited on targetsCheckCreation().
     *
     * The id segments match ANY non-empty segment rather than the vendor's
     * converters (`<agent:agent_id>`, `<int:policy>`): a spelling the vendor
     * would not route can only ever over-guard — a refusal, loud and
     * recoverable — whereas a converter mismatch on our side would UNDER-guard
     * and let a write through, which is the only outcome this seam may never
     * produce.
     */
    private static function isCheckCreationShape(string $path): bool
    {
        if ($path === 'checks') {
            return true;
        }

        $segments = explode('/', $path);
        $last = count($segments) - 1;

        if ($segments[$last] !== 'checks') {
            return false;
        }

        // agents/{agent_id}/checks/
        if ($last === 2 && $segments[0] === 'agents' && $segments[1] !== '') {
            return true;
        }

        // automation/policies/{policy}/checks/
        return $last === 3
            && $segments[0] === 'automation'
            && $segments[1] === 'policies'
            && $segments[2] !== '';
    }

    /**
     * Normalize a URI path for the collection comparison above: RFC 3986
     * dot-segment removal (as the PSR-7 resolver applies when Guzzle builds
     * the request URI), percent-decoding and duplicate-slash collapsing (as
     * the upstream server stack applies before routing), a second dot-segment
     * pass (decoding can reveal new ones), then surrounding-slash trimming.
     */
    private static function normalizedRequestPath(string $path): string
    {
        $path = UriResolver::removeDotSegments($path);
        $path = rawurldecode($path);
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        $path = UriResolver::removeDotSegments($path);

        return trim($path, '/');
    }

    /**
     * PUT a Tactical endpoint. Returns the decoded response as-is (mixed).
     *
     * Several Tactical PUT endpoints (core/urlaction/{id}/, alerts/templates/{id}/,
     * and agent maintenance/custom-field endpoints) return the scalar "ok" rather
     * than an object — live-verified 2026-06-17. Return type is `mixed` to handle
     * both scalar and object responses; callers that need an array must guard
     * against a non-array return value themselves.
     */
    public function put(string $endpoint, array $body = []): mixed
    {
        try {
            $response = $this->http->request('PUT', $endpoint, [
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            Log::error("[TacticalClient] PUT {$endpoint} failed", [
                'status' => ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse())
                    ? $e->getResponse()->getStatusCode()
                    : null,
            ]);
            throw TacticalClientException::fromGuzzle("Tactical API error (HTTP PUT {$endpoint})", $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    public function patch(string $endpoint, array $body = []): array
    {
        try {
            $response = $this->http->request('PATCH', $endpoint, [
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            Log::error("[TacticalClient] PATCH {$endpoint} failed", [
                'status' => ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse())
                    ? $e->getResponse()->getStatusCode()
                    : null,
            ]);
            throw TacticalClientException::fromGuzzle("Tactical API error (HTTP PATCH {$endpoint})", $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    public function delete(string $endpoint): mixed
    {
        try {
            $response = $this->http->request('DELETE', $endpoint);
        } catch (GuzzleException $e) {
            Log::error("[TacticalClient] DELETE {$endpoint} failed", [
                'status' => ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse())
                    ? $e->getResponse()->getStatusCode()
                    : null,
            ]);
            throw TacticalClientException::fromGuzzle("Tactical API error (HTTP DELETE {$endpoint})", $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * Set a custom field value on an agent.
     */
    public function setAgentCustomField(string $agentId, int $fieldId, string $value): void
    {
        $this->put("agents/{$agentId}/", [
            'custom_fields' => [
                ['field' => $fieldId, 'string_value' => $value],
            ],
        ]);
    }

    public function getAgents(): array
    {
        return $this->get('agents/');
    }

    public function getAgent(string $agentId, ?int $timeout = null): array
    {
        return $this->get("agents/{$agentId}/", $timeout);
    }

    public function getClients(): array
    {
        return $this->get('clients/');
    }

    /**
     * List all automation policies (used as workstation/server policy options
     * during client creation).
     */
    public function getPolicies(): array
    {
        return $this->get('automation/policies/');
    }

    public function createAutomationPolicy(array $body): mixed
    {
        return $this->post('automation/policies/', $body);
    }

    public function getAutomationPolicy(int $policyId): array
    {
        return $this->get("automation/policies/{$policyId}/");
    }

    public function updateAutomationPolicy(int $policyId, array $body): mixed
    {
        return $this->put("automation/policies/{$policyId}/", $body);
    }

    public function deleteAutomationPolicy(int $policyId): mixed
    {
        return $this->delete("automation/policies/{$policyId}/");
    }

    public function getAutomationPolicyRelated(int $policyId): array
    {
        return $this->get("automation/policies/{$policyId}/related/");
    }

    public function updateClientPolicies(int $clientId, array $body): mixed
    {
        return $this->put("clients/{$clientId}/", ['client' => $body]);
    }

    public function updateSitePolicies(int $siteId, array $body): mixed
    {
        return $this->put("clients/sites/{$siteId}/", ['site' => $body]);
    }

    public function updateAgentPolicy(string $agentId, array $body): mixed
    {
        return $this->put("agents/{$agentId}/", $body);
    }

    public function getTasks(): array
    {
        return $this->get('tasks/');
    }

    public function getPolicyTasks(int $policyId): array
    {
        return $this->get("automation/policies/{$policyId}/tasks/");
    }

    public function getPolicyChecks(int $policyId): array
    {
        return $this->get("automation/policies/{$policyId}/checks/");
    }

    /**
     * Create a Tactical check (POST checks/) — the named front door for the
     * MANDATORY platform guard (psa-0pb9m revise). Enforcement itself lives
     * one seam below, in post(): every POST that resolves to a check-creation
     * route (the collection or either vendor alias of the same view) passes
     * TacticalCheckPlatformGuard::assertSafe() before any HTTP, so no route —
     * this wrapper, a future wrapper, or a raw post('checks/', …) — reaches
     * the upstream create without server-derived platform evidence (psa-0pb9m
     * R5: guarding only this wrapper left the generic transport as a second,
     * unguarded public write seam). What the guard refuses: unknown agent
     * platforms, scripts without verifiable platform metadata, dual
     * agent+policy targets, and provably incompatible scripts. A
     * platform-bound check on a POLICY target is allowed only on
     * server-derived membership proof (every current member agent on a
     * compatible platform, from a structurally complete membership read) —
     * there is no caller-assertable override, and deliberately no parameter
     * through which a caller can supply script metadata: the guard resolves
     * it itself from the synced catalog or a live getScripts read (psa-0pb9m
     * R3 — a caller claim is assertion, not evidence).
     *
     * @throws TacticalClientException when the guard refuses (nothing sent).
     */
    public function createCheck(array $body): mixed
    {
        return $this->post('checks/', $body);
    }

    public function createTask(array $body): mixed
    {
        return $this->post('tasks/', $body);
    }

    public function getTask(int $taskId): array
    {
        return $this->get("tasks/{$taskId}/");
    }

    public function updateTask(int $taskId, array $body): mixed
    {
        return $this->put("tasks/{$taskId}/", $body);
    }

    public function deleteTask(int $taskId): mixed
    {
        return $this->delete("tasks/{$taskId}/");
    }

    public function runTask(int $taskId, ?string $agentId = null): mixed
    {
        $body = $agentId !== null ? ['agent_id' => $agentId] : [];

        return $this->post("tasks/{$taskId}/run/", $body);
    }

    public function runPolicyTask(int $taskId): mixed
    {
        return $this->post("automation/tasks/{$taskId}/run/");
    }

    /**
     * Cached policy list for UI dropdowns. Returns an empty array if Tactical
     * is unreachable so the calling view can degrade gracefully.
     *
     * @return array<array{id:int,name:string}>
     */
    public static function cachedPolicies(): array
    {
        if (! TacticalConfig::isConfigured()) {
            return [];
        }

        try {
            return \Illuminate\Support\Facades\Cache::remember(
                'tactical:policies',
                300,
                fn () => (new self)->getPolicies(),
            );
        } catch (\Throwable $e) {
            Log::warning('[TacticalClient] cachedPolicies failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Create a new Tactical client with a single default site.
     *
     * TRMM accepts {"client": {...}, "site": {...}} on POST /clients/ and
     * creates both in one call (amidaware/tacticalrmm clients/views.py ::
     * GetAddClients.post). The response body is a plain JSON-encoded string
     * ("Client … was added") rather than an object, so we bypass the array-
     * typed post() helper and use the raw Guzzle call directly.
     *
     * `workstation_policy` and `server_policy` are TRMM policy IDs; pass null
     * to leave them unset (agent inherits global default behavior).
     *
     * @return array{client_name: string, site_name: string} The accepted
     *                                                       names, suitable for storing as tactical_site_id.
     */
    public function createClient(
        string $clientName,
        string $siteName = 'Main',
        ?int $workstationPolicyId = null,
        ?int $serverPolicyId = null,
    ): array {
        $clientBody = ['name' => $clientName];
        if ($workstationPolicyId !== null) {
            $clientBody['workstation_policy'] = $workstationPolicyId;
        }
        if ($serverPolicyId !== null) {
            $clientBody['server_policy'] = $serverPolicyId;
        }

        try {
            $this->http->request('POST', 'clients/', [
                'json' => [
                    'client' => $clientBody,
                    'site' => ['name' => $siteName],
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::error('[TacticalClient] POST clients/ failed', [
                'status' => ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse())
                    ? $e->getResponse()->getStatusCode()
                    : null,
            ]);
            throw TacticalClientException::fromGuzzle('Tactical API error (HTTP POST clients/)', $e);
        }

        return [
            'client_name' => $clientName,
            'site_name' => $siteName,
        ];
    }

    public function getScripts(?bool $showCommunityScripts = null, ?bool $showHiddenScripts = null): array
    {
        $query = [];
        if ($showCommunityScripts !== null) {
            $query['showCommunityScripts'] = $showCommunityScripts ? 'true' : 'false';
        }
        if ($showHiddenScripts !== null) {
            $query['showHiddenScripts'] = $showHiddenScripts ? 'true' : 'false';
        }

        return $this->get('scripts/'.($query !== [] ? '?'.http_build_query($query) : ''));
    }

    public function createScript(array $body): mixed
    {
        return $this->post('scripts/', $body);
    }

    public function getScriptDetail(int $scriptId): array
    {
        return $this->get("scripts/{$scriptId}/");
    }

    public function updateScript(int $scriptId, array $body): mixed
    {
        return $this->put("scripts/{$scriptId}/", $body);
    }

    public function deleteScript(int $scriptId): mixed
    {
        return $this->delete("scripts/{$scriptId}/");
    }

    public function downloadScript(int $scriptId, bool $withSnippets = true): array
    {
        $query = $withSnippets ? '' : '?with_snippets=false';

        return $this->get("scripts/{$scriptId}/download/{$query}");
    }

    /**
     * Run a curated script on an agent (sync `wait`). Tactical's wait endpoint
     * returns a legacy stdout/stderr string, then the agent patches the real
     * stdout/stderr/retcode to AgentHistory.script_results out-of-band. Keep
     * the normal script-run route for Tactical-side audit/history semantics,
     * then read the new history row to recover the true retcode when available.
     */
    public function runScript(string $agentId, int $scriptId, ?array $args = null, int $timeout = 120): mixed
    {
        $previousHistoryId = null;
        $historySnapshotAvailable = false;
        try {
            $previousHistoryId = $this->latestScriptHistoryId($this->getAgentHistory($agentId), $scriptId);
            $historySnapshotAvailable = true;
        } catch (TacticalClientException) {
            // Older Tactical roles may lack AgentHistoryPerms. Preserve the run
            // path and fall back to the legacy immediate response below.
        }

        $body = [
            'output' => 'wait',
            'script' => $scriptId,
            'timeout' => $timeout,
            'args' => $args ?? [],
            'env_vars' => [],
            'run_as_user' => false,
            'emails' => [],
            'emailMode' => 'default',
            'custom_field' => null,
            'save_all_output' => false,
        ];

        $raw = $this->post("agents/{$agentId}/runscript/", $body);

        if (! $historySnapshotAvailable) {
            return $raw;
        }

        try {
            return $this->waitForScriptHistoryResult($agentId, $scriptId, $previousHistoryId, $raw) ?? $raw;
        } catch (TacticalClientException) {
            return $raw;
        }
    }

    public function getAgentHistory(string $agentId): array
    {
        return $this->get("agents/{$agentId}/history/");
    }

    private function waitForScriptHistoryResult(string $agentId, int $scriptId, ?int $previousHistoryId, mixed $legacyResponse): ?array
    {
        $legacyOutput = is_scalar($legacyResponse) ? (string) $legacyResponse : null;

        for ($attempt = 0; $attempt < 4; $attempt++) {
            if ($attempt > 0) {
                usleep(250_000);
            }

            $result = $this->scriptHistoryResult($this->getAgentHistory($agentId), $scriptId, $previousHistoryId, $legacyOutput);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /** @param array<int, mixed> $history */
    private function latestScriptHistoryId(array $history, int $scriptId): ?int
    {
        $latest = null;
        foreach ($history as $item) {
            if (! is_array($item) || $this->historyScriptId($item) !== $scriptId) {
                continue;
            }

            $id = $this->positiveInteger($item['id'] ?? null);
            if ($id !== null && ($latest === null || $id > $latest)) {
                $latest = $id;
            }
        }

        return $latest;
    }

    /** @param array<int, mixed> $history */
    private function scriptHistoryResult(array $history, int $scriptId, ?int $previousHistoryId, ?string $legacyOutput): ?array
    {
        $bestId = null;
        $bestResult = null;
        foreach ($history as $item) {
            if (! is_array($item) || $this->historyScriptId($item) !== $scriptId) {
                continue;
            }

            $id = $this->positiveInteger($item['id'] ?? null);
            if ($id === null || ($previousHistoryId !== null && $id <= $previousHistoryId)) {
                continue;
            }

            $result = $item['script_results'] ?? null;
            if (! is_array($result)) {
                continue;
            }
            if (! $this->historyResultMatchesLegacyOutput($result, $legacyOutput)) {
                continue;
            }

            if ($bestId === null || $id > $bestId) {
                $bestId = $id;
                $bestResult = $result;
            }
        }

        return $bestResult;
    }

    /** @param array<string, mixed> $result */
    private function historyResultMatchesLegacyOutput(array $result, ?string $legacyOutput): bool
    {
        if ($legacyOutput === null) {
            return true;
        }

        $stdout = $result['stdout'] ?? '';
        $stderr = $result['stderr'] ?? '';
        if (! is_scalar($stdout) || ! is_scalar($stderr)) {
            return false;
        }

        return (string) $stdout.(string) $stderr === $legacyOutput;
    }

    /** @param array<string, mixed> $history */
    private function historyScriptId(array $history): ?int
    {
        $script = $history['script'] ?? null;
        if (is_array($script)) {
            return $this->positiveInteger($script['id'] ?? $script['pk'] ?? null);
        }

        return $this->positiveInteger($script);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Fire-and-forget script execution. Doesn't wait for output.
     */
    public function runScriptAsync(string $agentId, int $scriptId, ?array $args = null, int $timeout = 120): void
    {
        $this->put("agents/{$agentId}/runscript/", [
            'output' => 'forget',
            'script' => $scriptId,
            'timeout' => $timeout,
            'args' => $args ?? [],
            'env_vars' => [],
            'run_as_user' => false,
        ]);
    }

    /**
     * Reboot an agent now (sync `rebootnow` over NATS).
     *
     * Endpoint per spec §3: POST /agents/{id}/reboot/. The exact response shape
     * and the offline/natsdown body MUST be confirmed against the live Vultr/
     * Tactical box (P2 gated note) — mocked until then. A non-2xx / connect
     * failure raises TacticalClientException, which the action bus catches and
     * classifies (transport => offline, HTTP error => error).
     */
    public function reboot(string $agentId): mixed
    {
        // The reboot endpoint returns the JSON scalar "ok" (not an object), so the
        // response is intentionally typed mixed — any 2xx is success; non-2xx throws.
        return $this->post("agents/{$agentId}/reboot/", []);
    }

    /**
     * Run an ad-hoc command on an agent (sync over NATS). The most dangerous
     * capability in the integration (arbitrary RCE) — the request body is PINNED
     * here server-side (amendment C1): `custom_shell` is ALWAYS null (it would
     * specify an arbitrary interpreter path and bypass the shell allowlist),
     * `run_as_user` ALWAYS false, `env_vars` ALWAYS []. None of those is derived
     * from any caller input; this method takes no parameter for them by design.
     *
     * Endpoint per spec §3: POST /agents/{id}/cmd/. The endpoint returns the
     * command output as a bare JSON string (NOT an object), so the return is
     * typed `mixed` — RunCommandAction::execute normalizes it. The exact body is
     * merge-blocking-verified against the live box (a wrong body on an RCE
     * endpoint silently sends a malformed command).
     *
     * @param  string  $shell  Pre-validated allowlist value (cmd|powershell|shell)
     * @param  string  $cmd  The discrete command string — passed through verbatim,
     *                       NEVER shell-concatenated PSA-side
     */
    public function cmd(string $agentId, string $cmd, string $shell, int $timeout): mixed
    {
        return $this->post("agents/{$agentId}/cmd/", [
            'shell' => $shell,
            'cmd' => $cmd,
            'timeout' => $timeout,
            'custom_shell' => null,
            'run_as_user' => false,
            'env_vars' => [],
        ]);
    }

    /**
     * Shut an agent down (sync over NATS). Unlike reboot, the box stays OFF —
     * remote power-on is impossible (physical/IPMI only); the consequence copy
     * lives in ShutdownAction::summary().
     *
     * Endpoint per spec §3: POST /agents/{id}/shutdown/. Expects a scalar "ok"-
     * style body like reboot, so the return is typed `mixed`.
     */
    public function shutdown(string $agentId): mixed
    {
        return $this->post("agents/{$agentId}/shutdown/", []);
    }

    /**
     * Recover an agent's services. Endpoint per spec §3: POST
     * /agents/{id}/recover/ with body {mode}. `mode=mesh` is synchronous (P3 ships
     * mesh only — see RecoverAction); `tacagent` is async and deferred. Typed
     * `mixed` for shape-safety.
     */
    public function recover(string $agentId, string $mode): mixed
    {
        return $this->post("agents/{$agentId}/recover/", [
            'mode' => $mode,
        ]);
    }

    /**
     * Toggle an agent's maintenance mode (alert suppression). Amendment D3: a
     * PARTIAL PUT of {maintenance_mode: bool} to /agents/{id}/ — the same partial-
     * PUT shape proven by setAgentCustomField, NOT a read-modify-write of the full
     * agent object (which would risk clobbering concurrent field changes).
     */
    public function setMaintenance(string $agentId, bool $enabled): mixed
    {
        return $this->put("agents/{$agentId}/", [
            'maintenance_mode' => $enabled,
        ]);
    }

    public function getSoftware(string $agentId, ?int $timeout = null): array
    {
        return $this->get("software/{$agentId}/", $timeout);
    }

    public function getPatches(string $agentId, ?int $timeout = null): array
    {
        return $this->get("winupdate/{$agentId}/", $timeout);
    }

    public function scanPatches(string $agentId): mixed
    {
        return $this->post("winupdate/{$agentId}/scan/", []);
    }

    public function setPatchAction(int $patchId, string $action): mixed
    {
        return $this->put("winupdate/{$patchId}/", [
            'action' => $action,
        ]);
    }

    public function installApprovedPatches(string $agentId): mixed
    {
        return $this->post("winupdate/{$agentId}/install/", []);
    }

    public function createPatchPolicy(array $body): mixed
    {
        return $this->post('automation/patchpolicy/', $body);
    }

    public function updatePatchPolicy(int $patchPolicyId, array $body): mixed
    {
        return $this->put("automation/patchpolicy/{$patchPolicyId}/", $body);
    }

    public function deletePatchPolicy(int $patchPolicyId): mixed
    {
        return $this->delete("automation/patchpolicy/{$patchPolicyId}/");
    }

    public function resetPatchPolicies(array $body): mixed
    {
        return $this->post('automation/patchpolicy/reset/', $body);
    }

    public function getServices(string $agentId, ?int $timeout = null): array
    {
        return $this->get("services/{$agentId}/", $timeout);
    }

    public function controlService(string $agentId, string $serviceName, string $action): mixed
    {
        return $this->post("services/{$agentId}/{$this->pathSegment($serviceName)}/", [
            'sv_action' => $action,
        ]);
    }

    public function setServiceStartType(string $agentId, string $serviceName, string $startType): mixed
    {
        return $this->put("services/{$agentId}/{$this->pathSegment($serviceName)}/", [
            'startType' => $startType,
        ]);
    }

    public function getAgentChecks(string $agentId, ?int $timeout = null): array
    {
        return $this->get("agents/{$agentId}/checks/", $timeout);
    }

    /**
     * Mint MeshCentral remote-control deep-links for an agent.
     * Tokens are short-lived — callers MUST fetch at click-time and NEVER cache or log the URLs.
     *
     * Returns Tactical's decoded JSON: {hostname, control, terminal, file, status, client, site}
     * where control/terminal/file are absolute https:// URLs containing session tokens.
     *
     * @throws TacticalClientException
     */
    public function getMeshCentralLinks(string $agentId, ?int $timeout = null): array
    {
        return $this->get("agents/{$agentId}/meshcentral/", $timeout);
    }

    public function getAgentTasks(string $agentId): array
    {
        return $this->get("agents/{$agentId}/tasks/");
    }

    // ── Provisioning helpers (P7) ────────────────────────────────────────────

    /**
     * List all URL actions. GET core/urlaction/
     * Used after create to resolve the newly-created action's id (the POST
     * endpoint returns the scalar "ok", not an object with an id field —
     * live-verified 2026-06-17 against dev Tactical).
     *
     * @return list<array{id: int, name: string, ...}>
     */
    public function getUrlActions(): array
    {
        return $this->get('core/urlaction/');
    }

    /**
     * Create a URL action (webhook). POST core/urlaction/
     * Body is built by the provisioning service.
     *
     * NOTE: Tactical returns the scalar "ok" on success, not an object. The
     * provisioning service calls getUrlActions() immediately after to resolve
     * the new id by name.
     *
     * @return mixed Scalar "ok" on success.
     */
    public function createUrlAction(array $body): mixed
    {
        return $this->post('core/urlaction/', $body);
    }

    /**
     * Update an existing URL action. PUT core/urlaction/{id}/
     * Returns scalar "ok" on success (live-verified 2026-06-17).
     *
     * @return mixed Scalar "ok" on success.
     */
    public function updateUrlAction(int $id, array $body): mixed
    {
        return $this->put("core/urlaction/{$id}/", $body);
    }

    /**
     * List all alert templates. GET alerts/templates/
     * Used after create to resolve the newly-created template's id (the POST
     * endpoint returns the scalar "ok", not an object with an id field —
     * live-verified 2026-06-17 against dev Tactical).
     *
     * @return list<array{id: int, name: string, ...}>
     */
    public function getAlertTemplates(): array
    {
        return $this->get('alerts/templates/');
    }

    /**
     * Create an alert template. POST alerts/templates/
     *
     * NOTE: Tactical returns the scalar "ok" on success, not an object. The
     * provisioning service calls getAlertTemplates() immediately after to
     * resolve the new id by name.
     *
     * @return mixed Scalar "ok" on success.
     */
    public function createAlertTemplate(array $body): mixed
    {
        return $this->post('alerts/templates/', $body);
    }

    /**
     * Update an existing alert template. PUT alerts/templates/{id}/
     * Returns scalar "ok" on success (live-verified 2026-06-17).
     *
     * @return mixed Scalar "ok" on success.
     */
    public function updateAlertTemplate(int $id, array $body): mixed
    {
        return $this->put("alerts/templates/{$id}/", $body);
    }

    /**
     * Set the global default alert template. PUT core/settings/ {alert_template: id}
     * Only sends the alert_template field — not a read-modify-write of the full settings object.
     *
     * @return mixed Decoded response from Tactical (shape depends on Tactical version).
     */
    public function setDefaultAlertTemplate(int $templateId): mixed
    {
        return $this->put('core/settings/', ['alert_template' => $templateId]);
    }

    /**
     * Read the global core settings. GET core/settings/
     * Used by the provisioning service to check whether a default template is already set
     * before clobbering it.
     *
     * @return array Decoded settings object.
     */
    public function getCoreSettings(): array
    {
        return $this->get('core/settings/');
    }

    public function isHealthy(): bool
    {
        try {
            $this->getAgents();

            return true;
        } catch (TacticalClientException) {
            return false;
        }
    }

    /**
     * Get installer info for a Tactical site. TRMM deployment tokens have an
     * expiry; we request 7 days so the URL stays valid for a reasonable
     * window for an end user to click through the portal download page.
     *
     * Research (verified against TRMM v1.4.0 OpenAPI schema + source):
     *   - Endpoint: POST /agents/installer/ (amidaware/tacticalrmm agents/views.py :: install_agent)
     *   - Required body: installMethod, expires, client, site, goarch, plat, api, agenttype, rdp, ping, power
     *   - For installMethod in {"manual", "mac"}, the server returns JSON {"cmd": ..., "url": ...}
     *     where "url" is the pre-signed installer binary download URL we can hand to the user.
     *   - installMethod "exe" returns a generated .exe (FileResponse) rather than JSON.
     *   - installMethod "bash" returns a generated .sh script (FileResponse).
     *   - We pick "manual" for Windows and "mac" for mac/linux so we always get JSON back.
     *     Both return the same shape; the "cmd" differs by platform but we only consume "url".
     *
     * @param  string  $siteId  Format: "ClientName|SiteName" from clients.tactical_site_id
     * @param  string  $platform  One of: 'windows', 'mac', 'linux'
     */
    public function getInstallerInfo(string $siteId, string $platform): ?\App\Services\Portal\InstallerInfo
    {
        if (empty($siteId) || ! str_contains($siteId, '|')) {
            return null;
        }

        [$clientName, $siteName] = explode('|', $siteId, 2);
        $clientName = trim($clientName);
        $siteName = trim($siteName);

        if ($clientName === '' || $siteName === '') {
            return null;
        }

        $tacticalPlatform = match ($platform) {
            'windows' => 'windows',
            'mac' => 'darwin',
            'linux' => 'linux',
            default => null,
        };

        if ($tacticalPlatform === null) {
            return null;
        }

        // TRMM requires numeric client/site IDs; we only have names. Look them up.
        try {
            $clients = $this->getClients();
            $tacticalClient = collect($clients)->firstWhere('name', $clientName);
            if (! $tacticalClient || empty($tacticalClient['id'])) {
                return null;
            }

            $site = collect($tacticalClient['sites'] ?? [])->firstWhere('name', $siteName);
            if (! $site || empty($site['id'])) {
                return null;
            }

            $installMethod = $tacticalPlatform === 'windows' ? 'manual' : 'mac';

            $deployment = $this->post('agents/installer/', [
                'installMethod' => $installMethod,
                'client' => $tacticalClient['id'],
                'site' => $site['id'],
                'expires' => 168,                // hours (7 days)
                'agenttype' => 'workstation',
                'power' => 0,
                'ping' => 0,
                'rdp' => 0,
                'goarch' => 'amd64',
                'api' => \App\Support\TacticalConfig::apiUrl(),
                'plat' => $tacticalPlatform,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[TacticalClient] installer fetch failed', [
                'site_id' => $siteId,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $url = $deployment['url'] ?? $deployment['download_url'] ?? null;
        if (! $url) {
            return null;
        }

        return new \App\Services\Portal\InstallerInfo(
            downloadUrl: $url,
            instructions: 'Download the installer and run it. Your device will automatically register with our management system.',
        );
    }

    private function pathSegment(string $value): string
    {
        return rawurlencode($value);
    }
}
