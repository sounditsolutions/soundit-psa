<?php

namespace Tests\Feature\Mcp;

use App\Models\Setting;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * tactical_list_custom_fields: a read of Tactical custom field DEFINITIONS
 * (GET core/customfields/), projected to id, name, model and type.
 *
 * TacticalClient builds its own Guzzle client, so Http::fake() cannot see its
 * traffic. The bare Http::fake() answers and records every Laravel HTTP call,
 * so preventStrayRequests() never fires here; the success test instead
 * asserts Http::assertNothingSent(). The Tactical traffic is recorded with
 * Guzzle's history middleware on an injected client whose mock queue always
 * holds a spare response, so a second request would be recorded rather than
 * refused.
 *
 * The fake row is the producer's serializer fields (tacticalrmm core/models.py
 * CustomField, fields="__all__") plus an extra `value` key the producer does
 * not send, kept as a sentinel; every value in it is synthetic.
 */
class TacticalCustomFieldsReadToolTest extends TestCase
{
    use RefreshDatabase;

    private const TOOL = 'tactical_list_custom_fields';

    private const SENTINELS = [
        'SENTINEL-DEFAULT-STRING',
        'SENTINEL-DEFAULT-MULTI',
        'SENTINEL-OPTION',
        'SENTINEL-VALUE',
        'SENTINEL-AUDIT',
    ];

    /** @var array<int, mixed> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Http::preventStrayRequests();

        Setting::setValue('tactical_api_url', 'https://tactical.invalid');
        Setting::setEncrypted('tactical_api_key', 'test-key');
    }

    /** @param  array<int, Response>  $queue */
    private function bindTactical(array $queue): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $guzzle = new GuzzleClient(['handler' => $stack, 'base_uri' => 'https://tactical.invalid/']);

