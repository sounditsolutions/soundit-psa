<?php

namespace App\Services\Servosity;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class ServosityClient
{
    /**
     * Bound on the getCompanies() page walk. Generous (100 pages ×
     * page-size), but a walk that exceeds it THROWS instead of returning a
     * truncated list: getCompanies() feeds deactivateMissingClients(), and a
     * silently-truncated list would zero the licenses of every client on the
     * unseen pages. It also converts a looping/self-referential `next` URL
     * from an infinite CLI hang into a loud abort.
     */
    private const MAX_COMPANY_PAGES = 100;

    private Client $http;

    private ?string $mfaToken = null;

    public function __construct(
        private readonly array $config,
    ) {
        $baseUrl = rtrim($this->config['base_url'] ?? 'https://api.servosity.com', '/');

        $options = [
            'base_uri' => $baseUrl.'/api/v1/',
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Token '.($this->config['api_token'] ?? ''),
                'Accept' => 'application/json',
            ],
        ];

        // Test seam only: lets a test drive the REAL decode path with raw JSON
        // bodies (Guzzle MockHandler) instead of mocking this class away.
        if (isset($this->config['handler'])) {
            $options['handler'] = $this->config['handler'];
        }

        $this->http = new Client($options);
    }

    /**
     * Make an authenticated GET request to the Servosity API.
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
    }

    /**
     * GET with JSON container identity preserved (psa-z30dv.7): JSON objects
     * decode to stdClass and JSON arrays to PHP arrays, so `{}` and `[]` stay
     * distinguishable. The assoc-array view (get()) collapses that identity —
     * a documented object arriving as `[]`, or documented list `results`
     * arriving as `{}`, would read as a clean empty and turn schema drift into
     * a verified zero. Validating reads (the MCP read surface) MUST use this.
     */
    public function getJson(string $endpoint, array $params = []): mixed
    {
        return self::decodeJson($this->send('GET', $endpoint, ['query' => $params]), $endpoint);
    }

    /**
     * Make an authenticated POST request to the Servosity API.
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, ['json' => $data]);
    }

    /**
     * Make an authenticated POST request with MFA TOTP header.
     *
     * First request sends "{enrollment_id} {totp_code}". On success, the response
     * includes X-Servosity-Mfa-Token (signed, 30-min TTL). Subsequent requests
     * reuse the cached token until it expires.
     */
    public function postWithMfa(string $endpoint, array $data = []): array
    {
        $mfaValue = $this->resolveMfaHeader();
        if (! $mfaValue) {
            // No MFA configured — try without (will 403 on protected endpoints)
            return $this->post($endpoint, $data);
        }

        return $this->requestWithMfa('POST', $endpoint, ['json' => $data], $mfaValue);
    }

    private function resolveMfaHeader(): ?string
    {
        // Reuse cached signed token if available
        if ($this->mfaToken) {
            return $this->mfaToken;
        }

        $totp = \App\Support\ServosityConfig::generateTotp();
        $enrollmentId = \App\Support\ServosityConfig::get('totp_enrollment_id');

        if (! $totp || ! $enrollmentId) {
            return null;
        }

        return "{$enrollmentId} {$totp}";
    }

    private function requestWithMfa(string $method, string $endpoint, array $options, string $mfaValue): array
    {
        $options['headers']['X-Servosity-Mfa'] = $mfaValue;

        try {
            $response = $this->http->request($method, $endpoint, $options);
        } catch (GuzzleException $e) {
            // If cached token expired, retry with fresh TOTP
            if ($this->mfaToken && $e->getCode() === 403) {
                $this->mfaToken = null;
                $freshMfa = $this->resolveMfaHeader();
                if ($freshMfa) {
                    return $this->requestWithMfa($method, $endpoint, $options, $freshMfa);
                }
            }

            throw $this->sanitizedFailure($method, $endpoint, $e);
        }

        // Cache signed MFA token from response for subsequent requests
        $signedToken = $response->getHeader('X-Servosity-Mfa-Token')[0] ?? null;
        if ($signedToken) {
            $this->mfaToken = $signedToken;
        }

        return $this->decodeAssoc((string) $response->getBody(), $endpoint);
    }

    /**
     * Check if the Servosity API is reachable with the configured token.
     */
    public function isHealthy(): bool
    {
        try {
            $this->get('companies/summary-ng/', ['page' => 1]);

            return true;
        } catch (ServosityClientException) {
            return false;
        }
    }

    /**
     * Get all companies with account counts (auto-paginates).
     *
     * Uses the summary-ng endpoint (account_counts + issue_counts). This
     * list feeds the license sync — quantities, deactivation decisions and
     * synced_at stamps — and the Settings mapping surfaces, so every page is
     * proven against the documented producer shape BEFORE any caller can act
     * on it (psa-z30dv.15; seams 4–5 in ServosityShapes): identity-preserving
     * decode, DRF envelope, strict CompanySummaryNg rows, and a type-proven
     * `next` URL. Any violation THROWS (ServosityShapeDriftException) so the
     * caller aborts — it never receives a collapsed or partial list it would
     * read as "these clients are gone". All-or-nothing: the throw happens
     * before this method returns, so a caller either gets the fully-proven
     * list or no list.
     *
     * Rows come back in the legacy assoc-array view for the existing
     * consumers — safe only because container identity was proven first: a
     * `results: {}` or `account_counts: []` has already thrown by the time
     * the flattening happens.
     *
     * Django REST Framework pagination: follow `next` (a full URL) until
     * null, bounded by MAX_COMPANY_PAGES (exceeding it throws — see the
     * constant's note).
     */
    public function getCompanies(): array
    {
        $allCompanies = [];
        $endpoint = 'companies/summary-ng/';
        $params = [];

        for ($page = 1; $page <= self::MAX_COMPANY_PAGES; $page++) {
            $response = $this->getJson($endpoint, $params);
            ServosityShapes::assertDrfEnvelope($response, $endpoint);
            foreach ($response->results as $row) {
                ServosityShapes::assertCompanySummaryRow($row);
                // Proven — the identity-collapsing assoc view is now safe.
                $allCompanies[] = json_decode(json_encode($row), true);
            }

            // Documented: `next` is a URI string or null. Anything else must
            // not be read as "last page" — stopping early truncates the list.
            $nextUrl = $response->next ?? null;
            if ($nextUrl === null) {
                return $allCompanies;
            }
            if (! is_string($nextUrl) || $nextUrl === '') {
                throw new ServosityShapeDriftException("Servosity {$endpoint} response carried a next URL that is neither the documented URI string nor null — refusing to treat it as the last page.");
            }
            // Keep the same relative endpoint; adopt the next URL's query
            // (the page cursor).
            parse_str(parse_url($nextUrl, PHP_URL_QUERY) ?: '', $params);
        }

        throw new ServosityClientException('Servosity companies/summary-ng/ pagination exceeded '.self::MAX_COMPANY_PAGES.' pages; aborting rather than acting on a possibly-incomplete company list.');
    }

    /**
     * Get full company detail including agent_provision_token_id.
     */
    public function getCompany(int $companyId): array
    {
        return $this->get("companies/{$companyId}/");
    }

    /**
     * Create a DR backup account.
     *
     * @param  array{company: int, device_name: string, product_type: string}  $data
     *                                                                                product_type: DR_DESKTOP, DR_SERVER, or DR_LINUX
     */
    public function createDrBackup(array $data): array
    {
        return $this->post('dr-backups/', $data);
    }

    /**
     * Create a credential entry for a company.
     *
     * @param  array{company: int, name: string, username: string, password: string, domain: string}  $data
     */
    public function createCredential(array $data): array
    {
        return $this->postWithMfa('credentials/', $data);
    }

    /**
     * Make an authenticated PUT request to the Servosity API.
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $endpoint, ['json' => $data]);
    }

    /**
     * Get unprovisioned agents for a reseller.
     *
     * @return array List of unprovisioned agent objects with agent_session and agent_provision_token_id
     */
    public function getUnprovisionedAgents(int $resellerId): array
    {
        return $this->get("resellers/{$resellerId}/agents/unprovisioned/");
    }

    /**
     * Link an agent session to a company and/or backup account.
     *
     * @param  array{agent_session_id: string, company_id?: int, dr_backup_id?: int}  $data
     */
    public function agentLogin(array $data): array
    {
        return $this->post('agent-login/', $data);
    }

    /**
     * Install SPX backup software on an agent.
     */
    public function installSpx(string $agentSessionId): array
    {
        return $this->put("agent-sessions/{$agentSessionId}/install-spx/");
    }

    /**
     * Get the Servosity ScreenConnect download URL for a company.
     */
    public function getConnectWiseDownloadUrl(int $companyId): ?string
    {
        $response = $this->get("companies/{$companyId}/connectwise-download-url/");

        return $response['connectwise_download_url'] ?? null;
    }

    /**
     * Internal request method: the legacy assoc-array view. JSON objects and
     * arrays both become PHP arrays here — fine for the write/sync callers
     * that consume known-present keys, but validating reads must use
     * getJson() (container identity) instead.
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        return $this->decodeAssoc($this->send($method, $endpoint, $options), $endpoint);
    }

    /**
     * Perform the HTTP exchange and return the raw body. The one sanitized
     * seam for transport failures (see sanitizedFailure()).
     */
    private function send(string $method, string $endpoint, array $options = []): string
    {
        try {
            $response = $this->http->request($method, $endpoint, $options);
        } catch (GuzzleException $e) {
            throw $this->sanitizedFailure($method, $endpoint, $e);
        }

        return (string) $response->getBody();
    }

    /**
     * Decode a response body to the legacy assoc-array view. Invalid JSON is
     * REJECTED (psa-z30dv.7) — the old `json_decode(...) ?? []` collapse
     * turned an unparseable vendor answer into a clean empty list, which a
     * sync or read then treated as "zero rows". A response that cannot be
     * parsed is a failed read and must scream. An empty or JSON `null` body
     * stays [] for the legacy callers' sake (write endpoints may 204).
     */
    private function decodeAssoc(string $body, string $endpoint): array
    {
        if (trim($body) === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw self::invalidJson($endpoint, $e);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Decode a response body preserving JSON container identity: objects →
     * stdClass, arrays → PHP arrays. Public static so tests can build
     * fixtures through the EXACT production decode instead of hand-authoring
     * pre-decoded trees (the psa-7lgo fixture rule). Invalid JSON is rejected,
     * never collapsed to an empty value.
     */
    public static function decodeJson(string $body, string $endpoint): mixed
    {
        try {
            return json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw self::invalidJson($endpoint, $e);
        }
    }

    /**
     * Invalid JSON is schema drift, not a transport failure: the API answered,
     * but with something no documented shape covers. Thrown as
     * ServosityShapeDriftException so the read surface reports schema_drift,
     * while legacy ServosityClientException catches still contain it (it is a
     * subclass). Message carries our endpoint string only — never body text —
     * and numeric path segments are redacted: they are vendor identifiers
     * (company / DR account ids), and this message lands verbatim in drift
     * logs, which must name the seam, not the identifier (psa-z30dv.14).
     */
    private static function invalidJson(string $endpoint, \JsonException $e): ServosityShapeDriftException
    {
        return new ServosityShapeDriftException(
            'Servosity '.preg_replace('/\d+/', '{id}', $endpoint).' response was not valid JSON.', 0, $e
        );
    }

    /**
     * One sanitized seam for every failed vendor request (psa-z30dv.6): a
     * Guzzle exception message embeds the full request URL and can quote the
     * response body — configured hosts, tokens in query strings, customer
     * text. Neither the log line nor the thrown exception's message may carry
     * it; only bounded structural fields travel (our own relative endpoint
     * string, exception class, HTTP/transport code). The raw exception stays
     * chained as ->getPrevious() for a debugger, which does not hit logs.
     */
    private function sanitizedFailure(string $method, string $endpoint, GuzzleException $e): ServosityClientException
    {
        Log::warning('[ServosityClient] request failed', [
            'method' => $method,
            'endpoint' => $endpoint,
            'exception' => $e::class,
            'code' => $e->getCode(),
        ]);

        return new ServosityClientException(
            "Servosity API request failed: {$method} {$endpoint} (code {$e->getCode()})", $e->getCode(), $e
        );
    }
}
