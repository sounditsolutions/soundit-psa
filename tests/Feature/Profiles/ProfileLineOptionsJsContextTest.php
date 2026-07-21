<?php

namespace Tests\Feature\Profiles;

use App\Enums\QuantityType;
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
     * The quantity-type list is enum-sourced rather than operator-entered, so it
     * is not an injection vector today — but it is built by the same code path,
     * and a label there must be inert data for the same reason. This pins the
     * whole option surface, not just the two lists an attacker can reach now.
     */
    public function test_quantity_type_options_are_inert_js_data_too(): void
    {
        $contract = $this->contract();

        $this->actingAs(User::factory()->create());
        $html = $this->get(route('profiles.create', $contract))->assertOk()->getContent();

        $label = QuantityType::PerBackupStorageGb->label();

        // This label carries no hostile characters, so it survives every encoder
        // verbatim and can locate itself.
        $this->assertLabelIsInertJsData($html, [$label], 'profiles.create', $label);
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
        $this->assertAddLineUsesACapturedIndex($html, 'profiles.show');
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
}
