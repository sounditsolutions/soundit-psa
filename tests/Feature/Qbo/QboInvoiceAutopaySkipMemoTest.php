<?php

namespace Tests\Feature\Qbo;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\RecurringInvoiceProfile;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Autopay scoping via QBO memo-token skip (#736).
 *
 * The payment processor auto-charges every open QBO invoice on a client's
 * saved card unless the invoice's memo carries a configured skip token
 * (its Invoice Skip Settings). Policy: autopay is for RECURRING invoices
 * only — a one-off hardware invoice must never hit the subscription card
 * (plus its CC surcharge) when the client is deliberately paying by check.
 *
 * So pushInvoiceToQbo stamps `billing.qbo_nonrecurring_skip_memo` as
 * CustomerMemo on every non-recurring invoice (profile_id null — the
 * discriminator every non-recurring creation path shares), and NEVER on
 * profile-generated recurring ones.
 *
 * The clobber rule: QBO full updates clear omitted writable fields, so the
 * UPDATE path must echo back a non-empty memo already on the QBO invoice
 * (manually entered or previously stamped) and may stamp the token only
 * when QBO's field is empty.
 */
class QboInvoiceAutopaySkipMemoTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'Not auto-charged - pay by check or portal';

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.qbo_nonrecurring_skip_memo' => self::TOKEN]);
    }

    private function makeInvoice(array $attrs = []): Invoice
    {
        $attrs['client_id'] ??= Client::factory()->create(['qbo_customer_id' => 'QBO-CUST-1'])->id;

        $invoice = Invoice::create(array_merge([
            'invoice_number' => 'INV-MEMO-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDays(3),
            'due_date' => now()->addDays(27),
            'subtotal' => '500.00',
            'tax' => '0.00',
            'total' => '500.00',
            'total_cost' => '200.00',
            'margin' => '300.00',
            'status' => InvoiceStatus::Posted,
        ], $attrs));

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Managed services',
            'quantity' => 5,
            'unit_price' => '100.00',
            'unit_cost' => '40.00',
            'amount' => '500.00',
            'cost_amount' => '200.00',
            'is_taxable' => false,
            'sort_order' => 0,
        ]);

        return $invoice->fresh();
    }

    private function makeRecurringProfile(Client $client): RecurringInvoiceProfile
    {
        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'status' => 'active',
            'billing_source' => 'psa',
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'start_date' => '2026-01-01',
        ]);

        return RecurringInvoiceProfile::create([
            'contract_id' => $contract->id,
            'name' => 'Monthly managed',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => '2026-08-01',
        ]);
    }

    /** Mock the QBO client, capturing every posted invoice payload into $posts. */
    private function mockQboClient(array &$posts, array $currentInvoice = []): void
    {
        $this->mock(QboClient::class, function (MockInterface $m) use (&$posts, $currentInvoice): void {
            if ($currentInvoice !== []) {
                $m->shouldReceive('get')->andReturn(['Invoice' => $currentInvoice]);
            }
            $m->shouldReceive('post')->andReturnUsing(function ($path, $payload) use (&$posts) {
                $posts[] = $payload;

                return [
                    'Invoice' => [
                        'Id' => $payload['Id'] ?? '9001',
                        'DocNumber' => 'DOC-9001',
                        'TotalAmt' => 500.0,
                        'TxnTaxDetail' => ['TotalTax' => 0],
                    ],
                ];
            });
        });
    }

    // ── CREATE path ──

    public function test_create_stamps_the_skip_memo_on_a_non_recurring_invoice(): void
    {
        $invoice = $this->makeInvoice(); // profile_id null
        $posts = [];
        $this->mockQboClient($posts);

        app(QboSyncService::class)->pushInvoiceToQbo($invoice);

        $this->assertCount(1, $posts);
        $this->assertSame(['value' => self::TOKEN], $posts[0]['CustomerMemo'] ?? null);
    }

    public function test_create_does_not_stamp_a_recurring_invoice(): void
    {
        $client = Client::factory()->create(['qbo_customer_id' => 'QBO-CUST-R']);
        $profile = $this->makeRecurringProfile($client);
        $invoice = $this->makeInvoice(['client_id' => $client->id, 'profile_id' => $profile->id]);
        $posts = [];
        $this->mockQboClient($posts);

        app(QboSyncService::class)->pushInvoiceToQbo($invoice);

        $this->assertCount(1, $posts);
        $this->assertArrayNotHasKey('CustomerMemo', $posts[0]);
    }

    public function test_create_does_not_stamp_when_the_token_is_not_configured(): void
    {
        config(['billing.qbo_nonrecurring_skip_memo' => null]);
        $invoice = $this->makeInvoice();
        $posts = [];
        $this->mockQboClient($posts);

        app(QboSyncService::class)->pushInvoiceToQbo($invoice);

        $this->assertCount(1, $posts);
        $this->assertArrayNotHasKey('CustomerMemo', $posts[0]);
    }

    public function test_create_treats_a_whitespace_only_token_as_unconfigured(): void
    {
        config(['billing.qbo_nonrecurring_skip_memo' => '   ']);
        $invoice = $this->makeInvoice();
        $posts = [];
        $this->mockQboClient($posts);

        app(QboSyncService::class)->pushInvoiceToQbo($invoice);

        $this->assertCount(1, $posts);
        $this->assertArrayNotHasKey('CustomerMemo', $posts[0]);
    }

    // ── UPDATE path ──

    public function test_update_echoes_back_an_existing_memo_instead_of_the_token(): void
    {
        // Manually entered memo in QBO must survive a re-push — a full update
        // omitting CustomerMemo would clear it, and the token must not replace it.
        $invoice = $this->makeInvoice(['qbo_invoice_id' => '7777', 'status' => InvoiceStatus::Synced]);
        $posts = [];
        $this->mockQboClient($posts, [
            'Id' => '7777',
            'SyncToken' => '2',
            'CustomerMemo' => ['value' => 'Net 45 per phone agreement'],
        ]);

        app(QboSyncService::class)->pushInvoiceToQbo($invoice);

        $this->assertCount(1, $posts);
        $this->assertSame(['value' => 'Net 45 per phone agreement'], $posts[0]['CustomerMemo'] ?? null);
    }

    public function test_update_stamps_a_non_recurring_invoice_whose_qbo_memo_is_empty(): void
    {
        $invoice = $this->makeInvoice(['qbo_invoice_id' => '7777', 'status' => InvoiceStatus::Synced]);
        $posts = [];
        $this->mockQboClient($posts, ['Id' => '7777', 'SyncToken' => '2']);

        app(QboSyncService::class)->pushInvoiceToQbo($invoice);

        $this->assertCount(1, $posts);
        $this->assertSame(['value' => self::TOKEN], $posts[0]['CustomerMemo'] ?? null);
    }

    public function test_update_does_not_stamp_a_recurring_invoice_with_an_empty_qbo_memo(): void
    {
        $client = Client::factory()->create(['qbo_customer_id' => 'QBO-CUST-R2']);
        $profile = $this->makeRecurringProfile($client);
        $invoice = $this->makeInvoice([
            'client_id' => $client->id,
            'profile_id' => $profile->id,
            'qbo_invoice_id' => '7778',
            'status' => InvoiceStatus::Synced,
        ]);
        $posts = [];
        $this->mockQboClient($posts, ['Id' => '7778', 'SyncToken' => '1']);

        app(QboSyncService::class)->pushInvoiceToQbo($invoice);

        $this->assertCount(1, $posts);
        $this->assertArrayNotHasKey('CustomerMemo', $posts[0]);
    }

    public function test_409_retry_redecides_the_memo_from_the_refetched_invoice(): void
    {
        // First fetch: empty memo → the payload carries the token. The write
        // 409s; the refetch shows a memo someone just entered in QBO. The
        // retried write must echo THAT memo, not overwrite it with the token.
        $invoice = $this->makeInvoice(['qbo_invoice_id' => '7779', 'status' => InvoiceStatus::Synced]);
        $posts = [];

        $this->mock(QboClient::class, function (MockInterface $m) use (&$posts): void {
            $m->shouldReceive('get')->once()->ordered()
                ->andReturn(['Invoice' => ['Id' => '7779', 'SyncToken' => '2']]);
            $m->shouldReceive('post')->once()->ordered()
                ->andReturnUsing(function ($path, $payload) use (&$posts) {
                    $posts[] = $payload;
                    throw new \App\Services\Qbo\QboClientException('conflict', 409);
                });
            $m->shouldReceive('get')->once()->ordered()->andReturn([
                'Invoice' => [
                    'Id' => '7779',
                    'SyncToken' => '3',
                    'CustomerMemo' => ['value' => 'Client prefers ACH'],
                ],
            ]);
            $m->shouldReceive('post')->once()->ordered()
                ->andReturnUsing(function ($path, $payload) use (&$posts) {
                    $posts[] = $payload;

                    return ['Invoice' => ['Id' => '7779', 'TotalAmt' => 500.0, 'TxnTaxDetail' => ['TotalTax' => 0]]];
                });
        });

        app(QboSyncService::class)->pushInvoiceToQbo($invoice);

        $this->assertCount(2, $posts);
        $this->assertSame(['value' => self::TOKEN], $posts[0]['CustomerMemo'] ?? null);
        $this->assertSame(['value' => 'Client prefers ACH'], $posts[1]['CustomerMemo'] ?? null);
    }
}
