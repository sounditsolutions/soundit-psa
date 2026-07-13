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

    public function test_email_recipient_knobs_default_off_and_read_settings(): void
    {
        $this->assertFalse(TechnicianConfig::allowArbitraryEmailRecipients());
        $this->assertFalse(TechnicianConfig::directEmailNewRecipients());

        Setting::setValue('allow_arbitrary_email_recipients', '1');
        Setting::setValue('direct_email_new_recipients', '1');

        $this->assertTrue(TechnicianConfig::allowArbitraryEmailRecipients());
        $this->assertTrue(TechnicianConfig::directEmailNewRecipients());
    }

    public function test_staged_arbitrary_recipient_knob_defaults_off_and_never_widens_immediate(): void
    {
        // psa-w4e0: ships dormant — raw knob and effective staged policy both off.
        $this->assertFalse(TechnicianConfig::allowArbitraryEmailRecipientsStaged());
        $this->assertFalse(TechnicianConfig::stagedSendsAllowArbitraryRecipients());

        // Staged-only knob ON opens the staged (human-approved) path ONLY: the global
        // knob the immediate/direct path reads stays off (exfil guard independence).
        Setting::setValue('allow_arbitrary_email_recipients_staged', '1');
        $this->assertTrue(TechnicianConfig::allowArbitraryEmailRecipientsStaged());
        $this->assertTrue(TechnicianConfig::stagedSendsAllowArbitraryRecipients());
        $this->assertFalse(TechnicianConfig::allowArbitraryEmailRecipients());

        // The global knob implies the staged POLICY (its pre-existing semantics
        // covered both paths) while the raw staged knob — what the settings
        // checkbox renders — stays honest about its own stored value.
        Setting::setValue('allow_arbitrary_email_recipients_staged', '0');
        Setting::setValue('allow_arbitrary_email_recipients', '1');
        $this->assertFalse(TechnicianConfig::allowArbitraryEmailRecipientsStaged());
        $this->assertTrue(TechnicianConfig::stagedSendsAllowArbitraryRecipients());
    }

    public function test_ai_actor_falls_back_to_first_user_then_honours_setting(): void
    {
        $first = User::factory()->create();
        $chet = User::factory()->create();

        $this->assertSame($first->id, TechnicianConfig::aiActorUserId());

        Setting::setValue('triage_system_user_id', (string) $chet->id);
        $this->assertSame($chet->id, TechnicianConfig::aiActorUserId());
    }

    public function test_required_ai_actor_user_id_requires_configured_existing_user_without_fallback(): void
    {
        User::factory()->create(['name' => 'Human First']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI actor user is not configured');

        TechnicianConfig::requiredAiActorUserId();
    }

    public function test_required_ai_actor_user_id_rejects_stale_configuration(): void
    {
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $actor->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configured AI actor user does not exist');

        TechnicianConfig::requiredAiActorUserId();
    }

    public function test_required_ai_actor_user_id_returns_configured_actor(): void
    {
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $this->assertSame($actor->id, TechnicianConfig::requiredAiActorUserId());
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
