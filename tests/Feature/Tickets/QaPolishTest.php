<?php

namespace Tests\Feature\Tickets;

use App\Enums\NoteType;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Locks in QA-sourced ticket-detail UI polish fixes (psa-grjd soft hint —
 * superseded for the Resolve modal by the required-resolution guard in psa-5d8f,
 * psa-4638 a11y labels, psa-uq6l system-note toggle consistency).
 */
class QaPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_resolve_modal_points_to_ai_draft_when_ai_configured(): void
    {
        // The resolution is now required (psa-5d8f). When AI is configured the
        // Resolve modal points at the "Draft with AI" assist rather than inviting a
        // blank submit — the tech generates a draft and reviews it before resolving.
        config(['services.ai.api_key' => 'test-key']);
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $this->actingAs($user)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee("to generate one from the ticket's notes, then review it before resolving", false);
    }

    public function test_resolve_modal_omits_draft_hint_when_ai_not_configured(): void
    {
        config(['services.ai.api_key' => null]);
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $this->actingAs($user)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertDontSee("to generate one from the ticket's notes", false);
    }

    public function test_note_action_buttons_have_accessible_names(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'author_name' => $user->name,
            'who_type' => WhoType::Agent,
            'body' => 'A note',
            'body_html' => '<p>A note</p>',
            'note_type' => NoteType::Note,
            'is_private' => true,
            'noted_at' => now(),
        ]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk();
        $resp->assertSee('aria-label="Edit note"', false);
        $resp->assertSee('aria-label="Delete note"', false);
    }

    /**
     * psa-uq6l: AI triage notes are system-generated, so the "system notes" toggle
     * must hide them by default. Otherwise the timeline shows a system note while
     * the control still reads "Show system notes" — a contradictory state.
     */
    public function test_ai_triage_notes_are_hidden_by_the_system_notes_toggle(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Resolved]);

        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_name' => 'AI Triage',
            'who_type' => WhoType::System,
            'body' => 'Classified as managed services; priority set to P3.',
            'note_type' => NoteType::AiTriage,
            'is_private' => true,
            'noted_at' => now(),
        ]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk();

        // The toggle starts in the "hidden" state ...
        $resp->assertSee('Show system notes', false);
        // ... the AI triage note did render into the timeline ...
        $resp->assertSee('AI Analysis', false);
        // ... so its row must carry the system-note class and be hidden by default,
        // exactly like every other system-generated note.
        $this->assertMatchesRegularExpression(
            '/class="d-flex gap-3 py-3 system-note[^"]*"\s+style="display: none;"/',
            $resp->getContent(),
            'AI triage note row should be a system-note hidden by default.'
        );
    }

    /**
     * psa-uq6l guard: hiding system notes must not sweep up human notes — a plain
     * technician note stays visible in the timeline regardless of the toggle.
     */
    public function test_human_notes_are_never_hidden_by_the_system_notes_toggle(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Resolved]);

        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $user->id,
            'author_name' => $user->name,
            'who_type' => WhoType::Agent,
            'body' => 'Human technician note that must stay visible.',
            'body_html' => '<p>Human technician note that must stay visible.</p>',
            'note_type' => NoteType::Note,
            'is_private' => true,
            'noted_at' => now(),
        ]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk();

        $resp->assertSee('Human technician note that must stay visible.');
        // No gap-3 note row should be a hidden system-note when the only note is human-authored.
        $this->assertDoesNotMatchRegularExpression(
            '/class="d-flex gap-3 py-3 system-note[^"]*"\s+style="display: none;"/',
            $resp->getContent(),
            'A human note must not be hidden by the system-notes toggle.'
        );
    }
}
