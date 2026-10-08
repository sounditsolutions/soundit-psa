<?php

namespace Tests\Feature\Mesh;

use App\Models\Client;
use App\Models\MeshAllowRule;
use App\Models\SignalEvent;
use App\Services\Mesh\MeshAllowRuleReaper;
use App\Services\Mesh\MeshWriteClient;
use App\Services\Signals\SignalEventTypes;
use App\Services\Signals\SignalHub;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #5160: a PERMANENT unresolved row whose scope was never proved is never
 * deleted by the PSA, so the reaper's settle pass raises an Alerts Hub event
 * for it, at most once per row per 24 hours. Status only: no tenant, sender,
 * comment or upstream id. Synthetic data only; Mesh is a routing handler.
 */
class MeshAllowRuleUnresolvedSignalTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'tenant-synthetic-5160';

    private const SENDER = 'unproved@sender.example.test';

    private const COMMENT = 'PSA allow UNPROVED5160';

    private const UPSTREAM_ID = 'eeeeeeee-0000-4000-8000-000000005160';

    /** @var array<string, array<string, mixed>> */
    private array $upstream = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();

        $handler = function (RequestInterface $request) {
            $path = ltrim($request->getUri()->getPath(), '/');

            if ($request->getMethod() === 'GET' && $path === 'api/rule-allows-blocks/') {
                $all = array_values($this->upstream);

                return new FulfilledPromise(new Response(200, [], json_encode([
                    'count' => count($all), 'next' => null, 'previous' => null, 'results' => $all,
                ])));
            }

            return new FulfilledPromise(new Response(404, [], '{"detail":"Not found."}'));
        };

        $guzzle = new GuzzleClient([
            'base_uri' => 'https://mesh.invalid/',
            'handler' => HandlerStack::create($handler),
            'http_errors' => true,
        ]);

        $this->app->instance(MeshWriteClient::class, new MeshWriteClient(['api_key' => 'k'], $guzzle));
    }

    private function upstreamRule(string $id, string $sender, string $comment): void
    {
        $this->upstream[$id] = [
            'id' => $id, 'sender' => $sender, 'comment' => $comment, 'ab' => true, 'active' => true,
            'organization_level' => true, 'customer_id' => null,
            'customer' => ['id' => self::TENANT, 'name' => 'Tenant'],
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    private function record(array $overrides = []): MeshAllowRule
    {
        return MeshAllowRule::create(array_merge([
            'client_id' => Client::factory()->create()->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => self::SENDER,
            'comment' => self::COMMENT,
            'mesh_rule_id' => null,
            'expires_at' => null,
            'state' => MeshAllowRule::STATE_UNRESOLVED,
            'scope_proved' => false,
            'last_error' => 'Mesh reported this rule was added for another tenant; check the Mesh portal.',
        ], $overrides));
    }

    private function events(): \Illuminate\Support\Collection
    {
        return SignalEvent::where('type_key', 'mesh.allow_rule_unresolved')->orderBy('id')->get();
    }

    public function test_an_unproved_permanent_unresolved_row_emits_one_status_only_routable_event(): void
    {
        $this->upstreamRule(self::UPSTREAM_ID, self::SENDER, self::COMMENT);
        $record = $this->record();

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(['examined' => 0, 'reaped' => 0, 'unresolved' => 1, 'failed' => 0], $counts);
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->fresh()->state);

        $events = $this->events();
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame("Mesh allow rule #{$record->id} stays unresolved: its scope was never proved; clear it by hand or remove it", $event->summary);
        $this->assertSame($record->getMorphClass(), $event->entity_type);
        $this->assertSame($record->id, (int) $event->entity_id);
        $this->assertSame(['client_id' => $record->client_id], $event->context);
        $this->assertTrue(SignalEventTypes::routable('mesh.allow_rule_unresolved'));
        $this->assertFalse(SignalEventTypes::all()['mesh.allow_rule_unresolved']['core']);

        // Status only (C-56): no tenant, sender, comment or upstream id anywhere in the event.
        $payload = json_encode($event->toArray());
        foreach ([self::TENANT, self::SENDER, self::COMMENT, self::UPSTREAM_ID, 'sender.example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
        $this->assertSame(self::UPSTREAM_ID, $record->fresh()->mesh_rule_id, 'positive control: the id was in hand');
    }

    public function test_the_event_repeats_only_after_24_hours_while_the_row_stays_unresolved(): void
    {
        // Two rows: the dedupe is per row, so one row's event never silences the other.
        $a = $this->record();
        $b = $this->record(['sender' => 'second@sender.example.test', 'comment' => 'PSA allow SECOND5160']);
        $ids = fn () => $this->events()->pluck('entity_id')->map(fn ($id) => (int) $id)->all();

        app(MeshAllowRuleReaper::class)->reap();
        $this->assertSame([$a->id, $b->id], $ids());

        $this->travel(23)->hours();
        app(MeshAllowRuleReaper::class)->reap();
        $this->assertCount(2, $this->events(), 'no repeat inside 24h');

        $this->travel(2)->hours();
        $counts = app(MeshAllowRuleReaper::class)->reap();
        $this->assertSame([$a->id, $b->id, $a->id, $b->id], $ids(), 'a fresh event per row once 24h have passed');
        $this->assertSame(2, $counts['unresolved']);
    }

    public function test_proved_reap_failed_and_dated_rows_emit_nothing(): void
    {
        $this->upstreamRule(self::UPSTREAM_ID, self::SENDER, self::COMMENT);
        $proved = $this->record(['scope_proved' => true, 'last_error' => 'Allow rule created, but its Mesh rule id could not be recovered by re-read.']);
        // Scope proved but no upstream match: stays unresolved and counted, yet it is not a scope fault.
        $this->record(['sender' => 'nomatch@sender.example.test', 'comment' => 'PSA allow NOMATCH5160', 'scope_proved' => true]);
        $this->record(['sender' => 'failed@sender.example.test', 'comment' => 'PSA allow FAILED5160', 'state' => MeshAllowRule::STATE_REAP_FAILED]);
        $this->record(['sender' => 'dated@sender.example.test', 'comment' => 'PSA allow DATED5160', 'expires_at' => now()->addMonth()]);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $proved->fresh()->state);
        $this->assertSame(['examined' => 0, 'reaped' => 0, 'unresolved' => 3, 'failed' => 0], $counts);
        $this->assertCount(0, $this->events());

        // Positive control: the same pass does emit for an unproved permanent row.
        $this->record(['sender' => 'control@sender.example.test', 'comment' => 'PSA allow CONTROL5160']);
        app(MeshAllowRuleReaper::class)->reap();
        $this->assertCount(1, $this->events());
    }

    public function test_an_emit_that_throws_changes_neither_the_counts_nor_the_exit(): void
    {
        $hub = \Mockery::mock(SignalHub::class);
        $hub->shouldReceive('emit')->once()->andThrow(new \RuntimeException('synthetic signal failure'));
        $this->app->instance(SignalHub::class, $hub);
        $record = $this->record();

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(['examined' => 0, 'reaped' => 0, 'unresolved' => 1, 'failed' => 0], $counts);
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->fresh()->state);
        $this->assertCount(0, $this->events());
    }
}
