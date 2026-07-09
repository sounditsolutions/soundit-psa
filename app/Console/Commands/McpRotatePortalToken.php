<?php

namespace App\Console\Commands;

use App\Support\McpConfig;
use Illuminate\Console\Command;

class McpRotatePortalToken extends Command
{
    protected $signature = 'mcp:rotate-portal-token {--force : Skip confirmation prompts.}';

    protected $description = 'Generate a new bearer token for the portal MCP server (replaces any existing token)';

    public function handle(): int
    {
        $existing = McpConfig::portalToken();
        if ($existing && ! $this->option('force')) {
            $this->warn('An existing portal MCP token is set. Rotating will invalidate it.');
            if (! $this->confirm('Rotate the portal MCP token?', false)) {
                return self::SUCCESS;
            }
        }

        $token = McpConfig::rotatePortalToken();

        $url = rtrim(config('app.url'), '/').'/api/mcp/portal';

        $this->info('Portal MCP token generated. Configure the client Teams agent bridge with:');
        $this->newLine();
        $this->line("  URL:    {$url}");
        $this->line("  Token:  {$token}");
        $this->line('  Header: X-Mcp-Portal-Object-Id: <the Teams sender\'s Entra Object ID>');
        $this->newLine();
        $this->warn('This token will not be shown again. Capture it now.');

        return self::SUCCESS;
    }
}
