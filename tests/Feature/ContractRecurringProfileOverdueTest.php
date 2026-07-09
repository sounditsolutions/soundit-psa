<?php

namespace Tests\Feature;

use App\Enums\BillingSource;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\RecurringInvoiceProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature coverage for psa-fbyq: the contract detail page must flag a recurring
 * profile whose next run date has passed as behind/overdue and expose a
 * Generate Now action, so a billing owner can see it needs attention without
 * drilling into the profile detail page.
 */
class ContractRecurringProfileOverdueTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Contract, 1: RecurringInvoiceProfile} */
    private function contractWithProfile(array $profileOverrides = []): array
    {
        $client = Client::factory()->create();

        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Vandelay Monthly Managed',
            'type' => ContractType::Managed,
            'status' => ContractStatus::Active,
            'billing_source' => BillingSource::Psa,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'auto_renew' => true,
            'billing_period' => 'monthly',
            'payment_terms_days' => 30,
        ]);

        $profile = RecurringInvoiceProfile::create(array_merge([
            'contract_id' => $contract->id,
            'name' => 'Vandelay Monthly Managed',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 4,
            'payment_terms_days' => 30,
            'next_run_date' => now()->subDay()->toDateString(),
        ], $profileOverrides));

        return [$contract, $profile];
    }

    public function test_contract_detail_flags_overdue_profile_and_offers_generate_now(): void
    {
        [$contract, $profile] = $this->contractWithProfile([
            'next_run_date' => now()->subDay()->toDateString(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('contracts.show', $contract));

        $response->assertOk();
        $response->assertSee('cycle behind');
        $response->assertSee('Generate Now');
        $response->assertSee(route('profiles.generate', $profile), escape: false);
    }

    public function test_contract_detail_does_not_flag_a_current_profile(): void
    {
        [$contract] = $this->contractWithProfile([
            'next_run_date' => now()->addMonthNoOverflow()->toDateString(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('contracts.show', $contract));

        $response->assertOk();
        $response->assertDontSee('cycle behind');
        $response->assertDontSee('Generate Now');
    }

    public function test_contract_detail_does_not_offer_generate_now_for_inactive_overdue_profile(): void
    {
        [$contract] = $this->contractWithProfile([
            'is_active' => false,
            'next_run_date' => now()->subMonthsNoOverflow(2)->toDateString(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('contracts.show', $contract));

        $response->assertOk();
        // Paused profiles are intentionally not generating; no overdue nag.
        $response->assertDontSee('cycle behind');
        $response->assertDontSee('Generate Now');
    }
}
