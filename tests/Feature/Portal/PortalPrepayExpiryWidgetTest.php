<?php

namespace Tests\Feature\Portal;

use App\Enums\PersonType;
use App\Enums\PrepayTransactionSource;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Person;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The client portal's "Support Hours Remaining" widget surfaces the next
 * upcoming prepaid-time expiry (psa-5uy, product #1 optional half). Backed by
 * PrepayExpirationService::nextExpiry(); this covers the controller→view wiring.
 */
class PortalPrepayExpiryWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('portal_enabled', '1');
    }

    private function contractWithPortalUser(): array
    {
        $client = Client::create(['name' => 'Acme Corp']); // stage defaults to Active

        $contract = Contract::create([
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

        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@acme.test',
            'is_active' => true,
            'portal_enabled' => true,
        ]);

        return [$contract, $person];
    }

    private function credit(Contract $c, float $hours, ?Carbon $expiry): void
    {
        PrepayTransaction::create([
            'contract_id' => $c->id,
            'source' => PrepayTransactionSource::ManualCredit,
            'date' => Carbon::now()->subMonth(),
            'hours' => $hours,
            'expiry_date' => $expiry,
            'description' => 'credit',
        ]);
        app(PrepayService::class)->recalculateBalance($c);
    }

    public function test_widget_shows_next_expiry_when_a_credit_will_expire(): void
    {
        [$contract, $person] = $this->contractWithPortalUser();
        $this->credit($contract, 10, Carbon::now()->addMonths(3));

        $this->actingAs($person, 'portal')
            ->get(route('portal.contracts.show', $contract))
            ->assertOk()
            ->assertSee('expire on')
            ->assertSee('Up to');
    }

    public function test_widget_hides_expiry_line_when_no_expiry_policy(): void
    {
        [$contract, $person] = $this->contractWithPortalUser();
        $this->credit($contract, 10, null); // never expires

        $this->actingAs($person, 'portal')
            ->get(route('portal.contracts.show', $contract))
            ->assertOk()
            ->assertDontSee('expire on');
    }
}
