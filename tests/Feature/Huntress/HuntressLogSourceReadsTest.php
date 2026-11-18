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
            $this->assertStringContainsString('Huntress HTTP 403: the Huntress API key in Settings may lack access to this endpoint; the key carries its Huntress user\'s permissions, so check that user\'s role.', $result['error']);
            $this->assertStringNotContainsString('https://', $result['error']);
            $this->assertStringNotContainsString('api.huntress.io', $result['error']);
            $this->bind([$key => $get ? [] : [[]]]);
            $this->assertArrayHasKey('error', $this->runTool($get, $input));
        }
    }

    public function test_misconfigured_refuses_filters_it_cannot_send(): void
    {
        foreach (['type_code' => 'meraki_cloud', 'only_for_account' => false] as $key => $value) {
            $id = $this->bind(['misconfigured_log_sources' => []]);
            $result = $this->runTool(false, ['misconfigured' => true, $key => $value, 'client_id' => $id]);
            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString($key.' cannot be combined with misconfigured=true', $result['error']);
            $this->assertArrayNotHasKey('misconfigured_log_sources', $result);
            $this->assertCount(0, $this->history);
        }
    }

    /** Measured 2026-11-18 LogSource row: organization is {id, name}, or null on an account-level source. Values are synthetic. */
    private function source(int $id, mixed $organization): array
    {
        return ['id' => $id, 'name' => 'Synthetic source '.$id, 'type_code' => 'meraki_cloud', 'status' => 'active', 'organization' => $organization];
    }

    public function test_unscoped_list_drops_and_counts_a_null_organization_source(): void
    {
        $this->bind(['log_sources' => [
            $this->source(1, ['id' => 42, 'name' => 'Synthetic organization']),
            $this->source(2, null),
            $this->source(3, ['id' => 42, 'name' => 'Synthetic organization']),
        ], 'pagination' => ['next_page_token' => 'next']]);
        $result = $this->runTool(false, ['limit' => 500]);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, $result['count']);
        $this->assertSame(1, $result['dropped_unclassified']);
        $this->assertSame([1, 3], array_column($result['log_sources'], 'id'));
        $this->assertSame([42, 42], array_column(array_column($result['log_sources'], 'organization'), 'id'));
        $this->assertSame('next', $result['next_page_token']);
        $this->assertStringContainsString('no organization (account-level) are dropped and counted', $result['note']);
        $this->assertStringContainsString('not shown', $result['note']);
        $this->assertCount(1, $this->history);
        parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
        $this->assertEquals(['limit' => '500'], $query);
    }

    public function test_unscoped_list_with_no_dropped_source_still_carries_the_count_and_note(): void
    {
        $this->bind(['log_sources' => [$this->source(1, ['id' => 42, 'name' => 'Synthetic organization'])]]);
        $result = $this->runTool(false, []);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['count']);
        $this->assertSame(0, $result['dropped_unclassified']);
        $this->assertSame('Log sources with no organization (account-level) are dropped and counted in dropped_unclassified; they are not shown.', $result['note']);
    }

    public function test_list_description_states_where_a_null_organization_source_is_dropped_and_where_it_fails(): void
    {
        $description = collect(HuntressReadOnlyToolset::definitions())->firstWhere('name', 'huntress_list_siem_log_sources')['description'];
        $this->assertStringContainsString('On a list with no client or organization scope and without misconfigured=true, sources whose organization is null (account-level) are left out and counted in dropped_unclassified. With misconfigured=true, or on a list scoped to a client or organization, such a source makes the call fail.', $description);
        $this->assertStringNotContainsString('returns no rows', $description);
    }

    public function test_only_for_account_returns_no_rows_and_counts_the_account_level_sources(): void
    {
        $this->bind(['log_sources' => [$this->source(2, null)]]);
        $result = $this->runTool(false, ['only_for_account' => true]);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['log_sources']);
        $this->assertSame(1, $result['dropped_unclassified']);
        $this->assertStringContainsString('account-level', $result['note']);
        $this->assertStringContainsString('not shown', $result['note']);
        parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
        $this->assertEquals(['limit' => '10', 'only_for_account' => 'true'], $query);

        $this->bind(['log_sources' => [$this->source(2, null), $this->source(4, null), $this->source(5, null)]]);
        $result = $this->runTool(false, ['only_for_account' => true]);
        $this->assertSame(0, $result['count']);
        $this->assertSame(3, $result['dropped_unclassified']);
    }

    public function test_a_present_but_malformed_organization_still_refuses_the_read(): void
    {
        $good = $this->source(1, ['id' => 42, 'name' => 'Synthetic organization']);
        $malformed = [['id' => '42', 'name' => 'Synthetic organization'], ['name' => 'Synthetic organization'], ['id' => null], 'Synthetic organization', [42], [], 42, false];
        foreach ($malformed as $organization) {
            $this->bind(['log_sources' => [$good, $this->source(2, $organization)]]);
            $this->assertSame(['error' => 'Huntress returned an unrecognized row organization'], $this->runTool(false, []), json_encode($organization));
        }
        // organization key absent is malformed, not account-level: still refused.
        $absent = $this->source(2, null);
        unset($absent['organization']);
        $this->bind(['log_sources' => [$good, $absent]]);
        $this->assertSame(['error' => 'Huntress returned an unrecognized row organization'], $this->runTool(false, []));
        // A get of one null-organization source and a misconfigured row with null organization keep refusing.
        $id = $this->bind(['log_source' => $this->source(2, null)]);
        $this->assertSame(['error' => 'Huntress returned an unrecognized row organization'], $this->runTool(true, ['id' => 2]));
        foreach ([['client_id' => $id], []] as $scope) {
            $this->bind(['misconfigured_log_sources' => [['organization' => null, 'agent_id' => 7, 'hostname' => 'Synthetic host', 'status' => 'unresolved', 'misconfigured_policies' => []]]]);
            $this->assertSame(['error' => 'Huntress returned an unrecognized row organization'], $this->runTool(false, ['misconfigured' => true] + $scope), json_encode($scope));
        }
    }

    /**
     * A scoped call (client_id or organization_id) keeps its rows, its organization_id
     * parameter, its refusals and the output shape it had before the null-organization drop:
     * count, log_sources and next_page_token, with no note and no dropped_unclassified.
     * On a scoped call a null-organization row refuses the read; it is never dropped.
     */
    public function test_scoped_list_keeps_its_scope_and_refusals(): void
    {
        $other = Client::factory()->create(['huntress_organization_id' => 43]);
        foreach (['client_id', 'organization_id'] as $key) {
            $id = $this->bind(['log_sources' => [$this->source(1, ['id' => 42, 'name' => 'Synthetic organization'])]]);
            $result = $this->runTool(false, $key === 'client_id' ? ['client_id' => $id] : ['organization_id' => 42]);
            $this->assertSame(['count', 'log_sources', 'next_page_token'], array_keys($result), $key);
            $this->assertArrayNotHasKey('note', $result);
            $this->assertArrayNotHasKey('dropped_unclassified', $result);
            $this->assertSame(1, $result['count']);
            $this->assertSame([1], array_column($result['log_sources'], 'id'));
            $this->assertStringContainsString('UNTRUSTED HUNTRESS', $result['log_sources'][0]['name']);
            parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
            $this->assertEquals(['limit' => '10', 'organization_id' => '42'], $query);
        }
        // A row of another mapped organization on a scoped call refuses; it is never dropped.
        $this->bind(['log_sources' => [$this->source(1, ['id' => 42, 'name' => 'Synthetic organization']), $this->source(9, ['id' => 43, 'name' => 'Synthetic organization'])]]);
        $this->assertSame(['error' => 'Huntress returned a row outside the requested organization'], $this->runTool(false, ['client_id' => $id]));
        $this->bind(['log_sources' => [$this->source(9, ['id' => 43, 'name' => 'Synthetic organization'])]]);
        $this->assertSame([9], array_column($this->runTool(false, ['client_id' => $other->id])['log_sources'], 'id'));
        // A null-organization row on a scoped call is not attributed to the requested organization:
        // it refuses, as before; it is never dropped and counted.
        foreach ([['client_id' => $id], ['organization_id' => 42]] as $scope) {
            $this->bind(['log_sources' => [$this->source(1, ['id' => 42, 'name' => 'Synthetic organization']), $this->source(2, null)]]);
            $this->assertSame(['error' => 'Huntress returned an unrecognized row organization'], $this->runTool(false, $scope), json_encode($scope));
        }
    }

    public function test_scoped_only_for_account_list_refuses_a_null_organization_source(): void
    {
        foreach (['client_id', 'organization_id'] as $key) {
            $id = $this->bind(['log_sources' => [$this->source(2, null)]]);
            $scope = $key === 'client_id' ? ['client_id' => $id] : ['organization_id' => 42];
            $result = $this->runTool(false, ['only_for_account' => true] + $scope);
            $this->assertSame(['error' => 'Huntress returned an unrecognized row organization'], $result, $key);
            parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
            $this->assertEquals(['limit' => '10', 'only_for_account' => 'true', 'organization_id' => '42'], $query, $key);
        }
    }

    public function test_unscoped_list_with_a_non_array_row_refuses_even_beside_a_null_organization_source(): void
    {
        $this->bind(['log_sources' => [
            $this->source(2, null),
            'Synthetic text row',
            $this->source(1, ['id' => 42, 'name' => 'Synthetic organization']),
        ]]);
        $this->assertSame(['error' => 'Huntress returned an unrecognized row organization'], $this->runTool(false, []));
    }
}
