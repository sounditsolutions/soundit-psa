<?php

namespace Tests\Feature\Prepay;

use App\Enums\PersonType;
use App\Enums\PrepayTransactionSource;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Person;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The portal "Support Hours Remaining" balance widget surfaces the soonest
 * upcoming prepaid-time expiry so clients see when their balance will lapse
 * before it forfeits — mirroring the staff contract "Expires" column (psa-dvp,
 * follow-up to psa-xt3).
 */
class PortalPrepayExpiryWidgetTest extends TestCase
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

    private function portalPerson(Client $client): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Portal',
            'last_name' => 'User',
            'email' => 'portal-user@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => false,
            'password' => 'secret-portal-pw',
        ]);
    }

    private function credit(Contract $c, float $hours, string $date, ?string $expiry): void
    {
        PrepayTransaction::create([
            'contract_id' => $c->id,
            'source' => PrepayTransactionSource::ManualCredit,
            'date' => $date,
            'hours' => $hours,
            'expiry_date' => $expiry,
            'description' => 'credit',
        ]);
        app(PrepayService::class)->recalculateBalance($c);
        $c->refresh();
    }

    public function test_widget_shows_next_expiry_when_balance_will_lapse(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $contract = $this->prepayContract($client);
        $this->credit($contract, 10, '2026-01-01', '2030-06-15');
        $person = $this->portalPerson($client);

        $response = $this->actingAs($person, 'portal')
            ->get(route('portal.contracts.show', $contract));

        $response->assertOk();
        $response->assertSee('Next expiry:');
        $response->assertSee('10.0h');
        $response->assertSee('Jun 15, 2030');
    }

    public function test_widget_omits_next_expiry_when_no_expiry_set(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $contract = $this->prepayContract($client);
        $this->credit($contract, 10, '2026-01-01', null); // never expires
        $person = $this->portalPerson($client);

        $response = $this->actingAs($person, 'portal')
            ->get(route('portal.contracts.show', $contract));

        $response->assertOk();
        $response->assertDontSee('Next expiry:');
    }
}
