<?php

namespace Tests\Feature\Tactical;

use App\Models\Setting;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Services\Tactical\TacticalProvisioningService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * TDD tests for TacticalProvisioningService (P7 Task 2 / G2/G3/G4/G5).
 *
 * Gate commitments proved here:
 *  G2: 403 → actionable error message; audit row written with actor id + outcome (no secret)
 *  G3: webhook key absent from logs AND audit row, on both request and response paths
 *  G4: idempotent (PUT on re-provision); PUT 404 → POST-create + overwrite id; no-clobber GET-first
 *  G5: all calls via TacticalClient
 */
class TacticalAlertProvisioningTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        // Configure Tactical so isConfigured() returns true
        Setting::setValue('tactical_api_url', 'https://tactical.example.com');
        Setting::setEncrypted('tactical_api_key', 'test-api-key-abc');

        $this->actor = User::factory()->create();
    }

    /**
     * Build a TacticalClient backed by a mock transport returning the given response queue.
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
            'handler'  => $stack,
            'allow_redirects' => false,
            'headers' => [
                'X-API-KEY'    => 'test-api-key',
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
        ]);

        return new TacticalClient($mockHttp);
    }

    /** Make a 403 TacticalClientException (with HTTP response, not a transport failure). */
    private function make403Exception(): TacticalClientException
    {
        $guzzleResp = new Response(403, [], '{"detail":"Permission denied"}');
        $guzzleReq  = new GuzzleRequest('POST', 'https://tactical.example.com/core/urlaction/');
        $guzzleEx   = RequestException::create($guzzleReq, $guzzleResp);

        return TacticalClientException::fromGuzzle('Tactical API error: 403 Forbidden', $guzzleEx);
    }

    /** Make a 404 TacticalClientException. */
    private function make404Exception(): TacticalClientException
    {
        $guzzleResp = new Response(404, [], '{"detail":"Not found"}');
        $guzzleReq  = new GuzzleRequest('PUT', 'https://tactical.example.com/core/urlaction/7/');
        $guzzleEx   = RequestException::create($guzzleReq, $guzzleResp);

        return TacticalClientException::fromGuzzle('Tactical API error: 404 Not Found', $guzzleEx);
    }

    // ── (a) Generates webhook key if absent ──────────────────────────────────

    public function test_provision_generates_webhook_key_if_absent(): void
    {
        $this->assertNull(Setting::getEncrypted('tactical_webhook_key'));

        $client = $this->clientReturning([
            new Response(201, [], json_encode(['id' => 7, 'name' => 'PSA Ticket Webhook'])),
            new Response(201, [], json_encode(['id' => 42, 'name' => 'PSA Auto-Ticket'])),
            new Response(200, [], json_encode(['alert_template' => null])),
            new Response(200, [], json_encode(['alert_template' => 42])),
        ]);

        $this->app->instance(TacticalClient::class, $client);
        $service = $this->app->make(TacticalProvisioningService::class);

        $result = $service->provision($this->actor->id);

        $this->assertTrue($result['success']);
        $key = Setting::getEncrypted('tactical_webhook_key');
        $this->assertNotNull($key, 'webhook key must be stored after provision');
        $this->assertNotEmpty($key);
    }

    public function test_provision_reuses_existing_webhook_key(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'existing-key-abc');

        $client = $this->clientReturning([
            new Response(201, [], json_encode(['id' => 7, 'name' => 'PSA Ticket Webhook'])),
            new Response(201, [], json_encode(['id' => 42, 'name' => 'PSA Auto-Ticket'])),
            new Response(200, [], json_encode(['alert_template' => null])),
            new Response(200, [], json_encode(['alert_template' => 42])),
        ]);

        $this->app->instance(TacticalClient::class, $client);
        $service = $this->app->make(TacticalProvisioningService::class);
        $service->provision($this->actor->id);

        $this->assertSame('existing-key-abc', Setting::getEncrypted('tactical_webhook_key'));
    }

    // ── (b) Creates URLAction with webhook URL + X-Webhook-Key ────────────────

    public function test_provision_posts_urlaction_with_webhook_url_and_key(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'my-webhook-key-xyz');

        $client = $this->clientReturning([
            new Response(201, [], json_encode(['id' => 7, 'name' => 'PSA Ticket Webhook', 'rest_headers' => 'should-be-stripped'])),
            new Response(201, [], json_encode(['id' => 42, 'name' => 'PSA Auto-Ticket'])),
            new Response(200, [], json_encode(['alert_template' => null])),
            new Response(200, [], json_encode(['alert_template' => 42])),
        ]);

        $this->app->instance(TacticalClient::class, $client);
        $service = $this->app->make(TacticalProvisioningService::class);
        $result  = $service->provision($this->actor->id);

        $this->assertTrue($result['success']);

        // Find the POST to core/urlaction/
        $urlActionReq = collect($this->history)
            ->first(fn ($h) => $h['request']->getMethod() === 'POST' &&
                str_contains($h['request']->getUri()->getPath(), 'core/urlaction'));

        $this->assertNotNull($urlActionReq, 'Expected a POST to core/urlaction/');

        $body = json_decode((string) $urlActionReq['request']->getBody(), true);
        $this->assertSame('PSA Ticket Webhook', $body['name']);
        $this->assertSame('rest', $body['action_type']);
        $this->assertSame('post', $body['rest_method']);

        // The webhook URL must point to our endpoint
        $this->assertStringContainsString('/api/webhooks/tactical', $body['pattern']);

        // X-Webhook-Key must be in rest_headers
        $headers = json_decode($body['rest_headers'], true);
        $this->assertSame('my-webhook-key-xyz', $headers['X-Webhook-Key']);
        $this->assertSame('application/json', $headers['Content-Type']);

        // rest_body must include Tactical template variables
        $bodyTemplate = json_decode($body['rest_body'], true);
        $this->assertArrayHasKey('alert_id', $bodyTemplate);
        $this->assertArrayHasKey('alert_type', $bodyTemplate);
        $this->assertArrayHasKey('severity', $bodyTemplate);
        $this->assertArrayHasKey('agent', $bodyTemplate);
    }

    // ── (c) Creates AlertTemplate wiring action_rest and resolved_action_rest ─

    public function test_provision_creates_alert_template_wiring_url_action(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'wk-key');

        $client = $this->clientReturning([
            new Response(201, [], json_encode(['id' => 7])),   // createUrlAction
            new Response(201, [], json_encode(['id' => 42])),  // createAlertTemplate
            new Response(200, [], json_encode(['alert_template' => null])), // getCoreSettings
            new Response(200, [], json_encode(['alert_template' => 42])),   // setDefaultAlertTemplate
        ]);

        $this->app->instance(TacticalClient::class, $client);
        $service = $this->app->make(TacticalProvisioningService::class);
        $result  = $service->provision($this->actor->id);

        $this->assertTrue($result['success']);

        // Find the POST to alerts/templates/
        $templateReq = collect($this->history)
            ->first(fn ($h) => $h['request']->getMethod() === 'POST' &&
                str_contains($h['request']->getUri()->getPath(), 'alerts/templates'));

        $this->assertNotNull($templateReq, 'Expected a POST to alerts/templates/');

        $body = json_decode((string) $templateReq['request']->getBody(), true);
        $this->assertSame('PSA Auto-Ticket', $body['name']);
        $this->assertTrue($body['is_active']);
        $this->assertSame('rest', $body['action_type']);
        $this->assertSame(7, $body['action_rest'],          'action_rest must be the urlaction id');
        $this->assertSame('rest', $body['resolved_action_type']);
        $this->assertSame(7, $body['resolved_action_rest'], 'resolved_action_rest must be the urlaction id');
        $this->assertTrue($body['agent_script_actions']);
        $this->assertTrue($body['check_script_actions']);
        $this->assertTrue($body['task_script_actions']);
    }

    // ── (d) GET-first; no-clobber if different default ────────────────────────

    public function test_provision_sets_default_when_no_existing_default(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'wk-key');

        $client = $this->clientReturning([
            new Response(201, [], json_encode(['id' => 7])),
            new Response(201, [], json_encode(['id' => 42])),
            new Response(200, [], json_encode(['alert_template' => null])), // no existing default
            new Response(200, [], json_encode(['alert_template' => 42])),   // setDefault response
        ]);

        $this->app->instance(TacticalClient::class, $client);
        $service = $this->app->make(TacticalProvisioningService::class);
        $result  = $service->provision($this->actor->id);

        $this->assertTrue($result['success']);

        // The PUT to core/settings/ must have been made
        $settingsReq = collect($this->history)
            ->first(fn ($h) => $h['request']->getMethod() === 'PUT' &&
                str_contains($h['request']->getUri()->getPath(), 'core/settings'));

        $this->assertNotNull($settingsReq, 'Expected a PUT to core/settings/');
        $body = json_decode((string) $settingsReq['request']->getBody(), true);
        $this->assertSame(42, $body['alert_template']);
    }

    public function test_provision_does_not_clobber_different_existing_default(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'wk-key');

        $client = $this->clientReturning([
            new Response(201, [], json_encode(['id' => 7])),
            new Response(201, [], json_encode(['id' => 42])),
            new Response(200, [], json_encode(['alert_template' => 99])), // different existing default!
            // No fourth response — setDefault should NOT be called
        ]);

        $this->app->instance(TacticalClient::class, $client);
        $service = $this->app->make(TacticalProvisioningService::class);
        $result  = $service->provision($this->actor->id);

        $this->assertTrue($result['success']);

        // Must record the prior default
        $this->assertSame('99', Setting::getValue('tactical_prior_default_alert_template_id'));

        // Must warn about changed default
        $this->assertStringContainsString('99', $result['warning'] ?? '');

        // The PUT to core/settings/ must NOT have been made (no clobber)
        $settingsPuts = collect($this->history)
            ->filter(fn ($h) => $h['request']->getMethod() === 'PUT' &&
                str_contains($h['request']->getUri()->getPath(), 'core/settings'));

        $this->assertCount(0, $settingsPuts, 'Must not PUT core/settings/ when a different default exists');
    }

    public function test_provision_sets_default_when_already_ours(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'wk-key');
        Setting::setValue('tactical_alert_template_id', '42');

        $client = $this->clientReturning([
            new Response(200, [], json_encode(['id' => 7])),   // updateUrlAction
            new Response(200, [], json_encode(['id' => 42])),  // updateAlertTemplate
            new Response(200, [], json_encode(['alert_template' => 42])), // getCoreSettings — already ours
            new Response(200, [], json_encode(['alert_template' => 42])), // setDefault (allowed)
        ]);

        Setting::setValue('tactical_url_action_id', '7');
        $this->app->instance(TacticalClient::class, $client);
        $service = $this->app->make(TacticalProvisioningService::class);
        $result  = $service->provision($this->actor->id);

        $this->assertTrue($result['success']);

        // PUT to core/settings/ must have been made
        $settingsPuts = collect($this->history)
            ->filter(fn ($h) => $h['request']->getMethod() === 'PUT' &&
                str_contains($h['request']->getUri()->getPath(), 'core/settings'));

        $this->assertCount(1, $settingsPuts, 'Should set default when it is already ours');
    }

    // ── (e) Stores ids + provisioned_at ──────────────────────────────────────

    public function test_provision_stores_ids_and_provisioned_at(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'wk-key');

        $client = $this->clientReturning([
            new Response(201, [], json_encode(['id' => 7])),
            new Response(201, [], json_encode(['id' => 42])),
            new Response(200, [], json_encode(['alert_template' => null])),
            new Response(200, [], json_encode(['alert_template' => 42])),
        ]);

        $this->app->instance(TacticalClient::class, $client);
        $service = $this->app->make(TacticalProvisioningService::class);
        $service->provision($this->actor->id);

        $this->assertSame('7', Setting::getValue('tactical_url_action_id'));
        $this->assertSame('42', Setting::getValue('tactical_alert_template_id'));
        $this->assertNotNull(Setting::getValue('tactical_webhook_provisioned_at'));
    }

    // ── Re-provision uses PUT ─────────────────────────────────────────────────

    public function test_reprovision_uses_put_for_existing_ids(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'wk-key');
        Setting::setValue('tactical_url_action_id', '7');
        Setting::setValue('tactical_alert_template_id', '42');

        $client = $this->clientReturning([
            new Response(200, [], json_encode(['id' => 7])),   // PUT urlaction/7/
            new Response(200, [], json_encode(['id' => 42])),  // PUT templates/42/
            new Response(200, [], json_encode(['alert_template' => null])),
            new Response(200, [], json_encode(['alert_template' => 42])),
        ]);

        $this->app->instance(TacticalClient::class, $client);
        $service = $this->app->make(TacticalProvisioningService::class);
        $result  = $service->provision($this->actor->id);

        $this->assertTrue($result['success']);

        // Verify PUT was used for urlaction
        $urlActionReq = collect($this->history)
            ->first(fn ($h) => str_contains($h['request']->getUri()->getPath(), 'core/urlaction'));

        $this->assertSame('PUT', $urlActionReq['request']->getMethod(),
            'Should use PUT (not POST) for existing url action id');
        $this->assertStringContainsString('/core/urlaction/7/', $urlActionReq['request']->getUri()->getPath());

        // Verify PUT was used for template
        $templateReq = collect($this->history)
            ->first(fn ($h) => str_contains($h['request']->getUri()->getPath(), 'alerts/templates'));

        $this->assertSame('PUT', $templateReq['request']->getMethod(),
            'Should use PUT (not POST) for existing template id');
        $this->assertStringContainsString('/alerts/templates/42/', $templateReq['request']->getUri()->getPath());
    }

    // ── PUT 404 → POST-create + overwrite id ─────────────────────────────────

    public function test_reprovision_falls_back_to_create_on_urlaction_404(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'wk-key');
        Setting::setValue('tactical_url_action_id', '7');   // stored, but deleted in Tactical

        $mockClient = \Mockery::mock(TacticalClient::class);

        // updateUrlAction(7) → throws 404
        $mockClient->shouldReceive('updateUrlAction')
            ->once()
            ->with(7, \Mockery::any())
            ->andThrow($this->make404Exception());

        // Falls back to createUrlAction → returns new id 99
        $mockClient->shouldReceive('createUrlAction')
            ->once()
            ->andReturn(['id' => 99]);

        // Template: no stored id → create
        $mockClient->shouldReceive('createAlertTemplate')
            ->once()
            ->andReturn(['id' => 55]);

        $mockClient->shouldReceive('getCoreSettings')
            ->once()
            ->andReturn(['alert_template' => null]);

        $mockClient->shouldReceive('setDefaultAlertTemplate')
            ->once()
            ->andReturn(['alert_template' => 55]);

        $this->app->instance(TacticalClient::class, $mockClient);
        $service = $this->app->make(TacticalProvisioningService::class);

        // Remove template id so it tries create
        Setting::setValue('tactical_alert_template_id', null);

        $result = $service->provision($this->actor->id);

        $this->assertTrue($result['success']);
        $this->assertSame('99', Setting::getValue('tactical_url_action_id'),
            'Stored id must be overwritten with the new id from create');
    }

    public function test_reprovision_falls_back_to_create_on_template_404(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'wk-key');
        Setting::setValue('tactical_alert_template_id', '42');

        $mockClient = \Mockery::mock(TacticalClient::class);

        // URLAction: no stored id → create
        $mockClient->shouldReceive('createUrlAction')
            ->once()
            ->andReturn(['id' => 7]);

        // updateAlertTemplate(42) → 404
        $mockClient->shouldReceive('updateAlertTemplate')
            ->once()
            ->with(42, \Mockery::any())
            ->andThrow($this->make404Exception());

        // Falls back to create
        $mockClient->shouldReceive('createAlertTemplate')
            ->once()
            ->andReturn(['id' => 88]);

        $mockClient->shouldReceive('getCoreSettings')
            ->once()
            ->andReturn(['alert_template' => null]);

        $mockClient->shouldReceive('setDefaultAlertTemplate')
            ->once()
            ->andReturn(['alert_template' => 88]);

        Setting::setValue('tactical_url_action_id', null);
        $this->app->instance(TacticalClient::class, $mockClient);
        $service = $this->app->make(TacticalProvisioningService::class);
        $result  = $service->provision($this->actor->id);

        $this->assertTrue($result['success']);
        $this->assertSame('88', Setting::getValue('tactical_alert_template_id'),
            'Stored template id must be overwritten with the new id from create');
    }

    // ── G2: 403 → actionable error ────────────────────────────────────────────

    public function test_provision_returns_actionable_error_on_403(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'wk-key');

        $mockClient = \Mockery::mock(TacticalClient::class);
        $mockClient->shouldReceive('createUrlAction')
            ->once()
            ->andThrow($this->make403Exception());

        // Other methods should not be called
        $mockClient->shouldNotReceive('createAlertTemplate');
        $mockClient->shouldNotReceive('getCoreSettings');

        $this->app->instance(TacticalClient::class, $mockClient);
        $service = $this->app->make(TacticalProvisioningService::class);
        $result  = $service->provision($this->actor->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('403', $result['message']);
        $this->assertStringContainsString('permission', strtolower($result['message']));
        $this->assertStringContainsString('URL Actions', $result['message']);
        $this->assertStringContainsString('Alert Templates', $result['message']);
        // Must NOT be a generic 500-style message
        $this->assertStringNotContainsString('500', $result['message']);
    }

    // ── G3: webhook key NEVER in logs ─────────────────────────────────────────

    public function test_webhook_key_absent_from_logs_on_request_path(): void
    {
        $webhookKey = 'super-secret-webhook-key-must-not-log';
        Setting::setEncrypted('tactical_webhook_key', $webhookKey);

        /** @var list<string> $loggedMessages */
        $loggedMessages = [];
        Log::listen(function ($message) use (&$loggedMessages) {
            $loggedMessages[] = (string) $message->message;
            foreach ((array) ($message->context ?? []) as $v) {
                $loggedMessages[] = (string) $v;
            }
        });

        $client = $this->clientReturning([
            new Response(201, [], json_encode(['id' => 7, 'rest_headers' => 'MUST_BE_STRIPPED'])),
            new Response(201, [], json_encode(['id' => 42])),
            new Response(200, [], json_encode(['alert_template' => null])),
            new Response(200, [], json_encode(['alert_template' => 42])),
        ]);

        $this->app->instance(TacticalClient::class, $client);
        $service = $this->app->make(TacticalProvisioningService::class);
        $service->provision($this->actor->id);

        // Collect all log output into a single string for a reliable assertion
        $allLogs = implode("\n", $loggedMessages);
        $this->assertStringNotContainsString(
            $webhookKey, $allLogs,
            'Webhook key must never appear in any log message'
        );
        // Ensure we actually verified something (at least the empty-log case counts)
        $this->addToAssertionCount(1);
    }

    public function test_webhook_key_absent_from_logs_on_response_path(): void
    {
        // G3: Tactical echoes back rest_headers in the response — we must strip it immediately.
        $webhookKey = 'echoed-key-in-response-must-not-log';
        Setting::setEncrypted('tactical_webhook_key', $webhookKey);

        /** @var list<string> $loggedMessages */
        $loggedMessages = [];
        Log::listen(function ($message) use (&$loggedMessages) {
            $loggedMessages[] = (string) $message->message;
            $loggedMessages[] = json_encode($message->context ?? []);
        });

        // Tactical echoes rest_headers + rest_body in the response
        $echoedRestHeaders = json_encode(['X-Webhook-Key' => $webhookKey, 'Content-Type' => 'application/json']);
        $echoedRestBody    = json_encode(['alert_id' => '{{alert.id}}']);

        $client = $this->clientReturning([
            new Response(201, [], json_encode([
                'id'           => 7,
                'rest_headers' => $echoedRestHeaders,
                'rest_body'    => $echoedRestBody,
            ])),
            new Response(201, [], json_encode(['id' => 42])),
            new Response(200, [], json_encode(['alert_template' => null])),
            new Response(200, [], json_encode(['alert_template' => 42])),
        ]);

        $this->app->instance(TacticalClient::class, $client);
        $service = $this->app->make(TacticalProvisioningService::class);
        $service->provision($this->actor->id);

        // Collect all log output into a single string for a reliable assertion
        $allLogs = implode("\n", $loggedMessages);
        $this->assertStringNotContainsString(
            $webhookKey, $allLogs,
            'Webhook key must not appear in logs even when echoed in response body'
        );
        $this->addToAssertionCount(1);
    }

    // ── G2: audit row written with actor + outcome, secret-free ──────────────

    public function test_provision_writes_audit_row_with_actor_and_outcome(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'audit-test-key');

        $client = $this->clientReturning([
            new Response(201, [], json_encode(['id' => 7])),
            new Response(201, [], json_encode(['id' => 42])),
            new Response(200, [], json_encode(['alert_template' => null])),
            new Response(200, [], json_encode(['alert_template' => 42])),
        ]);

        $this->app->instance(TacticalClient::class, $client);
        $service = $this->app->make(TacticalProvisioningService::class);
        $service->provision($this->actor->id);

        // Audit row must exist with the actor id
        $this->assertDatabaseHas('settings', ['key' => 'tactical_webhook_provisioned_at']);

        // The audit log (stored in settings as JSON) must include actor + outcome
        $auditJson = Setting::getValue('tactical_provision_audit');
        $this->assertNotNull($auditJson, 'Audit entry must be written to settings');

        $audit = json_decode($auditJson, true);
        $this->assertSame($this->actor->id, $audit['actor_id']);
        $this->assertTrue($audit['success']);
        $this->assertSame(7,  $audit['url_action_id']);
        $this->assertSame(42, $audit['alert_template_id']);

        // Secret must NOT appear in the audit row
        $this->assertStringNotContainsString('audit-test-key', $auditJson);
        $this->assertArrayNotHasKey('webhook_key', $audit);
        $this->assertArrayNotHasKey('rest_headers', $audit);
    }

    public function test_provision_writes_audit_row_on_403_failure(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'audit-fail-key');

        $mockClient = \Mockery::mock(TacticalClient::class);
        $mockClient->shouldReceive('createUrlAction')->andThrow($this->make403Exception());

        $this->app->instance(TacticalClient::class, $mockClient);
        $service = $this->app->make(TacticalProvisioningService::class);
        $service->provision($this->actor->id);

        $auditJson = Setting::getValue('tactical_provision_audit');
        $this->assertNotNull($auditJson);
        $audit = json_decode($auditJson, true);

        $this->assertSame($this->actor->id, $audit['actor_id']);
        $this->assertFalse($audit['success']);
        $this->assertStringNotContainsString('audit-fail-key', $auditJson);
    }

    // ── G3 PROOF: webhook key absent from ALL sinks on the FAILURE path ──────
    //
    // This is the gate test that proves the fix. The existing failure tests used
    // a key-free body ("{"detail":"Permission denied"}") so the leak passed green.
    // This test plants the real webhook key inside the exception message (simulating
    // Tactical echoing rest_headers in a 400 validation-error body) and asserts
    // the key is absent from (a) logs, (b) the audit row, and (c) return message.

    public function test_webhook_key_absent_from_all_sinks_when_urlaction_fails_with_key_in_body(): void
    {
        $webhookKey = 'wh-secret-12345-must-not-leak';
        Setting::setEncrypted('tactical_webhook_key', $webhookKey);

        // Simulate Tactical echoing rest_headers (containing X-Webhook-Key) in a
        // 400 validation-error body. Guzzle's BodySummarizer bakes this into the
        // exception message, so the TacticalClientException message will contain
        // the key verbatim.
        $echoedBody = json_encode([
            'rest_headers' => json_encode(['X-Webhook-Key' => $webhookKey, 'Content-Type' => 'application/json']),
            'pattern'      => ['This field is required.'],
        ]);
        $guzzleResp = new Response(400, [], $echoedBody);
        $guzzleReq  = new GuzzleRequest('POST', 'https://tactical.example.com/core/urlaction/');
        $guzzleEx   = RequestException::create($guzzleReq, $guzzleResp);
        // The exception message that fromGuzzle bakes in will reference the response body summary
        $keyLeakException = TacticalClientException::fromGuzzle(
            "Tactical API error: 400 Bad Request\n{$echoedBody}",
            $guzzleEx
        );

        // Verify our test setup: the exception message actually contains the key
        $this->assertStringContainsString($webhookKey, $keyLeakException->getMessage(),
            'Test setup error: exception message must contain the key to be a valid leak test');

        // Tap logs
        /** @var list<string> $loggedMessages */
        $loggedMessages = [];
        Log::listen(function ($message) use (&$loggedMessages) {
            $loggedMessages[] = (string) $message->message;
            $loggedMessages[] = json_encode($message->context ?? []);
        });

        $mockClient = \Mockery::mock(TacticalClient::class);
        $mockClient->shouldReceive('createUrlAction')
            ->once()
            ->andThrow($keyLeakException);

        $this->app->instance(TacticalClient::class, $mockClient);
        $service = $this->app->make(TacticalProvisioningService::class);
        $result  = $service->provision($this->actor->id);

        $this->assertFalse($result['success']);

        // (a) Key must be absent from ALL log output
        $allLogs = implode("\n", $loggedMessages);
        $this->assertStringNotContainsString(
            $webhookKey, $allLogs,
            'Webhook key must not appear in any log output on the failure path'
        );

        // (b) Key must be absent from the audit row stored in settings
        $auditJson = Setting::getValue('tactical_provision_audit') ?? '';
        $this->assertStringNotContainsString(
            $webhookKey, $auditJson,
            'Webhook key must not appear in the stored audit row'
        );

        // (c) Key must be absent from the returned message
        $this->assertStringNotContainsString(
            $webhookKey, $result['message'],
            'Webhook key must not appear in the returned message'
        );
    }

    // ── FIX 2 PROOF: no-clobber warning says "left unchanged", not "changed" ─

    public function test_no_clobber_warning_says_left_unchanged_not_changed(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'wk-key');

        $client = $this->clientReturning([
            new Response(201, [], json_encode(['id' => 7])),
            new Response(201, [], json_encode(['id' => 42])),
            new Response(200, [], json_encode(['alert_template' => 99])), // different existing default
            // No fourth response — setDefault must NOT be called
        ]);

        $this->app->instance(TacticalClient::class, $client);
        $service = $this->app->make(TacticalProvisioningService::class);
        $result  = $service->provision($this->actor->id);

        $this->assertTrue($result['success']);
        $warning = $result['warning'] ?? '';

        // Must mention both the existing id and our id
        $this->assertStringContainsString('99', $warning);
        $this->assertStringContainsString('42', $warning);

        // Must clearly communicate that the default was LEFT UNCHANGED
        $this->assertTrue(
            str_contains(strtolower($warning), 'left unchanged') || str_contains(strtolower($warning), 'not changed'),
            "Warning must say 'left unchanged' or 'not changed'; got: {$warning}"
        );

        // Must NOT say "changed … to ours" (the old misleading copy)
        $this->assertStringNotContainsString('Changed your', $warning);
    }

    // ── FIX 3: id-0 guard — malformed 2xx on create must not store id 0 ──────

    public function test_create_urlaction_without_id_in_response_throws_not_stores_zero(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'wk-key');

        // createUrlAction returns a 201 but without an `id` field
        $mockClient = \Mockery::mock(TacticalClient::class);
        $mockClient->shouldReceive('createUrlAction')
            ->once()
            ->andReturn(['name' => 'PSA Ticket Webhook']); // no `id`

        $this->app->instance(TacticalClient::class, $mockClient);
        $service = $this->app->make(TacticalProvisioningService::class);
        $result  = $service->provision($this->actor->id);

        // Must fail loudly — not succeed with id 0
        $this->assertFalse($result['success'],
            'Provision must fail when createUrlAction returns no id');

        // Must not have stored 0
        $storedId = Setting::getValue('tactical_url_action_id');
        $this->assertNotSame('0', $storedId,
            'Must not store id=0 when response is missing the id field');
    }

    public function test_create_alert_template_without_id_in_response_throws_not_stores_zero(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'wk-key');

        $mockClient = \Mockery::mock(TacticalClient::class);
        $mockClient->shouldReceive('createUrlAction')
            ->once()
            ->andReturn(['id' => 7]);
        $mockClient->shouldReceive('createAlertTemplate')
            ->once()
            ->andReturn(['name' => 'PSA Auto-Ticket']); // no `id`

        $this->app->instance(TacticalClient::class, $mockClient);
        $service = $this->app->make(TacticalProvisioningService::class);
        $result  = $service->provision($this->actor->id);

        $this->assertFalse($result['success'],
            'Provision must fail when createAlertTemplate returns no id');

        $storedId = Setting::getValue('tactical_alert_template_id');
        $this->assertNotSame('0', $storedId,
            'Must not store id=0 when template response is missing the id field');
    }

    // ── Controller action + route ─────────────────────────────────────────────

    public function test_controller_provision_alerts_returns_json_success(): void
    {
        Setting::setEncrypted('tactical_webhook_key', 'ctrl-test-key');

        $mockService = \Mockery::mock(TacticalProvisioningService::class);
        $mockService->shouldReceive('provision')
            ->once()
            ->with($this->actor->id)
            ->andReturn(['success' => true, 'message' => 'Provisioned successfully.']);

        $this->app->instance(TacticalProvisioningService::class, $mockService);

        $response = $this->actingAs($this->actor)
            ->postJson(route('settings.integrations.tactical.provision-alerts'));

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_controller_provision_alerts_returns_json_failure(): void
    {
        $mockService = \Mockery::mock(TacticalProvisioningService::class);
        $mockService->shouldReceive('provision')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Your Tactical API key lacks permissions.']);

        $this->app->instance(TacticalProvisioningService::class, $mockService);

        $response = $this->actingAs($this->actor)
            ->postJson(route('settings.integrations.tactical.provision-alerts'));

        $response->assertOk()->assertJson(['success' => false]);
    }

    public function test_controller_provision_alerts_requires_authentication(): void
    {
        $response = $this->postJson(route('settings.integrations.tactical.provision-alerts'));

        $response->assertStatus(401);
    }

    // ── TacticalConfig new keys ───────────────────────────────────────────────

    public function test_tactical_config_returns_url_action_id(): void
    {
        Setting::setValue('tactical_url_action_id', '15');

        $this->assertSame(15, \App\Support\TacticalConfig::urlActionId());
    }

    public function test_tactical_config_returns_null_url_action_id_when_absent(): void
    {
        $this->assertNull(\App\Support\TacticalConfig::urlActionId());
    }

    public function test_tactical_config_returns_alert_template_id(): void
    {
        Setting::setValue('tactical_alert_template_id', '99');

        $this->assertSame(99, \App\Support\TacticalConfig::alertTemplateId());
    }

    public function test_tactical_config_returns_null_alert_template_id_when_absent(): void
    {
        $this->assertNull(\App\Support\TacticalConfig::alertTemplateId());
    }

    // ── Settings page still renders with new Blade button ────────────────────

    public function test_integrations_settings_page_renders_with_provision_button(): void
    {
        Setting::setValue('tactical_connected_at', now()->toDateTimeString());
        Setting::setValue('tactical_api_url', 'https://tactical.example.com');
        Setting::setEncrypted('tactical_api_key', 'test-api-key');

        $this->actingAs($this->actor)
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Provision alert');
    }
}
