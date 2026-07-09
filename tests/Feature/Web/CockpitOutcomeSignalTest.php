<?php

namespace Tests\Feature\Web;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Jobs\RunTechnicianAgent;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalInboxEntry;
use App\Models\SignalRoute;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Signals\InteractsWithSignalEvents;
use Tests\TestCase;

/**
 * The cockpit-endpoint wiring for the MCP-Chet feedback flywheel (bd psa-0xvv):
 * approve / deny / correct each emit the matching outbound Signal, the emit is
 * idempotent with the underlying CAS, and the sensitive corrected signal honours
 * the reference-only MCP boundary end-to-end (the correction text never reaches the
 * agent's inbox).
 */
class CockpitOutcomeSignalTest extends TestCase
{
    use InteractsWithSignalEvents;
    use RefreshDatabase;

    /** @return array{0: User, 1: Ticket, 2: TechnicianRun} */
    private function heldRun(string $actionType = 'propose_close'): array
    {
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'email' => 'contact@example.com',
            'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $person->id,
            'status' => TicketStatus::InProgress,
        ]);
        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => $actionType,
            'content_hash' => hash('sha256', $actionType.':'.$ticket->id.':held'),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Looks resolved.',
        ]);

        return [$actor, $ticket, $run];
    }

    public function test_approving_a_close_emits_proposal_approved(): void
    {
        Bus::fake();
        [, $ticket, $run] = $this->heldRun();

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.approve', $run))
            ->assertRedirect();

        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $event = $this->assertSingleSignalEvent('agent.proposal_approved');
        $this->assertSame($ticket->id, $event->entity_id);
    }

    public function test_denying_emits_proposal_declined_exactly_once_even_on_double_tap(): void
    {
        Bus::fake();
        [, , $run] = $this->heldRun();
        $staff = User::factory()->create();

        $this->actingAs($staff)->post(route('cockpit.deny', $run))->assertRedirect();
        // Second tap: deny()'s CAS finds the run no longer AwaitingApproval → no re-emit.
        $this->actingAs($staff)->post(route('cockpit.deny', $run->fresh()))->assertRedirect();

        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
        $this->assertSame(1, SignalEvent::query()->where('type_key', 'agent.proposal_declined')->count());
    }

    public function test_correcting_emits_proposal_corrected(): void
    {
        Bus::fake();
        [, $ticket, $run] = $this->heldRun();

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.correct', $run), ['correction' => 'client is VIP — never auto-close'])
            ->assertRedirect();

        $this->assertSame(TechnicianRunState::Superseded, $run->fresh()->state);
        $event = $this->assertSingleSignalEvent('agent.proposal_corrected');
        $this->assertSame($ticket->id, $event->entity_id);
    }

    public function test_correction_is_delivered_to_mcp_inbox_reference_only(): void
    {
        // Let the signal route + deliver for real (sync queue); fake ONLY the
        // in-process re-assessment job so we don't drive the whole technician agent.
        Bus::fake([RunTechnicianAgent::class]);

        [, $ticket, $run] = $this->heldRun();

        $destination = SignalDestination::create([
            'label' => 'Chet',
            'type' => 'mcp',
            'mcp_token_label' => 'chet',
        ]);
        SignalRoute::create([
            'label' => 'Chet feedback',
            'event_filter' => ['types' => ['agent.proposal_corrected']],
            'enabled' => true,
        ])->steps()->create([
            'step_order' => 1,
            'destination_id' => $destination->id,
        ]);

        $secret = 'vip-account-do-not-close-marker';

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.correct', $run), ['correction' => $secret])
            ->assertRedirect();

        // The correction is stored on the event summary (for content sinks + audit)…
        $event = $this->assertSingleSignalEvent('agent.proposal_corrected');
        $this->assertStringContainsString($secret, $event->summary);

        // …but the MCP inbox row is reference-only: event + ticket + category, and the
        // correction text is NOT in it.
        $entry = SignalInboxEntry::query()->where('destination_id', $destination->id)->firstOrFail();
        $this->assertSame([
            'event' => 'agent.proposal_corrected',
            'entity' => ['type' => $ticket->getMorphClass(), 'id' => $ticket->id],
            'category' => 'propose_close',
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ], $entry->payload);
        $this->assertStringNotContainsString($secret, json_encode($entry->payload));
    }
}
