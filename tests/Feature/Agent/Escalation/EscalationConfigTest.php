<?php

namespace Tests\Feature\Agent\Escalation;

use App\Enums\FlagAttentionCategory;
use App\Models\Setting;
use App\Support\AgentConfig;
use App\Support\TeamsBotConfig;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscalationConfigTest extends TestCase
{
    use RefreshDatabase;

    // ── 1. AgentConfig::escalationEnabled() ──────────────────────────────────

    public function test_escalation_enabled_defaults_false(): void
    {
        $this->assertFalse(AgentConfig::escalationEnabled());
    }

    public function test_escalation_enabled_true_when_set_to_one(): void
    {
        Setting::setValue('agent_escalation_enabled', '1');
        $this->assertTrue(AgentConfig::escalationEnabled());
    }

    public function test_escalation_enabled_false_when_set_to_zero(): void
    {
        Setting::setValue('agent_escalation_enabled', '0');
        $this->assertFalse(AgentConfig::escalationEnabled());
    }

    // ── 2. TechnicianConfig: routing — both roles set ────────────────────────

    public function test_routing_both_roles_set(): void
    {
        Setting::setValue('technician_escalation_judgment_user', '10');
        Setting::setValue('technician_escalation_handson_user', '20');

        $this->assertSame(10, TechnicianConfig::escalationRecipientFor(FlagAttentionCategory::NeedsDecision));
        $this->assertSame(20, TechnicianConfig::escalationRecipientFor(FlagAttentionCategory::NeedsHandsOnsite));
        $this->assertSame(20, TechnicianConfig::escalationRecipientFor(FlagAttentionCategory::NeedsOverflow));
        $this->assertSame(10, TechnicianConfig::escalationRecipientFor(FlagAttentionCategory::Uncertain));
        $this->assertSame(10, TechnicianConfig::escalationRecipientFor(FlagAttentionCategory::Other));
    }

    // ── 3. TechnicianConfig: cross-fallback ──────────────────────────────────

    public function test_routing_only_judgment_set_falls_back_for_handson_category(): void
    {
        Setting::setValue('technician_escalation_judgment_user', '10');
        // handson not set

        $this->assertSame(10, TechnicianConfig::escalationRecipientFor(FlagAttentionCategory::NeedsHandsOnsite));
        $this->assertSame(10, TechnicianConfig::escalationRecipientFor(FlagAttentionCategory::NeedsOverflow));
        $this->assertSame(10, TechnicianConfig::escalationRecipientFor(FlagAttentionCategory::NeedsDecision));
    }

    public function test_routing_only_handson_set_falls_back_for_judgment_category(): void
    {
        Setting::setValue('technician_escalation_handson_user', '20');
        // judgment not set

        $this->assertSame(20, TechnicianConfig::escalationRecipientFor(FlagAttentionCategory::NeedsDecision));
        $this->assertSame(20, TechnicianConfig::escalationRecipientFor(FlagAttentionCategory::Uncertain));
        $this->assertSame(20, TechnicianConfig::escalationRecipientFor(FlagAttentionCategory::Other));
    }

    public function test_routing_neither_role_set_returns_null(): void
    {
        // neither set
        $this->assertNull(TechnicianConfig::escalationRecipientFor(FlagAttentionCategory::NeedsDecision));
        $this->assertNull(TechnicianConfig::escalationRecipientFor(FlagAttentionCategory::NeedsHandsOnsite));
    }

    // ── 4. TeamsBotConfig: escalation chat ref getters ───────────────────────

    public function test_escalation_conversation_id_null_when_unset(): void
    {
        $this->assertNull(TeamsBotConfig::escalationConversationId());
    }

    public function test_escalation_conversation_id_returns_trimmed_value(): void
    {
        Setting::setValue('teams_escalation_conversation_id', '  19:abc123@thread.v2  ');
        $this->assertSame('19:abc123@thread.v2', TeamsBotConfig::escalationConversationId());
    }

    public function test_escalation_service_url_null_when_unset(): void
    {
        $this->assertNull(TeamsBotConfig::escalationServiceUrl());
    }

    public function test_escalation_service_url_returns_trimmed_value(): void
    {
        Setting::setValue('teams_escalation_service_url', '  https://smba.trafficmanager.net/amer/  ');
        $this->assertSame('https://smba.trafficmanager.net/amer/', TeamsBotConfig::escalationServiceUrl());
    }

    // ── role user id getters ──────────────────────────────────────────────────

    public function test_judgment_user_id_null_when_unset(): void
    {
        $this->assertNull(TechnicianConfig::escalationJudgmentUserId());
    }

    public function test_judgment_user_id_returns_int_when_set(): void
    {
        Setting::setValue('technician_escalation_judgment_user', '42');
        $this->assertSame(42, TechnicianConfig::escalationJudgmentUserId());
    }

    public function test_handson_user_id_null_when_unset(): void
    {
        $this->assertNull(TechnicianConfig::escalationHandsOnUserId());
    }

    public function test_handson_user_id_returns_int_when_set(): void
    {
        Setting::setValue('technician_escalation_handson_user', '99');
        $this->assertSame(99, TechnicianConfig::escalationHandsOnUserId());
    }
}
