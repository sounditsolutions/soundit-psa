<?php

namespace App\Services;

use App\Enums\ClientStage;
use App\Models\Email;
use App\Models\Person;
use App\Models\PersonEmail;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

class PersonService
{
    public function createPerson(array $data): Person
    {
        $this->normalizePhones($data);

        $additionalEmails = $data['additional_emails'] ?? null;
        unset($data['additional_emails']);

        return DB::transaction(function () use ($data, $additionalEmails) {
            // If setting as primary, demote existing primary for this client
            if (! empty($data['is_primary']) && ! empty($data['client_id'])) {
                Person::where('client_id', $data['client_id'])
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $person = Person::create($data);

            if ($additionalEmails !== null) {
                $this->syncAdditionalEmails($person, $additionalEmails);
            }

            return $person;
        });
    }

    public function updatePerson(Person $person, array $data): Person
    {
        $this->normalizePhones($data);

        $additionalEmails = $data['additional_emails'] ?? null;
        unset($data['additional_emails']);

        return DB::transaction(function () use ($person, $data, $additionalEmails) {
            // If setting as primary, demote existing primary for this client
            $clientId = $data['client_id'] ?? $person->client_id;
            if (! empty($data['is_primary']) && $clientId) {
                Person::where('client_id', $clientId)
                    ->where('is_primary', true)
                    ->where('id', '!=', $person->id)
                    ->update(['is_primary' => false]);
            }

            $person->update($data);

            if ($person->wasChanged('client_id')) {
                $this->detachCrossClientPivots($person);
            }

            if ($additionalEmails !== null) {
                $this->syncAdditionalEmails($person, $additionalEmails);
            }

            return $person->fresh();
        });
    }

    /**
     * Count the person's contract/device pivot links that point at a client
     * other than $clientId (i.e. would be cross-client after a move to it).
     *
     * @return array{contracts:int, assets:int}
     */
    public function crossClientPivotCounts(Person $person, int $clientId): array
    {
        return [
            'contracts' => $person->contracts()->where('contracts.client_id', '!=', $clientId)->count(),
            'assets' => $person->assets()->where('assets.client_id', '!=', $clientId)->count(),
        ];
    }

    /**
     * Detach the person's contract/device pivot links that point at a client
     * other than the person's CURRENT client — after a cross-client move such a
     * link is factually wrong (regardless of assignment_source). Idempotent.
     *
     * Contract detaches route through ContractAssignmentService::unassignPerson so
     * each one leaves a ContractActivity 'assignment_removed' record — consistent
     * with the rest of the contract-assignment surface and important for billing
     * traceability (contract_person feeds per-user/per-workstation quantities).
     * Device (asset) links have no equivalent activity log, so they detach directly.
     *
     * @return array{contracts:int, assets:int}
     */
    public function detachCrossClientPivots(Person $person): array
    {
        $crossContracts = $person->contracts()->where('contracts.client_id', '!=', $person->client_id)->get();
        $assetIds = $person->assets()->where('assets.client_id', '!=', $person->client_id)->pluck('assets.id')->all();

        if ($crossContracts->isNotEmpty()) {
            $assignmentService = app(ContractAssignmentService::class);
            foreach ($crossContracts as $contract) {
                $assignmentService->unassignPerson($contract, $person);
            }
        }
        if ($assetIds !== []) {
            $person->assets()->detach($assetIds);
        }

        return ['contracts' => $crossContracts->count(), 'assets' => count($assetIds)];
    }

    public function deletePerson(Person $person): void
    {
        // Block deletion if person has open tickets
        $openTickets = $person->tickets()
            ->whereIn('status', ['new', 'in_progress', 'pending_client', 'pending_third_party'])
            ->count();

        if ($openTickets > 0) {
            throw new \RuntimeException("Cannot delete contact with {$openTickets} open ticket(s). Resolve or close them first.");
        }

        $person->delete();
    }

    /**
     * Merge a duplicate contact into a surviving contact within the same client.
     *
     * Repoints every reference to the duplicate (tickets, calls, emails, contract
     * and device assignments, additional email addresses) onto the survivor,
     * consolidates portal access and identity fields, then soft-deletes the
     * duplicate. Mirrors TicketService::mergeTickets(): query-builder repoints
     * (no model events), pessimistic locking, all inside one transaction.
     *
     * Moved assignments are stored as `manual` so the post-merge rule
     * reconciliation (PersonObserver::deleted) never strips the consolidated
     * links — manual assignments are never auto-removed.
     *
     * @return array{tickets:int,calls:int,emails:int,contracts:int,assets:int,email_addresses:int,portal_login_email_changed:bool}
     */
    public function mergePeople(Person $survivor, Person $duplicate, int $mergedByUserId): array
    {
        if ($survivor->id === $duplicate->id) {
            throw new \InvalidArgumentException('Cannot merge a contact into itself.');
        }

        if ($survivor->client_id !== $duplicate->client_id) {
            throw new \InvalidArgumentException('Cannot merge contacts from different clients.');
        }

        return DB::transaction(function () use ($survivor, $duplicate, $mergedByUserId) {
            // Pessimistic lock both rows for the duration of the merge
            $survivor = Person::lockForUpdate()->findOrFail($survivor->id);
            $duplicate = Person::lockForUpdate()->findOrFail($duplicate->id);

            // Portal auth resolves people.email (primary) only — not person_emails.
            // If the absorbed record is the portal-active one and its login email
            // differs from the survivor's, that login is intentionally migrated to
            // the survivor's email (the carried password still works). Flag it so
            // the caller can warn the operator. Determine before mutating anything.
            $portalLoginEmailChanged = $duplicate->portal_enabled
                && filled($duplicate->password)
                && filled($duplicate->email)
                && mb_strtolower(trim($duplicate->email)) !== mb_strtolower(trim((string) $survivor->email));

            // Repoint nullable FKs via the query builder (no model observers fire)
            $ticketCount = Ticket::where('contact_id', $duplicate->id)->update(['contact_id' => $survivor->id]);
            $callCount = PhoneCall::where('person_id', $duplicate->id)->update(['person_id' => $survivor->id]);
            $emailCount = Email::where('person_id', $duplicate->id)->update(['person_id' => $survivor->id]);

            // Contract assignments — move the ones the survivor lacks, then detach all from the duplicate
            $survivorContractIds = $survivor->contracts()->pluck('contracts.id')->all();
            $movedContracts = 0;
            foreach ($duplicate->contracts as $contract) {
                if (! in_array($contract->id, $survivorContractIds, true)) {
                    $survivor->contracts()->attach($contract->id, [
                        'assigned_at' => $contract->pivot->assigned_at ?? now(),
                        'assignment_source' => 'manual',
                    ]);
                    $movedContracts++;
                }
            }
            $duplicate->contracts()->detach();

            // Device (asset) assignments — same move-then-detach, preserving primary/last-seen
            $survivorAssetIds = $survivor->assets()->pluck('assets.id')->all();
            $movedAssets = 0;
            foreach ($duplicate->assets as $asset) {
                if (! in_array($asset->id, $survivorAssetIds, true)) {
                    $survivor->assets()->attach($asset->id, [
                        'is_primary' => (bool) $asset->pivot->is_primary,
                        'assignment_source' => 'manual',
                        'last_seen_at' => $asset->pivot->last_seen_at,
                    ]);
                    $movedAssets++;
                }
            }
            $duplicate->assets()->detach();

            // Additional email addresses — carry the duplicate's emails (its primary +
            // any extras) onto the survivor as non-primary, deduped against what the
            // survivor already has, so future email matching still resolves the survivor.
            $survivorEmails = $survivor->allEmailAddresses();
            $movedEmailAddresses = 0;
            $duplicateEmails = [];
            if (filled($duplicate->email)) {
                $duplicateEmails[] = ['email' => mb_strtolower(trim($duplicate->email)), 'label' => null];
            }
            foreach ($duplicate->emailAddresses as $pe) {
                $duplicateEmails[] = ['email' => $pe->email, 'label' => $pe->label];
            }
            foreach ($duplicateEmails as $entry) {
                if (! in_array($entry['email'], $survivorEmails, true)) {
                    PersonEmail::updateOrCreate(
                        ['person_id' => $survivor->id, 'email' => $entry['email']],
                        ['is_primary' => false, 'label' => $entry['label'], 'source' => 'merge'],
                    );
                    $survivorEmails[] = $entry['email'];
                    $movedEmailAddresses++;
                }
            }

            // Fill blank contact fields on the survivor — never overwrite existing data
            foreach (['phone', 'phone_display', 'mobile', 'mobile_display', 'job_title', 'department', 'office_location'] as $field) {
                if (blank($survivor->{$field}) && filled($duplicate->{$field})) {
                    $survivor->{$field} = $duplicate->{$field};
                }
            }

            // CIPP identity — move to the survivor when it has none, so the next
            // cipp:sync-contacts run binds to the survivor instead of resurrecting
            // the soft-deleted duplicate (it matches by cipp_user_id first).
            if (blank($survivor->cipp_user_id) && filled($duplicate->cipp_user_id)) {
                $survivor->cipp_user_id = $duplicate->cipp_user_id;
                $survivor->cipp_upn = $duplicate->cipp_upn;
                $survivor->cipp_synced_at = $duplicate->cipp_synced_at;
                $survivor->cipp_enriched_at = $duplicate->cipp_enriched_at;
            }

            // Primary-contact flag — the duplicate is removed, so no two-primary conflict
            if ($duplicate->is_primary && ! $survivor->is_primary) {
                $survivor->is_primary = true;
            }

            // Portal access — carry it to the survivor only when the survivor lacks it
            // AND the survivor's client is Active. A duplicate provisioned while Active
            // and later reclassified to Prospect must not grant portal/password to a
            // prospect's contact (the lockout invariant: no path sets portal_enabled or
            // a password for a Person whose client.stage === Prospect).
            // The 'hashed' cast returns an already-hashed value unchanged, so copying
            // the stored hash does not double-hash it.
            if ($duplicate->portal_enabled && ! $survivor->portal_enabled
                && $survivor->client?->stage === ClientStage::Active) {
                $survivor->portal_enabled = true;
                if ($duplicate->company_wide_access) {
                    $survivor->company_wide_access = true;
                }
                if (blank($survivor->password) && filled($duplicate->password)) {
                    $survivor->password = $duplicate->password;
                }
            }

            // Audit: a record line on the survivor and a tombstone on the duplicate
            $merger = User::find($mergedByUserId)?->name ?? 'Unknown';
            $when = now()->toDateString();
            $moved = [];
            foreach ([
                [$ticketCount, 'ticket', 'tickets'],
                [$callCount, 'call', 'calls'],
                [$emailCount, 'email', 'emails'],
                [$movedContracts, 'contract', 'contracts'],
                [$movedAssets, 'device', 'devices'],
            ] as [$n, $one, $many]) {
                if ($n) {
                    $moved[] = "{$n} ".($n === 1 ? $one : $many);
                }
            }
            $movedSummary = $moved ? ' Moved: '.implode(', ', $moved).'.' : '';
            $duplicateLabel = trim($duplicate->full_name).($duplicate->email ? " <{$duplicate->email}>" : '');
            $survivorLabel = trim($survivor->full_name).($survivor->email ? " <{$survivor->email}>" : '');

            $survivor->notes = trim(($survivor->notes ? $survivor->notes."\n\n" : '')
                ."Merged duplicate '{$duplicateLabel}' on {$when} by {$merger}.{$movedSummary}");

            // Clear the duplicate's external identity so re-sync binds to the survivor,
            // and leave a tombstone explaining where it went.
            $duplicate->cipp_user_id = null;
            $duplicate->cipp_upn = null;
            $duplicate->notes = trim(($duplicate->notes ? $duplicate->notes."\n\n" : '')
                ."Merged into '{$survivorLabel}' (#{$survivor->id}) on {$when} by {$merger}.");

            // Persist the duplicate FIRST: it clears the duplicate's unique external IDs
            // (cipp_user_id/cipp_upn are UNIQUE) so the survivor can take them on its own
            // save without colliding on the unique index.
            $duplicate->save();
            $survivor->save();

            // Remove the duplicate's own email rows AFTER its save (PersonObserver::saved
            // re-creates the primary one), so the duplicate is left with zero references.
            PersonEmail::where('person_id', $duplicate->id)->delete();

            $duplicate->delete();

            return [
                'tickets' => $ticketCount,
                'calls' => $callCount,
                'emails' => $emailCount,
                'contracts' => $movedContracts,
                'assets' => $movedAssets,
                'email_addresses' => $movedEmailAddresses,
                'portal_login_email_changed' => $portalLoginEmailChanged,
            ];
        });
    }

    /**
     * Sync additional (non-primary) email addresses for a person.
     * Upserts provided emails and removes any that are no longer in the list.
     */
    private function syncAdditionalEmails(Person $person, array $emails): void
    {
        $keepIds = [];

        foreach (array_slice($emails, 0, 10) as $entry) {
            $email = mb_strtolower(trim($entry['email'] ?? ''));
            if (! $email) {
                continue;
            }

            // Skip if this matches the primary email
            if ($person->email && mb_strtolower(trim($person->email)) === $email) {
                continue;
            }

            $record = PersonEmail::updateOrCreate(
                ['person_id' => $person->id, 'email' => $email],
                [
                    'is_primary' => false,
                    'label' => $entry['label'] ?? null,
                    'source' => 'manual',
                ],
            );

            $keepIds[] = $record->id;
        }

        // Remove additional emails no longer in the list (never touch the primary)
        PersonEmail::where('person_id', $person->id)
            ->where('is_primary', false)
            ->when($keepIds, fn ($q) => $q->whereNotIn('id', $keepIds))
            ->delete();
    }

    private function normalizePhones(array &$data): void
    {
        if (array_key_exists('phone', $data)) {
            if (! empty($data['phone'])) {
                $data['phone_display'] = PhoneNumber::format($data['phone']);
                $data['phone'] = PhoneNumber::normalize($data['phone']);
            } else {
                $data['phone'] = null;
                $data['phone_display'] = null;
            }
        }

        if (array_key_exists('mobile', $data)) {
            if (! empty($data['mobile'])) {
                $data['mobile_display'] = PhoneNumber::format($data['mobile']);
                $data['mobile'] = PhoneNumber::normalize($data['mobile']);
            } else {
                $data['mobile'] = null;
                $data['mobile_display'] = null;
            }
        }
    }
}
