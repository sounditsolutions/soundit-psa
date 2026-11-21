<?php

namespace Tests\Unit\Tactical;

use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #8466: runScriptAsync() starts a script with POST agents/{id}/runscript/, the only method
 * Tactical's run_script view accepts (@api_view(["POST"])), and sends the args it is given
 * unchanged. TacticalClient builds its own Guzzle client, so the upstream is a Guzzle
 * MockHandler; Http::preventStrayRequests() guards everything else. Synthetic ids only (G-13).
 */
class TacticalClientRunScriptAsyncTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** @param array<int, Response> $queue */
    private function client(array $queue): TacticalClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new TacticalClient(new GuzzleClient(['base_uri' => 'https://tactical.example.test/api/', 'handler' => $stack]));
    }

    public function test_run_script_async_posts_to_the_runscript_endpoint_with_the_given_args(): void
    {
        $args = ['-OrgCode', '{{client.ControlDOrgCode}}'];
        $this->client([new Response(200, [], json_encode('ok'))])->runScriptAsync('SYNTH-AGENT-1', 77, $args, 300);

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://tactical.example.test/api/agents/SYNTH-AGENT-1/runscript/', (string) $request->getUri());
        $this->assertSame([
            'output' => 'forget',
            'script' => 77,
            'timeout' => 300,
            'args' => $args,
            'env_vars' => [],
            'run_as_user' => false,
        ], json_decode((string) $request->getBody(), true));
    }

    public function test_null_args_are_still_sent_as_an_empty_list(): void
    {
        $this->client([new Response(200, [], json_encode('ok'))])->runScriptAsync('SYNTH-AGENT-2', 78);

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame([], json_decode((string) $request->getBody(), true)['args']);
        $this->assertSame(120, json_decode((string) $request->getBody(), true)['timeout']);
    }

    /** What the base PUT met in production: Tactical answers 405 and the call throws. */
    public function test_a_405_answer_throws_a_tactical_client_exception(): void
    {
        $this->expectException(TacticalClientException::class);
        $this->client([new Response(405, [], json_encode(['detail' => 'Method "PUT" not allowed.']))])->runScriptAsync('SYNTH-AGENT-3', 79, ['{{client.ControlDOrgCode}}']);
    }
}
