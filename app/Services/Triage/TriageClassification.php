<?php

namespace App\Services\Triage;

class TriageClassification
{
    public function __construct(
        public readonly string $clientType, // managed_services, break_fix, no_contract
        public readonly bool $hasActiveContract,
        public readonly bool $hasPrepaidTime,
        public readonly float $prepaidBalance,
        public readonly array $contractIds,
        public readonly bool $workCoveredByManaged,
        public readonly string $route,
        public readonly string $reasoning,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            clientType: $data['client_type'] ?? 'no_contract',
            hasActiveContract: $data['has_active_contract'] ?? false,
            hasPrepaidTime: $data['has_prepaid_time'] ?? false,
            prepaidBalance: (float) ($data['prepaid_balance'] ?? 0),
            contractIds: $data['contract_ids'] ?? [],
            workCoveredByManaged: $data['work_covered_by_managed'] ?? false,
            route: $data['route'] ?? 'technical',
            reasoning: $data['reasoning'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'client_type' => $this->clientType,
            'has_active_contract' => $this->hasActiveContract,
            'has_prepaid_time' => $this->hasPrepaidTime,
            'prepaid_balance' => $this->prepaidBalance,
            'contract_ids' => $this->contractIds,
            'work_covered_by_managed' => $this->workCoveredByManaged,
            'route' => $this->route,
            'reasoning' => $this->reasoning,
        ];
    }
}
