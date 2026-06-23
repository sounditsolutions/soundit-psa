<?php

namespace App\Services\Prospect;

use App\Enums\ClientStage;
use App\Enums\PersonType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

class ProspectIntakeService
{
    /**
     * Normalize the raw number and return the Client whose Person has that
     * phone or mobile, or null if no match exists.
     */
    public function matchByNumber(string $rawNumber): ?Client
    {
        $normalized = PhoneNumber::normalize($rawNumber);

        if ($normalized === null) {
            return null;
        }

        $person = Person::where('phone', $normalized)
            ->orWhere('mobile', $normalized)
            ->first();

        return $person?->client;
    }

    /**
     * Provision a new Prospect: Client (stage=Prospect) + Person (the caller,
     * portal_enabled=false, password=null, phone=normalized) + Ticket (seeded
     * from the call, no LLM). All wrapped in a DB transaction.
     *
     * @return array{client: Client, person: Person, ticket: Ticket}
     */
    public function provisionFromCall(PhoneCall $call, string $name): array
    {
        return DB::transaction(function () use ($call, $name) {
            // 1. Create Client (stage is not mass-fillable — set explicitly)
            $client = new Client(['name' => $name, 'is_active' => true]);
            $client->stage = ClientStage::Prospect;
            $client->save();

            // 2. Create Person (portal-inert; phone = normalized from_number)
            $normalizedPhone = PhoneNumber::normalize($call->from_number);

            $nameParts = $this->splitName($name);

            $person = Person::create([
                'client_id' => $client->id,
                'person_type' => PersonType::User,
                'first_name' => $nameParts['first'],
                'last_name' => $nameParts['last'],
                'phone' => $normalizedPhone,
                'is_active' => true,
                'portal_enabled' => false,
                // password intentionally omitted (stays null)
            ]);

            // 3. Create Ticket seeded from the call (no LLM — plain subject only)
            $subject = $call->call_summary
                ? 'Prospect inquiry: '.str($call->call_summary)->limit(120)->toString()
                : 'New prospect inquiry';

            $ticket = Ticket::create([
                'client_id' => $client->id,
                'contact_id' => $person->id,
                'subject' => $subject,
                'source' => TicketSource::Phone,
                'type' => TicketType::Incident,
                'status' => TicketStatus::New,
                'priority' => TicketPriority::P3,
                'opened_at' => $call->started_at ?? now(),
            ]);

            return compact('client', 'person', 'ticket');
        });
    }

    /**
     * Best-effort split of a company/person name into first + last for Person.
     * For company names (single token or multi-word), stores the whole name as
     * first_name and uses a single-space placeholder for last_name.
     */
    private function splitName(string $name): array
    {
        $parts = explode(' ', trim($name), 2);

        return [
            'first' => $parts[0],
            'last' => $parts[1] ?? '',
        ];
    }
}
