<?php

namespace Tests\Feature\Technician;

use App\Enums\NoteType;
use App\Enums\PersonType;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-u51h.3 (SECURITY review, REVISE): the per-token persona tagline was being defeated
 * at the RENDER layer for note headers.
 *
 * An AI note stores author_name = the ACTING TOKEN'S PERSONA ("Robin") but author_id =
 * the SHARED AI-actor user, whose name is the GLOBAL one ("GlobalChet"). display_author
 * preferred the relation whenever it was loaded — and the portal loads it (the avatar
 * takes :user="$note->author" one line above the header) — so a client read "GlobalChet"
 * as the author above a body signed "Drafted by Robin". That is the exact white-label
 * defect (so-bp4f) this bead exists to kill, surviving one layer up.
 *
 * Human notes must KEEP relation-first: author_name is a write-time snapshot, so a
 * renamed technician should still display their CURRENT name.
 */
class AiNoteAuthorDisplayTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Ticket, 1: User} the ticket and the shared global AI-actor user */
    private function seedTicket(): array
    {
        $aiActor = User::factory()->create(['name' => 'GlobalChet']);
        Setting::setValue('triage_system_user_id', (string) $aiActor->id);
        Setting::setValue('portal_enabled', '1'); // else the portal routes 404 and the render test proves nothing
        $client = Client::factory()->create();
        $contact = Person::create([
            'client_id' => $client->id, 'person_type' => PersonType::User,
            'first_name' => 'Client', 'last_name' => 'Contact',
            'email' => 'client@authordisplay.test', 'is_active' => true,
            'portal_enabled' => true, 'password' => bcrypt('secret'),
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id, 'contact_id' => $contact->id,
            'status' => TicketStatus::InProgress, 'closed_at' => null,
        ]);

        return [$ticket, $aiActor];
    }

    private function aiNote(Ticket $ticket, User $aiActor, string $persona): TicketNote
    {
        return TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $aiActor->id,      // the SHARED AI-actor user (global name)
            'author_name' => $persona,        // the ACTING TOKEN'S persona (client-facing)
            'who_type' => WhoType::Agent,
            'ai_authored' => true,
            'body' => "All fixed.\n\n— Drafted by {$persona}, an AI assistant for our team.",
            'note_type' => NoteType::Reply,
            'is_private' => false,
            'noted_at' => now(),
        ]);
    }

    public function test_an_ai_note_displays_its_persona_even_once_the_author_relation_is_loaded(): void
    {
        [$ticket, $aiActor] = $this->seedTicket();
        $note = $this->aiNote($ticket, $aiActor, 'Robin');

        // The portal renders the avatar from $note->author one line ABOVE the header,
        // which lazy-loads the relation — so the loaded case is the REAL case, not a
        // hypothetical. It must still name the persona.
        $note->load('author');
        $this->assertTrue($note->relationLoaded('author'));
        $this->assertSame('Robin', $note->display_author);
    }

    public function test_a_client_reading_the_portal_sees_the_persona_not_the_global_actor(): void
    {
        [$ticket, $aiActor] = $this->seedTicket();
        $this->aiNote($ticket, $aiActor, 'Robin');

        $html = $this->actingAs($ticket->contact, 'portal')
            ->get(route('portal.tickets.show', $ticket))->assertOk()->getContent();

        $this->assertStringContainsString('Robin', $html);
        // The client must never be shown another tenant's configured AI name.
        $this->assertStringNotContainsString('GlobalChet', $html);
    }

    public function test_a_human_note_still_shows_the_authors_current_name_after_a_rename(): void
    {
        [$ticket] = $this->seedTicket();
        $tech = User::factory()->create(['name' => 'Dana Old']);
        $note = TicketNote::create([
            'ticket_id' => $ticket->id, 'author_id' => $tech->id,
            'author_name' => 'Dana Old',   // write-time snapshot
            'who_type' => WhoType::Agent, 'ai_authored' => false,
            'body' => 'Looking into it.', 'note_type' => NoteType::Reply,
            'is_private' => false, 'noted_at' => now(),
        ]);

        $tech->update(['name' => 'Dana New']);

        // Relation-first must survive for humans: the snapshot is stale, the user is truth.
        $note->load('author');
        $this->assertSame('Dana New', $note->display_author);
    }

    public function test_an_ai_note_with_no_recorded_name_falls_back_rather_than_blanking(): void
    {
        [$ticket, $aiActor] = $this->seedTicket();
        $note = TicketNote::create([
            'ticket_id' => $ticket->id, 'author_id' => $aiActor->id,
            'author_name' => null, 'who_type' => WhoType::Agent, 'ai_authored' => true,
            'body' => 'Legacy note.', 'note_type' => NoteType::Reply,
            'is_private' => false, 'noted_at' => now(),
        ]);

        $note->load('author');
        // No persona recorded -> the actor relation is better than 'System' or blank.
        $this->assertSame('GlobalChet', $note->display_author);
    }
}
