<?php

namespace Tests\Feature\Settings;

use App\Models\McpAuditLog;
use App\Models\McpToken;
use App\Models\User;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTokensPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'operator@soundit.co']);
    }

    public function test_page_requires_authentication(): void
    {
        $this->get(route('settings.mcp-tokens.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_tokens_with_clickable_detail_links_and_registry_checkboxes(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff', 'get_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee('chet')
            ->assertSee(route('settings.mcp-tokens.show', $token), false)
            ->assertSee('post_to_operator')
            ->assertSee('list_open_tickets');
    }

    public function test_store_mints_a_scoped_token_shown_once_and_hashed_at_rest(): void
    {
        $response = $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'chet',
            'tools' => ['find_staff', 'get_staff'],
        ]);

        $response->assertRedirect(route('settings.mcp-tokens.index'));
        $plain = $response->getSession()->get('mcp_new_token');
        $this->assertIsString($plain);
        $this->assertStringStartsWith('psa-mcp-', $plain);

        $row = McpToken::where('label', 'chet')->firstOrFail();
        $this->assertSame(hash('sha256', $plain), $row->token_hash);
        $this->assertStringNotContainsString($plain, json_encode($row->getAttributes()));

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee($plain);

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertDontSee($plain);
    }

    public function test_store_rejects_unknown_tool_names_and_empty_tool_sets(): void
    {
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'evil',
            'tools' => ['definitely_not_a_tool'],
        ])->assertSessionHasErrors('tools.0');

        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'empty',
            'tools' => [],
        ])->assertSessionHasErrors('tools');

        $this->assertSame(0, McpToken::count());
    }

    public function test_store_and_revoke_write_lifecycle_audit_rows(): void
    {
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'chet',
            'tools' => ['find_staff'],
        ]);

        $this->assertDatabaseHas('mcp_audit_logs', [
            'server_name' => 'staff',
            'method' => 'token/mint',
            'tool_name' => 'chet',
            'status' => 'success',
            'actor_label' => 'web:operator@soundit.co',
        ]);

        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'chet',
            'tools' => ['get_staff'],
        ]);

        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'token/rotate',
            'tool_name' => 'chet',
        ]);

        $token = McpToken::where('label', 'chet')->firstOrFail();
        $this->actingAs($this->user)->delete(route('settings.mcp-tokens.revoke', $token));

        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'token/revoke',
            'tool_name' => 'chet',
        ]);
    }

    public function test_detail_page_shows_tools_directive_and_per_token_audit_log(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();
        $token->update(['directive' => 'Stay in the Chet Teams bridge lane.']);

        McpAuditLog::create([
            'server_name' => 'staff',
            'method' => 'tools/call',
            'tool_name' => 'find_staff',
            'arguments' => ['query' => 'alice'],
            'status' => 'success',
            'duration_ms' => 12,
            'actor_label' => 'mcp-staff:chet',
            'source_ip' => '127.0.0.1',
        ]);
        McpAuditLog::create([
            'server_name' => 'staff',
            'method' => 'token/mint',
            'tool_name' => 'chet',
            'arguments' => ['tools' => ['find_staff']],
            'status' => 'success',
            'duration_ms' => 0,
            'actor_label' => 'web:operator@soundit.co',
            'source_ip' => '127.0.0.1',
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.show', $token))
            ->assertOk()
            ->assertSee('find_staff')
            ->assertSee('Stay in the Chet Teams bridge lane.')
            ->assertSee('tools/call')
            ->assertSee('token/mint');
    }

    public function test_detail_page_updates_tools_after_mint(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)->patch(route('settings.mcp-tokens.tools', $token), [
            'tools' => ['find_staff', 'post_to_operator'],
        ])->assertRedirect(route('settings.mcp-tokens.show', $token));

        $this->assertSame(['find_staff', 'post_to_operator'], $token->fresh()->tools);
        $resolved = McpConfig::resolveStaffToken($plain);
        $this->assertTrue($resolved->allows('post_to_operator'));
        $this->assertFalse($resolved->allows('get_staff'));

        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'token/tools',
            'tool_name' => 'chet',
        ]);
    }

    public function test_detail_page_rejects_empty_or_unknown_tool_updates(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)->patch(route('settings.mcp-tokens.tools', $token), [
            'tools' => [],
        ])->assertSessionHasErrors('tools');

        $this->actingAs($this->user)->patch(route('settings.mcp-tokens.tools', $token), [
            'tools' => ['not_real'],
        ])->assertSessionHasErrors('tools.0');

        $this->assertSame(['find_staff'], $token->fresh()->tools);
    }

    public function test_detail_page_updates_directive(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)->patch(route('settings.mcp-tokens.directive', $token), [
            'directive' => 'Use this token only for Chet orientation and staff lookup.',
        ])->assertRedirect(route('settings.mcp-tokens.show', $token));

        $this->assertSame('Use this token only for Chet orientation and staff lookup.', $token->fresh()->directive);
        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'token/directive',
            'tool_name' => 'chet',
        ]);
    }

    public function test_revoke_stamps_revoked_at_and_blocks_resolution(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)
            ->delete(route('settings.mcp-tokens.revoke', $token))
            ->assertRedirect(route('settings.mcp-tokens.index'));

        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertNull(McpConfig::resolveStaffToken($plain));
    }
}
