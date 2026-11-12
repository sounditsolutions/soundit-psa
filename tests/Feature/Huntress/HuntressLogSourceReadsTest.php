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

class HuntressLogSourceReadsTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    private function bind(array $body, int $status = 200): int
    {
        $this->history = [];
        Setting::setEncrypted('huntress_api_key', 'test-key');
        Setting::setEncrypted('huntress_api_secret', 'test-secret');
        $client = Client::where('huntress_organization_id', 42)->first()
            ?? Client::factory()->create(['huntress_organization_id' => 42]);
        $stack = HandlerStack::create(new MockHandler([new Response($status, [], json_encode($body))]));
        $stack->push(Middleware::history($this->history));
        app()->instance(HuntressClient::class, new HuntressClient([], new HttpClient([
            'base_uri' => 'https://huntress.example/v1/', 'handler' => $stack,
        ])));

        return $client->id;
    }

    private function runTool(bool $get, array $input = []): array
    {
        return app(HuntressReadOnlyToolset::class)->execute($get ? 'huntress_get_siem_log_source' : 'huntress_list_siem_log_sources', $input);
    }

    public function test_wire_scope_and_errors_for_every_log_source_route(): void
    {
        // Fixtures follow vendor LogSource/LogSourceWithEventCounts/MisconfiguredLogSource schemas.
        foreach (['log_sources', 'misconfigured_log_sources', 'log_source'] as $key) {
            $get = $key === 'log_source';
            $misconfigured = $key === 'misconfigured_log_sources';
            $row = ['organization' => ['id' => 42, 'name' => 'Synthetic organization']] + ($misconfigured
                ? ['agent_id' => 7, 'hostname' => 'Synthetic host', 'status' => 'unresolved', 'misconfigured_policies' => []]
                : ['id' => 7, 'name' => 'Synthetic source', 'type_code' => 'meraki_cloud', 'status' => 'active']);
            $body = [$key => $get ? $row : [$row], 'pagination' => ['next_page_token' => 'next']];
            $id = $this->bind($body);
            $input = $get ? ['id' => 7] : ['misconfigured' => $misconfigured, 'limit' => 2, 'page_token' => 'cursor'];
            if (! $get && ! $misconfigured) {
                $input += ['only_for_account' => false, 'type_code' => 'meraki_cloud'];
            }
            $result = $this->runTool($get, $input + ['client_id' => $id]);
            $this->assertArrayNotHasKey('error', $result);
            if (! $get) {
                $this->assertSame(1, $result['count']);
                $this->assertSame('next', $result['next_page_token']);
            }
            $this->assertCount(1, $this->history);
            $request = $this->history[0]['request'];
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('/v1/siem/log_sources'.($get ? '/7' : ($misconfigured ? '/misconfigured' : '')), $request->getUri()->getPath());
            parse_str($request->getUri()->getQuery(), $query);
            $expected = $get ? [] : ['limit' => '2', 'page_token' => 'cursor', 'organization_id' => '42'];
            if (! $get && ! $misconfigured) {
                $expected += ['only_for_account' => 'false', 'type_code' => 'meraki_cloud'];
            }
            $this->assertEquals($expected, $query);
            $this->assertSame('', (string) $request->getBody());

            $foreign = array_replace($row, ['organization' => ['id' => 99, 'name' => 'Unmapped']]);
            $this->bind([$key => $get ? $foreign : [$row, $foreign]]);
            $result = $this->runTool($get, $input);
            if ($get) {
                $this->assertArrayHasKey('error', $result);
            } else {
                $this->assertSame(1, $result['count']);
            }
            $this->bind([$key => $get ? $foreign : [$row, $foreign]]);
            $this->assertArrayHasKey('error', $this->runTool($get, $input + ['client_id' => $id]));
            $this->bind([]);
            $this->assertArrayHasKey('error', $this->runTool($get, $input + ['client_id' => $id, 'organization_id' => 99]));
            $this->assertCount(0, $this->history);
            $this->bind(['error' => 'Forbidden'], 403);
            $result = $this->runTool($get, $input);
            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString('403', $result['error']);
            $this->assertStringContainsString("Charlie can widen the key's scope", $result['error']);
            $this->assertStringNotContainsString('https://', $result['error']);
            $this->assertStringNotContainsString('api.huntress.io', $result['error']);
            $this->bind([$key => $get ? [] : [[]]]);
            $this->assertArrayHasKey('error', $this->runTool($get, $input));
        }
    }
}
