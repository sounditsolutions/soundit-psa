<?php

namespace App\Services\Servosity;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class ServosityClient
{
    private Client $http;

    private ?string $mfaToken = null;

    public function __construct(
        private readonly array $config,
    ) {
        $baseUrl = rtrim($this->config['base_url'] ?? 'https://api.servosity.com', '/');

        $this->http = new Client([
            'base_uri' => $baseUrl.'/api/v1/',
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Token '.($this->config['api_token'] ?? ''),
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Make an authenticated GET request to the Servosity API.
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
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

            Log::warning("[ServosityClient] {$method} {$endpoint} failed: {$e->getMessage()}");
            throw new ServosityClientException(
                "Servosity API error: {$e->getMessage()}", $e->getCode(), $e
            );
        }

        // Cache signed MFA token from response for subsequent requests
        $signedToken = $response->getHeader('X-Servosity-Mfa-Token')[0] ?? null;
        if ($signedToken) {
            $this->mfaToken = $signedToken;
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
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
     * Uses the summary-ng endpoint which includes account_counts and issue_counts.
     * Django REST Framework pagination: follow `next` URL until null.
     */
    public function getCompanies(): array
    {
        $allCompanies = [];
        $url = 'companies/summary-ng/';
        $params = [];

        do {
            $response = $this->request('GET', $url, ['query' => $params]);

            $companies = $response['results'] ?? [];
            $allCompanies = array_merge($allCompanies, $companies);

            // Django REST Framework pagination: `next` is a full URL or null
            $nextUrl = $response['next'] ?? null;

            if ($nextUrl) {
                // Extract query params from the full next URL and use them
                $parsed = parse_url($nextUrl);
                parse_str($parsed['query'] ?? '', $params);
                // Keep using the same relative endpoint
            }
        } while ($nextUrl);

        return $allCompanies;
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
     * Internal request method.
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        try {
            $response = $this->http->request($method, $endpoint, $options);
        } catch (GuzzleException $e) {
            Log::warning("[ServosityClient] {$method} {$endpoint} failed: {$e->getMessage()}");
            throw new ServosityClientException(
                "Servosity API error: {$e->getMessage()}", $e->getCode(), $e
            );
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }
}
