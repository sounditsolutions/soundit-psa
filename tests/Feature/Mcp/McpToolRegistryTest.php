<?php

namespace Tests\Feature\Mcp;

use App\Models\CippMcpTool;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_are_ordered_and_classify_tools(): void
    {
        $groups = McpToolRegistry::groups();

        $this->assertSame(['general', 'client', 'integration', 'cipp_write', 'tactical_action', 'wiki_write', 'psa_action', 'bridge'], array_keys($groups));

        $names = fn (string $group): array => array_column($groups[$group]['tools'], 'name');

        $this->assertContains('list_open_tickets', $names('general'));
        $this->assertContains('propose_close', $names('general'));
        $this->assertContains('send_reply', $names('general'));
        $this->assertContains('request_tool', $names('general'));
        $this->assertNotContains('create_ticket', $names('client'));
        $this->assertContains('ninja_get_device', $names('integration'));
        $this->assertContains('tactical_get_device', $names('integration'));
        $this->assertContains('list_teams_chats', $names('general'));
        $this->assertNotContains('tactical_run_diagnostic', $names('integration'));
        $this->assertContains('wiki_add_fact', $names('wiki_write'));
        $this->assertContains('tactical_run_command', $names('tactical_action'));
        $this->assertContains('tactical_stage_command', $names('tactical_action'));
        $this->assertContains('tactical_shutdown_device', $names('tactical_action'));
        $this->assertContains('create_ticket', $names('psa_action'));
        $this->assertContains('send_email', $names('psa_action'));
        $this->assertContains('stage_email', $names('psa_action'));
        $this->assertContains('write_public_note', $names('psa_action'));
        $this->assertContains('stage_public_note', $names('psa_action'));
        $this->assertContains('propose_merge', $names('psa_action'));
        $this->assertContains('post_to_operator', $names('bridge'));
        $this->assertTrue($groups['wiki_write']['sensitive']);
        $this->assertTrue($groups['tactical_action']['sensitive']);
        $this->assertTrue($groups['psa_action']['sensitive']);
        $this->assertTrue($groups['bridge']['sensitive']);
        $this->assertTrue($groups['cipp_write']['sensitive']);
        $this->assertFalse($groups['general']['sensitive']);
    }

    public function test_dynamic_cipp_catalog_tools_are_registry_backed_and_grouped_by_sensitivity(): void
    {
        CippMcpTool::create([
            'local_name' => 'cipp_list_db_cache',
            'upstream_name' => 'ListDBCache',
            'category' => 'CIPP',
            'description' => 'List CIPP cache.',
            'input_schema' => ['type' => 'object', 'properties' => []],
            'annotations' => ['readOnlyHint' => true],
            'read_only' => true,
            'sensitive' => false,
            'active' => true,
            'last_seen_at' => now(),
        ]);
        CippMcpTool::create([
            'local_name' => 'cipp_set_user_license',
            'upstream_name' => 'SetUserLicense',
            'category' => 'Identity',
            'description' => 'Set license.',
            'input_schema' => ['type' => 'object', 'properties' => []],
            'annotations' => ['readOnlyHint' => false],
            'read_only' => false,
            'sensitive' => true,
            'active' => true,
            'last_seen_at' => now(),
        ]);

        $groups = McpToolRegistry::groups();

        $this->assertContains('cipp_list_db_cache', array_column($groups['integration']['tools'], 'name'));
        $this->assertContains('cipp_set_user_license', array_column($groups['cipp_write']['tools'], 'name'));
        $this->assertContains('cipp_list_db_cache', McpToolRegistry::allToolNames());
        $this->assertContains('cipp_set_user_license', McpToolRegistry::allToolNames());
    }

    public function test_tools_carry_descriptions_and_no_group_overlap(): void
    {
        $groups = McpToolRegistry::groups();

        $bridge = collect($groups['bridge']['tools'])->firstWhere('name', 'post_to_operator');
        $this->assertNotEmpty($bridge['description']);
        $wikiWrite = collect($groups['wiki_write']['tools'])->firstWhere('name', 'wiki_add_fact');
        $this->assertNotEmpty($wikiWrite['description']);
        $psaAction = collect($groups['psa_action']['tools'])->firstWhere('name', 'send_email');
        $this->assertNotEmpty($psaAction['description']);

        $seen = [];
        foreach ($groups as $group) {
            foreach ($group['tools'] as $tool) {
                $this->assertArrayNotHasKey($tool['name'], $seen, "duplicate across groups: {$tool['name']}");
                $seen[$tool['name']] = true;
            }
        }
    }

    public function test_all_tool_names_is_flat_deduped_superset(): void
    {
        $all = McpToolRegistry::allToolNames();

        $this->assertContains('list_open_tickets', $all);
        $this->assertContains('propose_close', $all);
        $this->assertContains('send_reply', $all);
        $this->assertContains('request_tool', $all);
        $this->assertContains('create_ticket', $all);
        $this->assertContains('tactical_get_device', $all);
        $this->assertContains('list_teams_chats', $all);
        $this->assertContains('wiki_add_fact', $all);
        $this->assertContains('tactical_run_command', $all);
        $this->assertContains('tactical_stage_command', $all);
        $this->assertContains('tactical_shutdown_device', $all);
        $this->assertContains('send_email', $all);
        $this->assertContains('stage_email', $all);
        $this->assertContains('write_public_note', $all);
        $this->assertContains('stage_public_note', $all);
        $this->assertContains('propose_merge', $all);
        $this->assertContains('post_to_operator', $all);
        $this->assertSame(array_values(array_unique($all)), $all, 'no duplicates');
    }
}
