<?php

namespace App\Services\Mcp;

use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Rules\SafeTacticalWebUrl;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Services\Tactical\TacticalDeviceSyncService;
use App\Services\Tactical\TacticalProvisioningService;
use App\Services\Tactical\TacticalScriptSyncService;
use App\Services\Technician\TechnicianApprovalResult;
use App\Support\CometConfig;
use App\Support\ServosityConfig;
use App\Support\TacticalConfig;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StaffTacticalAdminToolExecutor
{
    private const DIRECT_DEDUP_HOURS = 24;

    private const URL_ACTION_NAME = 'PSA Ticket Webhook';

    private const ALERT_TEMPLATE_NAME = 'PSA Auto-Ticket';

    /** @var array<int, string> */
    private const CLIENT_SCOPED_TOOLS = [
        'tactical_create_client_site',
        'tactical_provision_client_site',
        'tactical_set_agent_custom_field',
        'tactical_get_or_create_installer',
        'tactical_generate_installer',
        'tactical_sync_devices_now',
        'tactical_create_patch_policy',
        'tactical_update_patch_policy',
        'tactical_delete_patch_policy',
        'tactical_reset_patch_policies',
        'tactical_stage_reset_patch_policies',
    ];

    /** @var array<int, string> */
    private const UPSTREAM_IDENTIFIER_KEYS = [
        'agent_id',
        'upstream_agent_id',
        'tactical_agent_id',
        'tactical_client_id',
        'tactical_site_id',
        'upstream_client_id',
        'upstream_site_id',
        'client_name',
        'site_name',
        'tactical_client_name',
        'tactical_site_name',
        'field_id',
        'url_action_id',
        'alert_template_id',
        'template_id',
        'upstream_url_action_id',
        'upstream_alert_template_id',
        'policy',
        'patch_policy_id',
        'patch_policy_pk',
        'winupdatepolicy',
        'upstream_patch_policy_id',
        'tactical_patch_policy_id',
        'client',
        'site',
    ];

    /** @var array<string, int> */
    private const COOLDOWNS = [
        'tactical_create_client_site' => 300,
        'tactical_provision_client_site' => 300,
        'tactical_set_agent_custom_field' => 300,
        'tactical_upsert_url_action' => 300,
        'tactical_upsert_alert_template' => 300,
        'tactical_set_default_alert_template' => 300,
        'tactical_get_or_create_installer' => 60,
        'tactical_generate_installer' => 60,
        'tactical_sync_devices_now' => 300,
        'tactical_sync_scripts_now' => 300,
        'tactical_provision_alert_ticketing' => 300,
        'tactical_create_patch_policy' => 300,
        'tactical_update_patch_policy' => 300,
        'tactical_delete_patch_policy' => 600,
        'tactical_reset_patch_policies' => 900,
        'tactical_stage_reset_patch_policies' => 900,
    ];

    /** @var array<string, int> */
    private const CUSTOM_FIELD_ALLOWLIST = [
        'comet_install_token' => CometConfig::TACTICAL_TOKEN_FIELD_ID,
        'servosity_one_url' => ServosityConfig::TACTICAL_SERVOSITY_ONE_URL_FIELD_ID,
        'servosity_screenconnect_url' => ServosityConfig::TACTICAL_SERVOSITY_SC_URL_FIELD_ID,
        'servosity_credential_user' => ServosityConfig::TACTICAL_SERVOSITY_CRED_USER_FIELD_ID,
        'servosity_credential_password' => ServosityConfig::TACTICAL_SERVOSITY_CRED_PASS_FIELD_ID,
    ];

    /** @var array<string, string> */
    private const STAGED_TO_DIRECT = [
        'tactical_stage_reset_patch_policies' => 'tactical_reset_patch_policies',
    ];

    /** @var array<int, string> */
    private const PATCH_APPROVAL_VALUES = ['manual', 'approve', 'ignore', 'inherit'];

    /** @var array<int, string> */
    private const PATCH_FREQUENCIES = ['daily', 'monthly', 'inherit'];

    /** @var array<int, string> */
    private const PATCH_REBOOT_VALUES = ['never', 'required', 'always', 'inherit'];

    public function __construct(
        private readonly TacticalClient $client,
        private readonly TacticalDeviceSyncService $deviceSync,
        private readonly TacticalScriptSyncService $scriptSync,
        private readonly TacticalProvisioningService $provisioning,
        private readonly ActionRedactor $redactor,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            self::createClientSiteTool(),
            self::provisionClientSiteTool(),
            self::setAgentCustomFieldTool(),
            self::upsertUrlActionTool(),
            self::upsertAlertTemplateTool(),
            self::setDefaultAlertTemplateTool(),
            self::getOrCreateInstallerTool(),
            self::generateInstallerTool(),
            self::syncDevicesTool(),
            self::syncScriptsTool(),
            self::provisionAlertTicketingTool(),
            self::createPatchPolicyTool(),
            self::updatePatchPolicyTool(),
            self::deletePatchPolicyTool(),
            self::resetPatchPoliciesTool(),
            self::stageResetPatchPoliciesTool(),
        ];
    }

    /** @return array<int, string> */
    public static function toolNames(): array
    {
        return array_column(self::definitions(), 'name');
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::toolNames(), true);
    }

    public static function requiresClient(string $toolName): bool
    {
        return in_array($toolName, self::CLIENT_SCOPED_TOOLS, true);
    }

    public static function isStagedActionType(string $actionType): bool
    {
        return array_key_exists($actionType, self::STAGED_TO_DIRECT);
    }

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, ?int $clientId, string $actorLabel): array
    {
        if (! TacticalConfig::isConfigured()) {
            return ['error' => 'Tactical RMM is not configured'];
        }

        if (isset(self::STAGED_TO_DIRECT[$name])) {
            return $this->stagePatchPolicyReset($arguments, (int) $clientId, $actorLabel);
        }

        return match ($name) {
            'tactical_create_client_site', 'tactical_provision_client_site' => $this->createClientSite($name, $arguments, (int) $clientId, $actorLabel),
            'tactical_set_agent_custom_field' => $this->setAgentCustomField($arguments, (int) $clientId, $actorLabel),
            'tactical_upsert_url_action' => $this->upsertUrlAction($arguments, $actorLabel),
            'tactical_upsert_alert_template' => $this->upsertAlertTemplate($arguments, $actorLabel),
            'tactical_set_default_alert_template' => $this->setDefaultAlertTemplate($arguments, $actorLabel),
            'tactical_get_or_create_installer', 'tactical_generate_installer' => $this->getInstaller($name, $arguments, (int) $clientId, $actorLabel),
            'tactical_sync_devices_now' => $this->syncDevices($arguments, (int) $clientId, $actorLabel),
            'tactical_sync_scripts_now' => $this->syncScripts($arguments, $actorLabel),
            'tactical_provision_alert_ticketing' => $this->provisionAlertTicketing($arguments, $actorLabel),
            'tactical_create_patch_policy' => $this->createPatchPolicy($arguments, (int) $clientId, $actorLabel),
            'tactical_update_patch_policy' => $this->updatePatchPolicy($arguments, (int) $clientId, $actorLabel),
            'tactical_delete_patch_policy' => $this->deletePatchPolicy($arguments, (int) $clientId, $actorLabel),
            'tactical_reset_patch_policies' => $this->resetPatchPolicies($arguments, (int) $clientId, $actorLabel),
            default => ['error' => "Unknown Tactical admin tool: {$name}"],
        };
    }

    /** @return array<string, mixed> */
    private function createClientSite(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $guard = $this->baseGuard($tool, $arguments, $clientId, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $client = Client::find($clientId);
        if (! $client) {
            $this->auditAttempt($tool, 'rejected', $clientId, $this->contentHash($tool, $clientId, 'client', $arguments), 'Client not found.', $actorLabel);

            return ['error' => 'Client not found'];
        }

        if (is_string($client->tactical_site_id) && trim($client->tactical_site_id) !== '') {
            $contentHash = $this->contentHash($tool, $clientId, 'client', ['already_mapped' => $client->tactical_site_id]);
            $this->auditAttempt($tool, 'executed', $clientId, $contentHash, "Client already mapped to Tactical site {$client->tactical_site_id}; no upstream call made.", $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'tactical_site_id' => $client->tactical_site_id,
                'message' => 'Client already has a Tactical site mapping.',
            ];
        }

        $workstationPolicyId = $this->positiveInteger($arguments['workstation_policy_id'] ?? null);
        $serverPolicyId = $this->positiveInteger($arguments['server_policy_id'] ?? null);
        if (array_key_exists('workstation_policy_id', $arguments) && $workstationPolicyId === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, $this->contentHash($tool, $clientId, 'client', $arguments), 'workstation_policy_id must be a positive integer if supplied.', $actorLabel);

            return ['error' => 'workstation_policy_id must be a positive integer if supplied'];
        }
        if (array_key_exists('server_policy_id', $arguments) && $serverPolicyId === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, $this->contentHash($tool, $clientId, 'client', $arguments), 'server_policy_id must be a positive integer if supplied.', $actorLabel);

            return ['error' => 'server_policy_id must be a positive integer if supplied'];
        }

        $params = [
            'client_name' => $client->name,
            'site_name' => 'Main',
            'workstation_policy_id' => $workstationPolicyId,
            'server_policy_id' => $serverPolicyId,
        ];
        $contentHash = $this->contentHash($tool, $clientId, 'client', $params);
        if ($this->alreadyExecuted($tool, $clientId, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, "Duplicate {$tool} suppressed before upstream call.", $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Already executed identical Tactical admin action recently; no upstream call was made.',
            ];
        }
        if ($this->cooldownActive($tool, $clientId, self::COOLDOWNS[$tool])) {
            $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, "{$tool} cooldown active; upstream call refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this client; no upstream call was made."];
        }

        try {
            $policyError = $this->policyIdsError($workstationPolicyId, $serverPolicyId);
            if ($policyError !== null) {
                $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, $policyError, $actorLabel);

                return ['error' => $policyError];
            }

            $existing = collect($this->client->getClients())
                ->first(fn (array $candidate): bool => strcasecmp((string) ($candidate['name'] ?? ''), (string) $client->name) === 0);

            if ($existing !== null) {
                $siteName = trim((string) ($existing['sites'][0]['name'] ?? 'Main')) ?: 'Main';
                $siteKey = $this->siteKey((string) $client->name, $siteName);
                if ($error = $this->siteKeyCollision($siteKey, $client->id)) {
                    $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, $error, $actorLabel);

                    return ['error' => $error];
                }

                $client->update(['tactical_site_id' => $siteKey]);
                $this->auditAttempt($tool, 'executed', $clientId, $contentHash, "Linked PSA client #{$client->id} to existing Tactical site {$siteKey}.", $actorLabel);

                return [
                    'success' => true,
                    'idempotent' => true,
                    'tactical_site_id' => $siteKey,
                    'message' => 'Linked to existing Tactical client/site.',
                ];
            }

            $created = $this->client->createClient((string) $client->name, 'Main', $workstationPolicyId, $serverPolicyId);
            $siteKey = $this->siteKey((string) ($created['client_name'] ?? $client->name), (string) ($created['site_name'] ?? 'Main'));
            if ($error = $this->siteKeyCollision($siteKey, $client->id)) {
                $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, $error, $actorLabel);

                return ['error' => $error];
            }

            $client->update(['tactical_site_id' => $siteKey]);
            $this->auditAttempt($tool, 'executed', $clientId, $contentHash, "Created Tactical client/site {$siteKey} and linked PSA client #{$client->id}.", $actorLabel);

            return [
                'success' => true,
                'tactical_site_id' => $siteKey,
                'message' => 'Tactical client/site created and linked.',
            ];
        } catch (TacticalClientException $e) {
            $message = $this->tacticalFailureMessage($e, 'Tactical client-site provisioning');
            $this->auditAttempt($tool, 'error', $clientId, $contentHash, $message, $actorLabel);

            return ['error' => $message];
        }
    }

    /** @return array<string, mixed> */
    private function setAgentCustomField(array $arguments, int $clientId, string $actorLabel): array
    {
        $tool = 'tactical_set_agent_custom_field';
        $guard = $this->baseGuard($tool, $arguments, $clientId, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $fieldKey = mb_strtolower(trim((string) ($arguments['field_key'] ?? '')));
        $fieldId = self::CUSTOM_FIELD_ALLOWLIST[$fieldKey] ?? null;
        $value = array_key_exists('value', $arguments) && is_scalar($arguments['value'])
            ? (string) $arguments['value']
            : null;

        $params = ['field_key' => $fieldKey, 'asset_id' => $arguments['asset_id'] ?? null, 'hostname' => $arguments['hostname'] ?? null];
        $contentHash = $this->contentHash($tool, $clientId, 'asset-field', $params);

        if ($fieldId === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, 'field_key is not an allowlisted PSA-owned Tactical custom field.', $actorLabel);

            return ['error' => 'field_key is not an allowlisted PSA-owned Tactical custom field'];
        }
        if ($value === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, 'value is required.', $actorLabel);

            return ['error' => 'value is required'];
        }

        $asset = $this->resolveAsset($arguments, $clientId);
        if (is_array($asset)) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, $asset['error'], $actorLabel);

            return ['error' => $asset['error']];
        }

        if ($error = $this->linkedAgentError($asset)) {
            $this->auditAttempt($tool, 'rejected', $clientId, $this->contentHash($tool, $clientId, (string) $asset->id, $params), $error, $actorLabel);

            return ['error' => $error];
        }

        $contentHash = $this->contentHash($tool, $clientId, (string) $asset->id, $params);
        if ($this->alreadyExecuted($tool, $clientId, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Duplicate custom-field write suppressed before upstream call.', $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Already executed identical Tactical custom-field write recently; no upstream call was made.',
            ];
        }
        if ($this->cooldownActive($tool, $clientId, self::COOLDOWNS[$tool])) {
            $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Custom-field write cooldown active; upstream call refused.', $actorLabel);

            return ['error' => 'tactical_set_agent_custom_field cooldown active for this client; no upstream call was made.'];
        }

        try {
            $this->client->setAgentCustomField((string) $asset->tacticalAsset->agent_id, $fieldId, $value);
        } catch (TacticalClientException $e) {
            $message = $this->tacticalFailureMessage($e, 'Tactical custom-field write');
            $this->auditAttempt($tool, 'error', $clientId, $contentHash, $message, $actorLabel);

            return ['error' => $message];
        }

        $this->auditAttempt($tool, 'executed', $clientId, $contentHash, "Set PSA-owned Tactical custom field {$fieldKey} for asset #{$asset->id}.", $actorLabel);

        return [
            'success' => true,
            'asset_id' => $asset->id,
            'field_key' => $fieldKey,
            'message' => 'Tactical custom field updated.',
        ];
    }

    /** @return array<string, mixed> */
    private function upsertUrlAction(array $arguments, string $actorLabel): array
    {
        $tool = 'tactical_upsert_url_action';
        $guard = $this->baseGuard($tool, $arguments, null, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $contentHash = $this->contentHash($tool, null, 'url-action', ['name' => self::URL_ACTION_NAME]);
        if ($this->alreadyExecuted($tool, null, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', null, $contentHash, 'Duplicate PSA URL action upsert suppressed before upstream call.', $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Already executed identical Tactical URL action upsert recently; no upstream call was made.',
            ];
        }
        if ($this->cooldownActive($tool, null, self::COOLDOWNS[$tool])) {
            $this->auditAttempt($tool, 'blocked', null, $contentHash, 'URL action upsert cooldown active; upstream call refused.', $actorLabel);

            return ['error' => 'tactical_upsert_url_action cooldown active; no upstream call was made.'];
        }

        $body = $this->buildUrlActionBody($this->webhookKey());

        try {
            $storedId = TacticalConfig::urlActionId();
            if ($storedId !== null) {
                $existing = $this->findById($this->client->getUrlActions(), $storedId);
                if ($existing !== null && ($existing['name'] ?? null) !== self::URL_ACTION_NAME) {
                    $message = 'Stored Tactical URL action id is not PSA-owned; refused to clobber it.';
                    $this->auditAttempt($tool, 'blocked', null, $contentHash, $message, $actorLabel);

                    return ['error' => $message];
                }

                if ($existing !== null) {
                    $this->client->updateUrlAction($storedId, $body);
                    $newId = $storedId;
                } else {
                    $this->client->createUrlAction($body);
                    $newId = $this->findHighestIdByName($this->client->getUrlActions(), self::URL_ACTION_NAME, 'Tactical URLAction');
                }
            } else {
                $this->client->createUrlAction($body);
                $newId = $this->findHighestIdByName($this->client->getUrlActions(), self::URL_ACTION_NAME, 'Tactical URLAction');
            }
        } catch (TacticalClientException $e) {
            $message = $this->tacticalFailureMessage($e, 'Tactical URL action upsert');
            $this->auditAttempt($tool, 'error', null, $contentHash, $message, $actorLabel);

            return ['error' => $message];
        }

        Setting::setValue('tactical_url_action_id', (string) $newId);
        $this->auditAttempt($tool, 'executed', null, $contentHash, "Upserted PSA-owned Tactical URL action id {$newId}.", $actorLabel);

        return [
            'success' => true,
            'url_action_id' => $newId,
            'message' => 'PSA Tactical URL action upserted.',
        ];
    }

    /** @return array<string, mixed> */
    private function upsertAlertTemplate(array $arguments, string $actorLabel): array
    {
        $tool = 'tactical_upsert_alert_template';
        $guard = $this->baseGuard($tool, $arguments, null, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $urlActionId = TacticalConfig::urlActionId();
        $contentHash = $this->contentHash($tool, null, 'alert-template', ['name' => self::ALERT_TEMPLATE_NAME, 'url_action_id' => $urlActionId]);
        if ($urlActionId === null) {
            $this->auditAttempt($tool, 'rejected', null, $contentHash, 'Tactical URL action is not provisioned yet.', $actorLabel);

            return ['error' => 'Tactical URL action is not provisioned yet; run tactical_upsert_url_action first'];
        }
        if ($this->alreadyExecuted($tool, null, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', null, $contentHash, 'Duplicate PSA alert template upsert suppressed before upstream call.', $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Already executed identical Tactical alert template upsert recently; no upstream call was made.',
            ];
        }
        if ($this->cooldownActive($tool, null, self::COOLDOWNS[$tool])) {
            $this->auditAttempt($tool, 'blocked', null, $contentHash, 'Alert template upsert cooldown active; upstream call refused.', $actorLabel);

            return ['error' => 'tactical_upsert_alert_template cooldown active; no upstream call was made.'];
        }

        $body = $this->buildAlertTemplateBody($urlActionId);

        try {
            $storedId = TacticalConfig::alertTemplateId();
            if ($storedId !== null) {
                $existing = $this->findById($this->client->getAlertTemplates(), $storedId);
                if ($existing !== null && ($existing['name'] ?? null) !== self::ALERT_TEMPLATE_NAME) {
                    $message = 'Stored Tactical alert template id is not PSA-owned; refused to clobber it.';
                    $this->auditAttempt($tool, 'blocked', null, $contentHash, $message, $actorLabel);

                    return ['error' => $message];
                }

                if ($existing !== null) {
                    $this->client->updateAlertTemplate($storedId, $body);
                    $newId = $storedId;
                } else {
                    $this->client->createAlertTemplate($body);
                    $newId = $this->findHighestIdByName($this->client->getAlertTemplates(), self::ALERT_TEMPLATE_NAME, 'Tactical AlertTemplate');
                }
            } else {
                $this->client->createAlertTemplate($body);
                $newId = $this->findHighestIdByName($this->client->getAlertTemplates(), self::ALERT_TEMPLATE_NAME, 'Tactical AlertTemplate');
            }
        } catch (TacticalClientException $e) {
            $message = $this->tacticalFailureMessage($e, 'Tactical alert template upsert');
            $this->auditAttempt($tool, 'error', null, $contentHash, $message, $actorLabel);

            return ['error' => $message];
        }

        Setting::setValue('tactical_alert_template_id', (string) $newId);
        $this->auditAttempt($tool, 'executed', null, $contentHash, "Upserted PSA-owned Tactical alert template id {$newId}.", $actorLabel);

        return [
            'success' => true,
            'alert_template_id' => $newId,
            'message' => 'PSA Tactical alert template upserted.',
        ];
    }

    /** @return array<string, mixed> */
    private function setDefaultAlertTemplate(array $arguments, string $actorLabel): array
    {
        $tool = 'tactical_set_default_alert_template';
        $guard = $this->baseGuard($tool, $arguments, null, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $targetId = TacticalConfig::alertTemplateId();
        $contentHash = $this->contentHash($tool, null, 'core-default-alert-template', ['alert_template_id' => $targetId]);
        if ($targetId === null) {
            $this->auditAttempt($tool, 'rejected', null, $contentHash, 'Tactical alert template is not provisioned yet.', $actorLabel);

            return ['error' => 'Tactical alert template is not provisioned yet; run tactical_upsert_alert_template first'];
        }
        if ($this->alreadyExecuted($tool, null, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', null, $contentHash, 'Duplicate default alert-template set suppressed before upstream call.', $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Already set this Tactical default alert template recently; no upstream call was made.',
            ];
        }
        if ($this->cooldownActive($tool, null, self::COOLDOWNS[$tool])) {
            $this->auditAttempt($tool, 'blocked', null, $contentHash, 'Default alert-template cooldown active; upstream call refused.', $actorLabel);

            return ['error' => 'tactical_set_default_alert_template cooldown active; no upstream call was made.'];
        }

        try {
            $coreSettings = $this->client->getCoreSettings();
            $currentDefault = $coreSettings['alert_template'] ?? null;
            if ($currentDefault !== null && $currentDefault !== '' && (int) $currentDefault > 0 && (int) $currentDefault !== $targetId) {
                Setting::setValue('tactical_prior_default_alert_template_id', (string) $currentDefault);
                $message = "refused to clobber existing Tactical default alert template id {$currentDefault}; PSA template id {$targetId} was left unassigned.";
                $this->auditAttempt($tool, 'blocked', null, $contentHash, $message, $actorLabel);

                return ['error' => $message];
            }

            $this->client->setDefaultAlertTemplate($targetId);
        } catch (TacticalClientException $e) {
            $message = $this->tacticalFailureMessage($e, 'Tactical default alert-template update');
            $this->auditAttempt($tool, 'error', null, $contentHash, $message, $actorLabel);

            return ['error' => $message];
        }

        $this->auditAttempt($tool, 'executed', null, $contentHash, "Set Tactical global default alert template to PSA template id {$targetId}.", $actorLabel);

        return [
            'success' => true,
            'alert_template_id' => $targetId,
            'message' => 'Tactical default alert template set to the PSA-owned template.',
        ];
    }

    /** @return array<string, mixed> */
    private function getInstaller(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $guard = $this->baseGuard($tool, $arguments, $clientId, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $client = Client::find($clientId);
        $platform = $this->installerPlatform($arguments['platform'] ?? null);
        $contentHash = $this->contentHash($tool, $clientId, 'installer', ['platform' => $platform]);

        if (! $client) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, 'Client not found.', $actorLabel);

            return ['error' => 'Client not found'];
        }
        if (! is_string($client->tactical_site_id) || trim($client->tactical_site_id) === '') {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, 'Client has no Tactical site mapping.', $actorLabel);

            return ['error' => 'Client has no Tactical site mapping'];
        }
        if ($platform === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, 'platform must be one of: windows, mac, linux.', $actorLabel);

            return ['error' => 'platform must be one of: windows, mac, linux'];
        }
        if ($this->cooldownActive($tool, $clientId, self::COOLDOWNS[$tool])) {
            $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Installer generation cooldown active; upstream call refused.', $actorLabel);

            return ['error' => "{$tool} cooldown active for this client; no upstream call was made."];
        }

        $installer = $this->client->getInstallerInfo((string) $client->tactical_site_id, $platform);
        if ($installer === null) {
            $this->auditAttempt($tool, 'error', $clientId, $contentHash, 'Tactical did not return installer information.', $actorLabel);

            return ['error' => 'Tactical did not return installer information'];
        }

        if (! Validator::make(['u' => $installer->downloadUrl], ['u' => [new SafeTacticalWebUrl]])->passes()) {
            $this->auditAttempt($tool, 'error', $clientId, $contentHash, 'Tactical installer URL failed safety validation.', $actorLabel);

            return ['error' => 'Tactical installer URL failed safety validation'];
        }

        $this->auditAttempt($tool, 'executed', $clientId, $contentHash, "Generated {$platform} installer for PSA client #{$client->id}; signed URL returned but not retained.", $actorLabel);

        return [
            'success' => true,
            'platform' => $platform,
            'download_url' => $installer->downloadUrl,
            'instructions' => $installer->instructions,
            'message' => 'Signed installer URL generated. It is returned once and not retained in audits.',
        ];
    }

    /** @return array<string, mixed> */
    private function syncDevices(array $arguments, int $clientId, string $actorLabel): array
    {
        $tool = 'tactical_sync_devices_now';
        $guard = $this->baseGuard($tool, $arguments, $clientId, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $client = Client::find($clientId);
        $contentHash = $this->contentHash($tool, $clientId, 'device-sync', ['client_id' => $clientId]);
        if (! $client) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, 'Client not found.', $actorLabel);

            return ['error' => 'Client not found'];
        }
        if (! is_string($client->tactical_site_id) || trim($client->tactical_site_id) === '') {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, 'Client has no Tactical site mapping.', $actorLabel);

            return ['error' => 'Client has no Tactical site mapping'];
        }
        if ($this->alreadyExecuted($tool, $clientId, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Duplicate Tactical device sync suppressed before upstream call.', $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Already synced Tactical devices for this client recently; no upstream call was made.',
            ];
        }
        if ($this->cooldownActive($tool, $clientId, self::COOLDOWNS[$tool])) {
            $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Device sync cooldown active; upstream call refused.', $actorLabel);

            return ['error' => 'tactical_sync_devices_now cooldown active for this client; no upstream call was made.'];
        }

        $result = $this->deviceSync->syncDevices($clientId);
        $status = $result->errors > 0 ? 'error' : 'executed';
        $this->auditAttempt($tool, $status, $clientId, $contentHash, 'Tactical device sync complete: '.$result->summary().'.', $actorLabel);

        if ($result->errors > 0) {
            return [
                'error' => $result->summary(),
                'errors' => $result->errorMessages,
            ];
        }

        return [
            'success' => true,
            'summary' => $result->summary(),
            'created' => $result->created,
            'updated' => $result->updated,
            'deactivated' => $result->deactivated,
        ];
    }

    /** @return array<string, mixed> */
    private function syncScripts(array $arguments, string $actorLabel): array
    {
        $tool = 'tactical_sync_scripts_now';
        $guard = $this->baseGuard($tool, $arguments, null, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $contentHash = $this->contentHash($tool, null, 'script-sync', ['catalog' => 'visible']);
        if ($this->alreadyExecuted($tool, null, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', null, $contentHash, 'Duplicate Tactical script sync suppressed before upstream call.', $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Already synced Tactical scripts recently; no upstream call was made.',
            ];
        }
        if ($this->cooldownActive($tool, null, self::COOLDOWNS[$tool])) {
            $this->auditAttempt($tool, 'blocked', null, $contentHash, 'Script sync cooldown active; upstream call refused.', $actorLabel);

            return ['error' => 'tactical_sync_scripts_now cooldown active; no upstream call was made.'];
        }

        $stats = $this->scriptSync->syncScripts();
        $this->auditAttempt($tool, 'executed', null, $contentHash, 'Tactical script catalog sync complete.', $actorLabel);

        return [
            'success' => true,
            'stats' => $stats,
            'message' => 'Tactical script catalog synced.',
        ];
    }

    /** @return array<string, mixed> */
    private function provisionAlertTicketing(array $arguments, string $actorLabel): array
    {
        $tool = 'tactical_provision_alert_ticketing';
        $guard = $this->baseGuard($tool, $arguments, null, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $contentHash = $this->contentHash($tool, null, 'alert-ticketing', ['provision' => true]);
        if ($this->alreadyExecuted($tool, null, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', null, $contentHash, 'Duplicate Tactical alert-ticketing provisioning suppressed before upstream call.', $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Already provisioned Tactical alert ticketing recently; no upstream call was made.',
            ];
        }
        if ($this->cooldownActive($tool, null, self::COOLDOWNS[$tool])) {
            $this->auditAttempt($tool, 'blocked', null, $contentHash, 'Alert-ticketing provisioning cooldown active; upstream call refused.', $actorLabel);

            return ['error' => 'tactical_provision_alert_ticketing cooldown active; no upstream call was made.'];
        }

        try {
            $result = $this->provisioning->provision(TechnicianConfig::requiredAiActorUserId());
        } catch (\Throwable $e) {
            $message = str_contains($e->getMessage(), 'AI actor')
                ? $e->getMessage()
                : 'Tactical alert-ticketing provisioning failed.';
            $this->auditAttempt($tool, 'error', null, $contentHash, $message, $actorLabel);

            return ['error' => $message];
        }

        $success = (bool) ($result['success'] ?? false);
        $summary = (string) ($result['message'] ?? ($success ? 'Tactical alert ticketing provisioned.' : 'Tactical alert ticketing provisioning failed.'));
        $this->auditAttempt($tool, $success ? 'executed' : 'error', null, $contentHash, $summary, $actorLabel);

        return $success ? $result : ['error' => $summary, 'success' => false];
    }

    /** @return array<string, mixed> */
    private function createPatchPolicy(array $arguments, int $clientId, string $actorLabel): array
    {
        $tool = 'tactical_create_patch_policy';
        $guard = $this->baseGuard($tool, $arguments, $clientId, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $policyId = $this->positiveInteger($arguments['policy_id'] ?? null);
        $body = $this->patchPolicyBody($arguments, includePolicy: true);
        $contentHash = $this->contentHash($tool, $clientId, 'patch-policy', ['policy_id' => $policyId, 'body' => $body['body'] ?? []]);

        if ($policyId === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, 'policy_id is required.', $actorLabel);

            return ['error' => 'policy_id is required'];
        }
        if (isset($body['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, $body['error'], $actorLabel);

            return ['error' => $body['error']];
        }

        try {
            $policy = $this->policyById($policyId);
            if ($policy === null) {
                $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, "Policy id {$policyId} was not found in Tactical getPolicies; no patch policy was created.", $actorLabel);

                return ['error' => "Policy id {$policyId} was not found in Tactical getPolicies; no patch policy was created."];
            }
            if ($this->patchPolicyIdFromPolicy($policy) !== null) {
                $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, 'Policy already has a patch policy; use tactical_update_patch_policy.', $actorLabel);

                return ['error' => 'Policy already has a patch policy; use tactical_update_patch_policy'];
            }

            $payload = ['policy' => $policyId] + ($body['body'] ?? []);
            if ($this->alreadyExecuted($tool, $clientId, $contentHash)) {
                $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Duplicate patch-policy create suppressed before upstream call.', $actorLabel);

                return ['success' => true, 'idempotent' => true, 'message' => 'Already created an identical patch policy recently; no upstream call was made.'];
            }
            if ($this->cooldownActive($tool, $clientId, self::COOLDOWNS[$tool])) {
                $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Patch-policy create cooldown active; upstream call refused.', $actorLabel);

                return ['error' => 'tactical_create_patch_policy cooldown active for this client; no upstream call was made.'];
            }

            $this->client->createPatchPolicy($payload);
        } catch (TacticalClientException $e) {
            $message = $this->tacticalFailureMessage($e, 'Tactical patch-policy create');
            $this->auditAttempt($tool, 'error', $clientId, $contentHash, $message, $actorLabel);

            return ['error' => $message];
        }

        $this->auditAttempt($tool, 'executed', $clientId, $contentHash, "Created Tactical patch policy for policy id {$policyId}.", $actorLabel);

        return ['success' => true, 'policy_id' => $policyId, 'message' => 'Tactical patch policy created.'];
    }

    /** @return array<string, mixed> */
    private function updatePatchPolicy(array $arguments, int $clientId, string $actorLabel): array
    {
        $tool = 'tactical_update_patch_policy';
        $guard = $this->baseGuard($tool, $arguments, $clientId, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $resolved = $this->resolvedPatchPolicy($arguments, $clientId, $tool, $actorLabel);
        if (isset($resolved['error'])) {
            return ['error' => $resolved['error']];
        }

        $body = $this->patchPolicyBody($arguments, includePolicy: false);
        $contentHash = $this->contentHash($tool, $clientId, 'patch-policy-'.$resolved['patch_policy_id'], $body['body'] ?? []);
        if (isset($body['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, $body['error'], $actorLabel);

            return ['error' => $body['error']];
        }
        if (($body['body'] ?? []) === []) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, 'At least one patch policy field is required.', $actorLabel);

            return ['error' => 'At least one patch policy field is required'];
        }
        if ($this->alreadyExecuted($tool, $clientId, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Duplicate patch-policy update suppressed before upstream call.', $actorLabel);

            return ['success' => true, 'idempotent' => true, 'message' => 'Already updated this patch policy recently; no upstream call was made.'];
        }
        if ($this->cooldownActive($tool, $clientId, self::COOLDOWNS[$tool])) {
            $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Patch-policy update cooldown active; upstream call refused.', $actorLabel);

            return ['error' => 'tactical_update_patch_policy cooldown active for this client; no upstream call was made.'];
        }

        try {
            $this->client->updatePatchPolicy((int) $resolved['patch_policy_id'], $body['body']);
        } catch (TacticalClientException $e) {
            $message = $this->tacticalFailureMessage($e, 'Tactical patch-policy update');
            $this->auditAttempt($tool, 'error', $clientId, $contentHash, $message, $actorLabel);

            return ['error' => $message];
        }

        $this->auditAttempt($tool, 'executed', $clientId, $contentHash, "Updated Tactical patch policy id {$resolved['patch_policy_id']} for policy {$resolved['policy_name']}.", $actorLabel);

        return ['success' => true, 'patch_policy_id' => $resolved['patch_policy_id'], 'message' => 'Tactical patch policy updated.'];
    }

    /** @return array<string, mixed> */
    private function deletePatchPolicy(array $arguments, int $clientId, string $actorLabel): array
    {
        $tool = 'tactical_delete_patch_policy';
        $guard = $this->baseGuard($tool, $arguments, $clientId, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $resolved = $this->resolvedPatchPolicy($arguments, $clientId, $tool, $actorLabel);
        if (isset($resolved['error'])) {
            return ['error' => $resolved['error']];
        }

        $typed = trim((string) ($arguments['confirm_policy_name'] ?? ''));
        if (strcasecmp($typed, (string) $resolved['policy_name']) !== 0) {
            $contentHash = $this->contentHash($tool, $clientId, 'patch-policy-'.$resolved['patch_policy_id'], ['policy_id' => $resolved['policy_id']]);
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, 'The typed policy name does not match this Tactical automation policy.', $actorLabel);

            return ['error' => 'The typed policy name does not match this Tactical automation policy.'];
        }

        $contentHash = $this->contentHash($tool, $clientId, 'patch-policy-'.$resolved['patch_policy_id'], ['policy_id' => $resolved['policy_id']]);
        if ($this->alreadyExecuted($tool, $clientId, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Duplicate patch-policy delete suppressed before upstream call.', $actorLabel);

            return ['success' => true, 'idempotent' => true, 'message' => 'Already deleted this patch policy recently; no upstream call was made.'];
        }
        if ($this->cooldownActive($tool, $clientId, self::COOLDOWNS[$tool])) {
            $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Patch-policy delete cooldown active; upstream call refused.', $actorLabel);

            return ['error' => 'tactical_delete_patch_policy cooldown active for this client; no upstream call was made.'];
        }

        try {
            $this->client->deletePatchPolicy((int) $resolved['patch_policy_id']);
        } catch (TacticalClientException $e) {
            $message = $this->tacticalFailureMessage($e, 'Tactical patch-policy delete');
            $this->auditAttempt($tool, 'error', $clientId, $contentHash, $message, $actorLabel);

            return ['error' => $message];
        }

        $this->auditAttempt($tool, 'executed', $clientId, $contentHash, "Deleted Tactical patch policy id {$resolved['patch_policy_id']} for policy {$resolved['policy_name']}.", $actorLabel);

        return ['success' => true, 'patch_policy_id' => $resolved['patch_policy_id'], 'message' => 'Tactical patch policy deleted.'];
    }

    /** @return array<string, mixed> */
    private function resetPatchPolicies(array $arguments, int $clientId, string $actorLabel): array
    {
        $tool = 'tactical_reset_patch_policies';
        $guard = $this->baseGuard($tool, $arguments, $clientId, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $client = Client::find($clientId);
        if (! $client) {
            $this->auditAttempt($tool, 'rejected', $clientId, $this->contentHash($tool, $clientId, 'patch-reset', $arguments), 'Client not found.', $actorLabel);

            return ['error' => 'Client not found'];
        }

        $typed = trim((string) ($arguments['confirm_client_name'] ?? ''));
        if (strcasecmp($typed, (string) $client->name) !== 0) {
            $this->auditAttempt($tool, 'rejected', $clientId, $this->contentHash($tool, $clientId, 'patch-reset', $arguments), 'The typed client name does not match this client.', $actorLabel);

            return ['error' => 'The typed client name does not match this client. Type the client name to confirm this bulk reset.'];
        }

        return $this->executePatchPolicyReset($tool, $arguments, $client, $actorLabel);
    }

    /** @return array<string, mixed> */
    private function stagePatchPolicyReset(array $arguments, int $clientId, string $actorLabel): array
    {
        $tool = 'tactical_stage_reset_patch_policies';
        $guard = $this->baseGuard($tool, $arguments, $clientId, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $client = Client::find($clientId);
        if (! $client) {
            $this->auditAttempt($tool, 'rejected', $clientId, $this->contentHash($tool, $clientId, 'patch-reset', $arguments), 'Client not found.', $actorLabel);

            return ['error' => 'Client not found'];
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            $this->auditAttempt($tool, 'rejected', $clientId, $this->contentHash($tool, $clientId, 'patch-reset', $arguments), $ticket['error'], $actorLabel);

            return ['error' => $ticket['error']];
        }

        $scope = $this->patchResetScope($arguments, $client);
        $contentHash = $this->contentHash($tool, $clientId, 'patch-reset', ['scope' => $scope['body'] ?? null]);
        if (isset($scope['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, $scope['error'], $actorLabel);

            return ['error' => $scope['error']];
        }
        if ($this->alreadyAwaitingOrExecuted($tool, $clientId, $contentHash)) {
            $run = TechnicianRun::query()
                ->where('ticket_id', $ticket->id)
                ->where('action_type', $tool)
                ->where('content_hash', $contentHash)
                ->where('state', TechnicianRunState::AwaitingApproval->value)
                ->first();

            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $run?->id,
                'message' => 'Already staged; awaiting approval.',
            ];
        }
        if ($this->cooldownActive($tool, $clientId, self::COOLDOWNS[$tool])) {
            $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Patch-policy reset proposal cooldown active.', $actorLabel);

            return ['error' => 'tactical_stage_reset_patch_policies cooldown active for this client; no proposal was staged.'];
        }

        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $clientId,
            'action_type' => $tool,
            'content_hash' => $contentHash,
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Stage bulk reset Tactical patch policies for '.$scope['label'].".\nReason: ".$guard['reason'],
            'proposed_meta' => [
                'drafted_by' => $actorLabel,
                'reasons' => [$guard['reason']],
                'direct_tool' => self::STAGED_TO_DIRECT[$tool],
                'redacted_params' => ['scope' => $scope['label']],
                'encrypted_payload' => Crypt::encryptString(json_encode([
                    'direct_tool' => self::STAGED_TO_DIRECT[$tool],
                    'client_id' => $clientId,
                    'ticket_id' => $ticket->id,
                    'arguments' => [
                        'scope' => $scope['scope'],
                        'reason' => $guard['reason'],
                    ],
                ], JSON_THROW_ON_ERROR)),
            ],
            'confidence' => null,
            'tokens_used' => 0,
        ]);

        $this->auditAttempt($tool, 'awaiting_approval', $clientId, $contentHash, 'MCP staged Tactical patch-policy reset for '.$scope['label'].'.', $actorLabel, $run->id);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'message' => 'Staged for cockpit approval.',
        ];
    }

    public function approveStagedRun(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        if (! self::isStagedActionType($run->action_type) || ! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        try {
            $payload = $this->decryptRunPayload($run);
            $directTool = (string) ($payload['direct_tool'] ?? '');
            if ($payload === null || ! isset(self::STAGED_TO_DIRECT[$run->action_type]) || self::STAGED_TO_DIRECT[$run->action_type] !== $directTool) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $client = Client::find((int) ($payload['client_id'] ?? 0));
            $ticket = Ticket::find((int) ($payload['ticket_id'] ?? 0));
            if (! $client || ! $ticket || (int) $ticket->client_id !== (int) $run->client_id) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            if (TechnicianConfig::killSwitchEngaged()) {
                $this->auditAttempt($run->action_type, 'blocked', (int) $run->client_id, $run->content_hash, 'Technician kill-switch engaged; staged Tactical admin action refused.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            if ($this->cooldownActive($directTool, (int) $run->client_id, self::COOLDOWNS[$directTool] ?? 60)) {
                $this->auditAttempt($run->action_type, 'blocked', (int) $run->client_id, $run->content_hash, 'Tactical staged admin action cooldown active; approval refused before upstream call.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $arguments = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];
            $result = $this->executePatchPolicyReset($run->action_type, $arguments, $client, $this->approverLabel($approverId), $run, $approverId);
            if (isset($result['error'])) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $run->advanceTo(TechnicianRunState::Done);

            return new TechnicianApprovalResult('executed');
        } catch (\Throwable $e) {
            $run->releaseClaim();

            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function executePatchPolicyReset(
        string $tool,
        array $arguments,
        Client $client,
        string $actorLabel,
        ?TechnicianRun $run = null,
        ?int $approverId = null,
    ): array {
        $clientId = (int) $client->id;
        $scope = $this->patchResetScope($arguments, $client);
        $contentHash = $run?->content_hash ?? $this->contentHash($tool, $clientId, 'patch-reset', ['scope' => $scope['body'] ?? null]);
        if (isset($scope['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, $scope['error'], $actorLabel, $run?->id, $approverId);

            return ['error' => $scope['error']];
        }

        if ($run === null) {
            if ($this->alreadyExecuted($tool, $clientId, $contentHash)) {
                $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Duplicate patch-policy reset suppressed before upstream call.', $actorLabel);

                return ['success' => true, 'idempotent' => true, 'message' => 'Already reset this Tactical patch-policy scope recently; no upstream call was made.'];
            }
            if ($this->cooldownActive($tool, $clientId, self::COOLDOWNS[$tool])) {
                $this->auditAttempt($tool, 'blocked', $clientId, $contentHash, 'Patch-policy reset cooldown active; upstream call refused.', $actorLabel);

                return ['error' => 'tactical_reset_patch_policies cooldown active for this client; no upstream call was made.'];
            }
        }

        try {
            $this->client->resetPatchPolicies($scope['body']);
        } catch (TacticalClientException $e) {
            $message = $this->tacticalFailureMessage($e, 'Tactical patch-policy reset');
            $this->auditAttempt($tool, 'error', $clientId, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['error' => $message];
        }

        $this->auditAttempt($tool, 'executed', $clientId, $contentHash, 'Reset Tactical patch policies for '.$scope['label'].'.', $actorLabel, $run?->id, $approverId);

        return ['success' => true, 'scope' => $scope['scope'], 'message' => 'Tactical patch policies reset to inherit for the selected scope.'];
    }

    /** @return array{body?: array<string, int>, label?: string, scope?: string, error?: string} */
    private function patchResetScope(array $arguments, Client $client): array
    {
        $scope = mb_strtolower(trim((string) ($arguments['scope'] ?? 'site')));
        if (! in_array($scope, ['client', 'site'], true)) {
            return ['error' => 'scope must be one of: client, site'];
        }

        $siteKey = is_string($client->tactical_site_id) ? trim($client->tactical_site_id) : '';
        if ($siteKey === '' || ! str_contains($siteKey, '|')) {
            return ['error' => 'Client has no Tactical site mapping'];
        }

        [$clientName, $siteName] = array_pad(explode('|', $siteKey, 2), 2, 'Main');
        $clientName = trim($clientName);
        $siteName = trim($siteName) ?: 'Main';

        try {
            $clients = $this->client->getClients();
        } catch (TacticalClientException) {
            return ['error' => 'Could not read Tactical clients/sites to resolve patch-policy reset scope; no reset was sent.'];
        }

        foreach ($clients as $candidate) {
            if (! is_array($candidate) || strcasecmp((string) ($candidate['name'] ?? ''), $clientName) !== 0) {
                continue;
            }

            $upstreamClientId = $this->positiveInteger($candidate['id'] ?? null);
            if ($scope === 'client') {
                return $upstreamClientId !== null
                    ? ['body' => ['client' => $upstreamClientId], 'label' => "Tactical client {$clientName}", 'scope' => 'client']
                    : ['error' => 'Matched Tactical client has no numeric id; no reset was sent.'];
            }

            foreach (($candidate['sites'] ?? []) as $site) {
                if (! is_array($site) || strcasecmp((string) ($site['name'] ?? ''), $siteName) !== 0) {
                    continue;
                }

                $siteId = $this->positiveInteger($site['id'] ?? null);

                return $siteId !== null
                    ? ['body' => ['site' => $siteId], 'label' => "Tactical site {$clientName}|{$siteName}", 'scope' => 'site']
                    : ['error' => 'Matched Tactical site has no numeric id; no reset was sent.'];
            }

            return ['error' => "Tactical site {$clientName}|{$siteName} was not found; no reset was sent."];
        }

        return ['error' => "Tactical client {$clientName} was not found; no reset was sent."];
    }

    /** @return array{body?: array<string, mixed>, error?: string} */
    private function patchPolicyBody(array $arguments, bool $includePolicy): array
    {
        $allowed = [
            'critical',
            'important',
            'moderate',
            'low',
            'other',
            'run_time_hour',
            'run_time_frequency',
            'run_time_days',
            'run_time_day',
            'reboot_after_install',
            'reprocess_failed_inherit',
            'reprocess_failed',
            'reprocess_failed_times',
            'email_if_fail',
        ];
        $nonBody = ['client_id', 'reason', 'policy_id', 'confirm_policy_name', 'ticket_id'];
        $unsupported = array_values(array_diff(array_keys($arguments), array_merge($allowed, $nonBody)));
        if ($unsupported !== []) {
            return ['error' => 'Unsupported patch policy fields: '.implode(', ', $unsupported).'.'];
        }

        $body = [];
        foreach (['critical', 'important', 'moderate', 'low', 'other'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $value = $this->enumValue($arguments[$key], self::PATCH_APPROVAL_VALUES);
                if ($value === null) {
                    return ['error' => "{$key} must be one of: ".implode(', ', self::PATCH_APPROVAL_VALUES).'.'];
                }
                $body[$key] = $value;
            }
        }

        if (array_key_exists('run_time_frequency', $arguments)) {
            $value = $this->enumValue($arguments['run_time_frequency'], self::PATCH_FREQUENCIES);
            if ($value === null) {
                return ['error' => 'run_time_frequency must be one of: '.implode(', ', self::PATCH_FREQUENCIES).'.'];
            }
            $body['run_time_frequency'] = $value;
        }

        if (array_key_exists('reboot_after_install', $arguments)) {
            $value = $this->enumValue($arguments['reboot_after_install'], self::PATCH_REBOOT_VALUES);
            if ($value === null) {
                return ['error' => 'reboot_after_install must be one of: '.implode(', ', self::PATCH_REBOOT_VALUES).'.'];
            }
            $body['reboot_after_install'] = $value;
        }

        foreach (['run_time_hour', 'run_time_days', 'run_time_day', 'reprocess_failed_times'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $value = $this->nonNegativeInteger($arguments[$key]);
                if ($value === null) {
                    return ['error' => "{$key} must be a non-negative integer."];
                }
                $body[$key] = $value;
            }
        }

        foreach (['reprocess_failed_inherit', 'reprocess_failed', 'email_if_fail'] as $key) {
            if (array_key_exists($key, $arguments)) {
                if (! is_bool($arguments[$key])) {
                    return ['error' => "{$key} must be a boolean."];
                }
                $body[$key] = $arguments[$key];
            }
        }

        return ['body' => $body];
    }

    /** @return array<string, mixed>|null */
    private function policyById(int $policyId): ?array
    {
        foreach ($this->client->getPolicies() as $policy) {
            if (is_array($policy) && (int) ($policy['id'] ?? 0) === $policyId) {
                return $policy;
            }
        }

        return null;
    }

    /** @return array{policy_id?: int, policy_name?: string, patch_policy_id?: int, error?: string} */
    private function resolvedPatchPolicy(array $arguments, int $clientId, string $tool, string $actorLabel): array
    {
        $policyId = $this->positiveInteger($arguments['policy_id'] ?? null);
        $contentHash = $this->contentHash($tool, $clientId, 'patch-policy', ['policy_id' => $policyId]);
        if ($policyId === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, 'policy_id is required.', $actorLabel);

            return ['error' => 'policy_id is required'];
        }

        try {
            $policy = $this->policyById($policyId);
        } catch (TacticalClientException $e) {
            $message = $this->tacticalFailureMessage($e, 'Tactical policy lookup');
            $this->auditAttempt($tool, 'error', $clientId, $contentHash, $message, $actorLabel);

            return ['error' => $message];
        }

        if ($policy === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, "Policy id {$policyId} was not found in Tactical getPolicies.", $actorLabel);

            return ['error' => "Policy id {$policyId} was not found in Tactical getPolicies."];
        }

        $patchPolicyId = $this->patchPolicyIdFromPolicy($policy);
        if ($patchPolicyId === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, $contentHash, "Policy id {$policyId} does not have a visible patch policy.", $actorLabel);

            return ['error' => "Policy id {$policyId} does not have a visible patch policy."];
        }

        return [
            'policy_id' => $policyId,
            'policy_name' => (string) ($policy['name'] ?? $policyId),
            'patch_policy_id' => $patchPolicyId,
        ];
    }

    private function patchPolicyIdFromPolicy(array $policy): ?int
    {
        $raw = $policy['winupdatepolicy'] ?? null;
        if (is_array($raw)) {
            return $this->positiveInteger($raw['id'] ?? null);
        }

        return $this->positiveInteger($raw);
    }

    /** @return Ticket|array{error: string} */
    private function ticketForClient(mixed $ticketIdValue, int $clientId): Ticket|array
    {
        $ticketId = $this->positiveInteger($ticketIdValue);
        if ($ticketId === null) {
            return ['error' => 'ticket_id is required for staged Tactical patch-policy reset'];
        }

        $ticket = Ticket::find($ticketId);
        if (! $ticket || (int) $ticket->client_id !== $clientId) {
            return ['error' => 'Ticket not found or belongs to a different client'];
        }

        return $ticket;
    }

    private function decryptRunPayload(TechnicianRun $run): ?array
    {
        $ciphertext = $run->proposed_meta['encrypted_payload'] ?? null;
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        $json = Crypt::decryptString($ciphertext);
        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private function enumValue(mixed $value, array $allowed): ?string
    {
        $normalized = is_scalar($value) ? mb_strtolower(trim((string) $value)) : '';

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function approverLabel(int $approverId): string
    {
        $user = \App\Models\User::find($approverId);

        return $user?->email ?? $user?->name ?? "approver:{$approverId}";
    }

    /**
     * @return array{reason?: string, error?: string}
     */
    private function baseGuard(string $tool, array $arguments, ?int $clientId, string $actorLabel): array
    {
        if ($keys = $this->upstreamIdentifierKeys($arguments)) {
            $this->auditAttempt($tool, 'rejected', $clientId, $this->contentHash($tool, $clientId, 'guard', $arguments), 'Caller-supplied upstream Tactical identifiers are not accepted: '.implode(', ', $keys).'.', $actorLabel);

            return ['error' => 'Caller-supplied upstream Tactical identifiers are not accepted; provide PSA-facing selectors instead.'];
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, $this->contentHash($tool, $clientId, 'guard', $arguments), 'reason is required.', $actorLabel);

            return ['error' => 'reason is required'];
        }

        if (TechnicianConfig::killSwitchEngaged()) {
            $this->auditAttempt($tool, 'blocked', $clientId, $this->contentHash($tool, $clientId, 'guard', $arguments), 'Technician kill-switch engaged; Tactical MCP admin action refused.', $actorLabel);

            return ['error' => 'Technician kill-switch engaged; Tactical MCP admin action refused'];
        }

        return ['reason' => $reason];
    }

    private function policyIdsError(?int $workstationPolicyId, ?int $serverPolicyId): ?string
    {
        if ($workstationPolicyId === null && $serverPolicyId === null) {
            return null;
        }

        $ids = collect($this->client->getPolicies())
            ->map(fn (array $policy): int => (int) ($policy['id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        foreach (array_filter([$workstationPolicyId, $serverPolicyId]) as $policyId) {
            if (! in_array($policyId, $ids, true)) {
                return "Policy id {$policyId} was not found in Tactical getPolicies; no client was created.";
            }
        }

        return null;
    }

    private function siteKey(string $clientName, string $siteName): string
    {
        return trim($clientName).'|'.(trim($siteName) ?: 'Main');
    }

    private function siteKeyCollision(string $siteKey, int $clientId): ?string
    {
        $exists = Client::where('tactical_site_id', $siteKey)
            ->whereKeyNot($clientId)
            ->exists();

        return $exists ? "Tactical site mapping {$siteKey} is already linked to another PSA client." : null;
    }

    /** @return Asset|array{error: string} */
    private function resolveAsset(array $arguments, int $clientId): Asset|array
    {
        $assetId = $this->positiveInteger($arguments['asset_id'] ?? null);
        $hostname = trim((string) ($arguments['hostname'] ?? ''));

        if ($assetId === null && $hostname === '') {
            return ['error' => 'asset_id or hostname is required'];
        }

        $asset = null;
        if ($assetId !== null) {
            $asset = Asset::with('tacticalAsset')
                ->whereKey($assetId)
                ->where('client_id', $clientId)
                ->first();
            if (! $asset) {
                return ['error' => 'Asset not found or belongs to a different client'];
            }
        }

        if ($hostname !== '') {
            $byHostname = Asset::with('tacticalAsset')
                ->where('client_id', $clientId)
                ->whereHas('tacticalAsset', fn ($query) => $query->whereRaw('LOWER(hostname) = ?', [mb_strtolower($hostname)]))
                ->first();

            if (! $byHostname) {
                return ['error' => "Device '{$hostname}' not found or belongs to a different client"];
            }

            if ($asset !== null && ! $asset->is($byHostname)) {
                return ['error' => 'asset_id and hostname resolve to different devices'];
            }

            $asset = $byHostname;
        }

        return $asset;
    }

    private function linkedAgentError(Asset $asset): ?string
    {
        $asset->loadMissing('tacticalAsset');

        return empty($asset->tacticalAsset?->agent_id)
            ? 'Device has no Tactical agent'
            : null;
    }

    /** @return array<int, string> */
    private function upstreamIdentifierKeys(array $arguments): array
    {
        $keys = [];
        foreach (self::UPSTREAM_IDENTIFIER_KEYS as $key) {
            if (array_key_exists($key, $arguments)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function alreadyExecuted(string $tool, ?int $clientId, string $contentHash): bool
    {
        return $this->actionLogQuery($tool, $clientId)
            ->where('content_hash', $contentHash)
            ->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->exists();
    }

    private function alreadyAwaitingOrExecuted(string $tool, ?int $clientId, string $contentHash): bool
    {
        return $this->actionLogQuery($tool, $clientId)
            ->where('content_hash', $contentHash)
            ->whereIn('result_status', ['awaiting_approval', 'executed'])
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->exists();
    }

    private function cooldownActive(string $tool, ?int $clientId, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        $statuses = $tool === 'tactical_reset_patch_policies'
            ? ['executed']
            : ['executed', 'awaiting_approval'];

        return $this->actionLogQuery($tool, $clientId)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->whereIn('result_status', $statuses)
            ->exists();
    }

    private function actionLogQuery(string $tool, ?int $clientId)
    {
        $actionTypes = match ($tool) {
            'tactical_reset_patch_policies' => ['tactical_reset_patch_policies', 'tactical_stage_reset_patch_policies'],
            default => [$tool],
        };
        $query = TechnicianActionLog::query()->whereIn('action_type', $actionTypes);

        return $clientId === null
            ? $query->whereNull('client_id')
            : $query->where('client_id', $clientId);
    }

    private function auditAttempt(
        string $actionType,
        string $resultStatus,
        ?int $clientId,
        string $contentHash,
        string $summary,
        string $actorLabel,
        ?int $runId = null,
        ?int $approverId = null,
    ): void {
        TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::aiActorUserId(),
            'approver_user_id' => $approverId,
            'actor_label' => $actorLabel,
            'action_type' => $actionType,
            'tier' => TechnicianTier::Approve->value,
            'result_status' => $resultStatus,
            'ticket_id' => null,
            'client_id' => $clientId,
            'run_id' => $runId,
            'content_hash' => $contentHash,
            'summary' => mb_substr($this->redactor->redactString($summary), 0, 1000),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }

    private function contentHash(string $tool, ?int $clientId, string $target, array $params): string
    {
        return hash('sha256', json_encode([
            'tool' => $tool,
            'client_id' => $clientId,
            'target' => $target,
            'params' => $this->canonical($this->safeHashParams($params)),
        ]));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }

    /** @return array<string, mixed> */
    private function safeHashParams(array $params): array
    {
        $safe = $params;
        unset($safe['value'], $safe['rest_headers'], $safe['rest_body'], $safe['download_url']);

        return $safe;
    }

    private function requiredString(array $arguments, string $key): ?string
    {
        if (! array_key_exists($key, $arguments) || ! is_scalar($arguments[$key])) {
            return null;
        }

        $value = trim((string) $arguments[$key]);

        return $value !== '' ? $value : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function installerPlatform(mixed $value): ?string
    {
        $platform = is_string($value) ? mb_strtolower(trim($value)) : '';

        return in_array($platform, ['windows', 'mac', 'linux'], true) ? $platform : null;
    }

    private function webhookKey(): string
    {
        $webhookKey = TacticalConfig::get('webhook_key');
        if (! $webhookKey) {
            $webhookKey = TacticalConfig::generateWebhookKey();
            Setting::setEncrypted('tactical_webhook_key', $webhookKey);
        }

        return $webhookKey;
    }

    /** @return array<string, mixed> */
    private function buildUrlActionBody(string $webhookKey): array
    {
        return [
            'name' => self::URL_ACTION_NAME,
            'desc' => 'PSA integration: creates and resolves tickets from Tactical alerts.',
            'pattern' => url('/api/webhooks/tactical'),
            'action_type' => 'rest',
            'rest_method' => 'post',
            'rest_headers' => json_encode([
                'Content-Type' => 'application/json',
                'X-Webhook-Key' => $webhookKey,
            ]),
            'rest_body' => json_encode([
                'alert_id' => '{{alert.id}}',
                'alert_type' => '{{alert.alert_type}}',
                'severity' => '{{alert.severity}}',
                'agent' => '{{agent.hostname}}',
                'client' => '{{client.name}}',
                'site' => '{{site.name}}',
                'message' => '{{alert.message}}',
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function buildAlertTemplateBody(int $urlActionId): array
    {
        return [
            'name' => self::ALERT_TEMPLATE_NAME,
            'is_active' => true,
            'action_type' => 'rest',
            'action_rest' => $urlActionId,
            'resolved_action_type' => 'rest',
            'resolved_action_rest' => $urlActionId,
            'agent_script_actions' => true,
            'check_script_actions' => true,
            'task_script_actions' => true,
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function findById(array $items, int $id): ?array
    {
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $items */
    private function findHighestIdByName(array $items, string $name, string $label): int
    {
        $bestId = null;
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if (($item['name'] ?? null) === $name && $id > 0 && ($bestId === null || $id > $bestId)) {
                $bestId = $id;
            }
        }

        if ($bestId === null) {
            throw new TacticalClientException("{$label} '{$name}' was created but could not be found by name.");
        }

        return $bestId;
    }

    private function tacticalFailureMessage(TacticalClientException $e, string $action): string
    {
        $status = $e->statusCode();

        if ($status === 403) {
            return "{$action} failed: Tactical API key lacks permission for this admin operation.";
        }

        return "{$action} failed (HTTP ".($status ?? 'unknown').').';
    }

    /** @return array<string, mixed> */
    private static function reasonProperties(): array
    {
        return [
            'reason' => [
                'type' => 'string',
                'description' => 'Specific operational reason for this Tactical admin/provisioning action.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function endpointSelectorProperties(): array
    {
        return [
            'asset_id' => [
                'type' => 'integer',
                'description' => 'Optional PSA asset ID. The server verifies it belongs to client_id and derives the Tactical agent.',
            ],
            'hostname' => [
                'type' => 'string',
                'description' => 'Optional device hostname. The server resolves it within client_id and derives the Tactical agent.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function tool(string $name, string $description, array $properties, array $required): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'input_schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function createClientSiteTool(): array
    {
        return self::tool(
            'tactical_create_client_site',
            'Create and map a Tactical client/site for one PSA client using server-derived PSA client scope. Requires explicit grant, reason, kill-switch, dedup/cooldown, policy IDs verified from Tactical getPolicies, and no-clobber mapping.',
            array_merge(self::reasonProperties(), [
                'workstation_policy_id' => ['type' => 'integer', 'description' => 'Optional Tactical workstation policy ID. It is verified against getPolicies before create.'],
                'server_policy_id' => ['type' => 'integer', 'description' => 'Optional Tactical server policy ID. It is verified against getPolicies before create.'],
            ]),
            ['reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function provisionClientSiteTool(): array
    {
        return self::tool(
            'tactical_provision_client_site',
            'Composed client-site provisioning alias for tactical_create_client_site. It creates or links the PSA client to a Tactical site idempotently and refuses caller-supplied upstream client/site identifiers.',
            array_merge(self::reasonProperties(), [
                'workstation_policy_id' => ['type' => 'integer', 'description' => 'Optional Tactical workstation policy ID verified from getPolicies before create.'],
                'server_policy_id' => ['type' => 'integer', 'description' => 'Optional Tactical server policy ID verified from getPolicies before create.'],
            ]),
            ['reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function setAgentCustomFieldTool(): array
    {
        return self::tool(
            'tactical_set_agent_custom_field',
            'Set one allowlisted PSA-owned Tactical custom field on one server-derived endpoint. Arbitrary field IDs and upstream agent IDs are rejected; field values are withheld from MCP and Technician audits.',
            array_merge(self::reasonProperties(), self::endpointSelectorProperties(), [
                'field_key' => ['type' => 'string', 'description' => 'Allowlisted key: comet_install_token, servosity_one_url, servosity_screenconnect_url, servosity_credential_user, or servosity_credential_password.'],
                'value' => ['type' => 'string', 'description' => 'Value to write. It is sent upstream but withheld from MCP and Technician audits.'],
            ]),
            ['reason', 'field_key', 'value'],
        );
    }

    /** @return array<string, mixed> */
    private static function upsertUrlActionTool(): array
    {
        return self::tool(
            'tactical_upsert_url_action',
            'Upsert the PSA-owned Tactical URL action for alert ticketing through the existing wrapper shape. Requires explicit grant, reason, kill-switch, dedup/cooldown, and refuses to update a stored id unless it still points to the PSA-owned action name.',
            self::reasonProperties(),
            ['reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function upsertAlertTemplateTool(): array
    {
        return self::tool(
            'tactical_upsert_alert_template',
            'Upsert the PSA-owned Tactical alert template using the stored PSA URL action. Requires explicit grant, reason, kill-switch, dedup/cooldown, and refuses to update a stored id unless it still points to the PSA-owned template name.',
            self::reasonProperties(),
            ['reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function setDefaultAlertTemplateTool(): array
    {
        return self::tool(
            'tactical_set_default_alert_template',
            'Set the Tactical global default alert template to the stored PSA-owned template. This affects Tactical alert behavior across devices; it refuses to clobber a different existing default and requires explicit grant, reason, kill-switch, dedup, and cooldown.',
            self::reasonProperties(),
            ['reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function getOrCreateInstallerTool(): array
    {
        return self::tool(
            'tactical_get_or_create_installer',
            'Generate a signed installer URL for a client-scoped Tactical site using server-derived PSA client mapping. The signed installer URL is returned once, not retained in audits, and the response is no-store.',
            array_merge(self::reasonProperties(), [
                'platform' => ['type' => 'string', 'enum' => ['windows', 'mac', 'linux'], 'description' => 'Installer platform.'],
            ]),
            ['reason', 'platform'],
        );
    }

    /** @return array<string, mixed> */
    private static function generateInstallerTool(): array
    {
        return self::tool(
            'tactical_generate_installer',
            'Composed installer generation alias for tactical_get_or_create_installer. It returns a signed installer URL once, never audits the URL, and uses no-store HTTP caching.',
            array_merge(self::reasonProperties(), [
                'platform' => ['type' => 'string', 'enum' => ['windows', 'mac', 'linux'], 'description' => 'Installer platform.'],
            ]),
            ['reason', 'platform'],
        );
    }

    /** @return array<string, mixed> */
    private static function syncDevicesTool(): array
    {
        return self::tool(
            'tactical_sync_devices_now',
            'Run the existing Tactical device sync wrapper for one PSA client mapping. Requires explicit grant, reason, kill-switch, dedup/cooldown, and server-derived client scope.',
            self::reasonProperties(),
            ['reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function syncScriptsTool(): array
    {
        return self::tool(
            'tactical_sync_scripts_now',
            'Run the existing Tactical script catalog sync wrapper. Requires explicit grant, reason, kill-switch, dedup, and cooldown; it refreshes local script metadata only.',
            self::reasonProperties(),
            ['reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function provisionAlertTicketingTool(): array
    {
        return self::tool(
            'tactical_provision_alert_ticketing',
            'Run the existing TacticalProvisioningService to provision PSA alert ticketing. It upserts PSA-owned URL action and alert template, then applies the existing no-clobber default-template behavior.',
            self::reasonProperties(),
            ['reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function patchPolicyProperties(bool $includePolicy = true): array
    {
        $properties = [
            'critical' => ['type' => 'string', 'enum' => self::PATCH_APPROVAL_VALUES, 'description' => 'Approval behavior for critical updates.'],
            'important' => ['type' => 'string', 'enum' => self::PATCH_APPROVAL_VALUES, 'description' => 'Approval behavior for important updates.'],
            'moderate' => ['type' => 'string', 'enum' => self::PATCH_APPROVAL_VALUES, 'description' => 'Approval behavior for moderate updates.'],
            'low' => ['type' => 'string', 'enum' => self::PATCH_APPROVAL_VALUES, 'description' => 'Approval behavior for low updates.'],
            'other' => ['type' => 'string', 'enum' => self::PATCH_APPROVAL_VALUES, 'description' => 'Approval behavior for other updates.'],
            'run_time_hour' => ['type' => 'integer', 'description' => 'Patch run hour. Sent only when supplied.'],
            'run_time_frequency' => ['type' => 'string', 'enum' => self::PATCH_FREQUENCIES, 'description' => 'Patch run frequency.'],
            'run_time_days' => ['type' => 'integer', 'description' => 'Patch run days bitmask/value accepted by Tactical.'],
            'run_time_day' => ['type' => 'integer', 'description' => 'Patch run day accepted by Tactical.'],
            'reboot_after_install' => ['type' => 'string', 'enum' => self::PATCH_REBOOT_VALUES, 'description' => 'Reboot behavior after patch install.'],
            'reprocess_failed_inherit' => ['type' => 'boolean', 'description' => 'Whether failed-update reprocessing inherits.'],
            'reprocess_failed' => ['type' => 'boolean', 'description' => 'Whether to reprocess failed updates.'],
            'reprocess_failed_times' => ['type' => 'integer', 'description' => 'Failed-update reprocess attempts.'],
            'email_if_fail' => ['type' => 'boolean', 'description' => 'Whether Tactical emails on patch failure.'],
        ];

        if ($includePolicy) {
            $properties = ['policy_id' => ['type' => 'integer', 'description' => 'Tactical automation policy id from tactical_list_policies. The server verifies it with getPolicies before using it.']] + $properties;
        }

        return $properties;
    }

    /** @return array<string, mixed> */
    private static function createPatchPolicyTool(): array
    {
        return self::tool(
            'tactical_create_patch_policy',
            'Create a Tactical patch policy attached to one verified automation policy using POST automation/patchpolicy/. The upstream serializer is broad; this tool narrows writes to the confirmed patch-policy field set and verifies policy_id against getPolicies before sending.',
            array_merge(self::reasonProperties(), self::patchPolicyProperties(includePolicy: true)),
            ['reason', 'policy_id'],
        );
    }

    /** @return array<string, mixed> */
    private static function updatePatchPolicyTool(): array
    {
        return self::tool(
            'tactical_update_patch_policy',
            'Update the patch policy currently attached to one verified Tactical automation policy using PUT automation/patchpolicy/{pk}/. The server resolves the patch-policy id from getPolicies and sends only allowlisted patch-policy fields.',
            array_merge(self::reasonProperties(), self::patchPolicyProperties(includePolicy: true)),
            ['reason', 'policy_id'],
        );
    }

    /** @return array<string, mixed> */
    private static function deletePatchPolicyTool(): array
    {
        return self::tool(
            'tactical_delete_patch_policy',
            'Delete the patch policy currently attached to one verified Tactical automation policy using DELETE automation/patchpolicy/{pk}/. This can remove Windows update automation behavior; requires explicit grant, reason, typed policy-name confirmation, kill-switch, dedup/cooldown, and server-side patch-policy id resolution.',
            array_merge(self::reasonProperties(), [
                'policy_id' => ['type' => 'integer', 'description' => 'Tactical automation policy id from tactical_list_policies. The server resolves its patch-policy id from getPolicies.'],
                'confirm_policy_name' => ['type' => 'string', 'description' => 'Typed Tactical automation policy name.'],
            ]),
            ['reason', 'policy_id', 'confirm_policy_name'],
        );
    }

    /** @return array<string, mixed> */
    private static function resetPatchPoliciesTool(): array
    {
        return self::tool(
            'tactical_reset_patch_policies',
            'Directly bulk reset Tactical patch policies to inherit for the PSA client-derived Tactical client or site using POST automation/patchpolicy/reset/. This can affect many endpoints; the global empty-body reset is intentionally not exposed. Requires explicit grant, reason, typed client-name confirmation, kill-switch, dedup/cooldown, and server-derived client/site ids.',
            array_merge(self::reasonProperties(), [
                'scope' => ['type' => 'string', 'enum' => ['site', 'client'], 'description' => 'Reset scope derived from the PSA client Tactical site mapping. Default site.'],
                'confirm_client_name' => ['type' => 'string', 'description' => 'Typed PSA client name for direct bulk reset confirmation.'],
            ]),
            ['reason', 'confirm_client_name'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageResetPatchPoliciesTool(): array
    {
        return self::tool(
            'tactical_stage_reset_patch_policies',
            'Stage a bulk reset of Tactical patch policies to inherit for the PSA client-derived Tactical client or site. The MCP call makes no reset call; cockpit approval re-derives the Tactical client/site scope, re-checks kill-switch and cooldown, then POSTs automation/patchpolicy/reset/.',
            array_merge(self::reasonProperties(), [
                'ticket_id' => ['type' => 'integer', 'description' => 'Required ticket ID anchoring the cockpit approval. The ticket must belong to client_id.'],
                'scope' => ['type' => 'string', 'enum' => ['site', 'client'], 'description' => 'Reset scope derived from the PSA client Tactical site mapping. Default site.'],
            ]),
            ['ticket_id', 'reason'],
        );
    }
}
