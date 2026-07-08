<?php

namespace Tests\Feature\Prepay;

use App\Enums\PrepayTransactionSource;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PrepayTransaction;
use App\Models\User;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers inter-contract prepay transfers (psa-t8k / GitHub #111). A transfer is
 * a matched TransferOut/TransferIn ledger pair created atomically; the flagship
 * assertion is that the live denormalized balances match a from-ledger
 * recalculation, so the transfer can never drift the balance columns.
 */
class PrepayTransferTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PrepayService
    {
        return app(PrepayService::class);
    }

    private function contract(int $clientId, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'client_id' => $clientId,
            'name' => 'Contract',
            'type' => 'managed',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'prepay_as_amount' => false,
            'prepay_total' => 0,
            'prepay_used' => 0,
            'prepay_balance' => 0,
        ], $overrides));
    }

    /** A contract with no prepay tracking at all (balance column left null). */
    private function bareContract(int $clientId, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'client_id' => $clientId,
            'name' => 'No Prepay',
            'type' => 'managed',
            'status' => 'active',
            'start_date' => '2026-01-01',
        ], $overrides));
    }

    public function test_transfer_moves_balance_and_stays_ledger_consistent(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $from = $this->contract($client->id, ['name' => 'Old MSA']);
        $to = $this->contract($client->id, ['name' => 'New MSA']);
        $this->service()->addManualCredit($from, 10, 'seed');

        $result = $this->service()->transfer($from, $to, 4, 'Consolidating', null);

        $from->refresh();
        $to->refresh();

        // Balance moved; total is conserved across the two contracts.
        $this->assertEqualsWithDelta(6.0, (float) $from->prepay_balance, 1e-6);
        $this->assertEqualsWithDelta(4.0, (float) $to->prepay_balance, 1e-6);
        $this->assertEqualsWithDelta(
            10.0,
            (float) $from->prepay_balance + (float) $to->prepay_balance,
            1e-6,
        );

        // Source: transfer-out is a debit counted as "used"; total unchanged.
        $this->assertEqualsWithDelta(10.0, (float) $from->prepay_total, 1e-6);
        $this->assertEqualsWithDelta(4.0, (float) $from->prepay_used, 1e-6);
        // Destination: transfer-in is a fresh credit.
        $this->assertEqualsWithDelta(4.0, (float) $to->prepay_total, 1e-6);
        $this->assertEqualsWithDelta(0.0, (float) $to->prepay_used, 1e-6);

        // Ledger rows carry the right source and sign.
        $this->assertSame(PrepayTransactionSource::TransferOut, $result['out']->source);
        $this->assertEqualsWithDelta(-4.0, (float) $result['out']->hours, 1e-6);
        $this->assertSame(PrepayTransactionSource::TransferIn, $result['in']->source);
        $this->assertEqualsWithDelta(4.0, (float) $result['in']->hours, 1e-6);

        // Flagship: recomputing from the ledger reproduces the live state exactly.
        $this->service()->recalculateBalance($from);
        $this->service()->recalculateBalance($to);
        $from->refresh();
        $to->refresh();
        $this->assertEqualsWithDelta(6.0, (float) $from->prepay_balance, 1e-6);
        $this->assertEqualsWithDelta(4.0, (float) $from->prepay_used, 1e-6);
        $this->assertEqualsWithDelta(10.0, (float) $from->prepay_total, 1e-6);
        $this->assertEqualsWithDelta(4.0, (float) $to->prepay_balance, 1e-6);
        $this->assertEqualsWithDelta(4.0, (float) $to->prepay_total, 1e-6);
    }

    public function test_transfer_logs_activity_on_both_contracts(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $from = $this->contract($client->id, ['name' => 'Old MSA']);
        $to = $this->contract($client->id, ['name' => 'New MSA']);
        $this->service()->addManualCredit($from, 8, 'seed');

        $this->service()->transfer($from, $to, 3, 'Moving hours', null);

        $this->assertDatabaseHas('contract_activities', [
            'contract_id' => $from->id,
            'action' => 'prepay_transfer_out',
        ]);
        $this->assertDatabaseHas('contract_activities', [
            'contract_id' => $to->id,
            'action' => 'prepay_transfer_in',
        ]);
    }

    public function test_transfer_in_applies_destination_expiry_policy(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');
        $client = Client::create(['name' => 'Acme Corp']);
        $from = $this->contract($client->id);
        $to = $this->contract($client->id, ['prepay_expiry_months' => 6]);
        $this->service()->addManualCredit($from, 5, 'seed');

        $result = $this->service()->transfer($from, $to, 2, 'move', null);

        // The incoming credit expires per the destination's policy.
        $this->assertNotNull($result['in']->expiry_date);
        $this->assertEqualsWithDelta(
            now()->addMonths(6)->timestamp,
            $result['in']->expiry_date->timestamp,
            5,
        );
        // The outgoing debit never carries an expiry.
        $this->assertNull($result['out']->expiry_date);

        Carbon::setTestNow();
    }

    public function test_transfer_is_excluded_from_burn_rate(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $from = $this->contract($client->id);
        $to = $this->contract($client->id, ['name' => 'Recipient']);
        $this->service()->addManualCredit($from, 100, 'seed');

        // A large, recent transfer-in must not read as consumption. Without the
        // scopeConsumption exclusion this positive row would inflate burn rate.
        $this->service()->transfer($from, $to, 100, 'move all', null);

        $freshTo = Contract::find($to->id);
        $this->assertEqualsWithDelta(0.0, (float) $freshTo->burn_rate, 1e-6);

        $freshFrom = Contract::find($from->id);
        $this->assertEqualsWithDelta(0.0, (float) $freshFrom->burn_rate, 1e-6);
    }

    public function test_transfer_rejects_insufficient_balance(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $from = $this->contract($client->id);
        $to = $this->contract($client->id, ['name' => 'New MSA']);
        $this->service()->addManualCredit($from, 5, 'seed');

        $threw = false;
        try {
            $this->service()->transfer($from, $to, 20, 'too much', null);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected an over-balance transfer to be rejected.');
        $from->refresh();
        $this->assertEqualsWithDelta(5.0, (float) $from->prepay_balance, 1e-6);
        $this->assertSame(0, PrepayTransaction::where('source', PrepayTransactionSource::TransferOut)->count());
        $this->assertSame(0, PrepayTransaction::where('source', PrepayTransactionSource::TransferIn)->count());
    }

    public function test_transfer_rejects_cross_client(): void
    {
        $clientA = Client::create(['name' => 'Client A']);
        $clientB = Client::create(['name' => 'Client B']);
        $from = $this->contract($clientA->id);
        $to = $this->contract($clientB->id);
        $this->service()->addManualCredit($from, 10, 'seed');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->transfer($from, $to, 2, 'cross client', null);
    }

    public function test_transfer_rejects_unit_mismatch(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $from = $this->contract($client->id);
        $to = $this->contract($client->id, ['prepay_as_amount' => true]);
        $this->service()->addManualCredit($from, 10, 'seed');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->transfer($from, $to, 2, 'mismatch', null);
    }

    public function test_transfer_rejects_same_contract(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $from = $this->contract($client->id);
        $this->service()->addManualCredit($from, 10, 'seed');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->transfer($from, $from, 2, 'self', null);
    }

    public function test_transfer_rejects_destination_without_prepay(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $from = $this->contract($client->id);
        $to = $this->bareContract($client->id);
        $this->service()->addManualCredit($from, 10, 'seed');

        $this->assertFalse($to->has_prepay);
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->transfer($from, $to, 2, 'no prepay', null);
    }

    public function test_staff_can_transfer_via_http(): void
    {
        $user = User::factory()->create();
        $client = Client::create(['name' => 'Acme Corp']);
        $from = $this->contract($client->id, ['name' => 'Old MSA']);
        $to = $this->contract($client->id, ['name' => 'New MSA']);
        $this->service()->addManualCredit($from, 10, 'seed');

        $response = $this->actingAs($user)->post(route('contracts.prepay-transfer', $from), [
            'to_contract_id' => $to->id,
            'value' => 3,
            'note' => 'Consolidating balances',
        ]);

        $response->assertRedirect(route('contracts.show', $from));
        $response->assertSessionHas('success');

        $from->refresh();
        $to->refresh();
        $this->assertEqualsWithDelta(7.0, (float) $from->prepay_balance, 1e-6);
        $this->assertEqualsWithDelta(3.0, (float) $to->prepay_balance, 1e-6);
    }

    public function test_http_transfer_rejects_cross_client_target(): void
    {
        $user = User::factory()->create();
        $clientA = Client::create(['name' => 'Client A']);
        $clientB = Client::create(['name' => 'Client B']);
        $from = $this->contract($clientA->id);
        $other = $this->contract($clientB->id);
        $this->service()->addManualCredit($from, 10, 'seed');

        $response = $this->actingAs($user)->post(route('contracts.prepay-transfer', $from), [
            'to_contract_id' => $other->id,
            'value' => 3,
            'note' => 'wrong client',
        ]);

        $response->assertRedirect(route('contracts.show', $from));
        $response->assertSessionHas('error');

        $from->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $from->prepay_balance, 1e-6);
        $this->assertSame(0, PrepayTransaction::where('source', PrepayTransactionSource::TransferOut)->count());
    }

    public function test_http_transfer_rejects_insufficient_balance(): void
    {
        $user = User::factory()->create();
        $client = Client::create(['name' => 'Acme Corp']);
        $from = $this->contract($client->id);
        $to = $this->contract($client->id, ['name' => 'New MSA']);
        $this->service()->addManualCredit($from, 2, 'seed');

        $response = $this->actingAs($user)->post(route('contracts.prepay-transfer', $from), [
            'to_contract_id' => $to->id,
            'value' => 5,
            'note' => 'too much',
        ]);

        $response->assertRedirect(route('contracts.show', $from));
        $response->assertSessionHas('error');

        $from->refresh();
        $to->refresh();
        $this->assertEqualsWithDelta(2.0, (float) $from->prepay_balance, 1e-6);
        $this->assertEqualsWithDelta(0.0, (float) $to->prepay_balance, 1e-6);
    }
}
