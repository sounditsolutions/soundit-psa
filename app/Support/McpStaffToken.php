<?php

namespace App\Support;

use App\Models\McpToken;

final class McpStaffToken
{
    public const LEGACY_ACTOR_LABEL = 'mcp-legacy';

    /**
     * @param  array<int, string>|null  $allowedTools  Null means legacy full-surface token.
     */
    public function __construct(
        public readonly ?array $allowedTools = null,
        public readonly ?string $label = null,
        public readonly ?int $id = null,
        public readonly ?string $directive = null,
        public readonly bool $aiActor = false,
        public readonly bool $requireExplicitClientScope = false,
    ) {}

    public function allows(string $toolName): bool
    {
        return $this->allowedTools === null || in_array($toolName, $this->allowedTools, true);
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
