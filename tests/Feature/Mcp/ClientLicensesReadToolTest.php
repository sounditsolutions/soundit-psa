<?php

namespace Tests\Feature\Mcp;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Models\Client;
use App\Models\Contract;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Setting;
use App\Services\AppRiver\AppRiverLicenseSyncService;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * #8544: list_client_licenses, a grant-gated, client-scoped psa_read tool.
 * A client's licence rows with their type, contracts and the AppRiver
 * staleness flag, and no cost, minimum or note fields. Synthetic data only;
 * no HTTP is involved, so any stray request fails.
 */
class ClientLicensesReadToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function type(string $vendor = 'appriver', string $name = 'Example Suite Plan'): LicenseType
    {
        return LicenseType::create([
            'name' => $name,
            'vendor' => $vendor,
            'vendor_sku_id' => $vendor === 'appriver' ? 'EX-'.uniqid() : null,
            'default_unit_cost' => 7.77,
            'cost_divisor' => 1,
            'minimum_quantity' => 3,
            'minimum_cost' => 33.33,
            'notes' => 'TYPE-NOTE-MARKER',
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function license(Client $client, LicenseType $type, array $overrides = []): License
    {
        return License::create(array_merge([
            'license_type_id' => $type->id,
            'client_id' => $client->id,
            'quantity' => 10,
            'assigned_quantity' => 8,
            'scheduled_quantity' => null,
            'vendor_ref' => 'VREF-'.uniqid(),
            'status' => 'active',
            'vendor_status' => null,
            'synced_at' => now()->subHours(2),
            'notes' => 'LICENSE-NOTE-MARKER',
        ], $overrides));
    }

    private function contract(Client $client, string $name): Contract
    {
        return Contract::create([
            'client_id' => $client->id,
            'name' => $name,
            'type' => ContractType::Managed,
            'status' => ContractStatus::Active,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ]);
    }

    public function test_list_client_licenses_is_a_grant_gated_psa_read_taking_client_id(): void
    {
        $groups = McpToolRegistry::groups();
        $tool = collect($groups['psa_read']['tools'])->firstWhere('name', 'list_client_licenses');
        $this->assertNotNull($tool, 'list_client_licenses must be registered in psa_read');
        $this->assertTrue($groups['psa_read']['sensitive']);

        $definition = McpToolRegistry::listClientLicensesTool();
        $this->assertSame(['client_id'], array_keys($definition['input_schema']['properties']));
        $this->assertSame(['client_id'], $definition['input_schema']['required']);
        $this->assertStringContainsString('Read-only. Requires an explicit token grant.', $definition['description']);
        $this->assertStringContainsString('48 hours', $definition['description']);
        $this->assertStringContainsString('every vendor licence sync', $definition['description']);
        $this->assertStringContainsString('It is false for every other vendor and for rows with synced_at null.', $definition['description']);
        $this->assertStringNotContainsString('for manual rows', $definition['description']);
        $this->assertStringContainsString('whose vendor_status is not Suspended or Pending', $definition['description']);
        $this->assertStringContainsString('did not skip as referred', $definition['description']);
        $this->assertStringContainsString('stage Active and is_active', $definition['description']);
        $this->assertStringContainsString('a manually entered row can sit on any vendor', $definition['description']);
        $this->assertStringContainsString('is_manual is true exactly while synced_at is null, so false does not prove a row was never entered by hand.', $definition['description']);
        $this->assertStringNotContainsString('no sync has stamped', $definition['description']);
        $this->assertStringContainsString("A vendor sync or count that writes the same row, a vendor sync's cleanup, or removing that vendor's mapping from the client can stamp synced_at on a hand-entered row of that vendor's licence types (the cleanup and the mapping removal also zero and suspend it), and that row then reads is_manual false.", $definition['description']);
        $this->assertStringContainsString('synced_at (UTC ISO-8601; null while nothing has stamped the row)', $definition['description']);
        $this->assertStringNotContainsString('null until a sync stamps', $definition['description']);
        $this->assertStringContainsString('No returned field says outright whether we bill the row; a row whose vendor_status is Suspended or Pending is held out of billed licence counts.', $definition['description']);
        $this->assertStringNotContainsString('is_manual is what marks it', $definition['description']);
        $this->assertStringNotContainsString('null on a manual row', $definition['description']);

        // An undeclared argument is refused, not ignored.
        $client = Client::factory()->create();
        $text = $this->refusal($this->callTool($this->token(['list_client_licenses']), 'list_client_licenses', ['client_id' => $client->id, 'include_cost' => true]));
        $this->assertStringContainsString('include_cost', $text);
    }

    public function test_ungranted_and_legacy_tokens_cannot_call_it(): void
    {
        $client = Client::factory()->create();
        $this->license($client, $this->type());

        foreach ([$this->token(['list_client_contracts']), McpConfig::rotateStaffToken()] as $token) {
            $text = $this->refusal($this->callTool($token, 'list_client_licenses', ['client_id' => $client->id]));
            $this->assertStringContainsString('not allowed for this token', $text);
        }
    }

    public function test_a_missing_client_id_is_refused(): void
    {
        $text = $this->refusal($this->callTool($this->token(['list_client_licenses']), 'list_client_licenses', []));
        $this->assertStringContainsString('client', $text);
    }

    public function test_rows_are_client_scoped_and_carry_the_closed_key_list(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $type = $this->type();
        $mine = $this->license($clientA, $type, ['quantity' => 12, 'assigned_quantity' => 9, 'scheduled_quantity' => 11]);
        $this->license($clientB, $type);

        $result = $this->ok($this->callTool($this->token(['list_client_licenses']), 'list_client_licenses', ['client_id' => $clientA->id]));

        $this->assertSame(1, $result['count']);
        $this->assertSame($clientA->id, $result['client_id']);
        $row = $result['licenses'][0];
        $this->assertSame([
            'id', 'quantity', 'assigned_quantity', 'scheduled_quantity', 'status', 'vendor_status',
            'license_type_id', 'license_type_name', 'vendor', 'vendor_sku_id', 'sku_id',
            'synced_at', 'is_manual', 'sync_stale', 'contracts',
        ], array_keys($row));
        $this->assertSame($mine->id, $row['id']);
        $this->assertSame(12, $row['quantity']);
        $this->assertSame(9, $row['assigned_quantity']);
        $this->assertSame(11, $row['scheduled_quantity']);
        $this->assertSame('Example Suite Plan', $row['license_type_name']);
        $this->assertSame('appriver', $row['vendor']);
        $this->assertSame($type->vendor_sku_id, $row['vendor_sku_id']);
        $this->assertNull($row['sku_id']);
        $this->assertFalse($row['is_manual']);
    }

    public function test_no_cost_minimum_vendor_ref_or_note_values_are_returned(): void
    {
        $client = Client::factory()->create();
        $license = $this->license($client, $this->type());

        $response = $this->callTool($this->token(['list_client_licenses']), 'list_client_licenses', ['client_id' => $client->id]);
        $this->ok($response);
        $text = (string) $response->json('result.content.0.text');

        foreach (['default_unit_cost', 'cost_divisor', 'minimum_quantity', 'minimum_cost', 'notes', 'vendor_ref', '7.77', '33.33', 'NOTE-MARKER', $license->vendor_ref] as $held) {
            $this->assertStringNotContainsString($held, $text);
        }
    }

    public function test_sync_stale_is_true_only_for_an_active_appriver_row_past_48_hours(): void
    {
        $client = Client::factory()->create(['stage' => 'active', 'is_active' => true]);
        $appriver = $this->type();
        $manualType = $this->type('manual', 'Example Manual Plan');
        $stale = $this->license($client, $appriver, ['synced_at' => now()->subHours(49)]);
        $fresh = $this->license($client, $appriver, ['synced_at' => now()->subHours(47)]);
        $manual = $this->license($client, $manualType, ['synced_at' => null, 'vendor_ref' => null]);
        // Other vendors' synced rows are listed too, and the AppRiver rule never marks them.
        $tenant = $this->license($client, $this->type('cipp_m365', 'Example Tenant Plan'), ['synced_at' => now()->subHours(100)]);
        // A vendor-held AppRiver row is not stale however old its sync.
        $held = $this->license($client, $appriver, ['synced_at' => now()->subHours(100), 'vendor_status' => 'Suspended']);
        $pending = $this->license($client, $appriver, ['synced_at' => now()->subHours(100), 'vendor_status' => 'Pending']);
        // A manual row on an AppRiver type reports the type's vendor and is_manual true.
        $handEntered = $this->license($client, $appriver, ['synced_at' => null, 'vendor_ref' => null]);

        $result = $this->ok($this->callTool($this->token(['list_client_licenses']), 'list_client_licenses', ['client_id' => $client->id]));
        $rows = collect($result['licenses'])->keyBy('id');

        $this->assertTrue($rows[$stale->id]['sync_stale']);
        $this->assertFalse($rows[$fresh->id]['sync_stale']);
        $this->assertFalse($rows[$manual->id]['sync_stale']);
        $this->assertFalse($rows[$tenant->id]['sync_stale']);
        $this->assertSame('cipp_m365', $rows[$tenant->id]['vendor']);
        $this->assertFalse($rows[$tenant->id]['is_manual']);
        $this->assertFalse($rows[$held->id]['sync_stale']);
        $this->assertFalse($rows[$pending->id]['sync_stale']);
        $this->assertSame('appriver', $rows[$handEntered->id]['vendor']);
        $this->assertTrue($rows[$handEntered->id]['is_manual']);
        $this->assertFalse($rows[$handEntered->id]['sync_stale']);
        $this->assertTrue($rows[$manual->id]['is_manual']);
        $this->assertNull($rows[$manual->id]['synced_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $rows[$stale->id]['synced_at']);
    }

    public function test_a_row_of_a_client_the_last_sync_skipped_as_referred_is_not_stale(): void
    {
        $referred = Client::factory()->create(['stage' => 'active', 'is_active' => true]);
        $other = Client::factory()->create(['stage' => 'active', 'is_active' => true]);
        Setting::setValue(AppRiverLicenseSyncService::REFERRED_CLIENTS_SETTING, json_encode([$referred->id]));
        $type = $this->type();
        $skipped = $this->license($referred, $type, ['synced_at' => now()->subHours(100)]);
        $control = $this->license($other, $type, ['synced_at' => now()->subHours(100)]);
        $token = $this->token(['list_client_licenses']);

        $skippedRows = $this->ok($this->callTool($token, 'list_client_licenses', ['client_id' => $referred->id]))['licenses'];
        $controlRows = $this->ok($this->callTool($token, 'list_client_licenses', ['client_id' => $other->id]))['licenses'];

        $this->assertSame($skipped->id, $skippedRows[0]['id']);
        $this->assertFalse($skippedRows[0]['sync_stale']);
        $this->assertSame($control->id, $controlRows[0]['id']);
        $this->assertTrue($controlRows[0]['sync_stale']);
    }

    public function test_contracts_each_licence_is_on_are_listed(): void
    {
        $client = Client::factory()->create();
        $license = $this->license($client, $this->type());
        $loose = $this->license($client, $this->type('appriver', 'Example Other Plan'));
        $contract = $this->contract($client, 'Example Managed');
        $license->contracts()->attach($contract->id, ['assignment_source' => 'auto']);

        $result = $this->ok($this->callTool($this->token(['list_client_licenses']), 'list_client_licenses', ['client_id' => $client->id]));
        $rows = collect($result['licenses'])->keyBy('id');

        $this->assertSame([['id' => $contract->id, 'name' => 'Example Managed', 'assignment_source' => 'auto']], $rows[$license->id]['contracts']);
        $this->assertSame([], $rows[$loose->id]['contracts']);
    }
}
