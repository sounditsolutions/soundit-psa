<?php

namespace App\Services\ContactIntake;

use App\Enums\ClientStage;
use App\Models\Client;
use App\Models\Person;
use App\Models\Ticket;
use App\Support\PhoneNumber;

/** Classification only; callers must hold the ledger's identity lock while creating. */
final class ContactMatcher
{
    /** @return array{kind: string, person_id: ?int, client_id: ?int, ticket_ids: array<int>, reason: ?string} */
    public function match(string $email, ?string $phone = null, ?string $company = null): array
    {
        $email = mb_strtolower(trim($email));
        $people = Person::withTrashed()->whereEmailMatch($email)
            ->with(['client' => fn ($q) => $q->withTrashed()])->get();
        $number = PhoneNumber::normalize($phone ?? '');
        $phonePeople = $number === null ? collect() : Person::withTrashed()
            ->where(fn ($q) => $q->where('phone', $number)->orWhere('mobile', $number))->get();
        $result = ['kind' => 'exception', 'person_id' => null, 'client_id' => null, 'ticket_ids' => [], 'reason' => null];

        // Do not prune historical/inactive candidates before deciding ambiguity.
        if ($people->count() > 1) {
            return array_replace($result, ['reason' => 'multiple_email_contacts']);
        }
        if ($people->count() === 1) {
            $person = $people->sole();
            $client = $person->client;
            if ($person->trashed() || ! $person->is_active || ! $client || $client->trashed() || ! $client->is_active) {
                return array_replace($result, ['reason' => 'inactive_identity']);
            }
            if ($phonePeople->contains(fn ($p) => $p->id !== $person->id)) {
                return array_replace($result, ['reason' => 'conflicting_phone']);
            }
            $tickets = Ticket::open()->where('contact_id', $person->id)->get(['id', 'client_id']);
            if ($tickets->contains(fn ($t) => $t->client_id !== $client->id)) {
                return array_replace($result, ['reason' => 'conflicting_ticket_client']);
            }

            return array_replace($result, [
                'kind' => $tickets->count() === 1 ? 'existing_ticket' : ($client->stage === ClientStage::Prospect ? 'existing_prospect' : 'existing_client'),
                'person_id' => $person->id, 'client_id' => $client->id,
                'ticket_ids' => $tickets->pluck('id')->all(),
            ]);
        }
        if ($phonePeople->isNotEmpty()) {
            return array_replace($result, ['reason' => 'phone_only']);
        }
        $domain = explode('@', $email, 2)[1] ?? '';
        // Domain/company are suggestions, not association authority (including free mail).
        if ($domain !== '' && Person::withTrashed()->whereEmailDomain($domain)->exists()) {
            return array_replace($result, ['reason' => 'domain_only']);
        }
        if (filled($company) && Client::withTrashed()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($company))])->exists()) {
            return array_replace($result, ['reason' => 'company_only']);
        }

        return array_replace($result, ['kind' => 'new_prospect']);
    }
}
