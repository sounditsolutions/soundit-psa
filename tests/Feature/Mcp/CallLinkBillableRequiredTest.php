<?php

namespace Tests\Feature\Mcp;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\PhoneCallService;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * link_call_to_ticket and create_ticket_from_call take a REQUIRED explicit
 * boolean `billable`: the caller's billing decision for the call. It is checked
 * before anything is read or written, applied through the same
 * PhoneCallActionService path set_call_billable uses (so prepay is
 * re-evaluated), echoed as is_billable, and folded into the audit hash. The
 * link, the billing apply and the audit row commit together or not at all.
 */
class CallLinkBillableRequiredTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Contract $contract;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();

        $actor = User::factory()->create(['name' => 'Synthetic AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        // Hours-prepay contract, as in tests/Feature/Prepay/CallBillableMoneyPathTest.php.
        $this->client = Client::create(['name' => 'Synthetic Billable Client']);
        $this->contract = Contract::create([
            'client_id' => $this->client->id, 'name' => 'Synthetic Billable Contract',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $this->ticket = Ticket::factory()->create([
            'client_id' => $this->client->id, 'contract_id' => $this->contract->id,
            'subject' => 'Synthetic billable ticket', 'status' => 'new',
        ]);
    }

    /** duration is not fillable, so it is force-filled (a create() would drop it). */
    private function makeCall(string $uuid): PhoneCall
    {
        $call = PhoneCall::create([
            'call_uuid' => $uuid,
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550188',
            'status' => CallStatus::Completed,
            'started_at' => now()->subMinutes(10),
        ]);
        $call->forceFill(['client_id' => $this->client->id, 'duration' => 1800])->save();

        return $call->fresh();
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'synthetic');
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    private function ok(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true);
    }

    private function refusal(TestResponse $response): string
    {
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), 'expected a refusal: '.json_encode($response->json()));

        $text = (string) $response->json('result.content.0.text');
        $decoded = json_decode($text, true);

        return is_array($decoded) && isset($decoded['error']) ? (string) $decoded['error'] : $text;
    }

    /** @return array<string, mixed> */
    private function arguments(string $verb, PhoneCall $call, string $reason = 'Synthetic reason'): array
    {
        return $verb === 'link_call_to_ticket'
            ? ['phone_call_id' => $call->id, 'ticket_id' => $this->ticket->id, 'reason' => $reason]
            : ['phone_call_id' => $call->id, 'reason' => $reason];
    }

    private function expectedHash(string $verb, int $callId, int $ticketId, bool $billable, string $reason): string
    {
        return hash('sha256', json_encode([
            'action' => $verb,
            'ticket_id' => $callId,
            'payload' => ['ticket_id' => $ticketId, 'billable' => $billable],
            'reason' => $reason,
        ]));
    }

    private function assertNothingWritten(PhoneCall $call, int $ticketsBefore): void
    {
        $this->assertNull($call->fresh()->ticket_id, 'the call must stay unlinked');
        $this->assertNull($call->fresh()->is_billable);
        $this->assertSame($ticketsBefore, Ticket::query()->count(), 'no ticket may be created');
        $this->assertSame(0, TechnicianActionLog::query()->count(), 'no audit row may be written');
        $this->assertSame(0, PrepayTransaction::query()->count());
        $this->assertEquals(10.0, (float) $this->contract->fresh()->prepay_balance);
    }

    /** @return array<string, array{0: string}> */
    public static function verbs(): array
    {
        return [
            'link_call_to_ticket' => ['link_call_to_ticket'],
            'create_ticket_from_call' => ['create_ticket_from_call'],
        ];
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function badBillable(): array
    {
        $cases = [];
        foreach (['link_call_to_ticket', 'create_ticket_from_call'] as $verb) {
            $cases["{$verb} missing"] = [$verb, []];
            $cases["{$verb} string true"] = [$verb, ['billable' => 'true']];
            $cases["{$verb} integer 1"] = [$verb, ['billable' => 1]];
            $cases["{$verb} null"] = [$verb, ['billable' => null]];
        }

        return $cases;
    }

    #[DataProvider('badBillable')]
    public function test_a_missing_or_non_boolean_billable_is_refused_and_nothing_is_linked(string $verb, array $extra): void
    {
        $call = $this->makeCall('synthetic-bad-'.md5($verb.json_encode($extra)));
        $ticketsBefore = Ticket::query()->count();

        $text = $this->refusal($this->callTool($this->token([$verb]), $verb, $this->arguments($verb, $call) + $extra));

        $this->assertSame('billable is required: an explicit boolean (true or false), the billing decision for this call. Nothing was linked.', $text);
        $this->assertNothingWritten($call, $ticketsBefore);
    }

    #[DataProvider('verbs')]
    public function test_billable_is_checked_before_every_other_argument(string $verb): void
    {
        // No reason, no call id: the billable refusal still comes first.
        $text = $this->refusal($this->callTool($this->token([$verb]), $verb, ['phone_call_id' => 999999]));

        $this->assertStringStartsWith('billable is required', $text);
        $this->assertSame(0, TechnicianActionLog::query()->count());
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function verbsAndDecisions(): array
    {
        return [
            'link_call_to_ticket true' => ['link_call_to_ticket', true],
            'link_call_to_ticket false' => ['link_call_to_ticket', false],
            'create_ticket_from_call true' => ['create_ticket_from_call', true],
            'create_ticket_from_call false' => ['create_ticket_from_call', false],
        ];
    }

    #[DataProvider('verbsAndDecisions')]
    public function test_billable_sets_is_billable_echoes_it_and_moves_prepay_like_set_call_billable(string $verb, bool $billable): void
    {
        $call = $this->makeCall('synthetic-verb-'.$verb.'-'.var_export($billable, true));
        $reason = 'Synthetic decision';

        $result = $this->ok($this->callTool($this->token([$verb]), $verb, $this->arguments($verb, $call, $reason) + ['billable' => $billable]));

        $this->assertSame($billable, $result['is_billable']);
        $this->assertSame($billable, (bool) $call->fresh()->is_billable);
        $this->assertNotNull($call->fresh()->is_billable);
        $linkedTicketId = (int) $result['ticket_id'];
        $this->assertSame($linkedTicketId, (int) $call->fresh()->ticket_id);

        $verbLedger = PrepayTransaction::where('phone_call_id', $call->id)->get(['contract_id', 'hours'])
            ->map(fn ($row) => [(int) $row->contract_id, (float) $row->hours])->all();
        $balanceAfterVerb = (float) $this->contract->fresh()->prepay_balance;

        // The billability apply ran through the set_call_billable service path.
        $applied = TechnicianActionLog::where('action_type', 'set_call_billable')->sole();
        $this->assertStringContainsString('billable='.($billable ? 'true' : 'false'), (string) $applied->summary);

        // Reference: an identical call linked natively to the same ticket, then
        // set_call_billable through MCP. The prepay ledger must match.
        $reference = $this->makeCall('synthetic-ref-'.$verb.'-'.var_export($billable, true));
        app(PhoneCallService::class)->linkCallToTicket($reference, $linkedTicketId);
        $this->ok($this->callTool($this->token(['set_call_billable:immediate']), 'set_call_billable', [
            'phone_call_id' => $reference->id, 'billable' => $billable, 'reason' => $reason,
        ]));
        $referenceLedger = PrepayTransaction::where('phone_call_id', $reference->id)->get(['contract_id', 'hours'])
            ->map(fn ($row) => [(int) $row->contract_id, (float) $row->hours])->all();

        $this->assertSame($referenceLedger, $verbLedger);
        $this->assertEquals(10.0 - (float) $this->contract->fresh()->prepay_balance, 2 * (10.0 - $balanceAfterVerb));
        $this->assertSame($billable ? [[$this->contract->id, -0.5]] : [], $verbLedger);

        // The audit hash binds the decision.
        $log = TechnicianActionLog::where('action_type', $verb)->sole();
        $this->assertSame($this->expectedHash($verb, (int) $call->id, $linkedTicketId, $billable, $reason), $log->content_hash);
        $this->assertNotSame($this->expectedHash($verb, (int) $call->id, $linkedTicketId, ! $billable, $reason), $log->content_hash);
    }

    #[DataProvider('verbs')]
    public function test_a_refused_billability_apply_rolls_the_link_back(string $verb): void
    {
        $call = $this->makeCall('synthetic-rollback-'.$verb);
        $ticketsBefore = Ticket::query()->count();
        $notesBefore = \App\Models\TicketNote::query()->count();

        // Engage the kill-switch the moment the call is linked: after the verb's
        // own guard has passed, before the billability apply reads it.
        PhoneCall::saved(function (PhoneCall $saved) use ($call): void {
            if ((int) $saved->id === (int) $call->id && $saved->ticket_id !== null) {
                Setting::setValue('technician_kill_switch', '1');
            }
        });

        $text = $this->refusal($this->callTool($this->token([$verb]), $verb, $this->arguments($verb, $call) + ['billable' => true]));

        $this->assertSame('Technician kill-switch engaged. Nothing was linked.', $text);
        $this->assertNothingWritten($call, $ticketsBefore);
        $this->assertSame($notesBefore, \App\Models\TicketNote::query()->count());
        $this->assertNull(Setting::getValue('technician_kill_switch'), 'the switch was set inside the rolled-back transaction');
    }

    #[DataProvider('verbs')]
    public function test_the_schema_requires_billable_and_tools_list_publishes_it(string $verb): void
    {
        $tool = $verb === 'link_call_to_ticket' ? McpToolRegistry::linkCallToTicketTool() : McpToolRegistry::createTicketFromCallTool();
        $this->assertContains('billable', $tool['input_schema']['required']);
        $this->assertSame('boolean', $tool['input_schema']['properties']['billable']['type']);

        $listed = collect($this->withHeaders(['Authorization' => 'Bearer '.$this->token([$verb])])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->assertOk()->json('result.tools'))->keyBy('name');
        $this->assertContains('billable', $listed[$verb]['inputSchema']['required']);
        $this->assertSame('boolean', $listed[$verb]['inputSchema']['properties']['billable']['type']);
    }
}
