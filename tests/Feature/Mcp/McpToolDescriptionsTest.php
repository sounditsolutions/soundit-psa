<?php

namespace Tests\Feature\Mcp;

use App\Models\Setting;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpToolDescriptionsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array<string, mixed>> */
    private function listTools(string $token): array
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ]);

        $response->assertOk();

        return $response->json('result.tools') ?? [];
    }

    public function test_tools_list_appends_msp_custom_instruction_to_matching_tool_only(): void
    {
        Setting::setValue('mcp_tool_custom_instructions', json_encode([
            'find_staff' => 'Prefer the escalation roster before broad staff searches.',
            'send_reply' => 'Mention after-hours coverage when drafting customer updates.',
        ]));
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff', 'get_staff'], label: 'catalog');

        $tools = collect($this->listTools($token));
        $findStaff = $tools->firstWhere('name', 'find_staff');
        $getStaff = $tools->firstWhere('name', 'get_staff');

        $this->assertIsArray($findStaff);
        $this->assertStringContainsString(
            "MSP custom instructions:\nPrefer the escalation roster before broad staff searches.",
            $findStaff['description'],
        );
        $this->assertIsArray($getStaff);
        $this->assertStringNotContainsString('MSP custom instructions:', $getStaff['description']);

        $registryFindStaff = collect(McpToolRegistry::groups()['bridge']['tools'])->firstWhere('name', 'find_staff');
        $this->assertStringNotContainsString(
            'Prefer the escalation roster',
            $registryFindStaff['description'],
        );
    }

    public function test_exposed_mcp_descriptions_are_platform_neutral_and_policy_free(): void
    {
        $this->configureRuntimeCatalogProviders();

        $descriptions = [];
        foreach (McpToolRegistry::groups() as $groupKey => $group) {
            foreach ($group['tools'] as $tool) {
                $descriptions["registry.{$groupKey}.{$tool['name']}"] = $tool['description'];
            }
        }

        $token = McpConfig::rotateStaffToken(
            allowedTools: McpToolRegistry::allToolNames(),
            label: 'catalog',
        );

        foreach ($this->listTools($token) as $tool) {
            $this->collectDescriptions($tool, "tools_list.{$tool['name']}", $descriptions);
        }

        $forbidden = ['Chet', 'teammate-chet', 'teams-bot', 'Always confirm', 'Charlie review'];

        foreach ($descriptions as $path => $description) {
            foreach ($forbidden as $phrase) {
                $this->assertStringNotContainsString(
                    $phrase,
                    $description,
                    "{$path} description contains forbidden phrase '{$phrase}': {$description}",
                );
            }
        }
    }

    private function configureRuntimeCatalogProviders(): void
    {
        Setting::setValue('teams_bot_app_id', 'bot-app-id');
        Setting::setValue('teams_bot_tenant_id', 'tenant-1');
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'client-1');
        Setting::setEncrypted('cipp_client_secret', 'secret');
        Setting::setEncrypted('mesh_api_key', 'secret');
    }

    /**
     * @param  array<string, string>  $descriptions
     */
    private function collectDescriptions(mixed $value, string $path, array &$descriptions): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $childPath = is_string($key) ? "{$path}.{$key}" : "{$path}.{$key}";
            if ($key === 'description' && is_scalar($child)) {
                $descriptions[$childPath] = (string) $child;

                continue;
            }

            $this->collectDescriptions($child, $childPath, $descriptions);
        }
    }
}
