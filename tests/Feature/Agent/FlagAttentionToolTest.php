<?php

namespace Tests\Feature\Agent;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\FlagAttentionTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FlagAttentionTool (Increment H) — the agent's "this is over my head, a human
 * needs to look" escalation. It mirrors ProposeCloseTool's gated-run shape, but
 * the KEY DIFFERENCE is that a flag has NO execution side-effect: it is a NOTICE.
 * It records a HELD run (state Flagged) + an audit row, and must NEVER auto-execute
 * or touch a ticket/client — even if an operator misconfigures it to 'auto'.
 */
class FlagAttentionToolTest extends TestCase
{
    use RefreshDatabase;

    private function openTicketWithClient(): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
    }

    public function test_flag_records_a_held_flagged_run_with_reason_and_category(): void
    {
        User::factory()->create(); // AI actor fallback for the audit row
        $ticket = $this->openTicketWithClient();

        $result = app(FlagAttentionTool::class)->execute($ticket, [
            'reason' => 'Client is demanding a refund decision I am not authorised to make.',
            'category' => 'needs_decision',
        ]);

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'flag_attention')->first();
        $this->assertNotNull($run, 'a flag_attention run must be created');
        $this->assertSame(TechnicianRunState::Flagged, $run->state);
        $this->assertSame('Client is demanding a refund decision I am not authorised to make.', $run->proposed_content);
        $this->assertSame('needs_decision', $run->proposed_meta['category']);

        // Held + audited (durable attribution), NOT executed.
        $this->assertDatabaseHas('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'flag_attention',
            'result_status' => 'awaiting_approval',
        ]);

        $this->assertIsString($result);
    }

    public function test_flag_can_never_auto_execute_or_touch_the_ticket(): void
    {
        User::factory()->create();
        $ticket = $this->openTicketWithClient();
        $originalStatus = $ticket->status;

        // ADVERSARIAL: even if an operator hand-maps flag_attention to 'auto', a flag is
        // a notice, not an executable action — it must STILL hold and touch nothing.
        Setting::setValue('technician_action_tiers', json_encode(['flag_attention' => 'auto']));

        app(FlagAttentionTool::class)->execute($ticket, [
            'reason' => 'This is over my head and needs a person.',
            'category' => 'uncertain',
        ]);

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'flag_attention')->first();
        $this->assertSame(TechnicianRunState::Flagged, $run->state, 'a flag must NEVER auto-execute');

        // The ticket is untouched, and there is NO 'executed' audit row — only the held one.
        $this->assertSame($originalStatus, $ticket->fresh()->status);
        $this->assertDatabaseMissing('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'action_type' => 'flag_attention',
            'result_status' => 'executed',
        ]);
    }

    public function test_an_empty_reason_creates_no_flag(): void
    {
        $ticket = $this->openTicketWithClient();

        app(FlagAttentionTool::class)->execute($ticket, ['reason' => '   ', 'category' => 'other']);

        $this->assertSame(0, TechnicianRun::where('action_type', 'flag_attention')->count());
    }

    public function test_an_invalid_category_falls_back_to_other(): void
    {
        User::factory()->create();
        $ticket = $this->openTicketWithClient();

        app(FlagAttentionTool::class)->execute($ticket, ['reason' => 'needs a person', 'category' => 'bogus-value']);

        $run = TechnicianRun::where('action_type', 'flag_attention')->first();
        $this->assertSame('other', $run->proposed_meta['category']);
    }

    public function test_a_duplicate_flag_does_not_create_a_second_run(): void
    {
        User::factory()->create();
        $ticket = $this->openTicketWithClient();
        $tool = app(FlagAttentionTool::class);

        $tool->execute($ticket, ['reason' => 'same blocking reason', 'category' => 'uncertain']);
        $tool->execute($ticket, ['reason' => 'same blocking reason', 'category' => 'uncertain']);

        $this->assertSame(
            1,
            TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'flag_attention')->count(),
            'an identical re-flag must not create a duplicate run'
        );
    }

    public function test_flags_are_bounded_by_agent_max_pending(): void
    {
        User::factory()->create();
        Setting::setValue('agent_max_pending', '2');

        // Two flags already pending (Flagged) on other tickets => the cap is reached.
        foreach (range(1, 2) as $i) {
            $t = $this->openTicketWithClient();
            TechnicianRun::create([
                'ticket_id' => $t->id,
                'client_id' => $t->client_id,
                'action_type' => 'flag_attention',
                'content_hash' => 'pending-'.$i,
                'state' => TechnicianRunState::Flagged,
                'proposed_content' => 'pending',
                'proposed_meta' => ['category' => 'other'],
                'tokens_used' => 0,
            ]);
        }

        $ticket = $this->openTicketWithClient();
        app(FlagAttentionTool::class)->execute($ticket, ['reason' => 'one too many', 'category' => 'other']);

        $this->assertNull(
            TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'flag_attention')->first(),
            'a flag must not be created once the pending-flag cap is reached'
        );
    }
}
