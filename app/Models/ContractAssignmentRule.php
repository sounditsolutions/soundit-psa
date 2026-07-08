<?php

namespace App\Models;

use App\Enums\AssignmentRuleType;
use App\Enums\PersonType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class ContractAssignmentRule extends Model
{
    protected $fillable = [
        'contract_id',
        'name',
        'rule_type',
        'filter_values',
        'is_active',
        'last_evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'rule_type' => AssignmentRuleType::class,
            'filter_values' => 'array',
            'is_active' => 'boolean',
            'last_evaluated_at' => 'datetime',
        ];
    }

    // ── Relations ──

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    // ── Rule Evaluation ──

    /**
     * Evaluate this rule against the contract's client entities.
     * Returns a collection of matching Asset or Person models.
     */
    public function evaluate(): Collection
    {
        $clientId = $this->contract->client_id;

        return match ($this->rule_type) {
            AssignmentRuleType::AllAssets => Asset::where('client_id', $clientId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->get(),

            AssignmentRuleType::AssetsByType => Asset::where('client_id', $clientId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->whereIn('asset_type', $this->filter_values ?? [])
                ->get(),

            AssignmentRuleType::AllPeople => Person::where('client_id', $clientId)
                ->whereNull('deleted_at')
                ->where('person_type', PersonType::User->value)
                ->get(),

            AssignmentRuleType::AllActivePeople => Person::where('client_id', $clientId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where('person_type', PersonType::User->value)
                ->get(),
        };
    }
}
