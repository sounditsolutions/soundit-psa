<?php

namespace Tests\Feature\Tactical;

use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create as PromiseCreate;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Amendment C (P4): the cheap live reads get a short per-request timeout (~2-3s)
 * merged into the Guzzle call — mirroring NinjaClient::getDevice(timeout:). The
 * 30s singleton default is UNCHANGED so the action bus's NATS-blocking writes
 * are unaffected. These tests assert the option actually reaches the transfer
 * (so a refactor can't silently regress reads back to 30s).
 */
class TacticalClientTimeoutTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $capturedOptions = [];

    /**
     * Build a client whose handler records the transfer $options of each request.
     */
    private function capturingClient(Response $response): TacticalClient
    {
        $this->capturedOptions = [];

        $handler = function (RequestInterface $request, array $options) use ($response) {
            $this->capturedOptions[] = $options;

            return PromiseCreate::promiseFor($response);
        };

        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => HandlerStack::create($handler),
            'timeout' => 30,
            'allow_redirects' => false,
        ]);

        return new TacticalClient($http);
    }

    private function lastTimeout(): mixed
    {
        return $this->capturedOptions[array_key_last($this->capturedOptions)]['timeout'] ?? null;
    }

    public function test_get_applies_the_per_request_timeout_when_given(): void
    {
        $client = $this->capturingClient(new Response(200, [], json_encode([])));

        $client->get('agents/AGENT-1/', timeout: 3);

        $this->assertSame(3, $this->lastTimeout());
    }

    public function test_get_without_timeout_leaves_the_30s_default_intact(): void
    {
        $client = $this->capturingClient(new Response(200, [], json_encode([])));

        $client->get('agents/AGENT-1/');

        // No per-request override => Guzzle merges the client-level 30s default
        // onto the transfer. The action bus depends on this (NATS writes need the
        // full 30s); a read path must explicitly pass a short timeout to override.
        $this->assertSame(30, $this->lastTimeout());
    }

    public function test_get_agent_forwards_the_timeout(): void
    {
        $client = $this->capturingClient(new Response(200, [], json_encode(['agent_id' => 'a1'])));

        $client->getAgent('AGENT-1', timeout: 2);

        $this->assertSame(2, $this->lastTimeout());
    }

    public function test_get_agent_checks_forwards_the_timeout(): void
    {
        $client = $this->capturingClient(new Response(200, [], json_encode([])));

        $client->getAgentChecks('AGENT-1', timeout: 2);

        $this->assertSame(2, $this->lastTimeout());
    }

    public function test_get_software_forwards_the_timeout(): void
    {
        $client = $this->capturingClient(new Response(200, [], json_encode([])));

        $client->getSoftware('AGENT-1', timeout: 3);

        $this->assertSame(3, $this->lastTimeout());
    }

    public function test_get_patches_forwards_the_timeout(): void
    {
        $client = $this->capturingClient(new Response(200, [], json_encode([])));

        $client->getPatches('AGENT-1', timeout: 3);

        $this->assertSame(3, $this->lastTimeout());
    }
}
