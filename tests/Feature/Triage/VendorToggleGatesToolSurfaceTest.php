<?php

namespace Tests\Feature\Triage;

use App\Models\CippMcpTool;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Level\LevelClient;
use App\Services\Ninja\NinjaClient;
use App\Services\Triage\ContextBuilder;
use App\Services\Triage\TriageToolDefinitions;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use App\Support\McpToolSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * psa-wzjzz — OFF MEANS OFF on the AI tool surface.
 *
 * Charlie ruled (A) OFF=OFF (2026-07-21 15:39Z): "if the integration's master switch
 * is off, that should disable that integration's MCP tools too."
 *
 * The six vendor predicates in TriageToolDefinitions each defined a real, settings-backed
 * master switch and then ignored it — gating instead on "are credentials present" or, for
 * Ninja and Level, on a LIVE HTTP health probe. So an operator could flip the documented
 * off-switch, watch the UI confirm "disabled", have sync commands and webhooks correctly
 * stop, and still have the AI surface publishing and executing that vendor's tools against
 * the live API. Ninja is the sharpest case: ninja_enabled DEFAULTS to '0', so an
 * integration that is off by default went live merely because credentials existed.
 *
 * Note what tests/Feature/Ninja/NinjaDisabledTest.php covers by its own docblock — "sync
 * commands, schedules, and webhook jobs". The AI tool surface was never in the gate's
 * scope. That is the untested gap this file closes, and it is why the bug survived a
 * green suite.
 *
 * EVERY VENDOR IS ASSERTED IN BOTH DIRECTIONS, deliberately. A refusal-only test cannot
 * catch a wrongly-refused tool: proving the tools vanish when the switch is off says
 * nothing about whether they still appear when it is on. The "published when enabled"
 * half is the no-over-block mirror, and it is the half that catches a fix that
 * over-blocks by silently deleting a legitimate capability.
 */
class VendorToggleGatesToolSurfaceTest extends TestCase
{
    use RefreshDatabase;

    /** @return string[] */
    private function triageToolNames(): array
    {
        return array_column(TriageToolDefinitions::getTools(), 'name');
    }

    /** @return string[] */
    private function assistantClientToolNames(): array
    {
        return array_column(AssistantToolDefinitions::getTools(hasClient: true), 'name');
    }

    private function assertHasToolsPrefixed(string $prefix, array $names, string $context): void
    {
        $matching = array_filter($names, fn (string $n): bool => str_starts_with($n, $prefix));

        $this->assertNotEmpty(
            $matching,
            "{$context}: expected at least one '{$prefix}*' tool to be published when the integration is ENABLED and configured. ".
            'An empty result here means the fix OVER-BLOCKED and removed a legitimate capability.'
        );
    }

    private function assertNoToolsPrefixed(string $prefix, array $names, string $context): void
    {
        $matching = array_values(array_filter($names, fn (string $n): bool => str_starts_with($n, $prefix)));

        $this->assertSame(
            [],
            $matching,
            "{$context}: the master switch is OFF but these '{$prefix}*' tools were still published: ".
            implode(', ', $matching)
        );
    }

    /**
     * Ninja and Level currently gate on a LIVE health probe. In a test environment that
     * probe fails on its own (no credentials, no network), so a naive "tools are absent
     * when disabled" assertion would pass VACUOUSLY — for the wrong reason — and would
     * keep passing even if the toggle were still ignored. Forcing the probe to succeed
     * is the control that makes the assertion mean something: it reproduces the bead's
     * CASE B (credentials present, flag off, warm token), which is the ONLY state the
     * off-switch can actually produce, since toggleIntegration() writes the flag alone
     * and never clears credentials.
     */
    private function forceHealthProbesToSucceed(): void
    {
        $this->mock(NinjaClient::class, function (MockInterface $m): void {
            $m->shouldReceive('isHealthy')->andReturn(true);
        });

        $this->mock(LevelClient::class, function (MockInterface $m): void {
            $m->shouldReceive('isHealthy')->andReturn(true);
        });
    }

