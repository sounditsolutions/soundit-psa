<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\PrepayService;
use App\Services\TimeEntryContractMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Card bYsLRx06 r2 (contract-s3:12, diff:10): the contract-move readers price an
 * overridden entry by its billed time, not its actual time. Synthetic data only (G-13).
 */
class BillableTimeMoveAccessorTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private Contract $to;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $client = Client::create(['name' => 'Synthetic Move Client']);
        $from = Contract::create(['client_id' => $client->id, 'name' => 'Synthetic From', 'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01']);
        $this->to = Contract::create(['client_id' => $client->id, 'name' => 'Synthetic To', 'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01']);
        $this->ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $from->id]);
    }

    private function note(array $attributes): TicketNote
    {
        return TicketNote::forceCreate(array_merge([
            'ticket_id' => $this->ticket->id, 'body' => 'Synthetic work', 'note_type' => 'note',
            'is_private' => true, 'time_minutes' => 60, 'is_billable' => true, 'noted_at' => now(),
        ], $attributes))->fresh();
    }

    private function movedBody(TicketNote|PhoneCall $entry): string
    {
        app(PrepayService::class)->moveEntryContract($entry, $this->to, 'Synthetic reclassification', User::factory()->create());

        return TicketNote::where('ticket_id', $this->ticket->id)->where('note_type', 'system')->where('body', 'like', 'Moved %')->latest('id')->firstOrFail()->body;
    }

    public function test_unledgered_move_reports_billed_hours_for_overridden_note_and_call(): void
    {
        $this->assertStringContainsString('time (0.50 h)', $this->movedBody($this->note(['billable_minutes' => 30])));
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-move-accessor', 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'is_billable' => true, 'duration' => 720, 'billable_minutes' => 6,
            'started_at' => now(), 'ticket_id' => $this->ticket->id,
        ]));
        $this->assertStringContainsString('time (0.10 h)', $this->movedBody($call->fresh()));
    }

    public function test_note_billing_zero_is_refused_with_billed_minutes_wording(): void
    {
        $note = $this->note(['billable_minutes' => 0]);
        $this->assertSame(60, $note->pricedMinutes());
        $r = app(TimeEntryContractMoveService::class)->agentMove(
            ['entry_type' => 'note', 'entry_id' => $note->id, 'contract_id' => $this->to->id, 'reason' => 'Synthetic move'], 'synthetic', true);
        $this->assertSame('This note has no billed minutes to move.', $r['error'] ?? null);
        $this->assertArrayNotHasKey('error_code', $r);
        $this->assertDatabaseCount('time_entry_move_proposals', 0);
    }
}
