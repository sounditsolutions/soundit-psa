<?php

namespace App\Support;

use App\Models\McpToken;

final class McpStaffToken
{
    public const LEGACY_ACTOR_LABEL = 'mcp-legacy';

    /**
     * @param  array<int, string>|null  $allowedTools  Null means legacy full-surface token. Entries are plain canonical tool names (mode suffixes and staged aliases already resolved by McpToolModes::parseGrants()).
     * @param  array<string, string>  $toolModes  Per-tool execution mode for stageable capabilities: McpToolModes::MODE_STAGED or MODE_IMMEDIATE.
     */
    public function __construct(
        public readonly ?array $allowedTools = null,
        public readonly ?string $label = null,
        public readonly ?int $id = null,
        public readonly ?string $directive = null,
        public readonly bool $aiActor = false,
        public readonly bool $requireExplicitClientScope = false,
        public readonly array $toolModes = [],
    ) {}

    public function allows(string $toolName): bool
    {
        return $this->allowedTools === null || in_array($toolName, $this->allowedTools, true);
    }

    /** The granted execution mode for a stageable tool, or null when ungranted / not mode-tracked. */
    public function modeFor(string $toolName): ?string
    {
        return $this->toolModes[$toolName] ?? null;
    }

    /**
     * Whether staged=false may execute this stageable tool now. Resolved through
     * McpToolModes::effectiveMode() — the SAME call tools/list resolves the
     * advertised definition with — so the surface a token is shown and the lane
     * it can actually reach cannot drift apart. A token holding no per-tool mode
     * entry, INCLUDING the legacy full-surface token (allowedTools === null),
     * therefore falls to McpToolModes::defaultMode(): full trust exactly as
     * before, except for the capabilities whose immediate lane did not exist
     * before the unification (merge_ticket / merge_asset), which need the
     * explicit `:immediate` grant rather than acquiring an approval-free
     * destructive lane on deploy. An ungranted tool stays fail-closed here even
     * though the grant gate already refused it. (Staging itself needs only
     * allows() — any grant of a stageable tool permits the strictly-safer
     * staged path.)
     */
    public function allowsImmediate(string $toolName): bool
    {
        if (! $this->allows($toolName)) {
            return false;
        }

        return McpToolModes::effectiveMode($this, $toolName) === McpToolModes::MODE_IMMEDIATE;
    }

    public function actorLabel(): string
    {
        if ($this->label === null || $this->label === '') {
            return self::LEGACY_ACTOR_LABEL;
        }

        return 'mcp-staff:'.$this->label;
    }

    public function directiveOrDefault(): string
    {
        $directive = trim((string) $this->directive);

        return $directive !== '' ? $directive : McpToken::defaultDirective();
    }
}
