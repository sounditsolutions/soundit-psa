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

    public function __construct(
        private readonly array $config = [],
    ) {
        $baseUrl = rtrim($this->config['base_url'] ?? self::defaultBaseUrl(), '/');

        $this->http = new Client([
            'base_uri' => $baseUrl.self::API_PREFIX,
            'timeout' => 30,
        ]);

        $this->authHttp = new Client([
            'base_uri' => $baseUrl.'/',
            'timeout' => 15,
        ]);
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
     * Clear all stored OAuth tokens.
     */
    public function disconnect(): void
    {
        foreach ([
            'appriver_access_token', 'appriver_refresh_token',
            'appriver_token_expires_at', 'appriver_connected_at',
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

            $customers = $response['Customers'] ?? [];
            $totalCount = $response['TotalCount'] ?? 0;

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
        $response = $this->get("customers/{$customerId}/subscriptions");

        return $response['Subscriptions'] ?? $response;
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
        if (in_array($e->oauthError, ['invalid_grant', 'invalid_client'], true)) {
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
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        for ($attempt = 0; $attempt <= 1; $attempt++) {
            $token = $this->getAccessToken();
            $options['headers'] = [
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ];

            try {
                $response = $this->http->request($method, $endpoint, $options);

                return json_decode((string) $response->getBody(), true) ?? [];
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
            }

            $message = $oauthDesc
                ? "AppRiver OAuth token request failed: {$oauthDesc}"
                : "AppRiver OAuth token request failed: {$e->getMessage()}";

            Log::error("[AppRiver] Token request failed: {$message}", [
                'oauth_error' => $oauthError,
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
