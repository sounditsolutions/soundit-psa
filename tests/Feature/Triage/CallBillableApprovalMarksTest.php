<?php

namespace Tests\Feature\Triage;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PhoneCallActionProposal;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\PhoneCallService;
use App\Services\Triage\TriagePipeline;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

/**
 * #8459: a set_call_billable proposal staged before the deploy and approved after it is a
 * decision like any other apply. Approving it marks the call is_billable_explicit, whether
 * the approved value changes is_billable or matches it, and triage reconciliation then
 * leaves the value and its prepay debit alone. Synthetic data only (G-13).
 */
class CallBillableApprovalMarksTest extends TestCase
{
    use RefreshDatabase;

    private Contract $contract;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        Setting::setValue('triage_auto_new_tickets', '0');

        $actor = User::factory()->create(['name' => 'Synthetic AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $client = Client::create(['name' => 'Synthetic Approval Client']);
        $this->contract = Contract::create([
            'client_id' => $client->id, 'name' => 'Synthetic Approval Prepay',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $this->ticket = Ticket::factory()->create([
            'client_id' => $client->id, 'contract_id' => $this->contract->id,
            'subject' => 'Synthetic approval ticket', 'status' => 'new',
        ]);
    }

    /** A completed 30-minute call linked with the native default (billable, not explicit). */
    private function linkedCall(): PhoneCall
    {
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-approval-'.uniqid(), 'direction' => CallDirection::Inbound->value,
            'from_number' => '+15555550188', 'status' => CallStatus::Completed->value,
            'client_id' => $this->ticket->client_id, 'is_billable' => null, 'duration' => 1800,
            'started_at' => now()->subMinutes(40),
        ]))->fresh();
        app(PhoneCallService::class)->linkCallToTicket($call, $this->ticket->id);

        return $call->fresh();
    }

    /** Stage set_call_billable on a :staged grant; returns the pending proposal id. */
    private function stage(PhoneCall $call, bool $billable): int
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['set_call_billable:staged'], label: 'synthetic-approval');
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'set_call_billable', 'arguments' => [
                'phone_call_id' => $call->id, 'billable' => $billable, 'reason' => 'Synthetic staged decision',
            ]],
        ]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $result = json_decode((string) $response->json('result.content.0.text'), true);
        $this->assertTrue($result['staged']);
        $this->assertFalse($call->fresh()->is_billable_explicit, 'staging alone is not yet a decision');

        return (int) $result['proposal_id'];
    }

    private function approve(int $proposalId): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->postJson(route('phone-call-actions.approve', $proposalId))->assertOk();
        $this->assertSame('done', PhoneCallActionProposal::findOrFail($proposalId)->state);
    }

    private function reconcile(bool $managedCovered): void
    {
        $pipeline = app(TriagePipeline::class);
        $ref = new ReflectionClass($pipeline);
        $ref->getProperty('stageResults')->setValue($pipeline, ['classification' => ['work_covered_by_managed' => $managedCovered]]);
        $ref->getMethod('reconcileBillabilityAfterClassification')->invoke($pipeline, $this->ticket->fresh());
    }

    private function assertNetDebit(PhoneCall $call, float $hours): void
    {
        $this->assertEquals(-$hours, (float) PrepayTransaction::where('phone_call_id', $call->id)->sum('hours'));
        $this->assertEquals(10 - $hours, (float) $this->contract->fresh()->prepay_balance);
    }

    public function test_approving_a_staged_change_marks_the_call_and_triage_leaves_it(): void
    {
        $call = $this->linkedCall();
        $this->assertTrue($call->is_billable);
        $this->assertNetDebit($call, 0.5);

        $this->approve($this->stage($call, false));

        $this->assertFalse($call->fresh()->is_billable);
        $this->assertTrue($call->fresh()->is_billable_explicit, 'an approved proposal is an explicit decision');
        $this->assertNetDebit($call, 0.0);

        // Not managed-covered would make an unmarked call billable again.
        $this->reconcile(false);

        $this->assertFalse($call->fresh()->is_billable, 'triage must not overturn an approved decision');
        $this->assertNetDebit($call, 0.0);
    }

    public function test_approving_a_staged_unchanged_value_still_marks_the_call_and_triage_leaves_it(): void
    {
        $call = $this->linkedCall();
        $this->assertTrue($call->is_billable);
        $this->assertFalse($call->is_billable_explicit);

        $this->approve($this->stage($call, true));

        $this->assertTrue($call->fresh()->is_billable);
        $this->assertTrue($call->fresh()->is_billable_explicit, 'an approved unchanged value is still a decision');
        $this->assertNetDebit($call, 0.5);

        // Managed-covered would make an unmarked call non-billable and reverse its debit.
        $this->reconcile(true);

        $this->assertTrue($call->fresh()->is_billable);
        $this->assertNetDebit($call, 0.5);
    }
}
