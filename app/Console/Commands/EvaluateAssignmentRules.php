<?php

namespace App\Console\Commands;

use App\Services\ContractAssignmentService;
use Illuminate\Console\Command;

class EvaluateAssignmentRules extends Command
{
    protected $signature = 'contracts:evaluate-rules
        {--dry-run : Show what would change without committing}
        {--contract= : Evaluate rules for a specific contract ID only}';

    protected $description = 'Evaluate contract assignment rules and sync asset/people assignments';

    public function handle(ContractAssignmentService $service): int
    {
        $dryRun = $this->option('dry-run');
        $contractId = $this->option('contract');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be committed.');
        }

        if ($contractId) {
            $contract = \App\Models\Contract::findOrFail($contractId);
            $result = $service->evaluateRules($contract, $dryRun);
            $verb = $dryRun ? 'would be' : '';
            $this->info("Contract \"{$contract->name}\": {$result['assets_added']} assets added, {$result['assets_removed']} removed; {$result['people_added']} people added, {$result['people_removed']} removed. {$verb}");

            return self::SUCCESS;
        }

        $result = $service->evaluateAllContractRules($dryRun);

        $this->info("Evaluated {$result['contracts_processed']} contracts.");
        $this->info("Assets: {$result['assets_added']} added, {$result['assets_removed']} removed.");
        $this->info("People: {$result['people_added']} added, {$result['people_removed']} removed.");

        return self::SUCCESS;
    }
}
