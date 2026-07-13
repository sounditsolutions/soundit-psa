<?php

namespace Tests\Feature\Agent\Intake;

use App\Enums\CallStatus;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Jobs\CallIntakeJob;
use App\Models\Client;
use App\Models\Email;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Intake\CallIntakePipeline;
use App\Services\Agent\Intake\IntakeDecision;
use App\Services\Agent\Intake\IntakeRouter;
use App\Services\EmailService;
use App\Services\TranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * psa-28j4 §3.2 — the intake front door is gated PER CHANNEL.
 *
 * WHY THIS EXISTS: the call gate and the email gate used to read the SAME setting
 * (intake_enabled). Turning off PSA-native call→ticket creation (so the external
 * agent owns it and wins the race cleanly) therefore ALSO knee-capped inbound email
 * intake. This suite makes that coupling impossible to reintroduce.
 *
 * Two channels, two keys, one legacy fallback:
 *   - intake_call_enabled   → TranscriptionService dispatch + CallIntakePipeline
 *   - intake_email_enabled  → EmailService::routeInboundEmail
 *   - either key ABSENT     → inherits legacy intake_enabled (backward compatibility)
 *
 * NOTE ON WHAT EACH GATE ACTUALLY GATES (asserted below, not assumed):
 *   - CALL: the gate is a hard OFF switch. Closed ⇒ NO ticket is created from a call.
 *   - EMAIL: the gate governs the AI attach-vs-create ROUTER. Closed ⇒ the router is
 *     never consulted and EmailService falls back to autoCreateTicketFromEmail — an
 *     email still becomes a ticket (email ticketing itself is governed by the separate,
 *     older email_auto_ticket setting). So "email intake still works" means: the email
 *     is still routed to a ticket, and with the gate OPEN the router is still consulted.
 */
class IntakeChannelGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // EmailService only reaches routeInboundEmail when email_auto_ticket is on.
        Setting::setValue('email_auto_ticket', '1');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** A transcribed, already-client-resolved inbound call — ready for the pipeline. */
    private function makeResolvedCall(Client $client): PhoneCall
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'from_number' => '+15550100001',
            'status' => CallStatus::Completed,
            'call_summary' => 'Caller needs a new laptop onboarded.',
        ]);
        $call->client_id = $client->id;
        $call->save();

        return $call;
    }

    /**
     * An inbound Email that reaches the "create" branch of processInbound:
     * client known, recent, no thread match.
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

    private function callTickets(): \Illuminate\Database\Eloquent\Collection
    {
        return Ticket::where('source', TicketSource::Phone->value)->get();
    }

    /** The router said "create" — so an OPEN gate ends in a real new ticket. */
    private function expectRouterConsultedForCall(): void
    {
        $this->mock(IntakeRouter::class)
            ->shouldReceive('routeContent')
            ->once()
            ->andReturn(IntakeDecision::create('new issue — not a duplicate', 0.85));
    }

    private function expectRouterNeverConsultedForCall(): void
    {
        $this->mock(IntakeRouter::class)->shouldReceive('routeContent')->never();
    }

    private function expectRouterConsultedForEmail(): void
    {
        $this->mock(IntakeRouter::class)
            ->shouldReceive('route')
            ->once()
            ->andReturn(IntakeDecision::create('new issue — not a duplicate', 0.85));
    }

    private function expectRouterNeverConsultedForEmail(): void
    {
        $this->mock(IntakeRouter::class)->shouldReceive('route')->never();
    }

    /**
     * Drive the REAL dispatch gate in TranscriptionService — the success tail of
     * transcribe(), which was extracted specifically as a test seam (the Whisper
     * call above it uses raw Guzzle with no injection point).
     */
    private function finalizeTranscription(PhoneCall $call): void
    {
        $svc = app(TranscriptionService::class);
        $m = new \ReflectionMethod($svc, 'finalizeSuccessfulTranscription');
        $m->setAccessible(true);
        $m->invoke($svc, $call);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HEADLINE — CALL OFF / EMAIL ON, on a LIVE deployment.
    //
    // These are anchored at intake_enabled=1: the state Charlie's production box is
    // actually in. That anchor is load-bearing. Without it ("no keys at all"), the
    // "no ticket" assertion would pass trivially — intake is dormant by default — and
    // the test would pass whether or not the gate worked. With the legacy master ON,
    // the call gate is genuinely open today, so these fail until the split lands.
    // ═══════════════════════════════════════════════════════════════════════

    /** Call intake closed ⇒ a transcribed, fully-resolved call creates NO ticket. */
    public function test_call_off_email_on_a_transcribed_call_creates_no_ticket(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');      // live deployment: master ON
        Setting::setValue('intake_call_enabled', '0'); // operator closes the CALL door
        Setting::setValue('intake_email_enabled', '1');

        $client = Client::factory()->create();
        $call = $this->makeResolvedCall($client);

        $this->expectRouterNeverConsultedForCall();

        app(CallIntakePipeline::class)->handle($call);

        $this->assertSame(0, Ticket::count(), 'call intake is OFF — no ticket may be created from a call');
        $this->assertNull($call->fresh()->ticket_id, 'the call must not be linked to any ticket');
    }

    /** Call intake closed ⇒ the transcription success tail must not even queue the job. */
    public function test_call_off_does_not_dispatch_the_call_intake_job(): void
    {
        Queue::fake();
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_call_enabled', '0');
        Setting::setValue('intake_email_enabled', '1');

        $client = Client::factory()->create();
        $call = $this->makeResolvedCall($client);

        $this->finalizeTranscription($call);

        Queue::assertNotPushed(CallIntakeJob::class);
    }

    /**
     * …and with call intake off, inbound EMAIL is untouched: still routed, router still
     * consulted. REGRESSION LOCK — this is the exact damage the old shared gate did, and
     * it fails the moment anyone re-points the email path at the call switch.
     */
    public function test_call_off_email_on_an_inbound_email_still_routes_normally(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_call_enabled', '0');
        Setting::setValue('intake_email_enabled', '1');

        $client = Client::factory()->create();
        User::factory()->create();
        $email = $this->makeInboundEmail($client);

        // Email intake is ON, so the router MUST still be consulted for email.
        $this->expectRouterConsultedForEmail();

        app(EmailService::class)->processInbound($email);

        $this->assertNotNull($email->fresh()->ticket_id, 'email intake must still produce a ticket');
        $this->assertSame(1, Ticket::count());
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HEADLINE INVERSE — CALL ON / EMAIL OFF, on a live deployment.
    // ═══════════════════════════════════════════════════════════════════════

    /** Call intake open ⇒ a resolved call still creates its ticket. (Regression lock.) */
    public function test_call_on_email_off_a_transcribed_call_creates_a_ticket(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_call_enabled', '1');
        Setting::setValue('intake_email_enabled', '0');

        User::factory()->create();
        $client = Client::factory()->create();
        $call = $this->makeResolvedCall($client);

        $this->expectRouterConsultedForCall();

        app(CallIntakePipeline::class)->handle($call);

        $this->assertCount(1, $this->callTickets(), 'call intake is ON — the call must create its ticket');
        $this->assertSame($this->callTickets()->first()->id, $call->fresh()->ticket_id);
    }

    /** Call intake open ⇒ the transcription success tail queues the intake job. */
    public function test_call_on_dispatches_the_call_intake_job(): void
    {
        Queue::fake();
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_call_enabled', '1');
        Setting::setValue('intake_email_enabled', '0');

        $client = Client::factory()->create();
        $call = $this->makeResolvedCall($client);

        $this->finalizeTranscription($call);

        Queue::assertPushed(CallIntakeJob::class);
    }

    /**
     * Email intake closed ⇒ the router is never consulted for email, EVEN THOUGH the
     * legacy master is on. Genuinely fails before the split (the shared gate would have
     * kept email routing).
     */
    public function test_call_on_email_off_the_email_router_is_never_consulted(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_call_enabled', '1');
        Setting::setValue('intake_email_enabled', '0');

        $client = Client::factory()->create();
        User::factory()->create();
        $email = $this->makeInboundEmail($client);

        // Email intake is OFF: the AI router must not run. The email still becomes a
        // ticket via the legacy autoCreateTicketFromEmail fallback (unchanged behaviour).
        $this->expectRouterNeverConsultedForEmail();

        app(EmailService::class)->processInbound($email);

        $this->assertNotNull($email->fresh()->ticket_id, 'the dormant email path still auto-creates, exactly as before');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GREENFIELD — a fresh box configured ONLY through the new per-channel keys
    // (no legacy key at all), i.e. what the new Settings card writes.
    // ═══════════════════════════════════════════════════════════════════════

    /** The call key alone must open the call gate — with no legacy key in sight. */
    public function test_call_key_alone_opens_the_call_gate(): void
    {
        Bus::fake();
        Setting::setValue('intake_call_enabled', '1'); // no intake_enabled anywhere

        User::factory()->create();
        $client = Client::factory()->create();
        $call = $this->makeResolvedCall($client);

        $this->expectRouterConsultedForCall();

        app(CallIntakePipeline::class)->handle($call);

        $this->assertCount(1, $this->callTickets(), 'the per-channel key alone must drive the call gate');
    }

    /** The email key alone must open the email gate — with no legacy key in sight. */
    public function test_email_key_alone_opens_the_email_gate(): void
    {
        Bus::fake();
        Setting::setValue('intake_email_enabled', '1'); // no intake_enabled anywhere

        $client = Client::factory()->create();
        User::factory()->create();
        $email = $this->makeInboundEmail($client);

        $this->expectRouterConsultedForEmail();

        app(EmailService::class)->processInbound($email);

        $this->assertNotNull($email->fresh()->ticket_id);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // BACKWARD COMPATIBILITY — a deployment carrying ONLY the legacy key
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Legacy intake_enabled=1, neither new key present ⇒ BOTH channels are ON,
     * exactly as today. No surprise flip on deploy.
     */
    public function test_legacy_intake_enabled_on_drives_the_call_channel(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1'); // ONLY the legacy key

        User::factory()->create();
        $client = Client::factory()->create();
        $call = $this->makeResolvedCall($client);

        $this->expectRouterConsultedForCall();

        app(CallIntakePipeline::class)->handle($call);

        $this->assertCount(1, $this->callTickets(), 'legacy intake_enabled=1 must keep call intake ON');
    }

    public function test_legacy_intake_enabled_on_drives_the_email_channel(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1'); // ONLY the legacy key

        $client = Client::factory()->create();
        User::factory()->create();
        $email = $this->makeInboundEmail($client);

        $this->expectRouterConsultedForEmail();

        app(EmailService::class)->processInbound($email);

        $this->assertNotNull($email->fresh()->ticket_id, 'legacy intake_enabled=1 must keep email intake ON');
    }

    public function test_legacy_intake_enabled_on_dispatches_the_call_intake_job(): void
    {
        Queue::fake();
        Setting::setValue('intake_enabled', '1'); // ONLY the legacy key

        $client = Client::factory()->create();
        $call = $this->makeResolvedCall($client);

        $this->finalizeTranscription($call);

        Queue::assertPushed(CallIntakeJob::class);
    }

    /**
     * Legacy intake_enabled=0, neither new key present ⇒ BOTH channels are OFF,
     * exactly as today. The absent per-channel key must NOT read as "on".
     */
    public function test_legacy_intake_enabled_off_keeps_the_call_channel_off(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '0'); // ONLY the legacy key, explicitly off

        $client = Client::factory()->create();
        $call = $this->makeResolvedCall($client);

        $this->expectRouterNeverConsultedForCall();

        app(CallIntakePipeline::class)->handle($call);

        $this->assertSame(0, Ticket::count(), 'legacy intake_enabled=0 must keep call intake OFF');
    }

    public function test_legacy_intake_enabled_off_keeps_the_email_router_dormant(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '0'); // ONLY the legacy key, explicitly off

        $client = Client::factory()->create();
        User::factory()->create();
        $email = $this->makeInboundEmail($client);

        $this->expectRouterNeverConsultedForEmail();

        app(EmailService::class)->processInbound($email);

        // Dormant email intake still auto-creates — byte-identical to pre-split behaviour.
        $this->assertNotNull($email->fresh()->ticket_id);
    }

    /** The out-of-the-box state (no keys at all) stays dormant on both channels. */
    public function test_no_keys_at_all_leaves_both_channels_dormant(): void
    {
        Bus::fake();
        // No intake_enabled, no intake_call_enabled, no intake_email_enabled.

        $client = Client::factory()->create();
        $call = $this->makeResolvedCall($client);

        $this->expectRouterNeverConsultedForCall();

        app(CallIntakePipeline::class)->handle($call);

        $this->assertSame(0, Ticket::count(), 'intake ships dormant — no keys means no call intake');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRECEDENCE — an explicit per-channel key overrides the legacy key
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * THE BUG THIS PR EXISTS TO FIX. Legacy master ON (so email keeps working), but
     * the operator has explicitly closed the CALL channel. The explicit '0' must WIN
     * over the legacy '1' — otherwise the call gate is unturnoffable.
     */
    public function test_explicit_call_off_overrides_legacy_intake_enabled_on(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');      // legacy master ON
        Setting::setValue('intake_call_enabled', '0'); // …but calls explicitly OFF

        $client = Client::factory()->create();
        $call = $this->makeResolvedCall($client);

        $this->expectRouterNeverConsultedForCall();

        app(CallIntakePipeline::class)->handle($call);

        $this->assertSame(0, Ticket::count(), 'an explicit per-channel OFF must beat the legacy master ON');
    }

    /** …while the email channel, whose key is still absent, keeps inheriting the legacy ON. */
    public function test_explicit_call_off_leaves_email_inheriting_the_legacy_on(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_call_enabled', '0');

        $client = Client::factory()->create();
        User::factory()->create();
        $email = $this->makeInboundEmail($client);

        $this->expectRouterConsultedForEmail();

        app(EmailService::class)->processInbound($email);

        $this->assertNotNull($email->fresh()->ticket_id, 'closing the call gate must not touch email');
    }

    /** The mirror: an explicit per-channel ON beats a legacy master OFF. */
    public function test_explicit_call_on_overrides_legacy_intake_enabled_off(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '0');      // legacy master OFF
        Setting::setValue('intake_call_enabled', '1'); // …but calls explicitly ON

        User::factory()->create();
        $client = Client::factory()->create();
        $call = $this->makeResolvedCall($client);

        $this->expectRouterConsultedForCall();

        app(CallIntakePipeline::class)->handle($call);

        $this->assertCount(1, $this->callTickets(), 'an explicit per-channel ON must beat the legacy master OFF');
    }

    /** An empty-string key is "unset", not "off" — it must fall back, not silently disable. */
    public function test_blank_channel_key_falls_back_to_the_legacy_key(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_call_enabled', ''); // blank ⇒ treat as absent

        User::factory()->create();
        $client = Client::factory()->create();
        $call = $this->makeResolvedCall($client);

        $this->expectRouterConsultedForCall();

        app(CallIntakePipeline::class)->handle($call);

        $this->assertCount(1, $this->callTickets(), 'a blank key must inherit the legacy master, not read as OFF');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // The hard rail: this PR adds SWITCHES ONLY — no new auto-act thresholds.
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Turning the call channel ON must not, by itself, make the agent auto-act.
     * The attach threshold stays null (held-first), so a confident ATTACH is still
     * HELD as a new ticket + a suggestion — never silently applied.
     */
    public function test_enabling_a_channel_does_not_enable_auto_attach(): void
    {
        Bus::fake();
        Setting::setValue('intake_call_enabled', '1');
        // intake_attach_auto_threshold deliberately unset.

        User::factory()->create();
        $client = Client::factory()->create();
        $existing = Ticket::factory()->create([
            'client_id' => $client->id,
            'subject' => 'Printer offline',
            'status' => TicketStatus::New->value,
        ]);
        $call = $this->makeResolvedCall($client);

        $this->mock(IntakeRouter::class)
            ->shouldReceive('routeContent')
            ->once()
            ->andReturn(new IntakeDecision('attach', $existing->id, 0.99, 'same printer'));

        $this->assertNull(\App\Support\AgentConfig::intakeAttachAutoThreshold(), 'auto-attach must stay off by default');

        app(CallIntakePipeline::class)->handle($call);

        // Held, not auto-applied: a NEW ticket, not an attach to $existing.
        $this->assertCount(1, $this->callTickets(), 'a 0.99-confidence attach must still be HELD (new ticket), not auto-applied');
        $this->assertNotSame($existing->id, $call->fresh()->ticket_id);
    }
}
