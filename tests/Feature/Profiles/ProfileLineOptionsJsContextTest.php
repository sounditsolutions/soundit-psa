<?php

namespace Tests\Feature\Profiles;

use App\Enums\QuantityType;
use App\Models\BackupStorageTier;
use App\Models\Client;
use App\Models\Contract;
use App\Models\LicenseType;
use App\Models\RecurringInvoiceProfile;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsInertJsData;
use Tests\TestCase;

/**
 * psa-951q — the recurring-profile line editors (profiles/create + profiles/show)
 * build the SKU / license-type / quantity-type <option> lists in JavaScript, so
 * the "Add Line" button can stamp out another line client-side.
 *
 * Blade's {{ }} escapes for HTML. It does NOT escape for JavaScript, and a
 * backtick-delimited `template literal` is a JS STRING context stacked on top of
 * the HTML one. htmlspecialchars() leaves ` and $ and { untouched, so a persisted
 * SKU name carrying a backtick CLOSES the literal, and one carrying ${...} is
 * EVALUATED the moment the string is constructed — in the staff browser.
 *
 * SKU and license-type labels are operator-entered (staff create SKUs), so this
 * is staff-to-staff stored XSS: real, but not anonymous-user-reachable.
 *
 * These tests assert the JAVASCRIPT-context layer specifically. Asserting that
 * the labels are HTML-escaped is NOT sufficient — that already held while the
 * defect was live, which is exactly how it survived review.
 */
class ProfileLineOptionsJsContextTest extends TestCase
{
    use AssertsInertJsData;
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileLabels')]
    public function test_profile_create_form_keeps_hostile_sku_labels_out_of_js_code_position(string $label): void
    {
        $contract = $this->contract();
        $sku = $this->sku($label);

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('profiles.create', $contract))->assertOk()->getContent();

        // The label AND the description data attribute both carry the SKU name.
        $this->assertLabelIsInertJsData($html, [$sku->sku_code.' — '.$label, $label], 'profiles.create');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileLabels')]
    public function test_profile_show_form_keeps_hostile_sku_labels_out_of_js_code_position(string $label): void
    {
        $profile = $this->profile();
        $sku = $this->sku($label);

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('profiles.show', $profile))->assertOk()->getContent();

        // The label AND the description data attribute both carry the SKU name.
        $this->assertLabelIsInertJsData($html, [$sku->sku_code.' — '.$label, $label], 'profiles.show');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileLabels')]
    public function test_profile_forms_keep_hostile_license_type_labels_out_of_js_code_position(string $label): void
    {
        $contract = $this->contract();
        $profile = $this->profile($contract);
        $licenseType = $this->licenseType($label);

        $this->actingAs(User::factory()->create());

        foreach ([
            'profiles.create' => route('profiles.create', $contract),
            'profiles.show' => route('profiles.show', $profile),
        ] as $screen => $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertLabelIsInertJsData($html, [$label.' ('.$licenseType->vendor.')'], $screen);
        }
    }

    /**
     * The quantity-type list is enum-sourced — developer-authored, no operator
     * input — so unlike the SKU and license lists it deliberately lives in BOTH
     * places on each profile screen: the QUANTITY_TYPE_OPTIONS island that
     * fillSelectOptions rebuilds every added row from (the success path), and
     * the static addLine() row markup that survives the helper failing
     * (assertQuantityTypeFloorInAddLineMarkup — psa-951q.4's blocker: quantity
     * type is a required billing control, and it came up completely EMPTY on
     * both profile screens when fillSelectOptions was forced to throw).
     *
     * This test pins the island half: every enum case must still arrive as
     * inert JSON data decoding byte-for-byte to the enum's own value/label
     * pair, so the success-path dropdown and the static floor cannot drift
     * apart. The wiring tests below pin the markup half. Operator-entered
     * lists get no such split — they stay data-island-only, and the
     * hostile-label tests above hold them there.
     */
    public function test_quantity_type_options_are_inert_js_data_too(): void
    {
        $contract = $this->contract();
        $profile = $this->profile($contract);

        $this->actingAs(User::factory()->create());

        foreach ([
            'profiles.create' => route('profiles.create', $contract),
            'profiles.show' => route('profiles.show', $profile),
        ] as $screen => $url) {
            $html = $this->get($url)->assertOk()->getContent();

            foreach (QuantityType::cases() as $type) {
                $option = $this->optionFromIsland($html, 'QUANTITY_TYPE_OPTIONS', $type->value, $screen);

                $this->assertSame(
                    $type->label(),
                    $option['label'] ?? null,
                    "{$screen}: the QUANTITY_TYPE_OPTIONS island no longer carries the enum's own label for ".
                    "'{$type->value}' — the success-path dropdown would drift from the static floor."
                );
            }
        }
    }

