<?php

namespace Tests\Feature\Settings;

use App\Models\McpAuditLog;
use App\Models\McpToken;
use App\Models\User;
use App\Services\Chet\TeamsChatReadToolset;
use App\Services\Tactical\TacticalReadOnlyToolset;
use App\Services\Triage\TriageToolDefinitions;
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

        $this->user = User::factory()->create();
    }

    public function test_page_requires_authentication(): void
    {
        $this->get(route('settings.mcp-tokens.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_tokens_and_renders_registry_checkboxes(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff', 'get_staff'], label: 'chet');

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee('chet')
            ->assertSee('post_to_operator')
            ->assertSee('list_open_tickets');
    }

    public function test_data_surface_read_tools_are_grantable_from_registry_ui(): void
    {
        $tools = array_values(array_unique(array_merge(
            array_column(TacticalReadOnlyToolset::definitions(), 'name'),
            array_column(TeamsChatReadToolset::definitions(), 'name'),
            array_column(TriageToolDefinitions::cippTools(), 'name'),
        )));

        $page = $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'));

        $page->assertOk();
        foreach ($tools as $tool) {
            $page->assertSee('value="'.$tool.'"', false);
        }

        $this->actingAs($this->user)
            ->post(route('settings.mcp-tokens.store'), [
                'label' => 'chet-data',
                'tools' => $tools,
            ])
            ->assertOk();

        $this->assertSame($tools, McpToken::where('label', 'chet-data')->firstOrFail()->tools);
    }

    public function test_store_mints_a_scoped_token_shown_once_and_hashed_at_rest(): void
    {
        $response = $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'chet',
            'tools' => ['find_staff', 'get_staff'],
        ]);

        $response->assertOk();
        $this->assertFalse(session()->has('mcp_new_token'));
        preg_match('/psa-mcp-[A-Za-z0-9]{48}/', $response->getContent(), $matches);
        $plain = $matches[0] ?? null;
        $this->assertIsString($plain);
        $this->assertStringStartsWith('psa-mcp-', $plain);

        $row = McpToken::where('label', 'chet')->firstOrFail();
        $this->assertSame(hash('sha256', $plain), $row->token_hash);
        $this->assertStringNotContainsString($plain, json_encode($row->getAttributes()));
    }

    public function test_store_rejects_unknown_tool_names(): void
    {
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'evil',
            'tools' => ['definitely_not_a_tool'],
        ])->assertSessionHasErrors('tools.0');

        $this->assertSame(0, McpToken::count());
    }

    public function test_store_requires_at_least_one_tool(): void
    {
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'empty',
            'tools' => [],
        ])->assertSessionHasErrors('tools');
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

    public function test_index_does_not_render_plaintext_from_session_flash(): void
    {
        $this->actingAs($this->user)
            ->withSession(['mcp_new_token' => 'psa-mcp-EXAMPLEONETIME', 'mcp_new_token_label' => 'chet'])
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertDontSee('psa-mcp-EXAMPLEONETIME');
    }

    public function test_minted_secret_is_rendered_for_one_request_only(): void
    {
        $response = $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'chet',
            'tools' => ['find_staff', 'get_staff'],
        ]);
        preg_match('/psa-mcp-[A-Za-z0-9]{48}/', $response->getContent(), $matches);
        $plain = $matches[0] ?? null;

        $response->assertOk()
            ->assertSee($plain)
            ->assertSee('will not be shown again');

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertDontSee($plain);
    }

    public function test_tool_descriptions_and_sensitive_group_are_rendered(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee('Operator bridge (sensitive)')
            ->assertSee('Post a message to the operator Teams chat', false);
    }

    public function test_revoked_tokens_show_as_revoked_without_a_revoke_button(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'dead');
        McpToken::where('label', 'dead')->update(['revoked_at' => now()]);

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee('Revoked')
            ->assertDontSee('>Revoke<', false);
    }

    public function test_minting_writes_a_token_mint_audit_row(): void
    {
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'chet',
            'tools' => ['find_staff', 'get_staff'],
        ]);

        $this->assertDatabaseHas('mcp_audit_logs', [
            'server_name' => 'staff',
            'method' => 'token/mint',
            'tool_name' => 'chet',
            'status' => 'success',
        ]);

        $row = McpAuditLog::where('method', 'token/mint')->firstOrFail();
        $this->assertStringContainsString($this->user->email, (string) $row->actor_label);
        $this->assertSame(['tools' => ['find_staff', 'get_staff']], $row->arguments);
    }

    public function test_re_minting_an_existing_label_is_audited_as_rotate(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'chet',
            'tools' => ['get_staff'],
        ]);

        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'token/rotate',
            'tool_name' => 'chet',
        ]);
    }

    public function test_revoking_writes_a_token_revoke_audit_row(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)->delete(route('settings.mcp-tokens.revoke', $token));

        $this->assertDatabaseHas('mcp_audit_logs', [
            'server_name' => 'staff',
            'method' => 'token/revoke',
            'tool_name' => 'chet',
        ]);
    }

    public function test_revoking_an_already_revoked_token_is_idempotent_without_duplicate_audit(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)->delete(route('settings.mcp-tokens.revoke', $token));
        $this->actingAs($this->user)->delete(route('settings.mcp-tokens.revoke', $token->fresh()));

        $this->assertSame(1, McpAuditLog::where('method', 'token/revoke')
            ->where('tool_name', 'chet')
            ->count());
    }
}
