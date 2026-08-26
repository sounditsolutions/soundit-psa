<?php

namespace App\Console\Commands;

use App\Support\McpConfig;
use Illuminate\Console\Command;

class McpRotateStaffToken extends Command
{
    protected $signature = 'mcp:rotate-staff-token
        {--tool=* : Tool name allowed for this scoped token. Repeat or comma-separate. Stageable action tools accept a mode suffix (name:staged holds every call for cockpit approval; name:immediate allows direct execution; bare name = immediate). Required: every token carries an explicit allowlist (psa-688).}
        {--tools= : Comma-separated tool names allowed for this scoped token.}
        {--label= : Stable label for a scoped token; rotating the same label replaces the previous scoped token.}
        {--retire-legacy : Delete the legacy full-surface token without minting a replacement. The only remaining unscoped operation — break-glass on a leaked legacy token is retirement, not rotation.}
        {--force : Skip confirmation prompts.}';

    protected $description = 'Generate a new scoped bearer token for the staff MCP server (unscoped full-surface mints are refused; --retire-legacy deletes the legacy token)';

    public function handle(): int
    {
        $tools = $this->allowedTools();
        $scoped = $tools !== [];

        if ($this->option('retire-legacy')) {
            if ($scoped || $this->option('label')) {
                $this->error('--retire-legacy deletes the legacy full-surface token and mints nothing; it cannot be combined with --tool/--tools/--label.');

                return self::FAILURE;
            }

            if (McpConfig::staffToken() === null) {
                $this->info('No legacy full-surface staff MCP token is set. Nothing to retire.');

                return self::SUCCESS;
            }

            if (! $this->option('force') && ! $this->confirm('Retire (delete) the legacy full-surface staff MCP token? Any consumer still using it loses access.', false)) {
                return self::SUCCESS;
            }

            McpConfig::retireLegacyStaffToken();
            $this->info('Legacy full-surface staff MCP token retired. Scoped tokens are unaffected.');

            return self::SUCCESS;
        }

        // Fail closed at mint time (psa-688): a token with no allowlist inherits the
        // whole tool surface, and no consumer legitimately needs that. There is no
        // override — an operator who wants broad access grants the tools by name.
        if (! $scoped) {
            $this->error('Refusing to mint an unscoped staff MCP token: a token with no allowlist inherits the full tool surface. Pass --tool/--tools with the explicit allowlist this consumer needs (or --retire-legacy to delete an existing legacy full-surface token).');

            return self::FAILURE;
        }

        $label = $this->option('label') ?: null;
        $effectiveScopedLabel = $label ?: 'scoped';

        if (McpConfig::hasScopedStaffTokenLabel($effectiveScopedLabel) && ! $this->option('force')) {
            $this->warn("A scoped staff MCP token labeled [{$effectiveScopedLabel}] is set. Rotating will invalidate it.");
            if (! $this->confirm('Rotate this scoped staff MCP token?', false)) {
                return self::SUCCESS;
            }
        }

        $token = McpConfig::rotateStaffToken(
            allowedTools: $tools,
            label: $label,
        );

        $url = rtrim(config('app.url'), '/').'/api/mcp/staff';

        $this->info('Scoped staff MCP token generated. Configure the external MCP consumer with:');
        $this->newLine();
        $this->line("  URL:   {$url}");
        $this->line("  Token: {$token}");
        $this->line('  Tools: '.implode(', ', $tools));
        $this->line('  Label: '.$effectiveScopedLabel);
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
