<?php

namespace App\Services\Mesh;

use App\Models\MeshAllowRule;
use Illuminate\Support\Facades\Log;

/**
 * #1018 criterion 3 — the thing that actually expires a Mesh allow rule.
 *
 * Mesh's `date_expiry` does not expire anything (measured 2026-09-01: a rule
 * past its expiry was still active, unmodified, and readable). The PSA is
 * therefore the enforcement point, and this reaper is it. Everything below is
 * shaped by one rule: a delete is only a reap once the rule is PROVED absent.
 *
 * The proof is a detail GET returning 404. A 200 on the DELETE is not proof —
 * it is a status code from the same call whose effect we are trying to
 * verify. And an UNMEASURABLE post-condition (timeout, 500, anything that is
 * not a clean 404) is not a pass either: those rows go to reap_failed and are
 * retried, because "we could not tell" and "it is gone" are different answers
 * and only one of them means a customer's mail filtering is back to normal.
 *
 * #1133: a rule the caller asked to be permanent has a NULL `expires_at` and
 * is never selected for reaping (see MeshAllowRule::scopeReapable). That is
 * the whole mechanism for "permanent" — there is no flag and no far-future
 * sentinel — so this class NEVER deletes such a rule; removal is by hand in
 * the Mesh portal until mesh_remove_allow_rule exists.
 *
 * Excluded from reaping is not abandoned, though. A permanent row that landed
 * unresolved (or reap_failed) is still identified here, by settlePermanent():
 * it has no expiry for anything else to wait on and there is no PSA verb to
 * clear it, so without that pass the daily command goes quiet about a rule
 * that may be live. Identifying is ALL that pass does — the row keeps its
 * fault text, stays unresolved and stays counted, because the reason it never
 * settled is that scope was never proved, and re-reading an id does not prove
 * it.
 */
class MeshAllowRuleReaper
{
    /**
     * Rows processed per run. A ceiling, not a target — each row costs a
     * DELETE plus a paged list read, and the reaper runs daily, so there is no
     * value in letting one invocation walk an unbounded backlog inside a
     * scheduled window.
     */
    public const BATCH_LIMIT = 100;

    public function __construct(private readonly MeshWriteClient $client) {}

    /**
     * Reap every eligible rule. Returns per-outcome counts.
     *
     * @return array{examined: int, reaped: int, unresolved: int, failed: int}
     */
    public function reap(): array
    {
        $counts = ['examined' => 0, 'reaped' => 0, 'unresolved' => 0, 'failed' => 0];

        if (! $this->client->isConfigured()) {
            Log::warning('[MeshAllowRuleReaper] Mesh is not configured; no allow rules were reaped. Expired rules remain live upstream.');

            return $counts;
        }

        $counts = $this->settlePermanent($counts);

        $due = MeshAllowRule::reapable()->orderBy('expires_at')->limit(self::BATCH_LIMIT)->get();

        foreach ($due as $rule) {
            $counts['examined']++;
            $outcome = $this->reapOne($rule);
            $counts[$outcome]++;
        }

        return $counts;
    }

