<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianEmergency;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Technician\Emergency\MaxHoldSender;
use App\Services\Technician\TechnicianDisclosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * MaxHoldSender is the ONE autonomous client-facing send in the whole phase, so
 * the contract under test is narrow and load-bearing: it sends EXACTLY ONCE, only
 * with the structural disclosure, only through the gate, and a held (non-auto)
 * result must NOT permanently suppress a future legit auto-send.
 */
class MaxHoldSenderTest extends TestCase
{
    use RefreshDatabase;

    private function configureAuto(User $actor): void
    {
        Setting::setValue('technician_enabled', '1');
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode(['send_max_hold' => 'auto']));
    }

    private function ticketWithContact(Client $client): Ticket
    {
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'email' => 'c@example.com',
            'is_active' => true,
        ]);

        return Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);
    }

    private function emergencyFor(Ticket $ticket, Client $client): TechnicianEmergency
    {
        return TechnicianEmergency::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'signature' => 's', 'severity' => 3,
            'reasons' => ['age'], 'detected_by' => 'rules', 'state' => EmergencyState::Open,
            'escalation_step' => 0, 'ticket_ids' => [$ticket->id], 'alerted_at' => now(),
        ]);
    }

    /** CO-11b: auto + a real contact → one disclosed, gated, emailed max-hold; CAS-idempotent. */
    public function test_sends_one_disclosed_max_hold_through_the_gate_when_auto_and_is_idempotent(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $this->configureAuto($actor);
        $client = Client::factory()->create();
        $ticket = $this->ticketWithContact($client);
        $e = $this->emergencyFor($ticket, $client);

        $this->mock(EmailService::class, function (MockInterface $m): void {
            // sent exactly once across BOTH send() calls — the second is a no-op
            $m->shouldReceive('sendTicketReplyNote')->once()->andReturnNull();
        });

        app(MaxHoldSender::class)->send($e, $ticket);

        $this->assertNotNull($e->fresh()->max_hold_sent_at, 'the claim stands after a successful send');
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_max_hold',
            'result_status' => 'executed',
            'actor_id' => $actor->id,
        ]);

        $note = $ticket->notes()->latest('id')->first();
        $this->assertNotNull($note);
        $this->assertTrue((bool) $note->ai_authored);
        // CO-15: assert the name-independent CONSTANT, never a hardcoded substring.
        $this->assertStringContainsString(TechnicianDisclosure::DISCLOSURE_SENTINEL, $note->body);

        // CAS once-guard: a second tick does nothing — still one note, no second send.
        app(MaxHoldSender::class)->send($e->fresh(), $ticket);
        $this->assertSame(1, $ticket->notes()->count());
    }

    /** CO-11b: no deliverable address → the note is still written ONCE, no email, no re-fire. */
    public function test_no_contact_writes_the_note_once_and_sends_no_email(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $this->configureAuto($actor);
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => null]);
        $e = $this->emergencyFor($ticket, $client);

        $this->mock(EmailService::class, function (MockInterface $m): void {
            // no contact email ⇒ the send is short-circuited before EmailService.
            $m->shouldReceive('sendTicketReplyNote')->never();
        });

        app(MaxHoldSender::class)->send($e, $ticket);

        $note = $ticket->notes()->latest('id')->first();
        $this->assertNotNull($note, 'the note is written even with no email to send');
        $this->assertNull($note->email_id);
        $this->assertNotNull($e->fresh()->max_hold_sent_at, 'claim STAYS set — it must not re-fire chasing an undeliverable send');

        // proves it can't loop forever when there is no address.
        app(MaxHoldSender::class)->send($e->fresh(), $ticket);
        $this->assertSame(1, $ticket->notes()->count());
    }

    /** CO-9 revert: a held (non-auto) result must leave max_hold_sent_at NULL so a future auto-send still fires. */
    public function test_held_when_not_auto_reverts_the_claim_and_sends_nothing(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('technician_enabled', '1');
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        // EMPTY tier map ⇒ send_max_hold default-denies to Approve ⇒ gate HOLDS it.
        $client = Client::factory()->create();
        $ticket = $this->ticketWithContact($client);
        $e = $this->emergencyFor($ticket, $client);

        $this->mock(EmailService::class, function (MockInterface $m): void {
            $m->shouldReceive('sendTicketReplyNote')->never();
        });

        app(MaxHoldSender::class)->send($e, $ticket);

        $this->assertNull($e->fresh()->max_hold_sent_at, 'a held action must NOT permanently suppress a future auto-send');
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_max_hold',
            'result_status' => 'awaiting_approval',
        ]);
        $this->assertSame(0, $ticket->notes()->count(), 'a held action writes no client note');
    }
}
