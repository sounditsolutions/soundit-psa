<?php

namespace App\Services\Mcp;

use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use App\Models\McpToken;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDClientException;
use App\Services\ControlD\ControlDOnboardingStaged;
use App\Services\ControlD\ControlDProvisioning;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Services\Technician\PromptFence;
use App\Services\Technician\TechnicianApprovalResult;
use App\Support\ControlDConfig;
use App\Support\McpConfig;
use App\Support\McpStaffToken;
use App\Support\TechnicianConfig;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Control D client onboarding — the B4 caller for the dark B1–B3 services, in the
 * shape `tactical_set_client_custom_field` (#1277) already has: ONE staged verb
 * `controld_onboard_client`, held for cockpit approval by an active Admin (a second one
 * on the button lane; see TWO LANES below),
 * idempotent, audited, secret-free. Plus the client-page button (ClientControlD
 * OnboardingController), which stages the SAME proposal through stageForClient().
 *
 * HELD-ONLY BY CONSTRUCTION. The canonical name has no immediate implementation:
 * execute() answers it with a refusal, McpToolModes lists it HELD_ONLY, and the
 * `:immediate` grant is rejected at parse time. Approval is the only path to
 * ControlDOnboardingStaged, and approval happens in the cockpit under a logged-in
 * Admin who is NOT the stager.
 *
 * TWO LANES, ONE TWO-PERSON RULE (B4.1, #2043). The button lane is staged by a
 * logged-in Admin whose id is recorded, so approval refuses that same person. The
 * token lane is staged by an MCP token that stands for no person, so a human's
 * bearer could otherwise stage and approve alone. Hence: the verb may be staged ONLY
 * by a token whose `ai_actor` flag is true (the agent stages, one active Admin
 * approves — the family contract); a non-ai_actor token is refused at staging and
 * pointed at the client-page button, which records who. The staging token's id is
 * bound into the encrypted payload, and approval re-reads that token row and refuses
 * unless it still exists, is still live under the gate authentication applies (activated,
 * not paused, not revoked), is still an ai_actor, and is still granted this verb — every
 * documented withdrawal closes the lane. No token id, or an id that names no row, is
 * refused: unknown is never trusted.
 *
 * TWO STEPS, TWO APPROVALS, NEVER BOTH IN ONE. A client without `controld_org_id`
 * proposes the `organization` step (create the sub-organization, bind the mapping);
 * a mapped client with no stored code proposes the `code` step (cut the provisioning
 * code under that org). Each proposal names exactly one step at staging time and
 * re-derives it at approval; if the client's state moved in between, approval
 * refuses rather than doing the other step under a card nobody read.
 *
 * INPUTS ARE THE CLIENT RECORD AND THE PANEL ONLY. The verb takes client_id (scope),
 * ticket_id (the cockpit lane) and reason. Organization name = the client's name,
 * contact email = the client's own email, MFA required = 1 (the standing brief),
 * analytics region = the panel's auto-detected stats endpoint. The code carries the
 * panel's six defaults; icon is the class constant CODE_ICON; PIN and hostname prefix
 * are null — deferred out of B4 by ruling, not accepted from callers (any such key,
 * or any vendor PK, is refused by name). No secret ever reaches an argument, the
 * proposal card, the audit row or the tool result: the code and PIN are written to
 * the client's encrypted columns by B1/B3 and are never read back here.
 *
 * INERT UNLESS ControlDConfig::isOnboardingActive(): the explicit default-off
 * `controld_onboarding_enabled` toggle AND the six configured defaults. Checked at
 * staging and again at approval; the tools/list publication (McpToolSurface) gates
 * on the same predicate so publish and dispatch answer one question.
 */
class StaffControlDOnboardingToolExecutor
{
    public const TOOL = 'controld_onboard_client';

    public const STAGED_TOOL = 'controld_stage_onboard_client';

    /** The device icon every onboarding code is cut with (19:30 PT ruling; validated by A's preflight against /devices/types). */
    public const CODE_ICON = 'desktop-windows';

    /** Sub-organizations are created with two-factor required (standing brief, step 2). */
    public const REQUIRE_MFA = 1;

    private const COOLDOWN_SECONDS = 0;

    private const DIRECT_DEDUP_HOURS = 24;

    private const REASON_MAX = 500;

    /** @var array<string, string> */
    private const STAGED_TO_DIRECT = [
        self::STAGED_TOOL => self::TOOL,
    ];

    /** The ONLY argument keys a call may carry; everything else refuses by name. */
    private const ALLOWED_ARGUMENT_KEYS = ['ticket_id', 'reason', 'staged'];

    /** Named in the refusal so the caller learns the rule: these are never inputs. */
    private const KNOWN_REFUSED_KEYS = ['pin', 'deactivation_pin', 'name_prefix', 'hostname_prefix', 'icon', 'org_pk', 'controld_org_id', 'profile_id', 'code', 'max', 'ts_exp', 'stats', 'intercept_mode', 'name', 'contact_email'];

    public const STEP_ORGANIZATION = 'organization';

    public const STEP_CODE = 'code';

    public function __construct(
        private readonly ActionRedactor $redactor,
        private readonly PromptFence $fence,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [self::onboardTool(), self::stageOnboardTool()];
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

    /** @return array<string, string> */
    public static function stagedToDirectMap(): array
    {
        return self::STAGED_TO_DIRECT;
    }

    /**
     * @param  McpStaffToken|null  $staffToken  The authenticated caller. Staging needs an `ai_actor`
     *                                          token (B4.1); null (legacy / unknown caller) is refused.
     * @return array<string, mixed>
     */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel, ?McpStaffToken $staffToken = null): array
    {
        if (! ControlDConfig::isEnabled() || ! ControlDConfig::isConfigured()) {
            return ['error' => 'Control D is not configured'];
        }

        if (! ControlDConfig::isOnboardingActive()) {
            return ['error' => 'Control D client onboarding is not enabled on this instance (Settings > Integrations > Control D: all six Client Onboarding Defaults and the Client onboarding switch). Nothing was staged.'];
        }

        if ($name === self::STAGED_TOOL) {
            return $this->stageOnboarding($arguments, $clientId, $actorLabel, $staffToken);
        }

        if ($name === self::TOOL) {
            $contentHash = $this->contentHash(self::TOOL, $clientId, null, $arguments);
            $message = self::TOOL.' is held-only — creating a vendor organization or a provisioning code is never executed immediately, whatever mode was granted; call it with staged=true and a ticket_id for cockpit approval by an active Admin. Nothing was created.';
            $this->auditAttempt(self::TOOL, 'rejected', $clientId, null, $contentHash, $message, $actorLabel);

            return ['error' => $message];
        }

        return ['error' => "Unknown Control D onboarding tool: {$name}"];
    }

    // ── staging (MCP verb) ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function stageOnboarding(array $arguments, int $clientId, string $actorLabel, ?McpStaffToken $staffToken): array
    {
        $tool = self::STAGED_TOOL;
        $contentHash = $this->contentHash($tool, $clientId, null, $arguments);

        // B4.1: only an ai_actor token stages this verb. A human's bearer stands for a
        // person the payload cannot name, so the second-Admin rule could not bind;
        // the client-page button is that person's lane. Refused before any argument
        // is read, audited under the token's label (never the token itself).
        if (! $staffToken instanceof McpStaffToken || $staffToken->id === null || ! $staffToken->aiActor) {
            $message = self::TOOL.' may be staged only by an MCP token marked ai_actor (the agent stages, one active Admin approves). This token is not an ai_actor token: a person onboards a client from the Control D card on the client page, which records who staged it for the second-Admin rule. Nothing was staged.';
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, 'staging refused — caller is not an ai_actor token (B4.1); use the client-page button.', $actorLabel);

            return ['error' => $message];
        }

        if ($unexpected = $this->unexpectedArgumentKeys($arguments)) {
            $known = array_values(array_intersect($unexpected, self::KNOWN_REFUSED_KEYS));
            $message = 'Unexpected parameter'.(count($unexpected) === 1 ? '' : 's').' refused: '.implode(', ', $unexpected).'. '
                .'Onboarding inputs come from the client record and the Control D panel only'
                .($known !== [] ? ' — PIN, hostname prefix, icon, profile, limits and vendor PKs are never caller inputs' : '')
                .'. Pass ticket_id and reason only. Nothing was staged.';
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, $message, $actorLabel);

            return ['error' => $message];
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, 'reason is required.', $actorLabel);

            return ['error' => 'reason is required'];
        }
        $reason = mb_substr($reason, 0, self::REASON_MAX);

        if (TechnicianConfig::killSwitchEngaged()) {
            $this->auditAttempt($tool, 'blocked', $clientId, null, $contentHash, 'Technician kill-switch engaged; Control D onboarding refused.', $actorLabel);

            return ['error' => 'Technician kill-switch engaged; Control D onboarding refused'];
        }

        $client = Client::find($clientId);
        if (! $client) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, 'Client not found.', $actorLabel);

            return ['error' => 'Client not found'];
        }

        $ticketId = $this->positiveInt($arguments['ticket_id'] ?? null);
        $ticket = $ticketId !== null ? Ticket::automationVisible()->find($ticketId) : null;
        if (! $ticket || (int) $ticket->client_id !== $clientId) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, 'ticket_id is required and must belong to this client.', $actorLabel);

            return ['error' => 'ticket_id is required for staged Control D onboarding and must belong to this client'];
        }

        return $this->stageProposal($client, $ticket, $reason, $actorLabel, $tool, null, (int) $staffToken->id);
    }

    /**
     * The client-page button: same proposal, same card, same approval — staged by a
     * logged-in Admin instead of an MCP token. The stager's id is recorded on the run
     * so approval can refuse the same person approving their own proposal.
     *
     * @return array<string, mixed>
     */
    public function stageForClient(Client $client, Ticket $ticket, string $reason, User $stager): array
    {
        if (! ControlDConfig::isEnabled() || ! ControlDConfig::isConfigured() || ! ControlDConfig::isOnboardingActive()) {
            return ['error' => 'Control D client onboarding is not enabled on this instance.'];
        }
        if (! $stager->exists || ! $stager->is_active || ! $stager->isAdmin()) {
            return ['error' => 'Only an active Admin can stage Control D onboarding.'];
        }
        if ((int) $ticket->client_id !== (int) $client->id) {
            return ['error' => 'Ticket does not belong to this client.'];
        }
        $reason = mb_substr(trim($reason), 0, self::REASON_MAX);
        if ($reason === '') {
            return ['error' => 'reason is required'];
        }
        if (TechnicianConfig::killSwitchEngaged()) {
            return ['error' => 'Technician kill-switch engaged; Control D onboarding refused'];
        }

        return $this->stageProposal($client, $ticket, $reason, 'staff:'.$stager->id, self::STAGED_TOOL, (int) $stager->id, null);
    }

    // ── step derivation ─────────────────────────────────────────────────────────

    /**
     * Which step this client needs next, or an error. Exactly one step per proposal.
     *
     * @return array{step?: string, error?: string}
     */
    private function nextStep(Client $client): array
    {
        $client = Client::withTrashed()->find($client->id) ?? $client;
        if ($client->trashed()) {
            return ['error' => 'Client is deleted; onboarding refused.'];
        }
        $org = $client->controld_org_id;
        if ($org === null || trim((string) $org) === '') {
            if (ControlDOnboardingIntent::where('client_id', $client->id)->whereIn('state', ['staged', 'posted', 'uncertain'])->exists()) {
                return ['error' => 'A Control D onboarding intent already owns this client (staged, posted or uncertain); it must be reconciled before another organization step is proposed. Nothing was staged.'];
            }

            return ['step' => self::STEP_ORGANIZATION];
        }
        if ($client->getRawOriginal('controld_provisioning_code') !== null || $client->getRawOriginal('controld_deactivation_pin') !== null) {
            return ['error' => 'This client is already onboarded: it is mapped to a Control D organization and carries a provisioning code. Re-cutting a code is a separate, explicit verb; nothing was staged.'];
        }
        if (ControlDOnboardingIntent::where('client_id', $client->id)->whereIn('state', ['staged', 'posted', 'uncertain'])->exists()) {
            return ['error' => 'A Control D onboarding intent already owns this client (staged, posted or uncertain); it must be reconciled before the code step is proposed. Nothing was staged.'];
        }

        return ['step' => self::STEP_CODE];
    }

    /** @return array{name?: string, contact_email?: string, stats_endpoint?: string, error?: string} */
    private function organizationInputs(Client $client): array
    {
        $name = trim((string) $client->name);
        if ($name === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            return ['error' => 'The client name is empty, too long or contains control characters; fix the client record before onboarding.'];
        }
        $email = trim((string) $client->email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'The client record has no valid contact email; Control D requires one for the sub-organization. Fix the client record before onboarding.'];
        }
        $stats = (string) (ControlDConfig::get('stats_endpoint') ?? '');
        if (! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $stats)) {
            return ['error' => 'The Control D analytics region (stats endpoint) is not detected on this instance; run Test Connection on the Control D panel first.'];
        }

        return ['name' => $name, 'contact_email' => $email, 'stats_endpoint' => $stats];
    }

    // ── proposal ────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    /**
     * Exactly one of $stagerUserId (button lane: the Admin who staged) and
     * $stagerTokenId (token lane: the ai_actor token that staged) is set; approval
     * applies the lane's half of the two-person rule from whichever is present.
     */
    private function stageProposal(Client $client, Ticket $ticket, string $reason, string $actorLabel, string $tool, ?int $stagerUserId, ?int $stagerTokenId): array
    {
        $clientId = (int) $client->id;
        $derived = $this->nextStep($client);
        $contentHash = $this->contentHash($tool, $clientId, $ticket->id, ['step' => $derived['step'] ?? 'none']);
        if (isset($derived['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $contentHash, $derived['error'], $actorLabel);

            return ['error' => $derived['error']];
        }
        $step = $derived['step'];
        $targetKey = "onboard:{$clientId}:{$step}";

        $inputs = $step === self::STEP_ORGANIZATION ? $this->organizationInputs($client) : [];
        if (isset($inputs['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $contentHash, "{$targetKey}: ".$inputs['error'], $actorLabel);

            return ['error' => $inputs['error']];
        }
        if ($step === self::STEP_CODE && ! ControlDConfig::isOnboardingConfigured()) {
            $message = 'The six Control D onboarding defaults are not all configured; nothing was staged.';
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $contentHash, "{$targetKey}: {$message}", $actorLabel);

            return ['error' => $message];
        }

        if ($this->alreadyExecuted($tool, $clientId, $contentHash)) {
            return ['success' => true, 'idempotent' => true, 'step' => $step, 'ticket_id' => $ticket->id, 'ticket_display_id' => $ticket->display_id,
                'run_id' => $this->executedRunId($tool, $clientId, $contentHash), 'message' => 'This onboarding step already executed recently; no new proposal was staged.'];
        }

        $live = $this->liveAwaitingRun($ticket->id, $tool, $contentHash);
        if ($live !== null) {
            return ['success' => true, 'idempotent' => true, 'step' => $step, 'ticket_id' => $ticket->id, 'ticket_display_id' => $ticket->display_id,
                'run_id' => $live->id, 'message' => 'Already staged; awaiting approval.'];
        }

        if ($this->cooldownActive([$tool, self::TOOL], $clientId, $targetKey, self::COOLDOWN_SECONDS, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', $clientId, $ticket, $contentHash, "{$targetKey}: cooldown active; staged proposal refused.", $actorLabel);

            return ['error' => self::TOOL.' cooldown active for this client; no proposal was staged.'];
        }

        $proposedContent = $this->proposedContent($client, $step, $inputs)."\n".$this->fence->fence('AGENT SUPPLIED REASON', $reason);
        $meta = [
            'drafted_by' => $actorLabel,
            'reasons' => [$reason],
            'direct_tool' => self::TOOL,
            'redacted_params' => ['step' => $step, 'client_name' => (string) $client->name],
            'sensitive_inputs' => [],
            'staged_by_user_id' => $stagerUserId,
            'staged_by_token_id' => $stagerTokenId,
            // Only ids and the step: approval re-derives every input from the client
            // record and the panel, so nothing here is a value that could be replayed.
            // The stager (user id OR token id) is read from THIS sealed copy at approval.
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => self::TOOL, 'client_id' => $clientId, 'ticket_id' => $ticket->id, 'step' => $step,
                'staged_by_user_id' => $stagerUserId, 'staged_by_token_id' => $stagerTokenId,
            ], JSON_THROW_ON_ERROR)),
        ];

        $run = TechnicianRun::firstOrCreate(
            ['ticket_id' => $ticket->id, 'action_type' => $tool, 'content_hash' => $contentHash],
            ['client_id' => $clientId, 'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => $proposedContent,
                'proposed_meta' => $meta, 'confidence' => null, 'tokens_used' => 0],
        );

        if (! $run->wasRecentlyCreated) {
            // A live proposal is never rewritten under its own id (the #1277 rule), and
            // a claim held by an in-flight approval is left exactly as it is. This
            // family is excluded from the stale-claim reaper, and a stranded claim is
            // answered as executing rather than revived: the intent row on the B3 side
            // is the durable truth of whether a vendor write may have happened.
            if ($run->state === TechnicianRunState::AwaitingApproval) {
                return ['success' => true, 'idempotent' => true, 'step' => $step, 'ticket_id' => $ticket->id, 'ticket_display_id' => $ticket->display_id,
                    'run_id' => $run->id, 'message' => 'Already staged; awaiting approval.'];
            }
            if ($run->state === TechnicianRunState::Executing) {
                return ['success' => true, 'idempotent' => true, 'step' => $step, 'ticket_id' => $ticket->id, 'ticket_display_id' => $ticket->display_id,
                    'run_id' => $run->id, 'message' => 'An approved onboarding step for this client is currently executing; nothing new was staged.'];
            }
            $run->update(['state' => TechnicianRunState::AwaitingApproval->value, 'proposed_content' => $proposedContent,
                'proposed_meta' => $meta, 'confidence' => null, 'tokens_used' => 0]);
        }

        $this->auditAttempt($tool, 'awaiting_approval', $clientId, $ticket, $contentHash, "{$targetKey}: staged Control D onboarding step '{$step}': {$reason}", $actorLabel, $run->id);

        return ['success' => true, 'step' => $step, 'ticket_id' => $ticket->id, 'ticket_display_id' => $ticket->display_id, 'run_id' => $run->id, 'message' => 'Staged for cockpit approval.'];
    }

    /** @param  array<string, string>  $inputs */
    private function proposedContent(Client $client, string $step, array $inputs): string
    {
        $name = $this->fence->neutralizeUntrusted((string) $client->name);
        if ($step === self::STEP_ORGANIZATION) {
            return "Control D onboarding — step 1 of 2 (organization) for client '{$name}' (#{$client->id}).\n"
                ."Creates a Control D sub-organization named after the client, contact email from the client record ({$this->fence->neutralizeUntrusted($inputs['contact_email'])}), "
                .'two-factor required, analytics region '.$inputs['stats_endpoint'].", then binds the returned organization id to the client.\n"
                .'No provisioning code is cut by this step; once the mapping is bound, stage the verb again for step 2 (code).';
        }

        return "Control D onboarding — step 2 of 2 (provisioning code) for client '{$name}' (#{$client->id}), organization ".$this->fence->neutralizeUntrusted((string) $client->controld_org_id).".\n"
            .'Cuts one provisioning code under that organization with the panel defaults: enforced profile '.ControlDConfig::defaultProfileId()
            .', expiry '.ControlDConfig::codeExpiryDays().' days, device limit = asset count ('.$client->assets()->count().') + headroom '.ControlDConfig::codeDeviceLimitHeadroom()
            .', analytics level '.ControlDConfig::codeAnalyticsLevel().', intercept mode '.ControlDConfig::codeInterceptMode().', icon '.self::CODE_ICON.".\n"
            .'No deactivation PIN and no hostname prefix (deferred). The code is stored encrypted on the client record and is never shown here, in the audit log or in the tool result.';
    }

    // ── approval ────────────────────────────────────────────────────────────────

    /**
     * Cockpit approval: the ONLY path to ControlDOnboardingStaged. The approver must
     * be an active Admin and, on the button lane, must not be the stager (a second
     * Admin, by ruling); on the token lane the stager must be a live ai_actor token. The
     * step is re-derived from the client's live state and must equal the staged step.
     * Then exactly one intent: stageOrganization + execute, or stageCode + execute.
     *
     * OUTCOMES. B3 records the truth on the intent row: `bound` = done; `rejected` =
     * the vendor refused (read-only key, envelope error) — definite, nothing created,
     * the run is spent and the operator may stage again after fixing the cause;
     * `uncertain` = a POST may have landed and the intent holds the client's lock —
     * TERMINAL here, executed_with_fault, never re-armed (a retry would be a second
     * POST). A definite local refusal thrown BEFORE admission (B3 leaves the intent
     * `staged`) releases the run back to AwaitingApproval: nothing vendor-facing
     * happened — but the staged intent still holds the per-client lock, so the
     * operator-facing message says so and names the intent id, not a secret.
     */
    public function approveStagedRun(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        if (! self::isStagedActionType($run->action_type) || ! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        $intentId = null;
        try {
            $payload = $this->decryptRunPayload($run);
            if ($payload === null || (self::STAGED_TO_DIRECT[$run->action_type] ?? null) !== (string) ($payload['direct_tool'] ?? '')) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined', message: 'The held payload could not be read — deny this proposal and re-stage it.');
            }

            $client = Client::find((int) ($payload['client_id'] ?? 0));
            $ticket = Ticket::automationVisible()->find((int) ($payload['ticket_id'] ?? 0));
            $step = (string) ($payload['step'] ?? '');
            if (! $client || (int) $client->id !== (int) $run->client_id || ! $ticket || (int) $ticket->client_id !== (int) $client->id
                || ! in_array($step, [self::STEP_ORGANIZATION, self::STEP_CODE], true)) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $targetKey = "onboard:{$client->id}:{$step}";
            $contentHash = (string) $run->content_hash;
            $approverLabel = $this->approverLabel($approverId);

            $approver = User::find($approverId);
            if (! $approver || ! $approver->is_active || ! $approver->isAdmin()) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $contentHash, "{$targetKey}: approval refused — approver is not an active Admin.", $approverLabel, $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined', message: 'Control D onboarding must be approved by an active Admin — nothing was created.');
            }

            // The two-person rule, by lane (B4.1). Button lane: a person staged, so the
            // approver must be someone else. Token lane: no person staged, so the stager
            // must be an ai_actor token — still one, re-read now — and this Admin is the
            // one human signature. A payload naming neither is refused, never approved.
            $stagerId = $payload['staged_by_user_id'] ?? null;
            if ($stagerId !== null) {
                if ((int) $stagerId === (int) $approverId) {
                    $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $contentHash, "{$targetKey}: approval refused — approver is the stager.", $approverLabel, $run->id, $approverId);
                    $run->releaseClaim();

                    return new TechnicianApprovalResult('gate_declined', message: 'Control D onboarding needs a second Admin: the person who staged this proposal cannot approve it. Nothing was created.');
                }
            } else {
                $why = $this->tokenLaneRefusal($payload['staged_by_token_id'] ?? null);
                if ($why !== null) {
                    $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $contentHash, "{$targetKey}: approval refused — {$why}", $approverLabel, $run->id, $approverId);
                    $run->releaseClaim();

                    return new TechnicianApprovalResult('gate_declined', message: "Control D onboarding staged through the MCP verb is approved only when its staging token is still a live, granted ai_actor token ({$why}). Deny this proposal; a person onboards from the client page. Nothing was created.");
                }
            }

            if (! ControlDConfig::isEnabled() || ! ControlDConfig::isConfigured() || ! ControlDConfig::isOnboardingActive()) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $contentHash, "{$targetKey}: approval refused — onboarding is not enabled/configured.", $approverLabel, $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined', message: 'Control D client onboarding is not enabled on this instance — nothing was created.');
            }

            if (TechnicianConfig::killSwitchEngaged()) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $contentHash, "{$targetKey}: Technician kill-switch engaged; approval refused.", $approverLabel, $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            if ($this->executedCooldownActive([$run->action_type, self::TOOL], (int) $client->id, $targetKey, self::COOLDOWN_SECONDS)) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $contentHash, "{$targetKey}: cooldown active; approval refused before any vendor call.", $approverLabel, $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            // The step the client needs NOW must be the step the approver read.
            $derived = $this->nextStep($client);
            if (($derived['step'] ?? null) !== $step) {
                $why = $derived['error'] ?? "the client now needs the '".($derived['step'] ?? 'none')."' step, not '{$step}'";
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $contentHash, "{$targetKey}: approval refused — {$why}", $approverLabel, $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined', message: "The client's Control D state changed since this was staged ({$why}). Deny this proposal and stage again so the current step is read and approved on its own card. Nothing was created.");
            }

            $service = $this->onboarding();
            try {
                if ($step === self::STEP_ORGANIZATION) {
                    $inputs = $this->organizationInputs($client);
                    if (isset($inputs['error'])) {
                        throw new ControlDClientException($inputs['error']);
                    }
                    $intentId = $service->stageOrganization($approver, (int) $client->id, $inputs['name'], $inputs['contact_email'], self::REQUIRE_MFA, $inputs['stats_endpoint']);
                } else {
                    $intentId = $service->stageCode($approver, (int) $client->id, self::CODE_ICON, null, null);
                }
                $service->execute($approver, $intentId);
            } catch (ControlDClientException $e) {
                $intent = $intentId !== null ? ControlDOnboardingIntent::find($intentId) : null;
                if ($intent === null || $intent->state === 'staged') {
                    // Definite local refusal before admission: no POST was issued. The
                    // B3 intent (if any) still holds this client's lock while staged.
                    $held = $intent !== null ? " Intent {$intent->id} remains staged and holds this client's onboarding lock; it must be reconciled before another proposal." : '';
                    $this->auditAttempt($run->action_type, 'error', $client->id, $ticket, $contentHash, "{$targetKey}: refused before any vendor write — ".mb_substr($e->getMessage(), 0, 300).$held, $approverLabel, $run->id, $approverId);
                    $run->releaseClaim();

                    return new TechnicianApprovalResult('gate_declined', message: 'Control D onboarding was refused before any vendor call: '.mb_substr($e->getMessage(), 0, 300).$held);
                }
                // Anything else is post-admission and the intent row carries it; fall through.
            }

            $intent = $intentId !== null ? ControlDOnboardingIntent::find($intentId) : null;
            $state = $intent?->state ?? 'unknown';

            if ($state === 'bound') {
                $run->advanceTo(TechnicianRunState::Done);
                $this->safeAudit($run->action_type, 'executed', $client->id, $ticket, $contentHash, "{$targetKey}: operator-approved Control D onboarding step '{$step}' executed; intent {$intent->id} bound".($step === self::STEP_ORGANIZATION ? " to organization {$intent->org_pk}" : '; code stored encrypted on the client record').'.', $approverLabel, $run->id, $approverId);

                return new TechnicianApprovalResult('executed', message: $step === self::STEP_ORGANIZATION
                    ? "Control D organization created and bound to the client (organization {$intent->org_pk}). Stage the verb again for step 2 (provisioning code)."
                    : 'Control D provisioning code cut and stored encrypted on the client record. Nothing else remains for this client in this leg.');
            }

            if ($state === 'rejected') {
                // Definite vendor refusal: nothing was created, lock released by B3. The
                // proposal is spent; a fresh one may be staged once the cause is fixed.
                $run->advanceTo(TechnicianRunState::Done);
                $reason = (string) ($intent->reason ?? 'vendor rejected the write');
                $this->safeAudit($run->action_type, 'error', $client->id, $ticket, $contentHash, "{$targetKey}: Control D rejected the '{$step}' write — {$reason} (code {$intent->reason_code}); intent {$intent->id} rejected, nothing created.", $approverLabel, $run->id, $approverId);

                return new TechnicianApprovalResult('executed_with_fault', message: "Control D rejected the {$step} write: {$reason}".($intent->reason_code === 40301 ? ' — the API key is a Read token; replace it with a Write token in Settings > Integrations, then stage again.' : '.').' Nothing was created.');
            }

            // uncertain, posted, or unknown: a vendor write MAY have happened. Terminal;
            // never re-armed. The intent holds the client's lock for manual reconciliation.
            $run->advanceTo(TechnicianRunState::Done);
            $fault = "HARD FAULT: the Control D '{$step}' write for client #{$client->id} ended {$state}".($intent !== null ? " (intent {$intent->id}, phase {$intent->phase}".($intent->vendor_pk !== null ? ", vendor PK {$intent->vendor_pk}" : '').')' : '')
                .'. The vendor POST may have committed. Do NOT re-approve or re-stage: reconcile upstream and local state by hand; the intent keeps this client\'s onboarding lock until then.';
            $this->safeAudit($run->action_type, 'error', $client->id, $ticket, $contentHash, "{$targetKey}: {$fault}", $approverLabel, $run->id, $approverId);

            return new TechnicianApprovalResult('executed_with_fault', message: $fault);
        } catch (\Throwable $e) {
            if ($intentId !== null && (ControlDOnboardingIntent::find($intentId)?->state ?? 'staged') !== 'staged') {
                // Post-admission: a write may have happened. Keep the run terminal.
                $this->safeAudit($run->action_type, 'error', (int) $run->client_id, null, (string) $run->content_hash, "onboard: finalizing after a possible vendor write failed; intent {$intentId}; run NOT reopened.", $this->approverLabel($approverId), $run->id, $approverId);
                $run->advanceTo(TechnicianRunState::Done);

                return new TechnicianApprovalResult('executed_with_fault', message: "HARD FAULT: recording the Control D onboarding outcome failed after a possible vendor write (intent {$intentId}). Do NOT re-approve; reconcile by hand.");
            }
            $run->releaseClaim();

            throw $e;
        }
    }

    private function onboarding(): ControlDOnboardingStaged
    {
        $vendor = new ControlDClient(['api_key' => ControlDConfig::get('api_key')]);

        return app()->bound(ControlDOnboardingStaged::class)
            ? app(ControlDOnboardingStaged::class)
            : new ControlDOnboardingStaged($vendor, new ControlDProvisioning($vendor));
    }

    // ── helpers (mirroring StaffHuntressActionToolExecutor) ─────────────────────

    /** @return array<int, string> */
    private function unexpectedArgumentKeys(array $arguments): array
    {
        $unexpected = [];
        foreach (array_keys($arguments) as $key) {
            if (! in_array((string) $key, self::ALLOWED_ARGUMENT_KEYS, true)) {
                $unexpected[] = (string) $key;
            }
        }

        return $unexpected;
    }

    private function alreadyExecuted(string $tool, int $clientId, string $contentHash): bool
    {
        return TechnicianActionLog::query()->where('action_type', $tool)->where('client_id', $clientId)
            ->where('content_hash', $contentHash)->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))->exists();
    }

    private function executedRunId(string $tool, int $clientId, string $contentHash): ?int
    {
        return TechnicianActionLog::query()->where('action_type', $tool)->where('client_id', $clientId)
            ->where('content_hash', $contentHash)->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))->latest('id')->value('run_id');
    }

    private function liveAwaitingRun(int $ticketId, string $tool, string $contentHash): ?TechnicianRun
    {
        return TechnicianRun::query()->where('ticket_id', $ticketId)->where('action_type', $tool)
            ->where('content_hash', $contentHash)->where('state', TechnicianRunState::AwaitingApproval->value)->first();
    }

    /**
     * Stage-time cooldown over both names, anchored on the per-client target key; a
     * proposal's own awaiting row is excluded so deny-then-re-stage still works.
     *
     * @param  array<int, string>  $actionTypes
     */
    private function cooldownActive(array $actionTypes, int $clientId, string $targetKey, int $cooldownSeconds, ?string $ownContentHash = null): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()->whereIn('action_type', $actionTypes)->where('client_id', $clientId)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->where(function ($query) use ($ownContentHash) {
                $query->where('result_status', 'executed')->orWhere(function ($awaiting) use ($ownContentHash) {
                    $awaiting->where('result_status', 'awaiting_approval');
                    if ($ownContentHash !== null) {
                        $awaiting->where(fn ($own) => $own->whereNull('content_hash')->orWhere('content_hash', '!=', $ownContentHash));
                    }
                });
            })
            ->where('summary', 'like', $targetKey.':%')->exists();
    }

    /** @param  array<int, string>  $actionTypes */
    private function executedCooldownActive(array $actionTypes, int $clientId, string $targetKey, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()->whereIn('action_type', $actionTypes)->where('client_id', $clientId)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))->where('result_status', 'executed')
            ->where('summary', 'like', $targetKey.':%')->exists();
    }

    private function safeAudit(string $actionType, string $resultStatus, ?int $clientId, ?Ticket $ticket, string $contentHash, string $summary, string $actorLabel, ?int $runId = null, ?int $approverId = null): void
    {
        try {
            $this->auditAttempt($actionType, $resultStatus, $clientId, $ticket, $contentHash, $summary, $actorLabel, $runId, $approverId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[StaffControlDOnboardingToolExecutor] Post-write audit row failed', ['run_id' => $runId, 'result_status' => $resultStatus, 'error' => $e->getMessage()]);
        }
    }

    private function auditAttempt(string $actionType, string $resultStatus, ?int $clientId, ?Ticket $ticket, string $contentHash, string $summary, string $actorLabel, ?int $runId = null, ?int $approverId = null): void
    {
        if ($clientId !== null && ! Client::whereKey($clientId)->exists()) {
            $clientId = null;
        }

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
            'summary' => mb_substr($this->redactor->redactString($summary), 0, 1000),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }

    /**
     * B4.1: why a token-lane run may NOT be approved, or null when its staging token is
     * still a live, granted ai_actor token. The token row is re-read at approval so every
     * withdrawal the operator is told to use refuses a pending run: the flag cleared, the
     * token deleted, REVOKED or PAUSED (isActive() is exactly the authentication gate —
     * McpToken::scopeAuthenticatable), or the verb's grant removed. A token that could no
     * longer call the verb cannot carry the lane's single signature either. Names ids only.
     */
    private function tokenLaneRefusal(mixed $stagerTokenId): ?string
    {
        $tokenId = $this->positiveInt($stagerTokenId);
        if ($tokenId === null) {
            return 'the payload names no staging token (B4.1: token-lane runs must be staged by an ai_actor token).';
        }
        $token = McpToken::find($tokenId);
        if (! $token) {
            return "staging token #{$tokenId} no longer exists.";
        }
        if (! $token->isActive()) {
            return "staging token #{$tokenId} is no longer active (state: {$token->state()}).";
        }
        if (! $token->ai_actor) {
            return "staging token #{$tokenId} is not an ai_actor token.";
        }
        // The grant is re-read through THE projection authentication uses
        // (McpConfig::grantedTools — normalise, then parse; B4.2 #2056 c1:v1:1), so a
        // legacy comma-joined stored entry that stages also approves. A legacy
        // full-surface token (tools null) never inherits this verb, so it cannot
        // carry the lane either.
        $granted = McpConfig::grantedTools($token)['tools'] ?? [];
        if (! in_array(self::TOOL, $granted, true)) {
            return "staging token #{$tokenId} is no longer granted ".self::TOOL.'.';
        }

        return null;
    }

    private function decryptRunPayload(TechnicianRun $run): ?array
    {
        $ciphertext = $run->proposed_meta['encrypted_payload'] ?? null;
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }
        try {
            $json = Crypt::decryptString($ciphertext);
        } catch (DecryptException) {
            return null;
        }
        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private function contentHash(string $tool, int $clientId, ?int $ticketId, array $params): string
    {
        unset($params['reason'], $params['staged']);
        ksort($params);

        return hash('sha256', json_encode(['tool' => $tool, 'client_id' => $clientId, 'ticket_id' => $ticketId, 'params' => $params]));
    }

    private function requiredString(array $arguments, string $key): ?string
    {
        if (! array_key_exists($key, $arguments) || ! is_scalar($arguments[$key])) {
            return null;
        }
        $value = trim((string) $arguments[$key]);

        return $value !== '' ? $value : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value > 0 ? (int) $value : null;
        }

        return null;
    }

    private function approverLabel(int $approverId): string
    {
        $user = User::find($approverId);

        return $user?->email ?? $user?->name ?? "approver:{$approverId}";
    }

    // ── definitions ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function onboardTool(): array
    {
        return [
            'name' => self::TOOL,
            'description' => 'Onboard ONE PSA client to Control D: step 1 creates the client\'s Control D sub-organization (name and contact email from the client record, two-factor required, the panel\'s analytics region) and binds the returned organization id to the client; step 2, staged separately once the mapping is bound, cuts one provisioning code under that organization with the Settings > Integrations > Control D defaults (enforced profile, expiry, device limit = asset count + headroom, analytics level, intercept mode). HELD-ONLY: never executes immediately, whatever mode was granted — every call needs staged=true and a ticket_id and is approved in the cockpit by an active Admin. Only an MCP token marked ai_actor may stage it (the agent stages, one human approves); a person onboards from the Control D card on the client page, where a second Admin must approve. The server decides which step the client needs; one proposal is one step. No PIN, hostname prefix, icon, profile, limit or vendor PK is accepted from the caller; secrets are stored encrypted on the client record and never returned. Inert unless the Control D onboarding switch is on and all six defaults are configured. Requires an explicit token grant, reason, kill-switch, and TechnicianActionLog audit.',
            'input_schema' => ['type' => 'object', 'properties' => self::properties(), 'required' => ['reason']],
        ];
    }

    /** @return array<string, mixed> */
    private static function stageOnboardTool(): array
    {
        return [
            'name' => self::STAGED_TOOL,
            'description' => 'Stage the next Control D onboarding step for one client (organization create, or provisioning code once mapped) for cockpit approval by an active Admin. Staging requires an ai_actor token (a person uses the client-page button, which needs a second Admin). The MCP call makes no Control D write; the held proposal stores only ids and the step, and approval re-derives every input from the client record and the panel. Held-only — there is no immediate execution path.',
            'input_schema' => ['type' => 'object', 'properties' => self::properties(true), 'required' => ['ticket_id', 'reason']],
        ];
    }

    /** @return array<string, array<string, string>> */
    private static function properties(bool $ticket = false): array
    {
        $properties = [
            'reason' => ['type' => 'string', 'description' => 'Why this client is being onboarded to Control D now. Shown to the approver and recorded in the audit log.'],
        ];
        if ($ticket) {
            $properties['ticket_id'] = ['type' => 'integer', 'description' => 'PSA ticket this onboarding belongs to. Must belong to client_id; the staged proposal is held on this ticket for cockpit approval.'];
        }

        return $properties;
    }
}
