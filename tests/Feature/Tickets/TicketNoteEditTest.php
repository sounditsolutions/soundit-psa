<?php

namespace Tests\Feature\Tickets;

use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Models\Attachment;
use App\Models\Setting;
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

    public function test_editing_a_note_updates_its_noted_at(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $note = $this->makeNote($ticket, $user);

        // Backdate the note (the chronological-ordering use case).
        $newDate = now()->subDays(10)->startOfMinute();

        $this->actingAs($user)->put(route('tickets.notes.update', [$ticket, $note]), [
            'body' => 'Backdated note',
            'note_type' => 'note',
            'is_private' => '1',
            'noted_at' => $newDate->format('Y-m-d\TH:i'),
        ])->assertRedirect(route('tickets.show', $ticket));

        $note->refresh();
        $this->assertSame($newDate->format('Y-m-d H:i'), $note->noted_at->format('Y-m-d H:i'));
    }

    public function test_a_future_noted_at_is_rejected_and_leaves_the_note_unchanged(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $note = $this->makeNote($ticket, $user, 'Original body');
        $original = $note->noted_at->format('Y-m-d H:i');

        $resp = $this->actingAs($user)->put(route('tickets.notes.update', [$ticket, $note]), [
            'body' => 'Edited body',
            'note_type' => 'note',
            'is_private' => '1',
            'noted_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));
        $resp->assertSessionHas('error');

        $note->refresh();
        // Whole edit is rejected (mirrors the >24h time guard): date AND body unchanged.
        $this->assertSame($original, $note->noted_at->format('Y-m-d H:i'));
        $this->assertSame('Original body', $note->body);
    }

    public function test_noted_at_is_interpreted_in_the_app_timezone(): void
    {
        Setting::setValue('app_timezone', 'America/New_York');

        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $note = $this->makeNote($ticket, $user);

        // 09:00 on 2026-06-01 in America/New_York (EDT, UTC-4) == 13:00 UTC in storage.
        $this->actingAs($user)->put(route('tickets.notes.update', [$ticket, $note]), [
            'body' => 'tz note',
            'note_type' => 'note',
            'is_private' => '1',
            'noted_at' => '2026-06-01T09:00',
        ])->assertRedirect(route('tickets.show', $ticket));

        $note->refresh();
        $this->assertSame('2026-06-01 13:00', $note->noted_at->format('Y-m-d H:i'));
    }

    public function test_edit_modal_includes_a_prefilled_noted_at_input(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $note = $this->makeNote($ticket, $user);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertSee('name="noted_at"', false);
        // Pre-filled with the note's current date in the datetime-local format.
        $resp->assertSee('value="'.$note->noted_at->toAppTz()->format('Y-m-d\TH:i').'"', false);
    }
}
