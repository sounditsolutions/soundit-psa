<?php

namespace Tests\Feature\Profiles;

use App\Enums\BillingPeriod;
use App\Enums\BillingSource;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\RecurringInvoiceProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringProfilePreviewResponsiveTest extends TestCase
{
    use RefreshDatabase;

    private function profile(): RecurringInvoiceProfile
    {
        $client = Client::factory()->create();

        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => ContractType::Managed,
            'status' => ContractStatus::Active,
            'billing_source' => BillingSource::Psa,
            'billing_period' => BillingPeriod::Monthly,
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'start_date' => now()->subMonth(),
        ]);

        return RecurringInvoiceProfile::create([
            'contract_id' => $contract->id,
            'name' => 'Managed billing',
            'is_active' => true,
            'billing_period' => BillingPeriod::Monthly,
            'billing_day' => 1,
            'payment_terms_days' => 30,
            'next_run_date' => today(),
        ]);
    }

    /**
     * The "Preview Next Invoice" modal renders a six-column invoice table
     * (…Amount, Prepaid Time). On a narrow mobile viewport an unwrapped table
     * clips the rightmost Prepaid Time column off-screen. Bootstrap's
     * .table-responsive wrapper gives a horizontal-scroll area so every column
     * stays reachable — guard that the preview table keeps that wrapper.
     */
    public function test_preview_table_is_wrapped_for_horizontal_scroll_on_mobile(): void
    {
        $profile = $this->profile();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('profiles.show', $profile));

        $response->assertOk();

        // The Prepaid Time column identifies the preview table; it must sit
        // inside a .table-responsive scroll wrapper so it is not clipped.
        $this->assertMatchesRegularExpression(
            '/<div class="table-responsive">\s*<table[^>]*>.*?Prepaid Time.*?<\/table>\s*<\/div>/s',
            $response->getContent(),
        );
    }
}
