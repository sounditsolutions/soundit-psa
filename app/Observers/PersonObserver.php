<?php

namespace App\Observers;

use App\Models\Contract;
use App\Models\Person;
use App\Models\PersonEmail;
use App\Services\ContractAssignmentService;

class PersonObserver
{
    public function __construct(
        private readonly ContractAssignmentService $assignmentService,
    ) {}

    public function created(Person $person): void
    {
        $this->assignmentService->evaluateRulesForPerson($person);
    }

    public function updated(Person $person): void
    {
        if ($person->wasChanged(['client_id', 'is_active', 'person_type'])) {
            if ($person->wasChanged('client_id')) {
                $this->evaluateOldClientContracts($person->getOriginal('client_id'));
            }

            $this->assignmentService->evaluateRulesForPerson($person);
        }
    }

    /**
     * Auto-sync the primary email to person_emails lookup table.
     * Fires on both create and update — covers admin edits, imports, and API sync.
     */
    public function saved(Person $person): void
    {
        if ($person->email) {
            PersonEmail::updateOrCreate(
                ['person_id' => $person->id, 'is_primary' => true],
                ['email' => mb_strtolower(trim($person->email)), 'source' => 'sync'],
            );
        } elseif ($person->wasChanged('email') && ! $person->email) {
            // Primary email was removed — delete the primary row
            PersonEmail::where('person_id', $person->id)
                ->where('is_primary', true)
                ->delete();
        }
    }

    public function deleted(Person $person): void
    {
        $this->assignmentService->evaluateRulesForPerson($person);
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
