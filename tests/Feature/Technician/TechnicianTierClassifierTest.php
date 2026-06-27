<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianTier;
use App\Models\Setting;
use App\Services\Technician\TechnicianTierClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianTierClassifierTest extends TestCase
{
    use RefreshDatabase;

    private function setTiers(array $map): void
    {
        Setting::setValue('technician_action_tiers', json_encode($map));
    }

    public function test_unmapped_action_defaults_to_approve(): void
    {
        $this->assertSame(
            TechnicianTier::Approve,
            (new TechnicianTierClassifier)->classify('send_reply'),
        );
    }

    public function test_explicit_auto_is_honoured(): void
    {
        $this->setTiers(['send_ack' => 'auto']);

        $this->assertSame(
            TechnicianTier::Auto,
            (new TechnicianTierClassifier)->classify('send_ack'),
        );
    }

    public function test_explicit_block_is_honoured(): void
    {
        $this->setTiers(['run_script' => 'block']);

        $this->assertSame(
            TechnicianTier::Block,
            (new TechnicianTierClassifier)->classify('run_script'),
        );
    }

    public function test_unknown_tier_string_is_treated_as_approve(): void
    {
        $this->setTiers(['send_reply' => 'totally-bogus']);

        $this->assertSame(
            TechnicianTier::Approve,
            (new TechnicianTierClassifier)->classify('send_reply'),
        );
    }

    public function test_empty_map_default_denies_everything(): void
    {
        // No setting at all → empty map → Approve (never Auto).
        $this->assertSame(
            TechnicianTier::Approve,
            (new TechnicianTierClassifier)->classify('send_ack'),
        );
    }

    public function test_send_reply_can_never_be_auto_even_when_the_tier_map_says_auto(): void
    {
        // ADVERSARIAL (A2 landmine): a client-facing reply must ALWAYS be held —
        // even an operator who (mis)maps send_reply to 'auto' cannot make it auto-send.
        // send_reply is hard-coded to Approve, exactly like flag_attention.
        $this->setTiers(['send_reply' => 'auto']);

        $this->assertSame(
            TechnicianTier::Approve,
            (new TechnicianTierClassifier)->classify('send_reply'),
        );
    }

    public function test_send_reply_with_confidence_still_cannot_be_auto(): void
    {
        // Even passing a high confidence (the propose_close auto channel) must not
        // make send_reply auto — confidence does NOT gate a client send.
        $this->setTiers(['send_reply' => 'auto']);

        $this->assertSame(
            TechnicianTier::Approve,
            (new TechnicianTierClassifier)->classify('send_reply', 0.99),
        );
    }
}
