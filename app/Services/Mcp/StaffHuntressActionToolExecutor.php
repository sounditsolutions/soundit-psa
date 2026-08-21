<?php

namespace App\Services\Mcp;

use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Models\Client;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Huntress\HuntressClient;
use App\Services\Huntress\HuntressClientException;
use App\Services\Huntress\HuntressEscalationAlreadyResolvedException;
use App\Services\Huntress\HuntressEscalationNotApiResolvableException;
use App\Services\Huntress\HuntressResolveOutcomeUnknownException;
use App\Services\Huntress\HuntressWriteClient;
use App\Services\Huntress\HuntressWriteScopeException;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Services\Technician\PromptFence;
use App\Services\Technician\TechnicianApprovalResult;
use App\Support\HuntressConfig;
use App\Support\TechnicianConfig;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Huntress SOC escalation actions for the staff MCP surface — the write lane
 * the P1 reads (HuntressReadOnlyToolset, psa-shej) deliberately did not carry.
 * One capability: resolve ONE escalation by id, held-only, with the staged
 * twin `huntress_stage_resolve_escalation`. Design doc:
 * docs/superpowers/plans/2026-08-20-huntress-resolve-escalation-mcp-tool.md.
 *
 * THE WHOLE PARAMETERISED RESOLUTION BODY IS REFUSED, not allow-listed. The
 * vendor's `EscalationResolutionParameters` carries `determination`, `scope`
 * (account-wide access rules), `revoke_and_disable_identities` (boolean,
 * default TRUE — revokes sessions and disables identities), and
 * `expiration_date`. An allow-list of those names is a prose inventory of a
 * schema we do not control; instead the accepted argument keys are pinned
 * (ALLOWED_ARGUMENT_KEYS) and everything else — known-dangerous or unknown —
 * is refused by name, fail closed. HuntressWriteClient pins the HTTP body to
 * the literal `{}` so no accepted argument can reach it either.
 *
 * POST-CONDITION, not just request shape: the 201 response's required
 * `resolution_method` is the code path the server took — `direct` and
 * `dismiss` are the id-only outcomes; `rule` means attribute rules WERE
 * created upstream. The approval treats `rule` (or any unrecognised value) as
 * a hard fault: audit row `error`, operator-facing fault message — while the
 * run still advances to Done, because the upstream state DID change and a
 * fault that executed must never be reported as declined.
 *
 * DATA-BOUNDARY RULE (the Huntress account can be shared across MSPs): the
 * tool is client-scoped and v1 resolves only escalations whose organization
 * set is EXACTLY the calling client's mapped organization
 * (clients.huntress_organization_id). The upstream POST closes the whole
 * escalation record, not a per-org slice, so an any-of match would let this
 * client's approver resolve a SOC record that also covers another MSP's
 * tenant — MULTI-ORGANIZATION escalations are therefore refused, as are
 * account-level ones (no organization association): no single PSA client owns
 * either, so there is no cockpit lane to hold them against.
 */
class StaffHuntressActionToolExecutor
{
    private const DIRECT_DEDUP_HOURS = 24;

    private const COOLDOWN_SECONDS = 300;

    /**
     * How long an Executing claim may stand before a re-stage treats it as
     * DEAD and revives the run. BOTH legs of the approve path are bounded far
     * below this: the LIVE re-read (a HuntressClient::withClampedBackoff()
     * clone — the base read client stays unclamped for the claimless
     * background readers) and the resolution POST (HuntressWriteClient,
     * always clamped) each cap at 3 attempts x a 30 s transport timeout plus
     * two 429 back-offs clamped to their client's RETRY_AFTER_CEILING_SECONDS
     * — ~220 s worst case. The approve-path clamp is load-bearing here, not
     * incidental: with an unclamped Retry-After an upstream `Retry-After:
     * 1200` could park a LIVE approval well past this bound, and the recovery
     * below would then reopen a run whose
     * approval was still in flight. So no live approval is ever this old; a
     * claim that outlives it means the worker died
     * between claimForExecution() and any catch block — and this family is
     * deliberately excluded from the stale-claim reaper's
     * TechnicianRun::RECOVERY_SAFE_ACTION_TYPES, so nothing else will ever
     * release it.
     */
    private const STALE_CLAIM_SECONDS = 900;

    private const REASON_MAX = 500;

    private const SUBJECT_DISPLAY_MAX = 300;

    /** @var array<string, string> */
    private const STAGED_TO_DIRECT = [
        'huntress_stage_resolve_escalation' => 'huntress_resolve_escalation',
    ];

    /**
     * The ONLY argument keys a call may carry. `staged` is the harness's mode
     * flag (consumed by the controller, tolerated here); `client_id` is
     * stripped by the controller before dispatch. Everything else is refused —
     * including every field of the vendor's resolution body and anything the
     * vendor adds later.
     *
     * @var array<int, string>
     */
    private const ALLOWED_ARGUMENT_KEYS = ['escalation_id', 'ticket_id', 'reason', 'staged'];

    /**
     * The resolution-body fields we KNOW are dangerous, named in the refusal
     * so the caller learns the rule, not just the rejection. Refusal does not
     * depend on this list — any unknown key refuses identically.
     *
     * @var array<int, string>
     */
    private const KNOWN_RESOLUTION_BODY_KEYS = [
        'determination',
        'scope',
        'revoke_and_disable_identities',
        'expiration_date',
        'resolution_method',
    ];

    /** The 201 resolution_method values an id-only `{}` call may legitimately produce. */
    private const SAFE_RESOLUTION_METHODS = ['direct', 'dismiss'];

