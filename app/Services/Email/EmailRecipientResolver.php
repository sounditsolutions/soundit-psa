<?php

namespace App\Services\Email;

use App\Enums\EmailDirection;
use App\Models\Email;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;

class EmailRecipientResolver
{
    public function candidates(Ticket $ticket): RecipientCandidates
    {
        $contact = $ticket->contact;
        $ours = array_values(array_filter([
            strtolower(trim((string) Setting::getValue('graph_mailbox'))) ?: null,
        ]));

        $clientContacts = Person::query()
            ->where('client_id', $ticket->client_id)
            ->whereNotNull('email')
            ->where('is_active', true)
            ->get()
            ->map(fn (Person $p) => ['person_id' => $p->id, 'email' => strtolower(trim($p->email)), 'name' => $p->fullName])
            ->values()->all();

        $participants = [];
        foreach (Email::query()->where('ticket_id', $ticket->id)->get() as $email) {
            foreach ($this->addressesOf($email) as [$addr, $name]) {
                $low = strtolower(trim($addr));
                if ($low === '' || in_array($low, $ours, true) || isset($participants[$low])) {
                    continue;
                }
                $participants[$low] = ['email' => $low, 'name' => $name];
            }
        }

        return new RecipientCandidates(
            contactEmail: $contact?->email ? strtolower(trim($contact->email)) : null,
            contactName: $contact?->fullName,
            clientContacts: $clientContacts,
            threadParticipants: array_values($participants),
            ourAddresses: $ours,
        );
    }

    /** @return array{to: ?string, to_name: ?string, cc: array<int,string>} */
    public function replyAll(Ticket $ticket): array
    {
        $candidates = $this->candidates($ticket);
        $inbound = Email::query()
            ->where('ticket_id', $ticket->id)
            ->where('direction', EmailDirection::Inbound)
            ->orderByDesc('received_at')->orderByDesc('id')
            ->first();

        $to = $inbound?->from_address ? strtolower(trim($inbound->from_address)) : $candidates->contactEmail;
        if (! $to) {
            return ['to' => null, 'to_name' => null, 'cc' => []];
        }

        $cc = [];
        foreach ($candidates->threadParticipants as $p) {
            if ($p['email'] !== $to) {
                $cc[] = $p['email'];
            }
        }

        return ['to' => $to, 'to_name' => $candidates->nameFor($to), 'cc' => array_values(array_unique($cc))];
    }

    /** @return array<int,array{0:string,1:?string}> */
    private function addressesOf(Email $email): array
    {
        $out = [[$email->from_address ?? '', $email->from_name]];
        foreach (['to_recipients', 'cc_recipients'] as $field) {
            foreach ((array) ($email->{$field} ?? []) as $r) {
                if (is_array($r) && isset($r['address'])) {
                    $out[] = [$r['address'], $r['name'] ?? null];
                }
            }
        }

        return $out;
    }
}
