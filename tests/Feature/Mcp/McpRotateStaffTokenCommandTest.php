<?php

namespace Tests\Feature\Mcp;

use App\Models\McpToken;
use App\Support\McpConfig;
use App\Support\McpToolModes;
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

    /**
     * This command exists to run unattended — --force suppresses every prompt on
     * this path — so a `--tool` string is whatever a stored runbook says, not a
     * decision anyone made today. For a capability that requires re-consent the
     * legacy `:immediate` spelling therefore STAGES here, exactly as a stored grant
     * of it resolves, and says so; a bare legacy name stages too. Only the explicit
     * re-consent spelling, typed in this invocation, mints the immediate lane.
     */
    public function test_a_legacy_immediate_tool_option_cannot_mint_the_immediate_lane(): void
    {
        // An unchanged pre-lane rotation script, replayed after the lane shipped.
        $this->artisan('mcp:rotate-staff-token', [
            '--tool' => ['tactical_set_client_custom_field:immediate'],
            '--label' => 'controld-replayed',
            '--force' => true,
        ])
            ->expectsOutputToContain('tactical_set_client_custom_field:'.McpToolModes::MODE_IMMEDIATE_RECONSENT)
            ->assertSuccessful();

        $this->assertSame(
            ['tactical_set_client_custom_field:'.McpToolModes::MODE_STAGED],
            McpToken::where('label', 'controld-replayed')->sole()->tools,
            'a replayed pre-lane runbook flag must never mint the approval-free lane',
        );

        $this->artisan('mcp:rotate-staff-token', [
            '--tool' => ['tactical_set_client_custom_field'],
            '--label' => 'controld-bare',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(
            ['tactical_set_client_custom_field:'.McpToolModes::MODE_STAGED],
            McpToken::where('label', 'controld-bare')->sole()->tools,
        );

        // The uplift stays reachable, but only from the spelling no pre-lane script
        // can be carrying — typing it here IS the operator's decision made now.
        $this->artisan('mcp:rotate-staff-token', [
            '--tool' => ['tactical_set_client_custom_field:'.McpToolModes::MODE_IMMEDIATE_RECONSENT],
            '--label' => 'controld',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(
            ['tactical_set_client_custom_field:'.McpToolModes::MODE_IMMEDIATE_RECONSENT],
            McpToken::where('label', 'controld')->sole()->tools,
        );
        $this->assertSame(
            ['tactical_set_client_custom_field', McpToolModes::MODE_IMMEDIATE],
            McpToolModes::parseGrantEntry(McpToken::where('label', 'controld')->sole()->tools[0]),
        );
    }
}
