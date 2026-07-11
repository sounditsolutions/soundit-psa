<?php

namespace App\Console\Commands;

use App\Support\McpConfig;
use Illuminate\Console\Command;

class McpRotateStaffToken extends Command
{
    protected $signature = 'mcp:rotate-staff-token
        {--tool=* : Tool name allowed for this scoped token. Repeat or comma-separate. Stageable action tools accept a mode suffix (name:staged holds every call for cockpit approval; name:immediate allows direct execution; bare name = immediate). Omit for the legacy full-surface token.}
        {--tools= : Comma-separated tool names allowed for this scoped token.}
        {--label= : Stable label for a scoped token; rotating the same label replaces the previous scoped token.}
        {--force : Skip confirmation prompts.}';

    protected $description = 'Generate a new bearer token for the staff MCP server (replaces any existing token)';

    public function handle(): int
    {
        $tools = $this->allowedTools();
        $scoped = $tools !== [];
        $label = $this->option('label') ?: null;
        $effectiveScopedLabel = $label ?: 'scoped';

        $existing = McpConfig::staffToken();
        if (! $scoped && $existing && ! $this->option('force')) {
            $this->warn('An existing staff MCP token is set. Rotating will invalidate it.');
            if (! $this->confirm('Rotate the staff MCP token?', false)) {
                return self::SUCCESS;
            }
        }

        if ($scoped && McpConfig::hasScopedStaffTokenLabel($effectiveScopedLabel) && ! $this->option('force')) {
            $this->warn("A scoped staff MCP token labeled [{$effectiveScopedLabel}] is set. Rotating will invalidate it.");
            if (! $this->confirm('Rotate this scoped staff MCP token?', false)) {
                return self::SUCCESS;
            }
        }

        $token = McpConfig::rotateStaffToken(
            allowedTools: $scoped ? $tools : null,
            label: $label,
        );

        $url = rtrim(config('app.url'), '/').'/api/mcp/staff';

        $this->info($scoped ? 'Scoped staff MCP token generated. Configure the external MCP consumer with:' : 'Staff MCP token generated. Configure the Teams bot with:');
        $this->newLine();
        $this->line("  URL:   {$url}");
        $this->line("  Token: {$token}");
        if ($scoped) {
            $this->line('  Tools: '.implode(', ', $tools));
            $this->line('  Label: '.$effectiveScopedLabel);
        }
        $this->newLine();
        $this->warn('This token will not be shown again. Capture it now.');

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function allowedTools(): array
    {
        $tools = [];

        foreach ((array) $this->option('tool') as $tool) {
            foreach (explode(',', (string) $tool) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $tools[$part] = true;
                }
            }
        }

        foreach (explode(',', (string) ($this->option('tools') ?? '')) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $tools[$part] = true;
            }
        }

        return array_keys($tools);
    }
}
