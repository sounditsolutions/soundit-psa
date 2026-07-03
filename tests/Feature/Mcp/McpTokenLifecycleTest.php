<?php

namespace Tests\Feature\Mcp;

use App\Models\McpToken;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_is_derived_with_revoked_over_paused_over_active_over_draft(): void
    {
        $token = new McpToken;
        $this->assertSame('draft', $token->state());
        $this->assertTrue($token->isDraft());

        $token->activated_at = now();
        $this->assertSame('active', $token->state());
        $this->assertTrue($token->isActive());

        $token->paused_at = now();
        $this->assertSame('paused', $token->state());
        $this->assertTrue($token->isPaused());

        $token->revoked_at = now();
        $this->assertSame('revoked', $token->state());
        $this->assertTrue($token->isRevoked());
    }

    public function test_draft_token_does_not_authenticate(): void
    {
        $plain = McpConfig::mintDraftToken('draft-token');
        $token = McpToken::where('label', 'draft-token')->firstOrFail();

        $this->assertTrue($token->isDraft());
        $this->assertNull($token->activated_at);
        $this->assertSame([], $token->tools);
        $this->assertNull(McpConfig::resolveStaffToken($plain), 'a draft token must not authenticate');
    }

    public function test_activating_a_draft_lets_it_authenticate(): void
    {
        $plain = McpConfig::mintDraftToken('chet-tactical');
        $token = McpToken::where('label', 'chet-tactical')->firstOrFail();

        $token->forceFill(['activated_at' => now()])->save();

        $this->assertTrue($token->fresh()->isActive());
        $this->assertNotNull(McpConfig::resolveStaffToken($plain), 'an active token must authenticate');
    }

    public function test_paused_token_does_not_authenticate(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'paused-token');
        $token = McpToken::where('label', 'paused-token')->firstOrFail();
        $this->assertNotNull(McpConfig::resolveStaffToken($plain));

        $token->forceFill(['paused_at' => now()])->save();

        $this->assertTrue($token->fresh()->isPaused());
        $this->assertNull(McpConfig::resolveStaffToken($plain), 'a paused token must not authenticate');
    }

    public function test_revoked_token_does_not_authenticate(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'revoked-token');
        $token = McpToken::where('label', 'revoked-token')->firstOrFail();

        $token->forceFill(['revoked_at' => now()])->save();

        $this->assertNull(McpConfig::resolveStaffToken($plain));
    }

    public function test_rotate_staff_token_yields_an_active_token_that_authenticates(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'break-glass');
        $token = McpToken::where('label', 'break-glass')->firstOrFail();

        $this->assertTrue($token->isActive(), 'programmatic rotation must yield an active token');
        $this->assertNotNull(McpConfig::resolveStaffToken($plain));
    }

    public function test_api_gate_rejects_a_draft_token_as_unauthorized(): void
    {
        $plain = McpConfig::mintDraftToken('gate-draft');

        $response = $this->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'get_queue_stats', 'arguments' => []],
        ], ['Authorization' => 'Bearer '.$plain]);

        $response->assertStatus(401);
        $this->assertSame('Unauthorized', $response->json('error.message'));
    }

    public function test_api_gate_accepts_an_active_token(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['get_queue_stats'], label: 'gate-active');

        $response = $this->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'get_queue_stats', 'arguments' => []],
        ], ['Authorization' => 'Bearer '.$plain]);

        // Authorized: the gate passes (the tool itself may succeed or return a
        // domain result, but it is NOT a 401 Unauthorized).
        $response->assertStatus(200);
        $this->assertNotSame('Unauthorized', $response->json('error.message'));
    }
}
