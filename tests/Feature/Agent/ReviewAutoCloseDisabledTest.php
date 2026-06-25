<?php

namespace Tests\Feature\Agent;

use App\Enums\TicketStatus;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use App\Services\Triage\ConversationReviewer;
use App\Services\Triage\ReviewResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ConversationReviewer auto-close stands down when the agent owns closing.
 *
 * When AgentConfig::enabled() is true, all closing routes through the agent's
 * audited, human-approvable path (propose_close + the gate). The review's un-gated
 * auto-close must no-op so there is exactly one close path.
 */
class ReviewAutoCloseDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // prevent real queue dispatches from notifyStatusChanged
        // A user must exist so TriageConfig::systemUserId() resolves to a real ID.
        User::factory()->create();
    }

    // ── helpers ──────────────────────────────────────────────────────────────────

    private function configureSettings(bool $agentEnabled): void
    {
        Setting::setValue('triage_review_auto_close', '1');
        Setting::setValue('triage_review_auto_close_threshold', '80');
        Setting::setValue('agent_enabled', $agentEnabled ? '1' : '0');
    }

    /**
     * A high-confidence "resolved" result that meets the default threshold (80).
     * This is the exact assessment that would trigger auto-close when the setting is on.
     */
    private function autoCloseEligibleResult(): ReviewResult
    {
        return new ReviewResult(
            assessment: 'resolved',
            confidence: 'high',
            confidenceScore: 90, // above the 80 threshold
            reasoning: 'Customer confirmed the issue is resolved.',
        );
    }

    /**
     * An open ticket — InProgress avoids the stale-ticket guard inside takeAction()
     * that skips already-Closed/Resolved tickets, and avoids Ticket::factory()'s
     * default of Closed.
     */
    private function openTicket(): Ticket
    {
        return Ticket::factory()->create([
            'status' => TicketStatus::InProgress->value,
            'closed_at' => null,
        ]);
    }

    /**
     * Call the private static takeAction() directly via reflection so we can
     * unit-test the guard in isolation without needing to mock the AI layer.
     */
    private function callTakeAction(Ticket $ticket, ReviewResult $result): ?string
    {
        $method = new ReflectionMethod(ConversationReviewer::class, 'takeAction');
        $method->setAccessible(true);

        return $method->invoke(null, $ticket, $result, app(TicketService::class));
    }

    // ── tests ─────────────────────────────────────────────────────────────────────

    /**
     * Agent enabled → review auto-close no-ops.
     * The early-return guard must block the auto-close arm and leave the ticket open.
     */
    public function test_review_auto_close_no_ops_when_agent_is_enabled(): void
    {
        $this->configureSettings(agentEnabled: true);
        $ticket = $this->openTicket();

        $action = $this->callTakeAction($ticket, $this->autoCloseEligibleResult());

        $this->assertNull($action, 'takeAction() must return null when the agent owns closing');

        $ticket->refresh();
        $this->assertNotSame(TicketStatus::Closed, $ticket->status);
        $this->assertNull($ticket->closed_at);
    }

    /**
     * Agent disabled → review auto-close still works (regression guard).
     * The new guard is purely additive; agent-off behavior must be identical to today.
     */
    public function test_review_auto_close_still_works_when_agent_is_disabled(): void
    {
        $this->configureSettings(agentEnabled: false);
        $ticket = $this->openTicket();

        $action = $this->callTakeAction($ticket, $this->autoCloseEligibleResult());

        $this->assertNotNull($action, 'takeAction() must return an action string when agent is off');
        $this->assertStringContainsString('auto_closed', $action);

        $ticket->refresh();
        $this->assertSame(TicketStatus::Closed, $ticket->status);
        $this->assertNotNull($ticket->closed_at);
    }
}
