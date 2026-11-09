<?php

namespace Tests\Feature\Carbon;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\User;
use App\Services\Carbon\CarbonHardwareService;
use App\Services\Mcp\StaffCarbonHardwareExecutor;
use App\Services\Mcp\StaffCarbonHardwareTools;
use App\Support\CarbonHardwareConfig;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use App\Support\McpToolSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CarbonHardwareTest extends TestCase
{
    use FakesCarbonMcp;
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->seedConnected();
        Setting::setValue('carbon_hardware_ordering_enabled', '1');
        Http::fake($this->tokenFake() + $this->mcpFake());
        McpToolRegistry::flushMemoized();
        $this->client = Client::factory()->create(['name' => 'Synthetic Hardware Lab']);
        $this->client->carbon_customer_id = 91001;
        $this->client->save();
        $this->productFixture();
    }

    // synthetic, shape from capture mcp-capture-20261109 19:37Z
    private function productFixture(array $changes = []): void
    {
        $data = array_replace([
            'mfgPartNo' => 'SYNTH-LAPTOP', 'title' => 'Synthetic Laptop', 'status' => 'active',
            'description' => 'Synthetic fixture', 'manufacturer' => 'Synthetic Maker', 'category' => 'Laptop',
            'price' => 123.45, 'inventory' => 7, 'specs' => [], 'features' => [],
        ], $changes);
        $this->envelope($data);
    }

    private function envelope(mixed $data): void
    {
        $this->carbonCallResult = ['content' => [['type' => 'text', 'text' => json_encode($data)]], 'structuredContent' => $data];
    }

    private function add(string $sell = '200.00', bool $confirmed = false)
    {
        return app(CarbonHardwareService::class)->add($this->client, 'SYNTH-LAPTOP', $sell, $confirmed);
    }

    public function test_plain_product_adds_and_only_get_product_is_called(): void
    {
        $model = $this->add();
        $this->assertSame('123.45', $model->carbon_price);
        $this->assertSame('200.00', $model->sell_price);
        $this->assertSame('unknown', $model->availability);
        $calls = Http::recorded(fn ($r) => ($r->data()['method'] ?? null) === 'tools/call')->values();
        $this->assertCount(1, $calls);
        $this->assertSame('get_product', $calls[0][0]['params']['name']);
        $this->assertSame(['mfgPartNo' => 'SYNTH-LAPTOP'], $calls[0][0]['params']['arguments']);
        $this->assertSame(realpath(app_path('Services/Carbon/CarbonHardwareService.php')), (new \ReflectionClass(CarbonHardwareService::class))->getFileName());
    }

    public static function refusedShapes(): array
    {
        return [
            'configs present' => [['configs' => [['title' => 'Synthetic choice', 'variant' => 'single', 'configGroup' => null, 'isRequired' => true, 'maxQuantity' => null, 'isQuantityEditable' => false, 'components' => []]]]],
            'configs empty' => [['configs' => []]],
            'configurable false' => [['isConfigurable' => false]],
            'required null' => [['requiredConfigs' => null]],
            'part mismatch' => [['mfgPartNo' => 'SYNTH-OTHER']],
            'price nonnumeric' => [['price' => 'unknown']],
            'price zero' => [['price' => 0]],
            'price numeric string' => [['price' => '123.45']],
            'price exponent string' => [['price' => '1e2']],
        ];
    }

    #[DataProvider('refusedShapes')]
    public function test_contract_refuses(array $changes): void
    {
        $this->productFixture($changes);
        try {
            $this->add();
            $this->fail('Unconfirmed product was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame([__('carbon_hardware.unconfirmed')], $e->errors()['hardware']);
        }
        $this->assertDatabaseCount('client_hardware_models', 0);
    }

    public function test_missing_structured_content_refuses(): void
    {
        unset($this->carbonCallResult['structuredContent']);
        $this->expectException(ValidationException::class);
        $this->add();
    }

    public function test_malformed_structured_content_refuses(): void
    {
        $this->envelope('malformed');
        $this->expectException(ValidationException::class);
        $this->add();
    }

    public function test_margin_confirm_is_required_and_confirmed_save_succeeds(): void
    {
        $model = $this->add();
        try {
            app(CarbonHardwareService::class)->price($this->client, $model->id, '100.00');
            $this->fail('Below-cost price accepted without confirmation.');
        } catch (ValidationException $e) {
            $this->assertSame([__('carbon_hardware.margin_confirm')], $e->errors()['hardware']);
        }
        $this->assertSame('200.00', $model->fresh()->sell_price);
        app(CarbonHardwareService::class)->price($this->client, $model->id, '100.00', true);
        $this->assertSame('100.00', $model->fresh()->sell_price);
    }

    public function test_add_below_cost_also_requires_confirmation(): void
    {
        try {
            $this->add('100.00');
            $this->fail('Below-cost add accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('hardware', $e->errors());
        }
        $this->assertSame('100.00', $this->add('100.00', true)->sell_price);
    }

    public function test_switch_is_live_gated_on_server(): void
    {
        Setting::setValue('carbon_mcp_enabled', '0');
        Setting::setValue('carbon_hardware_ordering_enabled', '0');
        try {
            CarbonHardwareConfig::save(true, 'CAN');
            $this->fail('Switch accepted while Carbon off.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('enabled', $e->errors());
        }
        $this->assertFalse(CarbonHardwareConfig::isEnabled());
        Setting::setValue('carbon_mcp_enabled', '1');
        CarbonHardwareConfig::save(true, 'CAN');
        $this->assertTrue(CarbonHardwareConfig::isEnabled());
        $this->assertSame('CAN', CarbonHardwareConfig::country());
    }

    public function test_publication_and_executor_both_refuse_each_off_switch(): void
    {
        $executor = app(StaffCarbonHardwareExecutor::class);
        foreach (['carbon_mcp_enabled', 'carbon_hardware_ordering_enabled'] as $setting) {
            Setting::setValue($setting, '0');
            $this->assertNotContains('hardware_list_approved_models', array_column(McpToolSurface::liveGeneralToolDefinitions(), 'name'));
            $this->assertArrayHasKey('error', $executor->execute('hardware_list_approved_models', [], $this->client->id));
            Setting::setValue($setting, '1');
            $this->assertContains('hardware_list_approved_models', array_column(McpToolSurface::liveGeneralToolDefinitions(), 'name'));
            $this->assertSame(['models' => []], $executor->execute('hardware_list_approved_models', [], $this->client->id));
        }
        Http::assertNothingSent();
    }

    private function callHardware(string $token, string $name, array $arguments)
    {
        return $this->withToken($token)->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]]);
    }

    public function test_mcp_grants_validated_scope_and_cross_client_model_refusal(): void
    {
        $model = $this->add();
        $other = Client::factory()->create(['name' => 'Synthetic Other Lab']);
        $other->carbon_customer_id = 91002;
        $other->save();
        $name = 'hardware_set_sell_price';
        $token = McpConfig::rotateStaffToken([$name], 'synthetic-hardware');
        foreach ([null, 'bad', 999999, $other->id] as $clientId) {
            $result = $this->callHardware($token, $name, ['client_id' => $clientId, 'model_id' => $model->id, 'sell_price' => '250.00']);
            $this->assertTrue($result->json('result.isError'), $result->getContent());
            $this->assertSame('200.00', $model->fresh()->sell_price);
        }
        $ok = $this->callHardware($token, $name, ['client_id' => $this->client->id, 'model_id' => $model->id, 'sell_price' => '250.00']);
        $this->assertFalse($ok->json('result.isError'), $ok->getContent());
        $this->assertSame('250.00', $model->fresh()->sell_price);
        $audit = \App\Models\McpAuditLog::where('tool_name', $name)->latest('id')->first();
        $this->assertSame($this->client->id, $audit->arguments['client_id'] ?? null);
        $legacy = McpConfig::rotateStaffToken(null);
        $denied = $this->callHardware($legacy, $name, ['client_id' => $this->client->id, 'model_id' => $model->id, 'sell_price' => '300.00']);
        $this->assertTrue($denied->json('result.isError'));
        $this->assertSame('250.00', $model->fresh()->sell_price);
    }

    // Ruled A (card sL0cPqhk, STOP3): staff tokens are cross-client by design; the only
    // client-bound caller is the portal MCP. A portal person bound to client X naming
    // client Y must be refused before any read: no Carbon call, no hardware-model query.
    public function test_portal_caller_bound_to_x_is_refused_for_client_y_before_any_read(): void
    {
        $model = $this->add();
        $other = Client::factory()->create(['name' => 'Synthetic Portal Lab']);
        Person::create(['client_id' => $other->id, 'cipp_user_id' => 'synthetic-portal-oid', 'person_type' => PersonType::User,
            'first_name' => 'Pat', 'last_name' => 'Portal', 'email' => 'pat-portal@example.test', 'is_active' => true,
            'portal_enabled' => true, 'company_wide_access' => true, 'password' => 'synthetic-pw']);
        $token = McpConfig::rotatePortalToken();
        Http::fake(fn () => throw new \RuntimeException('no Carbon call expected'));
        $reads = [];
        DB::listen(function ($query) use (&$reads) {
            if (str_contains($query->sql, 'client_hardware_models')) {
                $reads[] = $query->sql;
            }
        });
        $arguments = ['client_id' => $this->client->id, 'mfg_part_no' => 'SYNTH-LAPTOP', 'model_id' => $model->id, 'sell_price' => '1.00', 'confirm_below_cost' => true];
        foreach (array_column(StaffCarbonHardwareTools::definitions(), 'name') as $name) {
            $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Mcp-Portal-Object-Id' => 'synthetic-portal-oid'])
                ->postJson('/api/mcp/portal', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]]);
            $this->assertTrue($response->json('result.isError'), $name.': '.$response->getContent());
        }
        $this->assertSame([], $reads);
        Http::assertNothingSent();
        $this->assertSame('200.00', $model->fresh()->sell_price);
    }

    public function test_incomplete_customer_page_refuses_instead_of_returning_partial_list(): void
    {
        // synthetic, shape from capture mcp-capture-20261109 19:37Z
        $this->envelope(['customers' => [], 'meta' => ['page' => 0, 'pageSize' => 100, 'totalRecords' => 1, 'totalPages' => 1]]);
        $this->expectException(ValidationException::class);
        app(CarbonHardwareService::class)->customers();
    }

    public function test_error_result_and_missing_price_refuse_without_persisting(): void
    {
        foreach (['error', 'missing-price'] as $case) {
            $this->productFixture();
            if ($case === 'error') {
                $this->carbonCallResult['isError'] = true;
            } else {
                unset($this->carbonCallResult['structuredContent']['price']);
                $this->carbonCallResult['content'][0]['text'] = json_encode($this->carbonCallResult['structuredContent']);
            }
            try {
                $this->add();
                $this->fail('Invalid product accepted.');
            } catch (ValidationException $e) {
                $this->assertSame([__('carbon_hardware.unconfirmed')], $e->errors()['hardware']);
            }
            $this->assertDatabaseCount('client_hardware_models', 0);
        }
    }

    public function test_customer_picker_projects_only_id_name_and_mapping_rejects_unknown(): void
    {
        // synthetic, shape from capture mcp-capture-20261109 19:37Z
        $this->envelope(['customers' => [['id' => 91001, 'name' => 'Synthetic Customer', 'email' => 'synthetic@example.test', 'phone' => '', 'companyName' => 'Synthetic Customer', 'companyId' => null, 'rmmLink' => null, 'tenantId' => null, 'bootLogo' => null, 'pricingTier' => 'synthetic', 'location' => ['id' => 92001, 'name' => 'Synthetic Lab', 'addressOne' => '1 Synthetic Way', 'addressTwo' => '', 'city' => 'Example', 'state' => 'WA', 'postalCode' => '00000', 'country' => 'USA', 'rmmLink' => null, 'tenantId' => null]]], 'meta' => ['page' => 0, 'pageSize' => 100, 'totalRecords' => 1, 'totalPages' => 1]]);
        $service = app(CarbonHardwareService::class);
        $this->assertSame([['id' => 91001, 'name' => 'Synthetic Customer']], $service->customers());
        $service->map($this->client, 91001, true);
        $this->assertTrue($this->client->fresh()->carbon_hardware_ordering_enabled);
        try {
            $service->map($this->client, 99999, true);
            $this->fail('Unknown customer mapped.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('hardware', $e->errors());
        }
        $service->map($this->client, null, false);
        $this->assertNull($this->client->fresh()->carbon_customer_id);
    }

    public function test_staff_tab_renders_both_warnings_and_unknown_availability(): void
    {
        $model = $this->add('100.00', true);
        $model->availability = null;
        $model->save();
        $this->envelope(['customers' => [], 'meta' => ['page' => 0, 'pageSize' => 100, 'totalRecords' => 0, 'totalPages' => 0]]);
        $response = $this->actingAs(User::factory()->create())->get(route('clients.hardware', $this->client));
        $response->assertOk()->assertSee('Synthetic Laptop')->assertSee('123.45')->assertSee('100.00')->assertSee('Unknown')->assertSee('-23.5%');
        $this->assertSame(2, substr_count($response->getContent(), __('carbon_hardware.margin_warning')));
    }

    public function test_unmapped_refuses_and_duplicate_remove_are_client_scoped(): void
    {
        $this->client->carbon_customer_id = null;
        $this->client->save();
        try {
            $this->add();
            $this->fail('Unmapped client accepted.');
        } catch (ValidationException $e) {
            $this->assertSame([__('carbon_hardware.unmapped')], $e->errors()['hardware']);
        }
        Http::assertNothingSent();
        $this->client->carbon_customer_id = 91001;
        $this->client->save();
        $model = $this->add();
        try {
            $this->add();
            $this->fail('Duplicate accepted.');
        } catch (ValidationException $e) {
            $this->assertSame([__('carbon_hardware.duplicate')], $e->errors()['hardware']);
        }
        $this->assertDatabaseCount('client_hardware_models', 1);
        app(CarbonHardwareService::class)->remove($this->client, $model->id);
        $this->assertDatabaseCount('client_hardware_models', 0);
    }

    public function test_migration_down_and_up_restore_default_off_schema(): void
    {
        $migration = require database_path('migrations/2026_11_09_200000_add_carbon_client_hardware.php');
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('client_hardware_models'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('clients', 'carbon_customer_id'));
        $migration->up();
        $this->assertFalse($this->client->fresh()->carbon_hardware_ordering_enabled);
        $this->assertNull($this->client->fresh()->carbon_customer_id);
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('client_hardware_models'));
    }

    public function test_settings_http_rejects_nonadmin_and_off_enable(): void
    {
        $staff = User::factory()->create(['role' => 'tech']);
        $this->actingAs($staff)->post(route('settings.integrations.carbon.hardware'), ['enabled' => 1, 'country' => 'CAN'])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::setValue('carbon_mcp_enabled', '0');
        $this->actingAs($admin)->post(route('settings.integrations.carbon.hardware'), ['enabled' => 1, 'country' => 'CAN'])->assertSessionHasErrors('enabled');
        Setting::setValue('carbon_mcp_enabled', '1');
        $this->actingAs($admin)->post(route('settings.integrations.carbon.hardware'), ['enabled' => 1, 'country' => 'CAN'])->assertSessionHasNoErrors();
        $this->assertSame('CAN', CarbonHardwareConfig::country());
    }
}
