<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\QuantityType;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsInertJsData;
use Tests\TestCase;

/**
 * psa-951q — the SAME defect the profile line editors had, in a FOURTH file the
 * original fix did not touch: invoices/_line_scripts, shared by invoices/create
 * and invoices/edit.
 *
 * It built its SKU <option> list inside a JavaScript TEMPLATE LITERAL:
 *
 *     const html = `... <option ... data-description="{{ e($s->name) }}">
 *                       {{ $s->sku_code }} &mdash; {{ $s->name }}</option> ...`;
 *
 * Blade's {{ }} escapes for HTML, and htmlspecialchars() does not touch a
 * backtick, a dollar sign or a brace — all three of which are syntax inside a
 * backtick literal. So an operator-entered SKU name carrying a backtick CLOSED
 * the literal and one carrying ${...} was EVALUATED as the string was built, in
 * the staff browser, on "Add Line".
 *
 * The assertions live in Tests\Support\AssertsInertJsData, shared with
 * ProfileLineOptionsJsContextTest so the two screens cannot drift apart on what
 * "inert" means or on which hostile inputs get tried.
 *
 * @see \Tests\Feature\Profiles\ProfileLineOptionsJsContextTest
 */
class InvoiceLineOptionsJsContextTest extends TestCase
{
    use AssertsInertJsData;
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileLabels')]
    public function test_invoice_create_form_keeps_hostile_sku_labels_out_of_js_code_position(string $label): void
    {
        $sku = $this->sku($label);

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('invoices.create'))->assertOk()->getContent();

        // The visible label AND the data-description attribute both carry the name.
        $this->assertLabelIsInertJsData($html, [$sku->sku_code.' — '.$label, $label], 'invoices.create');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileLabels')]
    public function test_invoice_edit_form_keeps_hostile_sku_labels_out_of_js_code_position(string $label): void
    {
        $sku = $this->sku($label);
        $invoice = $this->editableInvoice();

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('invoices.edit', $invoice))->assertOk()->getContent();

