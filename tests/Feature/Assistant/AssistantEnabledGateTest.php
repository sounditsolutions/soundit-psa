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
 * The DEFAULT was a separate product question (psa-98dq), and Charlie ruled it
 * on 2026-07-21: the Assistant DEFAULTS OFF — a present Anthropic key confers
 * no capability on its own. The gate honours whatever isEnabled() returns, so
 * that ruling was a one-line change to an untouched file rather than a rework.
 *
 * Every test here still sets `assistant_enabled` EXPLICITLY, except the one
 * that asserts the default itself. That is deliberate: a test asserting a gate
 * should not be silently coupled to which way the default happens to point.
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
    /**
     * psa-uw2o.8 caught that this was asserted on ONE route. The sibling test
     * covering all six passes only because Laravel's test client defaults to
     * `Accept: text/html` — so it exercised the BROWSER path six times and the
     * JSON path, which is the one the live UI actually receives, exactly once.
     * It looked comprehensive and was not. All six now assert the JSON shape.
     */
    public function test_every_endpoint_tells_a_json_client_why_it_was_refused(): void
    {
        $this->disable();

        $conversation = $this->conversation();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        foreach ($this->routes($conversation, $ticket) as $name => [$verb, $uri, $payload]) {
            $response = $this->actingAs($this->user)
                ->withHeaders(['Accept' => 'application/json'])
                ->{$verb}($uri, $payload);

            $body = (array) json_decode($response->getContent(), true);

            $this->assertArrayHasKey(
                'error',
                $body,
                "{$name}: the JS handlers read d.error — a refusal without that key surfaces as ".
                '"Request failed" or, on the load paths, silence'
            );
            $this->assertStringContainsStringIgnoringCase('disabled', (string) $body['error'], $name);
        }
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

    /**
     * psa-98dq, ruled by Charlie 2026-07-21: the Assistant DEFAULTS OFF.
     *
     * An Anthropic key alone must confer no capability. Previously an absent
     * `assistant_enabled` read as enabled, so pasting a key silently activated
     * a write-capable staff assistant — the only AI cluster here that did that,
     * and the only one that granted writes by doing so.
     */
    public function test_a_present_anthropic_key_does_not_by_itself_enable_the_assistant(): void
    {
        // setUp() has already configured the provider and key; deliberately
        // never touch assistant_enabled, so this asserts the DEFAULT.
        $this->assertNull(Setting::getValue('assistant_enabled'), 'precondition: the setting must be absent');

        $this->assertFalse(
            AssistantConfig::isEnabled(),
            'a configured Anthropic key must not activate the Assistant on its own'
        );

        $this->actingAs($this->user)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('/assistant/general')
            ->assertStatus(403);
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
     * psa-uw2o.5/.6/.7 all independently killed the first version of this
     * guard. It was a verb-PREFIX denylist, and every tool set in this repo is
     * LANE-prefixed (wiki_, dns_, cipp_, tactical_), so no lane tool could ever
     * match it. Reviewers proved it by adding a real writer — wiki_create_page,
     * from the exact lane the docblock named as the risk — and watching the
     * suite stay green while the prompt still claimed read-only.
     *
     * A denylist fails OPEN: it only catches what its author thought of. These
     * are ALLOWLISTS, so ANY change to either surface — a writer, a reader, a
     * rename — goes red until a human looks at it and updates the list
     * deliberately. That is the only shape that can catch a tool nobody
     * anticipated, which is the entire point.
     */
    private const EXPECTED_NO_CLIENT_TOOLS = [
        'dns_email_health',
        'dns_lookup',
        'find_clients',
        'get_queue_stats',
        'get_ticket_calls',
        'get_ticket_detail',
        'list_my_tickets',
        'list_open_tickets',
        'search_all_tickets',
        'wiki_get_page',
        'wiki_list_pages',
        'wiki_search',
    ];

    public function test_the_no_client_tool_surface_is_exactly_the_expected_read_only_set(): void
    {
        $names = array_column(AssistantToolDefinitions::getTools(false), 'name');
        sort($names);

        $expected = self::EXPECTED_NO_CLIENT_TOOLS;
        sort($expected);

        $this->assertSame(
            $expected,
            $names,
            "The no-client tool surface changed. The system prompt tells the model this surface is READ-ONLY.\n".
            "If you added a read tool, add it to EXPECTED_NO_CLIENT_TOOLS.\n".
            'If you added a tool that WRITES, the prompt is now lying and must be fixed too.'
        );
    }

    /**
     * The client surface's writers must be exactly WRITE_TOOLS — asserted in
     * BOTH directions.
     *
     * psa-uw2o.6 showed the previous anchor could detect a writer being
     * REMOVED or renamed but was structurally blind to one being ADDED: adding
     * close_ticket to psaTools() left the suite green while the prompt still
     * said "Two of your tools WRITE". Since the prompt is now generated from
     * WRITE_TOOLS, an added writer that IS declared self-discloses; this test
     * catches one that is NOT declared.
     */
    public function test_the_client_surface_offers_exactly_the_declared_write_tools(): void
    {
        $method = new \ReflectionMethod(AssistantToolDefinitions::class, 'psaTools');
        $method->setAccessible(true);
        $names = array_column($method->invoke(null), 'name');

        $declared = AssistantToolDefinitions::WRITE_TOOLS;

        // Every declared writer must actually be offered.
        foreach ($declared as $writer) {
            $this->assertContains($writer, $names, "WRITE_TOOLS declares '{$writer}' but psaTools() does not offer it");
        }

        // And nothing write-shaped may be offered without being declared.
        $expectedPsaTools = [
            'add_ticket_note',
            'create_ticket',
            'find_assets',
            'find_persons',
            'get_asset',
            'get_client',
            'get_person',
            'get_ticket_notes',
            'search_tickets',
        ];
        sort($names);
        sort($expectedPsaTools);

        $this->assertSame(
            $expectedPsaTools,
            $names,
            'psaTools() changed. If the new tool WRITES, add it to AssistantToolDefinitions::WRITE_TOOLS '.
            "so the system prompt discloses it to the model.\nThen add it here."
        );
    }

    /**
     * psa-uw2o.6: nothing proved buildSystemPrompt actually OBEYS its
     * $hasClient argument — the test helper re-derived it the same way correct
     * code does, so a mutant ignoring the parameter survived. Drive both values
     * against the SAME conversation so only the argument differs.
     */
    public function test_the_prompt_obeys_its_has_client_argument(): void
    {
        $this->enable();

        $conversation = $this->conversation();

        $method = new \ReflectionMethod(AssistantService::class, 'buildSystemPrompt');
        $method->setAccessible(true);
        $service = new AssistantService;

        $asClient = (string) $method->invoke($service, $conversation, true);
        $asGeneral = (string) $method->invoke($service, $conversation, false);

        $this->assertStringContainsString('create_ticket', $asClient, 'hasClient=true must disclose the writers');
        $this->assertStringNotContainsString('create_ticket', $asGeneral, 'hasClient=false must not');
        $this->assertNotSame($asClient, $asGeneral, 'the argument must change the prompt');
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
