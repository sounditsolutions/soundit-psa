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

    public function test_row_whose_read_throws_is_read_after_the_others_on_later_runs(): void
    {
        $bad = $this->order(['confirmation_code' => 'SYNTH-ORDER-BAD']);
        $good = $this->order();
        $before = $bad->getAttributes();
        $this->carbonCallSequence = [[400, []]];
        $this->artisan('carbon:sync-order-status')->assertFailed();
        $this->assertNull($good->refresh()->carbon_status);
        $this->carbonCallSequence = [[200, self::reply()], [400, []]];
        $this->artisan('carbon:sync-order-status')->assertFailed();
        $codes = $this->calls()->map(fn ($pair) => $pair[0]['params']['arguments']['confirmationCode'])->all();
        $this->assertSame(['SYNTH-ORDER-BAD', 'SYNTH-ORDER-1', 'SYNTH-ORDER-BAD'], $codes);
        $this->assertSame('draft', $good->refresh()->carbon_status);
        $this->assertNotNull($good->carbon_status_checked_at);
        $this->assertSame($before, $bad->refresh()->getAttributes());
        $ours = array_values(array_filter($this->logs, fn ($log) => $log[1] === '[CarbonOrderStatus] Status not read'));
        $this->assertSame(array_fill(0, 2, ['warning', '[CarbonOrderStatus] Status not read', ['order_id' => $bad->id, 'exception' => 'CarbonMcpException']]), $ours);
    }

    public function test_rows_whose_reads_keep_throwing_take_turns_after_the_others(): void
    {
        $badA = $this->order(['confirmation_code' => 'SYNTH-ORDER-BAD-A']);
        $badB = $this->order(['confirmation_code' => 'SYNTH-ORDER-BAD-B']);
        $this->order();
        $runs = [
            [[400, []]],
            [[400, []]],
            [[200, self::reply()], [400, []]],
            [[200, self::reply()], [400, []]],
            [[200, self::reply()], [200, self::reply(['confirmationCode' => 'SYNTH-ORDER-BAD-A'])], [400, []]],
        ];
        foreach ($runs as $sequence) {
            $this->travel(5)->minutes();
            $this->carbonCallSequence = $sequence;
            $this->artisan('carbon:sync-order-status')->assertFailed();
        }
        $codes = $this->calls()->map(fn ($pair) => $pair[0]['params']['arguments']['confirmationCode'])->all();
        $this->assertSame([
            'SYNTH-ORDER-BAD-A',
            'SYNTH-ORDER-BAD-B',
            'SYNTH-ORDER-1', 'SYNTH-ORDER-BAD-A',
            'SYNTH-ORDER-1', 'SYNTH-ORDER-BAD-B',
            'SYNTH-ORDER-1', 'SYNTH-ORDER-BAD-A', 'SYNTH-ORDER-BAD-B',
        ], $codes);
        $this->assertSame('draft', $badA->refresh()->carbon_status);
        $this->assertNull($badB->refresh()->carbon_status);
        $this->assertNull($badB->carbon_status_checked_at);
        $this->assertSame([$badB->id], array_keys(Cache::get('carbon-order-status-deferred')));
        $ours = array_column(array_filter($this->logs, fn ($log) => $log[1] === '[CarbonOrderStatus] Status not read'), 2);
        $this->assertSame(array_map(fn ($row) => ['order_id' => $row->id, 'exception' => 'CarbonMcpException'], [$badA, $badB, $badA, $badB, $badB]), $ours);
    }

    public function test_listed_row_alternating_throw_and_refused_stays_behind_the_others(): void
    {
        $bad = $this->order(['confirmation_code' => 'SYNTH-ORDER-BAD']);
        $good = $this->order();
        $refused = array_replace(self::reply(['confirmationCode' => 'SYNTH-ORDER-BAD']), ['isError' => true]);
        $runs = [
            [[[400, []]], false],
            [[[200, self::reply()], [200, $refused]], true],
            [[[200, self::reply()], [400, []]], false],
        ];
        foreach ($runs as [$sequence, $ok]) {
            $this->travel(5)->minutes();
            $this->carbonCallSequence = $sequence;
            $this->artisan('carbon:sync-order-status')->assertExitCode($ok ? 0 : 1)->run();
        }
        $codes = $this->calls()->map(fn ($pair) => $pair[0]['params']['arguments']['confirmationCode'])->all();
        $this->assertSame(['SYNTH-ORDER-BAD', 'SYNTH-ORDER-1', 'SYNTH-ORDER-BAD', 'SYNTH-ORDER-1', 'SYNTH-ORDER-BAD'], $codes);
        $this->assertNull($bad->refresh()->carbon_status_checked_at);
        $this->assertSame([$bad->id], array_keys(Cache::get('carbon-order-status-deferred')));
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

    public static function settledReads(): array
    {
        return [
            'paid canceled' => ['paid', 'canceled'],
            'paid refunded' => ['paid', 'partial_refund'],
            'refunded canceled' => ['partial_refund', 'canceled'],
        ];
    }

    #[DataProvider('settledReads')]
    public function test_settled_reread_stores_status_and_waits_since_success(string $stored, string $reply): void
    {
        $this->freezeTime();
        $order = $this->order(['carbon_status' => $stored, 'carbon_status_checked_at' => now()->subHours(25), 'created_at' => now()->subDays(89)]);
        $this->carbonCallResult = self::reply(['status' => $reply]);
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertCount(1, $this->calls());
        $this->assertSame($reply, $order->refresh()->carbon_status);
        $this->assertSame(now()->toDateTimeString(), $order->carbon_status_checked_at->toDateTimeString());
        $this->travel(23)->hours();
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertCount(1, $this->calls());
    }

    public static function settledExclusions(): array
    {
        return [
            'paid recent' => ['paid', 23, 10],
            'refunded recent' => ['partial_refund', 23, 10],
            'paid old' => ['paid', 25, 91],
            'refunded old' => ['partial_refund', 25, 91],
            'at age bound' => ['paid', 25, 90],
            'canceled' => ['canceled', 25, 10],
        ];
    }

    #[DataProvider('settledExclusions')]
    public function test_settled_reread_exclusions_with_eligible_control(string $status, int $hours, int $days): void
    {
        $this->freezeTime();
        $excluded = $this->order(['carbon_status' => $status, 'carbon_status_checked_at' => now()->subHours($hours), 'created_at' => now()->subDays($days)]);
        $before = $excluded->getAttributes();
        $control = $this->order(['confirmation_code' => 'SYNTH-CONTROL', 'carbon_status' => 'paid', 'carbon_status_checked_at' => now()->subDay()]);
        $this->carbonCallResult = self::reply(['confirmationCode' => 'SYNTH-CONTROL', 'status' => 'partial_refund']);
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertSame(['SYNTH-CONTROL'], $this->calls()->map(fn ($pair) => $pair[0]['params']['arguments']['confirmationCode'])->all());
        $this->assertSame($before, $excluded->refresh()->getAttributes());
        $this->assertSame('partial_refund', $control->refresh()->carbon_status);
    }

    public function test_settled_reread_is_after_open_rows_including_deferred_at_cap(): void
    {
        $this->freezeTime();
        $paid = $this->order(['confirmation_code' => 'SYNTH-PAID', 'carbon_status' => 'paid', 'carbon_status_checked_at' => now()->subDays(2)]);
        $open = [];
        for ($i = 0; $i < 26; $i++) {
            $open[] = $this->order(['confirmation_code' => 'SYNTH-OPEN-'.$i, 'carbon_status' => 'quote', 'carbon_status_checked_at' => now()]);
        }
        Cache::put('carbon-order-status-deferred', [$open[24]->id => now()->getTimestamp(), $open[25]->id => now()->getTimestamp()], now()->addDay());
        $this->carbonCallResult = array_replace(self::reply(), ['isError' => true]);
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertSame(array_map(fn ($row) => $row->confirmation_code, array_slice($open, 0, 25)), $this->calls()->map(fn ($pair) => $pair[0]['params']['arguments']['confirmationCode'])->all());
        $this->assertSame('paid', $paid->refresh()->carbon_status);
        foreach ($open as $row) {
            $row->update(['carbon_status' => 'canceled']);
        }
        $this->carbonCallResult = self::reply(['confirmationCode' => 'SYNTH-PAID', 'status' => 'partial_refund']);
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertCount(26, $this->calls());
        $this->assertSame('partial_refund', $paid->refresh()->carbon_status);
    }

    public function test_settled_refusal_is_retried_next_run_after_every_open_row(): void
    {
        $this->freezeTime();
        $paid = $this->order(['confirmation_code' => 'SYNTH-PAID', 'carbon_status' => 'paid', 'carbon_status_checked_at' => now()->subDays(2)]);
        $before = $paid->getAttributes();
        $deferred = $this->order(['confirmation_code' => 'SYNTH-DEFERRED', 'carbon_status' => 'quote']);
        $this->order(['confirmation_code' => 'SYNTH-OPEN']);
        Cache::put('carbon-order-status-deferred', [$deferred->id => now()->getTimestamp()], now()->addDay());
        $this->carbonCallResult = array_replace(self::reply(), ['isError' => true]);
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('carbon:sync-order-status')->assertSuccessful();
            $this->assertSame($before, $paid->refresh()->getAttributes());
            $this->travel(1)->hours();
        }
        $this->assertSame(['SYNTH-OPEN', 'SYNTH-DEFERRED', 'SYNTH-PAID', 'SYNTH-DEFERRED', 'SYNTH-OPEN', 'SYNTH-PAID'], $this->calls()->map(fn ($pair) => $pair[0]['params']['arguments']['confirmationCode'])->all());
        foreach ($this->calls() as [$request]) {
            $this->assertSame('get_order', $request['params']['name']);
        }
    }

    public function test_refusals_release_cap_slots_and_rotate_within_group(): void
    {
        $this->freezeTime();
        $rows = [];
        for ($i = 0; $i < 27; $i++) {
            $rows[] = $this->order(['confirmation_code' => 'SYNTH-FAIR-'.$i]);
        }
        $before = $rows[0]->getAttributes();
        $this->carbonCallResult = ['isError' => true];
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->travel(1)->hours();
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $codes = $this->calls()->map(fn ($p) => $p[0]['params']['arguments']['confirmationCode'])->all();
        $this->assertSame(['SYNTH-FAIR-25', 'SYNTH-FAIR-26', 'SYNTH-FAIR-0'], array_slice($codes, 25, 3));
        $this->assertCount(50, $codes);
        $this->assertSame($before, $rows[0]->refresh()->getAttributes());
        $this->travel(1)->hours();
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertSame('SYNTH-FAIR-23', $this->calls()[50][0]['params']['arguments']['confirmationCode']);
    }

    public static function settledStatuses(): array
    {
        return [['paid'], ['partial_refund']];
    }

    #[DataProvider('settledStatuses')]
    public function test_settled_group_after_refused_open_at_cap(string $status): void
    {
        $this->freezeTime();
        $this->order(['carbon_status' => $status, 'carbon_status_checked_at' => now()->subDays(2)]);
        for ($i = 0; $i < 25; $i++) {
            $open = $this->order(['confirmation_code' => 'SYNTH-OPEN-'.$i, 'carbon_status' => 'quote', 'carbon_status_checked_at' => now()]);
        }
        Cache::put('carbon-order-status-deferred', [$open->id => now()->getTimestamp()], now()->addDay());
        $this->carbonCallResult = ['isError' => true];
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertSame(array_map(fn ($i) => 'SYNTH-OPEN-'.$i, range(0, 24)), $this->calls()->map(fn ($p) => $p[0]['params']['arguments']['confirmationCode'])->all());
    }

    #[DataProvider('settledStatuses')]
    public function test_same_settled_status_stamps_and_waits_full_day(string $status): void
    {
        $this->freezeTime();
        $row = $this->order(['carbon_status' => $status, 'carbon_status_checked_at' => now()->subDays(2)]);
        $this->carbonCallResult = self::reply(['status' => $status]);
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertSame(now()->toDateTimeString(), $row->refresh()->carbon_status_checked_at->toDateTimeString());
        $this->travel(23)->hours();
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertCount(1, $this->calls());
        $this->travel(1)->hours();
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertCount(2, $this->calls());
    }

    #[DataProvider('settledStatuses')]
    public function test_null_checked_settled_excluded_below_cap(string $status): void
    {
        $row = $this->order(['carbon_status' => $status, 'confirmation_code' => 'SYNTH-NULL']);
        $before = $row->getAttributes();
        $this->order();
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertCount(1, $this->calls());
        $this->assertSame('SYNTH-ORDER-1', $this->calls()[0][0]['params']['arguments']['confirmationCode']);
        $this->assertSame($before, $row->refresh()->getAttributes());
    }

    #[DataProvider('settledStatuses')]
    public function test_settled_throw_unchanged_and_retried_after_unfailed_settled(string $status): void
    {
        $this->freezeTime();
        $bad = $this->order(['carbon_status' => $status, 'carbon_status_checked_at' => now()->subDays(2), 'confirmation_code' => 'SYNTH-BAD']);
        $this->order(['carbon_status' => $status, 'carbon_status_checked_at' => now()->subDays(2)]);
        $before = $bad->getAttributes();
        $this->carbonCallSequence = [[400, []]];
        $this->artisan('carbon:sync-order-status')->assertFailed();
        $this->travel(1)->hours();
        $this->carbonCallSequence = [[200, self::reply(['status' => $status])], [400, []]];
        $this->artisan('carbon:sync-order-status')->assertFailed();
        $this->assertSame(['SYNTH-BAD', 'SYNTH-ORDER-1', 'SYNTH-BAD'], $this->calls()->map(fn ($p) => $p[0]['params']['arguments']['confirmationCode'])->all());
        $this->assertSame($before, $bad->refresh()->getAttributes());
    }

    public static function pruneReasons(): array
    {
        return array_map(fn ($reason) => [$reason], ['settled', 'canceled', 'old', 'deleted', 'disabled', 'unsubmitted', 'no_code']);
    }

    #[DataProvider('pruneReasons')]
    public function test_failed_list_prunes_unselectable_entry(string $reason): void
    {
        $this->freezeTime();
        $row = $this->order();
        $disabled = Client::factory()->create(['carbon_hardware_ordering_enabled' => false]);
        match ($reason) {
            'settled' => $row->update(['carbon_status' => 'paid', 'carbon_status_checked_at' => now()]),
            'canceled' => $row->update(['carbon_status' => 'canceled']),
            'old' => $row->update(['carbon_status' => 'partial_refund', 'carbon_status_checked_at' => now()->subDays(2), 'created_at' => now()->subDays(90)]),
            'deleted' => $row->delete(),
            'disabled' => $row->update(['client_id' => $disabled->id]),
            'unsubmitted' => $row->update(['status' => 'pending']),
            'no_code' => $row->update(['confirmation_code' => null]),
        };
        Cache::put('carbon-order-status-deferred', [$row->id => now()->getTimestamp()], now()->addDay());
        $control = $this->order(['confirmation_code' => 'SYNTH-CONTROL']);
        $this->carbonCallResult = ['isError' => true];
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertSame([$control->id], array_keys(Cache::get('carbon-order-status-deferred')));
        $this->assertCount(1, $this->calls());
    }

    public function test_entry_expires_independently_while_list_is_rewritten(): void
    {
        $this->freezeTime();
        $old = $this->order(['confirmation_code' => 'SYNTH-OLD']);
        $other = $this->order();
        Cache::put('carbon-order-status-deferred', [$old->id => now()->subHours(23)->getTimestamp()], now()->addDay());
        $this->carbonCallSequence = [[400, []]];
        $this->artisan('carbon:sync-order-status')->assertFailed();
        $this->travel(2)->hours();
        $this->order(['confirmation_code' => 'SYNTH-UNFAILED', 'carbon_status' => 'quote', 'carbon_status_checked_at' => now()]);
        $this->carbonCallSequence = [[400, []]];
        $this->artisan('carbon:sync-order-status')->assertFailed();
        $this->assertSame(['SYNTH-ORDER-1', 'SYNTH-OLD'], $this->calls()->map(fn ($p) => $p[0]['params']['arguments']['confirmationCode'])->all());
        $this->assertArrayHasKey($other->id, Cache::get('carbon-order-status-deferred'));
    }

    public function test_unchanged_failed_list_is_not_saved(): void
    {
        $this->freezeTime();
        $deferred = $this->order();
        $list = [$deferred->id => now()->getTimestamp()];
        Cache::put('carbon-order-status-deferred', $list, now()->addDay());
        $writes = [];
        Event::listen(\Illuminate\Cache\Events\KeyWritten::class, function ($event) use (&$writes) {
            if ($event->key === 'carbon-order-status-deferred') {
                $writes[] = $event->value;
            }
        });
        $this->carbonCallSequence = [[400, []]];
        $this->artisan('carbon:sync-order-status')->assertFailed();
        $this->assertSame([], $writes);
        $this->assertSame($list, Cache::get('carbon-order-status-deferred'));
        Cache::put('carbon-order-status-deferred', $list, now()->addDay());
        $this->assertSame([$list], $writes);
    }

    public function test_recovered_entry_is_saved_without_a_later_throw(): void
    {
        $row = $this->order();
        Cache::put('carbon-order-status-deferred', [$row->id => now()->getTimestamp()], now()->addDay());
        $this->artisan('carbon:sync-order-status')->assertSuccessful();
        $this->assertSame([], Cache::get('carbon-order-status-deferred'));
        $this->assertSame('draft', $row->refresh()->carbon_status);
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
