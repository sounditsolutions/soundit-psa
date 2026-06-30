<?php

namespace App\Support;

final class McpStaffToken
{
    /**
     * @param  array<int, string>|null  $allowedTools  Null means legacy full-surface token.
     */
    public function __construct(
        public readonly ?array $allowedTools = null,
        public readonly ?string $label = null,
    ) {}

    public function allows(string $toolName): bool
    {
        return $this->allowedTools === null || in_array($toolName, $this->allowedTools, true);
    }

    public function actorLabel(): string
    {
        if ($this->label === null || $this->label === '') {
            return 'teams-bot';
        }

        return 'mcp-staff:'.$this->label;
    }
}
