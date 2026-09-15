<?php

namespace App\Support;

use App\Services\Mcp\StaffCalendarToolExecutor;
use App\Services\Mcp\StaffCippWriteToolExecutor;
use App\Services\Mcp\StaffHuntressActionToolExecutor;
use App\Services\Mcp\StaffMeshAdminToolExecutor;
use App\Services\Mcp\StaffTacticalActionToolExecutor;
use App\Services\Mcp\StaffTacticalAdminToolExecutor;

/**
 * Unified staged/immediate execution modes for MCP action tools.
 *
 * Historically every stageable capability shipped as a PAIR of tools — an
 * immediate variant (send_email, tactical_run_script, …) and a staged
 * held-for-approval variant (stage_email, tactical_stage_script, …). The MCP
 * surface now exposes ONE tool per capability (the immediate name) with a
 * `staged` boolean parameter; the paired staged names survive only as thin
 * call-time aliases for older clients and stored grants. Internally the
 * executors, cockpit approval flow, cooldown keys, and audit action_type
 * values still use both names — this class owns the boundary translation.
 *
 * Token grants gain a per-tool MODE for stageable capabilities, encoded in
 * the mcp_tokens.tools entries as a `:staged` / `:immediate` suffix:
 *
 *  - `send_email:staged`    — staged-only: every call is held for cockpit
 *                             approval; staged=false is auto-downgraded.
 *  - `send_email:immediate` — immediate allowed: staged=false executes now.
 *                             Staging remains available (it is strictly the
 *                             safer path), so immediate implies staged.
 *  - `send_email`           — bare legacy entry; grants immediate, matching
 *                             what granting the immediate name meant before.
 *  - `stage_email`          — bare legacy alias entry; grants staged-only on
 *                             the send_email capability.
 *  - `x:immediate-reconsent` — immediate grant made AFTER the capability gained
 *                             an immediate lane. Only capabilities listed in
 *                             IMMEDIATE_RECONSENT_REQUIRED use this spelling,
 *                             and for those it is the ONLY entry that grants
 *                             immediate: the two legacy spellings above were
 *                             both mintable while the lane was a refusal, so
 *                             neither can be read as consent to it.
 */
class McpToolModes
{
    public const MODE_STAGED = 'staged';

    public const MODE_IMMEDIATE = 'immediate';

    /**
     * Storage spelling for an immediate grant of a capability listed in
     * IMMEDIATE_RECONSENT_REQUIRED. It is deliberately a spelling no previously
     * minted token can carry: a bare canonical entry and a `:immediate` entry were
     * both mintable while such a capability's immediate lane was a refusal, and
     * normalizeGrantEntries() stored a BARE submission as `:immediate`, so the two
     * are indistinguishable in storage and neither is evidence that an operator
     * consented to an approval-free lane that did not exist when they granted it.
     *
     * It resolves to MODE_IMMEDIATE once parsed, so the mode vocabulary every
     * consumer sees (effectiveMode, allowsImmediate, tools/list, the cockpit
     * checkbox) stays two-valued — this is a storage distinction only.
     */
    public const MODE_IMMEDIATE_RECONSENT = 'immediate-reconsent';

    /**
     * PSA-native staged aliases; the vendor families contribute theirs through
     * their executors' stagedToDirectMap() accessors.
     *
     * @var array<string, string>
     */
    private const PSA_STAGED_TO_DIRECT = [
        'stage_email' => 'send_email',
        'stage_public_note' => 'write_public_note',
        'stage_close_ticket' => 'close_ticket',
        'stage_resolve_email_item' => 'resolve_email_item',
        'propose_merge' => 'merge_ticket',
        'propose_asset_merge' => 'merge_asset',
    ];

    /**
     * Alias (staged internal name) → canonical (immediate/public name), across
     * every executor family that ships a staged twin.
     *
     * @return array<string, string>
     */
    public static function stagedToCanonical(): array
    {
        return array_merge(
            self::PSA_STAGED_TO_DIRECT,
            StaffTacticalActionToolExecutor::stagedToDirectMap(),
            StaffTacticalAdminToolExecutor::stagedToDirectMap(),
            StaffCippWriteToolExecutor::stagedToDirectMap(),
            StaffCalendarToolExecutor::stagedToDirectMap(),
            StaffHuntressActionToolExecutor::stagedToDirectMap(),
            StaffMeshAdminToolExecutor::stagedToDirectMap(),
        );
    }

