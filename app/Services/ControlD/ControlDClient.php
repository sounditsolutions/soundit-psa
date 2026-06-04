<?php

namespace App\Services\ControlD;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class ControlDClient
{
    private Client $http;

    public function __construct(
        private readonly array $config,
    ) {
        $this->http = new Client([
            'base_uri' => 'https://api.controld.com/',
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer '.($this->config['api_key'] ?? ''),
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Make an authenticated GET request to the Control D API.
     */
    public function get(string $endpoint): array
    {
        try {
            $response = $this->http->request('GET', $endpoint);
        } catch (GuzzleException $e) {
            Log::error("[ControlDClient] GET {$endpoint} failed: {$e->getMessage()}");
            throw new ControlDClientException(
                "Control D API error: {$e->getMessage()}", $e->getCode(), $e
            );
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }

    /**
     * Check if the Control D API is reachable with the configured credentials.
     */
    public function isHealthy(): bool
    {
        try {
            $this->get('profiles');

            return true;
        } catch (ControlDClientException) {
            return false;
        }
    }

    /**
     * Make an authenticated GET request scoped to a specific sub-organization.
     * Control D requires X-Force-Org-Id header to access sub-org resources.
     */
    public function getForOrg(string $endpoint, string $orgPk): array
    {
        try {
            $response = $this->http->request('GET', $endpoint, [
                'headers' => [
                    'X-Force-Org-Id' => $orgPk,
                ],
            ]);
        } catch (GuzzleException $e) {
            Log::error("[ControlDClient] GET {$endpoint} (org: {$orgPk}) failed: {$e->getMessage()}");
            throw new ControlDClientException(
                "Control D API error: {$e->getMessage()}", $e->getCode(), $e
            );
        }

        $body = (string) $response->getBody();

        return json_decode($body, true) ?? [];
    }

    /**
     * Get all devices for a sub-organization.
     */
    public function getDevices(string $orgPk): array
    {
        $response = $this->getForOrg('devices', $orgPk);

        return $response['body']['devices'] ?? [];
    }

    /**
     * Get all sub-organizations with device counts.
     * Response is wrapped as { body: { sub_organizations: [...] } } — two-level unwrapping.
     */
    public function getSubOrganizations(): array
    {
        $response = $this->get('organizations/sub_organizations');

        return $response['body']['sub_organizations'] ?? [];
    }

    /**
     * Get the parent organization data (includes stats_endpoint).
     */
    public function getOrganization(): array
    {
        $response = $this->get('organizations/organization');

        return $response['body']['organization'] ?? [];
    }

    /**
     * Get the stats endpoint (analytics subdomain) from the org API.
     * Returns e.g. "jfk-org01" or null if not available.
     */
    public function getStatsEndpoint(): ?string
    {
        $org = $this->getOrganization();

        return $org['stats_endpoint'] ?? null;
    }
}
