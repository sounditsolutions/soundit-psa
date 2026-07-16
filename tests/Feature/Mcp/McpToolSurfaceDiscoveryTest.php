<?php

namespace Tests\Feature\Mcp;

use App\Models\Setting;
use App\Models\User;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use App\Support\McpToolSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * list_tool_surface (psa-ve9v) — the staff-MCP discovery tool. An agent
 * hitting a gap must be able to tell "doesn't exist (dev build)" from
 * "exists but not granted (operator enable)" from "exists but the
 * integration is off (infra config)" without anyone hand-enumerating the
 * catalog. The tool is a transport built-in like whoami: always listed,
 * always callable, capability names and one-line descriptions only.
 */
class McpToolSurfaceDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create();
    }

    private function callTool(string $token, string $name, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, mixed> */
    private function surface(string $token, array $arguments = []): array
    {
        $response = $this->callTool($token, 'list_tool_surface', $arguments);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true);
    }

    /** @return array<string, string> tool name => state */
    private function statesByName(array $payload): array
    {
        return collect($payload['tools'])->pluck('state', 'name')->all();
    }

    public function test_list_tool_surface_is_listed_and_callable_without_an_explicit_grant(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $names = collect(
            $this->withHeaders(['Authorization' => 'Bearer '.$token])
                ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
                ->json('result.tools'),
        )->pluck('name');

        $this->assertContains('list_tool_surface', $names);

        $payload = $this->surface($token);

        $this->assertArrayHasKey('states', $payload);
        $this->assertArrayHasKey('counts', $payload);
        $this->assertArrayHasKey('tools', $payload);
        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'tools/call',
            'tool_name' => 'list_tool_surface',
            'status' => 'success',
            'actor_label' => 'mcp-staff:chet',
        ]);
    }

    public function test_classifies_granted_ungranted_and_config_off_tools(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $states = $this->statesByName($this->surface($token));

        // Granted: in the token allowlist and live (OperatorBridge is unconditional).
        $this->assertSame(McpToolSurface::STATE_GRANTED, $states['find_staff']);
        // Available but ungranted: live general read the token was not granted.
        $this->assertSame(McpToolSurface::STATE_AVAILABLE_UNGRANTED, $states['list_open_tickets']);
        // Config-off: Huntress reads are catalogued (pre-grantable) but the
        // instance has no API key, so the live surface excludes them.
        $this->assertSame(McpToolSurface::STATE_UNAVAILABLE_CONFIG, $states['huntress_list_escalations']);
        $this->assertSame(McpToolSurface::STATE_UNAVAILABLE_CONFIG, $states['tactical_get_device']);
    }

    public function test_configuring_an_integration_moves_its_tools_out_of_unavailable_config(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['huntress_list_escalations'], label: 'chet');

        $states = $this->statesByName($this->surface($token));
        $this->assertSame(McpToolSurface::STATE_UNAVAILABLE_CONFIG, $states['huntress_list_escalations']);
        $this->assertSame(McpToolSurface::STATE_UNAVAILABLE_CONFIG, $states['huntress_list_incident_reports']);

        Setting::setEncrypted('huntress_api_key', 'k');
        Setting::setEncrypted('huntress_api_secret', 's');

        $states = $this->statesByName($this->surface($token));
        // The granted tool goes live; its ungranted siblings become enablement asks.
        $this->assertSame(McpToolSurface::STATE_GRANTED, $states['huntress_list_escalations']);
        $this->assertSame(McpToolSurface::STATE_AVAILABLE_UNGRANTED, $states['huntress_list_incident_reports']);
    }

    public function test_legacy_full_surface_token_shows_sensitive_classes_as_ungranted(): void
    {
        $legacy = McpConfig::rotateStaffToken();

        $states = $this->statesByName($this->surface($legacy));

        // Plain reads pass the legacy allow-everything gate…
        $this->assertSame(McpToolSurface::STATE_GRANTED, $states['list_open_tickets']);
        // …but sensitive classes require an explicit scoped grant, which a
        // legacy token can never carry (mirrors toolAllowed exactly).
        $this->assertSame(McpToolSurface::STATE_AVAILABLE_UNGRANTED, $states['create_ticket']);
        $this->assertSame(McpToolSurface::STATE_AVAILABLE_UNGRANTED, $states['find_staff']);
    }

    /**
     * *** REGRESSION (psa-4k6m.2 security lane): the tenant-wide mailbox-rule sweep must
     * NEVER be auto-inherited by the legacy full-surface token. ***
     *
     * It shipped that way and the security review caught it. cipp_list_tenant_mailbox_rules
     * was added as an ordinary curated CIPP read, and curated CIPP reads hit no explicit-grant
     * branch in toolAllowed() — they fall through to `$token->allows($toolName)`, which is
     * unconditionally true for a legacy token (allowedTools === null). So a break-glass token
     * silently gained a read of EVERY mailbox's inbox rules in a tenant, while the change's
     * own claim was "grantable, nothing auto-granted".
     *
     * The sibling per-mailbox read is deliberately asserted GRANTED alongside it: the point is
     * not that mailbox rules are sensitive, it is that the TENANT-WIDE scope is categorically
     * wider than every other curated CIPP read. If someone later "simplifies" by dropping the
     * CIPP_EXPLICIT_GRANT_READ_TOOLS branch, this fails.
     *
     * This asserts through McpToolSurface rather than calling toolAllowed() directly on
     * purpose: classify() takes the controller's real gate as its callable, so one assertion
     * pins BOTH the authorization decision and what discovery advertises about it. A fix that
     * gated the call but left list_tool_surface advertising "granted" would still fail here.
     */
    public function test_legacy_full_surface_token_does_not_inherit_the_tenant_wide_mailbox_rule_sweep(): void
    {
        $this->configureCipp();
        $legacy = McpConfig::rotateStaffToken();

        $states = $this->statesByName($this->surface($legacy));

        $this->assertSame(
            McpToolSurface::STATE_AVAILABLE_UNGRANTED,
            $states['cipp_list_tenant_mailbox_rules'],
            'A legacy full-surface token must not inherit the tenant-wide mailbox-rule sweep — it reads every mailbox in the tenant and requires an explicit operator grant.',
        );

        // The per-mailbox sibling stays an ordinary inherited read — scope is the
        // discriminator here, not the subject matter.
        $this->assertSame(McpToolSurface::STATE_GRANTED, $states['cipp_list_mailbox_rules']);
    }

    /**
     * The other half: an explicit grant DOES enable it. Requiring a grant is worthless if
     * granting does not work — and this is the line between "explicit-grant class" (correct,
     * Charlie's model: the operator decides) and "blocklist" (the reflex he overruled).
     */
    public function test_an_explicit_grant_enables_the_tenant_wide_sweep_for_a_scoped_token(): void
    {
        $this->configureCipp();
        $token = McpConfig::rotateStaffToken(
            allowedTools: ['cipp_list_tenant_mailbox_rules'],
            label: 'chet',
        );

        $states = $this->statesByName($this->surface($token));

        $this->assertSame(McpToolSurface::STATE_GRANTED, $states['cipp_list_tenant_mailbox_rules']);
    }

    /**
     * Without CIPP configured the tool is `unavailable_config`, not `available_ungranted`
     * — an infrastructure fact, not a token-grant fact. Asserted because the two tests
     * above would BOTH pass vacuously if the tool silently fell into unavailable_config
     * for the wrong reason (it is how they failed on first run), and because the distinction
     * is the whole point of the three-state surface: "grant me this" and "configure CIPP"
     * are different asks and an agent must not confuse them.
     */
    public function test_the_tenant_wide_sweep_reads_as_unavailable_config_when_cipp_is_not_configured(): void
    {
        $legacy = McpConfig::rotateStaffToken();

        $states = $this->statesByName($this->surface($legacy));

        $this->assertSame(McpToolSurface::STATE_UNAVAILABLE_CONFIG, $states['cipp_list_tenant_mailbox_rules']);
    }

    private function configureCipp(): void
    {
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'client-1');
        Setting::setEncrypted('cipp_client_secret', 'secret');
    }

    public function test_counts_cover_the_full_catalog_and_filters_narrow_the_listing(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $payload = $this->surface($token);
        $this->assertSame(count(McpToolRegistry::allToolNames()), $payload['counts']['total']);
        $this->assertSame(
            $payload['counts']['total'],
            $payload['counts'][McpToolSurface::STATE_GRANTED]
                + $payload['counts'][McpToolSurface::STATE_AVAILABLE_UNGRANTED]
                + $payload['counts'][McpToolSurface::STATE_UNAVAILABLE_CONFIG],
        );

        $granted = $this->surface($token, ['state' => 'granted']);
        $this->assertSame(['find_staff'], array_column($granted['tools'], 'name'));
        // Counts stay catalog-wide even when the listing is filtered.
        $this->assertSame($payload['counts'], $granted['counts']);

        $bridge = $this->surface($token, ['category' => 'bridge']);
        $this->assertNotEmpty($bridge['tools']);
        $this->assertSame(['bridge'], array_values(array_unique(array_column($bridge['tools'], 'category'))));
    }

    public function test_invalid_filters_return_an_error_with_the_valid_values(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $response = $this->callTool($token, 'list_tool_surface', ['state' => 'enabled']);
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('available_ungranted', (string) $response->json('result.content.0.text'));

        $response = $this->callTool($token, 'list_tool_surface', ['category' => 'nonsense']);
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('general', (string) $response->json('result.content.0.text'));
    }

    public function test_entries_carry_single_line_descriptions_and_no_schemas(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $payload = $this->surface($token);

        foreach ($payload['tools'] as $entry) {
            $this->assertSame(['name', 'category', 'state', 'description'], array_keys($entry));
            $this->assertNotSame('', $entry['name']);
            $this->assertLessThanOrEqual(200, mb_strlen($entry['description']), $entry['name']);
            $this->assertStringNotContainsString("\n", $entry['description'], $entry['name']);
        }
    }

    public function test_denied_call_hint_points_at_the_discovery_tool(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $response = $this->callTool($token, 'get_staff', ['id' => 1]);

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('list_tool_surface', (string) $response->json('result.content.0.text'));
    }
}
