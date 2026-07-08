<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\License;
use App\Models\Person;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContractAssignmentService
{
    // ── Manual Assignment ──

    public function assignAsset(Contract $contract, Asset $asset): void
    {
        if ($contract->assets()->where('asset_id', $asset->id)->exists()) {
            return;
        }

        $contract->assets()->attach($asset->id, [
            'assignment_source' => 'manual',
            'assigned_at' => now(),
        ]);

        $this->logActivity($contract, 'assignment_added', [
            'entity_type' => 'asset',
            'entity_id' => $asset->id,
            'entity_name' => $asset->hostname ?: $asset->name,
            'source' => 'manual',
        ]);
    }

    public function unassignAsset(Contract $contract, Asset $asset): void
    {
        $contract->assets()->detach($asset->id);

        $this->logActivity($contract, 'assignment_removed', [
            'entity_type' => 'asset',
            'entity_id' => $asset->id,
            'entity_name' => $asset->hostname ?: $asset->name,
        ]);
    }

    public function assignPerson(Contract $contract, Person $person): void
    {
        if ($contract->people()->where('person_id', $person->id)->exists()) {
            return;
        }

        $contract->people()->attach($person->id, [
            'assignment_source' => 'manual',
            'assigned_at' => now(),
        ]);

        $this->logActivity($contract, 'assignment_added', [
            'entity_type' => 'person',
            'entity_id' => $person->id,
            'entity_name' => $person->full_name,
            'source' => 'manual',
        ]);
    }

    public function unassignPerson(Contract $contract, Person $person): void
    {
        $contract->people()->detach($person->id);

        $this->logActivity($contract, 'assignment_removed', [
            'entity_type' => 'person',
            'entity_id' => $person->id,
            'entity_name' => $person->full_name,
        ]);
    }

    // ── Rule Evaluation ──

    /**
     * Evaluate all active rules for a contract.
     * Adds new matching entities and removes rule-based assignments that no longer match.
     * Manual assignments are never touched.
     */
    public function evaluateRules(Contract $contract, bool $dryRun = false): array
    {
        $rules = $contract->assignmentRules()->where('is_active', true)->orderBy('id')->get();
        $assetsAdded = 0;
        $peopleAdded = 0;
        $matchedAssetIds = collect();
        $matchedPersonIds = collect();

        foreach ($rules as $rule) {
            $matches = $rule->evaluate();
            $entityType = $rule->rule_type->entityType();

            if ($entityType === 'asset') {
                $matchedAssetIds = $matchedAssetIds->merge($matches->pluck('id'));
            } else {
                $matchedPersonIds = $matchedPersonIds->merge($matches->pluck('id'));
            }

            foreach ($matches as $entity) {
                if ($entityType === 'asset') {
                    if ($contract->assets()->where('asset_id', $entity->id)->exists()) {
                        continue;
                    }
                    if (! $dryRun) {
                        $contract->assets()->attach($entity->id, [
                            'assignment_source' => 'rule',
                            'rule_id' => $rule->id,
                            'assigned_at' => now(),
                        ]);
                    }
                    $assetsAdded++;
                } else {
                    if ($contract->people()->where('person_id', $entity->id)->exists()) {
                        continue;
                    }
                    if (! $dryRun) {
                        $contract->people()->attach($entity->id, [
                            'assignment_source' => 'rule',
                            'rule_id' => $rule->id,
                            'assigned_at' => now(),
                        ]);
                    }
                    $peopleAdded++;
                }
            }

            if (! $dryRun) {
                $rule->update(['last_evaluated_at' => now()]);
            }
        }

        // Removal pass: detach rule-based assignments that no longer match any active rule
        $assetsRemoved = 0;
        $peopleRemoved = 0;

        $staleAssetQuery = $contract->assets()
            ->wherePivot('assignment_source', 'rule')
            ->whereNotIn('assets.id', $matchedAssetIds->unique()->all());

        $stalePersonQuery = $contract->people()
            ->wherePivot('assignment_source', 'rule')
            ->whereNotIn('people.id', $matchedPersonIds->unique()->all());

        if (! $dryRun) {
            $staleAssets = $staleAssetQuery->get();
            foreach ($staleAssets as $asset) {
                $contract->assets()->detach($asset->id);
                $assetsRemoved++;
                $this->logActivity($contract, 'assignment_removed', [
                    'entity_type' => 'asset',
                    'entity_id' => $asset->id,
                    'entity_name' => $asset->hostname ?: $asset->name,
                    'source' => 'rule_cleanup',
                ]);
            }

            $stalePeople = $stalePersonQuery->get();
            foreach ($stalePeople as $person) {
                $contract->people()->detach($person->id);
                $peopleRemoved++;
                $this->logActivity($contract, 'assignment_removed', [
                    'entity_type' => 'person',
                    'entity_id' => $person->id,
                    'entity_name' => $person->full_name,
                    'source' => 'rule_cleanup',
                ]);
            }
        } else {
            $assetsRemoved = $staleAssetQuery->count();
            $peopleRemoved = $stalePersonQuery->count();
        }

        $anyChanges = $assetsAdded > 0 || $peopleAdded > 0 || $assetsRemoved > 0 || $peopleRemoved > 0;
        if (! $dryRun && $anyChanges) {
            $this->logActivity($contract, 'rule_evaluated', [
                'assets_added' => $assetsAdded,
                'assets_removed' => $assetsRemoved,
                'people_added' => $peopleAdded,
                'people_removed' => $peopleRemoved,
                'rules_count' => $rules->count(),
            ]);
        }

        return [
            'assets_added' => $assetsAdded,
            'assets_removed' => $assetsRemoved,
            'people_added' => $peopleAdded,
            'people_removed' => $peopleRemoved,
        ];
    }

    /**
     * Evaluate rules for all active contracts. Used by daily cron.
     */
    public function evaluateAllContractRules(bool $dryRun = false): array
    {
        $contracts = Contract::active()
            ->whereHas('assignmentRules', fn ($q) => $q->where('is_active', true))
            ->get();

        $totalAssetsAdded = 0;
        $totalAssetsRemoved = 0;
        $totalPeopleAdded = 0;
        $totalPeopleRemoved = 0;
        $contractsProcessed = 0;

        foreach ($contracts as $contract) {
            $result = $this->evaluateRules($contract, $dryRun);
            $totalAssetsAdded += $result['assets_added'];
            $totalAssetsRemoved += $result['assets_removed'];
            $totalPeopleAdded += $result['people_added'];
            $totalPeopleRemoved += $result['people_removed'];
            $contractsProcessed++;
        }

        return [
            'contracts_processed' => $contractsProcessed,
            'assets_added' => $totalAssetsAdded,
            'assets_removed' => $totalAssetsRemoved,
            'people_added' => $totalPeopleAdded,
            'people_removed' => $totalPeopleRemoved,
        ];
    }

    /**
     * Event-driven: evaluate rules for a specific asset across all
     * active contracts belonging to the asset's client.
     */
    public function evaluateRulesForAsset(Asset $asset): void
    {
        if (! $asset->client_id) {
            return;
        }

        $contracts = Contract::active()
            ->where('client_id', $asset->client_id)
            ->whereHas('assignmentRules', fn ($q) => $q->where('is_active', true))
            ->get();

        foreach ($contracts as $contract) {
            $this->evaluateRules($contract);
        }
    }

    /**
     * Event-driven: evaluate rules for a specific person across all
     * active contracts belonging to the person's client.
     */
    public function evaluateRulesForPerson(Person $person): void
    {
        if (! $person->client_id) {
            return;
        }

        $contracts = Contract::active()
            ->where('client_id', $person->client_id)
            ->whereHas('assignmentRules', fn ($q) => $q->where('is_active', true))
            ->get();

        foreach ($contracts as $contract) {
            $this->evaluateRules($contract);
        }
    }

    // ── Bulk License Assignment ──

    /**
     * Assign all unassigned client licenses to a contract.
     * "Unassigned" means not assigned to ANY contract for this client.
     */
    public function assignAllLicenses(Contract $contract): int
    {
        $unassigned = $this->getUnassignedLicenses($contract);

        if ($unassigned->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($contract, $unassigned) {
            $pivotData = [];
            foreach ($unassigned as $license) {
                $pivotData[$license->id] = [
                    'assignment_source' => 'manual',
                    'assigned_at' => now(),
                ];
            }

            $contract->licenses()->attach($pivotData);

            $this->logActivity($contract, 'bulk_license_assignment', [
                'count' => $unassigned->count(),
                'license_ids' => $unassigned->pluck('id')->all(),
            ]);

            return $unassigned->count();
        });
    }

    // ── Queries ──

    public function getUnassignedAssets(Contract $contract): Collection
    {
        $assignedIds = $contract->assets()->pluck('assets.id');

        return Asset::where('client_id', $contract->client_id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotIn('id', $assignedIds)
            ->orderBy('hostname')
            ->orderBy('name')
            ->get();
    }

    public function getUnassignedPeople(Contract $contract): Collection
    {
        $assignedIds = $contract->people()->pluck('people.id');

        return Person::where('client_id', $contract->client_id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->billable()
            ->whereNotIn('id', $assignedIds)
            ->orderBy('first_name')
            ->get();
    }

    /**
     * Get licenses for this client that are NOT assigned to ANY contract.
     * This prevents double-assignment on multi-contract clients.
     */
    public function getUnassignedLicenses(Contract $contract): Collection
    {
        $assignedToAnyContract = DB::table('contract_license')
            ->join('contracts', 'contracts.id', '=', 'contract_license.contract_id')
            ->where('contracts.client_id', $contract->client_id)
            ->pluck('contract_license.license_id');

        return License::where('client_id', $contract->client_id)
            ->where('status', 'active')
            ->whereNotIn('id', $assignedToAnyContract)
            ->with('licenseType')
            ->get();
    }

    // ── Activity Log ──

    private function logActivity(Contract $contract, string $action, ?array $changes = null): void
    {
        try {
            ContractActivity::create([
                'contract_id' => $contract->id,
                'user_id' => Auth::id(),
                'action' => $action,
                'changes' => $changes,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("[ContractActivity] Failed to log: {$e->getMessage()}");
        }
    }
}
