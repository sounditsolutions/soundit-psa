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
 * THREE STEPS, ONE APPROVAL EACH, NEVER TWO IN ONE. A client without `controld_org_id`
 * proposes the `organization` step (create the sub-organization with the configured
 * global profile, bind the mapping). A mapped client with no stored code proposes the
 * `global-profile` step when its sub-organization's parent_profile is ABSENT (fill it;
 * the org and profile PKs are pinned in the sealed payload), or the `code` step when
 * the configured global profile is already enforced (cut the provisioning code under
 * that org). A sub-organization that already enforces a DIFFERENT global profile, or
 * whose parent inventory cannot be read, gets no proposal at all. Each proposal names
 * exactly one step at staging time and re-derives it at approval; if the client's
 * state moved in between, approval refuses rather than doing another step under a
 * card nobody read.
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

    /** Fill an ABSENT parent_profile on a mapped sub-organization with the configured Global Profile. */
    public const STEP_GLOBAL_PROFILE = ControlDOnboardingStaged::GLOBAL_PROFILE;

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
        // G-14: a held intake ticket in THIS client exists; the refusal and its audit row say so.
        $held = $ticket === null && $ticketId !== null ? Ticket::find($ticketId) : null;
        if ($held?->isUnverifiedContactIntake() && (int) $held->client_id === $clientId) {
            $message = 'ticket_id is an unverified web-form intake held for staff verification; staff must verify it before Control D onboarding can trace to it.';
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, $message, $actorLabel);

            return ['error' => $message];
        }
        if (! $ticket || (int) $ticket->client_id !== $clientId) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, 'ticket_id is required and must belong to this client.', $actorLabel);

            return ['error' => 'ticket_id is required for staged Control D onboarding and must belong to this client'];
        }

        return $this->stageProposal($client, $ticket, $reason, $actorLabel, $tool, null, (int) $staffToken->id, $staffToken->label);
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

        return $this->stageProposal($client, $ticket, $reason, 'staff:'.$stager->id, self::STAGED_TOOL, (int) $stager->id, null, null);
    }

    // ── step derivation ─────────────────────────────────────────────────────────

    /**
     * Why the global-profile state could not be established (any arm of
     * globalProfileState()). No staging clause, like every nextStep() 'reason': approval
     * embeds it while its proposal is still staged; only the staging-time refusal
     * (UNREADABLE_REFUSAL here) says nothing was staged.
     */
    public const UNREADABLE_REASON = 'Could not confirm whether the Control D global profile is enforced on this client\'s organization (the configured global profile setting is missing or invalid, Control D is disabled or unconfigured, or the parent organization list could not be read or did not list this organization exactly once with a well-formed global profile)';

    /**
     * The global-profile race, on the approval card and the client page. True of this
     * code on every arm: the PUT carries no condition; only ControlDClient's
     * ControlDWriteRejectedException (an HTTP 4xx whose body is the vendor error envelope,
     * success false with an integer error code) ends the intent `rejected`; every other
     * response to the PUT that requestForOrg() refuses (a 4xx without that envelope; a 1xx,
     * 3xx or 5xx, since redirects are not followed; a 2xx whose body is not JSON or does not
     * carry success true), any other post-admission failure, or a read-back that does not
     * show the configured profile ends it uncertain; and when B3's finish() cannot save that
     * outcome, or the process stops after admit() and before the outcome is recorded, the
     * row stays `posted`. posted and uncertain both keep the lock and are never retried.
     * It claims nothing about what Control D does with the PUT. No apostrophes: the client
     * page renders it escaped.
     */
    public const RACE_DISCLOSURE = 'The global-profile step sends an unconditional update, so a global profile set after the last read before that update is not detected and the update may replace it. Only a refusal in the Control D error envelope (an HTTP 4xx whose body reports success false with an integer error code) ends the step rejected; any other refusal, an unknown outcome, or a read-back that does not show the configured profile ends it uncertain. If that outcome cannot be recorded, or the process stops after the step is admitted for its update and before its outcome is recorded, the step stays posted instead. Posted and uncertain steps are never retried and keep this client locked until reconciled by hand.';

    /** nextStep() refusal at staging when the global-profile state could not be established. */
    public const UNREADABLE_REFUSAL = self::UNREADABLE_REASON.'; nothing was staged.';

    // The two halves of OWNED_REFUSAL (staging) and OWNED_REASON (approval).
    private const OWNED_HEAD = 'A Control D onboarding intent already owns this client (staged, posted or uncertain)';

    private const OWNED_RELEASE = 'A never-admitted (staged) intent can be released by an Admin from the Control D onboarding card on the client page while client onboarding is enabled; a posted or uncertain one needs manual reconciliation before the next step is proposed';

    /** Next-step refusal while an intent owns the client: names the release path truthfully. */
    private const OWNED_REFUSAL = self::OWNED_HEAD.'; nothing was staged. '.self::OWNED_RELEASE.'.';

    /** OWNED_REFUSAL without its staging clause: approval embeds it while its proposal is still staged. */
    private const OWNED_REASON = self::OWNED_HEAD.'. '.self::OWNED_RELEASE;

    /** Why an onboarded client gets no next step; staging appends 'nothing was staged', approval does not. */
    private const ONBOARDED_REASON = 'This client is already onboarded: it is mapped to a Control D organization and carries a provisioning code. Re-cutting a code is a separate, explicit verb';

    /**
     * Which step this client needs next, or an error. Exactly one step per proposal.
     * For the global-profile step, also the org PK and profile PK the proposal pins.
     *
     * @return array{step?: string, error?: string, org_pk?: string, profile_pk?: string, unreadable?: bool, different?: bool, reason?: string}
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
                return ['error' => self::OWNED_REFUSAL, 'reason' => self::OWNED_REASON];
            }

            return ['step' => self::STEP_ORGANIZATION];
        }
        if ($client->getRawOriginal('controld_provisioning_code') !== null || $client->getRawOriginal('controld_deactivation_pin') !== null) {
            return ['error' => self::ONBOARDED_REASON.'; nothing was staged.', 'reason' => self::ONBOARDED_REASON];
        }
        if (ControlDOnboardingIntent::where('client_id', $client->id)->whereIn('state', ['staged', 'posted', 'uncertain'])->exists()) {
            return ['error' => self::OWNED_REFUSAL, 'reason' => self::OWNED_REASON];
        }
        // Ordering: organization, then global profile (only when the sub-organization's
        // parent_profile is ABSENT), then code (only when it equals the configured Global
        // Profile). Decided by a read-only GET of the parent's sub_organizations, here at
        // staging AND again at approval. A failed or malformed read refuses (unknown is
        // never "enforced"), and a DIFFERENT parent_profile refuses too: the code step's
        // preflight would not accept the configured profile there, and this code does not
        // knowingly replace a global profile someone chose (this code sends an
        // unconditional PUT, so one set after the pre-admission read is not detected and
        // the PUT may replace it).
        try {
            $state = $this->onboarding()->globalProfileState((string) $org);
        } catch (ControlDClientException) {
            // globalProfileState() throws before any request (setting missing or invalid,
            // Control D disabled or unconfigured) and after one (transport failure, a
            // malformed body, the org not listed exactly once). The text asserts neither.
            return ['unreadable' => true, 'error' => self::UNREADABLE_REFUSAL, 'reason' => self::UNREADABLE_REASON];
        }
        if ($state === ControlDOnboardingStaged::PROFILE_DIFFERENT) {
            return ['different' => true, 'error' => ControlDOnboardingStaged::DIFFERENT_PROFILE_REFUSAL];
        }
        if ($state === ControlDOnboardingStaged::PROFILE_ABSENT) {
            return ['step' => self::STEP_GLOBAL_PROFILE, 'org_pk' => (string) $org, 'profile_pk' => (string) ControlDConfig::defaultProfileId()];
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
    private function stageProposal(Client $client, Ticket $ticket, string $reason, string $actorLabel, string $tool, ?int $stagerUserId, ?int $stagerTokenId, ?string $tokenLabel): array
    {
        $clientId = (int) $client->id;
        $derived = $this->nextStep($client);
        $pins = array_intersect_key($derived, ['org_pk' => true, 'profile_pk' => true]);
        $contentHash = $this->contentHash($tool, $clientId, $ticket->id, ['step' => $derived['step'] ?? 'none', ...$pins]);
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

        $proposedContent = $this->proposedContent($client, $step, $inputs, $pins)."\n".$this->fence->fence('AGENT SUPPLIED REASON', $reason);
        $meta = [
            ...DraftedByToken::meta($actorLabel, $tokenLabel),
            'reasons' => [$reason],
            'direct_tool' => self::TOOL,
            'redacted_params' => ['step' => $step, 'client_name' => (string) $client->name],
            'sensitive_inputs' => [],
            'staged_by_user_id' => $stagerUserId,
            'staged_by_token_id' => $stagerTokenId,
            // Only ids, the step and (global-profile step) the org and profile PKs the
            // card names: approval re-derives every other input from the client record and
            // the panel and REFUSES unless those pins still equal the live mapping and
            // setting; the global-profile write uses the pinned values. The stager (user id OR token id) is read from THIS
            // sealed copy at approval.
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => self::TOOL, 'client_id' => $clientId, 'ticket_id' => $ticket->id, 'step' => $step,
                'staged_by_user_id' => $stagerUserId, 'staged_by_token_id' => $stagerTokenId, ...$pins,
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

    /**
     * @param  array<string, string>  $inputs
     * @param  array<string, string>  $pins
     */
    private function proposedContent(Client $client, string $step, array $inputs, array $pins = []): string
    {
        $name = $this->fence->neutralizeUntrusted((string) $client->name);
        if ($step === self::STEP_GLOBAL_PROFILE) {
            return "Control D onboarding — step 2 of 3 (global profile) for client '{$name}' (#{$client->id}), organization ".$this->fence->neutralizeUntrusted((string) ($pins['org_pk'] ?? '')).".\n"
                .'Sets the configured global profile '.($pins['profile_pk'] ?? '').' on that existing sub-organization, which has none set (one update, then a read-back from the parent organization list). If a different global profile is already set when this is approved, approval refuses and nothing is written. '.self::RACE_DISCLOSURE."\n"
                .'Approval refuses if the client\'s organization or the configured global profile no longer matches the values on this card. No provisioning code is cut by this step; once it is confirmed, stage the verb again for the final step (provisioning code).';
        }
        if ($step === self::STEP_ORGANIZATION) {
            return "Control D onboarding — step 1 of up to 3 (organization) for client '{$name}' (#{$client->id}).\n"
                ."Creates a Control D sub-organization named after the client, contact email from the client record ({$this->fence->neutralizeUntrusted($inputs['contact_email'])}), "
                .'two-factor required, analytics region '.$inputs['stats_endpoint'].", then binds the returned organization id to the client.\n"
                .'No provisioning code is cut by this step; once the mapping is bound, stage the verb again for the next step.';
        }

        return "Control D onboarding — final step (provisioning code) for client '{$name}' (#{$client->id}), organization ".$this->fence->neutralizeUntrusted((string) $client->controld_org_id).".\n"
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
     * Then exactly one intent: stageOrganization, stageGlobalProfile (with the card's
     * pinned org and profile PKs) or stageCode, then execute.
     *
     * OUTCOMES. B3 records the truth on the intent row: `bound` = done; `rejected` =
     * the vendor refused (read-only key, envelope error) — definite, nothing created,
     * the run is spent and the operator may stage again after fixing the cause;
     * `uncertain` = the vendor write (POST for organization and code, PUT for the global
     * profile) may have landed and the intent holds the client's lock — TERMINAL here,
     * executed_with_fault, never re-armed (a retry would repeat a write whose outcome is
     * unknown). When B3's finish() cannot save its outcome, the intent stays `posted` and
     * that is reported as a HARD FAULT the same way. When the process stops after
     * admission and before the outcome is recorded, the intent also stays `posted`, but
     * this method reports nothing: the run stays Executing and claimed. A
     * ControlDClientException BEFORE admission releases the run back to AwaitingApproval
     * with no vendor write issued (read-only GETs, nextStep() and the pre-admission check,
     * may have been). That branch has three arms: no intent id (staging threw before
     * returning one; stage()'s INSERT may still have committed first, so a staged row
     * holding the lock can exist under an id this method never received), an intent B3
     * left `staged`, and an intent the already-enforced no-op `released`. An intent id
     * whose row cannot be found is none of these: it falls through and is reported as a
     * HARD FAULT that ended `unknown`. Only a staged intent that still holds the per-client
     * lock (active_client_id = client_id) is named, by id, as holding it; a released one,
     * or a staged one whose lock is NULL, is not. If anything else throws
     * once an intent id exists and its row then cannot be read (find() throws or returns
     * null), admission cannot be ruled out: HARD FAULT, never re-armed; a find() that
     * throws is logged with the intent id, the run id and the exception class only. That
     * arm makes no unguarded approver lookup and closes the run Done when the run store
     * accepts it; a failed close is logged and leaves the run claimed, never released for
     * re-approval.
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
                || ! in_array($step, [self::STEP_ORGANIZATION, self::STEP_GLOBAL_PROFILE, self::STEP_CODE], true)) {
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

            // The step the client needs NOW must be the step the approver read, and for
            // the global-profile step the org and profile PKs on the card must still be
            // the live mapping and setting.
            $derived = $this->nextStep($client);
            $pinDrift = $step === self::STEP_GLOBAL_PROFILE && ($derived['step'] ?? null) === $step
                && (($payload['org_pk'] ?? null) !== ($derived['org_pk'] ?? null) || ($payload['profile_pk'] ?? null) !== ($derived['profile_pk'] ?? null));
            if (($derived['step'] ?? null) !== $step || $pinDrift) {
                // A refusal that says 'nothing was staged' at staging (unreadable, owned,
                // already onboarded) carries a reason without that clause: this proposal is
                // still staged.
                $why = $derived['reason'] ?? $derived['error'] ?? ($pinDrift
                    ? "the client's Control D organization or the configured global profile no longer matches the values on this card"
                    : "the client now needs the '".($derived['step'] ?? 'none')."' step, not '{$step}'");
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $contentHash, "{$targetKey}: approval refused — {$why}", $approverLabel, $run->id, $approverId);
                $run->releaseClaim();
                if ($derived['unreadable'] ?? false) {
                    return new TechnicianApprovalResult('gate_declined', message: "Control D onboarding approval could not re-check the client's current step ({$why}). Nothing was created. Approving again helps only if the cause was temporary; otherwise fix it or deny this proposal.");
                }
                if ($derived['different'] ?? false) {
                    // Staging again would refuse with the same text (nextStep() returns it while
                    // the org enforces another profile), so this arm does not say 'stage again'.
                    return new TechnicianApprovalResult('gate_declined', message: "This organization's global profile does not match the configured profile ({$why}). This proposal cannot be approved, and staging again is refused the same way, while this organization enforces a global profile other than the configured one. Nothing was created.");
                }

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
                } elseif ($step === self::STEP_GLOBAL_PROFILE) {
                    $intentId = $service->stageGlobalProfile($approver, (int) $client->id, (string) ($payload['org_pk'] ?? ''), (string) ($payload['profile_pk'] ?? ''));
                } else {
                    $intentId = $service->stageCode($approver, (int) $client->id, self::CODE_ICON, null, null);
                }
                $service->execute($approver, $intentId);
            } catch (ControlDClientException $e) {
                $intent = $intentId !== null ? ControlDOnboardingIntent::find($intentId) : null;
                // No id: staging threw before returning one, so execute() never ran and this
                // method admitted nothing. stage()'s INSERT may still have committed before the
                // error, leaving a staged row that holds the lock under an id never returned
                // here. An id whose row is gone cannot rule admission out and falls through to
                // the HARD FAULT ('unknown').
                if ($intentId === null || ($intent !== null && in_array($intent->state, ['staged', ControlDOnboardingStaged::RELEASED], true))) {
                    // Definite local refusal before admission: no vendor write was issued
                    // (read-only GETs may have been). A staged B3 intent whose active_client_id
                    // is this client holds the lock; an Admin may release it from the client
                    // page while onboarding is enabled (never-admitted intents only). A released
                    // one, or a staged one whose lock is NULL, does not, and is not called holding it.
                    $held = $intent?->state === 'staged' && (int) $intent->active_client_id === (int) $client->id ? " Intent {$intent->id} remains staged and holds this client's onboarding lock; an Admin can release it from the Control D onboarding card on the client page (shown while client onboarding is enabled) before another proposal." : '';
                    // safeAudit: a failed audit row here must not reach the outer catch, which
                    // would report a possible vendor write for an intent that made none.
                    // 600 keeps DIFFERENT_PROFILE_REFUSAL whole.
                    $this->safeAudit($run->action_type, 'error', $client->id, $ticket, $contentHash, "{$targetKey}: refused before any vendor write — ".mb_substr($e->getMessage(), 0, 600).$held, $approverLabel, $run->id, $approverId);
                    $run->releaseClaim();

                    return new TechnicianApprovalResult('gate_declined', message: 'Control D onboarding was refused before any vendor write: '.mb_substr($e->getMessage(), 0, 600).$held);
                }
                // Anything else is post-admission and the intent row carries it; fall through.
            }

            $intent = $intentId !== null ? ControlDOnboardingIntent::find($intentId) : null;
            $state = $intent?->state ?? 'unknown';

            if ($state === 'bound') {
                $run->advanceTo(TechnicianRunState::Done);
                $this->safeAudit($run->action_type, 'executed', $client->id, $ticket, $contentHash, "{$targetKey}: operator-approved Control D onboarding step '{$step}' executed; intent {$intent->id} bound".match ($step) {
                    self::STEP_ORGANIZATION => " to organization {$intent->org_pk}",
                    self::STEP_GLOBAL_PROFILE => "; global profile confirmed on organization {$intent->org_pk}",
                    default => '; code stored encrypted on the client record',
                }.'.', $approverLabel, $run->id, $approverId);

                return new TechnicianApprovalResult('executed', message: match ($step) {
                    self::STEP_ORGANIZATION => "Control D organization created with the global profile and bound to the client (organization {$intent->org_pk}). Stage the verb again for the provisioning code.",
                    self::STEP_GLOBAL_PROFILE => "Control D global profile enforced on organization {$intent->org_pk} and confirmed by read-back. Stage the verb again for the provisioning code.",
                    default => 'Control D provisioning code cut and stored encrypted on the client record. Nothing else remains for this client in this leg.',
                });
            }

            if ($state === 'rejected') {
                // Definite vendor refusal: nothing was created, lock released by B3. The
                // proposal is spent; a fresh one may be staged once the cause is fixed.
                // Audited before the run is closed, so a failure closing it (the outer
                // catch keeps a rejected run terminal) never loses the refusal.
                $reason = (string) ($intent->reason ?? 'vendor rejected the write');
                $nothing = $step === self::STEP_GLOBAL_PROFILE ? 'nothing changed' : 'nothing created';
                $this->safeAudit($run->action_type, 'error', $client->id, $ticket, $contentHash, "{$targetKey}: Control D rejected the '{$step}' write — {$reason} (code {$intent->reason_code}); intent {$intent->id} rejected, {$nothing}.", $approverLabel, $run->id, $approverId);
                $run->advanceTo(TechnicianRunState::Done);

                return new TechnicianApprovalResult('executed_with_fault', message: "Control D rejected the {$step} write: {$reason}".($intent->reason_code === 40301 ? ' — the API key is a Read token; replace it with a Write token in Settings > Integrations, then stage again.' : '.').' '.ucfirst($nothing).'.');
            }

            // uncertain, posted, or unknown: a vendor write MAY have happened. Terminal;
            // never re-armed. An intent row that still exists holds the client's lock for manual
            // reconciliation; one whose row is gone holds none, and the text says so.
            $run->advanceTo(TechnicianRunState::Done);
            $fault = "HARD FAULT: the Control D '{$step}' write for client #{$client->id} ended {$state}".($intent !== null ? " (intent {$intent->id}, phase {$intent->phase}".($intent->vendor_pk !== null ? ($step === self::STEP_GLOBAL_PROFILE ? ", org PK {$intent->vendor_pk}" : ", vendor PK {$intent->vendor_pk}") : '').')' : '')
                .($step === self::STEP_GLOBAL_PROFILE ? '. The vendor PUT may have committed.' : '. The vendor POST may have committed.').' Do NOT re-approve or re-stage: reconcile upstream and local state by hand; '.($intent !== null
                    ? 'the intent keeps this client\'s onboarding lock until then.'
                    : "intent {$intentId} could not be found afterwards, so it holds no onboarding lock and does not block a new proposal for this client; do not stage one until then.");
            $this->safeAudit($run->action_type, 'error', $client->id, $ticket, $contentHash, "{$targetKey}: {$fault}", $approverLabel, $run->id, $approverId);

            return new TechnicianApprovalResult('executed_with_fault', message: $fault);
        } catch (\Throwable $e) {
            // Only an ADMITTED intent can carry a vendor write; staged and released
            // (never admitted) are pre-admission whatever failed afterwards. `rejected` is
            // admitted but written only by B3's finish() on a ControlDWriteRejectedException
            // from the one POST/PUT itself (a vendor envelope refusal), so nothing was written;
            // its proposal is spent all the same, so the run stays terminal and is never
            // released for a second approval. With an intent id but no readable row (find()
            // throws, or the row is gone) admission cannot be ruled out: 'unknown', terminal.
            try {
                $caughtState = $intentId !== null ? (ControlDOnboardingIntent::find($intentId)?->state ?? 'unknown') : 'staged';
            } catch (\Throwable $readError) {
                $caughtState = 'unknown';
                // C-56: the failed read is not swallowed silently. Ids and the class only.
                \Illuminate\Support\Facades\Log::error('[StaffControlDOnboardingToolExecutor] The onboarding intent could not be read after this approval failed', ['intent_id' => $intentId, 'run_id' => $run->id, 'exception' => $readError::class]);
            }
            if ($caughtState === 'unknown') {
                // The failed read is most likely the database itself, so nothing else in this
                // arm may throw past it: the approver lookup falls back to the id, and a failed
                // close is logged (the run then stays claimed, never released for re-approval).
                $label = "approver:{$approverId}";
                try {
                    $label = $this->approverLabel($approverId);
                } catch (\Throwable) {
                    // keep the id-only label
                }
                $this->safeAudit($run->action_type, 'error', (int) $run->client_id, null, (string) $run->content_hash, "onboard: intent {$intentId} could not be read after this approval failed; a vendor write cannot be ruled out; run NOT reopened.", $label, $run->id, $approverId);
                try {
                    $run->advanceTo(TechnicianRunState::Done);
                } catch (\Throwable) {
                    \Illuminate\Support\Facades\Log::error('[StaffControlDOnboardingToolExecutor] Closing the run failed after its intent could not be read', ['run_id' => $run->id]);
                }

                return new TechnicianApprovalResult('executed_with_fault', message: "HARD FAULT: this approval failed and Control D onboarding intent {$intentId} could not be read afterwards, so a vendor write cannot be ruled out. Do NOT re-approve; reconcile by hand.");
            }
            if ($caughtState === 'rejected') {
                $this->safeAudit($run->action_type, 'error', (int) $run->client_id, null, (string) $run->content_hash, "onboard: finishing after Control D rejected the write failed; intent {$intentId} rejected; run NOT reopened.", $this->approverLabel($approverId), $run->id, $approverId);
                $run->advanceTo(TechnicianRunState::Done);

                return new TechnicianApprovalResult('executed_with_fault', message: "Control D rejected the onboarding write (intent {$intentId}); finishing this approval failed afterwards. The proposal is closed and was not reopened: stage a fresh one once the cause is fixed.");
            }
            if ($intentId !== null && ! in_array($caughtState, ['staged', ControlDOnboardingStaged::RELEASED], true)) {
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
            'description' => 'Onboard ONE PSA client to Control D in up to three separately staged steps: (1) create the client\'s Control D sub-organization (name and contact email from the client record, two-factor required, the panel\'s analytics region, the configured global profile) and bind the returned organization id to the client; (2) only for a mapped sub-organization with no global profile set, set the configured global profile on it (refused if a different one is already set when staged, when approved, or at the read just before the update; this code sends an unconditional update, so one set after that last read is not detected and the update may replace it); (3) once the configured global profile is enforced, cut one provisioning code under that organization with the Settings > Integrations > Control D defaults (enforced profile, expiry, device limit = asset count + headroom, analytics level, intercept mode). HELD-ONLY: never executes immediately, whatever mode was granted — every call needs staged=true and a ticket_id and is approved in the cockpit by an active Admin. Only an MCP token marked ai_actor may stage it (the agent stages, one human approves); a person onboards from the Control D card on the client page, where a second Admin must approve. The server decides which step the client needs; one proposal is one step. No PIN, hostname prefix, icon, profile, limit or vendor PK is accepted from the caller; secrets are stored encrypted on the client record and never returned. Inert unless the Control D onboarding switch is on and all six defaults are configured. Requires an explicit token grant, reason, kill-switch, and TechnicianActionLog audit.',
            'input_schema' => ['type' => 'object', 'properties' => self::properties(), 'required' => ['reason']],
        ];
    }

    /** @return array<string, mixed> */
    private static function stageOnboardTool(): array
    {
        return [
            'name' => self::STAGED_TOOL,
            'description' => 'Stage the next Control D onboarding step for one client (organization create; then, once mapped, the global profile if the sub-organization has none; then the provisioning code) for cockpit approval by an active Admin. Staging requires an ai_actor token (a person uses the client-page button, which needs a second Admin). The MCP call makes no Control D write; the held proposal stores ids and the step (and, for the global-profile step, the organization and profile PKs shown on the card, which approval requires to still match and then uses), and approval re-derives every other input from the client record and the panel. Held-only — there is no immediate execution path.',
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
