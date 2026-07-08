<?php

namespace Tests\Feature\Agent;

use App\Models\Setting;
use App\Services\Agent\TechnicianAgent;
use App\Support\AgentConfig;
use App\Support\AiConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_dormant_and_auto_off(): void
    {
        $this->assertFalse(AgentConfig::enabled());
        $this->assertSame(10, AgentConfig::maxPendingProposals());
        $this->assertNull(AgentConfig::proposeCloseAutoThreshold()); // auto band OFF by default
        $this->assertSame(0.50, AgentConfig::proposeCloseApproveFloor());
        $this->assertNotSame('', AgentConfig::significanceModel());
    }

    public function test_auto_threshold_absent_is_null_not_floor(): void // BLOCKER guard
    {
        $this->assertNull(AgentConfig::proposeCloseAutoThreshold());        // genuinely absent → null
        Setting::setValue('propose_close_auto_threshold', '0.5');
        $this->assertSame(0.90, AgentConfig::proposeCloseAutoThreshold());  // below floor → clamped up
        Setting::setValue('propose_close_auto_threshold', '0.97');
        $this->assertSame(0.97, AgentConfig::proposeCloseAutoThreshold());  // above floor → honored
    }

    public function test_overrides_and_floors(): void
    {
        Setting::setValue('agent_enabled', '1');
        Setting::setValue('agent_max_pending', '0'); // below floor
        $this->assertTrue(AgentConfig::enabled());
        $this->assertSame(1, AgentConfig::maxPendingProposals());
    }

    public function test_auto_quiet_days_default_and_floor(): void
    {
        $this->assertSame(14, AgentConfig::autoQuietDays()); // default when unset
        Setting::setValue('agent_auto_quiet_days', '0'); // below floor
        $this->assertSame(1, AgentConfig::autoQuietDays());
        Setting::setValue('agent_auto_quiet_days', '30');
        $this->assertSame(30, AgentConfig::autoQuietDays());
    }

    // ── Opus model (agent_model) ──────────────────────────────────────────────

    /** AiConfig::opusModel() must return the canonical Opus 4.8 model id. */
    public function test_opus_model_returns_the_expected_id(): void
    {
        $this->assertSame('claude-opus-4-8', AiConfig::opusModel());
    }

    /** agentModel() must default to the Opus id when no Setting is stored. */
    public function test_agent_model_defaults_to_opus(): void
    {
        $this->assertSame(AiConfig::opusModel(), AgentConfig::agentModel());
        $this->assertSame('claude-opus-4-8', AgentConfig::agentModel());
    }

    /** agentModel() must honour an operator-set override (e.g. a future Sonnet id). */
    public function test_agent_model_is_overridable_via_setting(): void
    {
        Setting::setValue('agent_model', 'claude-sonnet-4-6');
        $this->assertSame('claude-sonnet-4-6', AgentConfig::agentModel());
    }

    /** Blank/whitespace Setting must fall back to the Opus default (mirrors significanceModel). */
    public function test_agent_model_blank_setting_falls_back_to_default(): void
    {
        Setting::setValue('agent_model', '   ');
        $this->assertSame('claude-opus-4-8', AgentConfig::agentModel());
    }

    /**
     * The production binding must yield a TechnicianAgent instance.
     * This is a smoke test that withConfiguredModel() is callable and the binding is wired.
     */
    public function test_production_binding_resolves_a_technician_agent(): void
    {
        $agent = app(TechnicianAgent::class);
        $this->assertInstanceOf(TechnicianAgent::class, $agent);
    }

    // ── situationContextEnabled ───────────────────────────────────────────────

    public function test_situation_context_enabled_defaults_false(): void
    {
        $this->assertFalse(AgentConfig::situationContextEnabled());
    }

    public function test_situation_context_enabled_true_when_set_to_1(): void
    {
        Setting::setValue('agent_situation_context_enabled', '1');
        $this->assertTrue(AgentConfig::situationContextEnabled());
    }

    public function test_situation_context_enabled_false_for_string_true(): void
    {
        Setting::setValue('agent_situation_context_enabled', 'true');
        $this->assertFalse(AgentConfig::situationContextEnabled());
    }

    public function test_situation_context_enabled_false_for_zero(): void
    {
        Setting::setValue('agent_situation_context_enabled', '0');
        $this->assertFalse(AgentConfig::situationContextEnabled());
    }
}
