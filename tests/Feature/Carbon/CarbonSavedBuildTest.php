<?php

namespace Tests\Feature\Carbon;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Services\Carbon\CarbonHardwareOrderService;
use App\Services\Carbon\CarbonHardwareService;
use App\Services\Mcp\StaffCarbonHardwareTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CarbonSavedBuildTest extends TestCase
{
    use FakesCarbonMcp;
    use RefreshDatabase;

    private Client $client;

    private Person $person;

    private array $product;

    private bool $readFails = false;

    private ?\Closure $duringRead = null;

    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        Log::listen(function ($event): void {
            $this->logs[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
        $this->seedConnected();
        Setting::setValue('carbon_hardware_ordering_enabled', '1');
        Setting::setValue('carbon_hardware_country', 'CAN');
        $this->client = Client::factory()->create(['name' => 'Synthetic Lab', 'address_line1' => '1 Example Road', 'city' => 'Example', 'state' => 'BC', 'postcode' => '00000']);
        $this->client->forceFill(['carbon_customer_id' => 91001, 'carbon_hardware_ordering_enabled' => true])->save();
        $this->person = Person::create(['client_id' => $this->client->id, 'person_type' => PersonType::User, 'first_name' => 'Synthetic', 'last_name' => 'Person', 'email' => 'build@example.test', 'is_active' => true, 'portal_enabled' => true, 'company_wide_access' => true]);
        $this->product = ['mfgPartNo' => 'SYNTH-BUILD', 'title' => 'Synthetic Build', 'status' => 'active', 'price' => 123.45, 'configs' => [self::config(81001), self::config(81002)]];
        $base = $this->mcpFake();
        Http::fake($this->tokenFake() + ['https://api.carbonsys.com/mcp' => function ($request) use ($base) {
            if (($request['method'] ?? null) === 'tools/call') {
                if ($request['params']['name'] === 'get_product') {
                    // Observe the reservation boundary during the read, not just after rollback.
                    $this->assertDatabaseCount('client_hardware_orders', 0);
                    $this->assertDatabaseCount('tickets', 0);
                    if ($this->readFails) {
                        return Http::response('', 503);
                    }
                    if ($this->duringRead) {
                        ($this->duringRead)();
                    }
                    $result = ['structuredContent' => $this->product];
                } else {
                    $result = ['structuredContent' => ['confirmationCode' => 'SYNTH-CODE']];
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]);
            }

            return $base['https://api.carbonsys.com/mcp']($request);
        }]);
    }

    // Synthetic values; shape from 04-get_product, mcp-capture-20261109.
    private static function config(int $id): array
    {
        return ['title' => 'Synthetic choice', 'variant' => 'single', 'configGroup' => 'Synthetic group', 'isRequired' => true, 'maxQuantity' => 1, 'isQuantityEditable' => false,
            'components' => [['id' => $id, 'mfgPartNo' => 'SYNTH-COMPONENT', 'title' => 'Synthetic component', 'quantity' => 1, 'isDefault' => true, 'price' => 10, 'extendedPrice' => 10, 'status' => 'active']]];
    }

    private function add()
    {
        return app(CarbonHardwareService::class)->add($this->client, 'SYNTH-BUILD', '200.00');
    }

    private function input($model): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'lines' => [['model_id' => $model->id, 'quantity' => 2]]];
    }

    private function orderLogs(): array
    {
        return array_values(array_filter($this->logs, fn ($l) => str_starts_with($l['message'], '[CarbonHardwareOrder]')));
    }

    private function calls(string $name)
    {
        return Http::recorded(fn ($r) => ($r['method'] ?? null) === 'tools/call' && $r['params']['name'] === $name)->values();
    }

    public function test_saved_build_stores_ordered_ids_and_sends_exact_components_once(): void
    {
        try {
            $model = $this->add()->fresh();
        } catch (ValidationException $e) {
            $this->fail('Qualifying saved build refused: '.json_encode($e->errors()));
        }
        $this->assertSame([81001, 81002], $model->carbon_component_ids);
        $this->assertSame('123.45', $model->carbon_price);
        $input = $this->input($model);
        $service = app(CarbonHardwareOrderService::class);
        $order = $service->submit($this->person, $input);
        $service->submit($this->person, $input);
        $this->assertSame('submitted', $order->status);
        $this->assertCount(2, $this->calls('get_product')); // add + one submit; replay reads nothing
        $this->assertCount(1, $this->calls('create_draft_order'));
        $this->assertSame([['mfgPartNo' => 'SYNTH-BUILD', 'quantity' => 2, 'components' => [['id' => 81001], ['id' => 81002]]]], $this->calls('create_draft_order')[0][0]['params']['arguments']['items']);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertSame(realpath(app_path('Services/Carbon/CarbonHardwareOrderService.php')), (new \ReflectionClass(CarbonHardwareOrderService::class))->getFileName());
    }

    public function test_staff_approval_change_during_read_refuses_without_reservation(): void
    {
        $model = $this->add()->fresh();
        $this->duringRead = fn () => $model->update(['carbon_component_ids' => [81003]]);
        try {
            app(CarbonHardwareOrderService::class)->submit($this->person, $this->input($model));
            $this->fail('Approval changed during read was accepted');
        } catch (ValidationException $e) {
            $this->assertSame([__('carbon_hardware.invalid_request')], $e->errors()['hardware']);
        }
        $this->assertDatabaseCount('client_hardware_orders', 0);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertCount(0, $this->calls('create_draft_order'));
        // Staff changed the list: reloading helps, so no vendor-side warning.
        $this->assertSame([], $this->orderLogs());
    }

    public function test_component_details_and_cost_stay_out_of_portal_and_ticket(): void
    {
        Setting::setValue('portal_enabled', '1');
        $model = $this->add()->fresh();
        $this->actingAs($this->person, 'portal')->get(route('portal.hardware'))
            ->assertOk()->assertSee('$200.00')->assertDontSee('123.45')->assertDontSee('81001')->assertDontSee('81002')->assertDontSee('Synthetic component');
        $order = app(CarbonHardwareOrderService::class)->submit($this->person, $this->input($model));
        $description = \App\Models\Ticket::findOrFail($order->ticket_id)->description;
        $this->assertStringContainsString('$200.00', $description);
        foreach (['123.45', '81001', '81002', 'Synthetic component'] as $private) {
            $this->assertStringNotContainsString($private, $description);
        }
    }

    public static function invalidBuilds(): array
    {
        $valid = self::config(81001);
        $cases = ['empty configs' => [], 'not list' => ['choice' => $valid], 'scalar' => 'bad'];
        foreach (['optional' => ['isRequired' => false], 'truthy' => ['isRequired' => 1], 'zero components' => ['components' => []], 'two components' => ['components' => [$valid['components'][0], $valid['components'][0]]], 'component map' => ['components' => ['choice' => $valid['components'][0]]]] as $name => $change) {
            $cases[$name] = [array_replace($valid, $change)];
        }
        foreach (['string id' => ['id' => '81001'], 'zero id' => ['id' => 0], 'negative id' => ['id' => -1], 'note' => ['noteRequired' => true], 'inactive' => ['status' => 'inactive'], 'null status' => ['status' => null], 'quantity two' => ['quantity' => 2], 'zero quantity' => ['quantity' => 0], 'string quantity' => ['quantity' => '1'], 'float quantity' => ['quantity' => 1.0], 'null quantity' => ['quantity' => null]] as $name => $change) {
            $config = $valid;
            $config['components'][0] = array_replace($config['components'][0], $change);
            $cases[$name] = [$config];
        }
        $config = $valid;
        unset($config['components'][0]['quantity']);
        $cases['no quantity'] = [$config];
        $out = [];
        foreach ($cases as $name => $configs) {
            $out[$name] = [['configs' => $configs]];
        }
        $out['requiredConfigs'] = [['requiredConfigs' => null]];
        $out['isConfigurable'] = [['isConfigurable' => false]];

        return $out;
    }

    #[DataProvider('invalidBuilds')]
    public function test_invalid_build_refuses_with_existing_copy(array $changes): void
    {
        $this->product = array_replace($this->product, $changes);
        try {
            $this->add();
            $this->fail('Invalid build accepted');
        } catch (ValidationException $e) {
            $this->assertSame([__('carbon_hardware.unconfirmed')], $e->errors()['hardware']);
        }
        $this->assertDatabaseCount('client_hardware_models', 0);
    }

    public function test_numeric_key_objects_follow_the_existing_associative_decode(): void
    {
        $config = self::config(81001);
        $config['components'] = (object) $config['components'];
        $this->product['configs'] = (object) [$config];
        $this->assertSame([81001], $this->add()->fresh()->carbon_component_ids);
    }

    public static function driftCases(): array
    {
        return array_map(fn ($v) => [$v], ['membership', 'order', 'price', 'null_price', 'refused', 'quantity', 'exception']);
    }

    #[DataProvider('driftCases')]
    public function test_drift_refuses_before_any_reservation_or_draft(string $case): void
    {
        // Seed stored approval so this test can be red independently of the accept implementation.
        $model = $this->client->hardwareModels()->create(['mfg_part_no' => 'SYNTH-BUILD', 'label' => 'Synthetic Build', 'sell_price' => '200.00', 'carbon_price' => $case === 'null_price' ? null : '123.45', 'carbon_component_ids' => [81001, 81002]]);
        match ($case) {
            'membership' => $this->product['configs'][1]['components'][0]['id'] = 81003,
            'order' => $this->product['configs'] = array_reverse($this->product['configs']),
            'price' => $this->product['price'] = 123.46,
            'null_price' => null,
            'refused' => $this->product['configs'][0]['isRequired'] = false,
            'quantity' => $this->product['configs'][0]['components'][0]['quantity'] = 2,
            'exception' => $this->readFails = true,
        };
        try {
            app(CarbonHardwareOrderService::class)->submit($this->person, $this->input($model));
            $this->fail('Changed build accepted');
        } catch (ValidationException $e) {
            // Vendor-side: the selection is valid and reloading never helps (ruling 6af66d95).
            $this->assertSame([__('carbon_hardware.portal_unavailable')], $e->errors()['hardware']);
        }
        $reason = ['membership' => 'components_moved', 'order' => 'components_moved', 'price' => 'price_moved', 'null_price' => 'no_cached_price', 'refused' => 'unconfirmed', 'quantity' => 'unconfirmed', 'exception' => null][$case];
        $expected = $reason === null ? [] : [['level' => 'warning', 'message' => '[CarbonHardwareOrder] Saved build refused at submit',
            'context' => ['reason' => $reason, 'model_id' => $model->id, 'client_id' => $this->client->id]]];
        $this->assertSame($expected, $this->orderLogs());
        $this->assertDatabaseCount('client_hardware_orders', 0);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertCount(0, $this->calls('create_draft_order'));
        $this->assertGreaterThanOrEqual(1, $this->calls('get_product')->count());
    }

    public function test_unknown_model_keeps_invalid_request_without_read_or_log(): void
    {
        $model = $this->client->hardwareModels()->create(['mfg_part_no' => 'SYNTH-BUILD', 'label' => 'Synthetic Build', 'sell_price' => '200.00', 'carbon_price' => '123.45', 'carbon_component_ids' => [81001, 81002]]);
        $input = $this->input($model);
        $input['lines'][0]['model_id'] = $model->id + 1000;
        try {
            app(CarbonHardwareOrderService::class)->submit($this->person, $input);
            $this->fail('Unknown model accepted');
        } catch (ValidationException $e) {
            // The list changed: reloading helps (ruling 6af66d95).
            $this->assertSame([__('carbon_hardware.invalid_request')], $e->errors()['hardware']);
        }
        $this->assertCount(0, $this->calls('get_product'));
        $this->assertSame([], $this->orderLogs());
    }

    public function test_add_tool_description_names_plain_parts_and_saved_fixed_builds(): void
    {
        $add = array_values(array_filter(StaffCarbonHardwareTools::definitions(), fn ($d) => $d['name'] === 'hardware_add_approved_model'));
        $this->assertSame('Add a plain part or a saved fixed build to the client approved list using a read-only Carbon product lookup.', $add[0]['description']);
    }

    public function test_plain_part_has_null_ids_and_no_submit_read_or_components(): void
    {
        unset($this->product['configs']);
        $model = $this->add()->fresh();
        $this->assertNull($model->carbon_component_ids);
        app(CarbonHardwareOrderService::class)->submit($this->person, $this->input($model));
        $this->assertCount(1, $this->calls('get_product')); // add only
        $this->assertSame([['mfgPartNo' => 'SYNTH-BUILD', 'quantity' => 2]], $this->calls('create_draft_order')[0][0]['params']['arguments']['items']);
    }

    public function test_component_migration_is_nullable_and_reverses_only_its_column(): void
    {
        $migration = require database_path('migrations/2026_11_13_010000_add_carbon_component_ids_to_client_hardware_models.php');
        $before = Schema::getColumnListing('client_hardware_models');
        $migration->down();
        $this->assertEqualsCanonicalizing(array_values(array_diff($before, ['carbon_component_ids'])), Schema::getColumnListing('client_hardware_models'));
        unset($this->product['configs']);
        // Existing rows survive the additive migration.
        $model = $this->client->hardwareModels()->create(['mfg_part_no' => 'SYNTH-PLAIN', 'label' => 'Synthetic plain', 'sell_price' => '200.00']);
        $migration->up();
        $this->assertEqualsCanonicalizing($before, Schema::getColumnListing('client_hardware_models'));
        $this->assertNull($model->fresh()->carbon_component_ids);
        $this->assertSame('200.00', $model->fresh()->sell_price);
    }
}
