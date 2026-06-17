<?php

namespace Tests\Feature\Tactical;

use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * TDD tests for TacticalClient provisioning methods (P7 Task 1 / G1).
 *
 * Covers:
 *   - createUrlAction   → POST core/urlaction/
 *   - updateUrlAction   → PUT  core/urlaction/{id}/
 *   - createAlertTemplate   → POST alerts/templates/
 *   - updateAlertTemplate   → PUT  alerts/templates/{id}/
 *   - setDefaultAlertTemplate → PUT core/settings/ {alert_template: id}
 *   - getCoreSettings   → GET  core/settings/
 *   - put() returns [] (not null) on an empty 200 body
 *
 * Uses the injected MockHandler+history pattern established in TacticalClientHttpTest.
 */
class TacticalProvisioningTest extends TestCase
{
    private string $apiKey = 'svc-user-api-key-abc123';

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /**
     * Build a TacticalClient over an injected mock transport that returns $queue
     * in order, while applying the same default X-API-KEY header production uses.
     *
     * @param  Response[]  $queue
     */
    private function clientReturning(array $queue): TacticalClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        $mockHttp = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => $stack,
            'allow_redirects' => false,
            'headers' => [
                'X-API-KEY' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);

        return new TacticalClient($mockHttp);
    }

    private function lastRequest(): RequestInterface
    {
        return $this->history[array_key_last($this->history)]['request'];
    }

    // ── createUrlAction ──────────────────────────────────────────────────────

    public function test_create_url_action_posts_to_core_urlaction(): void
    {
        $payload = ['id' => 7, 'name' => 'PSA Alert', 'url' => 'https://psa.example.com/webhook'];
        $client = $this->clientReturning([new Response(201, [], json_encode($payload))]);

        $result = $client->createUrlAction(['name' => 'PSA Alert', 'url' => 'https://psa.example.com/webhook']);

        $req = $this->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/core/urlaction/', $req->getUri()->getPath());
        $this->assertSame(7, $result['id']);
        $this->assertSame('PSA Alert', $result['name']);
    }

    public function test_create_url_action_sends_body(): void
    {
        $body = ['name' => 'PSA Alert', 'url' => 'https://psa.example.com/webhook', 'rest_method' => 'POST'];
        $client = $this->clientReturning([new Response(201, [], json_encode(array_merge(['id' => 1], $body)))]);

        $client->createUrlAction($body);

        $sent = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame('PSA Alert', $sent['name']);
        $this->assertSame('https://psa.example.com/webhook', $sent['url']);
    }

    // ── updateUrlAction ──────────────────────────────────────────────────────

    public function test_update_url_action_puts_to_core_urlaction_id(): void
    {
        $payload = ['id' => 7, 'name' => 'PSA Alert Updated'];
        $client = $this->clientReturning([new Response(200, [], json_encode($payload))]);

        $result = $client->updateUrlAction(7, ['name' => 'PSA Alert Updated']);

        $req = $this->lastRequest();
        $this->assertSame('PUT', $req->getMethod());
        $this->assertSame('/core/urlaction/7/', $req->getUri()->getPath());
        $this->assertSame(7, $result['id']);
    }

    public function test_update_url_action_sends_body(): void
    {
        $body = ['name' => 'Renamed', 'url' => 'https://psa.example.com/v2/webhook'];
        $client = $this->clientReturning([new Response(200, [], json_encode(array_merge(['id' => 3], $body)))]);

        $client->updateUrlAction(3, $body);

        $sent = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame('Renamed', $sent['name']);
    }

    // ── createAlertTemplate ──────────────────────────────────────────────────

    public function test_create_alert_template_posts_to_alerts_templates(): void
    {
        $payload = ['id' => 42, 'name' => 'PSA Alert Template'];
        $client = $this->clientReturning([new Response(201, [], json_encode($payload))]);

        $result = $client->createAlertTemplate(['name' => 'PSA Alert Template']);

        $req = $this->lastRequest();
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/alerts/templates/', $req->getUri()->getPath());
        $this->assertSame(42, $result['id']);
        $this->assertSame('PSA Alert Template', $result['name']);
    }

    public function test_create_alert_template_sends_body(): void
    {
        $body = ['name' => 'PSA Template', 'action' => true];
        $client = $this->clientReturning([new Response(201, [], json_encode(array_merge(['id' => 5], $body)))]);

        $client->createAlertTemplate($body);

        $sent = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame('PSA Template', $sent['name']);
        $this->assertTrue($sent['action']);
    }

    // ── updateAlertTemplate ──────────────────────────────────────────────────

    public function test_update_alert_template_puts_to_alerts_templates_id(): void
    {
        $payload = ['id' => 42, 'name' => 'Updated Template'];
        $client = $this->clientReturning([new Response(200, [], json_encode($payload))]);

        $result = $client->updateAlertTemplate(42, ['name' => 'Updated Template']);

        $req = $this->lastRequest();
        $this->assertSame('PUT', $req->getMethod());
        $this->assertSame('/alerts/templates/42/', $req->getUri()->getPath());
        $this->assertSame(42, $result['id']);
    }

    public function test_update_alert_template_sends_body(): void
    {
        $body = ['name' => 'Changed', 'action_url' => 7];
        $client = $this->clientReturning([new Response(200, [], json_encode(array_merge(['id' => 9], $body)))]);

        $client->updateAlertTemplate(9, $body);

        $sent = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame('Changed', $sent['name']);
        $this->assertSame(7, $sent['action_url']);
    }

    // ── setDefaultAlertTemplate ──────────────────────────────────────────────

    public function test_set_default_alert_template_puts_to_core_settings(): void
    {
        $payload = ['alert_template' => 42];
        $client = $this->clientReturning([new Response(200, [], json_encode($payload))]);

        $result = $client->setDefaultAlertTemplate(42);

        $req = $this->lastRequest();
        $this->assertSame('PUT', $req->getMethod());
        $this->assertSame('/core/settings/', $req->getUri()->getPath());
        $this->assertSame(['alert_template' => 42], $result);
    }

    public function test_set_default_alert_template_sends_alert_template_in_body(): void
    {
        $client = $this->clientReturning([new Response(200, [], json_encode(['alert_template' => 99]))]);

        $client->setDefaultAlertTemplate(99);

        $sent = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertSame(99, $sent['alert_template']);
        $this->assertArrayNotHasKey('id', $sent, 'body should only contain alert_template');
    }

    // ── getCoreSettings ──────────────────────────────────────────────────────

    public function test_get_core_settings_gets_core_settings(): void
    {
        $payload = ['alert_template' => 5, 'default_agent_policy' => null];
        $client = $this->clientReturning([new Response(200, [], json_encode($payload))]);

        $result = $client->getCoreSettings();

        $req = $this->lastRequest();
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('/core/settings/', $req->getUri()->getPath());
        $this->assertSame(5, $result['alert_template']);
    }

    public function test_get_core_settings_returns_decoded_array(): void
    {
        $payload = ['alert_template' => null, 'some_flag' => true];
        $client = $this->clientReturning([new Response(200, [], json_encode($payload))]);

        $result = $client->getCoreSettings();

        $this->assertIsArray($result);
        $this->assertNull($result['alert_template']);
        $this->assertTrue($result['some_flag']);
    }

    // ── put() returns [] not null on empty body ──────────────────────────────

    public function test_put_returns_empty_array_on_200_with_empty_body(): void
    {
        $client = $this->clientReturning([new Response(200, [], '')]);

        // Call put() via a method that uses it — updateUrlAction is a thin wrapper
        $result = $client->updateUrlAction(1, ['name' => 'test']);

        $this->assertIsArray($result);
        $this->assertSame([], $result);
    }

    public function test_put_returns_empty_array_on_200_with_null_json(): void
    {
        // Some endpoints return literal "null" JSON
        $client = $this->clientReturning([new Response(200, [], 'null')]);

        $result = $client->updateAlertTemplate(1, ['name' => 'test']);

        $this->assertIsArray($result);
        $this->assertSame([], $result);
    }
}
