<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianTier;
use App\Models\Setting;
use App\Models\User;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_and_kill_switch_default_off(): void
    {
        $this->assertFalse(TechnicianConfig::enabled());
        $this->assertFalse(TechnicianConfig::killSwitchEngaged());
    }

    public function test_enabled_and_kill_switch_read_settings(): void
    {
        Setting::setValue('technician_enabled', '1');
        Setting::setValue('technician_kill_switch', '1');

        $this->assertTrue(TechnicianConfig::enabled());
        $this->assertTrue(TechnicianConfig::killSwitchEngaged());
    }

    public function test_ai_actor_falls_back_to_first_user_then_honours_setting(): void
    {
        $first = User::factory()->create();
        $chet = User::factory()->create();

        $this->assertSame($first->id, TechnicianConfig::aiActorUserId());

        Setting::setValue('triage_system_user_id', (string) $chet->id);
        $this->assertSame($chet->id, TechnicianConfig::aiActorUserId());
    }

    public function test_tier_map_is_empty_when_unset_or_invalid(): void
    {
        $this->assertSame([], TechnicianConfig::tierMap());

        Setting::setValue('technician_action_tiers', 'not-json');
        $this->assertSame([], TechnicianConfig::tierMap());

        Setting::setValue('technician_action_tiers', json_encode([
            'send_ack' => TechnicianTier::Auto->value,
            'send_reply' => TechnicianTier::Approve->value,
        ]));
        $this->assertSame([
            'send_ack' => 'auto',
            'send_reply' => 'approve',
        ], TechnicianConfig::tierMap());
    }

    public function test_per_client_overrides_and_defaults(): void
    {
        $this->assertFalse(TechnicianConfig::clientExcluded(7));
        $this->assertFalse(TechnicianConfig::clientAlwaysHuman(7));
        $this->assertTrue(TechnicianConfig::operatorCovering());
        $this->assertSame([], TechnicianConfig::escalationChain());

        Setting::setValue('technician_excluded_client_ids', json_encode([7, 9]));
        Setting::setValue('technician_always_human_client_ids', json_encode([7]));
        Setting::setValue('technician_operator_covering', '0');
        Setting::setValue('technician_escalation_chain', json_encode([3, 1]));

        $this->assertTrue(TechnicianConfig::clientExcluded(7));
        $this->assertFalse(TechnicianConfig::clientExcluded(8));
        $this->assertTrue(TechnicianConfig::clientAlwaysHuman(7));
        $this->assertFalse(TechnicianConfig::operatorCovering());
        $this->assertSame([3, 1], TechnicianConfig::escalationChain());
    }

    public function test_ack_eta_text_default_and_override(): void
    {
        $this->assertSame('within one business day', TechnicianConfig::ackEtaText());

        Setting::setValue('technician_ack_eta_text', 'by end of next business day');
        $this->assertSame('by end of next business day', TechnicianConfig::ackEtaText());
    }
}