    private function configureAllVendorCredentials(): void
    {
        Setting::setValue('ninja_client_id', 'ninja-client-id');
        Setting::setEncrypted('ninja_client_secret', 'ninja-client-secret');

        Setting::setEncrypted('level_api_key', 'level-api-key');

        Setting::setEncrypted('mesh_api_key', 'mesh-api-key');

        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-id');
        Setting::setValue('cipp_client_id', 'client-id');
        Setting::setEncrypted('cipp_client_secret', 'client-secret');

        Setting::setEncrypted('controld_api_key', 'controld-api-key');

        Setting::setEncrypted('zorus_api_key', 'zorus-api-key');
    }

    /** @return array<string, array{0: string, 1: string}> vendor => [setting key, tool prefix] */
    public static function vendorProvider(): array
    {
        return [
            'ninja' => ['ninja_enabled', 'ninja_'],
            'level' => ['level_enabled', 'level_'],
            'mesh' => ['mesh_enabled', 'mesh_'],
            'cipp' => ['cipp_enabled', 'cipp_'],
            'controld' => ['controld_enabled', 'controld_'],
            'zorus' => ['zorus_enabled', 'zorus_'],
        ];
    }

    // -----------------------------------------------------------------------
    // 1. TRIAGE LANE — the surface with no second gate
    // -----------------------------------------------------------------------

    /**
     * @dataProvider vendorProvider
     */
    public function test_triage_does_not_publish_vendor_tools_when_master_switch_is_off(string $settingKey, string $prefix): void
    {
        $this->configureAllVendorCredentials();
        $this->forceHealthProbesToSucceed();

        Setting::setValue($settingKey, '0');

        $this->assertNoToolsPrefixed($prefix, $this->triageToolNames(), "triage lane, {$settingKey}='0'");
    }

    /**
     * The no-over-block mirror. Fully configured and explicitly switched ON must still
     * publish, or the fix has quietly removed a capability triage legitimately uses.
     *
     * @dataProvider vendorProvider
     */
    public function test_triage_publishes_vendor_tools_when_master_switch_is_on(string $settingKey, string $prefix): void
    {
        $this->configureAllVendorCredentials();
        $this->forceHealthProbesToSucceed();

        Setting::setValue($settingKey, '1');

        $this->assertHasToolsPrefixed($prefix, $this->triageToolNames(), "triage lane, {$settingKey}='1'");
    }

    // -----------------------------------------------------------------------
    // 2. NINJA DEFAULTS OFF — the sharpest case, asserted on its own
    // -----------------------------------------------------------------------

    /**
     * ninja_enabled defaults to '0' (NinjaConfig: Charlie is offboarding Ninja). With the
     * setting ABSENT ENTIRELY — the true default, not an explicit '0' — a fully credentialled
     * box must publish no Ninja tools at all.
     */
    public function test_ninja_tools_are_absent_by_default_when_the_setting_was_never_written(): void
    {
        $this->configureAllVendorCredentials();
        $this->forceHealthProbesToSucceed();

        $this->assertNull(
            Setting::getValue('ninja_enabled'),
            'precondition: ninja_enabled must be absent for this test to exercise the DEFAULT'
        );

        $this->assertNoToolsPrefixed('ninja_', $this->triageToolNames(), 'triage lane, ninja_enabled absent (default off)');
    }

    // -----------------------------------------------------------------------
    // 3. STAFF ASSISTANT (client context) — inherits the same four predicates
    // -----------------------------------------------------------------------

    /**
     * AssistantToolDefinitions gates ninja/level/mesh/cipp only; it never publishes
     * controld/zorus tools, so those two are correctly absent from this provider.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function assistantVendorProvider(): array
    {
        return [
            'ninja' => ['ninja_enabled', 'ninja_'],
            'level' => ['level_enabled', 'level_'],
            'mesh' => ['mesh_enabled', 'mesh_'],
            'cipp' => ['cipp_enabled', 'cipp_'],
        ];
    }

    /**
     * @dataProvider assistantVendorProvider
     */
    public function test_assistant_does_not_publish_vendor_tools_when_master_switch_is_off(string $settingKey, string $prefix): void
    {
        $this->configureAllVendorCredentials();
        $this->forceHealthProbesToSucceed();

        Setting::setValue($settingKey, '0');

        $this->assertNoToolsPrefixed($prefix, $this->assistantClientToolNames(), "assistant client surface, {$settingKey}='0'");
    }

