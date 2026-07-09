<?php

namespace Tests\Feature\Invoices;

use App\Models\Client;
use App\Models\Contract;
use App\Models\RecurringInvoiceProfile;
use App\Models\RecurringInvoiceProfileLine;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TieredPricingBillingTest extends TestCase
{
    use RefreshDatabase;

    private function contract(): Contract
    {
        $client = Client::create(['name' => 'Acme Corp']);

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
        $this->assertStringContainsString('units 1', $first->description);
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
}
