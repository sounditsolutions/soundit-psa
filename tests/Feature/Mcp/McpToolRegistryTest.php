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

    public function test_billable_time_registration_count(): void
    {
        $names = array_column(McpToolRegistry::intakeManageTools(), 'name');
        $this->assertCount(21, $names);
        $this->assertContains('set_billable_time', $names);
        $this->assertContains('stage_set_billable_time', $names);
        $this->assertContains('set_billable_time', McpToolRegistry::allToolNames());
        $this->assertNotContains('stage_set_billable_time', McpToolRegistry::allToolNames());
    }

    public function test_groups_are_ordered_and_classify_tools(): void
    {
        $groups = McpToolRegistry::groups();

        $this->assertSame(['general', 'client', 'integration', 'cipp_write', 'cipp_admin', 'carbon_write', 'tactical_action', 'tactical_admin', 'huntress_action', 'mesh_read', 'mesh_admin', 'controld_onboarding', 'wiki_write', 'psa_action', 'psa_records', 'psa_read', 'psa_raw_file', 'intake_manage', 'taxonomy', 'calendar', 'calendar_write', 'bridge', 'asset_watch'], array_keys($groups));

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
        $this->assertContains('cipp_sync_people_now', $names('cipp_admin'));
        $this->assertNotContains('cipp_sync_people_now', $names('cipp_write'));
        $this->assertContains('create_ticket', $names('psa_action'));
        $this->assertContains('send_email', $names('psa_action'));
        $this->assertNotContains('stage_email', $names('psa_action'));
        $this->assertContains('write_public_note', $names('psa_action'));
        $this->assertNotContains('stage_public_note', $names('psa_action'));
        $this->assertContains('merge_ticket', $names('psa_action'));
        $this->assertNotContains('propose_merge', $names('psa_action'));
        $this->assertContains('merge_asset', $names('psa_action'));
        $this->assertNotContains('propose_asset_merge', $names('psa_action'));
        $this->assertContains('create_client', $names('psa_records'));
        $this->assertContains('update_client', $names('psa_records'));
        $this->assertContains('update_client_site_notes', $names('psa_records'));
        $this->assertContains('delete_client', $names('psa_records'));
        $this->assertContains('list_client_contracts', $names('psa_read'));
        $this->assertContains('get_contract', $names('psa_read'));
        // Explicit-grant-only at the gate, so never a plain client-scoped read here (psa-688).
        $this->assertNotContains('get_ticket_attachment', $names('client'));
        $this->assertContains('get_ticket_attachment', $names('psa_raw_file'));
        $this->assertContains('link_email_to_ticket', $names('intake_manage'));
        $this->assertContains('create_ticket_from_email', $names('intake_manage'));
        $this->assertContains('dismiss_email_item', $names('intake_manage'));
        $this->assertContains('link_call_to_ticket', $names('intake_manage'));
        $this->assertContains('create_ticket_from_call', $names('intake_manage'));
        $this->assertContains('list_ticket_categories', $names('taxonomy'));
        $this->assertContains('get_ticket_category', $names('taxonomy'));
        $this->assertContains('create_ticket_category', $names('taxonomy'));
        $this->assertContains('update_ticket_category', $names('taxonomy'));
        $this->assertContains('retire_ticket_category', $names('taxonomy'));
        $this->assertContains('set_ticket_category_sop', $names('taxonomy'));
        $this->assertContains('post_to_operator', $names('bridge'));
        $this->assertTrue($groups['wiki_write']['sensitive']);
        $this->assertTrue($groups['tactical_action']['sensitive']);
        $this->assertTrue($groups['tactical_admin']['sensitive']);
        $this->assertTrue($groups['psa_action']['sensitive']);
        $this->assertTrue($groups['psa_records']['sensitive']);
        $this->assertTrue($groups['psa_read']['sensitive']);
        $this->assertTrue($groups['psa_raw_file']['sensitive']);
        $this->assertTrue($groups['intake_manage']['sensitive']);
        $this->assertTrue($groups['taxonomy']['sensitive']);
        $this->assertTrue($groups['calendar']['sensitive']);
        $this->assertTrue($groups['bridge']['sensitive']);
        $this->assertTrue($groups['cipp_write']['sensitive']);
        $this->assertContains('controld_onboard_client', $names('controld_onboarding'));
        $this->assertNotContains('controld_stage_onboard_client', $names('controld_onboarding'));
        $this->assertTrue($groups['controld_onboarding']['sensitive']);
        $this->assertFalse($groups['general']['sensitive']);
        // Asset watch alerts (card K3VEcxtw): their own sensitive tier, never general/client.
        $this->assertSame(['create_asset_watch', 'list_asset_watches', 'remove_asset_watch'], $names('asset_watch'));
        $this->assertTrue($groups['asset_watch']['sensitive']);
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

    /**
     * Card 6abec4f9 (Charlie's ruling, 2026-10-01): the BenjiPays reads render
     * under their own BenjiPays card on the token page, not PSA Core. They stay
     * in psa_read (grant gate and sensitive Reads tier unchanged); the card is
     * display only, since grants are stored and checked by tool name.
     */
    public function test_benjipays_reads_render_under_a_benjipays_card_not_psa_core(): void
    {
        $benjipays = [
            'benjipays_autopay_forecast',
            'benjipays_list_transactions',
            'benjipays_get_invoice',
            'benjipays_get_customer_payment_methods',
            'benjipays_list_sent_emails',
            'benjipays_get_settings',
        ];

        $groups = McpToolRegistry::integrationGroups();
        $this->assertArrayHasKey('benjipays', McpToolRegistry::integrationMeta(), 'a BenjiPays card must exist on the token page');
        $this->assertArrayHasKey('benjipays', $groups, 'BenjiPays reads must render under their own card');
        $this->assertSame('BenjiPays', $groups['benjipays']['label']);

        $onCard = [];
        foreach ($groups['benjipays']['tiers'] as $tier) {
            foreach ($tier['tools'] as $tool) {
                $onCard[$tool['name']] = $tier;
            }
        }
        $psaNames = [];
        foreach ($groups['psa']['tiers'] as $tier) {
            $psaNames = [...$psaNames, ...array_column($tier['tools'], 'name')];
        }

        $psaRead = array_column(McpToolRegistry::groups()['psa_read']['tools'], 'name');
        foreach ($benjipays as $tool) {
            $this->assertSame('benjipays', McpToolRegistry::integrationForToolName($tool), "{$tool} must route to the benjipays integration");
            $this->assertArrayHasKey($tool, $onCard, "{$tool} must render on the BenjiPays card");
            $this->assertNotContains($tool, $psaNames, "{$tool} must not render under PSA Core");
            $this->assertSame(['read', 'Reads', true], [$onCard[$tool]['key'], $onCard[$tool]['label'], $onCard[$tool]['sensitive']], "{$tool} keeps the sensitive Reads tier");
            $this->assertContains($tool, $psaRead, "{$tool} stays grant-gated in psa_read");
        }
        $this->assertSame(count($benjipays), $groups['benjipays']['total']);
        $this->assertSame('psa', McpToolRegistry::integrationForToolName('list_invoices'), 'PSA-native invoice reads stay under PSA Core');
    }

    /**
     * Card 2Cj3kOsy (Charlie's correction, 2026-10-02): the Teams image reader renders
     * on the Teams & Operator card with the other Teams grants, not under PSA Core. It
     * stays in psa_raw_file, keeping its own sensitive "Attachment content" tier and
     * shield; the card is display only, since grants are stored and checked by tool name.
     */
    public function test_teams_attachment_reader_renders_on_the_teams_card_in_its_own_sensitive_tier(): void
    {
        $tool = 'get_teams_message_attachment';
        $groups = McpToolRegistry::integrationGroups();

        $tiersOf = function (string $integration) use ($groups): array {
            $out = [];
            foreach ($groups[$integration]['tiers'] as $tier) {
                foreach ($tier['tools'] as $t) {
                    $out[$t['name']] = $tier;
                }
            }

            return $out;
        };
        $teams = $tiersOf('teams');
        $psa = $tiersOf('psa');

        $this->assertArrayHasKey($tool, $teams, 'the Teams image reader must render on the Teams & Operator card');
        $this->assertArrayNotHasKey($tool, $psa, 'the Teams image reader must not render under PSA Core');
        $this->assertSame(['raw_file', 'Attachment content', true], [$teams[$tool]['key'], $teams[$tool]['label'], $teams[$tool]['sensitive']], 'it keeps its own sensitive Attachment content tier');
        $this->assertTrue($teams[$tool]['tools'][array_search($tool, array_column($teams[$tool]['tools'], 'name'), true)]['sensitive']);
        foreach ($teams as $name => $tier) {
            if ($tier['key'] === 'raw_file') {
                $this->assertSame($tool, $name, 'only the Teams image reader joins the Teams raw_file tier');
            }
        }

        // Grant gate unchanged: still registered and explicit-grant in psa_raw_file.
        $this->assertContains($tool, array_column(McpToolRegistry::groups()['psa_raw_file']['tools'], 'name'));
        $this->assertContains($tool, McpToolRegistry::RAW_FILE_CONTENT_TOOLS);

        // The PSA ticket attachment reader stays under PSA Core.
        $this->assertArrayHasKey('get_ticket_attachment', $psa);
        $this->assertSame('raw_file', $psa['get_ticket_attachment']['key']);
        $this->assertArrayNotHasKey('get_ticket_attachment', $teams);
    }

    public function test_screenconnect_reads_are_registry_backed_and_mapped_to_a_screenconnect_card(): void
    {
        $screenconnectReads = [
            'screenconnect_get_session_state',
            'screenconnect_list_devices',
        ];

        $integrationNames = array_column(McpToolRegistry::groups()['integration']['tools'], 'name');

        foreach ($screenconnectReads as $tool) {
            $this->assertContains($tool, $integrationNames, "{$tool} should be in the normal integration tier");
            $this->assertContains($tool, McpToolRegistry::allToolNames(), "{$tool} should be token-grantable");
            $this->assertSame('screenconnect', McpToolRegistry::integrationForToolName($tool), "{$tool} must route to the screenconnect integration");
        }

        $this->assertArrayHasKey('screenconnect', McpToolRegistry::integrationMeta(), 'a ScreenConnect card must exist on the token page');
        $this->assertArrayHasKey('screenconnect', McpToolRegistry::integrationGroups(), 'ScreenConnect reads must render under their own card');
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
        $this->assertContains('merge_ticket', $all);
        $this->assertContains('merge_asset', $all);
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

    /**
     * psa-688: raw-file-content reads are explicit-grant-only at the gate
     * (McpStaffController::RAW_FILE_CONTENT_EXPLICIT_GRANT_TOOLS, which takes its names
     * from McpToolRegistry::RAW_FILE_CONTENT_TOOLS), so the operator grant catalog must
     * render them as SENSITIVE in their own tier — never inside the PSA "Read" tier,
     * whose "Grant shown" bulk button would make that by-name decision in one silent
     * batch click (psa-lulgh: a mislabelled tier is how an operator grants a capability
     * believing it is an ordinary read).
     *
     * BOTH flags are asserted because the token page reads them for different things:
     * the TIER flag drives the shield styling and the bulk-grant confirmation, the TOOL
     * flag drives the per-tool "Sensitive" badge and the sensitive-enabled counter.
     */
    public function test_raw_file_content_reads_render_as_a_sensitive_tier_not_a_plain_read(): void
    {
        $groups = McpToolRegistry::integrationGroups();

        // The expected card is fixed here, never derived from integrationForToolName() (the
        // function production routes with), so a misroute cannot carry the test along (#4972).
        $expectedCard = [
            'get_ticket_attachment' => 'psa',
            'get_teams_message_attachment' => 'teams',
            'get_hdb_report' => 'psa',
        ];
        $this->assertSame(array_keys($expectedCard), McpToolRegistry::RAW_FILE_CONTENT_TOOLS, 'a new raw-file tool needs its card pinned here');

        foreach ($expectedCard as $name => $card) {
            $this->assertArrayHasKey($card, $groups, "the {$card} card must render");
            $tiers = collect($groups[$card]['tiers']);
            $tier = $tiers->first(fn (array $t): bool => in_array($name, array_column($t['tools'], 'name'), true));

            $this->assertNotNull($tier, "{$name} must render on the {$card} card");
            $this->assertSame('raw_file', $tier['key'], "{$name} keeps its own raw_file tier");
            $this->assertTrue($tier['sensitive'], "{$name} must sit in a sensitive tier — the bulk-grant confirmation is driven by the tier flag");
            $this->assertTrue(
                collect($tier['tools'])->firstWhere('name', $name)['sensitive'],
                "{$name} must carry the Sensitive badge",
            );
        }
    }

    /**
     * #4967/#4968/#4969/#4971: the PSA-registered tools that render off the PSA Core card are
     * an exact, pinned set — routed by exact name (or the anchored benjipays_ prefix), never by
     * a name substring — and every tool on the page renders exactly once, and every re-routed tool
     * lands in a sensitive tier (a sensitive tool merged into a non-sensitive tier would
     * be bulk-grantable through "Grant shown" without the sensitive confirmation, psa-lulgh).
     */
    public function test_psa_tools_rendered_off_psa_core_are_an_exact_set_and_every_tool_renders_once_rerouted_into_sensitive_tiers(): void
    {
        $groups = McpToolRegistry::integrationGroups();

        $seen = [];
        $tierOf = [];
        foreach ($groups as $card => $group) {
            foreach ($group['tiers'] as $tier) {
                foreach ($tier['tools'] as $tool) {
                    $seen[$tool['name']][] = $card;
                    $tierOf[$tool['name']] = $tier;
                }
            }
        }
        foreach ($seen as $name => $cards) {
            $this->assertCount(1, $cards, "{$name} must render exactly once on the page, got: ".implode(',', $cards));
        }

        $psaGroups = ['psa_action', 'psa_records', 'psa_read', 'psa_raw_file', 'intake_manage', 'taxonomy'];
        $offCore = [];
        foreach ($psaGroups as $key) {
            foreach (McpToolRegistry::groups()[$key]['tools'] ?? [] as $tool) {
                $name = (string) $tool['name'];
                $this->assertArrayHasKey($name, $seen, "{$name} must render somewhere on the page");
                if ($seen[$name][0] !== 'psa') {
                    $offCore[$name] = $seen[$name][0];
                }
            }
        }
        ksort($offCore);

        $this->assertSame([
            'benjipays_autopay_forecast' => 'benjipays',
            'benjipays_get_customer_payment_methods' => 'benjipays',
            'benjipays_get_invoice' => 'benjipays',
            'benjipays_get_settings' => 'benjipays',
            'benjipays_list_sent_emails' => 'benjipays',
            'benjipays_list_transactions' => 'benjipays',
            'get_teams_message_attachment' => 'teams',
        ], $offCore, 'only the pinned PSA tools may render off the PSA Core card');
        foreach (array_keys($offCore) as $name) {
            $this->assertTrue($tierOf[$name]['sensitive'], "{$name} is re-routed off PSA Core and must land in a sensitive tier, never a target card's plain Read tier");
        }
        $this->assertSame(['get_teams_message_attachment' => 'teams'], McpToolRegistry::PSA_TOOLS_ON_OTHER_CARDS);
    }

    /**
     * Card UtffkPs5: mesh_list_allow_rules is a READ, so the catalog must not show it under
     * "Allow-list writes" (psa-lulgh: a mislabelled tier misleads the operator). It moves to
     * its own SENSITIVE read tier on the Mesh card, never Mesh's plain non-sensitive "Read"
     * tier, whose bulk "Grant shown" grants without the sensitive confirmation: the gate
     * keeps it explicit-grant-only, and the catalog must say the same thing.
     *
     * Both flags are asserted, as for the raw-file tier: the TIER flag drives the shield and
     * the bulk-grant confirmation, the TOOL flag the per-tool badge and the sensitive counter.
     */
    public function test_mesh_list_allow_rules_renders_in_a_sensitive_mesh_read_tier_not_allow_list_writes(): void
    {
        $name = 'mesh_list_allow_rules';
        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('mesh_read', $groups, 'the Mesh read verb needs its own read group');
        $names = fn (string $group): array => array_column($groups[$group]['tools'], 'name');

        $this->assertSame([$name], $names('mesh_read'), 'the Mesh read group holds exactly the list verb');
        $this->assertTrue($groups['mesh_read']['sensitive'], 'the Mesh read group stays sensitive: explicit grant only');
        $this->assertStringContainsString('reads', $groups['mesh_read']['label']);
        $this->assertStringNotContainsString('write', $groups['mesh_read']['label']);
        $this->assertNotContains($name, $names('mesh_admin'), 'a read must not sit in the allow-list writes group');
        $this->assertNotContains($name, $names('integration'), 'a read granted explicitly must not sit in the non-sensitive integration reads');
        $this->assertSame(['mesh_add_allow_rule', 'mesh_remove_allow_rule', 'mesh_edit_allow_rule'], $names('mesh_admin'), 'the three writes stay in the writes group');

        $mesh = McpToolRegistry::integrationGroups()['mesh'] ?? null;
        $this->assertNotNull($mesh, 'the Mesh card must render');
        $tiers = collect($mesh['tiers'])->keyBy('key');
        $tierOf = $tiers->first(fn (array $t): bool => in_array($name, array_column($t['tools'], 'name'), true));

        $this->assertNotNull($tierOf, "{$name} must render on the Mesh card");
        $this->assertSame('allow_list_read', $tierOf['key'], 'its own tier key, never the plain read tier');
        $this->assertSame('Allow-list reads', $tierOf['label']);
        $this->assertSame([$name], array_column($tierOf['tools'], 'name'));
        $this->assertTrue($tierOf['sensitive'], 'the tier flag drives the bulk-grant confirmation');
        $this->assertTrue(collect($tierOf['tools'])->firstWhere('name', $name)['sensitive'], 'the tool keeps its Sensitive badge');

        $this->assertFalse($tiers['read']['sensitive']);
        $this->assertNotContains($name, array_column($tiers['read']['tools'], 'name'));
        $this->assertSame('Allow-list writes', $tiers['write']['label']);
        $this->assertTrue($tiers['write']['sensitive']);
        $this->assertSame(['mesh_add_allow_rule', 'mesh_remove_allow_rule', 'mesh_edit_allow_rule'], array_column($tiers['write']['tools'], 'name'));
        $this->assertSame(['read', 'allow_list_read', 'write'], $tiers->keys()->all(), 'reads render before writes');
        $this->assertSame(4, $mesh['sensitive_count'], 'the list verb still counts as sensitive');
    }
}
