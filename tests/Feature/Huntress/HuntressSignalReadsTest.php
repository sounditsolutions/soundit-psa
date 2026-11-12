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

class HuntressSignalReadsTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    private function bindResponse(array $body, int $status = 200): int
    {
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

    private function row(int $org = 42): array
    {
        // Vendor Signal schema and measured signals(all) key shapes; synthetic values only.
        return ['id' => 7, 'name' => 'Synthetic signal', 'organization' => ['id' => $org, 'name' => 'Synthetic organization'],
            'entity' => ['id' => 8, 'name' => 'Synthetic entity', 'type' => 'agent'],
            'details' => ['rule_name' => 'Synthetic rule', 'country_code' => 'US', 'vpn' => 'Synthetic', 'occurred_at' => '2026-01-01T00:00:00Z'],
            'status' => 'reported', 'type' => 'SIEM'];
    }

    private function execute(string $tool, array $input = []): array
    {
        return app(HuntressReadOnlyToolset::class)->execute('huntress_'.$tool, $input);
    }

    public function test_list_sends_exact_documented_filters_and_cursor(): void
    {
        $id = $this->bindResponse(['signals' => [$this->row()], 'pagination' => ['next_page_token' => 'next']]);
        $filters = ['limit' => 2, 'page_token' => 'cursor', 'sort_field' => 'status', 'sort_direction' => 'asc',
            'investigated_at_min' => '2026-01-01T00:00:00Z', 'investigated_at_max' => '2026-01-02T00:00:00Z',
            'entity_type' => 'agent', 'entity_id' => '8', 'types' => 'SIEM,Managed ITDR', 'statuses' => 'reported,closed'];
        $result = $this->execute('list_signals', ['client_id' => $id] + $filters);
        $this->assertSame(1, $result['count']);
        $this->assertSame('next', $result['next_page_token']);
        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/signals', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertEquals($filters + ['organization_id' => '42'], $query);
        $this->assertSame('', (string) $request->getBody());
    }

    public function test_get_sends_exact_path_and_checks_mapping(): void
    {
        $this->bindResponse(['signal' => $this->row()]);
        $result = $this->execute('get_signal', ['id' => 7]);
        $this->assertSame(7, $result['signal']['id']);
        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/signals/7', $request->getUri()->getPath());
        $this->assertSame('', $request->getUri()->getQuery());
        $this->assertSame('', (string) $request->getBody());
        $this->bindResponse(['signal' => $this->row(99)]);
        $result = $this->execute('get_signal', ['id' => 7]);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('signal', $result);
    }

    public function test_unscoped_list_drops_unmapped_but_scoped_response_refuses_whole_page(): void
    {
        $body = ['signals' => [$this->row(), $this->row(99)], 'pagination' => []];
        $id = $this->bindResponse($body);
        $this->assertSame(1, $this->execute('list_signals')['count']);
        $this->bindResponse($body);
        $result = $this->execute('list_signals', ['client_id' => $id]);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('signals', $result);
    }

    public function test_scope_mismatch_and_malformed_client_refuse_without_requests(): void
    {
        $id = $this->bindResponse([]);
        foreach (['list_signals', 'get_signal'] as $tool) {
            foreach ([['client_id' => $id, 'organization_id' => 99], ['client_id' => 'bad'], ['organization_id' => 99]] as $scope) {
                $this->assertArrayHasKey('error', $this->execute($tool, $scope + ['id' => 7]));
            }
        }
        $this->assertCount(0, $this->history);
    }

    public function test_agents_and_reports_wire_and_scope_contracts(): void
    {
        // Agent/SummaryReport schemas and measured list row keys; synthetic values.
        foreach (['agents' => 'agent', 'reports' => 'report'] as $plural => $singular) {
            $row = ['id' => 7, 'organization_id' => 42] + ($plural === 'agents'
                ? ['hostname' => 'Synthetic host', 'platform' => 'windows', 'ipv4_address' => '93.184.216.34']
                : ['analyst_note' => 'Synthetic report', 'period' => '2026-01-01', 'type' => 'monthly_summary']);
            $filters = ['limit' => 2, 'page_token' => 'cursor', 'sort_field' => 'id', 'sort_direction' => 'asc'] + ($plural === 'agents'
                ? ['platform' => 'windows', 'hostname' => 'Synthetic host', 'os' => 'Synthetic OS', 'version' => '1']
                : ['period_min' => '2026-01-01', 'period_max' => '2026-02-01', 'type' => 'monthly_summary']);
            $this->history = [];
            $id = $this->bindResponse([$plural => [$row], 'pagination' => ['next_page_token' => 'next']]);
            $result = $this->execute('list_'.$plural, ['client_id' => $id] + $filters);
            $this->assertSame(1, $result['count']);
            $this->assertSame('next', $result['next_page_token']);
            $this->assertCount(1, $this->history);
            $request = $this->history[0]['request'];
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('/v1/'.$plural, $request->getUri()->getPath());
            parse_str($request->getUri()->getQuery(), $query);
            $this->assertEquals($filters + ['organization_id' => '42'], $query);
            $this->assertSame('', (string) $request->getBody());

            $this->history = [];
            $this->bindResponse([$singular => $row]);
            $this->assertSame(7, $this->execute('get_'.$singular, ['id' => 7])[$singular]['id']);
            $this->assertCount(1, $this->history);
            $request = $this->history[0]['request'];
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('/v1/'.$plural.'/7', $request->getUri()->getPath());
            $this->assertSame('', $request->getUri()->getQuery());
            $this->assertSame('', (string) $request->getBody());

            $foreign = array_replace($row, ['organization_id' => 99]);
            $this->bindResponse([$plural => [$row, $foreign], 'pagination' => []]);
            $this->assertSame(1, $this->execute('list_'.$plural)['count']);
            $this->bindResponse([$plural => [$row, $foreign], 'pagination' => []]);
            $this->assertArrayHasKey('error', $this->execute('list_'.$plural, ['client_id' => $id]));
            $this->bindResponse([$singular => $foreign]);
            $this->assertArrayHasKey('error', $this->execute('get_'.$singular, ['id' => 7]));
            foreach (['list_'.$plural, 'get_'.$singular] as $tool) {
                $this->history = [];
                $this->bindResponse([]);
                $this->assertArrayHasKey('error', $this->execute($tool, ['id' => 7, 'client_id' => $id, 'organization_id' => 99]));
                $this->assertCount(0, $this->history);
                $this->bindResponse(['error' => 'Forbidden'], 403);
                $this->assertStringContainsString("Charlie can widen the key's scope", $this->execute($tool, ['id' => 7])['error']);
            }
        }
    }

    public function test_signal_errors_and_shape_drift_are_not_empty_lists(): void
    {
        foreach (['list_signals', 'get_signal'] as $tool) {
            $this->bindResponse(['error' => 'Forbidden'], 403);
            $result = $this->execute($tool, ['id' => 7]);
            $this->assertStringContainsString('403', $result['error']);
            $this->assertStringContainsString("Charlie can widen the key's scope", $result['error']);
            $this->assertStringNotContainsString('https://', $result['error']);
            $this->bindResponse(['signals' => [[]], 'signal' => []]);
            $this->assertArrayHasKey('error', $this->execute($tool, ['id' => 7]));
        }
    }
}
