<?php

namespace App\Services\Mcp;

use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Models\Client;
use App\Models\MeshAllowRule;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use App\Services\Mesh\MeshWriteRejectedException;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Services\Technician\TechnicianApprovalResult;
use App\Support\MeshConfig;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * #1018 — the Mesh Email Security WRITE surface. One verb:
 * `mesh_add_allow_rule`, which allow-lists a sender for ONE customer tenant.
 *
 * An allow rule is a hole in a customer's mail filtering. It is the exact
 * shape of write that phishing remediation asks for under time pressure and
 * that nobody ever goes back to remove, which is why the guards here are not
 * generic:
 *
 *  - STAGED-ONLY BY CONSTRUCTION. The canonical name is advertised and
 *    granted like any other verb, but has NO immediate implementation:
 *    execute() answers it with a refusal naming the staged grant. Combined
 *    with McpToolModes::IMMEDIATE_REQUIRES_EXPLICIT_GRANT, a bare or legacy
 *    full-surface grant resolves to staged rather than inheriting immediate
 *    trust (the grant-default flip).
 *  - SCOPE IS PROVED ON THE RESPONSE. Mesh normalises the stored row to
 *    `organization_level: true, customer_id: null` regardless of what we
 *    sent, so a read-back of those fields proves nothing. The only scope
 *    evidence is `added_for` in the 201 body, and it must equal exactly the
 *    tenant we targeted (criterion 1). Anything else is recorded as a fault.
 *  - THE ARGUMENT SET IS CLOSED. Only sender / confirm_domain / reason /
 *    ticket_id / expires_at reach this executor; `edge`, `customers`, `ab`,
 *    `organization_level`, `date_expiry` and friends are refused BY NAME so a
 *    caller trying to widen the rule gets told no rather than silently
 *    ignored. MeshWriteClient assembles the body itself, so even a bug here
 *    cannot smuggle them upstream.
 *  - THE CALLER CHOOSES THE LIFETIME, AND MAY CHOOSE NONE (#1133). The owner's
 *    ruling: a hard-set 90 days is "a landmine disguised as constraint". So
 *    `expires_at` takes an ISO-8601 date/datetime or the literal `never`, and
 *    an omitted value still means DEFAULT_LIFETIME_DAYS. A value that cannot
 *    be read, or that has already passed, is REFUSED — never quietly turned
 *    into 90 days, which would be the same landmine pointing the other way.
 *  - IT EXPIRES, UNLESS ASKED NOT TO. Mesh does not expire rules (measured
 *    2026-09-01), so every created rule gets a mesh_allow_rules row and
 *    MeshAllowRuleReaper deletes it. A rule whose id could not be recovered is
 *    surfaced as a fault, never left quietly unreapable (criterion 8). A rule
 *    the caller made permanent has a NULL expiry, is never reaped, and says so
 *    in those words on the approval card — a permanent hole must read as
 *    permanent to the human releasing it, not as a missing date.
 *  - THE PARTNER-WIDE LIST NEVER ESCAPES. Rule identity is recovered inside
 *    MeshWriteClient, which returns only this tenant's rows; nothing here
 *    puts a rule list into a return value, a log line or an error body
 *    (criterion 7).
 */
class StaffMeshAdminToolExecutor
{
    private const DIRECT_DEDUP_HOURS = 24;

    /**
     * How many proposals for the same write may exist on one ticket. A spent
     * run is never rewritten, so a repeat request takes the next generation of
     * its idempotency key; this is where that stops being served rather than
     * growing without bound.
     */
    private const MAX_RUN_GENERATIONS = 20;

    private const COOLDOWN_SECONDS = 300;

    private const REASON_MAX = 500;

    /**
     * `mesh_add_allow_rule` is STAGED-ONLY BY CONSTRUCTION — see the class
     * docblock. The canonical name has no immediate implementation; execute()
     * answers it with a refusal that names the staged grant, so there is no
     * code path from an immediate grant to createAllowRule().
     *
     * @var array<string, string>
     */
    private const STAGED_TO_DIRECT = [
        'mesh_stage_add_allow_rule' => 'mesh_add_allow_rule',
    ];

    /** @var array<int, string> */
    private const CLIENT_SCOPED_TOOLS = [
        'mesh_add_allow_rule',
        'mesh_stage_add_allow_rule',
    ];

    /**
     * The complete set of keys a caller may send. Anything else is a refusal.
     *
     * @var array<int, string>
     */
    private const ALLOWED_ARGUMENT_KEYS = ['sender', 'confirm_domain', 'reason', 'ticket_id', 'expires_at', 'staged'];

    /**
     * Keys that are refused with a REASON rather than as generic noise,
     * because each one is a specific attempt to widen the rule beyond what
     * the approver would be shown:
     *   ab                 — the allow/block flag; this lane is allow-only.
     *   edge               — applies the rule at connection level too.
     *   customers          — the partner-wide (every tenant) form.
     *   customer_id        — the tenant is derived from the PSA client, never typed.
     *   organization_level — the tenant-wide toggle.
     *   date_expiry        — the vendor's field name; the caller sets the
     *                        lifetime with `expires_at` (#1133), and what goes
     *                        upstream is derived from it, never typed here.
     *   active             — a rule that is created must be live or not created.
     *   comment            — PSA-generated; it is the reaper's match key.
     *   domains / users    — bulk forms of the same hole.
     *
     * @var array<string, string>
     */
    private const REFUSED_ARGUMENT_KEYS = [
        'ab' => 'this verb only ever creates ALLOW rules',
        'edge' => 'connection-level application is never enabled from the PSA',
        'customers' => 'partner-wide rules are never created from the PSA',
        'customer_id' => 'the Mesh tenant is derived from the PSA client, never supplied',
        'organization_level' => 'scope is fixed to the resolved customer',
        'date_expiry' => 'expiry is set with expires_at, not the Mesh field name',
        'active' => 'created rules are always active',
        'comment' => 'the comment is PSA-generated and is the expiry match key',
        'domains' => 'bulk domain allow-lists are not created from the PSA',
        'users' => 'bulk user allow-lists are not created from the PSA',
        'partner_id' => 'partner-scoped writes are never made from the PSA',
        'global' => 'global rules are never created from the PSA',
    ];

    /**
     * The Mesh `comment` this verb writes: a fixed word pair plus a random
     * reference token, and nothing else.
     *
     * Mesh regex-validates this field and answers 400 `{"comment":["String
     * invalid"]}` on `# _ - ( )` (measured); the accepted charset was NOT
     * narrowed past "plain alphanumerics and spaces pass". So the PSA
     * generates the whole string from a charset known to pass rather than
     * relying on knowing where the boundary is.
     *
     * The token — not a ticket number — is what makes it unique. Ticket
     * references live in the PSA audit row only (criterion 2): the Mesh
     * comment is visible in a vendor portal shared across tenants, and `#`
     * would fail validation anyway.
     */
    /**
     * The literal a caller sends as `expires_at` to ask for a rule the PSA
     * will never reap (#1133). A word, not a magic date: the owner's ruling
     * asked for "no expiration" to be sayable, and every alternative encoding
     * (null, 0, an empty string, 9999-12-31) is one a caller could arrive at
     * by accident rather than on purpose.
     */
    public const EXPIRY_NEVER = 'never';

    private const COMMENT_PREFIX = 'PSA allow';

    private const COMMENT_TOKEN_LENGTH = 10;

    public function __construct(
        private readonly MeshWriteClient $client,
        private readonly ActionRedactor $redactor,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            self::addAllowRuleTool(),
            self::stageAddAllowRuleTool(),
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

    /** @return array<string, string> */
    public static function stagedToDirectMap(): array
    {
        return self::STAGED_TO_DIRECT;
    }

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, ?int $clientId, string $actorLabel): array
    {
        if (! MeshConfig::isEnabled() || ! MeshConfig::isConfigured()) {
            return ['error' => 'Mesh Email Security is not configured'];
        }

        return match ($name) {
            'mesh_stage_add_allow_rule' => $this->stageAllowRule($arguments, (int) $clientId, $actorLabel),
            'mesh_add_allow_rule' => $this->immediateAllowRuleRefused($arguments, $clientId, $actorLabel),
            default => ['error' => "Unknown Mesh admin tool: {$name}"],
        };
    }

    /** @return array<string, mixed> */
    private function immediateAllowRuleRefused(array $arguments, ?int $clientId, string $actorLabel): array
    {
        $message = 'mesh_add_allow_rule is staged-only: it always requires cockpit approval. '
            .'Re-grant it as `mesh_add_allow_rule:staged` (or call `mesh_stage_add_allow_rule`) and pass a ticket_id. No upstream call was made.';
        $this->auditAttempt('mesh_add_allow_rule', 'rejected', $clientId, null, $this->contentHash('mesh_add_allow_rule', $clientId, 'immediate-refused', $arguments), $message, $actorLabel);

        return ['error' => $message];
    }

    /**
     * Stage an allow rule for cockpit approval. There is no immediate lane.
     *
     * @return array<string, mixed>
     */
    private function stageAllowRule(array $arguments, int $clientId, string $actorLabel): array
    {
        $tool = 'mesh_stage_add_allow_rule';

        $guard = $this->baseGuard($tool, $arguments, $clientId, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, $this->contentHash($tool, $clientId, 'guard', $arguments), $ticket['error'], $actorLabel);

            return ['error' => $ticket['error']];
        }

        $target = $this->allowRuleTarget($arguments, $clientId);
        $contentHash = $this->contentHash($tool, $clientId, 'allow-rule-'.($target['sender'] ?? 'unresolved'), [
            'mesh_customer_id' => $target['mesh_customer_id'] ?? null,
        ]);
        if (isset($target['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $contentHash, $target['error'], $actorLabel);

            return ['error' => $target['error']];
        }

        // #1133: the caller's expiry is validated HERE, before a proposal
        // exists and before any dedup answer is given. A refusal at approval
        // time would be a refusal in front of a human holding a button,
        // minutes or days after the mistake was made.
        $expiry = $this->requestedExpiry($arguments);
        if (isset($expiry['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $contentHash, $expiry['error'], $actorLabel);

            return ['error' => $expiry['error']];
        }
        $expiresAt = $expiry['expires_at'];

        // The lifetime is part of what the write DOES to a customer's mail
        // filtering, so it is part of the identity of the write: without it,
        // re-staging the same sender with a different expiry (or with 'never')
        // is answered "already staged" against somebody else's lifetime, which
        // is the silent lifetime substitution #1133 exists to delete.
        //
        // Hashed as the caller's own vocabulary, with 'default' standing for an
        // omitted key: the resolved default instant is now()+90d and moves every
        // second, so hashing THAT would give two identical requests different
        // hashes and defeat dedup entirely.
        $contentHash = $this->contentHash($tool, $clientId, 'allow-rule-'.($target['sender'] ?? 'unresolved'), [
            'mesh_customer_id' => $target['mesh_customer_id'] ?? null,
            'expires_at' => array_key_exists('expires_at', $arguments) ? self::expiryValue($expiresAt) : 'default',
        ]);

        if ($this->alreadyExecuted('mesh_add_allow_rule', $clientId, $contentHash)) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $this->executedRunId('mesh_add_allow_rule', $clientId, $contentHash),
                'message' => 'This allow rule was already created recently; no new proposal was staged.',
            ];
        }

        // A live PSA row is stronger evidence than the audit log's dedup
        // window: it says the rule is believed live upstream RIGHT NOW,
        // whatever its age. Creating a second identical rule would leave a
        // duplicate the reaper cannot distinguish by sender alone.
        $live = $this->liveAllowRule($clientId, $target['sender']);
        if ($live !== null) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'sender' => $target['sender'],
                'expires_at' => self::expiryValue($live->expires_at),
                // #1133: a permanent rule has no date, and "until an
                // unrecorded date" would read as a PSA bookkeeping gap rather
                // than as the deliberate answer it is. Say permanent.
                'message' => $live->isPermanent()
                    ? "'{$target['sender']}' is already allowed for this client PERMANENTLY (PSA record #{$live->id}; it has no expiry and the PSA will never remove it); no proposal was staged."
                    : "'{$target['sender']}' is already allowed for this client until "
                        .$live->expires_at->toDayDateTimeString().'; no proposal was staged.',
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

        if ($this->cooldownActive($tool, $clientId)) {
            $this->auditAttempt($tool, 'blocked', $clientId, $ticket, $contentHash, 'Mesh allow-rule proposal cooldown active.', $actorLabel);

            return ['error' => 'mesh_add_allow_rule cooldown active for this client; no proposal was staged.'];
        }

        // Generated HERE, not at execution, and carried in the encrypted
        // payload: the approver is shown the exact comment the rule will
        // carry, and a retried execution re-uses the same match key instead of
        // creating a second rule the first one's re-read would then find.
        $comment = $this->generateComment();

        $proposedContent = "Allow mail from '{$target['sender']}' through Mesh Email Security for {$target['client_name']}.\n"
            .$target['scope_note']."\n"
            .($expiresAt === null
                // Criterion 5: the permanent case must read as permanent. It is
                // the one lifetime nothing in the PSA will ever end, so it is
                // stated in words and in capitals rather than left to an absent
                // date the approver has to notice.
                ? "This weakens filtering for that sender PERMANENTLY: the rule has NO EXPIRY and the PSA will NEVER remove it. Undoing it means deleting the rule by hand in the Mesh portal.\n"
                : 'This weakens filtering for that sender until the PSA removes the rule on '.$expiresAt->toDayDateTimeString()." UTC.\n")
            // Criterion 6: the vendor audit trail does NOT record the approver.
            // Mesh attributes every rule to the identity that owns the API key —
            // a person's account, not a service account — so the approver is
            // told whose name this will appear under before they release it.
            .'Mesh will record the rule as created by '.$this->upstreamIdentityLabel().", not by the approving technician; the PSA audit row records the approver and this ticket.\n"
            ."Mesh comment: {$comment}\n"
            .'Reason: '.$guard['reason'];

        $meta = [
            'drafted_by' => $actorLabel,
            'reasons' => [$guard['reason']],
            'direct_tool' => self::STAGED_TO_DIRECT[$tool],
            'redacted_params' => [
                'sender' => $target['sender'],
                'client' => $target['client_name'],
                // 'never' rather than a null: the card renders these values,
                // and an absent field reads as "not recorded" where the whole
                // point is that permanence was chosen deliberately (#1133).
                'expires_at' => self::expiryValue($expiresAt),
                'expiry_note' => self::expiryPhrase($expiresAt),
                'comment' => $comment,
                'upstream_attribution' => $this->upstreamIdentityLabel(),
            ],
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => self::STAGED_TO_DIRECT[$tool],
                'client_id' => $clientId,
                'ticket_id' => $ticket->id,
                'arguments' => [
                    // The PSA client is carried, never the Mesh tenant uuid:
                    // approval re-resolves the tenant from live PSA state,
                    // because a client's Mesh mapping can change between
                    // staging and approval and the stale one would write the
                    // rule into somebody else's tenant.
                    'sender' => $target['sender'],
                    'confirm_domain' => $target['domain'],
                    'comment' => $comment,
                    // Carried as the caller's own vocabulary — an ISO string or
                    // the literal 'never' — so approval re-resolves it through
                    // exactly the same validator the proposal passed. An
                    // omitted key would silently mean 90 days on the way back
                    // in, which is the fall-through #1133 exists to delete.
                    'expires_at' => self::expiryValue($expiresAt),
                    'reason' => $guard['reason'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ];

        // A spent run is a record, not a scratchpad. Reviving one would
        // overwrite the encrypted payload and committed comment of a run a
        // mesh_allow_rules row still points at, turn an operator's deny back
        // into an approvable proposal, and reset an Executing row so a second
        // claimForExecution() could succeed. The proposal moves to a free slot
        // instead; the hash it lands on is the one everything downstream uses.
        $slot = $this->stagedRunSlot($ticket->id, $tool, $contentHash);
        if (isset($slot['error'])) {
            $this->auditAttempt($tool, 'blocked', $clientId, $ticket, $contentHash, $slot['error'], $actorLabel);

            return ['error' => $slot['error']];
        }
        $contentHash = $slot['content_hash'];

        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => $tool,
                'content_hash' => $contentHash,
            ],
            [
                'client_id' => $clientId,
                'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ],
        );

        if (! $run->wasRecentlyCreated) {
            // The slot was free when it was chosen; a row on it now means a
            // concurrent staging call won the insert. Awaiting is the
            // idempotent answer — anything else is spent and is NOT rewritten.
            if ($run->state !== TechnicianRunState::AwaitingApproval) {
                $message = 'Another proposal for this write is already in flight on this ticket; no proposal was staged.';
                $this->auditAttempt($tool, 'blocked', $clientId, $ticket, $contentHash, $message, $actorLabel, $run->id);

                return ['error' => $message];
            }

            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $run->id,
                'message' => 'Already staged; awaiting approval.',
            ];
        }

        $this->auditAttempt($tool, 'awaiting_approval', $clientId, $ticket, $contentHash, "MCP staged Mesh allow rule for '{$target['sender']}'.", $actorLabel, $run->id);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'sender' => $target['sender'],
            'expires_at' => self::expiryValue($expiresAt),
            'message' => 'Staged for cockpit approval ('.self::expiryPhrase($expiresAt).').',
        ];
    }

    /**
     * Execute an approved allow rule. Only reachable through
     * approveStagedRun(); there is no `mesh_add_allow_rule_execute` tool.
     *
     * @return array<string, mixed>
     */
    private function executeAllowRule(
        array $arguments,
        int $clientId,
        string $actorLabel,
        ?TechnicianRun $run = null,
        ?int $approverId = null,
    ): array {
        $tool = 'mesh_add_allow_rule';

        // Every precondition is re-measured against LIVE state: the client's
        // Mesh mapping, the sender shape and the typed domain confirmation are
        // all re-derived here rather than trusted from the proposal.
        $target = $this->allowRuleTarget($arguments, $clientId);
        // The BASE hash for this write, re-derived from live state — NEVER
        // $run->content_hash. stagedRunSlot() hands a second proposal for the
        // same write the next generation of the key, so a generation>=1 run
        // carries a hash no other run shares; keying the only pre-upstream
        // duplicate guard on it would let a sibling proposal (a re-stage while
        // the first was Executing, or a denied run put back by the cockpit
        // undo) walk straight past a guard the first approval already
        // satisfied and open a SECOND hole in the customer's mail filtering.
        // Every proposal for one write therefore dedups on one key; run_id on
        // the audit row is what ties each row back to its own proposal.
        $contentHash = $this->contentHash('mesh_stage_add_allow_rule', $clientId, 'allow-rule-'.($target['sender'] ?? 'unresolved'), [
            'mesh_customer_id' => $target['mesh_customer_id'] ?? null,
        ]);
        if (isset($target['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, $target['error'], $actorLabel, $run?->id, $approverId);

            return ['error' => $target['error']];
        }

        if ($this->alreadyExecuted($tool, $clientId, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', $clientId, null, $contentHash, 'Duplicate Mesh allow rule suppressed before upstream call.', $actorLabel, $run?->id, $approverId);

            return ['success' => true, 'idempotent' => true, 'message' => 'This allow rule was already created recently; no upstream call was made.'];
        }

        // A live PSA record is the MEASURED statement that this write already
        // landed upstream, and unlike the audit dedup window it does not age
        // out. It is the second brake on two approvable proposals for one
        // write: whichever is approved first, the other must not create a
        // duplicate rule the reaper cannot tell apart by sender alone.
        $live = $this->liveAllowRule($clientId, $target['sender']);
        if ($live !== null) {
            $message = "'{$target['sender']}' is already allowed for this client by PSA record #".$live->id.'; no upstream call was made.';
            $this->auditAttempt($tool, 'blocked', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['success' => true, 'idempotent' => true, 'message' => $message];
        }

        // ...and the brake above only sees rows that were MEASURED live. Every
        // fault path (scope unconfirmed, id unrecoverable, create
        // unacknowledged) writes a NON-active row and audits
        // 'executed_with_fault', which alreadyExecuted() does not match either
        // — and those are precisely the outcomes that can be sitting on top of
        // a rule that IS live upstream. Without this check a sibling proposal
        // for the same sender (a re-stage while the first was Executing, gen 1
        // via stagedRunSlot) walks past both brakes and opens a SECOND hole in
        // the customer's mail filtering. Unknown is a refusal, never a pass.
        $unsettled = $this->unsettledAllowRule($clientId, $target['sender']);
        if ($unsettled !== null) {
            $message = "An earlier allow rule for '{$target['sender']}' on this client (PSA record #".$unsettled->id
                .') may still be live upstream and has not been proved absent, so a second rule was not created. '
                // #1133: a permanent record has no expiry to wait for, so
                // "until its expiry passes" would promise a resolution that is
                // never coming. Say what actually has to happen instead.
                .($unsettled->isPermanent()
                    ? 'That record is PERMANENT (no expiry), so the expiry job will never examine it and the PSA will not settle it on its own; '
                        .'resolve it in the Mesh portal by hand. '
                    : 'The PSA cannot settle that record until its expiry ('.$unsettled->expires_at->toIso8601String()
                        .') passes and the expiry job examines it; until then, ')
                .'allow this sender directly in the Mesh portal '
                .'if it is needed now. No upstream call was made.';
            $this->auditAttempt($tool, 'blocked', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['error' => $message];
        }

        $comment = $this->requiredString($arguments, 'comment') ?? $this->generateComment();

        // Re-validated at approval, not carried as a parsed value: the same
        // rule as the tenant and the domain confirmation above. A proposal
        // whose date has passed while it sat awaiting approval is refused
        // HERE, before any upstream call — creating a rule that is already
        // expired would open a hole and leave it open until the daily reaper
        // next ran. Nothing is created; the technician re-stages with a date
        // that means something.
        $expiry = $this->requestedExpiry($arguments);
        if (isset($expiry['error'])) {
            $message = $expiry['error'].' No upstream call was made; stage a new proposal with a valid expiry.';
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['error' => $message];
        }
        $expiresAt = $expiry['expires_at'];

        try {
            $created = $this->client->createAllowRule(
                $target['mesh_customer_id'],
                $target['sender'],
                $comment,
                // null omits `date_expiry` upstream — a permanent rule shows no
                // expiry in the portal, which is the truth about it.
                $expiresAt?->toIso8601String(),
            );
        } catch (MeshWriteRejectedException $e) {
            // Criterion 9: Mesh's own validation text IS the useful reason —
            // it names which field it disliked and why. Passing it through
            // beats a generic "the request was refused" that sends a
            // technician hunting.
            $message = 'Mesh refused the allow rule: '.$e->getMessage();
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['error' => $message];
        } catch (MeshClientException $e) {
            // NOT a failed create — but only for the failures that can sit on
            // top of a committed rule. Mesh commits before it answers, so a
            // read timeout, a reset connection or an edge 502 can leave a rule
            // live; declaring failure there is exactly what leaves a hole in
            // mail filtering with no row to reap it, and invites a second rule
            // when the approver retries. The post-condition is MEASURED before
            // anything is declared.
            if ($this->createMayHaveCommitted($e)) {
                return $this->reconcileUnacknowledgedCreate($e, $target, $comment, $expiresAt, $clientId, $contentHash, $actorLabel, $run, $approverId);
            }

            // A DETERMINATE rejection: Mesh answered and did not act (or the
            // client never sent anything). There is no rule to record and
            // nothing to reconcile — a mesh_allow_rules row here would be a
            // phantom the reaper can never retire, keeping
            // mesh:reap-allow-rules red forever for a rule that never existed.
            // The proposal stays approvable once the cause is fixed, exactly
            // as a 400 does.
            $message = 'Mesh refused the allow rule before it was created: '.$e->getMessage();
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['error' => $message];
        }

        // CRITERION 1. `added_for` is the ONLY scope evidence this API gives:
        // the stored row is normalised to organization_level:true /
        // customer_id:null no matter what we sent, so a read-back cannot tell
        // a tenant rule from a partner-wide one. Exact match or it is a fault.
        $addedFor = is_array($created['added_for'] ?? null) ? array_values(array_filter(array_map(
            static fn ($v): string => is_scalar($v) ? (string) $v : '',
            $created['added_for'],
        ), static fn (string $v): bool => $v !== '')) : [];
        $scopeProved = $addedFor === [$target['mesh_customer_id']];

        // The column records what the SERVER attested (see the migration), not
        // what we asked for — and on a scope fault those differ. The reaper's
        // only way back to the rule is a list read filtered on THIS stored
        // tenant, so storing the tenant we targeted would send it hunting a
        // tenant the rule is not on, forever. Our own tenant wins when
        // `added_for` names it among others (the rule really is there); when it
        // does not, the first tenant named is the only pointer that exists. An
        // empty `added_for` attests nothing, so the targeted tenant stands.
        // Truncated to the column width when it is the SERVER's value: our own
        // tenant is length-checked in allowRuleTarget, but `added_for[0]` is an
        // unbounded server-supplied string, and an over-length insert under a
        // strict driver would leave the rule live with NO row at all — strictly
        // worse than a truncated pointer carried alongside the fault text.
        $attestedTenant = $addedFor === [] || in_array($target['mesh_customer_id'], $addedFor, true)
            ? $target['mesh_customer_id']
            : mb_substr($addedFor[0], 0, 64);

        // Recorded BEFORE the scope verdict is acted on: if the scope is
        // wrong, a rule still exists upstream and the PSA row is the only
        // thing that will ever chase it down.
        $record = $this->recordCreatedRule([
            'client_id' => $clientId,
            'ticket_id' => $run?->ticket_id,
            'technician_run_id' => $run?->id,
            'mesh_customer_id' => $attestedTenant,
            'sender' => $target['sender'],
            'comment' => $comment,
            'mesh_rule_id' => null,
            'expires_at' => $expiresAt,
            'state' => MeshAllowRule::STATE_UNRESOLVED,
            'created_by_actor' => $actorLabel,
            'approver_user_id' => $approverId,
        ]);

        if ($record === null) {
            // The rule IS live upstream and the row that would reap it could
            // not be written. This is a FAULT, never an error: an error
            // releases the claim and puts the proposal back in front of the
            // approver, whose next click walks past all three brakes (no
            // 'executed' audit row, no live record, no unsettled record) and
            // opens a SECOND hole in the customer's mail filtering. The
            // proposal is spent and the outcome is stated in full so a human
            // can remove the rule by hand.
            $message = "Mesh created the allow rule for '{$target['sender']}' on tenant '{$attestedTenant}' (Mesh comment: {$comment}), "
                .'but the PSA record that would remove it could not be written, so nothing will ever expire it. '
                .'Delete this rule in the Mesh portal — it is live now and the PSA is not tracking it.';
            $this->auditAttempt($tool, 'executed_with_fault', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return [
                'success' => false,
                'fault' => 'record_unwritable',
                'sender' => $target['sender'],
                'expires_at' => self::expiryValue($expiresAt),
                'message' => $message,
            ];
        }

        if (! $scopeProved) {
            $message = 'Mesh did not confirm the rule was scoped to this client only. The rule exists upstream and has been recorded for removal (PSA record #'
                .$record->id.'); check it in the Mesh portal before relying on it.';
            $record->forceFill(['last_error' => $message])->save();
            // NOT audited as 'executed': a wrongly-scoped rule must not
            // satisfy the dedup check that would suppress a corrected retry.
            $this->auditAttempt($tool, 'executed_with_fault', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return [
                'success' => false,
                'fault' => 'scope_unconfirmed',
                'sender' => $target['sender'],
                'psa_record_id' => $record->id,
                'message' => $message,
            ];
        }

        // The 201 carries no rule id (measured), so identity is recovered by
        // re-reading the tenant's rules and matching sender + comment. The
        // read happens inside MeshWriteClient and returns at most one row —
        // the partner-wide list never reaches this method (criterion 7).
        $match = null;
        try {
            $match = $this->client->findRuleByComment($target['mesh_customer_id'], $target['sender'], $comment);
        } catch (MeshClientException $e) {
            $record->forceFill(['last_error' => 'Rule id re-read failed: '.$e->getMessage()])->save();
        }

        $ruleId = is_scalar($match['id'] ?? null) && (string) $match['id'] !== '' ? (string) $match['id'] : null;
        $upstreamCreatedBy = is_scalar($match['created_by'] ?? null) ? (string) $match['created_by'] : null;

        if ($ruleId === null) {
            // Criterion 8: an unresolvable id is a FAULT. The rule is live and
            // the reaper cannot delete what it cannot name; the row stays
            // unresolved so the reaper retries the match and keeps saying so.
            $message = "Allow rule created for '{$target['sender']}', but its Mesh rule id could not be recovered by re-read. "
                .'It is recorded (PSA record #'.$record->id.') and the expiry job will retry resolving it; it cannot be removed automatically until it resolves.';
            $record->forceFill(['upstream_created_by' => $upstreamCreatedBy, 'last_error' => $message])->save();
            $this->auditAttempt($tool, 'executed_with_fault', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return [
                'success' => false,
                'fault' => 'rule_id_unresolved',
                'sender' => $target['sender'],
                'psa_record_id' => $record->id,
                'expires_at' => self::expiryValue($expiresAt),
                'message' => $message,
            ];
        }

        $record->forceFill([
            'mesh_rule_id' => $ruleId,
            'state' => MeshAllowRule::STATE_ACTIVE,
            'upstream_created_by' => $upstreamCreatedBy,
            'last_error' => null,
        ])->save();

        // The audit row is the durable statement of what was created, so the
        // lifetime goes in it in words: a permanent rule and a 90-day one must
        // not read the same six months later (#1133).
        $summary = "Created Mesh allow rule for '{$target['sender']}' scoped to this client; "
            .($expiresAt === null ? 'PERMANENT — no expiry, the PSA will never remove it.' : 'expires '.$expiresAt->toDateString().'.');
        $this->auditAttempt($tool, 'executed', $clientId, null, $contentHash, $summary.' Mesh rule id '.$ruleId.'.', $actorLabel, $run?->id, $approverId);

        return [
            'success' => true,
            'sender' => $target['sender'],
            'mesh_rule_id' => $ruleId,
            'psa_record_id' => $record->id,
            'expires_at' => self::expiryValue($expiresAt),
            'scope_confirmed' => true,
            'upstream_created_by' => $upstreamCreatedBy,
            'message' => $summary.($expiresAt === null
                ? ' Nothing in the PSA will ever delete it, and Mesh does not expire rules on its own; removing it means deleting it by hand in the Mesh portal.'
                : ' The PSA will delete it at expiry — Mesh does not expire rules on its own.'),
        ];
    }

    /**
     * A create whose OUTCOME was never measured — the request went out and no
     * usable response came back. "We could not tell" is not "it did not
     * happen": the proposal's comment is a random per-proposal token, so one
     * re-read of this client's tenant answers the question directly.
     *
     * Either answer ends in a row. A found rule is recorded ACTIVE with its id
     * and reaps normally; an unfound (or unreadable) one is recorded UNRESOLVED
     * so the reaper keeps trying to name it. Never audited as 'executed' —
     * there was no 201, so scope was never proved and a corrected retry must
     * stay possible — and never returned as an `error`, because that would
     * release the claim and let a second approval write a second rule.
     *
     * @param  array<string, string>  $target
     * @return array<string, mixed>
     */
    private function reconcileUnacknowledgedCreate(
        MeshClientException $error,
        array $target,
        string $comment,
        ?\Illuminate\Support\Carbon $expiresAt,
        int $clientId,
        string $contentHash,
        string $actorLabel,
        ?TechnicianRun $run,
        ?int $approverId,
    ): array {
        $tool = 'mesh_add_allow_rule';

        $match = null;
        $rereadError = null;
        try {
            $match = $this->client->findRuleByComment($target['mesh_customer_id'], $target['sender'], $comment);
        } catch (MeshClientException $e) {
            $rereadError = $e->getMessage();
        }

        $ruleId = is_scalar($match['id'] ?? null) && (string) $match['id'] !== '' ? (string) $match['id'] : null;

        $record = $this->recordCreatedRule([
            'client_id' => $clientId,
            'ticket_id' => $run?->ticket_id,
            'technician_run_id' => $run?->id,
            'mesh_customer_id' => $target['mesh_customer_id'],
            'sender' => $target['sender'],
            'comment' => $comment,
            'mesh_rule_id' => $ruleId,
            'expires_at' => $expiresAt,
            // NEVER active, even with an id in hand. ACTIVE is the MEASURED
            // record liveAllowRule() answers "already allowed for this client"
            // from, and a read-back is not scope evidence — the server
            // normalises every stored row to organization_level:true /
            // customer_id:null, so it cannot tell a tenant rule from a
            // partner-wide one. There was no create response here, so scope
            // was never proved; recording ACTIVE would suppress the corrected
            // retry for up to the full lifetime. The id (when we have one) is
            // still stored, which is all the reaper needs.
            'state' => MeshAllowRule::STATE_UNRESOLVED,
            'created_by_actor' => $actorLabel,
            'approver_user_id' => $approverId,
            'upstream_created_by' => is_scalar($match['created_by'] ?? null) ? (string) $match['created_by'] : null,
        ]);

        if ($record === null) {
            // Same rule as the create path: a row we could not write must not
            // be reported as an error, because an error hands the approver a
            // button that writes a second rule for the same sender. Spent,
            // loud, and handed to a human with the match key.
            $message = 'Mesh never answered the create ('.$error->getMessage()."), and the PSA record for '{$target['sender']}' could not be written"
                .($ruleId !== null ? " (a re-read found the rule live upstream as '{$ruleId}')" : '')
                .'. Nothing will expire it. Look for a rule with the comment '.$comment." on this client's Mesh tenant and remove it by hand.";
            $this->auditAttempt($tool, 'executed_with_fault', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return [
                'success' => false,
                'fault' => 'record_unwritable',
                'sender' => $target['sender'],
                'expires_at' => self::expiryValue($expiresAt),
                'message' => $message,
            ];
        }

        $message = $ruleId !== null
            ? 'Mesh never answered the create ('.$error->getMessage()."), but a re-read of this client's tenant found the rule live for '{$target['sender']}'. "
                .'It is recorded (PSA record #'.$record->id.') and '
                .($expiresAt === null
                    ? 'it is PERMANENT — the PSA will never remove it. '
                    : 'the PSA will remove it at expiry. ')
                .'There was no create response, so its scope was never confirmed — check it in the Mesh portal.'
            : 'Mesh never answered the create ('.$error->getMessage()."), and a re-read of this client's tenant did not find the rule"
                .($rereadError !== null ? ' (that read failed too: '.$rereadError.')' : '')
                .'. Whether the rule was created is UNMEASURED, so it is recorded unresolved (PSA record #'.$record->id.') and '
                .($expiresAt === null
                    // #1133: this row is PERMANENT (NULL expires_at), and
                    // scopeReapable()'s whereNotNull excludes it in EVERY state.
                    // The expiry job is not coming for it, so it will never
                    // resolve the id and never remove it — saying otherwise
                    // would be a false statement of system behaviour on the
                    // worst path this method has. A human is the only exit.
                    ? 'it is PERMANENT — nothing in the PSA will ever identify or remove it. Look for a rule with the comment '.$comment." on this client's Mesh tenant and remove it by hand."
                    : 'the expiry job keeps trying to identify and remove it.')
                .' Check the Mesh portal before allowing this sender again.';

        $record->forceFill(['last_error' => $message])->save();
        // Deliberately NOT 'executed': an unmeasured write must not satisfy the
        // dedup check that would suppress a corrected retry.
        $this->auditAttempt($tool, 'executed_with_fault', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

        return [
            'success' => false,
            'fault' => $ruleId !== null ? 'create_unacknowledged' : 'create_outcome_unmeasured',
            'sender' => $target['sender'],
            'psa_record_id' => $record->id,
            'expires_at' => self::expiryValue($expiresAt),
            'message' => $message,
        ];
    }

    /**
     * Could this failure be sitting on top of a rule Mesh already committed?
     *
     * Only two shapes can: a request that reached Mesh and got no readable
     * answer (status 0 — read timeout, reset connection, transport failure
     * mid-flight) and a 5xx from a server that may have committed before it
     * fell over. Everything else is a server that ANSWERED without acting —
     * 401/403 (credential), 404 (route), 429 — and MeshWriteClient's own
     * pre-flight guards ('… nothing was sent') never put a request on the
     * wire at all. Reconciling those would create a mesh_allow_rules row for a
     * rule that does not exist: reapOne() has no terminal state for it, so it
     * would burn a BATCH_LIMIT slot and force mesh:reap-allow-rules to exit
     * FAILURE on every run, forever.
     */
    private function createMayHaveCommitted(MeshClientException $error): bool
    {
        if (str_contains($error->getMessage(), 'nothing was sent')) {
            return false;
        }

        $status = (int) $error->getCode();

        return $status === 0 || $status >= 500;
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

            if (! MeshConfig::isEnabled() || ! MeshConfig::isConfigured()) {
                $this->auditAttempt($run->action_type, 'blocked', (int) $run->client_id, $ticket, $run->content_hash, 'Mesh is not configured; staged allow rule refused.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            if (TechnicianConfig::killSwitchEngaged()) {
                $this->auditAttempt($run->action_type, 'blocked', (int) $run->client_id, $ticket, $run->content_hash, 'Technician kill-switch engaged; staged Mesh allow rule refused.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            if ($this->approveCooldownActive((int) $run->client_id)) {
                $this->auditAttempt($run->action_type, 'blocked', (int) $run->client_id, $ticket, $run->content_hash, 'Mesh allow-rule cooldown active; approval refused before upstream call.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $arguments = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];
            $result = match ($directTool) {
                'mesh_add_allow_rule' => $this->executeAllowRule($arguments, (int) $run->client_id, $this->approverLabel($approverId), $run, $approverId),
                default => ['error' => 'Unsupported Mesh staged admin action.'],
            };

            if (isset($result['error'])) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $run->advanceTo(TechnicianRunState::Done);

            // The rule landed upstream but a post-condition did not hold
            // (wrong scope, or an id we cannot name). The proposal is spent —
            // the write is not undoable from here — so the outcome goes out on
            // the fault channel with the specific text, never as a clean
            // execution the cockpit renders green.
            if (isset($result['fault'])) {
                return new TechnicianApprovalResult(
                    'executed_with_fault',
                    message: is_string($result['message'] ?? null) ? $result['message'] : null,
                );
            }

            return new TechnicianApprovalResult('executed');
        } catch (\Throwable $e) {
            $run->releaseClaim();

            throw $e;
        }
    }

    /**
     * Resolve and validate everything the rule depends on: the client, its
     * Mesh tenant, the sender, and the typed domain confirmation.
     *
     * @return array{sender?: string, domain?: string, mesh_customer_id?: string, client_name?: string, scope_note?: string, error?: string}
     */
    private function allowRuleTarget(array $arguments, int $clientId): array
    {
        $client = Client::find($clientId);
        if (! $client) {
            return ['error' => 'Client not found'];
        }

        $tenant = trim((string) ($client->mesh_customer_id ?? ''));
        if ($tenant === '') {
            return ['error' => "{$client->name} has no Mesh customer mapping; link the client to its Mesh tenant before creating allow rules."];
        }

        // mesh_allow_rules.mesh_customer_id is a 64-char column. A value that
        // will not fit is refused HERE, before anything reaches upstream: an
        // insert that fails AFTER the 201 leaves a live rule with no row to
        // reap it, which is the one outcome this verb must never produce.
        if (mb_strlen($tenant) > 64) {
            return ['error' => "{$client->name}'s Mesh customer mapping is not a usable Mesh tenant id (longer than 64 characters); fix the mapping before creating allow rules."];
        }

        $sender = $this->requiredString($arguments, 'sender');
        if ($sender === null) {
            return ['error' => 'sender is required'];
        }
        $sender = mb_strtolower($sender);

        $domain = $this->senderDomain($sender);
        if ($domain === null) {
            return ['error' => 'sender must be a single email address or a single domain. Wildcards, lists and partial domains are refused.'];
        }

        // Same reason as the tenant check above: senders that pass the shape
        // rules can still exceed the 255-char sender column (64 local part +
        // 253 domain), and the sender is the reaper's match key, so it cannot
        // be truncated. Refuse before the upstream call, not after it.
        if (mb_strlen($sender) > 255) {
            return ['error' => 'sender is too long to record against this rule (max 255 characters); no allow rule was created.'];
        }

        // Criterion 5: the typed confirmation is on the DOMAIN, because the
        // domain is what the allow actually widens — a typo in the local part
        // allows one address, a typo in the domain allows a stranger's mail.
        $confirm = $this->requiredString($arguments, 'confirm_domain');
        if ($confirm === null || mb_strtolower($confirm) !== $domain) {
            return ['error' => "confirm_domain must exactly match the sender's domain ('{$domain}') to create this allow rule."];
        }

        return [
            'sender' => $sender,
            'domain' => $domain,
            'mesh_customer_id' => $tenant,
            'client_name' => (string) $client->name,
            'scope_note' => $sender === $domain
                ? "Scope: EVERY sender at '{$domain}' — this is a whole-domain allow, wider than a single address."
                : "Scope: this one address only; other senders at '{$domain}' are unaffected.",
        ];
    }

    /**
     * The domain an allow rule would widen, or null if the sender is not a
     * single address or a single domain.
     *
     * Deliberately strict: no wildcards, no comma/space-separated lists, no
     * bare TLDs. Everything this rejects is something whose blast radius a
     * technician typing `confirm_domain` would not have understood.
     */
    private function senderDomain(string $sender): ?string
    {
        if (preg_match('/[\s,;*]/', $sender) === 1) {
            return null;
        }

        $domain = $sender;
        if (str_contains($sender, '@')) {
            if (filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
                return null;
            }
            $domain = substr($sender, strrpos($sender, '@') + 1);
        }

        $valid = preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1;

        return $valid ? $domain : null;
    }

    /**
     * The Mesh `comment` for a new rule: fixed words plus a random token,
     * filtered to the charset measured to pass Mesh's validator. The filter is
     * belt-and-braces — Str::random is already alphanumeric — but it is what
     * guarantees the invariant rather than a fact about a helper elsewhere.
     */
    private function generateComment(): string
    {
        $token = preg_replace('/[^A-Za-z0-9]/', '', Str::random(self::COMMENT_TOKEN_LENGTH * 2)) ?? '';
        $token = mb_substr($token.'0000000000', 0, self::COMMENT_TOKEN_LENGTH);

        return self::COMMENT_PREFIX.' '.strtoupper($token);
    }

    /**
     * Whose name Mesh will put on the rule (criterion 6).
     *
     * Mesh has no service-account concept for API keys: `created_by` on every
     * rule resolves to the mailbox that owns the key. The last value we
     * actually OBSERVED is used when we have one, because it is measured
     * rather than assumed; before the first rule exists there is nothing to
     * measure, and the honest answer is to say what the attribution is
     * without naming a person we have not confirmed.
     */
    private function upstreamIdentityLabel(): string
    {
        $observed = MeshAllowRule::query()
            ->whereNotNull('upstream_created_by')
            ->latest('id')
            ->value('upstream_created_by');

        return is_string($observed) && trim($observed) !== ''
            ? trim($observed)
            : 'the account that owns the PSA’s Mesh API key (a named person, not a service account)';
    }

    /**
     * @return array{reason?: string, error?: string}
     */
    private function baseGuard(string $tool, array $arguments, ?int $clientId, string $actorLabel): array
    {
        if ($refused = $this->refusedArgumentKeys($arguments)) {
            $message = 'These parameters are not accepted by mesh_add_allow_rule: '.implode('; ', $refused).'.';
            $this->auditAttempt($tool, 'rejected', $clientId, null, $this->contentHash($tool, $clientId, 'guard', $arguments), $message, $actorLabel);

            return ['error' => $message];
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, $this->contentHash($tool, $clientId, 'guard', $arguments), 'reason is required.', $actorLabel);

            return ['error' => 'reason is required'];
        }

        if (mb_strlen($reason) > self::REASON_MAX) {
            return ['error' => 'reason must be '.self::REASON_MAX.' characters or fewer'];
        }

        if (TechnicianConfig::killSwitchEngaged()) {
            $this->auditAttempt($tool, 'blocked', $clientId, null, $this->contentHash($tool, $clientId, 'guard', $arguments), 'Technician kill-switch engaged; Mesh MCP admin action refused.', $actorLabel);

            return ['error' => 'Technician kill-switch engaged; Mesh MCP admin action refused'];
        }

        return ['reason' => $reason];
    }

    /**
     * Every argument key that is not in the allow-list, each with a reason
     * where we have one. Unknown keys are refused too: a caller sending a
     * parameter this verb has never heard of is either using the wrong tool or
     * expecting behaviour it will not get, and silently dropping it is how the
     * second kind of mistake goes unnoticed.
     *
     * @return array<int, string>
     */
    private function refusedArgumentKeys(array $arguments): array
    {
        $refused = [];

        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (in_array($key, self::ALLOWED_ARGUMENT_KEYS, true)) {
                continue;
            }

            $refused[] = isset(self::REFUSED_ARGUMENT_KEYS[$key])
                ? "{$key} (".self::REFUSED_ARGUMENT_KEYS[$key].')'
                : "{$key} (not a parameter of this tool)";
        }

        return $refused;
    }

    /** @return Ticket|array{error: string} */
    private function ticketForClient(mixed $ticketIdValue, int $clientId): Ticket|array
    {
        $ticketId = $this->positiveInteger($ticketIdValue);
        if ($ticketId === null) {
            return ['error' => 'ticket_id is required for a staged Mesh allow rule'];
        }

        $ticket = Ticket::find($ticketId);
        if (! $ticket || (int) $ticket->client_id !== $clientId) {
            return ['error' => 'Ticket not found or belongs to a different client'];
        }

        return $ticket;
    }

    /**
     * The MEASURED live PSA record for this sender on this client, if one
     * exists — active rows only.
     *
     * An `unresolved` row is what the scope-fault and id-unrecoverable paths
     * write: a rule whose existence or scope was never proved. Answering
     * "already allowed until ..." from one would assert a protection nobody
     * measured and would suppress the corrected retry, mid-remediation, which
     * is the one thing a technician needs to be able to do. Unknown is a
     * refusal to claim, not a pass.
     */
    private function liveAllowRule(int $clientId, string $sender): ?MeshAllowRule
    {
        return MeshAllowRule::query()
            ->where('client_id', $clientId)
            ->where('sender', $sender)
            ->where('state', MeshAllowRule::STATE_ACTIVE)
            // #1133: a permanent rule (NULL expiry) is the MOST live row this
            // query can find, and `expires_at > now()` evaluates to NULL for
            // it — not true. Without the null arm the strongest duplicate
            // brake in the verb would silently stop seeing exactly the rules
            // that never go away, and a second permanent hole could be opened
            // for the same sender.
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();
    }

    /**
     * A row for this sender whose upstream existence was never settled either
     * way: the fault paths write `unresolved`, and the reaper writes
     * `reap_failed` when it could not prove absence. Both mean "a rule may be
     * live for this sender right now", so a second create is refused.
     *
     * EXECUTE-TIME ONLY. At staging an unresolved row deliberately does not
     * answer "already allowed" — a corrected proposal must still be stageable;
     * it is the upstream write, not the proposal, that must fail closed.
     *
     * Not filtered on expiry: Mesh expires nothing (measured 2026-09-01), so an
     * expired row that was never reaped is exactly as live as an unexpired one.
     */
    private function unsettledAllowRule(int $clientId, string $sender): ?MeshAllowRule
    {
        return MeshAllowRule::query()
            ->where('client_id', $clientId)
            ->where('sender', $sender)
            ->whereIn('state', [MeshAllowRule::STATE_UNRESOLVED, MeshAllowRule::STATE_REAP_FAILED])
            ->latest('id')
            ->first();
    }

    /**
     * Persist the PSA row for a rule that IS (or may be) live upstream.
     *
     * Returns null instead of throwing. The upstream create has already
     * committed by the time this runs, and an exception here would escape into
     * approveStagedRun's catch, which calls releaseClaim() and returns the run
     * to AwaitingApproval — so the next Approve click would create a SECOND
     * rule for the same sender with neither one recorded for reaping. A row we
     * could not write is a fault the caller reports; it is never a reason to
     * make a landed write look un-done.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function recordCreatedRule(array $attributes): ?MeshAllowRule
    {
        try {
            return MeshAllowRule::create($attributes);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[Mesh] Allow rule is live upstream but its PSA record could not be written: '.$e->getMessage(), [
                'client_id' => $attributes['client_id'] ?? null,
                'mesh_customer_id' => $attributes['mesh_customer_id'] ?? null,
                'sender' => $attributes['sender'] ?? null,
                'comment' => $attributes['comment'] ?? null,
            ]);

            return null;
        }
    }

    /**
     * The lifetime the caller asked for (#1133), or a refusal.
     *
     * Three answers, and they are deliberately distinguishable:
     *   key absent            -> DEFAULT_LIFETIME_DAYS, the previous behaviour,
     *                            preserved for every caller that never asks.
     *   the literal `never`   -> null, i.e. permanent. An explicit sentinel,
     *                            because "permanent" must be something a
     *                            caller SAYS, never something it falls into.
     *   an ISO-8601 date/time -> that instant.
     *
     * Anything else is REFUSED. This method used to fall through to 90 days on
     * an unparseable value, on the reasoning that an unreadable expiry must not
     * become "no expiry" — true, but the remedy was wrong: it turned a typo
     * into a lifetime nobody chose and told nobody. Now the caller is told. A
     * past date is refused for the same reason and one more: the rule would be
     * born reapable, so it would open a hole and hold it until the daily reaper
     * happened to run.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{expires_at: \Illuminate\Support\Carbon|null}|array{error: string}
     */
    private function requestedExpiry(array $arguments): array
    {
        if (! array_key_exists('expires_at', $arguments)) {
            return ['expires_at' => now()->addDays(MeshAllowRule::DEFAULT_LIFETIME_DAYS)];
        }

        $value = $arguments['expires_at'];

        // Empty, whitespace-only, and an explicit null are all the SAME case,
        // and none of them is the absent case. Over HTTP the first two arrive
        // here as the third: Laravel's global TrimStrings and
        // ConvertEmptyStringsToNull middleware run before the MCP controller,
        // so '   ' has already become null by the time the executor sees it
        // (measured 2026-09-02 — the '' branch alone was unreachable over the
        // wire). The key was still SENT, so something was meant by it, and
        // guessing which of the three answers it was is exactly the class of
        // guess this method exists to stop.
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return ['error' => 'expires_at was empty; give an ISO-8601 date or datetime, or the word "never" for a rule that never expires. Omit the parameter entirely for the default '.MeshAllowRule::DEFAULT_LIFETIME_DAYS.'-day lifetime'];
        }

        if (! is_string($value)) {
            return ['error' => 'expires_at must be an ISO-8601 date or datetime, or the word "never" for a rule that never expires'];
        }

        $value = trim($value);

        if (strcasecmp($value, self::EXPIRY_NEVER) === 0) {
            return ['expires_at' => null];
        }

        try {
            // Normalised to UTC ONCE, here. An offset-bearing ISO-8601 value is
            // valid input ('2026-12-01T09:00:00+09:00' — the schema text invites
            // it) and Carbon::parse KEEPS that offset. Everything downstream
            // then reads the value as if it were UTC: the approval card and
            // expiryPhrase() append a literal ' UTC' to a wall-clock in the
            // input's own zone, and the `datetime` cast stores that same
            // wall-clock, so the reaper ends the hole by the offset. The instant
            // the caller named must be the instant every consumer sees.
            $parsed = \Illuminate\Support\Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return ['error' => "expires_at ('{$value}') is not a date this system can read; give an ISO-8601 date or datetime (e.g. 2026-12-01 or 2026-12-01T17:00:00Z), or the word \"never\""];
        }

        if ($parsed->lessThanOrEqualTo(now())) {
            // The parsed date is echoed back, not just the input, because PHP
            // silently REINTERPRETS a mistyped year rather than rejecting it:
            // '99999-01-01' parses as 2009-01-01 and '20261-01-01' as
            // 2001-01-01T20:26 (measured 2026-09-02). Both land in the past and
            // are caught here, but a caller told only "that is in the past"
            // about a date they typed as the year 99999 would have no idea why.
            return ['error' => "expires_at ('{$value}') reads as ".$parsed->toDayDateTimeString().' UTC, which is in the past; an allow rule cannot be created already expired. Give a future ISO-8601 date, or the word "never"'];
        }

        if ($parsed->year > 9999) {
            // Beyond what a datetime column can hold — reachable through a
            // relative expression such as '+100000 years', which Carbon accepts
            // and which parses to a real, far-future date rather than failing.
            // Refusing here beats failing on the INSERT, which happens AFTER
            // the rule is live upstream and is reported as record_unwritable —
            // a live, untracked hole created by a typo in a year.
            return ['error' => "expires_at ('{$value}') is further away than this system can record; use the word \"never\" for a rule that never expires"];
        }

        return ['expires_at' => $parsed];
    }

    /**
     * The wire/return form of a lifetime: an ISO-8601 instant, or the same
     * literal the caller uses to ask for permanence. Never a bare null — a
     * missing field reads as "not recorded" where the point is that permanence
     * was chosen on purpose.
     */
    private static function expiryValue(?\Illuminate\Support\Carbon $expiresAt): string
    {
        return $expiresAt?->toIso8601String() ?? self::EXPIRY_NEVER;
    }

    /** The same fact in words, for a human reading an approval card (#1133 criterion 5). */
    private static function expiryPhrase(?\Illuminate\Support\Carbon $expiresAt): string
    {
        return $expiresAt === null
            ? 'PERMANENT — never reaped by the PSA'
            : 'expires '.$expiresAt->toDayDateTimeString().' UTC';
    }

    private function alreadyExecuted(string $tool, ?int $clientId, string $contentHash): bool
    {
        return $this->actionLogQuery($tool, $clientId)
            ->where('content_hash', $contentHash)
            ->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->exists();
    }

    private function executedRunId(string $tool, ?int $clientId, string $contentHash): ?int
    {
        return $this->actionLogQuery($tool, $clientId)
            ->where('content_hash', $contentHash)
            ->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->latest('id')
            ->value('run_id');
    }

    /**
     * "Is there a live staged run awaiting approval right now" comes from the
     * runs table, NEVER the immutable audit log — a stale awaiting_approval
     * audit row survives an operator deny by design (bd psa-k4s0 Root B).
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

    /**
     * The (ticket, action, content_hash) slot this proposal may occupy.
     *
     * `technician_runs_idempotency` makes ticket_id + action_type +
     * content_hash UNIQUE, so a repeat request cannot insert a second row on
     * the same hash — and it must not rewrite the first: a Done run is the
     * record of a rule that exists upstream (mesh_allow_rules.technician_run_id
     * points at it), a Denied run is an operator's decision, and an Executing
     * run is holding the exactly-once claim. So a hash is usable only while it
     * is free or still awaiting approval; otherwise the proposal takes the next
     * generation of that key. Generations are exhausted, never reused.
     *
     * @return array{content_hash: string}|array{error: string}
     */
    private function stagedRunSlot(int $ticketId, string $tool, string $baseHash): array
    {
        for ($generation = 0; $generation < self::MAX_RUN_GENERATIONS; $generation++) {
            $hash = $generation === 0 ? $baseHash : hash('sha256', $baseHash.':'.$generation);

            $existing = TechnicianRun::query()
                ->where('ticket_id', $ticketId)
                ->where('action_type', $tool)
                ->where('content_hash', $hash)
                ->first();

            if ($existing === null || $existing->state === TechnicianRunState::AwaitingApproval) {
                return ['content_hash' => $hash];
            }
        }

        return ['error' => 'This ticket already carries '.self::MAX_RUN_GENERATIONS.' Mesh allow-rule proposals for this sender; no proposal was staged. Raise a new ticket for a further allow rule.'];
    }

    private function cooldownActive(string $tool, ?int $clientId): bool
    {
        return $this->actionLogQuery($tool, $clientId)
            ->where('created_at', '>=', now()->subSeconds(self::COOLDOWN_SECONDS))
            ->whereIn('result_status', ['executed', 'awaiting_approval'])
            ->exists();
    }

    /**
     * APPROVE-TIME cooldown: rows for attempts that REACHED UPSTREAM. The
     * staging call leaves an awaiting_approval row for this same client, and
     * counting it would make every proposal decline its own approval inside
     * the cooldown window. Runaway staging is the stage-time check's question,
     * not this one's.
     *
     * 'executed_with_fault' counts: the write landed (or may have), so a second
     * approval seconds later is the same burst this window exists to damp —
     * counting only clean executions left the fault outcomes, the ones where a
     * rule is live and unaccounted for, with no cooldown at all.
     */
    private function approveCooldownActive(int $clientId): bool
    {
        return $this->actionLogQuery('mesh_add_allow_rule', $clientId)
            ->where('created_at', '>=', now()->subSeconds(self::COOLDOWN_SECONDS))
            ->whereIn('result_status', ['executed', 'executed_with_fault'])
            ->exists();
    }

    /** The staged and direct names share a cooldown and dedup window; they are one action. */
    private function actionLogQuery(string $tool, ?int $clientId)
    {
        $query = TechnicianActionLog::query()
            ->whereIn('action_type', ['mesh_add_allow_rule', 'mesh_stage_add_allow_rule']);

        return $clientId === null
            ? $query->whereNull('client_id')
            : $query->where('client_id', $clientId);
    }

    /**
     * Audit an attempt. NEVER throws.
     *
     * Same rule as recordCreatedRule(), for the same reason and on the same
     * failure: the `record_unwritable` fault paths call this immediately after
     * recordCreatedRule() returned null, i.e. precisely when the DB is
     * unavailable, so a bare TechnicianActionLog::create() here would throw on
     * the very trigger those branches exist for. That exception escapes into
     * approveStagedRun's catch, which calls releaseClaim() and returns the run
     * to AwaitingApproval — and with neither an audit row nor a
     * mesh_allow_rules row written, none of the brakes (alreadyExecuted,
     * liveAllowRule, unsettledAllowRule, approveCooldownActive) can see the
     * write that landed, so the next Approve click opens a SECOND hole in the
     * customer's mail filtering. A lost audit line is a fault to log; it is
     * never a reason to make a landed write look un-done. Mirrors
     * McpStaffController::audit(), which guards the identical write.
     */
    private function auditAttempt(...$arguments)
    {
        try {
            return $this->writeAuditAttempt(...$arguments);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[Mesh] Allow-rule audit row could not be written: '.$e->getMessage());

            return null;
        }
    }

    private function writeAuditAttempt(
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
        // Criterion 2: the ticket reference lives HERE, in the PSA audit row —
        // never in the Mesh comment, which is visible in a vendor portal.
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

    private function contentHash(string $tool, ?int $clientId, string $target, array $params): string
    {
        // reason is excluded: two identical rules differing only in wording
        // are the same write, and dedup must catch the second one.
        unset($params['reason'], $params['staged']);
        ksort($params);

        return hash('sha256', json_encode([
            'tool' => $tool,
            'client_id' => $clientId,
            'target' => $target,
            'params' => $params,
        ]));
    }

    private function decryptRunPayload(TechnicianRun $run): ?array
    {
        $ciphertext = $run->proposed_meta['encrypted_payload'] ?? null;
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        try {
            $json = Crypt::decryptString($ciphertext);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private function approverLabel(int $approverId): string
    {
        $user = \App\Models\User::find($approverId);

        return $user?->email ?? $user?->name ?? "approver:{$approverId}";
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

        if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
            return (int) $value > 0 ? (int) $value : null;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function addAllowRuleTool(): array
    {
        return self::tool(
            'mesh_add_allow_rule',
            'Allow mail from one sender (a single address, or a whole domain) through Mesh Email Security for ONE customer tenant, resolved server-side from the PSA client. '
            .'STAGED ONLY: every call is held as a cockpit approval proposal. There is no immediate implementation — a bare (immediate) grant is refused with a pointer to `mesh_add_allow_rule:staged`. '
            .'This WEAKENS the customer’s mail filtering for that sender. It is allow-only, never partner-wide, never connection-level (`edge`). '
            .'The lifetime is yours to choose with `expires_at` and the PSA enforces it, because Mesh does not expire its own rules: give an ISO-8601 date, '
            .'or "'.self::EXPIRY_NEVER.'" for a rule that is NEVER removed, or omit it for the '.MeshAllowRule::DEFAULT_LIFETIME_DAYS.'-day default. '
            .'An expiry that cannot be read, or one already in the past, is refused rather than defaulted. '
            .'Scope is confirmed from Mesh’s create response, not from a read-back. Requires reason, ticket_id and a typed domain confirmation; '
            .'sender, ab, edge, customer scope, comment and vendor-side expiry parameters beyond those are refused.',
            self::allowRuleProperties(),
            ['sender', 'confirm_domain', 'reason', 'ticket_id'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageAddAllowRuleTool(): array
    {
        return self::tool(
            'mesh_stage_add_allow_rule',
            'Stage a Mesh Email Security allow rule for cockpit approval. STAGED ONLY — this is the only lane the verb has: approval re-resolves the client’s Mesh tenant and re-checks the sender and typed domain confirmation against LIVE state before the rule is created. '
            .'This WEAKENS the customer’s mail filtering for that sender (allow-only, never partner-wide, never `edge`) until the PSA removes the rule — after the lifetime `expires_at` asks for, '
            .'or the '.MeshAllowRule::DEFAULT_LIFETIME_DAYS.'-day default if it is omitted, or NEVER if it is "'.self::EXPIRY_NEVER.'". '
            .'The proposal names the sender, the scope width (single address vs whole domain), the chosen lifetime in words (including PERMANENT when there is no expiry), and whose identity Mesh will record as the rule’s creator. '
            .'Requires a ticket, reason, typed domain confirmation, explicit grant, kill-switch, dedup and cooldown.',
            self::allowRuleProperties(),
            ['sender', 'confirm_domain', 'reason', 'ticket_id'],
        );
    }

    /** @return array<string, mixed> */
    private static function allowRuleProperties(): array
    {
        return [
            'sender' => [
                'type' => 'string',
                'description' => 'One email address (allows that address) or one domain (allows EVERY sender at that domain). Wildcards, lists and partial domains are refused.',
            ],
            'confirm_domain' => [
                'type' => 'string',
                'description' => 'Typed confirmation of the sender’s domain. Must match exactly (case-insensitive) or the rule is refused.',
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'Specific operational reason for weakening filtering for this sender.',
            ],
            'ticket_id' => [
                'type' => 'integer',
                'description' => 'PSA ticket this allow belongs to. The proposal is staged against it and the ticket reference is recorded in the PSA audit row (never in the Mesh comment).',
            ],
            'expires_at' => [
                'type' => 'string',
                'description' => 'Optional. When this allow rule should stop applying: an ISO-8601 date or datetime (e.g. 2026-12-01 or 2026-12-01T17:00:00Z), '
                    .'or the word "'.self::EXPIRY_NEVER.'" for a rule that NEVER expires and that nothing in the PSA will ever remove. '
                    .'Omit it for the default '.MeshAllowRule::DEFAULT_LIFETIME_DAYS.'-day lifetime. A value that cannot be read, or a date already in the past, is refused — it is never rounded to the default. '
                    .'Choose "'.self::EXPIRY_NEVER.'" deliberately: it leaves a permanent hole in this customer’s mail filtering, and the approver is shown it as PERMANENT.',
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
}
