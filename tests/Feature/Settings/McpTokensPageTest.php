<?php

namespace Tests\Feature\Settings;

use App\Models\McpAuditLog;
use App\Models\McpToken;
use App\Models\Setting;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalInboxEntry;
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
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee('chet')
            ->assertSee(route('settings.mcp-tokens.show', $token), false)
            ->assertSee('post_to_operator')
            ->assertSee('send_reply')
            ->assertSee('request_tool')
            ->assertSee('list_open_tickets');
    }

    public function test_detail_page_shows_tools_directive_and_audit_log(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();
        McpAuditLog::create([
            'server_name' => 'staff',
            'method' => 'tools/call',
            'tool_name' => 'find_staff',
            'arguments' => [],
            'status' => 'success',
            'duration_ms' => 1,
            'actor_label' => 'mcp-staff:chet',
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.show', $token))
            ->assertOk()
            ->assertSee('Directive')
            ->assertSee('Alerts Hub Destinations')
            ->assertSee(McpToken::defaultDirective())
            ->assertSee('find_staff')
            ->assertSee('tools/call');
    }

    public function test_detail_page_updates_tools_and_directive(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)
            ->patch(route('settings.mcp-tokens.tools', $token), ['tools' => ['get_staff']])
            ->assertRedirect(route('settings.mcp-tokens.show', $token));
        $this->assertSame(['get_staff'], $token->fresh()->tools);

        $this->actingAs($this->user)
            ->patch(route('settings.mcp-tokens.directive', $token), ['directive' => 'Use Chet rules.'])
            ->assertRedirect(route('settings.mcp-tokens.show', $token));
        $this->assertSame('Use Chet rules.', $token->fresh()->directive);

        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'token/tools',
            'tool_name' => 'chet',
        ]);
        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'token/directive',
            'tool_name' => 'chet',
        ]);
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

    public function test_store_applies_safer_trust_defaults_and_audits_flags(): void
    {
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'opsbot',
            'tools' => ['find_staff'],
        ])->assertOk();

        $row = McpToken::where('label', 'opsbot')->firstOrFail();
        $this->assertFalse($row->ai_actor);
        $this->assertTrue($row->require_explicit_client_scope);

        $audit = McpAuditLog::where('method', 'token/mint')->where('tool_name', 'opsbot')->firstOrFail();
        $this->assertSame([
            'tools' => ['find_staff'],
            'ai_actor' => false,
            'require_explicit_client_scope' => true,
        ], $audit->arguments);
    }

    public function test_store_preserves_existing_trust_flags_when_rotating_tokens(): void
    {
        McpConfig::rotateStaffToken(
            allowedTools: ['find_staff'],
            label: 'chet',
            aiActor: true,
            requireExplicitClientScope: true,
        );
        McpConfig::rotateStaffToken(
            allowedTools: ['find_staff'],
            label: 'office-teams-pack',
            aiActor: false,
            requireExplicitClientScope: false,
        );

        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'chet',
            'tools' => ['get_staff'],
            'ai_actor' => '0',
            'require_explicit_client_scope' => '0',
        ])->assertOk();

        $chet = McpToken::where('label', 'chet')->firstOrFail();
        $this->assertTrue($chet->ai_actor);
        $this->assertTrue($chet->require_explicit_client_scope);
        $this->assertSame(['get_staff'], $chet->tools);

        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'office-teams-pack',
            'tools' => ['get_staff'],
            'ai_actor' => '0',
            'require_explicit_client_scope' => '1',
        ])->assertOk();

        $teams = McpToken::where('label', 'office-teams-pack')->firstOrFail();
        $this->assertFalse($teams->ai_actor);
        $this->assertFalse($teams->require_explicit_client_scope);
        $this->assertSame(['get_staff'], $teams->tools);
    }

    public function test_store_preserves_existing_trust_flags_when_submitted_label_normalizes_to_existing_token(): void
    {
        McpConfig::rotateStaffToken(
            allowedTools: ['find_staff'],
            label: 'chet',
            aiActor: true,
            requireExplicitClientScope: true,
        );

        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => '-chet-',
            'tools' => ['get_staff'],
            'ai_actor' => '0',
            'require_explicit_client_scope' => '0',
        ])->assertOk();

        $this->assertSame(1, McpToken::where('label', 'chet')->count());
        $chet = McpToken::where('label', 'chet')->firstOrFail();
        $this->assertTrue($chet->ai_actor);
        $this->assertTrue($chet->require_explicit_client_scope);
        $this->assertSame(['get_staff'], $chet->tools);
    }

    public function test_store_treats_revoked_label_as_new_token_for_trust_defaults(): void
    {
        McpConfig::rotateStaffToken(
            allowedTools: ['find_staff'],
            label: 'office-teams-pack',
            aiActor: false,
            requireExplicitClientScope: false,
        );
        McpToken::where('label', 'office-teams-pack')->firstOrFail()
            ->forceFill(['revoked_at' => now()])
            ->save();

        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'office-teams-pack',
            'tools' => ['get_staff'],
        ])->assertOk();

        $token = McpToken::where('label', 'office-teams-pack')->firstOrFail();
        $this->assertNull($token->revoked_at);
        $this->assertFalse($token->ai_actor);
        $this->assertTrue($token->require_explicit_client_scope);
        $this->assertSame(['get_staff'], $token->tools);
    }

    public function test_detail_page_updates_trust_flags(): void
    {
        McpConfig::rotateStaffToken(
            allowedTools: ['find_staff'],
            label: 'opsbot',
            aiActor: false,
            requireExplicitClientScope: false,
        );
        $token = McpToken::where('label', 'opsbot')->firstOrFail();

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.show', $token))
            ->assertOk()
            ->assertSee('Trust Controls')
            ->assertSee('name="ai_actor"', false)
            ->assertSee('name="require_explicit_client_scope"', false);

        $this->actingAs($this->user)
            ->patch(route('settings.mcp-tokens.trust-flags', $token), [
                'ai_actor' => '1',
                'require_explicit_client_scope' => '1',
            ])
            ->assertRedirect(route('settings.mcp-tokens.show', $token));

        $this->assertTrue($token->fresh()->ai_actor);
        $this->assertTrue($token->fresh()->require_explicit_client_scope);
        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'token/trust_flags',
            'tool_name' => 'opsbot',
        ]);
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

    public function test_revoke_clears_pending_signal_inbox_rows_for_that_token_label(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['poll_signals'], label: 'chet');
        McpConfig::rotateStaffToken(allowedTools: ['poll_signals'], label: 'other');
        $token = McpToken::where('label', 'chet')->firstOrFail();
        [$entry, $delivery] = $this->signalInboxRowForToken('chet');
        [$otherEntry, $otherDelivery] = $this->signalInboxRowForToken('other');

        $this->actingAs($this->user)
            ->delete(route('settings.mcp-tokens.revoke', $token))
            ->assertRedirect(route('settings.mcp-tokens.index'));

        $this->assertDatabaseMissing('signal_inbox', ['id' => $entry->id]);
        $this->assertSame('suppressed', $delivery->fresh()->status);
        $this->assertSame('token-revoked', $delivery->fresh()->error);
        $this->assertDatabaseHas('signal_inbox', ['id' => $otherEntry->id]);
        $this->assertSame('delivered', $otherDelivery->fresh()->status);
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

    public function test_index_renders_msp_tool_instruction_textareas(): void
    {
        Setting::setValue('mcp_tool_custom_instructions', json_encode([
            'find_staff' => 'Prefer escalation owners.',
        ]));

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee('MSP Tool Instructions')
            ->assertSee('name="tool_instructions[find_staff]"', false)
            ->assertSee('name="tool_instructions[send_reply]"', false)
            ->assertSee('Prefer escalation owners.');
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
        $this->assertSame([
            'tools' => ['find_staff', 'get_staff'],
            'ai_actor' => false,
            'require_explicit_client_scope' => true,
        ], $row->arguments);
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

    private function signalInboxRowForToken(string $tokenLabel): array
    {
        $destination = SignalDestination::create([
            'label' => 'Destination '.$tokenLabel,
            'type' => 'mcp',
            'mcp_token_label' => $tokenLabel,
        ]);
        $event = SignalEvent::create([
            'type_key' => 'agent.flag_attention',
            'entity_type' => 'ticket',
            'entity_id' => 123,
            'summary' => 'Signal event',
            'context' => [],
            'occurred_at' => now(),
        ]);
        $delivery = SignalDelivery::create([
            'event_id' => $event->id,
            'route_id' => null,
            'step_order' => 1,
            'destination_id' => $destination->id,
            'status' => 'delivered',
        ]);
        $entry = SignalInboxEntry::create([
            'destination_id' => $destination->id,
            'event_id' => $event->id,
            'delivery_id' => $delivery->id,
            'payload' => ['event' => 'agent.flag_attention'],
        ]);

        return [$entry, $delivery];
    }
}
