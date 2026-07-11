<?php

namespace Tests\Feature\Portal;

use App\Enums\InvoiceStatus;
use App\Enums\PersonType;
use App\Jobs\SendTicketNotification;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Covers the client-portal product catalog ("Shop"): catalog visibility,
 * gating, order → invoice creation, TOCTOU/validation guards, staff
 * notification, and cross-client confirmation authorization.
 */
class PortalShopTest extends TestCase
{
    use RefreshDatabase;

    private int $skuSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Portal routes are gated by PortalEnabled (404 when off); the shop has
        // its own opt-in gate on top.
        Setting::setValue('portal_enabled', '1');
        Setting::setValue('portal_shop_enabled', '1');
    }

    private function client(string $name = 'Acme Corp'): Client
    {
        return Client::create(['name' => $name]);
    }

    private function portalPerson(Client $client, array $overrides = []): Person
    {
        return Person::create(array_merge([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal',
            'last_name' => 'User',
            'email' => 'portal-user-'.uniqid().'@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => false,
        ], $overrides));
    }

    private function sku(array $overrides = []): Sku
    {
        $this->skuSeq++;

        return Sku::create(array_merge([
            'name' => 'Widget '.$this->skuSeq,
            'sku_code' => 'WIDGET-'.$this->skuSeq,
            'category' => 'Hardware',
            'unit_price' => 100.00,
            'unit_cost' => 60.00,
            'is_taxable' => true,
            'is_active' => true,
            'portal_orderable' => true,
        ], $overrides));
    }

    public function test_shop_requires_authentication(): void
    {
        // Unauthenticated access must not reach the catalog.
        $this->get(route('portal.shop.index'))->assertRedirect();
    }

    public function test_shop_disabled_returns_404(): void
    {
        Setting::setValue('portal_shop_enabled', '0');
        $person = $this->portalPerson($this->client());

        $this->actingAs($person, 'portal')
            ->get(route('portal.shop.index'))
            ->assertNotFound();
    }

    public function test_catalog_shows_only_orderable_active_skus(): void
    {
        $person = $this->portalPerson($this->client());

        $this->sku(['name' => 'Orderable Laptop']);
        $this->sku(['name' => 'Internal Only', 'portal_orderable' => false]);
        $this->sku(['name' => 'Retired Item', 'is_active' => false]);

        $this->actingAs($person, 'portal')
            ->get(route('portal.shop.index'))
            ->assertOk()
            ->assertSee('Orderable Laptop')
            ->assertDontSee('Internal Only')
            ->assertDontSee('Retired Item');
    }

    public function test_placing_an_order_creates_a_posted_invoice_with_lines(): void
    {
        Bus::fake();
        $client = $this->client();
        $person = $this->portalPerson($client);
        $skuA = $this->sku(['unit_price' => 100.00, 'unit_cost' => 60.00]);
        $skuB = $this->sku(['unit_price' => 25.50, 'unit_cost' => 10.00]);

        $response = $this->actingAs($person, 'portal')->post(route('portal.shop.store'), [
            'quantities' => [$skuA->id => 2, $skuB->id => 3],
            'expected_prices' => [$skuA->id => '100.00', $skuB->id => '25.50'],
        ]);

        $invoice = Invoice::first();
        $this->assertNotNull($invoice);
        $response->assertRedirect(route('portal.shop.confirmation', $invoice));

        $this->assertSame($client->id, $invoice->client_id);
        $this->assertNull($invoice->contract_id);
        $this->assertSame(InvoiceStatus::Posted, $invoice->status);

        // 2 × 100 + 3 × 25.50 = 276.50 ; cost 2 × 60 + 3 × 10 = 150 ; margin 126.50
        $this->assertEqualsWithDelta(276.50, (float) $invoice->subtotal, 0.001);
        $this->assertEqualsWithDelta(276.50, (float) $invoice->total, 0.001);
        $this->assertEqualsWithDelta(150.00, (float) $invoice->total_cost, 0.001);
        $this->assertEqualsWithDelta(126.50, (float) $invoice->margin, 0.001);
        $this->assertCount(2, $invoice->lines);
        $this->assertStringContainsString('Product order via client portal', (string) $invoice->notes);
    }

    public function test_order_notifies_staff(): void
    {
        Bus::fake();
        User::factory()->create(['is_active' => true]);
        $person = $this->portalPerson($this->client());
        $sku = $this->sku();

        $this->actingAs($person, 'portal')->post(route('portal.shop.store'), [
            'quantities' => [$sku->id => 1],
            'expected_prices' => [$sku->id => (string) $sku->unit_price],
        ]);

        Bus::assertDispatched(SendTicketNotification::class);
    }

    public function test_duplicate_order_within_window_reuses_invoice(): void
    {
        Bus::fake();
        $person = $this->portalPerson($this->client());
        $sku = $this->sku();
        $payload = [
            'quantities' => [$sku->id => 1],
            'expected_prices' => [$sku->id => (string) $sku->unit_price],
        ];

        $this->actingAs($person, 'portal')->post(route('portal.shop.store'), $payload);
        $first = Invoice::firstOrFail();

        // Immediate re-submit should route to the existing order, not create a second.
        $this->actingAs($person, 'portal')->post(route('portal.shop.store'), $payload)
            ->assertRedirect(route('portal.shop.confirmation', $first));

        $this->assertSame(1, Invoice::count());
    }

    public function test_order_rejects_non_orderable_sku(): void
    {
        $person = $this->portalPerson($this->client());
        $internal = $this->sku(['portal_orderable' => false]);

        $this->actingAs($person, 'portal')->post(route('portal.shop.store'), [
            'quantities' => [$internal->id => 1],
            'expected_prices' => [$internal->id => (string) $internal->unit_price],
        ])->assertRedirect(route('portal.shop.index'));

        $this->assertSame(0, Invoice::count());
    }

    public function test_order_rejects_stale_price(): void
    {
        $person = $this->portalPerson($this->client());
        $sku = $this->sku(['unit_price' => 100.00]);

        $this->actingAs($person, 'portal')->post(route('portal.shop.store'), [
            'quantities' => [$sku->id => 1],
            'expected_prices' => [$sku->id => '80.00'], // stale price
        ])->assertRedirect(route('portal.shop.index'));

        $this->assertSame(0, Invoice::count());
    }

    public function test_empty_order_rejected(): void
    {
        $person = $this->portalPerson($this->client());
        $sku = $this->sku();

        $this->actingAs($person, 'portal')->post(route('portal.shop.store'), [
            'quantities' => [$sku->id => 0],
            'expected_prices' => [$sku->id => (string) $sku->unit_price],
        ])->assertRedirect(route('portal.shop.index'));

        $this->assertSame(0, Invoice::count());
    }

    public function test_owner_can_view_order_confirmation(): void
    {
        Bus::fake();
        $client = $this->client();
        $person = $this->portalPerson($client);
        $sku = $this->sku(['name' => 'Configured Laptop', 'unit_price' => 100.00]);

        $this->actingAs($person, 'portal')->post(route('portal.shop.store'), [
            'quantities' => [$sku->id => 2],
            'expected_prices' => [$sku->id => '100.00'],
        ]);
        $invoice = Invoice::firstOrFail();

        $this->actingAs($person, 'portal')
            ->get(route('portal.shop.confirmation', $invoice))
            ->assertOk()
            ->assertSee('Order Confirmed')
            ->assertSee($invoice->invoice_number)
            ->assertSee('Configured Laptop');
    }

    public function test_confirmation_404_for_non_shop_invoice(): void
    {
        // An invoice that is not a portal product order must not render as one.
        $client = $this->client();
        $person = $this->portalPerson($client);
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'invoice_number' => 'INV-09999',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => InvoiceStatus::Posted,
            'notes' => 'Manually created by staff',
        ]);

        $this->actingAs($person, 'portal')
            ->get(route('portal.shop.confirmation', $invoice))
            ->assertNotFound();
    }

    public function test_confirmation_blocks_cross_client_access(): void
    {
        Bus::fake();
        $clientA = $this->client('Client A');
        $personA = $this->portalPerson($clientA);
        $sku = $this->sku();

        $this->actingAs($personA, 'portal')->post(route('portal.shop.store'), [
            'quantities' => [$sku->id => 1],
            'expected_prices' => [$sku->id => (string) $sku->unit_price],
        ]);
        $invoice = Invoice::firstOrFail();

        // A different client's portal user must not view the order.
        $clientB = $this->client('Client B');
        $personB = $this->portalPerson($clientB, ['email' => 'other-'.uniqid().'@example.test']);

        $this->actingAs($personB, 'portal')
            ->get(route('portal.shop.confirmation', $invoice))
            ->assertForbidden();
    }
}
