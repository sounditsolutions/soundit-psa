<?php

namespace App\Support;

use App\Services\Mcp\StaffCippWriteToolExecutor;
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
 */
class McpToolModes
{
    public const MODE_STAGED = 'staged';

    public const MODE_IMMEDIATE = 'immediate';

    /**
     * PSA-native staged aliases; the vendor families contribute theirs through
     * their executors' stagedToDirectMap() accessors.
     *
     * @var array<string, string>
     */
    private const PSA_STAGED_TO_DIRECT = [
        'stage_email' => 'send_email',
        'stage_public_note' => 'write_public_note',
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
            // Bare canonical = legacy grant of the immediate variant.
            return [$entry, self::MODE_IMMEDIATE];
        }

        foreach ([self::MODE_STAGED, self::MODE_IMMEDIATE] as $mode) {
            $suffix = ':'.$mode;
            if (str_ends_with($entry, $suffix)) {
                $base = substr($entry, 0, -strlen($suffix));
                if (($canonical = self::canonicalForAlias($base)) !== null) {
                    // A suffixed alias is nonsense; an alias grant is always staged.
                    return [$canonical, self::MODE_STAGED];
                }
                if (self::isStageable($base)) {
                    return [$base, $mode];
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
            $normalized[] = $name.':'.$mode;
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
                $mode = $token === null || $token->allowedTools === null
                    ? self::MODE_IMMEDIATE
                    : ($token->modeFor($name) ?? self::MODE_IMMEDIATE);
                $tool = self::unifyDefinition($tool, $stagedDefs[$name] ?? null, $mode);
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

        $direct['description'] = $description;
        $direct['input_schema'] = $schema;

        return $direct;
    }
}
