<?php

namespace App\Console\Commands;

use App\Services\Contracts\ContractLineDrift;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ContractLinesDriftReport extends Command
{
    protected $signature = 'contract-lines:drift {--contract=}';

    protected $description = 'Report contract line drift without values or writes';

    public function handle(ContractLineDrift $drift): int
    {
        if ($this->option('contract') !== null && ! preg_match('/^[1-9][0-9]*$/D', (string) $this->option('contract'))) {
            $this->error('status=invalid_contract');

            return self::SUCCESS;
        }
        try {
            $profiles = DB::table('recurring_invoice_profiles')->where('is_active', true)
                ->when($this->option('contract') !== null, fn ($query) => $query->where('contract_id', $this->option('contract')))
                ->orderBy('contract_id')->orderBy('id')->get();
            $rows = [];
            foreach ($profiles as $profile) {
                $version = DB::table('contract_lines')->where('contract_id', $profile->contract_id)->max('version');
                $lines = $version === null ? [] : DB::table('contract_lines')
                    ->where('contract_id', $profile->contract_id)->where('version', $version)
                    ->orderBy('id')->get()->map(fn ($line) => (array) $line)->all();
                $source = DB::table('recurring_invoice_profile_lines')->where('profile_id', $profile->id)
                    ->orderBy('id')->get()->map(fn ($line) => (array) $line)->all();
                foreach ($drift->compare($lines, $source) as $entry) {
                    $rows[] = [$profile->contract_id, $profile->id, $entry['contract_line_id'], $entry['profile_line_id'],
                        $entry['status'].($entry['columns'] === [] ? '' : ': '.implode(', ', $entry['columns']))];
                }
            }
            $this->table(['contract_id', 'profile_id', 'contract_line_id', 'profile_line_id', 'status'], $rows);
            $this->line('profiles='.$profiles->count().' drift='.count($rows));
        } catch (Throwable $error) {
            $this->error('status=failed class='.$error::class);
        }

        return self::SUCCESS;
    }
}
