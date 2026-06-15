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
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['stdout' => 'hello', 'retcode' => 0])),
        ]));
        $stack->push(Middleware::history($history));
        $client = new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]));

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
        $req = $history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/agents/AGENT-1/runscript/', $req->getUri()->getPath());
        $body = json_decode((string) $req->getBody(), true);
        $this->assertSame('wait', $body['output']);
        $this->assertSame(201, $body['script']);
        $this->assertSame(['-Foo', 'bar'], $body['args']);
        $this->assertSame(90, $body['timeout']);
    }

    public function test_execute_maps_alternate_output_keys(): void
    {
        // TRMM sometimes returns `output`/`return_code` — map them like the old controllers did.
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['output' => 'alt-out', 'return_code' => 3])),
        ]));
        $client = new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]));

        $params = $this->action->validateParams(['tactical_script_id' => 201, 'timeout' => 60]);
        $result = $this->action->execute($client, 'AGENT-1', $params);

        $this->assertSame('alt-out', $result->stdout);
        $this->assertSame(3, $result->retcode);
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
}
