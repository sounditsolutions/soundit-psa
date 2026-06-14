<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ChangeStatusPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ticket creation fires the observer (triage dispatch + creation
        // notification). Fake the bus so no queued work escapes the test.
        Bus::fake();
    }

    public function test_change_status_panel_posts_to_the_status_endpoint(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();

        $html = $resp->getContent();

        // Extract just the #actionStatus panel so we can assert against it specifically.
        preg_match('/<div[^>]+id="actionStatus"[^>]*>(.+?)<\/div>\s*<\/div>\s*<\/div>/s', $html, $m);
        $panelHtml = $m[0] ?? '';
        $this->assertNotEmpty($panelHtml, 'Could not find #actionStatus panel in response');

        // The panel's form must point at the dedicated status endpoint, not note-create.
        $statusUrl = route('tickets.update-status', $ticket);
        $notesUrl = route('tickets.notes.store', $ticket);

        $this->assertStringContainsString(
            'action="'.$statusUrl.'"',
            $panelHtml,
            'Panel form must post to tickets.update-status'
        );

        $this->assertStringNotContainsString(
            'action="'.$notesUrl.'"',
            $panelHtml,
            'Panel form must NOT post to tickets.notes.store'
        );

        // The status select must use name="status" (not name="new_status").
        $this->assertStringContainsString('name="status"', $panelHtml);
        $this->assertStringNotContainsString('name="new_status"', $panelHtml);

        // Resolution field must be present.
        $this->assertStringContainsString('name="resolution"', $panelHtml);

        // The hidden empty body input that caused the bug must be gone.
        $this->assertStringNotContainsString('name="body"', $panelHtml);
    }

    public function test_status_only_change_sets_status_and_saves_resolution(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::InProgress,
            'resolution' => null,
        ]);

        $resp = $this->actingAs($user)->patch(route('tickets.update-status', $ticket), [
            'status' => TicketStatus::PendingClient->value,
            'resolution' => 'Waiting on client confirmation',
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));
        $resp->assertSessionHas('success');

        $ticket->refresh();
        $this->assertSame(TicketStatus::PendingClient, $ticket->status);
        $this->assertSame('Waiting on client confirmation', $ticket->resolution);
        $this->assertDatabaseHas('ticket_notes', [
            'ticket_id' => $ticket->id,
            'status_to' => TicketStatus::PendingClient->value,
        ]);
    }
}