    public function __construct(
        private readonly HuntressWriteClient $writeClient,
        private readonly ActionRedactor $redactor,
        private readonly PromptFence $fence,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            self::resolveEscalationTool(),
            self::stageResolveEscalationTool(),
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

    /** @return array<string, string> */
    public static function stagedToDirectMap(): array
    {
        return self::STAGED_TO_DIRECT;
    }

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel): array
    {
        if (! HuntressConfig::isEnabled() || ! HuntressConfig::isConfigured()) {
            return ['error' => 'Huntress is not configured'];
        }

        if (! HuntressConfig::isWriteConfigured()) {
            return ['error' => 'Huntress write credential (user-based API key) is not configured; escalation resolution is unavailable until it is added in Settings > Integrations.'];
        }

        if (isset(self::STAGED_TO_DIRECT[$name])) {
            return $this->stageResolveEscalation($name, $arguments, $clientId, $actorLabel);
        }

        if ($name === 'huntress_resolve_escalation') {
            // Structurally held-only: the controller rewrites staged=true calls
            // to the staged internal name, so a call arriving under the
            // canonical name IS an immediate-execution attempt — refused
            // whatever mode was granted.
            $contentHash = $this->contentHash($name, $clientId, null, $arguments);
            $message = 'huntress_resolve_escalation is held-only — resolving a SOC escalation is a security record change and never executes immediately, whatever mode was granted; call it with staged=true and a ticket_id for cockpit approval. Nothing was resolved.';
            $this->auditAttempt($name, 'rejected', $clientId, null, $contentHash, $message, $actorLabel);

            return ['error' => $message];
        }

        return ['error' => "Unknown Huntress action tool: {$name}"];
    }

    /** @return array<string, mixed> */
    private function stageResolveEscalation(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $directTool = self::STAGED_TO_DIRECT[$tool];
        $contentHash = $this->contentHash($tool, $clientId, null, $arguments);

        // Whole-body refusal FIRST, before any other processing: any key
        // outside the pinned set — the vendor's resolution-body fields or
        // anything unknown — refuses the call outright.
        if ($unexpected = $this->unexpectedArgumentKeys($arguments)) {
            $known = array_values(array_intersect($unexpected, self::KNOWN_RESOLUTION_BODY_KEYS));
            $message = 'Unexpected parameter'.(count($unexpected) === 1 ? '' : 's').' refused: '.implode(', ', $unexpected).'. '
                .'This tool is id-only by construction — the upstream resolution body is always the empty object {}, and the parameterised form ('
                .implode(', ', self::KNOWN_RESOLUTION_BODY_KEYS).') is structurally refused'
                .($known !== [] ? ' (it can revoke sessions, disable identities, and create account-wide access rules)' : '')
                .'. Pass escalation_id, ticket_id, and reason only. Nothing was staged.';
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
            $this->auditAttempt($tool, 'blocked', $clientId, null, $contentHash, 'Technician kill-switch engaged; Huntress MCP write refused.', $actorLabel);

            return ['error' => 'Technician kill-switch engaged; Huntress MCP write refused'];
        }

        $client = Client::find($clientId);
        if (! $client) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, 'Client not found.', $actorLabel);

