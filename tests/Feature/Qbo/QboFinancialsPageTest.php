<?php

namespace Tests\Feature\Qbo;

use App\Models\QboBankAccount;
use App\Models\QboExpense;
use App\Models\User;
use App\Services\Qbo\QboClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * psa-7v9: read-only Bank & Expenses page and its on-demand sync action.
 */
class QboFinancialsPageTest extends TestCase
{
    use RefreshDatabase;

    private function seedFinancials(): void
    {
        QboBankAccount::create([
            'qbo_account_id' => '35',
            'name' => 'Business Checking',
            'account_sub_type' => 'Checking',
            'classification' => 'Asset',
            'current_balance' => 5000.75,
            'currency' => 'USD',
            'active' => true,
            'qbo_synced_at' => now(),
        ]);
        QboBankAccount::create([
            'qbo_account_id' => '36',
            'name' => 'Business Savings',
            'account_sub_type' => 'Savings',
            'classification' => 'Asset',
            'current_balance' => 12000.00,
            'currency' => 'USD',
            'active' => true,
            'qbo_synced_at' => now(),
        ]);
        QboExpense::create([
            'qbo_purchase_id' => '101',
            'txn_date' => '2026-06-15',
            'payment_type' => 'CreditCard',
            'account_name' => 'Visa Card',
            'payee_name' => 'Acme Supplies',
            'total_amount' => 149.99,
            'currency' => 'USD',
            'doc_number' => 'PO-1',
            'memo' => 'Office supplies',
            'qbo_synced_at' => now(),
        ]);
    }

    public function test_index_renders_with_synced_data(): void
    {
        $this->mock(QboClient::class, function (MockInterface $m): void {
            $m->shouldReceive('isConnected')->andReturnTrue();
        });

        $this->seedFinancials();

        $this->actingAs(User::factory()->create())
            ->get(route('qbo.financials.index'))
            ->assertOk()
            ->assertSee('Business Checking')
            ->assertSee('Acme Supplies')
            ->assertSee('17,000.75'); // total cash across active accounts
    }

    public function test_index_shows_warning_when_not_connected(): void
    {
        $this->mock(QboClient::class, function (MockInterface $m): void {
            $m->shouldReceive('isConnected')->andReturnFalse();
        });

        $this->actingAs(User::factory()->create())
            ->get(route('qbo.financials.index'))
            ->assertOk()
            ->assertSee('Not connected to QuickBooks Online');
    }

    public function test_sync_action_pulls_data_and_redirects_with_success(): void
    {
        $this->mock(QboClient::class, function (MockInterface $m): void {
            $m->shouldReceive('isConnected')->andReturnTrue();
            $m->shouldReceive('query')->andReturnUsing(function ($sql) {
                if (str_contains($sql, 'FROM Account')) {
                    return ['QueryResponse' => ['Account' => [[
                        'Id' => '35', 'Name' => 'Business Checking', 'AccountType' => 'Bank',
                        'AccountSubType' => 'Checking', 'CurrentBalance' => 5000.75,
                        'CurrencyRef' => ['value' => 'USD'], 'Active' => true,
                    ]]]];
                }

                return ['QueryResponse' => ['Purchase' => [[
                    'Id' => '101', 'TxnDate' => '2026-06-15', 'PaymentType' => 'Cash',
                    'AccountRef' => ['name' => 'Business Checking'], 'TotalAmt' => 25.00,
                ]]]];
            });
        });

        $this->actingAs(User::factory()->create())
            ->post(route('qbo.financials.sync'))
            ->assertRedirect(route('qbo.financials.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('qbo_bank_accounts', 1);
        $this->assertDatabaseCount('qbo_expenses', 1);
    }

    public function test_sync_action_errors_when_not_connected(): void
    {
        $this->mock(QboClient::class, function (MockInterface $m): void {
            $m->shouldReceive('isConnected')->andReturnFalse();
            $m->shouldReceive('query')->never();
        });

        $this->actingAs(User::factory()->create())
            ->post(route('qbo.financials.sync'))
            ->assertRedirect(route('qbo.financials.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('qbo_bank_accounts', 0);
    }
}
