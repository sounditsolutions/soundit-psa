<?php

namespace Tests\Feature\Mcp;

use App\Models\BillableTimeChange;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\TimeEntryBillingProposal;
use App\Models\User;
use App\Services\BillableTimeService;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BillableTimeToolTest extends TestCase
{
    use RefreshDatabase;

    private function note(): TicketNote
    {
        Http::fake();
        Http::preventStrayRequests();
        $client = Client::create(['name' => 'Synthetic MCP Client']);
        $contract = Contract::create([
            'client_id' => $client->id, 'name' => 'Synthetic Prepay', 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01', 'prepay_as_amount' => false,
            'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $contract->id]);

        return TicketNote::create(['ticket_id' => $ticket->id, 'body' => 'Synthetic work', 'note_type' => 'note',
            'time_minutes' => 60, 'is_billable' => true, 'is_private' => true, 'noted_at' => now()]);
    }

    private function invoke(?array $grants, TicketNote $note, array $changes = [], string $name = 'set_billable_time')
    {
        $credential = McpConfig::rotateStaffToken(allowedTools: $grants);

        return $this->withHeaders(['Authorization' => 'Bearer '.$credential])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => array_merge([
                'entry_type' => 'note', 'entry_id' => $note->id, 'billable_minutes' => 30,
                'reason' => 'DISTINCTIVE SYNTHETIC BILLING REASON',
            ], $changes)],
        ]);
    }

    public function test_cockpit_approve_deny_cap_and_permissions(): void
    {
        $note = $this->note();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $service = app(BillableTimeService::class);
        $proposal = $service->propose($note, 30, 'Synthetic cockpit request', null, 'Synthetic agent');
        $id = PrepayTransaction::where('ticket_note_id', $note->id)->sole()->id;
        $this->actingAs($admin)->post(route('time-entry-billing.approve', $proposal))->assertRedirect();
        $this->assertSame(1, BillableTimeChange::count());
        $row = PrepayTransaction::where('ticket_note_id', $note->id)->sole();
        $this->assertSame($id, $row->id);
        $this->assertEquals(-0.5, $row->hours);
        $denied = $service->propose($note, 20, 'Synthetic denial', null, 'Synthetic agent');
        $this->post(route('time-entry-billing.deny', $denied))->assertRedirect();
        $this->assertSame('rejected', $denied->fresh()->state);
        $this->assertSame(1, BillableTimeChange::count());
        $capped = $service->propose($note, 40, 'Synthetic cap', null, 'Synthetic agent');
        $note->refresh()->update(['time_minutes' => 10]);
        $this->postJson(route('time-entry-billing.approve', $capped))->assertStatus(422);
        $this->assertSame('pending', $capped->fresh()->state);
        $this->assertSame(1, BillableTimeChange::count());
        $inactive = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        foreach (['approve', 'deny'] as $action) {
            $this->actingAs($inactive)->postJson(route('time-entry-billing.'.$action, $capped))->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->postJson(route('time-entry-billing.approve', $capped))->assertUnauthorized();
    }

    public function test_call_immediate_staged_and_all_four_read_payloads(): void
    {
        $note = $this->note();
        $ticket = $note->ticket;
        $call = \App\Models\PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-billing-read', 'direction' => 'inbound', 'from_number' => '+15555550188',
            'status' => 'completed', 'duration' => 702, 'is_billable' => true,
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id, 'started_at' => now(),
        ]);
        app(\App\Services\PrepayService::class)->debitFromPhoneCall($call);
        $id = PrepayTransaction::where('phone_call_id', $call->id)->sole()->id;
        $args = ['entry_type' => 'call', 'entry_id' => $call->id, 'billable_minutes' => 5];
        $this->assertFalse($this->invoke(['set_billable_time:immediate'], $note, $args)->json('result.isError'));
        $this->assertSame(1, BillableTimeChange::count());
        $this->assertEquals(-round(5 / 60, 4), PrepayTransaction::where('phone_call_id', $call->id)->sole()->hours);
        $args['billable_minutes'] = 12;
        $this->assertFalse($this->invoke(['set_billable_time:staged'], $note, $args)->json('result.isError'));
        app(BillableTimeService::class)->approve(TimeEntryBillingProposal::sole(), User::factory()->create());
        $row = PrepayTransaction::where('phone_call_id', $call->id)->sole();
        $this->assertSame($id, $row->id);
        $this->assertEquals(-round(702 / 3600, 4), $row->hours);
        $this->assertSame(2, BillableTimeChange::count());
        $this->assertSame('mcp', BillableTimeChange::latest('id')->first()->source);
        app(BillableTimeService::class)->setNote($note, 20, 'Synthetic read', 'ui', null, 'Synthetic staff');
        $reader = new \App\Services\Assistant\AssistantToolExecutor(clientId: $ticket->client_id);
        $phone = $reader->execute('get_phone_call', ['phone_call_id' => $call->id]);
        $this->assertSame(12, $phone['phone_call']['billable_minutes']);
        $this->assertEquals(11.7, $phone['phone_call']['billed_minutes']);
        foreach (['get_ticket_detail', 'get_ticket_calls'] as $tool) {
            $result = $reader->execute($tool, ['ticket_id' => $ticket->id]);
            $this->assertSame(12, $result['calls'][0]['billable_minutes'], json_encode($result));
            $this->assertEquals(11.7, $result['calls'][0]['billed_minutes']);
        }
        $notes = $reader->execute('get_ticket_notes', ['ticket_id' => $ticket->id]);
        $this->assertSame(20, $notes[0]['billable_minutes'], json_encode($notes));
        $this->assertSame(20, $notes[0]['billed_minutes']);
    }

    public function test_grant_matrix_and_immediate_reprice(): void
    {
        $note = $this->note();
        $id = PrepayTransaction::where('ticket_note_id', $note->id)->sole()->id;
        foreach ([null, ['get_ticket']] as $grants) {
            $response = $this->invoke($grants, $note);
            $this->assertTrue($response->json('error') !== null || $response->json('result.isError') === true);
            $this->assertSame(0, TimeEntryBillingProposal::count());
            $this->assertSame(0, BillableTimeChange::count());
        }
        foreach (['set_billable_time', 'set_billable_time:staged', 'stage_set_billable_time'] as $grant) {
            $response = $this->invoke([$grant], $note)->assertOk();
            $this->assertFalse($response->json('result.isError'), json_encode($response->json()));
            $this->assertNull($note->fresh()->billable_minutes);
        }
        $this->assertSame(3, TimeEntryBillingProposal::count());
        $this->assertSame(0, BillableTimeChange::count());
        $response = $this->invoke(['set_billable_time:immediate'], $note)->assertOk();
        $this->assertFalse($response->json('result.isError'), json_encode($response->json()));
        $row = PrepayTransaction::where('ticket_note_id', $note->id)->sole();
        $this->assertSame($id, $row->id);
        $this->assertEquals(-0.5, $row->hours);
        $this->assertSame(1, BillableTimeChange::count());
        $this->assertSame('mcp', BillableTimeChange::sole()->source);
    }

    public function test_invalid_params_and_halo_leave_no_write(): void
    {
        $note = $this->note();
        foreach ([['billable_minutes' => 61], ['reason' => null], ['billable_minutes' => '30']] as $changes) {
            $this->assertSame(-32602, $this->invoke(['set_billable_time:immediate'], $note, $changes)->json('error.code'));
            $this->assertNull($note->fresh()->billable_minutes);
            $this->assertSame(0, BillableTimeChange::count());
        }
        DB::table('ticket_notes')->where('id', $note->id)->update(['time_adjustment_minutes' => -5]);
        $this->assertSame(-32602, $this->invoke(['set_billable_time:immediate'], $note)->json('error.code'));
        $this->assertSame(0, BillableTimeChange::count());
    }

    public function test_kill_switch_refuses_immediate_staged_and_approval(): void
    {
        $note = $this->note();
        $proposal = app(BillableTimeService::class)->propose($note, 30, 'Synthetic pending request', null, 'Synthetic agent');
        \App\Models\Setting::setValue('technician_kill_switch', '1');
        foreach (['set_billable_time:immediate', 'set_billable_time:staged'] as $grant) {
            $response = $this->invoke([$grant], $note)->assertOk();
            $this->assertTrue($response->json('result.isError'));
            $this->assertStringContainsString('Technician kill-switch engaged.', json_encode($response->json()));
        }
        try {
            app(BillableTimeService::class)->propose($note, 20, 'Synthetic request', null, 'Synthetic agent');
            $this->fail('Expected kill-switch refusal');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(['Technician kill-switch engaged.'], $e->errors()['source']);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]))
            ->postJson(route('time-entry-billing.approve', $proposal))->assertStatus(422)
            ->assertJsonPath('errors.source.0', 'Technician kill-switch engaged.');
        $this->assertSame('pending', $proposal->fresh()->state);
        $this->assertSame(1, TimeEntryBillingProposal::count());
        $this->assertSame(0, BillableTimeChange::count());
        $this->assertNull($note->fresh()->billable_minutes);
    }

    public function test_transport_redaction_and_staged_approval_revalidation(): void
    {
        $messages = [];
        Log::listen(function ($event) use (&$messages) {
            $messages[] = [$event->level, $event->message, $event->context];
        });
        $note = $this->note();
        $this->invoke(['set_billable_time:immediate'], $note)->assertOk();
        $this->invoke(['set_billable_time:staged'], $note, ['billable_minutes' => 20])->assertOk();
        $proposal = TimeEntryBillingProposal::sole();
        app(BillableTimeService::class)->approve($proposal, User::factory()->create());
        $this->assertSame(2, BillableTimeChange::count());
        $this->assertSame($proposal->reason, BillableTimeChange::latest('id')->first()->reason);
        $this->assertGreaterThanOrEqual(2, DB::table('mcp_audit_logs')->count());
        $this->assertStringNotContainsString($proposal->reason, json_encode(DB::table('mcp_audit_logs')->get()));
        $this->assertStringNotContainsString($proposal->reason, json_encode($messages));
        $this->invoke(['set_billable_time:staged'], $note, ['billable_minutes' => 40])->assertOk();
        $note->refresh()->update(['time_minutes' => 10]);
        try {
            app(BillableTimeService::class)->approve(TimeEntryBillingProposal::latest('id')->first(), User::factory()->create());
            $this->fail('Expected approval cap refusal');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(422, $e->status);
        }
        $this->assertSame(2, BillableTimeChange::count());
    }
}
