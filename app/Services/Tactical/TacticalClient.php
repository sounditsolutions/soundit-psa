<?php

namespace App\Services\Tactical;

use App\Support\TacticalConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class TacticalClient
{
    private Client $http;

    /**
     * @param  \GuzzleHttp\Client|null  $http  When null (the zero-arg
     *                                         AppServiceProvider singleton path), a config-driven client is built
     *                                         from the encrypted Settings (X-API-KEY, base_uri, 30s timeout) with
     *                                         redirect-following disabled (P2 §11/B2 outbound hardening: a followed
     *                                         redirect could exfiltrate the 2FA-bypassing key to a metadata host).
     *                                         When provided (the test/bus seam), it is used AS-IS — the injected
     *                                         client owns its own headers; config is NOT consulted.
     */
    public function __construct(?Client $http = null)
    {
        if ($http !== null) {
            $this->http = $http;

            return;
        }

        $baseUrl = rtrim(TacticalConfig::apiUrl(), '/').'/';

        $this->http = new Client([
            'base_uri' => $baseUrl,
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
            Log::error("[TacticalClient] GET {$endpoint} failed: {$e->getMessage()}");
            throw TacticalClientException::fromGuzzle("Tactical API error: {$e->getMessage()}", $e);
        }

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    public function post(string $endpoint, array $body = []): mixed
    {
        try {
            $response = $this->http->request('POST', $endpoint, [
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            Log::error("[TacticalClient] POST {$endpoint} failed: {$e->getMessage()}");
            throw TacticalClientException::fromGuzzle("Tactical API error: {$e->getMessage()}", $e);
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
            throw TacticalClientException::fromGuzzle("Tactical API error: {$e->getMessage()}", $e);
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
            throw TacticalClientException::fromGuzzle("Tactical API error: {$e->getMessage()}", $e);
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
            throw TacticalClientException::fromGuzzle("Tactical API error: {$e->getMessage()}", $e);
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

    /**
     * Run a curated script on an agent (sync `wait`). Typed `mixed`, not `array`:
     * post() returns whatever the endpoint's JSON decodes to. The runscript
     * endpoint normally returns an object, but — like reboot, which returns the
     * scalar "ok" (live-verified) — a non-object reply must not raise an
     * uncaught TypeError that bypasses the bus's TacticalClientException catch.
     * RunScriptAction::execute normalizes a non-array result defensively.
     */
    public function runScript(string $agentId, int $scriptId, ?array $args = null, int $timeout = 120): mixed
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

    public function getAgentChecks(string $agentId, ?int $timeout = null): array
    {
        return $this->get("agents/{$agentId}/checks/", $timeout);
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
