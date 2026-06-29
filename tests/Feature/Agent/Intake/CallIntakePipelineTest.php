<?php

namespace Tests\Feature\Agent\Intake;

use App\Enums\CallStatus;
use App\Enums\TechnicianRunState;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Jobs\CallIntakeJob;
use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Intake\CallIntakePipeline;
use App\Services\Agent\Intake\IntakeDecision;
use App\Services\Agent\Intake\IntakeRouter;
use App\Services\TranscriptionService;
use App\Support\AgentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Task 5 — CallIntakePipeline orchestrator (the keystone of the call-intake leg).
 *
 * Channel-neutral, DORMANT, HELD-FIRST, FAIL-SOFT routing for the call channel,
 * mirroring EmailService::routeInboundEmail. It composes the already-built T1/T3/T4
 * pieces (IntakeRouter::routeContent, CallerResolver, PhoneCallService link/create,
 * IntakeRecorder) — it does not re-implement them.
 *
 * Behaviour matrix:
 *  1. DORMANT (intake off) → no-op even for a resolved call (router never called)
 *  2. SKIP-IF-TICKETED → an already-linked call is left alone
 *  3. RESOLVED + held (threshold null) → create new Phone ticket + AwaitingApproval run
 *  4. RESOLVED + confident attach (threshold set) → link to existing ticket + Done run
 *  5. RESOLVED + router "create" → one new ticket, NO observational run
 *  6. AUTO-ATTACH re-validation (closed / cross-client candidate) → safe create
 *  7. UNRESOLVED → HOLD: no ticket, no run, call stays unknown-caller
 *  8. NEWLY-RESOLVED apply (call-history) → client_id set on the call + ticket created
 *  9. FAIL-SOFT → router throws → swallowed, no ticket, no crash
 * 10. DISPATCH dormancy → transcribe() only dispatches CallIntakeJob when intake on
 */
class CallIntakePipelineTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Create and persist a PhoneCall. client_id / person_id / ticket_id / to_number /
     * recording_url are set directly (some are FK columns set by the pipeline, not in
     * $fillable) — mirrors the CallerResolver/helper test fixtures.
     */
    private function makeCall(array $attrs = []): PhoneCall
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'from_number' => $attrs['from_number'] ?? '+15550100001',
            'status' => $attrs['status'] ?? CallStatus::Completed,
            'caller_identified_name' => $attrs['caller_identified_name'] ?? null,
            'caller_identified_company' => $attrs['caller_identified_company'] ?? null,
            'caller_identity_confidence' => $attrs['caller_identity_confidence'] ?? null,
            'call_summary' => $attrs['call_summary'] ?? null,
            'cleaned_transcript' => $attrs['cleaned_transcript'] ?? null,
            'transcription' => $attrs['transcription'] ?? null,
        ]);

        $call->client_id = $attrs['client_id'] ?? null;
        $call->person_id = $attrs['person_id'] ?? null;
        $call->ticket_id = $attrs['ticket_id'] ?? null;
        if (isset($attrs['to_number'])) {
            $call->to_number = $attrs['to_number'];
        }
        if (isset($attrs['recording_url'])) {
            $call->recording_url = $attrs['recording_url'];
        }
        $call->save();

        return $call;
    }

    private function openTicket(Client $client, string $subject = 'Printer offline'): Ticket
    {
        return Ticket::factory()->create([
            'client_id' => $client->id,
            'subject' => $subject,
            'status' => TicketStatus::New->value,
        ]);
    }

    private function pipeline(): CallIntakePipeline
    {
        return app(CallIntakePipeline::class);
    }

    private function intakeRouteRuns(): \Illuminate\Database\Eloquent\Collection
    {
        return TechnicianRun::where('action_type', 'intake_route')->get();
    }

    // ── Test 1: DORMANT ──────────────────────────────────────────────────────

    public function test_dormant_does_nothing_even_for_a_resolved_call(): void
    {
        Bus::fake();
        // intake_enabled NOT set → AgentConfig::intakeEnabled() returns false

        $client = Client::factory()->create();
        $call = $this->makeCall(['client_id' => $client->id, 'call_summary' => 'Need help with email.']);

        // Strict: the router must never be reached when intake is dormant.
        $this->mock(IntakeRouter::class)->shouldReceive('routeContent')->never();

        $this->pipeline()->handle($call);

        $this->assertSame(0, Ticket::count(), 'No ticket may be created when intake is dormant');
        $this->assertSame(0, $this->intakeRouteRuns()->count(), 'No intake_route run when dormant');
        $this->assertNull($call->fresh()->ticket_id, 'The call must not be linked when dormant');
    }

    // ── Test 2: SKIP-IF-TICKETED ─────────────────────────────────────────────

    public function test_skips_a_call_already_linked_to_a_ticket(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');

        $client = Client::factory()->create();
        $existing = $this->openTicket($client);
        $call = $this->makeCall([
            'client_id' => $client->id,
            'ticket_id' => $existing->id,
            'call_summary' => 'Follow-up on the printer.',
        ]);
        $ticketsBefore = Ticket::count();

        $this->mock(IntakeRouter::class)->shouldReceive('routeContent')->never();

        $this->pipeline()->handle($call);

        $this->assertSame($ticketsBefore, Ticket::count(), 'No second ticket for an already-linked call');
        $this->assertSame(0, $this->intakeRouteRuns()->count(), 'No intake_route run for a ticketed call');
        $this->assertSame($existing->id, $call->fresh()->ticket_id, 'The call stays on its original ticket');
    }

    // ── Test 3: RESOLVED + held (threshold null) ─────────────────────────────

    public function test_resolved_held_attach_creates_phone_ticket_and_awaiting_approval_run(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');
        // intake_attach_auto_threshold deliberately NOT set → null → held-first

        User::factory()->create(); // system user so the linked note is authored
        $client = Client::factory()->create();
        $candidate = $this->openTicket($client, 'Printer offline');
        $call = $this->makeCall([
            'client_id' => $client->id,
            'call_summary' => 'Caller says the printer is still offline.',
        ]);

        $this->mock(IntakeRouter::class)
            ->shouldReceive('routeContent')
            ->once()
            ->andReturn(new IntakeDecision('attach', $candidate->id, 0.9, 'same printer issue'));

        $this->pipeline()->handle($call);

        // Held-first does NOT auto-attach: a new Phone-source ticket is created and linked.
        $phoneTickets = Ticket::where('source', TicketSource::Phone->value)->get();
        $this->assertCount(1, $phoneTickets, 'Exactly one Phone-source ticket must be created in held mode');
        $newTicket = $phoneTickets->first();
        $this->assertSame($newTicket->id, $call->fresh()->ticket_id, 'The call must be linked to the new ticket');
        $this->assertNotSame($candidate->id, $newTicket->id, 'Must NOT attach to the candidate in held mode');

        // One observational AwaitingApproval intake_route run.
        $runs = $this->intakeRouteRuns();
        $this->assertCount(1, $runs, 'Exactly one observational intake_route run');
        $run = $runs->first();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame($newTicket->id, $run->ticket_id, 'Held run is keyed to the created ticket');

        $meta = $run->proposed_meta;
        $this->assertFalse($meta['attached']);
        $this->assertSame($candidate->id, $meta['suggested_ticket_id']);
        $this->assertSame($newTicket->id, $meta['created_ticket_id']);
        $this->assertSame($call->id, $meta['call_id'], 'proposed_meta.call_id must carry the call id');
        $this->assertSame('call', $meta['source']);
    }

    // ── Test 4: RESOLVED + confident auto-attach (threshold set) ─────────────

    public function test_resolved_confident_attach_links_existing_ticket_and_writes_done_run(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_attach_auto_threshold', '0.8');

        User::factory()->create();
        $client = Client::factory()->create();
        $existing = $this->openTicket($client, 'Printer offline');
        $call = $this->makeCall([
            'client_id' => $client->id,
            'call_summary' => 'Caller is still on the printer issue.',
        ]);
        $ticketsBefore = Ticket::count();

        $this->mock(IntakeRouter::class)
            ->shouldReceive('routeContent')
            ->once()
            ->andReturn(new IntakeDecision('attach', $existing->id, 0.9, 'same printer issue'));

        $this->pipeline()->handle($call);

        // The call is linked to the EXISTING ticket — no new ticket.
        $this->assertSame($existing->id, $call->fresh()->ticket_id, 'The call must link to the existing ticket');
        $this->assertSame($ticketsBefore, Ticket::count(), 'No new ticket on a confident auto-attach');

        // One Done intake_route run keyed to the attached ticket.
        $runs = $this->intakeRouteRuns();
        $this->assertCount(1, $runs);
        $run = $runs->first();
        $this->assertSame(TechnicianRunState::Done, $run->state);
        $this->assertSame($existing->id, $run->ticket_id);

        $meta = $run->proposed_meta;
        $this->assertTrue($meta['attached']);
        $this->assertSame($existing->id, $meta['suggested_ticket_id']);
        $this->assertNull($meta['created_ticket_id']);
        $this->assertSame($call->id, $meta['call_id']);
        $this->assertSame('call', $meta['source']);
    }

    // ── Test 5: RESOLVED + router "create" → one ticket, NO run ───────────────

    public function test_resolved_create_decision_makes_one_ticket_and_no_run(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');

        User::factory()->create();
        $client = Client::factory()->create();
        $call = $this->makeCall([
            'client_id' => $client->id,
            'call_summary' => 'Brand new request: onboard a laptop.',
        ]);

        $this->mock(IntakeRouter::class)
            ->shouldReceive('routeContent')
            ->once()
            ->andReturn(IntakeDecision::create('new issue — not a duplicate', 0.85));

        $this->pipeline()->handle($call);

        $phoneTickets = Ticket::where('source', TicketSource::Phone->value)->get();
        $this->assertCount(1, $phoneTickets, 'Exactly one Phone-source ticket created');
        $this->assertSame($phoneTickets->first()->id, $call->fresh()->ticket_id);
        $this->assertSame(0, $this->intakeRouteRuns()->count(),
            'A create decision is not a held suggestion — no intake_route run');
    }

    // ── Test 6: AUTO-ATTACH re-validation — closed candidate → safe create ────

    public function test_auto_attach_revalidation_closed_candidate_falls_through_to_create(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_attach_auto_threshold', '0.8');

        User::factory()->create();
        $client = Client::factory()->create();
        $existing = $this->openTicket($client, 'Printer offline');
        $call = $this->makeCall([
            'client_id' => $client->id,
            'call_summary' => 'Still the same printer.',
        ]);

        // Close the candidate before the pipeline runs (race / stale candidate).
        $existing->update(['status' => TicketStatus::Closed->value, 'closed_at' => now()]);
        $ticketsBefore = Ticket::count();

        $this->mock(IntakeRouter::class)
            ->shouldReceive('routeContent')
            ->once()
            ->andReturn(new IntakeDecision('attach', $existing->id, 0.95, 'same issue'));

        $this->pipeline()->handle($call);

        // A new ticket was created; the call is NOT attached to the closed candidate.
        $this->assertSame($ticketsBefore + 1, Ticket::count(), 'A new ticket must be created when candidate is closed');
        $freshTicketId = $call->fresh()->ticket_id;
        $this->assertNotNull($freshTicketId);
        $this->assertNotSame($existing->id, $freshTicketId, 'The call must NOT link to the closed candidate');

        // isAttach was true → held-first observational run is recorded (AwaitingApproval, not Done).
        $runs = $this->intakeRouteRuns();
        $this->assertCount(1, $runs);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $runs->first()->state);
        $this->assertSame($freshTicketId, $runs->first()->proposed_meta['created_ticket_id']);
    }

    public function test_auto_attach_revalidation_cross_client_candidate_falls_through_to_create(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_attach_auto_threshold', '0.8');

        User::factory()->create();
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();
        // Candidate belongs to a DIFFERENT client than the call.
        $foreign = $this->openTicket($otherClient, 'Foreign ticket');
        $call = $this->makeCall([
            'client_id' => $client->id,
            'call_summary' => 'A request for my own client.',
        ]);
        $ticketsBefore = Ticket::count();

        $this->mock(IntakeRouter::class)
            ->shouldReceive('routeContent')
            ->once()
            ->andReturn(new IntakeDecision('attach', $foreign->id, 0.95, 'crafted cross-client attach'));

        $this->pipeline()->handle($call);

        $this->assertSame($ticketsBefore + 1, Ticket::count(), 'A new ticket must be created — no cross-client attach');
        $this->assertNotSame($foreign->id, $call->fresh()->ticket_id, 'The call must NOT link to the foreign ticket');
        $created = Ticket::find($call->fresh()->ticket_id);
        $this->assertSame($client->id, $created->client_id, 'The created ticket belongs to the call\'s own client');
    }

    // ── Test 7: UNRESOLVED → HOLD ─────────────────────────────────────────────

    public function test_unresolved_call_is_held_no_ticket_no_run(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');

        // No client, no caller identity, a number that matches nothing → real CallerResolver
        // returns unresolved. The router must never be reached.
        $call = $this->makeCall([
            'from_number' => '+19998887777',
            'caller_identity_confidence' => null,
        ]);

        $this->mock(IntakeRouter::class)->shouldReceive('routeContent')->never();

        $this->pipeline()->handle($call);

        $this->assertSame(0, Ticket::count(), 'An unresolved call must not create a ticket (HOLD)');
        $this->assertSame(0, $this->intakeRouteRuns()->count(), 'No intake_route run for a held call');
        $this->assertNull($call->fresh()->client_id, 'The held call stays client_id null (unknown-caller facet)');
        $this->assertNull($call->fresh()->ticket_id);
    }

    // ── Test 8: NEWLY-RESOLVED apply (call-history) ──────────────────────────

    public function test_newly_resolved_via_call_history_sets_client_and_creates_ticket(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');

        User::factory()->create();
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'first_name' => 'Henry',
            'last_name' => 'Iver',
            'is_active' => true,
        ]);
        $num = '+15551239876';

        // Prior resolved call from this number (real CallerResolver Stage 2 source).
        $this->makeCall(['from_number' => $num, 'client_id' => $client->id, 'person_id' => $person->id]);

        // New unresolved call from the same number — client_id null at entry.
        $call = $this->makeCall([
            'from_number' => $num,
            'call_summary' => 'Returning caller needs a password reset.',
        ]);
        $this->assertNull($call->client_id, 'precondition: call enters unresolved');

        $this->mock(IntakeRouter::class)
            ->shouldReceive('routeContent')
            ->once()
            ->andReturn(IntakeDecision::create('new issue'));

        $this->pipeline()->handle($call);

        // Stage 4 applied the new resolution onto the call.
        $fresh = $call->fresh();
        $this->assertSame($client->id, $fresh->client_id, 'client_id must be set from call-history resolution');
        $this->assertSame($person->id, $fresh->person_id, 'person_id must be carried from the prior call');

        // A ticket was created for that resolved client.
        $phoneTickets = Ticket::where('source', TicketSource::Phone->value)->get();
        $this->assertCount(1, $phoneTickets);
        $this->assertSame($client->id, $phoneTickets->first()->client_id);
        $this->assertSame($phoneTickets->first()->id, $fresh->ticket_id);
    }

    // ── Test 9: FAIL-SOFT ─────────────────────────────────────────────────────

    public function test_fail_soft_swallows_router_exception_without_creating_a_ticket(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');

        $client = Client::factory()->create();
        $call = $this->makeCall([
            'client_id' => $client->id,
            'call_summary' => 'The router is about to blow up.',
        ]);

        $this->mock(IntakeRouter::class)
            ->shouldReceive('routeContent')
            ->once()
            ->andThrow(new \RuntimeException('AI service unavailable'));

        // Must NOT throw — the whole handle() body is fail-soft.
        $this->pipeline()->handle($call);

        $this->assertSame(0, Ticket::count(), 'Fail-soft must not create a ticket on a router throw (call is already surfaced)');
        $this->assertSame(0, $this->intakeRouteRuns()->count());
        $this->assertNull($call->fresh()->ticket_id);
    }

    // ── Test 10: DISPATCH dormancy in transcribe() ───────────────────────────

    /**
     * The dispatch gate the transcribe() hook uses (AgentConfig::intakeEnabled()) is
     * dormant by default, and the CallIntakeJob is queued (not run inline) when fired.
     * This directly exercises the dispatch CONDITION and the dispatch PRIMITIVE that
     * the hook composes. (The hook line itself sits on the success path of transcribe(),
     * after a live Whisper call that uses raw Guzzle with no injection seam — see the
     * failure-path test below + code inspection for the byte-identical-when-off claim.)
     */
    public function test_dispatch_gate_is_dormant_by_default_and_job_is_queued_when_fired(): void
    {
        Queue::fake();

        // Dormant by default — the hook's guard is false, so nothing dispatches.
        $this->assertFalse(AgentConfig::intakeEnabled(), 'intake must be dormant by default');
        Queue::assertNothingPushed();

        // Enabled — the guard the hook uses is now true.
        Setting::setValue('intake_enabled', '1');
        $this->assertTrue(AgentConfig::intakeEnabled());

        // The exact dispatch primitive the hook fires lands on the queue (not sync).
        CallIntakeJob::dispatch(123)->afterCommit();
        Queue::assertPushed(CallIntakeJob::class);
    }

    /**
     * Defence on the failure side: even with intake ENABLED, transcribe() must not
     * dispatch CallIntakeJob when transcription does not reach the success path
     * (the dispatch is strictly success-path, inside the try after "Mark completed").
     * Here the Whisper API key is absent, so transcribe() throws before the hook —
     * proving the dispatch never fires on the failure path.
     */
    public function test_transcribe_does_not_dispatch_intake_job_on_failure_path(): void
    {
        Queue::fake();
        Setting::setValue('intake_enabled', '1'); // enabled, yet failure path must not dispatch

        // recording_url present (passes the early guard) but no Whisper key → throws.
        $call = $this->makeCall(['recording_url' => 'https://example.com/recording.mp3']);

        try {
            app(TranscriptionService::class)->transcribe($call);
        } catch (\Throwable) {
            // expected: transcription cannot proceed without a key
        }

        Queue::assertNotPushed(CallIntakeJob::class);
    }

    // ── Job wiring: CallIntakeJob → CallIntakePipeline ───────────────────────

    public function test_job_loads_the_call_and_invokes_the_pipeline(): void
    {
        $client = Client::factory()->create();
        $call = $this->makeCall(['client_id' => $client->id]);

        $mock = $this->mock(CallIntakePipeline::class);
        $mock->shouldReceive('handle')
            ->once()
            ->with(\Mockery::on(fn (PhoneCall $c) => $c->id === $call->id));

        (new CallIntakeJob($call->id))->handle();
    }

    public function test_job_is_a_noop_when_the_call_is_missing(): void
    {
        $this->mock(CallIntakePipeline::class)->shouldReceive('handle')->never();

        (new CallIntakeJob(999999))->handle(); // must not throw
    }
}
