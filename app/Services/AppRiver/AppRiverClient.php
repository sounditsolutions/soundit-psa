<?php

namespace App\Services\AppRiver;

use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class AppRiverClient
{
    private Client $http;

    private Client $authHttp;

    private const API_PREFIX = '/service/api/securecloud/';

    /**
     * OAuth error codes that mean the stored credentials are dead and must be
     * cleared. Shared by the nested-code promotion in tokenRequest() and the
     * match in handleRefreshFailure() so the two can never drift apart.
     */
    private const DEAD_CREDENTIAL_ERRORS = ['invalid_grant', 'invalid_client'];

    public function __construct(
        private readonly array $config = [],
    ) {
        $baseUrl = rtrim($this->config['base_url'] ?? self::defaultBaseUrl(), '/');

        // Optional Guzzle handler override — tests inject a MockHandler here so the
        // OAuth error-envelope handling can be exercised without a live endpoint.
        // Honoured ONLY under the test runner and only when callable: this replaces
        // the transport for both the API and the OAuth client, so a config source
        // that ever grew a 'handler' key must not be able to reach it in production.
        $handler = app()->runningUnitTests() && is_callable($this->config['handler'] ?? null)
            ? $this->config['handler']
            : null;

        // Drop only a null handler — a bare array_filter() would also swallow any
        // future falsy Guzzle option (e.g. 'http_errors' => false) with nothing in
        // the diff to show for it.
        $notNull = static fn (mixed $value): bool => $value !== null;

        $this->http = new Client(array_filter([
            'base_uri' => $baseUrl.self::API_PREFIX,
            'timeout' => 30,
            'handler' => $handler,
        ], $notNull));

        $this->authHttp = new Client(array_filter([
            'base_uri' => $baseUrl.'/',
            'timeout' => 15,
            'handler' => $handler,
        ], $notNull));
    }

    // ── OAuth2 Authorization Code Flow ──

    /**
     * Build the authorization URL for the browser redirect.
     */
    public function getAuthorizationUrl(string $state): string
    {
        $baseUrl = rtrim($this->config['base_url'] ?? self::defaultBaseUrl(), '/');
        $clientId = $this->config['client_id'] ?? Setting::getEncrypted('appriver_client_id');

        $params = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => route('auth.appriver.callback'),
            'state' => $state,
            'scope' => 'SecureCloud.Platform',
        ]);

        return $baseUrl.'/auth/authorize?'.$params;
    }

    /**
     * Exchange an authorization code for access + refresh tokens.
     */
    public function exchangeCode(string $code): void
    {
        $data = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => route('auth.appriver.callback'),
            'scope' => 'SecureCloud.Platform',
        ]);

        $this->storeTokens($data);
    }

    /**
     * Refresh the access token using the stored refresh token.
     */
    public function refreshToken(): void
    {
        $refreshToken = Setting::getEncrypted('appriver_refresh_token');

        if (! $refreshToken) {
            $this->disconnect();
            throw new AppRiverClientException('AppRiver refresh token not found. Please reconnect.');
        }

        $data = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => 'SecureCloud.Platform',
        ]);

        $this->storeTokens($data);
    }

    /**
     * Check if we have stored OAuth tokens (connected state).
     */
    public static function isConnected(): bool
    {
        return (bool) Setting::getEncrypted('appriver_access_token');
    }

    /**
     * Clear the stored OAuth credentials.
     *
     * appriver_connected_at is deliberately KEPT. It is the only field that
     * distinguishes "credentials died on <date>" from "never connected", which is
     * what a reconnect decision and any staleness reporting both need.
     *
     * Readers, enumerated rather than assumed: IntegrationsController:156 passes it
     * to the integrations view, which renders it in both the connected and the
     * disconnected branch. AppRiverConfig::get('connected_at') exposes it but has
     * no callers today. storeTokens() is the only writer and rewrites it on the
     * next successful connect.
     */
    public function disconnect(): void
    {
        foreach ([
            'appriver_access_token', 'appriver_refresh_token',
            'appriver_token_expires_at',
        ] as $key) {
            Setting::where('key', $key)->delete();
        }
    }

    // ── API Methods ──

    /**
     * Make an authenticated GET request.
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
    }

    /**
     * Make an authenticated PATCH request.
     */
    public function patch(string $endpoint, array $data): array
    {
        return $this->request('PATCH', $endpoint, ['json' => $data]);
    }

    /**
     * Check if the API is reachable.
     */
    public function isHealthy(): bool
    {
        try {
            $this->get('customers', ['limit' => 1]);

            return true;
        } catch (AppRiverClientException) {
            return false;
        }
    }

    /**
     * List all AppRiver customers (paginated).
     */
    public function getCustomers(): array
    {
        $all = [];
        $offset = 0;
        $limit = 100;

        do {
            $response = $this->get('customers', [
                'limit' => $limit,
                'offset' => $offset,
            ]);

            // An unrecognised envelope must NOT read as "no customers". The mapping
            // page renders one row per returned customer and its save treats the
            // submission as the complete world — it clears every mapping and reapplies
            // only what was posted — so a silently truncated list unmaps clients and
            // permanently zeroes their licences. Absent is not empty.
            if (! isset($response['Customers']) || ! is_array($response['Customers'])) {
                throw new AppRiverClientException('AppRiver customer list response has no Customers array; refusing to treat it as an empty list.');
            }

            if (! isset($response['TotalCount']) || ! is_numeric($response['TotalCount'])) {
                throw new AppRiverClientException('AppRiver customer list response has no TotalCount; refusing to treat the first page as complete.');
            }

            $customers = $response['Customers'];
            $totalCount = (int) $response['TotalCount'];

            foreach ($customers as $customer) {
                $all[] = $customer;
            }

            $offset += $limit;
        } while ($offset < $totalCount);

        return $all;
    }

    /**
     * List subscriptions for a customer.
     */
    public function getSubscriptions(string $customerId): array
    {
        $jsonKind = null;
        $response = $this->request('GET', "customers/{$customerId}/subscriptions", ['query' => []], $jsonKind);

        if (isset($response['Subscriptions']) && is_array($response['Subscriptions'])) {
            return $response['Subscriptions'];
        }

        // The bare list is the other shape this endpoint is known to return, and an EMPTY
        // one is an ordinary answer: a customer whose last subscription was cancelled has
        // none. Refusing it would withhold the stale cleanup this sync exists to perform
        // from that client on every run, permanently, and pin the nightly command at
        // FAILURE with no operator remedy. $jsonKind is what makes accepting it safe —
        // `{}`, an empty body and an unparseable one all decode to the same `[]`, so the
        // top-level JSON type is the only thing separating "the vendor sent a list of
        // none" from "we could not read the envelope".
        if ($jsonKind === 'list') {
            return $response;
        }

        // Everything past here is an envelope we do not recognise. Returning $response
        // would hand the sync a "subscription list" it never saw: the client would count
        // as fully observed, deactivateStale() would zero and suspend every one of its
        // licences, and the run would exit SUCCESS.
        if ($response === []) {
            throw new AppRiverClientException('AppRiver subscription response was empty or unparseable; refusing to treat it as an empty subscription list — a genuinely empty list arrives as a JSON list or {"Subscriptions":[]}.');
        }

        throw new AppRiverClientException('AppRiver subscription response has no Subscriptions array; refusing to treat the envelope as a subscription list.');
    }

    /**
     * Get full detail for a specific subscription.
     */
    public function getSubscriptionDetail(string $customerId, string $subscriptionKey): array
    {
        return $this->get("customers/{$customerId}/subscriptions/{$subscriptionKey}");
    }

    /**
     * Update the seat count for a subscription.
     */
    public function updateSubscriptionQuantity(string $customerId, string $subscriptionKey, int $quantity): array
    {
        return $this->patch("customers/{$customerId}/subscriptions/{$subscriptionKey}", [
            'ConfigurableSubscriptionDetails' => [
                [
                    'Name' => 'SubscriptionQuantity',
                    'Value' => (string) $quantity,
                ],
            ],
        ]);
    }

    // ── Internal ──

    private function getAccessToken(): string
    {
        $expiresAt = Setting::getValue('appriver_token_expires_at');

        // Refresh if expired or within 60 seconds of expiry
        if ($expiresAt && now()->gte(now()->parse($expiresAt)->subSeconds(60))) {
            try {
                $this->refreshToken();
            } catch (AppRiverClientException $e) {
                $this->handleRefreshFailure($e);
            }
        }

        $token = Setting::getEncrypted('appriver_access_token');

        if (! $token) {
            throw new AppRiverClientException('AppRiver access token not found. Please connect via Settings > Integrations > AppRiver.');
        }

        return $token;
    }

    /**
     * Translate a refresh failure into a clean, actionable exception. When the
     * refresh token is rejected (invalid_grant / invalid_client), the stored
     * credentials are dead — clear them so the UI prompts a reconnect.
     */
    private function handleRefreshFailure(AppRiverClientException $e): void
    {
        if (in_array($e->oauthError, self::DEAD_CREDENTIAL_ERRORS, true)) {
            $this->disconnect();
            $clean = new AppRiverClientException(
                'AppRiver session expired. Please reconnect in Settings > Integrations > AppRiver.',
                401,
                $e,
            );
            $clean->oauthError = $e->oauthError;
            throw $clean;
        }

        throw $e;
    }

    /**
     * Internal request method with Bearer token. Auto-retries once on 401 with token refresh.
     *
     * $jsonKind reports the top-level JSON type the body actually had — 'list', 'object',
     * or null when nothing parseable arrived. `{}`, an empty body and an unparseable one
     * all decode to the same `[]` as a genuinely empty JSON list, so a caller that must
     * tell "a list of none" from "an envelope we could not read" cannot do it from the
     * decoded value alone.
     */
    private function request(string $method, string $endpoint, array $options = [], ?string &$jsonKind = null): array
    {
        $jsonKind = null;

        for ($attempt = 0; $attempt <= 1; $attempt++) {
            $token = $this->getAccessToken();
            $options['headers'] = [
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ];

            try {
                $response = $this->http->request($method, $endpoint, $options);

                $body = (string) $response->getBody();
                $decoded = json_decode($body, true);

                if (! is_array($decoded)) {
                    return [];
                }

                // A body that decoded to an array started with either `[` or `{`, and the
                // two are indistinguishable afterwards — `[]` and `{}` both decode to `[]`.
                // The first non-whitespace byte is the only surviving witness.
                $jsonKind = str_starts_with(ltrim($body), '[') ? 'list' : 'object';

                return $decoded;
            } catch (GuzzleException $e) {
                $statusCode = method_exists($e, 'getResponse') && $e->getResponse()
                    ? $e->getResponse()->getStatusCode()
                    : 0;

                // On 401, refresh token and retry once
                if ($statusCode === 401 && $attempt === 0) {
                    Log::info('[AppRiver] 401 received, refreshing token and retrying');
                    try {
                        $this->refreshToken();

                        continue;
                    } catch (AppRiverClientException $refreshEx) {
                        Log::error("[AppRiver] Token refresh failed: {$refreshEx->getMessage()}");
                        $this->handleRefreshFailure($refreshEx);
                        throw $refreshEx;
                    }
                }

                // Extract error_description from JSON response if available
                $errorMsg = $e->getMessage();
                if (method_exists($e, 'getResponse') && $e->getResponse()) {
                    $body = json_decode((string) $e->getResponse()->getBody(), true);
                    if (! empty($body['error_description'])) {
                        $errorMsg = $body['error_description'];
                    }
                }

                Log::error("[AppRiver] {$method} {$endpoint} failed: {$errorMsg}");
                throw new AppRiverClientException($errorMsg, $statusCode, $e);
            }
        }

        throw new AppRiverClientException('AppRiver request failed after max retries');
    }

    /**
     * Make a token request to the OAuth2 token endpoint.
     * Client credentials sent via HTTP Basic Auth header.
     */
    private function tokenRequest(array $params): array
    {
        try {
            $response = $this->authHttp->post('auth/token', [
                'auth' => [$this->getClientId(), $this->getClientSecret()],
                'form_params' => $params,
            ]);
        } catch (GuzzleException $e) {
            // Pull OAuth error code + description out of the response body so
            // callers can react to invalid_grant / invalid_client cleanly.
            $oauthError = null;
            $oauthDesc = null;
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $body = json_decode((string) $e->getResponse()->getBody(), true);
                $oauthError = $body['error'] ?? null;
                $oauthDesc = $body['error_description'] ?? null;

                // AppRiver nests the real OAuth error inside error_description as
                // a JSON string (top-level error reads "invalid_request" even for a
                // dead refresh token), so handleRefreshFailure() never matches and
                // credentials are never cleared.
                //
                // Promote the nested code ONLY when it names a dead credential. An
                // unconditional override would mask an actionable top-level code
                // behind whatever the description happens to nest — the same bug in
                // the opposite direction.
                [$nested, $nestedDesc] = $this->nestedOauthEnvelope($oauthDesc);

                if ($nested !== null && in_array($nested, self::DEAD_CREDENTIAL_ERRORS, true)) {
                    $oauthError = $nested;
                }

                // Whatever the promotion decides, the nested envelope's own text is
                // what a human should read — the raw description here is the JSON
                // fragment that hid this bug in the first place.
                $oauthDesc = $nestedDesc ?? $nested ?? $oauthDesc;
            }

            $message = $oauthDesc
                ? "AppRiver OAuth token request failed: {$oauthDesc}"
                : "AppRiver OAuth token request failed: {$e->getMessage()}";

            Log::error("[AppRiver] Token request failed: {$message}", [
                'oauth_error' => $oauthError,
                // Recorded even when it is not promoted: a nested code outside
                // DEAD_CREDENTIAL_ERRORS is exactly what the top-level field hides,
                // and leaving it out of the log reproduces #389's diagnosability gap
                // for every error that is not a credential death.
                'oauth_error_nested' => $nested ?? null,
            ]);

            $ex = new AppRiverClientException($message, $e->getCode(), $e);
            $ex->oauthError = $oauthError;
            throw $ex;
        }

        $data = json_decode((string) $response->getBody(), true);

        if (empty($data['access_token'])) {
            throw new AppRiverClientException('AppRiver OAuth response missing access_token');
        }

        return $data;
    }

    /**
     * AppRiver returns a generic top-level error ("invalid_request") and puts the
     * real OAuth error code in error_description as a nested JSON string, e.g.
     * {"error":"invalid_request","error_description":"{\"error\":\"invalid_grant\"}"}.
     *
     * Returns [code, description] from that nested envelope, either element null when
     * absent or empty. Accepts the description already decoded as an array as well as
     * the JSON-string form, so a vendor that stops double-encoding does not silently
     * reopen this bug.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function nestedOauthEnvelope(mixed $description): array
    {
        $nested = is_array($description)
            ? $description
            : (is_string($description) ? json_decode($description, true) : null);

        if (! is_array($nested)) {
            return [null, null];
        }

        $code = isset($nested['error']) && is_string($nested['error']) && $nested['error'] !== ''
            ? $nested['error']
            : null;

        $desc = isset($nested['error_description']) && is_string($nested['error_description']) && $nested['error_description'] !== ''
            ? $nested['error_description']
            : null;

        return [$code, $desc];
    }

    /**
     * Store OAuth tokens from a token response.
     */
    private function storeTokens(array $data): void
    {
        Setting::setEncrypted('appriver_access_token', $data['access_token']);

        if (! empty($data['refresh_token'])) {
            Setting::setEncrypted('appriver_refresh_token', $data['refresh_token']);
        }

        $expiresIn = (int) ($data['expires_in'] ?? 1800);
        Setting::setValue('appriver_token_expires_at', now()->addSeconds($expiresIn)->toDateTimeString());
        Setting::setValue('appriver_connected_at', now()->toDateTimeString());
    }

    private function getClientId(): string
    {
        return $this->config['client_id'] ?? Setting::getEncrypted('appriver_client_id') ?? '';
    }

    private function getClientSecret(): string
    {
        return $this->config['client_secret'] ?? Setting::getEncrypted('appriver_client_secret') ?? '';
    }

    private static function defaultBaseUrl(): string
    {
        return Setting::getValue('appriver_base_url', 'https://unityapi.webrootcloudav.com');
    }
}
