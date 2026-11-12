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

    public function test_remote_access(): void
    {
        $tool = app(HuntressReadOnlyToolset::class);
        $name = 'huntress_list_remote_access_connections';
        $row = ['agent' => 'synthetic-host', 'client_type' => 'synthetic', 'host' => 'example.test', 'port' => 443, 'first_seen_at' => '2026-01-01T00:00:00Z', 'last_seen_at' => '2026-01-02T00:00:00Z'];
        foreach ([0, 1, 500] as $count) {
            $id = $this->bind(['connections' => array_fill(0, $count, $row)]);
            $result = $tool->execute($name, ['client_id' => $id, 'last_seen_at_min' => '2026-01-01T00:00:00Z', 'limit' => 1, 'page_token' => 'ignored']);
            $this->assertSame($count, $result['count']);
            $this->assertSame(500, $result['capped_at']);
            $this->assertArrayNotHasKey('next_page_token', $result);
            $this->assertStringContainsString('no paging', $result['note']);
            $this->assertStringContainsString('vendor organization filter', $result['note']);
            if ($count === 500) {
                $this->assertStringContainsString('may be incomplete', $result['note']);
            } else {
                $this->assertStringNotContainsString('may be incomplete', $result['note']);
            }
            if ($count > 0) {
                $this->assertStringContainsString('UNTRUSTED HUNTRESS', $result['connections'][0]['agent']);
            }
            $this->wire('remote_access/connections', ['organization_id' => 42, 'last_seen_at_min' => '2026-01-01T00:00:00Z']);
        }
        foreach ([[], ['organization_id' => 42], ['client_id' => $id, 'organization_id' => 99], ['client_id' => $id, 'last_seen_at_min' => '2026-02-30T00:00:00Z'], ['client_id' => null]] as $input) {
            $this->bind(['connections' => []]);
            $this->assertArrayHasKey('error', $tool->execute($name, $input));
            $this->assertCount(0, $this->history);
        }
        $unmapped = Client::factory()->create(['huntress_organization_id' => null]);
        $this->bind(['connections' => []]);
        $this->assertArrayHasKey('error', $tool->execute($name, ['client_id' => $unmapped->id]));
        $this->assertCount(0, $this->history);
        $this->bind(['connections' => []]);
        $this->assertSame(0, $tool->execute($name, [], $id)['count']);
        $this->wire('remote_access/connections', ['organization_id' => 42]);
        $this->bind(['connections' => 'bad']);
        $this->assertArrayHasKey('error', $tool->execute($name, ['client_id' => $id]));
        $this->bind(['error' => 'Forbidden'], 403);
        $result = $tool->execute($name, ['client_id' => $id]);
        $this->assertStringContainsString('403', $result['error']);
        $this->assertStringContainsString("Charlie can widen the key's scope", $result['error']);
        $this->assertStringNotContainsString('https://', $result['error']);
    }

    public function test_external_ports(): void
    {
        $other = Client::factory()->create(['huntress_organization_id' => 43]);
        $tool = app(HuntressReadOnlyToolset::class);
        foreach ([false, true] as $get) {
            $name = $get ? 'huntress_get_external_port' : 'huntress_list_external_ports';
            $key = $get ? 'external_port' : 'external_ports';
            $row = ['id' => 7, 'organization_ids' => [42], 'ip_address' => '93.184.216.34', 'service' => 'synthetic'];
            $id = $this->bind([$key => $get ? $row : [$row], 'pagination' => ['next_page_token' => 'next']]);
            $input = $get ? ['id' => 7] : ['limit' => 2, 'page_token' => 'cursor', 'sort_field' => 'port', 'sort_direction' => 'asc', 'protocol' => 'TCP', 'port' => 22, 'risky_service' => false, 'service' => 'ssh'];
            $result = $tool->execute($name, $input + ['client_id' => $id]);
            $this->assertArrayNotHasKey('error', $result);
            $returned = $get ? $result[$key] : $result[$key][0];
            $this->assertSame([['organization_id' => 42, 'client_id' => $id]], $returned['organizations']);
            $this->assertStringContainsString('UNTRUSTED HUNTRESS', $returned['service']);
            $this->wire('external_ports'.($get ? '/7' : ''), $get ? [] : array_replace($input, ['risky_service' => 'false', 'organization_id' => 42]));
            if (! $get) {
                $this->assertSame('next', $result['next_page_token']);
            }
            foreach ([null, [], ['42'], [99], [42, 99], [42, 43], [43]] as $ids) {
                $row['organization_ids'] = $ids;
                $this->bind([$key => $get ? $row : [$row]]);
                $result = $tool->execute($name, ['id' => 7, 'client_id' => $id]);
                if ($get) {
                    $this->assertArrayHasKey('error', $result);
                } else {
                    $this->assertSame([], $result[$key]);
                    $this->assertSame(1, $result['dropped_unclassified']);
                    $this->assertStringContainsString('incomplete', $result['note']);
                }
            }
            foreach ([null, [], ['42'], [99], [42, 99]] as $ids) {
                $row['organization_ids'] = $ids;
                $this->bind([$key => $get ? $row : [$row]]);
                $result = $tool->execute($name, ['id' => 7]);
                if ($get) {
                    $this->assertArrayHasKey('error', $result);
                } else {
                    $this->assertSame(1, $result['dropped_unclassified']);
                }
            }
            $row['organization_ids'] = [42, 43];
            $this->bind([$key => $get ? $row : [$row]]);
            $result = $tool->execute($name, ['id' => 7]);
            $returned = $get ? $result[$key] : $result[$key][0];
            $this->assertSame([['organization_id' => 42, 'client_id' => $id], ['organization_id' => 43, 'client_id' => $other->id]], $returned['organizations']);
            $this->bind([]);
            $this->assertArrayHasKey('error', $tool->execute($name, ['id' => 7, 'client_id' => $id, 'organization_id' => 43]));
            $this->assertCount(0, $this->history);
            $this->bind(['error' => 'Forbidden'], 403);
            $result = $tool->execute($name, ['id' => 7]);
            $this->assertStringContainsString('403', $result['error']);
            $this->assertStringContainsString("Charlie can widen the key's scope", $result['error']);
            $this->assertStringNotContainsString('https://', $result['error']);
        }
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
