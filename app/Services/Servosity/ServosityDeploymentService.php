<?php

namespace App\Services\Servosity;

use App\Models\Asset;
use App\Services\Tactical\TacticalClient;
use App\Support\ServosityConfig;
use App\Support\TacticalConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ServosityDeploymentService
{
    private ServosityClient $servosity;

    public function __construct()
    {
        $this->servosity = new ServosityClient([
            'api_token' => ServosityConfig::get('api_token'),
            'base_url' => ServosityConfig::get('base_url'),
        ]);
    }

    /**
     * Enable Servosity backup for an asset (Phase 1).
     *
     * Generates credentials, fetches download URLs, and pushes config to Tactical
     * RMM custom fields. The DR backup account is NOT created here — Servosity One
     * must be installed and registered first. The scheduled provisionPendingBackups()
     * command handles Phase 2 (DR account creation) once the agent is ready.
     *
     * @return array Summary of what was provisioned
     * @throws \RuntimeException If provisioning fails
     */
    public function enableBackup(Asset $asset): array
    {
        $client = $asset->client;
        $companyId = $client->servosity_company_id;

        if (! $companyId) {
            throw new \RuntimeException('Client does not have a Servosity company mapping.');
        }

        // 1. Get company detail for agent_provision_token_id
        $company = $this->servosity->getCompany($companyId);
        $provisionToken = $company['agent_provision_token_id'] ?? null;

        if (! $provisionToken) {
            throw new \RuntimeException('Servosity company has no agent provision token.');
        }

        // 2. Generate backup credential password
        $password = Str::random(24);

        // 3. Build download URLs
        $baseUrl = rtrim(ServosityConfig::get('base_url') ?? 'https://api.servosity.com', '/');
        $servosityOneUrl = "{$baseUrl}/api/v1/companies/{$companyId}/servosity-one/download/windows/latest/?token={$provisionToken}";

        $screenConnectUrl = $this->servosity->getConnectWiseDownloadUrl($companyId);

        // 4. Push to Tactical custom fields (URLs + backup credential for local user creation)
        $this->pushToTactical($asset, $servosityOneUrl, $screenConnectUrl, $password);

        // 5. Update asset — dr_backup_id stays null until Phase 2
        $asset->update([
            'servosity_backup_enabled' => true,
            'servosity_backup_password' => $password,
        ]);

        return [
            'servosity_one_url' => $servosityOneUrl,
            'screen_connect_url' => $screenConnectUrl ? 'set' : 'unavailable',
        ];
    }

    /**
     * Provision DR backup accounts for assets awaiting Phase 2.
     *
     * Finds assets with servosity_backup_enabled=true but no dr_backup_id.
     * For each, checks if the agent has registered (unprovisioned agents list),
     * then runs the full provisioning flow:
     *   1. Link agent to company (POST /agent-login/)
     *   2. Create DR backup account (POST /dr-backups/)
     *   3. Link backup to agent (POST /agent-login/ with dr_backup_id)
     *   4. Install SPX backup software (PUT /agent-sessions/{id}/install-spx/)
     *
     * Assumes no ImageManager — standard workstation/server backup only.
     * BDR devices with ImageManager should be provisioned manually.
     *
     * @return array{provisioned: int, skipped: int, failed: int}
     */
    /**
     * Provision a single asset. Returns 'provisioned', 'skipped', or 'failed'.
     * Used by both the hourly cron and the 30-second retry job.
     */
    public function provisionSingleAsset(Asset $asset): string
    {
        $asset->loadMissing('client');
        $companyId = $asset->client?->servosity_company_id;

        if (! $companyId) {
            return 'failed';
        }

        // Find the agent session for this device
        $agentSessionId = null;
        $needsCompanyLink = false;

        // Check unprovisioned agents
        try {
            $company = $this->servosity->getCompany($companyId);
            $resellerUrl = $company['reseller'] ?? '';
            preg_match('/resellers\/(\d+)/', $resellerUrl, $matches);
            $resellerId = (int) ($matches[1] ?? 0);

            if ($resellerId) {
                $unprovisioned = $this->servosity->getUnprovisionedAgents($resellerId);
                foreach ($unprovisioned as $agent) {
                    $hostname = $agent['agent_session']['system_info']['node'] ?? null;
                    if ($hostname && strcasecmp($hostname, $asset->hostname) === 0) {
                        $agentSessionId = $agent['agent_session']['agent_session_id'];
                        $needsCompanyLink = true;
                        break;
                    }
                }
            }
        } catch (ServosityClientException $e) {
            // Continue — try company agent session fallback
        }

        // Fallback: check company agent session
        if (! $agentSessionId) {
            try {
                $company = $company ?? $this->servosity->getCompany($companyId);
                $sessionId = $company['agent_session_id'] ?? null;
                if ($sessionId) {
                    $session = $this->servosity->get("agent-sessions/{$sessionId}/");
                    $hostname = $session['system_info']['node'] ?? null;
                    if ($hostname && strcasecmp($hostname, $asset->hostname) === 0) {
                        $agentSessionId = $sessionId;
                    }
                }
            } catch (ServosityClientException $e) {
                // No session yet
            }
        }

        if (! $agentSessionId) {
            return 'skipped';
        }

        $productType = $this->resolveProductType($asset);

        try {
            // Step 1: Link agent to company
            if ($needsCompanyLink) {
                try {
                    $this->servosity->agentLogin([
                        'agent_session_id' => $agentSessionId,
                        'company_id' => $companyId,
                    ]);
                } catch (ServosityClientException $e) {
                    if (! str_contains($e->getMessage(), '404')) {
                        throw $e;
                    }
                }
            }

            // Step 2: Check for existing DR backup, create if none
            $existingDr = $this->servosity->get('dr-backups/', ['company' => $companyId]);
            $drBackup = collect($existingDr['results'] ?? [])
                ->firstWhere('device_name', $asset->hostname);

            if (! $drBackup) {
                $drBackup = $this->servosity->createDrBackup([
                    'company' => $companyId,
                    'device_name' => $asset->hostname,
                    'product_type' => $productType,
                    'volumes' => 'C',
                    'notes' => '',
                    'retention' => '1YEAR',
                ]);
            }

            $drBackupId = $drBackup['id'] ?? null;

            // Step 3: Link backup to agent
            if ($drBackupId && empty($drBackup['agent_session_id'])) {
                try {
                    $this->servosity->agentLogin([
                        'agent_session_id' => $agentSessionId,
                        'dr_backup_id' => $drBackupId,
                    ]);
                } catch (ServosityClientException $e) {
                    if (! str_contains($e->getMessage(), '404')) {
                        throw $e;
                    }
                }
            }

            // Step 4: Install SPX
            try {
                $this->servosity->installSpx($agentSessionId);
            } catch (ServosityClientException $e) {
                Log::debug('[Servosity] installSpx response', ['error' => $e->getMessage()]);
            }

            // Step 5: Create credential (requires TOTP)
            if ($asset->servosity_backup_password && ServosityConfig::get('totp_secret')) {
                // Check if credential already exists for this hostname
                $existingCreds = $this->servosity->get('credentials/', ['company' => $companyId]);
                $hasCred = collect($existingCreds['results'] ?? [])
                    ->contains('name', $asset->hostname);

                if (! $hasCred) {
                    try {
                        $this->servosity->createCredential([
                            'company' => $companyId,
                            'name' => $asset->hostname,
                            'username' => ServosityConfig::get('credential_username'),
                            'domain' => '',
                            'notes' => 'Local admin password: ' . $asset->servosity_backup_password,
                            'locked' => false,
                        ]);
                    } catch (ServosityClientException $e) {
                        Log::warning('[Servosity] Credential creation failed', [
                            'hostname' => $asset->hostname,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $asset->update(['servosity_dr_backup_id' => $drBackupId]);

            Log::info('[Servosity] Provisioning complete', [
                'hostname' => $asset->hostname,
                'dr_backup_id' => $drBackupId,
            ]);

            return 'provisioned';

        } catch (ServosityClientException $e) {
            Log::warning('[Servosity] Provisioning failed', [
                'asset_id' => $asset->id,
                'hostname' => $asset->hostname,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /**
     * Provision all pending assets (hourly cron safety net).
     */
    public function provisionPendingBackups(): array
    {
        $pending = Asset::where('servosity_backup_enabled', true)
            ->whereNull('servosity_dr_backup_id')
            ->whereHas('client', fn ($q) => $q->whereNotNull('servosity_company_id'))
            ->with('client')
            ->get();

        $stats = ['provisioned' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($pending as $asset) {
            $result = $this->provisionSingleAsset($asset);
            $stats[$result]++;
        }

        return $stats;
    }

    /**
     * Disable Servosity backup for an asset.
     *
     * Clears Tactical custom fields and updates the asset flag.
     * Does NOT delete the Servosity DR backup account (data preservation).
     */
    public function disableBackup(Asset $asset): void
    {
        $this->pushToTactical($asset, '', '', '');

        $asset->update([
            'servosity_backup_enabled' => false,
            'servosity_dr_backup_id' => null,
        ]);

        Log::info('[Servosity] Disabled backup', [
            'asset_id' => $asset->id,
            'hostname' => $asset->hostname,
        ]);
    }

    /**
     * Determine DR product type from asset_type.
     *
     * Server types → DR_SERVER, everything else → DR_DESKTOP.
     */
    private function resolveProductType(Asset $asset): string
    {
        $type = strtolower($asset->asset_type ?? '');

        if (str_contains($type, 'server') || $type === 'windows_server') {
            return 'DR_SERVER';
        }

        return 'DR_DESKTOP';
    }

    /**
     * Push Servosity deployment config to Tactical RMM custom fields.
     */
    private function pushToTactical(
        Asset $asset,
        string $servosityOneUrl,
        ?string $screenConnectUrl,
        string $credPass = '',
    ): void {
        $tacticalAsset = $asset->tacticalAsset;

        if (! $tacticalAsset) {
            Log::warning('[Servosity] No Tactical agent linked, skipping field push', [
                'asset_id' => $asset->id,
                'hostname' => $asset->hostname,
            ]);
            return;
        }

        if (! TacticalConfig::isConfigured()) {
            Log::warning('[Servosity] Tactical not configured, skipping field push');
            return;
        }

        $tactical = new TacticalClient();
        $agentId = $tacticalAsset->agent_id;

        $fields = [
            ServosityConfig::TACTICAL_SERVOSITY_ONE_URL_FIELD_ID => $servosityOneUrl,
            ServosityConfig::TACTICAL_SERVOSITY_SC_URL_FIELD_ID => $screenConnectUrl ?? '',
            ServosityConfig::TACTICAL_SERVOSITY_CRED_USER_FIELD_ID => $credPass ? ServosityConfig::get('credential_username') : '',
            ServosityConfig::TACTICAL_SERVOSITY_CRED_PASS_FIELD_ID => $credPass,
        ];

        foreach ($fields as $fieldId => $value) {
            $tactical->setAgentCustomField($agentId, $fieldId, $value);
        }

        Log::info('[Servosity] Pushed config to Tactical agent', [
            'asset_id' => $asset->id,
            'agent_id' => $agentId,
        ]);
    }
}
