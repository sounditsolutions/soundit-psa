<?php

namespace Tests\Feature\Ai;

use App\Models\Setting;
use App\Services\Ai\AiClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-ejzjd — a tool name the model was never OFFERED must never be DISPATCHED.
 *
 * THE DEFECT THIS PINS. AiClient::executeToolLoop() invoked the executor by name
 * without ever checking that the name appeared in the $tools schema it had just sent
 * to Anthropic. Publication and dispatch were two independent lists, so a surface
 * that hardened itself by FILTERING ITS SCHEMA was not hardened at all. The same
 * defect was found and fixed per-surface five times (Teams/psa-uw2o,
 * Technician/psa-hbbuq, triage/psa-hryjm, staff MCP/psa-vydpz, Chet) before being
 * closed here, once, at the single seam every one of those lanes passes through.
 *
 * WHY THE ASSERTION IS "NEVER REACHED" AND NOT "RETURNED AN ERROR". An executor that
 * happens to answer {"error": "Unknown tool"} would satisfy a weaker assertion while
 * having fully run — and running is precisely the thing under test, because these
 * executors reach live client data and, on the staff Assistant, four write arms
 * (psa-o8w6t). So the executor here is a SPY that records every invocation, and the
 * assertion is on the invocation log. A refusal that runs the tool is not a refusal.
 *
 * FAIL-CLOSED, NOT FAIL-HARD. The refusal is returned to the model as a tool_result
 * so the loop continues and the model can correct itself; a throw would turn a
 * hallucinated name into a failed turn.
 *
 * THE MIRROR IS TESTED TOO. Guardrail 3 of the manager's authorization: silently
 * removing a legitimate capability is a real regression that a refusal-only test
 * cannot catch. So every refusal test here has a published-name twin asserting the
 * executor IS reached.
 */
class AiClientSchemaEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** Tool names recorded in the order the executor was actually invoked. */
    private array $invoked = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');

        $this->invoked = [];
    }

    // ── The defect ───────────────────────────────────────────────────────────

    /**
     * The mutation that matters: the model names a tool that was never published,
     * and the executor must not run.
     */
    public function test_an_unpublished_name_never_reaches_the_executor(): void
    {
        $ai = $this->aiClientReturning([
            $this->toolUse('list_email_items', ['limit' => 50]),
            $this->finalText('done'),
        ]);

        $ai->runChatWithTools(
            system: 'sys',
            messages: [['role' => 'user', 'content' => 'hi']],
            tools: [$this->schema('search_tickets')],
            executor: $this->recordingExecutor(),
        );

        $this->assertNotContains(
            'list_email_items',
            $this->invoked,
            'An unpublished tool name REACHED the executor. Filtering the schema is not a boundary '.
            'unless dispatch is filtered too — this is the whole defect (psa-ejzjd).',
        );
        $this->assertSame([], $this->invoked, 'The executor must not run at all for an unpublished name.');
    }

    /**
     * The mirror (guardrail 3). A published name must still be dispatched — an
     * over-block is as much a bug as an under-block, and only this direction catches it.
     */
    public function test_a_published_name_is_still_dispatched(): void
    {
        $ai = $this->aiClientReturning([
            $this->toolUse('search_tickets', ['query' => 'vpn']),
            $this->finalText('done'),
        ]);

        $ai->runChatWithTools(
            system: 'sys',
            messages: [['role' => 'user', 'content' => 'hi']],
            tools: [$this->schema('search_tickets')],
            executor: $this->recordingExecutor(),
        );

        $this->assertSame(
            ['search_tickets'],
            $this->invoked,
            'A published tool must still run. Refusing it would silently remove working capability.',
        );
    }

    /**
     * Both public entry points share the private loop, so both must enforce.
     * runToolLoop() backs the triage and Technician lanes; runChatWithTools() backs
     * Teams, the staff Assistant and the client-facing portal chatbot.
     */
    public function test_enforcement_covers_run_tool_loop_as_well(): void
    {
        $ai = $this->aiClientReturning([
            $this->toolUse('get_client_security_posture'),
            $this->finalText('done'),
        ]);

        $ai->runToolLoop(
            system: 'sys',
            userMessage: 'hi',
            tools: [$this->schema('search_tickets')],
            executor: $this->recordingExecutor(),
        );

        $this->assertSame(
            [],
            $this->invoked,
            'runToolLoop() must enforce too — it is the triage lane, where the proven live '.
            'exposure was (psa-hryjm).',
        );
    }

    /**
     * Fail-closed, not fail-hard: the turn survives, the model is told, and a
     * legitimate follow-up call in the SAME turn still works.
     */
    public function test_the_refusal_is_reported_to_the_model_and_the_loop_continues(): void
    {
        $ai = $this->aiClientReturning([
            $this->toolUse('propose_close'),
            $this->toolUse('search_tickets'),
            $this->finalText('recovered'),
        ]);

        $response = $ai->runChatWithTools(
            system: 'sys',
            messages: [['role' => 'user', 'content' => 'hi']],
            tools: [$this->schema('search_tickets')],
            executor: $this->recordingExecutor(),
        );

        $this->assertSame(
            ['search_tickets'],
            $this->invoked,
            'The refused name must not run, but the loop must continue so the model can correct itself.',
        );
        $this->assertStringContainsString(
            'recovered',
            $response->text,
            'A refusal must not abort the turn — that would turn a hallucinated name into a failed turn.',
        );
    }

    /**
     * An empty schema means nothing is callable. Guards against a "no tools declared
     * means unrestricted" reading, which is how a fail-closed check rots into fail-open.
     */
    public function test_an_empty_schema_makes_every_name_unrunnable(): void
    {
        $ai = $this->aiClientReturning([
            $this->toolUse('search_tickets'),
            $this->finalText('done'),
        ]);

        $ai->runChatWithTools(
            system: 'sys',
            messages: [['role' => 'user', 'content' => 'hi']],
            tools: [],
            executor: $this->recordingExecutor(),
        );

        $this->assertSame([], $this->invoked, 'With nothing published, nothing may be dispatched.');
    }

    // ── Harness ──────────────────────────────────────────────────────────────

    /**
     * The executor under test: records the call, then returns a value. Recording is
     * the point — see the class docblock on why "returned an error" is not enough.
     */
    private function recordingExecutor(): callable
    {
        return function (string $name, array $input): array {
            $this->invoked[] = $name;

            return ['ok' => true];
        };
    }

    /** @param  array<int, Response>  $responses */
    private function aiClientReturning(array $responses): AiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));

        return new AiClient(http: new GuzzleClient(['handler' => $stack]));
    }

    private function schema(string $name): array
    {
        return [
            'name' => $name,
            'description' => 'test tool',
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
        ];
    }

    private function toolUse(string $name, array $input = []): Response
    {
        return $this->anthropic([
            ['type' => 'tool_use', 'id' => 'toolu_'.$name, 'name' => $name, 'input' => $input],
        ]);
    }

    private function finalText(string $text): Response
    {
        return $this->anthropic([['type' => 'text', 'text' => $text]]);
    }

    /** @param  array<int, array<string, mixed>>  $content */
    private function anthropic(array $content): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'content' => $content,
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]));
    }
}
