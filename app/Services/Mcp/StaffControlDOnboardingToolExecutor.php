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
use App\Services\ControlD\ControlDTacticalDeploy;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Services\Tactical\TacticalClient;
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
 * `global-profile` step when its sub-organization's parent_profile is ABSENT or null (fill it;
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
    private const ALLOWED_ARGUMENT_KEYS = ['ticket_id', 'reason', 'staged', 'deploy'];

    /** P7A74iGD (b): the payload 'step' of a one-approval plan proposal. */
    public const STEP_PLAN = 'plan';

    /** P7A74iGD (b): the plan's Tactical deploy step (after the code). */
    public const STEP_DEPLOY = 'deploy';

    /** Approval refusal for an onboarding proposal staged before the one-approval plan existed. */
    public const LEGACY_STEP_REFUSAL = 'This Control D onboarding proposal was staged as a single step, before onboarding became one plan per approval, so it cannot be shown to match what would run now. Deny this proposal and stage again; the new card lists every step. Nothing was created and nothing was changed at Control D or in Tactical.';

    /** G-14: what can and cannot be undone, on every plan card. */
    public const PLAN_UNDO = 'From the PSA, only the provisioning code can be undone: an Admin can invalidate it (Invalidate code on the client page), after which it stops working for new enrollments. Devices already enrolled are not removed, and the organization, its global profile and the Tactical client custom field stay as they are.';

    /** G-14: what a deploy result of "started" means. */
    public const DEPLOY_STARTED_MEANS = '"Started" means Tactical accepted the request to run the script on that agent; it does not mean Control D was installed. Check the agent in Tactical for the script\'s result.';

    /** Named in the refusal so the caller learns the rule: these are never inputs. */
    private const KNOWN_REFUSED_KEYS = ['pin', 'deactivation_pin', 'name_prefix', 'hostname_prefix', 'icon', 'org_pk', 'controld_org_id', 'profile_id', 'code', 'max', 'ts_exp', 'stats', 'intercept_mode', 'name', 'contact_email'];

    public const STEP_ORGANIZATION = 'organization';

    public const STEP_CODE = 'code';

    /** Fill an ABSENT or null parent_profile on a mapped sub-organization with the configured Global Profile. */
    public const STEP_GLOBAL_PROFILE = ControlDOnboardingStaged::GLOBAL_PROFILE;

    /**
     * P7A74iGD (a): invalidate the client's bound provisioning code (the undo). Staged only from
     * the client page by an Admin (stageInvalidateForClient()); a second Admin approves in the
     * cockpit under the same action type, so the cockpit dispatch is unchanged.
     */
    public const STEP_INVALIDATE = ControlDOnboardingStaged::INVALIDATE;

    /** G-14: what invalidating does and does not do; on the card, the client page and the result. */
    public const INVALIDATE_EFFECT = 'After it is invalidated, the code stops working for new enrollments: no new device can enroll with it. Devices already enrolled with it are NOT removed and keep their Control D enrollment.';

    /** Approval refusal when codePins() cannot derive the five values again (it threw), so a match with the card cannot be shown. */
    public const CODE_PINS_UNDERIVABLE = 'Approval could not derive the code values again from the current settings and the client\'s asset count (an onboarding default is missing or invalid, or the asset count plus the device-limit headroom is outside 1..10000), so they cannot be shown to match the values pinned on this card. Fix the cause, then deny this proposal and stage again so the current values are shown and approved on a new card. Nothing was created and nothing was changed at Control D.';

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

        return $this->stageProposal($client, $ticket, $reason, $actorLabel, $tool, null, (int) $staffToken->id, $staffToken->label, null, $arguments['deploy'] ?? null);
    }

    /**
     * The client-page button: same proposal, same card, same approval — staged by a
     * logged-in Admin instead of an MCP token. The stager's id is recorded on the run
     * so approval can refuse the same person approving their own proposal.
     *
     * @return array<string, mixed>
     */
    public function stageForClient(Client $client, Ticket $ticket, string $reason, User $stager, mixed $deploy = null): array
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

        return $this->stageProposal($client, $ticket, $reason, 'staff:'.$stager->id, self::STAGED_TOOL, (int) $stager->id, null, null, null, $deploy);
    }

    /**
     * P7A74iGD (b): the one-approval plan for this client now, or an error. Steps: the
     * not-yet-bound Control D steps from nextStep() (organization then code, or global-profile
     * then code, or code; an organization created by onboarding already carries the configured
     * global profile, which its read-back confirms), then `deploy` when $deployRequest is given
     * ('all' or a list of PSA asset ids). An onboarded client gets a deploy-only plan. Pins: the
     * organization step's inputs (name, contact email, analytics region, global profile), the
     * global-profile step's org and profile PKs, the code step's derived values (item E) and the
     * deploy scope with the Tactical field and script ids and each selected asset's Tactical agent id. Called at staging and again at
     * approval, which refuses unless the two are identical. Read-only (one Control D GET at most).
     *
     * @return array<string, mixed>
     */
    private function derivePlan(Client $client, mixed $deployRequest): array
    {
        $first = $this->nextStep($client);
        if (isset($first['error']) && ! (($first['onboarded'] ?? false) && $deployRequest !== null)) {
            return $first;
        }
        if (($first['onboarded'] ?? false) && ControlDOnboardingIntent::where('client_id', $client->id)->whereIn('state', ['staged', 'posted', 'uncertain'])->exists()) {
            return ['error' => self::OWNED_REFUSAL, 'reason' => self::OWNED_REASON];
        }
        $steps = match ($first['step'] ?? null) {
            self::STEP_ORGANIZATION => [self::STEP_ORGANIZATION, self::STEP_CODE],
            self::STEP_GLOBAL_PROFILE => [self::STEP_GLOBAL_PROFILE, self::STEP_CODE],
            self::STEP_CODE => [self::STEP_CODE],
            default => [],
        };
        $plan = ['step' => self::STEP_PLAN, 'plan' => $steps] + array_intersect_key($first, ['org_pk' => true, 'profile_pk' => true]);
        if (in_array(self::STEP_ORGANIZATION, $steps, true)) {
            // What the card shows and the organization step sends. An input error leaves it unset: staging refuses on that error.
            $inputs = $this->organizationInputs(Client::find($client->id) ?? $client);
            if (! isset($inputs['error'])) {
                $plan['org_inputs'] = $inputs + ['profile_id' => ControlDConfig::defaultProfileId()];
            }
        }
        if (in_array(self::STEP_CODE, $steps, true)) {
            try {
                $plan['code_pins'] = ControlDOnboardingStaged::codePins($client);
            } catch (ControlDClientException $e) {
                return ['error' => mb_substr($e->getMessage(), 0, 300).' Nothing was staged.', 'reason' => mb_substr($e->getMessage(), 0, 300), 'underivable' => true];
            }
        }
        if ($deployRequest !== null) {
            $deploy = ControlDTacticalDeploy::pins($client, $deployRequest);
            if (isset($deploy['error'])) {
                return ['error' => $deploy['error'].' Nothing was staged.', 'reason' => $deploy['error'], 'deploy_unavailable' => true];
            }
            $plan['plan'][] = self::STEP_DEPLOY;
            $plan['deploy'] = $deploy;
            $plan['deploy_request'] = $deployRequest === ControlDTacticalDeploy::SCOPE_ALL ? ControlDTacticalDeploy::SCOPE_ALL : array_column($deploy['assets'], 0);
        }

        return $plan;
    }

    /**
     * P7A74iGD (a): the client-page "Invalidate code" button. Admin-only; stages the invalidate
     * step on the same cockpit action type, for a SECOND Admin to approve (button lane only:
     * there is no MCP verb for it). The org PK and the bound code's PK (the vendor PK its bound
     * code intent recorded; never looked up at Control D by value) are pinned on the card.
     *
     * @return array<string, mixed>
     */
    public function stageInvalidateForClient(Client $client, Ticket $ticket, string $reason, User $stager): array
    {
        if (! ControlDConfig::isEnabled() || ! ControlDConfig::isConfigured() || ! ControlDConfig::isOnboardingActive()) {
            return ['error' => 'Control D client onboarding is not enabled on this instance.'];
        }
        if (! $stager->exists || ! $stager->is_active || ! $stager->isAdmin()) {
            return ['error' => 'Only an active Admin can stage invalidating a Control D code.'];
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

        return $this->stageProposal($client, $ticket, $reason, 'staff:'.$stager->id, self::STAGED_TOOL, (int) $stager->id, null, null, $this->invalidateStep($client));
    }

    /**
     * The invalidate step for this client now, or an error: the client is mapped, carries a
     * stored code, no intent owns it, and its bound code intent recorded the code's PK.
     *
     * @return array{step?: string, org_pk?: string, code_pk?: string, error?: string, reason?: string}
     */
    private function invalidateStep(Client $client): array
    {
        $client = Client::withTrashed()->find($client->id) ?? $client;
        if ($client->trashed()) {
            return ['error' => 'Client is deleted; nothing was staged.', 'reason' => 'Client is deleted'];
        }
        if (! is_string($client->controld_org_id) || $client->controld_org_id === '' || $client->getRawOriginal('controld_provisioning_code') === null) {
            return ['error' => 'This client has no stored Control D provisioning code to invalidate; nothing was staged.', 'reason' => 'This client has no stored Control D provisioning code to invalidate'];
        }
        if (ControlDOnboardingIntent::where('client_id', $client->id)->whereIn('state', ['staged', 'posted', 'uncertain'])->exists()) {
            return ['error' => self::OWNED_REFUSAL, 'reason' => self::OWNED_REASON];
        }
        $codePk = ControlDOnboardingStaged::boundCodePk($client);
        if ($codePk === null) {
            return ['error' => 'The stored code has no recorded Control D code record (it was not cut by onboarding), so it cannot be invalidated from here; nothing was staged.', 'reason' => 'The stored code has no recorded Control D code record'];
        }

        return ['step' => self::STEP_INVALIDATE, 'org_pk' => (string) $client->controld_org_id, 'code_pk' => $codePk];
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

    /** #6406: appended when advanceTo(Done) returned false (closeRunOrRecordLostFence()). */
    public const NOT_CLOSED = 'This request did not close the run, because it no longer holds it (another request may have claimed or closed it); check the run before acting on it.';

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
     * Which Control D step this client needs next, or an error. derivePlan() builds the plan from it.
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
            return ['error' => self::ONBOARDED_REASON.'; nothing was staged.', 'reason' => self::ONBOARDED_REASON, 'onboarded' => true];
        }
        if (ControlDOnboardingIntent::where('client_id', $client->id)->whereIn('state', ['staged', 'posted', 'uncertain'])->exists()) {
            return ['error' => self::OWNED_REFUSAL, 'reason' => self::OWNED_REASON];
        }
        // Ordering: organization, then global profile (only when the sub-organization's
        // parent_profile is ABSENT or null), then code (only when it equals the configured Global
        // Profile). Decided by a read-only GET of the parent's sub_organizations, here at
        // staging AND again at approval. A failed or malformed read refuses (unknown is
        // never "enforced"), and a DIFFERENT parent_profile refuses too: the code step's
        // preflight would not accept the configured profile there, and this code does not
        // knowingly replace a global profile someone chose (this code sends an
        // unconditional PUT, so one set after the pre-admission read is not detected and
        // the PUT may replace it).
        try {
            $state = $this->onboarding()->globalProfileState((string) $org);
        } catch (ControlDClientException $e) {
            // globalProfileState() throws before any request (setting missing or invalid,
            // Control D disabled or unconfigured) and after one (transport failure, a
            // malformed body, the org not listed exactly once). The text asserts neither.
            // #6408: the log carries ids, the exception class and a message only; distinct
            // arms can share one message. #6427: the label names the event, not a failed read
            // (the pre-request arms send none). #6429: the message is this executor's own
            // fixed text for the exception's message (readFailureMessage()), never the
            // exception's text itself, so a client text written for a write is not logged
            // for this read-only GET.
            \Illuminate\Support\Facades\Log::warning('[StaffControlDOnboardingToolExecutor] The Control D global-profile state was not established at step derivation', [
                'client_id' => $client->id, 'org_pk' => (string) $org, 'exception' => $e::class, 'message' => self::readFailureMessage($e),
            ]);

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
    private function stageProposal(Client $client, Ticket $ticket, string $reason, string $actorLabel, string $tool, ?int $stagerUserId, ?int $stagerTokenId, ?string $tokenLabel, ?array $forced = null, mixed $deploy = null): array
    {
        $clientId = (int) $client->id;
        $derived = $forced ?? $this->derivePlan($client, $deploy);
        $pins = array_intersect_key($derived, array_flip(self::PLAN_PIN_KEYS));
        $contentHash = $this->contentHash($tool, $clientId, $ticket->id, ['step' => $derived['step'] ?? 'none', ...$pins]);
        if (isset($derived['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $contentHash, $derived['error'], $actorLabel);

            return ['error' => $derived['error']];
        }
        $step = $derived['step'];
        $steps = $derived['plan'] ?? [$step];
        $targetKey = "onboard:{$clientId}:{$step}";

        $inputs = in_array(self::STEP_ORGANIZATION, $steps, true) ? $this->organizationInputs($client) : [];
        if (isset($inputs['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $contentHash, "{$targetKey}: ".$inputs['error'], $actorLabel);

            return ['error' => $inputs['error']];
        }
        if (in_array(self::STEP_CODE, $steps, true) && ! ControlDConfig::isOnboardingConfigured()) {
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

        $proposedContent = ($step === self::STEP_PLAN ? $this->planContent($client, $inputs, $pins) : $this->proposedContent($client, $step, $inputs, $pins))."\n".$this->fence->fence('AGENT SUPPLIED REASON', $reason);
        $meta = [
            ...DraftedByToken::meta($actorLabel, $tokenLabel),
            'reasons' => [$reason],
            'direct_tool' => self::TOOL,
            'redacted_params' => ['step' => $step, 'client_name' => (string) $client->name] + ($step === self::STEP_PLAN ? ['steps' => $steps] : []),
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

        $what = $step === self::STEP_PLAN ? 'plan ('.implode(', ', $steps).')' : "step '{$step}'";
        $this->auditAttempt($tool, 'awaiting_approval', $clientId, $ticket, $contentHash, "{$targetKey}: staged Control D onboarding {$what}: {$reason}", $actorLabel, $run->id);

        return ['success' => true, 'step' => $step, 'ticket_id' => $ticket->id, 'ticket_display_id' => $ticket->display_id, 'run_id' => $run->id, 'message' => 'Staged for cockpit approval.']
            + ($step === self::STEP_PLAN ? ['steps' => $steps] : []);
    }

    /** The payload keys a plan (or invalidate) proposal pins; approval compares each with a fresh derivation. */
    private const PLAN_PIN_KEYS = ['plan', 'org_pk', 'profile_pk', 'org_inputs', 'code_pk', 'code_pins', 'deploy', 'deploy_request'];

    /**
     * P7A74iGD (b), addition 3 (G-14): the one-approval plan card. Every step and what it writes
     * (Control D: organization, global profile, provisioning code; Tactical: the client custom
     * field and the deploy script run), the pinned code values (no secret), the device list and
     * what can be undone. Plain words.
     *
     * @param  array<string, string>  $inputs
     */
    private function planContent(Client $client, array $inputs, array $pins): string
    {
        $name = $this->fence->neutralizeUntrusted((string) $client->name);
        $steps = $pins['plan'] ?? [];
        $lines = ["Control D onboarding plan for client '{$name}' (#{$client->id}): ".count($steps).' step'.(count($steps) === 1 ? '' : 's').', approved once, run in this order.',
            'Each step runs only after the step before it succeeded. The first step that Control D rejects, or whose outcome is uncertain, stops the plan and nothing after it runs. An uncertain step is never retried and keeps this client locked until it is reconciled. Approval derives the plan again from the client\'s current state and refuses, with nothing written, if it differs from this card (a step already done, a changed setting or pinned value, or a changed device link); stage again then.'];
        $org = $this->fence->neutralizeUntrusted((string) ($pins['org_pk'] ?? $client->controld_org_id ?? ''));
        foreach ($steps as $i => $step) {
            $n = ($i + 1).'. ';
            $lines[] = match ($step) {
                self::STEP_ORGANIZATION => $n.'Organization (writes at Control D): creates a sub-organization named after the client, contact email from the client record ('.$this->fence->neutralizeUntrusted((string) ($pins['org_inputs']['contact_email'] ?? '')).'), two-factor required, analytics region '.($pins['org_inputs']['stats_endpoint'] ?? '').', with the configured global profile '.($pins['org_inputs']['profile_id'] ?? '').', and binds the returned organization id to the client.',
                self::STEP_GLOBAL_PROFILE => $n.'Global profile (writes at Control D): sets the configured global profile '.($pins['profile_pk'] ?? '')." on organization {$org}, which has none set (one update, then a read-back). If a different global profile is set when this runs, the step refuses and writes nothing. ".self::RACE_DISCLOSURE,
                self::STEP_CODE => $n.'Provisioning code (writes at Control D): cuts one provisioning code under the client\'s organization with these pinned values: enforced profile '.($pins['code_pins']['profile_id'] ?? '')
                    .', expiry '.($pins['code_pins']['expiry_days'] ?? '').' days, device limit '.($pins['code_pins']['max'] ?? '').' (asset count '.$client->assets()->count().' + headroom '.ControlDConfig::codeDeviceLimitHeadroom().'), analytics level '.($pins['code_pins']['stats'] ?? '').', intercept mode '.($pins['code_pins']['intercept_mode'] ?? '').', icon '.self::CODE_ICON
                    .'. No deactivation PIN and no hostname prefix. The code is stored encrypted on the client record and is never shown here, in the audit log or in the result.',
                self::STEP_DEPLOY => $n.'Deploy (writes in Tactical RMM): runs only if the provisioning code is bound (by this plan, or before it). Reads custom field #'.($pins['deploy']['field_id'] ?? '').' of Tactical client "'.$this->fence->neutralizeUntrusted((string) ($pins['deploy']['tactical_client'] ?? '')).'". If the field is empty, writes the client\'s provisioning code into it and reads it back; if it already holds that same code, it is left as it is; if it holds a different value, the deploy step refuses and writes nothing. Then it asks Tactical to run the configured deploy script (#'.($pins['deploy']['script_id'] ?? '').'), with no arguments from the PSA, on '
                    .$this->fence->neutralizeUntrusted(ControlDTacticalDeploy::describe($pins['deploy'] ?? [])).'. A device is refused if Tactical does not list it under that client or its link to the PSA asset has changed. '.self::DEPLOY_STARTED_MEANS,
                default => $n.$step,
            };
        }
        $lines[] = 'Undo: '.self::PLAN_UNDO;

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, string>  $inputs
     * @param  array<string, string>  $pins
     */
    private function proposedContent(Client $client, string $step, array $inputs, array $pins = []): string
    {
        // P7A74iGD (b): onboarding steps are staged as a plan (planContent()); only invalidate is staged on its own.
        $name = $this->fence->neutralizeUntrusted((string) $client->name);

        return "Control D: invalidate the provisioning code of client '{$name}' (#{$client->id}), organization ".$this->fence->neutralizeUntrusted((string) ($pins['org_pk'] ?? '')).', code record '.($pins['code_pk'] ?? '').".\n"
            .'Sends one invalidate request for that code to Control D, then reads the code back; only when the read-back shows it invalidated are the code and deactivation PIN removed from the client record. '.self::INVALIDATE_EFFECT."\n"
            .'If the result cannot be confirmed, the step ends uncertain, the code stays on the client record, and nothing is retried. Approval refuses, with nothing written, if the client\'s organization or stored code no longer matches this card, or if Control D, read before the invalidate request, does not show this code record carrying the stored code. The code itself is never shown here, in the audit log or in the result. Cutting a new code afterwards is a separate onboarding proposal.';
    }

    // ── approval ────────────────────────────────────────────────────────────────

    /**
     * Cockpit approval: the ONLY path to ControlDOnboardingStaged. The approver must
     * be an active Admin and, on the button lane, must not be the stager (a second
     * Admin, by ruling); on the token lane the stager must be a live ai_actor token. The
     * P7A74iGD (b): a `plan` proposal goes to approvePlan() after the shared gates below
     * (lane, approver, settings, kill switch); a single-step onboarding proposal staged
     * before the plan refuses (LEGACY_STEP_REFUSAL); the rest of this method handles the
     * `invalidate` step only: re-derived and compared with the card, then one intent
     * (stageInvalidate), then execute.
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
     *
     * #6406: every arm that closes the run checks advanceTo()'s bool. On false (this
     * request no longer holds the run) it writes a lost-fence record (an ids-only warning
     * line and an audit row), never reports 'executed', and its text says this request did
     * not close the run (NOT_CLOSED) instead of that it was closed.
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
                || ! in_array($step, [self::STEP_PLAN, self::STEP_ORGANIZATION, self::STEP_GLOBAL_PROFILE, self::STEP_CODE, self::STEP_INVALIDATE], true)
                || ($step === self::STEP_INVALIDATE && ($payload['staged_by_user_id'] ?? null) === null)) {
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

            if ($step === self::STEP_PLAN) {
                return $this->approvePlan($run, $payload, $client, $ticket, $approver, $approverLabel);
            }
            if ($step !== self::STEP_INVALIDATE) {
                // P7A74iGD (b): onboarding is one plan per approval now; a single-step card staged
                // before that refuses with nothing written (no vendor call) and must be re-staged.
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $contentHash, "{$targetKey}: approval refused — single-step proposal staged before the one-approval plan; nothing was changed at Control D or in Tactical.", $approverLabel, $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined', message: self::LEGACY_STEP_REFUSAL);
            }

            // The step the client needs NOW must be the step the approver read, and for
            // the global-profile step the org and profile PKs on the card must still be
            // the live mapping and setting.
            $derived = $this->invalidateStep($client);
            $pinDrift = in_array($step, [self::STEP_GLOBAL_PROFILE, self::STEP_INVALIDATE], true) && ($derived['step'] ?? null) === $step
                && (($payload['org_pk'] ?? null) !== ($derived['org_pk'] ?? null) || ($payload['profile_pk'] ?? null) !== ($derived['profile_pk'] ?? null)
                    || ($payload['code_pk'] ?? null) !== ($derived['code_pk'] ?? null));
            if (($derived['step'] ?? null) !== $step || $pinDrift) {
                // A refusal that says 'nothing was staged' at staging (unreadable, owned,
                // already onboarded) carries a reason without that clause: this proposal is
                // still staged.
                $why = $derived['reason'] ?? $derived['error'] ?? ($pinDrift
                    ? ($step === self::STEP_INVALIDATE ? "the client's Control D organization or stored code no longer matches the values on this card" : "the client's Control D organization or the configured global profile no longer matches the values on this card")
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

                if ($pinDrift) {
                    // #6393: the pins drift when the org mapping OR only the configured setting
                    // moved, so this text does not say the client's Control D state changed.
                    return new TechnicianApprovalResult('gate_declined', message: "The values pinned on this card are out of date ({$why}). Deny this proposal and stage again so the current values are read and approved on a new card. Nothing was created.");
                }

                return new TechnicianApprovalResult('gate_declined', message: "The client's Control D state changed since this was staged ({$why}). Deny this proposal and stage again so the current step is read and approved on its own card. Nothing was created.");
            }

            $service = $this->onboarding();
            try {
                // Only the invalidate step reaches here; onboarding steps run through approvePlan().
                $intentId = $service->stageInvalidate($approver, (int) $client->id, (string) ($payload['org_pk'] ?? ''), (string) ($payload['code_pk'] ?? ''));
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
                // #6406: a lost claim-owner fence means this request did not close the run; the
                // step still executed, so the result is the fault channel, never 'executed'.
                $closed = $this->closeRunOrRecordLostFence($run, $client->id, $ticket, $contentHash, $targetKey, $approverLabel, $approverId);
                $this->safeAudit($run->action_type, 'executed', $client->id, $ticket, $contentHash, "{$targetKey}: operator-approved Control D onboarding step '{$step}' executed; intent {$intent->id} bound; code record {$intent->vendor_pk} confirmed invalidated by read-back; code and PIN removed from the client record.", $approverLabel, $run->id, $approverId);
                if (! $closed) {
                    return new TechnicianApprovalResult('executed_with_fault', message: "The Control D '{$step}' step executed, but this request did not close the run, because it no longer holds it (another request may have claimed or closed it). Do NOT re-approve it; check Control D and the run. Intent {$intent->id} is bound.");
                }

                return new TechnicianApprovalResult('executed', message: 'Control D provisioning code invalidated and confirmed by read-back; the code and deactivation PIN were removed from the client record. '.self::INVALIDATE_EFFECT.' Cutting a new code is a separate onboarding proposal.');
            }

            if ($state === 'rejected') {
                // Definite vendor refusal: nothing was created, lock released by B3. The
                // proposal is spent; a fresh one may be staged once the cause is fixed.
                // Audited before the run is closed, so a failure closing it (the outer
                // catch keeps a rejected run terminal) never loses the refusal.
                $reason = (string) ($intent->reason ?? 'vendor rejected the write');
                $nothing = $step === self::STEP_GLOBAL_PROFILE ? 'nothing changed' : 'nothing created';
                // The vendor's own sanitized error.message, quoted and attributed to Control D;
                // absent, the text is unchanged.
                $said = self::vendorSaid($intent);
                $this->safeAudit($run->action_type, 'error', $client->id, $ticket, $contentHash, "{$targetKey}: Control D rejected the '{$step}' write — {$reason} (code {$intent->reason_code}); intent {$intent->id} rejected, {$nothing}.{$said}", $approverLabel, $run->id, $approverId);
                $closed = $this->closeRunOrRecordLostFence($run, $client->id, $ticket, $contentHash, $targetKey, $approverLabel, $approverId);

                return new TechnicianApprovalResult('executed_with_fault', message: "Control D rejected the {$step} write: {$reason}".($intent->reason_code === 40301 ? ' — the API key is a Read token; replace it with a Write token in Settings > Integrations, then stage again.' : '.').' '.ucfirst($nothing).'.'.$said.($closed ? '' : ' '.self::NOT_CLOSED));
            }

            // uncertain, posted, or unknown: a vendor write MAY have happened. Terminal;
            // never re-armed. An intent row that still exists holds the client's lock for manual
            // reconciliation; one whose row is gone holds none, and the text says so.
            $closed = $this->closeRunOrRecordLostFence($run, $client->id, $ticket, $contentHash, $targetKey, $approverLabel, $approverId);
            $fault = "HARD FAULT: the Control D '{$step}' write for client #{$client->id} ended {$state}".($intent !== null ? " (intent {$intent->id}, phase {$intent->phase}".($intent->vendor_pk !== null ? ($step === self::STEP_GLOBAL_PROFILE ? ", org PK {$intent->vendor_pk}" : ", vendor PK {$intent->vendor_pk}") : '').')' : '')
                .match ($step) {
                    self::STEP_GLOBAL_PROFILE => '. The vendor PUT may have committed.',
                    self::STEP_INVALIDATE => '. The invalidate request may or may not have taken effect; the code and PIN were kept on the client record.',
                    default => '. The vendor POST may have committed.',
                }.' Do NOT re-approve or re-stage: reconcile upstream and local state by hand; '.($intent !== null
                    ? 'the intent keeps this client\'s onboarding lock until then.'
                    : "intent {$intentId} could not be found afterwards, so it holds no onboarding lock and does not block a new proposal for this client; do not stage one until then.")
                .($closed ? '' : ' '.self::NOT_CLOSED);
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
                // #6406: a false close (lost fence) is recorded and the text says this request
                // did not close the run; a close that throws is logged and the run stays claimed.
                $closed = true;
                try {
                    $closed = $this->closeRunOrRecordLostFence($run, (int) $run->client_id, null, (string) $run->content_hash, "onboard: intent {$intentId}", $label, $approverId);
                } catch (\Throwable) {
                    \Illuminate\Support\Facades\Log::error('[StaffControlDOnboardingToolExecutor] Closing the run failed after its intent could not be read', ['run_id' => $run->id]);
                }

                return new TechnicianApprovalResult('executed_with_fault', message: "HARD FAULT: this approval failed and Control D onboarding intent {$intentId} could not be read afterwards, so a vendor write cannot be ruled out. Do NOT re-approve; reconcile by hand.".($closed ? '' : ' '.self::NOT_CLOSED));
            }
            if ($caughtState === 'rejected') {
                $label = $this->approverLabel($approverId);
                $this->safeAudit($run->action_type, 'error', (int) $run->client_id, null, (string) $run->content_hash, "onboard: finishing after Control D rejected the write failed; intent {$intentId} rejected; run NOT reopened.", $label, $run->id, $approverId);
                if (! $this->closeRunOrRecordLostFence($run, (int) $run->client_id, null, (string) $run->content_hash, "onboard: intent {$intentId}", $label, $approverId)) {
                    return new TechnicianApprovalResult('executed_with_fault', message: "Control D rejected the onboarding write (intent {$intentId}); finishing this approval failed afterwards. ".self::NOT_CLOSED.' Stage a fresh proposal once the cause is fixed.');
                }

                return new TechnicianApprovalResult('executed_with_fault', message: "Control D rejected the onboarding write (intent {$intentId}); finishing this approval failed afterwards. The proposal is closed and was not reopened: stage a fresh one once the cause is fixed.");
            }
            if ($intentId !== null && ! in_array($caughtState, ['staged', ControlDOnboardingStaged::RELEASED], true)) {
                // Post-admission: a write may have happened. Keep the run terminal.
                $label = $this->approverLabel($approverId);
                $this->safeAudit($run->action_type, 'error', (int) $run->client_id, null, (string) $run->content_hash, "onboard: finalizing after a possible vendor write failed; intent {$intentId}; run NOT reopened.", $label, $run->id, $approverId);
                $closed = $this->closeRunOrRecordLostFence($run, (int) $run->client_id, null, (string) $run->content_hash, "onboard: intent {$intentId}", $label, $approverId);

                return new TechnicianApprovalResult('executed_with_fault', message: "HARD FAULT: recording the Control D onboarding outcome failed after a possible vendor write (intent {$intentId}). Do NOT re-approve; reconcile by hand.".($closed ? '' : ' '.self::NOT_CLOSED));
            }
            $run->releaseClaim();

            throw $e;
        }
    }

    /** Plain words for the part of a plan that differs at approval (PLAN_PIN_KEYS). */
    private const PLAN_DRIFT_WORDS = [
        'plan' => 'the steps the client needs now differ from the steps on this card (a step may already be done)',
        'org_pk' => "the client's Control D organization or the configured global profile no longer matches the values on this card",
        'profile_pk' => "the client's Control D organization or the configured global profile no longer matches the values on this card",
        'org_inputs' => "the client's name or contact email, or the configured analytics region or global profile, differs from the values on this card",
        'code_pk' => 'the stored code record differs from the one on this card',
        'code_pins' => 'the derived code values (device limit, expiry, analytics level, intercept mode or enforced profile) differ from the ones pinned on this card',
        'deploy' => "the deploy scope, the Tactical client, a device's Tactical agent link, or the configured Tactical client custom field ID or deploy script ID differs from the one on this card",
        'deploy_request' => 'the deploy scope differs from the one on this card',
    ];

    /**
     * P7A74iGD (b): approve a one-approval plan. The plan is derived again from the client's
     * current state and must equal the card's (every PLAN_PIN_KEYS value), else it refuses with
     * nothing written and the run goes back to awaiting approval for the approver to deny. Then
     * the Control D steps run in order through ControlDOnboardingStaged (one intent each): bound
     * continues; anything else stops, and later steps are recorded as not run. The deploy step
     * runs only when the code step bound in this run, or (a deploy-only plan) the client already
     * stores its code. One audit row per step plus one summary row, ids and status only. The run
     * closes Done (Jeeves 10:51Z: no new run state in (b)); when a step did not succeed, the
     * result text and the summary row name that step and say the run did not complete. A first
     * step refused before any write (nothing done) returns the run to awaiting approval instead.
     */
    private function approvePlan(TechnicianRun $run, array $payload, Client $client, Ticket $ticket, User $approver, string $approverLabel): TechnicianApprovalResult
    {
        $approverId = (int) $approver->id;
        $hash = (string) $run->content_hash;
        $key = "onboard:{$client->id}:plan";
        $derived = $this->derivePlan($client, $payload['deploy_request'] ?? null);
        $why = isset($derived['error']) ? (string) ($derived['reason'] ?? $derived['error']) : null;
        $drift = null;
        foreach ($why === null ? self::PLAN_PIN_KEYS : [] as $pin) {
            if (($payload[$pin] ?? null) !== ($derived[$pin] ?? null)) {
                $drift = $pin;
                $why = $pin === 'plan'
                    ? "the client now needs the steps '".implode(', ', $derived['plan'] ?? [])."', not '".implode(', ', is_array($payload['plan'] ?? null) ? $payload['plan'] : [])."'"
                    : self::PLAN_DRIFT_WORDS[$pin];
                break;
            }
        }
        if ($why !== null || ! is_array($payload['plan'] ?? null) || $payload['plan'] === []) {
            $why ??= 'the card carries no steps';
            $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $hash, "{$key}: approval refused — {$why}; nothing was changed at Control D or in Tactical.", $approverLabel, $run->id, $approverId);
            $run->releaseClaim();
            // The per-cause texts the single-step approval used, so each refusal still says what is true of it.
            $message = match (true) {
                (bool) ($derived['unreadable'] ?? false) => "Control D onboarding approval could not re-check the client's current step ({$why}). Nothing was created. Approving again helps only if the cause was temporary; otherwise fix it or deny this proposal.",
                (bool) ($derived['different'] ?? false) => "This organization's global profile does not match the configured profile ({$why}). This proposal cannot be approved, and staging again is refused the same way, while this organization enforces a global profile other than the configured one. Nothing was created.",
                (bool) ($derived['underivable'] ?? false) => self::CODE_PINS_UNDERIVABLE,
                $drift === 'code_pins' => 'The code values pinned on this card (device limit, expiry, analytics level, intercept mode or enforced profile) no longer match the current Control D settings or the client\'s asset count. Deny this proposal and stage again so the current values are shown and approved on a new card. Nothing was created and nothing was changed at Control D.',
                in_array($drift, ['org_pk', 'profile_pk', 'org_inputs', 'deploy', 'deploy_request'], true) => "The values pinned on this card are out of date ({$why}). Deny this proposal and stage again so the current values are read and approved on a new card. Nothing was created.",
                default => "The client's Control D state changed since this was staged ({$why}). Deny this proposal and stage again so the current plan is read and approved on its own card. Nothing was created.",
            };

            return new TechnicianApprovalResult('gate_declined', message: $message);
        }

        $steps = $payload['plan'];
        $outcomes = [];
        $lines = [];
        $stopped = false;
        $wrote = false;
        $firstRefusal = null;
        $current = null;
        try {
            foreach ($steps as $step) {
                if ($step === self::STEP_DEPLOY) {
                    continue;
                }
                if ($stopped) {
                    $outcomes[$step] = 'not-run';
                    $this->safeAudit($run->action_type, 'blocked', $client->id, $ticket, $hash, "{$key}:{$step}: not run; an earlier step of this plan did not succeed.", $approverLabel, $run->id, $approverId);

                    continue;
                }
                $current = $step;
                $r = $this->runPlanStep($step, $payload, $client, $approver);
                $firstRefusal ??= $r['state'] === 'refused' && $outcomes === [] ? $r['refusal'] : null;
                $outcomes[$step] = $r['state'];
                $wrote = $wrote || $r['state'] !== 'refused';
                $lines[] = $r['line'];
                $this->safeAudit($run->action_type, $r['state'] === 'bound' ? 'executed' : 'error', $client->id, $ticket, $hash, "{$key}:{$step}: ".$r['audit'], $approverLabel, $run->id, $approverId);
                $stopped = $r['state'] !== 'bound';
            }

            if (in_array(self::STEP_DEPLOY, $steps, true)) {
                // THE DEPLOY GATE (addition 1): only after a code bound in this run, or one already bound.
                $codeBound = in_array(self::STEP_CODE, $steps, true)
                    ? ($outcomes[self::STEP_CODE] ?? null) === 'bound'
                    : Client::find($client->id)?->getRawOriginal('controld_provisioning_code') !== null;
                if (! $codeBound) {
                    $outcomes[self::STEP_DEPLOY] = 'not-run';
                    $lines[] = 'Deploy: not run, because the provisioning code is not bound; nothing was written in Tactical and no script was run.';
                    $this->safeAudit($run->action_type, 'blocked', $client->id, $ticket, $hash, "{$key}:deploy: not run; the provisioning code is not bound, so no Tactical write and no script run.", $approverLabel, $run->id, $approverId);
                } else {
                    $current = self::STEP_DEPLOY;
                    $d = (new ControlDTacticalDeploy(app(TacticalClient::class)))->execute($client, $payload['deploy']);
                    $outcomes[self::STEP_DEPLOY] = $d['outcome'];
                    $wrote = $wrote || ! in_array($d['field'], ['untouched', 'equal'], true) || array_diff($d['agents'], [ControlDTacticalDeploy::AGENT_REFUSED]) !== [];
                    $agents = implode(', ', array_map(static fn (string $id, string $s): string => "{$id}={$s}", array_keys($d['agents']), $d['agents']));
                    $lines[] = "Deploy: {$d['outcome']}: {$d['reason']}.".($d['agents'] !== [] ? " Agents: {$agents}. ".self::DEPLOY_STARTED_MEANS : '');
                    $this->safeAudit($run->action_type, $d['outcome'] === ControlDTacticalDeploy::STARTED ? 'executed' : 'error', $client->id, $ticket, $hash,
                        "{$key}:deploy: {$d['outcome']}; Tactical client #".($d['tactical_client_id'] ?? 'none').", field {$d['field']}; {$d['reason']}".($agents !== '' ? "; agents: {$agents}" : '').'.', $approverLabel, $run->id, $approverId);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[StaffControlDOnboardingToolExecutor] The onboarding plan failed while running', ['run_id' => $run->id, 'exception' => $e::class]);
            $outcomes[$current ?? 'unknown'] = 'error';
            $wrote = true;
            $lines[] = 'HARD FAULT: the plan failed while running, so a write cannot be ruled out for the step that was running. Do NOT re-approve; check Control D, Tactical and the client\'s onboarding intents.';
        }

        $ok = array_diff($outcomes, ['bound', ControlDTacticalDeploy::STARTED]) === [];
        $summary = implode(', ', array_map(static fn (string $s, string $o): string => "{$s}={$o}", array_keys($outcomes), $outcomes));
        $failed = null;
        foreach ($outcomes as $s => $o) {
            if (! in_array($o, ['bound', ControlDTacticalDeploy::STARTED], true)) {
                $failed = [$s, $o];
                break;
            }
        }
        $incomplete = $failed === null ? '' : "The onboarding run did not complete: the '{$failed[0]}' step ended {$failed[1]}.";
        if (! $ok && ! $wrote) {
            // The first step refused before any write; nothing was done, so the card may be approved again.
            $this->safeAudit($run->action_type, 'error', $client->id, $ticket, $hash, "{$key}: plan summary: {$summary}; run did not complete; failed step: {$failed[0]} ({$failed[1]}); refused before any write; run returned to awaiting approval.", $approverLabel, $run->id, $approverId);
            $run->releaseClaim();
            // A Control D step refused before admission keeps the single-step text (it is the same fact).
            $first = $firstRefusal ?? null;

            return new TechnicianApprovalResult('gate_declined', message: $first !== null
                ? 'Control D onboarding was refused before any vendor write: '.$first
                : 'The onboarding plan was refused before anything was written. '.implode(' ', $lines));
        }
        // Status only (no secret): the client page's Control D card shows a run that did not complete.
        // Recorded on the stored row first (that key only, never the state), so the card shows it even
        // when the close below throws or loses its fence; the fenced close also carries it.
        $outcome = ['completed' => $ok, 'failed_step' => $failed[0] ?? null, 'failed_state' => $failed[1] ?? null, 'steps' => $outcomes];
        try {
            $run->getConnection()->transaction(function () use ($run, $outcome): void {
                $stored = TechnicianRun::query()->whereKey($run->id)->lockForUpdate()->first(['id', 'proposed_meta']);
                if ($stored !== null) {
                    $stored->proposed_meta = array_merge((array) ($stored->proposed_meta ?? []), ['plan_outcome' => $outcome]);
                    $stored->save();
                }
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[StaffControlDOnboardingToolExecutor] Recording the onboarding plan outcome failed', ['run_id' => $run->id, 'exception' => $e::class]);
        }
        $meta = $run->proposed_meta;
        $meta['plan_outcome'] = $outcome;
        $run->proposed_meta = $meta;
        $closeFailed = false;
        try {
            // Jeeves 10:51Z: no new run state in (b). The run closes Done either way; when a step did not
            // succeed, the result, the summary row and the cockpit message say so and name the step.
            $closed = $this->closeRunOrRecordLostFence($run, $client->id, $ticket, $hash, $key, $approverLabel, $approverId);
        } catch (\Throwable $e) {
            // A step may have written, so the run is never released for re-approval: it stays claimed.
            \Illuminate\Support\Facades\Log::error('[StaffControlDOnboardingToolExecutor] Closing the onboarding plan run failed', ['run_id' => $run->id, 'exception' => $e::class]);
            $closed = false;
            $closeFailed = true;
        }
        $this->safeAudit($run->action_type, $ok ? 'executed' : 'error', $client->id, $ticket, $hash, "{$key}: plan summary: {$summary}; ".($ok ? 'run completed' : "run did not complete; failed step: {$failed[0]} ({$failed[1]})").'; run '.($closed ? 'closed (state done)' : ($closeFailed ? 'close failed; left claimed, not reopened' : 'not closed by this request')).'.', $approverLabel, $run->id, $approverId);
        $text = ($ok ? 'Control D onboarding plan completed. ' : $incomplete.' ').implode(' ', $lines)
            .($ok ? '' : ($closed ? ' The run is closed and is not re-approved; its state reads done, which here does not mean every step succeeded.' : '').' Steps already bound are not redone when the plan is staged again; a step that ended uncertain must be reconciled first.');
        if ($closeFailed) {
            return new TechnicianApprovalResult('executed_with_fault', message: $text.' Closing the run failed afterwards, so it stays claimed and was not reopened. Do NOT re-approve it; check the run.');
        }
        if (! $closed) {
            return new TechnicianApprovalResult('executed_with_fault', message: $text.' '.self::NOT_CLOSED);
        }

        return new TechnicianApprovalResult($ok ? 'executed' : 'executed_with_fault', message: $text);
    }

    /**
     * One Control D step of a plan: stage its intent and execute it. Returns the intent's state
     * ('refused' when nothing was admitted), its id, a result line and an ids-and-status audit text.
     *
     * @return array{state: string, intent: ?string, line: string, audit: string, refusal?: string}
     */
    private function runPlanStep(string $step, array $payload, Client $client, User $approver): array
    {
        $service = $this->onboarding();
        $intentId = null;
        $refusal = null;
        try {
            if ($step === self::STEP_ORGANIZATION) {
                // The inputs pinned on the card (compared with a fresh derivation at approval); never re-read here.
                $inputs = $payload['org_inputs'] ?? null;
                if (! is_array($inputs) || ! is_string($inputs['name'] ?? null) || ! is_string($inputs['contact_email'] ?? null) || ! is_string($inputs['stats_endpoint'] ?? null)
                    || ($inputs['profile_id'] ?? null) !== ControlDConfig::defaultProfileId()) {
                    throw new ControlDClientException('The organization values pinned on this card are missing, or the configured global profile changed after approval began; nothing was written. Deny this proposal and stage again.');
                }
                $intentId = $service->stageOrganization($approver, (int) $client->id, $inputs['name'], $inputs['contact_email'], self::REQUIRE_MFA, $inputs['stats_endpoint']);
            } elseif ($step === self::STEP_GLOBAL_PROFILE) {
                $intentId = $service->stageGlobalProfile($approver, (int) $client->id, (string) ($payload['org_pk'] ?? ''), (string) ($payload['profile_pk'] ?? ''));
            } else {
                $intentId = $service->stageCode($approver, (int) $client->id, self::CODE_ICON, null, null, $payload['code_pins'] ?? null);
            }
            $service->execute($approver, $intentId);
        } catch (ControlDClientException $e) {
            $refusal = mb_substr($e->getMessage(), 0, 600);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[StaffControlDOnboardingToolExecutor] An onboarding plan step failed', ['intent_id' => $intentId, 'step' => $step, 'exception' => $e::class]);
            $refusal = 'the step failed locally ('.class_basename($e).')';
        }
        // Read the intent's outcome; one failed read is tried once more, and a second failure is
        // 'unknown' (a write cannot be ruled out), never 'refused'.
        $intent = null;
        $readable = $intentId === null;
        for ($try = 0; ! $readable && $try < 2; $try++) {
            try {
                $intent = ControlDOnboardingIntent::find($intentId);
                $readable = true;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('[StaffControlDOnboardingToolExecutor] The onboarding intent could not be read after its plan step', ['intent_id' => $intentId, 'exception' => $e::class]);
            }
        }
        $state = $intentId === null ? 'refused' : ($intent?->state ?? 'unknown');
        if (in_array($state, ['staged', ControlDOnboardingStaged::RELEASED], true)) {
            $state = 'refused';
        }
        $label = ucfirst(str_replace('-', ' ', $step));
        $id = $intentId !== null ? " (intent {$intentId})" : '';
        if ($state === 'refused') {
            $held = $intent?->state === 'staged' && (int) $intent->active_client_id === (int) $client->id ? " Intent {$intent->id} remains staged and holds this client's onboarding lock; an Admin can release it from the Control D onboarding card on the client page (shown while client onboarding is enabled) before another proposal." : '';
            $refusal = ($refusal ?? 'refused').$held;

            return ['state' => $state, 'intent' => $intentId, 'refusal' => $refusal, 'line' => "{$label}: refused before any write to Control D: {$refusal}", 'audit' => "refused before any vendor write{$id} — {$refusal}"];
        }
        if ($state === 'bound') {
            $what = match ($step) {
                self::STEP_ORGANIZATION => "organization {$intent->org_pk} created and bound",
                self::STEP_GLOBAL_PROFILE => "global profile confirmed on organization {$intent->org_pk}",
                default => 'provisioning code cut and stored encrypted on the client record',
            };

            return ['state' => $state, 'intent' => $intentId, 'line' => "{$label}: done ({$what}).", 'audit' => "bound{$id}; {$what}"];
        }
        if ($state === 'rejected') {
            $said = self::vendorSaid($intent);

            // The single-step rejection text (#6522), unchanged: the vendor's reason, the read-token fix, and Control D's own message.
            $nothing = $step === self::STEP_GLOBAL_PROFILE ? 'nothing changed' : 'nothing created';
            $reason = (string) ($intent->reason ?? 'vendor rejected the write');

            return ['state' => $state, 'intent' => $intentId,
                'line' => "Control D rejected the {$step} write: {$reason}".($intent->reason_code === 40301 ? ' — the API key is a Read token; replace it with a Write token in Settings > Integrations, then stage again.' : '.').' '.ucfirst($nothing).'.'.$said,
                'audit' => "Control D rejected the '{$step}' write — {$reason} (code {$intent->reason_code}); intent {$intent->id} rejected, {$nothing}.{$said}"];
        }

        // The single-step HARD FAULT text, unchanged: the step, the state, the PK and which write may have committed.
        $fault = "HARD FAULT: the Control D '{$step}' write for client #{$client->id} ended {$state}".($intent !== null ? " (intent {$intent->id}, phase {$intent->phase}".($intent->vendor_pk !== null ? ($step === self::STEP_GLOBAL_PROFILE ? ", org PK {$intent->vendor_pk}" : ", vendor PK {$intent->vendor_pk}") : '').')' : '')
            .($step === self::STEP_GLOBAL_PROFILE ? '. The vendor PUT may have committed.' : '. The vendor POST may have committed.')
            .' Do NOT re-approve or re-stage: reconcile upstream and local state by hand; '.($intent !== null
                ? 'the intent keeps this client\'s onboarding lock until then; it is never retried.'
                : "intent {$intentId} could not be found afterwards, so it holds no onboarding lock and does not block a new proposal for this client; do not stage one until then.");

        return ['state' => $state, 'intent' => $intentId, 'line' => $fault, 'audit' => $fault];
    }

    /**
     * ' Control D's message (code N): "<message>"' when the rejected intent kept the
     * vendor's sanitized error.message (reason_detail), else ''. The text is Control D's,
     * quoted; the PSA asserts nothing about what it means. The quote is a JSON string, so an
     * embedded double quote or backslash is escaped and cannot end the attribution early;
     * text with neither is quoted unchanged.
     */
    private static function vendorSaid(ControlDOnboardingIntent $intent): string
    {
        $detail = $intent->reason_detail;
        if (! is_string($detail) || $detail === '') {
            return '';
        }

        return " Control D's message (code {$intent->reason_code}): ".json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
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

    /**
     * #6429: the #6408 warning's message for a ControlDClientException from the step
     * derivation read (globalProfileState()). Each known client message maps to a fixed text
     * of this executor's own; the two requestParent() texts that say 'outcome may be unknown'
     * (written for its POST) are logged as read failures, since this path sends only a GET.
     * Any other message is logged as the fixed fallback, never as itself.
     */
    private static function readFailureMessage(ControlDClientException $e): string
    {
        return match ($e->getMessage()) {
            ControlDConfig::DEFAULT_PROFILE_SETTING.' is required to onboard.' => ControlDConfig::DEFAULT_PROFILE_SETTING.' is required to onboard.',
            'Control D is disabled or unconfigured.' => 'Control D is disabled or unconfigured.',
            'Control D parent request is invalid or unconfigured.' => 'Control D parent request is invalid or unconfigured.',
            'Control D parent request failed; outcome may be unknown.' => 'Control D parent request failed (read-only GET).',
            'Control D parent response is unconfirmed; outcome may be unknown.' => 'Control D parent response is unconfirmed (read-only GET).',
            'Control D parent inventory is malformed.' => 'Control D parent inventory is malformed.',
            'Control D organization is not uniquely listed in the parent inventory.' => 'Control D organization is not uniquely listed in the parent inventory.',
            'Control D global profile field is malformed.' => 'Control D global profile field is malformed.',
            default => 'Unrecognised Control D client refusal.',
        };
    }

    /**
     * #6406: land the run Done, or record that this request did not. advanceTo() returns false
     * when this request no longer holds the run (another claim, or the run left Executing);
     * then this request did not close it (another request may have claimed or closed it)
     * and did not reopen it. The record is an
     * ids-only warning line and an audit row (safeAudit(), so a failed audit is logged
     * status-only and never throws). A throw from advanceTo() is not caught here.
     */
    private function closeRunOrRecordLostFence(TechnicianRun $run, ?int $clientId, ?Ticket $ticket, string $contentHash, string $prefix, string $actorLabel, int $approverId): bool
    {
        if ($run->advanceTo(TechnicianRunState::Done)) {
            return true;
        }
        \Illuminate\Support\Facades\Log::warning('[StaffControlDOnboardingToolExecutor] This request did not close the run: it no longer holds it', ['run_id' => $run->id, 'action' => $run->action_type]);
        $this->safeAudit($run->action_type, 'error', $clientId, $ticket, $contentHash, "{$prefix}: this request did not close the run, because it no longer holds it (another request may have claimed or closed it); this request did not reopen it.", $actorLabel, $run->id, $approverId);

        return false;
    }

    /**
     * #6394 (C-56): a failed audit row is logged status-only: the run id, the result status and
     * the exception class. Never the exception message: a QueryException's message carries the
     * SQL with its bindings (the actor label, the summary, the content hash, the client id).
     */
    private function safeAudit(string $actionType, string $resultStatus, ?int $clientId, ?Ticket $ticket, string $contentHash, string $summary, string $actorLabel, ?int $runId = null, ?int $approverId = null): void
    {
        try {
            $this->auditAttempt($actionType, $resultStatus, $clientId, $ticket, $contentHash, $summary, $actorLabel, $runId, $approverId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[StaffControlDOnboardingToolExecutor] Post-write audit row failed', ['run_id' => $runId, 'result_status' => $resultStatus, 'exception' => $e::class]);
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
            'description' => 'Onboard ONE PSA client to Control D as ONE plan with ONE approval. The plan is the not-yet-done subset of these steps, run in order: (1) create the client\'s Control D sub-organization (name and contact email from the client record, two-factor required, the panel\'s analytics region, the configured global profile) and bind the returned organization id to the client; or, for a mapped sub-organization with no global profile set, set the configured global profile on it (refused if a different one is already set when staged, when approved, or at the read just before the update; this code sends an unconditional update, so one set after that last read is not detected and the update may replace it); (2) cut one provisioning code under that organization with the Settings > Integrations > Control D defaults (enforced profile, expiry, device limit = asset count + headroom, analytics level, intercept mode), pinned on the card; (3) optional, only when deploy is given: in Tactical RMM, write the bound code into the configured client custom field if it is empty (a different non-empty value refuses), then request the configured deploy script on all of the client\'s Tactical agents or on the given PSA assets\' linked agents, with no script arguments. The plan stops at the first step that is rejected or uncertain; deploy runs only when the code is bound. An onboarded client may stage a deploy-only plan. HELD-ONLY: never executes immediately, whatever mode was granted — every call needs staged=true and a ticket_id and is approved in the cockpit by an active Admin. Only an MCP token marked ai_actor may stage it (the agent stages, one human approves); a person onboards from the Control D card on the client page, where a second Admin must approve. No PIN, hostname prefix, icon, profile, limit or vendor PK is accepted from the caller; secrets are stored encrypted on the client record and never returned. Inert unless the Control D onboarding switch is on and all six defaults are configured. Requires an explicit token grant, reason, kill-switch, and TechnicianActionLog audit.',
            'input_schema' => ['type' => 'object', 'properties' => self::properties(), 'required' => ['reason']],
        ];
    }

    /** @return array<string, mixed> */
    private static function stageOnboardTool(): array
    {
        return [
            'name' => self::STAGED_TOOL,
            'description' => 'Stage the Control D onboarding plan for one client (the not-yet-done steps of: organization create or global profile, provisioning code, and an optional Tactical deploy) for ONE cockpit approval by an active Admin. Staging requires an ai_actor token (a person uses the client-page button, which needs a second Admin). The MCP call makes no Control D or Tactical write; the held proposal stores ids, the steps and their pinned values (organization and profile PKs, the code values, the deploy scope and each selected asset\'s Tactical agent), and approval derives the plan again and refuses unless it is identical. Held-only — there is no immediate execution path.',
            'input_schema' => ['type' => 'object', 'properties' => self::properties(true), 'required' => ['ticket_id', 'reason']],
        ];
    }

    /** @return array<string, array<string, string>> */
    private static function properties(bool $ticket = false): array
    {
        $properties = [
            'reason' => ['type' => 'string', 'description' => 'Why this client is being onboarded to Control D now. Shown to the approver and recorded in the audit log.'],
            'deploy' => ['description' => 'Optional deploy step through Tactical RMM: the string "all" (every agent Tactical lists under the client when approved) or an array of PSA asset ids of this client, each linked to one Tactical agent. Omit it to stage the Control D steps only.'],
        ];
        if ($ticket) {
            $properties['ticket_id'] = ['type' => 'integer', 'description' => 'PSA ticket this onboarding belongs to. Must belong to client_id; the staged proposal is held on this ticket for cockpit approval.'];
        }

        return $properties;
    }
}