    /**
     * Canonical name → staged internal (dispatch) name.
     *
     * @return array<string, string>
     */
    public static function canonicalToStaged(): array
    {
        return array_flip(self::stagedToCanonical());
    }

    /**
     * Capabilities whose IMMEDIATE lane did not exist before the staged/immediate
     * unification: merge_ticket / merge_asset shipped only as cockpit-approved
     * proposals (propose_merge / propose_asset_merge). A token that never opted
     * into immediate merging — including a full-surface token (allowedTools ===
     * null), which resolves every unlisted tool to immediate — must not acquire an
     * approval-free destructive merge just because this change landed, so these
     * default to STAGED. Immediate merging is reachable only through the explicit
     * `merge_ticket:immediate` / `merge_asset:immediate` grant, exactly as both
     * tool descriptions promise.
     *
     * Every other stageable capability keeps its legacy default (a bare grant, or
     * a full-surface token, meant immediate before this change and still does).
     *
     * @var array<int, string>
     */
    private const IMMEDIATE_REQUIRES_EXPLICIT_GRANT = [
        'resolve_email_item',
        'merge_ticket',
        'merge_asset',
        // tactical_remove_agent has no immediate implementation at all
        // (StaffTacticalAdminToolExecutor::immediateAgentRemovalRefused). It is a
        // sensitive tactical_admin tool, so a full-surface token cannot reach it
        // today either way; this entry keeps the default honest if that group is
        // ever widened, and is the second lock behind the refusal in the executor.
        'tactical_remove_agent',
        // mesh_add_allow_rule likewise has no immediate implementation
        // (StaffMeshAdminToolExecutor::immediateRefused). An allow rule
        // is a hole in a customer's mail filtering, so the entry keeps the
        // full-surface default honest and is the second lock behind the
        // refusal in the executor.
        'mesh_add_allow_rule',
        // mesh_remove_allow_rule (#1134), same construction and same second
        // lock. Removing an allow rule usually STRENGTHENS filtering, so the
        // reason it is here is the other case: the verb can also remove a rule
        // the PSA never created, which somebody outside this system put there
        // on purpose. That is a change to live mail delivery made on a
        // customer's tenant, and it is not something a full-surface token
        // should reach without a human releasing it.
        'mesh_remove_allow_rule',
        // mesh_edit_allow_rule (#1135), same construction. Extending or
        // clearing an expiry LENGTHENS a hole in a customer's mail filtering,
        // which is the create verb's reason for being here, reached later.
        'mesh_edit_allow_rule',
        // A CLIENT-scoped Tactical custom field is read by automation for every
        // agent under that client, so one write is a fleet-wide change and may be
        // a deploy trigger. Immediate execution requires an explicit grant; a
        // full-surface token must not inherit it.
        'tactical_set_client_custom_field',
    ];

    /**
     * Capabilities whose immediate lane must be granted AGAIN, after the lane
     * existed, before any token can reach it.
     *
     * IMMEDIATE_REQUIRES_EXPLICIT_GRANT above governs defaultMode() only — the
     * full-surface token that carries no per-tool entry. It says nothing about a
     * SCOPED token that already carries a bare or `:immediate` entry, and for
     * tactical_set_client_custom_field every such entry was minted while the
     * canonical verb's only behaviour was a refusal and its description read that
     * there is no immediate implementation. Landing the lane would hand those
     * tokens an approval-free, fleet-wide, deploy-triggering write nobody granted,
     * so both legacy spellings resolve to STAGED here and the immediate lane is
     * reachable only from MODE_IMMEDIATE_RECONSENT — which an operator can only
     * produce by granting immediate again, today, against the description the lane
     * actually has. Nothing is revoked: the tool stays granted in its staged lane
     * and the cockpit shows it as staged until someone re-ticks the box.
     *
     * @var array<int, string>
     */
    private const IMMEDIATE_RECONSENT_REQUIRED = [
        'tactical_set_client_custom_field',
    ];

    /** Whether an immediate grant of this capability must carry the re-consent spelling. */
    public static function requiresImmediateReconsent(string $name): bool
    {
        return in_array($name, self::IMMEDIATE_RECONSENT_REQUIRED, true);
    }

