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
    public function replyAll(Ticket $ticket, ?RecipientCandidates $candidates = null): array
    {
        $candidates ??= $this->candidates($ticket);
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

    /**
     * @param  array<int,int|string>  $to  person_ids or emails (first is To; extras fold into CC)
     * @param  array<int,int|string>  $cc
     *
     * @throws RecipientValidationException
     */
    public function resolve(Ticket $ticket, array $to, array $cc, RecipientContext $context, bool $allowArbitrary, bool $directAllowNew): ResolvedRecipients
    {
        $candidates = $this->candidates($ticket);

        $toRefs = array_values($to);
        $extraToAsCc = array_slice($toRefs, 1);
        $toRef = $toRefs[0] ?? null;

        $toAddress = $toRef !== null
            ? $this->resolveRef($toRef, $candidates, $context, $allowArbitrary, $directAllowNew)
            : $candidates->contactEmail;

        if (! $toAddress) {
            throw new RecipientValidationException('No recipient: ticket has no contact email and no valid To was supplied.');
        }

        $ccAddresses = [];
        foreach ([...$extraToAsCc, ...array_values($cc)] as $ref) {
            // Our own mailbox in CC is a self-send no-op — drop it silently, never error.
            if (is_string($ref) && in_array(strtolower(trim($ref)), $candidates->ourAddresses, true)) {
                continue;
            }
            $addr = $this->resolveRef($ref, $candidates, $context, $allowArbitrary, $directAllowNew);
            if ($addr === $toAddress || in_array($addr, $candidates->ourAddresses, true) || in_array($addr, $ccAddresses, true)) {
                continue;
            }
            $ccAddresses[] = $addr;
        }

        // Addresses outside sources a/b/c can only be present when $allowArbitrary let
        // them through; surface them so approval readouts and audits can flag them.
        $custom = array_values(array_diff([$toAddress, ...$ccAddresses], $candidates->allEmails()));

        return new ResolvedRecipients($toAddress, $candidates->nameFor($toAddress), array_values($ccAddresses), $custom);
    }

    private function resolveRef(mixed $ref, RecipientCandidates $candidates, RecipientContext $context, bool $allowArbitrary, bool $directAllowNew): string
    {
        if (! is_scalar($ref)) {
            throw new RecipientValidationException('Each recipient must be a person id or an email address.');
        }

        if (is_int($ref) || ctype_digit((string) $ref)) {
            $email = $candidates->personEmail((int) $ref);
            if (! $email) {
                throw new RecipientValidationException("Recipient person #{$ref} is not a contact of this client.");
            }
        } else {
            $email = strtolower(trim((string) $ref));
            if (! in_array($email, $candidates->allEmails(), true)) {
                if (! $allowArbitrary) {
                    throw new RecipientValidationException("Recipient '{$email}' is not a known contact or thread participant. Only ticket contacts, client contacts, or people already on the email thread are allowed.");
                }
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RecipientValidationException("Recipient '{$email}' is not a valid email address.");
                }
                // Arbitrary but knob-allowed + syntactically valid — still subject to the
                // direct off-thread gate below (a new recipient on the direct path needs review).
            }
        }

        // Direct path: any recipient that is neither the ticket contact nor already on the
        // thread is a NEW recipient and needs human review — unless direct_new is enabled.
        if ($context === RecipientContext::Direct
            && ! $directAllowNew
            && $email !== $candidates->contactEmail
            && ! $candidates->isThreadParticipant($email)) {
            throw new RecipientValidationException("Recipient '{$email}' is not already on this email thread. Adding a new recipient needs review — use stage_email instead.");
        }

        return $email;
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
