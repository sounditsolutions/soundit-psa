<?php

namespace App\Services;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Models\Contract;
use App\Models\ContractActivity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContractService
{
    public function createContract(array $data): Contract
    {
        return Contract::create($data);
    }

    public function updateContract(Contract $contract, array $data): Contract
    {
        $contract->update($data);

        return $contract->fresh();
    }

    public function getContractList(array $filters): LengthAwarePaginator
    {
        $query = Contract::query()->with('client')->withCount('profiles');
        $this->applyFilters($query, $filters);

        return $query
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * Shared filter logic used by both indexAll pagination and getFilteredContractIds.
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query;
    }

    /**
     * Get IDs of contracts matching the given filters (for select-all-filter bulk actions).
     */
    public function getFilteredContractIds(array $filters): array
    {
        $query = Contract::query();
        $this->applyFilters($query, $filters);

        return $query->pluck('id')->all();
    }

    // ── Bulk Operations ──

    /**
     * Load contracts by IDs for bulk operations.
     *
     * @return array{editable: \Illuminate\Support\Collection, skipped: int}
     */
    private function partitionEditable(array $ids): array
    {
        return ['editable' => Contract::whereIn('id', $ids)->get(), 'skipped' => 0];
    }

    public function bulkChangeStatus(array $ids, ContractStatus $status, int $userId): array
    {
        $partition = $this->partitionEditable($ids);

        return DB::transaction(function () use ($partition, $status, $userId) {
            $affected = 0;

            foreach ($partition['editable'] as $contract) {
                $oldStatus = $contract->status;
                if ($oldStatus === $status) {
                    continue;
                }

                $contract->update(['status' => $status]);
                $affected++;

                ContractActivity::create([
                    'contract_id' => $contract->id,
                    'user_id' => $userId,
                    'action' => 'bulk_status_change',
                    'changes' => [
                        'old_status' => $oldStatus->value,
                        'new_status' => $status->value,
                    ],
                    'created_at' => now(),
                ]);
            }

            return ['affected' => $affected, 'skipped' => $partition['skipped']];
        });
    }

    public function bulkChangeType(array $ids, ContractType $type, int $userId): array
    {
        $partition = $this->partitionEditable($ids);

        return DB::transaction(function () use ($partition, $type, $userId) {
            $affected = 0;

            foreach ($partition['editable'] as $contract) {
                $oldType = $contract->type;
                if ($oldType === $type) {
                    continue;
                }

                $contract->update(['type' => $type]);
                $affected++;

                ContractActivity::create([
                    'contract_id' => $contract->id,
                    'user_id' => $userId,
                    'action' => 'bulk_type_change',
                    'changes' => [
                        'old_type' => $oldType->value,
                        'new_type' => $type->value,
                    ],
                    'created_at' => now(),
                ]);
            }

            return ['affected' => $affected, 'skipped' => $partition['skipped']];
        });
    }

    public function bulkEditAttributes(array $ids, array $attributes, int $userId): array
    {
        $partition = $this->partitionEditable($ids);

        return DB::transaction(function () use ($partition, $attributes, $userId) {
            $affected = 0;

            foreach ($partition['editable'] as $contract) {
                $changes = [];

                foreach ($attributes as $field => $value) {
                    $oldValue = $contract->{$field};
                    // Cast for comparison
                    if (is_bool($value)) {
                        $changed = (bool) $oldValue !== $value;
                    } else {
                        $changed = (string) $oldValue !== (string) $value;
                    }

                    if ($changed) {
                        $changes[$field] = ['old' => $oldValue, 'new' => $value];
                    }
                }

                if (empty($changes)) {
                    continue;
                }

                $contract->update($attributes);
                $affected++;

                ContractActivity::create([
                    'contract_id' => $contract->id,
                    'user_id' => $userId,
                    'action' => 'bulk_edit',
                    'changes' => $changes,
                    'created_at' => now(),
                ]);
            }

            return ['affected' => $affected, 'skipped' => $partition['skipped']];
        });
    }

    public function bulkDelete(array $ids, int $userId): array
    {
        $partition = $this->partitionEditable($ids);

        return DB::transaction(function () use ($partition, $userId) {
            $affected = 0;

            foreach ($partition['editable'] as $contract) {
                ContractActivity::create([
                    'contract_id' => $contract->id,
                    'user_id' => $userId,
                    'action' => 'bulk_delete',
                    'changes' => [
                        'name' => $contract->name,
                        'client_id' => $contract->client_id,
                    ],
                    'created_at' => now(),
                ]);

                $contract->delete(); // SoftDelete — also deactivates profiles via booted() event
                $affected++;
            }

            return ['affected' => $affected, 'skipped' => $partition['skipped']];
        });
    }
}