    /**
     * The mode a LEGACY immediate spelling (bare canonical, or `:immediate`)
     * resolves to: immediate exactly as before, unless the capability requires
     * re-consent — for which a legacy spelling is not consent to this lane.
     */
    private static function legacyImmediateMode(string $name): string
    {
        return self::requiresImmediateReconsent($name) ? self::MODE_STAGED : self::MODE_IMMEDIATE;
    }

    /**
     * Mode for a capability the token holds no explicit per-tool mode entry for.
     */
    public static function defaultMode(string $name): string
    {
        return in_array($name, self::IMMEDIATE_REQUIRES_EXPLICIT_GRANT, true)
            ? self::MODE_STAGED
            : self::MODE_IMMEDIATE;
    }

    /**
     * The effective mode for one capability under one token. This is the single
     * resolution point for BOTH the advertised tools/list definition and the
     * controller's call-time mode gate — the surface a token is shown and the lane
     * it can actually reach must not be able to drift apart.
     *
     * defaultMode() is the LEGACY FULL-SURFACE trust level, so it is reachable only
     * by the full-surface token (allowedTools === null). A SCOPED token that carries
     * no per-tool mode entry for a capability is out of the grant grammar's contract
     * — parseGrantEntry() stamps a mode on every stageable grant (bare canonical =>
     * immediate except where re-consent is required, alias or `:staged` => staged)
     * — so it resolves STAGED rather than
     * inheriting full-surface trust. A scoped grant can therefore never gain an
     * approval-free lane it was not explicitly given, and tools/list advertises the
     * staged schema for exactly the calls the gate will stage.
     */
    public static function effectiveMode(?McpStaffToken $token, string $name): string
    {
        $mode = $token?->modeFor($name);
        if ($mode !== null) {
            return $mode;
        }

        if ($token !== null && $token->allowedTools !== null) {
            return self::MODE_STAGED;
        }

        return self::defaultMode($name);
    }

    public static function isStagedAlias(string $name): bool
    {
        return array_key_exists($name, self::stagedToCanonical());
    }

    /** Whether this canonical tool supports the staged parameter. */
    public static function isStageable(string $name): bool
    {
        return array_key_exists($name, self::canonicalToStaged());
    }

    /** Canonical name for a staged alias, or null when the name is not an alias. */
    public static function canonicalForAlias(string $name): ?string
    {
        return self::stagedToCanonical()[$name] ?? null;
    }

    /** The staged internal (dispatch) name for a stageable canonical tool. */
    public static function stagedInternalFor(string $canonical): ?string
    {
        return self::canonicalToStaged()[$canonical] ?? null;
    }

    /**
     * Parse one stored grant entry into [tool name, mode]. Mode is null for
     * tools without a staged/immediate split. Order matters: an entry that IS
     * a known name (alias or canonical) is never suffix-parsed, so a real tool
     * name can never be mis-read as a mode grant.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function parseGrantEntry(string $entry): array
    {
        $entry = trim($entry);

        if (($canonical = self::canonicalForAlias($entry)) !== null) {
            return [$canonical, self::MODE_STAGED];
        }

        if (self::isStageable($entry)) {
            // Bare canonical = legacy grant of the immediate variant, except for a
            // capability whose immediate lane post-dates the grant grammar: there a
            // legacy spelling stages (IMMEDIATE_RECONSENT_REQUIRED).
            return [$entry, self::legacyImmediateMode($entry)];
        }

        foreach ([self::MODE_STAGED, self::MODE_IMMEDIATE_RECONSENT, self::MODE_IMMEDIATE] as $mode) {
            $suffix = ':'.$mode;
            if (str_ends_with($entry, $suffix)) {
                $base = substr($entry, 0, -strlen($suffix));
                if (($canonical = self::canonicalForAlias($base)) !== null) {
                    // A suffixed alias is nonsense; an alias grant is always staged.
                    return [$canonical, self::MODE_STAGED];
                }
                if (self::isStageable($base)) {
                    // The re-consent spelling IS the immediate mode downstream; the
                    // legacy `:immediate` spelling is only immediate where the
                    // capability does not require re-consent.
                    if ($mode === self::MODE_IMMEDIATE_RECONSENT) {
                        return [$base, self::MODE_IMMEDIATE];
                    }

                    return [$base, $mode === self::MODE_IMMEDIATE ? self::legacyImmediateMode($base) : $mode];
                }
            }
        }

        return [$entry, null];
    }

    /**
     * Parse a stored grant list into the plain allowed-tool list plus the
     * per-tool mode map for stageable capabilities. When the same capability
     * is granted in both modes, immediate wins (it implies staged).
     *
     * @param  array<int, string>  $entries
     * @return array{tools: array<int, string>, modes: array<string, string>}
     */
    public static function parseGrants(array $entries): array
    {
        $tools = [];
        $modes = [];

        foreach ($entries as $entry) {
            [$name, $mode] = self::parseGrantEntry((string) $entry);
            if ($name === '') {
                continue;
            }

            $tools[$name] = true;
            if ($mode !== null && ($modes[$name] ?? null) !== self::MODE_IMMEDIATE) {
                $modes[$name] = $mode;
            }
        }

        return ['tools' => array_keys($tools), 'modes' => $modes];
    }

