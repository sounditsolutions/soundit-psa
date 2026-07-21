<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantConversation;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\AssistantToolDefinitions;
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

    /**
     * psa-uw2o.2: the route group was documented as meaning "a route added
     * later cannot land outside the gate". It did not mean that — a group only
     * covers what is written inside it, so a route registered elsewhere would
     * have bypassed it. The binding now lives on the controller
     * (HasMiddleware), which is what actually delivers that invariant.
     *
     * This registers a route to the controller OUTSIDE the group and proves it
     * is still refused, rather than asserting the claim in a comment again.
     */
    public function test_a_route_registered_outside_the_group_is_still_gated(): void
    {
        $this->disable();

        \Illuminate\Support\Facades\Route::middleware(['web', 'auth'])
            ->get('/totally-elsewhere-assistant', [\App\Http\Controllers\Web\AssistantController::class, 'general']);

        $this->actingAs($this->user)
            ->get('/totally-elsewhere-assistant')
            ->assertStatus(404);
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

    /**
     * psa-uw2o.4 (UX review): the refusal must be legible to the code that
     * actually receives it.
     *
     * A bare abort(404) renders as {"message":""} — no `error` key. Every
     * assistant JS handler in this repo reads `d.error`, so the operator got
     * either a generic "Request failed" or, on the load paths, nothing at all.
     * The controller already speaks ['error' => ...] and the service guard
     * already throws the exact right sentence; the middleware must not pre-empt
     * that with a shape no consumer reads.
     */
    public function test_a_json_client_is_told_why_it_was_refused(): void
    {
        $this->disable();

        $response = $this->actingAs($this->user)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('/assistant/general');

        $body = (array) json_decode($response->getContent(), true);

        $this->assertArrayHasKey(
            'error',
            $body,
            'the JS handlers read d.error — a refusal without that key surfaces as "Request failed" or silence'
        );
        $this->assertStringContainsStringIgnoringCase('disabled', (string) $body['error']);
    }

    /**
     * A browser navigating to an assistant URL is not the JSON case; keep the
     * PortalEnabled-style 404 there so the surface is not advertised.
     */
    public function test_a_plain_browser_request_still_gets_a_bare_404(): void
    {
        $this->disable();

        $response = $this->actingAs($this->user)
            ->withHeaders(['Accept' => 'text/html'])
            ->get('/assistant/general');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertArrayNotHasKey('error', (array) json_decode($response->getContent(), true));
    }

    /**
     * psa-uw2o.1 (security review): saveAsNote() writes a TicketNote and is a
     * second service entry point. It is gated by the route today, but the
     * service-level guard promised to cover "every programmatic caller" — so it
     * must actually cover this one, not just sendMessage.
     */
    public function test_save_as_note_also_refuses_when_disabled(): void
    {
        $this->disable();

        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $conversation = $this->conversation();
        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'AI output.',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/disabled/i');

        (new AssistantService)->saveAsNote($message, $ticket, $this->user->id);
    }

    public function test_disabling_the_assistant_is_honoured_by_the_config_helper(): void
    {
        $this->enable();
        $this->assertTrue(AssistantConfig::isEnabled());

        $this->disable();
        $this->assertFalse(AssistantConfig::isEnabled());
    }

    private function promptFor(AssistantConversation $conversation): string
    {
        $method = new \ReflectionMethod(AssistantService::class, 'buildSystemPrompt');
        $method->setAccessible(true);

        // Mirrors how sendMessage derives it, which is the point of the invariant.
        $hasClient = $conversation->resolveClientId() !== null;

        return (string) $method->invoke(new AssistantService, $conversation, $hasClient);
    }

    /**
     * psa-uw2o.3 (product review): the no-client prompt says "your tools are
     * read-only". That is true today only by coincidence of which definition
     * sets getTools(false) merges — one of which, TriageToolDefinitions::
     * wikiTools(), is owned by the TRIAGE lane, not this one. Someone adding a
     * writer there would re-falsify the prompt with a green suite, which is
     * precisely the failure this whole bead is about.
     *
     * So assert the property rather than trusting the coincidence.
     */
    public function test_the_no_client_tool_surface_carries_no_write_tool(): void
    {
        $names = array_column(AssistantToolDefinitions::getTools(false), 'name');

        $this->assertNotEmpty($names, 'the general assistant must offer some tools, or this test proves nothing');

        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(create|add|update|delete|set|send|remove|assign|close|resolve|write|push|link|dismiss|retire|move)_/',
                $name,
                "the no-client surface is described to the model as read-only, but offers '{$name}'. ".
                'Either that tool does not belong here, or the prompt must stop claiming read-only.'
            );
        }
    }

    /**
     * The system prompt told the model it had "read-only tools" while handing
     * it create_ticket and add_ticket_note. That is not documentation rot: it
     * is the write surface being invisible to anyone — human or model — who
     * reads the prompt to find out what the Assistant can do.
     *
     * psa-uw2o.2 (architecture review) showed the first version of this test
     * was worthless: it only asserted the absence of one literal string, so
     * INVERTING the two branches still passed, and DELETING the whole
     * disclosure still passed. It guarded a deleted sentence, not the
     * behaviour — the exact "assert your assumptions against your assumptions"
     * failure CLAUDE.md rule 2 is about.
     *
     * So both directions are now asserted positively AND negatively, against
     * the real tool surface getTools() hands over. Inverting the branches fails
     * this test; deleting either branch fails it.
     */
    public function test_the_prompt_discloses_the_write_tools_exactly_when_they_are_offered(): void
    {
        $this->enable();

        $client = Client::factory()->create();
        $withClient = AssistantConversation::create([
            'user_id' => $this->user->id,
            'context_type' => 'client',
            'context_id' => $client->id,
        ]);
        $withoutClient = $this->conversation();

        // Anchor to reality: these are the two writers getTools() adds for a
        // client context. If that surface changes, this test should be revisited
        // rather than silently continuing to assert a stale pair.
        $writers = array_values(array_filter(
            array_column(AssistantToolDefinitions::getTools(true), 'name'),
            fn (string $n) => in_array($n, ['create_ticket', 'add_ticket_note'], true)
        ));
        sort($writers);
        $this->assertSame(['add_ticket_note', 'create_ticket'], $writers);

        $clientPrompt = $this->promptFor($withClient);
        $generalPrompt = $this->promptFor($withoutClient);

        // WITH a client: both writers named, and no read-only claim.
        foreach ($writers as $writer) {
            $this->assertStringContainsString(
                $writer,
                $clientPrompt,
                "the prompt must name {$writer} — it is handed to the model in client context"
            );
        }
        $this->assertStringNotContainsStringIgnoringCase(
            'read-only',
            $clientPrompt,
            'the prompt must not claim read-only while both writers are offered'
        );

        // WITHOUT a client: read-only is the truth, and the writers are absent.
        $this->assertStringContainsStringIgnoringCase(
            'read-only',
            $generalPrompt,
            'the no-client surface genuinely is read-only and should say so'
        );
        foreach ($writers as $writer) {
            $this->assertStringNotContainsString(
                $writer,
                $generalPrompt,
                "the prompt must not name {$writer} when it is not offered"
            );
        }
    }

    public function test_the_tool_definitions_docblock_does_not_claim_read_only_only(): void
    {
        // Same rot, aimed at the next reviewer rather than the model:
        // AssistantToolDefinitions' own header claimed "Read-only tools only
        // (v1)" in the very file that defines both writers.
        $source = (string) file_get_contents(app_path('Services/Assistant/AssistantToolDefinitions.php'));

        // psa-uw2o.2: without this, a rename makes strpos() return false, which
        // casts to 0, which makes substr() return '' — and an empty haystack
        // trivially "does not contain" the claim. The test would pass by
        // finding nothing, which is the repo's own never-fail-into-a-clean-
        // empty rule turned against its own guard.
        $pos = strpos($source, 'class AssistantToolDefinitions');
        $this->assertNotFalse($pos, 'class declaration not found — this guard would silently pass on nothing');

        $header = substr($source, 0, $pos);

        $this->assertStringNotContainsStringIgnoringCase(
            'read-only tools only',
            $header,
            'AssistantToolDefinitions defines create_ticket and add_ticket_note — its docblock must not claim read-only only'
        );
    }
}
