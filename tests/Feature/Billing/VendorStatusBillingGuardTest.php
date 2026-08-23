<?php

namespace Tests\Feature\Billing;

use App\Enums\ClientStage;
use App\Enums\QuantityType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AppRiver suspended-subscription billing guard, at the billing predicate.
 *
 * The inconclusive hold-out guard keeps a vendor-suspended licence row PSA-active at
 * its stored seat count — deliberately, so the vendor restoring the subscription
 * restores billing with no operator action. The cost of that design was that the
 * held seat count kept BILLING for as long as the vendor left it suspended. The sync
 * now records the vendor's own report in `licenses.vendor_status` on every
 * observation, and every billing quantity resolver excludes rows whose value is in
 * License::VENDOR_HELD_STATUSES.
 *
 * The NULL cases are the load-bearing half of this file. `vendor_status` is only
 * ever written by syncs that report one (AppRiver today); NULL is the
 * no-such-vendor case, not the unknown-status case, and it MUST bill normally —
 * excluding it would stop invoicing every CIPP and Microsoft licence on deploy
 * day. Same for a value this build does not list: fail open, bill it.
 */
class VendorStatusBillingGuardTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $attrs = []): Client
    {
        return Client::factory()->create(array_merge([
            'stage' => ClientStage::Active,
            'is_active' => true,
        ], $attrs));
    }

    private function licenseType(string $vendor, string $sku): LicenseType
    {
        return LicenseType::create([
            'vendor' => $vendor,
            'vendor_sku_id' => $sku,
            'name' => strtoupper($sku),
            'is_active' => true,
        ]);
    }

    private function license(Client $client, LicenseType $type, int $quantity, ?string $vendorStatus, string $ref): License
    {
        return License::create([
            'license_type_id' => $type->id,
            'client_id' => $client->id,
            'vendor_ref' => $ref,
            'quantity' => $quantity,
            'status' => 'active',
            'vendor_status' => $vendorStatus,
            'synced_at' => now(),
        ]);
    }

    public function test_vendor_suspended_seats_are_excluded_from_per_license_quantity(): void
    {
        $client = $this->client();
        $type = $this->licenseType('appriver', 'business-premium');
        $this->license($client, $type, 4, 'Active', 'sub-a');
        $this->license($client, $type, 3, 'Suspended', 'sub-b');

        $quantity = app(BillingService::class)->resolveQuantity(QuantityType::PerLicense, $client);

        $this->assertSame(4, $quantity, 'Vendor-suspended seats must not bill while the vendor reports the subscription suspended.');
    }

    public function test_vendor_pending_seats_are_excluded_from_per_license_type_quantity(): void
    {
        $client = $this->client();
        $type = $this->licenseType('appriver', 'business-premium');
        $other = $this->licenseType('appriver', 'exchange-plan');
        $this->license($client, $type, 5, 'Active', 'sub-a');
        $this->license($client, $type, 2, 'Pending', 'sub-b');
        $this->license($client, $other, 9, 'Active', 'sub-c');

        $quantity = app(BillingService::class)->resolveQuantity(
            QuantityType::PerLicenseType, $client, licenseTypeId: $type->id,
        );

        $this->assertSame(5, $quantity);
    }

    public function test_contract_scoped_count_excludes_vendor_held_seats(): void
    {
        $client = $this->client();
        $type = $this->licenseType('appriver', 'business-premium');
        $billable = $this->license($client, $type, 6, 'Active', 'sub-a');
        $held = $this->license($client, $type, 4, 'Suspended', 'sub-b');

        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services Agreement',
            'type' => 'managed',
            'status' => 'active',
            'billing_source' => 'psa',
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 15,
            'start_date' => now()->subYear()->toDateString(),
        ]);
        $contract->licenses()->attach([$billable->id, $held->id]);

        $quantity = app(BillingService::class)->resolveQuantity(
            QuantityType::PerLicense, $client, contract: $contract,
        );

        $this->assertSame(6, $quantity, 'The contract-scoped (joined) arm must apply the same vendor-held exclusion as the client-wide arm.');
    }

    public function test_reseller_count_excludes_vendor_held_seats_across_children(): void
    {
        $reseller = $this->client();
        $childA = $this->client(['reseller_id' => $reseller->id]);
        $childB = $this->client(['reseller_id' => $reseller->id]);
        $type = $this->licenseType('appriver', 'business-premium');
        $this->license($childA, $type, 7, 'Active', 'sub-a');
        $this->license($childB, $type, 3, 'Suspended', 'sub-b');

        $quantity = app(BillingService::class)->resolveQuantity(
            QuantityType::PerResellerLicenseType, $reseller, licenseTypeId: $type->id,
        );

        $this->assertSame(7, $quantity, 'The raw query-builder reseller site must carry the predicate — no Eloquent scope reaches it.');
    }

    /**
     * POSITIVE CONTROL (ruling 2026-08-19): a row whose sync never reports a vendor
     * status — every CIPP and Microsoft licence today — has vendor_status NULL and
     * MUST bill normally. This is the deploy-day regression the fail-open exists to
     * prevent; it must be asserted, not inferred from a docblock.
     */
    public function test_null_vendor_status_bills_normally(): void
    {
        $client = $this->client();
        $type = $this->licenseType('cipp', 'm365-business-standard');
        $this->license($client, $type, 8, null, 'ms-sub-1');

        $quantity = app(BillingService::class)->resolveQuantity(
            QuantityType::PerLicenseType, $client, licenseTypeId: $type->id,
        );

        $this->assertSame(8, $quantity, 'NULL vendor_status is the no-such-vendor case and must bill normally — every CIPP/Microsoft licence would stop invoicing otherwise.');
    }

    public function test_unlisted_vendor_status_value_bills_normally(): void
    {
        $client = $this->client();
        $type = $this->licenseType('appriver', 'business-premium');
        $this->license($client, $type, 5, 'SomethingNew', 'sub-a');

        $quantity = app(BillingService::class)->resolveQuantity(QuantityType::PerLicense, $client);

        $this->assertSame(5, $quantity, 'Only statuses this build affirmatively lists as held withhold billing; an unrecognised value fails open.');
    }
}
