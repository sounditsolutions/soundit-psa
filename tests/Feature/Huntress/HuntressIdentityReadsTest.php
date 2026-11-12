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

class HuntressIdentityReadsTest extends TestCase
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
        return app(HuntressReadOnlyToolset::class)->execute($get ? 'huntress_get_identity' : 'huntress_list_identities', $input);
    }

    public function test_identity_wire_and_errors(): void
    {
        foreach ([false, true] as $get) {
            $row = ['id' => 7, 'organization' => ['id' => 42, 'name' => 'Synthetic organization'], 'account' => ['id' => 1], 'username' => 'synthetic@example.test'];
            $key = $get ? 'identity' : 'identities';
            $id = $this->bind([$key => $get ? $row : [$row], 'pagination' => ['next_page_token' => 'next']]);
            $input = $get ? ['id' => 7] : ['limit' => 2, 'page_token' => 'cursor', 'sort_field' => 'id', 'sort_direction' => 'asc', 'tenant_type' => 'google_workspace', 'risk_level' => 'low', 'billable' => true, 'enabled' => false, 'external' => false, 'mfa_enabled' => true, 'escalation_id' => 8];
            $result = $this->runTool($get, $input + ['client_id' => $id]);
            $this->assertArrayNotHasKey('error', $result);
            $returned = $get ? $result[$key] : $result[$key][0];
            $this->assertSame(42, $returned['organization']['id']);
            $this->assertStringContainsString('UNTRUSTED HUNTRESS IDENTITY', $returned['organization']['name']);
            $this->assertStringContainsString('UNTRUSTED HUNTRESS IDENTITY', $returned['username']);
            $this->assertCount(1, $this->history);
            $request = $this->history[0]['request'];
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('/v1/identities'.($get ? '/7' : ''), $request->getUri()->getPath());
            parse_str($request->getUri()->getQuery(), $query);
            $expected = $get ? [] : array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $input + ['organization_id' => 42]);
            $this->assertEquals($expected, $query);
            $this->assertSame('', (string) $request->getBody());
            if (! $get) {
                $this->assertSame('next', $result['next_page_token']);
            }
            $this->bind([]);
            $this->assertArrayHasKey('error', $this->runTool($get, $input + ['client_id' => $id, 'organization_id' => 99]));
            $this->assertCount(0, $this->history);
            $this->bind(['error' => 'Forbidden'], 403);
            $result = $this->runTool($get, $input);
            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString('403', $result['error']);
            $this->assertStringContainsString('Huntress HTTP 403: the Huntress API key in Settings may lack access to this endpoint.', $result['error']);
            $this->assertStringNotContainsString('https://', $result['error']);
            $this->assertStringNotContainsString('api.huntress.io', $result['error']);
        }
    }

    public function test_identity_fence_branches(): void
    {
        $valid = ['id' => 7, 'organization' => ['id' => 42]];
        foreach ([[], ['organization' => null], ['organization' => ['id' => null]], ['organization' => ['id' => '42']], ['organization' => ['id' => 99]]] as $scope) {
            // A mapped-looking account/top-level id must not rescue an unclassifiable identity.
            $row = $scope + ['id' => 8, 'account' => ['id' => 42], 'organization_id' => 42];
            $this->bind(['identities' => [$valid, $row]]);
            $result = $this->runTool(false);
            $this->assertArrayNotHasKey('error', $result);
            $this->assertSame([$valid], $result['identities']);
            $this->bind(['identity' => $row]);
            $this->assertArrayHasKey('error', $this->runTool(true, ['id' => 8]));
        }
        Client::factory()->create(['huntress_organization_id' => 43]);
        foreach ([43, 99] as $foreign) {
            foreach ([false, true] as $get) {
                $row = ['id' => 8, 'organization' => ['id' => $foreign]];
                $id = $this->bind([$get ? 'identity' : 'identities' => $get ? $row : [$valid, $row]]);
                $result = $this->runTool($get, ['id' => 8, 'client_id' => $id]);
                $this->assertArrayHasKey('error', $result);
                $this->assertStringContainsString('outside the requested organization', $result['error']);
                $this->assertArrayNotHasKey('identities', $result);
                $this->assertArrayNotHasKey('identity', $result);
            }
        }
    }

    public function test_invalid_identity_filters_send_no_request(): void
    {
        foreach (['billable', 'enabled', 'external', 'mfa_enabled', 'escalation_id'] as $filter) {
            $this->bind([]);
            $this->assertArrayHasKey('error', $this->runTool(false, [$filter => 'invalid']));
            $this->assertCount(0, $this->history);
        }
    }
}