    /**
     * @dataProvider assistantVendorProvider
     */
    public function test_assistant_publishes_vendor_tools_when_master_switch_is_on(string $settingKey, string $prefix): void
    {
        $this->configureAllVendorCredentials();
        $this->forceHealthProbesToSucceed();

        Setting::setValue($settingKey, '1');

        $this->assertHasToolsPrefixed($prefix, $this->assistantClientToolNames(), "assistant client surface, {$settingKey}='1'");
    }

    // -----------------------------------------------------------------------
    // 4. STAFF MCP LIVE SURFACE — must INHERIT the gate, not re-derive it
    // -----------------------------------------------------------------------

    /**
     * McpToolSurface's live definitions are assembled from AssistantToolDefinitions, so
     * gating the predicates once must flow through to what staff MCP publishes. This is
     * the publication half of Charlie's MCP extension.
     *
     * *** IT IS ONLY THE PUBLICATION HALF. *** A granted token can still CALL a tool that
     * is no longer live, because McpStaffController's call path consults toolAllowed()
     * and never McpToolSurface::liveToolNames(). That is psa-vydpz, and until it lands
     * these tools are HIDDEN rather than DISABLED. Do not read a green test here as the
     * MCP leg being satisfied.
     *
     * @dataProvider assistantVendorProvider
     */
    public function test_mcp_live_surface_inherits_the_master_switch(string $settingKey, string $prefix): void
    {
        $this->configureAllVendorCredentials();
        $this->forceHealthProbesToSucceed();

        Setting::setValue($settingKey, '1');
        $this->assertHasToolsPrefixed(
            $prefix,
            McpToolSurface::liveToolNames(),
            "mcp live surface, {$settingKey}='1'"
        );

        Setting::setValue($settingKey, '0');
        $this->assertNoToolsPrefixed(
            $prefix,
            McpToolSurface::liveToolNames(),
            "mcp live surface, {$settingKey}='0'"
        );
    }

    // -----------------------------------------------------------------------
    // 5. NO COLLATERAL DAMAGE — the PSA core surface is untouched
    // -----------------------------------------------------------------------

    /**
     * Switching every vendor off must not disturb the non-vendor tools. Without this,
     * a fix that returned an empty tool list outright would satisfy every assertion above.
     */
    public function test_psa_core_tools_survive_with_every_vendor_switched_off(): void
    {
        $this->configureAllVendorCredentials();
        $this->forceHealthProbesToSucceed();

        foreach (array_keys(self::vendorProvider()) as $vendor) {
            Setting::setValue(self::vendorProvider()[$vendor][0], '0');
        }

        $names = $this->triageToolNames();

        $this->assertNotEmpty($names, 'triage must still publish its PSA core tools with all vendors off');
        $this->assertContains('search_tickets', $names, 'search_tickets is a PSA core tool and must not be gated by any vendor switch');
    }

    // -----------------------------------------------------------------------
    // 5b. THE PROMPT MUST NOT CONTRADICT THE TOOL SURFACE
    // -----------------------------------------------------------------------

    /**
     * FOUND IN REVIEW (psa-wzjzz.4 UX, PR #296 @ 2469f4f).
     *
     * ContextBuilder writes an "Available Integrations for This Client" block into the triage
     * prompt, telling the model which tool families to use. Its checks were INDEPENDENT of the
     * predicates that decide publication, and had drifted: Ninja consulted no configuration at
     * all (client mapping only), while CIPP / Control D / Zorus asked isConfigured() and
     * ignored the master switch.
     *
     * Once the switch gates publication, that independence becomes an active contradiction —
     * the prompt says "use cipp_* tools" in the same turn the tools are withheld. The model
     * then hallucinates a call or silently skips the check, and *** a technician reading the
     * triage note cannot tell "the operator disabled this vendor" from "the AI didn't
     * bother" ***. That is the diagnosability failure, and it is why this is a real finding
     * rather than a cosmetic one.
     */
    public function test_the_prompt_does_not_advertise_a_vendor_whose_switch_is_off(): void
    {
        $this->configureAllVendorCredentials();
        $this->forceHealthProbesToSucceed();

        $client = Client::factory()->create([
            'mesh_customer_id' => 'mesh-cust',
            'ninja_org_id' => 42,
            'cipp_tenant_domain' => 'acme.example',
            'controld_org_id' => 'cd-org',
            'zorus_customer_id' => 'zorus-cust',
        ]);
        $ticket = Ticket::factory()->for($client)->create();

        // Driven through the real public entry point rather than reflecting into the private
        // section builder: what matters is what actually reaches the model's prompt.
        // Every vendor switched ON: the prompt should advertise them (the mirror — without
        // this the assertion below could pass simply because the block is always empty).
        foreach (array_keys(self::vendorProvider()) as $vendor) {
            Setting::setValue(self::vendorProvider()[$vendor][0], '1');
        }

        $on = ContextBuilder::buildForTicket($ticket, skipNotes: true);
        foreach (['Mesh', 'NinjaRMM', 'CIPP/M365', 'Control D', 'Zorus'] as $label) {
            $this->assertStringContainsString($label, $on, "with every switch ON the prompt must advertise {$label}");
        }

        // Every vendor switched OFF: the prompt must advertise none of them.
        foreach (array_keys(self::vendorProvider()) as $vendor) {
            Setting::setValue(self::vendorProvider()[$vendor][0], '0');
        }

        $off = ContextBuilder::buildForTicket($ticket, skipNotes: true);
        foreach (['mesh_*', 'ninja_*', 'cipp_*', 'controld_*', 'zorus_*'] as $family) {
            $this->assertStringNotContainsString(
                $family,
                $off,
                "the prompt still tells the model to use {$family} tools while that vendor's master ".
                'switch is off — the prompt and the published tool surface disagree'
            );
        }
    }

