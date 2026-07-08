<?php

namespace Tests\Feature\Prepay;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Person;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the expiration-policy field. prepay_expiry_months sets a destructive
 * billing term (use-it-or-lose-it), so it must be settable ONLY via the staff
 * route — never by a (company-wide-access) portal client. See security review
 * of psa-xt3.
 */
class PrepayExpiryAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Portal routes are gated by the PortalEnabled middleware (404 when off).
        Setting::setValue('portal_enabled', '1');
    }

    private function prepayContract(Client $client): Contract
    {
        return Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'prepay_as_amount' => false,
            'prepay_total' => 0,
            'prepay_used' => 0,
            'prepay_balance' => 0,
        ]);
    }

    public function test_portal_client_cannot_set_expiry_months(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $contract = $this->prepayContract($client);
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal',
            'last_name' => 'Admin',
            'email' => 'portal-admin@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => true,
            'password' => 'secret-portal-pw',
        ]);

        $response = $this->actingAs($person, 'portal')->put(
            route('portal.prepaid.update-alert-settings', $contract),
            [
                'prepay_alert_threshold' => 5,
                'prepay_auto_topup_enabled' => '0',
                'prepay_expiry_months' => 12, // the attempted billing-integrity bypass
            ],
        );

        $response->assertRedirect();
        $contract->refresh();

        // Guardrail: the forfeiture policy must be unchanged…
        $this->assertNull($contract->prepay_expiry_months);
        // …while the legitimate portal field still applied (endpoint works).
        $this->assertEqualsWithDelta(5.0, (float) $contract->prepay_alert_threshold, 0.0001);
    }

    public function test_staff_can_set_expiry_months(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $contract = $this->prepayContract($client);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put(
            route('contracts.update-alert-settings', $contract),
            [
                'prepay_alert_threshold' => 5,
                'prepay_auto_topup_enabled' => '0',
                'prepay_expiry_months' => 12,
            ],
        );

        $response->assertRedirect();
        $contract->refresh();
        $this->assertSame(12, $contract->prepay_expiry_months);
    }
}
