<?php

namespace App\Observers;

use App\Models\Asset;
use App\Models\Contract;
use App\Services\AssetUserAssignmentService;
use App\Services\ContractAssignmentService;

class AssetObserver
{
    public function __construct(
        private readonly ContractAssignmentService $assignmentService,
    ) {}

    public function created(Asset $asset): void
    {
        $this->assignmentService->evaluateRulesForAsset($asset);
    }

    public function updated(Asset $asset): void
    {
        if ($asset->wasChanged(['client_id', 'is_active', 'asset_type'])) {
            // If client changed, evaluate rules for the old client's contracts too
            if ($asset->wasChanged('client_id')) {
                $this->evaluateOldClientContracts($asset->getOriginal('client_id'));
            }

            $this->assignmentService->evaluateRulesForAsset($asset);
        }

        // Auto-assign user when last_user changes (e.g., after NinjaRMM sync)
        if ($asset->wasChanged('last_user') && $asset->last_user) {
            app(AssetUserAssignmentService::class)->assignForAsset($asset);
        }
    }

    public function deleted(Asset $asset): void
    {
        $this->assignmentService->evaluateRulesForAsset($asset);
    }

    private function evaluateOldClientContracts(?int $oldClientId): void
    {
        if (! $oldClientId) {
            return;
        }

        $contracts = Contract::active()
            ->where('client_id', $oldClientId)
            ->whereHas('assignmentRules', fn ($q) => $q->where('is_active', true))
            ->get();

        foreach ($contracts as $contract) {
            $this->assignmentService->evaluateRules($contract);
        }
    }
}
