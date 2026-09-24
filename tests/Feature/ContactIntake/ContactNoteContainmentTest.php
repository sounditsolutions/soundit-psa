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

    public function test_form_note_edits_preserve_private_zero_time_provenance(): void
    {
        $observer = new \App\Observers\TicketNoteObserver(app(\App\Services\PrepayService::class));
        $note = $this->note('FORM_CONTENT', true, true);
        $note->exists = true;
        $note->syncOriginal();
        $note->forceFill(['contact_intake_origin' => false, 'is_private' => false, 'is_billable' => true, 'time_minutes' => 60, 'contract_id' => 123]);
        $observer->saving($note);
        $this->assertTrue($note->contact_intake_origin);
        $this->assertTrue($note->is_private);
        $this->assertFalse($note->is_billable);
        $this->assertSame(0, $note->time_minutes);
        $this->assertNull($note->contract_id);
        $this->assertNull(app(\App\Services\PrepayService::class)->debitFromTicketNote($note));

        $ordinary = $this->note('ORDINARY_PRIVATE_CONTROL', true);
        $ordinary->time_minutes = 60;
        $ordinary->is_billable = true;
        $observer->saving($ordinary);
        $this->assertSame(60, $ordinary->time_minutes);
        $this->assertTrue($ordinary->is_billable);
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
