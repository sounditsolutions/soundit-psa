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
 *  - THE CALLER CHOOSES THE LIFETIME, AND THE DEFAULT IS NONE (#1133, and the
 *    owner's 2026-10-05 ruling: "it should be an option, not a default").
 *    `expires_at` is optional. Omitted, or the literal `never`, the rule is
 *    PERMANENT; an ISO-8601 date/datetime makes it temporary. A value that
 *    was sent but is empty, cannot be read, or has already passed is
 *    REFUSED — never quietly turned into some lifetime nobody chose.
 *  - IT IS PERMANENT UNLESS AN EXPIRY IS GIVEN. Mesh does not expire rules
 *    (measured 2026-09-01), so every created rule gets a mesh_allow_rules row,
 *    and MeshAllowRuleReaper deletes a dated one once its expiry passes. A
 *    rule whose id could not be recovered is surfaced as a fault, never left
 *    quietly unreapable (criterion 8). A permanent rule has a NULL expiry, is
 *    never reaped, and says so in those words on the approval card — a
 *    permanent hole must read as permanent to the human releasing it, not as
 *    a missing date.
 *  - THE PARTNER-WIDE LIST NEVER ESCAPES. Rule identity is recovered inside
 *    MeshWriteClient, which returns only this tenant's rows; nothing here
 *    puts a rule list into a return value, a log line or an error body
 *    (criterion 7).
 */
class StaffMeshAdminToolExecutor
{
    private const DIRECT_DEDUP_HOURS = 24;

    /**
     * What an approval-time dedup refusal does to its card (#5568): it is
     * closed, not returned to the approval queue, so no later click on it
     * can reach createAllowRule once the dedup window has passed. It closes
     * THIS card only: another proposal already staged for the sender is not
     * closed, cancelled or re-checked by it, so the text claims nothing about
     * the sender beyond this card (G-14, b5c-2 contract:2).
     */
    /** Opener for a change the PSA cannot place before or after the staging (second precision). */
    private const SAME_SECOND = 'In the same second this card was staged (the PSA records these times only to the second, so it cannot say which came first), ';

    /** #5746: the close lost its compare-and-set; the card keeps whatever state took it. */
    private const DEDUP_CARD_NOT_CLOSED = 'This card was NOT closed: while this refusal was being written it was released or claimed again elsewhere, so it keeps that state and is not changed by this approval.';

    private const DEDUP_CARD_CLOSED = 'This card is closed, not returned to the approval queue, so approving it again cannot create a rule. Only this card is closed; any other proposal already staged for this sender stays as it is.';

    /**
     * How many proposals for the same write may exist on one ticket. A spent
     * run is never rewritten, so a repeat request takes the next generation of
     * its idempotency key; this is where that stops being served rather than
     * growing without bound.
     */
    private const MAX_RUN_GENERATIONS = 20;

    private const REASON_MAX = 500;

    /** faultedExecution(): a fault row with a run whose PSA record lookup found none (#5923). */
    private const FAULT_RECORD_NOT_FOUND = 'record_not_found';

    /** faultedExecution(): a fault row audited against no run; no record lookup is possible (#5923). */
    private const FAULT_NO_RUN = 'no_run';

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
        'mesh_stage_remove_allow_rule' => 'mesh_remove_allow_rule',
        'mesh_stage_edit_allow_rule' => 'mesh_edit_allow_rule',
    ];

    /** @var array<int, string> */
    private const CLIENT_SCOPED_TOOLS = [
        'mesh_add_allow_rule',
        'mesh_stage_add_allow_rule',
        'mesh_remove_allow_rule',
        'mesh_stage_remove_allow_rule',
        'mesh_edit_allow_rule',
        'mesh_stage_edit_allow_rule',
        self::LIST_TOOL,
    ];

    /**
     * The one READ verb on this surface (card dxWX6IBS). It reads one client's
     * tenant through MeshWriteClient::listCustomerRules() and writes nothing:
     * no upstream write, no PSA row, no audit attempt. It has no entry in
     * STAGED_TO_DIRECT and none in McpToolModes' held-only or
     * explicit-immediate lists, so it is not stageable and a grant of it is a
     * plain name. Like every tool handles() answers for, it is reachable only
     * through an explicit per-token grant (McpStaffController::toolAllowed()).
     */
    public const LIST_TOOL = 'mesh_list_allow_rules';

    /**
     * The complete set of keys a caller may send. Anything else is a refusal.
     *
     * @var array<int, string>
     */
    private const ALLOWED_ARGUMENT_KEYS = [
        'mesh_add_allow_rule' => ['sender', 'confirm_domain', 'reason', 'ticket_id', 'expires_at', 'staged'],
        // #1134: no `sender` and no `expires_at`. The sender is a property of
        // the rule being removed, read from the tenant's own list, not typed by
        // the caller — `confirm_sender` is the typed confirmation and is
        // checked AGAINST that read. And removal is immediate: a scheduled
        // removal is what an allow rule's expiry already is.
        'mesh_remove_allow_rule' => ['rule_id', 'confirm_sender', 'reason', 'ticket_id', 'staged'],
        // #1135: ONE editable field. `expires_at` is the PSA's own lifetime
        // (the reaper enforces it; Mesh's `date_expiry` is display-only), so
        // the edit is authoritative locally and the upstream PATCH only makes
        // the portal agree. The comment is the reaper's fallback identity for
        // the rule and is never edited.
        'mesh_edit_allow_rule' => ['rule_id', 'confirm_sender', 'expires_at', 'reason', 'ticket_id', 'staged'],
        // dxWX6IBS: client_id is lifted out by the controller before dispatch,
        // so the only key this read takes is the optional filter.
        self::LIST_TOOL => ['sender'],
    ];

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
     * Keyed by verb, because the same key is refused for different reasons on
     * either side of the lane and a reason that is true of the create verb is
     * not automatically true of the remove verb. A shared map would put
     * "expiry is set with expires_at" in front of a caller of a verb that has
     * no expiry at all, which is worse than the generic refusal.
     *
     * @var array<string, array<string, string>>
     */
    private const REFUSED_ARGUMENT_KEYS = [
        'mesh_add_allow_rule' => [
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
        ],
        /**
         * #1134. The remove lane's widening moves are different from the
         * create lane's: not "make this rule bigger" but "remove more than the
         * one rule the approver was shown", and "remove something that is not
         * an allow rule at all".
         */
        'mesh_remove_allow_rule' => [
            'ab' => 'this verb only ever removes ALLOW rules; removing a block rule is a different power and is not available from the PSA',
            'sender' => 'the sender is read from the rule itself, never typed; confirm_sender is the typed confirmation and is checked against what Mesh holds',
            'comment' => 'the rule is identified by its Mesh rule id, not by its comment',
            'rule_ids' => 'one rule per proposal; a bulk removal is not something an approver can read',
            'all' => 'this verb never removes more than the single rule named by rule_id',
            'force' => 'there is no force lane; a removal whose absence cannot be proved is reported as a fault, never forced through',
            'edge' => 'connection-level rules are never touched from the PSA',
            'customers' => 'partner-wide rules are never removed from the PSA',
            'customer_id' => 'the Mesh tenant is derived from the PSA client, never supplied',
            'organization_level' => 'scope is fixed to the resolved customer',
            'expires_at' => 'removal is immediate; a scheduled removal is what an allow rule expiry already is',
            'date_expiry' => 'removal is immediate; the vendor expiry field is never written by this verb',
            'active' => 'this verb removes rules, it does not deactivate them',
            'domains' => 'bulk domain removals are not made from the PSA',
            'users' => 'bulk user removals are not made from the PSA',
            'partner_id' => 'partner-scoped writes are never made from the PSA',
            'global' => 'global rules are never removed from the PSA',
        ],
        /**
         * #1135. The edit lane's widening moves are "change something other
         * than the lifetime": the comment (which is how the reaper finds a
         * rule whose id has gone stale — editing it strands the rule outside
         * the only queue that would close it), the sender or the allow/block
         * flag (a different rule, not an edit), and the scope fields.
         */
        self::LIST_TOOL => [
            'customer_id' => 'the Mesh tenant is derived from the PSA client, never supplied',
            'mesh_customer_id' => 'the Mesh tenant is derived from the PSA client, never supplied',
            'tenant_id' => 'the Mesh tenant is derived from the PSA client, never supplied',
        ],
        'mesh_edit_allow_rule' => [
            'comment' => 'the comment is the reaper\'s identity for the rule and is never edited; only expires_at can change',
            'sender' => 'the sender is what the rule IS; changing it is a different rule, not an edit — confirm_sender is the typed confirmation and is checked against what Mesh holds',
            'ab' => 'this verb only ever edits ALLOW rules and never flips one to a block rule',
            'active' => 'this verb does not enable or disable rules; remove the rule instead',
            'date_expiry' => 'expiry is set with expires_at, not the Mesh field name',
            'rule_ids' => 'one rule per proposal; a bulk edit is not something an approver can read',
            'all' => 'this verb never edits more than the single rule named by rule_id',
            'force' => 'there is no force lane; an edit whose upstream display cannot be confirmed is reported as a fault, never forced through',
            'edge' => 'connection-level rules are never touched from the PSA',
            'customers' => 'partner-wide rules are never edited from the PSA',
            'customer_id' => 'the Mesh tenant is derived from the PSA client, never supplied',
            'organization_level' => 'scope is fixed to the resolved customer',
            'domains' => 'bulk domain edits are not made from the PSA',
            'users' => 'bulk user edits are not made from the PSA',
            'partner_id' => 'partner-scoped writes are never made from the PSA',
            'global' => 'global rules are never edited from the PSA',
        ],
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
     * The literal a caller sends as `expires_at` to ask EXPLICITLY for a
     * permanent rule, one the reaper never removes (#1133).
     *
     * It is not the only way to ask: since the owner's 2026-10-05 ruling a
     * temporary rule is "an option, not a default", so an ABSENT key is
     * permanent too, and requestedExpiry() resolves both to the same NULL
     * expiry. From then on the two are NOT told apart (#5411): the proposal
     * hash, the encrypted payload, the redacted card params and the result
     * all carry this word for an omitted key exactly as for an explicit one,
     * because expiryValue() writes it for every NULL expiry. So 'never' in a
     * record means "permanent", never "the caller typed never"
     * (MeshAddAllowRuleTest::test_an_omitted_expiry_and_never_are_the_same_permanent_proposal
     * reads all four for an omitted key: the shared hash by idempotence, then
     * the result, the card params and the decrypted payload, #5494).
     * It is a word, not a magic date, because a sentinel date is one the
     * reaper would one day act on. A value that is SENT empty or null is
     * still refused, never read as either answer.
     */
    public const EXPIRY_NEVER = 'never';

    /**
     * What is true of a permanent rule's end (G-14): nothing removes it
     * automatically, but the PSA's own verbs can. mesh_remove_allow_rule
     * removes it; mesh_edit_allow_rule can give it a date, after which the
     * reaper removes it.
     */
    private const PERMANENT_UNTIL = 'it stays until someone removes it (mesh_remove_allow_rule) or gives it a date (mesh_edit_allow_rule)';

    /**
     * The same fact for a permanent record with NO upstream rule id (#5410).
     * Both verbs reach a PSA record only through its recorded mesh_rule_id
     * (removeAllowRuleTarget(), editAllowRuleTarget()), so for this record
     * the edit verb refuses the rule as FOREIGN and the remove verb, which
     * also removes foreign rules, leaves this record open. The expiry job
     * never removes a rule with no expiry (MeshAllowRule::scopeReapable), so
     * nothing in the PSA closes the record: it keeps answering for its
     * sender until a human clears it (#5497 item 4).
     */
    private const PERMANENT_UNTIL_NO_ID = 'the PSA holds no upstream rule id for it, so mesh_edit_allow_rule cannot give it a date, mesh_remove_allow_rule would not close this record, and the expiry job never removes a rule with no expiry; this PSA record keeps blocking new allow rules for this sender until someone checks the rule in the Mesh portal and clears the record by hand';

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
            self::removeAllowRuleTool(),
            self::stageRemoveAllowRuleTool(),
            self::editAllowRuleTool(),
            self::stageEditAllowRuleTool(),
            self::listAllowRulesTool(),
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

    /**
     * $actorLabel is the prefixed audit label; $tokenLabel is the caller's BARE
     * McpToken.label, recorded on staged runs as proposed_meta.drafted_by_token so
     * the drafting token can withdraw its own proposal (card XUiMXNEH). Null for a
     * caller with no token label: such a run has no drafter to match.
     *
     * @return array<string, mixed>
     */
    public function execute(string $name, array $arguments, ?int $clientId, string $actorLabel, ?string $tokenLabel = null): array
    {
        if (! MeshConfig::isEnabled() || ! MeshConfig::isConfigured()) {
            return ['error' => 'Mesh Email Security is not configured'];
        }

        $result = match ($name) {
            'mesh_stage_add_allow_rule' => $this->stageAllowRule($arguments, (int) $clientId, $actorLabel, $tokenLabel),
            'mesh_add_allow_rule' => $this->immediateRefused('mesh_add_allow_rule', 'mesh_stage_add_allow_rule', $arguments, $clientId, $actorLabel),
            'mesh_stage_remove_allow_rule' => $this->stageRemoveAllowRule($arguments, (int) $clientId, $actorLabel, $tokenLabel),
            'mesh_remove_allow_rule' => $this->immediateRefused('mesh_remove_allow_rule', 'mesh_stage_remove_allow_rule', $arguments, $clientId, $actorLabel),
            'mesh_stage_edit_allow_rule' => $this->stageEditAllowRule($arguments, (int) $clientId, $actorLabel, $tokenLabel),
            'mesh_edit_allow_rule' => $this->immediateRefused('mesh_edit_allow_rule', 'mesh_stage_edit_allow_rule', $arguments, $clientId, $actorLabel),
            self::LIST_TOOL => $this->listAllowRules($arguments, $clientId),
            default => ['error' => "Unknown Mesh admin tool: {$name}"],
        };
        // Same outcome recording as the PSA/Assistant executors: a returned
        // ['error' => ...] here is a failure, not a pending outcome.
        TicketToolActivityContext::current()?->finish($result);

        return $result;
    }

    /**
     * Both canonical verbs are staged-only BY CONSTRUCTION: they are
     * advertised and grantable, and neither has an immediate implementation.
     * The refusal names the staged grant rather than saying "not permitted",
     * because the caller is not doing anything wrong — the grant is the wrong
     * shape, and only the refusal text can say which shape is right.
     *
     * @return array<string, mixed>
     */
    private function immediateRefused(string $directTool, string $stagedTool, array $arguments, ?int $clientId, string $actorLabel): array
    {
        $message = "{$directTool} is staged-only: it always requires cockpit approval. "
            ."Re-grant it as `{$directTool}:staged` (or call `{$stagedTool}`) and pass a ticket_id. No upstream call was made.";
        $this->auditAttempt($directTool, 'rejected', $clientId, null, $this->contentHash($directTool, $clientId, 'immediate-refused', $arguments), $message, $actorLabel);

        return ['error' => $message];
    }

    /**
     * Stage an allow rule for cockpit approval. There is no immediate lane.
     *
     * @return array<string, mixed>
     */
    private function stageAllowRule(array $arguments, int $clientId, string $actorLabel, ?string $tokenLabel = null): array
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
        // The BASE key for this write: tenant + sender, no expiry. It is the
        // key executeAllowRule() re-derives and audits its 'executed' row
        // under, so it is the ONLY key the post-execution dedup below can ever
        // match on. The lifetime-bearing hash built once the expiry is
        // validated identifies the PROPOSAL, not the write.
        $baseHash = $this->contentHash($tool, $clientId, 'allow-rule-'.($target['sender'] ?? 'unresolved'), [
            'mesh_customer_id' => $target['mesh_customer_id'] ?? null,
        ]);
        if (isset($target['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $baseHash, $target['error'], $actorLabel);

            return ['error' => $target['error']];
        }

        // #1133: the caller's expiry is validated HERE, before a proposal
        // exists and before any dedup answer is given. A refusal at approval
        // time would be a refusal in front of a human holding a button,
        // minutes or days after the mistake was made.
        $expiry = $this->requestedExpiry($arguments);
        if (isset($expiry['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $baseHash, $expiry['error'], $actorLabel);

            return ['error' => $expiry['error']];
        }
        $expiresAt = $expiry['expires_at'];

        // The lifetime is part of what the write DOES to a customer's mail
        // filtering, so it is part of the identity of the PROPOSAL: without it,
        // re-staging the same sender with a different expiry (or with 'never')
        // is answered "already staged" against somebody else's lifetime, which
        // is the silent lifetime substitution #1133 exists to delete.
        //
        // It is NOT the identity of the WRITE. executeAllowRule() derives and
        // audits under the expiry-free $baseHash deliberately — every proposal
        // for one write must dedup on one key, or a sibling proposal walks past
        // a brake the first approval already satisfied. So this hash names the
        // proposal (its run slot, its awaiting-approval answer) and $baseHash
        // names the write (the post-execution dedup immediately below).
        //
        // Hashed as the RESOLVED lifetime, so an omitted key and an explicit
        // 'never' are the same proposal: both are permanent, and hashing them
        // apart would refuse the second as "a different lifetime" when it is
        // not one.
        $contentHash = $this->contentHash($tool, $clientId, 'allow-rule-'.($target['sender'] ?? 'unresolved'), [
            'mesh_customer_id' => $target['mesh_customer_id'] ?? null,
            'expires_at' => self::expiryValue($expiresAt),
        ]);

        // Asked with $baseHash, the key the 'executed' audit row was written
        // under. Asking it with the lifetime-bearing hash could never be
        // answered yes for ANY input, which would silently delete the 24-hour
        // post-execution dedup window from the staging path.
        // #5650: ONE read of the executed row, with one cutoff. Asking
        // alreadyExecuted() and then executedRunId() computed the 24-hour
        // cutoff twice, so a row could age out between them and the answer
        // claimed the PSA holds no record while it named none.
        $executed = $this->executedAudit('mesh_add_allow_rule', $clientId, $baseHash);
        if ($executed !== null) {
            // #1133: this answer is given against the lifetime of whichever
            // proposal actually landed, NOT the one being staged now — the key
            // above excludes the expiry deliberately. Saying only "already
            // created recently" is therefore the silent lifetime substitution
            // in its purest form: a technician re-staging this sender as
            // permanent is told the allow exists and nothing tells them it is
            // dated, or that its rule has since been removed or reaped. The
            // record's lifetime and state are named here for the same reason
            // the two branches below name theirs (and recordRefusal() says
            // what each state proves; a removal or reap proves one recorded
            // id absent, never that no allow is in force, #5573).
            //
            // #5566: the record is the one the matched create wrote (its
            // run), not the sender's newest record, which may belong to
            // another create or another tenant mapping.
            $created = $this->executedAllowRecord($clientId, $executed);
            if ($created === null) {
                // Unknown is a refusal. A write was audited as executed but no
                // PSA row carries its lifetime, so nothing here can state what
                // is in force — and an idempotent "already created" would be a
                // pass on exactly that unknown.
                $message = "An allow rule for '{$target['sender']}' was created for this client recently, but the PSA holds no record of it, so its lifetime cannot be stated and no PSA expiry applies to it. Resolve it in the Mesh portal by hand; no proposal was staged.";
                $this->auditAttempt($tool, 'blocked', $clientId, $ticket, $baseHash, $message, $actorLabel);

                return ['error' => $message];
            }

            // b5c (#5487, #5497 items 1-3): the dedup key says only that a
            // create was audited 'executed' inside the window. Whether an
            // allow is in force NOW depends on the record, so only an ACTIVE
            // record with its upstream id (measured live, and reachable by
            // the verbs) is answered as an idempotent success. Every other
            // state is a refusal, with an audited blocked row: proved absent
            // (removed, reaped) is not a success (b5b ruling), and an
            // unmeasured one (unresolved, reap_failed, or active with its id
            // cleared by display_id_lost) is not one either. The certain case
            // cannot be greener than the unknown one above.
            $refusal = self::recordRefusal($created, "An allow rule for '{$target['sender']}' was created for this client recently as PSA record #{$created->id}", false);
            if ($refusal !== null) {
                $this->auditAttempt($tool, 'blocked', $clientId, $ticket, $baseHash, $refusal, $actorLabel);

                return ['error' => $refusal];
            }

            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $executed->run_id,
                'sender' => $target['sender'],
                'expires_at' => self::expiryValue($created->expires_at),
                'message' => 'This allow rule was already created recently: PSA record #'.$created->id.' is '
                    .match (true) {
                        $created->isPermanent() => 'PERMANENT (it has no automatic expiry; '.self::PERMANENT_UNTIL.')',
                        // #5497 item 3: an ACTIVE row past its date has not
                        // been reaped yet; "set to expire" a past date reads
                        // as a rule that is already gone.
                        ! $created->expires_at->isFuture() => 'past its expiry ('.$created->expires_at->toDayDateTimeString().' UTC) but not yet removed by the expiry job, so it stays in force until that job removes it',
                        default => 'set to expire '.$created->expires_at->toDayDateTimeString().' UTC',
                    }
                    .", state '{$created->state}'"
                    .'. No new proposal was staged and the lifetime asked for here was NOT applied.',
            ];
        }

        // A live PSA row is stronger evidence than the audit log's dedup
        // window: it says the rule is believed live upstream RIGHT NOW,
        // whatever its age. Creating a second identical rule would leave a
        // duplicate the reaper cannot distinguish by sender alone.
        $live = $this->liveAllowRule($clientId, $target['sender']);
        // #5490: an ACTIVE record whose id display_id_lost cleared is not a
        // measured allow; it is refused, not answered "already allowed".
        $liveRefusal = $live === null ? null : self::recordRefusal($live, "An allow rule for '{$target['sender']}' is recorded for this client as PSA record #{$live->id}", false);
        if ($liveRefusal !== null) {
            $this->auditAttempt($tool, 'blocked', $clientId, $ticket, $baseHash, $liveRefusal, $actorLabel);

            return ['error' => $liveRefusal];
        }
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
                    ? "'{$target['sender']}' is already allowed for this client PERMANENTLY (PSA record #{$live->id}; it has no automatic expiry; ".self::PERMANENT_UNTIL.'); no proposal was staged.'
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

        // The hash above carries the caller's lifetime, so an awaiting proposal
        // for this SENDER asking a DIFFERENT lifetime is invisible to it. Both
        // cards would then be approvable, and whichever is released second
        // creates nothing — executeAllowRule's duplicate brakes stop it — while
        // its approver read a lifetime that was never applied. That is the
        // silent lifetime substitution #1133 exists to delete, moved from
        // staging to approval. One awaiting lifetime per sender per ticket: the
        // second staging is refused here, and it names the proposal to settle.
        $awaitingForSender = $this->awaitingRunForSender($ticket->id, $tool, $target['sender']);
        if ($awaitingForSender !== null) {
            $awaitingNote = data_get($awaitingForSender->proposed_meta, 'redacted_params.expiry_note');
            $message = "A proposal to allow '{$target['sender']}' for this client is already awaiting approval on this ticket with a different lifetime"
                .(is_string($awaitingNote) ? " ({$awaitingNote})" : '')
                .'; approve or deny run #'.$awaitingForSender->id.' before staging another for this sender. No proposal was staged.';
            $this->auditAttempt($tool, 'blocked', $clientId, $ticket, $contentHash, $message, $actorLabel, $awaitingForSender->id);

            return ['error' => $message];
        }

        // No per-client staging cooldown: a proposal is a held card a human
        // still has to approve, so a damper here only delayed that decision
        // (Charlie, 2026-09-08; same ruling as tactical_run_command, T-22782).
        // Distinct senders may be staged back-to-back; identical content is
        // still answered by the dedup brakes above.

        // Generated HERE, not at execution, and carried in the encrypted
        // payload: the approver is shown the exact comment the rule will
        // carry, and a retried execution re-uses the same match key instead of
        // creating a second rule the first one's re-read would then find.
        $comment = $this->generateComment();

        $proposedContent = "Allow mail from '{$target['sender']}' through Mesh Email Security for {$target['client_name']}.\n"
            .$target['scope_note']."\n"
            .($expiresAt === null
                // Criterion 5: the permanent case must read as permanent. It is
                // the one lifetime nothing ends automatically, so it is stated
                // in words and in capitals rather than left to an absent date
                // the approver has to notice. What DOES end it is named too
                // (G-14, #5156): the PSA's own removal and edit verbs.
                ? 'This weakens filtering for that sender PERMANENTLY: the rule has NO EXPIRY, so nothing removes it automatically; '.self::PERMANENT_UNTIL.".\n"
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
            // Card XUiMXNEH: the BARE token label; withdraw_staged_action matches this
            // field only, never the prefixed drafted_by above.
            'drafted_by_token' => $tokenLabel,
            'reasons' => [$guard['reason']],
            'direct_tool' => self::STAGED_TO_DIRECT[$tool],
            'redacted_params' => [
                'sender' => $target['sender'],
                'client' => $target['client_name'],
                // 'never' rather than a null: the card renders these values,
                // and an absent field reads as "not recorded" where the point
                // is that the rule is permanent (#1133). Written for an
                // omitted key too, so it does not say the caller typed it (#5411).
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
                    // Carried as expiryValue() writes it — an ISO string, or
                    // 'never' for every permanent proposal, an omitted key
                    // included (#5493), so it does not record what the caller
                    // typed — and approval re-resolves it through exactly the
                    // same validator the proposal passed. Always
                    // written, never omitted: the payload states the lifetime
                    // the approver was shown rather than leaving approval to
                    // re-derive it from a default.
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

        // The lifetime in words, as the edit verb's staging row states it
        // (#5155): this durable line is the only record of a proposal that is
        // later denied, and a permanent request must not read like a dated one.
        $this->auditAttempt($tool, 'awaiting_approval', $clientId, $ticket, $contentHash, "MCP staged Mesh allow rule for '{$target['sender']}': ".self::expiryPhrase($expiresAt).'.', $actorLabel, $run->id);

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

        $executed = $this->executedAudit($tool, $clientId, $contentHash);
        if ($executed !== null) {
            // #5497 item 1: the audit key says a create ran inside the window,
            // not that its rule is in force now. A record in any state but
            // ACTIVE-with-its-id is refused with what that state proves, as at
            // staging (recordRefusal()).
            //
            // #5568: the refusal CLOSES the card (close_card), it does not
            // release it. This arm fires only because this write was already
            // executed for this sender inside the window, so the card is spent
            // exactly as the idempotent answer below spends it. Released, it
            // went back to AwaitingApproval, and once the window passed no
            // brake sees a missing, removed or reaped record, so the next
            // click created a second rule (or re-created one a named approver
            // had removed) with nobody told it would.
            $created = $this->executedAllowRecord($clientId, $executed);
            $refusal = $created === null
                // Unknown is a refusal here too, as at staging.
                ? "An allow rule for '{$target['sender']}' was created for this client recently, but the PSA holds no record of it, so whether it is in force cannot be stated. Resolve it in the Mesh portal by hand. No upstream call was made and the lifetime on this proposal was NOT applied."
                : self::recordRefusal($created, "An allow rule for '{$target['sender']}' was created for this client recently as PSA record #{$created->id}", true);
            if ($refusal !== null) {
                // #5746: the card is closed BEFORE the text is written, so
                // the sentence about the card says what the close did.
                $refusal .= ' '.$this->closeSpentCard($run);
                $this->auditAttempt($tool, 'blocked', $clientId, null, $contentHash, $refusal, $actorLabel, $run?->id, $approverId);

                return ['error' => $refusal, 'close_card' => true];
            }

            $this->auditAttempt($tool, 'blocked', $clientId, null, $contentHash, 'Duplicate Mesh allow rule suppressed before upstream call.', $actorLabel, $run?->id, $approverId);

            return ['success' => true, 'idempotent' => true, 'message' => 'This allow rule was already created recently; no upstream call was made.'];
        }

        // A live PSA record is the MEASURED statement that this write already
        // landed upstream, and unlike the audit dedup window it does not age
        // out. It is the second brake on two approvable proposals for one
        // write: whichever is approved first, the other must not create a
        // duplicate rule the reaper cannot tell apart by sender alone.
        $live = $this->liveAllowRule($clientId, $target['sender']);
        // #5490: as at staging, an id-less ACTIVE record is not a measured allow.
        $liveRefusal = $live === null ? null : self::recordRefusal($live, "An allow rule for '{$target['sender']}' is recorded for this client as PSA record #{$live->id}", true);
        if ($liveRefusal !== null) {
            $this->auditAttempt($tool, 'blocked', $clientId, null, $contentHash, $liveRefusal, $actorLabel, $run?->id, $approverId);

            return ['error' => $liveRefusal];
        }
        if ($live !== null) {
            // #1133: the rule that exists carries the lifetime of whichever
            // proposal created it, NOT the one on the card being approved. This
            // text is what that approver reads, so it names the lifetime
            // actually in force and says plainly that theirs was not applied —
            // 'already allowed' alone leaves them believing their date holds.
            $message = "'{$target['sender']}' is already allowed for this client by PSA record #".$live->id
                .($live->isPermanent()
                    ? ' PERMANENTLY (that record has no automatic expiry; '.self::PERMANENT_UNTIL.')'
                    : ' until '.$live->expires_at->toDayDateTimeString().' UTC')
                .'; no upstream call was made and the lifetime on this proposal was NOT applied.';
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
                // never coming. What the expiry job can do for such a record
                // depends on what its create actually proved
                // (MeshAllowRuleReaper::settleUnexpired): a scope-proved row
                // was only ever missing its id, so identifying it settles the
                // row and lifts this block. A row whose scope was never proved
                // is NOT settled by an id, so identifying it never clears it;
                // only a proved removal against the record does (an approved
                // mesh_remove_allow_rule, or the expiry job once the row has a
                // date, e.g. from mesh_edit_allow_rule; both need its upstream
                // id recorded), or a human — and this
                // text must not send the approver away to wait for a change
                // that is never coming.
                //
                // Nh0dzF2T: the identify pass settles only an UNRESOLVED row
                // that is permanent or not yet expired. A reap_failed row
                // records a removal that did not prove absence and is never
                // settled by identifying it, and a dated row past its expiry
                // is reapOne()'s to remove, so neither is told that
                // identification lifts this block. A PERMANENT reap_failed row
                // is not told to wait for a removal either: reapOne() never
                // selects it, and once its rule is gone upstream a re-staged
                // removal is refused at findRuleById, so only a human can
                // clear that record.
                .($unsettled->state === MeshAllowRule::STATE_REAP_FAILED
                    ? ($unsettled->isPermanent()
                        ? 'An earlier attempt to remove that rule did not prove it absent, and that record is PERMANENT (no expiry), so the expiry job never retries the removal and nothing in the PSA will clear this block on its own. '
                            .'Someone has to check that rule in the Mesh portal and, if it is gone, clear the PSA record by hand; checking the portal alone changes nothing here. In the meantime, '
                        : 'An earlier attempt to remove that rule (by the expiry job or by an approved removal) did not prove it absent, so identifying it does not settle the record, '
                            .'and this block stays until the PSA proves a removal against this record. Until then, ')
                    : ($unsettled->isPermanent()
                        ? ($unsettled->scope_proved
                            ? 'That record is PERMANENT (no expiry), so the expiry job never removes it; its scope WAS confirmed when it was created, so the expiry job only has to IDENTIFY it, '
                                .'and this block clears when it does. Until then, '
                            : 'That record is PERMANENT (no expiry) and Mesh never confirmed its scope, so the expiry job never removes it while it has no expiry, and identifying it does not clear this block — recovering its id is not scope evidence. '
                                .'This block clears when the PSA proves that rule removed: an approved mesh_remove_allow_rule does that directly, and an approved mesh_edit_allow_rule that gives the rule a date leaves it to the hourly expiry job once that date passes. Both need the PSA to have recorded the rule\'s upstream id first; otherwise someone has to check that rule in the Mesh portal AND clear the PSA record by hand. Checking the portal alone changes nothing here. In the meantime, ')
                        : (! $unsettled->expires_at->isFuture()
                            // #5748: an ACTIVE record past its expiry, not
                            // reaped yet; nothing about it needs settling.
                            ? ($unsettled->state === MeshAllowRule::STATE_ACTIVE
                                ? 'That record is active and its expiry ('.$unsettled->expires_at->toIso8601String().') has passed, but Mesh does not expire rules on its own and the hourly expiry job has not removed it yet, '
                                    .'so the rule is treated as live: this block stays until the PSA proves it removed (the expiry job tries that on its next run). Until then, '
                                : 'Its expiry ('.$unsettled->expires_at->toIso8601String().') has passed, so the hourly expiry job does not settle it: it tries to identify and remove it, '
                                    .'and this block clears once that removal is proved. Until then, ')
                            : ($unsettled->scope_proved
                                ? 'Its scope WAS confirmed when it was created, so the hourly expiry job only has to IDENTIFY it, and this block clears on the first run before its expiry ('
                                    .$unsettled->expires_at->toIso8601String().') that finds exactly one rule in Mesh carrying its sender and comment. Until then, '
                                : 'The PSA cannot settle that record until its expiry ('.$unsettled->expires_at->toIso8601String()
                                    .') passes and the expiry job examines it; until then, '))))
                .'allow this sender directly in the Mesh portal '
                .'if it is needed now. No upstream call was made.';
            $this->auditAttempt($tool, 'blocked', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['error' => $message];
        }

        // ...and one fault path leaves NO row for either of those two brakes to
        // find: record_unwritable, where the rule may be live upstream and the
        // row that would reap it is precisely what could not be written. Its
        // 'executed_with_fault' audit line is then the only trace the write
        // left, and alreadyExecuted() matches 'executed' alone, so without this
        // check a second card for the same sender walks past every brake and
        // opens a SECOND hole — one of them untracked, and therefore never
        // reaped. Unknown is a refusal, never a pass.
        $fault = $this->faultedExecution($tool, $clientId, $contentHash);
        if ($fault !== null) {
            // #5923: say only what was measured, as changedSinceStaging()
            // does (#5747, contract-s2:3). A record_unwritable row is also
            // written when Mesh never acknowledged the create, so the text
            // does not say the write reached Mesh; and only a fault row WITH
            // a run had its record looked up, so only that arm says none
            // exists.
            $message = "An earlier create for '{$target['sender']}' on this client "
                .($fault === self::FAULT_RECORD_NOT_FOUND
                    ? 'FAULTED (this refusal does not show whether a rule reached Mesh), and no PSA record of it exists, '
                    : 'was audited against no proposal and FAULTED (this refusal does not show whether a rule reached Mesh), and no PSA record can be tied to it, ')
                .'so a rule may be live upstream with nothing tracking it and a second rule was not created. '
                .'Check for that rule in the Mesh portal and remove it by hand if it is there — the earlier audit row for this write says what happened — '
                .'and note that the lifetime on this proposal is NOT in force. No upstream call was made.';
            $this->auditAttempt($tool, 'blocked', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['error' => $message];
        }

        // b5c3 (#5639, #5654): every brake above is a question about the
        // state NOW, and the dedup one only looks back 24 hours. None of them
        // refuses a record that is gone (removed, reaped, never written), so a
        // card staged BEFORE a create, a removal or a reap of this sender's
        // rule reached createAllowRule once the window had passed: a second
        // rule, or the re-creation of one a named approver removed, from a
        // card drafted against a state that no longer holds. Whatever the PSA
        // wrote about this sender after the card was staged is a fact the
        // approver was never shown, so the card is refused and CLOSED (the
        // fact is permanent, so every later click would be refused too). A
        // card staged in a later second than the change was drafted against
        // it and passes; a change in the staging second itself is refused.
        $changed = $run === null ? null : $this->changedSinceStaging($run, $clientId, $target['mesh_customer_id'], $target['sender'], $contentHash);
        if ($changed !== null) {
            $message = "{$changed}, so the card was not approved against what the PSA now records for this sender and no rule was created."
                .' No upstream call was made and the lifetime on this proposal was NOT applied. '
                .$this->closeSpentCard($run)
                .' If the sender still needs allowing, check its rules in the Mesh portal and stage a new proposal; it is checked against what the PSA records when it is staged and again when it is approved.';
            $this->auditAttempt($tool, 'blocked', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['error' => $message, 'close_card' => true];
        }

        $comment = $this->requiredString($arguments, 'comment') ?? $this->generateComment();

        // Re-validated at approval, not carried as a parsed value: the same
        // rule as the tenant and the domain confirmation above. A proposal
        // whose date has passed while it sat awaiting approval is refused
        // HERE, before any upstream call — creating a rule that is already
        // expired would open a hole and leave it open until the hourly reaper
        // (routes/console.php: mesh:reap-allow-rules ->hourly()) next ran.
        // Nothing is created; the technician re-stages with a date that means
        // something.
        //
        // A payload with NO expires_at key is refused before that (#5159),
        // exactly as executeEditAllowRule() refuses it. requestedExpiry()
        // reads an absent key as PERMANENT, which is right for a caller who
        // omitted it at staging but wrong here: stageAllowRule() always writes
        // the key, so a payload without it was not written by this code, and
        // its approver was never shown PERMANENT. It must not silently become
        // a permanent hole. array_key_exists, not isset: a key that is present
        // but null is not "no expires_at", and requestedExpiry() refuses it
        // as empty with its own words.
        if (! array_key_exists('expires_at', $arguments)) {
            $message = 'The approved proposal carries no expires_at; nothing was changed. Stage a new proposal.';
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['error' => $message];
        }
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
            // Status only (C-56, card FLzMLDxF): the refusal text is the
            // vendor's own response body (MeshWriteClient::refusalText()), so
            // it is not quoted here; the status says Mesh validated the
            // request and declined it.
            $message = 'Mesh refused the allow rule: '.$e->statusPhrase('the create');
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
            // Status only (C-56): the exception message can quote the vendor's
            // response body and the request URI.
            // Not "Mesh refused": on the never-sent connect arm and the
            // pre-flight guards Mesh received nothing (G-14).
            $message = 'The allow rule was not created: '.$e->statusPhrase('the create');
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
            // The 201's `added_for` verdict, recorded at the only moment it can
            // be measured — no later read-back can prove scope. It is what lets
            // the expiry job tell a row that is missing only its id from one
            // that is missing the proof itself
            // (MeshAllowRuleReaper::settleUnexpired).
            'scope_proved' => $scopeProved,
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
            // #1133: "recorded for removal" is only true of a rule with an
            // expiry. A permanent rule is recorded and never reaped — the
            // reaper excludes it by design — so promising removal here would
            // misstate what is coming for a hole in a customer's mail
            // filtering whose scope we could not confirm.
            $message = 'Mesh did not confirm the rule was scoped to this client only. The rule exists upstream and has been recorded (PSA record #'
                .$record->id.'); '
                .($expiresAt === null
                    ? 'it is PERMANENT, so nothing removes it automatically — check it in the Mesh portal and remove it by hand if the scope is wrong.'
                    : 'the expiry job will remove it at expiry. Check it in the Mesh portal before relying on it.');
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
            $record->forceFill(['last_error' => 'Rule id re-read failed: '.$e->statusPhrase('the rule list read')])->save();
        }

        $ruleId = is_scalar($match['id'] ?? null) && (string) $match['id'] !== '' ? (string) $match['id'] : null;
        $upstreamCreatedBy = is_scalar($match['created_by'] ?? null) ? (string) $match['created_by'] : null;

        if ($ruleId === null) {
            // Criterion 8: an unresolvable id is a FAULT. The rule is live and
            // the reaper cannot delete what it cannot name; the row stays
            // unresolved so the expiry job retries the match and keeps saying
            // so. #1133: for a PERMANENT row that retry is an IDENTIFY pass
            // only (MeshAllowRuleReaper::settleUnexpired) — resolving the id
            // never leads to a removal, so this must not imply that it does.
            $message = "Allow rule created for '{$target['sender']}', but its Mesh rule id could not be recovered by re-read. "
                .'It is recorded (PSA record #'.$record->id.') and the expiry job will retry resolving it; '
                .($expiresAt === null
                    ? 'it is PERMANENT, so even once resolved nothing removes it automatically — remove it when it is no longer needed.'
                    : 'it cannot be removed automatically until it resolves.');
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
        // lifetime goes in it in words: a permanent rule and a dated one must
        // not read the same six months later (#1133).
        $summary = "Created Mesh allow rule for '{$target['sender']}' scoped to this client; "
            .($expiresAt === null ? 'PERMANENT — no automatic expiry; '.self::PERMANENT_UNTIL.'.' : 'expires '.$expiresAt->toDateString().'.');
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
                ? ' Mesh does not expire rules on its own and the PSA expiry job skips a rule with no date.'
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
            // Status only (C-56): this reaches the approver, last_error and the audit row.
            $rereadError = $e->statusPhrase('the rule list read');
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
            // There was no create response at all, so `added_for` never spoke:
            // scope is UNPROVED here by definition, and the expiry job must
            // never settle this row on a recovered id.
            'scope_proved' => false,
            'created_by_actor' => $actorLabel,
            'approver_user_id' => $approverId,
            'upstream_created_by' => is_scalar($match['created_by'] ?? null) ? (string) $match['created_by'] : null,
        ]);

        if ($record === null) {
            // Same rule as the create path: a row we could not write must not
            // be reported as an error, because an error hands the approver a
            // button that writes a second rule for the same sender. Spent,
            // loud, and handed to a human with the match key.
            $message = 'Mesh did not acknowledge the create ('.$error->statusPhrase('the create')."), and the PSA record for '{$target['sender']}' could not be written"
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
            ? 'Mesh did not acknowledge the create ('.$error->statusPhrase('the create')."), but a re-read of this client's tenant found the rule live for '{$target['sender']}'. "
                .'It is recorded (PSA record #'.$record->id.') and '
                .($expiresAt === null
                    ? 'it is PERMANENT — nothing removes it automatically. '
                    : 'the PSA will remove it at expiry. ')
                .'There was no successful create response, so its scope was never confirmed — check it in the Mesh portal.'
            : 'Mesh did not acknowledge the create ('.$error->statusPhrase('the create')."), and a re-read of this client's tenant did not find the rule"
                .($rereadError !== null ? ' (that read failed too: '.$rereadError.')' : '')
                .'. Whether the rule was created is UNMEASURED, so it is recorded unresolved (PSA record #'.$record->id.') and '
                .($expiresAt === null
                    // #1133: this row is PERMANENT (NULL expires_at), so
                    // scopeReapable()'s whereNotNull excludes it in EVERY
                    // state and nothing here will ever DELETE it. The expiry
                    // job does keep trying to IDENTIFY it
                    // (MeshAllowRuleReaper::settleUnexpired), so say that much
                    // and no more: on the worst path this method has, removal
                    // is a human's job and claiming otherwise is a false
                    // statement of system behaviour.
                    ? 'it is PERMANENT — the expiry job keeps trying to identify it, but it never removes it. Look for a rule with the comment '.$comment." on this client's Mesh tenant and remove it by hand."
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
     * pre-flight guards and its connect-phase failures never put a request
     * on the wire at all; the exception says so structurally
     * (MeshClientException::nothingWasSent()), not in its message. Reconciling those would create a mesh_allow_rules row for a
     * rule that does not exist: reapOne() has no terminal state for it, so it
     * would burn a BATCH_LIMIT slot and force mesh:reap-allow-rules to exit
     * FAILURE on every run, forever.
     */
    private function createMayHaveCommitted(MeshClientException $error): bool
    {
        if ($error->nothingWasSent()) {
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
            $ticket = Ticket::automationVisible()->find((int) ($payload['ticket_id'] ?? 0));
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

            $arguments = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];
            $result = match ($directTool) {
                'mesh_add_allow_rule' => $this->executeAllowRule($arguments, (int) $run->client_id, $this->approverLabel($approverId), $run, $approverId),
                'mesh_remove_allow_rule' => $this->executeRemoveAllowRule($arguments, (int) $run->client_id, $this->approverLabel($approverId), $run, $approverId),
                'mesh_edit_allow_rule' => $this->executeEditAllowRule($arguments, (int) $run->client_id, $this->approverLabel($approverId), $run, $approverId),
                default => ['error' => 'Unsupported Mesh staged admin action.'],
            };

            // The refusal text IS the outcome here — an expiry that passed
            // while the card waited, a duplicate brake, a tenant that no longer
            // matches. A message-less gate_declined renders the generic
            // gate_declined fallback in TechnicianCockpitController::approve(),
            // which names no reason; the specific reason names the re-stage
            // they actually need.
            if (isset($result['error'])) {
                // #5568: an approval-time dedup refusal spends the card
                // (executeAllowRule()), so it is closed, never released: a
                // released card was approvable again after the window, and
                // then nothing stopped a second create. Same terminal state
                // as the idempotent dedup answer, still on the error channel.
                // executeAllowRule() has already tried the close
                // (closeSpentCard()) and said in the text what it did. A
                // close that lost is NOT released either: the run is no
                // longer this request's to move.
                if (($result['close_card'] ?? false) !== true) {
                    $run->releaseClaim();
                }

                return new TechnicianApprovalResult(
                    'gate_declined',
                    message: is_string($result['error']) ? $result['error'] : null,
                );
            }

            $run->advanceTo(TechnicianRunState::Done);

            // Two outcomes that are terminal but are NOT the write this
            // approver released:
            //   fault     — the rule landed upstream and a post-condition did
            //               not hold (wrong scope, or an id we cannot name);
            //               the write is not undoable from here.
            //   idempotent — a duplicate brake fired and NOTHING was written by
            //               this run. The rule that exists belongs to another
            //               proposal and carries ITS lifetime, not this card's
            //               (#1133).
            // Both go out on the fault channel with the specific text, never as
            // a clean execution the cockpit renders green: a green 'executed'
            // over an idempotent no-op is exactly how an approver comes to
            // believe a lifetime was applied that nothing will ever enforce.
            if (isset($result['fault']) || ($result['idempotent'] ?? false)) {
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
     * Close a card an approval-time refusal spent (#5568), and return the
     * sentence that says what happened to it (#5746).
     *
     * #5652: compare-and-set, like the claim and the release. Only a run this
     * request still holds (Executing, under this claim) is closed; a run
     * something else released or re-claimed meanwhile keeps that state rather
     * than being overwritten with Done. The claim's own claimed_at is part of
     * the compare, so a later claim of the same run (Executing again) is not
     * taken for this one, and a release (which keeps claimed_at) fails on the
     * state.
     *
     * Run BEFORE the refusal is audited, so neither the approver nor the
     * blocked row is told the card is closed when the compare lost.
     */
    private function closeSpentCard(?TechnicianRun $run): string
    {
        if ($run === null) {
            return 'No card is involved, so none was closed.';
        }

        $closed = TechnicianRun::query()->whereKey($run->getKey())
            ->where('state', TechnicianRunState::Executing->value)
            ->when(
                $run->claimed_at !== null,
                fn ($q) => $q->where('claimed_at', $run->claimed_at),
                fn ($q) => $q->whereNull('claimed_at'),
            )
            ->update(['state' => TechnicianRunState::Done->value]) === 1;

        if ($closed) {
            $run->state = TechnicianRunState::Done;

            return self::DEDUP_CARD_CLOSED;
        }

        \Illuminate\Support\Facades\Log::warning("[Mesh] Allow-rule card #{$run->id} was refused at approval but not closed: it was released or claimed again while the refusal was being written.");

        return self::DEDUP_CARD_NOT_CLOSED;
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
        $directTool = self::STAGED_TO_DIRECT[$tool] ?? $tool;

        if ($refused = $this->refusedArgumentKeys($directTool, $arguments)) {
            $message = "These parameters are not accepted by {$directTool}: ".implode('; ', $refused).'.';
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
    private function refusedArgumentKeys(string $directTool, array $arguments): array
    {
        $allowed = self::ALLOWED_ARGUMENT_KEYS[$directTool] ?? [];
        $reasons = self::REFUSED_ARGUMENT_KEYS[$directTool] ?? [];

        $refused = [];

        foreach (array_keys($arguments) as $key) {
            $key = (string) $key;
            if (in_array($key, $allowed, true)) {
                continue;
            }

            $refused[] = isset($reasons[$key])
                ? "{$key} (".$reasons[$key].')'
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

        $ticket = Ticket::automationVisible()->find($ticketId);
        if (! $ticket || (int) $ticket->client_id !== $clientId) {
            // G-14: a held intake ticket in THIS client exists; say so rather than "not found".
            $held = Ticket::find($ticketId);
            if ($held?->isUnverifiedContactIntake() && (int) $held->client_id === $clientId) {
                return ['error' => 'This ticket is an unverified web-form intake held for staff verification; staff must verify it before any action on it.'];
            }

            return ['error' => 'Ticket not found or belongs to a different client'];
        }

        TicketToolActivityContext::current()?->validated($ticket);

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
        return $this->openAllowRecords($clientId, $sender)
            ->where(fn ($q) => self::measuredLive($q))
            ->latest('id')
            ->first();
    }

    /**
     * The states in which a record still answers for a rule that may be live
     * upstream: every state but the two that PROVED its rule absent (#5748).
     *
     * ONE rule for every approval-time question "is there a live record for
     * this sender": a record is live until a removal or a reap proves its
     * rule gone, whatever its expiry says. Mesh expires nothing (measured
     * 2026-09-01), so an ACTIVE record past its expiry and not yet reaped is
     * exactly as live as an unexpired one. The two brakes split these
     * states between them (liveAllowRule() takes the measured-live part,
     * unsettledAllowRule() the rest), and changedSinceStaging()'s ended fact
     * reads the complement, so every state a record can be in is answered by
     * one of them, including the record of a fault (#5751).
     */
    private const OPEN_STATES = [MeshAllowRule::STATE_ACTIVE, MeshAllowRule::STATE_UNRESOLVED, MeshAllowRule::STATE_REAP_FAILED];

    /** The complement of OPEN_STATES: a removal or a reap proved the record's rule absent. */
    private const ABSENT_STATES = [MeshAllowRule::STATE_REMOVED, MeshAllowRule::STATE_REAPED];

    /** This client's records for $sender in an OPEN_STATES state (#5748). */
    private function openAllowRecords(int $clientId, string $sender): \Illuminate\Database\Eloquent\Builder
    {
        return MeshAllowRule::query()
            ->where('client_id', $clientId)
            ->where('sender', $sender)
            ->whereIn('state', self::OPEN_STATES);
    }

    /**
     * ACTIVE and unexpired: the only open record answered from as an allow
     * MEASURED in force (subject to recordRefusal()).
     *
     * #1133: a permanent rule (NULL expiry) is the MOST live row this can
     * find, and `expires_at > now()` evaluates to NULL for it — not true.
     * Without the null arm the strongest duplicate brake in the verb would
     * silently stop seeing exactly the rules that never go away, and a
     * second permanent hole could be opened for the same sender.
     */
    private static function measuredLive($query): void
    {
        $query->where('state', MeshAllowRule::STATE_ACTIVE)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * The PSA record written by the create that the post-execution dedup
     * matched, in ANY state, or null when no record ties back to it (#5566).
     *
     * The dedup answer is given against whatever lifetime the proposal that
     * actually landed carried (the key excludes the expiry deliberately), so
     * naming that lifetime needs the row itself, a `reaped` one included
     * (#1133).
     *
     * The dedup answers on the content hash, so the record named in its
     * answer must be the one that executed create wrote: $executed, the
     * newest 'executed' row on that hash inside the window, read ONCE by the
     * caller (#5650), gives the run, and the
     * record carries that run in technician_run_id. latestAllowRule() by
     * sender could name a record from another create (a sibling proposal, an
     * older tenant mapping) whose state and lifetime say nothing about this
     * one. A row audited against no run cannot be tied to a record and is
     * answered as no record, which is a refusal.
     */
    private function executedAllowRecord(int $clientId, TechnicianActionLog $executed): ?MeshAllowRule
    {
        $runId = $executed->run_id;
        if ($runId === null) {
            return null;
        }

        return MeshAllowRule::query()
            ->where('client_id', $clientId)
            ->where('technician_run_id', $runId)
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
     *
     * #5748: the same rule covers an ACTIVE record past its expiry that the
     * hourly expiry job has not reaped yet. liveAllowRule() does not answer
     * from it (it is not measured in force now), and before this it fell
     * through every brake, so a card approved in that window created a
     * second rule beside one that is still live upstream. It is every open
     * record the live brake does not take (OPEN_STATES).
     */
    private function unsettledAllowRule(int $clientId, string $sender): ?MeshAllowRule
    {
        return $this->openAllowRecords($clientId, $sender)
            ->whereNot(fn ($q) => self::measuredLive($q))
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
            // The class, never the message: a QueryException's message is the
            // SQL with its bindings, i.e. the row's client data. The context
            // keeps the ids and the comment (the PSA's own match key), which
            // is what a human needs to find the rule in the portal.
            \Illuminate\Support\Facades\Log::error('[Mesh] Allow rule is live upstream but its PSA record could not be written ('.$e::class.')', [
                'client_id' => $attributes['client_id'] ?? null,
                'mesh_customer_id' => $attributes['mesh_customer_id'] ?? null,
                'comment' => $attributes['comment'] ?? null,
            ]);

            return null;
        }
    }

    /**
     * The lifetime the caller asked for (#1133), or a refusal.
     *
     * Three accepted answers:
     *   key absent            -> null, i.e. permanent. The owner's ruling
     *                            (2026-10-05): a temporary rule is "an option,
     *                            not a default". The approval card still says
     *                            PERMANENT in words, so an omitted key is never
     *                            released as a blank.
     *   the literal `never`   -> null, the same permanent rule asked for
     *                            explicitly.
     *   an ISO-8601 date/time -> that instant; the rule is reaped after it.
     *
     * Anything else is REFUSED — including a key that was SENT empty, which is
     * not the absent case. A typo must not become a lifetime nobody chose,
     * permanent or otherwise; the caller is told instead. A past date is
     * refused for the same reason and one more: the rule would be born
     * reapable, so it would open a hole and hold it until the hourly reaper
     * (routes/console.php: mesh:reap-allow-rules ->hourly()) next ran.
     *
     * `$absentIsPermanent` is false for the edit verb, which refuses an absent
     * key before calling this. The empty-value refusal then leaves out
     * "omit the parameter for a permanent rule", because on that verb the
     * caller would be refused for following it (G-14).
     *
     * @param  array<string, mixed>  $arguments
     * @return array{expires_at: \Illuminate\Support\Carbon|null}|array{error: string}
     */
    private function requestedExpiry(array $arguments, bool $absentIsPermanent = true): array
    {
        if (! array_key_exists('expires_at', $arguments)) {
            return ['expires_at' => null];
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
            return ['error' => 'expires_at was empty; give an ISO-8601 date or datetime for a rule that expires, or the word "never" for a rule that never expires.'
                .($absentIsPermanent ? ' Omit the parameter entirely for a permanent rule' : '')];
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

    /**
     * The refusal for a PSA record that does NOT show an allow in force for
     * its sender, or null for the one record that does: ACTIVE, with its
     * upstream rule id (b5c: #5486, #5487, #5490, #5495, #5497 items 1-2).
     *
     * Every answer that says "already created" or "already allowed" is
     * success:true/idempotent:true on the machine-readable channel, and a
     * caller that branches on it records the sender as handled. That is true
     * only of a record whose rule was MEASURED live and is still reachable by
     * the verbs PERMANENT_UNTIL names, so every other state is refused here,
     * each saying exactly what its state proves:
     *
     *  - removed / reaped: ONE recorded rule id was proved absent (ruleAbsent()
     *    after the DELETE). Nothing checked the tenant for any other rule
     *    for the sender, so the text never says no allow is in force.
     *  - unresolved: whether the rule exists is not proved now. It may never
     *    have been (a create fault), or the record was measured live once and
     *    lost its id later (display_id_lost, then an unmatched reap), so the
     *    text says "cannot currently say", never "never proved" (#5569).
     *  - reap_failed: a removal ran and did not prove the rule absent.
     *  - active without an id: only executeEditAllowRule()'s display_id_lost
     *    path writes this. Mesh stopped returning the rule under its id,
     *    and the rule may be re-keyed or gone.
     *
     * $opener names the record ("... created recently as PSA record #N" from
     * the dedup arms, "... is recorded as PSA record #N" from the live ones);
     * $atApproval picks the tail, since nothing is staged at approval.
     */
    private static function recordRefusal(MeshAllowRule $record, string $opener, bool $atApproval): ?string
    {
        $hasId = trim((string) $record->mesh_rule_id) !== '';
        if ($record->state === MeshAllowRule::STATE_ACTIVE && $hasId) {
            return null;
        }

        $state = "(state '{$record->state}')";
        $expiry = $record->expires_at?->toDayDateTimeString().' UTC';
        $pastExpiry = $record->expires_at !== null && ! $record->expires_at->isFuture();
        $oneIdOnly = 'That proves only that the rule this record tracked is gone; the PSA has not checked whether any other rule for this sender is in force on the tenant.';

        $body = match (true) {
            $record->state === MeshAllowRule::STATE_REMOVED => "an approved mesh_remove_allow_rule has since proved it absent upstream {$state}. {$oneIdOnly}",
            $record->state === MeshAllowRule::STATE_REAPED => "the expiry job has since proved it absent upstream after its expiry {$state}. {$oneIdOnly}",
            $record->state === MeshAllowRule::STATE_REAP_FAILED => "an earlier removal of it did not prove it absent {$state}, so whether the rule is still live upstream is unknown. "
                .match (true) {
                    $record->isPermanent() && $hasId => 'It is PERMANENT, so the expiry job never retries the removal. While Mesh still returns the rule under its recorded id, an approved mesh_remove_allow_rule can retry it; if the rule is already gone, neither verb can reach it and the PSA record has to be cleared by hand.',
                    // An approved edit's display_id_lost clears the id and keeps
                    // reap_failed, so there may be no recorded id to retry under;
                    // MeshAllowRuleReaper::settleUnexpired() can write one back.
                    $record->isPermanent() => "It is PERMANENT, so the expiry job never retries the removal, and the PSA holds no upstream rule id for it, so neither verb can reach it now. The expiry job does look this record up by sender and comment: once exactly one rule in Mesh matches, it records that rule's id here and an approved mesh_remove_allow_rule can retry the removal; if the rule is already gone, the PSA record has to be cleared by hand.",
                    $pastExpiry => 'Its expiry ('.$expiry.') has passed, so the expiry job retries the removal.',
                    default => 'The expiry job retries the removal once its expiry ('.$expiry.') passes.',
                },
            $record->state === MeshAllowRule::STATE_ACTIVE => "Mesh stopped returning the rule under its recorded id after an approved mesh_edit_allow_rule, and that id was cleared {$state}, so whether the rule is still in force upstream is unknown: Mesh may have re-keyed it or it may be gone. "
                .match (true) {
                    $record->isPermanent() => 'It is PERMANENT, and '.self::PERMANENT_UNTIL_NO_ID.'.',
                    $pastExpiry => 'Its expiry ('.$expiry.') has passed, so the expiry job tries to re-identify the rule by sender and comment and remove it.',
                    default => 'Once its expiry ('.$expiry.') passes, the expiry job tries to re-identify the rule by sender and comment and remove it.',
                },
            // STATE_UNRESOLVED, and any state this code does not know, which is
            // never an allow in force.
            default => "the PSA cannot currently say whether that rule is live upstream {$state}, so it is not answered as already in place. "
                .match (true) {
                    $pastExpiry => 'Its expiry ('.$expiry.') has passed, so the expiry job tries to identify the rule and remove it.',
                    // #5495: the same recovery the unsettled brake names.
                    (bool) $record->scope_proved => 'Its scope WAS confirmed when it was created, so the expiry job only has to identify it: once one rule in Mesh carries its sender and comment, the record goes active with that id and the remove and edit verbs can reach it. '
                        .($hasId ? '' : 'Until then the PSA holds no upstream rule id for it, so neither verb can.'),
                    default => 'Mesh never confirmed its scope, so identifying the rule does not settle this record. It closes when the PSA proves the rule removed: an approved mesh_remove_allow_rule can do that once the record carries its upstream id, and the expiry job does it after the record\'s expiry passes'
                        .($record->isPermanent() ? ' (this record is PERMANENT, so it first needs a date from mesh_edit_allow_rule, which also needs the id)' : '')
                        .'; otherwise someone has to check the rule in the Mesh portal and clear the PSA record by hand.',
                },
        };

        $tail = $atApproval
            ? ' No upstream call was made and the lifetime on this proposal was NOT applied.'
            : (in_array($record->state, [MeshAllowRule::STATE_REMOVED, MeshAllowRule::STATE_REAPED], true)
                ? ' Nothing was staged now. If the sender still needs allowing, check its rules in the Mesh portal, then stage it again once the 24-hour post-execution dedup window has elapsed.'
                : ' Nothing was staged now.');

        return rtrim("{$opener}, but {$body}").$tail;
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
        return $this->executedAudit($tool, $clientId, $contentHash) !== null;
    }

    /**
     * The newest 'executed' audit row for this write inside the dedup window,
     * or null. The window's cutoff is computed once, here; a caller that
     * needs both "did it execute" and "which run" reads this row once rather
     * than asking twice across a moving cutoff (#5650).
     */
    private function executedAudit(string $tool, ?int $clientId, string $contentHash): ?TechnicianActionLog
    {
        return $this->actionLogQuery($tool, $clientId)
            ->where('content_hash', $contentHash)
            ->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->latest('id')
            ->first();
    }

    /**
     * A recent audit row for this write whose outcome was a FAULT: the write
     * landed (or may have) and a post-condition did not hold.
     *
     * Deliberately NOT folded into alreadyExecuted(): a fault is not an
     * idempotent success and must never be answered as one — 'already created
     * recently', success:true, is the opposite of what a live untracked rule
     * needs said about it. Kept because it is the LAST brake standing for
     * record_unwritable, the one fault that writes no mesh_allow_rules row for
     * liveAllowRule()/unsettledAllowRule() to see — and NARROWED to exactly
     * that fault, because this reads the IMMUTABLE audit log and nothing that
     * later settles the situation can clear a row in it.
     *
     * Every other fault path (scope_unconfirmed, rule_id_unresolved,
     * create_unacknowledged, create_outcome_unmeasured) DOES write a
     * mesh_allow_rules row, so the two brakes above own those cases in every
     * state that row can reach — including 'removed' (executeRemoveAllowRule
     * proved absence) and 'reaped' (the reaper proved a 404), where the right
     * answer is to let the CORRECTED RETRY through. That retry is the one thing
     * the scope branch and reconcileUnacknowledgedCreate both say a fault must
     * not suppress; matching them here refused it for the whole dedup window
     * with a message asserting a live untracked rule the PSA had already proved
     * gone.
     *
     * So the question is not "did a fault happen" but "did a fault happen that
     * left NO row of its own": run_id ties an audit row to its proposal, and
     * mesh_allow_rules.technician_run_id ties the row back to the same one.
     *
     * Returns null when no such fault is in the window, FAULT_RECORD_NOT_FOUND
     * when a fault row's run was looked up and carries no record, else
     * FAULT_NO_RUN (a run-less fault row: nothing was looked up). Which one
     * decides only the refusal's wording, never whether it refuses (#5923).
     */
    private function faultedExecution(string $tool, ?int $clientId, string $contentHash): ?string
    {
        $faultRunIds = $this->actionLogQuery($tool, $clientId)
            ->where('content_hash', $contentHash)
            ->where('result_status', 'executed_with_fault')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->pluck('run_id')
            ->all();

        if ($faultRunIds === []) {
            return null;
        }

        $recordedRunIds = MeshAllowRule::query()
            ->whereIn('technician_run_id', array_values(array_filter($faultRunIds, static fn ($id): bool => $id !== null)))
            ->pluck('technician_run_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        // A fault with a run whose record lookup found nothing is the
        // measured case and is answered first. A fault audited against no
        // run cannot be tied to a row either way, and unknown is a refusal
        // here as everywhere else in this verb; the two are worded apart
        // (#5923).
        $runLess = false;
        foreach ($faultRunIds as $runId) {
            if ($runId === null) {
                $runLess = true;
            } elseif (! in_array((int) $runId, $recordedRunIds, true)) {
                return self::FAULT_RECORD_NOT_FOUND;
            }
        }

        return $runLess ? self::FAULT_NO_RUN : null;
    }

    /**
     * What the PSA wrote about this sender's rule AFTER $run was staged, in
     * words that open the refusal, or null when nothing was (b5c3: #5639,
     * #5654). Asked only at
     * approval, after every other brake, so it decides only cards that would
     * otherwise reach createAllowRule.
     *
     * Two facts, neither bounded by the dedup window:
     *  - a create for this write (the base key, so this tenant) that ran
     *    after the card's own staging row: audited 'executed', or audited
     *    'executed_with_fault' with no PSA record of its own (record_unwritable:
     *    a rule may be live that nothing tracks). Either way a sibling card was
     *    approved first, whatever became of its record since (removed, reaped,
     *    or never written). Ordered by audit row id, not by clock, so a create
     *    in the same second as the staging is still placed on the right side
     *    of it. A fault that DID write a record is that record's business
     *    and is not matched here. While that record is open, in ANY open
     *    state (OPEN_STATES, #5748), an ACTIVE one past its expiry and not
     *    yet reaped included, the two brakes above have already refused the
     *    card before this is asked. Once it is proved absent, the second
     *    fact below refuses a card staged before that if the record is on
     *    this tenant; a scope fault's record stores the tenant Mesh
     *    attested instead, and then the card is the corrected retry both
     *    fault texts promise and it creates (MeshAddAllowRuleTest,
     *    MeshAllowRuleBurnerB5dTest pin both);
     *  - a record for this sender on this tenant proved absent after the card
     *    was staged: removed by an approved mesh_remove_allow_rule
     *    (removed_at) or reaped by the expiry job (reaped_at). This is the
     *    card a live brake refused and released while the record was
     *    unsettled, and the card staged before a named approver's removal.
     *    At or after the staging second: both are second-precision, so a
     *    removal later in the staging second cannot be told apart from one
     *    just before it, and the tie errs toward refusing, as the create
     *    fact's fallback does. The refusal then says it was the same second
     *    rather than claiming the change came after the staging.
     */
    private function changedSinceStaging(TechnicianRun $run, int $clientId, string $tenant, string $sender, string $baseHash): ?string
    {
        $stagedAuditId = TechnicianActionLog::query()
            ->where('run_id', $run->id)
            ->where('action_type', 'mesh_stage_add_allow_rule')
            ->where('result_status', 'awaiting_approval')
            ->min('id');

        $recordedRunIds = MeshAllowRule::query()->where('client_id', $clientId)->whereNotNull('technician_run_id')->select('technician_run_id');
        $create = $this->actionLogQuery('mesh_add_allow_rule', $clientId)
            ->where('content_hash', $baseHash)
            ->where(fn ($q) => $q
                ->where('result_status', 'executed')
                ->orWhere(fn ($f) => $f
                    ->where('result_status', 'executed_with_fault')
                    ->where(fn ($r) => $r->whereNull('run_id')->orWhereNotIn('run_id', $recordedRunIds))))
            // No staging row (its audit write can fail without throwing):
            // the run's own creation time stands in, inclusively, so a tie
            // on that fallback errs toward refusing.
            ->when(
                $stagedAuditId !== null,
                fn ($q) => $q->where('id', '>', $stagedAuditId),
                fn ($q) => $q->where('created_at', '>=', $run->created_at),
            )
            ->latest('id')
            ->first();

        if ($create !== null) {
            $record = $create->run_id === null ? null : MeshAllowRule::query()
                ->where('client_id', $clientId)
                ->where('technician_run_id', $create->run_id)
                ->latest('id')
                ->first();

            // #5747: say only what the audit row and the record show. A row
            // with no run is tied to no proposal (the verb is staged-only, so
            // nothing in it approved that create); a row of THIS card is not
            // "another proposal"; a fault is not a clean create; and a
            // missing record is a fact about now, not about what the create
            // wrote (a record can be written and later deleted by hand).
            $faulted = $create->result_status === 'executed_with_fault';
            $who = match (true) {
                $create->run_id === null => "a create of an allow rule for '{$sender}' on this client was audited against no proposal",
                (int) $create->run_id === (int) $run->id => "an earlier approval of this same card ran its create for '{$sender}'",
                default => "another proposal for '{$sender}' on this client was approved",
            };
            // A fault is matched only when no record carries its run, so a
            // faulted create never reaches the record arm. contract-s2:3: a
            // fault row is also written when Mesh never acknowledged the
            // create (reconcileUnacknowledgedCreate), so the text does not say
            // the create reached Mesh; and only a row WITH a run had its
            // record looked up, so only that arm says none exists now.
            $what = match (true) {
                $record !== null => " and wrote PSA record #{$record->id} (now in state '{$record->state}')",
                $faulted && $create->run_id !== null => ' and its create ended in a fault (this refusal does not show whether a rule reached Mesh), and no PSA record of it exists now',
                $faulted => ', and that create ended in a fault (this refusal does not show whether a rule reached Mesh)',
                $create->run_id === null => ', and no PSA record can be tied to it',
                default => ', and no PSA record of its create exists now',
            };
            // The no-staging-row fallback is by clock, inclusive: a create in
            // the staging second cannot be placed after it.
            $opener = $stagedAuditId === null && $run->created_at !== null
                && $create->created_at?->format('Y-m-d H:i:s') === $run->created_at->format('Y-m-d H:i:s')
                ? self::SAME_SECOND
                : 'Since this card was staged, ';

            return $opener.$who.$what;
        }

        $ended = MeshAllowRule::query()
            ->where('client_id', $clientId)
            ->where('mesh_customer_id', $tenant)
            ->where('sender', $sender)
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->where('state', MeshAllowRule::STATE_REMOVED)->where('removed_at', '>=', $run->created_at))
                ->orWhere(fn ($r) => $r->where('state', MeshAllowRule::STATE_REAPED)->where('reaped_at', '>=', $run->created_at)))
            ->latest('id')
            ->first();

        if ($ended === null) {
            return null;
        }

        // A same-second tie is refused (above) but not called "since": the
        // PSA cannot say which came first.
        $endedAt = $ended->state === MeshAllowRule::STATE_REMOVED ? $ended->removed_at : $ended->reaped_at;
        $opener = $endedAt !== null && $run->created_at !== null
            && \Illuminate\Support\Carbon::parse($endedAt)->format('Y-m-d H:i:s') === $run->created_at->format('Y-m-d H:i:s')
            ? self::SAME_SECOND
            : 'Since this card was staged, ';

        return $opener.($ended->state === MeshAllowRule::STATE_REMOVED
            ? "PSA record #{$ended->id} for '{$sender}' on this client was closed by an approved mesh_remove_allow_rule that proved its rule absent"
            : "PSA record #{$ended->id} for '{$sender}' on this client was closed by the expiry job, which proved its rule absent");
    }

    private function executedRunId(string $tool, ?int $clientId, string $contentHash): ?int
    {
        $runId = $this->executedAudit($tool, $clientId, $contentHash)?->run_id;

        return $runId === null ? null : (int) $runId;
    }

    /**
     * "Is there a live staged run awaiting approval right now" comes from the
     * runs table, NEVER the immutable audit log — a stale awaiting_approval
     * audit row survives an operator deny by design (bd psa-k4s0 Root B).
     *
     * Asked over the WHOLE generation series of the base hash, never just
     * generation 0. stagedRunSlot() moves a proposal onto
     * hash($baseHash.':'.$generation) whenever an earlier generation is held by
     * a spent run, so after one deny the awaiting proposal for an identical
     * request sits on a hash generation 0 alone would never look at — and that
     * identical re-stage would fall through to the sender-keyed guard and be
     * refused with a message asserting a lifetime difference that does not
     * exist, where it used to be answered "Already staged; awaiting approval."
     * What stagedRunSlot() can hand out, this must be able to find.
     */
    private function liveAwaitingRun(int $ticketId, string $tool, string $baseHash): ?TechnicianRun
    {
        $hashes = [$baseHash];
        for ($generation = 1; $generation < self::MAX_RUN_GENERATIONS; $generation++) {
            $hashes[] = hash('sha256', $baseHash.':'.$generation);
        }

        return TechnicianRun::query()
            ->where('ticket_id', $ticketId)
            ->where('action_type', $tool)
            ->whereIn('content_hash', $hashes)
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->first();
    }

    /**
     * An AwaitingApproval proposal on this ticket for the SAME SENDER, on any
     * lifetime.
     *
     * liveAwaitingRun() is keyed on the content hash — over every generation of
     * it, so an identical re-stage is already answered idempotently there and
     * what reaches here genuinely asks a DIFFERENT lifetime — and that hash
     * carries the caller's expiry (#1133), so it cannot see a proposal for this
     * sender that asks for another one. This one is keyed on the sender itself, read
     * from the plaintext redacted_params the approval card renders: the sender
     * is not recoverable from a hash, and the encrypted payload is not
     * queryable. Bounded by MAX_RUN_GENERATIONS proposals per ticket.
     */
    private function awaitingRunForSender(int $ticketId, string $tool, string $sender): ?TechnicianRun
    {
        return TechnicianRun::query()
            ->where('ticket_id', $ticketId)
            ->where('action_type', $tool)
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->get()
            ->first(static fn (TechnicianRun $awaiting): bool => mb_strtolower((string) data_get($awaiting->proposed_meta, 'redacted_params.sender')) === $sender);
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

    /**
     * The staged and direct names of ONE verb share a dedup window; they are
     * one action.
     *
     * The pair is derived from $tool rather than listed, and that is
     * load-bearing now that there is more than one verb (#1134). While
     * mesh_add_allow_rule was the only one, hardcoding its two names was
     * indistinguishable from honouring the argument — with a second verb it
     * would mean the removal verb deduping against the create verb's log
     * (so a repeat removal is never caught). They are opposite-signed writes:
     * an allow that just landed says nothing about the removal of a different
     * rule.
     */
    private function actionLogQuery(string $tool, ?int $clientId)
    {
        $direct = self::STAGED_TO_DIRECT[$tool] ?? $tool;
        $staged = array_search($direct, self::STAGED_TO_DIRECT, true);

        $query = TechnicianActionLog::query()
            ->whereIn('action_type', $staged === false ? [$direct] : [$direct, $staged]);

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
     * liveAllowRule, unsettledAllowRule) can see the
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
            // The class, never the message: a QueryException's message is the
            // SQL with its bindings (the summary, sender and ticket text).
            \Illuminate\Support\Facades\Log::error('[Mesh] Allow-rule audit row could not be written ('.$e::class.')');

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

    /**
     * The sender carried by the removal this client ALREADY executed on this
     * content hash, lower-cased — or null if it cannot be re-read.
     *
     * The rule is proved absent, so findRuleById() cannot answer this and the
     * un-scoped detail route is never an option. Two local sources can, both
     * scoped to this client so neither can speak about a row on another
     * tenant: the executed proposal's plaintext `redacted_params.sender` (the
     * same field the approval card rendered), and, for a rule the PSA
     * tracked, the row that removal closed.
     *
     * Null is UNKNOWN, not "no sender", and the caller refuses on it — a
     * confirmation that cannot be checked is not a confirmation that passed.
     */
    private function executedRemovalSender(int $clientId, string $contentHash, string $ruleId): ?string
    {
        $runId = $this->executedRunId('mesh_remove_allow_rule', $clientId, $contentHash);
        if ($runId !== null) {
            $sender = data_get(TechnicianRun::find($runId)?->proposed_meta, 'redacted_params.sender');
            if (is_scalar($sender) && trim((string) $sender) !== '') {
                return mb_strtolower(trim((string) $sender));
            }
        }

        $record = MeshAllowRule::query()
            ->where('client_id', $clientId)
            ->where('mesh_rule_id', $ruleId)
            ->latest('id')
            ->first();

        return $record !== null && trim((string) $record->sender) !== ''
            ? mb_strtolower(trim((string) $record->sender))
            : null;
    }

    /**
     * Resolve and validate everything the REMOVAL depends on, against LIVE
     * upstream state (#1134).
     *
     * Called at staging AND again at approval, deliberately. The card an
     * approver reads is a snapshot; between it and the button the rule can be
     * deleted in the portal, re-created for a different sender under the same
     * id (it cannot, but nothing here depends on believing that), or the
     * client's Mesh mapping can be re-pointed at another tenant. Re-resolving
     * means the scope check is made against the state the delete will actually
     * hit, not the state that justified staging it.
     *
     * The rule is looked up through MeshWriteClient::findRuleById(), which
     * resolves ONLY within this tenant. A rule id belonging to another
     * customer is therefore not "refused" here — it is absent, and the caller
     * is told exactly that. Saying "that rule belongs to another customer"
     * would confirm the existence of a row on a tenant this client has no
     * business knowing about.
     *
     * @return array{rule_id?: string, sender?: string, comment?: string, mesh_customer_id?: string, client_name?: string, scope_note?: string, expiry_note?: string, created_by?: string|null, record?: MeshAllowRule|null, unlinked_note?: string|null, tracked_note?: string, error?: string}
     */
    private function removeAllowRuleTarget(array $arguments, int $clientId): array
    {
        $client = Client::find($clientId);
        if (! $client) {
            return ['error' => 'Client not found'];
        }

        $tenant = trim((string) ($client->mesh_customer_id ?? ''));
        if ($tenant === '') {
            return ['error' => "{$client->name} has no Mesh customer mapping; link the client to its Mesh tenant before removing allow rules."];
        }

        $ruleId = $this->requiredString($arguments, 'rule_id');
        if ($ruleId === null) {
            return ['error' => 'rule_id is required'];
        }

        // Bounded before it is used in a URL path segment or a log line. The
        // id is a vendor uuid; anything of this length is a caller mistake and
        // refusing it here keeps an unbounded string out of every downstream
        // string we build.
        if (mb_strlen($ruleId) > 255) {
            return ['error' => 'rule_id is not a Mesh rule id (longer than 255 characters); nothing was removed.'];
        }

        try {
            $row = $this->client->findRuleById($tenant, $ruleId);
        } catch (MeshClientException $e) {
            // Fail closed. An unreadable list is not an empty list, and the
            // difference matters in both directions: refusing wrongly costs a
            // retry, deleting on an unverified scope cannot be undone. The
            // status is the whole report: the exception message can quote the
            // vendor's response body and the request URI (C-56).
            return ['error' => "The rule could not be read from Mesh, so its scope could not be checked and nothing was removed: {$e->statusPhrase('the rule list read')}"];
        }

        if ($row === null) {
            return ['error' => "No allow rule with id '{$ruleId}' belongs to {$client->name}'s Mesh tenant. Nothing was removed. "
                .'Check the id against this client\'s own rules — an id from another customer, or from the partner-wide list, will not resolve here.'];
        }

        // ALLOW-ONLY, and unknown is a refusal. `ab` is the allow/block flag
        // (MeshWriteClient::ALLOW_RULE documents how its meaning was measured).
        // Removing a BLOCK rule un-blocks a sender the customer chose to block
        // — the same shape of weakening this whole lane exists to gate, and it
        // is not what an approver reading "remove allow rule" consented to. A
        // row whose `ab` is absent or is not a boolean is not proved to be an
        // allow rule, so it is refused too rather than assumed.
        $ab = $row['ab'] ?? null;
        if (! is_bool($ab)) {
            return ['error' => "Mesh did not state whether rule '{$ruleId}' is an allow rule or a block rule, so it was not removed. This verb only ever removes rules Mesh confirms are ALLOW rules."];
        }
        if ($ab !== MeshWriteClient::ALLOW_RULE) {
            return ['error' => "Rule '{$ruleId}' is a BLOCK rule, not an allow rule. Removing it would un-block a sender this customer chose to block, which is a different power and is not available from the PSA. Nothing was removed."];
        }

        $sender = is_scalar($row['sender'] ?? null) ? mb_strtolower(trim((string) $row['sender'])) : '';
        if ($sender === '') {
            return ['error' => "Mesh returned no sender for rule '{$ruleId}', so the typed confirmation cannot be checked and nothing was removed."];
        }

        // The typed confirmation is on the SENDER here, not the domain. The
        // create verb confirms the domain because the domain is what an allow
        // widens; a removal is identified by an opaque uuid, and the thing the
        // approver must prove they know is WHICH rule that id is. Typing the
        // sender back is the only check that can catch a pasted id that
        // resolves to a real rule other than the intended one.
        $confirm = $this->requiredString($arguments, 'confirm_sender');
        if ($confirm === null || mb_strtolower(trim($confirm)) !== $sender) {
            return ['error' => "confirm_sender must exactly match the sender on rule '{$ruleId}' to remove it. Read the rule first and type its sender back."];
        }

        $comment = is_scalar($row['comment'] ?? null) ? trim((string) $row['comment']) : '';
        $createdBy = is_scalar($row['created_by'] ?? null) && trim((string) $row['created_by']) !== ''
            ? trim((string) $row['created_by'])
            : null;

        // Is this a rule the PSA itself wrote and is tracking? The join is on
        // the upstream id, scoped to this client — a rule id is unique
        // upstream, and scoping the lookup keeps a row belonging to another
        // client from ever being mutated by this client's removal.
        $record = MeshAllowRule::query()
            ->where('client_id', $clientId)
            ->where('mesh_rule_id', $ruleId)
            ->latest('id')
            ->first();

        // #5570: no record under this id is not "no record". A PSA record
        // whose id display_id_lost cleared still matches this rule by sender
        // and comment, so the rule is not called PSA-created-and-unknown then.
        // That record is NOT closed by this removal: the id is the only proof
        // that ties them together.
        //
        // #5648 / #5640: the match is narrowed to what could be the PSA's own
        // write of THIS rule: this client, THIS tenant (the one the rule was
        // just read from), a state in which the record still answers for a
        // rule (removed and reaped records are closed), sender and comment
        // compared case-exactly in PHP (MariaDB's default collation would
        // not), and
        // EXACTLY ONE such record; two or more are no match. The comment is
        // free text in the Mesh portal, so the delivery caveat the FOREIGN
        // label gives is kept in the unlinked note, never dropped.
        $unlinkedCandidates = $record === null && $comment !== ''
            ? MeshAllowRule::query()
                ->where('client_id', $clientId)
                ->where('mesh_customer_id', $tenant)
                ->whereNull('mesh_rule_id')
                ->whereIn('state', [MeshAllowRule::STATE_ACTIVE, MeshAllowRule::STATE_UNRESOLVED, MeshAllowRule::STATE_REAP_FAILED])
                // A case-insensitive PREFILTER (SQLite's = is exact, MariaDB's
                // default collation is not). It is not the same on every
                // engine (#5750): SQLite's LOWER() folds ASCII letters only,
                // so a non-ASCII capital in a stored sender or comment drops
                // the record here on SQLite (the card errs toward FOREIGN)
                // while MariaDB keeps it. The PHP === filter below is the one
                // exact compare, and it is the same everywhere.
                ->whereRaw('LOWER(sender) = ?', [mb_strtolower($sender)])
                ->whereRaw('LOWER(comment) = ?', [mb_strtolower($comment)])
                ->get()
                ->filter(static fn (MeshAllowRule $r): bool => $r->sender === $sender && $r->comment === $comment)
                ->values()
            : collect();
        $unlinked = $unlinkedCandidates->count() === 1 ? $unlinkedCandidates->first() : null;
        // A PERMANENT active record is never visited by the expiry job (the
        // reap pass needs an expiry, the settle pass an unsettled state), so
        // the note makes no identify or re-check promise for it.
        $unlinkedUnvisited = $unlinked !== null && $unlinked->state === MeshAllowRule::STATE_ACTIVE && $unlinked->isPermanent();
        // #5641 / #5749: the record stays, and the live brake (active,
        // unexpired) or the unsettled brake (every other open state, an
        // ACTIVE record past its expiry included, #5748) still refuses a new
        // allow rule for this sender at approval on it, whatever its id. #5647: approval re-derives the target, so if the record gets
        // this rule's id before then, approval closes it after all; the note
        // says so rather than promising a state the approval may not keep.
        $unlinkedNote = $unlinked === null ? null
            : "The PSA holds no record under this rule id, but PSA record #{$unlinked->id} (state '{$unlinked->state}', no recorded rule id) carries this rule's sender and comment. "
                .'The PSA cannot prove it tracks this rule, and the comment can be edited in the Mesh portal, so the rule may have been set up outside this system: removing it may break mail delivery that is working today. '
                .'That record is NOT closed by this removal. It stays as it is, and while it does, a new allow rule for this sender is refused (when it is approved, if not already when it is staged); '
                .($unlinkedUnvisited
                    ? 'it is PERMANENT and active, so the expiry job never examines it, never records this rule\'s id on it and never clears it: it has to be cleared by hand.'
                    : 'once this rule is gone the expiry job finds no rule to identify that record by, so it has to be cleared by hand.');

        return [
            'rule_id' => $ruleId,
            'sender' => $sender,
            'comment' => $comment,
            'mesh_customer_id' => $tenant,
            'client_name' => (string) $client->name,
            'created_by' => $createdBy,
            'record' => $record,
            'unlinked_note' => $unlinkedNote,
            'scope_note' => str_contains($sender, '@')
                ? "Scope: this rule allows the single address '{$sender}'. Removing it stops that address bypassing filtering."
                : "Scope: this rule allows EVERY sender at '{$sender}'. Removing it restores filtering for that whole domain.",
            // Criterion 3 on #1134: foreign is ALLOWED, and it is LABELLED. A
            // rule the PSA never wrote was put there by a human for a reason
            // this system cannot see — the Huntress SAT phishing-server allows
            // are the standing example — so the approver is told plainly that
            // removing it may break something somebody set up on purpose.
            'tracked_note' => $record !== null
                ? "This rule is PSA-TRACKED (record #{$record->id}"
                    .($record->isPermanent()
                        ? ', PERMANENT — it has no expiry, so nothing would have removed it automatically'
                        : ', due to expire '.$record->expires_at->toDayDateTimeString().' UTC')
                    .'), so removing it now ends it early and the PSA record is closed with it.'
                // #5647: card only (the executed summary states what
                // happened): approval re-derives the target, so a record that
                // gets this rule's id before then is closed after all.
                : ($unlinkedNote !== null
                    ? $unlinkedNote.($unlinkedUnvisited ? '' : ' Approval re-checks this: if the expiry job records this rule\'s id on that record before the card is approved, approving closes the record as removed.')
                    // #5754: true on every arm that reaches it: no record at
                    // all, and every record the narrowed match above declines
                    // (another tenant, removed or reaped, two or more, a case
                    // difference). The label and the warning are unchanged.
                    : 'This rule is FOREIGN: this client holds no PSA record under this rule id, and the PSA cannot match it by its exact sender and comment to exactly one open PSA record of this client on this tenant, so the PSA cannot show that it created this rule. '
                        .'It may have been set up outside this system, possibly deliberately and possibly for a reason this system cannot see — removing it may break mail delivery that is working today.'),
            'expiry_note' => is_scalar($row['date_expiry'] ?? null) && trim((string) $row['date_expiry']) !== ''
                ? 'Mesh displays an expiry of '.trim((string) $row['date_expiry']).' on this rule (display only — Mesh does not act on it).'
                : 'Mesh displays no expiry on this rule.',
        ];
    }

    /**
     * Stage a removal for cockpit approval. There is no immediate lane.
     *
     * @return array<string, mixed>
     */
    private function stageRemoveAllowRule(array $arguments, int $clientId, string $actorLabel, ?string $tokenLabel = null): array
    {
        $tool = 'mesh_stage_remove_allow_rule';

        $guard = $this->baseGuard($tool, $arguments, $clientId, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, $this->contentHash($tool, $clientId, 'guard', $arguments), $ticket['error'], $actorLabel);

            return ['error' => $ticket['error']];
        }

        $ruleIdForHash = is_scalar($arguments['rule_id'] ?? null) ? trim((string) $arguments['rule_id']) : 'unresolved';
        // ONE key for this write, and it is derivable before the target
        // resolves: the rule id IS the identity of a removal. Unlike the create
        // verb there is no second, lifetime-bearing hash — a removal has no
        // parameter that changes what it does, so the proposal and the write
        // are the same thing and dedup on the same key.
        $contentHash = $this->contentHash($tool, $clientId, 'remove-allow-rule-'.$ruleIdForHash, []);

        // Asked BEFORE the target is resolved, and that order is load-bearing.
        // A removal that landed is PROVED absent, so findRuleById() no longer
        // resolves the id and removeAllowRuleTarget() would refuse the retry
        // with "No allow rule with id ... belongs to" — a tenant-scope error
        // for a scope problem that does not exist, handed to the one caller
        // whose request has already been satisfied. Resolved-first, the
        // idempotent answer below was unreachable by construction.
        //
        // Asking it here is not a leak: the key is client-scoped and carries
        // the rule id, so it can only ever answer for a removal THIS client
        // already executed, and it confirms nothing about a row on any other
        // tenant.
        //
        // Unlike the create verb this is a genuinely idempotent answer and is
        // reported as success: the requested end state (that rule gone) is the
        // state that holds, and there is no lifetime for a second caller to be
        // silently deprived of. The id is echoed from the caller's own
        // argument — there is no live row left to read it back from.
        if ($this->alreadyExecuted('mesh_remove_allow_rule', $clientId, $contentHash)) {
            // The typed confirmation is compared HERE as well, and it has to
            // be: this branch runs before removeAllowRuleTarget(), which is
            // the only other place `confirm_sender` is read. Without it the
            // one guard against a valid id pasted for the WRONG rule would be
            // skipped in exactly the case where the pasted id matches a recent
            // removal, and the caller would be told `success` while the rule
            // it meant to remove stayed live. The rule is proved absent so
            // upstream cannot answer for it; the sender is re-read from local,
            // client-scoped state instead, and unknown is a refusal.
            $removedSender = $this->executedRemovalSender($clientId, $contentHash, $ruleIdForHash);

            if ($removedSender === null) {
                $message = "Rule '{$ruleIdForHash}' was already removed for this client recently, but the sender that removal carried could not be re-read, so confirm_sender could not be checked and no proposal was staged.";
                $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $contentHash, $message, $actorLabel);

                return ['error' => $message];
            }

            $confirm = $this->requiredString($arguments, 'confirm_sender');
            if ($confirm === null || mb_strtolower(trim($confirm)) !== $removedSender) {
                $message = "confirm_sender must exactly match the sender on rule '{$ruleIdForHash}' to remove it. Read the rule first and type its sender back.";
                $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $contentHash, $message, $actorLabel);

                return ['error' => $message];
            }

            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $this->executedRunId('mesh_remove_allow_rule', $clientId, $contentHash),
                'rule_id' => $ruleIdForHash,
                'message' => "Rule '{$ruleIdForHash}' was already removed for this client recently; no proposal was staged.",
            ];
        }

        $target = $this->removeAllowRuleTarget($arguments, $clientId);
        if (isset($target['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $contentHash, $target['error'], $actorLabel);

            return ['error' => $target['error']];
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

        // No per-client staging cooldown — see stageAllowRule().

        $proposedContent = "Remove the Mesh Email Security allow rule '{$target['rule_id']}' for {$target['client_name']}.\n"
            ."Sender allowed by this rule: {$target['sender']}\n"
            .$target['scope_note']."\n"
            .$target['tracked_note']."\n"
            .$target['expiry_note']."\n"
            .'Mesh comment on the rule: '.($target['comment'] !== '' ? $target['comment'] : '(none)')."\n"
            .'Recorded upstream as created by: '.($target['created_by'] ?? 'not stated by Mesh')."\n"
            ."Removal is immediate and is proved by re-reading the rule: if it is still readable afterwards the removal is reported as a fault, never as done.\n"
            .'Reason: '.$guard['reason'];

        $meta = [
            'drafted_by' => $actorLabel,
            // Card XUiMXNEH: the BARE token label; withdraw_staged_action matches this
            // field only, never the prefixed drafted_by above.
            'drafted_by_token' => $tokenLabel,
            'reasons' => [$guard['reason']],
            'direct_tool' => self::STAGED_TO_DIRECT[$tool],
            'redacted_params' => [
                'rule_id' => $target['rule_id'],
                'sender' => $target['sender'],
                'client' => $target['client_name'],
                'psa_tracked' => $target['record'] !== null ? 'yes' : (($target['unlinked_note'] ?? null) !== null ? 'no record under this rule id; an id-less PSA record matches its sender and comment' : 'no (foreign rule)'),
                'upstream_created_by' => $target['created_by'] ?? 'not stated by Mesh',
            ],
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => self::STAGED_TO_DIRECT[$tool],
                'client_id' => $clientId,
                'ticket_id' => $ticket->id,
                'arguments' => [
                    // Only the id and the confirmation are carried. The tenant,
                    // the sender, the allow/block flag and the PSA record are
                    // ALL re-resolved at approval against live state — carrying
                    // them would be carrying the very facts the second scope
                    // check exists to re-measure.
                    'rule_id' => $target['rule_id'],
                    'confirm_sender' => $target['sender'],
                    'reason' => $guard['reason'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ];

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
            if ($run->state !== TechnicianRunState::AwaitingApproval) {
                $message = 'Another proposal for this removal is already in flight on this ticket; no proposal was staged.';
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

        $this->auditAttempt($tool, 'awaiting_approval', $clientId, $ticket, $contentHash, "MCP staged removal of Mesh allow rule '{$target['rule_id']}' (sender '{$target['sender']}').", $actorLabel, $run->id);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'rule_id' => $target['rule_id'],
            'sender' => $target['sender'],
            'psa_tracked' => $target['record'] !== null,
            'message' => 'Staged for cockpit approval.',
        ];
    }

    /**
     * Execute an approved removal. Only reachable through approveStagedRun().
     *
     * The shape that matters here is criterion 4: SUCCESS IS THE
     * POST-CONDITION, NOT THE DELETE'S RESPONSE. `ruleAbsent()` — the reaper's
     * own detail-GET-must-404 — is the only thing that decides the outcome,
     * and it is measured even when the DELETE itself threw, because a DELETE
     * that timed out may well have committed.
     *
     * @return array<string, mixed>
     */
    private function executeRemoveAllowRule(
        array $arguments,
        int $clientId,
        string $actorLabel,
        ?TechnicianRun $run = null,
        ?int $approverId = null,
    ): array {
        $tool = 'mesh_remove_allow_rule';

        $ruleIdForHash = is_scalar($arguments['rule_id'] ?? null) ? trim((string) $arguments['rule_id']) : 'unresolved';
        // Re-derived from the arguments, NEVER $run->content_hash: a second
        // proposal for the same removal takes the next generation of the run
        // key (stagedRunSlot), so keying the duplicate guard on the run's own
        // hash would let a sibling proposal walk past a brake the first
        // approval already satisfied. Same rule, same reason, as the create
        // verb.
        $contentHash = $this->contentHash('mesh_stage_remove_allow_rule', $clientId, 'remove-allow-rule-'.$ruleIdForHash, []);

        // Asked BEFORE the scope re-check, for the reason stageRemoveAllowRule
        // records: the removal this key matches is PROVED absent, so the id no
        // longer resolves and the re-check below would refuse a duplicate card
        // with a tenant-scope error, release the claim, and leave that card
        // AwaitingApproval forever with nothing that could ever settle it.
        // Client-scoped and keyed on the rule id, so it can only answer for a
        // removal THIS client executed. The id is echoed from the approved
        // arguments — there is no live row left to read it back from.
        if ($this->alreadyExecuted($tool, $clientId, $contentHash)) {
            $message = "Rule '{$ruleIdForHash}' was already removed for this client recently; no upstream call was made.";
            $this->auditAttempt($tool, 'blocked', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['success' => true, 'idempotent' => true, 'message' => $message];
        }

        // The scope check runs AGAIN, against live state. This is the check
        // that makes the removal safe, not the one at staging: the card may
        // have waited days, and the client's Mesh mapping can be re-pointed in
        // that time.
        $target = $this->removeAllowRuleTarget($arguments, $clientId);
        if (isset($target['error'])) {
            $message = $target['error'].' (re-checked at approval; nothing was removed)';
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['error' => $message];
        }

        $record = $target['record'] instanceof MeshAllowRule ? $target['record'] : null;
        $deleteError = null;

        try {
            $this->client->deleteRule($target['rule_id']);
        } catch (MeshClientException $e) {
            // NOT returned as an error, and this is the whole design of the
            // method. Mesh commits before it answers, so a timeout or a reset
            // connection can sit on top of a rule that IS gone. Declaring
            // failure here would put the proposal back in front of the
            // approver for a removal that already happened; declaring success
            // would assert one that may not have. The post-condition below
            // answers it, and it is the only thing that does. Status only
            // (C-56): this text reaches the approver, last_error and the audit row.
            $deleteError = $e->statusPhrase('the DELETE');
        }

        $absent = $this->client->ruleAbsent($target['rule_id']);

        if ($absent === true) {
            $summary = "Removed Mesh allow rule '{$target['rule_id']}' (sender '{$target['sender']}') for this client; absence proved by detail read."
                .($deleteError !== null ? ' The DELETE call itself did not answer cleanly ('.$deleteError.'), but the rule is gone.' : '')
                .($record !== null ? " PSA record #{$record->id} closed." : ' '.($target['unlinked_note'] ?? 'The rule was foreign — the PSA held no record of it.'));

            if ($record !== null) {
                // Recorded as an operator removal, not as a reaping: see the
                // migration. STATE_REMOVED is outside scopeReapable(), so the
                // row leaves the reaper's queue by construction.
                $record->forceFill([
                    'state' => MeshAllowRule::STATE_REMOVED,
                    'removed_at' => now(),
                    'last_error' => null,
                ])->save();
            }

            $this->auditAttempt($tool, 'executed', $clientId, null, $contentHash, $summary, $actorLabel, $run?->id, $approverId);

            return [
                'success' => true,
                'rule_id' => $target['rule_id'],
                'sender' => $target['sender'],
                'psa_record_id' => $record?->id,
                'removal_proved' => true,
                'message' => $summary,
            ];
        }

        // Everything below is a FAULT, never an error. An error releases the
        // claim and puts the card back in front of the approver, whose next
        // click would re-run a DELETE against a rule whose state we already
        // could not measure. The proposal is spent and the outcome is stated
        // in full instead.
        //
        // false = still readable, the delete did not take.
        // null  = unmeasurable, which is NOT a pass (MeshWriteClient::ruleAbsent).
        $fault = $absent === false ? 'removal_unproved' : 'removal_unmeasurable';
        $message = $absent === false
            ? "Mesh still returns allow rule '{$target['rule_id']}' (sender '{$target['sender']}') after the delete, so it was NOT removed and that sender is still bypassing filtering."
            : "Whether allow rule '{$target['rule_id']}' (sender '{$target['sender']}') was removed could NOT be measured — the confirming read did not answer. Treat the rule as still live until it is checked.";
        $message .= ($deleteError !== null ? ' The DELETE call reported: '.$deleteError.'.' : '')
            .' Check it in the Mesh portal and remove it by hand if it is still there.';

        if ($record !== null) {
            // STATE_REAP_FAILED, deliberately, and this is why there is no
            // `remove_failed` state: the rule may still be live and it still
            // carries whatever expiry this row holds, so it must stay in a
            // state the reaper still works (scopeReapable lists this one).
            // Marking it removed would retire a live hole from the only queue
            // that would ever have closed it.
            $record->forceFill([
                'state' => MeshAllowRule::STATE_REAP_FAILED,
                'last_error' => $message,
            ])->save();
            $message .= " PSA record #{$record->id} is left in the expiry job's queue so it keeps being retried.";
        }

        $this->auditAttempt($tool, 'executed_with_fault', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

        return [
            'success' => false,
            'fault' => $fault,
            'rule_id' => $target['rule_id'],
            'sender' => $target['sender'],
            'psa_record_id' => $record?->id,
            'removal_proved' => false,
            'message' => $message,
        ];
    }

    /**
     * Resolve and validate everything the EDIT depends on, against LIVE
     * upstream state AND the PSA's own record (#1135).
     *
     * Same shape and same reasons as removeAllowRuleTarget() — called at
     * staging and again at approval, resolved only within this client's
     * tenant, allow-only, sender typed back — with one requirement the remove
     * verb does not have: THE RULE MUST BE PSA-TRACKED. The only expiry that
     * does anything is the PSA's own (`mesh_allow_rules.expires_at`, which the
     * reaper enforces); Mesh's `date_expiry` is display-only. A rule the PSA
     * never wrote has no row for that expiry to live on, so "editing" its
     * expiry would change a portal column and nothing else — a no-op dressed
     * as a change. It is refused, and the refusal says why. Adopting foreign
     * rules into the reaper's queue is a product decision, not an edit path.
     *
     * @return array{rule_id?: string, sender?: string, comment?: string, mesh_customer_id?: string, client_name?: string, record?: MeshAllowRule, scope_note?: string, current_note?: string, error?: string}
     */
    private function editAllowRuleTarget(array $arguments, int $clientId): array
    {
        $client = Client::find($clientId);
        if (! $client) {
            return ['error' => 'Client not found'];
        }

        $tenant = trim((string) ($client->mesh_customer_id ?? ''));
        if ($tenant === '') {
            return ['error' => "{$client->name} has no Mesh customer mapping; link the client to its Mesh tenant before editing allow rules."];
        }

        $ruleId = $this->requiredString($arguments, 'rule_id');
        if ($ruleId === null) {
            return ['error' => 'rule_id is required'];
        }

        if (mb_strlen($ruleId) > 255) {
            return ['error' => 'rule_id is not a Mesh rule id (longer than 255 characters); nothing was changed.'];
        }

        try {
            $row = $this->client->findRuleById($tenant, $ruleId);
        } catch (MeshClientException $e) {
            // Status only, as on the remove path (C-56).
            return ['error' => "The rule could not be read from Mesh, so its scope could not be checked and nothing was changed: {$e->statusPhrase('the rule list read')}"];
        }

        if ($row === null) {
            return ['error' => "No allow rule with id '{$ruleId}' belongs to {$client->name}'s Mesh tenant. Nothing was changed. "
                .'Check the id against this client\'s own rules — an id from another customer, or from the partner-wide list, will not resolve here.'];
        }

        $ab = $row['ab'] ?? null;
        if (! is_bool($ab)) {
            return ['error' => "Mesh did not state whether rule '{$ruleId}' is an allow rule or a block rule, so it was not changed. This verb only ever edits rules Mesh confirms are ALLOW rules."];
        }
        if ($ab !== MeshWriteClient::ALLOW_RULE) {
            return ['error' => "Rule '{$ruleId}' is a BLOCK rule, not an allow rule. Block rules are never edited from the PSA. Nothing was changed."];
        }

        $sender = is_scalar($row['sender'] ?? null) ? mb_strtolower(trim((string) $row['sender'])) : '';
        if ($sender === '') {
            return ['error' => "Mesh returned no sender for rule '{$ruleId}', so the typed confirmation cannot be checked and nothing was changed."];
        }

        $confirm = $this->requiredString($arguments, 'confirm_sender');
        if ($confirm === null || mb_strtolower(trim($confirm)) !== $sender) {
            return ['error' => "confirm_sender must exactly match the sender on rule '{$ruleId}' to edit it. Read the rule first and type its sender back."];
        }

        $record = MeshAllowRule::query()
            ->where('client_id', $clientId)
            ->where('mesh_rule_id', $ruleId)
            ->latest('id')
            ->first();

        if ($record === null) {
            return ['error' => "Rule '{$ruleId}' (sender '{$sender}') is FOREIGN: the PSA did not create it and holds no record of it for {$client->name}. "
                .'Its expiry cannot be edited from here, because the only expiry that is enforced is the PSA\'s own — Mesh displays an expiry but does not act on one — '
                .'and a rule with no PSA record has nothing to enforce it. Nothing was changed. '
                .'Bringing rules created outside the PSA under its expiry job is a product decision, not something this verb can do; until then, foreign rules are managed in the Mesh portal.'];
        }

        // A closed row is not an editable one. STATE_REMOVED and STATE_REAPED
        // are terminal and outside scopeReapable(), so a new expiry written
        // onto either would never be enforced — the same no-op the foreign
        // refusal above exists to stop, reached from the other side.
        if (in_array($record->state, [MeshAllowRule::STATE_REMOVED, MeshAllowRule::STATE_REAPED], true)) {
            return ['error' => "The PSA record for rule '{$ruleId}' (#{$record->id}) is already closed ({$record->state}), so its expiry is no longer enforced and cannot be edited. "
                .'If the rule is still live in Mesh, it is now foreign to the PSA and is managed in the portal. Nothing was changed.'];
        }

        return [
            'rule_id' => $ruleId,
            'sender' => $sender,
            'comment' => is_scalar($row['comment'] ?? null) ? trim((string) $row['comment']) : '',
            'mesh_customer_id' => $tenant,
            'client_name' => (string) $client->name,
            'record' => $record,
            'scope_note' => str_contains($sender, '@')
                ? "Scope: this rule allows the single address '{$sender}'. The scope is not changed by this edit."
                : "Scope: this rule allows EVERY sender at '{$sender}'. The scope is not changed by this edit.",
            'current_note' => "PSA-TRACKED (record #{$record->id}, state {$record->state}): currently ".self::expiryPhrase($record->expires_at).'.',
        ];
    }

    /**
     * Stage an expiry edit for cockpit approval. There is no immediate lane.
     *
     * @return array<string, mixed>
     */
    private function stageEditAllowRule(array $arguments, int $clientId, string $actorLabel, ?string $tokenLabel = null): array
    {
        $tool = 'mesh_stage_edit_allow_rule';

        $guard = $this->baseGuard($tool, $arguments, $clientId, $actorLabel);
        if (isset($guard['error'])) {
            return ['error' => $guard['error']];
        }

        $ticket = $this->ticketForClient($arguments['ticket_id'] ?? null, $clientId);
        if (is_array($ticket)) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, $this->contentHash($tool, $clientId, 'guard', $arguments), $ticket['error'], $actorLabel);

            return ['error' => $ticket['error']];
        }

        // REQUIRED here, where the create verb makes it optional, and checked
        // before requestedExpiry() is consulted: that method answers an ABSENT
        // key with PERMANENT, which is the right answer for a rule being born
        // and the wrong one for a rule being edited. An edit with nothing to
        // edit is a mistake, and defaulting it would quietly rewrite a lifetime
        // somebody chose — here, by making a dated rule permanent.
        if (! array_key_exists('expires_at', $arguments)) {
            $message = 'expires_at is required: it is the only thing this verb changes. Give an ISO-8601 date or datetime, or the word "'.self::EXPIRY_NEVER.'" for a rule that never expires.';
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $this->contentHash($tool, $clientId, 'guard', $arguments), $message, $actorLabel);

            return ['error' => $message];
        }

        $expiry = $this->requestedExpiry($arguments, absentIsPermanent: false);
        if (isset($expiry['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $this->contentHash($tool, $clientId, 'guard', $arguments), $expiry['error'], $actorLabel);

            return ['error' => $expiry['error']];
        }
        $expiresAt = $expiry['expires_at'];

        $ruleIdForHash = is_scalar($arguments['rule_id'] ?? null) ? trim((string) $arguments['rule_id']) : 'unresolved';
        // ONE key, and the new lifetime is part of it. Unlike the create verb
        // there is no split between the proposal's identity and the write's:
        // the lifetime IS the write here, so two proposals for the same rule
        // with different dates are two different writes, and the same date
        // twice is the same one. Hashed as expiryValue(), which is stable for
        // one input, never as the parsed instant's default.
        $contentHash = $this->contentHash($tool, $clientId, 'edit-allow-rule-'.$ruleIdForHash, [
            'expires_at' => self::expiryValue($expiresAt),
        ]);

        $target = $this->editAllowRuleTarget($arguments, $clientId);
        if (isset($target['error'])) {
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $contentHash, $target['error'], $actorLabel);

            return ['error' => $target['error']];
        }

        $record = $target['record'];

        // A rule the PSA already holds at exactly this expiry has nothing to
        // change, and the row itself is the evidence — unlike the remove verb,
        // whose target is gone once it has succeeded, an edit leaves a local
        // record to compare against, so the duplicate question is answered
        // from STATE and not from a 24-hour window over the audit log. A
        // window would answer "already done" for a request the state no
        // longer satisfies (edited to X, then to Y, then X asked again) and
        // stop answering it after a day for one it still does.
        if (self::sameInstant($record->expires_at, $expiresAt)) {
            // A retry of an edit that landed recently is a satisfied request
            // and is answered as one, with the run that did it.
            if ($this->alreadyExecuted('mesh_edit_allow_rule', $clientId, $contentHash)) {
                return [
                    'success' => true,
                    'idempotent' => true,
                    'ticket_id' => $ticket->id,
                    'ticket_display_id' => $ticket->display_id,
                    'run_id' => $this->executedRunId('mesh_edit_allow_rule', $clientId, $contentHash),
                    'rule_id' => $target['rule_id'],
                    'expires_at' => self::expiryValue($expiresAt),
                    'message' => "Rule '{$target['rule_id']}' was already set to ".self::expiryPhrase($expiresAt).' for this client recently; no proposal was staged.',
                ];
            }

            // Otherwise it is a request for no change. Refused rather than
            // staged, because a card whose approval does nothing is a card
            // that teaches approvers not to read cards.
            $message = "Rule '{$target['rule_id']}' (sender '{$target['sender']}') already ".self::expiryPhrase($record->expires_at).' in the PSA; there is nothing to change and no proposal was staged.';
            $this->auditAttempt($tool, 'rejected', $clientId, $ticket, $contentHash, $message, $actorLabel);

            return ['error' => $message];
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

        // No per-client staging cooldown — see stageAllowRule().

        $proposedContent = "Change the expiry of the Mesh Email Security allow rule '{$target['rule_id']}' for {$target['client_name']}.\n"
            ."Sender allowed by this rule: {$target['sender']}\n"
            .$target['scope_note']."\n"
            .$target['current_note']."\n"
            .'New expiry: '.self::expiryPhrase($expiresAt)."\n"
            .($expiresAt === null
                ? "This makes the rule PERMANENT: nothing removes it automatically, and the hole in this customer's mail filtering stays open until someone removes the rule (mesh_remove_allow_rule) or gives it a date (mesh_edit_allow_rule).\n"
                : "The PSA enforces this: its expiry job removes the rule once that date has passed.\n")
            ."Mesh's own Expires column is display only — Mesh does not act on it. The PSA will ask Mesh to show the new date; "
            ."if Mesh does not accept or does not keep it, Mesh will keep showing the old date while the PSA enforces the new one, and that is reported on this card's outcome rather than retried.\n"
            .'Mesh comment on the rule (unchanged — it is how the PSA identifies the rule): '.($target['comment'] !== '' ? $target['comment'] : '(none)')."\n"
            .'Reason: '.$guard['reason'];

        $meta = [
            'drafted_by' => $actorLabel,
            // Card XUiMXNEH: the BARE token label; withdraw_staged_action matches this
            // field only, never the prefixed drafted_by above.
            'drafted_by_token' => $tokenLabel,
            'reasons' => [$guard['reason']],
            'direct_tool' => self::STAGED_TO_DIRECT[$tool],
            'redacted_params' => [
                'rule_id' => $target['rule_id'],
                'sender' => $target['sender'],
                'client' => $target['client_name'],
                'psa_record_id' => $record->id,
                'current_expires_at' => self::expiryValue($record->expires_at),
                'expires_at' => self::expiryValue($expiresAt),
                'expiry_note' => self::expiryPhrase($expiresAt),
            ],
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => self::STAGED_TO_DIRECT[$tool],
                'client_id' => $clientId,
                'ticket_id' => $ticket->id,
                'arguments' => [
                    // The id, the confirmation and the requested lifetime.
                    // The tenant, the sender, the allow/block flag and the PSA
                    // record are re-resolved at approval; the lifetime is
                    // re-validated there too, so a date that passes while the
                    // card waits is refused rather than applied.
                    'rule_id' => $target['rule_id'],
                    'confirm_sender' => $target['sender'],
                    'expires_at' => self::expiryValue($expiresAt),
                    'reason' => $guard['reason'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ];

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
            if ($run->state !== TechnicianRunState::AwaitingApproval) {
                $message = 'Another proposal for this edit is already in flight on this ticket; no proposal was staged.';
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

        $this->auditAttempt($tool, 'awaiting_approval', $clientId, $ticket, $contentHash, "MCP staged expiry edit of Mesh allow rule '{$target['rule_id']}' (sender '{$target['sender']}'): ".self::expiryPhrase($record->expires_at).' -> '.self::expiryPhrase($expiresAt).'.', $actorLabel, $run->id);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'rule_id' => $target['rule_id'],
            'sender' => $target['sender'],
            'current_expires_at' => self::expiryValue($record->expires_at),
            'expires_at' => self::expiryValue($expiresAt),
            'message' => 'Staged for cockpit approval ('.self::expiryPhrase($expiresAt).').',
        ];
    }

    /**
     * Execute an approved expiry edit. Only reachable through approveStagedRun().
     *
     * Two writes, in a fixed order, and only the first is the change:
     *
     *   1. `mesh_allow_rules.expires_at` — AUTHORITATIVE. The reaper reads this
     *      and nothing else; once it is saved the new lifetime is enforced.
     *   2. PATCH `date_expiry` upstream — DISPLAY. Mesh does not act on the
     *      field; the call exists so the portal's Expires column agrees with
     *      what the PSA will do.
     *
     * The post-condition is a tenant-scoped re-read of the SAME id: the row
     * must still resolve under it and must show the new date. If it does not
     * — the PATCH was refused, threw, did not stick, or Mesh answered with a
     * different id — the upstream side is NOT retried and NOT undone. The
     * authoritative change stands, the card is spent, and the outcome says in
     * words that Mesh may keep showing the old date while the PSA enforces the
     * new one. A second PATCH cannot make that truer, and a remove-plus-re-add
     * of a live allow rule is not an edit.
     *
     * @return array<string, mixed>
     */
    private function executeEditAllowRule(
        array $arguments,
        int $clientId,
        string $actorLabel,
        ?TechnicianRun $run = null,
        ?int $approverId = null,
    ): array {
        $tool = 'mesh_edit_allow_rule';

        $ruleIdForHash = is_scalar($arguments['rule_id'] ?? null) ? trim((string) $arguments['rule_id']) : 'unresolved';
        $requestedValue = is_scalar($arguments['expires_at'] ?? null) ? trim((string) $arguments['expires_at']) : 'unresolved';
        // Re-derived from the arguments, NEVER $run->content_hash — same rule
        // and same reason as the other two verbs (stagedRunSlot generations).
        $contentHash = $this->contentHash('mesh_stage_edit_allow_rule', $clientId, 'edit-allow-rule-'.$ruleIdForHash, [
            'expires_at' => $requestedValue,
        ]);

        if ($this->alreadyExecuted($tool, $clientId, $contentHash)) {
            $message = "Rule '{$ruleIdForHash}' was already set to this expiry for this client recently; no upstream call was made.";
            $this->auditAttempt($tool, 'blocked', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['success' => true, 'idempotent' => true, 'message' => $message];
        }

        // The lifetime is re-validated against NOW, not carried as a parsed
        // value: a date that was in the future when the card was staged and is
        // in the past when the button is pressed would make the rule reapable
        // on the spot, and the approver did not consent to an immediate
        // removal.
        if (! array_key_exists('expires_at', $arguments)) {
            $message = 'The approved proposal carries no expires_at; nothing was changed. Stage a new proposal.';
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['error' => $message];
        }
        $expiry = $this->requestedExpiry($arguments, absentIsPermanent: false);
        if (isset($expiry['error'])) {
            $message = $expiry['error'].' No upstream call was made and nothing was changed; stage a new proposal with a valid expiry.';
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['error' => $message];
        }
        $expiresAt = $expiry['expires_at'];

        $target = $this->editAllowRuleTarget($arguments, $clientId);
        if (isset($target['error'])) {
            $message = $target['error'].' (re-checked at approval; nothing was changed)';
            $this->auditAttempt($tool, 'rejected', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['error' => $message];
        }

        $record = $target['record'];
        $previous = $record->expires_at;

        // Answered from the row, not only from the dedup window above: a
        // duplicate card approved after that window has closed would otherwise
        // send a second PATCH for a lifetime the PSA already holds. Nothing to
        // enforce differently means nothing to send.
        if (self::sameInstant($previous, $expiresAt)) {
            $message = "Rule '{$target['rule_id']}' (sender '{$target['sender']}') already ".self::expiryPhrase($previous).' in the PSA; this proposal changes nothing and no PATCH was sent.';
            $this->auditAttempt($tool, 'blocked', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

            return ['success' => true, 'idempotent' => true, 'message' => $message];
        }

        $transition = self::expiryPhrase($previous).' -> '.self::expiryPhrase($expiresAt);

        // THE change. Saved before anything is sent upstream, so that a PATCH
        // that hangs, throws or is refused cannot leave the approver with a
        // card that says "changed" over a lifetime the reaper never saw.
        $record->forceFill(['expires_at' => $expiresAt])->save();

        $patchError = null;
        try {
            // Null is sent EXPLICITLY here (patchRule keeps the key): on a
            // partial update an omitted field is "leave unchanged", and a rule
            // made permanent must stop displaying a date it no longer has.
            $this->client->patchRule($target['rule_id'], ['date_expiry' => $expiresAt?->toIso8601String()]);
        } catch (MeshClientException $e) {
            // Status only (C-56): this text reaches the approver and the audit row.
            $patchError = $e->statusPhrase('the PATCH');
        }

        $readError = null;
        $after = null;
        try {
            $after = $this->client->findRuleById($target['mesh_customer_id'], $target['rule_id']);
        } catch (MeshClientException $e) {
            // Status only (C-56): this text reaches the approver and the audit row.
            $readError = $e->statusPhrase('the rule list read');
        }

        $displayAgrees = $after !== null && self::displayAgrees($after, $expiresAt);

        if ($displayAgrees) {
            $summary = "Changed the expiry of Mesh allow rule '{$target['rule_id']}' (sender '{$target['sender']}') for this client: {$transition}. "
                ."PSA record #{$record->id} now carries the new expiry and the PSA enforces it; Mesh re-read under the same id and displays it."
                .($patchError !== null ? ' The PATCH call itself did not answer cleanly ('.$patchError.'), but the re-read shows the new date.' : '');

            $this->auditAttempt($tool, 'executed', $clientId, null, $contentHash, $summary, $actorLabel, $run?->id, $approverId);

            return [
                'success' => true,
                'rule_id' => $target['rule_id'],
                'sender' => $target['sender'],
                'psa_record_id' => $record->id,
                'previous_expires_at' => self::expiryValue($previous),
                'expires_at' => self::expiryValue($expiresAt),
                'display_synced' => true,
                'message' => $summary,
            ];
        }

        // Everything below is a FAULT on the DISPLAY side only. The
        // authoritative change is already saved and is not undone: an error
        // would release the claim and invite a second PATCH, which is the one
        // thing this path must never do.
        if ($after === null && $readError === null) {
            // The id no longer resolves on this tenant after the PATCH. Either
            // Mesh re-keyed the rule or the rule is gone; both look the same
            // from here, and neither is measured further. The recorded id is
            // CLEARED so the reaper falls back to the sender+comment lookup
            // when the new expiry comes due — a stale id would 404 on its
            // DELETE, be read as absent, and retire a rule that may still be
            // live under another id. The comment is why that fallback works,
            // and why this verb never edits it.
            $fault = 'display_id_lost';
            $record->forceFill(['mesh_rule_id' => null])->save();
            $message = "The PSA now enforces the new expiry for allow rule '{$target['rule_id']}' (sender '{$target['sender']}'): {$transition}. "
                ."But after the update Mesh no longer returns a rule under id '{$target['rule_id']}' on this tenant, so whether the portal shows the new date could not be confirmed and the upstream side was NOT retried. "
                .'Mesh may keep showing the old expiry while the PSA enforces the new one. '
                // #5491: only a DATED record is ever due (scopeReapable needs
                // a non-null expiry), so only that arm may promise the expiry
                // job's re-identification; the permanent arm says what is
                // actually true of an id-less permanent record. Only an ACTIVE
                // one is never visited: scopeUnsettledUnexpired() selects a
                // permanent UNRESOLVED or REAP_FAILED row, and
                // MeshAllowRuleReaper::settleUnexpired() writes back an id it
                // finds by sender and comment, after which both verbs reach it.
                // Writing the id back does NOT change the row's state: the
                // unsettled brake (unsettledAllowRule()) selects on state alone,
                // settleUnexpired() never settles a reap_failed row, and an
                // UNRESOLVED row that had an id to edit is scope-unproved, so it
                // is never settled either. The text therefore says the id only
                // makes the record reachable, and that the block lasts until a
                // removal is proved against it or it is cleared by hand.
                .($expiresAt === null
                    ? ($record->state === MeshAllowRule::STATE_ACTIVE
                        ? "PSA record #{$record->id} keeps the new expiry; its recorded rule id was cleared. The record is now PERMANENT, so the expiry job never visits it, and with no recorded id neither mesh_remove_allow_rule nor mesh_edit_allow_rule can reach it. "
                            .'Check the rule in the Mesh portal; until someone clears this PSA record by hand, it blocks new allow rules for this sender.'
                        : "PSA record #{$record->id} keeps the new expiry; its recorded rule id was cleared. The record is now PERMANENT (state '{$record->state}'), so the expiry job never removes it, and while it has no recorded id neither mesh_remove_allow_rule nor mesh_edit_allow_rule can reach it. "
                            ."The expiry job does look a record in this state up by sender and comment: if exactly one rule in Mesh matches, it records that rule's id here again, which only makes the record reachable by both verbs; it does not lift the block. "
                            .'This record keeps blocking new allow rules for this sender until the PSA proves a removal against it (an approved mesh_remove_allow_rule, once the id is back) or someone clears it by hand. Check the rule in the Mesh portal.')
                    // #5563: reapOne() TRIES the sender+comment match; when
                    // nothing matches it records the row unresolved instead.
                    // #5642: resolveRuleId() takes the FIRST listed match
                    // (findRuleByComment), and an unreadable list marks the
                    // row reap_failed (markFailed()). #5657: an unresolved or
                    // reap_failed row refuses a new allow rule at APPROVAL
                    // (unsettledAllowRule()); staging is not refused by it
                    // once the dedup window has passed.
                    : "PSA record #{$record->id} keeps the new expiry; its recorded rule id was cleared, so when it is due the expiry job tries to re-identify the rule by sender and comment and remove it (if more than one rule in Mesh matches, it takes the first one Mesh lists). "
                        .'If no rule in Mesh matches then, the record is marked unresolved; if the rule list cannot be read, it is marked reap_failed and the removal is retried. Either way a new allow rule for this sender is then refused when it is approved. Check the rule in the Mesh portal.');
        } elseif ($after === null) {
            $fault = 'display_unmeasured';
            $message = "The PSA now enforces the new expiry for allow rule '{$target['rule_id']}' (sender '{$target['sender']}'): {$transition}. "
                ."Whether Mesh displays it could NOT be measured — the confirming read failed ({$readError}) — and the upstream side was NOT retried. "
                .'Mesh may keep showing the old expiry while the PSA enforces the new one. Check the rule in the Mesh portal.';
        } else {
            $fault = 'display_unsynced';
            $shown = self::upstreamExpiry($after);
            $message = "The PSA now enforces the new expiry for allow rule '{$target['rule_id']}' (sender '{$target['sender']}'): {$transition}. "
                .'But Mesh did not take the display update: re-read under the same id, it still shows '
                .(self::upstreamExpiryUnreadable($after)
                    ? 'an expiry this system cannot read'
                    : ($shown === null ? 'no expiry' : 'an expiry of '.$shown->toDayDateTimeString().' UTC'))
                .', and the upstream side was NOT retried. Mesh will keep showing the old date while the PSA enforces the new one.';
        }
        $message .= ($patchError !== null ? ' The PATCH call reported: '.$patchError.'.' : '');

        $this->auditAttempt($tool, 'executed_with_fault', $clientId, null, $contentHash, $message, $actorLabel, $run?->id, $approverId);

        return [
            'success' => true,
            'fault' => $fault,
            'rule_id' => $target['rule_id'],
            'sender' => $target['sender'],
            'psa_record_id' => $record->id,
            'previous_expires_at' => self::expiryValue($previous),
            'expires_at' => self::expiryValue($expiresAt),
            'display_synced' => false,
            'message' => $message,
        ];
    }

    /**
     * The expiry Mesh displays on a rule row, as an instant — or null when it
     * shows none, or shows something this system cannot read (which is not
     * agreement with anything).
     */
    private static function upstreamExpiry(array $row): ?\Illuminate\Support\Carbon
    {
        $value = $row['date_expiry'] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse(trim((string) $value))->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Is the expiry Mesh displays PRESENT but unreadable — a value this system
     * cannot turn into an instant (an unparseable string, or a shape that is
     * not a scalar at all)?
     *
     * upstreamExpiry() answers null for both "Mesh shows no date" and "Mesh
     * shows something we cannot read", and those are not the same fact: the
     * first is a measurement, the second is the absence of one. Collapsed
     * together, an unreadable value would read as agreement with a PERMANENT
     * edit — the one edit that leaves the hole open forever — so the two are
     * separated here and unable-to-assess fails closed.
     *
     * @param  array<string, mixed>  $row
     */
    private static function upstreamExpiryUnreadable(array $row): bool
    {
        $value = $row['date_expiry'] ?? null;

        if ($value === null) {
            return false;
        }

        // A nested object or list where a date belongs is not a date we failed
        // to parse; it is a shape we cannot read at all.
        if (! is_scalar($value)) {
            return true;
        }

        // An empty string is Mesh stating no expiry, the same as an absent key.
        if (trim((string) $value) === '') {
            return false;
        }

        return self::upstreamExpiry($row) === null;
    }

    /** Two lifetimes are the same when both are permanent or both name the same instant. */
    private static function sameInstant(?\Illuminate\Support\Carbon $a, ?\Illuminate\Support\Carbon $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }

        return $a->equalTo($b);
    }

    /**
     * Does the row Mesh returns after the PATCH display the lifetime that was
     * asked for? Permanent must read back as no expiry. A dated lifetime is
     * matched to the DAY, not the second: the field is display-only, the
     * precision Mesh stores and echoes is not measured, and a portal column
     * showing the right day is what the PATCH exists to achieve. A different
     * day, or a date where none was asked for, is the fault.
     */
    private static function displayAgrees(array $row, ?\Illuminate\Support\Carbon $expiresAt): bool
    {
        // Unable-to-assess is a refusal, never a pass: a value we cannot read
        // is not evidence that Mesh displays the lifetime that was asked for,
        // and it is emphatically not agreement with a PERMANENT edit.
        if (self::upstreamExpiryUnreadable($row)) {
            return false;
        }

        $shown = self::upstreamExpiry($row);

        if ($expiresAt === null || $shown === null) {
            return $expiresAt === null && $shown === null;
        }

        return $shown->isSameDay($expiresAt);
    }

    /**
     * dxWX6IBS: READ one client's Mesh allow rules, joined to the PSA's own
     * mesh_allow_rules records, plus the PSA records that are not settled.
     *
     * Read-only by construction: the only upstream call is
     * MeshWriteClient::listCustomerRules() (GET, paged), and nothing here
     * writes a PSA row or an action-log attempt. The tenant is the PSA
     * client's stored mesh_customer_id; no argument can name another one.
     *
     * Upstream row fields used (MeshWriteClient's measured representation —
     * see its class docblock and listCustomerRules()/rowBelongsTo()): `id`,
     * `sender`, `ab` (true = allow, MeshWriteClient::ALLOW_RULE), `active`,
     * `comment`, `created_by`, `date_expiry`. No creation-date key has been
     * measured on this route, so none is named: every scalar `date_*` key
     * other than `date_expiry` is passed through verbatim under `mesh_dates`,
     * and the PSA's own record time is `psa_recorded_at`.
     *
     * @return array<string, mixed>
     */
    private function listAllowRules(array $arguments, ?int $clientId): array
    {
        if ($refused = $this->refusedArgumentKeys(self::LIST_TOOL, $arguments)) {
            return ['error' => 'These parameters are not accepted by '.self::LIST_TOOL.': '.implode('; ', $refused).'. Nothing was read.'];
        }

        $client = $clientId !== null && $clientId > 0 ? Client::find($clientId) : null;
        if (! $client) {
            return ['error' => 'Client not found'];
        }

        $tenant = trim((string) ($client->mesh_customer_id ?? ''));
        if ($tenant === '') {
            return ['error' => "{$client->name} has no Mesh customer mapping; link the client to its Mesh tenant before listing its allow rules. Nothing was read."];
        }

        $filter = null;
        if (array_key_exists('sender', $arguments)) {
            $filter = $this->senderFilter($arguments['sender']);
            if (isset($filter['error'])) {
                return ['error' => $filter['error']];
            }
        }

        try {
            $rows = $this->client->listCustomerRules($tenant);
        } catch (MeshClientException $e) {
            // The vendor's message and body never reach the caller: a Guzzle
            // message can quote the response body, and a 400 carries the
            // vendor's own text. The status code is the whole report.
            $status = (int) $e->getCode();
            $what = $status > 0
                ? "Mesh answered the rule list read with HTTP {$status}"
                : 'the rule list read failed without an HTTP status from Mesh';

            return ['error' => "{$client->name}'s Mesh allow rules could not be read: {$what}. This is not an empty list; retry, and report it if it persists."];
        }

        // The reaper works each record against its OWN mesh_customer_id
        // (MeshAllowRuleReaper::resolveRuleId()), whichever client holds it,
        // not any client's current mapping. So the join is keyed on that
        // tenant alone: a record another client stored against this tenant
        // before a remap still decides whether a rule here is reaped, and a
        // record this client stored against another tenant never does.
        // psa_client_id says whose record it is.
        $records = MeshAllowRule::query()
            ->where('mesh_customer_id', $tenant)
            ->orderByDesc('id')
            ->get();

        // mesh_edit_allow_rule and mesh_remove_allow_rule do not use that join.
        // They look the record up by this client + rule id
        // (editAllowRuleTarget(), removeAllowRuleTarget()). The same lookup is
        // reported per rule, so a caller can tell when those verbs will call a
        // rule foreign even though the join above found a record for it.
        $heldRuleIds = MeshAllowRule::query()
            ->where('client_id', $client->id)
            ->whereNotNull('mesh_rule_id')
            ->pluck('mesh_rule_id')
            ->map(fn ($id): string => (string) $id)
            ->flip();

        // Unsettled records are selected more widely than the join: every one
        // of this client's under ANY tenant, because the approval brake
        // (unsettledAllowRule()) refuses on client + sender alone and a scope
        // fault stores the tenant Mesh attested rather than this one; plus any
        // client's record stored against the listed tenant.
        $unsettledRecords = MeshAllowRule::query()
            ->whereIn('state', [MeshAllowRule::STATE_UNRESOLVED, MeshAllowRule::STATE_REAP_FAILED])
            ->where(fn ($q) => $q->where('client_id', $client->id)->orWhere('mesh_customer_id', $tenant))
            ->orderByDesc('id')
            ->get();

        $rules = [];
        $blockRules = 0;
        $matchedRecordIds = [];

        foreach ($rows as $row) {
            $ab = $row['ab'] ?? null;
            if ($ab === false) {
                $blockRules++;

                continue;
            }

            $sender = is_scalar($row['sender'] ?? null) ? mb_strtolower(trim((string) $row['sender'])) : '';
            if ($filter !== null && ! $this->senderMatches($sender, $filter)) {
                continue;
            }

            $ruleId = is_scalar($row['id'] ?? null) && trim((string) $row['id']) !== '' ? trim((string) $row['id']) : null;
            $comment = is_scalar($row['comment'] ?? null) ? trim((string) $row['comment']) : '';
            [$record, $matchedBy] = $this->psaRecordFor($records, $ruleId, $sender, $comment);
            if ($record !== null) {
                $matchedRecordIds[$record->id] = true;
            }

            $rules[] = $this->ruleView($row, $ruleId, $sender, $comment, $record, $matchedBy, $ruleId !== null && $heldRuleIds->has($ruleId));
        }

        $unsettled = [];
        foreach ($unsettledRecords as $record) {
            if (! in_array($record->state, [MeshAllowRule::STATE_UNRESOLVED, MeshAllowRule::STATE_REAP_FAILED], true)) {
                continue;
            }
            if ($filter !== null && ! $this->senderMatches(mb_strtolower(trim((string) $record->sender)), $filter)) {
                continue;
            }

            $unsettled[] = [
                'psa_record_id' => $record->id,
                'psa_client_id' => $record->client_id,
                'stored_under_listed_tenant' => trim((string) $record->mesh_customer_id) === $tenant,
                'state' => $record->state,
                'sender' => (string) $record->sender,
                'rule_id' => $record->mesh_rule_id,
                'seen_in_mesh_list' => isset($matchedRecordIds[$record->id]),
                'scope_proved' => (bool) $record->scope_proved,
                'permanent' => $record->expires_at === null,
                'expires_at' => self::expiryValue($record->expires_at),
                'ticket_id' => $record->ticket_id,
                'technician_run_id' => $record->technician_run_id,
                // The stored text can hold a vendor exception message (URL,
                // response body); only whether one is recorded is reported.
                'last_error_recorded' => $record->last_error !== null && trim((string) $record->last_error) !== '',
                'psa_recorded_at' => $record->created_at?->toIso8601String(),
            ];
        }

        return [
            'client_id' => $client->id,
            'client_name' => (string) $client->name,
            'sender_filter' => $filter === null ? null : ['value' => $filter['value'], 'match' => $filter['mode']],
            'rule_count' => count($rules),
            'rules' => $rules,
            'block_rules_not_listed' => $blockRules,
            'unsettled_psa_records' => $unsettled,
        ];
    }

    /**
     * The PSA record for one upstream row: by upstream rule id first; failing
     * that, a record stored against the listed tenant with NO recorded rule id whose sender and
     * comment both match (the identity findRuleByComment() resolves on), so a
     * PSA-created rule whose id never resolved is not shown as foreign.
     *
     * @param  \Illuminate\Support\Collection<int, MeshAllowRule>  $records  newest first
     * @return array{0: MeshAllowRule|null, 1: string|null}
     */
    private function psaRecordFor($records, ?string $ruleId, string $sender, string $comment): array
    {
        if ($ruleId !== null) {
            $byId = $records->first(fn (MeshAllowRule $r): bool => $r->mesh_rule_id !== null && trim((string) $r->mesh_rule_id) === $ruleId);
            if ($byId !== null) {
                return [$byId, 'rule_id'];
            }
        }

        if ($sender === '' || $comment === '') {
            return [null, null];
        }

        $byComment = $records->first(fn (MeshAllowRule $r): bool => $r->mesh_rule_id === null
            && strcasecmp(trim((string) $r->sender), $sender) === 0
            && strcasecmp(trim((string) $r->comment), $comment) === 0);

        return $byComment !== null ? [$byComment, 'sender_and_comment'] : [null, null];
    }

    /** @return array<string, mixed> */
    private function ruleView(array $row, ?string $ruleId, string $sender, string $comment, ?MeshAllowRule $record, ?string $matchedBy, bool $heldByThisClient): array
    {
        $ab = $row['ab'] ?? null;
        $displayed = $row['date_expiry'] ?? null;

        // The PSA's reaper is the only thing that removes a rule automatically (Mesh's
        // date_expiry is display-only, measured 2026-09-01), and it works only
        // rows with an expiry in one of these states (MeshAllowRule::scopeReapable).
        $reapedByPsa = $record !== null
            && $record->expires_at !== null
            && in_array($record->state, [MeshAllowRule::STATE_ACTIVE, MeshAllowRule::STATE_UNRESOLVED, MeshAllowRule::STATE_REAP_FAILED], true);

        $dates = [];
        foreach ($row as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'date_') && $key !== 'date_expiry' && (is_scalar($value) || $value === null)) {
                $dates[$key] = $value;
            }
        }

        return [
            'rule_id' => $ruleId,
            'sender' => $sender,
            'scope' => $sender === '' ? 'unknown' : (str_contains($sender, '@') ? 'address' : 'domain'),
            'action' => $ab === true ? 'allow' : 'unknown',
            'active' => is_bool($row['active'] ?? null) ? $row['active'] : null,
            'permanent' => ! $reapedByPsa,
            'expires_at' => $reapedByPsa ? self::expiryValue($record->expires_at) : null,
            'mesh_displayed_expiry' => is_scalar($displayed) && trim((string) $displayed) !== '' ? trim((string) $displayed) : null,
            'comment' => $comment,
            'created_by' => is_scalar($row['created_by'] ?? null) && trim((string) $row['created_by']) !== '' ? trim((string) $row['created_by']) : null,
            'mesh_dates' => $dates,
            'psa_created' => $record !== null,
            'psa_match' => $matchedBy,
            'psa_record_id' => $record?->id,
            'psa_client_id' => $record?->client_id,
            'psa_record_held_by_this_client' => $heldByThisClient,
            'psa_state' => $record?->state,
            'ticket_id' => $record?->ticket_id,
            'technician_run_id' => $record?->technician_run_id,
            'psa_recorded_at' => $record?->created_at?->toIso8601String(),
        ];
    }

    /**
     * The `sender` filter. An input with a local part (`a@b.example`) is an
     * EXACT address match; a bare domain (`b.example`) or `@b.example` is a
     * DOMAIN match: a rule for that domain itself, or for any address whose
     * domain is exactly it (no subdomains). Both are case-insensitive.
     *
     * @return array{value?: string, mode?: string, error?: string}
     */
    private function senderFilter(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return ['error' => 'sender must be a non-empty email address or domain. Nothing was read.'];
        }

        $value = mb_strtolower(trim($value));
        if (mb_strlen($value) > 255 || preg_match('/[\s,;*]/', $value) === 1 || substr_count($value, '@') > 1) {
            return ['error' => 'sender must be one email address or one domain (no wildcards, lists or spaces). Nothing was read.'];
        }

        if (str_starts_with($value, '@')) {
            $value = substr($value, 1);
        }

        if (str_contains($value, '@')) {
            return ['value' => $value, 'mode' => 'exact_address'];
        }

        return $value === ''
            ? ['error' => 'sender must be a non-empty email address or domain. Nothing was read.']
            : ['value' => $value, 'mode' => 'domain'];
    }

    /** @param  array{value: string, mode: string}  $filter */
    private function senderMatches(string $sender, array $filter): bool
    {
        if ($filter['mode'] === 'exact_address') {
            return $sender === $filter['value'];
        }

        return $sender === $filter['value'] || str_ends_with($sender, '@'.$filter['value']);
    }

    /** @return array<string, mixed> */
    private static function listAllowRulesTool(): array
    {
        return self::tool(
            self::LIST_TOOL,
            'Read ONE customer tenant\'s Mesh Email Security allow rules, resolved server-side from the PSA client. READ-ONLY: it lists rules and changes nothing in Mesh or the PSA. '
            .'Per rule: rule_id (what mesh_edit_allow_rule and mesh_remove_allow_rule take), sender, scope (address or domain), action, active, comment, created_by, '
            .'permanent / expires_at, the expiry Mesh displays, any other date_* fields Mesh returns (verbatim, under mesh_dates), and whether the PSA created it '
            .'(psa_created, with the PSA record stored against this tenant: its psa_client_id, ticket_id, technician_run_id and state). '
            .'psa_record_held_by_this_client says whether this client holds a PSA record with this rule_id, which is the lookup mesh_edit_allow_rule and mesh_remove_allow_rule use. When it is false, mesh_edit_allow_rule refuses the rule as foreign even where psa_created is true (a record another client stored against this tenant, or one matched by sender and comment), and mesh_remove_allow_rule does not close any PSA record with it: its card calls the rule FOREIGN, unless exactly one of this client\'s records on this tenant with no recorded rule id, and not removed or reaped, matches it by sender and comment, in which case the card names that record and says it stays open. '
            .'permanent is true unless the PSA holds a record with an expiry that its reaper still works; Mesh does not expire rules itself, so a rule the PSA did not create is permanent whatever date Mesh displays. '
            .'Block rules are not listed (only counted). Also returns, as unsettled_psa_records, the PSA records that are unresolved or reap_failed: every one of this client\'s under any Mesh tenant, and any client\'s stored against this tenant (psa_client_id and stored_under_listed_tenant say which). '
            .'A Mesh read that fails (an HTTP error or no answer) is returned as an error, never as an empty list. Requires an explicit grant.',
            [
                'sender' => [
                    'type' => 'string',
                    'description' => 'Optional filter. An email address (name@example.com) matches that exact sender only. '
                        .'A bare domain (example.com) or @example.com matches a rule for that domain itself and every address rule at exactly that domain (not subdomains). Case-insensitive.',
                ],
            ],
            [],
        );
    }

    /** @return array<string, mixed> */
    private static function addAllowRuleTool(): array
    {
        return self::tool(
            'mesh_add_allow_rule',
            'Allow mail from one sender (a single address, or a whole domain) through Mesh Email Security for ONE customer tenant, resolved server-side from the PSA client. '
            .'STAGED ONLY: every call is held as a cockpit approval proposal. There is no immediate implementation — a bare (immediate) grant is refused with a pointer to `mesh_add_allow_rule:staged`. '
            .'This WEAKENS the customer’s mail filtering for that sender. It is allow-only, never partner-wide, never connection-level (`edge`). '
            .'The rule is PERMANENT unless you give `expires_at`: omit it (or pass "'.self::EXPIRY_NEVER.'") and the rule has no automatic expiry; it stays until someone removes it (`mesh_remove_allow_rule`) or gives it a date (`mesh_edit_allow_rule`). '
            .'For a temporary rule, give an ISO-8601 date or datetime; the PSA removes the rule after it, because Mesh does not expire its own rules. '
            .'An expiry that is empty, cannot be read, or is already in the past is refused rather than defaulted. '
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
            .'This WEAKENS the customer’s mail filtering for that sender (allow-only, never partner-wide, never `edge`) PERMANENTLY unless `expires_at` gives a date: '
            .'with a date the PSA removes the rule after it; omitted, or "'.self::EXPIRY_NEVER.'", there is no automatic expiry and it stays until someone removes it (`mesh_remove_allow_rule`) or gives it a date (`mesh_edit_allow_rule`). '
            .'The proposal names the sender, the scope width (single address vs whole domain), the chosen lifetime in words (including PERMANENT when there is no expiry), and whose identity Mesh will record as the rule’s creator. '
            .'Requires a ticket, reason, typed domain confirmation, explicit grant, kill-switch and identical-content dedup. No staging cooldown: distinct senders may be staged back-to-back.',
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
                'description' => 'Optional. Give an ISO-8601 date or datetime (e.g. 2026-12-01 or 2026-12-01T17:00:00Z) to make this a TEMPORARY rule that the PSA removes after that moment. '
                    .'Omit it, or give the word "'.self::EXPIRY_NEVER.'", for a PERMANENT rule with no automatic expiry: it stays until someone removes it (`mesh_remove_allow_rule`) or gives it a date (`mesh_edit_allow_rule`). '
                    .'A value that is empty, cannot be read, or is a date already in the past is refused — it is never rounded to a default. '
                    .'A permanent rule leaves a lasting hole in this customer’s mail filtering, and the approver is shown it as PERMANENT.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function removeAllowRuleTool(): array
    {
        return self::tool(
            'mesh_remove_allow_rule',
            'Remove ONE Mesh Email Security allow rule from a customer tenant, resolved server-side from the PSA client. This RESTORES filtering for the sender the rule was allowing. '
            .'STAGED ONLY: every call is held as a cockpit approval proposal. There is no immediate implementation — a bare (immediate) grant is refused with a pointer to `mesh_remove_allow_rule:staged`. '
            .'The rule is resolved only within this client’s own tenant, so an id belonging to another customer simply does not resolve. '
            .'ALLOW-ONLY: a rule Mesh reports as a BLOCK rule is refused, and so is one whose type Mesh does not state. '
            .'It removes rules the PSA created AND rules it did not; a rule the PSA holds no record of under its id is labelled FOREIGN on the approval card, because removing it may break something a human set up on purpose. '
            .'If exactly one PSA record with no recorded rule id (not removed or reaped) matches the rule by sender and comment, the card names that record instead, keeps that warning, and says the record is NOT closed by the removal. '
            .'Success is proved by re-reading the rule and requiring a 404 — a rule still readable afterwards, or one whose absence cannot be measured, is reported as a fault and never as done. '
            .'Requires reason, ticket_id, and the rule’s sender typed back as confirmation.',
            self::removeAllowRuleProperties(),
            ['rule_id', 'confirm_sender', 'reason', 'ticket_id'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageRemoveAllowRuleTool(): array
    {
        return self::tool(
            'mesh_stage_remove_allow_rule',
            'Stage the removal of a Mesh Email Security allow rule for cockpit approval. STAGED ONLY — this is the only lane the verb has: approval re-resolves the client’s Mesh tenant and re-checks the rule’s ownership, its allow/block type and the typed sender confirmation against LIVE state before anything is deleted. '
            .'The proposal names the sender the rule allows, how wide it is (one address vs a whole domain), whether the rule is PSA-TRACKED, FOREIGN, or matched by sender and comment to a PSA record with no recorded rule id (which the removal does not close), the expiry Mesh displays, the Mesh comment, and who Mesh records as its creator. '
            .'Removing an allow rule STRENGTHENS filtering for that sender — but a FOREIGN rule was put there by someone outside this system, and removing it can break mail that is being delivered today. '
            .'Requires a ticket, reason, the sender typed back, explicit grant, kill-switch and identical-content dedup. No staging cooldown: distinct rules may be staged back-to-back.',
            self::removeAllowRuleProperties(),
            ['rule_id', 'confirm_sender', 'reason', 'ticket_id'],
        );
    }

    /** @return array<string, mixed> */
    private static function removeAllowRuleProperties(): array
    {
        return [
            'rule_id' => [
                'type' => 'string',
                'description' => 'The Mesh rule id to remove. Read it from this client’s own allow rules (or from a PSA mesh_allow_rules record). '
                    .'An id that does not belong to this client’s Mesh tenant will not resolve, and no partner-wide or global rule is reachable.',
            ],
            'confirm_sender' => [
                'type' => 'string',
                'description' => 'Typed confirmation of the sender this rule allows. Must match exactly (case-insensitive) what Mesh holds for the rule, or the removal is refused. '
                    .'This is the check that catches a valid id pasted for the wrong rule — read the rule before you type it.',
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'Specific operational reason for removing this allow rule.',
            ],
            'ticket_id' => [
                'type' => 'integer',
                'description' => 'PSA ticket this removal belongs to. The proposal is staged against it and the ticket reference is recorded in the PSA audit row.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function editAllowRuleTool(): array
    {
        return self::tool(
            'mesh_edit_allow_rule',
            'Change the expiry of ONE Mesh Email Security allow rule the PSA created, for a customer tenant resolved server-side from the PSA client. '
            .'STAGED ONLY: every call is held as a cockpit approval proposal. There is no immediate implementation — a bare (immediate) grant is refused with a pointer to `mesh_edit_allow_rule:staged`. '
            .'ONE FIELD: `expires_at` (an ISO-8601 date or datetime, or "'.self::EXPIRY_NEVER.'" for a rule with no automatic expiry) is the only thing this verb changes, and it is required. '
            .'The PSA enforces the expiry — Mesh only displays one — so the change is made to the PSA record first and Mesh is then asked to show the same date; if Mesh does not keep it, the outcome says so and the PSA still enforces the new date. '
            .'PSA-TRACKED RULES ONLY: a rule the PSA did not create (FOREIGN) has no enforced expiry to edit and is refused with the reason. '
            .'The sender, the comment (which is how the PSA identifies the rule), the allow/block type and the scope are never changed; changing the sender is a removal plus a new rule. '
            .'Requires reason, ticket_id, and the rule’s sender typed back as confirmation.',
            self::editAllowRuleProperties(),
            ['rule_id', 'confirm_sender', 'expires_at', 'reason', 'ticket_id'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageEditAllowRuleTool(): array
    {
        return self::tool(
            'mesh_stage_edit_allow_rule',
            'Stage an expiry change for a PSA-created Mesh Email Security allow rule for cockpit approval. STAGED ONLY — this is the only lane the verb has: approval re-resolves the client’s Mesh tenant, re-checks the rule’s ownership, its allow/block type, the typed sender confirmation and the PSA record against LIVE state, and re-validates the date, before anything is written. '
            .'ONE FIELD: `expires_at` is the only thing this verb changes — the sender, the comment (which is how the PSA identifies the rule), the allow/block type and the scope are never touched. '
            .'The proposal names the sender the rule allows, how wide it is, the expiry the PSA currently enforces, the new expiry in words (including PERMANENT when there is no expiry), and that Mesh’s own Expires column is display only. '
            .'Extending or removing an expiry LENGTHENS the hole in this customer’s mail filtering. '
            .'PSA-TRACKED RULES ONLY: a FOREIGN rule (one the PSA did not create) has no PSA-enforced expiry to change and is refused with the reason. '
            .'Requires a ticket, reason, the sender typed back, an explicit expires_at, explicit grant, kill-switch and identical-content dedup. No staging cooldown: distinct rules may be staged back-to-back.',
            self::editAllowRuleProperties(),
            ['rule_id', 'confirm_sender', 'expires_at', 'reason', 'ticket_id'],
        );
    }

    /** @return array<string, mixed> */
    private static function editAllowRuleProperties(): array
    {
        return [
            'rule_id' => [
                'type' => 'string',
                'description' => 'The Mesh rule id to edit. Read it from this client’s own allow rules (or from a PSA mesh_allow_rules record). '
                    .'An id that does not belong to this client’s Mesh tenant will not resolve, and no partner-wide or global rule is reachable.',
            ],
            'confirm_sender' => [
                'type' => 'string',
                'description' => 'Typed confirmation of the sender this rule allows. Must match exactly (case-insensitive) what Mesh holds for the rule, or the edit is refused. '
                    .'This is the check that catches a valid id pasted for the wrong rule — read the rule before you type it.',
            ],
            'expires_at' => [
                'type' => 'string',
                'description' => 'Required. The new expiry: an ISO-8601 date or datetime (e.g. 2026-12-01 or 2026-12-01T17:00:00Z), '
                    .'or the word "'.self::EXPIRY_NEVER.'" for a rule that NEVER expires: it stays until someone removes it (`mesh_remove_allow_rule`) or gives it another date with this verb. '
                    .'A value that cannot be read, or a date already in the past, is refused. There is no default — an edit must say what it changes the expiry to. '
                    .'Choose "'.self::EXPIRY_NEVER.'" deliberately: it leaves a permanent hole in this customer’s mail filtering, and the approver is shown it as PERMANENT.',
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'Specific operational reason for changing when this allow rule stops applying.',
            ],
            'ticket_id' => [
                'type' => 'integer',
                'description' => 'PSA ticket this edit belongs to. The proposal is staged against it and the ticket reference is recorded in the PSA audit row.',
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
