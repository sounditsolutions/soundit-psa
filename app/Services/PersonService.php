<?php

namespace App\Services;

use App\Models\Person;
use App\Models\PersonEmail;
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
            if (!empty($data['is_primary']) && !empty($data['client_id'])) {
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
            if (!empty($data['is_primary']) && $clientId) {
                Person::where('client_id', $clientId)
                    ->where('is_primary', true)
                    ->where('id', '!=', $person->id)
                    ->update(['is_primary' => false]);
            }

            $person->update($data);

            if ($additionalEmails !== null) {
                $this->syncAdditionalEmails($person, $additionalEmails);
            }

            return $person->fresh();
        });
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
            if (!empty($data['phone'])) {
                $data['phone_display'] = PhoneNumber::format($data['phone']);
                $data['phone'] = PhoneNumber::normalize($data['phone']);
            } else {
                $data['phone'] = null;
                $data['phone_display'] = null;
            }
        }

        if (array_key_exists('mobile', $data)) {
            if (!empty($data['mobile'])) {
                $data['mobile_display'] = PhoneNumber::format($data['mobile']);
                $data['mobile'] = PhoneNumber::normalize($data['mobile']);
            } else {
                $data['mobile'] = null;
                $data['mobile_display'] = null;
            }
        }
    }
}
