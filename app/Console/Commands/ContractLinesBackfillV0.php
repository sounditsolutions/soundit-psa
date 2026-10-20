<?php

namespace App\Console\Commands;

use App\Models\ContractActivity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ContractLinesBackfillV0 extends Command
{
    protected $signature = 'contract-lines:backfill-v0 {--commit} {--contract=} {--rollback}';

    protected $description = 'Preview or apply contract line v0 backfill or rollback';

    private const COLUMNS = [
        'sku_id', 'description', 'quantity_type', 'fixed_quantity', 'unit_price',
        'pricing_tiers', 'is_taxable', 'sort_order',
    ];

    public function handle(): int
    {
        if ($this->option('contract') !== null && ! preg_match('/^[1-9][0-9]*$/D', (string) $this->option('contract'))) {
            $this->error('status=invalid_contract');

            return self::FAILURE;
        }
        try {
            return $this->option('rollback') ? $this->rollback() : $this->backfill();
        } catch (Throwable $error) {
            $this->error('status=failed class='.$error::class);

            return self::FAILURE;
        }
    }

    private function backfill(): int
    {
        $profiles = DB::table('recurring_invoice_profiles')->orderBy('contract_id')->orderBy('id')
            ->when($this->option('contract') !== null, fn ($query) => $query->where('contract_id', $this->option('contract')))
            ->get()->groupBy('contract_id');
        $plan = [];
        $rows = [];
        $skipped = 0;
        foreach ($profiles as $contractId => $group) {
            $contract = DB::table('contracts')->find($contractId);
            $activeCount = $group->where('is_active', true)->count();
            foreach ($group as $profile) {
                $reason = match (true) {
                    ! $profile->is_active => 'inactive profile',
                    $contract->deleted_at !== null => 'soft-deleted contract',
                    $activeCount > 1 => 'multiple active profiles',
                    DB::table('contract_lines')->where('contract_id', $contractId)->where('source', 'backfill_v0')->exists() => 'already backfilled',
                    default => null,
                };
                $lines = DB::table('recurring_invoice_profile_lines')->where('profile_id', $profile->id)->orderBy('id')->get();
                if ($reason === null && $lines->isEmpty()) {
                    $reason = 'empty profile';
                }
                $rows[] = [$contractId, $profile->id, $reason === null ? $lines->count() : 0, $reason ?? 'to write'];
                if ($reason !== null) {
                    $skipped++;
                } else {
                    $plan[] = ['contract_id' => $contractId, 'profile_id' => $profile->id, 'lines' => $lines];
                }
            }
        }
        $this->table(['contract_id', 'profile_id', 'line_count', 'status'], $rows);
        $this->line('contracts_to_write='.count($plan).' lines_to_write='.array_sum(array_map(fn ($item) => $item['lines']->count(), $plan)).' skipped='.$skipped);
        if ($this->option('commit')) {
            foreach ($plan as $item) {
                DB::transaction(function () use ($item) {
                    $ids = [];
                    foreach ($item['lines'] as $line) {
                        $attributes = array_intersect_key((array) $line, array_flip(self::COLUMNS));
                        $ids[] = DB::table('contract_lines')->insertGetId($attributes + [
                            'contract_id' => $item['contract_id'], 'version' => 0, 'source' => 'backfill_v0',
                            'source_profile_line_id' => $line->id, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                    ContractActivity::create([
                        'contract_id' => $item['contract_id'], 'action' => 'contract_lines_backfilled_v0',
                        'changes' => ['line_count' => count($ids), 'line_ids' => $ids], 'created_at' => now(),
                    ]);
                });
            }
        }

        return self::SUCCESS;
    }

    private function rollback(): int
    {
        $lines = DB::table('contract_lines')->where('version', 0)->where('source', 'backfill_v0')
            ->when($this->option('contract') !== null, fn ($query) => $query->where('contract_id', $this->option('contract')));
        $activities = DB::table('contract_activities')->where('action', 'contract_lines_backfilled_v0')
            ->when($this->option('contract') !== null, fn ($query) => $query->where('contract_id', $this->option('contract')));
        $contractIds = $lines->pluck('contract_id')->merge($activities->pluck('contract_id'))->unique()->sort();
        $rows = [];
        foreach ($contractIds as $id) {
            $rows[] = [$id, (clone $lines)->where('contract_id', $id)->count(), (clone $activities)->where('contract_id', $id)->count()];
        }
        $this->table(['contract_id', 'line_count', 'activity_count'], $rows);
        $this->line('lines_to_delete='.$lines->count().' activities_to_delete='.$activities->count());
        if ($this->option('commit')) {
            foreach ($contractIds as $id) {
                DB::transaction(function () use ($lines, $activities, $id) {
                    (clone $lines)->where('contract_id', $id)->delete();
                    (clone $activities)->where('contract_id', $id)->delete();
                });
            }
        }

        return self::SUCCESS;
    }
}
