<?php

namespace App\Console\Commands;

use App\Support\McpConfig;
use App\Support\McpToolModes;
use Illuminate\Console\Command;

class McpRotateStaffToken extends Command
{
    protected $signature = 'mcp:rotate-staff-token
        {--tool=* : Tool name allowed for this scoped token. Repeat or comma-separate. Stageable action tools accept a mode suffix (name:staged holds every call for cockpit approval; name:immediate allows direct execution; bare name = immediate, except for a capability whose immediate lane post-dates the grant grammar — there a bare name AND name:immediate both stage, because this command runs unattended, and only an explicit name:immediate-reconsent typed in this invocation grants the immediate lane). Required: every token carries an explicit allowlist (psa-688).}
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

        // This command is a grant-minting surface like the cockpit, so `--tool`
        // spellings are normalized the same way (McpTokensController::updateTools)
        // — with one difference the cockpit does not have. There, `name:immediate`
        // is a checkbox a human just ticked, so it IS a decision made NOW. Here the
        // argument is whatever a stored runbook file says, and --force removes the
        // only prompt on this path, so a replayed pre-lane flag line is not consent
        // to a lane that did not exist when it was written. For a capability in
        // McpToolModes::IMMEDIATE_RECONSENT_REQUIRED this command therefore reads
        // ONLY the explicit re-consent spelling as consent and stages a typed
        // `:immediate`, exactly as a stored grant of it resolves. Names outside the
        // grantable catalog are stored exactly as typed, as before.
        $tools = $this->withoutLegacyImmediateUplift($tools);

        $normalized = McpToolModes::normalizeGrantEntries($tools);
        $tools = array_merge($normalized['entries'], $normalized['unknown']);

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

    /**
     * Stage a typed legacy `name:immediate` for every capability that requires
     * re-consent, naming each one it downgrades. The uplift is still available
     * here, but only to an operator who types the re-consent spelling in THIS
     * invocation: the command is built to run unattended, so there is no prompt to
     * fall back on and an unchanged pre-lane script must not silently mint an
     * approval-free lane nobody granted today.
     *
     * @param  array<int, string>  $tools
     * @return array<int, string>
     */
    private function withoutLegacyImmediateUplift(array $tools): array
    {
        $legacySuffix = ':'.McpToolModes::MODE_IMMEDIATE;
        $reconsentSuffix = ':'.McpToolModes::MODE_IMMEDIATE_RECONSENT;
        $kept = [];

        foreach ($tools as $tool) {
            $base = str_ends_with($tool, $legacySuffix)
                ? substr($tool, 0, -strlen($legacySuffix))
                : null;

            if ($base !== null && McpToolModes::requiresImmediateReconsent($base)) {
                $this->warn("[{$base}] was granted STAGED, not immediate: `{$legacySuffix}` is a legacy spelling this capability's immediate lane post-dates, and this command runs unattended, so it is not read as consent to that lane. To grant it, re-run with --tool={$base}{$reconsentSuffix}.");
                $kept[] = $base.':'.McpToolModes::MODE_STAGED;

                continue;
            }

            $kept[] = $tool;
        }

        return $kept;
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
