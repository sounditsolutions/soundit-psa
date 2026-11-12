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

class HuntressRemainingReadsTest extends TestCase
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

    private function wire(string $path, array $query = []): void
    {
        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/'.$path, $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $actual);
        $this->assertEquals($query, $actual);
        $this->assertSame('', (string) $request->getBody());
    }

    public function test_known_vpns(): void
    {
        $body = ['known_vpns' => [['name' => 'Synthetic VPN', 'slug' => 'SYNTHETIC']]];
        $id = $this->bind($body);
        $tool = app(HuntressReadOnlyToolset::class);
        $result = $tool->execute('huntress_list_known_vpns', []);
        $this->assertSame(1, $result['count']);
        $this->assertStringContainsString('UNTRUSTED HUNTRESS', $result['known_vpns'][0]['name']);
        $this->wire('known_vpns');
        foreach ([['client_id' => $id], ['organization_id' => 42], ['client_id' => null]] as $scope) {
            $this->bind($body);
            $result = $tool->execute('huntress_list_known_vpns', $scope);
            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString('account-level', $result['error']);
            $this->assertCount(0, $this->history);
        }
        $this->bind($body);
        $this->assertArrayHasKey('error', $tool->execute('huntress_list_known_vpns', [], $id));
        $this->assertCount(0, $this->history);
        $this->bind(['known_vpns' => []]);
        $this->assertSame(0, $tool->execute('huntress_list_known_vpns', [])['count']);
        $this->bind(['known_vpns' => [['name' => 'Bad']]]);
        $this->assertArrayHasKey('error', $tool->execute('huntress_list_known_vpns', []));
        $this->bind(['error' => 'Forbidden'], 403);
        $result = $tool->execute('huntress_list_known_vpns', []);
        $this->assertStringContainsString('403', $result['error']);
        $this->assertStringContainsString("Charlie can widen the key's scope", $result['error']);
        $this->assertStringNotContainsString('https://', $result['error']);
    }
}
