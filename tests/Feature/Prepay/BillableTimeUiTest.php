<?php

namespace Tests\Feature\Prepay;

use App\Models\BillableTimeChange;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillableTimeUiTest extends TestCase
{
    use RefreshDatabase;

    private function entry(bool $call): TicketNote|PhoneCall
    {
        Http::fake();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $client = Client::create(['name' => 'Synthetic UI Client']);
        $contract = Contract::create(['client_id' => $client->id, 'name' => 'Synthetic Prepay', 'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01', 'prepay_as_amount' => false, 'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $contract->id]);
        if (! $call) {
            return TicketNote::create(['ticket_id' => $ticket->id, 'body' => 'Synthetic work', 'note_type' => 'note', 'time_minutes' => 120, 'is_billable' => true, 'is_private' => true, 'noted_at' => now()]);
        }
        $entry = PhoneCall::forceCreate(['call_uuid' => 'synthetic-ui-call', 'direction' => 'inbound', 'from_number' => '+15555550188', 'status' => 'completed', 'duration' => 7200, 'is_billable' => true, 'ticket_id' => $ticket->id, 'client_id' => $client->id, 'started_at' => now()]);
        app(PrepayService::class)->debitFromPhoneCall($entry);

        return $entry;
    }

    private function save(TicketNote|PhoneCall $entry, mixed $bill, array $extra = [])
    {
        $data = array_merge(['billable_minutes' => $bill, 'reason' => 'Synthetic billing change', 'is_billable' => '1'], $extra);
        if ($entry instanceof PhoneCall) {
            return $this->patchJson(route('calls.update-notes', $entry), $data + ['notes' => 'Synthetic edited call']);
        }

        return $this->putJson(route('tickets.notes.update', [$entry->ticket_id, $entry]), $data + ['body' => 'Synthetic edited note', 'note_type' => 'note', 'time' => '120', 'is_private' => true]);
    }

    public function test_note_parsing_and_repricing(): void
    {
        $this->parsing(false);
    }

    public function test_call_parsing_and_repricing(): void
    {
        $this->parsing(true);
    }

    public function test_note_lowered_actual_halo_and_nonbillable(): void
    {
        $note = $this->entry(false);
        $id = PrepayTransaction::where('ticket_note_id', $note->id)->sole()->id;
        $this->save($note, '60')->assertRedirect();
        $this->save($note, '60', ['time' => '45', 'reason' => null])->assertRedirect();
        $this->assertSame(60, $note->fresh()->billable_minutes);
        $this->assertSame(45, $note->fresh()->billedMinutes());
        $row = PrepayTransaction::where('ticket_note_id', $note->id)->sole();
        $this->assertSame($id, $row->id);
        $this->assertEquals(-0.75, $row->hours);
        $before = $note->fresh()->getAttributes();
        $this->save($note, '40', ['time' => '30'])->assertUnprocessable();
        $this->assertSame($before, $note->fresh()->getAttributes());
        $this->save($note, '20', ['is_billable' => '0'])->assertRedirect();
        $this->assertSame(0, PrepayTransaction::where('ticket_note_id', $note->id)->count());
        \Illuminate\Support\Facades\DB::table('ticket_notes')->where('id', $note->id)->update(['time_adjustment_minutes' => -10]);
        $count = BillableTimeChange::count();
        $this->save($note, '10')->assertUnprocessable()->assertJsonValidationErrors('billable_minutes');
        $this->assertSame($count, BillableTimeChange::count());
    }

    public function test_call_seconds_voicemail_and_nonbillable(): void
    {
        $call = $this->entry(true);
        $id = PrepayTransaction::where('phone_call_id', $call->id)->sole()->id;
        $call->forceFill(['duration' => 702])->save();
        $this->save($call, '12')->assertRedirect();
        $this->assertSame(702, $call->fresh()->billedSeconds());
        $row = PrepayTransaction::where('phone_call_id', $call->id)->sole();
        $this->assertSame($id, $row->id);
        $this->assertEquals(-round(702 / 3600, 4), $row->hours);
        $call->update(['status' => 'voicemail']);
        $this->save($call, '5')->assertRedirect();
        $this->assertSame(0, PrepayTransaction::where('phone_call_id', $call->id)->count());
        $call->update(['status' => 'completed']);
        $this->save($call, '4', ['is_billable' => '0'])->assertRedirect();
        $this->assertSame(0, PrepayTransaction::where('phone_call_id', $call->id)->count());
    }

    public function test_rendered_override_and_adjustment_only_held_badges(): void
    {
        $note = $this->entry(false);
        $this->get(route('tickets.show', $note->ticket_id))->assertOk()->assertDontSee('Billed 30m');
        $this->save($note, '30')->assertRedirect();
        $this->get(route('tickets.show', $note->ticket_id))->assertOk()->assertSee('Billed 30m');
        $this->save($note, '')->assertRedirect();
        \Illuminate\Support\Facades\DB::table('ticket_notes')->where('id', $note->id)->update(['time_minutes' => 0, 'time_adjustment_minutes' => 30, 'contract_held_at' => now()]);
        $this->get(route('tickets.show', $note->ticket_id))->assertOk()->assertSee('Not debited yet:')->assertDontSee('Billed 30m');
    }

    public function test_call_notes_only_save_keeps_override_without_reason(): void
    {
        $call = $this->entry(true);
        $this->save($call, '30')->assertRedirect();
        $count = BillableTimeChange::count();
        $this->patchJson(route('calls.update-notes', $call), ['notes' => 'Synthetic notes only'])->assertRedirect();
        $this->assertSame(30, $call->fresh()->billable_minutes);
        $this->assertSame('Synthetic notes only', $call->fresh()->notes);
        $this->assertSame($count, BillableTimeChange::count());
    }

    public function test_stale_call_instance_keeps_override_price(): void
    {
        $call = $this->entry(true);
        app(\App\Services\BillableTimeService::class)->setCall($call->fresh(), 30, 'Synthetic billing change', 'ui', null, 'Synthetic staff');
        app(PrepayService::class)->debitFromPhoneCall($call);
        $this->assertEquals(-0.5, PrepayTransaction::where('phone_call_id', $call->id)->sole()->hours);
    }

    public function test_refused_note_override_writes_nothing_and_sends_no_alert(): void
    {
        $note = $this->entry(false);
        \Illuminate\Support\Facades\DB::table('contracts')->where('id', $note->ticket->contract_id)->update(['prepay_alert_threshold' => 7.5]);
        $messages = [];
        \Illuminate\Support\Facades\Log::listen(function ($event) use (&$messages) {
            $messages[] = $event->message;
        });
        $before = $note->fresh()->getAttributes();
        $this->save($note, '200', ['time' => '150'])->assertUnprocessable()->assertJsonValidationErrors('billable_minutes');
        $this->assertSame($before, $note->fresh()->getAttributes());
        $this->assertEquals(-2.0, PrepayTransaction::where('ticket_note_id', $note->id)->sole()->hours);
        $this->assertNotContains('[PrepayAlert] Balance below threshold', $messages);
    }

    public function test_combined_note_edit_prices_once_without_interim_alert(): void
    {
        $note = $this->entry(false);
        $id = PrepayTransaction::where('ticket_note_id', $note->id)->sole()->id;
        \Illuminate\Support\Facades\DB::table('contracts')->where('id', $note->ticket->contract_id)->update(['prepay_alert_threshold' => 7.5]);
        $messages = [];
        \Illuminate\Support\Facades\Log::listen(function ($event) use (&$messages) {
            $messages[] = $event->message;
        });
        $this->save($note, '60', ['time' => '150'])->assertRedirect();
        $this->assertSame(150, $note->fresh()->time_minutes);
        $this->assertSame(60, $note->fresh()->billable_minutes);
        $row = PrepayTransaction::where('ticket_note_id', $note->id)->sole();
        $this->assertSame($id, $row->id);
        $this->assertEquals(-1.0, $row->hours);
        $this->assertNotContains('[PrepayAlert] Balance below threshold', $messages);
        // Positive control: clearing the Bill bills the full 150m, which reaches the threshold.
        $this->save($note, '', ['time' => '150'])->assertRedirect();
        $this->assertEquals(-2.5, PrepayTransaction::where('ticket_note_id', $note->id)->sole()->hours);
        $this->assertContains('[PrepayAlert] Balance below threshold', $messages);
    }

    public function test_ticking_call_billable_with_bill_debits_billed_time_without_interim_alert(): void
    {
        $call = $this->entry(true);
        $call->update(['is_billable' => false]);
        app(PrepayService::class)->debitFromPhoneCall($call);
        $this->assertSame(0, PrepayTransaction::where('phone_call_id', $call->id)->count());
        \Illuminate\Support\Facades\DB::table('contracts')->where('id', $call->ticket->contract_id)->update(['prepay_alert_threshold' => 9]);
        $messages = [];
        \Illuminate\Support\Facades\Log::listen(function ($event) use (&$messages) {
            $messages[] = $event->message;
        });
        $this->save($call, '5')->assertRedirect();
        $this->assertTrue($call->fresh()->is_billable);
        $this->assertSame(5, $call->fresh()->billable_minutes);
        $this->assertEquals(-round(5 / 60, 4), PrepayTransaction::where('phone_call_id', $call->id)->sole()->hours);
        $this->assertNotContains('[PrepayAlert] Balance below threshold', $messages);
        // Positive control: clearing the Bill bills the full two hours, which crosses the threshold.
        $this->save($call, '')->assertRedirect();
        $this->assertEquals(-2.0, PrepayTransaction::where('phone_call_id', $call->id)->sole()->hours);
        $this->assertContains('[PrepayAlert] Balance below threshold', $messages);
    }

    public function test_refused_bill_on_unverified_intake_note_saves_nothing(): void
    {
        $note = $this->entry(false);
        $intake = TicketNote::forceCreate(['ticket_id' => $note->ticket_id, 'body' => 'Synthetic form', 'note_type' => 'note', 'contact_intake_origin' => true, 'contact_intake_verified_at' => null, 'noted_at' => now()]);
        $before = $intake->fresh()->getAttributes();
        $this->save($intake, '30', ['time' => '60'])->assertUnprocessable()->assertJsonValidationErrors('billable_minutes');
        $this->assertSame($before, $intake->fresh()->getAttributes());
        $this->assertSame(0, BillableTimeChange::count());
    }

    private function parsing(bool $call): void
    {
        $entry = $this->entry($call);
        $key = $call ? 'phone_call_id' : 'ticket_note_id';
        $id = PrepayTransaction::where($key, $entry->id)->sole()->id;
        foreach (['1h 30m' => 90, '0' => 0, '0m' => 0, '' => null] as $input => $expected) {
            $this->save($entry, (string) $input)->assertRedirect();
            $this->assertSame($expected, $entry->fresh()->billable_minutes);
            if ($expected === 0) {
                $this->assertSame(0, PrepayTransaction::where($key, $entry->id)->count());
                $this->assertEquals(10, $entry->ticket->contract->fresh()->prepay_balance);
                $this->assertSame(2, BillableTimeChange::count());
            } else {
                $row = PrepayTransaction::where($key, $entry->id)->sole();
                if ($expected === 90) {
                    $this->assertSame($id, $row->id);
                }
                $this->assertEquals(-($expected ?? 120) / 60, $row->hours);
            }
            $this->assertSame($call ? 7200 : 120, $call ? $entry->fresh()->duration : $entry->fresh()->time_minutes);
        }
        foreach (['-5', 'abc', '121'] as $input) {
            $before = $entry->fresh()->getAttributes();
            $count = BillableTimeChange::count();
            $this->save($entry, $input)->assertUnprocessable()->assertJsonValidationErrors('billable_minutes');
            $this->assertSame($before, $entry->fresh()->getAttributes());
            $this->assertSame($count, BillableTimeChange::count());
        }
        $this->save($entry, '', ['reason' => null])->assertRedirect();
        $this->save($entry, '30', ['reason' => null])->assertUnprocessable()->assertJsonPath('errors.reason.0', 'The reason field is required.');
        $this->assertNull($entry->fresh()->billable_minutes);
        $this->save($entry, '30')->assertRedirect();
        $audit = BillableTimeChange::latest('id')->first();
        $this->assertSame('ui', $audit->source);
        $this->assertSame(auth()->id(), $audit->actor_user_id);
        $this->save($entry, '', ['reason' => null])->assertUnprocessable()->assertJsonPath('errors.reason.0', 'The reason field is required.');
        $this->assertSame(30, $entry->fresh()->billable_minutes);
        $count = BillableTimeChange::count();
        $this->save($entry, '30', ['reason' => null])->assertRedirect();
        $this->assertSame($count, BillableTimeChange::count());
    }
}
