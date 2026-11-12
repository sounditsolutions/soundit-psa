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

class HuntressPlatformReadsTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    private function runRead(bool $get, array $rows, array $input = [], int $status = 200): array
    {
        Setting::setEncrypted('huntress_api_key', 'test-key');
        Setting::setEncrypted('huntress_api_secret', 'test-secret');
        $this->history = [];
        $body = $status === 200 ? ($get ? ['platform_action' => $rows[0]] : ['platform_actions' => $rows, 'pagination' => ['next_page_token' => 'next']]) : ['error' => 'Forbidden'];
        $stack = HandlerStack::create(new MockHandler([new Response($status, [], json_encode($body))]));
        $stack->push(Middleware::history($this->history));
        app()->instance(HuntressClient::class, new HuntressClient([], new HttpClient(['base_uri' => 'https://huntress.example/v1/', 'handler' => $stack])));

        return app(HuntressReadOnlyToolset::class)->execute($get ? 'huntress_get_platform_action' : 'huntress_list_platform_actions', $input + ($get ? ['id' => 7] : []));
    }

    private function row(array $orgs): array
    {
        // Vendor PlatformAction example, corroborated by measured platform_actions keys.
        return ['id' => 7, 'account' => ['id' => 1, 'name' => 'Synthetic account'],
            'organizations' => array_map(fn ($id) => ['id' => $id, 'name' => 'Synthetic organization'], $orgs),
            'subject' => 'Synthetic action', 'severity' => 'low', 'status' => 'open'];
    }

    public function test_wire_contracts_and_scope(): void
    {
        $client = Client::factory()->create(['huntress_organization_id' => 42]);
        Client::factory()->create(['huntress_organization_id' => 43]);
        $filters = ['limit' => 2, 'page_token' => 'cursor', 'sort_field' => 'severity', 'sort_direction' => 'asc', 'status' => 'open', 'severity' => 'low', 'subtype' => 'synthetic'];
        $result = $this->runRead(false, [$this->row([42])], ['client_id' => $client->id] + $filters);
        $this->assertSame(1, $result['count']);
        $this->assertSame('next', $result['next_page_token']);
        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/platform_actions', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertEquals($filters + ['organization_id' => 42], $query);
        $this->assertSame('', (string) $request->getBody());

        $this->assertSame(7, $this->runRead(true, [$this->row([42, 43])])['platform_action']['id']);
        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/platform_actions/7', $request->getUri()->getPath());
        $this->assertSame('', $request->getUri()->getQuery());
        $this->assertSame('', (string) $request->getBody());
        $result = $this->runRead(false, [$this->row([]), $this->row([42, 43]), $this->row([42, 99])]);
        $this->assertSame(1, $result['count']);
        $this->assertSame([42, 43], array_column($result['platform_actions'][0]['organizations'], 'id'));
        $this->assertSame(2, $result['dropped_unclassified']);
        $this->assertStringContainsString('incomplete', $result['note']);
        $result = $this->runRead(true, [$this->row([])]);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('platform_action', $result);
        foreach ([false, true] as $get) {
            $result = $this->runRead($get, [$this->row([])], ['client_id' => $client->id]);
            if ($get) {
                $this->assertArrayHasKey('error', $result);
                $this->assertArrayNotHasKey('platform_action', $result);
            } else {
                $this->assertSame([], $result['platform_actions']);
                $this->assertSame(0, $result['count']);
                $this->assertSame(1, $result['dropped_unclassified']);
            }
        }
        foreach ([false, true] as $get) {
            // Forbidden thing present: actions listing the asked org plus another (mapped or not).
            $shared = $this->runRead($get, [$this->row([42, 43]), $this->row([42, 99]), $this->row([42])], ['client_id' => $client->id]);
            if ($get) {
                $this->assertSame('Platform action is shared with another organization', $shared['error'] ?? null);
                $this->assertArrayNotHasKey('platform_action', $shared);
            } else {
                $this->assertArrayNotHasKey('error', $shared);
                $this->assertSame(1, $shared['count']);
                $this->assertSame([42], array_column($shared['platform_actions'][0]['organizations'], 'id'));
                $this->assertSame(2, $shared['dropped_unclassified']);
                $this->assertStringContainsString('shared with another organization', $shared['note']);
            }
            $foreign = $this->runRead($get, [$this->row([43])], ['client_id' => $client->id]);
            $this->assertSame('Huntress returned a row outside the requested organization', $foreign['error'] ?? null);
            $this->assertArrayNotHasKey('platform_actions', $foreign);
            $this->assertArrayNotHasKey('platform_action', $foreign);
            $this->assertArrayHasKey('error', $this->runRead($get, [$this->row([42])], ['client_id' => $client->id, 'organization_id' => 99]));
            $this->assertCount(0, $this->history);
            $this->assertArrayHasKey('error', $this->runRead($get, [$this->row([42])], ['client_id' => 'bad']));
            $this->assertCount(0, $this->history);
            $this->assertStringContainsString('Huntress HTTP 403: the Huntress API key in Settings may lack access to this endpoint.', $this->runRead($get, [], [], 403)['error']);
            foreach ([['id' => 7], ['organizations' => [['name' => 'Unknown']]]] as $bad) {
                $this->assertArrayHasKey('error', $this->runRead($get, [$bad]));
            }
        }
        $this->assertArrayHasKey('error', $this->runRead(true, [$this->row([42, 99])]));
    }
}
