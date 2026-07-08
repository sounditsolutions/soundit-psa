<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardProfitabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The profitability estimate is read from this cache key by
     * DashboardController::getManagedServicesProfitability(). Pre-seeding it
     * lets us drive the "Est. Monthly Profit" card to a known sign without
     * standing up contracts, profiles, and license types.
     */
    private function seedProfitability(float $mrr, float $licenseCost, float $profit): void
    {
        Cache::put('dashboard:managed_profitability', [
            'mrr' => $mrr,
            'license_cost' => $licenseCost,
            'profit' => $profit,
        ], 3600);
    }

    public function test_dashboard_renders_a_loss_with_a_minus_sign(): void
    {
        $user = User::factory()->create();

        // MRR below license cost => a real loss. Magnitude is rounded to whole
        // dollars in the card (number_format(..., 0)).
        $this->seedProfitability(mrr: 0, licenseCost: 2363, profit: -2363);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        // A loss must be unambiguous WITHOUT relying on colour: the figure
        // itself carries the sign. Before the fix the card rendered "$2,363"
        // (abs() stripped the minus), identical in form to a real profit.
        $response->assertSee('-$2,363');
    }

    public function test_dashboard_renders_a_profit_without_a_minus_sign(): void
    {
        $user = User::factory()->create();

        $this->seedProfitability(mrr: 3000, licenseCost: 0, profit: 3000);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        // A profit keeps the plain form; the sign logic must not prepend a
        // stray minus to positive figures.
        $response->assertSee('$3,000');
        $response->assertDontSee('-$3,000');
    }

    public function test_a_sub_dollar_loss_does_not_render_a_confusing_minus_zero(): void
    {
        $user = User::factory()->create();

        // A loss smaller than a dollar rounds to $0 in the whole-dollar card.
        // The sign is taken from the rounded value, so it must read "$0",
        // never "-$0".
        $this->seedProfitability(mrr: 0, licenseCost: 0.4, profit: -0.4);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertDontSee('-$0');
    }
}
