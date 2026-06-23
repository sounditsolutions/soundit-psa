<?php

namespace Tests\Feature\Prospect;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProspectExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_prospect_is_absent_from_the_ticket_create_client_picker(): void
    {
        $user = User::factory()->create();
        $active = Client::factory()->create(['name' => 'Acme Active']);
        $prospect = Client::factory()->prospect()->create(['name' => 'Tirekicker Prospect']);

        $resp = $this->actingAs($user)->get(route('tickets.create'))->assertOk();
        $resp->assertSee('Acme Active', false);
        $resp->assertDontSee('Tirekicker Prospect', false);
    }

    public function test_stripe_auto_match_candidate_set_excludes_prospects(): void
    {
        Client::factory()->create(['name' => 'Real Co', 'stripe_customer_id' => null]);
        Client::factory()->prospect()->create(['name' => 'Real Co', 'stripe_customer_id' => null]);

        // The candidate query the auto-matcher uses must not contain the prospect.
        $candidates = Client::operational()->whereNull('stripe_customer_id')->pluck('name');
        $this->assertSame(1, $candidates->filter(fn ($n) => $n === 'Real Co')->count());
    }

    public function test_billing_rollup_excludes_prospect_reseller_children(): void
    {
        $reseller = Client::factory()->create(['name' => 'Reseller Corp']);
        // Active child — should be counted
        Client::factory()->create(['name' => 'Active Child', 'reseller_id' => $reseller->id, 'is_active' => true]);
        // Prospect child — must NOT appear in the reseller roll-up
        Client::factory()->prospect()->create(['name' => 'Prospect Child', 'reseller_id' => $reseller->id]);

        $childIds = Client::where('reseller_id', $reseller->id)
            ->operational()
            ->pluck('id');

        $this->assertCount(1, $childIds, 'Prospect child must not appear in reseller billing roll-up');
    }

    public function test_license_sync_fleet_excludes_prospects(): void
    {
        // Active client with a cipp_tenant_domain — should be in the sync fleet
        Client::factory()->create(['name' => 'Active Tenant', 'cipp_tenant_domain' => 'active.example.com']);
        // Prospect with a cipp_tenant_domain — must NOT be synced
        Client::factory()->prospect()->create(['name' => 'Prospect Tenant', 'cipp_tenant_domain' => 'prospect.example.com']);

        $fleet = Client::whereNotNull('cipp_tenant_domain')
            ->operational()
            ->get();

        $this->assertCount(1, $fleet);
        $this->assertSame('Active Tenant', $fleet->first()->name);
    }
}
