<?php

namespace Tests\Feature\Mcp;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\PrepayTransactionSource;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Card 3vhEBCDG: get_contract's prepay block and the list_prepay_transactions
 * ledger read. Both are grant-gated psa_read tools and both are read-only.
 * Synthetic data only.
 */
class PrepayReadToolsTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $tools, string $label = 'chet'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
    }

    /** @param  array<string, mixed>  $arguments */
    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, mixed> */
    private function ok(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    private function refusal(TestResponse $response): string
    {
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return (string) $response->json('result.content.0.text');
    }

    private function contract(Client $client, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'client_id' => $client->id,
            'name' => 'Block Hours',
            'type' => ContractType::Managed,
            'status' => ContractStatus::Active,
            'start_date' => now()->subYear()->toDateString(),
        ], $overrides));
    }

    private function txn(Contract $contract, PrepayTransactionSource $source, ?float $hours, string $date, array $extra = []): PrepayTransaction
    {
        return PrepayTransaction::create(array_merge([
            'contract_id' => $contract->id,
            'source' => $source,
            'date' => $date,
            'hours' => $hours,
        ], $extra));
    }

    public function test_get_contract_returns_the_prepay_block_for_a_prepay_contract(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client, [
            'prepay_total' => 12.5,
            'prepay_used' => 4.25,
            'prepay_expired' => 1.5,
            'prepay_balance' => 6.75,
            'prepay_as_amount' => false,
            'prepay_expiry_months' => 12,
            'prepay_alert_threshold' => 2,
            'prepay_auto_topup_enabled' => true,
            'prepay_auto_topup_qty' => 3,
        ]);
        $contract->forceFill(['halo_prepay_synced_at' => '2026-09-01 10:00:00'])->save();

        $result = $this->ok($this->callTool($this->token(['get_contract']), 'get_contract', ['client_id' => $client->id, 'contract_id' => $contract->id]));

        $this->assertEquals([
            'unit' => 'hours',
            'as_amount' => false,
            'total' => 12.5,
            'used' => 4.25,
            'expired' => 1.5,
            'balance' => 6.75,
            'expiry_months' => 12,
            'halo_prepay_synced_at' => \Carbon\Carbon::parse('2026-09-01 10:00:00')->toIso8601String(),
            'alert_threshold' => 2.0,
            'alert_notified_at' => null,
            'auto_topup_enabled' => true,
            'auto_topup_qty' => 3,
        ], $result['prepay']);

        // Billing terms and pricing stay held.
        foreach (['billing_period', 'billing_day', 'payment_terms_days'] as $held) {
            $this->assertArrayNotHasKey($held, $result);
        }
    }

    public function test_get_contract_names_the_dollar_unit(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client, ['prepay_total' => 500, 'prepay_used' => 120, 'prepay_balance' => 380, 'prepay_as_amount' => true]);

        $result = $this->ok($this->callTool($this->token(['get_contract']), 'get_contract', ['client_id' => $client->id, 'contract_id' => $contract->id]));

        $this->assertSame('dollars', $result['prepay']['unit']);
        $this->assertTrue($result['prepay']['as_amount']);
        $this->assertEquals(380.0, $result['prepay']['balance']);
        $this->assertNull($result['prepay']['expired']);
    }

    public function test_get_contract_prepay_is_null_without_prepay(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client);

        $result = $this->ok($this->callTool($this->token(['get_contract']), 'get_contract', ['client_id' => $client->id, 'contract_id' => $contract->id]));

        $this->assertArrayHasKey('prepay', $result);
        $this->assertNull($result['prepay']);
    }

    /**
     * Five rows that net to 3.25 hours: two 2.5h deposits, a 1h ticket debit,
     * a 0.5h call debit and a 0.25h expiration.
     *
     * @return array{Client, Contract, Ticket, TicketNote, PhoneCall}
     */
    private function ledger(float $storedBalance = 3.25): array
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client, ['prepay_total' => 5, 'prepay_used' => 1.5, 'prepay_expired' => 0.25, 'prepay_balance' => $storedBalance, 'prepay_as_amount' => false]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $note = TicketNote::create(['ticket_id' => $ticket->id, 'body' => 'Worked.', 'is_billable' => false, 'noted_at' => now()]);
        $call = PhoneCall::create(['call_uuid' => 'synthetic-call-1', 'direction' => 'inbound', 'from_number' => '+15555550101', 'to_number' => '+15555550100', 'status' => 'completed', 'ticket_id' => $ticket->id, 'started_at' => now()]);

        $this->txn($contract, PrepayTransactionSource::InvoiceDeposit, 2.5, '2026-01-01 09:00:00', ['invoice_number' => 'INV-T001', 'expiry_date' => '2027-01-01 09:00:00', 'description' => 'Auto-deposit SECRET-DESC']);
        $this->txn($contract, PrepayTransactionSource::InvoiceDeposit, 2.5, '2026-02-01 09:00:00', ['invoice_number' => 'INV-T002']);
        $this->txn($contract, PrepayTransactionSource::TicketTime, -1.0, '2026-03-01 09:00:00', ['ticket_note_id' => $note->id, 'description' => 'Ticket SECRET-SUBJECT']);
        $this->txn($contract, PrepayTransactionSource::PhoneCallTime, -0.5, '2026-03-05 09:00:00', ['phone_call_id' => $call->id]);
        $this->txn($contract, PrepayTransactionSource::Expiration, -0.25, '2026-04-01 09:00:00', ['note' => 'OPERATOR-PROSE']);

        return [$client, $contract, $ticket, $note, $call];
    }

    public function test_ledger_is_newest_first_with_ids_and_running_balance(): void
    {
        [$client, $contract, $ticket, $note, $call] = $this->ledger();

        $response = $this->callTool($this->token(['list_prepay_transactions']), 'list_prepay_transactions', ['client_id' => $client->id, 'contract_id' => $contract->id]);
        $result = $this->ok($response);

        $this->assertSame('hours', $result['unit']);
        $this->assertSame(5, $result['total']);
        $this->assertSame(5, $result['count']);
        $this->assertFalse($result['has_more']);
        $this->assertTrue($result['balance_after_available']);
        $this->assertEquals(3.25, $result['ledger_net']);

        $rows = $result['transactions'];
        $this->assertSame(['expiration', 'phone_call_time', 'ticket_time', 'invoice_deposit', 'invoice_deposit'], array_column($rows, 'type'));
        $this->assertEquals([-0.25, -0.5, -1.0, 2.5, 2.5], array_column($rows, 'hours'));
        $this->assertEquals([3.25, 3.5, 4.0, 5.0, 2.5], array_column($rows, 'balance_after'));
        $this->assertSame([null, null, null, 'INV-T002', 'INV-T001'], array_column($rows, 'invoice_number'));

        $this->assertSame($call->id, $rows[1]['phone_call_id']);
        $this->assertSame($ticket->id, $rows[1]['ticket_id']);
        $this->assertSame($note->id, $rows[2]['ticket_note_id']);
        $this->assertSame($ticket->id, $rows[2]['ticket_id']);
        $this->assertSame(\Carbon\Carbon::parse('2027-01-01 09:00:00')->toIso8601String(), $rows[4]['expiry_date']);
        $this->assertTrue($rows[0]['has_note']);
        $this->assertFalse($rows[0]['is_credit']);
        $this->assertTrue($rows[4]['is_credit']);

        // Free text is withheld.
        $text = (string) $response->json('result.content.0.text');
        foreach (['SECRET-DESC', 'SECRET-SUBJECT', 'OPERATOR-PROSE'] as $freeText) {
            $this->assertStringNotContainsString($freeText, $text);
        }
    }

    public function test_running_balance_is_withheld_when_the_ledger_does_not_reproduce_the_stored_balance(): void
    {
        [$client, $contract] = $this->ledger(storedBalance: 4.0);

        $result = $this->ok($this->callTool($this->token(['list_prepay_transactions']), 'list_prepay_transactions', ['client_id' => $client->id, 'contract_id' => $contract->id]));

        $this->assertFalse($result['balance_after_available']);
        $this->assertEquals(4.0, $result['stored_balance']);
        $this->assertEquals(3.25, $result['ledger_net']);
        $this->assertSame([null, null, null, null, null], array_column($result['transactions'], 'balance_after'));
        // Per-row amounts are still reported.
        $this->assertEquals([-0.25, -0.5, -1.0, 2.5, 2.5], array_column($result['transactions'], 'hours'));
    }

    public function test_same_date_rows_order_by_id_descending(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client, ['prepay_balance' => 1.5, 'prepay_total' => 2, 'prepay_used' => 0.5]);
        $first = $this->txn($contract, PrepayTransactionSource::ManualCredit, 2.0, '2026-05-01 09:00:00');
        $second = $this->txn($contract, PrepayTransactionSource::ManualDebit, -0.5, '2026-05-01 09:00:00');

        $result = $this->ok($this->callTool($this->token(['list_prepay_transactions']), 'list_prepay_transactions', ['contract_id' => $contract->id]));

        $this->assertSame([$second->id, $first->id], array_column($result['transactions'], 'id'));
        $this->assertEquals([1.5, 2.0], array_column($result['transactions'], 'balance_after'));
    }

    public function test_limit_is_capped_at_100_and_reports_the_rest(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client, ['prepay_balance' => 0, 'prepay_total' => 0, 'prepay_used' => 0]);
        $start = \Carbon\Carbon::parse('2026-01-01 00:00:00');
        for ($i = 0; $i < 105; $i++) {
            $this->txn($contract, PrepayTransactionSource::ManualDebit, -0.1, $start->copy()->addHours($i)->toDateTimeString());
        }
        $token = $this->token(['list_prepay_transactions']);

        $capped = $this->ok($this->callTool($token, 'list_prepay_transactions', ['contract_id' => $contract->id, 'limit' => 500]));
        $this->assertSame(100, $capped['limit']);
        $this->assertSame(100, $capped['count']);
        $this->assertSame(105, $capped['total']);
        $this->assertTrue($capped['has_more']);

        $default = $this->ok($this->callTool($token, 'list_prepay_transactions', ['contract_id' => $contract->id]));
        $this->assertSame(25, $default['count']);

        $floor = $this->ok($this->callTool($token, 'list_prepay_transactions', ['contract_id' => $contract->id, 'limit' => -1]));
        $this->assertSame(1, $floor['count']);
    }

    public function test_unknown_contract_is_refused(): void
    {
        $client = Client::factory()->create();
        $token = $this->token(['list_prepay_transactions']);

        $this->assertStringContainsString('not found', $this->refusal($this->callTool($token, 'list_prepay_transactions', ['contract_id' => 999999])));
        $this->assertStringContainsString('not found', $this->refusal($this->callTool($token, 'list_prepay_transactions', ['client_id' => $client->id, 'contract_id' => 999999])));
        $this->assertStringContainsString('contract_id is required', $this->refusal($this->callTool($token, 'list_prepay_transactions', [])));
    }

    public function test_client_that_does_not_own_the_contract_is_refused(): void
    {
        [$owner, $contract] = $this->ledger();
        $other = Client::factory()->create();
        $token = $this->token(['list_prepay_transactions']);

        $response = $this->callTool($token, 'list_prepay_transactions', ['client_id' => $other->id, 'contract_id' => $contract->id]);
        $text = $this->refusal($response);
        $this->assertStringContainsString('does not belong', $text);
        $this->assertStringNotContainsString('INV-T001', $text);
        $this->assertNull(json_decode($text, true)['transactions'] ?? null);

        // A malformed client_id is refused rather than dropping the fence.
        $this->assertStringContainsString('not a positive integer', $this->refusal($this->callTool($token, 'list_prepay_transactions', ['client_id' => 'abc', 'contract_id' => $contract->id])));

        // The owner reads it.
        $this->assertSame(5, $this->ok($this->callTool($token, 'list_prepay_transactions', ['client_id' => $owner->id, 'contract_id' => $contract->id]))['count']);
    }

    public function test_every_ledger_source_surfaces_its_enum_value(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client, ['prepay_balance' => 0, 'prepay_total' => 0, 'prepay_used' => 0]);
        $start = \Carbon\Carbon::parse('2026-01-01 00:00:00');
        foreach (PrepayTransactionSource::cases() as $i => $case) {
            $this->txn($contract, $case, $case->isCredit() ? 1.0 : -1.0, $start->copy()->addDays($i)->toDateTimeString());
        }

        $result = $this->ok($this->callTool($this->token(['list_prepay_transactions']), 'list_prepay_transactions', ['contract_id' => $contract->id]));

        $expected = array_reverse(array_map(fn ($c) => $c->value, PrepayTransactionSource::cases()));
        $this->assertSame($expected, array_column($result['transactions'], 'type'));
        $this->assertSame(array_reverse(array_map(fn ($c) => $c->label(), PrepayTransactionSource::cases())), array_column($result['transactions'], 'type_label'));
    }

    public function test_dollar_ledger_reports_amounts_and_runs_on_amount(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client, ['prepay_balance' => 70, 'prepay_total' => 100, 'prepay_used' => 30, 'prepay_as_amount' => true]);
        $this->txn($contract, PrepayTransactionSource::ManualCredit, null, '2026-01-01 09:00:00', ['amount' => 100]);
        $this->txn($contract, PrepayTransactionSource::ManualDebit, null, '2026-02-01 09:00:00', ['amount' => -30]);

        $result = $this->ok($this->callTool($this->token(['list_prepay_transactions']), 'list_prepay_transactions', ['contract_id' => $contract->id]));

        $this->assertSame('dollars', $result['unit']);
        $this->assertEquals([-30.0, 100.0], array_column($result['transactions'], 'amount'));
        $this->assertSame([null, null], array_column($result['transactions'], 'hours'));
        $this->assertEquals([70.0, 100.0], array_column($result['transactions'], 'balance_after'));
    }

    public function test_read_writes_nothing(): void
    {
        [$client, $contract] = $this->ledger(storedBalance: 4.0);
        $before = [$contract->fresh()->toArray(), PrepayTransaction::orderBy('id')->get()->toArray()];

        $this->ok($this->callTool($this->token(['list_prepay_transactions', 'get_contract']), 'list_prepay_transactions', ['contract_id' => $contract->id]));
        $this->ok($this->callTool($this->token(['get_contract']), 'get_contract', ['client_id' => $client->id, 'contract_id' => $contract->id]));

        $this->assertSame($before, [$contract->fresh()->toArray(), PrepayTransaction::orderBy('id')->get()->toArray()]);
    }

    public function test_registered_in_psa_read_dispatched_and_grant_gated(): void
    {
        $this->assertContains('list_prepay_transactions', array_column(McpToolRegistry::groups()['psa_read']['tools'], 'name'));
        $this->assertSame('psa', McpToolRegistry::integrationForToolName('list_prepay_transactions'));

        $schema = collect(McpToolRegistry::psaReadTools())->firstWhere('name', 'list_prepay_transactions')['input_schema'];
        $this->assertSame(['contract_id'], $schema['required']);

        [, $contract] = $this->ledger();
        $ungranted = $this->token(['psa_version'], 'other');
        foreach ([McpConfig::rotateStaffToken(), $ungranted] as $token) {
            $this->assertStringContainsString('not allowed for this token', $this->refusal($this->callTool($token, 'list_prepay_transactions', ['contract_id' => $contract->id])));
        }

        $matches = json_decode((string) $this->callTool($ungranted, 'search_tools', ['query' => 'list_prepay_transactions'])->json('result.content.0.text'), true)['matches'] ?? [];
        $this->assertSame('available_ungranted', collect($matches)->firstWhere('name', 'list_prepay_transactions')['grant_state'] ?? null, json_encode($matches));

        // The executor dispatches it as a read.
        $executor = new \App\Services\Assistant\AssistantToolExecutor(ticket: null, clientId: null, userId: null);
        $this->assertSame(5, $executor->execute('list_prepay_transactions', ['contract_id' => $contract->id])['count']);
        $this->assertContains('list_prepay_transactions', \App\Services\Assistant\AssistantToolExecutor::readTools());
    }
}
