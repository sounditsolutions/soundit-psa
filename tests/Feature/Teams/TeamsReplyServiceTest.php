<?php

namespace Tests\Feature\Teams;

use App\Enums\TicketStatus;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiResponse;
use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Teams\ResolvedSender;
use App\Services\Teams\TeamsBotClient;
use App\Services\Teams\TeamsReadOnlyToolset;
use App\Services\Teams\TeamsReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TeamsReplyService (E2a) — the read-only Teams chat brain.
 *
 * Every test drives the loop deterministically by mocking AiClient::runChatWithTools
 * and TeamsBotClient — no real HTTP. Because reply() is fail-soft (it catches every
 * \Throwable so a reply failure can never 500 the inbound webhook), assertions are
 * captured into outer variables inside andReturnUsing and checked AFTER reply()
 * returns — an assertion that fired inside the callback would be swallowed by the
 * fail-soft try/catch (AssertionFailedError extends Throwable).
 *
 * NOTE on argument capture: the service calls runChatWithTools with NAMED args, but
 * Mockery forwards them positionally in declaration order
 * (system, messages, tools, executor, maxRounds, maxTokenBudget, wallClockSeconds,
 * onToolCall, enableCaching). We only ever read the first four — which are always
 * explicitly passed — so capture is robust regardless of how the trailing/skipped
 * params are forwarded.
 */
class TeamsReplyServiceTest extends TestCase
{
    use RefreshDatabase;

    private const REFUSAL = ['error' => 'That tool is not available in chat (read-only).'];

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeUser(string $name): User
    {
        return User::factory()->create(['name' => $name]);
    }

    private function sender(User $user): ResolvedSender
    {
        return new ResolvedSender(
            user: $user,
            appId: '11111111-1111-1111-1111-111111111111',
            tenantId: '22222222-2222-2222-2222-222222222222',
            conversationId: 'a:conv-1',
            serviceUrl: 'https://smba.trafficmanager.net/amer/',
            aadObjectId: 'aad-'.$user->id,
        );
    }

    /** Mock AiClient (constructor bypassed by Mockery — no Guzzle is built). */
    private function aiMock(): AiClient
    {
        return $this->mock(AiClient::class);
    }

    /** Mock TeamsBotClient. sendTyping/sendMessage are ignored unless a test asserts. */
    private function clientMock(): TeamsBotClient
    {
        $client = $this->mock(TeamsBotClient::class);
        $client->shouldIgnoreMissing();

        return $client;
    }

    private function service(AiClient $ai, TeamsBotClient $client): TeamsReplyService
    {
        return new TeamsReplyService($ai, $client);
    }

    private function aiResponse(string $text): AiResponse
    {
        return new AiResponse(text: $text, inputTokens: 0, outputTokens: 0, stopReason: 'end_turn');
    }

    // ── 1. reply() sends the AI's text exactly once ──────────────────────────

    public function test_reply_sends_the_ai_text_via_send_message_once(): void
    {
        $sender = $this->sender($this->makeUser('Charlie Coutts'));

        $ai = $this->aiMock();
        $ai->shouldReceive('runChatWithTools')->once()
            ->andReturnUsing(fn ($system, $messages, $tools, $executor, ...$rest): AiResponse => $this->aiResponse('the reply'));

        $captured = null;
        $client = $this->clientMock();
        $client->shouldReceive('sendMessage')->once()
            ->andReturnUsing(function ($url, $conv, $text) use (&$captured): bool {
                $captured = $text;

                return true;
            });

        $this->service($ai, $client)->reply($sender, 'hello there', 'BlueTier IT');

        $this->assertSame('the reply', $captured, 'reply() must send the model text verbatim.');
    }

    // ── 2. Only read-only tools reach the loop ───────────────────────────────

