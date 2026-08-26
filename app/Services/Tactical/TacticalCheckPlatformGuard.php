<?php

namespace App\Services\Tactical;

use App\Models\TacticalAsset;
use App\Models\TacticalScript;

/**
 * The MANDATORY platform-safety gate for Tactical check creation (psa-0pb9m
 * revise). A wrong-platform check fails on 100% of runs forever and
 * manufactures broken coverage — the exact defect this bead removes — so the
 * invariant is enforced where every check creation converges: the
 * TacticalClient TRANSPORT itself (post() asserts this guard on every
 * request that resolves to checks/ — the psa-mocr choke-point rule;
 * createCheck() is the named front door that delegates there). R5 proved
 * that enforcing only inside createCheck() left the generic public post()
 * as a second write seam a raw caller could drive with no evidence.
 * Per-surface pre-checks (StaffTacticalAdminToolExecutor,
 * TacticalMacosCheckProvisioner) remain as defence in depth and produce the
 * friendlier audited refusals; THIS gate is what makes bypass impossible for
 * any future caller.
 *
 * EVIDENCE, NEVER ASSERTION (psa-0pb9m R3): every input to the safety
 * decision is resolved INSIDE this boundary from server-derived state — the
 * synced local snapshot/catalog or a live read over the same client. There is
 * deliberately NO parameter through which a caller can supply script metadata,
 * platform claims, or membership facts: R2/R3 proved that any caller-assertable
 * claim (acknowledge_platform_risk, a scriptMeta array) is retried or
 * fabricated by an AI caller and reopens the original defect.
 *
 * Fail-closed rules (each refusal names its remedy):
 *  - Payload targeting BOTH an agent and a policy → refused. The upstream
 *    Check model holds both nullable FKs with no XOR validation (checks/
 *    models.py @ 632a37a4), so such a row lands in BOTH the agent's and the
 *    policy's check lists while safety was proven for only one target.
 *  - AGENT target with an UNKNOWN platform → refused. An agent's platform is
 *    knowable — sync it (tactical:sync-devices). No override: guessing here
 *    recreates the original bug. A snapshot older than
 *    FRESH_EVIDENCE_MAX_HOURS (or never stamped) does not count as knowing:
 *    it is resolved LIVE at this boundary, and a failed or unresolvable live
 *    read refuses (psa-ou9pe — stale evidence is not evidence).
 *  - AGENT target, non-script check on a darwin/linux agent → refused.
 *    Tactical macOS/Linux agents run SCRIPT checks only (vendor constraint,
 *    also documented on the provisioner) — a non-script check there never
 *    reports and reads as broken coverage.
 *  - Script whose metadata cannot be resolved from a server-derived source,
 *    OR carries no usable platform signal (no shell AND no
 *    supported_platforms) → refused. Resolution order: the local synced
 *    catalog (tactical:sync-scripts / the provisioner's post-create upsert)
 *    when its row is within FRESH_EVIDENCE_MAX_HOURS, then a live getScripts
 *    read over this client for a not-yet-synced OR stale-cataloged script
 *    (psa-ou9pe: an unboundedly old row must not outvote an upstream edit).
 *    Caller claims are not a source. Absence of constraints is NOT
 *    compatibility: treating an empty claim as "runs anywhere" is exactly how
 *    a wrong-platform always-failing check ships (psa-0pb9m R2).
 *  - AGENT target with a provably incompatible script → refused, no override.
 *  - POLICY target, a check whose check_type IS 'script' and whose script
 *    DECLARES supported_platforms that are ALL exact vendor platform tokens
 *    (windows/darwin/linux — upstream matching is case-sensitive membership,
 *    so a mis-cased or unknown token is delivered to ZERO agents and waives
 *    nothing) and are ALL platforms the script's own shell can run on
 *    (scoping delivers the check to every DECLARED platform, so a declared
 *    platform the shell cannot run on receives a check that fails on every
 *    run) → allowed WITHOUT membership proof; anything else — a shell-only
 *    script, a mis-declared one, or a non-script check carrying a script id
 *    — stays on the proof path. Tactical scopes policy script-check
 *    delivery per member agent by the script's own supported_platforms:
 *    generate_checks / delivered check lists include a policy script check
 *    only when agent.is_supported_script(script.supported_platforms) — read
 *    at the deployed v1.5.1 server (automation/models.py, agents/models.py
 *    is_supported_script, 2026-08-25) — so a member on an undeclared
 *    platform NEVER RECEIVES the check; the server itself withholds it.
 *    Requiring an all-compatible membership on top of that was refusing
 *    writes the vendor already renders safe (Charlie's ruling 2026-08-25:
 *    "Tactical Option A" — platform scoping is the script's own
 *    supported_platforms' job on mixed-platform policies).
 *  - POLICY target whose check could not run on some platform AND is not
 *    delivery-scoped upstream — a SHELL-ONLY script (empty
 *    supported_platforms is delivered to EVERY member: `... if platforms
 *    else True`), or ANY non-script check (Windows-only per the vendor
 *    constraint above) — → allowed ONLY on SERVER-DERIVED
 *    MEMBERSHIP PROOF: the policy's current membership is resolved live from
 *    Tactical (GET automation/policies/{pk}/related/ + the fleet agents
 *    list) and every member agent must be on a provably compatible platform.
 *    Any member on a blocked platform, any member whose platform cannot be
 *    resolved, any failure to enumerate membership, or any STRUCTURALLY
 *    INCOMPLETE membership payload → refused. A 200 response missing the
 *    serializer's fields is drift/degradation, and absence of proof is never
 *    zero members (psa-0pb9m R3: related={} previously proved true with
 *    members_checked=0).
 *
 * Membership resolution (producers read at amidaware/tacticalrmm 632a37a4,
 * 2026-07-24 — cite, don't guess; captured in
 * tests/Fixtures/tactical/upstream_producers.json):
 *  - GET automation/policies/{pk}/related/ → PolicyRelatedSerializer
 *    (automation/serializers.py:41-89): direct `agents`
 *    ({id, hostname, agent_id, client, site} per AgentHostnameSerializer,
 *    agents/serializers.py:190-203), `workstation_clients`/`server_clients`
 *    (ClientMinimumSerializer — all Client fields incl. `name`),
 *    `workstation_sites`/`server_sites` (SiteMinimumSerializer — all Site
 *    fields incl. `name` + `client_name`), plus `is_default_server_policy` /
 *    `is_default_workstation_policy` (SerializerMethodFields returning real
 *    booleans). All seven fields are emitted on EVERY healthy response — the
 *    five collections as JSON lists, the two flags as JSON booleans — so this
 *    guard REQUIRES that exact runtime shape and refuses anything less
 *    (see REQUIRED_RELATED_LIST_FIELDS / REQUIRED_RELATED_FLAG_FIELDS, which
 *    TacticalSchemaDriftTest proves against the captured producer).
 *  - GET agents/ → AgentTableSerializer rows carrying `agent_id`, `plat`,
 *    `operating_system`, `monitoring_type`, `client_name`, `site_name`.
 *    When the related payload contains a client/site/default assignment, the
 *    fleet rows are the ONLY join evidence, so every row must carry the join
 *    keys that assignment needs (FLEET_JOIN_FIELDS subset) — a row missing
 *    them could belong to the policy invisibly, which means membership cannot
 *    be completely enumerated → refused.
 *  - Composition OVER-approximates Policy.related_agents()
 *    (automation/models.py:91+): upstream subtracts excluded agents/sites/
 *    clients and block_policy_inheritance; we do not, so our member set is a
 *    superset — strictly more refusals, never fewer (fail-closed).
 *  - The proof covers membership AS OF the write. Agents added to the policy
 *    later are not covered — the allow-note says so.
 *
 * Throws TacticalClientException so existing caller error paths surface the
 * refusal; nothing is sent upstream on refusal.
 */
