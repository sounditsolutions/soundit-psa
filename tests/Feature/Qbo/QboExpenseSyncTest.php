<?php

namespace Tests\Feature\Qbo;

use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * psa-7v9: expense (Purchase) sync from QuickBooks Online.
 */
class QboExpenseSyncTest extends TestCase
{
    use RefreshDatabase;

    private function purchaseResponse(): array
    {
        return [
            'QueryResponse' => [
                'Purchase' => [
                    [
                        'Id' => '101',
                        'TxnDate' => '2026-06-15',
                        'PaymentType' => 'CreditCard',
                        'AccountRef' => ['value' => '42', 'name' => 'Visa Card'],
                        'EntityRef' => ['value' => '7', 'name' => 'Acme Supplies', 'type' => 'Vendor'],
                        'TotalAmt' => 149.99,
                        'CurrencyRef' => ['value' => 'USD'],
                        'DocNumber' => 'PO-1',
                        'PrivateNote' => 'Office supplies',
                    ],
                    [
                        // Minimal purchase — no EntityRef, DocNumber, or memo.
                        'Id' => '102',
                        'TxnDate' => '2026-06-10',
                        'PaymentType' => 'Cash',
                        'AccountRef' => ['value' => '35', 'name' => 'Business Checking'],
                        'TotalAmt' => 25.00,
                    ],
                ],
            ],
        ];
    }

    public function test_sync_expenses_upserts_purchases_with_null_safe_fields(): void
    {
        $this->mock(QboClient::class, function (MockInterface $m): void {
            $m->shouldReceive('query')
                ->once()
                ->andReturn($this->purchaseResponse());
        });

        $result = app(QboSyncService::class)->syncExpenses();

        $this->assertSame(2, $result['synced']);
        $this->assertDatabaseCount('qbo_expenses', 2);
        $this->assertDatabaseHas('qbo_expenses', [
            'qbo_purchase_id' => '101',
            'payment_type' => 'CreditCard',
            'account_name' => 'Visa Card',
            'payee_name' => 'Acme Supplies',
            'total_amount' => 149.99,
            'doc_number' => 'PO-1',
            'memo' => 'Office supplies',
        ]);
        // Missing optional fields must land as null, not crash.
        $this->assertDatabaseHas('qbo_expenses', [
            'qbo_purchase_id' => '102',
            'payee_name' => null,
            'doc_number' => null,
            'memo' => null,
            'total_amount' => 25.00,
        ]);
    }

    public function test_sync_expenses_is_idempotent(): void
    {
        $this->mock(QboClient::class, function (MockInterface $m): void {
            $m->shouldReceive('query')->andReturn($this->purchaseResponse());
        });

        $service = app(QboSyncService::class);
        $service->syncExpenses();
        $service->syncExpenses();

        // Upsert by qbo_purchase_id — re-running must not duplicate rows.
        $this->assertDatabaseCount('qbo_expenses', 2);
    }

    public function test_since_filter_is_applied_and_sanitized(): void
    {
        $capturedSql = null;

        $this->mock(QboClient::class, function (MockInterface $m) use (&$capturedSql): void {
            $m->shouldReceive('query')
                ->once()
                ->andReturnUsing(function ($sql) use (&$capturedSql) {
                    $capturedSql = $sql;

                    return ['QueryResponse' => ['Purchase' => []]];
                });
        });

        // Malicious input must be reduced to the leading Y-m-d date.
        app(QboSyncService::class)->syncExpenses("2026-01-01'; DROP TABLE qbo_expenses; --");

        $this->assertStringContainsString("WHERE TxnDate >= '2026-01-01'", $capturedSql);
        $this->assertStringNotContainsString('DROP', $capturedSql);
    }

    public function test_no_since_filter_omits_where_clause(): void
    {
        $capturedSql = null;

        $this->mock(QboClient::class, function (MockInterface $m) use (&$capturedSql): void {
            $m->shouldReceive('query')
                ->once()
                ->andReturnUsing(function ($sql) use (&$capturedSql) {
                    $capturedSql = $sql;

                    return ['QueryResponse' => ['Purchase' => []]];
                });
        });

        app(QboSyncService::class)->syncExpenses();

        $this->assertStringNotContainsString('WHERE', $capturedSql);
        $this->assertStringContainsString('FROM Purchase', $capturedSql);
    }
}