    /**
     * Identify — never delete — permanent rules the PSA never settled.
     *
     * The id is re-resolved the same way the create path recovers it and is
     * stored, so the rule is nameable when a human (or a future removal verb)
     * comes to end it. The row NEVER goes ACTIVE. ACTIVE is the MEASURED,
     * scope-proved record liveAllowRule() answers "already allowed for this
     * client" from, and every row in this pass is here precisely because a
     * create was never scope-proved or was never answered at all; a read-back
     * cannot prove scope, because the server normalises every stored row to
     * organization_level:true. Promoting one would erase the fault text that
     * is the only record of that, and would answer every corrected re-stage
     * for this sender as an idempotent success forever.
     *
     * So the row stays UNRESOLVED and counts as unresolved either way, and
     * mesh:reap-allow-rules keeps exiting FAILURE — a permanent rule whose
     * scope was never proved may be a hole in the wrong tenant's filtering,
     * and that must not go quiet. It is NOT marked reap_failed: no reap was
     * attempted here and none ever will be.
     *
     * `examined` is untouched — these rows were not examined for reaping — so
     * the counts keep meaning what the command prints about expired rules.
     *
     * @param  array{examined: int, reaped: int, unresolved: int, failed: int}  $counts
     * @return array{examined: int, reaped: int, unresolved: int, failed: int}
     */
    private function settlePermanent(array $counts): array
    {
        $stuck = MeshAllowRule::unsettledPermanent()->orderBy('id')->limit(self::BATCH_LIMIT)->get();

        foreach ($stuck as $rule) {
            $ruleId = null;
            $note = 'Upstream rule id unresolved: no rule on this tenant matches the recorded sender and comment. This rule is PERMANENT, so it is never reaped, and the PSA keeps refusing new allow rules for this sender.';

            try {
                $ruleId = $rule->mesh_rule_id ?: $this->resolveRuleId($rule);
            } catch (MeshClientException $e) {
                $note = "Could not read the tenant's rule list to resolve the upstream id of this PERMANENT rule: {$e->getMessage()}";
            }

            if ($ruleId !== null) {
                $note = "Upstream rule id is '{$ruleId}'. An id is not scope evidence, so this PERMANENT rule stays unresolved: the PSA will never remove it, and it keeps refusing new allow rules for this sender until a human checks the rule in the Mesh portal.";
            }

            $update = [
                // NEVER active, even with an id in hand. ACTIVE is the
                // MEASURED, scope-proved record liveAllowRule() answers
                // "already allowed for this client" from, and nothing in this
                // pass proves scope — these rows exist because a create was
                // never scope-proved or never answered, and the server
                // normalises every stored row to organization_level:true, so a
                // read-back cannot tell a tenant rule from a partner-wide one.
                'state' => MeshAllowRule::STATE_UNRESOLVED,
            ];

            if ($ruleId !== null) {
                $update['mesh_rule_id'] = $ruleId;
            }

            // The row's existing last_error is the ONLY record of why it never
            // settled — a scope that was never proved, or a create that was
            // never answered — and it names what a human has to go and check.
            // Learning an id answers neither question, so this pass writes
            // here only where there is nothing to overwrite.
            if (trim((string) $rule->last_error) === '') {
                $update['last_error'] = mb_substr($note, 0, 1000);
            }

            $rule->forceFill($update)->save();

            Log::warning("[MeshAllowRuleReaper] mesh_allow_rules#{$rule->id} is permanent and unsettled; it may be live and it blocks new allow rules for this sender. {$note}");

            $counts['unresolved']++;
        }

        return $counts;
    }

    /**
     * @return 'reaped'|'unresolved'|'failed'
     */
    private function reapOne(MeshAllowRule $rule): string
    {
        try {
            $ruleId = $rule->mesh_rule_id ?: $this->resolveRuleId($rule);
        } catch (MeshClientException $e) {
            return $this->markFailed($rule, "Could not read the tenant's rule list to resolve the upstream id: {$e->getMessage()}");
        }

        if ($ruleId === null) {
            // The create was scope-proved by its 201, so the rule existed; we
            // simply cannot name it. Absence from the list read is NOT taken
            // as proof it is gone — a filtered or partial read looks identical
            // — so the row stays unresolved and stays loud (criterion 8).
            $rule->forceFill([
                'state' => MeshAllowRule::STATE_UNRESOLVED,
                'last_error' => 'Upstream rule id unresolved: no rule on this tenant matches the recorded sender and comment. The rule may still be live and cannot be reaped until it is identified.',
            ])->save();

            Log::warning("[MeshAllowRuleReaper] mesh_allow_rules#{$rule->id} is past expiry but its upstream rule id is unresolved; it cannot be reaped and may still be live.");

            return 'unresolved';
        }

        if ($rule->mesh_rule_id !== $ruleId) {
            $rule->forceFill(['mesh_rule_id' => $ruleId])->save();
        }

        try {
            $this->client->deleteRule($ruleId);
        } catch (MeshClientException $e) {
            // A delete that threw may still have landed, so absence is
            // re-measured below rather than assumed either way.
            Log::warning("[MeshAllowRuleReaper] DELETE failed for mesh_allow_rules#{$rule->id}: {$e->getMessage()}");
        }

        $absent = $this->client->ruleAbsent($ruleId);

        if ($absent !== true) {
            return $this->markFailed($rule, $absent === false
                ? 'The rule was still readable upstream after the delete; it was not reaped.'
                : 'The post-condition read could not be measured, so absence was not proved; the rule is treated as still live.');
        }

        $rule->forceFill([
            'state' => MeshAllowRule::STATE_REAPED,
            'reaped_at' => now(),
            'last_error' => null,
        ])->save();

        return 'reaped';
    }

    /**
     * Recover the upstream id by re-reading the tenant's rules and matching on
     * the recorded sender + PSA-generated comment — the same recovery the
     * create path uses, because the 201 carries no id.
     */
    private function resolveRuleId(MeshAllowRule $rule): ?string
    {
        $match = $this->client->findRuleByComment(
            (string) $rule->mesh_customer_id,
            (string) $rule->sender,
            (string) $rule->comment,
        );

        $id = $match['id'] ?? null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    private function markFailed(MeshAllowRule $rule, string $error): string
    {
        $rule->forceFill([
            'state' => MeshAllowRule::STATE_REAP_FAILED,
            'last_error' => mb_substr($error, 0, 1000),
        ])->save();

        Log::error("[MeshAllowRuleReaper] mesh_allow_rules#{$rule->id} not reaped: {$error}");

        return 'failed';
    }
}
