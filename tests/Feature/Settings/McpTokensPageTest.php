<?php

namespace Tests\Feature\Settings;

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
}