    /**
     * Normalize submitted grant entries into canonical storage form: stageable
     * capabilities always carry an explicit `:mode` suffix, everything else is
     * a plain name. Entries whose tool is not in the grantable catalog are
     * returned under `unknown` for the caller to reject.
     *
     * @param  array<int, string>  $entries
     * @return array{entries: array<int, string>, unknown: array<int, string>}
     */
    public static function normalizeGrantEntries(array $entries): array
    {
        $known = array_flip(McpToolRegistry::allToolNames());
        $plain = [];
        $modes = [];
        $unknown = [];

        foreach ($entries as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }

            [$name, $mode] = self::parseGrantEntry($raw);
            if (! isset($known[$name])) {
                $unknown[] = $raw;

                continue;
            }

            // A submission is a decision a human is making NOW, against the tool
            // description the lane actually has, so an explicit `name:immediate`
            // submission IS the re-consent and is stored under the re-consent
            // spelling below. A bare entry is not (legacy grammar only), and nor is
            // a staged alias — hence the exact match on the canonical name.
            //
            // "NOW" is the CALLER's warranty, not this method's. It holds for an
            // interactive surface where a human just ticked the box (the cockpit
            // checkbox, McpTokensController::updateTools) and NOT for an unattended
            // one, where the entry is whatever a stored runbook says. A
            // non-interactive caller must require the explicit re-consent spelling
            // itself before routing here — see
            // McpRotateStaffToken::withoutLegacyImmediateUplift().
            if ($mode === self::MODE_STAGED
                && self::requiresImmediateReconsent($name)
                && $raw === $name.':'.self::MODE_IMMEDIATE) {
                $mode = self::MODE_IMMEDIATE;
            }

            if ($mode === null) {
                $plain[$name] = true;
            } elseif (($modes[$name] ?? null) !== self::MODE_IMMEDIATE) {
                $modes[$name] = $mode;
            }
        }

        $normalized = [];
        foreach (array_keys($plain) as $name) {
            $normalized[] = $name;
        }
        foreach ($modes as $name => $mode) {
            $suffix = $mode === self::MODE_IMMEDIATE && self::requiresImmediateReconsent($name)
                ? self::MODE_IMMEDIATE_RECONSENT
                : $mode;
            $normalized[] = $name.':'.$suffix;
        }

