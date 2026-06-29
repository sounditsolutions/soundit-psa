<?php

namespace Tests\Feature\Email;

use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Email;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Agent\Intake\IntakeDecision;
use App\Services\Agent\Intake\IntakeRouter;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Tests for EmailService::routeInboundEmail — the intake front-door hook that
 * wires IntakeRouter into the "create" branch of processInbound.
 *
 * Behaviour matrix (from the spec):
 * - DORMANT (intake_enabled off): autoCreateTicketFromEmail, router never called  ← Test 1
 * - enabled + create decision: autoCreate, NO intake_route record                 ← Test 2
 * - enabled + confident attach (threshold set): auto-attach, no new ticket, Done  ← Test 3
 * - enabled + held attach (threshold null): create + AwaitingApproval record      ← Test 4
 * - enabled + attach but ticket closed: falls through to safe create              ← Test 5
 * - router throws: fail-soft → autoCreate, no exception escapes                  ← Test 6
 */
class IntakeRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Neutralise all queued jobs (triage on ticket create, email notifications).
        // linkEmailToTicket / processInbound run synchronously; jobs are captured only.
        Bus::fake();

        // The create branch only fires when email_auto_ticket is on.
        Setting::setValue('email_auto_ticket', '1');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Create an inbound Email that will reach the "create" branch in processInbound:
     *  - direction = inbound, ticket_id = null
     *  - client_id set (not unknown sender → spam filter skipped)
     *  - received_at = now() (< 24h guard passes)
     *  - clean subject (no [T-xxx] / [ID:x] / [#x]), no conversation_id, no in_reply_to
     *    → matchToExistingTicket returns null (no thread match)
     */
    private function makeInboundEmail(Client $client): Email
    {
        return Email::create([
            'direction' => 'inbound',
            'from_address' => 'user@clientdomain.com',
            'from_name' => 'Test User',
            'subject' => 'My computer is slow today',
            'body_text' => 'It has been running slowly all morning.',
            'received_at' => now(),
            'client_id' => $client->id,
        ]);
    }

    /** Persist an open ticket for the given client. */
    private function openTicket(Client $client, string $subject = 'Printer offline'): Ticket
    {
        return Ticket::factory()->create([
            'client_id' => $client->id,
            'subject' => $subject,
            'status' => TicketStatus::New,
        ]);
    }

    // ── Test 1: DISABLED (default) → autoCreate; router NEVER called ─────────

    /**
     * When intake_enabled is off (the default), processInbound must call
     * autoCreateTicketFromEmail exactly as before — IntakeRouter must not be
     * invoked at all (DORMANT path is byte-identical to pre-feature behaviour).
     */
    public function test_disabled_auto_creates_ticket_and_router_is_never_called(): void
    {
        // intake_enabled NOT set → AgentConfig::intakeEnabled() returns false
        $client = Client::factory()->create();
        $email = $this->makeInboundEmail($client);
        $before = Ticket::count();

        // Strict: any call to route() is a failure
        $this->mock(IntakeRouter::class)->shouldReceive('route')->never();

        app(EmailService::class)->processInbound($email);

        $this->assertSame($before + 1, Ticket::count(), 'A new ticket should be created');
        $this->assertNotNull($email->fresh()->ticket_id, 'Email should be linked to the new ticket');
    }

    // ── Test 2: enabled + create decision → autoCreate, no TechnicianRun ─────

    /**
     * When intake is enabled and the router returns a "create" decision, a new
     * ticket is created and NO intake_route TechnicianRun record is written
     * (create decisions are not calibration-worthy — only attach suggestions are).
     */
    public function test_enabled_create_decision_auto_creates_ticket_with_no_record(): void
    {
        Setting::setValue('intake_enabled', '1');

        $client = Client::factory()->create();
        $email = $this->makeInboundEmail($client);
        $before = Ticket::count();

        $this->mock(IntakeRouter::class)
            ->shouldReceive('route')
            ->once()
            ->andReturn(IntakeDecision::create('new issue — not a duplicate', 0.85));

        app(EmailService::class)->processInbound($email);

        $this->assertSame($before + 1, Ticket::count(), 'A new ticket should be created');
        $this->assertNotNull($email->fresh()->ticket_id);
        $this->assertSame(
            0,
            TechnicianRun::where('action_type', 'intake_route')->count(),
            'No intake_route record should be written for a create decision',
        );
    }

    // ── Test 3: enabled + confident attach (threshold set) → auto-attach ─────

    /**
     * When intake is enabled, the router returns an attach decision with high
     * confidence (>= threshold), and the candidate ticket is still open and
     * belongs to the same client, the email is auto-attached to the EXISTING
     * ticket — no new ticket is created — and a Done intake_route TechnicianRun
     * is written.
     */
    public function test_enabled_confident_attach_auto_attaches_and_writes_done_record(): void
    {
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_attach_auto_threshold', '0.8');

        $client = Client::factory()->create();
        $existing = $this->openTicket($client, 'Printer offline');
        $email = $this->makeInboundEmail($client);
        $ticketsBefore = Ticket::count();

        $this->mock(IntakeRouter::class)
            ->shouldReceive('route')
            ->once()
            ->andReturn(new IntakeDecision('attach', $existing->id, 0.9, 'same printer issue'));

        app(EmailService::class)->processInbound($email);

        // Email was auto-attached to the EXISTING ticket (not a new one)
        $this->assertSame($existing->id, $email->fresh()->ticket_id,
            'Email must be linked to the existing ticket');
        // No new ticket was minted
        $this->assertSame($ticketsBefore, Ticket::count(),
            'No new ticket should be created on a confident auto-attach');

        // A Done intake_route record must exist
        $run = TechnicianRun::where('action_type', 'intake_route')->first();
        $this->assertNotNull($run, 'A Done intake_route TechnicianRun must be written');
        $this->assertSame(TechnicianRunState::Done, $run->state);
        $this->assertSame($existing->id, $run->ticket_id);

        $meta = $run->proposed_meta;
        $this->assertTrue($meta['attached'], 'attached flag must be true for auto-attach');
        $this->assertSame($existing->id, $meta['suggested_ticket_id']);
        $this->assertNull($meta['created_ticket_id']);
        $this->assertSame($email->id, $meta['email_id']);
    }

    // ── Test 4: enabled + held attach (threshold null) → create + AwaitingApproval ──

    /**
     * When intake is enabled but the auto-threshold is NOT set (null = held-first,
     * the safe default), a high-confidence attach decision does NOT auto-attach.
     * A new ticket is created (safe duplicate path) AND an AwaitingApproval
     * intake_route TechnicianRun is written as an observational suggestion.
     */
    public function test_enabled_held_attach_creates_ticket_and_writes_awaiting_approval_record(): void
    {
        Setting::setValue('intake_enabled', '1');
        // intake_attach_auto_threshold deliberately NOT set → null → held-first

        $client = Client::factory()->create();
        $existing = $this->openTicket($client, 'Printer offline');
        $email = $this->makeInboundEmail($client);
        $ticketsBefore = Ticket::count();

        $this->mock(IntakeRouter::class)
            ->shouldReceive('route')
            ->once()
            ->andReturn(new IntakeDecision('attach', $existing->id, 0.9, 'same printer issue'));

        app(EmailService::class)->processInbound($email);

        // A NEW ticket was created (held-first does not auto-attach)
        $this->assertSame($ticketsBefore + 1, Ticket::count(),
            'A new ticket must be created in held-first mode');
        $freshEmail = $email->fresh();
        $this->assertNotNull($freshEmail->ticket_id);
        $this->assertNotSame($existing->id, $freshEmail->ticket_id,
            'The email must NOT be attached to the existing ticket in held mode');

        // An AwaitingApproval intake_route record must exist
        $run = TechnicianRun::where('action_type', 'intake_route')->first();
        $this->assertNotNull($run, 'An AwaitingApproval intake_route TechnicianRun must be written');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        $meta = $run->proposed_meta;
        $this->assertFalse($meta['attached'], 'attached must be false for held suggestions');
        $this->assertSame($existing->id, $meta['suggested_ticket_id'],
            'suggested_ticket_id must be the router\'s matched ticket');
        $this->assertSame($freshEmail->ticket_id, $meta['created_ticket_id'],
            'created_ticket_id must be the newly created ticket');
        $this->assertSame($email->id, $meta['email_id']);
    }

    // ── Test 5: stale candidate (closed before processInbound) → safe create ──

    /**
     * When the router returns an attach decision but the candidate ticket was
     * closed (or belongs to a different client) by the time processInbound runs,
     * the re-validation fails and the code falls through to autoCreateTicketFromEmail.
     */
    public function test_stale_closed_candidate_falls_through_to_safe_create(): void
    {
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_attach_auto_threshold', '0.8');

        $client = Client::factory()->create();
        $existing = $this->openTicket($client, 'Printer offline');
        $email = $this->makeInboundEmail($client);
        $ticketsBefore = Ticket::count();

        // Close the ticket BEFORE processInbound is called (simulates race condition)
        $existing->update(['status' => TicketStatus::Closed->value, 'closed_at' => now()]);

        $this->mock(IntakeRouter::class)
            ->shouldReceive('route')
            ->once()
            ->andReturn(new IntakeDecision('attach', $existing->id, 0.95, 'same issue'));

        app(EmailService::class)->processInbound($email);

        // A new ticket was created (the closed candidate is never auto-attached)
        $this->assertSame($ticketsBefore + 1, Ticket::count(),
            'A new ticket must be created when the candidate is closed');
        $freshEmail = $email->fresh();
        $this->assertNotNull($freshEmail->ticket_id);
        $this->assertNotSame($existing->id, $freshEmail->ticket_id,
            'The email must NOT be attached to the closed ticket');
    }

    // ── Test 7: idempotency — second processInbound call is a safe no-op ────────

    /**
     * When processInbound is called twice on the same email (e.g. a poll race or
     * retry), the `ticket_id !== null` guard at the top of processInbound returns
     * early on the second call. Exactly ONE ticket and ONE intake_route record must
     * exist — no second ticket, no second run, no unique-constraint violation.
     */
    public function test_process_inbound_idempotent_on_held_attach(): void
    {
        Setting::setValue('intake_enabled', '1');
        // intake_attach_auto_threshold deliberately NOT set → null → held-first

        $client = Client::factory()->create();
        $existing = $this->openTicket($client, 'Printer offline');
        $email = $this->makeInboundEmail($client);
        $ticketsBefore = Ticket::count();

        // Router must only be called ONCE — on the first processInbound.
        // The second call returns early before the router is reached.
        $this->mock(IntakeRouter::class)
            ->shouldReceive('route')
            ->once()
            ->andReturn(new IntakeDecision('attach', $existing->id, 0.9, 'same printer issue'));

        app(EmailService::class)->processInbound($email);
        app(EmailService::class)->processInbound($email); // second call — must be a no-op

        $this->assertSame($ticketsBefore + 1, Ticket::count(),
            'Only one ticket should be created — second call is a no-op');
        $this->assertSame(
            1,
            TechnicianRun::where('action_type', 'intake_route')->count(),
            'Only one intake_route record should exist',
        );
    }

    // ── Test 6: fail-soft → autoCreate; no exception escapes ─────────────────

    /**
     * If IntakeRouter::route() throws for any reason (AI down, network error, etc.)
     * the catch block must create the ticket as normal and must not let any exception
     * escape processInbound (fail-soft, never lose an email).
     */
    public function test_fail_soft_creates_ticket_when_router_throws(): void
    {
        Setting::setValue('intake_enabled', '1');

        $client = Client::factory()->create();
        $email = $this->makeInboundEmail($client);
        $before = Ticket::count();

        $this->mock(IntakeRouter::class)
            ->shouldReceive('route')
            ->once()
            ->andThrow(new \RuntimeException('AI service unavailable'));

        // Must NOT throw — fail-soft absorbs the exception
        app(EmailService::class)->processInbound($email);

        $this->assertSame($before + 1, Ticket::count(),
            'A new ticket must still be created even when the router throws');
        $this->assertNotNull($email->fresh()->ticket_id,
            'The email must be linked to the new ticket after fail-soft recovery');
    }

    // ── Test 8: no double-create when autoCreateTicketFromEmail throws ─────────

    /**
     * Double-create guard: in the OLD code autoCreateTicketFromEmail ran INSIDE the
     * try block, and the catch also re-called it when ticket_id was null — so a
     * partial-persist failure (ticket row written, email not yet linked) would
     * produce a second orphan ticket.
     *
     * The restructured routeInboundEmail moves the single create call OUTSIDE the try,
     * so a throw from autoCreate propagates immediately with no catch-block retry.
     *
     * We simulate a partial-persist by mocking TicketService::createTicket to throw
     * (the ticket "was written" but an exception fires before email.ticket_id is set).
     * The mock's counter proves createTicket is called EXACTLY ONCE regardless of the
     * exception — the old code would have called it twice.
     */
    public function test_no_double_create_when_auto_create_throws_after_partial_persist(): void
    {
        Setting::setValue('intake_enabled', '1');

        $client = Client::factory()->create();
        $email = $this->makeInboundEmail($client);

        // Router succeeds (returns 'create') — the exception comes from autoCreate, not the router.
        $this->mock(IntakeRouter::class)
            ->shouldReceive('route')
            ->once()
            ->andReturn(IntakeDecision::create('brand-new issue', 0.9));

        // Count createTicket calls via andReturnUsing closure (avoids Mockery constraint edge-cases).
        // Throw on every call to simulate a partial-persist (ticket "exists" in DB, email not linked).
        $callCount = 0;
        $this->mock(\App\Services\TicketService::class)
            ->shouldReceive('createTicket')
            ->andReturnUsing(function () use (&$callCount): never {
                $callCount++;
                throw new \RuntimeException('Simulated DB error after partial persist');
            });

        // In the new code: autoCreate is outside the try — its exception propagates from
        // routeInboundEmail → processInbound. Catch it here so the test doesn't error.
        // In the OLD code: the catch block would re-call autoCreate (callCount would reach 2).
        try {
            app(EmailService::class)->processInbound($email);
        } catch (\Throwable) {
            // Expected: the single create attempt threw. No double-create happened.
        }

        $this->assertSame(1, $callCount,
            'createTicket must be called exactly once — the catch path must not retry');
        $this->assertNull($email->fresh()->ticket_id,
            'email.ticket_id stays null — create threw before linking');
    }
}
