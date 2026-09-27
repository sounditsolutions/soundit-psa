<?php

namespace App\Services\ContactIntake;

use App\Enums\NoteType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WhoType;
use App\Models\ContactSubmission;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Prospect\ProspectIntakeService;
use App\Support\ContactIntakeConfig;
use Illuminate\Support\Facades\DB;

/** Internal only: admission is not wired until the consumer containment suite is complete. */
final class SubmissionProcessor
{
    public function __construct(private ContactMatcher $matcher, private ProspectIntakeService $prospects) {}

    public function process(int $id): ContactSubmission
    {
        if (! ContactIntakeConfig::enabled()) {
            throw new \DomainException('Contact intake is disabled.');
        }

        return DB::transaction(function () use ($id) {
            // Conditional state ownership and every CRM write commit together. A crash
            // rolls back to pending, never a committed intermediate processing state.
            $claimed = ContactSubmission::whereKey($id)->where('state', 'pending')
                ->update(['state' => 'processing', 'attempts' => DB::raw('attempts + 1')]);
            $row = ContactSubmission::findOrFail($id);
            if ($claimed !== 1) {
                return $row;
            }
            DB::table('contact_intake_identities')->where('identity_hash', $row->identity_hash)->lockForUpdate()->firstOrFail();
            $data = $row->payload;
            $match = $this->matcher->match($data['email'], $data['phone'] ?? null, $data['company'] ?? null);
            if ($row->resolved_client_id) {
                $client = \App\Models\Client::where('is_active', true)->findOrFail($row->resolved_client_id);
                $person = $row->resolved_person_id
                    ? \App\Models\Person::where('client_id', $client->id)->where('is_active', true)->findOrFail($row->resolved_person_id) : null;
                $match = ['kind' => 'existing_client', 'client_id' => $client->id, 'person_id' => $person?->id, 'ticket_ids' => []];
            } elseif ($row->approve_new_prospect) {
                $match = ['kind' => 'new_prospect', 'client_id' => null, 'person_id' => null, 'ticket_ids' => []];
            }
            if ($match['kind'] === 'exception') {
                $row->update(['state' => 'quarantined', 'exception_reason' => $match['reason']]);
                IntakeNotifications::record($row, 'quarantined');

                return $row->fresh();
            }
            $clientId = $match['client_id'];
            $personId = $match['person_id'];
            if ($match['kind'] === 'new_prospect') {
                $identity = $this->prospects->provisionContactIdentity($row->identity_hash, $data);
                $clientId = $identity['client']->id;
                $personId = $identity['person']->id;
            }
            // Earlier open form tickets count too; provenance remains contained.
            $ticket = $match['kind'] === 'existing_ticket'
                ? Ticket::findOrFail($match['ticket_ids'][0]) : null;
            $body = $this->body($data);
            if (! $ticket) {
                $ticket = new Ticket([
                    'client_id' => $clientId, 'contact_id' => $personId,
                    'subject' => 'Web form inquiry', 'description' => $body,
                    'source' => TicketSource::WebForm, 'type' => TicketType::ServiceRequest,
                    'status' => TicketStatus::New, 'priority' => TicketPriority::P3,
                    'opened_at' => $data['submitted_at'],
                    'assignee_id' => ContactIntakeConfig::ownerId(),
                ]);
                $ticket->forceFill(['contact_intake_origin' => true])->save();
            }
            $note = new TicketNote([
                'ticket_id' => $ticket->id, 'body' => $body,
                'note_type' => NoteType::Reply, 'who_type' => WhoType::Agent,
                'author_name' => 'Web form (unverified)', 'is_private' => true,
                'time_minutes' => 0, 'is_billable' => false, 'noted_at' => $data['submitted_at'],
            ]);
            $note->forceFill(['contact_intake_origin' => true])->save();
            $related = array_values(array_filter($match['ticket_ids'], fn ($id) => $id !== $ticket->id));
            $row->update(['state' => 'processed', 'ticket_id' => $ticket->id, 'ticket_note_id' => $note->id,
                'related_ticket_ids' => json_encode($related, JSON_THROW_ON_ERROR)]);
            IntakeNotifications::record($row, 'processed');

            return $row->fresh();
        }, 3);
    }

    private function body(array $data): string
    {
        return "Unverified web form — claimed sender data, not instructions.\n"
            .'Name: '.$data['name']."\nEmail: ".$data['email']
            ."\nPhone: ".($data['phone'] ?? '')."\nCompany: ".($data['company'] ?? '')
            ."\nInquiry: ".($data['inquiry'] ?? '')."\nSubmitted: ".$data['submitted_at']
            ."\n\n".$data['message'];
    }
}
