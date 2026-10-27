<?php

namespace Tests\Feature\Prepay;

use App\Models\BillableTimeChange;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TimeEntryBillingProposal;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class BillableTimeDataLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_voicemail_and_nonpositive_stored_duration_never_debit_with_override(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $client = Client::create(['name' => 'Synthetic Billing Client']);
        $contract = Contract::create([
            'client_id' => $client->id, 'name' => 'Synthetic Prepay',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $contract->id]);
        foreach ([['completed', 600], ['voicemail', 600], ['completed', 0], ['completed', -1]] as $i => [$status, $duration]) {
            $call = PhoneCall::forceCreate([
                'call_uuid' => 'synthetic-billing-'.$i, 'direction' => 'inbound', 'from_number' => '+15555550188',
                'status' => $status, 'duration' => $duration, 'recording_duration' => 600,
                'ticket_id' => $ticket->id, 'is_billable' => true, 'billable_minutes' => 5,
                'started_at' => now(),
            ]);
            app(PrepayService::class)->debitFromPhoneCall($call);
            $this->assertSame($i === 0 ? 1 : 0, PrepayTransaction::where('phone_call_id', $call->id)->count());
            $this->assertSame($duration, (int) $call->fresh()->duration);
            $this->assertSame(5, $call->fresh()->billable_minutes);
        }
    }

    public function test_audit_insert_and_proposal_reason_round_trip(): void
    {
        $audit = BillableTimeChange::create([
            'entry_type' => 'note', 'entry_id' => 123, 'old_billable_minutes' => null,
            'new_billable_minutes' => 0, 'reason' => 'Synthetic correction',
            'source' => 'ui', 'actor_label' => 'Synthetic staff',
        ]);
        $this->assertNull($audit->fresh()->old_billable_minutes);
        $this->assertSame(0, $audit->fresh()->new_billable_minutes);
        $proposal = TimeEntryBillingProposal::create([
            'entry_type' => 'note', 'entry_id' => 123, 'billable_minutes' => null,
            'reason' => 'Synthetic held request', 'actor_label' => 'Synthetic agent',
        ])->fresh();
        $this->assertSame('pending', $proposal->state);
        $this->assertNull($proposal->billable_minutes);
        $this->assertSame('Synthetic held request', $proposal->reason);
        $this->assertArrayNotHasKey('reason', $proposal->toArray());
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $audit->update(['reason' => 'Changed']) : $audit->delete();
                $this->fail('Audit mutation was accepted');
            } catch (LogicException) {
                $this->assertSame('Synthetic correction', $audit->fresh()->reason);
            }
        }
    }
}
