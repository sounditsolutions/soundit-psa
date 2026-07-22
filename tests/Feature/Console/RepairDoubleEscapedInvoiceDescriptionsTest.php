<?php

namespace Tests\Feature\Console;

use App\Enums\InvoiceStatus;
use App\Enums\QuantityType;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceLineDescriptionRepair;
use App\Models\Sku;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * invoices:repair-double-escaped-descriptions (psa-946hr).
 *
 * The pre-psa-951q SKU picker double-escaped SKU names into the description
 * input, so invoice_lines.description persisted single-encoded HTML entities
 * ('Acme &amp; Co'). Charlie ruled option (b): repair ONLY draft, unsynced
 * invoices — issued/finalized invoices are the historical record and must
 * never be touched. The load-bearing test here is the prove-the-negative
 * case: rows under every non-draft status (and invalid statuses, and drafts
 * that somehow carry an external billing id) are never modified.
 */
class RepairDoubleEscapedInvoiceDescriptionsTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'invoices:repair-double-escaped-descriptions';

    private static int $invoiceSeq = 0;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeInvoice(array $attrs = []): Invoice
    {
        $attrs['client_id'] ??= Client::factory()->create()->id;

        return Invoice::create(array_merge([
            'invoice_number' => 'INV-946HR-'.str_pad((string) ++self::$invoiceSeq, 4, '0', STR_PAD_LEFT),
            'invoice_date' => now()->subDays(5),
            'due_date' => now()->addDays(25),
            'status' => InvoiceStatus::Draft,
        ], $attrs));
    }

    private function makeLine(Invoice $invoice, string $description, array $attrs = []): InvoiceLine
    {
        return InvoiceLine::create(array_merge([
            'invoice_id' => $invoice->id,
            'description' => $description,
            'quantity' => 1,
            'unit_price' => '10.00',
            'amount' => '10.00',
        ], $attrs));
    }

    /** Byte-exact current description straight from the DB, bypassing casts. */
    private function rawDescription(int $lineId): string
    {
        return DB::table('invoice_lines')->where('id', $lineId)->value('description');
    }

    // -------------------------------------------------------------------------
    // The load-bearing guard: prove the negative
    // -------------------------------------------------------------------------

    public function test_finalized_and_unknown_status_rows_are_never_modified(): void
    {
        $corrupted = 'Acme &amp; Co Backup';

        // One corrupted line under every non-draft enum status.
        $untouchable = [];
        foreach ([
            InvoiceStatus::PendingSync,
            InvoiceStatus::Synced,
            InvoiceStatus::Posted,
            InvoiceStatus::Paid,
            InvoiceStatus::Void,
        ] as $status) {
            $untouchable[] = $this->makeLine($this->makeInvoice(['status' => $status]), $corrupted);
        }

        // Invalid statuses the enum cast would never write — seeded raw to
        // prove the selector is an allowlist, not a denylist. A NULL status is
        // schema-impossible (see the companion test below).
        foreach (['', 'bogus'] as $rawStatus) {
            $invoice = $this->makeInvoice();
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => $rawStatus]);
            $untouchable[] = $this->makeLine($invoice, $corrupted);
        }

        // A soft-deleted draft: excluded.
        $trashedDraft = $this->makeInvoice();
        $untouchable[] = $this->makeLine($trashedDraft, $corrupted);
        $trashedDraft->delete();

        // Drafts that anomalously carry an external billing id: excluded
        // (fail closed — "draft" status alone is not proof it never left).
        $untouchable[] = $this->makeLine($this->makeInvoice(['qbo_invoice_id' => 'QBO-123']), $corrupted);
        $untouchable[] = $this->makeLine($this->makeInvoice(['stripe_invoice_id' => 'in_test_123']), $corrupted);

        $this->artisan(self::COMMAND, ['--write' => true, '--yes' => true])
            ->assertSuccessful();

        foreach ($untouchable as $line) {
            $this->assertSame($corrupted, $this->rawDescription($line->id));
        }
        $this->assertSame(0, InvoiceLineDescriptionRepair::count());
    }

    public function test_null_status_cannot_exist_under_the_schema(): void
    {
        // Documents why the negative-proof test cannot seed a NULL status: the
        // column is NOT NULL, so the state the fail-closed filter would have to
        // defend against cannot be created in the first place. If this ever
        // starts passing NULL, the allowlist selector must be re-proven.
        $invoice = $this->makeInvoice();

        $this->expectException(QueryException::class);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => null]);
    }

    // -------------------------------------------------------------------------
    // The positive: draft rows are repaired, one decode level, with a ledger
    // -------------------------------------------------------------------------

    public function test_draft_corrupted_lines_are_repaired_with_write(): void
    {
        $invoice = $this->makeInvoice();
        $cases = [
            'Acme &amp; Co Backup' => 'Acme & Co Backup',
            'Bob&#039;s Widgets' => "Bob's Widgets",
            '&quot;Premium&quot; Plan' => '"Premium" Plan',
            'SLA &lt;24h&gt; Response' => 'SLA <24h> Response',
        ];

        $lines = [];
        foreach (array_keys($cases) as $before) {
            $lines[$before] = $this->makeLine($invoice, $before);
        }

        $this->artisan(self::COMMAND, ['--write' => true, '--yes' => true])
            ->expectsOutputToContain('Repaired 4')
            ->assertSuccessful();

        foreach ($cases as $before => $after) {
            $line = $lines[$before];
            $this->assertSame($after, $this->rawDescription($line->id));

            $ledger = InvoiceLineDescriptionRepair::where('invoice_line_id', $line->id)->first();
            $this->assertNotNull($ledger);
            $this->assertSame($before, $ledger->description_before);
            $this->assertSame($after, $ledger->description_after);
            $this->assertSame('draft', $ledger->invoice_status_at_repair);
            $this->assertNull($ledger->reverted_at);
        }
    }

    public function test_default_run_is_a_dry_run_that_reports_but_writes_nothing(): void
    {
        $line = $this->makeLine($this->makeInvoice(), 'Acme &amp; Co Backup');

        // Before and after are asserted as ONE substring: they share an output
        // line, and each written line can only satisfy a single
        // expectsOutputToContain expectation.
        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('"Acme &amp; Co Backup" -> "Acme & Co Backup"')
            ->assertSuccessful();

        $this->assertSame('Acme &amp; Co Backup', $this->rawDescription($line->id));
        $this->assertSame(0, InvoiceLineDescriptionRepair::count());
    }

    public function test_write_without_confirmation_refuses_to_mutate(): void
    {
        $line = $this->makeLine($this->makeInvoice(), 'Acme &amp; Co Backup');

        $this->artisan(self::COMMAND, ['--write' => true])
            ->expectsConfirmation('Apply 1 repair(s) to draft invoice lines?', 'no')
            ->assertSuccessful();

        $this->assertSame('Acme &amp; Co Backup', $this->rawDescription($line->id));
        $this->assertSame(0, InvoiceLineDescriptionRepair::count());
    }

    public function test_clean_and_raw_special_characters_are_not_selected(): void
    {
        $invoice = $this->makeInvoice();
        $clean = [
            'Managed Services',
            'Acme & Co Backup',        // raw ampersand — already correct
            "Bob's Widgets",           // raw apostrophe — already correct
            'Uptime > 99.9% <SLA>',    // raw angle brackets — already correct
        ];

        $lines = [];
        foreach ($clean as $description) {
            $lines[] = $this->makeLine($invoice, $description);
        }

        $this->artisan(self::COMMAND, ['--write' => true, '--yes' => true])
            ->assertSuccessful();

        foreach ($lines as $i => $line) {
            $this->assertSame($clean[$i], $this->rawDescription($line->id));
        }
        $this->assertSame(0, InvoiceLineDescriptionRepair::count());
    }

    // -------------------------------------------------------------------------
    // Idempotency: at most one decode, ever
    // -------------------------------------------------------------------------

    public function test_repair_never_decodes_a_row_twice(): void
    {
        // A SKU whose real name legitimately contains entity-looking text.
        // The picker corruption added exactly one encode level; one decode
        // restores the real name — which still LOOKS corrupted. The ledger
        // must stop a second run from decoding it again.
        $line = $this->makeLine($this->makeInvoice(), 'Say &amp;amp; in HTML');

        $this->artisan(self::COMMAND, ['--write' => true, '--yes' => true])->assertSuccessful();
        $this->assertSame('Say &amp; in HTML', $this->rawDescription($line->id));

        $this->artisan(self::COMMAND, ['--write' => true, '--yes' => true])
            ->expectsOutputToContain('already repaired')
            ->assertSuccessful();

        $this->assertSame('Say &amp; in HTML', $this->rawDescription($line->id));
        $this->assertSame(1, InvoiceLineDescriptionRepair::count());
    }

    public function test_sku_provenance_is_annotated_in_the_report(): void
    {
        $sku = Sku::create([
            'name' => 'Acme & Co Backup',
            'sku_code' => 'PSA946HR-SKU',
            'unit_price' => '10.00',
            'unit_cost' => '4.00',
            'default_quantity_type' => QuantityType::Fixed,
            'is_taxable' => true,
            'is_active' => true,
        ]);
        $this->makeLine($this->makeInvoice(), 'Acme &amp; Co Backup', ['sku_id' => $sku->id]);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('matches once-escaped SKU name')
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // Revert
    // -------------------------------------------------------------------------

    public function test_revert_restores_the_pre_repair_description(): void
    {
        $line = $this->makeLine($this->makeInvoice(), 'Acme &amp; Co Backup');

        $this->artisan(self::COMMAND, ['--write' => true, '--yes' => true])->assertSuccessful();
        $this->assertSame('Acme & Co Backup', $this->rawDescription($line->id));

        // Revert without --write is itself a dry run.
        $this->artisan(self::COMMAND, ['--revert' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();
        $this->assertSame('Acme & Co Backup', $this->rawDescription($line->id));

        $this->artisan(self::COMMAND, ['--revert' => true, '--write' => true, '--yes' => true])
            ->assertSuccessful();

        $this->assertSame('Acme &amp; Co Backup', $this->rawDescription($line->id));
        $this->assertNotNull(InvoiceLineDescriptionRepair::where('invoice_line_id', $line->id)->first()->reverted_at);

        // A second revert finds nothing left to restore.
        $this->artisan(self::COMMAND, ['--revert' => true, '--write' => true, '--yes' => true])
            ->assertSuccessful();
        $this->assertSame('Acme &amp; Co Backup', $this->rawDescription($line->id));
    }

    public function test_revert_skips_rows_edited_after_repair(): void
    {
        $line = $this->makeLine($this->makeInvoice(), 'Acme &amp; Co Backup');

        $this->artisan(self::COMMAND, ['--write' => true, '--yes' => true])->assertSuccessful();

        DB::table('invoice_lines')->where('id', $line->id)->update(['description' => 'Hand-edited later']);

        $this->artisan(self::COMMAND, ['--revert' => true, '--write' => true, '--yes' => true])
            ->expectsOutputToContain('edited since repair')
            ->assertSuccessful();

        $this->assertSame('Hand-edited later', $this->rawDescription($line->id));
        $this->assertNull(InvoiceLineDescriptionRepair::where('invoice_line_id', $line->id)->first()->reverted_at);
    }

    public function test_revert_refuses_once_the_invoice_left_draft(): void
    {
        // The same option-(b) ruling applies in reverse: once an invoice is
        // finalized, its lines are the historical record — even to undo our
        // own earlier repair.
        $invoice = $this->makeInvoice();
        $line = $this->makeLine($invoice, 'Acme &amp; Co Backup');

        $this->artisan(self::COMMAND, ['--write' => true, '--yes' => true])->assertSuccessful();

        $invoice->update(['status' => InvoiceStatus::Posted]);

        $this->artisan(self::COMMAND, ['--revert' => true, '--write' => true, '--yes' => true])
            ->expectsOutputToContain('no longer a repairable draft')
            ->assertSuccessful();

        $this->assertSame('Acme & Co Backup', $this->rawDescription($line->id));
        $this->assertNull(InvoiceLineDescriptionRepair::where('invoice_line_id', $line->id)->first()->reverted_at);
    }

    // -------------------------------------------------------------------------
    // Reporting: the survey doubles as the prod sizing query
    // -------------------------------------------------------------------------

    public function test_survey_reports_counts_across_all_statuses(): void
    {
        $this->makeLine($this->makeInvoice(), 'Acme &amp; Co Backup');
        $this->makeLine($this->makeInvoice(['status' => InvoiceStatus::Paid, 'qbo_invoice_id' => 'QBO-9']), 'Bob&#039;s Widgets');
        $this->makeLine($this->makeInvoice(['status' => InvoiceStatus::Posted, 'stripe_invoice_id' => 'in_test_9']), '&quot;Premium&quot; Plan');

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('3 matching line(s)')
            ->expectsOutputToContain('1 line(s) eligible for repair')
            ->assertSuccessful();
    }
}
