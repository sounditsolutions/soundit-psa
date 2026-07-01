<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\VerifyMcpStaffToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpStaffTokenGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_verified_token_attribute_denies_ordinary_tools(): void
    {
        $response = $this->withoutMiddleware(VerifyMcpStaffToken::class)
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'get_queue_stats', 'arguments' => []],
            ]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
    }
}