    public function test_only_read_only_tools_reach_the_loop(): void
    {
        $sender = $this->sender($this->makeUser('Charlie Coutts'));

        $capturedTools = null;
        $ai = $this->aiMock();
        $ai->shouldReceive('runChatWithTools')->once()
            ->andReturnUsing(function ($system, $messages, $tools, $executor, ...$rest) use (&$capturedTools): AiResponse {
                $capturedTools = $tools;

                return $this->aiResponse('ok');
            });

        $this->service($ai, $this->clientMock())->reply($sender, 'hi', 'BlueTier IT');

        $this->assertNotNull($capturedTools, 'runChatWithTools must have been called with a tool list.');
        $names = array_column($capturedTools, 'name');

        $this->assertNotContains('create_ticket', $names, 'No mutating tool may reach the chat loop.');
        $this->assertNotContains('add_ticket_note', $names, 'No mutating tool may reach the chat loop.');
        $this->assertNotContains('propose_close', $names, 'Held close proposals are not part of Teams read-only chat.');
        $this->assertContains('wiki_search', $names, 'At least one read tool must be present.');

        // Prove the filter genuinely strips mutators: the CLIENT-scoped raw surface
        // DOES contain them, and definitions() removes them.
        $rawClient = array_column(AssistantToolDefinitions::getTools(true), 'name');
        $this->assertContains('create_ticket', $rawClient, 'Sanity: the raw client surface still has the mutators.');
        $this->assertNotContains('propose_close', array_column(AssistantToolDefinitions::getTools(false), 'name'));

        // NOTE: this used to read TeamsReadOnlyToolset::definitions(true), which
        // took no parameter — PHP silently discards extra arguments to a userland
        // function, so the `true` never meant anything and this was not the
        // "client-scoped" variant it appeared to be. The published surface is
        // whatever forTurn() resolved, merged over both scopes; ask it directly.
        $filtered = array_column(TeamsReadOnlyToolset::forTurn(null)->tools(), 'name');
        $this->assertNotContains('create_ticket', $filtered);
        $this->assertNotContains('add_ticket_note', $filtered);
        $this->assertNotContains('propose_close', $filtered);
    }

    // ── 3. The executor refuses mutating tools without touching the inner one ─

    public function test_executor_refuses_mutating_tools(): void
    {
        $sender = $this->sender($this->makeUser('Charlie Coutts'));

        $capturedExecutor = null;
        $ai = $this->aiMock();
        $ai->shouldReceive('runChatWithTools')->once()
            ->andReturnUsing(function ($system, $messages, $tools, $executor, ...$rest) use (&$capturedExecutor): AiResponse {
                $capturedExecutor = $executor;

                return $this->aiResponse('ok');
            });

        $this->service($ai, $this->clientMock())->reply($sender, 'hi', 'BlueTier IT');

        $this->assertIsCallable($capturedExecutor);
        $this->assertSame(self::REFUSAL, $capturedExecutor('create_ticket', ['subject' => 'x', 'description' => 'y']));
        $this->assertSame(self::REFUSAL, $capturedExecutor('add_ticket_note', ['ticket_id' => 1, 'body' => 'z']));
    }

