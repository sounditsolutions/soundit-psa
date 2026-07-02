<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\ClientStage;
use App\Enums\EmergencyState;
use App\Enums\NoteType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianEmergency;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * EmergencySweep is the relied-on "never miss a real emergency" backstop: it wires
 * detector → grouper → escalation → max-hold and runs every tick while the operator
 * is away. The contract under test is the orchestration's safety invariants:
 *   - operational clients ONLY (prospects must never be escalated) — CO-10;
 *   - a still-aged still-untouched ticket re-detected at minute 16 must NOT spawn a
 *     SECOND emergency (storm window != re-detection dedup) — CO-1;
 *   - a genuine human touch (reply / non-system agent note) marks it acknowledged and
 *     halts escalation — CO-6;
 *   - disabled is a hard no-op.
 */
class EmergencySweepTest extends TestCase
{
    use RefreshDatabase;

    /** An operational client (scopeOperational = stage Active AND is_active). */
    private function operationalClient(): Client
    {
        return Client::factory()->create(['stage' => ClientStage::Active, 'is_active' => true]);
    }

    /** An aged, untouched, OUTAGE P1 — the detector's age+keyword signals both fire. */
    private function agedP1(Client $client): Ticket
    {
        return Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::New->value,
            'priority' => TicketPriority::P1->value,
            'opened_at' => now()->subHour(),
            'responded_at' => null,
            'subject' => 'site OUTAGE',
        ]);
    }

    public function test_disabled_does_nothing(): void
    {
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->never());

        $this->artisan('technician:emergency-sweep')->assertSuccessful();

        $this->assertSame(0, TechnicianEmergency::count());
    }

    public function test_detects_and_escalates_an_aged_p1(): void
    {
        Setting::setValue('technician_enabled', '1');
        $justin = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id]));
        $this->agedP1($this->operationalClient());

        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->atLeast()->once());

        $this->artisan('technician:emergency-sweep')->assertSuccessful();

        $this->assertSame(1, TechnicianEmergency::count());
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'emergency_escalate']);
    }

    public function test_detects_and_escalates_when_only_the_emergency_backstop_is_enabled(): void
    {
        Setting::setValue('technician_enabled', '0');
        Setting::setValue('technician_emergency_enabled', '1');
        $justin = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id]));
        $this->agedP1($this->operationalClient());

        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->atLeast()->once());

        $this->artisan('technician:emergency-sweep')->assertSuccessful();

        $this->assertSame(1, TechnicianEmergency::count());
        $this->assertNotNull(TechnicianConfig::coverageStartAt(), 'the sweep defensively anchors emergency-only coverage');
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'emergency_escalate']);
    }

    /**
     * CO-1 (HARD): a still-aged, still-untouched ticket re-detected 20 minutes later
     * (past the 15m storm window) must NOT create a second emergency — the open-emergency
     * skip is the re-detection dedup, the storm window is only the clustering key.
     */
    public function test_co1_aged_ticket_is_not_redetected_into_a_second_emergency(): void
    {
        Setting::setValue('technician_enabled', '1');
        $justin = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id]));
        $this->agedP1($this->operationalClient());

        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->atLeast()->once());

        $this->artisan('technician:emergency-sweep')->assertSuccessful();
        $this->assertSame(1, TechnicianEmergency::count());

        // Jump past the 15-minute storm window. The ticket is STILL aged + untouched.
        Carbon::setTestNow(now()->addMinutes(20));
        $this->artisan('technician:emergency-sweep')->assertSuccessful();

        $this->assertSame(1, TechnicianEmergency::count(), 'no duplicate emergency at minute 16+');

        Carbon::setTestNow();
    }

    /**
     * CO-6: a genuine human touch (a non-system Agent note after alerted_at) marks the
     * emergency acknowledged + writes an implicit emergency_ack audit row, and escalation
     * does NOT fire that tick.
     */
    public function test_co6_human_agent_note_marks_acknowledged_and_does_not_escalate(): void
    {
        Setting::setValue('technician_enabled', '1');
        $justin = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id]));
        $client = $this->operationalClient();
        $ticket = $this->agedP1($client);

        $e = TechnicianEmergency::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'signature' => 's', 'severity' => 3,
            'reasons' => ['age'], 'detected_by' => 'rules', 'state' => EmergencyState::Open,
            'escalation_step' => 0, 'ticket_ids' => [$ticket->id], 'alerted_at' => now()->subMinutes(5),
        ]);

        // A real human agent worked the ticket AFTER the alert.
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $justin->id,
            'author_name' => $justin->name,
            'who_type' => WhoType::Agent,
            'ai_authored' => false,
            'body' => 'On it.',
            'note_type' => NoteType::Note,
            'is_private' => true,
            'noted_at' => now(),
        ]);

        // Escalation must NOT page anyone this tick — a human is already on it.
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->never());

        $this->artisan('technician:emergency-sweep')->assertSuccessful();

        $this->assertSame(EmergencyState::Acknowledged, $e->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'emergency_ack', 'ticket_id' => $ticket->id]);
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'emergency_escalate')->count());
    }

    /**
     * CO-5: an acknowledged emergency with NO detected human touch (the one-tap link
     * was used, but nobody actually worked the ticket) is a SNOOZE — once the reping
     * interval lapses it RESUMES (state → open) and escalates again, so a leaked or
     * forwarded ack link can never permanently silence the backstop.
     */
    public function test_co5_acked_but_untouched_emergency_re_alerts_after_the_interval(): void
    {
        Setting::setValue('technician_enabled', '1');
        Setting::setValue('technician_emergency_reping', '30');
        $justin = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id]));
        $client = $this->operationalClient();
        $ticket = $this->agedP1($client);

        // Acknowledged 31 minutes ago, but NO human ever touched the ticket
        // (responded_at null, no agent note, no assignee).
        $e = TechnicianEmergency::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'signature' => 's', 'severity' => 3,
            'reasons' => ['age'], 'detected_by' => 'rules', 'state' => EmergencyState::Acknowledged,
            'escalation_step' => 0, 'ticket_ids' => [$ticket->id],
            'alerted_at' => now()->subHour(), 'acknowledged_at' => now()->subMinutes(31),
        ]);

        // The snooze has lapsed ⇒ it must page again this tick.
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->atLeast()->once());

        $this->artisan('technician:emergency-sweep')->assertSuccessful();

        $this->assertSame(EmergencyState::Open, $e->fresh()->state, 'a leaked ack link cannot permanently silence the backstop');
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'emergency_escalate', 'ticket_id' => $ticket->id]);
    }

    /** CO-5 (inverse): inside the snooze interval an acked-untouched emergency stays quiet. */
    public function test_co5_acked_but_untouched_within_interval_stays_quiet(): void
    {
        Setting::setValue('technician_enabled', '1');
        Setting::setValue('technician_emergency_reping', '30');
        $justin = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id]));
        $client = $this->operationalClient();
        $ticket = $this->agedP1($client);

        $e = TechnicianEmergency::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'signature' => 's', 'severity' => 3,
            'reasons' => ['age'], 'detected_by' => 'rules', 'state' => EmergencyState::Acknowledged,
            'escalation_step' => 0, 'ticket_ids' => [$ticket->id],
            'alerted_at' => now()->subHour(), 'acknowledged_at' => now()->subMinutes(5), // well inside the 30m snooze
        ]);

        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->never());

        $this->artisan('technician:emergency-sweep')->assertSuccessful();

        $this->assertSame(EmergencyState::Acknowledged, $e->fresh()->state);
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'emergency_escalate')->count());
    }

    /** (3) Resolve: an emergency whose member tickets are all closed/resolved is resolved. */
    public function test_resolves_when_all_member_tickets_are_closed(): void
    {
        Setting::setValue('technician_enabled', '1');
        $justin = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id]));
        $client = $this->operationalClient();
        // A CLOSED ticket (factory default status is Closed) — terminal.
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'status' => TicketStatus::Closed->value]);

        $e = TechnicianEmergency::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'signature' => 's', 'severity' => 3,
            'reasons' => ['age'], 'detected_by' => 'rules', 'state' => EmergencyState::Open,
            'escalation_step' => 0, 'ticket_ids' => [$ticket->id], 'alerted_at' => now()->subHour(),
        ]);

        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->never());

        $this->artisan('technician:emergency-sweep')->assertSuccessful();

        $this->assertSame(EmergencyState::Resolved, $e->fresh()->state);
        $this->assertNotNull($e->fresh()->resolved_at);
    }

    /**
     * The honest max-hold trigger: when NOBODY on the chain is reachable and the
     * max-hold has not been sent, the sweep sends it once on the representative ticket
     * (the one autonomous client-facing send). With send_max_hold mapped auto + a real
     * contact, the note is written and emailed exactly once.
     */
    public function test_sends_max_hold_when_whole_chain_unreachable(): void
    {
        Setting::setValue('technician_enabled', '1');
        Setting::setValue('technician_action_tiers', json_encode(['send_max_hold' => 'auto']));
        $actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $justin = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id]));
        \App\Support\TechnicianConfig::setOperatorAvailable($justin->id, false); // whole chain away

        $client = $this->operationalClient();
        $person = \App\Models\Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test', 'last_name' => 'Contact', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id, 'contact_id' => $person->id,
            'status' => TicketStatus::New->value, 'priority' => TicketPriority::P1->value,
            'opened_at' => now()->subHour(), 'responded_at' => null, 'subject' => 'site OUTAGE',
        ]);

        // notifyUser still fires (the all_unavailable re-ping path); the client email is
        // what proves the max-hold actually went out.
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->zeroOrMoreTimes());
        $this->mock(\App\Services\EmailService::class, function (MockInterface $m): void {
            $m->shouldReceive('sendTicketReplyNote')->once()->andReturnNull();
        });

        $this->artisan('technician:emergency-sweep')->assertSuccessful();

        $e = TechnicianEmergency::first();
        $this->assertNotNull($e);
        $this->assertNotNull($e->max_hold_sent_at, 'the max-hold claim stands after the autonomous send');
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_max_hold', 'result_status' => 'executed',
        ]);
    }

    /**
     * Reachability must be DEFINED IDENTICALLY in the sweep and EscalationService, or
     * the backstop has a hole: if the ENTIRE chain is deleted/inactive users,
     * EscalationService correctly finds nobody reachable and pages no one — and the
     * sweep MUST therefore send the client max-hold. With the OLD operatorAvailable()-
     * only gate the sweep saw a missing user as "available" (default), withheld the
     * max-hold, and the emergency sat open forever: nobody paged AND no client comms.
     * This pins that an all-deleted chain still triggers the honest max-hold — the same
     * outcome as the away-toggle path (test above) — via the shared isReachable().
     */
    public function test_sends_max_hold_when_whole_chain_is_deleted_users(): void
    {
        Setting::setValue('technician_enabled', '1');
        Setting::setValue('technician_action_tiers', json_encode(['send_max_hold' => 'auto']));
        $actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        // The WHOLE escalation chain is a user that no longer exists. operatorAvailable()
        // would default this id to "available" — so only a reachability check that ANDs
        // in existence (isReachable) keeps the sweep and EscalationService in agreement.
        $ghost = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$ghost->id]));
        $ghost->delete();

        $client = $this->operationalClient();
        $person = \App\Models\Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test', 'last_name' => 'Contact', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        Ticket::factory()->create([
            'client_id' => $client->id, 'contact_id' => $person->id,
            'status' => TicketStatus::New->value, 'priority' => TicketPriority::P1->value,
            'opened_at' => now()->subHour(), 'responded_at' => null, 'subject' => 'site OUTAGE',
        ]);

        // EscalationService pages no one (the deleted member is skipped); the client
        // email is what proves the max-hold went out because the chain is unreachable.
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->zeroOrMoreTimes());
        $this->mock(\App\Services\EmailService::class, function (MockInterface $m): void {
            $m->shouldReceive('sendTicketReplyNote')->once()->andReturnNull();
        });

        $this->artisan('technician:emergency-sweep')->assertSuccessful();

        $e = TechnicianEmergency::first();
        $this->assertNotNull($e);
        $this->assertNotNull($e->max_hold_sent_at, 'an all-deleted chain is unreachable ⇒ the max-hold must still go out (no missed emergency)');
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_max_hold', 'result_status' => 'executed',
        ]);
    }

    /**
     * THE FLOOD REGRESSION (psa-wmqp). On enable the sweep stamps coverage_start = now,
     * so a pre-existing aged backlog ticket — untouched and past the floor, but BENIGN
     * (no keyword, no SLA breach) — is anchored OUT of the age signal and produces NO
     * emergency and NO page. Before the anchor, the age rule alone flagged the whole
     * stale backlog (~70 emergencies) the moment the Technician was switched on.
     */
    public function test_flood_a_preexisting_aged_backlog_ticket_does_not_page_on_enable(): void
    {
        Setting::setValue('technician_enabled', '1');
        $justin = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id]));

        $client = $this->operationalClient();
        Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::New->value,
            'priority' => TicketPriority::P1->value,
            'opened_at' => now()->subHour(), // aged past the 15m P1 floor…
            'responded_at' => null,           // …and untouched…
            'subject' => 'Printer is a little slow', // …but benign: no keyword
            'description' => 'minor cosmetic issue',
        ]);

        // No one is paged — the backlog predates coverage.
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->never());

        $this->artisan('technician:emergency-sweep')->assertSuccessful();

        $this->assertSame(0, TechnicianEmergency::count(), 'a pre-existing aged backlog ticket must not flood on enable');
    }

    /**
     * Defensive backfill (psa-wmqp): the run() preamble stamps coverage_start for the
     * already-enabled upgrade case — but only when enabled. A disabled invocation
     * early-exits (schedule ->when() + command guard) and must never stamp.
     */
    public function test_sweep_backfills_coverage_start_only_when_enabled(): void
    {
        // Disabled ⇒ the command early-exits, run() is never reached, nothing is stamped.
        $this->assertNull(TechnicianConfig::coverageStartAt());
        $this->artisan('technician:emergency-sweep')->assertSuccessful();
        $this->assertNull(TechnicianConfig::coverageStartAt(), 'a disabled sweep must not stamp coverage start');

        // Enabled ⇒ the run() preamble backfills the anchor.
        Setting::setValue('technician_enabled', '1');
        $this->artisan('technician:emergency-sweep')->assertSuccessful();
        $this->assertNotNull(TechnicianConfig::coverageStartAt(), 'an enabled sweep backfills coverage start');
    }

    /** CO-10: a prospect / non-operational client's aged P1 must NOT be escalated. */
    public function test_co10_prospect_client_is_not_escalated(): void
    {
        Setting::setValue('technician_enabled', '1');
        $justin = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id]));
        $prospect = Client::factory()->create(['stage' => ClientStage::Prospect, 'is_active' => true]);
        $this->agedP1($prospect);

        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->never());

        $this->artisan('technician:emergency-sweep')->assertSuccessful();

        $this->assertSame(0, TechnicianEmergency::count());
    }
}
