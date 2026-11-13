<?php

namespace Tests\Feature\Carbon;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\ClientHardwareOrder;
use App\Models\Person;
use App\Models\Setting;
use App\Services\Carbon\CarbonMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CarbonOrderStatusTest extends TestCase
{
    use FakesCarbonMcp;
    use RefreshDatabase;

    private Client $client;

    private Person $person;

    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->seedConnected();
        Setting::setValue('carbon_hardware_ordering_enabled', '1');
        Setting::setValue('portal_enabled', '1');
        $this->client = Client::factory()->create(['name' => 'Synthetic Lab']);
        $this->client->forceFill(['carbon_customer_id' => 91001, 'carbon_hardware_ordering_enabled' => true])->save();
        $this->person = Person::create(['client_id' => $this->client->id, 'person_type' => PersonType::User, 'first_name' => 'Synthetic', 'last_name' => 'Person', 'email' => 'synthetic@example.test', 'is_active' => true, 'portal_enabled' => true, 'company_wide_access' => true]);
        Http::fake($this->tokenFake() + $this->mcpFake(callResult: self::reply()));
        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logs[] = [$event->level, $event->message, $event->context];
        });
    }

    // synthetic; shape from one read-only capture, keys and types only, no captured values
    private static function reply(array $replace = []): array
    {
        return ['isError' => false, 'content' => [['type' => 'text', 'text' => json_encode(array_replace([
            'confirmationCode' => 'SYNTH-ORDER-1', 'status' => 'draft', 'total' => 42,
            'customer' => ['name' => 'SYNTH-CUSTOMER-CANARY'],
            'location' => ['name' => 'SYNTH-LOCATION-CANARY'],
        ], $replace), JSON_THROW_ON_ERROR)]]];
    }

    private function order(array $attributes = []): ClientHardwareOrder
    {
        return ClientHardwareOrder::create(array_replace([
            'client_id' => $this->client->id, 'person_id' => $this->person->id,
            'idempotency_key' => (string) Str::uuid(), 'status' => 'submitted',
            'confirmation_code' => 'SYNTH-ORDER-1', 'lines' => [], 'estimated_total' => 42,
        ], $attributes))->refresh();
    }

    private function calls()
    {
        return Http::recorded(fn ($r) => ($r['method'] ?? null) === 'tools/call')->values();
    }

    private function warning(ClientHardwareOrder $order, string $key, string $value): void
    {
        $this->assertSame([['warning', '[CarbonOrderStatus] Status not read', ['order_id' => $order->id, $key => $value]]], $this->logs);
    }

    public function test_draft_is_stored_with_one_read_and_only_two_columns_changed(): void
    {
        $order = $this->order();
        $before = $order->getAttributes();
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $order->refresh();
        $this->assertSame('draft', $order->carbon_status);
        $this->assertNotNull($order->carbon_status_checked_at);
        foreach ($before as $key => $value) {
            if (! in_array($key, ['carbon_status', 'carbon_status_checked_at'], true)) {
                $this->assertSame($value, $order->getRawOriginal($key), $key);
            }
        }
        $this->assertCount(1, $this->calls());
        $this->assertSame(['name' => 'get_order', 'arguments' => ['confirmationCode' => 'SYNTH-ORDER-1']], $this->calls()[0][0]['params']);
        $page = $this->actingAs($this->person, 'portal')->get(route('portal.hardware'))->assertOk();
        foreach (['SYNTH-CUSTOMER-CANARY', 'SYNTH-LOCATION-CANARY'] as $canary) {
            $page->assertDontSee($canary);
            $this->assertStringNotContainsString($canary, json_encode([$this->logs, $order->getAttributes()]));
        }
        $this->assertSame([], $this->logs);
    }

    public static function refused(): array
    {
        $reply = self::reply();

        return [
            'code_mismatch' => [self::reply(['confirmationCode' => 'SYNTH-OTHER']), 'code_mismatch'],
            'status_type' => [self::reply(['status' => 123]), 'status_type'],
            'two_text' => [array_replace($reply, ['content' => [$reply['content'][0], $reply['content'][0]]]), 'shape'],
            'is_error' => [array_replace($reply, ['isError' => true]), 'is_error'],
            'structured' => [$reply + ['structuredContent' => null], 'shape'],
            'non_json' => [['content' => [['type' => 'text', 'text' => 'SYNTH-CUSTOMER-CANARY']]], 'shape'],
            'list' => [['content' => [['type' => 'text', 'text' => '[]']]], 'shape'],
            'missing_code' => [['content' => [['type' => 'text', 'text' => '{"status":"draft"}']]], 'code_mismatch'],
            'unknown' => [self::reply(['status' => 'SYNTH-UNKNOWN']), 'unknown_status'],
            'non_text' => [['content' => [['type' => 'image', 'text' => '{}']]], 'shape'],
            'empty' => [['content' => []], 'shape'],
            'not_list' => [['content' => ['entry' => $reply['content'][0]]], 'shape'],
            'scalar' => [['content' => [['type' => 'text', 'text' => '42']]], 'shape'],
        ];
    }

    #[DataProvider('refused')]
    public function test_failed_check_keeps_entire_row_and_warns_once(array $reply, string $reason): void
    {
        $order = $this->order(['carbon_status' => 'quote', 'carbon_status_checked_at' => now()->subDay()]);
        $before = $order->getAttributes();
        $this->carbonCallResult = $reply;
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertSame($before, $order->refresh()->getAttributes());
        $this->warning($order, 'reason', $reason);
        $this->assertCount(1, $this->calls());
        $page = $this->actingAs($this->person, 'portal')->get(route('portal.hardware'))->assertOk();
        foreach (['SYNTH-CUSTOMER-CANARY', 'SYNTH-LOCATION-CANARY'] as $canary) {
            $page->assertDontSee($canary);
            $this->assertStringNotContainsString($canary, json_encode([$this->logs, $order->getAttributes()]));
        }
    }

    public function test_401_then_success_uses_existing_single_replay(): void
    {
        $order = $this->order();
        $this->carbonCallSequence = [[401, []], [200, self::reply()]];
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertCount(2, $this->calls());
        foreach ($this->calls() as [$request]) {
            $this->assertSame(['name' => 'get_order', 'arguments' => ['confirmationCode' => 'SYNTH-ORDER-1']], $request['params']);
        }
        $this->assertSame('draft', $order->refresh()->carbon_status);
        $this->assertNotNull($order->carbon_status_checked_at);
        $this->assertSame([], $this->logs);
    }

    public function test_401_twice_stops_without_another_row_or_invocation(): void
    {
        $first = $this->order();
        $next = $this->order(['confirmation_code' => 'SYNTH-ORDER-2']);
        $before = [$first->getAttributes(), $next->getAttributes()];
        $this->carbonCallSequence = [[401, []], [401, []]];
        $this->artisan('carbon:sync-order-status')->assertFailed();
        $this->assertCount(2, $this->calls());
        $this->assertSame(2, $this->carbonCallAttempts);
        $this->assertSame($before, [$first->refresh()->getAttributes(), $next->refresh()->getAttributes()]);
        $this->warning($first, 'exception', 'CarbonMcpAuthException');
    }

    public function test_transport_throw_stops_before_next_row(): void
    {
        $first = $this->order();
        $next = $this->order();
        $before = [$first->getAttributes(), $next->getAttributes()];
        $this->carbonCallNoResponse = true;
        $this->artisan('carbon:sync-order-status')->assertFailed();
        // Failed connections have no recorded response; count fake callback invocations.
        $this->assertSame(1, $this->carbonCallAttempts);
        $this->assertSame($before, [$first->refresh()->getAttributes(), $next->refresh()->getAttributes()]);
        $this->warning($first, 'exception', 'CarbonMcpException');
    }

    public function test_other_throwable_stops_without_leaking_its_message(): void
    {
        $first = $this->order();
        $next = $this->order();
        $before = [$first->getAttributes(), $next->getAttributes()];
        $this->mock(CarbonMcpClient::class)->shouldReceive('callTool')->once()->with('get_order', ['confirmationCode' => 'SYNTH-ORDER-1'])->andThrow(new \LogicException('SYNTH-CUSTOMER-CANARY'));
        $this->artisan('carbon:sync-order-status')->assertFailed();
        $this->assertSame($before, [$first->refresh()->getAttributes(), $next->refresh()->getAttributes()]);
        $this->warning($first, 'exception', 'LogicException');
        Http::assertNothingSent();
    }

    public function test_selection_and_null_first_oldest_order_with_cap(): void
    {
        foreach (['paid', 'partial_refund', 'canceled'] as $status) {
            $this->order(['carbon_status' => $status]);
        }
        foreach (['unread', 'pending'] as $status) {
            $this->order(['status' => $status]);
        }
        $this->order(['confirmation_code' => null]);
        $disabled = Client::factory()->create(['carbon_hardware_ordering_enabled' => false]);
        $this->order(['client_id' => $disabled->id]);
        $recent = $this->order(['carbon_status' => 'payment_authorized', 'carbon_status_checked_at' => now()]);
        $old = $this->order(['carbon_status' => 'quote', 'carbon_status_checked_at' => now()->subDays(2)]);
        $nulls = [];
        for ($i = 0; $i < 24; $i++) {
            $nulls[] = $this->order(['confirmation_code' => 'SYNTH-NULL-'.$i]);
        }
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertCount(25, $this->calls());
        $codes = $this->calls()->map(fn ($pair) => $pair[0]['params']['arguments']['confirmationCode'])->all();
        $this->assertSame([...array_map(fn ($row) => $row->confirmation_code, $nulls), $old->confirmation_code], $codes);
        $this->assertSame('payment_authorized', $recent->refresh()->carbon_status);
        foreach ($this->calls() as [$request]) {
            $this->assertSame('get_order', $request['params']['name']);
            $this->assertSame(['confirmationCode'], array_keys($request['params']['arguments']));
        }
    }

    public function test_switches_off_send_nothing(): void
    {
        $this->order();
        Setting::setValue('carbon_hardware_ordering_enabled', '0');
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        Setting::setValue('carbon_hardware_ordering_enabled', '1');
        Setting::setValue('carbon_mcp_enabled', '0');
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_stored_carbon_status_is_not_shown_to_clients(): void
    {
        $this->order(['carbon_status' => 'paid']);
        $this->actingAs($this->person, 'portal')->get(route('portal.hardware'))->assertOk()->assertSee('Your requests')->assertSee('<td>Submitted</td>', false)->assertDontSee('paid');
        Http::assertNothingSent();
    }

    public function test_hourly_schedule_is_gated_and_non_overlapping(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'carbon:sync-order-status'));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->filtersPass(app()));
        Setting::setValue('carbon_hardware_ordering_enabled', '0');
        $this->assertFalse($event->filtersPass(app()));
        Setting::setValue('carbon_hardware_ordering_enabled', '1');
        Setting::setValue('carbon_mcp_enabled', '0');
        $this->assertFalse($event->filtersPass(app()));
        Http::assertNothingSent();
    }

    public function test_migration_down_and_up(): void
    {
        $migration = require database_path('migrations/2026_11_13_160000_add_carbon_status_to_client_hardware_orders.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('client_hardware_orders', 'carbon_status'));
        $this->assertFalse(Schema::hasColumn('client_hardware_orders', 'carbon_status_checked_at'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('client_hardware_orders', 'carbon_status'));
        $this->assertTrue(Schema::hasColumn('client_hardware_orders', 'carbon_status_checked_at'));
    }
}
