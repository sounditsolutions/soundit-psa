<?php

namespace Tests\Feature\Prepay;

use App\Enums\PrepayTransactionSource;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PrepayTransaction;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpirePrepayCommandTest extends TestCase
{
    use RefreshDatabase;

    private function contractWithExpiredCredit(float $hours = 10): Contract
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $c = Contract::create([
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

        PrepayTransaction::create([
            'contract_id' => $c->id,
            'source' => PrepayTransactionSource::ManualCredit,
            'date' => '2026-01-01',
            'hours' => $hours,
            'expiry_date' => '2026-03-01', // in the past relative to "now"
        ]);

        app(PrepayService::class)->recalculateBalance($c);

        return $c;
    }

    private function expirationCount(Contract $c): int
    {
        return PrepayTransaction::where('contract_id', $c->id)
            ->where('source', PrepayTransactionSource::Expiration)
            ->count();
    }

    public function test_command_forfeits_eligible_contract(): void
    {
        $c = $this->contractWithExpiredCredit(10);

        $this->artisan('prepay:expire')->assertSuccessful();

        $this->assertSame(1, $this->expirationCount($c));
        $c->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $c->prepay_balance, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $c->prepay_expired, 0.0001);
    }

    public function test_dry_run_makes_no_changes(): void
    {
        $c = $this->contractWithExpiredCredit(10);

        $this->artisan('prepay:expire --dry-run')->assertSuccessful();

        $this->assertSame(0, $this->expirationCount($c));
        $c->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $c->prepay_balance, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $c->prepay_expired, 0.0001);
    }

    public function test_contract_option_scopes_to_one_contract(): void
    {
        $a = $this->contractWithExpiredCredit(10);
        $b = $this->contractWithExpiredCredit(8);

        $this->artisan("prepay:expire --contract={$a->id}")->assertSuccessful();

        $this->assertSame(1, $this->expirationCount($a));
        $this->assertSame(0, $this->expirationCount($b), 'Unscoped contract must be untouched.');
    }
}
