<?php

namespace App\Services\Mcp;

use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Models\Client;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippClientException;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Cipp\CippWriteScopeException;
use App\Services\Cipp\CippWriteScopeResolver;
use App\Services\Cipp\ResolvedCippLicense;
use App\Services\Cipp\ResolvedCippPerson;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Services\Technician\TechnicianApprovalResult;
use App\Support\CippConfig;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class StaffCippWriteToolExecutor
{
    private const DIRECT_DEDUP_HOURS = 24;

    /** @var array<string, string> */
    private const STAGED_TO_DIRECT = [
        'cipp_stage_disable_user_sign_in' => 'cipp_disable_user_sign_in',
        'cipp_stage_enable_user_sign_in' => 'cipp_enable_user_sign_in',
        'cipp_stage_revoke_user_sessions' => 'cipp_revoke_user_sessions',
        'cipp_stage_remove_user_mfa_methods' => 'cipp_remove_user_mfa_methods',
        'cipp_stage_set_legacy_per_user_mfa' => 'cipp_set_legacy_per_user_mfa',
        'cipp_stage_assign_user_license' => 'cipp_assign_user_license',
        'cipp_stage_remove_user_license' => 'cipp_remove_user_license',
    ];

    /** @var array<string, int> */
    private const COOLDOWNS = [
        'cipp_disable_user_sign_in' => 300,
        'cipp_stage_disable_user_sign_in' => 300,
        'cipp_enable_user_sign_in' => 300,
        'cipp_stage_enable_user_sign_in' => 300,
        'cipp_revoke_user_sessions' => 300,
        'cipp_stage_revoke_user_sessions' => 300,
        'cipp_remove_user_mfa_methods' => 300,
        'cipp_stage_remove_user_mfa_methods' => 300,
        'cipp_set_legacy_per_user_mfa' => 300,
        'cipp_stage_set_legacy_per_user_mfa' => 300,
        'cipp_assign_user_license' => 300,
        'cipp_stage_assign_user_license' => 300,
        'cipp_remove_user_license' => 300,
        'cipp_stage_remove_user_license' => 300,
    ];

    /** @var array<int, string> */
    private const UPSTREAM_IDENTIFIER_KEYS = [
        'tenantFilter',
        'TenantFilter',
        'tenant_filter',
        'tenant',
        'tenant_domain',
        'cipp_tenant_domain',
        'customerId',
        'customer_id',
        'ID',
        'id',
        'userId',
        'userID',
        'userPrincipalName',
        'Username',
        'upstream_user_id',
        'cipp_user_id',
        'cipp_upn',
        'skuId',
        'sku_id',
        'licenseSku',
        'license_sku',
        'licenseSkuId',
        'Licenses',
        'LicensesToRemove',
        'LicenseOperation',
        'RemoveAllLicenses',
        'ReplaceAllLicenses',
        'removeAllLicenses',
        'replaceAllLicenses',
        'endpoint',
        'Endpoint',
        'cipp_endpoint',
        'body',
        'request',
    ];

    public function __construct(
        private readonly CippRestWriteClient $client,
        private readonly CippWriteScopeResolver $resolver,
        private readonly ActionRedactor $redactor,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            self::disableSignInTool(),
            self::stageDisableSignInTool(),
            self::enableSignInTool(),
            self::stageEnableSignInTool(),
            self::revokeSessionsTool(),
            self::stageRevokeSessionsTool(),
            self::removeMfaTool(),
            self::stageRemoveMfaTool(),
            self::setLegacyMfaTool(),
            self::stageSetLegacyMfaTool(),
            self::assignLicenseTool(),
            self::stageAssignLicenseTool(),
            self::removeLicenseTool(),
            self::stageRemoveLicenseTool(),
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
        return self::handles($toolName);
    }

    public static function isStagedActionType(string $actionType): bool
    {
        return array_key_exists($actionType, self::STAGED_TO_DIRECT);
    }

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel): array
    {
        if (! CippConfig::isEnabled() || ! CippConfig::isConfigured()) {
            return ['error' => 'CIPP is not enabled or configured'];
        }

        if (isset(self::STAGED_TO_DIRECT[$name])) {
            return $this->stageAction($name, $arguments, $clientId, $actorLabel);
        }

        return $this->executeDirect($name, $arguments, $clientId, $actorLabel);
    }

    public function approveStagedRun(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        if (! self::isStagedActionType($run->action_type) || ! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        try {
            $payload = $this->decryptRunPayload($run);
            if ($payload === null) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $directTool = (string) ($payload['direct_tool'] ?? '');
            if ((self::STAGED_TO_DIRECT[$run->action_type] ?? null) !== $directTool) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $client = Client::find((int) ($payload['client_id'] ?? 0));
            if (! $client || (int) $client->id !== (int) $run->client_id) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $tenant = $this->resolver->resolveCippTenant($client);
            $person = $this->resolver->resolveCippPerson($client->id, $payload['person_id'] ?? null);
            $ticket = $this->resolver->resolveTicketForHeldAction($client->id, $payload['ticket_id'] ?? null);
            $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
            $license = $this->licenseForTool($directTool, $client->id, $params['license_type_id'] ?? null);
            $state = $this->stateForTool($directTool, $params['state'] ?? null);

            if (TechnicianConfig::killSwitchEngaged()) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $person, $license, $run->content_hash, 'Technician kill-switch engaged; staged CIPP write refused.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            if ($this->cooldownActive($directTool, $client->id, $person, $license, self::COOLDOWNS[$directTool] ?? 300)) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $person, $license, $run->content_hash, 'CIPP staged action cooldown active; approval refused before upstream call.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            try {
                $this->executeUpstream($directTool, $tenant, $person, $license, $state);
            } catch (CippClientException $e) {
                $this->auditAttempt($run->action_type, 'error', $client->id, $ticket, $person, $license, $run->content_hash, $this->safeFailureSummary($run->action_type, $e), $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $this->auditAttempt($run->action_type, 'executed', $client->id, $ticket, $person, $license, $run->content_hash, "Operator-approved {$run->action_type} executed.", $this->approverLabel($approverId), $run->id, $approverId);
            $run->advanceTo(TechnicianRunState::Done);

            return new TechnicianApprovalResult('executed');
        } catch (CippWriteScopeException) {
            $run->releaseClaim();

            return new TechnicianApprovalResult('gate_declined');
        } catch (\Throwable $e) {
            $run->releaseClaim();

            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function executeDirect(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->context($tool, $arguments, $clientId, $actorLabel, requireTicket: false);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var string $tenant */
        $tenant = $context['tenant'];
        /** @var ResolvedCippPerson $person */
        $person = $context['person'];
        /** @var Ticket|null $ticket */
        $ticket = $context['ticket'];
        /** @var ResolvedCippLicense|null $license */
        $license = $context['license'];
        $state = is_string($context['state'] ?? null) ? $context['state'] : null;
        $reason = (string) $context['reason'];

        $contentHash = $this->contentHash($tool, $client->id, $person->person->id, $ticket?->id, $this->hashParams($tool, $license, $state));

        if ($this->alreadyExecuted($tool, $client->id, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, $person, $license, $contentHash, "Duplicate {$tool} suppressed before upstream call.", $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Already executed identical CIPP write recently; no upstream call was made.',
            ];
        }

        if ($this->cooldownActive($tool, $client->id, $person, $license, self::COOLDOWNS[$tool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, $person, $license, $contentHash, "{$tool} cooldown active; upstream call refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no upstream call was made."];
        }

        try {
            $this->executeUpstream($tool, $tenant, $person, $license, $state);
        } catch (CippClientException $e) {
            $this->auditAttempt($tool, 'error', $client->id, $ticket, $person, $license, $contentHash, $this->safeFailureSummary($tool, $e), $actorLabel);

            return ['error' => "CIPP write failed for {$tool}; no response body returned."];
        }

        $this->auditAttempt($tool, 'executed', $client->id, $ticket, $person, $license, $contentHash, "{$tool} executed: {$reason}", $actorLabel);

        return [
            'success' => true,
            'tool' => $tool,
            'person_id' => $person->person->id,
            'ticket_id' => $ticket?->id,
            'message' => 'CIPP action executed.',
        ];
    }

    /** @return array<string, mixed> */
    private function stageAction(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->context($tool, $arguments, $clientId, $actorLabel, requireTicket: true);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var ResolvedCippPerson $person */
        $person = $context['person'];
        /** @var Ticket $ticket */
        $ticket = $context['ticket'];
        /** @var ResolvedCippLicense|null $license */
        $license = $context['license'];
        $state = is_string($context['state'] ?? null) ? $context['state'] : null;
        $reason = (string) $context['reason'];
        $directTool = self::STAGED_TO_DIRECT[$tool];
        $params = $this->hashParams($directTool, $license, $state);
        $contentHash = $this->contentHash($tool, $client->id, $person->person->id, $ticket->id, $params);

        if ($this->alreadyAwaitingOrExecuted($tool, $client->id, $contentHash)) {
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

        if ($this->proposalCooldownActive($tool, $ticket, $person, $license, self::COOLDOWNS[$tool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, $person, $license, $contentHash, "{$tool} cooldown active; staged proposal refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no proposal was staged."];
        }

        $meta = [
            'drafted_by' => $actorLabel,
            'reasons' => [$reason],
            'direct_tool' => $directTool,
            'person_id' => $person->person->id,
            'license_type_id' => $license?->licenseType->id,
            'redacted_params' => $params,
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => $directTool,
                'client_id' => $client->id,
                'person_id' => $person->person->id,
                'ticket_id' => $ticket->id,
                'params' => $params,
            ], JSON_THROW_ON_ERROR)),
        ];

        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => $tool,
            'content_hash' => $contentHash,
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => $this->stagedDisplay($directTool, $person, $license, $state)."\nReason: ".$reason,
            'proposed_meta' => $meta,
            'confidence' => null,
            'tokens_used' => 0,
        ]);

        TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', $tool)
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->where('id', '!=', $run->id)
            ->get()
            ->each
            ->markSuperseded();

        $this->auditAttempt($tool, 'awaiting_approval', $client->id, $ticket, $person, $license, $contentHash, "MCP staged {$tool}: {$reason}", $actorLabel, $run->id);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'message' => 'Staged for cockpit approval.',
        ];
    }

    /**
     * @return array{client?: Client, tenant?: string, person?: ResolvedCippPerson, ticket?: Ticket|null, license?: ResolvedCippLicense|null, state?: string|null, reason?: string, error?: string}
     */
    private function context(string $tool, array $arguments, int $clientId, string $actorLabel, bool $requireTicket): array
    {
        $contentHash = $this->contentHash($tool, $clientId, null, null, $arguments);

        if ($keys = $this->upstreamIdentifierKeys($arguments)) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'Caller-supplied upstream CIPP identifiers are not accepted: '.implode(', ', $keys).'.', $actorLabel);

            return ['error' => 'Caller-supplied upstream CIPP identifiers are not accepted; provide PSA person_id, license_type_id, and ticket_id only.'];
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'reason is required.', $actorLabel);

            return ['error' => 'reason is required'];
        }

        if (TechnicianConfig::killSwitchEngaged()) {
            $this->auditAttempt($tool, 'blocked', $clientId, null, null, null, $contentHash, 'Technician kill-switch engaged; CIPP MCP write refused.', $actorLabel);

            return ['error' => 'Technician kill-switch engaged; CIPP MCP write refused'];
        }

        $client = Client::find($clientId);
        if (! $client) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'Client not found.', $actorLabel);

            return ['error' => 'Client not found'];
        }

        try {
            $tenant = $this->resolver->resolveCippTenant($client);
            $person = $this->resolver->resolveCippPerson($client->id, $arguments['person_id'] ?? null);
            $ticket = $requireTicket
                ? $this->resolver->resolveTicketForHeldAction($client->id, $arguments['ticket_id'] ?? null)
                : $this->resolver->resolveOptionalTicket($client->id, $arguments['ticket_id'] ?? null);
            $license = $this->licenseForTool($tool, $client->id, $arguments['license_type_id'] ?? null);
            $state = $this->stateForTool($tool, $arguments['state'] ?? null);
        } catch (CippWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $client->id, null, null, null, $contentHash, $e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        if ($error = $this->confirmUpnError($arguments, $person)) {
            $this->auditAttempt($tool, 'rejected', $client->id, $ticket, $person, $license, $this->contentHash($tool, $client->id, $person->person->id, $ticket?->id, $this->hashParams($tool, $license, $state)), $error, $actorLabel);

            return ['error' => $error];
        }

        return [
            'client' => $client,
            'tenant' => $tenant,
            'person' => $person,
            'ticket' => $ticket,
            'license' => $license,
            'state' => $state,
            'reason' => $reason,
        ];
    }

    private function executeUpstream(string $tool, string $tenant, ResolvedCippPerson $person, ?ResolvedCippLicense $license, ?string $state): void
    {
        match ($tool) {
            'cipp_disable_user_sign_in' => $this->client->setUserSignInState($tenant, $person->userId, false),
            'cipp_enable_user_sign_in' => $this->client->setUserSignInState($tenant, $person->userId, true),
            'cipp_revoke_user_sessions' => $this->client->revokeUserSessions($tenant, $person->userId, $person->userPrincipalName),
            'cipp_remove_user_mfa_methods' => $this->client->removeUserMfaMethods($tenant, $person->userPrincipalName),
            'cipp_set_legacy_per_user_mfa' => $this->client->setLegacyPerUserMfa($tenant, $person->userPrincipalName, $person->userId, (string) $state),
            'cipp_assign_user_license' => $this->client->assignUserLicense($tenant, $person->userId, (string) $license?->skuId),
            'cipp_remove_user_license' => $this->client->removeUserLicense($tenant, $person->userId, (string) $license?->skuId),
            default => throw new \InvalidArgumentException("Unsupported CIPP write tool {$tool}"),
        };
    }

    private function licenseForTool(string $tool, int $clientId, mixed $licenseTypeId): ?ResolvedCippLicense
    {
        $directTool = self::STAGED_TO_DIRECT[$tool] ?? $tool;
        if (! in_array($directTool, ['cipp_assign_user_license', 'cipp_remove_user_license'], true)) {
            return null;
        }

        return $this->resolver->resolveCippLicense($clientId, $licenseTypeId);
    }

    private function stateForTool(string $tool, mixed $state): ?string
    {
        $directTool = self::STAGED_TO_DIRECT[$tool] ?? $tool;
        if ($directTool !== 'cipp_set_legacy_per_user_mfa') {
            return null;
        }

        if (! is_string($state)) {
            throw new CippWriteScopeException('state is required');
        }

        $normalized = mb_strtolower(trim($state));
        if (! in_array($normalized, ['disabled', 'enabled', 'enforced'], true)) {
            throw new CippWriteScopeException('state must be one of: disabled, enabled, enforced');
        }

        return $normalized;
    }

    private function confirmUpnError(array $arguments, ResolvedCippPerson $person): ?string
    {
        $typed = $this->requiredString($arguments, 'confirm_upn');
        if ($typed === null || strcasecmp($typed, $person->userPrincipalName) !== 0) {
            return 'The typed confirm_upn does not match the resolved CIPP user. CIPP write cancelled.';
        }

        return null;
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

    private function alreadyExecuted(string $tool, int $clientId, string $contentHash): bool
    {
        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('client_id', $clientId)
            ->where('content_hash', $contentHash)
            ->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->exists();
    }

    private function alreadyAwaitingOrExecuted(string $tool, int $clientId, string $contentHash): bool
    {
        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('client_id', $clientId)
            ->where('content_hash', $contentHash)
            ->whereIn('result_status', ['awaiting_approval', 'executed'])
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->exists();
    }

    private function cooldownActive(string $tool, int $clientId, ResolvedCippPerson $person, ?ResolvedCippLicense $license, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('client_id', $clientId)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->whereIn('result_status', ['executed', 'awaiting_approval'])
            ->where('summary', 'like', '%'.$this->targetKey($person, $license).'%')
            ->exists();
    }

    private function proposalCooldownActive(string $tool, Ticket $ticket, ResolvedCippPerson $person, ?ResolvedCippLicense $license, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('ticket_id', $ticket->id)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->whereIn('result_status', ['awaiting_approval', 'executed'])
            ->where('summary', 'like', '%'.$this->targetKey($person, $license).'%')
            ->exists();
    }

    private function auditAttempt(
        string $actionType,
        string $resultStatus,
        ?int $clientId,
        ?Ticket $ticket,
        ?ResolvedCippPerson $person,
        ?ResolvedCippLicense $license,
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
            'ticket_id' => $ticket?->id,
            'client_id' => $clientId,
            'run_id' => $runId,
            'content_hash' => $contentHash,
            'summary' => mb_substr($this->redactor->redactString($this->summaryWithTarget($summary, $person, $license)), 0, 1000),
            'correlation_id' => (string) Str::uuid(),
        ]);
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

    /** @return array<string, mixed> */
    private function hashParams(string $tool, ?ResolvedCippLicense $license, ?string $state): array
    {
        $params = [];
        if ($license !== null) {
            $params['license_type_id'] = $license->licenseType->id;
        }
        if ($state !== null) {
            $params['state'] = $state;
        }

        return $params;
    }

    private function contentHash(string $tool, int $clientId, ?int $personId, ?int $ticketId, array $params): string
    {
        return hash('sha256', json_encode([
            'tool' => $tool,
            'client_id' => $clientId,
            'person_id' => $personId,
            'ticket_id' => $ticketId,
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
        foreach (self::UPSTREAM_IDENTIFIER_KEYS as $key) {
            unset($safe[$key]);
        }
        unset($safe['confirm_upn'], $safe['reason']);

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

    private function targetKey(?ResolvedCippPerson $person, ?ResolvedCippLicense $license): string
    {
        if ($person === null) {
            return 'person #unknown';
        }

        $key = 'person #'.$person->person->id;
        if ($license !== null) {
            $key .= ' license_type #'.$license->licenseType->id;
        }

        return $key;
    }

    private function summaryWithTarget(string $summary, ?ResolvedCippPerson $person, ?ResolvedCippLicense $license): string
    {
        if ($person === null) {
            return $summary;
        }

        return $this->targetKey($person, $license).': '.$summary;
    }

    private function stagedDisplay(string $directTool, ResolvedCippPerson $person, ?ResolvedCippLicense $license, ?string $state): string
    {
        return match ($directTool) {
            'cipp_disable_user_sign_in' => 'Disable sign-in for PSA person #'.$person->person->id.'.',
            'cipp_enable_user_sign_in' => 'Enable sign-in for PSA person #'.$person->person->id.'.',
            'cipp_revoke_user_sessions' => 'Revoke active sessions for PSA person #'.$person->person->id.'.',
            'cipp_remove_user_mfa_methods' => 'Remove MFA methods for PSA person #'.$person->person->id.'.',
            'cipp_set_legacy_per_user_mfa' => 'Set legacy per-user MFA to '.$state.' for PSA person #'.$person->person->id.'.',
            'cipp_assign_user_license' => 'Assign license_type #'.$license?->licenseType->id.' to PSA person #'.$person->person->id.'.',
            'cipp_remove_user_license' => 'Remove license_type #'.$license?->licenseType->id.' from PSA person #'.$person->person->id.'.',
            default => $directTool.' for PSA person #'.$person->person->id.'.',
        };
    }

    private function safeFailureSummary(string $tool, CippClientException $e): string
    {
        return "{$tool} failed before completion: ".mb_substr($this->redactor->redactString($e->getMessage()), 0, 300);
    }

    private function approverLabel(int $approverId): string
    {
        $user = User::find($approverId);

        return $user?->email ?? $user?->name ?? "approver:{$approverId}";
    }

    /** @return array<string, mixed> */
    private static function personProperties(bool $ticket = false): array
    {
        $properties = [
            'person_id' => [
                'type' => 'integer',
                'description' => 'PSA person ID. The server verifies it belongs to client_id and derives the CIPP user id and UPN.',
            ],
            'confirm_upn' => [
                'type' => 'string',
                'description' => 'Typed UPN confirmation for defense-in-depth. The server still derives the actual upstream user identity from person_id.',
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'Specific operational reason for this CIPP write.',
            ],
        ];

        $properties['ticket_id'] = [
            'type' => 'integer',
            'description' => $ticket
                ? 'Required ticket ID for cockpit-held actions. The server verifies it belongs to client_id.'
                : 'Optional ticket ID for incident attribution. The server verifies it belongs to client_id when supplied.',
        ];

        return $properties;
    }

    /** @return array<string, mixed> */
    private static function licenseProperties(): array
    {
        return [
            'license_type_id' => [
                'type' => 'integer',
                'description' => 'Local PSA license_types.id for a CIPP M365 SKU. The server derives the upstream SKU from synced license rows.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function stateProperties(): array
    {
        return [
            'state' => [
                'type' => 'string',
                'enum' => ['disabled', 'enabled', 'enforced'],
                'description' => 'Legacy per-user MFA state to set.',
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
    private static function disableSignInTool(): array
    {
        return self::tool(
            'cipp_disable_user_sign_in',
            'Disable Microsoft 365 sign-in for one server-derived CIPP user immediately. This blocks sign-in and can interrupt mail, Teams, and business app access. Requires an explicit token grant, reason, confirm_upn friction, kill-switch, dedup/cooldown, and TechnicianActionLog audit.',
            self::personProperties(),
            ['person_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageDisableSignInTool(): array
    {
        return self::tool(
            'cipp_stage_disable_user_sign_in',
            'Stage a Microsoft 365 sign-in disable for cockpit approval. The MCP call makes no CIPP upstream call; the execution payload is encrypted at rest and approval revalidates client, ticket, tenant, and person scope before execution.',
            self::personProperties(ticket: true),
            ['person_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function enableSignInTool(): array
    {
        return self::tool(
            'cipp_enable_user_sign_in',
            'Enable Microsoft 365 sign-in for one server-derived CIPP user immediately. This can restore account access. Requires an explicit token grant, reason, confirm_upn friction, kill-switch, dedup/cooldown, and TechnicianActionLog audit.',
            self::personProperties(),
            ['person_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageEnableSignInTool(): array
    {
        return self::tool(
            'cipp_stage_enable_user_sign_in',
            'Stage a Microsoft 365 sign-in enable for cockpit approval. The execution payload is encrypted at rest and approval revalidates server-derived CIPP scope before execution.',
            self::personProperties(ticket: true),
            ['person_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function revokeSessionsTool(): array
    {
        return self::tool(
            'cipp_revoke_user_sessions',
            'Revoke active Microsoft 365 sessions for one server-derived CIPP user immediately. This signs the user out of active sessions and may disrupt work. Requires an explicit token grant, reason, confirm_upn friction, kill-switch, dedup/cooldown, and audit.',
            self::personProperties(),
            ['person_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageRevokeSessionsTool(): array
    {
        return self::tool(
            'cipp_stage_revoke_user_sessions',
            'Stage Microsoft 365 session revocation for cockpit approval. The MCP call makes no CIPP upstream call; the held payload is encrypted at rest and revalidated on approval.',
            self::personProperties(ticket: true),
            ['person_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function removeMfaTool(): array
    {
        return self::tool(
            'cipp_remove_user_mfa_methods',
            'Remove MFA methods for one server-derived CIPP user immediately. This can weaken account protection until MFA is re-registered. Requires an explicit token grant, reason, confirm_upn friction, kill-switch, dedup/cooldown, and audit.',
            self::personProperties(),
            ['person_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageRemoveMfaTool(): array
    {
        return self::tool(
            'cipp_stage_remove_user_mfa_methods',
            'Stage MFA-method removal for cockpit approval. The MCP call makes no CIPP upstream call; the execution payload is encrypted at rest and approval revalidates server-derived CIPP user scope.',
            self::personProperties(ticket: true),
            ['person_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function setLegacyMfaTool(): array
    {
        return self::tool(
            'cipp_set_legacy_per_user_mfa',
            'Set legacy per-user MFA state for one server-derived CIPP user immediately. This changes authentication requirements and can lock out or weaken access. Requires explicit grant, reason, confirm_upn, kill-switch, dedup/cooldown, and audit.',
            array_merge(self::personProperties(), self::stateProperties()),
            ['person_id', 'confirm_upn', 'reason', 'state'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageSetLegacyMfaTool(): array
    {
        return self::tool(
            'cipp_stage_set_legacy_per_user_mfa',
            'Stage a legacy per-user MFA state change for cockpit approval. The MCP call makes no CIPP upstream call; the payload is encrypted at rest and approval revalidates local person and tenant mappings.',
            array_merge(self::personProperties(ticket: true), self::stateProperties()),
            ['person_id', 'ticket_id', 'confirm_upn', 'reason', 'state'],
        );
    }

    /** @return array<string, mixed> */
    private static function assignLicenseTool(): array
    {
        return self::tool(
            'cipp_assign_user_license',
            'Assign one local CIPP M365 license SKU to one server-derived user immediately. This can alter billing and app entitlements. Requires explicit grant, reason, confirm_upn, kill-switch, dedup/cooldown, and audit. Dial note: human-smoke-verify before first live grant; no replace-all or remove-all license body is supported.',
            array_merge(self::personProperties(), self::licenseProperties()),
            ['person_id', 'license_type_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageAssignLicenseTool(): array
    {
        return self::tool(
            'cipp_stage_assign_user_license',
            'Stage assignment of one local CIPP M365 license SKU for cockpit approval. This can alter billing and entitlements; the held payload is encrypted at rest and approval revalidates person, tenant, and SKU mappings.',
            array_merge(self::personProperties(ticket: true), self::licenseProperties()),
            ['person_id', 'license_type_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function removeLicenseTool(): array
    {
        return self::tool(
            'cipp_remove_user_license',
            'Remove one local CIPP M365 license SKU from one server-derived user immediately. This can remove Microsoft 365 app/service access and alter billing. Requires explicit grant, reason, confirm_upn, kill-switch, dedup/cooldown, and audit. No replace-all or remove-all license body is supported.',
            array_merge(self::personProperties(), self::licenseProperties()),
            ['person_id', 'license_type_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageRemoveLicenseTool(): array
    {
        return self::tool(
            'cipp_stage_remove_user_license',
            'Stage removal of one local CIPP M365 license SKU for cockpit approval. This can remove user access and alter billing; the held payload is encrypted at rest and approval revalidates mappings before execution.',
            array_merge(self::personProperties(ticket: true), self::licenseProperties()),
            ['person_id', 'license_type_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }
}