class TacticalCheckPlatformGuard
{
    /**
     * How old locally-synced write evidence — the agent-platform snapshot
     * and the script-catalog row — may be and still authorize a check write
     * on its own (psa-ou9pe: the guard previously accepted UNBOUNDEDLY stale
     * local rows as write authorization, so an arbitrarily old darwin
     * snapshot authorized a check on an agent long since reimaged to
     * Windows, and an arbitrarily old catalog row outvoted an upstream
     * script edit). Both feeds sync DAILY (tactical:sync-devices 05:32,
     * tactical:sync-scripts 05:35), so 48h — the same one-missed-daily-cycle
     * threshold the read surfaces use for their staleness flags — keeps the
     * healthy pipeline on the zero-HTTP local path while a row the pipeline
     * has demonstrably stopped refreshing is demoted to LIVE resolution at
     * this boundary: the live answer governs, and a failed or unresolvable
     * live read REFUSES with a recovery instruction. A never-stamped row
     * (synced_at null) is age-unknown, and unknown age is never fresh.
     */
    public const FRESH_EVIDENCE_MAX_HOURS = 48;

    /** Refusals name at most this many offending member hostnames. */
    private const MAX_NAMED_MEMBERS = 5;

    /**
     * PolicyRelatedSerializer fields the membership proof REQUIRES at runtime
     * as JSON lists. Proven against the captured vendor producer by
     * TacticalSchemaDriftTest — one list, enforced here, pinned there.
     *
     * @var string[]
     */
    public const REQUIRED_RELATED_LIST_FIELDS = [
        'agents',
        'workstation_clients',
        'server_clients',
        'workstation_sites',
        'server_sites',
    ];

    /**
     * PolicyRelatedSerializer fields the membership proof REQUIRES at runtime
     * as JSON booleans. Same drift-test binding as the list fields.
     *
     * @var string[]
     */
    public const REQUIRED_RELATED_FLAG_FIELDS = [
        'is_default_server_policy',
        'is_default_workstation_policy',
    ];

    /**
     * Fleet (agents-list) keys the client/site/default membership joins read.
     * When such an assignment exists, every fleet row must carry the keys that
     * join needs, or membership cannot be completely enumerated.
     *
     * @var string[]
     */
    public const FLEET_JOIN_FIELDS = [
        'monitoring_type',
        'client_name',
        'site_name',
    ];

