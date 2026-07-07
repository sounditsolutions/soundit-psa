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
        'cipp_stage_convert_mailbox' => 'cipp_convert_mailbox',
        'cipp_stage_set_mailbox_forwarding' => 'cipp_set_mailbox_forwarding',
        'cipp_stage_set_mailbox_gal_visibility' => 'cipp_set_mailbox_gal_visibility',
        'cipp_stage_set_mailbox_out_of_office' => 'cipp_set_mailbox_out_of_office',
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
        'cipp_convert_mailbox' => 300,
        'cipp_stage_convert_mailbox' => 300,
        'cipp_set_mailbox_forwarding' => 300,
        'cipp_stage_set_mailbox_forwarding' => 300,
        'cipp_set_mailbox_gal_visibility' => 300,
        'cipp_stage_set_mailbox_gal_visibility' => 300,
        'cipp_set_mailbox_out_of_office' => 300,
        'cipp_stage_set_mailbox_out_of_office' => 300,
        'cipp_reset_user_password' => 300,
    ];

    private const OOO_MESSAGE_MAX = 2000;

    /** @var array<int, string> */
    private const MAILBOX_TYPES = ['Shared', 'Regular', 'Room', 'Equipment'];

    /** @var array<int, string> */
    private const DIRECT_FORWARDING_MODES = ['disabled', 'internal'];

    /** @var array<int, string> */
    private const STAGED_FORWARDING_MODES = ['disabled', 'internal', 'external'];

    /** @var array<int, string> */
    private const OOO_STATES = ['Disabled', 'Enabled', 'Scheduled'];

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
        'mailbox',
        'mailbox_id',
        'mailbox_identity',
        'MailboxType',
        'ForwardInternal',
        'ForwardExternal',
        'forwardOption',
        'KeepCopy',
        'HideFromGAL',
        'AutoReplyState',
        'StartTime',
        'EndTime',
        'target_upn',
        'target_user_id',
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
            self::convertMailboxTool(),
            self::stageConvertMailboxTool(),
            self::setMailboxForwardingTool(),
            self::stageSetMailboxForwardingTool(),
            self::setMailboxGalVisibilityTool(),
            self::stageSetMailboxGalVisibilityTool(),
            self::setMailboxOutOfOfficeTool(),
            self::stageSetMailboxOutOfOfficeTool(),
            self::resetUserPasswordTool(),
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

        if ($name === 'cipp_reset_user_password') {
            return $this->executeResetPassword($name, $arguments, $clientId, $actorLabel);
        }

        if (isset(self::STAGED_TO_DIRECT[$name])) {
            return $this->stageAction($name, $arguments, $clientId, $actorLabel);
        }

        return $this->executeDirect($name, $arguments, $clientId, $actorLabel);
    }

    public function approveStagedRun(TechnicianRun $run, int $approverId, array $approvalInputs = []): TechnicianApprovalResult
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
            $mailbox = $this->mailboxParamsForTool($directTool, $client->id, $params, $approvalInputs, heldApproval: true);

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
                $this->executeUpstream($directTool, $tenant, $person, $license, $state, $mailbox);
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
        $mailbox = is_array($context['mailbox'] ?? null) ? $context['mailbox'] : null;
        $reason = (string) $context['reason'];

        $contentHash = $this->contentHash($tool, $client->id, $person->person->id, $ticket?->id, $this->hashParams($tool, $license, $state, $mailbox));

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
            $this->executeUpstream($tool, $tenant, $person, $license, $state, $mailbox);
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

    /**
     * Dedicated direct path for the password reset — the only cipp_write tool that reads
     * back an upstream value (the temp password). Reuses every context() gate; skips the
     * idempotent alreadyExecuted() short-circuit (a password reset is NON-idempotent — a
     * second reset must generate a new password, not return a stale "already done"). A
     * cooldown still guards runaway repeats. The credential lives ONLY in the returned
     * result; auditAttempt() records the action + target UPN, never the password.
     *
     * @return array<string, mixed>
     */
    private function executeResetPassword(string $tool, array $arguments, int $clientId, string $actorLabel): array
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
        $reason = (string) $context['reason'];

        try {
            $mustChange = array_key_exists('must_change', $arguments)
                ? $this->booleanValue($arguments['must_change'], 'must_change')
                : true;
        } catch (CippWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $client->id, $ticket, $person, null, $this->contentHash($tool, $client->id, $person->person->id, $ticket?->id, []), $e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        $contentHash = $this->contentHash($tool, $client->id, $person->person->id, $ticket?->id, ['must_change' => $mustChange]);

        if ($this->cooldownActive($tool, $client->id, $person, null, self::COOLDOWNS[$tool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, $person, null, $contentHash, "{$tool} cooldown active; upstream call refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no reset was performed. Wait before retrying a password reset."];
        }

        try {
            $upstream = $this->client->resetUserPassword($tenant, $person->userPrincipalName, $mustChange);
        } catch (CippClientException $e) {
            $this->auditAttempt($tool, 'error', $client->id, $ticket, $person, null, $contentHash, $this->safeFailureSummary($tool, $e), $actorLabel);

            return ['error' => "CIPP password reset failed for {$tool}; no password was returned."];
        }

        // Audit records the action + target + the EFFECTIVE must_change flag (a boolean, not a
        // credential) so the immutable log distinguishes a temp reset from a permanent one. NO password.
        $mustChangeLabel = $mustChange ? 'true' : 'false';
        $this->auditAttempt($tool, 'executed', $client->id, $ticket, $person, null, $contentHash, "{$tool} executed (must_change={$mustChangeLabel}): {$reason}", $actorLabel);

        $results = is_array($upstream['body']['Results'] ?? null) ? $upstream['body']['Results'] : [];
        $password = (isset($results['copyField']) && is_string($results['copyField']) && $results['copyField'] !== '')
            ? $results['copyField']
            : null;
        $state = isset($results['state']) && is_string($results['state']) ? $results['state'] : null;

        if ($password === null) {
            return [
                'success' => true,
                'tool' => $tool,
                'person_id' => $person->person->id,
                'password_returned' => false,
                'message' => 'CIPP reported a successful reset but returned no password value. Verify in CIPP; if PwPush is configured the value may be delivered as a link instead.',
            ];
        }

        $adSynced = $state === 'warning';

        return [
            'success' => true,
            'tool' => $tool,
            'person_id' => $person->person->id,
            'user_principal_name' => $person->userPrincipalName,
            'temporary_password' => $password,
            'must_change_at_next_logon' => $mustChange,
            'ad_synced_warning' => $adSynced,
            'message' => 'Temporary password generated. Relay it to the user over a secure channel and instruct them to change it at first sign-in.'
                .($adSynced ? ' WARNING: this account appears to be directory-synced (AD-synced); a cloud password reset may not take effect if on-prem Active Directory is authoritative — verify with the on-prem/hybrid identity source.' : ''),
            'guidance' => 'If your CIPP instance has PwPush enabled, the temporary_password value may be a one-time secure link rather than the literal password.',
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
        $mailbox = is_array($context['mailbox'] ?? null) ? $context['mailbox'] : null;
        $reason = (string) $context['reason'];
        $directTool = self::STAGED_TO_DIRECT[$tool];
        $params = $this->hashParams($directTool, $license, $state, $mailbox);
        $contentHash = $this->contentHash($tool, $client->id, $person->person->id, $ticket->id, $params);

        // The audit log is IMMUTABLE and stays authoritative ONLY for "was this exact
        // content already executed" — an 'executed' row can never go stale the way an
        // 'awaiting_approval' row can (bd psa-k4s0 Root B).
        if ($this->alreadyExecuted($tool, $client->id, $contentHash)) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $this->executedRunId($tool, $client->id, $contentHash),
                'message' => 'Already executed identical action recently; no new proposal was staged.',
            ];
        }

        // "Still awaiting approval" is decided by the LIVE runs table ONLY, never the
        // audit log — a stale 'awaiting_approval' audit row survives supersede/deny by
        // design and can never be used to infer that a run is still live (bd psa-k4s0
        // Root B). Checked before the cooldown so a legitimate identical re-send is
        // reported idempotent rather than refused as a cooldown hit.
        $liveAwaitingRun = $this->liveAwaitingRun($ticket->id, $tool, $contentHash);
        if ($liveAwaitingRun !== null) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $liveAwaitingRun->id,
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
            'sensitive_inputs' => $this->sensitiveInputsForStagedAction($directTool, $params),
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => $directTool,
                'client_id' => $client->id,
                'person_id' => $person->person->id,
                'ticket_id' => $ticket->id,
                'params' => $params,
            ], JSON_THROW_ON_ERROR)),
        ];
        $proposedContent = $this->stagedDisplay($directTool, $person, $license, $state, $mailbox)."\nReason: ".$reason;

        // Keyed on the DB's own idempotency invariant (technician_runs_idempotency:
        // ticket_id + action_type + content_hash is UNIQUE) — a run with this EXACT
        // content either doesn't exist yet (create it) or exists but is no longer live
        // (superseded/denied, per the liveAwaitingRun() check above finding nothing), in
        // which case we revive THAT SAME row rather than attempt a second row with the
        // same key, which the DB would reject outright. firstOrCreate (rather than a bare
        // create()) also closes the TOCTOU gap against the liveAwaitingRun() check above.
        // Distinct content (e.g. forwarding for a different person) always gets its own
        // content_hash and therefore its own row — never colliding with, and never
        // superseding, an unrelated sibling (bd psa-k4s0 Root A).
        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => $tool,
                'content_hash' => $contentHash,
            ],
            [
                'client_id' => $client->id,
                'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ],
        );

        if (! $run->wasRecentlyCreated && $run->state !== TechnicianRunState::AwaitingApproval) {
            // Race winner: another request staged this exact content between the
            // liveAwaitingRun() check and this firstOrCreate() call. Never a false
            // idempotent dead end (bd psa-k4s0 Root B) — revive it as a fresh proposal.
            $run->update([
                'state' => TechnicianRunState::AwaitingApproval->value,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ]);
        } elseif (! $run->wasRecentlyCreated) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $run->id,
                'message' => 'Already staged; awaiting approval.',
            ];
        }

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
     * @return array{client?: Client, tenant?: string, person?: ResolvedCippPerson, ticket?: Ticket|null, license?: ResolvedCippLicense|null, state?: string|null, mailbox?: array<string, mixed>|null, reason?: string, error?: string}
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
        $reason = $this->safeReason($tool, $reason, $arguments);

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
            $mailbox = $this->mailboxParamsForTool($tool, $client->id, $arguments);
        } catch (CippWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $client->id, null, null, null, $contentHash, $e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        if ($error = $this->confirmUpnError($arguments, $person)) {
            $this->auditAttempt($tool, 'rejected', $client->id, $ticket, $person, $license, $this->contentHash($tool, $client->id, $person->person->id, $ticket?->id, $this->hashParams($tool, $license, $state, $mailbox)), $error, $actorLabel);

            return ['error' => $error];
        }

        return [
            'client' => $client,
            'tenant' => $tenant,
            'person' => $person,
            'ticket' => $ticket,
            'license' => $license,
            'state' => $state,
            'mailbox' => $mailbox,
            'reason' => $reason,
        ];
    }

    private function executeUpstream(string $tool, string $tenant, ResolvedCippPerson $person, ?ResolvedCippLicense $license, ?string $state, ?array $mailbox): void
    {
        match ($tool) {
            'cipp_disable_user_sign_in' => $this->client->setUserSignInState($tenant, $person->userId, false),
            'cipp_enable_user_sign_in' => $this->client->setUserSignInState($tenant, $person->userId, true),
            'cipp_revoke_user_sessions' => $this->client->revokeUserSessions($tenant, $person->userId, $person->userPrincipalName),
            'cipp_remove_user_mfa_methods' => $this->client->removeUserMfaMethods($tenant, $person->userPrincipalName),
            'cipp_set_legacy_per_user_mfa' => $this->client->setLegacyPerUserMfa($tenant, $person->userPrincipalName, $person->userId, (string) $state),
            'cipp_assign_user_license' => $this->client->assignUserLicense($tenant, $person->userId, (string) $license?->skuId),
            'cipp_remove_user_license' => $this->client->removeUserLicense($tenant, $person->userId, (string) $license?->skuId),
            'cipp_convert_mailbox' => $this->client->convertMailbox($tenant, $person->userPrincipalName, (string) ($mailbox['mailbox_type'] ?? '')),
            'cipp_set_mailbox_forwarding' => $this->executeMailboxForwarding($tenant, $person, $mailbox ?? []),
            'cipp_set_mailbox_gal_visibility' => $this->client->setMailboxGalVisibility($tenant, $person->userPrincipalName, (bool) ($mailbox['hidden'] ?? false)),
            'cipp_set_mailbox_out_of_office' => $this->client->setMailboxOutOfOffice(
                $tenant,
                $person->userPrincipalName,
                (string) ($mailbox['state'] ?? ''),
                $mailbox['internal_message'] ?? null,
                $mailbox['external_message'] ?? null,
                $mailbox['start_time'] ?? null,
                $mailbox['end_time'] ?? null,
                $mailbox['timezone'] ?? null,
            ),
            default => throw new \InvalidArgumentException("Unsupported CIPP write tool {$tool}"),
        };
    }

    private function executeMailboxForwarding(string $tenant, ResolvedCippPerson $person, array $mailbox): void
    {
        match ((string) ($mailbox['mode'] ?? '')) {
            'internal' => $this->client->setMailboxForwardingInternal(
                $tenant,
                $person->userPrincipalName,
                $mailbox['target_person'] instanceof ResolvedCippPerson ? $mailbox['target_person']->userPrincipalName : '',
                (bool) ($mailbox['keep_copy'] ?? false),
            ),
            'external' => $this->client->setMailboxForwardingExternal(
                $tenant,
                $person->userPrincipalName,
                (string) ($mailbox['external_smtp'] ?? ''),
                (bool) ($mailbox['keep_copy'] ?? false),
            ),
            'disabled' => $this->client->disableMailboxForwarding($tenant, $person->userPrincipalName),
            default => throw new \InvalidArgumentException('Unsupported mailbox forwarding mode'),
        };
    }

    /** @return array<string, mixed>|null */
    private function mailboxParamsForTool(string $tool, int $clientId, array $arguments, array $approvalInputs = [], bool $heldApproval = false): ?array
    {
        $directTool = self::STAGED_TO_DIRECT[$tool] ?? $tool;
        $isHeld = $heldApproval || array_key_exists($tool, self::STAGED_TO_DIRECT);

        return match ($directTool) {
            'cipp_convert_mailbox' => $this->convertMailboxParams($arguments),
            'cipp_set_mailbox_forwarding' => $this->mailboxForwardingParams($clientId, $arguments, $approvalInputs, $isHeld, $heldApproval),
            'cipp_set_mailbox_gal_visibility' => $this->mailboxGalParams($arguments),
            'cipp_set_mailbox_out_of_office' => $this->mailboxOutOfOfficeParams($arguments, $approvalInputs, $isHeld, $heldApproval),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function convertMailboxParams(array $arguments): array
    {
        return [
            'mailbox_type' => $this->canonicalChoice($this->requiredString($arguments, 'mailbox_type'), self::MAILBOX_TYPES, 'mailbox_type'),
        ];
    }

    /** @return array<string, mixed> */
    private function mailboxForwardingParams(int $clientId, array $arguments, array $approvalInputs, bool $isHeld, bool $heldApproval): array
    {
        $mode = mb_strtolower((string) $this->requiredString($arguments, 'mode'));
        if ($mode === '') {
            throw new CippWriteScopeException('mode is required');
        }

        $allowed = $isHeld ? self::STAGED_FORWARDING_MODES : self::DIRECT_FORWARDING_MODES;
        if (! in_array($mode, $allowed, true)) {
            if ($mode === 'external') {
                throw new CippWriteScopeException('External SMTP forwarding is held-only; use cipp_stage_set_mailbox_forwarding with ticket_id for cockpit approval.');
            }

            throw new CippWriteScopeException('mode must be one of: '.implode(', ', $allowed));
        }

        $params = [
            'mode' => $mode,
            'keep_copy' => $this->booleanValue($arguments['keep_copy'] ?? false, 'keep_copy'),
        ];

        if ($mode === 'internal') {
            $target = $this->resolver->resolveCippPerson($clientId, $arguments['target_person_id'] ?? null);
            $params['target_person_id'] = $target->person->id;
            $params['target_person'] = $target;
        }

        if ($mode === 'external') {
            $source = $heldApproval ? $approvalInputs : $arguments;
            $externalSmtp = $this->externalSmtpAddress($source['external_smtp'] ?? null);
            $domain = $this->domainFromEmail($externalSmtp);
            if ($heldApproval && isset($arguments['external_domain']) && strcasecmp((string) $arguments['external_domain'], $domain) !== 0) {
                throw new CippWriteScopeException('Approved external forwarding domain does not match the staged domain');
            }

            $params['external_domain'] = $domain;
            if ($heldApproval) {
                $params['external_smtp'] = $externalSmtp;
            }
        }

        return $params;
    }

    /** @return array<string, mixed> */
    private function mailboxGalParams(array $arguments): array
    {
        return [
            'hidden' => $this->booleanValue($arguments['hidden'] ?? null, 'hidden'),
        ];
    }

    /** @return array<string, mixed> */
    private function mailboxOutOfOfficeParams(array $arguments, array $approvalInputs, bool $isHeld, bool $heldApproval): array
    {
        $state = $this->canonicalChoice($this->requiredString($arguments, 'state'), self::OOO_STATES, 'state');
        $params = ['state' => $state];

        if ($state === 'Scheduled') {
            $params['start_time'] = $this->boundedString($arguments, 'start_time', 100, required: true);
            $params['end_time'] = $this->boundedString($arguments, 'end_time', 100, required: true);
        }

        $timezone = $this->boundedString($arguments, 'timezone', 100, required: false);
        if ($timezone !== null) {
            $params['timezone'] = $timezone;
        }

        if ($state === 'Disabled') {
            return $params;
        }

        $source = $heldApproval ? $approvalInputs : $arguments;
        $internalMessage = $this->boundedString($source, 'internal_message', self::OOO_MESSAGE_MAX, required: true);
        $externalMessage = $this->boundedString($source, 'external_message', self::OOO_MESSAGE_MAX, required: true);

        $params['internal_message_length'] = mb_strlen($internalMessage);
        $params['external_message_length'] = mb_strlen($externalMessage);

        if (! $isHeld || $heldApproval) {
            $params['internal_message'] = $internalMessage;
            $params['external_message'] = $externalMessage;
        }

        return $params;
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

    private function safeReason(string $tool, string $reason, array $arguments): string
    {
        $directTool = self::STAGED_TO_DIRECT[$tool] ?? $tool;
        $safe = $this->redactor->redactString($reason);

        if ($directTool === 'cipp_set_mailbox_forwarding') {
            if (isset($arguments['external_smtp']) && is_scalar($arguments['external_smtp'])) {
                $safe = str_replace((string) $arguments['external_smtp'], '[external address withheld]', $safe);
            }

            if (mb_strtolower((string) ($arguments['mode'] ?? '')) === 'external') {
                $safe = \App\Support\EmailRedactor::redact($safe);
            }
        }

        if ($directTool === 'cipp_set_mailbox_out_of_office') {
            foreach (['internal_message', 'external_message'] as $key) {
                if (isset($arguments[$key]) && is_scalar($arguments[$key])) {
                    $value = trim((string) $arguments[$key]);
                    if ($value !== '') {
                        $safe = str_replace($value, "[{$key} withheld]", $safe);
                    }
                }
            }
        }

        return $safe;
    }

    private function canonicalChoice(?string $value, array $allowed, string $field): string
    {
        if ($value === null) {
            throw new CippWriteScopeException("{$field} is required");
        }

        foreach ($allowed as $choice) {
            if (strcasecmp($value, $choice) === 0) {
                return $choice;
            }
        }

        throw new CippWriteScopeException("{$field} must be one of: ".implode(', ', $allowed));
    }

    private function booleanValue(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));
            if (in_array($normalized, ['true', '1'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', '0'], true)) {
                return false;
            }
        }

        throw new CippWriteScopeException("{$field} must be true or false");
    }

    private function boundedString(array $arguments, string $field, int $maxLength, bool $required): ?string
    {
        $value = $this->requiredString($arguments, $field);
        if ($value === null) {
            if ($required) {
                throw new CippWriteScopeException("{$field} is required");
            }

            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new CippWriteScopeException("{$field} must be {$maxLength} characters or fewer");
        }

        return $value;
    }

    private function externalSmtpAddress(mixed $value): string
    {
        if (! is_scalar($value)) {
            throw new CippWriteScopeException('external_smtp is required for external forwarding');
        }

        $email = trim((string) $value);
        if ($email === '' || mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new CippWriteScopeException('external_smtp must be a valid SMTP address');
        }

        return $email;
    }

    private function domainFromEmail(string $email): string
    {
        $domain = mb_strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '') {
            throw new CippWriteScopeException('external_smtp must include a domain');
        }

        return $domain;
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

    /** The run_id of the most recent matching EXECUTED audit row, if any (bd psa-k4s0: never surface idempotent:true with a null run_id). */
    private function executedRunId(string $tool, int $clientId, string $contentHash): ?int
    {
        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('client_id', $clientId)
            ->where('content_hash', $contentHash)
            ->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->latest('id')
            ->value('run_id');
    }

    /**
     * The single source of truth for "is there a live staged run awaiting approval right
     * now" — the runs table, NEVER the (immutable) audit log (bd psa-k4s0 Root B).
     */
    private function liveAwaitingRun(int $ticketId, string $tool, string $contentHash): ?TechnicianRun
    {
        return TechnicianRun::query()
            ->where('ticket_id', $ticketId)
            ->where('action_type', $tool)
            ->where('content_hash', $contentHash)
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->first();
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
    private function hashParams(string $tool, ?ResolvedCippLicense $license, ?string $state, ?array $mailbox): array
    {
        $params = [];
        if ($license !== null) {
            $params['license_type_id'] = $license->licenseType->id;
        }
        if ($state !== null) {
            $params['state'] = $state;
        }
        if ($mailbox !== null) {
            $params = array_merge($params, $this->safeMailboxParams($mailbox));
        }

        return $params;
    }

    /** @return array<string, mixed> */
    private function safeMailboxParams(array $mailbox): array
    {
        $safe = [];
        foreach ([
            'mailbox_type',
            'mode',
            'target_person_id',
            'keep_copy',
            'external_domain',
            'hidden',
            'state',
            'internal_message_length',
            'external_message_length',
            'start_time',
            'end_time',
            'timezone',
        ] as $key) {
            if (array_key_exists($key, $mailbox)) {
                $safe[$key] = $mailbox[$key];
            }
        }

        return $safe;
    }

    /** @return array<int, string> */
    private function sensitiveInputsForStagedAction(string $directTool, array $safeParams): array
    {
        $inputs = [];
        if ($directTool === 'cipp_set_mailbox_forwarding' && ($safeParams['mode'] ?? null) === 'external') {
            $inputs[] = 'external_smtp';
        }

        if ($directTool === 'cipp_set_mailbox_out_of_office' && in_array($safeParams['state'] ?? null, ['Enabled', 'Scheduled'], true)) {
            $inputs[] = 'internal_message';
            $inputs[] = 'external_message';
        }

        return $inputs;
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

    private function stagedDisplay(string $directTool, ResolvedCippPerson $person, ?ResolvedCippLicense $license, ?string $state, ?array $mailbox): string
    {
        return match ($directTool) {
            'cipp_disable_user_sign_in' => 'Disable sign-in for PSA person #'.$person->person->id.'.',
            'cipp_enable_user_sign_in' => 'Enable sign-in for PSA person #'.$person->person->id.'.',
            'cipp_revoke_user_sessions' => 'Revoke active sessions for PSA person #'.$person->person->id.'.',
            'cipp_remove_user_mfa_methods' => 'Remove MFA methods for PSA person #'.$person->person->id.'.',
            'cipp_set_legacy_per_user_mfa' => 'Set legacy per-user MFA to '.$state.' for PSA person #'.$person->person->id.'.',
            'cipp_assign_user_license' => 'Assign license_type #'.$license?->licenseType->id.' to PSA person #'.$person->person->id.'.',
            'cipp_remove_user_license' => 'Remove license_type #'.$license?->licenseType->id.' from PSA person #'.$person->person->id.'.',
            'cipp_convert_mailbox' => 'Convert mailbox for PSA person #'.$person->person->id.' to '.($mailbox['mailbox_type'] ?? 'unknown').'. Shared mailbox conversion can change licensing obligations.',
            'cipp_set_mailbox_forwarding' => $this->mailboxForwardingDisplay($person, $mailbox ?? []),
            'cipp_set_mailbox_gal_visibility' => 'Set GAL visibility for PSA person #'.$person->person->id.' to '.((bool) ($mailbox['hidden'] ?? false) ? 'hidden' : 'visible').'.',
            'cipp_set_mailbox_out_of_office' => $this->mailboxOutOfOfficeDisplay($person, $mailbox ?? []),
            default => $directTool.' for PSA person #'.$person->person->id.'.',
        };
    }

    private function mailboxForwardingDisplay(ResolvedCippPerson $person, array $mailbox): string
    {
        return match ((string) ($mailbox['mode'] ?? '')) {
            'disabled' => 'Disable mailbox forwarding for PSA person #'.$person->person->id.'.',
            'internal' => 'Set mailbox forwarding for PSA person #'.$person->person->id.' to PSA target person #'.($mailbox['target_person_id'] ?? 'unknown').' (keep copy '.((bool) ($mailbox['keep_copy'] ?? false) ? 'true' : 'false').').',
            'external' => 'Set external SMTP mailbox forwarding for PSA person #'.$person->person->id.' to domain '.($mailbox['external_domain'] ?? 'unknown').' (full address re-entered at approval; keep copy '.((bool) ($mailbox['keep_copy'] ?? false) ? 'true' : 'false').').',
            default => 'Set mailbox forwarding for PSA person #'.$person->person->id.'.',
        };
    }

    private function mailboxOutOfOfficeDisplay(ResolvedCippPerson $person, array $mailbox): string
    {
        $display = 'Set mailbox out-of-office for PSA person #'.$person->person->id.' to '.($mailbox['state'] ?? 'unknown').'.';
        if (isset($mailbox['internal_message_length'], $mailbox['external_message_length'])) {
            $display .= ' internal_message_length='.$mailbox['internal_message_length'].'; external_message_length='.$mailbox['external_message_length'].'.';
        }
        if (($mailbox['state'] ?? null) === 'Scheduled') {
            $display .= ' start='.$mailbox['start_time'].'; end='.$mailbox['end_time'].'.';
        }

        return $display;
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
    private static function mailboxTypeProperties(): array
    {
        return [
            'mailbox_type' => [
                'type' => 'string',
                'enum' => self::MAILBOX_TYPES,
                'description' => 'Mailbox recipient type to set through the curated CIPP ExecConvertMailbox wrapper.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function forwardingProperties(bool $stage): array
    {
        $properties = [
            'mode' => [
                'type' => 'string',
                'enum' => $stage ? self::STAGED_FORWARDING_MODES : self::DIRECT_FORWARDING_MODES,
                'description' => $stage
                    ? 'Forwarding mode. External SMTP forwarding is staged only and requires approval.'
                    : 'Forwarding mode. Direct execution supports only disabled or internal.',
            ],
            'target_person_id' => [
                'type' => 'integer',
                'description' => 'Required when mode=internal. Local PSA person ID in the same client; the server derives the internal forwarding target UPN.',
            ],
            'keep_copy' => [
                'type' => 'boolean',
                'description' => 'Whether Exchange should also keep delivered mail in the source mailbox when forwarding is enabled.',
            ],
        ];

        if ($stage) {
            $properties['external_smtp'] = [
                'type' => 'string',
                'description' => 'Required when mode=external. Validated for the proposal, reduced to domain for storage/audit, then re-entered by the approver before execution.',
            ];
        }

        return $properties;
    }

    /** @return array<string, mixed> */
    private static function galVisibilityProperties(): array
    {
        return [
            'hidden' => [
                'type' => 'boolean',
                'description' => 'true hides the mailbox from the Global Address List; false makes it visible.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function resetUserPasswordProperties(): array
    {
        return [
            'must_change' => [
                'type' => 'boolean',
                'description' => 'Whether the user must change the password at next sign-in. Defaults to true (the temporary-password method). Set false only for a deliberate permanent reset.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function outOfOfficeProperties(): array
    {
        return [
            'state' => [
                'type' => 'string',
                'enum' => self::OOO_STATES,
                'description' => 'Out-of-office auto-reply state.',
            ],
            'internal_message' => [
                'type' => 'string',
                'description' => 'Required for Enabled or Scheduled. Max 2000 characters; body is sent to CIPP but only length is audited.',
            ],
            'external_message' => [
                'type' => 'string',
                'description' => 'Required for Enabled or Scheduled. Max 2000 characters; body is sent to CIPP but only length is audited.',
            ],
            'start_time' => [
                'type' => 'string',
                'description' => 'Required for Scheduled. ISO-like datetime or source-compatible timestamp string.',
            ],
            'end_time' => [
                'type' => 'string',
                'description' => 'Required for Scheduled. ISO-like datetime or source-compatible timestamp string.',
            ],
            'timezone' => [
                'type' => 'string',
                'description' => 'Optional Exchange timezone identifier.',
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

    /** @return array<string, mixed> */
    private static function convertMailboxTool(): array
    {
        return self::tool(
            'cipp_convert_mailbox',
            'Convert a server-derived Microsoft 365 mailbox immediately through CIPP. Shared mailbox conversion can change licensing obligations and mailbox behavior. Requires explicit grant, reason, confirm_upn, kill-switch, dedup/cooldown, and audit.',
            array_merge(self::personProperties(), self::mailboxTypeProperties()),
            ['person_id', 'mailbox_type', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageConvertMailboxTool(): array
    {
        return self::tool(
            'cipp_stage_convert_mailbox',
            'Stage a Microsoft 365 mailbox conversion for cockpit approval. Shared mailbox conversion can change licensing obligations; the held payload stores only local identifiers and safe parameters, then approval revalidates CIPP scope.',
            array_merge(self::personProperties(ticket: true), self::mailboxTypeProperties()),
            ['person_id', 'mailbox_type', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function setMailboxForwardingTool(): array
    {
        return self::tool(
            'cipp_set_mailbox_forwarding',
            'Set mailbox forwarding immediately through CIPP for one server-derived user. Direct execution supports internal forwarding or disabling only. External SMTP forwarding is held-only because it can create BEC and data-exfiltration risk. Requires explicit grant, reason, confirm_upn, kill-switch, cooldown, and audit.',
            array_merge(self::personProperties(), self::forwardingProperties(stage: false)),
            ['person_id', 'mode', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageSetMailboxForwardingTool(): array
    {
        return self::tool(
            'cipp_stage_set_mailbox_forwarding',
            'Stage mailbox forwarding for cockpit approval. External SMTP forwarding carries BEC and data-exfiltration risk; the external address is re-entered at approval and is not stored, while audit keeps only target type/domain. Approval revalidates local client/person scope before CIPP execution.',
            array_merge(self::personProperties(ticket: true), self::forwardingProperties(stage: true)),
            ['person_id', 'mode', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function setMailboxGalVisibilityTool(): array
    {
        return self::tool(
            'cipp_set_mailbox_gal_visibility',
            'Set Global Address List visibility immediately for one server-derived mailbox. Hiding a mailbox can affect discoverability for staff. Requires explicit grant, reason, confirm_upn, kill-switch, cooldown, and audit.',
            array_merge(self::personProperties(), self::galVisibilityProperties()),
            ['person_id', 'hidden', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageSetMailboxGalVisibilityTool(): array
    {
        return self::tool(
            'cipp_stage_set_mailbox_gal_visibility',
            'Stage a Global Address List visibility change for cockpit approval. The MCP call makes no CIPP upstream call; approval revalidates local client/person scope before execution.',
            array_merge(self::personProperties(ticket: true), self::galVisibilityProperties()),
            ['person_id', 'hidden', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function setMailboxOutOfOfficeTool(): array
    {
        return self::tool(
            'cipp_set_mailbox_out_of_office',
            'Set mailbox out-of-office state/messages/schedule immediately through CIPP. Calendar-decline options are not supported in v1. Message bodies are sent upstream but never stored or returned; audit records message lengths only. Requires explicit grant, reason, confirm_upn, kill-switch, cooldown, and audit.',
            array_merge(self::personProperties(), self::outOfOfficeProperties()),
            ['person_id', 'state', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageSetMailboxOutOfOfficeTool(): array
    {
        return self::tool(
            'cipp_stage_set_mailbox_out_of_office',
            'Stage mailbox out-of-office state/messages/schedule for cockpit approval. Message bodies are re-entered at approval and are not stored; the proposal stores message lengths only plus safe schedule metadata.',
            array_merge(self::personProperties(ticket: true), self::outOfOfficeProperties()),
            ['person_id', 'state', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function resetUserPasswordTool(): array
    {
        return self::tool(
            'cipp_reset_user_password',
            'Reset the Microsoft 365 password for one server-derived CIPP user and return a newly generated temporary password. The password is generated by CIPP/Microsoft and returned only in this tool result — it is never written to any log or audit record. Defaults to must-change-at-next-sign-in. Relay the password to the user over a secure channel and have them change it at first sign-in. Requires an explicit token grant, reason, confirm_upn friction, kill-switch, cooldown, and TechnicianActionLog audit. Consequential: performs a live credential reset immediately.',
            array_merge(self::personProperties(), self::resetUserPasswordProperties()),
            ['person_id', 'confirm_upn', 'reason'],
        );
    }
}
