<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantConversation;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-uw2o.4 (UX review): the ticket timeline's AI chat block survived the gate.
 *
 * The "Ask AI" button lives in a gated partial and correctly disappears. The
 * timeline conversation block does not: tickets/show.blade.php includes
 * _timeline-ai-chat with no enable check, and that partial renders a live input
 * and send button whenever $isActive (owner + a message inside 30 minutes).
 *
 * So the incident journey read: a technician's AI conversation misbehaves, the
 * operator switches the Assistant off *because of that conversation*, the
 * technician reloads the ticket — and still sees a chat box badged "Active"
 * with a working-looking send button, inside the exact 30-minute window an
 * incident lives in. Typing into it hit a refusal.
 *
 * The history must remain visible; only the live affordance goes away.
 */
class AssistantDisabledTicketTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');

        $this->user = User::factory()->create();
        $client = Client::factory()->create();
        $this->ticket = Ticket::factory()->create(['client_id' => $client->id]);

        // An owned conversation with a fresh message — i.e. $isActive is true,
        // which is exactly the state that rendered the live input.
        $conversation = AssistantConversation::create([
            'user_id' => $this->user->id,
            'context_type' => 'ticket',
            'context_id' => $this->ticket->id,
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Checking the mailbox rules now.',
        ]);
    }

    private function ticketPage(): string
    {
        return (string) $this->actingAs($this->user)
            ->get(route('tickets.show', $this->ticket))
            ->assertOk()
            ->getContent();
    }

    public function test_the_live_chat_input_is_rendered_while_the_assistant_is_enabled(): void
    {
        // Control: without this, the disabled-case assertion below could pass
        // simply because the block never renders at all.
        Setting::setValue('assistant_enabled', '1');

        $html = $this->ticketPage();

        $this->assertStringContainsString('ai-chat-send', $html, 'the live send button should exist while enabled');
    }

    public function test_no_live_chat_input_is_rendered_while_the_assistant_is_disabled(): void
    {
        Setting::setValue('assistant_enabled', '0');

        $html = $this->ticketPage();

        $this->assertStringNotContainsString(
            'ai-chat-send',
            $html,
            'a disabled Assistant must not render a send button — it refuses the request behind it'
        );
        $this->assertStringNotContainsString(
            'ai-chat-text',
            $html,
            'a disabled Assistant must not render a live chat input'
        );
    }

    /**
     * psa-uw2o.11 / psa-322qo: a disabled Assistant must SAY it is disabled,
     * not just vanish.
     *
     * Charlie approved default-off on the condition that it is not a silent
     * absence. The notice goes where the affordance WAS — someone who used the
     * Assistant goes to the button they used to click and finds an explanation
     * instead of nothing.
     */
    public function test_the_ticket_page_explains_that_the_assistant_is_disabled(): void
    {
        Setting::setValue('assistant_enabled', '0');

        $html = $this->ticketPage();

        $this->assertStringContainsString(
            'AI Assistant is disabled',
            $html,
            'a disabled Assistant must be explained where its control used to be, not silently absent'
        );
        // ...and must not resurrect a live-looking control (the psa-uw2o.4
        // lesson: a dead affordance is worse than an absent one).
        $this->assertStringNotContainsString('id="askAiBtn"', $html);
    }

    public function test_the_disabled_notice_is_absent_when_the_assistant_is_enabled(): void
    {
        // Control: without this the assertion above could pass on a string that
        // is always present.
        Setting::setValue('assistant_enabled', '1');

        $html = $this->ticketPage();

        $this->assertStringNotContainsString('AI Assistant is disabled', $html);
        $this->assertStringContainsString('id="askAiBtn"', $html);
    }

    public function test_the_conversation_history_is_still_visible_while_disabled(): void
    {
        // Turning the Assistant off must not erase the record of what it did.
        Setting::setValue('assistant_enabled', '0');

        $html = $this->ticketPage();

        $this->assertStringContainsString(
            'Checking the mailbox rules now.',
            $html,
            'disabling the Assistant hides the live affordance, not the history'
        );
    }
}
