<?php

namespace Tests\Feature\Invoices;

use App\Enums\QuantityType;
use App\Models\Asset;
use App\Models\BackupStorageTier;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\RecurringInvoiceProfile;
use App\Models\RecurringInvoiceProfileLine;
use App\Models\Sku;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TieredPricingBillingTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1073741824; // 1024^3 bytes

    private function contract(?Client $client = null): Contract
    {
        $client ??= Client::create(['name' => 'Acme Corp']);

        return Contract::create([
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
    }

    /**
     * Assert the invariant every generated invoice line must satisfy:
     * amount = round(quantity × unit_price, 2), and the invoice subtotal is the
     * exact sum of its lines. Stripe re-derives a taxable line's charge from
     * quantity × unit_amount and never reads our `amount`; QBO reconciles
     * `Amount` against `Qty × UnitPrice`. A line that breaks this bills the
     * client a different number than the invoice says.
     */
    private function assertLineInvariant(Invoice $invoice): void
    {
        $summed = 0.0;

        foreach ($invoice->lines as $line) {
            $expected = round((float) $line->quantity * (float) $line->unit_price, 2);

            $this->assertSame(
                $expected,
                round((float) $line->amount, 2),
                "Line '{$line->description}' broke amount = round(quantity × unit_price, 2)",
            );

            $summed += (float) $line->amount;
        }

        $this->assertSame(
            round($summed, 2),
            round((float) $invoice->subtotal, 2),
            'Invoice subtotal is not the exact sum of its line amounts',
        );
        $this->assertSame(
            round((float) $invoice->subtotal, 2),
            round((float) $invoice->total, 2),
        );
    }

    /** A backup-storage SKU carrying a VOLUME rate card (whole qty at one rate). */
    private function backupSkuWithVolumeTiers(): Sku
    {
        $sku = Sku::create([
            'name' => 'Cloud Backup Storage',
            'sku_code' => 'BKP-GB-'.fake()->unique()->numerify('###'),
            'unit_price' => '0.00',
            'unit_cost' => '0.00',
            'default_quantity_type' => QuantityType::PerBackupStorageGb,
            'is_taxable' => true,
            'is_active' => true,
        ]);

        foreach ([
            ['up_to_gb' => 100, 'unit_price' => '1.00'],
            ['up_to_gb' => 500, 'unit_price' => '0.80'],
            ['up_to_gb' => null, 'unit_price' => '0.60'],
        ] as $i => $tier) {
            BackupStorageTier::create([
                'sku_id' => $sku->id,
                'up_to_gb' => $tier['up_to_gb'],
                'unit_price' => $tier['unit_price'],
                'sort_order' => $i,
            ]);
        }

        return $sku;
    }

    /**
     * A 300 GB backup-storage profile line on the given SKU. Graduated bands
     * are applied to the line only when passed.
     */
    private function backupProfile(?array $graduatedTiers, Sku $sku): RecurringInvoiceProfile
    {
        $client = Client::factory()->create();
        Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => 200 * self::GB]);
        Asset::factory()->create(['client_id' => $client->id, 'backup_cloud_bytes' => 100 * self::GB]);

        $profile = $this->profile($this->contract($client));

        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'sku_id' => $sku->id,
            'description' => 'Cloud backup storage',
            'unit_price' => '9.99', // flat fallback; neither rate card should use it
            'pricing_tiers' => $graduatedTiers,
            'quantity_type' => QuantityType::PerBackupStorageGb,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        return $profile->fresh();
    }

    private function profile(Contract $contract): RecurringInvoiceProfile
    {
        return RecurringInvoiceProfile::create([
            'contract_id' => $contract->id,
            'name' => 'Tiered profile',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => '2026-08-01',
        ]);
    }

    public function test_generate_expands_a_tiered_line_into_one_invoice_line_per_band(): void
    {
        $profile = $this->profile($this->contract());

        // 25 units: first 10 @ $10 = $100, next 15 @ $8 = $120 => $220.
        // Unit cost $2 across all 25 units => $50 cost, $170 margin.
        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Managed Services',
            'unit_price' => 10,
            'pricing_tiers' => [
                ['up_to' => 10, 'unit_price' => 10],
                ['up_to' => null, 'unit_price' => 8],
            ],
            'quantity_type' => 'fixed',
            'fixed_quantity' => 25,
            'unit_cost_override' => 2,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        $result = app(BillingService::class)->generateInvoice($profile->fresh());

        $this->assertSame('created', $result['status']);
        $invoice = $result['invoice'];

        $this->assertCount(2, $invoice->lines);
        $this->assertEqualsWithDelta(220.0, (float) $invoice->subtotal, 0.001);
        $this->assertEqualsWithDelta(220.0, (float) $invoice->total, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $invoice->total_cost, 0.001);
        $this->assertEqualsWithDelta(170.0, (float) $invoice->margin, 0.001);

        // Every emitted line must satisfy amount = quantity × unit_price — the
        // invariant the Stripe/QBO push paths rely on.
        foreach ($invoice->lines as $line) {
            $this->assertEqualsWithDelta(
                (float) $line->quantity * (float) $line->unit_price,
                (float) $line->amount,
                0.001,
                "Line '{$line->description}' broke amount = qty × unit_price",
            );
        }

        $first = $invoice->lines->firstWhere('quantity', 10);
        $this->assertNotNull($first);
        $this->assertEqualsWithDelta(100.0, (float) $first->amount, 0.001);
        $this->assertStringContainsString('1'."\u{2013}".'10 units', $first->description);
        $this->assertStringContainsString('@ $10.00', $first->description);

        $second = $invoice->lines->firstWhere('quantity', 15);
        $this->assertNotNull($second);
        $this->assertEqualsWithDelta(120.0, (float) $second->amount, 0.001);
        $this->assertEqualsWithDelta(8.0, (float) $second->unit_price, 0.001);
        $this->assertStringContainsString('@ $8.00', $second->description);
    }

    public function test_flat_line_still_produces_a_single_line(): void
    {
        $profile = $this->profile($this->contract());

        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Flat service',
            'unit_price' => 50,
            'quantity_type' => 'fixed',
            'fixed_quantity' => 3,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        $result = app(BillingService::class)->generateInvoice($profile->fresh());

        $this->assertSame('created', $result['status']);
        $this->assertCount(1, $result['invoice']->lines);
        $this->assertEqualsWithDelta(150.0, (float) $result['invoice']->subtotal, 0.001);
        $this->assertSame('Flat service', $result['invoice']->lines->first()->description);
    }

    public function test_preview_shows_the_tier_breakdown(): void
    {
        $profile = $this->profile($this->contract());

        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Managed Services',
            'unit_price' => 10,
            'pricing_tiers' => [
                ['up_to' => 10, 'unit_price' => 10],
                ['up_to' => null, 'unit_price' => 8],
            ],
            'quantity_type' => 'fixed',
            'fixed_quantity' => 25,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        $preview = app(BillingService::class)->previewInvoice($profile->fresh());

        $this->assertCount(2, $preview['lines']);
        $this->assertEqualsWithDelta(220.0, $preview['subtotal'], 0.001);
        $this->assertSame(10, $preview['lines'][0]['quantity']);
        $this->assertSame(15, $preview['lines'][1]['quantity']);
    }

    public function test_store_persists_tiers_and_uses_first_band_as_base_price(): void
    {
        $contract = $this->contract();
        $this->actingAs(User::factory()->create());

        $response = $this->post(route('profiles.store', $contract), [
            'name' => 'Tiered MS',
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => '2026-09-01',
            'lines' => [
                [
                    'description' => 'Managed Services',
                    'unit_price' => 999, // ignored for tiered lines
                    'quantity_type' => 'fixed',
                    'fixed_quantity' => 25,
                    'is_taxable' => 1,
                    'pricing_tiers' => [
                        ['up_to' => 10, 'unit_price' => 10],
                        ['up_to' => '', 'unit_price' => 8],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();

        $line = RecurringInvoiceProfile::where('name', 'Tiered MS')
            ->firstOrFail()
            ->lines()
            ->firstOrFail();

        $this->assertTrue($line->isTiered());
        $tiers = $line->pricingTiers();
        $this->assertCount(2, $tiers);
        $this->assertSame(10, $tiers[0]['up_to']);
        $this->assertNull($tiers[1]['up_to'], 'Final tier must be unbounded.');
        // Base unit price is taken from the first band, not the submitted flat value.
        $this->assertEqualsWithDelta(10.0, (float) $line->unit_price, 0.001);
    }

    public function test_update_can_switch_a_line_between_tiered_and_flat(): void
    {
        $contract = $this->contract();
        $profile = $this->profile($contract);
        $flat = RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Managed Services',
            'unit_price' => 50,
            'quantity_type' => 'fixed',
            'fixed_quantity' => 5,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);
        $this->assertFalse($flat->isTiered());

        $this->actingAs(User::factory()->create());

        // Flat -> tiered.
        $this->patch(route('profiles.update', $profile), [
            'name' => $profile->name,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => '2026-08-01',
            'lines' => [
                [
                    'description' => 'Managed Services',
                    'unit_price' => 10,
                    'quantity_type' => 'fixed',
                    'fixed_quantity' => 25,
                    'is_taxable' => 1,
                    'pricing_tiers' => [
                        ['up_to' => 10, 'unit_price' => 10],
                        ['up_to' => '', 'unit_price' => 8],
                    ],
                ],
            ],
        ])->assertRedirect();

        $line = $profile->fresh()->lines()->firstOrFail();
        $this->assertTrue($line->isTiered());

        // Tiered -> flat (no tiers submitted clears the column).
        $this->patch(route('profiles.update', $profile), [
            'name' => $profile->name,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => '2026-08-01',
            'lines' => [
                [
                    'description' => 'Managed Services',
                    'unit_price' => 42,
                    'quantity_type' => 'fixed',
                    'fixed_quantity' => 25,
                    'is_taxable' => 1,
                ],
            ],
        ])->assertRedirect();

        $line = $profile->fresh()->lines()->firstOrFail();
        $this->assertFalse($line->isTiered());
        $this->assertNull($line->pricing_tiers);
        $this->assertEqualsWithDelta(42.0, (float) $line->unit_price, 0.001);
    }

    public function test_create_page_renders_the_tiered_pricing_control(): void
    {
        $contract = $this->contract();
        $this->actingAs(User::factory()->create());

        $this->get(route('profiles.create', $contract))
            ->assertOk()
            ->assertSee('Tiered pricing (graduated)');
    }

    public function test_show_page_renders_existing_tiers(): void
    {
        $profile = $this->profile($this->contract());
        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Managed Services',
            'unit_price' => 10,
            'pricing_tiers' => [
                ['up_to' => 10, 'unit_price' => 10],
                ['up_to' => null, 'unit_price' => 8],
            ],
            'quantity_type' => 'fixed',
            'fixed_quantity' => 25,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);
        $this->actingAs(User::factory()->create());

        $this->get(route('profiles.show', $profile))
            ->assertOk()
            ->assertSee('Tiered pricing (graduated)')
            ->assertSee('pricing_tiers');
    }

    // ── Graduated (line) vs volume (SKU) rate cards ──

    /**
     * The precedence rule. A backup-storage line can be caught by two rate
     * cards at once: GRADUATED bands on the line, and the VOLUME card on its
     * SKU. The line's own bands win.
     *
     * The fixture is built so the two models disagree on the money: the same
     * three rates, over the same 300 GB, are $260 graduated (100 @ $1.00 + 200
     *
     * @ $0.80) but $240 volume (the whole 300 billed at the single $0.80 tier
     * that covers it). Asserting $260 therefore proves graduated actually
     * priced the invoice — the test cannot pass if precedence flips.
     */
    public function test_graduated_line_tiers_take_precedence_over_the_skus_volume_rate_card(): void
    {
        $profile = $this->backupProfile([
            ['up_to' => 100, 'unit_price' => 1.00],
            ['up_to' => 500, 'unit_price' => 0.80],
            ['up_to' => null, 'unit_price' => 0.60],
        ], $this->backupSkuWithVolumeTiers());

        $result = app(BillingService::class)->generateInvoice($profile);
        $invoice = $result['invoice'];

        $this->assertSame('created', $result['status']);

        // Graduated: 100 GB @ $1.00 + 200 GB @ $0.80.
        $this->assertCount(2, $invoice->lines);
        $this->assertSame(260.00, round((float) $invoice->subtotal, 2));
        $this->assertNotSame(240.00, round((float) $invoice->subtotal, 2), 'Volume pricing won — precedence is inverted.');

        [$first, $second] = [$invoice->lines[0], $invoice->lines[1]];
        $this->assertSame(100.0, (float) $first->quantity);
        $this->assertSame(1.00, (float) $first->unit_price);
        $this->assertSame(100.00, (float) $first->amount);
        $this->assertSame(200.0, (float) $second->quantity);
        $this->assertSame(0.80, (float) $second->unit_price);
        $this->assertSame(160.00, (float) $second->amount);

        $this->assertLineInvariant($invoice);

        // The audit snapshot must name the card that actually priced the line,
        // never the one it overrode.
        $this->assertStringContainsString('[graduated: 3 bands]', $first->quantity_source);
        $this->assertStringNotContainsString('volume tier rate', $first->quantity_source);
        $this->assertStringContainsString('300 GB backup storage', $first->quantity_source);
    }

    /**
     * The other half of the precedence rule: with no graduated bands on the
     * line, the SKU's volume card prices it — the whole 300 GB at the single
     * $0.80 tier. Guards the volume path through generateInvoice(), which
     * previously only had preview-level coverage.
     */
    public function test_volume_rate_card_prices_a_backup_line_that_has_no_graduated_bands(): void
    {
        $profile = $this->backupProfile(null, $this->backupSkuWithVolumeTiers());

        $invoice = app(BillingService::class)->generateInvoice($profile)['invoice'];

        $this->assertCount(1, $invoice->lines);

        $line = $invoice->lines->first();
        $this->assertSame(300.0, (float) $line->quantity);
        $this->assertSame(0.80, (float) $line->unit_price);   // volume tier rate
        $this->assertSame(240.00, (float) $line->amount);     // 300 × 0.80, not 260
        $this->assertSame(240.00, round((float) $invoice->subtotal, 2));
        // Never the flat $9.99 fallback while a rate card exists.
        $this->assertNotSame(9.99, (float) $line->unit_price);

        $this->assertLineInvariant($invoice);
        $this->assertStringContainsString('[volume tier rate $0.80/GB]', $line->quantity_source);
        $this->assertStringNotContainsString('graduated', $line->quantity_source);
    }

    // ── Money: rounding, boundaries, and the per-line invariant ──

    /**
     * Sub-dollar rates through the full invoice path. Each band is rounded
     * independently, so this is where a split line could shed a cent.
     */
    public function test_cent_level_rates_survive_the_full_invoice_path(): void
    {
        $profile = $this->profile($this->contract());

        // 10 units: 3 @ $0.07 = $0.21, 4 @ $0.03 = $0.12, 3 @ $0.01 = $0.03 => $0.36.
        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Metered widgets',
            'unit_price' => 0.07,
            'pricing_tiers' => [
                ['up_to' => 3, 'unit_price' => 0.07],
                ['up_to' => 7, 'unit_price' => 0.03],
                ['up_to' => null, 'unit_price' => 0.01],
            ],
            'quantity_type' => 'fixed',
            'fixed_quantity' => 10,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        $invoice = app(BillingService::class)->generateInvoice($profile->fresh())['invoice'];

        $this->assertCount(3, $invoice->lines);
        $this->assertSame(
            [0.21, 0.12, 0.03],
            $invoice->lines->map(fn ($l) => (float) $l->amount)->all(),
        );
        $this->assertSame(0.36, round((float) $invoice->subtotal, 2));
        $this->assertLineInvariant($invoice);
    }

    /**
     * Cost and prepaid minutes are apportioned per band. Split across bands,
     * they must still sum to what the un-split line would have carried —
     * profitability (margin) and the prepay ledger both read these.
     */
    public function test_cost_and_prepaid_minutes_split_across_bands_sum_to_the_whole_line(): void
    {
        $profile = $this->profile($this->contract());

        // 25 units: 10 @ $10 + 15 @ $8 = $220 revenue.
        // Cost $0.33/unit => 25 × 0.33 = $8.25. Prepaid 6 min/unit => 150 min.
        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Managed Services',
            'unit_price' => 10,
            'pricing_tiers' => [
                ['up_to' => 10, 'unit_price' => 10],
                ['up_to' => null, 'unit_price' => 8],
            ],
            'quantity_type' => 'fixed',
            'fixed_quantity' => 25,
            'unit_cost_override' => 0.33,
            'prepaid_time_override' => 6,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        $invoice = app(BillingService::class)->generateInvoice($profile->fresh())['invoice'];

        $this->assertCount(2, $invoice->lines);
        $this->assertSame([3.30, 4.95], $invoice->lines->map(fn ($l) => (float) $l->cost_amount)->all());
        $this->assertSame([60, 90], $invoice->lines->map(fn ($l) => (int) $l->prepaid_time_minutes)->all());

        // The bands add up to the whole line — no cent, no minute lost.
        $this->assertSame(8.25, round((float) $invoice->total_cost, 2));
        $this->assertSame(8.25, round(25 * 0.33, 2));
        $this->assertSame(150, (int) $invoice->lines->sum('prepaid_time_minutes'));
        $this->assertSame(211.75, round((float) $invoice->margin, 2)); // 220.00 − 8.25

        $this->assertLineInvariant($invoice);
    }

    /**
     * A graduated line whose quantity resolves to zero still emits one line at
     * zero units — the "record of coverage" behaviour flat lines already have.
     */
    public function test_zero_quantity_graduated_line_emits_a_single_zero_unit_line(): void
    {
        $profile = $this->profile($this->contract());

        // No workstations exist for this client, so the quantity resolves to 0.
        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Per-workstation support',
            'unit_price' => 10,
            'pricing_tiers' => [
                ['up_to' => 10, 'unit_price' => 10],
                ['up_to' => null, 'unit_price' => 8],
            ],
            'quantity_type' => 'per_workstation',
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        $invoice = app(BillingService::class)->generateInvoice($profile->fresh())['invoice'];

        $this->assertCount(1, $invoice->lines);

        $line = $invoice->lines->first();
        $this->assertSame(0.0, (float) $line->quantity);
        $this->assertSame(0.0, (float) $line->amount);
        $this->assertSame(10.0, (float) $line->unit_price); // first band
        // No band range is annotated when nothing was consumed.
        $this->assertSame('Per-workstation support', $line->description);
        $this->assertSame(0.00, round((float) $invoice->subtotal, 2));

        $this->assertLineInvariant($invoice);
    }

    /**
     * Every emitted invoice line needs its own sort_order. Both QBO push
     * (buildQboInvoice) and QBO readback (syncLineItemsFromQbo) pair PSA lines
     * to QBO lines by their sort_order *position*, so bands that tie on
     * sort_order would order non-deterministically and a readback could write
     * one band's amounts onto another band's row.
     */
    public function test_expanded_bands_get_distinct_ascending_sort_orders(): void
    {
        $profile = $this->profile($this->contract());

        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Flat onboarding',
            'unit_price' => 50,
            'quantity_type' => 'fixed',
            'fixed_quantity' => 2,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        // 40 units: 10 @ $10 + 20 @ $8 + 10 @ $5 = $310.
        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Managed Services',
            'unit_price' => 10,
            'pricing_tiers' => [
                ['up_to' => 10, 'unit_price' => 10],
                ['up_to' => 30, 'unit_price' => 8],
                ['up_to' => null, 'unit_price' => 5],
            ],
            'quantity_type' => 'fixed',
            'fixed_quantity' => 40,
            'is_taxable' => true,
            'sort_order' => 1,
        ]);

        $invoice = app(BillingService::class)->generateInvoice($profile->fresh())['invoice'];

        $this->assertCount(4, $invoice->lines);

        $sortOrders = $invoice->lines->map(fn ($l) => (int) $l->sort_order)->all();
        $this->assertSame([0, 1, 2, 3], $sortOrders);
        $this->assertSame($sortOrders, array_values(array_unique($sortOrders)), 'Bands tied on sort_order.');

        // Profile-line order is preserved, and bands ascend within their line.
        $this->assertSame('Flat onboarding', $invoice->lines[0]->description);
        $this->assertSame([2.0, 10.0, 20.0, 10.0], $invoice->lines->map(fn ($l) => (float) $l->quantity)->all());
        $this->assertSame(410.00, round((float) $invoice->subtotal, 2)); // 100 + 310

        $this->assertLineInvariant($invoice);
    }

    // ── Band labels ──

    /**
     * Band labels land on a CLIENT-FACING invoice. Where the quantity type knows
     * what it is counting, the label says so ("1–100 GB", "1–3 workstations")
     * rather than making the client guess at a generic "units". Where it does not
     * (Fixed), "units" remains the honest fallback.
     */
    public function test_band_labels_name_the_quantity_domain_where_it_is_known(): void
    {
        $client = Client::factory()->create();
        // The backup host is a Server, so it is not also counted as a workstation.
        Asset::factory()->create(['client_id' => $client->id, 'asset_type' => 'Server', 'backup_cloud_bytes' => 300 * self::GB]);
        Asset::factory()->count(3)->create(['client_id' => $client->id, 'asset_type' => 'Workstation', 'is_active' => true]);

        $profile = $this->profile($this->contract($client));

        // 300 GB: 100 @ $1.00 + 200 @ $0.80 = $260. No SKU, so no volume card is in play.
        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Cloud backup storage',
            'unit_price' => 1.00,
            'pricing_tiers' => [
                ['up_to' => 100, 'unit_price' => 1.00],
                ['up_to' => null, 'unit_price' => 0.80],
            ],
            'quantity_type' => QuantityType::PerBackupStorageGb,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        // 3 workstations, all in the first band: 3 @ $50 = $150.
        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Managed workstation',
            'unit_price' => 50,
            'pricing_tiers' => [
                ['up_to' => 10, 'unit_price' => 50],
                ['up_to' => null, 'unit_price' => 40],
            ],
            'quantity_type' => QuantityType::PerWorkstation,
            'is_taxable' => true,
            'sort_order' => 1,
        ]);

        // Fixed has no known domain: 12 of *something*. 5 @ $9 + 7 @ $6 = $87.
        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Widgets',
            'unit_price' => 9,
            'pricing_tiers' => [
                ['up_to' => 5, 'unit_price' => 9],
                ['up_to' => null, 'unit_price' => 6],
            ],
            'quantity_type' => QuantityType::Fixed,
            'fixed_quantity' => 12,
            'is_taxable' => true,
            'sort_order' => 2,
        ]);

        $invoice = app(BillingService::class)->generateInvoice($profile->fresh())['invoice'];

        $descriptions = $invoice->lines->map(fn ($l) => $l->description)->all();

        $this->assertSame([
            'Cloud backup storage (1'."\u{2013}".'100 GB @ $1.00)',
            'Cloud backup storage (101'."\u{2013}".'300 GB @ $0.80)',
            'Managed workstation (1'."\u{2013}".'3 workstations @ $50.00)',
            'Widgets (1'."\u{2013}".'5 units @ $9.00)',
            'Widgets (6'."\u{2013}".'12 units @ $6.00)',
        ], $descriptions);

        // The labels describe money that was actually billed.
        $this->assertSame(
            [100.00, 160.00, 150.00, 45.00, 42.00],
            $invoice->lines->map(fn ($l) => round((float) $l->amount, 2))->all(),
        );
        $this->assertSame(497.00, round((float) $invoice->subtotal, 2));
        $this->assertLineInvariant($invoice);
    }

    /**
     * A Fixed-quantity line records no quantity_source — the operator typed the
     * number, there is nothing to audit about *how much*. But Fixed + graduated
     * is the commonest graduated config there is, and the audit record must not
     * be silent about the rate card that priced it.
     */
    public function test_a_fixed_graduated_line_still_records_the_rate_card_that_priced_it(): void
    {
        $profile = $this->profile($this->contract());

        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Managed Services',
            'unit_price' => 10,
            'pricing_tiers' => [
                ['up_to' => 10, 'unit_price' => 10],
                ['up_to' => null, 'unit_price' => 8],
            ],
            'quantity_type' => 'fixed',
            'fixed_quantity' => 25,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        $invoice = app(BillingService::class)->generateInvoice($profile->fresh())['invoice'];

        $this->assertSame(220.00, round((float) $invoice->subtotal, 2));

        foreach ($invoice->lines as $line) {
            $this->assertSame('[graduated: 2 bands]', $line->quantity_source);
        }
    }

    /** A Fixed line priced flat still records nothing — main's behaviour, unchanged. */
    public function test_a_fixed_flat_line_records_no_quantity_source(): void
    {
        $profile = $this->profile($this->contract());

        RecurringInvoiceProfileLine::create([
            'profile_id' => $profile->id,
            'description' => 'Flat service',
            'unit_price' => 50,
            'quantity_type' => 'fixed',
            'fixed_quantity' => 3,
            'is_taxable' => true,
            'sort_order' => 0,
        ]);

        $invoice = app(BillingService::class)->generateInvoice($profile->fresh())['invoice'];

        $this->assertSame(150.00, round((float) $invoice->subtotal, 2));
        $this->assertNull($invoice->lines->first()->quantity_source);
    }
}
