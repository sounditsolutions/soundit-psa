<?php

namespace Tests\Feature\Mesh;

use App\Models\MeshAllowRule;
use App\Services\Mesh\MeshAllowRuleReaper;
use App\Services\Mesh\MeshWriteClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * The reaper's settle pass over UNEXPIRED rows: permanent rows and dated
 * rows whose expiry is still in the future.
 *
 * Before this pass covered dated rows, an unresolved (or reap_failed) row
 * with a future expiry was selected by neither pass. It stayed unsettled
 * until its expiry, and the duplicate brake refused every new allow rule for
 * its sender in the meantime.
 *
 * The rules under test:
 *  - a row goes active only with scope_proved AND exactly one matching rule;
 *  - the pass reads the list and writes the local row, and nothing else: no
 *    POST, PATCH or DELETE reaches Mesh;
 *  - an expired row is never settled here; it stays with reapOne();
 *  - not found, or more than one match, never guesses.
 *
 * A routing handler plays the vendor: the list is served with the measured
 * {count, next, previous, results} envelope, DELETE answers 200, and the
 * detail read answers 404 once a rule is deleted. Synthetic data only.
 */
class MeshAllowRuleReaperUnexpiredSettleTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'tenant-synthetic-7';

    /** @var array<string, array<string, mixed>> */
    private array $upstream = [];

    /** @var list<string> "METHOD path" for every request the client sent */
    private array $requests = [];

    private int $listStatus = 200;

    /** @var array<int, list<string>> row id => states written, in order */
    private array $stateWrites = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        MeshAllowRule::saving(function (MeshAllowRule $rule): void {
            if ($rule->exists && $rule->isDirty('state')) {
                $this->stateWrites[$rule->id][] = (string) $rule->state;
            }
        });

        $this->upstream = [
            'aaaaaaaa-0000-4000-8000-000000000001' => $this->row('aaaaaaaa-0000-4000-8000-000000000001', 'tenant-synthetic-OTHER', 'someone@other.example.test', 'not ours'),
        ];
    }

    private function bindClient(): void
    {
        $handler = function (RequestInterface $request) {
            $path = ltrim($request->getUri()->getPath(), '/');
            $this->requests[] = $request->getMethod().' '.$path;

            if ($request->getMethod() === 'GET' && $path === 'api/rule-allows-blocks/') {
                if ($this->listStatus !== 200) {
                    return new FulfilledPromise(new Response($this->listStatus, [], '{"detail":"synthetic failure"}'));
                }

                parse_str($request->getUri()->getQuery(), $q);
                $from = (int) ($q['_from'] ?? 0);
                $size = min((int) ($q['_size'] ?? 100), 100);
                $all = array_values($this->upstream);
                $slice = array_slice($all, $from, $size);
                $end = $from + count($slice);

                return new FulfilledPromise(new Response(200, [], json_encode([
                    'count' => count($all),
                    'next' => $end < count($all) ? "https://mesh.invalid/api/rule-allows-blocks/?_from={$end}&_size=100" : null,
                    'previous' => null,
                    'results' => $slice,
                ])));
            }

            $id = trim(substr($path, strlen('api/rule-allows-blocks/')), '/');

            if ($request->getMethod() === 'DELETE') {
                unset($this->upstream[$id]);

                return new FulfilledPromise(new Response(200, [], '{}'));
            }

            if ($request->getMethod() !== 'GET') {
                return new FulfilledPromise(new Response(201, [], '{}'));
            }

            return new FulfilledPromise(isset($this->upstream[$id])
                ? new Response(200, [], json_encode($this->upstream[$id]))
                : new Response(404, [], '{"detail":"Not found."}'));
        };

        $guzzle = new GuzzleClient([
            'base_uri' => 'https://mesh.invalid/',
            'handler' => HandlerStack::create($handler),
            'http_errors' => true,
        ]);

        $this->app->instance(MeshWriteClient::class, new MeshWriteClient(['api_key' => 'k'], $guzzle));
    }

    /** @return array<string, mixed> */
    private function row(string $id, string $tenant, string $sender, string $comment): array
    {
        return [
            'id' => $id,
            'sender' => $sender,
            'comment' => $comment,
            'ab' => true,
            'active' => true,
            'organization_level' => true,
            'customer_id' => null,
            'customer' => ['id' => $tenant, 'name' => 'Tenant'],
        ];
    }

    private function upstreamRule(string $id, string $sender, string $comment): void
    {
        $this->upstream[$id] = $this->row($id, self::TENANT, $sender, $comment);
    }

    /** @param  array<string, mixed>  $overrides */
    private function record(string $sender, string $comment, array $overrides = []): MeshAllowRule
    {
        return MeshAllowRule::create(array_merge([
            'mesh_customer_id' => self::TENANT,
            'sender' => $sender,
            'comment' => $comment,
            'mesh_rule_id' => null,
            'expires_at' => now()->addMonths(11),
            'state' => MeshAllowRule::STATE_UNRESOLVED,
            'scope_proved' => true,
            'last_error' => 'Allow rule created, but its Mesh rule id could not be recovered by re-read.',
        ], $overrides));
    }

    /** No create, change or delete verb reached Mesh. */
    private function assertNoMeshWrite(): void
    {
        $writes = array_values(array_filter($this->requests, fn (string $r) => ! str_starts_with($r, 'GET ')));
        $this->assertSame([], $writes, 'the settle pass is read plus local settle only');
        $this->assertNotSame([], $this->requests, 'positive control: the pass did read the list');
    }

    public function test_an_unexpired_unresolved_scope_proved_row_with_exactly_one_match_goes_active(): void
    {
        $this->upstreamRule('bbbbbbbb-0000-4000-8000-000000000002', 'one@sender.example.test', 'PSA allow ONEMATCH01');
        $this->bindClient();
        $record = $this->record('one@sender.example.test', 'PSA allow ONEMATCH01');

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->state);
        $this->assertSame('bbbbbbbb-0000-4000-8000-000000000002', $record->mesh_rule_id);
        $this->assertNull($record->last_error);
        $this->assertNull($record->reaped_at);
        $this->assertNotNull($record->expires_at, 'settling keeps the expiry; the row is still reaped when it passes');
        $this->assertSame(['examined' => 0, 'reaped' => 0, 'unresolved' => 0, 'failed' => 0], $counts);
        $this->assertNoMeshWrite();
    }

    /**
     * A failed mesh_remove_allow_rule leaves its row reap_failed, with its id
     * and a future expiry, so the settle pass selects it. Identifying the rule
     * answers nothing about that removal: the row is never marked active, its
     * fault text is kept and its stored id is not replaced, whether the rule
     * is still listed or not. An id-less reap_failed row gets its id recorded
     * for the reap pass and nothing else.
     */
    public function test_an_unexpired_reap_failed_row_is_never_settled_and_keeps_its_fault(): void
    {
        $this->upstreamRule('cccccccc-0000-4000-8000-000000000003', 'failed@sender.example.test', 'PSA allow REAPFAIL01');
        $this->upstreamRule('cccccccc-0000-4000-8000-000000000005', 'noid@sender.example.test', 'PSA allow REAPFAIL03');
        $this->bindClient();
        $listed = $this->record('failed@sender.example.test', 'PSA allow REAPFAIL01', [
            'mesh_rule_id' => 'cccccccc-0000-4000-8000-000000000003',
            'state' => MeshAllowRule::STATE_REAP_FAILED,
            'last_error' => "Mesh still returns allow rule 'cccccccc-0000-4000-8000-000000000003' (sender 'failed@sender.example.test') after the delete, so it was NOT removed.",
        ]);
        $gone = $this->record('gone@sender.example.test', 'PSA allow REAPFAIL02', [
            'mesh_rule_id' => 'cccccccc-0000-4000-8000-000000000004',
            'state' => MeshAllowRule::STATE_REAP_FAILED,
            'last_error' => "Whether allow rule 'cccccccc-0000-4000-8000-000000000004' (sender 'gone@sender.example.test') was removed could NOT be measured. Treat the rule as still live until it is checked.",
        ]);
        $noId = $this->record('noid@sender.example.test', 'PSA allow REAPFAIL03', [
            'state' => MeshAllowRule::STATE_REAP_FAILED,
            'last_error' => "Could not read the tenant's rule list to resolve the upstream id: synthetic.",
        ]);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        foreach ([$listed, $gone, $noId] as $record) {
            $fresh = $record->fresh();
            $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $fresh->state);
            $this->assertSame($record->last_error, $fresh->last_error, 'the removal fault is never erased');
            $this->assertSame([], $this->stateWrites[$record->id] ?? [], 'the state was never written');
        }

        $this->assertSame('cccccccc-0000-4000-8000-000000000003', $listed->fresh()->mesh_rule_id);
        $this->assertSame('cccccccc-0000-4000-8000-000000000004', $gone->fresh()->mesh_rule_id);
        $this->assertSame('cccccccc-0000-4000-8000-000000000005', $noId->fresh()->mesh_rule_id);
        $this->assertSame(0, MeshAllowRule::where('state', MeshAllowRule::STATE_ACTIVE)->count());
        $this->assertSame(['examined' => 0, 'reaped' => 0, 'unresolved' => 3, 'failed' => 0], $counts);
        $this->assertNoMeshWrite();
    }

    /** An id is not scope evidence: the id is stored, the row stays unresolved and counted. */
    public function test_an_unexpired_row_without_scope_proof_stores_the_id_and_stays_unresolved(): void
    {
        $this->upstreamRule('dddddddd-0000-4000-8000-000000000004', 'noscope@sender.example.test', 'PSA allow NOSCOPE001');
        $this->bindClient();
        $record = $this->record('noscope@sender.example.test', 'PSA allow NOSCOPE001', [
            'scope_proved' => false,
            'last_error' => 'Mesh reported this rule was added for another tenant; check the Mesh portal.',
        ]);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state);
        $this->assertSame('dddddddd-0000-4000-8000-000000000004', $record->mesh_rule_id);
        $this->assertStringContainsString('another tenant', (string) $record->last_error, 'the scope fault is never overwritten');
        $this->assertSame(1, $counts['unresolved']);
        $this->assertSame(0, $counts['examined']);
        $this->assertNoMeshWrite();

        $this->artisan('mesh:reap-allow-rules')->assertFailed();
    }

    /**
     * reapOne() never selects a PERMANENT row, so a permanent reap_failed row's
     * note must not promise a removal the PSA will prove; a dated one's still
     * may, because reapOne() retries it once its expiry passes.
     */
    public function test_a_permanent_reap_failed_row_is_not_told_the_psa_will_prove_a_removal(): void
    {
        $this->upstreamRule('bcbcbcbc-0000-4000-8000-000000000016', 'permfail@sender.example.test', 'PSA allow PERMFAIL01');
        $this->upstreamRule('bcbcbcbc-0000-4000-8000-000000000017', 'datedfail@sender.example.test', 'PSA allow DATEDFAIL1');
        $this->bindClient();
        $permanent = $this->record('permfail@sender.example.test', 'PSA allow PERMFAIL01', [
            'expires_at' => null,
            'mesh_rule_id' => 'bcbcbcbc-0000-4000-8000-000000000016',
            'state' => MeshAllowRule::STATE_REAP_FAILED,
            'last_error' => null,
        ]);
        $dated = $this->record('datedfail@sender.example.test', 'PSA allow DATEDFAIL1', [
            'mesh_rule_id' => 'bcbcbcbc-0000-4000-8000-000000000017',
            'state' => MeshAllowRule::STATE_REAP_FAILED,
            'last_error' => null,
        ]);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $permanentNote = (string) $permanent->fresh()->last_error;
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $permanent->fresh()->state);
        $this->assertStringContainsString('never retries the removal', $permanentNote);
        $this->assertStringNotContainsString('until the PSA proves a removal', $permanentNote);

        $datedNote = (string) $dated->fresh()->last_error;
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $dated->fresh()->state);
        $this->assertStringContainsString('until the PSA proves a removal against this record', $datedNote);
        $this->assertStringNotContainsString('never retries the removal', $datedNote);

        $this->assertSame(2, $counts['unresolved']);
        $this->assertNoMeshWrite();
    }

    /** Two rules carry the row's sender and comment: never guess which one it recorded. */
    public function test_an_unexpired_row_with_two_matching_rules_is_not_settled_and_the_ambiguity_is_noted(): void
    {
        $this->upstreamRule('eeeeeeee-0000-4000-8000-000000000005', 'twice@sender.example.test', 'PSA allow TWOMATCH01');
        $this->upstreamRule('eeeeeeee-0000-4000-8000-000000000006', 'twice@sender.example.test', 'PSA allow TWOMATCH01');
        $this->bindClient();
        $record = $this->record('twice@sender.example.test', 'PSA allow TWOMATCH01');

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state);
        $this->assertNull($record->mesh_rule_id);
        $this->assertStringContainsString('2 rules on this tenant match the recorded sender and comment', (string) $record->last_error);
        $this->assertSame(1, $counts['unresolved']);
        $this->assertSame([], $this->stateWrites[$record->id] ?? [], 'the state was never written');
        $this->assertNoMeshWrite();
    }

    /** The ambiguity note never replaces a scope fault: on a scope-unproved row the old text stays. */
    public function test_an_ambiguous_match_on_a_row_without_scope_proof_keeps_its_fault_and_stores_no_id(): void
    {
        $this->upstreamRule('eeeeeeee-0000-4000-8000-000000000007', 'twice@sender.example.test', 'PSA allow TWOMATCH02');
        $this->upstreamRule('eeeeeeee-0000-4000-8000-000000000008', 'twice@sender.example.test', 'PSA allow TWOMATCH02');
        $this->bindClient();
        $record = $this->record('twice@sender.example.test', 'PSA allow TWOMATCH02', [
            'scope_proved' => false,
            'last_error' => 'Mesh reported this rule was added for another tenant; check the Mesh portal.',
        ]);

        app(MeshAllowRuleReaper::class)->reap();

        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state);
        $this->assertNull($record->mesh_rule_id);
        $this->assertStringContainsString('another tenant', (string) $record->last_error);
        $this->assertNoMeshWrite();
    }

    public function test_an_unexpired_row_with_no_match_stays_unresolved_and_keeps_its_note(): void
    {
        $this->upstreamRule('ffffffff-0000-4000-8000-000000000009', 'someone-else@sender.example.test', 'PSA allow NOTTHIS001');
        $this->bindClient();
        $record = $this->record('missing@sender.example.test', 'PSA allow MISSING001');

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state);
        $this->assertNull($record->mesh_rule_id);
        $this->assertSame('Allow rule created, but its Mesh rule id could not be recovered by re-read.', $record->last_error);
        $this->assertSame(1, $counts['unresolved']);
        $this->assertNoMeshWrite();
    }

    public function test_an_unexpired_row_with_no_match_and_no_note_gets_one(): void
    {
        $this->bindClient();
        $record = $this->record('missing@sender.example.test', 'PSA allow MISSING002', ['last_error' => null]);

        app(MeshAllowRuleReaper::class)->reap();

        $record->refresh();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state);
        $this->assertStringContainsString('no rule on this tenant matches the recorded sender and comment', (string) $record->last_error);
        $this->assertStringContainsString('not due for reaping until its expiry', (string) $record->last_error);
        $this->assertNoMeshWrite();
    }

    /** An unreadable list leaves a dated reap_failed row in reap_failed with its note: the state is not rewritten. */
    public function test_an_unreadable_list_leaves_an_unexpired_row_as_it_was(): void
    {
        $this->upstreamRule('abababab-0000-4000-8000-00000000000a', 'err@sender.example.test', 'PSA allow LISTERR001');
        $this->listStatus = 503;
        $this->bindClient();
        $failed = $this->record('err@sender.example.test', 'PSA allow LISTERR001', [
            'state' => MeshAllowRule::STATE_REAP_FAILED,
            'last_error' => 'earlier delete fault',
        ]);
        $bare = $this->record('err@sender.example.test', 'PSA allow LISTERR001', ['last_error' => null]);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $failed->refresh();
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $failed->state);
        $this->assertNull($failed->mesh_rule_id);
        $this->assertSame('earlier delete fault', $failed->last_error);

        $bare->refresh();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $bare->state);
        $this->assertStringContainsString("Could not read the tenant's rule list", (string) $bare->last_error);
        // #5248 / C-56: the note is status-only; the vendor body and the
        // request URL never reach last_error.
        $this->assertStringContainsString('the Mesh host (or something in front of it) answered the rule list read with HTTP 503', (string) $bare->last_error);
        $this->assertStringNotContainsString('synthetic failure', (string) $bare->last_error);
        $this->assertStringNotContainsString('mesh.invalid', (string) $bare->last_error);

        $this->assertSame(2, $counts['unresolved']);
        $this->assertSame(0, $counts['failed']);
        $this->assertNoMeshWrite();
    }

    /**
     * An EXPIRED row is the reap pass's, never the settle pass's. Its
     * single match is found and DELETED by reapOne(), and the row never
     * passes through active on the way: a settle would turn a row due for
     * removal into "already allowed".
     */
    public function test_an_expired_unresolved_row_is_reaped_through_the_delete_path_and_never_settled(): void
    {
        $this->freezeTime();
        $this->upstreamRule('12121212-0000-4000-8000-00000000000b', 'old@sender.example.test', 'PSA allow EXPIRED001');
        $this->upstreamRule('12121212-0000-4000-8000-00000000000c', 'edge@sender.example.test', 'PSA allow EXPIRED002');
        $this->bindClient();
        $expired = $this->record('old@sender.example.test', 'PSA allow EXPIRED001', ['expires_at' => now()->subDay()]);
        // Exactly at expiry is due for reaping (scopeReapable: expires_at <= now()).
        $edge = $this->record('edge@sender.example.test', 'PSA allow EXPIRED002', ['expires_at' => now()]);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        foreach (['12121212-0000-4000-8000-00000000000b' => $expired, '12121212-0000-4000-8000-00000000000c' => $edge] as $id => $record) {
            $record->refresh();
            $this->assertSame(MeshAllowRule::STATE_REAPED, $record->state);
            $this->assertSame($id, $record->mesh_rule_id);
            $this->assertNotNull($record->reaped_at);
            $this->assertSame([MeshAllowRule::STATE_REAPED], $this->stateWrites[$record->id] ?? [], 'never settled to active first');
            $this->assertContains("DELETE api/rule-allows-blocks/{$id}/", $this->requests);
        }

        $this->assertSame(['examined' => 2, 'reaped' => 2, 'unresolved' => 0, 'failed' => 0], $counts);
    }

    /** An expired row with no match is counted once, by reapOne(), not also by the settle pass. */
    public function test_an_expired_row_with_no_match_is_counted_once_by_the_reap_path(): void
    {
        $this->bindClient();
        $record = $this->record('gone@sender.example.test', 'PSA allow EXPIRED003', ['expires_at' => now()->subDay(), 'last_error' => null]);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(['examined' => 1, 'reaped' => 0, 'unresolved' => 1, 'failed' => 0], $counts);
        $this->assertStringContainsString('cannot be reaped until it is identified', (string) $record->fresh()->last_error);
        $this->assertNoMeshWrite();
    }

    /**
     * Permanent, unexpired and expired rows in one run: each lands in its own
     * pass. The permanent row still settles (today's behaviour), the dated
     * unexpired row settles, the expired row is deleted.
     */
    public function test_permanent_unexpired_and_expired_rows_each_take_their_own_path_in_one_run(): void
    {
        $this->upstreamRule('34343434-0000-4000-8000-00000000000d', 'perm@sender.example.test', 'PSA allow PERMANENT1');
        $this->upstreamRule('34343434-0000-4000-8000-00000000000e', 'dated@sender.example.test', 'PSA allow DATED00001');
        $this->upstreamRule('34343434-0000-4000-8000-00000000000f', 'past@sender.example.test', 'PSA allow PAST000001');
        $this->bindClient();
        $permanent = $this->record('perm@sender.example.test', 'PSA allow PERMANENT1', ['expires_at' => null]);
        $dated = $this->record('dated@sender.example.test', 'PSA allow DATED00001');
        $past = $this->record('past@sender.example.test', 'PSA allow PAST000001', ['expires_at' => now()->subDay()]);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $permanent->fresh()->state);
        $this->assertSame('34343434-0000-4000-8000-00000000000d', $permanent->fresh()->mesh_rule_id);
        $this->assertNull($permanent->fresh()->expires_at);
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $dated->fresh()->state);
        $this->assertSame('34343434-0000-4000-8000-00000000000e', $dated->fresh()->mesh_rule_id);
        $this->assertSame(MeshAllowRule::STATE_REAPED, $past->fresh()->state);

        $deletes = array_values(array_filter($this->requests, fn (string $r) => str_starts_with($r, 'DELETE ')));
        $this->assertSame(['DELETE api/rule-allows-blocks/34343434-0000-4000-8000-00000000000f/'], $deletes);
        $this->assertSame(['examined' => 1, 'reaped' => 1, 'unresolved' => 0, 'failed' => 0], $counts);
        $this->assertSame([], array_values(array_filter($this->requests, fn (string $r) => str_starts_with($r, 'POST ') || str_starts_with($r, 'PATCH ') || str_starts_with($r, 'PUT '))));
    }

    /** A dated row settled active is still reaped once its expiry passes. */
    public function test_a_dated_row_settled_active_is_reaped_after_its_expiry(): void
    {
        $this->upstreamRule('56565656-0000-4000-8000-000000000010', 'later@sender.example.test', 'PSA allow LATER00001');
        $this->bindClient();
        $record = $this->record('later@sender.example.test', 'PSA allow LATER00001', ['expires_at' => now()->addDays(3)]);

        app(MeshAllowRuleReaper::class)->reap();
        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $record->fresh()->state);

        $this->travel(4)->days();
        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(1, $counts['reaped']);
        $this->assertSame(MeshAllowRule::STATE_REAPED, $record->fresh()->state);
        $this->assertContains('DELETE api/rule-allows-blocks/56565656-0000-4000-8000-000000000010/', $this->requests);
    }

    /** The scope selects exactly the unsettled, not-yet-due rows. */
    public function test_the_unsettled_unexpired_scope_selects_permanent_and_future_rows_only(): void
    {
        $this->freezeTime();
        $make = fn (string $tag, array $o) => $this->record("{$tag}@sender.example.test", "PSA allow {$tag}", $o)->id;

        $want = [
            $make('PERMUNRES', ['expires_at' => null]),
            $make('PERMFAIL', ['expires_at' => null, 'state' => MeshAllowRule::STATE_REAP_FAILED]),
            $make('FUTUNRES', ['expires_at' => now()->addSecond()]),
            $make('FUTFAIL', ['expires_at' => now()->addYear(), 'state' => MeshAllowRule::STATE_REAP_FAILED]),
        ];
        $make('PAST', ['expires_at' => now()->subSecond()]);
        $make('NOW', ['expires_at' => now()]);
        $make('FUTACTIVE', ['state' => MeshAllowRule::STATE_ACTIVE, 'mesh_rule_id' => 'x']);
        $make('PERMACTIVE', ['expires_at' => null, 'state' => MeshAllowRule::STATE_ACTIVE, 'mesh_rule_id' => 'y']);
        $make('FUTREMOVED', ['state' => MeshAllowRule::STATE_REMOVED]);

        $this->assertEqualsCanonicalizing($want, MeshAllowRule::unsettledUnexpired()->pluck('id')->all());
    }

    /**
     * A stored id alone never settles a row: the list is read, and the row goes
     * active only when that read returns exactly one rule. With no match it
     * stays unresolved and keeps both its id and its note.
     */
    public function test_an_unexpired_scope_proved_row_that_already_has_its_id_settles_only_on_a_read(): void
    {
        $this->upstreamRule('78787878-0000-4000-8000-000000000011', 'known@sender.example.test', 'PSA allow KNOWNID001');
        $this->bindClient();
        $found = $this->record('known@sender.example.test', 'PSA allow KNOWNID001', ['mesh_rule_id' => '78787878-0000-4000-8000-000000000011']);
        $missing = $this->record('unlisted@sender.example.test', 'PSA allow KNOWNID002', ['mesh_rule_id' => '78787878-0000-4000-8000-000000000012']);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(MeshAllowRule::STATE_ACTIVE, $found->fresh()->state);
        $this->assertSame('78787878-0000-4000-8000-000000000011', $found->fresh()->mesh_rule_id);
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $missing->fresh()->state);
        $this->assertSame('78787878-0000-4000-8000-000000000012', $missing->fresh()->mesh_rule_id);
        $this->assertSame('Allow rule created, but its Mesh rule id could not be recovered by re-read.', $missing->fresh()->last_error);
        $this->assertSame(1, $counts['unresolved']);
        $this->assertContains('GET api/rule-allows-blocks/', $this->requests);
        $this->assertNoMeshWrite();
    }

    /**
     * The client lookups: findRulesByComment() returns every match, and
     * findRuleByComment() still returns the first, as its existing callers
     * expect.
     */
    public function test_find_rules_by_comment_returns_every_match_and_find_rule_by_comment_still_returns_the_first(): void
    {
        $this->upstreamRule('9a9a9a9a-0000-4000-8000-000000000012', 'Twice@Sender.example.test', ' PSA allow CLIENT0001 ');
        $this->upstreamRule('9a9a9a9a-0000-4000-8000-000000000013', 'twice@sender.example.test', 'psa allow client0001');
        $this->upstreamRule('9a9a9a9a-0000-4000-8000-000000000014', 'other@sender.example.test', 'PSA allow CLIENT0001');
        $this->upstream['9a9a9a9a-0000-4000-8000-000000000015'] = $this->row('9a9a9a9a-0000-4000-8000-000000000015', 'tenant-synthetic-OTHER', 'twice@sender.example.test', 'PSA allow CLIENT0001');
        $this->bindClient();
        $client = app(MeshWriteClient::class);

        $all = $client->findRulesByComment(self::TENANT, 'twice@sender.example.test', 'PSA allow CLIENT0001');
        $this->assertSame(['9a9a9a9a-0000-4000-8000-000000000012', '9a9a9a9a-0000-4000-8000-000000000013'], array_column($all, 'id'));

        $first = $client->findRuleByComment(self::TENANT, 'twice@sender.example.test', 'PSA allow CLIENT0001');
        $this->assertSame('9a9a9a9a-0000-4000-8000-000000000012', $first['id'] ?? null);

        $this->assertSame([], $client->findRulesByComment(self::TENANT, 'nobody@sender.example.test', 'PSA allow CLIENT0001'));
        $this->assertNoMeshWrite();
    }
}
