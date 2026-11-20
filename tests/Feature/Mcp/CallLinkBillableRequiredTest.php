<?php

namespace Tests\Feature\Mcp;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\Sku;
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
 * right after the kill-switch guard and before the reason, the call or the
 * ticket is looked at; then the reason is capped at the set_call_billable limit
 * (800 characters). The decision is applied through the same
 * PhoneCallActionService path set_call_billable uses, echoed as is_billable
 * (read back from the database), and folded into the audit hash. With
 * billable=false the link itself takes no prepay debit. The link, the billing
 * apply and the audit row commit together or not at all.
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
    public function test_billable_is_checked_before_the_reason_and_the_call_lookup(string $verb): void
    {
        // No reason, an unknown call id: the billable refusal still comes first.
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
        $this->assertSame(800, $tool['input_schema']['properties']['reason']['maxLength']);
        $this->assertSame(800, $listed[$verb]['inputSchema']['properties']['reason']['maxLength']);

        // billable always overrides the native default, and with billable=false the
        // link takes no debit, so the text must not promise either older behaviour.
        foreach ([$tool['description'], $tool['input_schema']['properties']['billable']['description']] as $text) {
            $this->assertStringNotContainsString('sets billability when already known', $text);
            $this->assertStringNotContainsString('exactly as set_call_billable', $text);
        }
        $this->assertStringContainsString('With billable=false the link takes no prepay debit', $tool['description']);
    }

    #[DataProvider('verbs')]
    public function test_the_description_says_triage_does_not_change_the_explicit_decision(string $verb): void
    {
        // #8459 (b): the #8453 clause saying triage can still change is_billable is false and
        // gone; the replacement sentence (proposed in review, pinned once ruled) is pinned verbatim,
        // on the registry entry and on tools/list.
        $old = 'can still change is_billable';
        $clause = 'The billable value you set here is an explicit decision. Automatic or re-run triage of the ticket does not change it.';
        $tool = $verb === 'link_call_to_ticket' ? McpToolRegistry::linkCallToTicketTool() : McpToolRegistry::createTicketFromCallTool();
        $this->assertStringNotContainsString($old, $tool['description']);
        $this->assertStringEndsWith(' '.$clause, $tool['description']);

        $listed = collect($this->withHeaders(['Authorization' => 'Bearer '.$this->token([$verb])])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->assertOk()->json('result.tools'))->keyBy('name');
        $this->assertSame($tool['description'], $listed[$verb]['description']);
        $this->assertStringNotContainsString($old, $listed[$verb]['description']);
    }

    #[DataProvider('verbs')]
    public function test_an_801_character_reason_is_refused_before_anything_is_written(string $verb): void
    {
        $call = $this->makeCall('synthetic-reason-801-'.$verb);
        $ticketsBefore = Ticket::query()->count();
        $notesBefore = \App\Models\TicketNote::query()->count();

        $text = $this->refusal($this->callTool($this->token([$verb]), $verb, $this->arguments($verb, $call, str_repeat('r', 801)) + ['billable' => true]));

        $this->assertSame('reason must be at most 800 characters (the set_call_billable reason limit). Nothing was linked.', $text);
        $this->assertNothingWritten($call, $ticketsBefore);
        $this->assertSame($notesBefore, \App\Models\TicketNote::query()->count());
    }

    #[DataProvider('verbs')]
    public function test_an_800_character_reason_is_accepted(string $verb): void
    {
        $call = $this->makeCall('synthetic-reason-800-'.$verb);
        // 800 characters, 1600 bytes: the limit is counted in characters.
        $reason = str_repeat('é', 800);

        $result = $this->ok($this->callTool($this->token([$verb]), $verb, $this->arguments($verb, $call, $reason) + ['billable' => true]));

        $this->assertTrue($result['is_billable']);
        $this->assertNotNull($call->fresh()->ticket_id);
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'set_call_billable')->count());
    }

    #[DataProvider('verbs')]
    public function test_the_kill_switch_refusal_comes_before_the_billable_refusal(string $verb): void
    {
        $call = $this->makeCall('synthetic-order-'.$verb);
        $ticketsBefore = Ticket::query()->count();
        Setting::setValue('technician_kill_switch', '1');

        // billable is missing: the kill-switch refusal still wins.
        $text = $this->refusal($this->callTool($this->token([$verb]), $verb, $this->arguments($verb, $call)));

        $this->assertStringStartsWith('Technician kill-switch engaged', $text);
        $this->assertStringNotContainsString('billable', $text);
        $this->assertNothingWritten($call, $ticketsBefore);
    }

    #[DataProvider('verbs')]
    public function test_the_result_reports_the_stored_is_billable_not_the_argument(string $verb): void
    {
        $call = $this->makeCall('synthetic-readback-'.$verb);

        // Another write lands after the apply and before the verb reads the call
        // back: the result must report what is stored, not echo the argument.
        TechnicianActionLog::created(function (TechnicianActionLog $log) use ($verb, $call): void {
            if ($log->action_type === $verb) {
                PhoneCall::whereKey($call->id)->update(['is_billable' => true]);
            }
        });

        $result = $this->ok($this->callTool($this->token([$verb]), $verb, $this->arguments($verb, $call) + ['billable' => false]));

        $this->assertTrue($result['is_billable']);
    }

    #[DataProvider('verbs')]
    public function test_a_failure_after_the_billability_apply_moved_prepay_rolls_everything_back(string $verb): void
    {
        // A stored recording keeps the native link from defaulting billability, so
        // the link takes no debit and the set_call_billable apply makes it.
        $call = $this->makeCall('synthetic-late-failure-'.$verb);
        $call->forceFill(['recording_url' => 'https://example.test/recording.mp3'])->save();
        $ticketsBefore = Ticket::query()->count();
        $notesBefore = \App\Models\TicketNote::query()->count();

        $seen = [];
        TechnicianActionLog::creating(function (TechnicianActionLog $log) use ($verb, $call, &$seen): void {
            if ($log->action_type === 'set_call_billable') {
                $seen['apply_debits'] = PrepayTransaction::where('phone_call_id', $call->id)->count();
            }
            if ($log->action_type === $verb) {
                $seen['linked'] = PhoneCall::whereKey($call->id)->value('ticket_id') !== null;
                $seen['apply_status'] = TechnicianActionLog::where('action_type', 'set_call_billable')->value('result_status');
                throw new \LogicException('synthetic failure after the apply');
            }
        });

        $response = $this->callTool($this->token([$verb]), $verb, $this->arguments($verb, $call) + ['billable' => true]);

        $this->assertStringContainsString('synthetic failure after the apply', $this->refusal($response));
        // "executed": the apply ran setBillable itself (the link left the call unset),
        // so the debit counted at its audit row is the apply's, not the native link's.
        $this->assertSame(['apply_debits' => 1, 'linked' => true, 'apply_status' => 'executed'], $seen, 'the service had linked the call and the apply had debited it before the failure');
        $this->assertNothingWritten($call, $ticketsBefore);
        $this->assertSame($notesBefore, \App\Models\TicketNote::query()->count());
    }

    #[DataProvider('verbs')]
    public function test_a_runtime_exception_after_the_link_that_is_not_a_refusal_surfaces_as_an_error_and_rolls_back(string $verb): void
    {
        // A RuntimeException (the class the verb's catch handles) that is not a mapped
        // refusal, thrown after the call is linked and debited: it must reach the caller
        // as an error, never as a result without one.
        $call = $this->makeCall('synthetic-runtime-failure-'.$verb);
        $ticketsBefore = Ticket::query()->count();
        $notesBefore = \App\Models\TicketNote::query()->count();

        $seen = [];
        TechnicianActionLog::creating(function (TechnicianActionLog $log) use ($verb, $call, &$seen): void {
            if ($log->action_type === $verb) {
                $seen['linked'] = PhoneCall::whereKey($call->id)->value('ticket_id') !== null;
                $seen['debits'] = PrepayTransaction::where('phone_call_id', $call->id)->count();
                throw new \RuntimeException('synthetic runtime failure after the link');
            }
        });

        $response = $this->callTool($this->token([$verb]), $verb, $this->arguments($verb, $call) + ['billable' => true]);

        $this->assertSame('Tool execution failed: synthetic runtime failure after the link', $this->refusal($response));
        $this->assertSame(['linked' => true, 'debits' => 1], $seen, 'the call was linked and debited before the failure');
        $this->assertNothingWritten($call, $ticketsBefore);
        $this->assertSame($notesBefore, \App\Models\TicketNote::query()->count());
    }

    #[DataProvider('verbs')]
    public function test_a_billable_false_failure_on_a_debited_billable_call_leaves_its_stored_value_and_ledger_as_before(string $verb): void
    {
        // A call already linked elsewhere, stored is_billable=true with its debit. With
        // billable=false the verb clears is_billable to null and the apply reverses the
        // debit; a failure after both must leave the call and the ledger untouched.
        $call = $this->callInState('synthetic-false-rollback-'.$verb, 'linked', true);
        $earlierTicketId = (int) $call->ticket_id;
        $ledgerBefore = $this->ledgerOf($call);
        $this->assertSame([[$this->contract->id, -0.5]], $ledgerBefore);
        $balanceBefore = (float) $this->contract->fresh()->prepay_balance;
        $ticketsBefore = Ticket::query()->count();
        $notesBefore = \App\Models\TicketNote::query()->count();

        $seen = [];
        TechnicianActionLog::creating(function (TechnicianActionLog $log) use ($verb, $call, $earlierTicketId, &$seen): void {
            if ($log->action_type === $verb) {
                $seen['relinked'] = (int) PhoneCall::whereKey($call->id)->value('ticket_id') !== $earlierTicketId;
                $seen['stored'] = PhoneCall::whereKey($call->id)->value('is_billable');
                $seen['debits'] = PrepayTransaction::where('phone_call_id', $call->id)->count();
                $seen['apply_status'] = TechnicianActionLog::where('action_type', 'set_call_billable')->value('result_status');
                throw new \LogicException('synthetic failure after the billable=false apply');
            }
        });

        $response = $this->callTool($this->token([$verb]), $verb, $this->arguments($verb, $call) + ['billable' => false]);

        $this->assertStringContainsString('synthetic failure after the billable=false apply', $this->refusal($response));
        // "executed": with billable=false the apply always runs setBillable(false).
        $this->assertSame(['relinked' => true, 'stored' => false, 'debits' => 0, 'apply_status' => 'executed'], $seen, 'the call had been moved to the new ticket and the apply had set false and reversed the debit before the failure');
        $after = $call->fresh();
        $this->assertTrue($after->is_billable, 'the stored is_billable=true is kept: neither the null clear nor the false is committed');
        $this->assertSame($earlierTicketId, (int) $after->ticket_id);
        $this->assertSame($ledgerBefore, $this->ledgerOf($call));
        $this->assertEquals($balanceBefore, (float) $this->contract->fresh()->prepay_balance);
        $this->assertSame($ticketsBefore, Ticket::query()->count());
        $this->assertSame($notesBefore, \App\Models\TicketNote::query()->count());
        $this->assertSame(0, TechnicianActionLog::query()->count());
    }

    #[DataProvider('verbs')]
    public function test_a_billable_false_runtime_exception_after_the_apply_that_is_not_a_refusal_surfaces_as_an_error_and_leaves_the_call_as_before(string $verb): void
    {
        // billable=false on a call stored is_billable=true with its debit. A
        // RuntimeException (the class the verb's catch handles) that is not a mapped
        // refusal, thrown after the null clear and the apply: it must reach the caller
        // as an error, never as a result without one, and nothing may be committed.
        $call = $this->callInState('synthetic-false-runtime-failure-'.$verb, 'linked', true);
        $earlierTicketId = (int) $call->ticket_id;
        $ledgerBefore = $this->ledgerOf($call);
        $this->assertSame([[$this->contract->id, -0.5]], $ledgerBefore);
        $balanceBefore = (float) $this->contract->fresh()->prepay_balance;
        $ticketsBefore = Ticket::query()->count();
        $notesBefore = \App\Models\TicketNote::query()->count();

        $seen = [];
        TechnicianActionLog::creating(function (TechnicianActionLog $log) use ($verb, $call, $earlierTicketId, &$seen): void {
            if ($log->action_type === $verb) {
                $seen['relinked'] = (int) PhoneCall::whereKey($call->id)->value('ticket_id') !== $earlierTicketId;
                $seen['stored'] = PhoneCall::whereKey($call->id)->value('is_billable');
                $seen['debits'] = PrepayTransaction::where('phone_call_id', $call->id)->count();
                $seen['apply_status'] = TechnicianActionLog::where('action_type', 'set_call_billable')->value('result_status');
                throw new \RuntimeException('synthetic runtime failure after the billable=false apply');
            }
        });

        $response = $this->callTool($this->token([$verb]), $verb, $this->arguments($verb, $call) + ['billable' => false]);

        $this->assertSame('Tool execution failed: synthetic runtime failure after the billable=false apply', $this->refusal($response));
        $this->assertSame(['relinked' => true, 'stored' => false, 'debits' => 0, 'apply_status' => 'executed'], $seen, 'the call had been moved to the new ticket and the apply had set false and reversed the debit before the failure');
        $after = $call->fresh();
        $this->assertTrue($after->is_billable, 'the stored is_billable=true is kept: neither the null clear nor the false is committed');
        $this->assertSame($earlierTicketId, (int) $after->ticket_id);
        $this->assertSame($ledgerBefore, $this->ledgerOf($call));
        $this->assertEquals($balanceBefore, (float) $this->contract->fresh()->prepay_balance);
        $this->assertSame($ticketsBefore, Ticket::query()->count());
        $this->assertSame($notesBefore, \App\Models\TicketNote::query()->count());
        $this->assertSame(0, TechnicianActionLog::query()->count());
    }

    #[DataProvider('verbs')]
    public function test_a_missing_ai_actor_is_refused_before_the_link_takes_a_debit(string $verb): void
    {
        $call = $this->makeCall('synthetic-no-actor-'.$verb);
        $ticketsBefore = Ticket::query()->count();
        Setting::setValue('triage_system_user_id', '');
        $debitsCreated = 0;
        PrepayTransaction::created(function () use (&$debitsCreated): void {
            $debitsCreated++;
        });

        $text = $this->refusal($this->callTool($this->token([$verb]), $verb, $this->arguments($verb, $call) + ['billable' => true]));

        $this->assertSame('AI actor user is not configured for MCP AI-authored writes. Nothing was linked.', $text);
        $this->assertSame(0, $debitsCreated, 'no debit may be taken before the refusal');
        $this->assertNothingWritten($call, $ticketsBefore);
    }

    /**
     * A call in a given stored state. 'linked' calls already sit on another ticket
     * of the same client and contract (a re-link); a stored billable linked call
     * carries its prepay debit, as a set_call_billable or native link leaves it.
     */
    private function callInState(string $uuid, string $prior, ?bool $stored): PhoneCall
    {
        $call = $this->makeCall($uuid);
        $attributes = ['is_billable' => $stored];
        if ($prior === 'linked') {
            $other = Ticket::factory()->create([
                'client_id' => $this->client->id, 'contract_id' => $this->contract->id,
                'subject' => 'Synthetic earlier ticket', 'status' => 'new',
            ]);
            $attributes['ticket_id'] = $other->id;
        }
        $call->forceFill($attributes)->save();
        if ($prior === 'linked' && $stored === true) {
            app(\App\Services\PrepayService::class)->debitFromPhoneCall($call->fresh());
            $this->assertSame(1, PrepayTransaction::where('phone_call_id', $call->id)->count());
        }

        return $call->fresh();
    }

    /** @return list<array{0: int, 1: float}> */
    private function ledgerOf(PhoneCall $call): array
    {
        return PrepayTransaction::where('phone_call_id', $call->id)->get(['contract_id', 'hours'])
            ->map(fn ($row) => [(int) $row->contract_id, (float) $row->hours])->all();
    }

    /** @return array<string, array{0: string, 1: string, 2: ?bool, 3: bool}> */
    public static function storedStates(): array
    {
        $cases = [];
        foreach (['link_call_to_ticket', 'create_ticket_from_call'] as $verb) {
            foreach (['unlinked', 'linked'] as $prior) {
                foreach (['null' => null, 'true' => true, 'false' => false] as $label => $stored) {
                    foreach ([true, false] as $billable) {
                        $cases["{$verb} {$prior} stored {$label} billable ".($billable ? 'true' : 'false')] = [$verb, $prior, $stored, $billable];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('storedStates')]
    public function test_every_stored_state_ends_on_the_set_call_billable_ledger_and_false_never_debits(string $verb, string $prior, ?bool $stored, bool $billable): void
    {
        $tag = md5($verb.$prior.var_export($stored, true).var_export($billable, true));
        $call = $this->callInState('synthetic-state-'.$tag, $prior, $stored);
        $debitBefore = PrepayTransaction::where('phone_call_id', $call->id)->count();

        // From here one more 0.5 h debit takes the balance under the alert threshold,
        // which stamps the alert and raises an auto top-up invoice.
        $sku = Sku::create(['name' => 'Synthetic prepaid hour', 'sku_code' => 'SYN-'.$tag, 'unit_price' => 100, 'prepaid_time_minutes' => 60]);
        $this->contract->refresh();
        // A call that already carries its debit is the exception: a re-run of that debit
        // adjusts the existing row (no new row, no balance change) and then checks the
        // threshold on the current balance. With billable=false the threshold sits 0.25 h
        // above the balance there, so a threshold check made before the reversal would
        // stamp the alert and raise the invoice; after the reversal the balance is above it.
        $thresholdOffset = $debitBefore > 0 && ! $billable ? 0.25 : -0.25;
        $this->contract->update([
            'prepay_alert_threshold' => (float) $this->contract->prepay_balance + $thresholdOffset,
            'prepay_alert_notified_at' => null,
            'prepay_auto_topup_enabled' => true,
            'prepay_auto_topup_qty' => 1,
            'portal_prepay_sku_id' => $sku->id,
        ]);
        $invoicesBefore = Invoice::withTrashed()->count();
        $debitsCreated = 0;
        $stamped = false;
        PrepayTransaction::created(function (PrepayTransaction $row) use ($call, &$debitsCreated): void {
            if ((int) $row->phone_call_id === (int) $call->id) {
                $debitsCreated++;
            }
        });
        \App\Models\Contract::updated(function (\App\Models\Contract $contract) use (&$stamped): void {
            if ($contract->wasChanged('prepay_alert_notified_at') && $contract->prepay_alert_notified_at !== null) {
                $stamped = true;
            }
        });

        $result = $this->ok($this->callTool($this->token([$verb]), $verb, $this->arguments($verb, $call) + ['billable' => $billable]));

        $this->assertSame($billable, $result['is_billable']);
        $this->assertSame($billable, (bool) $call->fresh()->is_billable);
        $this->assertNotNull($call->fresh()->is_billable);
        $linkedTicketId = (int) $result['ticket_id'];
        $verbLedger = $this->ledgerOf($call);
        $newInvoices = Invoice::withTrashed()->count() - $invoicesBefore;

        if (! $billable) {
            $this->assertSame(0, $debitsCreated, 'billable=false must not create a debit at any point');
            $this->assertFalse($stamped, 'billable=false must not stamp the low-balance alert');
            $this->assertSame(0, $newInvoices, 'billable=false must not raise an auto top-up invoice');
            $this->assertSame([], $verbLedger, 'a debit already on the ledger is reversed');
        } elseif ($debitBefore === 0) {
            // Positive control: the fixture does fire when a debit is really taken.
            $this->assertSame(1, $debitsCreated);
            $this->assertTrue($stamped);
            $this->assertSame(1, $newInvoices);
        } else {
            // billable=true on a call that already carries its debit: the native link
            // re-runs that debit as an adjustment of the existing row (same hours, no
            // new row), the balance stays above the threshold, and the apply is a no_op.
            $this->assertSame(0, $debitsCreated, 'an existing debit is adjusted, never re-created');
            $this->assertFalse($stamped, 'the balance stays above the alert threshold, so the alert is not stamped');
            $this->assertSame(0, $newInvoices, 'the balance stays above the alert threshold, so no auto top-up invoice is raised');
        }

        // The set_call_billable apply ran. With billable=false it always re-runs
        // prepay (setBillable), whatever was stored; with billable=true it does so
        // when the link left the call non-billable (stored false), which is the
        // case where the apply itself takes the debit.
        $applied = TechnicianActionLog::where('action_type', 'set_call_billable')->sole();
        $applyMoved = ! $billable || $stored === false;
        $this->assertSame($applyMoved ? 'executed' : 'no_op', $applied->result_status);

        // Reference: an identical call linked natively to the same ticket, then
        // set_call_billable through MCP.
        $reference = $this->callInState('synthetic-state-ref-'.$tag, $prior, $stored);
        app(PhoneCallService::class)->linkCallToTicket($reference, $linkedTicketId);
        $this->ok($this->callTool($this->token(['set_call_billable:immediate']), 'set_call_billable', [
            'phone_call_id' => $reference->id, 'billable' => $billable, 'reason' => 'Synthetic reason',
        ]));

        $this->assertSame($this->ledgerOf($reference), $verbLedger);
        $this->assertSame($billable ? [[$this->contract->id, -0.5]] : [], $verbLedger);
        $this->assertEqualsWithDelta(10.0 + (float) PrepayTransaction::sum('hours'), (float) $this->contract->fresh()->prepay_balance, 0.0001);
    }

    #[DataProvider('verbs')]
    public function test_billable_false_reverses_a_debit_left_on_a_call_stored_non_billable(string $verb): void
    {
        // A linked call stored non-billable that still carries a debit (written
        // around PhoneCallService, for example by hand). billable=false must
        // still leave no debit for it.
        $call = $this->callInState('synthetic-stale-debit-'.$verb, 'linked', true);
        PhoneCall::whereKey($call->id)->update(['is_billable' => false]);
        $this->assertSame(1, PrepayTransaction::where('phone_call_id', $call->id)->count());

        $result = $this->ok($this->callTool($this->token([$verb]), $verb, $this->arguments($verb, $call->fresh()) + ['billable' => false]));

        $this->assertFalse($result['is_billable']);
        $this->assertFalse((bool) $call->fresh()->is_billable);
        $this->assertNotNull($call->fresh()->is_billable);
        $this->assertSame([], $this->ledgerOf($call));
        $this->assertEquals(10.0, (float) $this->contract->fresh()->prepay_balance);
    }
}
