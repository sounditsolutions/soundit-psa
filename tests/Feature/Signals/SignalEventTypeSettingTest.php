<?php

namespace Tests\Feature\Signals;

use App\Models\SignalEventTypeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalEventTypeSettingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The D4 global per-type master toggle defaults ON: with no overlay row, every type is
     * globally enabled, so the feature ships dormant and backward-compatible — nothing about
     * routing changes until an operator explicitly disables a type.
     */
    public function test_a_type_with_no_overlay_row_is_globally_enabled(): void
    {
        $this->assertTrue(SignalEventTypeSetting::isTypeGloballyEnabled('ticket.created'));
        $this->assertTrue(SignalEventTypeSetting::isTypeGloballyEnabled('agent.flag_attention'));
    }

    /** An explicit disabled overlay row turns the master gate off for that type only. */
    public function test_an_explicit_disabled_row_gates_the_type(): void
    {
        SignalEventTypeSetting::create(['type_key' => 'ticket.created', 'enabled' => false]);

        $this->assertFalse(SignalEventTypeSetting::isTypeGloballyEnabled('ticket.created'));
        // Unrelated types stay enabled by default.
        $this->assertTrue(SignalEventTypeSetting::isTypeGloballyEnabled('ticket.client_replied'));
    }

    /** An explicit enabled=true row reads as enabled (same as absent, but persisted). */
    public function test_an_explicit_enabled_row_reads_enabled(): void
    {
        SignalEventTypeSetting::create(['type_key' => 'ticket.created', 'enabled' => true]);

        $this->assertTrue(SignalEventTypeSetting::isTypeGloballyEnabled('ticket.created'));
    }

    /** setGlobalEnabled upserts idempotently — one row per type, latest wins. */
    public function test_set_global_enabled_upserts_one_row_per_type(): void
    {
        SignalEventTypeSetting::setGlobalEnabled('ticket.created', false);
        SignalEventTypeSetting::setGlobalEnabled('ticket.created', true);
        SignalEventTypeSetting::setGlobalEnabled('ticket.created', false);

        $this->assertSame(1, SignalEventTypeSetting::where('type_key', 'ticket.created')->count());
        $this->assertFalse(SignalEventTypeSetting::isTypeGloballyEnabled('ticket.created'));
    }
}
