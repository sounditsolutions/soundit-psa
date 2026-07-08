<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Enums\NoteType;
use App\Enums\PersonType;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianEmergency;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Technician\DraftPipeline;
use App\Services\Technician\TechnicianAssessment;
use App\Services\Technician\TechnicianClassifier;
use App\Services\TicketResolutionDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class DraftPipelineEmergencyHaltTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a ticket that WOULD draft — AI configured, classifier says ownable,
     * drafter returns a reply, and a real client reply note exists.
     * This mirrors the setup in DraftPipelineTest::test_ownable_ticket_records_held_reply_and_resolution.
     */
    private function wouldDraftTicket(): Ticket
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');

        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'email' => 'c@example.com',
            'is_active' => true,
        ]);

        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $person->id,
        ]);

        // A genuine (non-AI) client reply so hasUnaddressedClientReply() returns true.
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => 'Client',
            'who_type' => WhoType::EndUser,
            'ai_authored' => false,
            'body' => 'Any update on this?',
            'note_type' => NoteType::Reply,
            'is_private' => false,
            'noted_at' => now(),
        ]);

        return $ticket;
    }

    private function mockOwnable(): void
    {
        $this->mock(TechnicianClassifier::class, fn (MockInterface $m) => $m->shouldReceive('classify')
            ->andReturn(new TechnicianAssessment(0.85, true, ['known-runbook'], 160)));

        $this->mock(TicketResolutionDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')
            ->andReturn('Reset the print spooler; printer back online.'));
    }

    private function openEmergencyFor(Ticket $ticket, ?array $ticketIds = null): TechnicianEmergency
    {
        return TechnicianEmergency::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'signature' => 's',
            'severity' => 3,
            'reasons' => ['age'],
            'detected_by' => 'rules',
            'state' => EmergencyState::Open,
            'escalation_step' => 0,
            'ticket_ids' => $ticketIds ?? [$ticket->id],
            'alerted_at' => now(),
        ]);
    }

    /**
     * The primary halt test: a ticket that WOULD otherwise draft is stopped by an open emergency.
     * We wire up ownable mocks and a client reply note so the pipeline genuinely reaches the
     * drafting path — confirming the emergency guard fires before any send_reply run is created.
     */
    public function test_open_emergency_halts_a_ticket_that_would_otherwise_draft(): void
    {
        // Wire mocks so the pipeline would reach drafting if not halted.
        $this->mockOwnable();

        $ticket = $this->wouldDraftTicket();

        // Create an open emergency for this ticket (representative member).
        $this->openEmergencyFor($ticket);

        app(DraftPipeline::class)->run($ticket);

        $this->assertSame(
            0,
            TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_resolution')->count(),
            'Expected no propose_resolution run — open emergency should halt the pipeline before drafting.'
        );
    }

    /**
     * CO-13 storm-member test: the guard fires for a NON-representative storm member.
     * The emergency's ticket_id = ticket A, but ticket_ids contains ticket B (the ticket
     * under test). hasOpenEmergency() must find it via orWhereJsonContains('ticket_ids', ...).
     */
    public function test_open_emergency_halts_non_representative_storm_member(): void
    {
        // Wire mocks so the pipeline would reach drafting if not halted.
        $this->mockOwnable();

        // Ticket A — the representative emergency owner (different client is fine; we just
        // need a separate ticket row for a realistic storm).
        $clientA = Client::factory()->create();
        $ticketA = Ticket::factory()->create(['client_id' => $clientA->id]);

        // Ticket B — the non-representative storm member that is under test.
        $ticketB = $this->wouldDraftTicket();

        // Emergency is recorded against ticket A but ticket_ids includes ticket B.
        TechnicianEmergency::create([
            'ticket_id' => $ticketA->id,
            'client_id' => $ticketA->client_id,
            'signature' => 'storm-sig',
            'severity' => 4,
            'reasons' => ['volume'],
            'detected_by' => 'rules',
            'state' => EmergencyState::Open,
            'escalation_step' => 0,
            'ticket_ids' => [$ticketA->id, $ticketB->id],
            'alerted_at' => now(),
        ]);

        // Run the pipeline on ticket B — must be halted via the JSON-contains branch.
        app(DraftPipeline::class)->run($ticketB);

        $this->assertSame(
            0,
            TechnicianRun::where('ticket_id', $ticketB->id)->where('action_type', 'propose_resolution')->count(),
            'Expected no propose_resolution run — storm-member ticket B should be halted by the open emergency on ticket A.'
        );
    }

    /**
     * A2b: client REPLIES now come from the agent path, so the emergency halt must cover
     * RunTechnicianAgent too (it used to be enforced by DraftPipeline). With an open
     * emergency the agent must NOT even reach the SignificanceGate — it halts first, so
     * no reply (or close/flag) is drafted during a crisis.
     */
    public function test_open_emergency_halts_the_agent_before_it_can_draft_a_reply(): void
    {
        Setting::setValue('agent_enabled', '1');
        // If the guard fails, the agent would proceed to the (cheap) SignificanceGate; we
        // assert it is NEVER reached — proving the emergency halt fires before any agent work.
        $this->mock(\App\Services\Agent\SignificanceGate::class, fn (MockInterface $m) => $m->shouldReceive('assess')->never());

        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create(['status' => \App\Enums\TicketStatus::InProgress]);
        $this->openEmergencyFor($ticket);

        (new \App\Jobs\RunTechnicianAgent($ticket->id))->handle();

        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count(),
            'the agent must take no action on a ticket under an open emergency');
    }
}
