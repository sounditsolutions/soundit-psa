<?php

namespace Tests\Feature\Tactical;

use App\Models\Setting;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * psa-rkf6: request-time peer-IP pinning on the Tactical outbound client closes
 * the DNS-rebinding TOCTOU left by the save-time SafeUrlInspector check. Before
 * each request the client resolves the target host, validates EVERY resolved
 * address with the shared SafeUrlInspector::ipIsSafe() checker, and pins the
 * connection to the validated address via CURLOPT_RESOLVE — so a host that
 * passed save-time validation cannot rebind to a private/metadata address at
 * connect time. The resolver is injected so the matrix is deterministic.
 */
class TacticalClientSsrfPinTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a TacticalClient whose transport is a recording handler (no network)
     * sitting UNDER the real production pin middleware, so we exercise the exact
     * middleware code. $captured records whether the transport was reached and
     * the curl options it saw.
     */
    private function pinnedClient(callable $resolver, array &$captured, ?Response $response = null): TacticalClient
    {
        $inner = function (RequestInterface $request, array $options) use (&$captured, $response) {
            $captured['called'] = true;
            $captured['curl'] = $options['curl'] ?? null;

            return Create::promiseFor($response ?? new Response(200, [], '{}'));
        };

        $stack = HandlerStack::create($inner);
        $stack->push(TacticalClient::ssrfPinMiddleware($resolver));

        $http = new Client(['handler' => $stack, 'base_uri' => 'https://rmm.example.com/']);

        return new TacticalClient($http);
    }

    public function test_public_resolution_proceeds_and_pins_the_validated_ip(): void
    {
        $captured = [];
        $client = $this->pinnedClient(
            fn (string $host) => ['93.184.216.34'],
            $captured,
            new Response(200, [], '{"agents":[]}'),
        );

        $result = $client->get('agents/');

        $this->assertSame(['agents' => []], $result);
        $this->assertTrue($captured['called'] ?? false, 'transport should be reached for a public host');
        $this->assertSame(
            ['rmm.example.com:443:93.184.216.34'],
            $captured['curl'][CURLOPT_RESOLVE] ?? null,
            'the connection must be pinned to the validated IP via CURLOPT_RESOLVE',
        );
    }

    public function test_private_resolution_fails_closed_before_connect(): void
    {
        $captured = [];
        $client = $this->pinnedClient(fn (string $host) => ['10.1.2.3'], $captured);

        try {
            $client->get('agents/');
            $this->fail('expected TacticalClientException for a private-resolving host');
        } catch (TacticalClientException $e) {
            $this->assertStringContainsStringIgnoringCase('private', $e->getMessage());
        }

        $this->assertArrayNotHasKey('called', $captured, 'transport must NOT be reached when the host is unsafe');
    }

    public function test_a_single_private_ip_among_public_ones_fails_closed(): void
    {
        $captured = [];
        $client = $this->pinnedClient(
            fn (string $host) => ['93.184.216.34', '169.254.169.254'],
            $captured,
        );

        $this->expectException(TacticalClientException::class);
        $client->get('agents/');
    }

    public function test_unresolvable_host_fails_closed(): void
    {
        $captured = [];
        $client = $this->pinnedClient(fn (string $host) => false, $captured);

        try {
            $client->get('agents/');
            $this->fail('expected TacticalClientException for an unresolvable host');
        } catch (TacticalClientException $e) {
            $this->assertArrayNotHasKey('called', $captured, 'transport must NOT be reached on NXDOMAIN');
        }
    }

    public function test_config_driven_constructor_installs_the_pin(): void
    {
        Setting::setValue('tactical_api_url', 'https://rmm.example.com');
        Setting::setEncrypted('tactical_api_key', 'secret-key');

        // The real config-driven path (the production container singleton path),
        // but with an injected resolver returning a PRIVATE address. The pin the
        // constructor installs must refuse the request before any connection.
        $client = new TacticalClient(null, fn (string $host) => ['10.1.2.3']);

        try {
            $client->get('agents/');
            $this->fail('expected the installed pin to refuse the private resolution');
        } catch (TacticalClientException $e) {
            // The pin's message — distinguishes a real refusal from an incidental
            // connection error (which would not mention "private").
            $this->assertStringContainsStringIgnoringCase('private', $e->getMessage());
        }
    }
}
