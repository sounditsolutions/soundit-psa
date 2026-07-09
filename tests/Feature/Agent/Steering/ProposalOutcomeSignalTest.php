<?php

namespace Tests\Feature\Agent\Steering;

use App\Enums\TechnicianRunState;
use App\Enums\TicketPriority;
use App\Models\Client;
use App\Models\SignalEvent;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Agent\Steering\ProposalOutcomeSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Signals\InteractsWithSignalEvents;
use Tests\TestCase;

/**
 * The emit side of the MCP-Chet feedback flywheel (bd psa-0xvv): approve / decline /
 * correct verdicts on a held proposal become outbound Signal events. Bus::fake() so
 * we assert the persisted SignalEvent (written synchronously by SignalHub::emit)
 * without exercising routing/delivery — the reference-only MCP boundary is covered
 * end-to-end in CockpitOutcomeSignalTest.
 */
class ProposalOutcomeSignalTest extends TestCase
{
    use InteractsWithSignalEvents;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    /** @return array{0: Ticket, 1: TechnicianRun} */
    private function heldProposal(string $actionType = 'propose_close'): array
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'priority' => TicketPriority::P2,
        ]);
        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => $actionType,
            'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Looks resolved.',
        ]);

        return [$ticket, $run];
    }

    public function test_approved_emits_one_reference_signal_with_ticket_entity_and_context(): void
    {
        [$ticket, $run] = $this->heldProposal();

        app(ProposalOutcomeSignal::class)->approved($run);

        $event = $this->assertSingleSignalEvent('agent.proposal_approved');
        $this->assertSame($ticket->getMorphClass(), $event->entity_type);
        $this->assertSame($ticket->id, $event->entity_id);
        $this->assertSame($ticket->client_id, $event->context['client_id'] ?? null);
        $this->assertSame($ticket->priority_order, $event->context['priority'] ?? null);
        // action_type is carried as the discriminating category.
        $this->assertSame('propose_close', $event->context['category'] ?? null);
        $this->assertStringContainsString('approved', $event->summary);
        $this->assertStringContainsString('#'.$ticket->id, $event->summary);
    }

    public function test_declined_emits_declined_signal(): void
    {
        [, $run] = $this->heldProposal('send_reply');

        app(ProposalOutcomeSignal::class)->declined($run);

        $event = $this->assertSingleSignalEvent('agent.proposal_declined');
        $this->assertSame('send_reply', $event->context['category'] ?? null);
        $this->assertStringContainsString('declined', $event->summary);
    }

    public function test_corrected_signal_carries_the_correction_text_in_the_summary(): void
    {
        [$ticket, $run] = $this->heldProposal();

        app(ProposalOutcomeSignal::class)->corrected($run, 'client is on a no-auto-close contract');

        $event = $this->assertSingleSignalEvent('agent.proposal_corrected');
        $this->assertSame($ticket->id, $event->entity_id);
        // The correction is the lesson — it rides the SignalEvent summary (for content
        // sinks + audit), NOT the MCP reference payload.
        $this->assertStringContainsString('corrected', $event->summary);
        $this->assertStringContainsString('client is on a no-auto-close contract', $event->summary);
    }

    public function test_missing_ticket_emits_nothing(): void
    {
        [$ticket, $run] = $this->heldProposal();
        $ticket->delete(); // soft-delete → belongsTo resolves null

        app(ProposalOutcomeSignal::class)->approved($run->fresh());

        // (creating the ticket emits its own ticket.created signal — scope to ours)
        $this->assertSame(0, SignalEvent::query()->where('type_key', 'like', 'agent.proposal_%')->count());
    }
}
