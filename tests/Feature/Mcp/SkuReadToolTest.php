<?php

namespace Tests\Feature\Mcp;

use App\Models\Sku;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * #8526: get_sku, a grant-gated psa_read tool. One SKU's selling price,
 * prepaid minutes and the three portal promo fields, and nothing else.
 * Synthetic data only; no HTTP is involved, so any stray request fails.
 */
class SkuReadToolTest extends TestCase
{
    use RefreshDatabase;

    private const START_UTC = '2026-11-27 08:00:00';

    private const END_UTC = '2026-12-01 07:59:59';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
    }

    private function token(array $tools, string $label = 'chet'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
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

    /** @return array<string, mixed> */
    private function ok(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    private function refusal(TestResponse $response): string
    {
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return (string) $response->json('result.content.0.text');
    }

    /** @param  array<string, mixed>  $columns  written straight to the table */
    private function sku(array $columns = []): Sku
    {
        $sku = Sku::create([
            'name' => 'Example Prepaid Hour Block',
            'sku_code' => 'EX-HOUR-BLOCK',
            'unit_price' => 100.00,
            'unit_cost' => 40.00,
            'prepaid_time_minutes' => 60,
            'is_taxable' => false,
            'is_active' => true,
        ]);

        if ($columns !== []) {
            DB::table('skus')->where('id', $sku->id)->update($columns);
        }

        return $sku->fresh();
    }

    public function test_get_sku_is_a_grant_gated_psa_read_taking_exactly_sku_id(): void
    {
        $groups = McpToolRegistry::groups();
        $tool = collect($groups['psa_read']['tools'])->firstWhere('name', 'get_sku');
        $this->assertNotNull($tool, 'get_sku must be registered in psa_read');
        $this->assertTrue($groups['psa_read']['sensitive']);

        $definition = McpToolRegistry::getSkuTool();
        $this->assertSame(['sku_id'], array_keys($definition['input_schema']['properties']));
        $this->assertSame(['sku_id'], $definition['input_schema']['required']);
        $this->assertStringContainsString('Read-only. Requires an explicit token grant.', $definition['description']);
        $this->assertStringContainsString('UTC', $definition['description']);

        // An undeclared argument is refused, not ignored.
        $sku = $this->sku();
        $text = $this->refusal($this->callTool($this->token(['get_sku']), 'get_sku', ['sku_id' => $sku->id, 'include_cost' => true]));
        $this->assertStringContainsString('include_cost', $text);
    }

    public function test_a_granted_token_reads_every_field_of_a_sku_with_a_promo_window(): void
    {
        $sku = $this->sku([
            'unit_price' => '129.99',
            'prepaid_time_minutes' => 90,
            'portal_promo_price' => '99.50',
            'portal_promo_starts_at' => self::START_UTC,
            'portal_promo_ends_at' => self::END_UTC,
        ]);

        $result = $this->ok($this->callTool($this->token(['get_sku']), 'get_sku', ['sku_id' => $sku->id]));

        // assertSame on the whole array: exact keys, exact order, exact types.
        // No cost, sku_code, vendor or any other column may appear.
        $this->assertSame([
            'id' => $sku->id,
            'name' => 'Example Prepaid Hour Block',
            'unit_price' => 129.99,
            'prepaid_time_minutes' => 90,
            'portal_promo_price' => 99.5,
            'portal_promo_starts_at' => '2026-11-27T08:00:00Z',
            'portal_promo_ends_at' => '2026-12-01T07:59:59Z',
        ], $result);
    }

    public function test_a_sku_with_no_promo_and_no_prepaid_time_returns_nulls(): void
    {
        $sku = $this->sku(['unit_price' => '100.00', 'prepaid_time_minutes' => null]);

        $result = $this->ok($this->callTool($this->token(['get_sku']), 'get_sku', ['sku_id' => $sku->id]));

        // JSON writes the float 100.0 as 100, so the wire type is a number either
        // way; what must never arrive is the decimal cast's string "100.00".
        $this->assertTrue(is_int($result['unit_price']) || is_float($result['unit_price']), 'unit_price must be a number, not '.var_export($result['unit_price'], true));
        $result['unit_price'] = (float) $result['unit_price'];

        $this->assertSame([
            'id' => $sku->id,
            'name' => 'Example Prepaid Hour Block',
            'unit_price' => 100.0,
            'prepaid_time_minutes' => null,
            'portal_promo_price' => null,
            'portal_promo_starts_at' => null,
            'portal_promo_ends_at' => null,
        ], $result);
    }

    public function test_an_unknown_sku_id_is_refused_as_not_found(): void
    {
        $sku = $this->sku();

        $text = $this->refusal($this->callTool($this->token(['get_sku']), 'get_sku', ['sku_id' => $sku->id + 1000]));
        $this->assertStringContainsString('SKU not found', $text);
    }

    public function test_a_missing_zero_or_malformed_sku_id_is_refused(): void
    {
        $this->sku();
        $token = $this->token(['get_sku']);

        foreach ([[], ['sku_id' => 0], ['sku_id' => -3], ['sku_id' => 'abc'], ['sku_id' => '1; drop']] as $arguments) {
            $text = $this->refusal($this->callTool($token, 'get_sku', $arguments));
            $this->assertStringContainsString('sku_id is required', $text, json_encode($arguments));
        }
    }

    public function test_ungranted_and_legacy_tokens_cannot_call_get_sku(): void
    {
        $sku = $this->sku();

        foreach ([$this->token(['get_contract', 'get_invoice']), McpConfig::rotateStaffToken()] as $token) {
            $text = $this->refusal($this->callTool($token, 'get_sku', ['sku_id' => $sku->id]));
            $this->assertStringContainsString('not allowed for this token', $text);
            $this->assertStringNotContainsString('Example Prepaid Hour Block', $text);
        }
    }
}
