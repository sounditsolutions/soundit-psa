<?php

namespace Tests\Unit\Mesh;

use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MeshWriteClientDeliverTest extends TestCase
{
    private const QUEUE = '11111111-2222-4333-8444-555555555555';

    private array $history = [];

    private function client(int $status = 200): MeshWriteClient
    {
        Http::fake([]);
        Http::preventStrayRequests();
        $stack = HandlerStack::create(new MockHandler([new Response($status, [], '{}')]));
        $stack->push(Middleware::history($this->history));

        return new MeshWriteClient(['api_key' => 'synthetic'], new Client([
            'base_uri' => 'https://mesh.example.test/', 'handler' => $stack,
        ]));
    }

    public function test_exact_deliver_body_and_only_one_message_and_action(): void
    {
        $this->client()->deliver(self::QUEUE, '202653', 'Mesh Unified', ['recipient@example.test']);
        $this->assertCount(1, $this->history);
        foreach ($this->history as $entry) {
            $request = $entry['request'];
            $this->assertSame('PATCH', $request->getMethod());
            $this->assertSame('/api/message/', $request->getUri()->getPath());
            $this->assertSame('{"action":"deliver","messages":[{"queue_id":"'.self::QUEUE.'","yearweek":"202653","service":"Mesh Unified","recipient":["recipient@example.test"]}]}', (string) $request->getBody());
            $this->assertSame('deliver', json_decode((string) $request->getBody(), true)['action']);
        }
    }

    public function test_invalid_inputs_send_nothing(): void
    {
        $client = $this->client();
        foreach ([
            ['not-uuid', '202653', 'Mesh Unified', ['a@example.test']],
            [self::QUEUE, '20265', 'Mesh Unified', ['a@example.test']],
            [self::QUEUE, '202653', ' ', ['a@example.test']],
            [self::QUEUE, '202653', 'Mesh Unified', []],
            [self::QUEUE, '202653', 'Mesh Unified', ['bad']],
            [self::QUEUE, '202653', 'Mesh Unified', [42]],
            [self::QUEUE, "202653\n", 'Mesh Unified', ['a@example.test']],
        ] as $args) {
            try {
                $client->deliver(...$args);
                $this->fail('Invalid input accepted');
            } catch (MeshClientException $e) {
                $this->assertTrue($e->nothingWasSent());
            }
        }
        $this->assertSame([], $this->history);
    }

    public function test_forbidden_is_never_retried(): void
    {
        try {
            $this->client(403)->deliver(self::QUEUE, '202653', 'Mesh Unified', ['a@example.test']);
            $this->fail('403 accepted');
        } catch (MeshClientException $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertCount(1, $this->history);
    }
}
