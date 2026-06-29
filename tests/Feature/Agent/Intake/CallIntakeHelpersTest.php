<?php

namespace Tests\Feature\Agent\Intake;

use App\Enums\CallStatus;
use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketType;
use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Intake\IntakeDecision;
use App\Services\Agent\Intake\IntakeRecorder;
use App\Services\PhoneCallService;
use App\Services\Technician\Cockpit\CockpitQuery;
use App\Support\TriageConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Task 4 — the apply-side primitives the call-intake PIPELINE (T5) composes:
 *   1. PhoneCallService::linkCallToTicketWithNote — attach a call + drop a note
 *   2. PhoneCallService::createTicketFromCall      — mint a ticket from a resolved call
 *   3. IntakeRecorder::record                      — write the observational intake_route run
 *
 * None of these decide anything or run AI — they only apply. Dormancy / held-first
 * routing lives in the pipeline, not here.
 */
class CallIntakeHelpersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ticket creation fires TicketObserver::created which may dispatch triage /
        // technician jobs. Capture them so the apply-side helpers are tested in isolation.
        Bus::fake();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Create and persist a PhoneCall. client_id / person_id are FK columns not in
     * $fillable (set by the resolution pipeline), so we assign them directly.
     */
    private function makeCall(array $attrs = []): PhoneCall
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'from_number' => $attrs['from_number'] ?? '+15550100001',
            'status' => $attrs['status'] ?? CallStatus::Completed,
            'caller_identified_name' => $attrs['caller_identified_name'] ?? null,
            'call_summary' => $attrs['call_summary'] ?? null,
            'cleaned_transcript' => $attrs['cleaned_transcript'] ?? null,
        ]);

        $call->client_id = $attrs['client_id'] ?? null;
        $call->person_id = $attrs['person_id'] ?? null;
        $call->save();

        return $call;
    }

    private function makePerson(Client $client, string $firstName, string $lastName): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_active' => true,
        ]);
    }

    private function service(): PhoneCallService
    {
        return app(PhoneCallService::class);
    }

    // ── 1. linkCallToTicketWithNote ──────────────────────────────────────────

    /**
     * With a system user configured, the call is linked AND exactly one private
     * NoteType::PhoneCall note is written on the ticket carrying the given body.
     */
    public function test_link_call_to_ticket_with_note_links_and_adds_one_private_phone_call_note(): void
    {
        $user = User::factory()->create();   // becomes the fallback system user
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $call = $this->makeCall(['client_id' => $client->id]);

        $result = $this->service()->linkCallToTicketWithNote($call, $ticket->id, 'Linked from inbound call.');

        // Linked (and the link reused linkCallToTicket — ticket_id is persisted)
        $this->assertSame($ticket->id, $result->ticket_id);
        $this->assertSame($ticket->id, $call->fresh()->ticket_id);

        // Exactly one PhoneCall note, private, with the supplied body, authored by the system user
        $notes = $ticket->notes()->where('note_type', NoteType::PhoneCall->value)->get();
        $this->assertCount(1, $notes);
        $this->assertSame('Linked from inbound call.', $notes->first()->body);
        $this->assertTrue((bool) $notes->first()->is_private);
        $this->assertSame($user->id, $notes->first()->author_id);
    }

    /**
     * Fail-soft: with NO system user (no users, no triage_system_user_id setting →
     * TriageConfig::systemUserId() is null) the call is STILL linked, no note is
     * written, and nothing throws — a missing author never undoes the link.
     */
    public function test_link_call_to_ticket_with_note_skips_note_when_no_system_user(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $call = $this->makeCall(['client_id' => $client->id]);

        // Precondition for this test: no system user resolvable.
        $this->assertNull(TriageConfig::systemUserId());

        $result = $this->service()->linkCallToTicketWithNote($call, $ticket->id, 'No author available.');

        // Link still succeeded
        $this->assertSame($ticket->id, $result->ticket_id);
        $this->assertSame($ticket->id, $call->fresh()->ticket_id);

        // No note written
        $this->assertSame(0, $ticket->notes()->where('note_type', NoteType::PhoneCall->value)->count());
    }

    // ── 2. createTicketFromCall ──────────────────────────────────────────────

    /**
     * A resolved call (client + contact set, call_summary present) mints a ticket:
     * source=Phone, type=ServiceRequest, P3, client/contact carried from the call,
     * description=call_summary, "Phone call from {caller}" subject. The call is
     * linked to the new ticket and a PhoneCall note exists.
     */
    public function test_create_ticket_from_call_mints_a_phone_service_request_and_links_the_call(): void
    {
        User::factory()->create(); // system user so the linked note is written
        $client = Client::factory()->create(['name' => 'Acme Corp']);
        $person = $this->makePerson($client, 'Diana', 'Earl');
        $call = $this->makeCall([
            'client_id' => $client->id,
            'person_id' => $person->id,
            'caller_identified_name' => 'Diana Earl',
            'call_summary' => 'Customer reports the office printer is offline.',
        ]);

        $ticket = $this->service()->createTicketFromCall($call);

        // Ticket shape mirrors autoCreateTicketFromEmail (Phone channel)
        $this->assertSame(TicketSource::Phone->value, $ticket->source->value);
        $this->assertSame(TicketType::ServiceRequest->value, $ticket->type->value);
        $this->assertSame(TicketPriority::P3->value, $ticket->priority->value);
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame($person->id, $ticket->contact_id);
        $this->assertSame('Customer reports the office printer is offline.', $ticket->description);
        $this->assertSame('Phone call from Diana Earl', $ticket->subject);

        // The call is linked to the new ticket and carries a PhoneCall note
        $this->assertSame($ticket->id, $call->fresh()->ticket_id);
        $this->assertSame(1, $ticket->notes()->where('note_type', NoteType::PhoneCall->value)->count());
    }

    /**
     * Subject / description fall back through their cascades when the richer fields
     * are absent. With no identified name and no resolved person, the caller cascade
     * lands on the client name (the precondition guarantees a resolved client); with
     * no summary or transcript, the description lands on the literal fallback.
     */
    public function test_create_ticket_from_call_falls_back_for_caller_and_description(): void
    {
        User::factory()->create();
        $client = Client::factory()->create(['name' => 'Fallback Co']);
        $call = $this->makeCall([
            'client_id' => $client->id,
            'from_number' => '+15557654321',
            // no caller_identified_name, no person, no call_summary, no cleaned_transcript
        ]);

        $ticket = $this->service()->createTicketFromCall($call);

        // caller cascade lands on client->name; description cascade lands on the literal fallback
        $this->assertSame('Phone call from Fallback Co', $ticket->subject);
        $this->assertSame('Inbound phone call — see linked call for transcript.', $ticket->description);
        $this->assertNull($ticket->contact_id);
        $this->assertSame($ticket->id, $call->fresh()->ticket_id);
    }

    // ── 3. IntakeRecorder::record ────────────────────────────────────────────

    /**
     * An attach (attachedTicketId set) writes a Done intake_route run, ticket_id =
     * the attached ticket, attached=true, content_hash keyed on the content key,
     * and the channel-specific meta (call_id, source) merged into proposed_meta.
     */
    public function test_recorder_attach_writes_done_run_with_merged_meta(): void
    {
        $client = Client::factory()->create();
        $existing = Ticket::factory()->create(['client_id' => $client->id]);
        $call = $this->makeCall(['client_id' => $client->id]);

        $decision = new IntakeDecision('attach', $existing->id, 0.91, 'same printer issue');

        $run = app(IntakeRecorder::class)->record(
            clientId: $client->id,
            contentKey: 'call:'.$call->id,
            decision: $decision,
            attachedTicketId: $existing->id,
            createdTicketId: null,
            meta: ['call_id' => $call->id, 'source' => 'call'],
        );

        $this->assertSame('intake_route', $run->action_type);
        $this->assertSame(TechnicianRunState::Done, $run->state);
        $this->assertSame($existing->id, $run->ticket_id);
        $this->assertSame($client->id, $run->client_id);
        $this->assertSame(hash('sha256', 'intake:call:'.$call->id), $run->content_hash);
        $this->assertSame('same printer issue', $run->proposed_content);
        $this->assertSame(0, $run->tokens_used);

        $meta = $run->proposed_meta;
        $this->assertTrue($meta['attached']);
        $this->assertSame($existing->id, $meta['suggested_ticket_id']);
        $this->assertNull($meta['created_ticket_id']);
        $this->assertSame('attach', $meta['decision']);
        $this->assertSame(0.91, $meta['confidence']);
        // channel-specific meta merged in
        $this->assertSame($call->id, $meta['call_id']);
        $this->assertSame('call', $meta['source']);
    }

    /**
     * A held suggestion (createdTicketId set, attached null) writes an
     * AwaitingApproval intake_route run (ticket_id = the created ticket) that the
     * cockpit Intake lane surfaces via CockpitQuery::intakeReview().
     */
    public function test_recorder_held_writes_awaiting_approval_run_surfaced_in_cockpit_intake_lane(): void
    {
        $client = Client::factory()->create();
        $created = Ticket::factory()->create(['client_id' => $client->id]);
        $call = $this->makeCall(['client_id' => $client->id]);

        $decision = new IntakeDecision('attach', 4242, 0.88, 'looks like a duplicate');

        $run = app(IntakeRecorder::class)->record(
            clientId: $client->id,
            contentKey: 'call:'.$call->id,
            decision: $decision,
            attachedTicketId: null,
            createdTicketId: $created->id,
            meta: ['call_id' => $call->id, 'source' => 'call'],
        );

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame($created->id, $run->ticket_id);
        $this->assertSame(hash('sha256', 'intake:call:'.$call->id), $run->content_hash);

        $meta = $run->proposed_meta;
        $this->assertFalse($meta['attached']);
        $this->assertSame(4242, $meta['suggested_ticket_id']);
        $this->assertSame($created->id, $meta['created_ticket_id']);
        $this->assertSame($call->id, $meta['call_id']);
        $this->assertSame('call', $meta['source']);

        // The held run surfaces in the cockpit Intake lane
        $lane = app(CockpitQuery::class)->intakeReview();
        $this->assertTrue($lane->contains(fn (TechnicianRun $r) => $r->id === $run->id),
            'The held intake_route run must surface in CockpitQuery::intakeReview()');
    }
}