    /**
     * @param  array<string, mixed>  $payload  The POST checks/ body about to be sent.
     * @param  TacticalClient  $client  Used ONLY for read calls (script
     *                                  metadata for a not-yet-synced script;
     *                                  policy membership proof); never to
     *                                  write.
     *
     * @throws TacticalClientException on refusal — nothing was sent upstream.
     */
    public static function assertSafe(array $payload, TacticalClient $client): void
    {
        $agentId = isset($payload['agent']) && is_scalar($payload['agent']) ? trim((string) $payload['agent']) : '';
        $policyId = isset($payload['policy']) && is_numeric($payload['policy']) ? (int) $payload['policy'] : null;

        if ($agentId !== '' && $policyId !== null) {
            throw new TacticalClientException(
                'Refusing to create this check: the payload targets BOTH an agent and a policy. The upstream Check model '
                .'accepts either foreign key with no exactly-one validation, so such a row would appear in both the '
                ."agent's and the policy's check lists while platform safety was proven for only one of them (psa-0pb9m). "
                .'Create two separate checks, one per target.'
            );
        }

        if ($agentId === '' && $policyId === null) {
            throw new TacticalClientException(
                'Refusing to create this check: the payload targets neither an agent nor a policy, so platform safety cannot be assessed.'
            );
        }

        $checkType = isset($payload['check_type']) && is_scalar($payload['check_type'])
            ? mb_strtolower(trim((string) $payload['check_type']))
            : '';
        $isScriptCheck = $checkType === 'script' || isset($payload['script']);

        if ($agentId !== '') {
            self::assertAgentTargetSafe($agentId, $isScriptCheck, $payload, $client);

            return;
        }

        self::assertPolicyTargetSafe((int) $policyId, $isScriptCheck, $payload, $client);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function assertAgentTargetSafe(string $agentId, bool $isScriptCheck, array $payload, TacticalClient $client): void
    {
        $platform = self::resolveAgentPlatform($agentId, $client);

        if (! $isScriptCheck) {
            if ($platform !== TacticalPlatform::WINDOWS) {
                throw new TacticalClientException(
                    "Refusing to create a '{$payload['check_type']}' check on agent '{$agentId}': Tactical {$platform} agents "
                    .'run SCRIPT checks only (vendor constraint) — a non-script check there never reports and reads as broken coverage (psa-0pb9m).'
                );
            }

            return;
        }

        $meta = self::resolveScriptMeta($payload, $client);

        $incompatibility = TacticalPlatform::scriptIncompatibility(
            $platform,
            $meta['shell'],
            $meta['supported_platforms'],
        );

        if ($incompatibility !== null) {
            throw new TacticalClientException(
                "Refusing to create this check: {$incompatibility}. It would fail on every run on agent '{$agentId}' "
                .'and register as broken coverage (psa-0pb9m). Use a script compatible with the agent platform instead.'
            );
        }
    }

    /**
     * The agent's platform, resolved from evidence that is either FRESH or
     * LIVE — never from an unboundedly stale snapshot (psa-ou9pe):
     *
     *  - No local snapshot at all → refused (unchanged psa-0pb9m behaviour):
     *    the platform is knowable — sync it.
     *  - Snapshot synced within FRESH_EVIDENCE_MAX_HOURS → it authorizes
     *    exactly as before (zero HTTP); a fresh row that still resolves no
     *    platform keeps the unknown-platform refusal (a live re-read of the
     *    same vendor fields the sync just wrote would answer no better).
     *  - Snapshot STALER than the bound (or never stamped) → the platform is
     *    resolved LIVE from the vendor's own agent detail
     *    (GET agents/{id}/ — the same plat/operating_system fields
     *    TacticalDeviceSyncService::syncDeviceDetail() consumes), with
     *    bounded fail-closed validation: a failed read, or a payload from
     *    which TacticalPlatform::fromAgentPayload() resolves nothing,
     *    REFUSES with a recovery instruction. The stale row itself is never
     *    fallen back to — the agent may have been reimaged since it was
     *    written, which is exactly the wrong-platform vector this guard
     *    exists to close.
     */
    private static function resolveAgentPlatform(string $agentId, TacticalClient $client): string
    {
        $local = TacticalAsset::where('agent_id', $agentId)->first();

        if ($local === null) {
            // Fail CLOSED on the unknown: an unknown platform is precisely the
            // state in which the original always-failing check was attached.
            throw new TacticalClientException(
                "Refusing to create this check: the platform of agent '{$agentId}' is unknown to the PSA "
                .'(no synced platform on the local snapshot). Run tactical:sync-devices to resolve it, then retry — '
                .'creating a check against an unknown platform is how a wrong-platform always-failing check ships (psa-0pb9m).'
            );
        }

        if (self::isFreshEvidence($local->synced_at)) {
            $platform = $local->platform();

            if ($platform === null) {
                throw new TacticalClientException(
                    "Refusing to create this check: the platform of agent '{$agentId}' is unknown to the PSA "
                    .'(no synced platform on the local snapshot). Run tactical:sync-devices to resolve it, then retry — '
                    .'creating a check against an unknown platform is how a wrong-platform always-failing check ships (psa-0pb9m).'
                );
            }

            return $platform;
        }

        try {
            $live = $client->getAgent($agentId);
        } catch (\Throwable $e) {
            throw new TacticalClientException(
                "Refusing to create this check: the local platform snapshot for agent '{$agentId}' is stale "
                .'(last synced more than '.self::FRESH_EVIDENCE_MAX_HOURS.' hours ago, or never), and the live Tactical '
                .'agent read that would replace it failed ('.$e::class.'), so the agent\'s CURRENT platform cannot be '
                .'verified. Run tactical:sync-devices, then retry — stale platform evidence must not authorize a check '
                .'write: the agent may have been reimaged since the snapshot (psa-ou9pe).'
            );
        }

        $platform = TacticalPlatform::fromAgentPayload(
            is_scalar($live['plat'] ?? null) ? (string) $live['plat'] : null,
            is_scalar($live['operating_system'] ?? null) ? (string) $live['operating_system'] : null,
        );

        if ($platform === null) {
            throw new TacticalClientException(
                "Refusing to create this check: the local platform snapshot for agent '{$agentId}' is stale "
                .'(last synced more than '.self::FRESH_EVIDENCE_MAX_HOURS.' hours ago, or never), and the live Tactical '
                .'agent read carries no resolvable platform (no usable plat/operating_system — a drifted or degraded '
                .'response), so the agent\'s CURRENT platform cannot be verified. Run tactical:sync-devices and verify '
                .'the agent in Tactical, then retry (psa-ou9pe).'
            );
        }

        return $platform;
    }

    /**
     * Whether a locally-synced row is recent enough to authorize a check
     * write on its own (see FRESH_EVIDENCE_MAX_HOURS). Null — never stamped —
     * is age-unknown, and unknown age is never fresh.
     */
    private static function isFreshEvidence(?\Carbon\CarbonInterface $syncedAt): bool
    {
        return $syncedAt !== null && $syncedAt->gt(now()->subHours(self::FRESH_EVIDENCE_MAX_HOURS));
    }

    /**
     * A policy-target check is allowed only when its platform demands are
     * proven against the policy's CURRENT membership (server-derived, never
     * caller-asserted).
     *
     * @param  array<string, mixed>  $payload
     */
    private static function assertPolicyTargetSafe(int $policyId, bool $isScriptCheck, array $payload, TacticalClient $client): void
    {
        // A payload is classified as a script check whenever it carries a
        // script id, but only a check whose declared check_type IS 'script'
        // is delivery-scoped upstream: non-script checks are not filtered by
        // the script's supported_platforms at all (PING is not filtered even
        // by platform), so a ping-with-a-script-id payload still owes the
        // all-Windows vendor constraint — the delivery-scoping waiver must
        // not reopen the psa-0pb9m R2 non-script bypass.
        $checkType = isset($payload['check_type']) && is_scalar($payload['check_type'])
            ? mb_strtolower(trim((string) $payload['check_type']))
            : '';
        $isDeliverableScriptCheck = $checkType === 'script';

        $blocked = [];

        if ($isScriptCheck) {
            $meta = self::resolveScriptMeta($payload, $client);

            // The compatibility math runs FIRST: delivery scoping only helps
            // where the script can actually RUN. Tactical scopes policy
            // script-check delivery per member agent by the script's own
            // supported_platforms (is_supported_script — read at the deployed
            // v1.5.1 server), so members on undeclared platforms never receive
            // it; but it DELIVERS to every declared platform, so a script
            // declaring a platform its own shell cannot run on lands exactly
            // where it fails on every run. A shell-only script gets no scoping
            // at all (empty supported_platforms delivers everywhere), and an
            // unknown/mis-cased token is delivered to nobody. All of those
            // fall through to the membership proof.
            $blocked = self::incompatiblePlatforms($meta['shell'], $meta['supported_platforms']);

            if ($isDeliverableScriptCheck && self::isPolicyDeliveryScoped($meta['shell'], $meta['supported_platforms'])) {
                return;
            }

            // NOT delivery-scoped, so the declared list buys nothing upstream
            // and must not stand in for the shell here: scriptIncompatibility
            // gives a non-empty supported_platforms precedence over the shell
            // ("vendor metadata vouches for this platform"), so a powershell
            // script declaring windows/darwin/linux yields NO blocked
            // platforms at all and would exit at the `$blocked === []` return
            // below with no membership proof — delivered to every member,
            // failing on every run on the darwin/linux ones. Union in the
            // SHELL's own constraints (declared list deliberately withheld)
            // so any shell-bound script that is not delivery-scoped stays on
            // the proof path wherever its shell cannot run (psa-0pb9m).
            if (is_string($meta['shell']) && trim($meta['shell']) !== '') {
                $blocked = array_values(array_unique(array_merge(
                    $blocked,
                    self::incompatiblePlatforms($meta['shell'], null),
                )));
            }
        }

        if (! $isDeliverableScriptCheck) {
            // Non-script checks exist on WINDOWS agents only (vendor
            // constraint) — on a policy they demand an all-Windows membership
            // exactly like a Windows-bound script (psa-0pb9m R2: this path
            // previously bypassed the guard entirely). A script id in the
            // payload does not make the check delivery-scoped.
            $blocked = array_values(array_unique(array_merge(
                $blocked,
                [TacticalPlatform::DARWIN, TacticalPlatform::LINUX],
            )));
        }

        if ($blocked === []) {
            return;
        }

        $proof = self::provePolicyMembership($client, $policyId, $blocked);
        if (! $proof['proven']) {
            throw new TacticalClientException(
                'Refusing to create this policy check before any write: '.$proof['reason']
                .' A check that cannot run on a member platform fails on every run there and manufactures broken coverage '
                .'(the original psa-0pb9m defect arrived through exactly this route). There is no override: for a script check, '
                .'declare the script\'s supported_platforms in Tactical (Tactical then delivers the policy check only to members '
                .'on those platforms); otherwise make the policy cover only compatible platforms, or target the compatible '
                .'agents directly (one agent-target check each).'
            );
        }
    }

    /**
     * SERVER-DERIVED membership proof: every agent the policy currently
     * reaches must be on a platform outside $blockedPlatforms. Public so the
     * MCP executor can pre-check with audited, surface-friendly copy; the
     * client-boundary guard re-asserts the same proof (defence in depth).
     *
     * Never trusts caller claims, and never treats ABSENT response data as
     * evidence (psa-0pb9m R3): the related payload must carry the vendor
     * serializer's full runtime shape (REQUIRED_RELATED_LIST_FIELDS as lists,
     * REQUIRED_RELATED_FLAG_FIELDS as booleans), assignment rows must carry
     * the keys their join needs, and — when a client/site/default assignment
     * exists — every fleet row must carry that join's keys. Anything less is
     * a drifted or degraded response, and proving "zero members" from it
     * would authorize the exact wrong-platform write this guard exists to
     * stop. Zero members is accepted ONLY from a structurally complete
     * response whose collections are genuinely empty.
     *
     * A member whose platform cannot be resolved — absent from the fleet
     * list, or a row without a usable `plat`/`operating_system` — fails the
     * proof (unknown is never compatible).
     *
     * @param  array<int, string>  $blockedPlatforms
     * @return array{proven: bool, reason: ?string, members_checked: int}
     */
    public static function provePolicyMembership(TacticalClient $client, int $policyId, array $blockedPlatforms): array
    {
        try {
            $related = $client->getAutomationPolicyRelated($policyId);
            $fleet = $client->getAgents();
        } catch (\Throwable $e) {
            return [
                'proven' => false,
                'reason' => "the membership of policy {$policyId} could not be read from Tactical (".$e::class.'), so platform compatibility cannot be verified.',
                'members_checked' => 0,
            ];
        }

        $shapeError = self::relatedShapeError($related, $policyId) ?? self::fleetShapeError($related, $fleet, $policyId);
        if ($shapeError !== null) {
            return ['proven' => false, 'reason' => $shapeError, 'members_checked' => 0];
        }

        $members = self::resolveMembers($related, $fleet);
        if (isset($members['error'])) {
            return ['proven' => false, 'reason' => $members['error'], 'members_checked' => 0];
        }

        $offending = [];
        $unresolved = [];
        foreach ($members['agents'] as $member) {
            $platform = TacticalPlatform::fromAgentPayload(
                is_scalar($member['plat'] ?? null) ? (string) $member['plat'] : null,
                is_scalar($member['operating_system'] ?? null) ? (string) $member['operating_system'] : null,
            );

            if ($platform === null) {
                $unresolved[] = (string) ($member['hostname'] ?? $member['agent_id'] ?? 'unknown-agent');
            } elseif (in_array($platform, $blockedPlatforms, true)) {
                $offending[] = (string) ($member['hostname'] ?? $member['agent_id'] ?? 'unknown-agent')." ({$platform})";
            }
        }

        if ($offending !== []) {
            return [
                'proven' => false,
                'reason' => 'this check cannot run on '.implode('/', $blockedPlatforms)." agents, and policy {$policyId}'s current membership includes "
                    .count($offending).' such agent(s): '.self::nameSome($offending).'.',
                'members_checked' => count($members['agents']),
            ];
        }

        if ($unresolved !== []) {
            return [
                'proven' => false,
                'reason' => 'the platform of '.count($unresolved)." member agent(s) of policy {$policyId} could not be resolved from the Tactical fleet list ("
                    .self::nameSome($unresolved).') — unknown is never compatible.',
                'members_checked' => count($members['agents']),
            ];
        }

        return ['proven' => true, 'reason' => null, 'members_checked' => count($members['agents'])];
    }

    /**
     * Structural validation of the policies/{pk}/related/ payload against the
     * vendor serializer's runtime shape. A healthy PolicyRelatedSerializer
     * response ALWAYS carries the five collections as lists (of objects) and
     * the two default-policy flags as booleans; a 200 missing any of them is
     * drift or degradation, and absence of proof is never zero members
     * (psa-0pb9m R3: related={} previously proved true, members_checked=0).
     *
     * @param  array<string, mixed>  $related
     */
    private static function relatedShapeError(array $related, int $policyId): ?string
    {
        foreach (self::REQUIRED_RELATED_LIST_FIELDS as $field) {
            if (! array_key_exists($field, $related)) {
                return "the membership payload of policy {$policyId} (GET automation/policies/{$policyId}/related/) is missing `{$field}`, "
                    .'which the vendor serializer always emits — a drifted or degraded response proves nothing about membership, and absence of proof is never zero members.';
            }
            if (! is_array($related[$field])) {
                return "the membership payload of policy {$policyId} carries `{$field}` as ".get_debug_type($related[$field])
                    .' where the vendor serializer emits a list — a drifted or degraded response; membership cannot be proven.';
            }
            foreach ($related[$field] as $row) {
                if (! is_array($row)) {
                    return "a `{$field}` assignment row of policy {$policyId} is not an object (".get_debug_type($row)
                        .') — a drifted or degraded response; membership cannot be completely enumerated.';
                }
            }
        }

        foreach (self::REQUIRED_RELATED_FLAG_FIELDS as $field) {
            if (! array_key_exists($field, $related)) {
                return "the membership payload of policy {$policyId} is missing `{$field}`, which the vendor serializer always emits "
                    .'as a boolean — a drifted or degraded response; whether this is a fleet-default policy cannot be determined.';
            }
            if (! is_bool($related[$field])) {
                return "the membership payload of policy {$policyId} carries `{$field}` as ".get_debug_type($related[$field])
                    .' where the vendor serializer emits a boolean — a drifted or degraded response; whether this is a fleet-default policy cannot be determined.';
            }
        }

        return null;
    }

    /**
     * Structural validation of the fleet list AS JOIN EVIDENCE. Runs after
     * relatedShapeError, so $related is known well-formed. Every fleet row
     * must be an object; and when the policy has client/site/default
     * assignments, every fleet row must carry the join keys those assignments
     * enumerate through — a row missing them could belong to the policy
     * invisibly. An EMPTY fleet is accepted as evidence only when the local
     * synced snapshot agrees the fleet is empty; zero rows while the snapshot
     * knows agents is a degraded read wearing a 200.
     *
     * @param  array<string, mixed>  $related
     * @param  array<int, mixed>  $fleet
     */
    private static function fleetShapeError(array $related, array $fleet, int $policyId): ?string
    {
        foreach ($fleet as $row) {
            if (! is_array($row)) {
                return 'a row of the Tactical fleet list (GET agents/) is not an object ('.get_debug_type($row)
                    .") — a drifted or degraded response; policy {$policyId}'s membership cannot be completely enumerated.";
            }
        }

        $requiredKeys = [];
        if ($related['is_default_server_policy'] === true || $related['is_default_workstation_policy'] === true) {
            $requiredKeys['monitoring_type'] = 'default-policy';
        }
        if ($related['workstation_clients'] !== [] || $related['server_clients'] !== []) {
            $requiredKeys['monitoring_type'] = $requiredKeys['monitoring_type'] ?? 'client';
            $requiredKeys['client_name'] = 'client';
        }
        if ($related['workstation_sites'] !== [] || $related['server_sites'] !== []) {
            $requiredKeys['monitoring_type'] = $requiredKeys['monitoring_type'] ?? 'site';
            $requiredKeys['client_name'] = $requiredKeys['client_name'] ?? 'site';
            $requiredKeys['site_name'] = 'site';
        }

        if ($requiredKeys === []) {
            return null; // only direct-agent assignments (or none) — no fleet join needed
        }

        if ($fleet === []) {
            $known = TacticalAsset::query()->count();
            if ($known > 0) {
                return "the Tactical fleet list (GET agents/) returned zero agents while the local synced snapshot knows {$known} — "
                    ."a degraded or drifted read, not an empty fleet, so policy {$policyId}'s "
                    .implode('/', array_unique(array_values($requiredKeys))).' assignment(s) cannot be enumerated. '
                    .'If agents were genuinely removed, run tactical:sync-devices and retry.';
            }

            return null; // fleet genuinely empty on both live and synced evidence — the assignments reach nobody
        }

        foreach ($fleet as $row) {
            foreach ($requiredKeys as $key => $assignmentKind) {
                $value = $row[$key] ?? null;
                if (! is_scalar($value) || trim((string) $value) === '') {
                    $who = is_scalar($row['hostname'] ?? null) ? (string) $row['hostname'] : 'unknown hostname';

                    return "a Tactical fleet row ({$who}) is missing `{$key}`, so policy {$policyId}'s {$assignmentKind} assignment(s) "
                        .'cannot be completely enumerated — an agent could belong to this policy invisibly, and absent keys are never evidence.';
                }
                // EXACT vendor vocabulary, because the membership joins compare
                // raw (===): a 'Server'/' server' row would pass a folded
                // validation yet silently escape every join — the precise
                // invisible-member hole this validation exists to close.
                if ($key === 'monitoring_type' && ! in_array($value, ['server', 'workstation'], true)) {
                    $who = is_scalar($row['hostname'] ?? null) ? (string) $row['hostname'] : 'unknown hostname';

                    return "a Tactical fleet row ({$who}) carries monitoring_type '".trim((string) $value)
                        ."' where the vendor emits exactly server|workstation — such a row would silently escape policy {$policyId}'s "
                        .'membership joins, so membership cannot be completely enumerated.';
                }
            }
        }

        return null;
    }

    /**
     * Compose the policy's current member agents from the (shape-validated)
     * related payload + fleet list. Over-approximates upstream
     * related_agents() (exclusions and block_policy_inheritance are ignored —
     * a superset is fail-closed).
     *
     * @param  array<string, mixed>  $related
     * @param  array<int, mixed>  $fleet
     * @return array{agents: array<int, array<string, mixed>>}|array{error: string}
     */
    private static function resolveMembers(array $related, array $fleet): array
    {
        /** @var array<int, array<string, mixed>> $fleetRows (shape-validated: every row is an array) */
        $fleetRows = array_values($fleet);
        $byAgentId = [];
        foreach ($fleetRows as $row) {
            if (is_scalar($row['agent_id'] ?? null)) {
                $byAgentId[(string) $row['agent_id']] = $row;
            }
        }

        $members = [];
        $fallbackKey = 0;

        // Default policies reach the whole fleet of that monitoring type.
        if ($related['is_default_server_policy'] === true) {
            foreach ($fleetRows as $row) {
                if (($row['monitoring_type'] ?? null) === 'server') {
                    $members[self::memberKey($row, $fallbackKey)] = $row;
                }
            }
        }
        if ($related['is_default_workstation_policy'] === true) {
            foreach ($fleetRows as $row) {
                if (($row['monitoring_type'] ?? null) === 'workstation') {
                    $members[self::memberKey($row, $fallbackKey)] = $row;
                }
            }
        }

        // Directly-assigned agents ({agent_id, hostname, …}).
        foreach ($related['agents'] as $direct) {
            $directId = is_scalar($direct['agent_id'] ?? null) && trim((string) $direct['agent_id']) !== ''
                ? (string) $direct['agent_id']
                : null;
            $directName = is_scalar($direct['hostname'] ?? null) ? (string) $direct['hostname'] : 'unknown hostname';
            if ($directId === null) {
                return ['error' => "a directly-assigned member agent of this policy ({$directName}) carries no agent_id — "
                    .'a drifted or degraded row; it cannot be resolved against the fleet list, and absent keys are never evidence.'];
            }
            if (! isset($byAgentId[$directId])) {
                return ['error' => "a directly-assigned member agent of this policy ({$directName}) is missing from the "
                    .'Tactical fleet list, so its platform cannot be resolved — unknown is never compatible.'];
            }
            $members[$directId] = $byAgentId[$directId];
        }

        // Client- and site-scoped assignment, per monitoring type. Names are
        // the join key the vendor exposes on both sides (client_name/site_name
        // on AgentTableSerializer; name/client_name on the minimum
        // serializers).
        // Join values must be NON-BLANK scalars, exactly as fleetShapeError
        // demands of the fleet side (psa-0pb9m R4 A5): a blank assignment
        // `name` is present-but-empty evidence — it matches no fleet row, so
        // the assignment's members silently fall out of the proven set and a
        // blocked-platform fleet "proves" zero-member compatibility.
        foreach (['workstation_clients' => 'workstation', 'server_clients' => 'server'] as $key => $monType) {
            foreach ($related[$key] as $clientRow) {
                $clientName = is_scalar($clientRow['name'] ?? null) && trim((string) $clientRow['name']) !== ''
                    ? (string) $clientRow['name']
                    : null;
                if ($clientName === null) {
                    return ['error' => "a {$monType}-client assignment of this policy carries no usable `name` (missing or blank), so its member agents "
                        .'cannot be resolved — a blank join value matches nothing and would silently shrink the proven member set; '
                        .'absent or blank keys are never evidence.'];
                }
                foreach ($fleetRows as $row) {
                    if (($row['client_name'] ?? null) === $clientName && ($row['monitoring_type'] ?? null) === $monType) {
                        $members[self::memberKey($row, $fallbackKey)] = $row;
                    }
                }
            }
        }
        foreach (['workstation_sites' => 'workstation', 'server_sites' => 'server'] as $key => $monType) {
            foreach ($related[$key] as $siteRow) {
                $siteName = is_scalar($siteRow['name'] ?? null) && trim((string) $siteRow['name']) !== ''
                    ? (string) $siteRow['name']
                    : null;
                $siteClient = is_scalar($siteRow['client_name'] ?? null) && trim((string) $siteRow['client_name']) !== ''
                    ? (string) $siteRow['client_name']
                    : null;
                if ($siteName === null || $siteClient === null) {
                    return ['error' => "a {$monType}-site assignment of this policy carries no usable `name`/`client_name` (missing or blank), so its member agents "
                        .'cannot be resolved — a blank join value matches nothing and would silently shrink the proven member set; '
                        .'absent or blank keys are never evidence.'];
                }
                foreach ($fleetRows as $row) {
                    if (($row['site_name'] ?? null) === $siteName
                        && ($row['client_name'] ?? null) === $siteClient
                        && ($row['monitoring_type'] ?? null) === $monType) {
                        $members[self::memberKey($row, $fallbackKey)] = $row;
                    }
                }
            }
        }

        return ['agents' => array_values($members)];
    }

    /**
     * Dedup key for one fleet row. agent_id when present; otherwise a
     * guaranteed-unique fallback so a keyless row still COUNTS as a member
     * (dropping or colliding it would shrink the proven set — fail-open).
     *
     * @param  array<string, mixed>  $row
     */
    private static function memberKey(array $row, int &$fallbackKey): string
    {
        if (is_scalar($row['agent_id'] ?? null) && trim((string) $row['agent_id']) !== '') {
            return (string) $row['agent_id'];
        }

        return 'keyless-'.(++$fallbackKey);
    }

    /** @param array<int, string> $names */
    private static function nameSome(array $names): string
    {
        $shown = array_slice($names, 0, self::MAX_NAMED_MEMBERS);
        $more = count($names) - count($shown);

        return implode(', ', $shown).($more > 0 ? " (+{$more} more)" : '');
    }

    /**
     * The platforms this script provably cannot (or very likely cannot) run
     * on, per the shared TacticalPlatform rules (vendor supported_platforms
     * metadata first, definitive shell heuristics second).
     *
     * WRITE-ORIENTED, so signal-less input REFUSES instead of widening to []
     * (psa-0pb9m R4 A4/S6): every caller treats the returned [] as "no
     * platform is blocked — no membership proof required", which is a claim
     * of UNIVERSAL compatibility. Metadata that says nothing cannot make that
     * claim; mapping NULL shell + no supported_platforms to [] would readmit
     * absent-data-as-proof one layer below the boundary that just removed it.
     * Callers must establish a usable signal first (hasUsablePlatformSignal)
     * or catch the refusal; the read-side annotation that may honestly answer
     * "unknown" is TacticalPlatform::checkScriptMismatch, not this.
     *
     * @param  array<int, mixed>|null  $supportedPlatforms
     * @return array<int, string>
     *
     * @throws TacticalClientException when the metadata carries no usable platform signal.
     */
    public static function incompatiblePlatforms(?string $shell, ?array $supportedPlatforms): array
    {
        if (! self::hasUsablePlatformSignal($shell, $supportedPlatforms)) {
            throw new TacticalClientException(
                'Refusing to assess platform compatibility: this script carries neither a shell nor any supported_platforms, '
                .'so its platform constraints cannot be verified — absence of metadata is not compatibility, and an empty '
                .'blocked-platform list here would read as "compatible with everything" (psa-0pb9m). '
                .'Re-run tactical:sync-scripts, or verify the script in Tactical.'
            );
        }

        return array_values(array_filter(
            [TacticalPlatform::WINDOWS, TacticalPlatform::DARWIN, TacticalPlatform::LINUX],
            fn (string $platform): bool => TacticalPlatform::scriptIncompatibility($platform, $shell, $supportedPlatforms) !== null,
        ));
    }

    /**
     * Script metadata for the payload's script id, resolved INSIDE the
     * boundary from server-derived sources only — the local synced catalog
     * first (tactical:sync-scripts, or the provisioner's post-create upsert),
     * then a live getScripts read over this client for a script the catalog
     * has not synced yet. There is deliberately no way for a caller to supply
     * this: R3 proved a caller-supplied metadata array is an assertion, not
     * evidence — a fabricated cross-platform claim for a Windows-only script
     * sailed straight to POST (psa-0pb9m R3 A3/S3).
     *
     * In EVERY case the resolved metadata must carry a usable platform
     * signal — a non-empty shell or a non-empty supported_platforms list.
     * Metadata that says nothing is not "no constraints": treating absence as
     * compatibility is how a wrong-platform always-failing check ships.
     *
     * @param  array<string, mixed>  $payload
     * @return array{shell: ?string, supported_platforms: ?array<int, mixed>}
     */
    private static function resolveScriptMeta(array $payload, TacticalClient $client): array
    {
        $scriptId = isset($payload['script']) && is_numeric($payload['script']) ? (int) $payload['script'] : null;
        if ($scriptId === null || $scriptId <= 0) {
            throw new TacticalClientException(
                'Refusing to create this script check: the payload carries no numeric script id, so the script\'s platform '
                .'constraints cannot be verified (psa-0pb9m).'
            );
        }

        $local = TacticalScript::where('tactical_script_id', $scriptId)->first();
        $staleLocal = $local !== null && ! self::isFreshEvidence($local->synced_at);

        if ($local !== null && ! $staleLocal) {
            $resolved = [
                'shell' => is_string($local->shell) && trim($local->shell) !== '' ? $local->shell : null,
                'supported_platforms' => is_array($local->supported_platforms) ? $local->supported_platforms : null,
            ];

            if (! self::hasUsablePlatformSignal($resolved['shell'], $resolved['supported_platforms'])) {
                throw new TacticalClientException(
                    "Refusing to create this script check: the synced catalog row for script {$scriptId} carries neither "
                    .'a shell nor any supported_platforms, so its platform constraints cannot be verified — absence of metadata is '
                    .'not compatibility (psa-0pb9m). Re-run tactical:sync-scripts, or verify the script in Tactical.'
                );
            }

            return $resolved;
        }

        // Not in the catalog yet (e.g. created upstream since the last sync),
        // or in it only as a row too STALE to authorize a write on its own
        // (psa-ou9pe: an unboundedly old catalog row must not outvote an
        // upstream script edit): read the vendor's own getScripts row live
        // over the same client. A failed or empty read REFUSES — never
        // degrades to a caller claim, and never falls back to the stale row.
        try {
            $upstream = $client->getScripts(true, true);
        } catch (\Throwable $e) {
            throw new TacticalClientException($staleLocal
                ? "Refusing to create this script check: the synced catalog row for script {$scriptId} is stale "
                    .'(last synced more than '.self::FRESH_EVIDENCE_MAX_HOURS.' hours ago, or never — the script may have '
                    .'been edited upstream since), and its metadata could not be read live from Tactical ('.$e::class.'), '
                    .'so its platform constraints cannot be verified. Run tactical:sync-scripts, then retry — stale catalog '
                    .'evidence must not authorize a check write (psa-ou9pe).'
                : "Refusing to create this script check: script {$scriptId} is not in the local synced script catalog, and its "
                    .'metadata could not be read live from Tactical ('.$e::class.'), so its platform constraints cannot be verified. '
                    .'Run tactical:sync-scripts, then retry — attaching a script blind is how a wrong-platform always-failing check ships (psa-0pb9m).'
            );
        }

        $row = null;
        foreach ($upstream as $candidate) {
            if (is_array($candidate) && is_numeric($candidate['id'] ?? null) && (int) $candidate['id'] === $scriptId) {
                $row = $candidate;
                break;
            }
        }

        if ($row === null) {
            throw new TacticalClientException($staleLocal
                ? "Refusing to create this script check: the synced catalog row for script {$scriptId} is stale "
                    .'(last synced more than '.self::FRESH_EVIDENCE_MAX_HOURS.' hours ago, or never), and the script is not '
                    .'visible in a live Tactical getScripts read — it may have been deleted upstream since the last sync, so '
                    .'its platform constraints cannot be verified. Run tactical:sync-scripts first (psa-ou9pe).'
                : "Refusing to create this script check: script {$scriptId} is not in the local synced script catalog and is not "
                    .'visible in Tactical getScripts, so its platform constraints cannot be verified. '
                    .'Run tactical:sync-scripts first — attaching a script blind is how a wrong-platform always-failing check ships (psa-0pb9m).'
            );
        }

        $resolved = [
            'shell' => isset($row['shell']) && is_scalar($row['shell']) && trim((string) $row['shell']) !== ''
                ? (string) $row['shell']
                : null,
            'supported_platforms' => is_array($row['supported_platforms'] ?? null) ? $row['supported_platforms'] : null,
        ];

        if (! self::hasUsablePlatformSignal($resolved['shell'], $resolved['supported_platforms'])) {
            throw new TacticalClientException(
                "Refusing to create this script check: the Tactical getScripts row for script {$scriptId} carries neither a "
                .'shell nor any supported_platforms, so its platform constraints cannot be verified — absence of metadata is '
                .'not compatibility (psa-0pb9m). Verify the script in Tactical.'
            );
        }

        return $resolved;
    }

    /**
     * Whether script metadata carries any usable platform signal — a
     * non-blank shell or at least one non-blank supported_platforms entry.
     * Public because write-side pre-checks (StaffTacticalAdminToolExecutor)
     * must refuse a signal-less row BEFORE consulting the compatibility
     * helpers, with their own audited copy (psa-0pb9m R4 A4/S6).
     *
     * @param  array<int, mixed>|null  $supportedPlatforms
     */
    public static function hasUsablePlatformSignal(?string $shell, ?array $supportedPlatforms): bool
    {
        if (is_string($shell) && trim($shell) !== '') {
            return true;
        }

        return self::hasDeclaredPlatforms($supportedPlatforms);
    }

    /**
     * Whether script metadata DECLARES at least one supported platform (a
     * non-blank supported_platforms entry). Deliberately narrower than
     * hasUsablePlatformSignal: a shell alone is a usable signal for
     * compatibility math, but it does NOT engage Tactical's per-agent policy
     * delivery scoping — the server delivers a policy script check to a
     * member only when the script's supported_platforms names the member's
     * platform, and an EMPTY list is delivered to every member
     * (agents/models.py is_supported_script: `plat in platforms if platforms
     * else True`, read at the deployed v1.5.1 server 2026-08-25). Only a
     * declared list therefore waives the policy membership proof. Public so
     * the MCP executor pre-check makes the same call with its own audited
     * copy.
     *
     * @param  array<int, mixed>|null  $supportedPlatforms
     */
    public static function hasDeclaredPlatforms(?array $supportedPlatforms): bool
    {
        foreach ($supportedPlatforms ?? [] as $platform) {
            if (is_scalar($platform) && trim((string) $platform) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether Tactical's own per-agent delivery scoping actually protects a
     * POLICY script check — the ONLY condition that waives the membership
     * proof. Deliberately narrower than hasDeclaredPlatforms, which answers
     * the weaker "is there a usable signal" question: three things must hold,
     * and each is checked because it is what makes the waiver true rather
     * than merely plausible.
     *
     *  - At least one platform is DECLARED. An empty list is delivered to
     *    EVERY member (`plat in platforms if platforms else True`).
     *  - Every declared token is one of the vendor's own platform values,
     *    matched EXACTLY as upstream matches it — `plat in platforms` is
     *    case-sensitive membership against a lowercase `plat`, so 'Windows'
     *    or 'win32' names no platform at all and the check is delivered to
     *    ZERO agents: phantom coverage, and unknown is never compatible
     *    (psa-0pb9m).
     *  - Every declared platform is one the script's SHELL can run on.
     *    Scoping delivers the check to every declared platform, so a
     *    powershell script declaring ['linux'] is delivered precisely where
     *    it fails on every run — the original broken-coverage defect.
     *
     * Public so the MCP executor pre-check makes the same call with its own
     * audited copy.
     *
     * @param  array<int, mixed>|null  $supportedPlatforms
     */
    public static function isPolicyDeliveryScoped(?string $shell, ?array $supportedPlatforms): bool
    {
        $declared = [];

        foreach ($supportedPlatforms ?? [] as $platform) {
            if (! is_scalar($platform)) {
                return false;
            }

            $token = (string) $platform;

            if (! in_array($token, [TacticalPlatform::WINDOWS, TacticalPlatform::DARWIN, TacticalPlatform::LINUX], true)) {
                return false;
            }

            $declared[] = $token;
        }

        if ($declared === []) {
            return false;
        }

        if (! is_string($shell) || trim($shell) === '') {
            return true;
        }

        foreach ($declared as $token) {
            if (TacticalPlatform::scriptIncompatibility($token, $shell, null) !== null) {
                return false;
            }
        }

        return true;
    }
}