    // -----------------------------------------------------------------------
    // 6. THE DYNAMIC CIPP CATALOG — a second publication path around the switch
    // -----------------------------------------------------------------------
    //
    // FOUND IN REVIEW (psa-wzjzz.1 security / .2 architecture, PR #296 @ 2469f4f), proven by
    // an execution probe, and it was a real hole in the first cut of this fix.
    //
    // Gating the six predicates covers the STATIC vendor lanes. Staff MCP publishes CIPP a
    // SECOND way: McpToolSurface::liveClientScopedToolDefinitions() merged
    // McpToolRegistry::dynamicCippReadTools()/dynamicCippWriteTools() UNCONDITIONALLY, one
    // line above a sibling that was already gated on the master switch. So with
    // cipp_enabled='0' a synced catalog row stayed published AND callable.
    //
    // *** AND THE SIBLING PR COULD NOT HAVE SAVED IT. *** psa-vydpz refuses a name that is
    // not in liveToolNames(), but the dynamic row was still IN liveToolNames() — the
    // liveness gate derives from the very source that was ungated. Two guards reading one
    // wrong answer agree with each other, which is exactly why "the other PR covers it" is
    // never a substitute for closing the publication path itself.

    private function configureCippMcpRelay(): void
    {
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        Setting::setEncrypted('cipp_mcp_client_secret', 'mcp-secret');
        Setting::setValue('cipp_mcp_enabled', '1');
    }

    private function createDynamicCippRow(): void
    {
        CippMcpTool::create([
            'local_name' => 'cipp_list_db_cache',
            'upstream_name' => 'ListDBCache',
            'category' => 'CIPP',
            'description' => '[CIPP] List DB cache.',
            'input_schema' => ['type' => 'object', 'properties' => ['tenantFilter' => ['type' => 'string']]],
            'annotations' => ['readOnlyHint' => true],
            'read_only' => true,
            'sensitive' => false,
            'active' => true,
            'last_seen_at' => now(),
        ]);
    }

