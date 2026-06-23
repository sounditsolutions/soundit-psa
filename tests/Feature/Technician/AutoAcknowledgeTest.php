<?php

namespace Tests\Feature\Technician;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\WhoType;
use App\Jobs\RunTechnicianLoop;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Technician\TechnicianDisclosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Tests\TestCase;

class AutoAcknowledgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function configureAutoAck(User $actor): void
    {
        Setting::setValue('technician_enabled', '1');
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode(['send_ack' => 'auto']));
    }

    private function ticketWithContact(): Ticket
    {
        $client = Client::factory()->create();
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

    public function test_ack_creates_disclosed_ai_note_emails_the_client_and_advances_run(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $this->configureAutoAck($actor);
        $ticket = $this->ticketWithContact();

        $this->mock(EmailService::class, function (MockInterface $m): void {
            $m->shouldReceive('sendTicketReplyNote')->once()->andReturnNull();
        });

        (new RunTechnicianLoop($ticket->id))->handle();

        $note = TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->first();
        $this->assertNotNull($note);
        $this->assertSame($actor->id, $note->author_id);
        $this->assertSame(WhoType::Agent, $note->who_type);
        $this->assertSame(NoteType::Reply, $note->note_type);
        $this->assertStringContainsString(TechnicianDisclosure::DISCLOSURE_SENTINEL, $note->body);
        $this->assertStringContainsString('Chet', $note->body);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_ack',
            'result_status' => 'executed',
            'actor_id' => $actor->id,
        ]);

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_ack')->firstOrFail();
        $this->assertSame(TechnicianRunState::Done, $run->state);
    }

    public function test_no_contact_email_keeps_the_note_and_does_not_crash(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $this->configureAutoAck($actor);
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => null]);

        $this->mock(EmailService::class, function (MockInterface $m): void {
            $m->shouldReceive('sendTicketReplyNote')->never();
        });

        (new RunTechnicianLoop($ticket->id))->handle();

        $note = TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->first();
        $this->assertNotNull($note, 'the note is still written even when there is no email to send');
        $this->assertNull($note->email_id);
    }

    public function test_ack_is_idempotent_on_re_run(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $this->configureAutoAck($actor);
        $ticket = $this->ticketWithContact();

        $this->mock(EmailService::class, function (MockInterface $m): void {
            $m->shouldReceive('sendTicketReplyNote')->once()->andReturnNull();
        });

        (new RunTechnicianLoop($ticket->id))->handle();
        (new RunTechnicianLoop($ticket->id))->handle();

        $this->assertSame(1, TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->count());
    }

    public function test_kill_switch_writes_no_note_and_sends_no_email(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $this->configureAutoAck($actor);
        Setting::setValue('technician_kill_switch', '1');
        $ticket = $this->ticketWithContact();

        $this->mock(EmailService::class, function (MockInterface $m): void {
            $m->shouldReceive('sendTicketReplyNote')->never();
        });

        (new RunTechnicianLoop($ticket->id))->handle();

        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->count());
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'send_ack', 'result_status' => 'held']);
    }

    public function test_ack_suppressed_for_sensitive_category_writes_no_note_sends_no_email_and_advances_run(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $this->configureAutoAck($actor);
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => null,
            'category' => 'Security Incident',
        ]);

        $this->mock(EmailService::class, function (MockInterface $m): void {
            $m->shouldReceive('sendTicketReplyNote')->never();
        });

        (new RunTechnicianLoop($ticket->id))->handle();

        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->count());

        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_ack')->firstOrFail();
        $this->assertSame(TechnicianRunState::Done, $run->state);
    }
}
