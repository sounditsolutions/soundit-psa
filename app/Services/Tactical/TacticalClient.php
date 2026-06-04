<?php

namespace App\Services\Tactical;

use App\Support\TacticalConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class TacticalClient
{
    private Client $http;

    public function __construct()
    {
        $baseUrl = rtrim(TacticalConfig::apiUrl(), '/').'/';

        $this->http = new Client([
            'base_uri' => $baseUrl,
            'timeout' => 30,
            'headers' => [
                'X-API-KEY' => TacticalConfig::get('api_key'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function get(string $endpoint): array
    {
        try {
            $response = $this->http->request('GET', $endpoint);
        } catch (GuzzleException $e) {
            Log::error("[TacticalClient] GET {$endpoint} failed: {$e->getMessage()}");
            throw new TacticalClientException("Tactical API error: {$e->getMessage()}", $e->getCode(), $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    public function post(string $endpoint, array $body = []): array
    {
        try {
            $response = $this->http->request('POST', $endpoint, [
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            Log::error("[TacticalClient] POST {$endpoint} failed: {$e->getMessage()}");
            throw new TacticalClientException("Tactical API error: {$e->getMessage()}", $e->getCode(), $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    public function put(string $endpoint, array $body = []): mixed
    {
        try {
            $response = $this->http->request('PUT', $endpoint, [
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            Log::error("[TacticalClient] PUT {$endpoint} failed: {$e->getMessage()}");
            throw new TacticalClientException("Tactical API error: {$e->getMessage()}", $e->getCode(), $e);
        }

        return json_decode((string) $response->getBody(), true);
    }

    public function patch(string $endpoint, array $body = []): array
    {
        try {
            $response = $this->http->request('PATCH', $endpoint, [
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            Log::error("[TacticalClient] PATCH {$endpoint} failed: {$e->getMessage()}");
            throw new TacticalClientException("Tactical API error: {$e->getMessage()}", $e->getCode(), $e);
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

    public function getAgent(string $agentId): array
    {
        return $this->get("agents/{$agentId}/");
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
            Log::error("[TacticalClient] POST clients/ failed: {$e->getMessage()}");
            throw new TacticalClientException("Tactical API error: {$e->getMessage()}", $e->getCode(), $e);
        }

        return [
            'client_name' => $clientName,
            'site_name' => $siteName,
        ];
    }

    public function getScripts(): array
    {
        return $this->get('scripts/');
    }

    public function runScript(string $agentId, int $scriptId, ?array $args = null, int $timeout = 120): array
    {
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

        return $this->post("agents/{$agentId}/runscript/", $body);
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

    public function getSoftware(string $agentId): array
    {
        return $this->get("software/{$agentId}/");
    }

    public function getPatches(string $agentId): array
    {
        return $this->get("winupdate/{$agentId}/");
    }

    public function getAgentChecks(string $agentId): array
    {
        return $this->get("agents/{$agentId}/checks/");
    }

    public function getAgentTasks(string $agentId): array
    {
        return $this->get("agents/{$agentId}/tasks/");
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
}
