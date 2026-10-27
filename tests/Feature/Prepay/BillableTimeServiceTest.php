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
use App\Services\BillableTimeService;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BillableTimeServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $this->actor = User::factory()->create();
        $client = Client::create(['name' => 'Synthetic Billing Client']);
        $contract = Contract::create([
            'client_id' => $client->id, 'name' => 'Synthetic Prepay', 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01', 'prepay_as_amount' => false,
            'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $this->ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $contract->id]);
    }

    private function entry(bool $call): TicketNote|PhoneCall
    {
        if ($call) {
            $entry = PhoneCall::forceCreate([
                'call_uuid' => 'synthetic-'.uniqid(), 'from_number' => '+15555550188', 'direction' => 'inbound',
                'status' => 'completed', 'duration' => 3600, 'ticket_id' => $this->ticket->id,
                'is_billable' => true, 'started_at' => now(),
            ]);
            app(PrepayService::class)->debitFromPhoneCall($entry);

            return $entry;
        }

        return TicketNote::create([
            'ticket_id' => $this->ticket->id, 'author_id' => $this->actor->id,
            'body' => 'Synthetic work', 'note_type' => 'note', 'is_private' => true,
            'time_minutes' => 60, 'is_billable' => true, 'noted_at' => now(),
        ]);
    }

    private function set(TicketNote|PhoneCall $entry, ?int $minutes, ?string $reason = 'Synthetic correction', string $source = 'ui'): void
    {
        $service = app(BillableTimeService::class);
        $entry instanceof TicketNote
            ? $service->setNote($entry, $minutes, $reason, $source, $this->actor, 'Synthetic actor')
            : $service->setCall($entry, $minutes, $reason, $source, $this->actor, 'Synthetic actor');
    }

    private function row(TicketNote|PhoneCall $entry)
    {
        return PrepayTransaction::where($entry instanceof TicketNote ? 'ticket_note_id' : 'phone_call_id', $entry->id);
    }

    private function refused(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected validation refusal');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_set_clear_zero_and_nonbillable_reprice_without_duplicate_debit(): void
    {
        foreach ([false, true] as $call) {
            $entry = $this->entry($call);
            $id = $this->row($entry)->sole()->id;
            foreach ([30 => -0.5, 15 => -0.25] as $minutes => $hours) {
                $this->set($entry, $minutes);
                $this->assertSame(1, $this->row($entry)->count());
                $this->assertSame($id, $this->row($entry)->sole()->id);
                $this->assertEquals($hours, $this->row($entry)->sole()->hours);
            }
            $this->set($entry, null);
            $this->assertEquals(-1, $this->row($entry)->sole()->hours);
            $this->set($entry, 0);
            $this->assertSame(0, $this->row($entry)->count());
            $entry->refresh()->update(['is_billable' => false]);
            $this->set($entry, 20);
            $this->assertSame(0, $this->row($entry)->count());
            $this->assertSame($call ? 3600 : 60, (int) $entry->fresh()->getAttribute($call ? 'duration' : 'time_minutes'));
        }
    }

    public function test_caps_and_reason_refuse_without_writes_and_unchanged_needs_no_reason(): void
    {
        foreach ([false, true] as $call) {
            $entry = $this->entry($call);
            $before = BillableTimeChange::count();
            foreach ([-1, 61] as $minutes) {
                $this->refused(fn () => $this->set($entry, $minutes), 'billable_minutes');
            }
            foreach (['', str_repeat('x', 801)] as $reason) {
                $this->refused(fn () => $this->set($entry, 30, $reason), 'reason');
            }
            $this->assertNull($entry->fresh()->billable_minutes);
            $this->assertSame($before, BillableTimeChange::count());
            $this->assertEquals(-1, $this->row($entry)->sole()->hours);
            $this->set($entry, 30);
            $this->refused(fn () => $this->set($entry, null, ''), 'reason');
            $this->assertSame(30, $entry->fresh()->billable_minutes);
            $this->set($entry, 30, null);
            $this->assertSame($before + 1, BillableTimeChange::count());
            $this->set($entry, null);
            $audit = BillableTimeChange::latest('id')->first();
            $this->assertSame(30, $audit->old_billable_minutes);
            $this->assertNull($audit->new_billable_minutes);
            $this->assertSame('ui', $audit->source);
            $this->assertSame($this->actor->id, $audit->actor_user_id);
            $this->assertSame('Synthetic actor', $audit->actor_label);
        }
    }

    public function test_halo_negative_adjustment_refuses_ui_and_mcp(): void
    {
        $entry = $this->entry(false);
        DB::table('ticket_notes')->where('id', $entry->id)->update(['time_adjustment_minutes' => -10]);
        foreach (['ui', 'mcp'] as $source) {
            $this->refused(fn () => $this->set($entry, 20, 'Synthetic reason', $source), 'billable_minutes');
        }
        $this->assertNull($entry->fresh()->billable_minutes);
        $this->assertSame(0, BillableTimeChange::count());
    }

    public function test_call_ceiling_and_never_debit_guards(): void
    {
        $entry = $this->entry(true);
        $entry->duration = 702;
        $entry->save();
        $this->set($entry, 12);
        $this->assertEquals(-round(702 / 3600, 4), $this->row($entry)->sole()->hours);
        $this->assertSame(702, $entry->fresh()->billedSeconds());
        foreach ([['voicemail', 702], ['completed', 0], ['completed', -1]] as [$status, $duration]) {
            $entry->refresh()->forceFill(['status' => $status, 'duration' => $duration, 'recording_duration' => 702])->save();
            $this->set($entry, 5);
            $this->assertSame(0, $this->row($entry)->count());
            $this->set($entry, null);
        }
        $entry->refresh()->forceFill(['duration' => null, 'recording_duration' => null])->save();
        $this->refused(fn () => $this->set($entry, 1), 'billable_minutes');
        $this->set($entry, 0);
        $this->assertSame(0, $this->row($entry)->count());
    }

    public function test_staged_approval_passes_reason_once_without_transport_or_application_log_text(): void
    {
        $messages = [];
        Log::listen(function ($event) use (&$messages) {
            $messages[] = [$event->level, $event->message, $event->context];
        });
        foreach ([false, true] as $call) {
            $entry = $this->entry($call);
            $id = $this->row($entry)->sole()->id;
            $reason = 'Synthetic held billing reason '.($call ? 'call' : 'note');
            $service = app(BillableTimeService::class);
            $before = BillableTimeChange::count();
            $proposal = $service->propose($entry, 20, $reason, $this->actor, 'Synthetic agent');
            $this->assertSame($before, BillableTimeChange::count());
            $service->approve($proposal, $this->actor);
            $this->assertSame($before + 1, BillableTimeChange::count());
            $audit = BillableTimeChange::latest('id')->first();
            $this->assertSame($reason, $audit->reason);
            $this->assertSame('mcp', $audit->source);
            $this->assertNull($audit->old_billable_minutes);
            $this->assertSame(20, $audit->new_billable_minutes);
            $this->assertSame($id, $this->row($entry)->sole()->id);
            $this->assertEquals(-round(20 / 60, 4), $this->row($entry)->sole()->hours);
            $this->refused(fn () => $service->approve($proposal, $this->actor), 'proposal');
            $this->assertSame($before + 1, BillableTimeChange::count());
            $this->assertStringNotContainsString($reason, json_encode(DB::table('mcp_audit_logs')->get()));
            $this->assertStringNotContainsString($reason, json_encode($messages));
        }
    }

    public function test_staged_approval_rechecks_cap_and_halo_and_reject_expire_write_no_audit(): void
    {
        $service = app(BillableTimeService::class);
        $entry = $this->entry(false);
        $proposal = $service->propose($entry, 30, 'Synthetic request', $this->actor, 'Synthetic agent');
        DB::table('ticket_notes')->where('id', $entry->id)->update(['time_minutes' => 20]);
        $this->refused(fn () => $service->approve($proposal, $this->actor), 'billable_minutes');
        DB::table('ticket_notes')->where('id', $entry->id)->update(['time_minutes' => 60, 'time_adjustment_minutes' => -5]);
        $this->refused(fn () => $service->approve($proposal, $this->actor), 'billable_minutes');
        $this->assertTrue($service->reject($proposal, $this->actor));
        $this->refused(fn () => $service->approve($proposal, $this->actor), 'proposal');
        DB::table('ticket_notes')->where('id', $entry->id)->update(['time_adjustment_minutes' => 0]);
        $expired = $service->propose($entry, 30, 'Synthetic expiry', $this->actor, 'Synthetic agent');
        $expired->update(['expires_at' => now()->subMinute()]);
        $this->refused(fn () => $service->approve($expired, $this->actor), 'proposal');
        $this->assertTrue($service->expire($expired));
        $this->assertSame('expired', $expired->fresh()->state);
        $this->assertNull($entry->fresh()->billable_minutes);
        $this->assertSame(0, BillableTimeChange::count());
    }

    public function test_invalid_attribution_refuses_without_write(): void
    {
        $entry = $this->entry(false);
        $service = app(BillableTimeService::class);
        foreach ([['invalid', 'Actor'], ['ui', ''], ['mcp', str_repeat('x', 256)]] as [$source, $label]) {
            $this->refused(fn () => $service->setNote($entry, 30, 'Synthetic reason', $source, $this->actor, $label), 'source');
        }
        $this->assertNull($entry->fresh()->billable_minutes);
        $this->assertSame(0, BillableTimeChange::count());
    }

    public function test_clear_alone_requires_reason(): void
    {
        $entry = $this->entry(true);
        $this->set($entry, 30);
        $this->refused(fn () => $this->set($entry, null, ''), 'reason');
        $this->assertSame(30, $entry->fresh()->billable_minutes);
        $this->assertSame(1, BillableTimeChange::count());
        $this->assertEquals(-0.5, $this->row($entry)->sole()->hours);
    }

    public function test_reprice_failure_rolls_back_override_and_audit(): void
    {
        $entry = $this->entry(true);
        $prepay = $this->mock(PrepayService::class);
        $prepay->shouldReceive('debitFromPhoneCall')->once()->andThrow(new \RuntimeException('Synthetic failure'));
        try {
            $this->set($entry, 30);
            $this->fail('Expected injected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic failure', $e->getMessage());
        }
        $this->assertNull($entry->fresh()->billable_minutes);
        $this->assertSame(0, BillableTimeChange::count());
        $this->assertEquals(-1, $this->row($entry)->sole()->hours);
    }
}