        $this->assertLabelIsInertJsData($html, [$sku->sku_code.' — '.$label, $label], 'invoices.edit');
    }

    /**
     * The option data is what prices the line: onSkuSelected() copies these four
     * dataset entries straight into the description, unit price, unit cost and
     * taxable inputs. A fix that made the label inert but renamed or dropped one
     * of them would silently MIS-PRICE an invoice line, so the attribute names
     * are pinned here against their actual consumer.
     */
    public function test_sku_option_data_carries_every_attribute_on_skuselected_reads(): void
    {
        $sku = Sku::create([
            'name' => 'Managed Workstation',
            'sku_code' => 'PSA951Q-PRICED',
            'unit_price' => '123.45',
            'unit_cost' => '67.89',
            'default_quantity_type' => QuantityType::Fixed,
            'is_taxable' => true,
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('invoices.create'))->assertOk()->getContent();

        $option = $this->skuOptionFor($html, (string) $sku->id);

        // Keys are the dataset property names fillSelectOptions assigns, so each
        // must be exactly what onSkuSelected reads back off opt.dataset.
        $this->assertSame('123.45', $option['data']['price'] ?? null, 'opt.dataset.price feeds .price-input');
        $this->assertSame('67.89', $option['data']['cost'] ?? null, 'opt.dataset.cost feeds .cost-input');
        $this->assertSame('1', $option['data']['taxable'] ?? null, 'opt.dataset.taxable feeds .taxable-check');
        $this->assertSame('Managed Workstation', $option['data']['description'] ?? null, 'opt.dataset.description feeds .desc-input');

        $this->assertSame('PSA951Q-PRICED — Managed Workstation', $option['label']);
    }

    /**
     * A zero-cost, non-taxable SKU must round-trip its falsy-looking values as
     * the same STRINGS the old markup produced. onSkuSelected compares
     * `opt.dataset.taxable === '1'`, so a boolean false arriving as JSON `false`
     * (or as the string "") would silently flip the tax checkbox's meaning.
     */
    public function test_sku_option_data_keeps_zero_and_false_as_the_strings_the_js_compares(): void
    {
        $sku = Sku::create([
            'name' => 'Pass-through',
            'sku_code' => 'PSA951Q-FREE',
            'unit_price' => '0.00',
            'unit_cost' => '0.00',
            'default_quantity_type' => QuantityType::Fixed,
            'is_taxable' => false,
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('invoices.create'))->assertOk()->getContent();

        $option = $this->skuOptionFor($html, (string) $sku->id);

        $this->assertSame('0.00', $option['data']['cost'] ?? null);
        $this->assertSame('0.00', $option['data']['price'] ?? null);
        // Strictly '0', not false and not '' — the JS tests `=== '1'`.
        $this->assertSame('0', $option['data']['taxable'] ?? null);
    }

    /**
     * invoices/edit renders existing rows server-side (_line_row) and new rows
     * client-side (_line_scripts), and onSkuSelected serves BOTH. They must
     * therefore agree byte-for-byte on what a SKU's description is.
     *
     * _line_row escaped it twice — e() inside {{ }} — so the HTML parser handed
     * onSkuSelected a still-encoded 'Acme &amp; Co', which then got copied into
     * the description input and SAVED to the invoice line. The data island does
     * not double-escape, so without this the same screen produces two different
     * descriptions for one SKU depending on which row you use.
     */
    public function test_server_rendered_and_js_added_rows_agree_on_the_description(): void
    {
        $sku = Sku::create([
            'name' => 'Acme & Co "Pro" <Bundle>',
            'sku_code' => 'PSA951Q-AMP',
            'unit_price' => '10.00',
            'unit_cost' => '4.00',
            'default_quantity_type' => QuantityType::Fixed,
            'is_taxable' => true,
            'is_active' => true,
        ]);
        $invoice = $this->editableInvoice();

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('invoices.edit', $invoice))->assertOk()->getContent();

        // What a browser would hand onSkuSelected off the server-rendered row.
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        $serverDescription = null;

        foreach ((new \DOMXPath($doc))->query('//option[@data-description]') as $option) {
            if ($option->getAttribute('value') === (string) $sku->id) {
                $serverDescription = $option->getAttribute('data-description');
                break;
            }
        }

        $this->assertNotNull($serverDescription, 'no server-rendered option carried a data-description');

        $this->assertSame(
            $sku->name,
            $serverDescription,
            'the server-rendered row hands onSkuSelected a re-encoded description, which is then saved to the line'
        );

        $this->assertSame(
            $serverDescription,
            $this->skuOptionFor($html, (string) $sku->id)['data']['description'],
            'server-rendered rows and JS-added rows disagree on the same SKU description'
        );
    }

    // ------------------------------------------------------------------ helpers

    /** Pull one SKU's entry out of the rendered SKU_OPTIONS data island. */
    private function skuOptionFor(string $html, string $skuId): array
    {
        $this->assertMatchesRegularExpression(
            '/const SKU_OPTIONS = (\[.*?\]);/s',
            $html,
            'invoices.create: no SKU_OPTIONS data island found — the option list is not being shipped as data.'
        );

        preg_match('/const SKU_OPTIONS = (\[.*?\]);/s', $html, $m);
        $options = json_decode($m[1], true);

        $this->assertIsArray($options, 'SKU_OPTIONS did not decode as JSON.');

        foreach ($options as $option) {
            if (($option['value'] ?? null) === $skuId) {
                return $option;
            }
        }

        $this->fail("SKU {$skuId} was not present in the SKU_OPTIONS data island.");
    }

    // ----------------------------------------------------------------- fixtures

    /** A SKU whose operator-entered name is hostile. */
    private function sku(string $name): Sku
    {
        return Sku::create([
            'name' => $name,
            'sku_code' => 'PSA951Q-SKU',
            'unit_price' => '10.00',
            'unit_cost' => '4.00',
            'default_quantity_type' => QuantityType::Fixed,
            'is_taxable' => true,
            'is_active' => true,
        ]);
    }

    /**
     * An invoice the edit screen will actually render — Posted, not Stripe-synced,
     * carrying a line so the server-rendered rows and the JS "Add Line" path are
     * both exercised on the same page.
     */
    private function editableInvoice(): Invoice
    {
        $invoice = Invoice::create([
            'client_id' => Client::factory()->create()->id,
            'invoice_number' => 'INV-PSA951Q-1',
            'invoice_date' => now()->subDays(3),
            'due_date' => now()->addDays(27),
            'subtotal' => '100.00',
            'tax' => '0.00',
            'total' => '100.00',
            'status' => InvoiceStatus::Posted,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Existing line',
            'quantity' => 1,
            'unit_price' => '100.00',
            'amount' => '100.00',
            'sort_order' => 0,
        ]);

        $this->assertTrue($invoice->fresh()->is_editable, 'fixture invoice must be editable or the view never renders');

        return $invoice->fresh();
    }
}
