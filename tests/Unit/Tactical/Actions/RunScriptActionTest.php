<?php

namespace Tests\Unit\Tactical\Actions;

use App\Services\Tactical\Actions\InvalidActionParams;
use App\Services\Tactical\Actions\RunScriptAction;
use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Task 6 (P2): run-script as a bus action. Argv tokenization must respect
 * quotes (NOT explode(' ')) — amendments §11.1 / m5. The action is
 * side-effect-free w.r.t. PSA models (m5): it only talks to TacticalClient.
 */
class RunScriptActionTest extends TestCase
{
    private RunScriptAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new RunScriptAction;
    }

    private function client(Response $runResponse, ?array $scriptResults = null, ?array &$history = null): TacticalClient
    {
        $afterHistory = $scriptResults === null
            ? [['id' => 11, 'script' => 201]]
            : [['id' => 11, 'script' => 201, 'script_results' => $scriptResults]];

        $queue = [
            new Response(200, [], json_encode([['id' => 10, 'script' => 201, 'script_results' => ['retcode' => 0]]])),
            $runResponse,
            new Response(200, [], json_encode($afterHistory)),
        ];
        if ($scriptResults === null) {
            $queue[] = new Response(200, [], json_encode($afterHistory));
            $queue[] = new Response(200, [], json_encode($afterHistory));
            $queue[] = new Response(200, [], json_encode($afterHistory));
        }

        $stack = HandlerStack::create(new MockHandler($queue));
        if ($history !== null) {
            $stack->push(Middleware::history($history));
        }

        return new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]));
    }

    public function test_key_and_non_destructive(): void
    {
        $this->assertSame('tactical.run_script', $this->action->key());
        $this->assertFalse($this->action->isDestructive());
    }

    public function test_validate_requires_a_script_id(): void
    {
        $this->expectException(InvalidActionParams::class);
        $this->action->validateParams(['timeout' => 60]);
    }

    public function test_validate_bounds_the_timeout(): void
    {
        $this->expectException(InvalidActionParams::class);
        $this->action->validateParams(['tactical_script_id' => 201, 'timeout' => 99999]);
    }

    public function test_validate_normalizes_a_simple_arg_string(): void
    {
        $params = $this->action->validateParams([
            'tactical_script_id' => 201,
            'args' => '-Foo bar -Baz',
            'timeout' => 90,
        ]);

        $this->assertSame(201, $params['tactical_script_id']);
        $this->assertSame(90, $params['timeout']);
        $this->assertSame(['-Foo', 'bar', '-Baz'], $params['args']);
    }

    public function test_validate_preserves_quoted_args_not_explode_space(): void
    {
        // The whole point: "C:\Program Files\app" stays ONE token.
        $params = $this->action->validateParams([
            'tactical_script_id' => 201,
            'args' => '-Path "C:\\Program Files\\app" -Mode fast',
            'timeout' => 60,
        ]);

        $this->assertSame(['-Path', 'C:\\Program Files\\app', '-Mode', 'fast'], $params['args']);
    }

    public function test_validate_handles_single_quotes(): void
    {
        $params = $this->action->validateParams([
            'tactical_script_id' => 201,
            'args' => "-Name 'New Folder' -Force",
            'timeout' => 60,
        ]);

        $this->assertSame(['-Name', 'New Folder', '-Force'], $params['args']);
    }

    public function test_validate_empty_args_is_empty_list(): void
    {
        $params = $this->action->validateParams([
            'tactical_script_id' => 201,
            'args' => '',
            'timeout' => 60,
        ]);

        $this->assertSame([], $params['args']);

        $params2 = $this->action->validateParams(['tactical_script_id' => 201, 'timeout' => 60]);
        $this->assertSame([], $params2['args']);
    }

    public function test_execute_posts_the_mapped_runscript_body(): void
    {
        $history = [];
        $client = $this->client(new Response(200, [], json_encode('hello')), ['stdout' => 'hello', 'retcode' => 0], $history);

        $params = $this->action->validateParams([
            'tactical_script_id' => 201,
            'args' => '-Foo bar',
            'timeout' => 90,
        ]);

        $result = $this->action->execute($client, 'AGENT-1', $params);

        $this->assertTrue($result->isOk());
        $this->assertSame('hello', $result->stdout);
        $this->assertSame(0, $result->retcode);

        /** @var RequestInterface $req */
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame('/agents/AGENT-1/history/', $history[0]['request']->getUri()->getPath());

        $req = $history[1]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/agents/AGENT-1/runscript/', $req->getUri()->getPath());
        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('wait', $body['output']);
        $this->assertSame(201, $body['script']);
        $this->assertSame(['-Foo', 'bar'], $body['args']);
        $this->assertSame(90, $body['timeout']);
        $this->assertFalse($body['run_as_user']);
        $this->assertSame([], $body['env_vars']);

        $this->assertSame('GET', $history[2]['request']->getMethod());
        $this->assertSame('/agents/AGENT-1/history/', $history[2]['request']->getUri()->getPath());
    }

    public function test_execute_maps_alternate_output_keys(): void
    {
        // TRMM sometimes returns `output`/`return_code` — map them like the old controllers did.
        $client = $this->client(new Response(200, [], json_encode(['output' => 'legacy alt'])), ['output' => 'alt-out', 'return_code' => 3]);

        $params = $this->action->validateParams(['tactical_script_id' => 201, 'timeout' => 60]);
        $result = $this->action->execute($client, 'AGENT-1', $params);

        $this->assertSame('alt-out', $result->stdout);
        $this->assertSame(3, $result->retcode);
    }

    public function test_execute_maps_nested_script_results_payload(): void
    {
        // The Tactical agent records wait-run results under `script_results` on
        // the history callback; preserve its real retcode if that shape is
        // proxied back by a Tactical build.
        $client = $this->client(new Response(200, [], json_encode(['status' => 'queued'])), [
            'script_results' => [
                'stdout' => 'nested-out',
                'stderr' => 'nested-error',
                'retcode' => 7,
                'execution_time' => 1.234,
            ],
        ]);

        $params = $this->action->validateParams(['tactical_script_id' => 201, 'timeout' => 60]);
        $result = $this->action->execute($client, 'AGENT-1', $params);

        $this->assertSame('nested-out', $result->stdout);
        $this->assertSame('nested-error', $result->stderr);
        $this->assertSame(7, $result->retcode);
    }

    public function test_execute_carries_stderr_from_the_response(): void
    {
        $client = $this->client(new Response(200, [], json_encode('outa warning occurred')), ['stdout' => 'out', 'stderr' => 'a warning occurred', 'retcode' => 1]);

        $params = $this->action->validateParams(['tactical_script_id' => 201, 'timeout' => 60]);
        $result = $this->action->execute($client, 'AGENT-1', $params);

        $this->assertSame('out', $result->stdout);
        $this->assertSame('a warning occurred', $result->stderr);
        $this->assertSame(1, $result->retcode);
    }

    public function test_summary_is_redacted(): void
    {
        $params = $this->action->validateParams([
            'tactical_script_id' => 201,
            'args' => '-Password supersecretvalue123',
            'timeout' => 60,
        ]);

        $summary = $this->action->summary($params);

        $this->assertStringNotContainsString('supersecretvalue123', $summary);
    }

    public function test_shell_metacharacters_stay_inside_one_quoted_token(): void
    {
        // Argv safety (§11.1): the tokenizer must NOT split on whitespace inside a
        // quoted value, so an injection-style arg stays a SINGLE inert argv
        // element (it is sent as a discrete arg, never shell-concatenated).
        $params = $this->action->validateParams([
            'tactical_script_id' => 201,
            'args' => '-Cmd "a; rm -rf / && curl evil.example" -Flag',
            'timeout' => 60,
        ]);

        $this->assertSame(['-Cmd', 'a; rm -rf / && curl evil.example', '-Flag'], $params['args']);
    }

    public function test_execute_normalizes_a_scalar_runscript_response(): void
    {
        // Regression (mirrors the live reboot fix): if runscript returns a JSON
        // scalar instead of an object, runScript() (typed `mixed`) returns a
        // non-array and execute() must normalize it to an ok result — NOT raise
        // an uncaught TypeError that bypasses the bus's exception catch.
        $client = $this->client(new Response(200, [], json_encode('ok'))); // body is the JSON string "ok"

        $params = $this->action->validateParams(['tactical_script_id' => 201, 'timeout' => 60]);
        $result = $this->action->execute($client, 'AGENT-1', $params);

        $this->assertTrue($result->isOk());
        $this->assertSame('ok', $result->stdout);
        $this->assertNull($result->retcode);
    }
}
