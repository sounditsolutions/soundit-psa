<?php

namespace App\Services;

use App\Enums\PrepayTransactionSource;
use App\Models\Contract;
use App\Models\PrepayTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Enforces prepaid-time expiration: when a credit "lot" passes its expiry_date,
 * its unconsumed remainder is forfeited as an Expiration ledger debit.
 *
 * Debits are not linked to specific credits, so the unconsumed remainder of an
 * expiring lot is derived by replaying the ledger in chronological order with a
 * FIFO queue of credit lots and an expiry "checkpoint" at each lot's expiry.
 * Consumption recorded after a lot expires therefore draws from later lots, not
 * the expired one. The result is idempotent: each run recomputes the canonical
 * set of forfeitures and converges the ledger to it (create / update / delete).
 */
class PrepayExpirationService
{
    /** Client-facing description on forfeiture rows (visible in the portal). */
    public const EXPIRY_DESCRIPTION = 'Prepaid hours expired';

    public function __construct(private PrepayService $prepayService) {}

    /**
     * Reconcile a contract's Expiration ledger rows against the canonical set
     * computed as of $asOf. Returns a summary; does not mutate when $dryRun.
     *
     * @return array{skipped:bool, forfeited_hours:float, lots:int, created:int, updated:int, deleted:int}
     */
    public function expireContract(Contract $contract, ?CarbonInterface $asOf = null, bool $dryRun = false): array
    {
        $asOf ??= now();

        // Hours-based PSA prepay only. Dollar-based prepay never expires.
        if (! $contract->has_prepay || $contract->prepay_as_amount) {
            return $this->summary(true);
        }

        return DB::transaction(function () use ($contract, $asOf, $dryRun) {
            /** @var Contract $locked */
            $locked = Contract::lockForUpdate()->find($contract->id);

            $canonical = $this->computeExpirations($locked, $asOf);
            $canonicalByLot = [];
            foreach ($canonical as $exp) {
                $canonicalByLot[$exp['lot_id']] = $exp;
            }

            $existing = PrepayTransaction::where('contract_id', $locked->id)
                ->where('source', PrepayTransactionSource::Expiration)
                ->get();

            $created = 0;
            $updated = 0;
            $deleted = 0;
            $forfeited = 0.0;

            // Delete Expiration rows that no longer match a canonical lot — including
            // orphans whose expired_transaction_id FK was nulled (defensive: cascade
            // normally removes these when the lot is deleted).
            $existingByLot = [];
            foreach ($existing as $row) {
                $lotId = $row->expired_transaction_id;
                if ($lotId === null || ! isset($canonicalByLot[$lotId])) {
                    if (! $dryRun) {
                        $row->delete();
                    }
                    $deleted++;

                    continue;
                }
                $existingByLot[$lotId] = $row;
            }

            // Create or update the canonical forfeitures.
            foreach ($canonical as $exp) {
                $lotId = $exp['lot_id'];
                $hours = $exp['hours'];
                $forfeited += $hours;
                $row = $existingByLot[$lotId] ?? null;

                if ($row) {
                    $changed = round(abs((float) $row->hours), 4) !== round($hours, 4)
                        || optional($row->expiry_date)->ne($exp['expiry_date']);
                    if ($changed) {
                        if (! $dryRun) {
                            $row->update([
                                'hours' => -$hours,
                                'date' => $exp['expiry_date'],
                                'expiry_date' => $exp['expiry_date'],
                                'description' => self::EXPIRY_DESCRIPTION,
                            ]);
                        }
                        $updated++;
                    }

                    continue;
                }

                if (! $dryRun) {
                    PrepayTransaction::create([
                        'contract_id' => $locked->id,
                        'source' => PrepayTransactionSource::Expiration,
                        'expired_transaction_id' => $lotId,
                        'date' => $exp['expiry_date'],
                        'hours' => -$hours,
                        'expiry_date' => $exp['expiry_date'],
                        'description' => self::EXPIRY_DESCRIPTION,
                    ]);
                }
                $created++;
            }

            if (! $dryRun && ($created || $updated || $deleted)) {
                $this->prepayService->recalculateBalanceLocked($locked);

                Log::info('[PrepayExpiration] Reconciled forfeitures', [
                    'contract_id' => $locked->id,
                    'forfeited_hours' => round($forfeited, 4),
                    'created' => $created,
                    'updated' => $updated,
                    'deleted' => $deleted,
                ]);
            }

            return [
                'skipped' => false,
                'forfeited_hours' => round($forfeited, 4),
                'lots' => count($canonical),
                'created' => $created,
                'updated' => $updated,
                'deleted' => $deleted,
            ];
        });
    }

    /**
     * Pure FIFO chronological replay. Returns the canonical forfeitures as of
     * $asOf: one entry per eligible lot (PSA-native, has expiry_date, expiry
     * passed) that still has an unconsumed remainder.
     *
     * @return list<array{lot_id:int, expiry_date:CarbonInterface, hours:float}>
     */
    public function computeExpirations(Contract $contract, CarbonInterface $asOf): array
    {
        if (! $contract->has_prepay || $contract->prepay_as_amount) {
            return [];
        }

        return $this->replayLedger($contract, $asOf)['forfeitures'];
    }

