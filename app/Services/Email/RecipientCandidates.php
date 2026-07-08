<?php

namespace App\Services\Email;

final class RecipientCandidates
{
    /**
     * @param  array<int,array{person_id:int,email:string,name:?string}>  $clientContacts
     * @param  array<int,array{email:string,name:?string}>  $threadParticipants
     * @param  array<int,string>  $ourAddresses  lowercased
     */
    public function __construct(
        public readonly ?string $contactEmail,
        public readonly ?string $contactName,
        public readonly array $clientContacts,
        public readonly array $threadParticipants,
        public readonly array $ourAddresses,
    ) {}

    /** @return array<int,string> lowercased union of sources a∪b∪c */
    public function allEmails(): array
    {
        $emails = [];
        if ($this->contactEmail) {
            $emails[] = strtolower($this->contactEmail);
        }
        foreach ($this->clientContacts as $p) {
            $emails[] = strtolower($p['email']);
        }
        foreach ($this->threadParticipants as $p) {
            $emails[] = strtolower($p['email']);
        }

        return array_values(array_unique($emails));
    }

    public function isThreadParticipant(string $email): bool
    {
        $needle = strtolower(trim($email));
        foreach ($this->threadParticipants as $p) {
            if (strtolower($p['email']) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function nameFor(string $email): ?string
    {
        $needle = strtolower(trim($email));
        if ($this->contactEmail && strtolower($this->contactEmail) === $needle) {
            return $this->contactName;
        }
        foreach ([...$this->clientContacts, ...$this->threadParticipants] as $p) {
            if (strtolower($p['email']) === $needle) {
                return $p['name'] ?? null;
            }
        }

        return null;
    }

    public function personEmail(int $personId): ?string
    {
        foreach ($this->clientContacts as $p) {
            if ($p['person_id'] === $personId) {
                return strtolower($p['email']);
            }
        }

        return null;
    }
}
