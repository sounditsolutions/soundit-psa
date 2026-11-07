<?php

namespace Tests\Feature\Mcp;

use App\Models\Setting;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class McpRootSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_advertised_tool_schema_roots_are_plain_objects_in_both_modes(): void
    {
        Http::preventStrayRequests();
        Http::fake([]);
        foreach ([
            'teams_bot_app_id' => 'synthetic-bot', 'teams_bot_tenant_id' => 'synthetic-tenant',
            'tactical_api_url' => 'https://tactical.example.test',
            'cipp_enabled' => '1', 'cipp_api_url' => 'https://cipp.example.test',
            'cipp_tenant_id' => 'synthetic-tenant', 'cipp_client_id' => 'synthetic-client',
        ] as $key => $value) {
            Setting::setValue($key, $value);
        }
        foreach (['tactical_api_key', 'cipp_client_secret', 'mesh_api_key'] as $key) {
            Setting::setEncrypted($key, 'synthetic-value');
        }
        $names = McpToolRegistry::allToolNames();
        $this->assertContains('cipp_set_mailbox_delegate', $names);
        foreach ([false, true] as $held) {
            $grants = array_map(fn ($name) => $held && McpToolModes::isStageable($name) ? $name.':staged' : $name, $names);
            $token = McpConfig::rotateStaffToken(allowedTools: $grants, label: 'synthetic-schema');
            $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
            ])->assertOk();
            $tools = $response->json('result.tools');
            $this->assertNotEmpty($tools);
            $this->assertContains('cipp_set_mailbox_delegate', array_column($tools, 'name'));
            foreach ($tools as $tool) {
                $this->assertSame('object', $tool['inputSchema']['type'], $tool['name']);
                foreach (['oneOf', 'anyOf', 'allOf'] as $key) {
                    $this->assertArrayNotHasKey($key, $tool['inputSchema'], $tool['name']);
                }
            }
        }
        Http::assertNothingSent();
    }
}
