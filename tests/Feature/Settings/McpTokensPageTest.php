<?php

namespace Tests\Feature\Settings;

use App\Models\McpAuditLog;
use App\Models\McpToken;
use App\Models\Setting;
use App\Models\SignalDestination;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTokensPageTest extends TestCase
{
    use RefreshDatabase;

    private \App\Models\User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \App\Models\User::factory()->create();
    }

    private function latestToken(): McpToken
    {
        return McpToken::query()->latest('id')->firstOrFail();
    }

    public function test_page_requires_authentication(): void
    {
        $this->get(route('settings.mcp-tokens.index'))->assertRedirect(route('login'));
    }

    public function test_index_is_lean_and_shows_status_without_the_create_form(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee('chet')
            ->assertSee('Active')
            ->assertSee(route('settings.mcp-tokens.show', $token), false)
            // The bulk create form + registry checkboxes + MSP instruction editors are gone from the list page.
            ->assertDontSee('name="tools[]"', false)
            ->assertDontSee('MSP Tool Instructions');
    }

    public function test_create_mints_an_inactive_draft_and_redirects_into_detail(): void
    {
        $response = $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'));

        $token = $this->latestToken();
        $response->assertRedirect(route('settings.mcp-tokens.show', $token));

        $this->assertTrue($token->isDraft());
        $this->assertNull($token->activated_at);
        $this->assertSame([], $token->tools);
        $this->assertFalse($token->ai_actor);
        $this->assertTrue($token->require_explicit_client_scope);
        $this->assertStringStartsWith('untitled', $token->label);

        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'token/mint',
            'tool_name' => $token->label,
            'status' => 'success',
        ]);
    }

    public function test_create_gives_each_draft_a_unique_name(): void
    {
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'));
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'));

        $labels = McpToken::query()->pluck('label');
        $this->assertCount(2, $labels);
        $this->assertSame($labels->count(), $labels->unique()->count());
    }

    public function test_secret_is_revealed_once_on_the_detail_page_and_hashed_at_rest(): void
    {
        $response = $this->actingAs($this->user)
            ->followingRedirects()
            ->post(route('settings.mcp-tokens.store'));

        $response->assertOk()->assertSee('will not be shown again');
        preg_match('/psa-mcp-[A-Za-z0-9]{48}/', $response->getContent(), $matches);
        $plain = $matches[0] ?? null;
        $this->assertIsString($plain);

        $token = McpToken::where('token_hash', hash('sha256', $plain))->firstOrFail();
        $this->assertStringNotContainsString($plain, json_encode($token->getAttributes()));

        // Not shown on any later view.
        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.show', $token))
            ->assertOk()
            ->assertDontSee($plain);
    }

    public function test_activate_pause_and_resume_move_through_the_lifecycle(): void
    {
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'));
        $token = $this->latestToken();
        $this->assertTrue($token->isDraft());

        $this->actingAs($this->user)->post(route('settings.mcp-tokens.activate', $token))
            ->assertRedirect(route('settings.mcp-tokens.show', $token));
        $this->assertTrue($token->fresh()->isActive());

        $this->actingAs($this->user)->post(route('settings.mcp-tokens.pause', $token));
        $this->assertTrue($token->fresh()->isPaused());

        $this->actingAs($this->user)->post(route('settings.mcp-tokens.resume', $token));
        $this->assertTrue($token->fresh()->isActive());

        $this->assertDatabaseHas('mcp_audit_logs', ['method' => 'token/activate', 'tool_name' => $token->label]);
        $this->assertDatabaseHas('mcp_audit_logs', ['method' => 'token/pause', 'tool_name' => $token->label]);
        $this->assertDatabaseHas('mcp_audit_logs', ['method' => 'token/resume', 'tool_name' => $token->label]);
    }

    public function test_rename_updates_label_and_keeps_linked_destinations_attached(): void
    {
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'));
        $token = $this->latestToken();
        $old = $token->label;
        SignalDestination::create(['label' => 'Inbox', 'type' => 'mcp', 'mcp_token_label' => $old]);

        $this->actingAs($this->user)
            ->patch(route('settings.mcp-tokens.rename', $token), ['label' => 'chet-tactical'])
            ->assertRedirect(route('settings.mcp-tokens.show', $token));

        $this->assertSame('chet-tactical', $token->fresh()->label);
        $this->assertSame('chet-tactical', SignalDestination::where('label', 'Inbox')->firstOrFail()->mcp_token_label);
        $this->assertDatabaseHas('mcp_audit_logs', ['method' => 'token/rename', 'tool_name' => 'chet-tactical']);
    }

    public function test_rename_rejects_a_duplicate_label(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'taken');
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'));
        $token = $this->latestToken();

        $this->actingAs($this->user)
            ->patchJson(route('settings.mcp-tokens.rename', $token), ['label' => 'taken'])
            ->assertStatus(422);

        $this->assertStringStartsWith('untitled', $token->fresh()->label);
    }

    public function test_update_tools_allows_an_empty_grant_set_and_saves_json(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff', 'get_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)
            ->patchJson(route('settings.mcp-tokens.tools', $token), ['tools' => ['get_staff']])
            ->assertOk()
            ->assertJson(['ok' => true, 'granted_count' => 1]);
        $this->assertSame(['get_staff'], $token->fresh()->tools);

        // Auto-save supports revoking every tool (a token may grant nothing).
        $this->actingAs($this->user)
            ->patchJson(route('settings.mcp-tokens.tools', $token), ['tools' => []])
            ->assertOk();
        $this->assertSame([], $token->fresh()->tools);

        $this->assertDatabaseHas('mcp_audit_logs', ['method' => 'token/tools', 'tool_name' => 'chet']);
    }

    public function test_update_tools_rejects_unknown_tool_names(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)
            ->patchJson(route('settings.mcp-tokens.tools', $token), ['tools' => ['definitely_not_a_tool']])
            ->assertStatus(422);

        $this->assertSame(['find_staff'], $token->fresh()->tools);
    }

    public function test_directive_and_trust_flags_auto_save(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet', aiActor: false, requireExplicitClientScope: false);
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)
            ->patchJson(route('settings.mcp-tokens.directive', $token), ['directive' => 'Use Chet rules.'])
            ->assertOk();
        $this->assertSame('Use Chet rules.', $token->fresh()->directive);

        $this->actingAs($this->user)
            ->patchJson(route('settings.mcp-tokens.trust-flags', $token), ['ai_actor' => 1, 'require_explicit_client_scope' => 1])
            ->assertOk();
        $this->assertTrue($token->fresh()->ai_actor);
        $this->assertTrue($token->fresh()->require_explicit_client_scope);
    }

    public function test_detail_page_renders_integration_groups_directive_trust_and_audit(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['tactical_get_device'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();
        McpAuditLog::create([
            'server_name' => 'staff', 'method' => 'tools/call', 'tool_name' => 'tactical_get_device',
            'arguments' => [], 'status' => 'success', 'duration_ms' => 1, 'actor_label' => 'mcp-staff:chet',
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.show', $token))
            ->assertOk()
            ->assertSee('PSA Core')
            ->assertSee('Tactical RMM')
            ->assertSee('tactical_get_device')
            ->assertSee('Directive')
            ->assertSee('Trust &amp; scope', false)
            ->assertSee('Alerts Hub Destinations')
            ->assertSee('tools/call');
    }

    public function test_revoke_stamps_revoked_at_and_blocks_resolution(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();
        $this->assertNotNull(McpConfig::resolveStaffToken($plain));

        $this->actingAs($this->user)
            ->delete(route('settings.mcp-tokens.revoke', $token))
            ->assertRedirect(route('settings.mcp-tokens.index'));

        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertNull(McpConfig::resolveStaffToken($plain));
    }

    public function test_discarding_a_draft_revokes_it(): void
    {
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'));
        $token = $this->latestToken();

        $this->actingAs($this->user)
            ->delete(route('settings.mcp-tokens.revoke', $token))
            ->assertRedirect(route('settings.mcp-tokens.index'));

        $this->assertTrue($token->fresh()->isRevoked());
        $this->assertDatabaseHas('mcp_audit_logs', ['method' => 'token/revoke', 'tool_name' => $token->label]);
    }

    public function test_updates_msp_tool_instructions_without_auditing_body_text(): void
    {
        $body = 'Use the MSP closeout checklist before proposing customer-facing text.';

        $this->actingAs($this->user)
            ->patch(route('settings.mcp-tokens.tool-instructions'), [
                'tool_instructions' => [
                    'send_reply' => "  {$body}  ",
                    'find_staff' => '',
                    'not_a_tool' => 'ignore me',
                ],
            ])
            ->assertRedirect(route('settings.mcp-tokens.index'));

        $stored = json_decode((string) Setting::getValue('mcp_tool_custom_instructions'), true);
        $this->assertSame(['send_reply' => $body], $stored);

        $audit = McpAuditLog::where('method', 'token/tool_instructions')->firstOrFail();
        $this->assertSame('mcp_tool_custom_instructions', $audit->tool_name);
        $this->assertSame(['send_reply'], $audit->arguments['tools']);
        $this->assertSame(mb_strlen($body), $audit->arguments['total_length']);
        $this->assertStringNotContainsString($body, (string) json_encode($audit->arguments));
        $this->assertStringNotContainsString('ignore me', (string) json_encode($audit->arguments));
    }

    public function test_revoked_tokens_show_as_revoked_and_are_read_only_on_detail(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'dead');
        $token = McpToken::where('label', 'dead')->firstOrFail();
        $token->update(['revoked_at' => now()]);

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee('Revoked');

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.show', $token))
            ->assertOk()
            ->assertSee('Revoked');
    }
}
