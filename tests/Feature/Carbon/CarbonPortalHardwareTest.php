<?php

namespace Tests\Feature\Carbon;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\ClientHardwareOrder;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CarbonPortalHardwareTest extends TestCase
{
    use FakesCarbonMcp;
    use RefreshDatabase;

    private Client $client;

    private Person $person;

    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->seedConnected();
        Setting::setValue('portal_enabled', '1');
        Setting::setValue('carbon_hardware_ordering_enabled', '1');
        Setting::setValue('carbon_hardware_country', 'CAN');
        $this->client = Client::factory()->create(['name' => 'Synthetic Lab', 'address_line1' => '12 Example Road', 'city' => 'Example', 'state' => 'BC', 'postcode' => '00000']);
        $this->client->forceFill(['carbon_customer_id' => 91001, 'carbon_hardware_ordering_enabled' => true])->save();
        $this->person = Person::create(['client_id' => $this->client->id, 'person_type' => PersonType::User, 'first_name' => 'Synthetic', 'last_name' => 'Person', 'email' => 'synthetic@example.test', 'is_active' => true, 'portal_enabled' => true, 'company_wide_access' => true]);
        $model = $this->client->hardwareModels()->create(['mfg_part_no' => 'SYNTH-LAPTOP', 'label' => 'Synthetic Laptop', 'sell_price' => '200.01', 'carbon_price' => '123.45']);
        $this->input = ['idempotency_key' => (string) Str::uuid(), 'lines' => [['model_id' => $model->id, 'quantity' => 2]]];
        $this->actingAs($this->person, 'portal');
    }

    // synthetic, response shape unverified (REST Order schema)
    private function responseFixture(array $result = []): void
    {
        Http::fake($this->tokenFake() + $this->mcpFake(callResult: $result ?: ['content' => [['type' => 'text', 'text' => 'synthetic']], 'structuredContent' => ['confirmationCode' => 'SYNTH-CODE', 'status' => 'draft']]));
    }

    private function submit(): void
    {
        $this->post(route('portal.hardware.store'), $this->input)->assertRedirect(route('portal.hardware'))->assertSessionHasNoErrors();
    }

    private function calls()
    {
        return Http::recorded(fn ($r) => ($r['method'] ?? null) === 'tools/call')->values();
    }

    public function test_happy_payload_confirmation_and_double_submit(): void
    {
        $this->responseFixture();
        $this->input += ['customer' => ['name' => 'Forbidden'], 'client_id' => 99999, 'endUserPoNumber' => 'SYNTH-PO'];
        $this->submit();
        $this->submit();
        $this->assertDatabaseCount('client_hardware_orders', 1);
        $this->assertDatabaseCount('tickets', 1);
        $order = ClientHardwareOrder::sole();
        $this->assertSame('submitted', $order->status);
        $this->assertSame('400.02', $order->estimated_total);
        $this->assertSame('SYNTH-CODE', $order->confirmation_code);
        $this->assertStringContainsString('Confirmation: SYNTH-CODE', Ticket::findOrFail($order->ticket_id)->description);
        $this->assertSame(0, Ticket::findOrFail($order->ticket_id)->notes()->count());
        $this->assertCount(1, $this->calls());
        $params = $this->calls()[0][0]['params'];
        $this->assertSame('create_draft_order', $params['name']);
        $this->assertSame(['customerId' => 91001, 'shippingAddress' => ['name' => 'Synthetic Lab', 'addressOne' => '12 Example Road', 'addressTwo' => '', 'city' => 'Example', 'state' => 'BC', 'postalCode' => '00000', 'country' => 'CAN'], 'items' => [['mfgPartNo' => 'SYNTH-LAPTOP', 'quantity' => 2]], 'endUserPoNumber' => 'SYNTH-PO'], $params['arguments']);
        $this->assertStringNotContainsString('"customer":', json_encode($params['arguments']));
        $this->assertNull($order->response_keys);
    }

    public static function badShapes(): array
    {
        return [
            'number' => [['structuredContent' => ['confirmationCode' => 123, 'status' => 'draft']]],
            'missing code' => [['structuredContent' => ['status' => 'draft']]],
            'nested' => [['structuredContent' => ['order' => ['confirmationCode' => 'SECRET']]]],
            'empty' => [['structuredContent' => ['confirmationCode' => '']]],
            'spaces' => [['structuredContent' => ['confirmationCode' => '  ']]],
            'long' => [['structuredContent' => ['confirmationCode' => str_repeat('x', 65)]]],
            'control' => [['structuredContent' => ['confirmationCode' => "A\nB"]]],
            'no object' => [['content' => []]],
            'scalar' => [['structuredContent' => 'SECRET']],
            'list' => [['structuredContent' => [['confirmationCode' => 'SECRET']]]],
            'error' => [['isError' => true, 'structuredContent' => ['confirmationCode' => 'SECRET']]],
        ];
    }

    #[DataProvider('badShapes')]
    public function test_wrong_shape_is_reserved_without_retry(array $result): void
    {
        $result['content'] = [['type' => 'text', 'text' => 'SECRET']];
        $this->responseFixture($result);
        $this->submit();
        $this->submit();
        $order = ClientHardwareOrder::sole();
        $this->assertSame('unread', $order->status);
        $this->assertNull($order->confirmation_code);
        $keys = is_array($result['structuredContent'] ?? null) && ! array_is_list($result['structuredContent']) ? array_keys($result['structuredContent']) : [];
        sort($keys);
        $this->assertSame(['structuredContent' => $keys, 'content_types' => ['text']], $order->response_keys);
        $this->assertStringNotContainsString('SECRET', json_encode($order->response_keys));
        $this->assertUnreadTicketKeepsCarbonStaffOnly($order);
        $this->assertCount(1, $this->calls());
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_timeout_is_unread_and_not_retried(): void
    {
        $this->responseFixture();
        $this->carbonCallNoResponse = true;
        $this->submit();
        $sent = count(Http::recorded());
        $this->submit();
        $this->assertSame($sent, count(Http::recorded()));
        $this->assertSame('unread', ClientHardwareOrder::sole()->status);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertUnreadTicketKeepsCarbonStaffOnly(ClientHardwareOrder::sole());
    }

    private function assertUnreadTicketKeepsCarbonStaffOnly(ClientHardwareOrder $order): void
    {
        $ticket = Ticket::findOrFail($order->ticket_id);
        $this->assertStringNotContainsString('Carbon', $ticket->description);
        $this->assertStringContainsString(__('carbon_hardware.unread'), $ticket->description);
        $private = $ticket->notes()->where('is_private', true)->pluck('body')->all();
        $this->assertSame([__('carbon_hardware.check_carbon')], $private);
        $this->assertStringContainsString('Carbon', $private[0]);
        $this->assertSame(0, $ticket->notes()->portalVisible()->count());
    }

    public function test_401_draft_is_sent_once_and_resubmit_sends_nothing(): void
    {
        Http::fake($this->tokenFake() + ['https://api.carbonsys.com/mcp' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18']])
            ->push('', 202)->push('', 401)->push('', 401)]);
        $this->submit();
        $this->assertSame('unread', ClientHardwareOrder::sole()->status);
        $this->assertUnreadTicketKeepsCarbonStaffOnly(ClientHardwareOrder::sole());
        $sent = count(Http::recorded());
        $this->submit();
        $this->assertSame($sent, count(Http::recorded()));
        $this->assertCount(1, $this->calls());
        $this->assertSame('create_draft_order', $this->calls()[0][0]['params']['name']);
    }

    public static function refusals(): array
    {
        return array_map(fn ($v) => [$v], ['tenant', 'carbon', 'unmapped', 'client_off', 'personal', 'model', 'quantity', 'fraction', 'name', 'address_line1', 'city', 'state', 'postcode', 'country']);
    }

    #[DataProvider('refusals')]
    public function test_server_refuses_without_order_or_request(string $case): void
    {
        $this->responseFixture();
        match ($case) {
            'tenant' => Setting::setValue('carbon_hardware_ordering_enabled', '0'),
            'carbon' => Setting::setValue('carbon_mcp_enabled', '0'),
            'unmapped' => $this->client->forceFill(['carbon_customer_id' => null])->save(),
            'client_off' => $this->client->forceFill(['carbon_hardware_ordering_enabled' => false])->save(),
            'personal' => $this->person->update(['company_wide_access' => false]),
            'model' => $this->input['lines'][0]['model_id'] = 999999,
            'quantity' => $this->input['lines'][0]['quantity'] = 0,
            'fraction' => $this->input['lines'][0]['quantity'] = 1.5,
            'country' => Setting::setValue('carbon_hardware_country', ''),
            default => $this->client->update([$case => '']),
        };
        $this->post(route('portal.hardware.store'), $this->input)->assertSessionHasErrors('hardware');
        Http::assertNothingSent();
        $this->assertDatabaseCount('client_hardware_orders', 0);
    }

    public function test_client_scope_readonly_page_and_sell_price_only(): void
    {
        $this->responseFixture();
        $other = Client::factory()->create();
        $model = $other->hardwareModels()->create(['mfg_part_no' => 'OTHER', 'label' => 'Other Model Secret', 'sell_price' => '300.00']);
        ClientHardwareOrder::create(['client_id' => $other->id, 'person_id' => $this->person->id, 'idempotency_key' => (string) Str::uuid(), 'status' => 'submitted', 'lines' => [], 'estimated_total' => '9876.54']);
        $this->get(route('portal.hardware'))->assertOk()->assertSee('200.01')->assertDontSee('123.45')->assertDontSee('Other Model Secret')->assertDontSee('9876.54')->assertSee('Unknown');
        $this->input['lines'][0]['model_id'] = $model->id;
        $this->post(route('portal.hardware.store'), $this->input)->assertSessionHasErrors('hardware');
        $this->person->update(['company_wide_access' => false]);
        $this->get(route('portal.hardware'))->assertOk()->assertSee(__('carbon_hardware.personal'))->assertSee('disabled');
        Http::assertNothingSent();
    }

    public function test_pending_reservation_never_dispatches_and_other_client_key_refuses(): void
    {
        $this->responseFixture();
        $order = ClientHardwareOrder::create(['client_id' => $this->client->id, 'person_id' => $this->person->id,
            'idempotency_key' => $this->input['idempotency_key'], 'status' => 'pending', 'lines' => [], 'estimated_total' => '0.00']);
        $this->submit();
        $this->assertSame('pending', $order->fresh()->status);
        $order->update(['client_id' => Client::factory()->create()->id]);
        $this->post(route('portal.hardware.store'), $this->input)->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_trimmed_confirmation_and_empty_object_identity(): void
    {
        $this->responseFixture(['content' => [], 'structuredContent' => ['confirmationCode' => '  SYNTH-TRIM  ', 'status' => 'draft']]);
        $this->submit();
        $this->assertSame('SYNTH-TRIM', ClientHardwareOrder::sole()->confirmation_code);
        $this->carbonCallResult = ['content' => [], 'structuredContent' => new \stdClass];
        $this->input['idempotency_key'] = (string) Str::uuid();
        $this->submit();
        $this->assertSame('unread', ClientHardwareOrder::latest('id')->first()->status);
    }

    public function test_migration_down_up(): void
    {
        $migration = require database_path('migrations/2026_11_10_030000_create_client_hardware_orders.php');
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('client_hardware_orders'));
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('client_hardware_orders'));
    }
}
