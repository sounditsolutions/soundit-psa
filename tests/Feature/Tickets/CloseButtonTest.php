<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Jobs\MineTicketKnowledge;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Guards psa-2jho: an active ticket must not be closable in one click straight
 * from the sidebar — that bypassed the resolution-summary prompt (and the
 * wiki-mining path) that Resolve routes through. Closing an active ticket now
 * opens #closeModal, which captures a resolution before posting status=closed.
 */
class CloseButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ticket creation fires the observer (triage dispatch + creation
        // notification). Fake the bus so no queued work escapes the test.
        Bus::fake();
    }

    /**
     * Extract the sidebar quick-actions bar (first `d-flex gap-2`, which has no
     * nested <div>) so assertions target it specifically and not the modals.
     */
    private function quickActions(string $html): string
    {
        preg_match('/<div class="d-flex gap-2">(.*?)<\/div>/s', $html, $m);
        $bar = $m[0] ?? '';
        $this->assertNotEmpty($bar, 'Could not find the quick-actions bar in the response');
        $this->assertStringContainsString('bi-x-circle', $bar, 'Wrong block extracted — expected the Close action');

        return $bar;
    }

    public function test_active_ticket_close_routes_through_a_resolution_prompt(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::New]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));
        $resp->assertOk();

        // The Close affordance in the sidebar opens the resolution-capturing modal...
        $resp->assertSee('data-bs-target="#closeModal"', false);
        // ...and that modal exists, captures a resolution, and posts to the status endpoint.
        $resp->assertSee('id="closeModal"', false);
        $resp->assertSee('id="closeResolution"', false);
        $resp->assertSee('action="'.route('tickets.update-status', $ticket).'"', false);

        // The quick-actions bar itself must NOT submit status=closed directly — that
        // one-click form was the bypass. On an active ticket the only status=closed
        // form now lives inside the modal.
        $bar = $this->quickActions($resp->getContent());
        $this->assertStringContainsString('data-bs-target="#closeModal"', $bar);
        $this->assertStringNotContainsString(
            'value="closed"',
            $bar,
            'Active-ticket quick actions must not post status=closed directly; closing must route through #closeModal.'
        );
    }

    public function test_closing_an_active_ticket_with_a_resolution_saves_it_and_fires_mining(): void
    {
        // wiki + auto-mine on so TicketObserver dispatches mining on the close.
        Setting::setValue('wiki_enabled', '1');
        Setting::setValue('wiki_auto_mine', '1');
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::New, 'resolution' => null]);

        $resp = $this->actingAs($user)->patch(route('tickets.update-status', $ticket), [
            'status' => TicketStatus::Closed->value,
            'resolution' => 'Confirmed the print spooler restart cleared the stuck queue.',
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));
        $ticket->refresh();
        $this->assertSame(TicketStatus::Closed, $ticket->status);
        // The resolution captured by the close prompt is persisted...
        $this->assertSame('Confirmed the print spooler restart cleared the stuck queue.', $ticket->resolution);
        // ...and the gold-path mining fires instead of the empty-resolution fallback.
        Bus::assertDispatched(MineTicketKnowledge::class);
    }

    public function test_resolved_ticket_keeps_a_one_click_close_finalize(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Resolved,
            'resolution' => 'Already summarised at resolve time.',
        ]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));
        $resp->assertOk();

        // A Resolved ticket already has its resolution captured, so Close stays a
        // direct one-click finalize (no prompt). Regression guard for that path.
        $bar = $this->quickActions($resp->getContent());
        $this->assertStringContainsString(
            'value="closed"',
            $bar,
            'A Resolved ticket should offer a one-click close finalize.'
        );
        $this->assertStringNotContainsString(
            'data-bs-target="#closeModal"',
            $bar,
            'A Resolved ticket should not route Close through the active-ticket resolution modal.'
        );
        // The active-ticket close modal is not rendered for a non-open ticket.
        $resp->assertDontSee('id="closeModal"', false);
    }
}
