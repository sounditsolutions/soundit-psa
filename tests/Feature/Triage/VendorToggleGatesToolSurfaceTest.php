<?php

namespace Tests\Feature\Triage;

use App\Models\Setting;
use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Level\LevelClient;
use App\Services\Ninja\NinjaClient;
use App\Services\Triage\TriageToolDefinitions;
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
}
