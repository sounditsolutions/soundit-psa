<?php

namespace Tests\Feature\Triage;

use App\Enums\CategorySuggestionStatus;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketCategorySuggestion;
use App\Models\User;
use App\Services\Triage\TicketCategorySuggestionService;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketCategorySuggestionTest extends TestCase
{
    use RefreshDatabase;

    // ── Executor: approval gate ──

    public function test_executor_records_pending_suggestion_when_approval_enabled(): void
    {
        // Approval defaults to on (setting unset) — no direct write should occur.
        $ticket = Ticket::factory()->create(['category' => null, 'subcategory' => null]);

        $result = (new TriageToolExecutor($ticket))->execute('set_ticket_category', [
            'category' => 'Hardware',
            'subcategory' => 'Laptop',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('pending_approval', $result['status']);

        // Ticket is untouched — the suggestion is queued, not applied.
        $ticket->refresh();
        $this->assertNull($ticket->category);
        $this->assertNull($ticket->subcategory);

        $this->assertDatabaseHas('ticket_category_suggestions', [
            'ticket_id' => $ticket->id,
            'category' => 'Hardware',
            'subcategory' => 'Laptop',
            'status' => CategorySuggestionStatus::Pending->value,
        ]);
    }

    public function test_executor_writes_category_directly_when_approval_disabled(): void
    {
        Setting::setValue('triage_category_approval', '0');
        $ticket = Ticket::factory()->create(['category' => null, 'subcategory' => null]);

        $result = (new TriageToolExecutor($ticket))->execute('set_ticket_category', [
            'category' => 'Network',
            'subcategory' => 'VPN',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('status', $result);

        $ticket->refresh();
        $this->assertSame('Network', $ticket->category);
        $this->assertSame('VPN', $ticket->subcategory);

        $this->assertDatabaseCount('ticket_category_suggestions', 0);
    }

    public function test_executor_rejects_invalid_category(): void
    {
        $ticket = Ticket::factory()->create(['category' => null]);

        $result = (new TriageToolExecutor($ticket))->execute('set_ticket_category', [
            'category' => 'Nonsense',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertDatabaseCount('ticket_category_suggestions', 0);
        $ticket->refresh();
        $this->assertNull($ticket->category);
    }

    // ── Service ──

    public function test_suggest_supersedes_prior_pending_for_same_ticket(): void
    {
        $ticket = Ticket::factory()->create();
        $service = app(TicketCategorySuggestionService::class);

        $service->suggest($ticket, 'Hardware', 'Laptop');
        $service->suggest($ticket, 'Software', 'OS');

        // Only one pending suggestion survives, carrying the latest values.
        $pending = TicketCategorySuggestion::where('ticket_id', $ticket->id)
            ->where('status', CategorySuggestionStatus::Pending)
            ->get();

        $this->assertCount(1, $pending);
        $this->assertSame('Software', $pending->first()->category);
        $this->assertSame('OS', $pending->first()->subcategory);
    }

    public function test_suggest_normalizes_empty_subcategory_to_null(): void
    {
        $ticket = Ticket::factory()->create();

        $suggestion = app(TicketCategorySuggestionService::class)->suggest($ticket, 'Email', '');

        $this->assertNull($suggestion->subcategory);
    }

    // ── Approval queue routes ──

    public function test_approve_applies_category_to_ticket_and_stamps_review(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['category' => null, 'subcategory' => null]);
        $suggestion = app(TicketCategorySuggestionService::class)->suggest($ticket, 'Security', 'Phishing');

        $response = $this->actingAs($user)
            ->post(route('triage.category-suggestions.approve', $suggestion));

        $response->assertRedirect(route('triage.category-suggestions.index'));

        $ticket->refresh();
        $this->assertSame('Security', $ticket->category);
        $this->assertSame('Phishing', $ticket->subcategory);

        $suggestion->refresh();
        $this->assertSame(CategorySuggestionStatus::Approved, $suggestion->status);
        $this->assertSame($user->id, $suggestion->reviewed_by);
        $this->assertNotNull($suggestion->reviewed_at);
    }

    public function test_reject_marks_rejected_without_touching_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['category' => 'Hardware', 'subcategory' => 'Desktop']);
        $suggestion = app(TicketCategorySuggestionService::class)->suggest($ticket, 'Software', 'OS');

        $response = $this->actingAs($user)
            ->post(route('triage.category-suggestions.reject', $suggestion));

        $response->assertRedirect(route('triage.category-suggestions.index'));

        // Ticket keeps its existing category — reject never applies the suggestion.
        $ticket->refresh();
        $this->assertSame('Hardware', $ticket->category);
        $this->assertSame('Desktop', $ticket->subcategory);

        $suggestion->refresh();
        $this->assertSame(CategorySuggestionStatus::Rejected, $suggestion->status);
        $this->assertSame($user->id, $suggestion->reviewed_by);
    }

    public function test_approving_already_reviewed_suggestion_is_a_noop(): void
    {
        $firstReviewer = User::factory()->create();
        $secondReviewer = User::factory()->create();
        $ticket = Ticket::factory()->create(['category' => null]);
        $suggestion = app(TicketCategorySuggestionService::class)->suggest($ticket, 'Cloud', 'Azure');

        $this->actingAs($firstReviewer)->post(route('triage.category-suggestions.approve', $suggestion));

        // Second approval by a different user must not re-stamp the review.
        $this->actingAs($secondReviewer)
            ->post(route('triage.category-suggestions.approve', $suggestion))
            ->assertRedirect(route('triage.category-suggestions.index'));

        $suggestion->refresh();
        $this->assertSame(CategorySuggestionStatus::Approved, $suggestion->status);
        $this->assertSame($firstReviewer->id, $suggestion->reviewed_by);
    }

    public function test_index_lists_pending_suggestions(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['subject' => 'Laptop will not boot']);
        app(TicketCategorySuggestionService::class)->suggest($ticket, 'Hardware', 'Laptop');

        $response = $this->actingAs($user)->get(route('triage.category-suggestions.index'));

        $response->assertOk();
        $response->assertSee('Laptop will not boot');
        $response->assertSee('Hardware');
    }

    public function test_index_shows_recently_reviewed_suggestions(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['subject' => 'Printer offline']);
        $suggestion = app(TicketCategorySuggestionService::class)->suggest($ticket, 'Hardware', 'Printer');
        app(TicketCategorySuggestionService::class)->reject($suggestion, $user->id);

        $response = $this->actingAs($user)->get(route('triage.category-suggestions.index'));

        $response->assertOk();
        $response->assertSee('Recently reviewed');
        $response->assertSee($suggestion->status->label());
        $response->assertSee($user->name);
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('triage.category-suggestions.index'))->assertRedirect(route('login'));
    }
}
