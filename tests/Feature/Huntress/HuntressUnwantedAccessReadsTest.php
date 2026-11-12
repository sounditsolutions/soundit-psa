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

class HuntressUnwantedAccessReadsTest extends TestCase
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
        return app(HuntressReadOnlyToolset::class)->execute($get ? 'huntress_get_unwanted_access_rule' : 'huntress_list_unwanted_access_rules', $input);
    }

    public function test_rule_wire_and_errors(): void
    {
        foreach ([false, true] as $get) {
            $row = ['id' => 7, 'applied_to' => ['type' => 'Organization', 'id' => 42], 'note' => 'Synthetic note'];
            $key = $get ? 'unwanted_access_rule' : 'unwanted_access_rules';
            $id = $this->bind([$key => $get ? $row : [$row], 'pagination' => ['next_page_token' => 'next']]);
            $input = $get ? ['id' => 7] : ['limit' => 2, 'page_token' => 'cursor', 'identity_id' => 8, 'type' => 'expected', 'status' => 'active', 'scope' => 'organization', 'category' => 'country', 'country_code' => 'US', 'vpn' => 'synthetic', 'ip_address' => '93.184.216.34', 'logic' => 'standard'];
            $result = $this->runTool($get, $input + ['client_id' => $id]);
            $this->assertArrayNotHasKey('error', $result);
            $returned = $get ? $result[$key] : $result[$key][0];
            $this->assertSame(42, $returned['applied_to']['id']);
            $this->assertStringContainsString('UNTRUSTED HUNTRESS', $returned['note']);
            $this->assertCount(1, $this->history);
            $request = $this->history[0]['request'];
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('/v1/unwanted_access_rules'.($get ? '/7' : ''), $request->getUri()->getPath());
            parse_str($request->getUri()->getQuery(), $query);
            $this->assertEquals($get ? [] : array_map('strval', $input + ['organization_id' => 42]), $query);
            $this->assertSame('', (string) $request->getBody());
            if (! $get) {
                $this->assertSame('next', $result['next_page_token']);
                $this->assertSame(0, $result['dropped_unclassified']);
            }
            $this->bind([]);
            $this->assertArrayHasKey('error', $this->runTool($get, $input + ['client_id' => $id, 'organization_id' => 99]));
            $this->assertCount(0, $this->history);
            $this->bind(['error' => 'Forbidden'], 403);
            $result = $this->runTool($get, $input);
            $this->assertStringContainsString('403', $result['error']);
            $this->assertStringContainsString("Charlie can widen the key's scope", $result['error']);
            $this->assertStringNotContainsString('https://', $result['error']);
            $this->assertStringNotContainsString('api.huntress.io', $result['error']);
        }
    }

    public function test_rule_fence_branches(): void
    {
        foreach ([null, [], ['type' => 'Identity', 'id' => 42], ['type' => 'Account', 'id' => 42], ['type' => 'Organization', 'id' => '42'], ['type' => 'Organization', 'id' => 99]] as $scope) {
            $row = ['id' => 8, 'applied_to' => $scope];
            $this->bind(['unwanted_access_rules' => [$row]]);
            $result = $this->runTool(false);
            $this->assertSame([], $result['unwanted_access_rules']);
            $this->assertSame(1, $result['dropped_unclassified']);
            $this->assertStringContainsString('incomplete', $result['note']);
            $this->bind(['unwanted_access_rule' => $row]);
            $this->assertArrayHasKey('error', $this->runTool(true, ['id' => 8]));
        }
        Client::factory()->create(['huntress_organization_id' => 43]);
        foreach ([43, 99] as $foreign) {
            foreach ([false, true] as $get) {
                $row = ['id' => 8, 'applied_to' => ['type' => 'Organization', 'id' => $foreign]];
                $id = $this->bind([$get ? 'unwanted_access_rule' : 'unwanted_access_rules' => $get ? $row : [$row]]);
                $result = $this->runTool($get, ['id' => 8, 'client_id' => $id]);
                $this->assertArrayHasKey('error', $result);
                $this->assertStringContainsString('outside the requested organization', $result['error']);
            }
        }
    }
}
