<?php

namespace Tests\Feature\Invoices;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\InvoiceStatus;
use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Person;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * #8527: an invoice carrying prepaid minutes and no contract posts no prepay
 * deposit when it is paid (the observer's contract guard stays). Staff are
 * told on the staff invoice page, with a sentence that is true at the
 * invoice's status, and the move to Paid writes one warning log line. The
 * portal never carries the sentence. Synthetic data only.
 */
class PrepaidNoContractWarningTest extends TestCase
{
    use RefreshDatabase;

    private const UNPAID = 'No contract is linked, so this prepaid time will not be credited when the invoice is paid. Link a contract first.';

    private const PAID = 'No contract was linked when this invoice was paid, so this prepaid time was not credited.';

    private const LOG_MESSAGE = '[Prepay] Paid invoice carries prepaid minutes but has no contract; no deposit posted';

    private static int $seq = 0;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::create(['name' => 'Synthetic Client Co']);
    }

    private function invoice(InvoiceStatus $status, ?int $minutes = 90, bool $withContract = false): Invoice
    {
        $contractId = null;
        if ($withContract) {
            $contractId = Contract::create([
                'client_id' => $this->client->id,
                'name' => 'Synthetic prepay contract',
                'type' => ContractType::Managed->value,
                'status' => ContractStatus::Active->value,
                'start_date' => now()->subYear(),
                'prepay_as_amount' => false,
                'prepay_total' => 0, 'prepay_used' => 0, 'prepay_balance' => 0,
            ])->id;
        }

        $invoice = Invoice::create([
            'client_id' => $this->client->id,
            'contract_id' => $contractId,
            'invoice_number' => 'INV-SYN-'.str_pad((string) ++self::$seq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDays(5),
            'due_date' => now()->addDays(25),
            'subtotal' => '100.00', 'tax' => '0.00', 'total' => '100.00',
            'status' => $status,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Synthetic prepaid block',
            'quantity' => '1.00', 'unit_price' => '100.00', 'amount' => '100.00',
            'prepaid_time_minutes' => $minutes,
            'sort_order' => 0,
        ]);

        return $invoice;
    }

    private function staffPage(Invoice $invoice): string
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice))
            ->assertOk()
            ->getContent();
    }

    // ── staff page ──────────────────────────────────────────────────────────

    public function test_unpaid_invoice_with_minutes_and_no_contract_shows_the_not_yet_paid_sentence(): void
    {
        foreach ([InvoiceStatus::Draft, InvoiceStatus::PendingSync, InvoiceStatus::Synced, InvoiceStatus::Posted] as $status) {
            $html = $this->staffPage($this->invoice($status));

            $this->assertStringContainsString(self::UNPAID, $html, "{$status->value}: the not-yet-paid sentence is shown");
            $this->assertStringNotContainsString(self::PAID, $html, "{$status->value}: the Paid sentence is not shown");
        }
    }

    public function test_paid_invoice_with_minutes_and_no_contract_shows_the_paid_sentence_only(): void
    {
        $html = $this->staffPage($this->invoice(InvoiceStatus::Paid));

        $this->assertStringContainsString(self::PAID, $html);
        $this->assertStringNotContainsString(self::UNPAID, $html);
        $this->assertStringNotContainsString('will not be credited', $html);
    }

    public function test_void_invoice_shows_no_warning(): void
    {
        $html = $this->staffPage($this->invoice(InvoiceStatus::Void));

        $this->assertStringNotContainsString(self::UNPAID, $html);
        $this->assertStringNotContainsString(self::PAID, $html);
    }

    public function test_invoice_with_a_contract_shows_no_warning(): void
    {
        foreach ([InvoiceStatus::Posted, InvoiceStatus::Paid] as $status) {
            $html = $this->staffPage($this->invoice($status, 90, withContract: true));

            $this->assertStringContainsString('of prepaid time', $html, 'the prepaid note itself still renders');
            $this->assertStringNotContainsString(self::UNPAID, $html);
            $this->assertStringNotContainsString(self::PAID, $html);
        }
    }

    public function test_invoice_without_prepaid_minutes_shows_no_warning(): void
    {
        foreach ([null, 0] as $minutes) {
            foreach ([InvoiceStatus::Posted, InvoiceStatus::Paid] as $status) {
                $html = $this->staffPage($this->invoice($status, $minutes));

                $this->assertStringNotContainsString(self::UNPAID, $html);
                $this->assertStringNotContainsString(self::PAID, $html);
            }
        }
    }

    // ── log line ────────────────────────────────────────────────────────────

    private function exactContext(Invoice $invoice, int $minutes): \Closure
    {
        return fn ($context) => $context === ['invoice_id' => $invoice->id, 'minutes' => $minutes];
    }

    public function test_change_to_paid_logs_once_with_id_and_minutes_and_a_second_save_does_not(): void
    {
        $invoice = $this->invoice(InvoiceStatus::Posted, 150);
        InvoiceLine::create([
            'invoice_id' => $invoice->id, 'description' => 'Second synthetic block',
            'quantity' => '1.00', 'unit_price' => '50.00', 'amount' => '50.00',
            'prepaid_time_minutes' => 30, 'sort_order' => 1,
        ]);
        // The client HAS a prepay contract; it is just not linked. Nothing may
        // pick it up on its own: the deposit needs the link (no auto-link).
        $unlinked = $this->invoice(InvoiceStatus::Draft, 0, withContract: true)->contract;
        Log::spy();

        $invoice->update(['status' => InvoiceStatus::Paid]);

        Log::shouldHaveReceived('warning')
            ->with(self::LOG_MESSAGE, \Mockery::on($this->exactContext($invoice, 180)))
            ->once();

        // A later save of the paid invoice that does not change its status.
        $invoice->update(['notes' => 'Synthetic note after payment']);
        $invoice->fresh()->update(['due_date' => now()->addDays(40)]);

        Log::shouldHaveReceived('warning')->with(self::LOG_MESSAGE, \Mockery::any())->once();
        $this->assertSame(0, PrepayTransaction::count(), 'no deposit is posted without a contract');
        $this->assertNull($invoice->fresh()->contract_id, 'no contract is linked automatically');
        $this->assertEquals(0, (float) $unlinked->fresh()->prepay_balance);
    }

    public function test_no_log_line_without_minutes_with_a_contract_or_on_a_non_paid_change(): void
    {
        $noMinutes = $this->invoice(InvoiceStatus::Posted, 0);
        $nullMinutes = $this->invoice(InvoiceStatus::Posted, null);
        $contracted = $this->invoice(InvoiceStatus::Posted, 90, withContract: true);
        $voided = $this->invoice(InvoiceStatus::Posted, 90);
        $synced = $this->invoice(InvoiceStatus::Draft, 90);
        Log::spy();

        $noMinutes->update(['status' => InvoiceStatus::Paid]);
        $nullMinutes->update(['status' => InvoiceStatus::Paid]);
        $contracted->update(['status' => InvoiceStatus::Paid]);
        $voided->update(['status' => InvoiceStatus::Void]);
        $synced->update(['status' => InvoiceStatus::Synced]);

        Log::shouldNotHaveReceived('warning', [self::LOG_MESSAGE, \Mockery::any()]);
        // The contract path is unchanged: that invoice still gets its deposit.
        $this->assertSame(1, PrepayTransaction::where('invoice_id', $contracted->id)->count());
        $this->assertSame(1, PrepayTransaction::count());
    }

    // ── portal ──────────────────────────────────────────────────────────────

    public function test_portal_invoice_views_do_not_carry_either_sentence(): void
    {
        Setting::setValue('portal_enabled', '1');
        $person = Person::create([
            'client_id' => $this->client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Synthetic', 'last_name' => 'Portal',
            'email' => 'portal-8527@example.test',
            'is_active' => true, 'portal_enabled' => true, 'company_wide_access' => false,
        ]);
        $posted = $this->invoice(InvoiceStatus::Posted);
        $paid = $this->invoice(InvoiceStatus::Paid);

        // Positive control: the staff page carries both sentences for these rows.
        $this->assertStringContainsString(self::UNPAID, $this->staffPage($posted));
        $this->assertStringContainsString(self::PAID, $this->staffPage($paid));

        $pages = [
            $this->actingAs($person, 'portal')->get(route('portal.invoices.show', $posted))->assertOk()->getContent(),
            $this->actingAs($person, 'portal')->get(route('portal.invoices.show', $paid))->assertOk()->getContent(),
            $this->actingAs($person, 'portal')->get(route('portal.invoices.index'))->assertOk()->getContent(),
        ];

        $this->assertStringContainsString($paid->invoice_number, $pages[1], 'the portal page rendered this invoice');
        foreach ($pages as $i => $html) {
            $this->assertStringNotContainsString(self::UNPAID, $html, "portal page {$i}");
            $this->assertStringNotContainsString(self::PAID, $html, "portal page {$i}");
            $this->assertStringNotContainsString('No contract', $html, "portal page {$i}");
        }
    }
}
