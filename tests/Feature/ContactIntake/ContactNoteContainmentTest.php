<?php

namespace Tests\Feature\ContactIntake;

use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Triage\ContextBuilder;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class ContactNoteContainmentTest extends TestCase
{
    private function note(string $body, bool $private, bool $form = false): TicketNote
    {
        $note = new TicketNote;
        $note->forceFill([
            'body' => $body,
            'is_private' => $private,
            'note_type' => NoteType::Note,
            'who_type' => WhoType::Agent,
            'noted_at' => now(),
            'author_name' => 'Synthetic staff',
            'contact_intake_origin' => $form,
            'contact_intake_verified_at' => null,
        ]);
        $note->setRelation('author', null);
        $note->setRelation('email', null);

        return $note;
    }

    public function test_notes_context_excludes_unverified_form_material_but_preserves_ordinary_private_notes(): void
    {
        $ticket = new Ticket;
        $ticket->setRelation('notes', new Collection([
            $this->note('ORDINARY_PUBLIC_CONTROL', false),
            $this->note('ORDINARY_PRIVATE_CONTROL', true),
            $this->note('SYNTHETIC_UNVERIFIED_FORM_INSTRUCTION', true, true),
        ]));
        $method = new \ReflectionMethod(ContextBuilder::class, 'buildNotesSection');
        $text = $method->invoke(null, $ticket);
        $this->assertStringContainsString('ORDINARY_PUBLIC_CONTROL', $text);
        $this->assertStringContainsString('ORDINARY_PRIVATE_CONTROL', $text);
        $this->assertStringNotContainsString('SYNTHETIC_UNVERIFIED_FORM_INSTRUCTION', $text);
    }
}
