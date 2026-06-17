<?php

namespace Tests\Feature\Tickets;

use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TicketNoteEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ticket creation fires the observer (triage dispatch + creation notification).
        Bus::fake();
    }

    private function makeNote(Ticket $ticket, User $user, string $body = 'Original body'): TicketNote
    {
        return TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'author_name' => $user->name,
            'who_type' => WhoType::Agent,
            'body' => $body,
            'body_html' => '<p>'.$body.'</p>',
            'note_type' => NoteType::Note,
            'is_private' => true,
            'noted_at' => now(),
        ]);
    }

    public function test_editing_a_note_persists_the_change_and_redirects(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $note = $this->makeNote($ticket, $user);

        $resp = $this->actingAs($user)->put(route('tickets.notes.update', [$ticket, $note]), [
            'body' => 'Edited body',
            'note_type' => 'note',
            'is_private' => '1',
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));
        $resp->assertSessionHas('success');

        $this->assertDatabaseHas('ticket_notes', [
            'id' => $note->id,
            'body' => 'Edited body',
        ]);
    }

    public function test_editing_a_note_relinks_attachments_referenced_in_the_body(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $note = $this->makeNote($ticket, $user);

        // A ticket-level attachment that the edited body will reference.
        $attachment = Attachment::create([
            'attachable_type' => 'App\\Models\\Ticket',
            'attachable_id' => $ticket->id,
            'filename' => 'diagram.png',
            'original_filename' => 'diagram.png',
            'storage_path' => 'attachments/diagram.png',
            'mime_type' => 'image/png',
            'size_bytes' => 1234,
            'uploaded_by' => $user->id,
        ]);

        $body = "See diagram: /attachments/{$attachment->id}/diagram.png";

        $resp = $this->actingAs($user)->put(route('tickets.notes.update', [$ticket, $note]), [
            'body' => $body,
            'note_type' => 'note',
            'is_private' => '1',
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));

        // The fix must pass the correct 4 args so the attachment is re-linked to the note.
        $this->assertDatabaseHas('attachments', [
            'id' => $attachment->id,
            'attachable_type' => 'App\\Models\\TicketNote',
            'attachable_id' => $note->id,
        ]);
    }
}
