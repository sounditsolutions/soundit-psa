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
            if ($match['kind'] === 'exception') {
                $row->update(['state' => 'quarantined', 'exception_reason' => $match['reason']]);

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
            $row->update(['state' => 'processed', 'ticket_id' => $ticket->id, 'ticket_note_id' => $note->id]);

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
