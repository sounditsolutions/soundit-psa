<?php

namespace Tests\Feature\Carbon;

use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\Carbon\CarbonHardwareService;
use App\Services\Mcp\StaffCarbonHardwareExecutor;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CarbonHardwareRefreshTest extends TestCase
{
    use FakesCarbonMcp;
    use RefreshDatabase;

    private Client $client;

    private array $product;

    private ?\Closure $duringRead = null;

    private bool $readFails = false;

    private int $reads = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->seedConnected();
        Setting::setValue('carbon_hardware_ordering_enabled', '1');
        $this->client = Client::factory()->create(['name' => 'Synthetic Refresh Lab']);
        $this->client->forceFill(['carbon_customer_id' => 91001])->save();
        // Synthetic values, plain-product shape from mcp-capture-20261109 (CarbonHardwareTest).
        $this->product = ['mfgPartNo' => 'SYNTH-REFRESH', 'title' => 'Synthetic Laptop', 'status' => 'active', 'price' => 123.45];
        $base = $this->mcpFake();
        Http::fake($this->tokenFake() + ['https://api.carbonsys.com/mcp' => function ($request) use ($base) {
            if (($request['method'] ?? null) === 'tools/call' && $request['params']['name'] === 'get_product') {
                $this->reads++;
                if ($this->duringRead) {
                    ($this->duringRead)();
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => ['structuredContent' => $this->product]], $this->readFails ? 503 : 200);
            }

            return $base['https://api.carbonsys.com/mcp']($request);
        }]);
    }

    private function model()
    {
        $this->travelTo(now()->subDay());
        $model = app(CarbonHardwareService::class)->add($this->client, 'SYNTH-REFRESH', '200.00');
        $this->travelBack();

        return $model;
    }

    private function refresh($model, bool $confirmed = false): array
    {
        return app(CarbonHardwareService::class)->refresh($this->client, $model->id, $confirmed);
    }

    public static function refusals(): array
    {
        return [['margin_confirm'], ['components_moved'], ['not_active'], ['unconfirmed'], ['failed']];
    }

    #[DataProvider('refusals')]
    public function test_refusal_leaves_entire_row_unchanged(string $reason): void
    {
        $model = $this->model();
        if ($reason === 'margin_confirm') {
            $this->product['price'] = 250;
        }
        if ($reason === 'components_moved') {
            $model->update(['carbon_component_ids' => [81001]]);
        }
        if ($reason === 'not_active') {
            $this->product['status'] = 'inactive';
        }
        if ($reason === 'unconfirmed') {
            unset($this->product['price']);
        }
        if ($reason === 'failed') {
            $this->readFails = true;
        }
        $before = $model->fresh()->getRawOriginal();
        if ($reason === 'failed') {
            $writes = [];
            \Illuminate\Support\Facades\DB::listen(function ($query) use (&$writes) {
                if (preg_match('/^\s*(update|insert|delete)\b/i', $query->sql) && str_contains($query->sql, 'client_hardware_models')) {
                    $writes[] = $query->sql;
                }
            });
            $result = app(StaffCarbonHardwareExecutor::class)->execute('hardware_refresh_approved_model', ['model_id' => $model->id], $this->client->id);
            $this->assertSame(['error' => 'Hardware operation failed.', 'status' => 0, 'class' => 'CarbonMcpException'], $result);
            $this->assertSame([], $writes);
            $this->assertSame($before, $model->fresh()->getRawOriginal());

            return;
        }
        try {
            $this->refresh($model);
            $this->fail('Refresh accepted refusal fixture');
        } catch (ValidationException $e) {
            $this->assertSame([__('carbon_hardware.'.$reason)], $e->errors()['hardware']);
        }
        $this->assertSame($before, $model->fresh()->getRawOriginal());
    }

    public function test_success_returns_costs_preserves_sell_and_reads_once(): void
    {
        $model = $this->model();
        $this->product['price'] = 250;
        $reads = $this->reads;
        $result = $this->refresh($model, true);
        $this->assertSame('123.45', $result['old_cost']);
        $this->assertSame('250.00', $result['new_cost']);
        $this->assertSame('200.00', $model->fresh()->sell_price);
        $this->assertSame($reads + 1, $this->reads);
        $this->assertNotEquals($model->synced_at, $model->fresh()->synced_at);
        $this->assertSame(realpath(app_path('Services/Carbon/CarbonHardwareService.php')), (new \ReflectionClass(CarbonHardwareService::class))->getFileName());
    }

    public static function availability(): array
    {
        return [['unmapped'], ['unavailable']];
    }

    #[DataProvider('availability')]
    public function test_availability_checked_before_product(string $reason): void
    {
        $model = $this->model();
        $before = $model->fresh()->getRawOriginal();
        $reads = $this->reads;
        if ($reason === 'unmapped') {
            $this->client->forceFill(['carbon_customer_id' => null])->save();
        } else {
            Setting::setValue('carbon_hardware_ordering_enabled', '0');
        }
        try {
            $this->refresh($model);
            $this->fail('First availability check omitted');
        } catch (ValidationException $e) {
            $this->assertSame([__('carbon_hardware.'.$reason)], $e->errors()['hardware']);
        }
        $this->assertSame($reads, $this->reads);
        $this->assertSame($before, $model->fresh()->getRawOriginal());
    }

    #[DataProvider('availability')]
    public function test_availability_rechecked_after_product(string $reason): void
    {
        $model = $this->model();
        $before = $model->fresh()->getRawOriginal();
        $calls = 0;
        $this->duringRead = function () use ($reason, &$calls) {
            $calls++;
            if ($reason === 'unmapped') {
                Client::findOrFail($this->client->id)->forceFill(['carbon_customer_id' => null])->save();
                $this->assertNull(Client::findOrFail($this->client->id)->carbon_customer_id);
            } else {
                Setting::setValue('carbon_hardware_ordering_enabled', '0');
            }
        };
        try {
            $this->refresh($model);
            $this->fail('Second availability check omitted');
        } catch (ValidationException $e) {
            $this->assertSame([__('carbon_hardware.'.$reason)], $e->errors()['hardware']);
        }
        $this->assertSame(1, $calls);
        $this->assertSame($before, $model->fresh()->getRawOriginal());
    }

    public function test_components_and_sell_are_reread_after_the_product_read(): void
    {
        $model = $this->model();
        $this->duringRead = fn () => $model->update(['carbon_component_ids' => [81003]]);
        try {
            $this->refresh($model);
            $this->fail('Stale components used');
        } catch (ValidationException $e) {
            $this->assertSame([__('carbon_hardware.components_moved')], $e->errors()['hardware']);
        }
        $this->duringRead = fn () => $model->update(['carbon_component_ids' => null, 'sell_price' => '1.00']);
        try {
            $this->refresh($model);
            $this->fail('Stale sell used');
        } catch (ValidationException $e) {
            $this->assertSame([__('carbon_hardware.margin_confirm')], $e->errors()['hardware']);
        }
        $this->assertSame('123.45', $model->fresh()->carbon_price);
    }

    public function test_other_clients_model_is_refused(): void
    {
        $model = $this->model();
        $before = $model->fresh()->getRawOriginal();
        $other = Client::factory()->create(['name' => 'Synthetic Other Lab']);
        $other->forceFill(['carbon_customer_id' => 91002])->save();
        $reads = $this->reads;
        try {
            app(CarbonHardwareService::class)->refresh($other, $model->id);
            $this->fail('Tenant scope dropped');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->assertSame($before, $model->fresh()->getRawOriginal());
        }
        $this->assertSame($reads, $this->reads);
    }

    public function test_executor_requires_model_id_without_reads_or_writes(): void
    {
        $model = $this->model();
        $before = $model->fresh()->getRawOriginal();
        $this->reads = 0;
        $writes = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(update|insert|delete)\b/i', $query->sql) && str_contains($query->sql, 'client_hardware_models')) {
                $writes[] = $query->sql;
            }
        });
        $result = app(StaffCarbonHardwareExecutor::class)->execute('hardware_refresh_approved_model', [], $this->client->id);
        $this->assertSame(['error' => 'The model id field is required.'], $result);
        $this->assertSame(0, $this->reads);
        $this->assertSame([], $writes);
        $this->assertSame($before, $model->fresh()->getRawOriginal());
    }

    public function test_executor_and_web_show_changed_and_unchanged_costs(): void
    {
        $model = $this->model();
        $this->product['price'] = 150;
        $result = app(StaffCarbonHardwareExecutor::class)->execute('hardware_refresh_approved_model', ['model_id' => $model->id], $this->client->id);
        $this->assertSame('123.45', $result['old_cost']);
        $this->assertSame('150.00', $result['new_cost']);
        $this->assertSame('200.00', $result['model']['sell_price']);
        $this->actingAs(User::factory()->create());
        $url = route('clients.hardware.model', [$this->client, 'refresh']);
        $this->post($url, ['model_id' => $model->id])->assertSessionHas('success', 'Carbon price is unchanged at 150.00.');
        $this->product['price'] = 160;
        $this->post($url, ['model_id' => $model->id])->assertSessionHas('success', 'Carbon price updated from 150.00 to 160.00.');
    }

    public static function tokenGrants(): array
    {
        return ['sell only' => [['hardware_set_sell_price'], false], 'legacy' => [null, false], 'explicit refresh' => [['hardware_refresh_approved_model'], true]];
    }

    #[DataProvider('tokenGrants')]
    public function test_refresh_requires_explicit_name_grant(?array $grants, bool $allowed): void
    {
        $model = $this->model();
        $before = $model->fresh()->getRawOriginal();
        $reads = $this->reads;
        $this->product['price'] = 150;
        $token = McpConfig::rotateStaffToken($grants);
        $response = $this->withToken($token)->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'hardware_refresh_approved_model', 'arguments' => ['client_id' => $this->client->id, 'model_id' => $model->id]]]);
        if ($allowed) {
            $this->assertFalse($response->json('result.isError'), $response->getContent());
            $this->assertSame($reads + 1, $this->reads);
            $this->assertSame('150.00', $model->fresh()->carbon_price);

            return;
        }
        $this->assertTrue($response->json('result.isError'), $response->getContent());
        $this->assertStringStartsWith('Tool not allowed for this token: hardware_refresh_approved_model ', $response->json('result.content.0.text'));
        $this->assertSame($reads, $this->reads);
        $this->assertSame($before, $model->fresh()->getRawOriginal());
    }

    public function test_next_submit_passes_after_refresh(): void
    {
        $model = $this->model();
        $this->client->forceFill(['carbon_hardware_ordering_enabled' => true, 'address_line1' => '12 Example Road', 'city' => 'Example', 'state' => 'BC', 'postcode' => '00000'])->save();
        Setting::setValue('carbon_hardware_country', 'CAN');
        $person = \App\Models\Person::create(['client_id' => $this->client->id, 'person_type' => \App\Enums\PersonType::User, 'first_name' => 'Synthetic', 'last_name' => 'Person', 'email' => 'refresh@example.test', 'company_wide_access' => true]);
        $input = ['idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'lines' => [['model_id' => $model->id, 'quantity' => 1]]];
        $this->product['price'] = 150;
        $reasons = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($event) use (&$reasons) {
            if (str_starts_with($event->message, '[CarbonHardwareOrder]')) {
                $reasons[] = $event->context['reason'] ?? null;
            }
        });
        try {
            app(\App\Services\Carbon\CarbonHardwareOrderService::class)->submit($person, $input);
            $this->fail('Moved cost accepted before refresh');
        } catch (ValidationException $e) {
            $this->assertSame([__('carbon_hardware.portal_unavailable')], $e->errors()['hardware']);
        }
        $this->assertSame(['price_moved'], $reasons);
        $this->assertDatabaseCount('client_hardware_orders', 0);
        $this->refresh($model);
        // Synthetic confirmation shape, as in CarbonSavedBuildTest; not a captured draft response.
        $this->carbonCallResult = ['structuredContent' => ['confirmationCode' => 'SYNTH-REFRESH-ORDER']];
        $order = app(\App\Services\Carbon\CarbonHardwareOrderService::class)->submit($person, $input);
        $this->assertSame('submitted', $order->status);
        $this->assertNotNull($order->ticket_id);
        $this->assertSame('200.00', $order->estimated_total);
        $this->assertDatabaseCount('client_hardware_orders', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => ($r['params']['name'] ?? null) === 'create_draft_order'));
    }

    public function test_staff_partial_renders_refresh_form_and_confirmation(): void
    {
        $model = $this->model();
        $html = view('clients.partials.hardware', ['client' => $this->client, 'hardwareModels' => collect([$model]), 'hardwareEnabled' => true, 'hardwareLookupError' => null, 'hardwareCustomers' => []])->render();
        $this->assertStringContainsString(route('clients.hardware.model', [$this->client, 'refresh']), $html);
        $this->assertSame(1, substr_count($html, 'Refresh Carbon price'));
        $this->assertStringContainsString('id="refresh-confirm-'.$model->id.'"', $html);
    }

    public static function refreshButtonAvailability(): array
    {
        return ['enabled and mapped' => [true, true, false], 'switch off' => [false, true, true], 'unmapped' => [true, false, true]];
    }

    #[DataProvider('refreshButtonAvailability')]
    public function test_refresh_button_disabled_when_unavailable(bool $enabled, bool $mapped, bool $disabled): void
    {
        $model = $this->model();
        if (! $mapped) {
            $this->client->forceFill(['carbon_customer_id' => null])->save();
        }
        $html = view('clients.partials.hardware', ['client' => $this->client, 'hardwareModels' => collect([$model]), 'hardwareEnabled' => $enabled, 'hardwareLookupError' => null, 'hardwareCustomers' => []])->render();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $buttons = $xpath->query('//form[@action="'.route('clients.hardware.model', [$this->client, 'refresh']).'"]//button');
        $this->assertSame(1, $buttons->length);
        $this->assertSame(__('carbon_hardware.refresh'), trim($buttons->item(0)->textContent));
        $this->assertSame($disabled, $buttons->item(0)->hasAttribute('disabled'));
    }

    public function test_group_matches_sell_not_read(): void
    {
        McpToolRegistry::flushMemoized();
        $groups = McpToolRegistry::groups();
        $this->assertContains('hardware_refresh_approved_model', array_column($groups['psa_records']['tools'], 'name'));
        $this->assertContains('hardware_set_sell_price', array_column($groups['psa_records']['tools'], 'name'));
        $this->assertNotContains('hardware_refresh_approved_model', array_column($groups['psa_read']['tools'], 'name'));
    }
}
