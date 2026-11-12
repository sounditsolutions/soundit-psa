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

class HuntressIncidentDetailReadsTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    private function bind(int $org, string $family, bool $get, int $status = 200): void
    {
        Setting::setEncrypted('huntress_api_key', 'test-key');
        Setting::setEncrypted('huntress_api_secret', 'test-secret');
        $this->history = [];
        // Vendor Comment/Remediation definitions and measured remediation row keys.
        $row = $family === 'comments' ? ['id' => 9, 'body' => 'Synthetic comment', 'author' => 'Synthetic author']
            : ['id' => 9, 'type' => 'manual', 'action' => 'Synthetic action', 'parameters' => '', 'status' => 'completed'];
        $body = $get ? ['remediation' => $row] : [$family => [$row], 'pagination' => ['next_page_token' => 'next']];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['incident_report' => ['id' => 7, 'organization_id' => $org]])),
            new Response($status, [], json_encode($status === 200 ? $body : ['error' => 'Forbidden'])),
        ]));
        $stack->push(Middleware::history($this->history));
        app()->instance(HuntressClient::class, new HuntressClient([], new HttpClient(['base_uri' => 'https://huntress.example/v1/', 'handler' => $stack])));
    }

    public function test_every_child_wire_parent_gate_and_scope(): void
    {
        $client = Client::factory()->create(['huntress_organization_id' => 42]);
        foreach (['list_incident_comments' => 'comments', 'list_incident_remediations' => 'remediations', 'get_incident_remediation' => 'remediations'] as $tool => $family) {
            $get = str_starts_with($tool, 'get_');
            $input = ['incident_report_id' => 7, 'client_id' => $client->id];
            $filters = $get ? [] : ['limit' => 2, 'page_token' => 'cursor', 'sort_field' => 'id', 'sort_direction' => 'asc'];
            if (! $get && $family === 'remediations') {
                $filters += ['types' => ['assisted', 'manual'], 'statuses' => ['approved', 'completed']];
            }
            $input += $filters + ($get ? ['remediation_id' => 9] : []);
            $this->bind(42, $family, $get);
            $result = app(HuntressReadOnlyToolset::class)->execute('huntress_'.$tool, $input);
            $this->assertArrayNotHasKey('error', $result);
            $this->assertSame($get ? 9 : 1, $get ? $result['remediation']['id'] : $result['count']);
            $this->assertCount(2, $this->history);
            foreach ($this->history as $index => $exchange) {
                $request = $exchange['request'];
                $this->assertSame('GET', $request->getMethod());
                $this->assertSame('/v1/incident_reports/7'.($index ? '/'.$family.($get ? '/9' : '') : ''), $request->getUri()->getPath());
                $expected = $index ? $filters : [];
                foreach (['types', 'statuses'] as $key) {
                    if (isset($expected[$key])) {
                        $expected[$key.'[]'] = implode(',', $expected[$key]);
                        unset($expected[$key]);
                    }
                }
                $actual = [];
                foreach (array_filter(explode('&', $request->getUri()->getQuery())) as $pair) {
                    [$key, $value] = explode('=', $pair, 2);
                    $actual[urldecode($key)] = urldecode($value);
                }
                $this->assertEquals($expected, $actual);
                $this->assertSame('', (string) $request->getBody());
            }
            $this->bind(99, $family, $get);
            $this->assertArrayHasKey('error', app(HuntressReadOnlyToolset::class)->execute('huntress_'.$tool, $input));
            $this->assertCount(1, $this->history);
            $this->bind(42, $family, $get);
            $this->assertArrayHasKey('error', app(HuntressReadOnlyToolset::class)->execute('huntress_'.$tool, $input + ['organization_id' => 99]));
            $this->assertCount(0, $this->history);
            $this->bind(42, $family, $get, 403);
            $error = app(HuntressReadOnlyToolset::class)->execute('huntress_'.$tool, $input)['error'];
            $this->assertStringContainsString('Huntress HTTP 403: the Huntress API key in Settings may lack access to this endpoint.', $error);
            $this->assertStringNotContainsString('https://', $error);
        }
    }

    public function test_parent_failures_stop_before_children_and_malformed_children_scream(): void
    {
        $client = Client::factory()->create(['huntress_organization_id' => 42]);
        Client::factory()->create(['huntress_organization_id' => 43]);
        foreach (['list_incident_comments', 'list_incident_remediations', 'get_incident_remediation'] as $tool) {
            $get = str_starts_with($tool, 'get_');
            $family = $tool === 'list_incident_comments' ? 'comments' : 'remediations';
            $input = ['client_id' => $client->id, 'incident_report_id' => 7, 'remediation_id' => 9];
            $this->bind(43, $family, $get);
            $result = app(HuntressReadOnlyToolset::class)->execute('huntress_'.$tool, $input);
            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString('outside the mapped scope', $result['error']);
            $this->assertCount(1, $this->history);

            foreach ([
                [new Response(403, [], '{"error":"Forbidden"}'), 1, 'HTTP 403'],
                [new Response(200, [], '{"incident_report":{}}'), 1, 'outside the mapped scope'],
                [new Response(200, [], '{"incident_report":{"organization_id":"42"}}'), 1, 'outside the mapped scope'],
                [new Response(200, [], '{"unexpected":[]}'), 2, 'unrecognized'],
                [new Response(200, [], json_encode($get ? ['remediation' => ['id' => 'bad']] : [$family => [['id' => 'bad']]])), 2, 'unrecognized'],
            ] as [$response, $count, $message]) {
                $this->history = [];
                $responses = $count === 1 ? [$response] : [new Response(200, [], '{"incident_report":{"id":7,"organization_id":42}}'), $response];
                $stack = HandlerStack::create(new MockHandler($responses));
                $stack->push(Middleware::history($this->history));
                app()->instance(HuntressClient::class, new HuntressClient([], new HttpClient(['base_uri' => 'https://huntress.example/v1/', 'handler' => $stack])));
                $result = app(HuntressReadOnlyToolset::class)->execute('huntress_'.$tool, $input);
                $this->assertStringContainsString($message, $result['error']);
                $this->assertStringNotContainsString('https://', $result['error']);
                $this->assertCount($count, $this->history);
                $this->assertSame('/v1/incident_reports/7', $this->history[0]['request']->getUri()->getPath());
            }
        }
    }

    public function test_lists_longer_than_thirty_rows_are_returned_whole(): void
    {
        Setting::setEncrypted('huntress_api_key', 'test-key');
        Setting::setEncrypted('huntress_api_secret', 'test-secret');
        $client = Client::factory()->create(['huntress_organization_id' => 42]);
        $rows = array_map(fn (int $i) => ['id' => $i, 'type' => 'manual', 'action' => 'Synthetic action', 'parameters' => '', 'status' => 'completed'], range(1, 31));
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['incident_report' => ['id' => 7, 'organization_id' => 42]])),
            new Response(200, [], json_encode(['remediations' => $rows, 'pagination' => []])),
        ]));
        $stack->push(Middleware::history($this->history));
        app()->instance(HuntressClient::class, new HuntressClient([], new HttpClient(['base_uri' => 'https://huntress.example/v1/', 'handler' => $stack])));
        $result = app(HuntressReadOnlyToolset::class)->execute('huntress_list_incident_remediations', ['incident_report_id' => 7, 'client_id' => $client->id, 'limit' => 100]);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(31, $result['count']);
        $this->assertCount(31, $result['remediations']);
        $this->assertTrue(array_is_list($result['remediations']));
        $this->assertSame(31, $result['remediations'][30]['id']);
        $this->assertCount(2, $this->history);
    }

    public function test_empty_remediation_filters_refuse_before_transport(): void
    {
        $client = Client::factory()->create(['huntress_organization_id' => 42]);
        foreach (['types', 'statuses'] as $filter) {
            $this->bind(42, 'remediations', false);
            $result = app(HuntressReadOnlyToolset::class)->execute('huntress_list_incident_remediations', [
                'incident_report_id' => 7, 'client_id' => $client->id, $filter => [],
            ]);
            $this->assertSame($filter.' must not be empty', $result['error'] ?? null);
            $this->assertCount(0, $this->history);
        }
    }

    public function test_invalid_remediation_enums_refuse_before_transport(): void
    {
        foreach (['types' => ['wrong'], 'statuses' => ['wrong']] as $key => $values) {
            $this->bind(42, 'remediations', false);
            $this->assertArrayHasKey('error', app(HuntressReadOnlyToolset::class)->execute('huntress_list_incident_remediations', ['incident_report_id' => 7, $key => $values]));
            $this->assertCount(0, $this->history);
        }
    }
}
