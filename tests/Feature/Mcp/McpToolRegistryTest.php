<?php

namespace Tests\Feature\Mcp;

use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_are_ordered_and_classify_tools(): void
    {
        $groups = McpToolRegistry::groups();

        $this->assertSame(['general', 'client', 'integration', 'wiki_write', 'bridge'], array_keys($groups));

        $names = fn (string $group): array => array_column($groups[$group]['tools'], 'name');

        $this->assertContains('list_open_tickets', $names('general'));
        $this->assertContains('propose_close', $names('general'));
        $this->assertContains('create_ticket', $names('client'));
        $this->assertContains('ninja_get_device', $names('integration'));
        $this->assertContains('tactical_get_device', $names('integration'));
        $this->assertContains('list_teams_chats', $names('general'));
        $this->assertNotContains('tactical_run_diagnostic', $names('integration'));
        $this->assertContains('wiki_add_fact', $names('wiki_write'));
        $this->assertContains('post_to_operator', $names('bridge'));
        $this->assertTrue($groups['wiki_write']['sensitive']);
        $this->assertTrue($groups['bridge']['sensitive']);
        $this->assertFalse($groups['general']['sensitive']);
    }

    public function test_tools_carry_descriptions_and_no_group_overlap(): void
    {
        $groups = McpToolRegistry::groups();

        $bridge = collect($groups['bridge']['tools'])->firstWhere('name', 'post_to_operator');
        $this->assertNotEmpty($bridge['description']);
        $wikiWrite = collect($groups['wiki_write']['tools'])->firstWhere('name', 'wiki_add_fact');
        $this->assertNotEmpty($wikiWrite['description']);

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
        $this->assertContains('create_ticket', $all);
        $this->assertContains('tactical_get_device', $all);
        $this->assertContains('list_teams_chats', $all);
        $this->assertContains('wiki_add_fact', $all);
        $this->assertContains('post_to_operator', $all);
        $this->assertSame(array_values(array_unique($all)), $all, 'no duplicates');
    }
}
