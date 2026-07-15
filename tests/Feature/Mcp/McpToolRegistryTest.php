<?php

namespace Tests\Feature\Mcp;

use App\Models\CippMcpTool;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_are_ordered_and_classify_tools(): void
    {
        $groups = McpToolRegistry::groups();

        $this->assertSame(['general', 'client', 'integration', 'cipp_write', 'tactical_action', 'tactical_admin', 'wiki_write', 'psa_action', 'psa_records', 'psa_read', 'intake_manage', 'bridge'], array_keys($groups));

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
        $this->assertNotContains('tactical_stage_command', $names('tactical_action'));
        $this->assertContains('tactical_shutdown_device', $names('tactical_action'));
        $this->assertContains('tactical_create_client_site', $names('tactical_admin'));
        $this->assertContains('tactical_set_default_alert_template', $names('tactical_admin'));
        $this->assertContains('tactical_get_or_create_installer', $names('tactical_admin'));
        $this->assertContains('create_ticket', $names('psa_action'));
        $this->assertContains('send_email', $names('psa_action'));
        $this->assertNotContains('stage_email', $names('psa_action'));
        $this->assertContains('write_public_note', $names('psa_action'));
        $this->assertNotContains('stage_public_note', $names('psa_action'));
        $this->assertContains('propose_merge', $names('psa_action'));
        $this->assertContains('create_client', $names('psa_records'));
        $this->assertContains('update_client', $names('psa_records'));
        $this->assertContains('update_client_site_notes', $names('psa_records'));
        $this->assertContains('delete_client', $names('psa_records'));
        $this->assertContains('list_client_contracts', $names('psa_read'));
        $this->assertContains('get_contract', $names('psa_read'));
        $this->assertContains('link_email_to_ticket', $names('intake_manage'));
        $this->assertContains('create_ticket_from_email', $names('intake_manage'));
        $this->assertContains('dismiss_email_item', $names('intake_manage'));
        $this->assertContains('link_call_to_ticket', $names('intake_manage'));
        $this->assertContains('create_ticket_from_call', $names('intake_manage'));
        $this->assertContains('post_to_operator', $names('bridge'));
        $this->assertTrue($groups['wiki_write']['sensitive']);
        $this->assertTrue($groups['tactical_action']['sensitive']);
        $this->assertTrue($groups['tactical_admin']['sensitive']);
        $this->assertTrue($groups['psa_action']['sensitive']);
        $this->assertTrue($groups['psa_records']['sensitive']);
        $this->assertTrue($groups['psa_read']['sensitive']);
        $this->assertTrue($groups['intake_manage']['sensitive']);
        $this->assertTrue($groups['bridge']['sensitive']);
        $this->assertTrue($groups['cipp_write']['sensitive']);
        $this->assertFalse($groups['general']['sensitive']);
    }

    /**
     * A live dynamic cipp read tool is registry-backed and grouped as an integration read.
     * Under the default-deny allow-list only the reviewed tools are live, so the read tool
     * is the approved cipp_list_graph_request; an unapproved read tool AND an unapproved
     * (would-be write-tier) tool are both excluded from the registry entirely — the
     * inversion holds at the registry surface too, not just at import (psa-3g8y).
     */
    public function test_dynamic_cipp_catalog_tools_are_registry_backed_and_grouped_by_sensitivity(): void
    {
        CippMcpTool::create([
            'local_name' => 'cipp_list_graph_request',
            'upstream_name' => 'ListGraphRequest',
            'category' => 'CIPP',
            'description' => 'Generic Graph request.',
            'input_schema' => ['type' => 'object', 'properties' => []],
            'annotations' => ['readOnlyHint' => true],
            'read_only' => true,
            'sensitive' => false,
            'active' => true,
            'last_seen_at' => now(),
        ]);
        // An unreviewed read tool and an unreviewed write tool from the long tail — both
        // belong in the grant catalog, tiered by sensitivity.
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

        // Read tools are registry-backed and grouped as integration reads.
        $this->assertContains('cipp_list_graph_request', array_column($groups['integration']['tools'], 'name'));
        $this->assertContains('cipp_list_graph_request', McpToolRegistry::allToolNames());

        // The unreviewed long tail is registry-backed too, and MUST be: groups() is the
        // grant catalog an operator picks from, and honoring explicit grants is meaningless
        // if the tool cannot be seen to be granted. psa-3g8y's allow-list dropped these rows
        // from the catalog entirely, so an operator could neither keep an existing grant nor
        // make a new one — the drift that made the hardcoded list untenable (psa-pzwv).
        // Being catalogued is not being live: nothing here reaches an agent without an
        // explicit per-token grant (McpStaffController::toolAllowed()).
        $this->assertContains('cipp_list_db_cache', McpToolRegistry::allToolNames());
        $this->assertContains('cipp_list_db_cache', array_column($groups['integration']['tools'], 'name'));

        // Sensitivity tiering holds: a write-class row is catalogued under the sensitive
        // cipp_write group, never as an integration read.
        $this->assertContains('cipp_set_user_license', array_column($groups['cipp_write']['tools'], 'name'));
        $this->assertNotContains('cipp_set_user_license', array_column($groups['integration']['tools'], 'name'));
        $this->assertTrue($groups['cipp_write']['sensitive']);
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

    public function test_huntress_p1_reads_are_registry_backed_and_mapped_to_a_huntress_card(): void
    {
        $huntressReads = [
            'huntress_list_incident_reports',
            'huntress_get_incident_report',
            'huntress_list_escalations',
            'huntress_get_escalation',
            'huntress_list_organizations',
            'huntress_get_organization',
        ];

        $integrationNames = array_column(McpToolRegistry::groups()['integration']['tools'], 'name');

        foreach ($huntressReads as $tool) {
            $this->assertContains($tool, $integrationNames, "{$tool} should be in the normal integration tier");
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable + MSP-appendix-able");
            $this->assertSame('huntress', McpToolRegistry::integrationForToolName($tool), "{$tool} must route to the huntress integration");
        }

        $this->assertArrayHasKey('huntress', McpToolRegistry::integrationMeta(), 'a Huntress card must exist on the token page');
        $this->assertArrayHasKey('huntress', McpToolRegistry::integrationGroups(), 'Huntress reads must render under their own card');
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
        $this->assertContains('tactical_shutdown_device', $all);
        $this->assertContains('tactical_create_client_site', $all);
        $this->assertContains('tactical_set_default_alert_template', $all);
        $this->assertContains('tactical_get_or_create_installer', $all);
        $this->assertContains('send_email', $all);
        $this->assertContains('write_public_note', $all);
        $this->assertContains('propose_merge', $all);
        $this->assertContains('post_to_operator', $all);
        $this->assertSame(array_values(array_unique($all)), $all, 'no duplicates');
    }

    public function test_staged_aliases_are_retired_from_the_catalog_but_map_to_their_canonical_tool(): void
    {
        $all = McpToolRegistry::allToolNames();

        foreach (McpToolModes::stagedToCanonical() as $alias => $canonical) {
            $this->assertNotContains($alias, $all, "{$alias} is a retired staged alias and must not be grantable");
            $this->assertContains($canonical, $all, "{$canonical} must remain the grantable capability for {$alias}");
            $this->assertSame($canonical, McpToolModes::canonicalForAlias($alias));
            $this->assertSame($alias, McpToolModes::stagedInternalFor($canonical));
            $this->assertTrue(McpToolModes::isStageable($canonical));
        }

        // The token detail page flags stageable capabilities for the per-tool
        // staged/immediate mode control.
        $flags = [];
        foreach (McpToolRegistry::integrationGroups() as $group) {
            foreach ($group['tiers'] as $tier) {
                foreach ($tier['tools'] as $tool) {
                    $flags[$tool['name']] = $tool['stageable'] ?? null;
                }
            }
        }
        $this->assertTrue($flags['send_email']);
        $this->assertTrue($flags['tactical_run_script']);
        $this->assertTrue($flags['cipp_convert_mailbox'] ?? true, 'cipp tools only render when configured');
        $this->assertFalse($flags['create_ticket']);
        $this->assertFalse($flags['find_clients'] ?? false);
    }
}
