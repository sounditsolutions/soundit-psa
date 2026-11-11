<?php

namespace Tests\Feature\Dashboard;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\EmailDirection;
use App\Enums\NoteType;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\ActivityStreamService;
use App\Support\ActivityItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Card efgnQbCM: the dashboard Activity feed showed no tickets. Production
 * writes staff notes private and the unscoped stream kept public notes only;
 * ticket creation was not a source at all. Synthetic fixtures only.
 */
class ActivityFeedTicketsTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ActivityStreamService
    {
        return app(ActivityStreamService::class);
    }

    /** Ticket created in the past so it does not itself land in a since-window. */
    private function oldTicket(array $attrs = []): Ticket
    {
        $ticket = Ticket::factory()->create($attrs);
        $ticket->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        return $ticket;
    }

    private function note(Ticket $ticket, string $body, array $attrs = []): TicketNote
    {
        return TicketNote::create($attrs + [
            'ticket_id' => $ticket->id,
            'author_name' => 'Synthetic Tech',
            'body' => $body,
            'note_type' => NoteType::Note,
            'is_private' => true,
            'noted_at' => now()->subMinutes(5),
        ]);
    }

    private function noteIds(Collection $stream): array
    {
        return $stream->filter(fn (ActivityItem $i) => $i->model instanceof TicketNote)
            ->map(fn (ActivityItem $i) => $i->model->id)->values()->all();
    }

    private function openedIds(Collection $stream): array
    {
        return $stream->filter(fn (ActivityItem $i) => $i->type === 'ticket-opened')
            ->map(fn (ActivityItem $i) => $i->model->id)->values()->all();
    }

    public function test_private_staff_note_appears_in_dashboard_stream(): void
    {
        $note = $this->note($this->oldTicket(), 'PRIVATE-STAFF-NOTE');

        $this->assertContains($note->id, $this->noteIds($this->service()->getStream()));
    }

    public function test_private_staff_note_appears_in_dashboard_since_stream(): void
    {
        $note = $this->note($this->oldTicket(), 'PRIVATE-STAFF-NOTE');

        $this->assertContains($note->id, $this->noteIds($this->service()->getStreamSince(now()->subHour())));
    }

    public function test_system_generated_and_email_linked_notes_stay_out(): void
    {
        $ticket = $this->oldTicket();
        $system = $this->note($ticket, 'SYSTEM-NOTE', ['note_type' => NoteType::StatusChange]);
        $email = Email::create([
            'direction' => EmailDirection::Inbound,
            'from_address' => 'sender@example.test',
            'subject' => 'Synthetic reply',
            'received_at' => now()->subMinutes(5),
            'ticket_id' => $ticket->id,
        ]);
        $linked = $this->note($ticket, 'EMAIL-LINKED-NOTE', ['note_type' => NoteType::Reply, 'email_id' => $email->id]);
        $kept = $this->note($ticket, 'KEPT-NOTE');

        foreach ([$this->service()->getStream(), $this->service()->getStreamSince(now()->subHour())] as $stream) {
            $ids = $this->noteIds($stream);
            $this->assertContains($kept->id, $ids);
            $this->assertNotContains($system->id, $ids);
            $this->assertNotContains($linked->id, $ids);
        }
    }

    public function test_new_ticket_appears_in_dashboard_stream(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'Synthetic printer jam']);

        $this->assertContains($ticket->id, $this->openedIds($this->service()->getStream()));
    }

    public function test_new_ticket_appears_in_dashboard_since_stream(): void
    {
        $ticket = Ticket::factory()->create();

        $this->assertContains($ticket->id, $this->openedIds($this->service()->getStreamSince(now()->subHour())));
        $this->assertNotContains($ticket->id, $this->openedIds($this->service()->getStreamSince(now()->addHour())));
    }

    public function test_new_ticket_appears_in_its_clients_stream_only(): void
    {
        $mine = Client::factory()->create();
        $other = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $mine->id]);

        $this->assertContains($ticket->id, $this->openedIds($this->service()->getClientStream($mine->id)));
        $this->assertNotContains($ticket->id, $this->openedIds($this->service()->getClientStream($other->id)));
    }

    public function test_opened_tickets_respect_before_cursor(): void
    {
        $ticket = Ticket::factory()->create();

        $this->assertContains($ticket->id, $this->openedIds($this->service()->getStream(now()->addMinute())));
        $this->assertNotContains($ticket->id, $this->openedIds($this->service()->getStream(now()->subDay())));
    }

    public function test_dashboard_page_and_since_endpoint_render_opened_ticket(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'Synthetic VPN outage subject']);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Ticket opened')
            ->assertSee('Synthetic VPN outage subject')
            ->assertSee(route('tickets.show', $ticket), false);

        $resp = $this->actingAs($user)
            ->getJson('/dashboard/activity?since='.urlencode(now()->subHour()->toIso8601String()))
            ->assertOk();
        $html = $resp->json('html');
        $this->assertStringContainsString('Ticket opened', $html);
        $this->assertStringContainsString('Synthetic VPN outage subject', $html);
        $this->assertStringContainsString('data-type="ticket"', $html);
    }

    public function test_softphone_person_stream_serializes_opened_ticket(): void
    {
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'first_name' => 'Synthetic', 'last_name' => 'Caller', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $person->id,
            'subject' => 'Synthetic caller ticket subject',
        ]);
        // client_id is not mass-assignable on PhoneCall, so bypass the guard.
        PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-softphone-opened',
            'direction' => CallDirection::Inbound,
            'from_number' => '+15555550100',
            'to_number' => '+15555550199',
            'status' => CallStatus::Ringing,
            'started_at' => now(),
            'client_id' => $client->id,
            'person_id' => $person->id,
        ]);

        $activity = collect($this->actingAs(User::factory()->create())
            ->getJson(route('calls.latest'))
            ->assertOk()
            ->json('call.activity'));
        $row = $activity->firstWhere('type', 'ticket-opened');

        $this->assertNotNull($row);
        $this->assertSame('bi-ticket-perforated', $row['icon']);
        $this->assertSame('Ticket opened', $row['label']);
        $this->assertSame($ticket->display_id, $row['ticket_id']);
        $this->assertSame('Synthetic caller ticket subject', $row['preview']);
        $this->assertSame($person->full_name, $row['who']);
    }
}
