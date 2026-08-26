<?php

namespace Tests\Feature\Mcp;

use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpRotateStaffTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_scoped_token_rotation_requires_confirmation_before_replacing_existing_label(): void
    {
        $existing = McpConfig::rotateStaffToken(
            allowedTools: ['get_ticket_detail'],
            label: 'scoped',
        );

        $this->artisan('mcp:rotate-staff-token', ['--tool' => ['list_open_tickets']])
            ->expectsConfirmation('Rotate this scoped staff MCP token?', 'no')
            ->assertSuccessful();

        $resolved = McpConfig::resolveStaffToken($existing);

        $this->assertNotNull($resolved);
        $this->assertSame('scoped', $resolved->label);
        $this->assertSame(['get_ticket_detail'], $resolved->allowedTools);
    }

    /**
     * psa-688: an unscoped mint fails closed. A token with no allowlist inherits the
     * full tool surface, and no consumer legitimately needs that — the command
     * refuses outright, with no override flag, and mints nothing.
     */
    public function test_unscoped_mint_is_refused_and_mints_nothing(): void
    {
        $this->artisan('mcp:rotate-staff-token')
            ->expectsOutputToContain('Refusing to mint an unscoped staff MCP token')
            ->assertFailed();

        $this->assertNull(McpConfig::staffToken());
    }

    public function test_unscoped_mint_is_refused_even_with_force(): void
    {
        $existing = McpConfig::rotateStaffToken();

        $this->artisan('mcp:rotate-staff-token', ['--force' => true])
            ->assertFailed();

        // The refusal also does not rotate away an existing legacy token as a
        // side effect — it mints and replaces nothing.
        $this->assertSame($existing, McpConfig::staffToken());
    }

    public function test_retire_legacy_deletes_the_legacy_token_and_leaves_scoped_tokens(): void
    {
        McpConfig::rotateStaffToken();
        $scoped = McpConfig::rotateStaffToken(
            allowedTools: ['get_ticket_detail'],
            label: 'chet',
        );

        $this->artisan('mcp:rotate-staff-token', ['--retire-legacy' => true, '--force' => true])
            ->expectsOutputToContain('retired')
            ->assertSuccessful();

        $this->assertNull(McpConfig::staffToken());

        $resolved = McpConfig::resolveStaffToken($scoped);
        $this->assertNotNull($resolved);
        $this->assertSame(['get_ticket_detail'], $resolved->allowedTools);
    }

    public function test_retire_legacy_requires_confirmation_without_force(): void
    {
        $existing = McpConfig::rotateStaffToken();

        $this->artisan('mcp:rotate-staff-token', ['--retire-legacy' => true])
            ->expectsConfirmation('Retire (delete) the legacy full-surface staff MCP token? Any consumer still using it loses access.', 'no')
            ->assertSuccessful();

        $this->assertSame($existing, McpConfig::staffToken());
    }

    public function test_retire_legacy_with_no_legacy_token_reports_nothing_to_retire(): void
    {
        $this->artisan('mcp:rotate-staff-token', ['--retire-legacy' => true, '--force' => true])
            ->expectsOutputToContain('Nothing to retire')
            ->assertSuccessful();
    }

    public function test_retire_legacy_cannot_be_combined_with_tool_options(): void
    {
        $existing = McpConfig::rotateStaffToken();

        $this->artisan('mcp:rotate-staff-token', ['--retire-legacy' => true, '--tool' => ['get_ticket_detail'], '--force' => true])
            ->assertFailed();

        $this->assertSame($existing, McpConfig::staffToken());
    }
}
