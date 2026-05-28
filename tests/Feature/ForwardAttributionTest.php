<?php

namespace Tests\Feature;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Email;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ForwardAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Neutralize stray queued jobs (triage on ticket create, email-add
        // notification). linkEmailToTicket/processInbound run synchronously.
        Bus::fake();
    }

    private function makeTicket(): Ticket
    {
        $client = Client::create(['name' => 'Acme Corp']);

        // No assignee_id on purpose: notifyEmailAdded() no-ops without one.
        return Ticket::create([
            'client_id' => $client->id,
            'subject'   => 'Printer offline',
            'type'      => TicketType::Incident,
            'status'    => TicketStatus::New,
            'priority'  => TicketPriority::P3,
        ]);
    }

    public function test_forwarded_email_is_attributed_to_original_sender(): void
    {
        $ticket = $this->makeTicket();

        $email = Email::create([
            'direction'    => 'inbound',
            'from_address' => 'charlie@couttspnw.com',
            'from_name'    => 'Charlie Coutts',
            'subject'      => "FW: Printer offline [{$ticket->display_id}]",
            'body_text'    => "FYI\n\nFrom: Jane Doe <jane@acme.com>\nSent: Thursday, May 28, 2026\nTo: Charlie Coutts\nSubject: Printer offline\n\nThe printer is still offline.",
            'received_at'  => now(),
        ]);

        app(EmailService::class)->linkEmailToTicket($email, $ticket);

        $note = TicketNote::where('ticket_id', $ticket->id)
            ->where('email_id', $email->id)
            ->first();

        $this->assertNotNull($note);
        $this->assertSame('Jane Doe', $note->author_name);
        $this->assertSame(WhoType::EndUser, $note->who_type);
        $this->assertStringContainsString("[Forwarded into {$ticket->display_id} by Charlie Coutts]", $note->body);
        $this->assertStringContainsString('The printer is still offline.', $note->body);
    }

    public function test_normal_reply_is_not_reattributed(): void
    {
        $ticket = $this->makeTicket();

        $email = Email::create([
            'direction'    => 'inbound',
            'from_address' => 'jane@acme.com',
            'from_name'    => 'Jane Doe',
            'subject'      => "RE: Printer offline [{$ticket->display_id}]",
            'body_text'    => "Thanks, that worked!",
            'received_at'  => now(),
        ]);

        app(EmailService::class)->linkEmailToTicket($email, $ticket);

        $note = TicketNote::where('email_id', $email->id)->first();
        $this->assertSame('Jane Doe', $note->author_name);
        $this->assertStringNotContainsString('Forwarded into', $note->body);
    }

    public function test_forwarded_email_with_unparseable_sender_falls_back_to_forwarder(): void
    {
        $ticket = $this->makeTicket();

        // Gmail-style banner makes isForwarded() true (genuinely hits the forward
        // branch), but the From: line has no extractable email address, so
        // parseOriginalSender() returns null and we must fall back to the forwarder.
        $email = Email::create([
            'direction'    => 'inbound',
            'from_address' => 'charlie@couttspnw.com',
            'from_name'    => 'Charlie Coutts',
            'subject'      => "FW: Printer offline [{$ticket->display_id}]",
            'body_text'    => "FYI\n\n---------- Forwarded message ---------\nFrom: Jane Doe\nDate: Thursday, May 28, 2026\nSubject: Printer offline\nTo: Charlie Coutts\n\nThe printer is still offline.",
            'received_at'  => now(),
        ]);

        app(EmailService::class)->linkEmailToTicket($email, $ticket);

        $note = TicketNote::where('ticket_id', $ticket->id)
            ->where('email_id', $email->id)
            ->first();

        $this->assertNotNull($note);
        $this->assertSame('Charlie Coutts', $note->author_name);
        $this->assertSame(WhoType::EndUser, $note->who_type);
        $this->assertStringNotContainsString('Forwarded into', $note->body);
    }

    public function test_forwarded_email_does_not_create_a_new_ticket(): void
    {
        $ticket = $this->makeTicket();

        $email = Email::create([
            'direction'    => 'inbound',
            'from_address' => 'charlie@couttspnw.com',
            'from_name'    => 'Charlie Coutts',
            'subject'      => "FW: Printer offline [{$ticket->display_id}]",
            'body_text'    => "FYI\n\nFrom: Jane Doe <jane@acme.com>\nSent: today\nSubject: Printer offline\n\nstill broken",
            'received_at'  => now(),
        ]);

        $before = Ticket::count();
        app(EmailService::class)->processInbound($email);

        $this->assertSame($before, Ticket::count());
        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
    }
}