            return ['error' => 'Client not found'];
        }

        $escalationId = $this->positiveInt($arguments['escalation_id'] ?? null);
        if ($escalationId === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, 'escalation_id is required.', $actorLabel);

            return ['error' => 'escalation_id is required and must be a positive integer'];
        }

        try {
            $ticket = $this->resolveTicketForHeldAction($clientId, $arguments['ticket_id'] ?? null);
        } catch (HuntressWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, $e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        // Recompute the hash on the validated identifying scalars so the
        // stage/approve/dedup keys are stable across argument spelling.
        $contentHash = $this->contentHash($tool, $clientId, $ticket->id, ['escalation_id' => $escalationId]);
        $targetKey = "escalation:{$escalationId}";

        // LIVE read (read client, account key) before anything is staged: the
        // escalation must exist, must not already be resolved, and must touch
        // THIS client's mapped organization. No execution claim exists yet,
        // so this read deliberately keeps the DEFAULT back-off — honouring
        // the upstream Retry-After in full, like every other reader.
        try {
            $escalation = $this->readClient()->getEscalation($escalationId);
        } catch (\Throwable $e) {
            $message = 'Huntress escalation lookup failed: '.mb_substr($e->getMessage(), 0, 200);
            $this->auditAttempt($tool, 'error', $clientId, $ticket, $contentHash, "{$targetKey}: {$message}", $actorLabel);

            return ['error' => $message];
        }

        if (empty($escalation)) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $contentHash, "{$targetKey}: Escalation not found.", $actorLabel);

            return ['error' => "Escalation {$escalationId} was not found."];
        }

        if ($scopeError = $this->escalationScopeError($escalation, $client, $escalationId)) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $contentHash, "{$targetKey}: {$scopeError}", $actorLabel);

            return ['error' => $scopeError];
        }

        if ($this->escalationResolved($escalation)) {
            // This short-circuit sits ABOVE the stranded-claim revive below, and
            // must: a resolve that already committed may never be re-presented
            // for a second POST. But that also puts a run stranded in Executing
            // by a dead finalization (worker kill mid-approve, a throwing
            // advanceTo) out of the revive's reach — and this family is excluded
            // from TechnicianRun::RECOVERY_SAFE_ACTION_TYPES, so no reaper
            // reopens it either. Land it TERMINAL here instead of leaving it
            // wedged forever with manual DB surgery the only exit.
            $this->finalizeStrandedResolvedRun($tool, $clientId, $ticket, $contentHash, $targetKey, $escalationId, $actorLabel);

            $this->auditAttempt($tool, 'blocked', $clientId, $ticket, $contentHash, "{$targetKey}: Escalation already resolved upstream; staging skipped.", $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'already_resolved' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'message' => "Escalation {$escalationId} is already resolved upstream; nothing was staged.",
            ];
        }

        if ($this->alreadyExecuted($tool, $clientId, $contentHash)) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $this->executedRunId($tool, $clientId, $contentHash),
                'message' => 'Already executed identical action recently; no new proposal was staged.',
            ];
        }

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

        if ($this->cooldownActive([$tool, $directTool], $clientId, $targetKey, self::COOLDOWN_SECONDS, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', $clientId, $ticket, $contentHash, "{$targetKey}: {$tool} cooldown active; staged proposal refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this escalation; no proposal was staged."];
        }

        $meta = [
            'drafted_by' => $actorLabel,
            'reasons' => [$reason],
            'direct_tool' => $directTool,
            'redacted_params' => ['escalation_id' => $escalationId],
            'sensitive_inputs' => [],
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => $directTool,
                'client_id' => $client->id,
                'ticket_id' => $ticket->id,
                'escalation_id' => $escalationId,
            ], JSON_THROW_ON_ERROR)),
        ];
        // The reason is agent-authored free text and is the one field the
        // approver actually reads to decide — it goes through the SAME fence
        // as the vendor subject, never appended bare after the fenced block
        // where it would read as system-authored framing.
        $proposedContent = $this->stagedDisplay($escalation, $escalationId, $client)
            ."\n".$this->fence->fence('AGENT SUPPLIED REASON', $reason);

        // Same idempotency-revive contract as the CIPP staged families
        // (bd psa-k4s0): the DB unique key (ticket_id + action_type +
        // content_hash) either creates a fresh run or revives the
        // superseded/denied row it collides with.
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

        // A run that approveStagedRun() has claimed is MID-EXECUTION — the
        // upstream POST may be in flight. Reviving it here would rewrite
        // Executing back to AwaitingApproval, break the claimForExecution
        // invariant, and re-present an escalation for approval while it is
        // being resolved. Answer idempotently and touch nothing — but ONLY
        // while the claim can still be live.
        //
        // A hard process death mid-approve (worker kill, redeploy, PHP
        // max_execution_time) strands the run in Executing with no catch
        // block ever running, and no reaper may reopen this family. An
        // unbounded "currently executing" answer would therefore make the
        // escalation PERMANENTLY unresolvable through the tool: every
        // re-stage collides with the same content_hash row forever and gets
        // the same answer, with manual DB surgery the only exit. Past
        // STALE_CLAIM_SECONDS the claim is provably dead, so the run falls
        // through to the ordinary revive below.
        //
        // That revive cannot resurrect a resolve that already landed: an
        // executed audit row short-circuits at alreadyExecuted() and a
        // committed upstream resolve short-circuits at the live
        // already-resolved read, both above this point — and approval itself
        // re-reads the escalation LIVE (and maps a 409) before any POST.
        $strandedClaim = ! $run->wasRecentlyCreated && $run->state === TechnicianRunState::Executing;

        if ($strandedClaim && ! $this->claimIsStale($run)) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $run->id,
                'message' => 'An approved resolve for this escalation is currently executing; nothing new was staged.',
            ];
        }

        if (! $run->wasRecentlyCreated && $run->state !== TechnicianRunState::AwaitingApproval) {
            $revival = [
                'state' => TechnicianRunState::AwaitingApproval->value,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ];

            if ($strandedClaim) {
                // claimIsStale() is a TIME-VARYING read, so the revive must be a
                // compare-and-swap on the exact claim it measured — still
                // Executing, still the same claimed_at — taken under a row lock.
                // A blind update could rewrite a claim a concurrent approval took
                // (or renewed) in between: that re-presents a card whose approval
                // is still running, admits a second approval and a second
                // resolution POST, and leaves the first approval's releaseClaim()
                // free to reopen a resolve that already committed. Every other
                // transition on this model (claimForExecution, releaseClaimTo,
                // markSuperseded) is CAS-guarded; this one is no different. The
                // write goes through the MODEL so the AwaitingApproval
                // notification (TechnicianRunObserver) still fires.
                $claimedAt = $run->claimed_at;
                $recovered = DB::transaction(function () use ($run, $claimedAt, $revival): bool {
                    $fresh = TechnicianRun::query()->whereKey($run->getKey())->lockForUpdate()->first();
                    if (! $fresh || $fresh->state !== TechnicianRunState::Executing) {
                        return false;
                    }

                    $sameClaim = $claimedAt === null
                        ? $fresh->claimed_at === null
                        : ($fresh->claimed_at !== null && $fresh->claimed_at->equalTo($claimedAt));
                    if (! $sameClaim) {
                        return false;
                    }

                    $fresh->update($revival);

                    return true;
                });

                if (! $recovered) {
                    // The claim moved under us — treat it as live and touch
                    // nothing, the same answer the not-yet-stale path gives.
                    return [
                        'success' => true,
                        'idempotent' => true,
                        'ticket_id' => $ticket->id,
                        'ticket_display_id' => $ticket->display_id,
                        'run_id' => $run->id,
                        'message' => 'An approved resolve for this escalation is currently executing; nothing new was staged.',
                    ];
                }

                $run->state = TechnicianRunState::AwaitingApproval;

                // The recovery is never silent: a run left claimed by a dead
                // approval is an operational fault, so releasing it leaves a log
                // line and an audit row rather than quietly re-presenting the
                // card. Both are written only once the CAS has actually won, so
                // the record can never claim a recovery that did not happen.
                Log::warning('[StaffHuntressActionToolExecutor] Recovering a stranded execution claim on re-stage', [
                    'run_id' => $run->id,
                    'escalation_id' => $escalationId,
                    'claimed_at' => $claimedAt?->toIso8601String(),
                ]);
                $this->auditAttempt($tool, 'error', $clientId, $ticket, $contentHash, "{$targetKey}: Stranded execution claim (run left Executing by a dead approval) recovered on re-stage; the run returns to awaiting approval and re-verifies the escalation LIVE before any upstream call.", $actorLabel, $run->id);
            } else {
                $run->update($revival);
            }
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

        $this->auditAttempt($tool, 'awaiting_approval', $clientId, $ticket, $contentHash, "{$targetKey}: MCP staged {$tool} for escalation {$escalationId}: {$reason}", $actorLabel, $run->id);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'message' => 'Staged for cockpit approval.',
        ];
    }

    /**
     * Approval replay for a held escalation resolve. Everything is
     * revalidated from the encrypted payload; the escalation is re-read LIVE
     * (existence, unresolved state, org↔client scope) before the id-only POST,
     * and the 201's `resolution_method` post-condition is asserted after it.
     */
    public function approveStagedRun(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        if (! self::isStagedActionType($run->action_type) || ! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        // Tracks whether the upstream resolve has COMMITTED. Once it has, no
        // failure path below may return this run to AwaitingApproval — a
        // re-approval would send a second POST /escalations/{id}/resolution —
        // and no failure may swallow the operator-facing outcome either. The
        // sibling StaffCalendarToolExecutor keeps the same flag for exactly
        // this window.
        $writeCommitted = false;

        try {
            $payload = $this->decryptRunPayload($run);
            if ($payload === null) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined', message: 'The held payload could not be read — deny this proposal and re-stage it.');
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

            $ticket = Ticket::find((int) ($payload['ticket_id'] ?? 0));
            if (! $ticket || (int) $ticket->client_id !== (int) $client->id) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $escalationId = $this->positiveInt($payload['escalation_id'] ?? null);
            if ($escalationId === null) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $targetKey = "escalation:{$escalationId}";
            $contentHash = (string) $run->content_hash;
            $approverLabel = $this->approverLabel($approverId);

            if (! HuntressConfig::isEnabled() || ! HuntressConfig::isConfigured() || ! HuntressConfig::isWriteConfigured()) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $contentHash, "{$targetKey}: Huntress write lane not configured; approval refused before upstream call.", $approverLabel, $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined', message: 'Huntress write credential is not configured — nothing was resolved.');
            }

            if (TechnicianConfig::killSwitchEngaged()) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $contentHash, "{$targetKey}: Technician kill-switch engaged; staged Huntress write refused.", $approverLabel, $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            if ($this->executedCooldownActive([$run->action_type, $directTool], (int) $client->id, $targetKey, self::COOLDOWN_SECONDS)) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $contentHash, "{$targetKey}: Huntress staged action cooldown active; approval refused before upstream call.", $approverLabel, $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            // Fresh LIVE read: the record may have moved while the proposal
            // waited. Vanished → decline; resolved meanwhile → the approved
            // intent is satisfied without an upstream call; out of scope →
            // decline (a remap while held must not widen the write). This is
            // the one read that sleeps while holding the run's execution
            // claim, so it — and only it — clamps its 429 back-off; the
            // STALE_CLAIM_SECONDS bound depends on that.
            try {
                $escalation = $this->readClient()->withClampedBackoff()->getEscalation($escalationId);
            } catch (\Throwable $e) {
                $this->auditAttempt($run->action_type, 'error', $client->id, $ticket, $contentHash, "{$targetKey}: Approval refused — escalation lookup failed: ".mb_substr($e->getMessage(), 0, 200), $approverLabel, $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined', message: 'Huntress could not be reached to re-verify the escalation — nothing was resolved. Try again.');
            }

            if (empty($escalation)) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $contentHash, "{$targetKey}: Approval refused — escalation no longer exists upstream.", $approverLabel, $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined', message: "Escalation {$escalationId} no longer exists upstream — nothing was resolved.");
            }

            if ($scopeError = $this->escalationScopeError($escalation, $client, $escalationId)) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $contentHash, "{$targetKey}: Approval refused — {$scopeError}", $approverLabel, $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined', message: $scopeError);
            }

            if ($this->escalationResolved($escalation)) {
                $this->auditAttempt($run->action_type, 'executed', $client->id, $ticket, $contentHash, "{$targetKey}: Escalation already resolved upstream — approved resolve satisfied without an upstream call.", $approverLabel, $run->id, $approverId);
                $run->advanceTo(TechnicianRunState::Done);

                return new TechnicianApprovalResult('executed', message: "Escalation {$escalationId} was already resolved upstream — nothing needed sending.");
            }

            try {
                $resolution = $this->writeClient->resolveEscalation($escalationId);
            } catch (HuntressEscalationAlreadyResolvedException) {
                $this->auditAttempt($run->action_type, 'executed', $client->id, $ticket, $contentHash, "{$targetKey}: Upstream answered 409 already-resolved — approved resolve satisfied.", $approverLabel, $run->id, $approverId);
                $run->advanceTo(TechnicianRunState::Done);

                return new TechnicianApprovalResult('executed', message: "Escalation {$escalationId} was already resolved upstream — nothing needed sending.");
            } catch (HuntressEscalationNotApiResolvableException $e) {
                $this->auditAttempt($run->action_type, 'error', $client->id, $ticket, $contentHash, "{$targetKey}: ".$e->getMessage(), $approverLabel, $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined', message: "Huntress refused: escalation {$escalationId} cannot be resolved through the API — resolve it in the Huntress console. Nothing was changed.");
            } catch (HuntressResolveOutcomeUnknownException $e) {
                // The POST WAS SENT and its outcome is INDETERMINATE — the reply
                // was lost (a 30 s read timeout is indistinguishable from a
                // connection that never opened) or the server answered 5xx. The
                // escalation MAY be resolved, and if it is, the server's
                // resolution_method was never seen, so the post-condition never
                // ran. Two things must therefore NOT happen here. The claim is
                // NOT released: reopening invites a second POST, and that replay
                // converges on the live-read/409 branch as a clean 'executed' —
                // exactly how a rules-were-created fault launders into a green
                // success. And the operator is NOT told nothing was resolved,
                // because nobody knows that.
                //
                // The run stays CLAIMED rather than terminal, the shape the
                // sibling StaffCalendarToolExecutor gives an indeterminate
                // non-idempotent write: if the POST never landed the re-stage
                // revive reopens it past STALE_CLAIM_SECONDS (the live re-read
                // and the 409 mapping still guard that path), and if it DID land
                // finalizeStrandedResolvedRun lands it terminal carrying this
                // same indeterminacy — never a clean executed row. safeAudit,
                // not auditAttempt: a throwing INSERT would fall to the outer
                // handler, which releases the claim.
                Log::error('[StaffHuntressActionToolExecutor] Huntress resolve outcome UNKNOWN after the POST was sent; the run is held CLAIMED, not reopened', [
                    'run_id' => $run->id,
                    'escalation_id' => $escalationId,
                    'error' => $e->getMessage(),
                ]);
                $this->safeAudit($run->action_type, 'error', $client->id, $ticket, $contentHash, "{$targetKey}: INDETERMINATE OUTCOME — the resolve POST was sent and no conclusive reply arrived (".mb_substr($e->getMessage(), 0, 200)."). Whether escalation {$escalationId} was resolved is UNKNOWN, and if it was, its resolution_method post-condition was never evaluated. The run is held CLAIMED so it cannot be one-tap re-approved into a second POST. Establish in the Huntress console whether this escalation is resolved and whether the server created attribute rules; if it did, escalate to a human.", $approverLabel, $run->id, $approverId);

                return new TechnicianApprovalResult('gate_declined', message: "The Huntress resolve request was SENT but its outcome is UNKNOWN (the reply was lost, or the server errored) — escalation {$escalationId} may already be resolved, and if it is, the resolution_method post-condition was never checked. This run is held for manual verification and deliberately NOT reopened for re-approval: check the escalation in the Huntress console, including any attribute rules the server may have created, before any re-send.");
            } catch (HuntressWriteScopeException|HuntressClientException $e) {
                // An ANSWERED 4xx: the request was refused before the server
                // acted, so nothing was resolved and reopening is safe.
                $this->auditAttempt($run->action_type, 'error', $client->id, $ticket, $contentHash, "{$targetKey}: ".mb_substr($e->getMessage(), 0, 300), $approverLabel, $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined', message: 'The Huntress resolve call failed — nothing was resolved. Try again.');
            }

            // The upstream resolve has now COMMITTED. Land the run terminal
            // FIRST; everything below is bookkeeping. A bookkeeping failure
            // that reopened the run would re-present an already-resolved
            // escalation for a second POST and — on the fault branch — drop
            // the HARD FAULT audit row, so the later re-approval would
            // converge through the live-read/409 path and report a clean
            // 'executed', laundering a rules-were-created security fault into
            // a green success.
            $writeCommitted = true;
            $run->advanceTo(TechnicianRunState::Done);

            // POST-CONDITION on the server's own report of what it did. The
            // id-only `{}` body legitimately produces `direct` or `dismiss`;
            // `rule` means attribute rules WERE created, and an unrecognised
            // value is treated identically (fail closed on unknown). The run
            // still advances to Done — the upstream state DID change — but the
            // audit row is `error` and the result status is the DISTINCT
            // `executed_with_fault`, which the cockpit renders through the
            // error channel: a fault that executed must never be reported as
            // declined, and it must never be dressed as a green success either.
            $method = is_scalar($resolution['resolution_method'] ?? null)
                ? (string) $resolution['resolution_method']
                : '(missing)';

            if (! in_array($method, self::SAFE_RESOLUTION_METHODS, true)) {
                $fault = "HARD FAULT: Huntress reported resolution_method '{$method}' for escalation {$escalationId} — an id-only resolve must report 'direct' or 'dismiss'. "
                    ."'rule' means the server CREATED attribute rules during this resolution. The escalation IS resolved; inspect the Huntress console for created rules and escalate to a human immediately.";
                Log::error("[StaffHuntressActionToolExecutor] {$fault}", ['run_id' => $run->id, 'escalation_id' => $escalationId]);
                $this->safeAudit($run->action_type, 'error', $client->id, $ticket, $contentHash, "{$targetKey}: {$fault}", $approverLabel, $run->id, $approverId);

                return new TechnicianApprovalResult('executed_with_fault', message: $fault);
            }

            $this->safeAudit($run->action_type, 'executed', $client->id, $ticket, $contentHash, "{$targetKey}: Operator-approved {$run->action_type} executed — escalation {$escalationId} resolved (resolution_method {$method}).", $approverLabel, $run->id, $approverId);

            return new TechnicianApprovalResult('executed', message: "Escalation {$escalationId} resolved (server reported resolution_method '{$method}').");
        } catch (\Throwable $e) {
            // Only reachable BEFORE the upstream resolve committed — safe to
            // reopen for a retry. Once it HAS committed, reopening is the
            // double-resolve this flag exists to prevent: keep the run
            // terminal and hand the operator the executed-with-fault channel
            // instead of a 500 that hides what landed upstream.
            if ($writeCommitted) {
                Log::error('[StaffHuntressActionToolExecutor] Finalizing a COMMITTED escalation resolve failed; the run is NOT reopened', [
                    'run_id' => $run->id,
                    'error' => $e->getMessage(),
                ]);

                return new TechnicianApprovalResult('executed_with_fault', message: 'The escalation WAS resolved upstream, but recording the outcome failed — the resolution_method post-condition and its audit row may be missing. Do NOT re-approve; verify this escalation (and any attribute rules the server may have created) in the Huntress console.');
            }

            $run->releaseClaim();

            throw $e;
        }
    }

    // ── scope & state helpers ───────────────────────────────────────────────────

    /**
     * Null when the escalation is in scope for this client; otherwise the
     * operator-readable refusal. In scope = the escalation's organization set
     * is EXACTLY THIS client's mapped organization — not merely one of its
     * organizations: resolution closes the entire escalation record, so an
     * any-of match would resolve a security record on behalf of every other
     * tenant it covers. Multi-organization escalations and account-level ones
     * (no organization association) are both refused in v1 — no single PSA
     * client owns them, so there is no cockpit lane to hold them against.
     *
     * @param  array<string, mixed>  $escalation
     */
    private function escalationScopeError(array $escalation, Client $client, int $escalationId): ?string
    {
        $mappedOrgId = $this->positiveInt($client->huntress_organization_id);
        if ($mappedOrgId === null) {
            return "Client {$client->id} has no mapped Huntress organization; map it in Settings > Huntress before resolving escalations.";
        }

        $orgIds = [];
        $unreadable = 0;
        foreach ((array) ($escalation['organizations'] ?? []) as $org) {
            $id = is_array($org) ? $this->positiveInt($org['id'] ?? null) : $this->positiveInt($org);
            if ($id !== null) {
                $orgIds[] = $id;
            } else {
                $unreadable++;
            }
        }

        $orgIds = array_values(array_unique($orgIds));

        // The sole-org invariant below is only as sound as this parse: a
        // DROPPED entry (missing/null/non-numeric id, or an entry shape this
        // parser does not recognise) would collapse a multi-tenant escalation
        // to a single-org set and admit exactly the cross-tenant whole-record
        // resolve the count check exists to refuse. An unreadable entry means
        // the organization set is UNKNOWN, so it refuses — fail closed.
        if ($unreadable > 0) {
            return "Escalation {$escalationId} has {$unreadable} Huntress organization ".($unreadable === 1 ? 'entry' : 'entries').' this tool cannot read, so its organization set cannot be established — it may cover tenants outside this client. Resolve it in the Huntress console, not through this tool.';
        }

        if ($orgIds === []) {
            return "Escalation {$escalationId} is account-level (no organization association) — it has no PSA client scope and is resolved in the Huntress console, not through this tool.";
        }

        // POST /escalations/{id}/resolution closes the WHOLE record, not a
        // per-org slice. An any-of match would let this client's approver
        // resolve a SOC escalation that also covers another MSP's tenant on
        // the shared account — a security-record write for a tenant with no
        // PSA client, no ticket and no approver here. v1 requires the set to
        // be exactly the mapped org; under-acting is the right direction.
        if (count($orgIds) > 1) {
            return "Escalation {$escalationId} covers ".count($orgIds).' Huntress organizations — resolving it would close the record for tenants outside this client. Multi-organization escalations are resolved in the Huntress console, not through this tool.';
        }

        if (! in_array($mappedOrgId, $orgIds, true)) {
            return "Escalation {$escalationId} does not touch this client's Huntress organization.";
        }

        return null;
    }

    /** @param array<string, mixed> $escalation */
    private function escalationResolved(array $escalation): bool
    {
        $status = is_scalar($escalation['status'] ?? null) ? (string) $escalation['status'] : '';
        $resolvedAt = $escalation['resolved_at'] ?? null;

        return $status === 'resolved' || (is_scalar($resolvedAt) && (string) $resolvedAt !== '');
    }

    /**
     * Whether an Executing run's claim is old enough that no live approval
     * can still be holding it. claimed_at is stamped inside
     * claimForExecution()'s CAS; updated_at is the fallback for rows claimed
     * before that column existed, and an unreadable stamp counts as STALE
     * rather than pinning the escalation in Executing forever (mirrors
     * TechnicianRun::scopeStaleExecuting).
     */
    private function claimIsStale(TechnicianRun $run): bool
    {
        $claimedAt = $run->claimed_at ?? $run->updated_at;

        return $claimedAt === null || $claimedAt->lte(now()->subSeconds(self::STALE_CLAIM_SECONDS));
    }

    /**
     * Land a run left wedged in Executing by a dead approval when the
     * escalation now reads RESOLVED upstream — the one strand the re-stage
     * revive cannot reach, because the live already-resolved read
     * short-circuits above it (correctly: whatever resolved it, the record must
     * never be re-presented for a second POST). Without this the run never
     * reaches a terminal state at all:
     * excluded from TechnicianRun::RECOVERY_SAFE_ACTION_TYPES so no reaper
     * touches it, not in PENDING_STATES so the cockpit cannot show it, no
     * executed audit row, and manual DB surgery the only exit — while the
     * stale-claim reaper logs the same error every five minutes forever.
     *
     * The audit row is `error`, not `executed`, and it asserts NOTHING about
     * whether THIS approval's POST landed — it cannot. The Executing claim is
     * taken at the top of approveStagedRun, and the payload decrypt, the
     * config/kill-switch/executedCooldown gates and the clamped live re-read
     * all run while it is held, while a committed resolve advances the run to
     * Done immediately; so a run found stranded here most likely died BEFORE
     * the POST, and a third-party console resolve then leaves the escalation
     * reading resolved with nothing ever sent by us. The one path that strands
     * a run DELIBERATELY after the POST was sent — the indeterminate-outcome
     * hold, where the reply was lost — cannot be told apart from those here
     * either: it leaves the same claimed row, and whether its POST resolved
     * anything is precisely what nobody knows. The row therefore records
     * only what was observed (the run was stranded; the escalation now reads
     * resolved) and asks the operator to establish which — rather than
     * directing a human security investigation into writes that may never have
     * happened, and rather than a clean `executed` row, which is exactly how a
     * real resolution_method fault would get laundered into a green success.
     *
     * Only a claim past STALE_CLAIM_SECONDS is touched, and only through the
     * same claim-CAS-under-row-lock the re-stage revive uses: an approval that
     * is still finishing must always win the race.
     */
    private function finalizeStrandedResolvedRun(
        string $tool,
        int $clientId,
        Ticket $ticket,
        string $contentHash,
        string $targetKey,
        int $escalationId,
        string $actorLabel,
    ): void {
        $run = TechnicianRun::query()
            ->where('ticket_id', $ticket->id)
            ->where('action_type', $tool)
            ->where('content_hash', $contentHash)
            ->where('state', TechnicianRunState::Executing->value)
            ->first();

        if ($run === null || ! $this->claimIsStale($run)) {
            return;
        }

        $claimedAt = $run->claimed_at;
        $finalized = DB::transaction(function () use ($run, $claimedAt): bool {
            $fresh = TechnicianRun::query()->whereKey($run->getKey())->lockForUpdate()->first();
            if (! $fresh || $fresh->state !== TechnicianRunState::Executing) {
                return false;
            }

            $sameClaim = $claimedAt === null
                ? $fresh->claimed_at === null
                : ($fresh->claimed_at !== null && $fresh->claimed_at->equalTo($claimedAt));
            if (! $sameClaim) {
                return false;
            }

            $fresh->advanceTo(TechnicianRunState::Done);

            return true;
        });

        if (! $finalized) {
            return;
        }

        $run->state = TechnicianRunState::Done;

        // Never silent: a run landed terminal by reconstruction rather than by
        // its own approval is an operational fault, and the record must say so.
        Log::warning('[StaffHuntressActionToolExecutor] Landing a run stranded in Executing by a dead approval; the escalation now reads resolved upstream, by means this run cannot determine', [
            'run_id' => $run->id,
            'escalation_id' => $escalationId,
            'claimed_at' => $claimedAt?->toIso8601String(),
        ]);
        $this->auditAttempt($tool, 'error', $clientId, $ticket, $contentHash, "{$targetKey}: Run left Executing by a dead approval; the escalation now reads RESOLVED upstream. Whether this approval's resolve POST ever landed could NOT be determined — the claim is taken well before the POST and a committed resolve lands the run Done immediately, so an external console resolve is at least as likely. The run is landed terminal here rather than wedged forever. Establish in the Huntress console how this escalation was resolved: if it was resolved through this tool, its resolution_method post-condition was never evaluated, so inspect it for attribute rules the server may have created and escalate to a human.", $actorLabel, $run->id);
    }

    /**
     * The approver-facing card body. The escalation subject is vendor-relayed
     * free text (SOC analyst prose, entity names, mail subjects) — fenced as
     * untrusted data; the scalar facts are bounded.
     *
     * @param  array<string, mixed>  $escalation
     */
    private function stagedDisplay(array $escalation, int $escalationId, Client $client): string
    {
        $scalar = fn (string $key): string => is_scalar($escalation[$key] ?? null)
            ? mb_substr(trim((string) $escalation[$key]), 0, 100)
            : '';

        $lines = [
            "Resolve Huntress escalation {$escalationId} for {$client->name}.",
            trim('Status: '.($scalar('status') ?: 'unknown').' · Severity: '.($scalar('severity') ?: 'unknown').' · Type: '.trim($scalar('type').' / '.$scalar('subtype'), ' /')),
            'The resolve is id-only: the upstream body is the empty object {} — no determination, no scope, no identity changes. The server-reported resolution_method is verified after the call.',
        ];

        $subject = is_scalar($escalation['subject'] ?? null) ? trim((string) $escalation['subject']) : '';
        if ($subject !== '') {
            $lines[] = $this->fence->fence('HUNTRESS ESCALATION SUBJECT', mb_substr($subject, 0, self::SUBJECT_DISPLAY_MAX));
        }

        return implode("\n", $lines);
    }

    // ── plumbing (mirrors the CIPP/Tactical staged families) ────────────────────

    private function readClient(): HuntressClient
    {
        return app(HuntressClient::class);
    }

    /** @return array<int, string> Argument keys outside the pinned accepted set. */
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

    private function resolveTicketForHeldAction(int $clientId, mixed $ticketIdValue): Ticket
    {
        $ticketId = $this->positiveInt($ticketIdValue);
        if ($ticketId === null) {
            throw new HuntressWriteScopeException('ticket_id is required for staged Huntress actions');
        }

        $ticket = Ticket::find($ticketId);
        if (! $ticket || (int) $ticket->client_id !== $clientId) {
            throw new HuntressWriteScopeException('Ticket not found or belongs to a different client');
        }

        return $ticket;
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

    private function liveAwaitingRun(int $ticketId, string $tool, string $contentHash): ?TechnicianRun
    {
        return TechnicianRun::query()
            ->where('ticket_id', $ticketId)
            ->where('action_type', $tool)
            ->where('content_hash', $contentHash)
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->first();
    }

    /**
     * STAGE-TIME cooldown over BOTH names (staged action_type and canonical),
     * keyed on the per-escalation target embedded in the audit summary — the
     * psa-eerg4 R2 lesson: a single-name lookup is asymmetric across the
     * stage/approve paths. Counts awaiting_approval rows too, so a second
     * ticket cannot pile a duplicate proposal onto a live one — but NEVER this
     * proposal's OWN staging row: $ownContentHash (ticket + escalation + tool)
     * is excluded from the awaiting_approval leg, because a DENIED run misses
     * the liveAwaitingRun() short-circuit above (that query filters on
     * AwaitingApproval) and would otherwise collide with the very row its own
     * staging wrote — refusing the deny-then-re-stage revive this family's
     * firstOrCreate contract documents, for the rest of the window, under a
     * message indistinguishable from a genuine runaway-staging refusal.
     * Executed rows still count unconditionally: a resolve that landed is a
     * cooldown for everyone. The
     * target key is built from a validated integer, so it cannot carry LIKE
     * wildcards — and the match is ANCHORED at the start of the summary with
     * the trailing `:` delimiter included, because every countable summary is
     * written as "escalation:{id}: …". An unanchored substring match would let
     * escalation:1234 collide with escalation:12345 (prefix ids) and let an
     * agent-authored reason containing "escalation:N:" spoof cooldowns for
     * escalations it never touched.
     *
     * @param  array<int, string>  $actionTypes
     */
    private function cooldownActive(array $actionTypes, int $clientId, string $targetKey, int $cooldownSeconds, ?string $ownContentHash = null): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()
            ->whereIn('action_type', $actionTypes)
            ->where('client_id', $clientId)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->where(function ($query) use ($ownContentHash) {
                $query->where('result_status', 'executed')
                    ->orWhere(function ($awaiting) use ($ownContentHash) {
                        $awaiting->where('result_status', 'awaiting_approval');
                        if ($ownContentHash !== null) {
                            // A row with no content_hash is not this proposal's own,
                            // so it must keep counting — a bare `!=` would drop it
                            // (NULL compares to neither).
                            $awaiting->where(fn ($own) => $own->whereNull('content_hash')
                                ->orWhere('content_hash', '!=', $ownContentHash));
                        }
                    });
            })
            ->where('summary', 'like', $targetKey.':%')
            ->exists();
    }

    /**
     * APPROVE-TIME cooldown: EXECUTED rows only, deliberately — the staging
     * call leaves an awaiting_approval row under the staged name carrying
     * this same target key, and counting it would make every proposal block
     * its own approval (the licenseTargetCooldownActive lesson). Runaway
     * staging is the stage-time check's question, not this one's. Anchored
     * prefix match for the same reason as cooldownActive(): unanchored LIKE
     * collides prefix-sharing escalation ids and silently declines legitimate
     * approvals.
     *
     * @param  array<int, string>  $actionTypes
     */
    private function executedCooldownActive(array $actionTypes, int $clientId, string $targetKey, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()
            ->whereIn('action_type', $actionTypes)
            ->where('client_id', $clientId)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->where('result_status', 'executed')
            ->where('summary', 'like', $targetKey.':%')
            ->exists();
    }

    /**
     * An audit row on a path where the upstream write has ALREADY COMMITTED —
     * or, on the indeterminate-outcome path, MAY have.
     * A throwing INSERT there must not escape: the handler above would either
     * reopen an executed run (a second resolve POST) or turn the operator's
     * HARD FAULT text into a 500 they never read. Losing the row to the log
     * is strictly better than losing the fault. Mirrors the calendar family's
     * post-write bookkeeping.
     */
    private function safeAudit(
        string $actionType,
        string $resultStatus,
        ?int $clientId,
        ?Ticket $ticket,
        string $contentHash,
        string $summary,
        string $actorLabel,
        ?int $runId = null,
        ?int $approverId = null,
    ): void {
        try {
            $this->auditAttempt($actionType, $resultStatus, $clientId, $ticket, $contentHash, $summary, $actorLabel, $runId, $approverId);
        } catch (\Throwable $e) {
            Log::error('[StaffHuntressActionToolExecutor] Post-write audit row failed after a COMMITTED escalation resolve', [
                'run_id' => $runId,
                'result_status' => $resultStatus,
                'summary' => $summary,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function auditAttempt(
        string $actionType,
        string $resultStatus,
        ?int $clientId,
        ?Ticket $ticket,
        string $contentHash,
        string $summary,
        string $actorLabel,
        ?int $runId = null,
        ?int $approverId = null,
    ): void {
        // technician_action_logs.client_id is FK-constrained. Several refusal
        // paths audit BEFORE the client is resolved (whole-body refusal,
        // missing reason, kill-switch, and "Client not found" itself), so an
        // unvalidated id here would turn the intended refusal into a
        // QueryException that leaks raw SQL to the MCP caller and writes no
        // audit row at all. Verify existence and fall back to null — the
        // summary text still names the attempt.
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

    private function decryptRunPayload(TechnicianRun $run): ?array
    {
        $ciphertext = $run->proposed_meta['encrypted_payload'] ?? null;
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        // A corrupt/tampered/key-rotated ciphertext must return null, not
        // escape as a framework error — the psa-491 decryptRunPayload lesson.
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

        return hash('sha256', json_encode([
            'tool' => $tool,
            'client_id' => $clientId,
            'ticket_id' => $ticketId,
            'params' => $params,
        ]));
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

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
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
    private static function resolveEscalationTool(): array
    {
        return [
            'name' => 'huntress_resolve_escalation',
            'description' => 'Resolve ONE Huntress SOC escalation by id — the record-level "this has been handled" acknowledgement after a technician works an escalation. HELD-ONLY: this capability never executes immediately, whatever mode was granted — every call must use staged=true with a ticket_id and is held for cockpit approval; staged=false calls are refused. ID-ONLY BY CONSTRUCTION: the upstream resolution body is always the empty object {} — determination, scope, revoke_and_disable_identities, expiration_date, and every other resolution parameter are structurally refused (the parameterised form can revoke sessions, disable identities, and create account-wide access rules). After the call the server-reported resolution_method is verified: direct/dismiss pass; rule (attribute rules were created) is treated as a hard fault and escalated. Only escalations whose organization set is exactly this client\'s mapped Huntress organization are accepted; account-level and multi-organization escalations (resolution closes the whole record, including other tenants) are resolved in the Huntress console. Requires an explicit token grant, reason, kill-switch, cooldown, and TechnicianActionLog audit.',
            'input_schema' => [
                'type' => 'object',
                'properties' => self::escalationProperties(),
                'required' => ['escalation_id', 'reason'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function stageResolveEscalationTool(): array
    {
        return [
            'name' => 'huntress_stage_resolve_escalation',
            'description' => 'Stage the resolution of one Huntress SOC escalation for cockpit approval. The MCP call makes no Huntress write; the held payload stores only the escalation id, and approval re-reads the escalation LIVE (existence, unresolved state, organization↔client scope) before sending the id-only resolve — whose body is always the empty object {}; every resolution parameter is structurally refused. The server-reported resolution_method is verified after the call (direct/dismiss pass; rule is a hard fault). This capability is held-only — there is no immediate execution path.',
            'input_schema' => [
                'type' => 'object',
                'properties' => self::escalationProperties(ticket: true),
                'required' => ['escalation_id', 'ticket_id', 'reason'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function escalationProperties(bool $ticket = false): array
    {
        $properties = [
            'escalation_id' => [
                'type' => 'integer',
                'description' => 'Huntress escalation ID (from huntress_list_escalations / huntress_get_escalation). The server verifies its organization set is exactly this client\'s mapped Huntress organization (multi-organization and account-level escalations are refused) and that it is not already resolved, at staging AND again at approval.',
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'Why this escalation is being resolved (what was done about it). Shown to the approver and recorded in the audit log.',
            ],
        ];

        if ($ticket) {
            $properties['ticket_id'] = [
                'type' => 'integer',
                'description' => 'PSA ticket this resolution belongs to. Must belong to client_id; the staged proposal is held on this ticket for cockpit approval.',
            ];
        }

        return $properties;
    }
}