    /**
     * The island is inert; that is only half the story. These two assert the
     * other half — that the thing which turns it into a dropdown is on the page
     * and wired to every select. Deleting the @include leaves all of the
     * assertions above green while every "Add Line" row comes up empty.
     *
     * profiles/create fills five selects: SKU, quantity type, and three
     * license-type selects (line, overage usage, overage base).
     */
    public function test_profile_create_add_line_is_wired_to_the_option_builder(): void
    {
        $contract = $this->contract();

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('profiles.create', $contract))->assertOk()->getContent();

        $this->assertOptionBuilderReachesThePage($html, 'profiles.create', 5);
        $this->assertPlaceholderFloorInAddLineMarkup($html, 'profiles.create', [
            '-- Manual --',
            'Select...',
            '(none — use 1)',
        ]);
        $this->assertQuantityTypeFloorInAddLineMarkup($html, 'profiles.create');
        $this->assertAddLineUsesACapturedIndex($html, 'profiles.create');
    }

    /** profiles/show fills four: SKU, quantity type, overage usage and overage base. */
    public function test_profile_show_add_line_is_wired_to_the_option_builder(): void
    {
        $profile = $this->profile();

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('profiles.show', $profile))->assertOk()->getContent();

        $this->assertOptionBuilderReachesThePage($html, 'profiles.show', 4);
        $this->assertPlaceholderFloorInAddLineMarkup($html, 'profiles.show', [
            '-- Manual --',
            'Select...',
            '(none — use 1)',
        ]);
        $this->assertQuantityTypeFloorInAddLineMarkup($html, 'profiles.show');
        $this->assertAddLineUsesACapturedIndex($html, 'profiles.show');
    }

    /**
     * The profile option data does not merely price a line — it CONFIGURES one,
     * and that makes a key drift here worse than on the invoice side.
     *
     * onSkuSelected copies opt.dataset.defaultQuantityType into the quantity-type
     * select. If that key stops arriving, the select silently stays on "Fixed":
     * the operator picks a per-workstation SKU, sees a plausible-looking line, and
     * the client is billed 1 unit per cycle instead of N — every cycle, forever,
     * with nothing failing anywhere. Silent, permanent, client-facing mis-billing.
     *
     * These keys are camelCase PHP array keys joined to their kebab data-*
     * attribute by an invisible DOM rule (fillSelectOptions writes
     * opt.dataset[key]; onSkuSelected reads opt.dataset.<key>). They used to be
     * copy-pasted attribute text visible on both sides. The refactor RAISED the
     * drift risk here, so each key is pinned against the consumer that reads it —
     * mirroring the invoice-side pin, which this branch shipped while leaving the
     * profile screens, with more keys and a riskier one, entirely unguarded.
     */
    public function test_profile_create_sku_option_data_carries_every_key_its_consumers_read(): void
    {
        $contract = $this->contract();
        $licenseType = $this->licenseType('Backup Seats');
        $sku = $this->configuredSku($licenseType);

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('profiles.create', $contract))->assertOk()->getContent();

        $option = $this->optionFromIsland($html, 'SKU_OPTIONS', (string) $sku->id, 'profiles.create');

        // profiles/create is the only profile screen that emits 'cost'.
        $this->assertProfileSkuOptionData($option['data'] ?? [], 'profiles.create', $licenseType, withCost: true);
    }

    /** @see test_profile_create_sku_option_data_carries_every_key_its_consumers_read */
    public function test_profile_show_sku_option_data_carries_every_key_its_consumers_read(): void
    {
        $profile = $this->profile();
        $licenseType = $this->licenseType('Backup Seats');
        $sku = $this->configuredSku($licenseType);

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('profiles.show', $profile))->assertOk()->getContent();

        $option = $this->optionFromIsland($html, 'SKU_OPTIONS', (string) $sku->id, 'profiles.show');

        $this->assertProfileSkuOptionData($option['data'] ?? [], 'profiles.show', $licenseType, withCost: false);
    }

    /**
     * Every key the profile SKU option data emits, pinned against its reader.
     *
     * The full-key-set assertion comes first and is not redundant with the
     * per-key ones: a RENAME presents as a missing key AND an unexpected key,
     * and only comparing the whole set catches the second half — which is what
     * tells the next reader that the data is now carrying a name nothing reads.
     */
    private function assertProfileSkuOptionData(array $data, string $screen, LicenseType $licenseType, bool $withCost): void
    {
        $expected = [
            'price',
            'taxable',
            'description',
            'includedPerUnit',
            'defaultQuantityType',
            'defaultLicenseTypeId',
            'hasVolumeTiers',
        ];

        if ($withCost) {
            $expected[] = 'cost';
        }

        $actual = array_keys($data);
        sort($expected);
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            "{$screen}: the SKU option data no longer emits exactly the keys its consumers read. ".
            'A key renamed here is read back as undefined by onSkuSelected and the line silently keeps '.
            'its default — on defaultQuantityType that is a per-cycle mis-bill with nothing failing.'
        );

        // onSkuSelected (profiles/create + profiles/show).
        $this->assertSame('123.45', $data['price'] ?? null, "{$screen}: opt.dataset.price feeds .price-input");
        $this->assertSame('1', $data['taxable'] ?? null, "{$screen}: opt.dataset.taxable feeds .taxable-check (compared === '1')");
        $this->assertSame('Managed Backup', $data['description'] ?? null, "{$screen}: opt.dataset.description feeds .desc-input");
        $this->assertSame('1024', $data['includedPerUnit'] ?? null, "{$screen}: opt.dataset.includedPerUnit feeds .included-per-base-input");
        $this->assertSame(
            'per_license_type',
            $data['defaultQuantityType'] ?? null,
            "{$screen}: opt.dataset.defaultQuantityType feeds .qty-type-select — a drift here bills 1 unit ".
            'per cycle instead of N, silently and forever'
        );
        $this->assertSame(
            (string) $licenseType->id,
            $data['defaultLicenseTypeId'] ?? null,
            "{$screen}: opt.dataset.defaultLicenseTypeId feeds [license_type_id] / [usage_license_type_id], ".
            'branched on defaultQuantityType'
        );

        // updatePricingMethodNote (profiles/_tier_editor_js). Also covered by
        // TieredPricingOverrideTest; asserted here so the key set above is a
        // complete statement of the contract rather than a partial one.
        $this->assertSame(
            '1',
            $data['hasVolumeTiers'] ?? null,
            "{$screen}: opt.dataset.hasVolumeTiers drives the inline pricing-method override note"
        );

        // 'cost' is emitted but NOTHING on either profile screen reads it — the
        // cost field here is unit_cost_override, which is deliberately left blank
        // to mean "inherit the SKU cost". Pinned as emitted rather than as
        // consumed, so this stays an accurate record. See psa-951q.3 notes.
        if ($withCost) {
            $this->assertSame(
                '67.89',
                $data['cost'] ?? null,
                'profiles.create: cost is emitted but currently has NO reader on this screen'
            );
        }
    }

    // ------------------------------------------------------------------ fixtures

    private function contract(): Contract
    {
        return Contract::create([
            'client_id' => Client::factory()->create()->id,
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

    private function profile(?Contract $contract = null): RecurringInvoiceProfile
    {
        return RecurringInvoiceProfile::create([
            'contract_id' => ($contract ?? $this->contract())->id,
            'name' => 'Monthly services',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => '2026-08-01',
        ]);
    }

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

    /** A license type whose operator-entered name is hostile. */
    private function licenseType(string $name): LicenseType
    {
        return LicenseType::create([
            'name' => $name,
            'vendor' => 'psa951q',
            'is_active' => true,
        ]);
    }

    /**
     * A SKU that exercises EVERY key the profile option data emits: a non-default
     * quantity type, an included-per-unit, a linked license type, and a volume
     * rate card. A fixture that left any of these at its default would let the
     * matching key drift undetected.
     */
    private function configuredSku(LicenseType $licenseType): Sku
    {
        $sku = Sku::create([
            'name' => 'Managed Backup',
            'sku_code' => 'PSA951Q-CONFIGURED',
            'unit_price' => '123.45',
            'unit_cost' => '67.89',
            'included_per_unit' => 1024,
            'default_quantity_type' => QuantityType::PerLicenseType,
            'default_license_type_id' => $licenseType->id,
            'is_taxable' => true,
            'is_active' => true,
        ]);

        // Gives the SKU a volume rate card, so hasVolumeTiers renders '1'.
        BackupStorageTier::create([
            'sku_id' => $sku->id,
            'up_to_gb' => null,
            'unit_price' => '0.60',
            'sort_order' => 0,
        ]);

        return $sku;
    }
}
