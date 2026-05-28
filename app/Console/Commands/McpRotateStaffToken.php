<?php

namespace App\Console\Commands;

use App\Support\McpConfig;
use Illuminate\Console\Command;

class McpRotateStaffToken extends Command
{
    protected $signature = 'mcp:rotate-staff-token';

    protected $description = 'Generate a new bearer token for the staff MCP server (replaces any existing token)';

    public function handle(): int
    {
        $existing = McpConfig::staffToken();
        if ($existing) {
            $this->warn('An existing staff MCP token is set. Rotating will invalidate it.');
            if (! $this->confirm('Rotate the staff MCP token?', false)) {
                return self::SUCCESS;
            }
        }

        $token = McpConfig::rotateStaffToken();

        $url = rtrim(config('app.url'), '/') . '/api/mcp/staff';

        $this->info('Staff MCP token generated. Configure the Teams bot with:');
        $this->newLine();
        $this->line("  URL:   {$url}");
        $this->line("  Token: {$token}");
        $this->newLine();
        $this->warn('This token will not be shown again. Capture it now.');

        return self::SUCCESS;
    }
}
