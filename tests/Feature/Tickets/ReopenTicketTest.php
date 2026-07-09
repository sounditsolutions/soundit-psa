<?php

namespace Tests\Feature\Tickets;

use App\Enums\NoteType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ReopenTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_resolved_ticket_show_page_has_reopen_button(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Resolved]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertSee('action="'.route('tickets.update-status', $ticket).'"', false);
        $resp->assertSee('value="in_progress"', false);
        $resp->assertSee('Reopen');
    }

    public function test_open_ticket_does_not_show_reopen_button(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertDontSee('Reopen');
    }

    public function test_closed_ticket_show_page_has_reopen_button(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Closed]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertSee('action="'.route('tickets.update-status', $ticket).'"', false);
        $resp->assertSee('value="in_progress"', false);
        $resp->assertSee('Reopen');
    }

    public function test_reopening_preserves_the_resolution(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Resolved,
            'resolution' => 'Replaced the failing NIC.',
        ]);

        $resp = $this->actingAs($user)->patch(route('tickets.update-status', $ticket), [
            'status' => TicketStatus::InProgress->value,
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));
        $ticket->refresh();
        $this->assertSame(TicketStatus::InProgress, $ticket->status);
        $this->assertSame('Replaced the failing NIC.', $ticket->resolution);
    }

    public function test_reopened_ticket_with_resolution_renders_prior_resolution_card(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => 'Replaced the failing NIC.',
        ]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertSee('Prior resolution (ticket reopened)');
        $resp->assertSee('border-secondary', false);
        $resp->assertDontSee('border-success', false);
    }

    public function test_resolved_ticket_with_resolution_renders_resolution_card(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Resolved,
            'resolution' => 'Replaced the failing NIC.',
        ]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertSee('Resolution');
        $resp->assertSee('border-success', false);
        $resp->assertDontSee('border-secondary', false);
    }

    /**
     * Regression guard for psa-m0yw: the visible Reopen button must be a single
     * self-contained form that PATCHes the dedicated status endpoint with
     * status=in_progress. The earlier assertions checked those three tokens
     * appeared *somewhere* on the page, which a form pointing at the wrong
     * endpoint (the psa-3vzk note-create failure mode) would still satisfy.
     * This asserts they are co-located in the one form the button submits.
     */
    public function test_reopen_control_targets_the_status_endpoint_not_note_create(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Resolved]);

        $html = $this->actingAs($user)->get(route('tickets.show', $ticket))->getContent();
        $form = $this->formForButton($html, 'Reopen');

        $this->assertSame('patch', $form['method'], 'Reopen must submit as PATCH.');
        $this->assertSame(
            route('tickets.update-status', $ticket),
            $form['action'],
            'Reopen must post to tickets.update-status.'
        );
        $this->assertNotSame(
            route('tickets.notes.store', $ticket),
            $form['action'],
            'Reopen must NOT post to the note-create endpoint.'
        );
        $this->assertSame('in_progress', $form['fields']['status'] ?? null);
    }

    /**
     * Faithfully replays the browser flow from the bug report: render the
     * resolved ticket, extract the exact fields the Reopen button submits, and
     * POST them. The ticket must return to In Progress, clear resolved_at,
     * preserve the prior resolution, and record a status-change audit note.
     */
    public function test_submitting_the_rendered_reopen_form_reopens_a_resolved_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Resolved,
            'resolution' => 'Replaced the failing NIC.',
            'resolved_at' => now()->subHour(),
        ]);

        $html = $this->actingAs($user)->get(route('tickets.show', $ticket))->getContent();
        $form = $this->formForButton($html, 'Reopen');

        $resp = $this->actingAs($user)->patch($form['action'], $form['fields']);

        $resp->assertRedirect(route('tickets.show', $ticket));
        $resp->assertSessionHas('success');

        $ticket->refresh();
        $this->assertSame(TicketStatus::InProgress, $ticket->status);
        $this->assertNull($ticket->resolved_at, 'resolved_at must be cleared on reopen.');
        $this->assertSame('Replaced the failing NIC.', $ticket->resolution);

        $this->assertDatabaseHas('ticket_notes', [
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::StatusChange->value,
            'status_from' => TicketStatus::Resolved->value,
            'status_to' => TicketStatus::InProgress->value,
        ]);
    }

    /**
     * The Reopen button is also offered on closed tickets; reopening one must
     * clear both closed_at and resolved_at and return it to In Progress.
     */
    public function test_submitting_the_rendered_reopen_form_reopens_a_closed_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Closed,
            'resolution' => 'Password reset completed.',
            'resolved_at' => now()->subDay(),
            'closed_at' => now()->subHour(),
        ]);

        $html = $this->actingAs($user)->get(route('tickets.show', $ticket))->getContent();
        $form = $this->formForButton($html, 'Reopen');

        $resp = $this->actingAs($user)->patch($form['action'], $form['fields']);

        $resp->assertRedirect(route('tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame(TicketStatus::InProgress, $ticket->status);
        $this->assertNull($ticket->closed_at, 'closed_at must be cleared on reopen.');
        $this->assertNull($ticket->resolved_at, 'resolved_at must be cleared on reopen.');
        $this->assertSame('Password reset completed.', $ticket->resolution);
    }

    /**
     * Extract the rendered <form> whose submit button carries the given label,
     * returning the effective HTTP method (honoring Laravel's _method spoof),
     * the action URL, and the input fields the browser would post. Forms on the
     * ticket page are never nested, so a non-greedy match is unambiguous.
     *
     * @return array{method: string, action: string, fields: array<string, string>}
     */
    private function formForButton(string $html, string $buttonLabel): array
    {
        preg_match_all('/<form\b[^>]*>.*?<\/form>/s', $html, $matches);

        $form = null;
        foreach ($matches[0] as $candidate) {
            if (str_contains($candidate, $buttonLabel)) {
                $form = $candidate;
                break;
            }
        }
        $this->assertNotNull($form, "No <form> containing a '{$buttonLabel}' button was found.");

        preg_match('/action="([^"]*)"/', $form, $actionMatch);
        $action = html_entity_decode($actionMatch[1] ?? '');

        $fields = [];
        preg_match_all('/<input\b[^>]*>/s', $form, $inputs);
        foreach ($inputs[0] as $input) {
            if (! preg_match('/name="([^"]*)"/', $input, $nameMatch)) {
                continue;
            }
            preg_match('/value="([^"]*)"/', $input, $valueMatch);
            $fields[$nameMatch[1]] = html_entity_decode($valueMatch[1] ?? '');
        }

        $method = strtolower($fields['_method'] ?? 'post');
        unset($fields['_method']);

        return ['method' => $method, 'action' => $action, 'fields' => $fields];
    }
}
