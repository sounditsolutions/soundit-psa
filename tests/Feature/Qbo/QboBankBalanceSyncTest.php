<?php

namespace Tests\Feature\Qbo;

use App\Models\QboBankAccount;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * psa-7v9: bank account balance sync from QuickBooks Online.
 */
class QboBankBalanceSyncTest extends TestCase
{
    use RefreshDatabase;

    private function bankAccountResponse(float $checking = 5000.75, float $savings = 12000.00): array
    {
        return [
            'QueryResponse' => [
                'Account' => [
                    [
                        'Id' => '35',
                        'Name' => 'Business Checking',
                        'AccountType' => 'Bank',
                        'AccountSubType' => 'Checking',
                        'Classification' => 'Asset',
                        'CurrentBalance' => $checking,
                        'CurrencyRef' => ['value' => 'USD', 'name' => 'United States Dollar'],
                        'Active' => true,
                    ],
                    [
                        'Id' => '36',
                        'Name' => 'Business Savings',
                        'AccountType' => 'Bank',
                        'AccountSubType' => 'Savings',
                        'Classification' => 'Asset',
                        'CurrentBalance' => $savings,
                        'CurrencyRef' => ['value' => 'USD'],
                        'Active' => true,
                    ],
                ],
            ],
        ];
    }

    public function test_sync_bank_balances_upserts_accounts(): void
    {
        $this->mock(QboClient::class, function (MockInterface $m): void {
            $m->shouldReceive('query')
                ->once()
                ->with(\Mockery::on(fn ($sql) => str_contains($sql, "FROM Account WHERE AccountType = 'Bank'")))
                ->andReturn($this->bankAccountResponse());
        });

        $result = app(QboSyncService::class)->syncBankBalances();

        $this->assertSame(2, $result['synced']);
        $this->assertDatabaseCount('qbo_bank_accounts', 2);
        $this->assertDatabaseHas('qbo_bank_accounts', [
            'qbo_account_id' => '35',
            'name' => 'Business Checking',
            'account_sub_type' => 'Checking',
            'classification' => 'Asset',
            'current_balance' => 5000.75,
            'currency' => 'USD',
            'active' => true,
        ]);
    }

    public function test_sync_bank_balances_is_idempotent_and_refreshes_balance(): void
    {
        $this->mock(QboClient::class, function (MockInterface $m): void {
            $m->shouldReceive('query')
                ->andReturn(
                    $this->bankAccountResponse(5000.75, 12000.00),
                    $this->bankAccountResponse(9999.99, 12000.00),
                );
        });

        // Resolve the service AFTER mocking so it receives the mocked client.
        $service = app(QboSyncService::class);

        $service->syncBankBalances();
        $service->syncBankBalances();

        // Same accounts — no duplicate rows, balance overwritten with latest.
        $this->assertDatabaseCount('qbo_bank_accounts', 2);
        $this->assertSame('9999.99', QboBankAccount::where('qbo_account_id', '35')->value('current_balance'));
    }

    public function test_command_syncs_balances_only_with_flag(): void
    {
        $this->mock(QboClient::class, function (MockInterface $m): void {
            $m->shouldReceive('isConnected')->andReturnTrue();
            // Only the Account query may run; a Purchase query would be a bug.
            $m->shouldReceive('query')
                ->with(\Mockery::on(fn ($sql) => str_contains($sql, 'FROM Account')))
                ->andReturn($this->bankAccountResponse());
            $m->shouldReceive('query')
                ->with(\Mockery::on(fn ($sql) => str_contains($sql, 'FROM Purchase')))
                ->never();
        });

        $this->artisan('qbo:sync-financials --balances')
            ->assertExitCode(0);

        $this->assertDatabaseCount('qbo_bank_accounts', 2);
    }

    public function test_command_fails_when_not_connected(): void
    {
        $this->mock(QboClient::class, function (MockInterface $m): void {
            $m->shouldReceive('isConnected')->andReturnFalse();
            $m->shouldReceive('query')->never();
        });

        $this->artisan('qbo:sync-financials')
            ->assertExitCode(1);

        $this->assertDatabaseCount('qbo_bank_accounts', 0);
    }
}