        return ['entries' => $normalized, 'unknown' => array_values(array_unique($unknown))];
    }

    /**
     * Re-cut a raw tools/list definition set for the unified surface: staged
     * alias definitions are absorbed into their canonical tool, which gains a
     * `staged` boolean parameter. The advertised schema follows the caller's
     * effective mode — a staged-only token sees the staged variant's schema
     * (its calls are always staged), an immediate-granted or legacy
     * full-surface token sees the immediate schema with the staged fields
     * folded in as conditional.
     *
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    public static function unifyDefinitionsForList(array $tools, ?McpStaffToken $token): array
    {
        $aliases = self::stagedToCanonical();

        $stagedDefs = [];
        foreach ($tools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if (isset($aliases[$name])) {
                $stagedDefs[$aliases[$name]] = $tool;
            }
        }

        $unified = [];
        foreach ($tools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if (isset($aliases[$name])) {
                continue; // retired from the advertised surface
            }

            if (self::isStageable($name)) {
                $tool = self::unifyDefinition($tool, $stagedDefs[$name] ?? null, self::effectiveMode($token, $name));
            }

            $unified[] = $tool;
        }

        return $unified;
    }

    /**
     * Build the single advertised definition for one stageable capability from
     * its immediate definition and (when present) its staged twin.
     *
     * @param  array<string, mixed>  $direct
     * @param  array<string, mixed>|null  $stagedDef
     * @return array<string, mixed>
     */
    private static function unifyDefinition(array $direct, ?array $stagedDef, string $mode): array
    {
        $directSchema = is_array($direct['input_schema'] ?? null) ? $direct['input_schema'] : ['type' => 'object', 'properties' => []];
        $stagedSchema = is_array($stagedDef['input_schema'] ?? null) ? $stagedDef['input_schema'] : null;

        if ($mode === self::MODE_STAGED && $stagedSchema !== null) {
            // Staged-only grant: every call is staged, so advertise the staged
            // variant's schema (e.g. required ticket_id, no confirm_* friction).
            $schema = $stagedSchema;
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $properties['staged'] = [
                'type' => 'boolean',
                'description' => 'This token holds the staged-only grant for this tool: every call is held as a staged proposal for human cockpit approval. staged=false is automatically downgraded to a staged proposal.',
            ];
            $schema['properties'] = $properties;

            $description = trim((string) ($stagedDef['description'] ?? $direct['description'] ?? ''));
            $description .= ' This token grants staged mode only; immediate execution (staged=false) is downgraded to a staged proposal.';
        } else {
            $schema = $directSchema;
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $directRequired = array_map(strval(...), (array) ($schema['required'] ?? []));
            $stagedRequired = $stagedSchema !== null ? array_map(strval(...), (array) ($stagedSchema['required'] ?? [])) : [];
            $stagedProperties = $stagedSchema !== null && is_array($stagedSchema['properties'] ?? null) ? $stagedSchema['properties'] : [];

            // Fold in fields the staged variant carries that the immediate one
            // does not, and mark fields whose requiredness depends on the mode.
            foreach ($stagedProperties as $key => $property) {
                if (! array_key_exists($key, $properties)) {
                    if (is_array($property)) {
                        $property['description'] = rtrim((string) ($property['description'] ?? '')).' Only used when staged=true.';
                    }
                    $properties[$key] = $property;

                    continue;
                }

                // Some pairs deliberately allow more values in staged mode
                // (e.g. external mailbox forwarding is held-only). Advertise
                // the union so a staged call is not blocked by client-side
                // schema validation; the server still enforces the immediate
                // restriction on staged=false.
                if (is_array($properties[$key]) && is_array($property)
                    && is_array($properties[$key]['enum'] ?? null) && is_array($property['enum'] ?? null)) {
                    $stagedOnlyValues = array_values(array_diff($property['enum'], $properties[$key]['enum']));
                    if ($stagedOnlyValues !== []) {
                        $properties[$key]['enum'] = array_values(array_unique(array_merge($properties[$key]['enum'], $property['enum'])));
                        $properties[$key]['description'] = rtrim((string) ($properties[$key]['description'] ?? ''))
                            .' Values ['.implode(', ', array_map(strval(...), $stagedOnlyValues)).'] are only accepted when staged=true.';
                    }
                }
            }
            foreach ($stagedRequired as $key) {
                if (! in_array($key, $directRequired, true) && isset($properties[$key]) && is_array($properties[$key])) {
                    $properties[$key]['description'] = rtrim((string) ($properties[$key]['description'] ?? '')).' Required when staged=true.';
                }
            }

            $properties['staged'] = [
                'type' => 'boolean',
                'description' => 'Set true to hold this action as a staged proposal for human cockpit approval instead of executing it now (requires ticket_id). Defaults to false (immediate execution). Immediate execution requires this token to hold the immediate mode grant for this tool; otherwise the call is automatically downgraded to a staged proposal.',
            ];
            $schema['properties'] = $properties;
            $schema['required'] = $directRequired;

            $description = trim((string) ($direct['description'] ?? ''));
            $description .= ' Supports staged=true to hold the action for cockpit approval instead of executing immediately.';
        }

        if (($direct['name'] ?? null) === 'resolve_email_item') {
            $schema['properties']['staged'] = ['type' => 'boolean', 'enum' => [true], 'description' => 'Required true. No immediate execution or automatic downgrade.'];
            $schema['required'] = array_values(array_unique([...($schema['required'] ?? []), 'staged']));
            $description = (string) $direct['description'];
        }

        $direct['description'] = $description;
        $direct['input_schema'] = $schema;

        return $direct;
    }
}
