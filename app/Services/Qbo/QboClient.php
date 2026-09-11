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

        Log::error('[QboClient] API request failed', [
            'method' => $method,
            'path' => $path,
            'status' => $statusCode,
            // psa-1354: QBO overloads HTTP 400 across genuine bad syntax and
            // Fault 610 "Object Not Found", which need opposite handling. The
            // Fault code is the only thing that separates them, so it is logged
            // alongside the status. Codes only — never Message/Detail, which can
            // carry request content.
            'fault_codes' => self::faultCodes($faultErrors),
            'error' => $e->getMessage(),
        ]);

        $detail = "QBO API error: {$method} {$path} returned {$statusCode}";

        if ($faultErrors) {
            $messages = array_map(fn ($err) => $err['Message'] ?? $err['Detail'] ?? '', $faultErrors);
            $detail .= ' — '.implode('; ', array_filter($messages));
        }

        throw new QboClientException(
            $detail,
            $statusCode,
            $responseBody,
        );
    }

    /**
     * The `code` of every entry in a QBO Fault.Error array, in order.
     *
     * Intuit documents `code` as a numeric string, but the wire is not trusted:
     * a non-scalar (array/object) entry is dropped rather than stringified, so
     * a malformed or hostile payload cannot smuggle structure into the log
     * context. Absent codes are dropped; the empty array means "no Fault, or no
     * codes in it", which is exactly what a transport-level failure looks like.
     *
     * @param  mixed  $faultErrors  Raw `Fault.Error` as decoded, shape unverified.
     * @return list<string>
     */
    private static function faultCodes(mixed $faultErrors): array
    {
        if (! is_array($faultErrors)) {
            return [];
        }

        $codes = [];

        foreach ($faultErrors as $error) {
            $code = is_array($error) ? ($error['code'] ?? null) : null;

            if (is_scalar($code) && (string) $code !== '') {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }
}
