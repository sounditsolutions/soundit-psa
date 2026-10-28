<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\BillableTimeService;
use App\Services\TimeEntryContractMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillableTimeReadersTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $client = Client::create(['name' => 'Synthetic Reader Client']);
        $contract = Contract::create([
            'client_id' => $client->id, 'name' => 'Synthetic Prepay', 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01', 'prepay_as_amount' => false,
            'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $this->ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $contract->id]);
    }

    private function note(array $attributes = []): TicketNote
    {
        return TicketNote::create(array_merge([
            'ticket_id' => $this->ticket->id, 'body' => 'Synthetic work', 'note_type' => 'note',
            'is_private' => true, 'time_minutes' => 90, 'is_billable' => true, 'noted_at' => now(),
        ], $attributes));
    }

    public function test_lowered_actual_reprices_same_debit_through_observer(): void
    {
        $note = $this->note();
        app(BillableTimeService::class)->setNote($note, 60, 'Synthetic correction', 'ui', null, 'Synthetic staff');
        $id = PrepayTransaction::where('ticket_note_id', $note->id)->sole()->id;
        $note->refresh()->update(['time_minutes' => 45]);
        $row = PrepayTransaction::where('ticket_note_id', $note->id)->sole();
        $this->assertSame($id, $row->id);
        $this->assertEquals(-0.75, $row->hours);
        $this->assertSame(60, $note->fresh()->billable_minutes);
        $this->assertSame(45, $note->fresh()->time_minutes);
    }

    public function test_observer_alone_reverses_override_to_zero(): void
    {
        $note = $this->note();
        $this->assertSame(1, PrepayTransaction::where('ticket_note_id', $note->id)->count());
        // Deliberately bypass the service to isolate the observer's sync arm.
        $note->update(['billable_minutes' => 0]);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count());
        $this->assertSame(90, $note->fresh()->time_minutes);
    }

    public function test_zero_billed_note_does_not_stamp_contract(): void
    {
        $note = $this->note(['billable_minutes' => 0]);
        $this->assertNull($note->fresh()->contract_id);
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count());
        $positive = $this->note(['billable_minutes' => 10]);
        $this->assertSame($this->ticket->contract_id, $positive->fresh()->contract_id);
    }

    public function test_move_entries_match_billed_filter_and_hours(): void
    {
        $notes = collect([
            $this->note(),
            $this->note(['billable_minutes' => 0]),
            $this->note(['billable_minutes' => 30]),
            $this->note(['time_minutes' => 20, 'billable_minutes' => 60]),
            $this->note(['is_billable' => false]),
        ]);
        $adjusted = $this->note(['time_minutes' => 0]);
        $adjusted->time_adjustment_minutes = 15;
        $adjusted->save();
        $notes->push($adjusted);
        $call = PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-reader', 'from_number' => '+15555550188', 'direction' => 'inbound',
            'status' => 'completed', 'duration' => 702, 'billable_minutes' => 5,
            'ticket_id' => $this->ticket->id, 'is_billable' => true, 'started_at' => now(),
        ]);
        $service = app(TimeEntryContractMoveService::class);
        $rows = $service->entries($this->ticket);
        $expected = $notes->filter(fn ($note) => $note->is_billable && $note->billedMinutes() > 0)->pluck('id')->sort()->values()->all();
        $this->assertSame($expected, $rows->where('type', 'note')->pluck('id')->sort()->values()->all());
        foreach ($notes->filter(fn ($note) => $note->is_billable && $note->billedMinutes() > 0) as $note) {
            $this->assertEquals(round($note->billedMinutes() / 60, 2), $rows->where('type', 'note')->firstWhere('id', $note->id)['hours']);
        }
        $this->assertEquals(round(5 / 60, 2), $rows->where('type', 'call')->sole()['hours']);
        $this->assertEquals(round(5 / 60, 4), $service->drawHoursIfMoved($call, null));
        $this->assertEquals(0.5, $service->drawHoursIfMoved($notes[2], null));
        $this->assertEquals(0, $service->drawHoursIfMoved($notes[1], null));
        $call->update(['billable_minutes' => 0]);
        $this->assertCount(0, $service->entries($this->ticket)->where('type', 'call'));
    }
}
