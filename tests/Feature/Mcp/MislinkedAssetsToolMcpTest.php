<?php

namespace Tests\Feature\Mcp;

use App\Models\Asset;
use App\Models\Client;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * MCP boundary coverage for list_mislinked_assets — the READ-ONLY cross-client
 * mislink sweep in the dormant, grant-gated psa_read group. Explicit-grant only;
 * client_id is an OPTIONAL filter (OMITTED = deliberate fleet-wide sweep,
 * PRESENT-but-malformed = refused); each row carries rule + other-client +
 * evidence; the caveat rides in the response.
 */
class MislinkedAssetsToolMcpTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $tools, string $label = 'chet'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
    }

    private function legacyToken(): string
    {
        return McpConfig::rotateStaffToken();
    }

    /** @param  array<string, mixed>  $arguments */
    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function tools(string $token): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->json('result.tools') ?? [];
    }

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** Seed a genuine cross-client serial collision; returns [clientA, clientB, assetA]. */
    private function seedCrossClientSerial(): array
    {
        $a = Client::factory()->create(['name' => 'Acme']);
        $b = Client::factory()->create(['name' => 'Bravo']);
        $assetA = Asset::factory()->create(['client_id' => $a->id, 'serial_number' => 'DUP-SN', 'hostname' => 'HOST-A']);
        Asset::factory()->create(['client_id' => $b->id, 'serial_number' => 'DUP-SN', 'hostname' => 'HOST-B']);

        return [$a, $b, $assetA];
    }

    public function test_registry_lists_the_tool_in_psa_read_and_it_ships_dormant(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('psa_read', $groups);
        $names = array_column($groups['psa_read']['tools'], 'name');
        $this->assertContains('list_mislinked_assets', $names);

        // Dormant: a legacy (no-grant) token cannot see it.
        $legacyNames = collect($this->tools($this->legacyToken()))->pluck('name')->all();
        $this->assertNotContains('list_mislinked_assets', $legacyNames);

        // Granted: visible, with an OPTIONAL client_id (not in required).
        $scoped = collect($this->tools($this->token(['list_mislinked_assets'])))->keyBy('name');
        $this->assertTrue($scoped->has('list_mislinked_assets'));
        $schema = $scoped['list_mislinked_assets']['inputSchema'];
        $this->assertArrayHasKey('client_id', $schema['properties']);
        $this->assertNotContains('client_id', $schema['required'] ?? []);
    }

    public function test_ungranted_and_legacy_tokens_cannot_call_the_tool(): void
    {
        $this->seedCrossClientSerial();

        foreach ([$this->token(['create_ticket']), $this->legacyToken()] as $token) {
            $response = $this->callTool($token, 'list_mislinked_assets', []);
            $response->assertOk();
            $this->assertTrue((bool) $response->json('result.isError'), 'must be denied without an explicit grant');
            $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
        }
    }

    public function test_fleet_wide_call_returns_both_sides_with_rule_other_client_and_evidence(): void
    {
        [$a, $b, $assetA] = $this->seedCrossClientSerial();
        $token = $this->token(['list_mislinked_assets']);

        $response = $this->callTool($token, 'list_mislinked_assets', []);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertSame('fleet', $result['scope']);
        $this->assertSame(2, $result['tier_a_count']);
        $this->assertArrayHasKey('tier_a', $result);
        $this->assertArrayHasKey('tier_b', $result);

        $row = collect($result['tier_a'])->firstWhere('asset_id', $assetA->id);
        $this->assertSame('duplicate_serial_cross_client', $row['rule']);
        $this->assertSame($b->id, $row['other_client_id']);
        $this->assertSame('Bravo', $row['other_client_name']);
        $this->assertSame('DUP-SN', $row['evidence']['duplicate_serial']);

        // The Tier-A absence caveat must ride in the response text.
        $this->assertStringContainsString('Absence of a Tier A hit is not proof', (string) $response->json('result.content.0.text'));
    }

    public function test_client_id_argument_scopes_the_sweep_to_one_client(): void
    {
        [$a, $b, $assetA] = $this->seedCrossClientSerial();
        $token = $this->token(['list_mislinked_assets']);

        $response = $this->callTool($token, 'list_mislinked_assets', ['client_id' => $a->id]);
        $response->assertOk();
        $result = $this->decodedResult($response);

        $this->assertSame('client:'.$a->id, $result['scope']);
        $this->assertSame(1, $result['tier_a_count']);
        $this->assertSame($assetA->id, $result['tier_a'][0]['asset_id']);
        $this->assertSame($b->id, $result['tier_a'][0]['other_client_id']);
    }

    public function test_include_inactive_passthrough_over_the_boundary(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create();
        Asset::factory()->create(['client_id' => $a->id, 'serial_number' => 'INACT-SN', 'hostname' => 'H-IA', 'is_active' => true]);
        Asset::factory()->create(['client_id' => $b->id, 'serial_number' => 'INACT-SN', 'hostname' => 'H-IB', 'is_active' => false]);
        $token = $this->token(['list_mislinked_assets']);

        $off = $this->decodedResult($this->callTool($token, 'list_mislinked_assets', []));
        $this->assertSame(0, $off['tier_a_count']);

        $on = $this->decodedResult($this->callTool($token, 'list_mislinked_assets', ['include_inactive' => true]));
        $this->assertTrue($on['include_inactive']);
        $this->assertGreaterThanOrEqual(1, $on['tier_a_count']);
    }

    // ── Malformed-scope guards (found by Chet, 2026-07-31) ────────────────────
    //
    // The failure these exist to prevent: a scope the caller MEANT to be narrow
    // silently becoming the WIDEST read the tool can perform — cross-tenant rows
    // for a typo. Every guard asserts the refusal AND that no rows rode along
    // with it; an error message beside a full fleet dump is not a refusal.
    //
    // Note the deliberate asymmetry, and do not "fix" it: an ABSENT client_id is
    // still a fleet-wide sweep (see the fleet-wide tests above). Supplying a
    // broken scope is the caller error; omitting one is a documented choice.

    /** @return array<string, array<int, mixed>> */
    public static function malformedClientIdProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'leading zero string' => ['07'],
            'numeric prefix garbage' => ['42abc'],
            'empty string' => [''],
            'explicit null' => [null],
            'boolean true' => [true],
            'array' => [[7]],
        ];

        // Two values from the original report are deliberately NOT here, because
        // neither can reach the boundary malformed. Both are covered by their own
        // tests below so the reasoning survives:
        //   - a JSON float 7.0 never survives PHP's json_encode in a test client
        //     (it emits `7`), so it needs a raw body — see the raw-JSON test.
        //   - '7 ' is trimmed to '7' by Laravel's global TrimStrings before any
        //     controller sees it, and is a VALID scope in production.
    }

    #[DataProvider('malformedClientIdProvider')]
    public function test_a_present_but_malformed_client_id_is_refused_and_never_widened_to_the_fleet(mixed $malformed): void
    {
        $this->seedCrossClientSerial();
        $token = $this->token(['list_mislinked_assets']);

        $response = $this->callTool($token, 'list_mislinked_assets', ['client_id' => $malformed]);
        $response->assertOk();

        $text = (string) $response->json('result.content.0.text');
        $this->assertTrue(
            (bool) $response->json('result.isError'),
            'a malformed client_id must be refused, not collapsed to a fleet-wide sweep: '.$text
        );
        $this->assertStringContainsString('is not a positive integer', $text);

        // The refusal must carry NO rows — not one asset, not a count.
        $this->assertStringNotContainsString('DUP-SN', $text);
        $this->assertStringNotContainsString('tier_a', $text);
        $this->assertSame([], $this->decodedResult($response));
    }

    public function test_a_malformed_client_id_is_refused_even_when_it_would_have_matched_nothing(): void
    {
        // No seed at all: the refusal is a property of the ARGUMENT, not of the
        // result set, so it must not depend on there being data to leak. It also
        // names the rejected value back, or a typo stays invisible to the caller.
        $token = $this->token(['list_mislinked_assets']);

        $response = $this->callTool($token, 'list_mislinked_assets', ['client_id' => '42abc']);
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('"42abc"', (string) $response->json('result.content.0.text'));
    }

    public function test_a_genuine_json_float_client_id_is_refused_at_the_boundary(): void
    {
        // Must be sent as a RAW body: postJson() would json_encode(7.0) to `7`
        // and silently test nothing. Over the wire a literal 7.0 decodes to
        // PHP float(7), which is neither is_int nor is_string, so it collapses
        // to null — exactly the widening path this guard exists for.
        $this->seedCrossClientSerial();
        $token = $this->token(['list_mislinked_assets']);

        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":'
            .'{"name":"list_mislinked_assets","arguments":{"client_id":7.0}}}';

        $response = $this->call(
            'POST', '/api/mcp/staff', [], [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $body
        );
        $response->assertOk();

        $text = (string) $response->json('result.content.0.text');
        $this->assertTrue((bool) $response->json('result.isError'), $text);
        $this->assertStringContainsString('is not a positive integer', $text);
        $this->assertStringNotContainsString('DUP-SN', $text);
    }

    public function test_a_trailing_space_client_id_is_trimmed_to_a_valid_scope_not_a_hazard(): void
    {
        // Documented deliberately: '7 ' was reported as a widening value, but
        // Laravel's global TrimStrings normalizes it BEFORE the controller, so
        // it is an ordinary valid scope. Recorded so nobody re-files it as a hole.
        [$a, $b, $assetA] = $this->seedCrossClientSerial();
        $token = $this->token(['list_mislinked_assets']);

        $result = $this->decodedResult($this->callTool($token, 'list_mislinked_assets', ['client_id' => (string) $a->id.' ']));

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('client:'.$a->id, $result['scope']);
        $this->assertSame($assetA->id, $result['tier_a'][0]['asset_id']);
        $this->assertSame($b->id, $result['tier_a'][0]['other_client_id']);
    }

    public function test_an_omitted_client_id_is_still_a_deliberate_fleet_wide_sweep(): void
    {
        // Guards the 2026-07-31 ruling in the other direction: the malformed-scope
        // fix must not quietly become "fleet-wide now needs an opt-in". Omission
        // is an affirmative choice here, matching list_email_items/list_phone_calls.
        [, $b, $assetA] = $this->seedCrossClientSerial();
        $token = $this->token(['list_mislinked_assets']);

        $result = $this->decodedResult($this->callTool($token, 'list_mislinked_assets', []));

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('fleet', $result['scope']);
        $this->assertSame(2, $result['tier_a_count']);
        $row = collect($result['tier_a'])->firstWhere('asset_id', $assetA->id);
        $this->assertSame($b->id, $row['other_client_id']);
    }

    public function test_the_published_contract_advertises_no_scope_opt_in(): void
    {
        // The schema must not grow a `scope` arm behind the fix; the tool's
        // breadth is bounded by the explicit grant, not by an extra argument.
        $scoped = collect($this->tools($this->token(['list_mislinked_assets'])))->keyBy('name');
        $schema = $scoped['list_mislinked_assets']['inputSchema'];

        $this->assertArrayNotHasKey('scope', $schema['properties']);
        $this->assertNotContains('client_id', $schema['required'] ?? []);
        $this->assertStringContainsString('omit for a fleet-wide sweep', $scoped['list_mislinked_assets']['description']);
    }

    public function test_a_valid_client_id_scopes_exactly_as_before(): void
    {
        [$a, $b, $assetA] = $this->seedCrossClientSerial();
        $token = $this->token(['list_mislinked_assets']);

        // Both the integer and the canonical numeric-string form still work.
        foreach ([$a->id, (string) $a->id] as $scope) {
            $result = $this->decodedResult($this->callTool($token, 'list_mislinked_assets', ['client_id' => $scope]));

            $this->assertSame('client:'.$a->id, $result['scope']);
            $this->assertSame(1, $result['tier_a_count']);
            $this->assertSame($assetA->id, $result['tier_a'][0]['asset_id']);
            $this->assertSame($b->id, $result['tier_a'][0]['other_client_id']);
        }
    }

    public function test_a_well_formed_but_unknown_client_id_still_fails_closed_at_the_finder(): void
    {
        // The boundary refusal must not shadow the finder's own fail-closed path:
        // 999999 parses fine, so it reaches find(), which rejects it there.
        $this->seedCrossClientSerial();
        $token = $this->token(['list_mislinked_assets']);

        $result = $this->decodedResult($this->callTool($token, 'list_mislinked_assets', ['client_id' => 999999]));

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('does not resolve to a client', $result['error']);
        $this->assertArrayNotHasKey('tier_a', $result);
    }

    public function test_the_grant_gate_still_precedes_the_scope_refusal(): void
    {
        // An ungranted token must be told it has no grant — the scope refusal
        // must not become an oracle for which tools exist behind the gate.
        $response = $this->callTool($this->token(['create_ticket']), 'list_mislinked_assets', ['client_id' => '42abc']);
        $response->assertOk();

        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('not allowed for this token', $text);
        $this->assertStringNotContainsString('is not a positive integer', $text);
    }
}
