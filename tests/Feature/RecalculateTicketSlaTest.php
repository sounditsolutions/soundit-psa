<?php

namespace Tests\Feature;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RecalculateTicketSlaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ticket creation fires the observer (triage dispatch + creation
        // notification). Fake the bus so no queued work escapes the test.
        Bus::fake();
    }

    private const ANCHOR = '2026-01-01 09:00:00';

    private function client(): Client
    {
        return Client::create(['name' => 'Acme Corp']);
    }

    /**
     * @param  array<string, array<string, int>>  $slaTerms
     */
    private function contractWithSla(Client $client, array $slaTerms): Contract
    {
        return Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'start_date' => '2026-01-01',
            'sla_terms' => $slaTerms,
        ]);
    }

    private function ticket(Client $client, ?Contract $contract, TicketPriority $priority, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'client_id' => $client->id,
            'contract_id' => $contract?->id,
            'subject' => 'Printer offline',
            'type' => TicketType::Incident,
            'status' => TicketStatus::New,
            'priority' => $priority,
            'opened_at' => Carbon::parse(self::ANCHOR),
        ], $overrides));
    }

    public function test_recomputes_response_and_resolution_from_contract_terms(): void
    {
        $client = $this->client();
        $contract = $this->contractWithSla($client, [
            'response' => ['p2' => 2],
            'resolution' => ['p2' => 8],
        ]);
        // Deliberately wrong/missing deadlines that the command should fix.
        $ticket = $this->ticket($client, $contract, TicketPriority::P2, [
            'due_at' => null,
            'response_due_at' => null,
        ]);

        $this->artisan('tickets:recalculate-sla')->assertSuccessful();

        $ticket->refresh();
        $this->assertSame('2026-01-01 17:00:00', $ticket->due_at->toDateTimeString());
        $this->assertSame('2026-01-01 11:00:00', $ticket->response_due_at->toDateTimeString());
    }

    public function test_overwrites_stale_deadline_to_match_changed_terms(): void
    {
        $client = $this->client();
        $contract = $this->contractWithSla($client, [
            'resolution' => ['p1' => 4],
        ]);
        // An old deadline computed under different terms.
        $ticket = $this->ticket($client, $contract, TicketPriority::P1, [
            'due_at' => Carbon::parse('2026-06-01 00:00:00'),
        ]);

        $this->artisan('tickets:recalculate-sla')->assertSuccessful();

        $this->assertSame('2026-01-01 13:00:00', $ticket->refresh()->due_at->toDateTimeString());
    }

    public function test_uses_priority_specific_hours(): void
    {
        $client = $this->client();
        $contract = $this->contractWithSla($client, [
            'resolution' => ['p1' => 4, 'p4' => 72],
        ]);
        $p1 = $this->ticket($client, $contract, TicketPriority::P1);
        $p4 = $this->ticket($client, $contract, TicketPriority::P4);

        $this->artisan('tickets:recalculate-sla')->assertSuccessful();

        $this->assertSame('2026-01-01 13:00:00', $p1->refresh()->due_at->toDateTimeString());
        $this->assertSame('2026-01-04 09:00:00', $p4->refresh()->due_at->toDateTimeString());
    }

    public function test_skips_ticket_whose_contract_has_no_sla_terms(): void
    {
        $client = $this->client();
        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'No SLA',
            'type' => 'breakfix',
            'start_date' => '2026-01-01',
        ]);
        $ticket = $this->ticket($client, $contract, TicketPriority::P2, ['due_at' => null]);

        $this->artisan('tickets:recalculate-sla')->assertSuccessful();

        $this->assertNull($ticket->refresh()->due_at);
    }

    public function test_skips_ticket_with_no_contract(): void
    {
        $client = $this->client();
        $ticket = $this->ticket($client, null, TicketPriority::P2, ['due_at' => null]);

        $this->artisan('tickets:recalculate-sla')->assertSuccessful();

        $this->assertNull($ticket->refresh()->due_at);
    }

    public function test_open_only_by_default_leaves_closed_tickets_untouched(): void
    {
        $client = $this->client();
        $contract = $this->contractWithSla($client, ['resolution' => ['p2' => 8]]);
        $closed = $this->ticket($client, $contract, TicketPriority::P2, [
            'status' => TicketStatus::Closed,
            'due_at' => null,
        ]);

        $this->artisan('tickets:recalculate-sla')->assertSuccessful();

        $this->assertNull($closed->refresh()->due_at, 'Closed tickets should be skipped without --all.');
    }

    public function test_all_flag_includes_closed_tickets(): void
    {
        $client = $this->client();
        $contract = $this->contractWithSla($client, ['resolution' => ['p2' => 8]]);
        $closed = $this->ticket($client, $contract, TicketPriority::P2, [
            'status' => TicketStatus::Closed,
            'due_at' => null,
        ]);

        $this->artisan('tickets:recalculate-sla', ['--all' => true])->assertSuccessful();

        $this->assertSame('2026-01-01 17:00:00', $closed->refresh()->due_at->toDateTimeString());
    }

    public function test_dry_run_makes_no_changes(): void
    {
        $client = $this->client();
        $contract = $this->contractWithSla($client, ['resolution' => ['p2' => 8]]);
        $ticket = $this->ticket($client, $contract, TicketPriority::P2, ['due_at' => null]);

        $this->artisan('tickets:recalculate-sla', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($ticket->refresh()->due_at, 'Dry-run must not persist changes.');
    }

    public function test_clear_missing_nulls_deadline_when_priority_has_no_hours(): void
    {
        $client = $this->client();
        // Terms cover p1 only; our p4 ticket has no configured hours.
        $contract = $this->contractWithSla($client, ['resolution' => ['p1' => 4]]);
        $ticket = $this->ticket($client, $contract, TicketPriority::P4, [
            'due_at' => Carbon::parse('2026-06-01 00:00:00'),
        ]);

        $this->artisan('tickets:recalculate-sla', ['--clear-missing' => true])->assertSuccessful();

        $this->assertNull($ticket->refresh()->due_at);
    }

    public function test_without_clear_missing_leaves_unconfigured_priority_deadline_intact(): void
    {
        $client = $this->client();
        $contract = $this->contractWithSla($client, ['resolution' => ['p1' => 4]]);
        $ticket = $this->ticket($client, $contract, TicketPriority::P4, [
            'due_at' => Carbon::parse('2026-06-01 00:00:00'),
        ]);

        $this->artisan('tickets:recalculate-sla')->assertSuccessful();

        $this->assertSame('2026-06-01 00:00:00', $ticket->refresh()->due_at->toDateTimeString());
    }

    public function test_ticket_option_limits_scope_to_one_ticket(): void
    {
        $client = $this->client();
        $contract = $this->contractWithSla($client, ['resolution' => ['p2' => 8]]);
        $target = $this->ticket($client, $contract, TicketPriority::P2, ['due_at' => null]);
        $other = $this->ticket($client, $contract, TicketPriority::P2, ['due_at' => null]);

        $this->artisan('tickets:recalculate-sla', ['--ticket' => $target->id])->assertSuccessful();

        $this->assertSame('2026-01-01 17:00:00', $target->refresh()->due_at->toDateTimeString());
        $this->assertNull($other->refresh()->due_at, 'Tickets outside --ticket scope must be untouched.');
    }

    public function test_ticket_option_processes_a_closed_ticket_without_all(): void
    {
        $client = $this->client();
        $contract = $this->contractWithSla($client, ['resolution' => ['p2' => 8]]);
        // Explicitly named closed ticket: --ticket overrides the open-only default.
        $closed = $this->ticket($client, $contract, TicketPriority::P2, [
            'status' => TicketStatus::Closed,
            'due_at' => null,
        ]);

        $this->artisan('tickets:recalculate-sla', ['--ticket' => $closed->id])->assertSuccessful();

        $this->assertSame('2026-01-01 17:00:00', $closed->refresh()->due_at->toDateTimeString());
    }

    public function test_falls_back_to_created_at_when_opened_at_is_null(): void
    {
        $client = $this->client();
        $contract = $this->contractWithSla($client, ['resolution' => ['p2' => 8]]);
        $ticket = $this->ticket($client, $contract, TicketPriority::P2, [
            'opened_at' => null,
            'due_at' => null,
        ]);

        $this->artisan('tickets:recalculate-sla')->assertSuccessful();

        $ticket->refresh();
        $expected = $ticket->created_at->copy()->addHours(8);
        $this->assertSame($expected->toDateTimeString(), $ticket->due_at->toDateTimeString());
    }

    public function test_is_idempotent_on_second_run(): void
    {
        $client = $this->client();
        $contract = $this->contractWithSla($client, ['resolution' => ['p2' => 8]]);
        $this->ticket($client, $contract, TicketPriority::P2, ['due_at' => null]);

        $this->artisan('tickets:recalculate-sla')->assertSuccessful();
        // Second run should find nothing to change.
        $this->artisan('tickets:recalculate-sla')
            ->expectsOutputToContain('updated=0')
            ->assertSuccessful();
    }
}
