<?php

namespace Tests\Feature\ContactIntake;

use App\Models\Client;
use App\Models\Person;
use App\Models\Ticket;
use App\Services\Mcp\PortalMcpToolExecutor;
use App\Services\Triage\ContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ContactTicketContainmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_reads_exclude_whole_form_ticket_but_not_existing_ticket(): void
    {
        Bus::fake();
        $client = Client::factory()->create();
        $person = Person::create(['client_id' => $client->id, 'first_name' => 'Synthetic', 'email' => 'synthetic@example.com', 'company_wide_access' => true]);
        $normal = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id, 'subject' => 'NORMAL_CONTROL', 'status' => \App\Enums\TicketStatus::New]);
        $held = Ticket::factory()->make(['client_id' => $client->id, 'contact_id' => $person->id, 'subject' => 'UNVERIFIED_FORM_CONTROL', 'status' => \App\Enums\TicketStatus::New]);
        $held->forceFill(['contact_intake_origin' => true])->save();
        $executor = new PortalMcpToolExecutor($person);
        $this->assertSame('NORMAL_CONTROL', $executor->execute('get_my_ticket', ['ticket_id' => $normal->id])['subject']);
        $this->assertSame(['error' => 'Ticket not found.'], $executor->execute('get_my_ticket', ['ticket_id' => $held->id]));
        $list = json_encode($executor->execute('list_my_open_tickets', []));
        $this->assertStringContainsString('NORMAL_CONTROL', $list);
        $this->assertStringNotContainsString('UNVERIFIED_FORM_CONTROL', $list);
        $this->assertSame([$normal->id], Ticket::automationVisible()->pluck('id')->all());
        $held->forceFill(['contact_intake_origin' => false])->save();
        $this->assertTrue($held->fresh()->contact_intake_origin);
    }

    public function test_context_refuses_whole_form_ticket_with_normal_positive_control(): void
    {
        Bus::fake();
        $ticket = Ticket::factory()->create(['subject' => 'NORMAL_CONTEXT_CONTROL']);
        $this->assertStringContainsString('NORMAL_CONTEXT_CONTROL', ContextBuilder::buildForTicket($ticket));
        $ticket->forceFill(['contact_intake_origin' => true])->save();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Unverified contact intake.');
        ContextBuilder::buildForTicket($ticket);
    }
}
