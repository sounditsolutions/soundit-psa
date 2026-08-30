<?php

namespace Tests\Feature\Assistant;

use App\Enums\CallStatus;
use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Services\Assistant\AssistantToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-838: the get_ticket_detail client fence was bypassable through
 * get_ticket_calls.
 *
 * From the Absurd review of PR #823 (finding c1:v2:1, adjudicated
 * ticket-class): psa-823 fenced get_ticket_detail to the client context, and
 * its own test asserted that on a fence miss no hostname, serial or client
 * stub crosses. But get_ticket_calls sits in the SAME generalTools() set,
 * dispatches through the SAME executor instance, and was untouched — a bare
 * `Ticket::find($ticketId)` with no client_id predicate. get_ticket_detail's
 * published description routes the caller there ("For full call transcripts,
 * follow up with get_ticket_calls"), so the pivot was the documented next
 * step, not an obscure one: a scoped executor refused on a foreign ticket
 * could turn around and read that ticket's display_id (confirming the id
 * exists, which psa-823's comment claims is never confirmed), the contact's
 * full name, both phone numbers, the call summary, next steps, coaching notes
 * and up to 10000 chars of transcript.
 *
 * The fix is the sibling's fence, not a new rule: scoped reads require the
 * ticket to belong to the client context; the unscoped staff board keeps its
 * cross-client read, including the client_id IS NULL unresolved-intake
 * tickets that are reachable nowhere else (psa-6usr).
 */
class TicketCallsClientFenceTest extends TestCase
{
    use RefreshDatabase;

    private function person(int $clientId, string $lastName): Person
    {
        return Person::create([
            'client_id' => $clientId,
            'person_type' => PersonType::User,
            'first_name' => 'Calls',
            'last_name' => $lastName,
            'email' => strtolower($lastName).'@example.test',
            'is_active' => true,
        ]);
    }

    private function phoneCall(int $ticketId, ?int $personId, string $uuid): PhoneCall
    {
        $call = PhoneCall::create([
            'call_uuid' => $uuid,
            'ticket_id' => $ticketId,
            'from_number' => '+15095550101',
            'to_number' => '+15095550202',
            'status' => CallStatus::Completed,
            'started_at' => now()->subHour(),
            'call_summary' => 'CALLS-SUMMARY-SECRET',
            'next_steps' => 'CALLS-NEXTSTEPS-SECRET',
            'coaching_notes' => 'CALLS-COACHING-SECRET',
            'cleaned_transcript' => 'CALLS-TRANSCRIPT-SECRET',
        ]);

        if ($personId) {
            $call->person_id = $personId;
            $call->save();
        }

        return $call;
    }

    public function test_get_ticket_calls_is_fenced_to_the_client_context(): void
    {
        $mine = Client::factory()->create();
        $theirs = Client::factory()->create();

        $foreign = Ticket::factory()->create(['client_id' => $theirs->id]);
        $contact = $this->person($theirs->id, 'Foreignholder');
        $this->phoneCall($foreign->id, $contact->id, 'psa838-foreign');

        $scoped = new AssistantToolExecutor(clientId: $mine->id);
        $refused = $scoped->execute('get_ticket_calls', ['ticket_id' => $foreign->id]);

        // Not found — never a partial read, and never a confirmation that the
        // id exists. This is the exact pivot psa-823's fence left open.
        $this->assertArrayHasKey('error', $refused);
        $this->assertArrayNotHasKey('calls', $refused);
        $this->assertArrayNotHasKey('display_id', $refused);

        $json = json_encode($refused);
        $this->assertStringNotContainsString((string) $foreign->display_id, $json);
        $this->assertStringNotContainsString('Foreignholder', $json);
        $this->assertStringNotContainsString('+15095550101', $json);
        $this->assertStringNotContainsString('+15095550202', $json);
        $this->assertStringNotContainsString('CALLS-SUMMARY-SECRET', $json);
        $this->assertStringNotContainsString('CALLS-NEXTSTEPS-SECRET', $json);
        $this->assertStringNotContainsString('CALLS-COACHING-SECRET', $json);
        $this->assertStringNotContainsString('CALLS-TRANSCRIPT-SECRET', $json);
    }

    public function test_get_ticket_calls_still_reads_the_scoped_clients_own_ticket(): void
    {
        $mine = Client::factory()->create();
        $own = Ticket::factory()->create(['client_id' => $mine->id]);
        $contact = $this->person($mine->id, 'Ownholder');
        $this->phoneCall($own->id, $contact->id, 'psa838-own');

        $result = (new AssistantToolExecutor(clientId: $mine->id))
            ->execute('get_ticket_calls', ['ticket_id' => $own->id]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame($own->id, $result['ticket_id']);
        $this->assertSame(1, $result['call_count']);
        $this->assertSame('Calls Ownholder', $result['calls'][0]['contact']);
        $this->assertSame('CALLS-TRANSCRIPT-SECRET', $result['calls'][0]['transcript']);
    }

    public function test_unscoped_staff_read_keeps_its_cross_client_reach(): void
    {
        $theirs = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $theirs->id]);
        $this->phoneCall($ticket->id, null, 'psa838-staff');

        $result = (new AssistantToolExecutor)->execute('get_ticket_calls', ['ticket_id' => $ticket->id]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame($ticket->id, $result['ticket_id']);
        $this->assertSame(1, $result['call_count']);
        $this->assertSame('CALLS-TRANSCRIPT-SECRET', $result['calls'][0]['transcript']);
    }

    public function test_unscoped_staff_read_still_reaches_unresolved_intake_tickets(): void
    {
        // psa-6usr: a client_id IS NULL intake ticket is reachable on the staff
        // board and nowhere else. The fence must not close that door.
        $ticket = Ticket::factory()->create(['client_id' => null]);
        $this->phoneCall($ticket->id, null, 'psa838-intake');

        $result = (new AssistantToolExecutor)->execute('get_ticket_calls', ['ticket_id' => $ticket->id]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame($ticket->id, $result['ticket_id']);
        $this->assertSame(1, $result['call_count']);
    }

    public function test_scoped_read_refuses_an_unresolved_intake_ticket(): void
    {
        // The mirror of the case above: a client-scoped caller has no claim on
        // an unassigned intake ticket, so the fence must refuse it rather than
        // let a NULL client_id read as "belongs to everyone".
        $mine = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => null]);
        $this->phoneCall($ticket->id, null, 'psa838-intake-scoped');

        $refused = (new AssistantToolExecutor(clientId: $mine->id))
            ->execute('get_ticket_calls', ['ticket_id' => $ticket->id]);

        $this->assertArrayHasKey('error', $refused);
        $this->assertStringNotContainsString('CALLS-TRANSCRIPT-SECRET', json_encode($refused));
    }
}
