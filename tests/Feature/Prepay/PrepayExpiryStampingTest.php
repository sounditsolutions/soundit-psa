<?php

namespace Tests\Feature\Prepay;

use App\Enums\PrepayTransactionSource;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PrepayExpiryStampingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): PrepayService
    {
        return app(PrepayService::class);
    }

    private function contract(array $overrides = []): Contract
    {
        $client = Client::create(['name' => 'Acme Corp']);

        return Contract::create(array_merge([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'prepay_as_amount' => false,
            'prepay_total' => 0,
            'prepay_used' => 0,
            'prepay_balance' => 0,
        ], $overrides));
    }

    public function test_manual_credit_stamps_expiry_from_contract_policy(): void
    {
        Carbon::setTestNow('2026-05-01 12:00:00');
        $c = $this->contract(['prepay_expiry_months' => 12]);

        $txn = $this->service()->addManualCredit($c, 5, 'top up');

        $this->assertNotNull($txn->expiry_date);
        $this->assertSame('2027-05-01', $txn->expiry_date->format('Y-m-d'));
    }

    public function test_explicit_expiry_override_wins_over_policy(): void
    {
        Carbon::setTestNow('2026-05-01 12:00:00');
        $c = $this->contract(['prepay_expiry_months' => 12]);

        $txn = $this->service()->addManualCredit($c, 5, 'top up', null, Carbon::parse('2026-08-15'));

        $this->assertSame('2026-08-15', $txn->expiry_date->format('Y-m-d'));
    }

    public function test_no_policy_means_no_expiry(): void
    {
        $c = $this->contract(); // prepay_expiry_months null, no global default

        $txn = $this->service()->addManualCredit($c, 5, 'top up');

        $this->assertNull($txn->expiry_date);
    }

    public function test_global_default_applies_when_contract_has_no_policy(): void
    {
        Carbon::setTestNow('2026-05-01 12:00:00');
        Setting::setValue('prepay_expiry_months', '6'); // global default
        $c = $this->contract(); // prepay_expiry_months null → inherit global

        $txn = $this->service()->addManualCredit($c, 5, 'top up');

        $this->assertNotNull($txn->expiry_date);
        $this->assertSame('2026-11-01', $txn->expiry_date->format('Y-m-d'));
    }

    public function test_contract_policy_overrides_global_default(): void
    {
        Carbon::setTestNow('2026-05-01 12:00:00');
        Setting::setValue('prepay_expiry_months', '6');
        $c = $this->contract(['prepay_expiry_months' => 12]); // override wins

        $txn = $this->service()->addManualCredit($c, 5, 'top up');

        $this->assertSame('2027-05-01', $txn->expiry_date->format('Y-m-d'));
    }

    public function test_contract_zero_never_expires_despite_global_default(): void
    {
        Carbon::setTestNow('2026-05-01 12:00:00');
        Setting::setValue('prepay_expiry_months', '6');
        // Explicit 0 opts this contract out of the global forfeiture policy.
        $c = $this->contract(['prepay_expiry_months' => 0]);

        $txn = $this->service()->addManualCredit($c, 5, 'top up');

        $this->assertNull($txn->expiry_date);
    }

    public function test_effective_prepay_expiry_months_resolves_all_states(): void
    {
        // No global, no override → never expire.
        $this->assertNull($this->contract()->effectivePrepayExpiryMonths());

        // Global set, contract null → inherit global.
        Setting::setValue('prepay_expiry_months', '9');
        $this->assertSame(9, $this->contract()->effectivePrepayExpiryMonths());

        // Contract override (positive) wins over global.
        $this->assertSame(3, $this->contract(['prepay_expiry_months' => 3])->effectivePrepayExpiryMonths());

        // Contract explicit 0 = never, overriding the global.
        $this->assertNull($this->contract(['prepay_expiry_months' => 0])->effectivePrepayExpiryMonths());
    }

    public function test_dollar_based_credit_is_never_stamped(): void
    {
        Carbon::setTestNow('2026-05-01 12:00:00');
        // A dollar-based contract should never carry an expiry, even with a policy.
        $c = $this->contract(['prepay_as_amount' => true, 'prepay_expiry_months' => 12]);

        $txn = $this->service()->addManualCredit($c, 100, 'deposit');

        $this->assertNull($txn->expiry_date);
    }

    public function test_recalculate_splits_used_and_expired(): void
    {
        $c = $this->contract();
        $credit = PrepayTransaction::create([
            'contract_id' => $c->id,
            'source' => PrepayTransactionSource::ManualCredit,
            'date' => '2026-01-01',
            'hours' => 10,
            'expiry_date' => '2026-02-01',
        ]);
        PrepayTransaction::create([
            'contract_id' => $c->id,
            'source' => PrepayTransactionSource::TicketTime,
            'date' => '2026-01-10',
            'hours' => -3,
        ]);
        PrepayTransaction::create([
            'contract_id' => $c->id,
            'source' => PrepayTransactionSource::Expiration,
            'expired_transaction_id' => $credit->id,
            'date' => '2026-02-01',
            'hours' => -2,
        ]);

        $this->service()->recalculateBalance($c);
        $c->refresh();

        $this->assertEqualsWithDelta(10.0, (float) $c->prepay_total, 0.0001);
        $this->assertEqualsWithDelta(3.0, (float) $c->prepay_used, 0.0001);     // consumption only
        $this->assertEqualsWithDelta(2.0, (float) $c->prepay_expired, 0.0001);  // forfeiture
        $this->assertEqualsWithDelta(5.0, (float) $c->prepay_balance, 0.0001);  // 10 − 3 − 2
    }
}