    public function test_executor_refuses_propose_close_without_creating_a_held_run(): void
    {
        $sender = $this->sender($this->makeUser('Charlie Coutts'));
        $ticket = Ticket::factory()->create(['status' => TicketStatus::PendingClient]);

        $capturedExecutor = null;
        $ai = $this->aiMock();
        $ai->shouldReceive('runChatWithTools')->once()
            ->andReturnUsing(function ($system, $messages, $tools, $executor, ...$rest) use (&$capturedExecutor): AiResponse {
                $capturedExecutor = $executor;

                return $this->aiResponse('ok');
            });

        $this->service($ai, $this->clientMock())->reply($sender, 'hi', 'BlueTier IT');

        $this->assertIsCallable($capturedExecutor);
        $this->assertSame(self::REFUSAL, $capturedExecutor('propose_close', [
            'ticket_id' => $ticket->id,
            'reason' => 'Client has not replied for weeks.',
            'confidence' => 0.96,
        ]));
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'propose_close')
            ->count());
    }

    // ── 4. A typing indicator is shown ───────────────────────────────────────

    public function test_a_typing_indicator_is_shown(): void
    {
        $sender = $this->sender($this->makeUser('Charlie Coutts'));

        $ai = $this->aiMock();
        $ai->shouldReceive('runChatWithTools')->once()
            ->andReturnUsing(fn ($system, $messages, $tools, $executor, ...$rest): AiResponse => $this->aiResponse('ok'));

        $client = $this->clientMock();
        $client->shouldReceive('sendTyping')->atLeast()->once();

        $this->service($ai, $client)->reply($sender, 'hi', 'BlueTier IT');

        // Mockery verifies the atLeast(once) expectation on teardown.
        $this->assertTrue(true);
    }

    // ── 5. Persona: the configured AI-actor name is in the system prompt ──────

    public function test_persona_name_is_in_the_system_prompt(): void
    {
        $actor = $this->makeUser('Sound Assistant');
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $sender = $this->sender($this->makeUser('Charlie Coutts'));

        $capturedSystem = null;
        $ai = $this->aiMock();
        $ai->shouldReceive('runChatWithTools')->once()
            ->andReturnUsing(function ($system, ...$rest) use (&$capturedSystem): AiResponse {
                $capturedSystem = $system;

                return $this->aiResponse('ok');
            });

        $this->service($ai, $this->clientMock())->reply($sender, 'hi', 'BlueTier IT');

        $this->assertNotNull($capturedSystem);
        $this->assertStringContainsString('Sound Assistant', $capturedSystem, 'Persona name must appear.');
        $this->assertStringContainsString('BlueTier IT', $capturedSystem, 'MSP name must appear.');
        $this->assertStringContainsString('read-only', $capturedSystem, 'The read-only constraint must be stated.');
    }

    public function test_banter_setting_adds_personality_to_the_persona(): void
    {
        $sender = $this->sender($this->makeUser('Charlie Coutts'));

        $captured = null;
        $ai = $this->aiMock();
        $ai->shouldReceive('runChatWithTools')->twice()
            ->andReturnUsing(function ($system, ...$rest) use (&$captured): AiResponse {
                $captured = $system;

                return $this->aiResponse('ok');
            });
        $client = $this->clientMock();

        // Banter on (default) → a personality clause is present.
        $this->service($ai, $client)->reply($sender, 'hi', 'BlueTier IT');
        $this->assertStringContainsString('personality', (string) $captured);

        // Banter off → the clause is gone.
        Setting::setValue('teams_ambient_banter', '0');
        $this->service($ai, $client)->reply($sender, 'hi', 'BlueTier IT');
        $this->assertStringNotContainsString('personality', (string) $captured);
    }

    // ── 6. Runs as the resolved user, never a system user ────────────────────

    public function test_runs_as_the_resolved_user_not_the_system_user(): void
    {
        // AI actor is a DIFFERENT user than the sender, so we can tell them apart.
        $actor = $this->makeUser('AI Bot');
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $senderUser = $this->makeUser('Charlie Coutts');
        $sender = $this->sender($senderUser);

        $capturedExecutor = null;
        $ai = $this->aiMock();
        $ai->shouldReceive('runChatWithTools')->once()
            ->andReturnUsing(function ($system, $messages, $tools, $executor, ...$rest) use (&$capturedExecutor): AiResponse {
                $capturedExecutor = $executor;

                return $this->aiResponse('ok');
            });

        $this->service($ai, $this->clientMock())->reply($sender, 'please help', 'BlueTier IT');

        // Transcript proxy: the resolved user (not the actor) is the recorded speaker.
        $conv = AssistantConversation::where('external_key', 'teams:a:conv-1')->first();
        $this->assertNotNull($conv);
        $this->assertSame($actor->id, $conv->user_id, 'The AI actor owns the transcript row.');

        $human = $conv->messages()->where('role', 'user')->first();
        $this->assertNotNull($human);
        $this->assertSame('Charlie Coutts: please help', $human->content);
        $this->assertStringNotContainsString('AI Bot', $human->content);

        // Executor identity: scoped to the resolved user, not a null/system user.
        // A null-user executor short-circuits list_my_tickets with this exact error
        // BEFORE querying; a resolved-user executor reaches the query instead (which
        // on sqlite throws on the MySQL-only FIELD() ordering). Either path proves a
        // real user id is bound.
        $this->assertIsCallable($capturedExecutor);
        $result = null;
        $threw = false;
        try {
            $result = $capturedExecutor('list_my_tickets', []);
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue(
            $threw || $result !== ['error' => 'No user context'],
            'The executor must run as the resolved user, never a null/system user.',
        );
    }

    // ── 7. Transcript: one conversation per chat, appended across turns ───────

    public function test_transcript_is_persisted_and_appended_across_turns(): void
    {
        $sender = $this->sender($this->makeUser('Charlie Coutts'));

        $capturedMessages = null;
        $call = 0;
        $ai = $this->aiMock();
        $ai->shouldReceive('runChatWithTools')->twice()
            ->andReturnUsing(function ($system, $messages, $tools, $executor, ...$rest) use (&$capturedMessages, &$call): AiResponse {
                $capturedMessages = $messages;
                $call++;

                return $this->aiResponse($call === 1 ? 'first reply' : 'second reply');
            });

        $svc = $this->service($ai, $this->clientMock());

        // First turn → fresh conversation with a user turn + an assistant turn.
        $svc->reply($sender, 'hello', 'BlueTier IT');

        $conv = AssistantConversation::where('context_type', 'teams_chat')
            ->where('external_key', 'teams:a:conv-1')
            ->first();
        $this->assertNotNull($conv, 'A teams_chat conversation must be created.');

        $msgs = $conv->messages()->get();
        $this->assertCount(2, $msgs);
        $this->assertSame('user', $msgs[0]->role);
        $this->assertSame('Charlie Coutts: hello', $msgs[0]->content);
        $this->assertSame('assistant', $msgs[1]->role);
        $this->assertSame('first reply', $msgs[1]->content);

        // Second turn → SAME conversation, appended (no new conversation row).
        $svc->reply($sender, 'again', 'BlueTier IT');

        $this->assertSame(
            1,
            AssistantConversation::where('context_type', 'teams_chat')->where('external_key', 'teams:a:conv-1')->count(),
            'A second turn must reuse the same conversation.',
        );
        $this->assertSame(4, $conv->fresh()->messages()->count());

        // The history fed to the SECOND loop call includes the earlier turns.
        $contents = array_column($capturedMessages ?? [], 'content');
        $this->assertContains('Charlie Coutts: hello', $contents, 'Earlier human turn must be in history.');
        $this->assertContains('first reply', $contents, 'Earlier AI turn must be in history.');
        $this->assertContains('Charlie Coutts: again', $contents, 'The just-said human turn must be in history.');
    }

    // ── 8. Fail-soft: a loop failure never throws and persists no AI turn ─────

    public function test_reply_is_fail_soft_when_the_loop_throws(): void
    {
        $sender = $this->sender($this->makeUser('Charlie Coutts'));

        $ai = $this->aiMock();
        $ai->shouldReceive('runChatWithTools')->once()
            ->andThrow(new \RuntimeException('model hiccup'));

        // Must not throw — a reply failure may never 500 the inbound webhook.
        $this->service($ai, $this->clientMock())->reply($sender, 'hello', 'BlueTier IT');

        // No assistant turn was persisted (the loop threw before the reply was built).
        $this->assertSame(0, AssistantMessage::where('role', 'assistant')->count());
    }
}
