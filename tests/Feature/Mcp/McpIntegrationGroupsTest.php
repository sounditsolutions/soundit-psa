<?php

namespace Tests\Feature\Mcp;

use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpIntegrationGroupsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array{name: string, tier: array<string, mixed>, integration: string}> */
    private function flatten(): array
    {
        $rows = [];
        foreach (McpToolRegistry::integrationGroups() as $integration => $group) {
            foreach ($group['tiers'] as $tier) {
                foreach ($tier['tools'] as $tool) {
                    $rows[] = ['name' => $tool['name'], 'tier' => $tier, 'integration' => $integration];
                }
            }
        }

        return $rows;
    }

    public function test_every_tool_lands_in_exactly_one_integration_tier(): void
    {
        $rows = $this->flatten();
        $placed = array_column($rows, 'name');

        // No tool appears twice.
        $this->assertSame(array_values(array_unique($placed)), $placed, 'a tool is placed more than once');

        // Every registry tool is placed — nothing uncategorised.
        $all = McpToolRegistry::allToolNames();
        sort($all);
        $placedSorted = $placed;
        sort($placedSorted);
        $this->assertSame($all, $placedSorted, 'integrationGroups must cover exactly allToolNames()');
    }

    public function test_tools_route_to_the_expected_integration_and_tier(): void
    {
        $rows = $this->flatten();
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row;
        }

        $expect = function (string $name, string $integration, bool $sensitive) use ($byName): void {
            $this->assertArrayHasKey($name, $byName, "{$name} was not placed");
            $this->assertSame($integration, $byName[$name]['integration'], "{$name} integration");
            $this->assertSame($sensitive, $byName[$name]['tier']['sensitive'], "{$name} sensitivity");
        };

        $expect('list_open_tickets', 'psa', false);
        $expect('create_ticket', 'psa', true);
        $expect('send_email', 'psa', true);
        $expect('tactical_get_device', 'tactical', false);
        $expect('tactical_run_command', 'tactical', true);
        $expect('ninja_get_device', 'ninja', false);
        $expect('wiki_add_fact', 'wiki', true);
        $expect('post_to_operator', 'teams', true);
        $expect('list_teams_chats', 'teams', false);
    }

    public function test_integration_group_carries_counts_and_metadata(): void
    {
        $groups = McpToolRegistry::integrationGroups();

        $this->assertArrayHasKey('psa', $groups);
        $psa = $groups['psa'];
        $this->assertSame('PSA Core', $psa['label']);
        $this->assertNotEmpty($psa['blurb']);
        $this->assertGreaterThan(0, $psa['total']);
        $this->assertGreaterThan(0, $psa['sensitive_count']);

        // The Read tier sorts before the sensitive Write tier.
        $this->assertFalse($psa['tiers'][0]['sensitive']);
        $this->assertSame('Read', $psa['tiers'][0]['label']);

        // integrationForToolName covers every vendor prefix (spot check).
        $this->assertSame('other', McpToolRegistry::integrationForToolName('mesh_search_email_logs'));
        $this->assertSame('other', McpToolRegistry::integrationForToolName('level_get_device'));
        $this->assertSame('psa', McpToolRegistry::integrationForToolName('get_queue_stats'));
    }
}
