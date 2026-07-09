<?php

namespace Tests\Feature\Prepay;

use App\Enums\PrepayTransactionSource;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PrepayTransaction;
use App\Services\PrepayExpirationService;
use App\Services\PrepayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PrepayExpirationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PrepayExpirationService
    {
        return app(PrepayExpirationService::class);
    }

    private function prepayContract(array $overrides = []): Contract
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

    private function credit(Contract $c, float $hours, string $date, ?string $expiry = null, ?int $haloId = null): PrepayTransaction
    {
        return PrepayTransaction::create([
            'contract_id' => $c->id,
            'source' => PrepayTransactionSource::ManualCredit,
            'halo_id' => $haloId,
            'date' => $date,
            'hours' => $hours,
            'expiry_date' => $expiry,
            'description' => 'credit',
        ]);
    }

    private function debit(Contract $c, float $hours, string $date, PrepayTransactionSource $source = PrepayTransactionSource::TicketTime): PrepayTransaction
    {
        return PrepayTransaction::create([
            'contract_id' => $c->id,
            'source' => $source,
            'date' => $date,
            'hours' => -abs($hours),
            'description' => 'debit',
        ]);
    }

    private function recalc(Contract $c): void
    {
        app(PrepayService::class)->recalculateBalance($c);
    }

    private function expirationRows(Contract $c)
    {
        return PrepayTransaction::where('contract_id', $c->id)
            ->where('source', PrepayTransactionSource::Expiration)
            ->get();
    }

    public function test_full_unconsumed_lot_forfeits_all_at_expiry(): void
    {
        $c = $this->prepayContract();
        $lot = $this->credit($c, 10, '2026-01-01', '2026-02-01');
        $this->recalc($c);

        $result = $this->service()->expireContract($c, Carbon::parse('2026-03-01'));

        $this->assertFalse($result['skipped']);
        $this->assertEqualsWithDelta(10.0, $result['forfeited_hours'], 0.0001);
        $rows = $this->expirationRows($c);
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(-10.0, (float) $rows->first()->hours, 0.0001);
        $this->assertSame($lot->id, $rows->first()->expired_transaction_id);

        $c->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $c->prepay_balance, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $c->prepay_expired, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $c->prepay_used, 0.0001);
    }

    public function test_partial_consumption_forfeits_remainder(): void
    {
        $c = $this->prepayContract();
        $this->credit($c, 10, '2026-01-01', '2026-02-01');
        $this->debit($c, 4, '2026-01-10');
        $this->recalc($c);

        $this->service()->expireContract($c, Carbon::parse('2026-03-01'));

        $rows = $this->expirationRows($c);
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(-6.0, (float) $rows->first()->hours, 0.0001);

        $c->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $c->prepay_balance, 0.0001);
        $this->assertEqualsWithDelta(4.0, (float) $c->prepay_used, 0.0001);
        $this->assertEqualsWithDelta(6.0, (float) $c->prepay_expired, 0.0001);
    }

    public function test_consumption_after_expiry_draws_from_later_lot_not_expired(): void
    {
        $c = $this->prepayContract();
        $this->credit($c, 10, '2026-01-01', '2026-02-01');  // lot A
        $this->credit($c, 5, '2026-01-15', '2026-06-01');   // lot B
        $this->debit($c, 3, '2026-01-10');                  // before A expiry → draws from A
        $this->recalc($c);

        // First run after A's expiry: A had 10, consumed 3 → forfeit 7. B intact.
        $this->service()->expireContract($c, Carbon::parse('2026-03-01'));
        $c->refresh();
        $this->assertEqualsWithDelta(5.0, (float) $c->prepay_balance, 0.0001);
        $this->assertEqualsWithDelta(7.0, (float) $c->prepay_expired, 0.0001);

        // New consumption AFTER A expired must draw from B, not the expired A.
        // (recalc simulates the debit path updating the denormalized balance,
        // as debitFromTicketNote does in production before the cron runs.)
        $this->debit($c, 2, '2026-03-15');
        $this->recalc($c);
        $this->service()->expireContract($c, Carbon::parse('2026-04-01'));
        $c->refresh();

        // A still forfeits 7 (unchanged); the 2h drew from B → balance 3.
        $this->assertCount(1, $this->expirationRows($c));
        $this->assertEqualsWithDelta(7.0, (float) $c->prepay_expired, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $c->prepay_used, 0.0001);
        $this->assertEqualsWithDelta(3.0, (float) $c->prepay_balance, 0.0001);
    }

    public function test_multi_lot_spanning_debit(): void
    {
        // One debit drains lot A and bites into lot B; A expires empty, B partially.
        $c = $this->prepayContract();
        $this->credit($c, 4, '2026-01-01', '2026-02-01');   // lot A
        $this->credit($c, 10, '2026-01-05', '2026-06-01');  // lot B
        $this->debit($c, 6, '2026-01-10');                  // 4 from A, 2 from B
        $this->recalc($c);

        // asOf after BOTH expiries: A forfeits 0 (drained), B forfeits its remaining 8.
        $this->service()->expireContract($c, Carbon::parse('2026-07-01'));

        $rows = $this->expirationRows($c);
        $this->assertCount(1, $rows, 'Only lot B should forfeit; lot A was fully consumed.');
        $this->assertEqualsWithDelta(-8.0, (float) $rows->first()->hours, 0.0001);

        $c->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $c->prepay_balance, 0.0001);
        $this->assertEqualsWithDelta(6.0, (float) $c->prepay_used, 0.0001);
        $this->assertEqualsWithDelta(8.0, (float) $c->prepay_expired, 0.0001);
    }

    public function test_idempotent_rerun_is_noop(): void
    {
        $c = $this->prepayContract();
        $this->credit($c, 10, '2026-01-01', '2026-02-01');
        $this->debit($c, 4, '2026-01-10');
        $this->recalc($c);

        $this->service()->expireContract($c, Carbon::parse('2026-03-01'));
        $countAfterFirst = PrepayTransaction::where('contract_id', $c->id)->count();

        $result = $this->service()->expireContract($c, Carbon::parse('2026-03-01'));

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['deleted']);
        $this->assertSame($countAfterFirst, PrepayTransaction::where('contract_id', $c->id)->count());
    }

    public function test_backdated_consumption_shrinks_existing_forfeiture(): void
    {
        $c = $this->prepayContract();
        $this->credit($c, 10, '2026-01-01', '2026-02-01');
        $this->recalc($c);

        $this->service()->expireContract($c, Carbon::parse('2026-03-01'));
        $this->assertEqualsWithDelta(-10.0, (float) $this->expirationRows($c)->first()->hours, 0.0001);

        // A backdated, pre-expiry consumption appears → forfeiture shrinks to 6.
        $this->debit($c, 4, '2026-01-15');
        $result = $this->service()->expireContract($c, Carbon::parse('2026-03-01'));

        $this->assertSame(1, $result['updated']);
        $this->assertCount(1, $this->expirationRows($c));
        $this->assertEqualsWithDelta(-6.0, (float) $this->expirationRows($c)->first()->hours, 0.0001);

        $c->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $c->prepay_balance, 0.0001);
        $this->assertEqualsWithDelta(6.0, (float) $c->prepay_expired, 0.0001);
    }

    public function test_zero_remainder_deletes_existing_expiration(): void
    {
        $c = $this->prepayContract();
        $this->credit($c, 10, '2026-01-01', '2026-02-01');
        $this->recalc($c);

        $this->service()->expireContract($c, Carbon::parse('2026-03-01'));
        $this->assertCount(1, $this->expirationRows($c));

        // Backdated consumption fully consumes the lot before expiry → forfeiture removed.
        $this->debit($c, 10, '2026-01-15');
        $result = $this->service()->expireContract($c, Carbon::parse('2026-03-01'));

        $this->assertSame(1, $result['deleted']);
        $this->assertCount(0, $this->expirationRows($c));

        $c->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $c->prepay_balance, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $c->prepay_expired, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $c->prepay_used, 0.0001);
    }

    public function test_orphan_expiration_rows_are_swept(): void
    {
        // A2 (defensive orphan sweep): converge must delete any source=expiration
        // row whose expired_transaction_id is null OR doesn't map to a current
        // canonical lot — covering strays cascadeOnDelete wouldn't catch (a nulled
        // FK, a hand-edited/legacy row). Distinct from the "lot dropped out of
        // canonical" path in test_zero_remainder_deletes_existing_expiration.
        $c = $this->prepayContract();
        // A never-expiring credit → produces NO canonical forfeiture.
        $lot = $this->credit($c, 10, '2026-01-01', null);

        // Stray #1: null FK (cascade can't reach it). Stray #2: points at a real
        // lot that is absent from the canonical map (this lot never expires).
        $nullOrphan = PrepayTransaction::create([
            'contract_id' => $c->id,
            'source' => PrepayTransactionSource::Expiration,
            'expired_transaction_id' => null,
            'date' => '2026-02-01',
            'hours' => -3,
            'expiry_date' => '2026-02-01',
            'description' => PrepayExpirationService::EXPIRY_DESCRIPTION,
        ]);
        $unmatchedOrphan = PrepayTransaction::create([
            'contract_id' => $c->id,
            'source' => PrepayTransactionSource::Expiration,
            'expired_transaction_id' => $lot->id,
            'date' => '2026-02-01',
            'hours' => -2,
            'expiry_date' => '2026-02-01',
            'description' => PrepayExpirationService::EXPIRY_DESCRIPTION,
        ]);
        $this->recalc($c);

        $result = $this->service()->expireContract($c, Carbon::parse('2030-01-01'));

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(2, $result['deleted'], 'Both the null-FK and unmatched-FK orphans must be swept.');
        $this->assertCount(0, $this->expirationRows($c));
        $this->assertNull(PrepayTransaction::find($nullOrphan->id));
        $this->assertNull(PrepayTransaction::find($unmatchedOrphan->id));

        // Isolation: the legitimate (non-expiration) credit lot is untouched.
        $this->assertNotNull(PrepayTransaction::find($lot->id));

        $c->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $c->prepay_balance, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $c->prepay_expired, 0.0001);
    }

    public function test_lot_without_expiry_never_forfeits(): void
    {
        $c = $this->prepayContract();
        $this->credit($c, 10, '2026-01-01', null);
        $this->recalc($c);

        $result = $this->service()->expireContract($c, Carbon::parse('2030-01-01'));

        $this->assertEqualsWithDelta(0.0, $result['forfeited_hours'], 0.0001);
        $this->assertCount(0, $this->expirationRows($c));
        $c->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $c->prepay_balance, 0.0001);
    }

    public function test_legacy_halo_lot_is_protected_but_participates_in_fifo(): void
    {
        $c = $this->prepayContract();
        $this->credit($c, 10, '2026-01-01', '2026-02-01', haloId: 555); // legacy — never forfeits
        $this->credit($c, 5, '2026-01-05', '2026-02-15');               // PSA-native — eligible
        $this->debit($c, 3, '2026-01-10');                              // FIFO → draws from older halo lot
        $this->recalc($c);

        $this->service()->expireContract($c, Carbon::parse('2026-03-01'));

        // Only the PSA lot forfeits, and its full 5h (the debit hit the halo lot).
        $rows = $this->expirationRows($c);
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(-5.0, (float) $rows->first()->hours, 0.0001);

        $c->refresh();
        $this->assertEqualsWithDelta(7.0, (float) $c->prepay_balance, 0.0001); // 10 halo − 3 used
        $this->assertEqualsWithDelta(5.0, (float) $c->prepay_expired, 0.0001);
    }

    public function test_dollar_based_contract_is_skipped(): void
    {
        $c = $this->prepayContract(['prepay_as_amount' => true]);
        PrepayTransaction::create([
            'contract_id' => $c->id,
            'source' => PrepayTransactionSource::ManualCredit,
            'date' => '2026-01-01',
            'amount' => 100,
            'expiry_date' => '2026-02-01',
            'description' => 'dollar credit',
        ]);

        $result = $this->service()->expireContract($c, Carbon::parse('2026-03-01'));

        $this->assertTrue($result['skipped']);
        $this->assertCount(0, $this->expirationRows($c));
    }

    public function test_converge_never_touches_non_expiration_rows(): void
    {
        $c = $this->prepayContract();
        $lot = $this->credit($c, 10, '2026-01-01', '2026-02-01');
        $d = $this->debit($c, 4, '2026-01-10');
        $this->recalc($c);

        $before = PrepayTransaction::whereIn('id', [$lot->id, $d->id])
            ->get()
            ->mapWithKeys(fn ($t) => [$t->id => [(float) $t->hours, $t->source->value, $t->description]]);

        $this->service()->expireContract($c, Carbon::parse('2026-03-01'));
        // Run a second scenario that deletes the expiration (full backdated consumption).
        $this->debit($c, 6, '2026-01-20');
        $this->service()->expireContract($c, Carbon::parse('2026-03-01'));

        foreach ($before as $id => $snap) {
            $t = PrepayTransaction::find($id);
            $this->assertNotNull($t, "Non-expiration row {$id} must not be deleted by converge.");
            $this->assertEqualsWithDelta($snap[0], (float) $t->hours, 0.0001);
            $this->assertSame($snap[1], $t->source->value);
            $this->assertSame($snap[2], $t->description);
        }
    }

    public function test_dry_run_makes_no_changes(): void
    {
        $c = $this->prepayContract();
        $this->credit($c, 10, '2026-01-01', '2026-02-01');
        $this->recalc($c);

        $result = $this->service()->expireContract($c, Carbon::parse('2026-03-01'), dryRun: true);

        $this->assertEqualsWithDelta(10.0, $result['forfeited_hours'], 0.0001);
        $this->assertCount(0, $this->expirationRows($c)); // nothing written
        $c->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $c->prepay_balance, 0.0001); // unchanged
    }

    // ── nextExpiry(): portal "upcoming expiry" projection (psa-5uy) ──

    public function test_next_expiry_returns_soonest_future_lot(): void
    {
        $c = $this->prepayContract();
        $this->credit($c, 10, '2026-01-01', '2026-06-01');

        $next = $this->service()->nextExpiry($c, Carbon::parse('2026-03-01'));

        $this->assertNotNull($next);
        $this->assertSame('2026-06-01', $next['expiry_date']->format('Y-m-d'));
        $this->assertEqualsWithDelta(10.0, $next['hours'], 0.0001);
    }

    public function test_next_expiry_excludes_already_consumed_lots(): void
    {
        // Oldest lot (expires sooner) is fully consumed → the real at-risk hours
        // sit in the newer lot. FIFO replay must skip the drained lot.
        $c = $this->prepayContract();
        $this->credit($c, 10, '2026-01-01', '2026-06-01');
        $this->debit($c, 10, '2026-02-01');
        $this->credit($c, 5, '2026-02-15', '2026-09-01');

        $next = $this->service()->nextExpiry($c, Carbon::parse('2026-03-01'));

        $this->assertNotNull($next);
        $this->assertSame('2026-09-01', $next['expiry_date']->format('Y-m-d'));
        $this->assertEqualsWithDelta(5.0, $next['hours'], 0.0001);
    }

    public function test_next_expiry_ignores_lots_that_already_expired(): void
    {
        $c = $this->prepayContract();
        $this->credit($c, 10, '2026-01-01', '2026-02-01'); // expired before asOf

        $this->assertNull($this->service()->nextExpiry($c, Carbon::parse('2026-03-01')));
    }

    public function test_next_expiry_null_when_no_expiry_policy(): void
    {
        $c = $this->prepayContract();
        $this->credit($c, 10, '2026-01-01'); // no expiry_date

        $this->assertNull($this->service()->nextExpiry($c, Carbon::parse('2026-03-01')));
    }

    public function test_next_expiry_null_for_dollar_based_prepay(): void
    {
        $c = $this->prepayContract(['prepay_as_amount' => true]);
        $this->credit($c, 10, '2026-01-01', '2026-06-01');

        $this->assertNull($this->service()->nextExpiry($c, Carbon::parse('2026-03-01')));
    }
}
