<?php

namespace Tests\Feature\Agent\Escalation;

use App\Enums\FlagAttentionCategory;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Escalation\EscalationNotifier;
use App\Services\Agent\FlagAttentionTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Increment H Task 3 — EscalationNotifier wired into flag_attention.
 *
 * Tests that:
 *  1. When escalation is enabled AND a NEW flag is created, EscalationNotifier::notify
 *     is called exactly once with the correct arguments.
 *  2. When escalation is disabled (the default), notify is NEVER called — but the
 *     flag IS still recorded (dormant = behaviour unchanged).
 *  3. A duplicate/idempotent re-flag (same reason, already Flagged) hits the
 *     early-return and does NOT re-notify — no spam.
 *  4. An empty reason is rejected before any flag or notify.
 *  5. A revived flag (same blocker, previously resolved) DOES notify again —
 *     a recurring need re-surfaces.
 */
class FlagAttentionEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function openTicketWithClient(): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
    }

    /** Test 1: enabled + new flag → notify called once with the correct args. */
    public function test_enabled_and_new_flag_notifies_once(): void
    {
        User::factory()->create(); // AI actor fallback for the gate audit row
        $ticket = $this->openTicketWithClient();

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldReceive('notify')
            ->once()
            ->withArgs(function (
                Ticket $t,
                TechnicianRun $run,
                FlagAttentionCategory $cat,
                string $blocker,
            ) use ($ticket): bool {
                return $t->is($ticket)
                    && $run->action_type === 'flag_attention'
                    && $cat === FlagAttentionCategory::NeedsDecision
                    && $blocker === 'Client demands a refund I cannot authorise.';
            });

        app(FlagAttentionTool::class)->execute($ticket, [
            'reason' => 'Client demands a refund I cannot authorise.',
            'category' => 'needs_decision',
        ]);

        // The Flagged run IS still recorded (additive — core behaviour unchanged).
        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'flag_attention')
            ->first();
        $this->assertNotNull($run, 'flag_attention run must be created');
        $this->assertSame(TechnicianRunState::Flagged, $run->state);
    }

    /** Test 2: disabled (default) → notify NEVER called, flag IS still recorded. */
    public function test_disabled_records_flag_but_does_not_notify(): void
    {
        User::factory()->create();
        $ticket = $this->openTicketWithClient();

        // No setting → escalationEnabled() returns false (dormant by default).

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldNotReceive('notify');

        app(FlagAttentionTool::class)->execute($ticket, [
            'reason' => 'Need a decision on billing.',
            'category' => 'needs_decision',
        ]);

        // Dormant: flag IS recorded exactly as before, no notify.
        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'flag_attention')
            ->first();
        $this->assertNotNull($run, 'flag must still be recorded when escalation is disabled');
        $this->assertSame(TechnicianRunState::Flagged, $run->state);
    }

    /** Test 3: duplicate (already Flagged) → notify exactly ONCE total, not twice. */
    public function test_duplicate_flag_does_not_re_notify(): void
    {
        User::factory()->create();
        $ticket = $this->openTicketWithClient();

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        // Only the first call (new flag) should notify. The second is an idempotent
        // duplicate that hits the "already flagged" early-return before the wire-in.
        $notifier->shouldReceive('notify')->once();

        $tool = app(FlagAttentionTool::class);
        $input = ['reason' => 'Same blocking reason.', 'category' => 'uncertain'];

        $tool->execute($ticket, $input); // first → new flag → notify fires
        $tool->execute($ticket, $input); // second → already Flagged → early-return → NO re-notify
    }

    /** Test 4: empty reason → gate rejected, no flag, no notify. */
    public function test_empty_reason_no_flag_no_notify(): void
    {
        $ticket = $this->openTicketWithClient();

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        $notifier->shouldNotReceive('notify');

        app(FlagAttentionTool::class)->execute($ticket, [
            'reason' => '',
            'category' => 'other',
        ]);

        $this->assertSame(0, TechnicianRun::where('action_type', 'flag_attention')->count());
    }

    /** Test 5: revived flag (resolved then re-raised) → notify fires again. */
    public function test_revived_flag_notifies_again(): void
    {
        User::factory()->create();
        $ticket = $this->openTicketWithClient();

        Setting::setValue('agent_escalation_enabled', '1');

        $notifier = $this->mock(EscalationNotifier::class);
        // notify must fire for the first flag AND after the revive — total 2.
        $notifier->shouldReceive('notify')->twice();

        $tool = app(FlagAttentionTool::class);
        $input = ['reason' => 'Recurring need for a person.', 'category' => 'needs_decision'];

        // First flag → notify #1.
        $tool->execute($ticket, $input);

        // Resolve the flag (acknowledge → Done).
        $run = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'flag_attention')
            ->first();
        $run->acknowledgeFlag();
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        // Same flag again (same hash) → revived to Flagged → notify #2.
        $tool->execute($ticket, $input);
    }
}
