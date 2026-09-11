<?php

namespace App\Services\Qbo;

use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class QboClient
{
    private Client $http;

    private const AUTH_URL = 'https://appcenter.intuit.com/connect/oauth2';

    private const TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    /**
     * Longest Fault `code` copied into the log, in BYTES; real codes are 3–4
     * characters. Bytes, not characters, because the bound exists to cap what
     * reaches the log file: 32 astral-plane characters are 128 bytes, and more
     * again once a formatter escapes them.
     */
    private const MAX_FAULT_CODE_BYTES = 32;

    /** Most DISTINCT Fault codes copied into one log line. */
    private const MAX_FAULT_CODES = 25;

    private const BASE_URLS = [
        'production' => 'https://quickbooks.api.intuit.com/v3/company/',
        'sandbox' => 'https://sandbox-quickbooks.api.intuit.com/v3/company/',
    ];

    /**
     * @param  Client|null  $http  HTTP seam. Defaults to a plain 30s client in
     *                             production; injected by tests so the error
     *                             path can be driven through a real Guzzle
     *                             stack rather than a throwing fake.
     */
    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 30]);
    }

    public function getAuthorizationUrl(string $state): string
    {
        $clientId = Setting::getEncrypted('qbo_client_id');
        $redirectUri = route('auth.qbo.callback');

        $params = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'scope' => 'com.intuit.quickbooks.accounting',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return self::AUTH_URL.'?'.$params;
    }

    public function exchangeCode(string $code, string $realmId): void
    {
        $clientId = Setting::getEncrypted('qbo_client_id');
        $clientSecret = Setting::getEncrypted('qbo_client_secret');

        try {
            $response = $this->http->post(self::TOKEN_URL, [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => route('auth.qbo.callback'),
                ],
                'headers' => [
                    'Authorization' => 'Basic '.base64_encode($clientId.':'.$clientSecret),
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::error('[QboClient] Token exchange failed', ['error' => $e->getMessage()]);
            throw new QboClientException('Failed to exchange authorization code: '.$e->getMessage());
        }

        $data = json_decode((string) $response->getBody(), true);

        Setting::setEncrypted('qbo_access_token', $data['access_token']);
        Setting::setEncrypted('qbo_refresh_token', $data['refresh_token']);
        Setting::setValue('qbo_realm_id', $realmId);
        Setting::setValue('qbo_token_expires_at', now()->addSeconds($data['expires_in'] ?? 3600)->toDateTimeString());
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    public function post(string $path, array $data): array
    {
        return $this->request('POST', $path, ['json' => $data]);
    }

    public function query(string $sql): array
    {
        return $this->get('query', ['query' => $sql]);
    }

    public function isConnected(): bool
    {
        return (bool) Setting::getValue('qbo_realm_id')
            && (bool) Setting::getValue('qbo_access_token');
    }

    public function disconnect(): void
    {
        foreach ([
            'qbo_access_token', 'qbo_refresh_token', 'qbo_realm_id',
            'qbo_token_expires_at',
        ] as $key) {
            Setting::where('key', $key)->delete();
        }
    }

    private function request(string $method, string $path, array $options = []): array
    {
        $this->guardEnvironment();

        $token = $this->getAccessToken();
        $url = $this->buildUrl($path);

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);

        for ($attempt = 0; $attempt <= 1; $attempt++) {
            try {
                $response = $this->http->request($method, $url, $options);
                break;
            } catch (GuzzleException $e) {
                $statusCode = method_exists($e, 'getResponse') && $e->getResponse()
                    ? $e->getResponse()->getStatusCode()
                    : 0;

                // Retry once on 401 with a refreshed token
                if ($statusCode === 401 && $attempt === 0) {
                    $this->refreshToken();
                    $freshToken = $this->getAccessToken();
                    $options['headers']['Authorization'] = 'Bearer '.$freshToken;

                    continue;
                }

                $this->throwFromGuzzle($e, $method, $path);
            }
        }

        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new QboClientException(
                "Invalid JSON response from QBO API: {$method} {$path}",
                $response->getStatusCode(),
            );
        }

        return $decoded;
    }

    private function getAccessToken(): string
    {
        $expiresAt = Setting::getValue('qbo_token_expires_at');

        // Refresh if expired or within 60 seconds of expiry
        if ($expiresAt && now()->gte(now()->parse($expiresAt)->subSeconds(60))) {
            $this->refreshToken();
        }

        $token = Setting::getEncrypted('qbo_access_token');

        if (! $token) {
            throw new QboClientException('QBO access token not found. Please reconnect to QuickBooks.');
        }

        return $token;
    }

    private function refreshToken(): void
    {
        $refreshToken = Setting::getEncrypted('qbo_refresh_token');
        $clientId = Setting::getEncrypted('qbo_client_id');
        $clientSecret = Setting::getEncrypted('qbo_client_secret');

        if (! $refreshToken) {
            $this->disconnect();
            throw new QboClientException('QBO refresh token not found. Please reconnect to QuickBooks.');
        }

        try {
            $response = $this->http->post(self::TOKEN_URL, [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
                'headers' => [
                    'Authorization' => 'Basic '.base64_encode($clientId.':'.$clientSecret),
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::error('[QboClient] Token refresh failed', ['error' => $e->getMessage()]);

            // Retry once
            try {
                sleep(2);
                $response = $this->http->post(self::TOKEN_URL, [
                    'form_params' => [
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $refreshToken,
                    ],
                    'headers' => [
                        'Authorization' => 'Basic '.base64_encode($clientId.':'.$clientSecret),
                        'Accept' => 'application/json',
                    ],
                ]);
            } catch (GuzzleException $retryEx) {
                Log::error('[QboClient] Token refresh retry failed, disconnecting', ['error' => $retryEx->getMessage()]);
                $this->disconnect();
                throw new QboClientException('QBO token refresh failed after retry. Please reconnect to QuickBooks.');
            }
        }

        $data = json_decode((string) $response->getBody(), true);

        Setting::setEncrypted('qbo_access_token', $data['access_token']);
        Setting::setEncrypted('qbo_refresh_token', $data['refresh_token']);
        Setting::setValue('qbo_token_expires_at', now()->addSeconds($data['expires_in'] ?? 3600)->toDateTimeString());
    }

    private function buildUrl(string $path): string
    {
        $environment = Setting::getValue('qbo_environment', 'sandbox');
        $realmId = Setting::getValue('qbo_realm_id');

        if (! $realmId) {
            throw new QboClientException('QBO Realm ID not set. Please reconnect to QuickBooks.');
        }

        $baseUrl = self::BASE_URLS[$environment] ?? self::BASE_URLS['sandbox'];

        return $baseUrl.$realmId.'/'.ltrim($path, '/');
    }

    private function guardEnvironment(): void
    {
        $qboEnvironment = Setting::getValue('qbo_environment', 'sandbox');

        if ($qboEnvironment === 'production' && app()->environment() !== 'production') {
            throw new QboClientException(
                'Cannot access production QBO from non-production environment. '
                .'Current APP_ENV: '.app()->environment()
            );
        }
    }

    /**
     * @throws QboClientException
     */
    private function throwFromGuzzle(GuzzleException $e, string $method, string $path): never
    {
        $statusCode = 0;
        $responseBody = null;

        if (method_exists($e, 'getResponse') && $e->getResponse()) {
            $statusCode = $e->getResponse()->getStatusCode();
            $responseBody = json_decode((string) $e->getResponse()->getBody(), true);
        }

        // Extract human-readable error from QBO Fault response
        $faultErrors = $responseBody['Fault']['Error'] ?? [];
        $faultEntries = self::faultErrorEntries($faultErrors);

        Log::error('[QboClient] API request failed', [
            'method' => $method,
            'path' => $path,
            'status' => $statusCode,
            // psa-1354: QBO overloads HTTP 400 across genuine bad syntax and
            // Fault 610 "Object Not Found", which need opposite handling. The
            // Fault code is the only thing that separates them, so it is logged
            // alongside the status. Codes only — never Message/Detail, which can
            // carry request content.
            'fault_codes' => self::faultCodes($faultEntries),
            // How many Error entries the Fault carried, readable or not — and
            // "or not" is load-bearing: every present-but-unrecognised shape
            // counts as at least one entry (see faultErrorEntries), so this is
            // 0 only when no Fault arrived. Without
            // it an empty `fault_codes` says two very different things with one
            // value: "no Fault came back at all" and "a Fault came back whose
            // codes I could not read". A count is a plain int — it carries none
            // of the Message/Detail bytes the codes-only rule above excludes.
            'fault_error_count' => count($faultEntries),
            'error' => $e->getMessage(),
        ]);

        $detail = "QBO API error: {$method} {$path} returned {$statusCode}";

        // is_array guards a pre-existing fatal, nothing more: a scalar
        // `Fault.Error` would put a string through array_map and raise a
        // TypeError out of the error path itself. The text this produces is
        // unchanged for every shape that did not fatal (a non-array entry
        // already yielded '' through the null-coalesce); the detail path proper
        // is owned by the forward log-remediation work, not by this change.
        if (is_array($faultErrors) && $faultErrors) {
            $messages = array_map(fn ($err) => is_array($err) ? ($err['Message'] ?? $err['Detail'] ?? '') : '', $faultErrors);
            $detail .= ' — '.implode('; ', array_filter($messages));
        }

        throw new QboClientException(
            $detail,
            $statusCode,
            $responseBody,
        );
    }

    /**
     * A QBO `Fault.Error` as a list of entries, whatever shape it arrived in.
     *
     * Intuit documents `Fault.Error` as an array, and it is one on every
     * response we have seen. But single-element collapsing — an object where a
     * list is expected — is the standard XML-to-JSON translation hazard on
     * payloads of this shape, and a collapsed Error would silently lose the one
     * value this logging exists to surface. So a collapsed object is wrapped, a
     * numbered or sparse map of entries is re-listed, and anything else that is
     * present but unrecognised counts as ONE entry that cannot be read.
     *
     * Nothing here returns an empty list for a Fault that did arrive. That is
     * the whole point: the count beside `fault_codes` is derived from what this
     * returns, and a Fault whose shape defeats the reader must not log the same
     * pair as a response carrying no Fault at all.
     *
     * @param  mixed  $faultErrors  Raw `Fault.Error` as decoded, shape unverified.
     * @return list<mixed>
     */
    private static function faultErrorEntries(mixed $faultErrors): array
    {
        if (! is_array($faultErrors)) {
            // A scalar `Error` is an entry this code cannot read, which is not
            // the same fact as no Fault at all — so it counts as one entry and
            // yields no code. Absent or empty is nothing.
            return ($faultErrors === null || $faultErrors === '') ? [] : [$faultErrors];
        }

        if (array_is_list($faultErrors)) {
            return $faultErrors;
        }

        // A `code` key positively identifies one collapsed entry, so it is
        // checked BEFORE the map heuristic below — an entry whose values all
        // happen to be arrays would otherwise be read as a map of two entries.
        // array_key_exists, not isset, because the predicate means "carries a
        // code key" and a present-but-null code is an entry I cannot read, not
        // a missing key. (The catch-all at the end means the two spellings agree
        // on today's shapes; the difference is that this one says what it means.)
        if (array_key_exists('code', $faultErrors)) {
            return [$faultErrors];
        }

        // A keyed map whose every value is an array is a list the translator
        // numbered from 1, or left sparse — the same XML-to-JSON hazard as
        // collapsing, one shape over.
        if (array_filter($faultErrors, 'is_array') === $faultErrors) {
            return array_values($faultErrors);
        }

        // Anything else non-list: one entry that arrived and cannot be read.
        return [$faultErrors];
    }

    /**
     * The `code` of every readable entry in a QBO Fault.Error, in order.
     *
     * Intuit documents `code` as a short numeric string, but the wire is not
     * trusted, and three separate limits say so rather than resting on that
     * documentation:
     *
     *  - only `string` and `int` codes are kept. `is_scalar` would also admit
     *    bool and float, and `(string) true` is `'1'` — a value shaped exactly
     *    like a real fault code. A fabricated code is worse than an absent one,
     *    and `true`/`false` would otherwise take opposite paths for no reason.
     *  - each code is cut to MAX_FAULT_CODE_BYTES *bytes* (mb_strcut, so never
     *    mid-character). Real codes are 3–4 characters; anything longer means a
     *    malformed upstream, an intermediary or a future schema change, and none
     *    of those may copy an unbounded span of wire bytes into a log line that
     *    is written on every failure. A character bound would not say that: 32
     *    astral-plane characters are 128 bytes.
     *  - at most MAX_FAULT_CODES DISTINCT codes are kept, so a Fault carrying
     *    thousands of Error entries cannot do the same thing by cardinality.
     *    Distinct, not positional: 25 repetitions of one code would otherwise
     *    exhaust the budget and hide the single 610 behind them, which is the
     *    exact value the operator needs. `fault_error_count` still reports every
     *    entry, so cardinality is not lost by de-duplicating here.
     *
     * Truncation deliberately is NOT an allow-list regex: a code Intuit adds
     * later should still reach the operator, merely bounded. Nothing here is a
     * substitute for `fault_error_count` — an empty list means "no code I was
     * willing to read", which is not the same fact as "no Fault at all", and the
     * count beside it is what separates the two.
     *
     * @param  list<mixed>  $faultEntries  From faultErrorEntries(); entry shapes unverified.
     * @return list<string>
     */
    private static function faultCodes(array $faultEntries): array
    {
        $codes = [];

        foreach ($faultEntries as $error) {
            $code = is_array($error) ? ($error['code'] ?? null) : null;

            if (! is_string($code) && ! is_int($code)) {
                continue;
            }

            $code = mb_strcut((string) $code, 0, self::MAX_FAULT_CODE_BYTES, 'UTF-8');

            if ($code === '' || in_array($code, $codes, true)) {
                continue;
            }

            $codes[] = $code;

            if (count($codes) >= self::MAX_FAULT_CODES) {
                break;
            }
        }

        return $codes;
    }
}