    public function test_the_cipp_master_switch_also_disables_the_dynamic_catalog(): void
    {
        $this->configureAllVendorCredentials();
        $this->configureCippMcpRelay();
        $this->createDynamicCippRow();

        Setting::setValue('cipp_enabled', '0');
        McpToolRegistry::flushMemoized();

        $this->assertNotContains(
            'cipp_list_db_cache',
            McpToolSurface::liveToolNames(),
            'cipp_enabled=0 but a dynamic CIPP catalog row is still live — the master switch is '
            .'bypassed by the dynamic publication path, and psa-vydpz cannot save it because its '
            .'liveness gate reads this same list'
        );

        $token = McpConfig::rotateStaffToken(allowedTools: ['cipp_list_db_cache'], label: 'chet');

        $listed = array_column(
            $this->withHeaders(['Authorization' => 'Bearer '.$token])
                ->postJson('/api/mcp/staff', [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'tools/list',
                    'params' => [],
                ])->json('result.tools') ?? [],
            'name'
        );

        $this->assertNotContains('cipp_list_db_cache', $listed, 'tools/list published a dynamic CIPP tool with the master switch off');

        // The executor is the defence-in-depth layer and must refuse independently of
        // publication: isMcpRelayEnabled() ignored the master switch, while its three
        // siblings in CippConfig all consult it.
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'cipp_list_db_cache', 'arguments' => ['client_id' => 1]],
            ]);

        $this->assertTrue(
            (bool) $response->json('result.isError'),
            'cipp_enabled=0 and a granted token still executed a dynamic CIPP tool: '
            .(string) $response->json('result.content.0.text')
        );
    }

    /**
     * The mirror. Switched ON (integration AND relay), the dynamic catalog must still
     * publish — otherwise the fix has silently removed the entire synced CIPP surface,
     * which is a far larger regression than the hole it closes.
     */
    public function test_the_dynamic_catalog_still_publishes_when_cipp_is_switched_on(): void
    {
        $this->configureAllVendorCredentials();
        $this->configureCippMcpRelay();
        $this->createDynamicCippRow();

        Setting::setValue('cipp_enabled', '1');
        McpToolRegistry::flushMemoized();

        $this->assertContains(
            'cipp_list_db_cache',
            McpToolSurface::liveToolNames(),
            'CIPP is switched ON and configured, but the dynamic catalog row is not live — over-block'
        );
    }

    /**
     * FOUND IN RE-REVIEW (psa-wzjzz.6 architecture, PR #296 @ bce8066), proven by an
     * execution probe. The first cut of the CIPP fix closed the cipp_enabled='0' bypass but
     * left a NEW publish-vs-dispatch split one notch over.
     *
     * The dynamic catalog EXECUTES through CippMcpDynamicToolExecutor, which gates on
     * CippConfig::isMcpRelayEnabled() — that is isEnabled() AND cipp_mcp_enabled='1' AND
     * isMcpConfigured(). But publication was gated on isEnabled() ALONE. So in the state
     * cipp_enabled='1', cipp_mcp_enabled='0' the tool was advertised as LIVE and then
     * refused at execution as "CIPP MCP relay is not enabled or configured" — the exact
     * publish-vs-dispatch divergence this whole bead family exists to eliminate, and it made
     * the unavailable_config copy wrong (the tool showed as available, not as needing config).
     *
     * The fix is one predicate for both: publication is gated on the SAME isMcpRelayEnabled()
     * the executor uses, so the two cannot disagree by construction. This test pins the split
     * the earlier two dynamic-CIPP tests missed because both always enabled the relay first.
     */
    public function test_the_dynamic_catalog_matches_the_executor_gate_not_a_weaker_one(): void
    {
        $this->configureAllVendorCredentials();
        $this->configureCippMcpRelay();
        $this->createDynamicCippRow();

        // Integration ON, but the MCP relay sub-switch OFF: the executor would refuse, so
        // publication must too. Precisely the reviewer's probe state.
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_mcp_enabled', '0');
        McpToolRegistry::flushMemoized();

        $this->assertFalse(
            \App\Support\CippConfig::isMcpRelayEnabled(),
            'precondition: with cipp_mcp_enabled=0 the executor gate must be closed, or this asserts nothing'
        );

        $this->assertNotContains(
            'cipp_list_db_cache',
            McpToolSurface::liveToolNames(),
            'the dynamic CIPP tool is published as live while cipp_mcp_enabled=0, but the executor '
            .'refuses it — publication and dispatch disagree. Gate publication on isMcpRelayEnabled(), '
            .'the same predicate the executor uses.'
        );

        $token = McpConfig::rotateStaffToken(allowedTools: ['cipp_list_db_cache'], label: 'chet');

        $listed = array_column(
            $this->withHeaders(['Authorization' => 'Bearer '.$token])
                ->postJson('/api/mcp/staff', [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'tools/list',
                    'params' => [],
                ])->json('result.tools') ?? [],
            'name'
        );

        $this->assertNotContains(
            'cipp_list_db_cache',
            $listed,
            'tools/list advertised a dynamic CIPP tool the executor will refuse — the same tool '
            .'that would then read as unavailable_config only AFTER the model tried to call it'
        );
    }
}
