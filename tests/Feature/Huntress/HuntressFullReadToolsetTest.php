<?php

namespace Tests\Feature\Huntress;

use App\Models\Client;
use App\Models\Setting;
use App\Services\Huntress\HuntressClient;
use App\Services\Huntress\HuntressReadOnlyToolset;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HuntressFullReadToolsetTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    private function runQuery(array $input = [], array $logs = [], int $status = 200, string $error = 'Invalid ESQL: Invalid expression'): array
    {
        Setting::setEncrypted('huntress_api_key', 'test-key');
        Setting::setEncrypted('huntress_api_secret', 'test-secret');
        $client = Client::where('huntress_organization_id', 42)->first()
            ?? Client::factory()->create(['huntress_organization_id' => 42]);
        // SiemQueryResult vendor schema: ECS dotted keys, logs + pagination.
        $body = $status === 200 ? ['logs' => $logs, 'pagination' => (object) []]
            : ['error' => $error];
        $stack = HandlerStack::create(new MockHandler([new Response($status, [], json_encode($body))]));
        $stack->push(Middleware::history($this->history));
        app()->instance(HuntressClient::class, new HuntressClient([], new HttpClient([
            'base_uri' => 'https://huntress.example/v1/', 'handler' => $stack,
        ])));

        return app(HuntressReadOnlyToolset::class)->execute('huntress_siem_query', $input + [
            'client_id' => $client->id,
            'esql' => 'from logs | KEEP event.provider',
            'range_start' => '2026-01-01T00:00:00Z',
            'range_end' => '2026-01-02T00:00:00Z',
        ]);
    }

    public function test_siem_injects_first_stage_and_sends_json_not_query(): void
    {
        $result = $this->runQuery(['page_token' => 'cursor'], [['organization_id' => 42, 'event.provider' => 'Synthetic']]);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/siem/query', $request->getUri()->getPath());
        $this->assertSame('', $request->getUri()->getQuery());
        $this->assertSame([
            'esql' => 'FROM logs | WHERE organization_id == 42 | KEEP event.provider',
            'range_start' => '2026-01-01T00:00:00Z',
            'range_end' => '2026-01-02T00:00:00Z',
            'page_token' => 'cursor',
        ], json_decode((string) $request->getBody(), true));
        $this->assertSame('FROM logs | WHERE organization_id == 42 | KEEP event.provider', $result['esql_sent']);
        $this->assertArrayHasKey('event.provider', $result['logs'][0]);
    }

    public function test_siem_refuses_foreign_row_without_partial_results(): void
    {
        $result = $this->runQuery([], [['organization_id' => 42], ['organization_id' => 99]]);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('logs', $result);
    }

    public function test_siem_zero_is_not_all_clear(): void
    {
        $result = $this->runQuery();
        $this->assertSame(0, $result['count']);
        $this->assertStringContainsString('huntress_list_siem_log_sources', $result['note']);
    }

    public function test_siem_fence_refuses_unsafe_sources_without_transport(): void
    {
        foreach (['FROM other', 'FROM logs*', 'FROM logs, other', 'FROM logs METADATA _id', 'FROM logs, x', 'FROM logs METADATA', 'FROM logs | FROM logs', 'FROM logs | ENRICH x', 'FROM logs | LOOKUP JOIN x', 'FROM logs | JOIN x'] as $query) {
            $this->assertArrayHasKey('error', $this->runQuery(['esql' => $query]));
        }
        $this->assertCount(0, $this->history);
    }

    public function test_siem_missing_client_and_invalid_range_do_not_send(): void
    {
        $this->assertArrayHasKey('error', $this->runQuery(['client_id' => null]));
        $this->assertArrayHasKey('error', $this->runQuery(['range_start' => 'tomorrow']));
        $this->assertArrayHasKey('error', $this->runQuery(['range_start' => '2026-02-30T00:00:00Z', 'range_end' => '2026-03-04T00:00:00Z']));
        $this->assertArrayHasKey('error', $this->runQuery(['range_start' => '2026-01-03T00:00:00Z']));
        $this->assertArrayHasKey('error', $this->runQuery(['organization_id' => 99]));
        $this->assertCount(0, $this->history);
    }

    public function test_siem_comments_are_refused_before_transport(): void
    {
        foreach (["FROM logs // x\n| FROM other", 'FROM logs /* */ , other', 'FROM logs | KEEP a /* | FROM x */', 'FROM logs | LIMIT 1 // tail'] as $query) {
            $result = $this->runQuery(['esql' => $query]);
            $this->assertSame('ESQL comments are refused', $result['error']);
            $this->assertCount(0, $this->history);
        }
    }

    public function test_siem_aggregate_and_drop_rows_without_org_keep_first_stage_fence(): void
    {
        foreach (['STATS n = COUNT(*)', 'DROP organization_id'] as $pipeline) {
            $result = $this->runQuery(['esql' => 'FROM logs | '.$pipeline], [['n' => 3]]);
            $this->assertArrayNotHasKey('error', $result);
            $this->assertSame([['n' => 3]], $result['logs']);
            $this->assertSame('FROM logs | WHERE organization_id == 42 | '.$pipeline, $result['esql_sent']);
        }
    }

    public function test_siem_hostile_vendor_errors_are_redacted(): void
    {
        foreach ([
            'See https://api.huntress.io/v1/siem/query?key=synthetic',
            'Authorization: Basic c3ludGhldGljOmNyZWRlbnRpYWw=',
            'api_key=synthetic-private-key',
            'syntheticLongKey0123456789abcdef0123456789',
        ] as $hostile) {
            $result = $this->runQuery([], [], 422, $hostile);
            $this->assertStringContainsString('422', $result['error']);
            foreach (['https://', 'api.huntress.io', 'Authorization', 'c3ludGhldGlj', 'synthetic-private-key', 'syntheticLongKey'] as $secret) {
                $this->assertStringNotContainsString($secret, $result['error']);
            }
        }
    }

    public function test_siem_errors_scream_without_request_url(): void
    {
        foreach ([422, 403, 404] as $status) {
            $result = $this->runQuery([], [], $status);
            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString((string) $status, $result['error']);
            $this->assertStringContainsString('Invalid ESQL: Invalid expression', $result['error']);
            $this->assertStringNotContainsString('https://', $result['error']);
            $this->assertStringNotContainsString('api.huntress.io', $result['error']);
            if ($status === 403) {
                $this->assertStringContainsString("Charlie can widen the key's scope", $result['error']);
            }
            if ($status === 404) {
                $this->assertStringContainsString('SIEM query is not enabled', $result['error']);
            }
        }
    }
}
