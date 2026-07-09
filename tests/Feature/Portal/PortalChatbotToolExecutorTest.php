<?php

namespace Tests\Feature\Portal;

use App\Enums\InvoiceStatus;
use App\Enums\NoteType;
use App\Enums\PersonType;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Portal\PortalChatbotToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security-critical scoping for the portal chatbot's read-only tool executor
 * (psa-2ab). The whole feature's safety rests on: never returning another
 * client's data, honouring company-wide access on tickets, and mirroring the
 * portal's own visibility rules for invoices / devices / agreements.
 */
class PortalChatbotToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    private function person(Client $client, bool $companyWide): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Pat',
            'last_name' => 'Portal',
            'email' => 'pat'.uniqid().'@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => $companyWide,
            'password' => 'secret-portal-pw',
        ]);
    }

    public function test_constructor_rejects_a_missing_client_scope(): void
    {
        $this->expectException(\RuntimeException::class);
        new PortalChatbotToolExecutor(clientId: 0);
    }

    public function test_list_tickets_never_returns_another_clients_tickets(): void
    {
        $mine = Client::create(['name' => 'Mine Inc']);
        $other = Client::create(['name' => 'Other Inc']);

        Ticket::factory()->create(['client_id' => $mine->id, 'status' => TicketStatus::New, 'subject' => 'My printer is down']);
        Ticket::factory()->create(['client_id' => $other->id, 'status' => TicketStatus::New, 'subject' => 'SECRET other client issue']);

        $result = (new PortalChatbotToolExecutor(clientId: $mine->id, companyWideAccess: true))
            ->execute('list_tickets', ['status' => 'all']);

        $subjects = array_column($result['tickets'], 'subject');
        $this->assertContains('My printer is down', $subjects);
        $this->assertNotContains('SECRET other client issue', $subjects);
        $this->assertSame(1, $result['count']);
    }

    public function test_list_tickets_without_company_wide_access_shows_only_own_tickets(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $me = $this->person($client, companyWide: false);
        $colleague = $this->person($client, companyWide: false);

        Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $me->id, 'status' => TicketStatus::New, 'subject' => 'Mine']);
        Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $colleague->id, 'status' => TicketStatus::New, 'subject' => 'Colleague only']);

        $result = (new PortalChatbotToolExecutor(clientId: $client->id, companyWideAccess: false, personId: $me->id))
            ->execute('list_tickets', ['status' => 'all']);

        $subjects = array_column($result['tickets'], 'subject');
        $this->assertSame(['Mine'], $subjects);
    }

    public function test_company_wide_access_sees_all_client_tickets(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $me = $this->person($client, companyWide: true);
        $colleague = $this->person($client, companyWide: false);

        Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $me->id, 'status' => TicketStatus::New]);
        Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $colleague->id, 'status' => TicketStatus::New]);

        $result = (new PortalChatbotToolExecutor(clientId: $client->id, companyWideAccess: true, personId: $me->id))
            ->execute('list_tickets', ['status' => 'all']);

        $this->assertSame(2, $result['count']);
    }

    public function test_get_ticket_refuses_another_clients_ticket(): void
    {
        $mine = Client::create(['name' => 'Mine']);
        $other = Client::create(['name' => 'Other']);
        $foreign = Ticket::factory()->create(['client_id' => $other->id, 'subject' => 'Not yours']);

        $result = (new PortalChatbotToolExecutor(clientId: $mine->id, companyWideAccess: true))
            ->execute('get_ticket', ['ticket_id' => $foreign->id]);

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('subject', $result);
    }

    public function test_get_ticket_returns_only_portal_visible_notes(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'status' => TicketStatus::New]);

        TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'Tech', 'body' => 'Public reply to client',
            'note_type' => NoteType::Reply->value, 'is_private' => false, 'who_type' => WhoType::Agent->value,
            'noted_at' => now(),
        ]);
        TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'Tech', 'body' => 'INTERNAL private note',
            'note_type' => NoteType::Note->value, 'is_private' => true, 'who_type' => WhoType::Agent->value,
            'noted_at' => now(),
        ]);
        TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'AI', 'body' => 'AI TRIAGE reasoning',
            'note_type' => NoteType::AiTriage->value, 'is_private' => false, 'who_type' => WhoType::System->value,
            'noted_at' => now(),
        ]);

        $result = (new PortalChatbotToolExecutor(clientId: $client->id, companyWideAccess: true))
            ->execute('get_ticket', ['ticket_id' => $ticket->id]);

        $messages = array_column($result['notes'], 'message');
        $this->assertContains('Public reply to client', $messages);
        $this->assertNotContains('INTERNAL private note', $messages);
        $this->assertNotContains('AI TRIAGE reasoning', $messages);
    }

    public function test_list_invoices_only_shows_portal_visible_statuses(): void
    {
        $client = Client::create(['name' => 'Acme']);

        foreach ([InvoiceStatus::Posted, InvoiceStatus::Synced, InvoiceStatus::Paid, InvoiceStatus::Draft, InvoiceStatus::Void] as $i => $status) {
            Invoice::create([
                'client_id' => $client->id,
                'invoice_number' => 'INV-'.$i,
                'invoice_date' => now()->subDays($i),
                'due_date' => now()->addDays(10),
                'subtotal' => '10.00', 'tax' => '0.00', 'total' => '10.00',
                'status' => $status,
            ]);
        }

        $result = (new PortalChatbotToolExecutor(clientId: $client->id))
            ->execute('list_invoices', []);

        $numbers = array_column($result['invoices'], 'invoice_number');
        sort($numbers);
        $this->assertSame(['INV-0', 'INV-1', 'INV-2'], $numbers); // Posted, Synced, Paid only
    }

    public function test_list_devices_excludes_inactive_and_foreign_assets(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $other = Client::create(['name' => 'Other']);

        Asset::factory()->create(['client_id' => $client->id, 'is_active' => true, 'name' => 'Active-PC']);
        Asset::factory()->create(['client_id' => $client->id, 'is_active' => false, 'name' => 'Retired-PC']);
        Asset::factory()->create(['client_id' => $other->id, 'is_active' => true, 'name' => 'Foreign-PC']);

        $result = (new PortalChatbotToolExecutor(clientId: $client->id))
            ->execute('list_devices', []);

        $names = array_column($result['devices'], 'name');
        $this->assertSame(['Active-PC'], $names);
    }

    public function test_list_agreements_hides_prepay_balance_without_company_wide_access(): void
    {
        $client = Client::create(['name' => 'Acme']);
        Contract::create([
            'client_id' => $client->id, 'name' => 'Managed Services', 'type' => 'managed', 'status' => 'active',
            'start_date' => '2026-01-01', 'prepay_as_amount' => false, 'prepay_balance' => 12.5,
        ]);

        $withoutAccess = (new PortalChatbotToolExecutor(clientId: $client->id, companyWideAccess: false))
            ->execute('list_agreements', []);
        $this->assertArrayNotHasKey('prepaid_hours_remaining', $withoutAccess['agreements'][0]);

        $withAccess = (new PortalChatbotToolExecutor(clientId: $client->id, companyWideAccess: true))
            ->execute('list_agreements', []);
        $this->assertArrayHasKey('prepaid_hours_remaining', $withAccess['agreements'][0]);
    }

    public function test_list_agreements_excludes_inactive_contracts(): void
    {
        $client = Client::create(['name' => 'Acme']);
        Contract::create(['client_id' => $client->id, 'name' => 'Active', 'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01']);
        Contract::create(['client_id' => $client->id, 'name' => 'Expired', 'type' => 'managed', 'status' => 'expired', 'start_date' => '2020-01-01']);

        $result = (new PortalChatbotToolExecutor(clientId: $client->id, companyWideAccess: true))
            ->execute('list_agreements', []);

        $this->assertSame(['Active'], array_column($result['agreements'], 'name'));
    }

    public function test_account_summary_counts_are_client_scoped(): void
    {
        $client = Client::create(['name' => 'Acme']);
        $other = Client::create(['name' => 'Other']);

        Ticket::factory()->create(['client_id' => $client->id, 'status' => TicketStatus::New]);
        Ticket::factory()->create(['client_id' => $client->id, 'status' => TicketStatus::Closed]);
        Ticket::factory()->create(['client_id' => $other->id, 'status' => TicketStatus::New]);
        Asset::factory()->create(['client_id' => $client->id, 'is_active' => true]);
        Contract::create(['client_id' => $client->id, 'name' => 'C', 'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01']);

        $result = (new PortalChatbotToolExecutor(clientId: $client->id, companyWideAccess: true))
            ->execute('get_account_summary', []);

        $this->assertSame(1, $result['open_tickets']);
        $this->assertSame(1, $result['devices']);
        $this->assertSame(1, $result['active_agreements']);
    }
}
