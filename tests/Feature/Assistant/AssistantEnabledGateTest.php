<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantConversation;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assistant\AssistantService;
use App\Support\AssistantConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-uw2o: "Enable AI Assistant" must actually disable the Assistant.
 *
 * Before this test existed, every reader of `assistant_enabled` was a VIEW
 * concern — the bubble, the topbar entry, a ticket button, and the settings
 * page itself. There was not one functional gate. Unticking the box hid the
 * door and left it unlocked: the endpoints still ran the tool loop, and the
 * Assistant's two write tools (create_ticket, add_ticket_note) still executed.
 *
 * That is the failure mode the control exists to prevent: an operator reaches
 * for this toggle mid-incident precisely because they want the AI to stop
 * writing. A control that is trusted under stress must do what its label says.
 *
 * Scope note: this fixes the OFF switch only. Whether the Assistant should
 * DEFAULT to on when an Anthropic key is present is a separate product call
 * (psa-98dq, Charlie's). These tests deliberately assert against an EXPLICIT
 * setting in both directions, so they hold under either ruling.
 */
class AssistantEnabledGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // AiConfig::isConfigured() → true, provider anthropic. Without this the
        // Assistant is off for an unrelated reason and the gate proves nothing.
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');

        $this->user = User::factory()->create();
    }

    private function enable(): void
    {
        Setting::setValue('assistant_enabled', '1');
    }

    private function disable(): void
    {
        Setting::setValue('assistant_enabled', '0');
    }

    private function conversation(): AssistantConversation
    {
        return AssistantConversation::create([
            'user_id' => $this->user->id,
            'context_type' => null,
            'context_id' => null,
        ]);
    }

    /**
     * Every route the Assistant exposes, with a request that would otherwise
     * succeed. Built per-test because several need a live conversation row.
     */
    private function routes(AssistantConversation $conversation, Ticket $ticket): array
    {
        return [
            'assistant.create' => ['post', '/assistant/conversations', []],
            'assistant.for-ticket' => ['get', "/assistant/conversations/for-ticket/{$ticket->id}", []],
            'assistant.show' => ['get', "/assistant/conversations/{$conversation->id}", []],
            'assistant.message' => ['post', "/assistant/conversations/{$conversation->id}/messages", ['message' => 'hello']],
            'assistant.save-note' => ['post', "/assistant/conversations/{$conversation->id}/save-note", ['message_id' => 1]],
            'assistant.general' => ['get', '/assistant/general', []],
        ];
    }

    public function test_every_assistant_endpoint_is_refused_when_the_assistant_is_disabled(): void
    {
        $this->disable();

        $conversation = $this->conversation();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        foreach ($this->routes($conversation, $ticket) as $name => [$verb, $uri, $payload]) {
            $response = $this->actingAs($this->user)->{$verb}($uri, $payload);

            $this->assertSame(
                404,
                $response->getStatusCode(),
                "{$name} ({$verb} {$uri}) must be refused while assistant_enabled=0 — ".
                'the toggle is a safety control, not a cosmetic one'
            );
        }
    }

    public function test_the_endpoints_still_work_when_the_assistant_is_enabled(): void
    {
        // The gate must not over-block: proving 404-when-off is only half the
        // claim. If this passes trivially the gate would be indistinguishable
        // from the feature being broken outright.
        $this->enable();

        $conversation = $this->conversation();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        // Read paths that do not call the AI provider.
        $this->actingAs($this->user)
            ->get("/assistant/conversations/{$conversation->id}")
            ->assertOk();

        $this->actingAs($this->user)
            ->get('/assistant/general')
            ->assertOk();

        $this->actingAs($this->user)
            ->get("/assistant/conversations/for-ticket/{$ticket->id}")
            ->assertOk();

        $this->actingAs($this->user)
            ->post('/assistant/conversations', [])
            ->assertOk();
    }

    public function test_no_assistant_conversation_is_created_while_disabled(): void
    {
        // The gate must stop the write, not merely the response body. If the
        // row lands before the refusal, "off" still mutates state.
        $this->disable();

        $before = AssistantConversation::count();

        $this->actingAs($this->user)->post('/assistant/conversations', [])->assertStatus(404);
        $this->actingAs($this->user)->get('/assistant/general')->assertStatus(404);

        $this->assertSame(
            $before,
            AssistantConversation::count(),
            'a disabled Assistant must not create conversation rows'
        );
    }

    /**
     * The gate must not lock the operator out of their own switch. If the
     * settings writer were swept into the `assistant.enabled` group, turning
     * the Assistant off would be irreversible through the UI — an off switch
     * you cannot undo is its own outage. This is the reason the route group
     * covers only the assistant endpoints and not settings.
     */
    public function test_the_assistant_can_be_re_enabled_while_it_is_disabled(): void
    {
        $this->disable();
        $this->assertFalse(AssistantConfig::isEnabled());

        $this->actingAs($this->user)
            ->post('/settings/integrations/assistant', [
                'assistant_enabled' => '1',
                'assistant_max_messages' => 50,
                'assistant_daily_token_limit' => 500000,
            ])
            ->assertRedirect();

        $this->assertTrue(
            AssistantConfig::isEnabled(),
            'the settings route must stay reachable while the Assistant is off, or the toggle is one-way'
        );
    }

    public function test_the_service_itself_refuses_when_disabled(): void
    {
        // Defence in depth. The route middleware guards the HTTP door; this
        // guards every programmatic caller, so a future entry point cannot
        // reach the tool loop (and its two write tools) around the gate.
        $this->disable();

        $conversation = $this->conversation();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/disabled/i');

        (new AssistantService)->sendMessage($conversation, 'hello');
    }

    public function test_disabling_the_assistant_is_honoured_by_the_config_helper(): void
    {
        $this->enable();
        $this->assertTrue(AssistantConfig::isEnabled());

        $this->disable();
        $this->assertFalse(AssistantConfig::isEnabled());
    }

    /**
     * The system prompt told the model it had "read-only tools" while handing
     * it create_ticket and add_ticket_note. That is not documentation rot: it
     * is the write surface being invisible to anyone — human or model — who
     * reads the prompt to find out what the Assistant can do.
     */
    public function test_the_system_prompt_does_not_claim_read_only_while_offering_write_tools(): void
    {
        $this->enable();

        $client = Client::factory()->create();
        $conversation = AssistantConversation::create([
            'user_id' => $this->user->id,
            'context_type' => 'client',
            'context_id' => $client->id,
        ]);

        $method = new \ReflectionMethod(AssistantService::class, 'buildSystemPrompt');
        $method->setAccessible(true);
        $prompt = (string) $method->invoke(new AssistantService, $conversation);

        $this->assertStringNotContainsStringIgnoringCase(
            'read-only tools',
            $prompt,
            'the system prompt must not claim read-only while create_ticket and add_ticket_note are offered'
        );
    }

    public function test_the_tool_definitions_docblock_does_not_claim_read_only_only(): void
    {
        // Same rot, aimed at the next reviewer rather than the model:
        // AssistantToolDefinitions' own header claimed "Read-only tools only
        // (v1)" in the very file that defines both writers.
        $source = (string) file_get_contents(app_path('Services/Assistant/AssistantToolDefinitions.php'));

        $header = substr($source, 0, (int) strpos($source, 'class AssistantToolDefinitions'));

        $this->assertStringNotContainsStringIgnoringCase(
            'read-only tools only',
            $header,
            'AssistantToolDefinitions defines create_ticket and add_ticket_note — its docblock must not claim read-only only'
        );
    }
}
