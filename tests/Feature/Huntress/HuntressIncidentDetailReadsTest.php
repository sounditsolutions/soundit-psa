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
            $this->assertStringContainsString("Charlie can widen the key's scope", $error);
            $this->assertStringNotContainsString('https://', $error);
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