        $this->app->instance(TacticalClient::class, new TacticalClient($guzzle));
    }

    /** @return list<string> */
    private function requests(): array
    {
        return array_map(
            fn (array $t): string => $t['request']->getMethod().' '.(string) $t['request']->getUri(),
            $this->history,
        );
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $name, string $model, string $type): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'model' => $model,
            'type' => $type,
            'order' => 0,
            'required' => false,
            'options' => ['SENTINEL-OPTION'],
            'default_value_string' => 'SENTINEL-DEFAULT-STRING',
            'default_value_bool' => true,
            'default_values_multiple' => ['SENTINEL-DEFAULT-MULTI'],
            'hide_in_ui' => false,
            'hide_in_summary' => false,
            'value' => 'SENTINEL-VALUE',
            'created_by' => 'SENTINEL-AUDIT',
            'created_time' => '2026-01-01T00:00:00Z',
            'modified_by' => 'SENTINEL-AUDIT',
            'modified_time' => '2026-01-01T00:00:00Z',
        ];
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
    }

    private function callTool(string $token, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => self::TOOL, 'arguments' => $arguments],
            ]);
    }

    /** @return list<string> */
    private function listedNames(string $token): array
    {
        $tools = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->json('result.tools') ?? [];

        return array_column($tools, 'name');
    }

    /** @return array<string, mixed> */
    private function decoded(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    public function test_it_sends_one_get_to_core_customfields_and_returns_only_id_name_model_type(): void
    {
        $this->bindTactical([
            new Response(200, [], json_encode([
                $this->row(18, 'ControlDOrgCode', 'client', 'text'),
                $this->row(19, 'Example Agent Field', 'agent', 'multiple'),
            ])),
            new Response(200, [], json_encode([])),
        ]);

        $response = $this->callTool($this->token([self::TOOL]));
        $result = $this->decoded($response);

        $this->assertSame(['GET https://tactical.invalid/core/customfields/'], $this->requests());
        Http::assertNothingSent();

        $this->assertSame(['count', 'custom_fields'], array_keys($result));
        $this->assertSame(2, $result['count']);
        $this->assertSame([
            ['id' => 18, 'name' => 'ControlDOrgCode', 'model' => 'client', 'type' => 'text'],
            ['id' => 19, 'name' => 'Example Agent Field', 'model' => 'agent', 'type' => 'multiple'],
        ], $result['custom_fields']);
        foreach ($result['custom_fields'] as $field) {
            $this->assertSame(['id', 'model', 'name', 'type'], collect($field)->keys()->sort()->values()->all());
        }

        $raw = (string) $response->getContent();
        foreach (self::SENTINELS as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $raw, "{$sentinel} leaked into the tool output");
        }
        $encoded = json_encode($result);
        foreach (['default_value_string', 'default_values_multiple', 'options', 'value', 'default_value_bool'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $encoded, "{$key} leaked into the tool output");
        }
    }

    public function test_an_upstream_403_reports_status_and_class_only_and_is_logged(): void
    {
        Log::spy();
        $this->bindTactical([
            new Response(403, [], json_encode(['detail' => 'SENTINEL-UPSTREAM-BODY'])),
            new Response(200, [], json_encode([])),
        ]);

        $response = $this->callTool($this->token([self::TOOL]));
        $result = $this->decoded($response);

        $this->assertSame(['GET https://tactical.invalid/core/customfields/'], $this->requests());
        $this->assertSame([
            'error' => 'Tactical custom fields query failed',
            'status' => 403,
            'class' => 'TacticalClientException',
        ], $result);
        $this->assertStringNotContainsString('SENTINEL-UPSTREAM-BODY', (string) $response->getContent());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => $message === '[ChetDataSurface] Tactical custom fields query failed'
                && $context === ['status' => 403, 'class' => TacticalClientException::class])
            ->once();
    }

    private function assertNotAListWarningLoggedOnce(): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => $message === '[ChetDataSurface] Tactical custom fields response was not a list of objects')
            ->once();
    }

    /** @return array<string, array{0: string}> */
    public static function nonListBodies(): array
    {
        return [
            'empty object' => ['{}'],
            'empty body' => [''],
            'html page' => ['<html><body>Sign in</body></html>'],
            'json null' => ['null'],
            'json scalar' => ['"ok"'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonListBodies')]
    public function test_a_200_body_that_is_not_a_json_array_is_an_error_and_is_logged(string $body): void
    {
        Log::spy();
        $this->bindTactical([
            new Response(200, [], $body),
            new Response(200, [], json_encode([])),
        ]);

        $result = $this->decoded($this->callTool($this->token([self::TOOL])));

        $this->assertSame(['error' => 'Tactical custom fields response was not a list of objects'], $result);
        $this->assertSame(['GET https://tactical.invalid/core/customfields/'], $this->requests());
        $this->assertNotAListWarningLoggedOnce();
    }

    public function test_a_200_empty_json_array_is_an_empty_list_not_an_error(): void
    {
        Log::spy();
        $this->bindTactical([
            new Response(200, [], '[]'),
            new Response(200, [], json_encode([])),
        ]);

        $result = $this->decoded($this->callTool($this->token([self::TOOL])));

        $this->assertSame(['count' => 0, 'custom_fields' => []], $result);
        $this->assertSame(['GET https://tactical.invalid/core/customfields/'], $this->requests());
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_payload_that_is_not_a_list_is_an_error_not_an_empty_result(): void
    {
        Log::spy();
        $this->bindTactical([
            new Response(200, [], json_encode(['results' => [], 'detail' => 'not a list'])),
            new Response(200, [], json_encode([])),
        ]);

        $result = $this->decoded($this->callTool($this->token([self::TOOL])));

        $this->assertSame(['error' => 'Tactical custom fields response was not a list of objects'], $result);
        $this->assertSame(['GET https://tactical.invalid/core/customfields/'], $this->requests());
        $this->assertNotAListWarningLoggedOnce();
    }

    public function test_it_is_listed_for_a_token_holding_it_and_refused_for_one_that_does_not(): void
    {
        $this->bindTactical([
            new Response(200, [], json_encode([])),
        ]);

        $integrationNames = array_column(McpToolRegistry::groups()['integration']['tools'], 'name');
        $this->assertContains(self::TOOL, $integrationNames);
        $this->assertContains(self::TOOL, McpToolRegistry::allToolNames());

        $holder = $this->token([self::TOOL]);
        $this->assertContains(self::TOOL, $this->listedNames($holder));

        $sibling = $this->token(['tactical_list_alert_templates']);
        $this->assertNotContains(self::TOOL, $this->listedNames($sibling));
        $refused = (string) $this->callTool($sibling)->json('result.content.0.text');
        $this->assertStringContainsString('Tool not allowed for this token: '.self::TOOL, $refused);

        $legacy = McpConfig::rotateStaffToken();
        $this->assertNotContains(self::TOOL, $this->listedNames($legacy));

        $this->assertSame([], $this->requests(), 'listing and a refused call must not reach Tactical');
    }
}