    /**
     * Forward-looking projection for the portal balance widget: the soonest
     * FUTURE lot expiry that still holds an unconsumed remainder as of $asOf —
     * i.e. when the current balance next begins to lapse. Hours are summed across
     * lots sharing that soonest expiry instant. Returns null when nothing is
     * scheduled to lapse: dollar-based prepay, no expiry dates set, legacy/Halo
     * lots only, or every future lot already drawn down. Read-only; the same FIFO
     * replay that drives forfeiture, so the figure matches what will forfeit.
     *
     * @return array{expiry_date: CarbonInterface, hours: float}|null
     */
    public function nextExpiration(Contract $contract, ?CarbonInterface $asOf = null): ?array
    {
        $asOf ??= now();

        if (! $contract->has_prepay || $contract->prepay_as_amount) {
            return null;
        }

        $soonest = null;
        $hours = 0.0;

        foreach ($this->replayLedger($contract, $asOf)['lots'] as $lot) {
            // Only PSA-native lots with a future expiry can lapse next; legacy/Halo
            // lots never forfeit, and already-expired lots have been zeroed.
            if (! $lot['eligible'] || $lot['expiry']->lte($asOf)) {
                continue;
            }

            $remaining = round($lot['remaining'], 4);
            if ($remaining <= 0) {
                continue;
            }

            if ($soonest === null || $lot['expiry']->lt($soonest)) {
                $soonest = $lot['expiry'];
                $hours = $remaining;
            } elseif ($lot['expiry']->eq($soonest)) {
                $hours += $remaining;
            }
        }

        return $soonest === null ? null : ['expiry_date' => $soonest, 'hours' => round($hours, 4)];
    }

    /**
     * Chronological FIFO replay of the ledger up to $asOf. Shared by
     * computeExpirations() (which consumes the 'forfeitures') and nextExpiration()
     * (which reads the post-replay lot remainders). Callers pre-check
     * has_prepay / prepay_as_amount.
     *
     * @return array{
     *     lots: list<array{id:int, remaining:float, date:CarbonInterface, expiry:?CarbonInterface, eligible:bool}>,
     *     forfeitures: list<array{lot_id:int, expiry_date:CarbonInterface, hours:float}>
     * }
     */
    private function replayLedger(Contract $contract, CarbonInterface $asOf): array
    {
        $txns = $contract->prepayTransactions()
            ->orderByRaw('COALESCE(date, created_at) asc')
            ->orderBy('id')
            ->get();

        // FIFO queue of credit lots (all positive-hours credits, oldest first).
        // Legacy/Halo lots participate in consumption allocation for accuracy but
        // never forfeit (eligible = false), protecting pre-PSA balances.
        $lots = [];
        foreach ($txns as $t) {
            $hours = (float) $t->hours;
            if ($hours <= 0) {
                continue;
            }
            $lots[] = [
                'id' => $t->id,
                'remaining' => $hours,
                'date' => $t->date ?? $t->created_at,
                'expiry' => $t->expiry_date,
                'eligible' => $t->halo_id === null && $t->expiry_date !== null,
            ];
        }

        if ($lots === []) {
            return ['lots' => [], 'forfeitures' => []];
        }

        // Events: consumption debits (excluding our own Expiration output) and an
        // expiry checkpoint per eligible, already-expired lot. rank orders ties at
        // the same instant: spend (1) before forfeit (2).
        $events = [];
        foreach ($txns as $t) {
            $hours = (float) $t->hours;
            if ($hours < 0 && $t->source !== PrepayTransactionSource::Expiration) {
                $date = $t->date ?? $t->created_at;
                if ($date->lte($asOf)) {
                    $events[] = ['date' => $date, 'rank' => 1, 'kind' => 'debit', 'amount' => abs($hours)];
                }
            }
        }
        foreach ($lots as $i => $lot) {
            if ($lot['eligible'] && $lot['expiry']->lte($asOf)) {
                $events[] = ['date' => $lot['expiry'], 'rank' => 2, 'kind' => 'expire', 'lot' => $i];
            }
        }

        usort($events, function ($a, $b) {
            $cmp = $a['date']->timestamp <=> $b['date']->timestamp;

            return $cmp !== 0 ? $cmp : ($a['rank'] <=> $b['rank']);
        });

        $forfeitures = [];
        foreach ($events as $event) {
            if ($event['kind'] === 'debit') {
                $amount = $event['amount'];
                foreach ($lots as $i => $lot) {
                    if ($amount <= 0) {
                        break;
                    }
                    // FIFO: only lots that exist by this point and still have balance.
                    if ($lot['remaining'] <= 0 || $lot['date']->gt($event['date'])) {
                        continue;
                    }
                    $draw = min($lot['remaining'], $amount);
                    $lots[$i]['remaining'] -= $draw;
                    $amount -= $draw;
                }

                continue;
            }

            // expire checkpoint
            $i = $event['lot'];
            $remaining = round($lots[$i]['remaining'], 4);
            if ($remaining > 0) {
                $forfeitures[] = [
                    'lot_id' => $lots[$i]['id'],
                    'expiry_date' => $lots[$i]['expiry'],
                    'hours' => $remaining,
                ];
            }
            $lots[$i]['remaining'] = 0.0;
        }

        return ['lots' => $lots, 'forfeitures' => $forfeitures];
    }

    /**
     * @return array{skipped:bool, forfeited_hours:float, lots:int, created:int, updated:int, deleted:int}
     */
    private function summary(bool $skipped): array
    {
        return [
            'skipped' => $skipped,
            'forfeited_hours' => 0.0,
            'lots' => 0,
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
        ];
    }
}
