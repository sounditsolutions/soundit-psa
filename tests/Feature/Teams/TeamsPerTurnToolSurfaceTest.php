<?php

namespace Tests\Feature\Teams;

use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiResponse;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Level\LevelClient;
use App\Services\Mesh\MeshClient;
use App\Services\Ninja\NinjaClient;
use App\Services\Teams\ResolvedSender;
use App\Services\Teams\TeamsBotClient;
use App\Services\Teams\TeamsReadOnlySurface;
use App\Services\Teams\TeamsReplyService;
use App\Services\Triage\TriageToolDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-uw2o.21 / psa-uw2o.22: the Teams turn took TWO availability snapshots, so
 * what the bot published and what it would run were answers to the same question
 * asked at two different moments.
 *
 * TeamsReplyService built the schema and the executor as two separate calls, and
 * the executor re-derived its allowlist from a SECOND definitions() call. Both
 * are live: they resolve the vendor availability probes at the moment they are
 * asked. So a vendor lane that flipped between them made the two disagree, in
 * whichever direction it flipped:
 *
 *   - GRANTED between them -> the executor runs a tool the model was never
 *     offered. A capability the published schema denies having, exercised by
 *     name. (Security proved this with Mesh: schema built with no mesh_api_key,
 *     key present by the time the executor snapshotted, mesh_get_email_events
 *     then RAN and returned vendor event data.)
 *   - REVOKED between them -> the executor refuses a tool it did publish. The
 *     model is told it can look something up and then told it cannot.
 *     (Architecture proved this with Ninja: published_first=true,
 *     ninja_probe_calls=2, executor_result=refusal.)
 *
 * WHY THESE TESTS DRIVE reply() AND NOT THE TOOLSET.
 * The defect does not live in either builder — each is correct in isolation. It
 * lives in the SEAM: the pair of values handed to AiClient for one turn. So the
 * property is asserted where that pair actually exists, by capturing the real
 * $tools and the real $executor out of a real reply() and asking whether they
 * agree. That also makes them independent of how the toolset is shaped — the
 * four reply()-driven tests below were written against the two-call API, went
 * red there, and pass unchanged against the per-turn surface that replaced it.
 *
 * (test_publishing_a_write_does_not_make_it_runnable is the exception and is not
 * red-first: it addresses the surface directly because there is no way to ask
 * "would a published write run?" through reply(), whose publisher only ever
 * emits reads. It was added with the fix and is pinned by mutation instead —
 * dropping the read conjunct is the only thing that fails it.)
 *
 * Assertions are captured and checked AFTER reply() returns: reply() is
 * fail-soft and catches every \Throwable, which would swallow an assertion
 * failing inside the callback (AssertionFailedError extends Throwable).
 */
class TeamsPerTurnToolSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private const REFUSAL = ['error' => 'That tool is not available in chat (read-only).'];

    // ── fakes ────────────────────────────────────────────────────────────────
    //
    // The vendor availability probes are the clock here: isNinjaAvailable() and
    // isLevelAvailable() resolve their client out of the container and call
    // isHealthy(), so binding an instance both COUNTS the probes and lets a turn
    // be observed (or perturbed) between them.

    /**
     * @param  list<bool>  $sequence  answer for probe #0, #1, …; the last repeats
     * @param  ?\Closure(int): void  $onProbe  called with the 0-based probe index
     */
    private function fakeNinja(array $sequence, ?\Closure $onProbe = null): object
    {
        $fake = new class extends NinjaClient
        {
            /** @var list<bool> */
            public array $sequence = [false];

            public ?\Closure $onProbe = null;

            public int $probes = 0;

            public function __construct() {}

            public function isHealthy(): bool
            {
                $index = $this->probes++;

                if ($this->onProbe !== null) {
                    ($this->onProbe)($index);
                }

                return $this->sequence[min($index, count($this->sequence) - 1)];
            }
        };

        $fake->sequence = $sequence;
        $fake->onProbe = $onProbe;
        $this->app->instance(NinjaClient::class, $fake);

        return $fake;
    }

    /** Level's isHealthy() is an unconditional live HTTP GET — always faked here. */
    private function fakeLevel(bool $healthy = false): object
    {
        $fake = new class extends LevelClient
        {
            public bool $healthy = false;

            public int $probes = 0;

            public function __construct() {}

            public function isHealthy(): bool
            {
                $this->probes++;

                return $this->healthy;
            }
        };

        $fake->healthy = $healthy;
        $this->app->instance(LevelClient::class, $fake);

        return $fake;
    }

    /**
     * A Mesh client that hands back vendor data the moment it is called, so a
     * fail-open shows up as the leaked payload rather than as a silent success.
     */
    private function fakeMesh(): object
    {
        $fake = new class extends MeshClient
        {
            public function __construct() {}

            public function get(string $endpoint, array $params = []): array
            {
                return ['events' => [['sender' => 'attacker@example.test', 'endpoint' => $endpoint]]];
            }
        };

        $this->app->instance(MeshClient::class, $fake);

        return $fake;
    }

    // ── harness ──────────────────────────────────────────────────────────────

    private function sender(User $user): ResolvedSender
    {
        return new ResolvedSender(
            user: $user,
            appId: '11111111-1111-1111-1111-111111111111',
            tenantId: '22222222-2222-2222-2222-222222222222',
            conversationId: 'a:conv-turn',
            serviceUrl: 'https://smba.trafficmanager.net/amer/',
            aadObjectId: 'aad-'.$user->id,
        );
    }

    /**
     * Run ONE real Teams turn and return the exact (schema, executor) pair that
     * turn handed to AiClient.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: callable}
     */
    private function runTurn(): array
    {
        $user = User::factory()->create(['name' => 'Charlie Coutts']);

        $tools = null;
        $executor = null;

        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('runChatWithTools')->once()
            ->andReturnUsing(function ($system, $messages, $t, $e, ...$rest) use (&$tools, &$executor): AiResponse {
                $tools = $t;
                $executor = $e;

                return new AiResponse(text: 'ok', inputTokens: 0, outputTokens: 0, stopReason: 'end_turn');
            });

        $client = $this->mock(TeamsBotClient::class);
        $client->shouldIgnoreMissing();

        (new TeamsReplyService($ai, $client))->reply($this->sender($user), 'hi', 'BlueTier IT');

        $this->assertIsArray($tools, 'the turn never reached AiClient with a tool schema');
        $this->assertIsCallable($executor, 'the turn never reached AiClient with an executor');

        return [$tools, $executor];
    }

    // ── 1. FAIL-OPEN: granted after the schema was built ─────────────────────

    /**
     * psa-uw2o.21, at the production seam.
     *
     * Mesh is unconfigured when the schema is built, so mesh_get_email_events is
     * NOT published. The grant lands during the second availability pass — the
     * one the executor used to take for itself — and the executor then runs a
     * vendor tool the model was never offered.
     *
     * *** THE CLOCK CHANGED IN psa-wzjzz — READ THIS BEFORE EDITING. *** This test used
     * to hang the mid-turn grant off the Ninja health probe (probe #0 = the schema build,
     * probe #1 = the executor's own pass). psa-wzjzz replaced the Ninja and Level liveness
     * probes with configuration checks, so isHealthy() is no longer called on this path at
     * all — the callback stopped firing, the grant never landed, and this test went GREEN
     * WHILE ASSERTING NOTHING. A vacuous pass is worse than a failure: it reads as coverage.
     *
     * The grant is now applied directly between the two things whose disagreement is the
     * defect — after the turn has published its schema, before the executor is invoked.
     * That needs no clock, and it is strictly more adversarial than the probe version was:
     * the grant is guaranteed to have landed before the executor runs, where the old
     * version depended on a probe ordering to make it happen at all.
     */
    public function test_a_tool_granted_after_the_schema_was_built_cannot_run_in_that_turn(): void
    {
        $this->fakeLevel();
        $this->fakeMesh();
        $this->fakeNinja([false]);

        [$tools, $executor] = $this->runTurn();

        Setting::setEncrypted('mesh_api_key', 'granted-after-the-schema-was-built');

        // CONTROL — without this the test can rot back into the vacuum it was just rescued
        // from. It asserts the grant genuinely took effect, so the refusal below is the
        // per-turn snapshot holding the line and not merely Mesh still being unconfigured.
        $this->assertTrue(
            TriageToolDefinitions::isMeshAvailable(),
            'the mid-turn grant did not take effect, so this test would prove nothing'
        );

        $this->assertNotContains(
            'mesh_get_email_events',
            array_column($tools, 'name'),
            'precondition: Mesh was unconfigured when the schema was built, so the turn must not publish it'
        );

        $result = $executor('mesh_get_email_events', ['queue_id' => 'q-1']);

        $this->assertSame(
            self::REFUSAL,
            $result,
            'The Teams bot RAN a vendor tool its own schema never published, and got vendor data back: '
            .json_encode($result)
        );
    }

    // ── 2. OVER-BLOCK: revoked after the schema was built ────────────────────

    /**
     * psa-uw2o.22, the mirror. A published tool must stay runnable for the whole
     * turn: the bot must not advertise a capability and then refuse it — half a
     * turn wasted, and a model told two different things.
     *
     * *** THE LEVER CHANGED IN psa-wzjzz. *** Ninja availability used to flap via the
     * health probe (healthy at schema build, unhealthy by the executor's pass). It is now
     * a configuration read, so the master switch IS the lever: Ninja is switched on and
     * credentialled when the schema is built, and switched off before the executor runs.
     * The revocation is real and lands in exactly the same window; only the mechanism moved.
     *
     * Note ninja_enabled must be set explicitly — it defaults to '0' (offboarding, psa-u97k),
     * which is precisely the default psa-wzjzz stopped the tool surface from ignoring.
     *
     * ninja_search_devices with no client context returns a plain "no client
     * context" error, so this asserts the guard's verdict without any vendor I/O.
     */
    public function test_a_tool_published_in_the_schema_stays_runnable_for_that_turn(): void
    {
        $this->fakeLevel();
        $this->fakeNinja([true, false]);

        Setting::setValue('ninja_enabled', '1');
        Setting::setValue('ninja_client_id', 'ninja-client-id');
        Setting::setEncrypted('ninja_client_secret', 'ninja-client-secret');

        [$tools, $executor] = $this->runTurn();

        Setting::setValue('ninja_enabled', '0');

        $this->assertContains(
            'ninja_search_devices',
            array_column($tools, 'name'),
            'precondition: Ninja was healthy when the schema was built, so the turn must publish it'
        );

        $this->assertNotSame(
            self::REFUSAL,
            $executor('ninja_search_devices', []),
            'The Teams bot published ninja_search_devices to the model and then refused to run it — '
            .'the executor took a second availability snapshot instead of using the published one.'
        );
    }

    // ── 3. One snapshot means one probe pass ─────────────────────────────────

    /**
     * The double snapshot was also a double cost. LevelClient::isHealthy() was an
     * unconditional live HTTP GET, so a turn that resolved availability twice paid
     * two round trips for an answer that must not change mid-turn anyway.
     *
     * psa-uw2o got that down to exactly one. *** psa-wzjzz took it to ZERO: *** vendor
     * availability is now a configuration read, so a Teams turn makes no vendor HTTP call
     * to decide its tool surface at all. Charlie accepted this trade explicitly — the
     * surface stops varying with vendor uptime, and the round trip leaves the hot path.
     *
     * Still asserted as an exact count rather than a ceiling, for the same reason it always
     * was: "at most once" is a bar the original defect already cleared. Zero is the whole
     * point, so zero is what is pinned — if a probe ever returns to this path, this fails.
     */
    public function test_the_availability_probes_never_run_on_the_turn_path(): void
    {
        $level = $this->fakeLevel();
        $ninja = $this->fakeNinja([false]);

        $this->runTurn();

        $this->assertSame(0, $ninja->probes, 'the Ninja availability probe ran '.$ninja->probes.' times in one Teams turn; availability is a config read now, so it must make no vendor call at all');
        $this->assertSame(0, $level->probes, 'the Level availability probe ran '.$level->probes.' times in one Teams turn, and each one is a live HTTP GET on the turn path');
    }

    // ── 4. The read check is a second conjunct, not a side effect ────────────

    /**
     * Publishing is necessary but not sufficient: a tool must ALSO be
     * read-classified to run.
     *
     * TeamsReadOnlyToolset only ever publishes reads, so in production the two
     * conditions coincide and no end-to-end test can tell them apart — which is
     * exactly how a conjunct rots into a comment. Handing the surface a schema
     * that publishes a WRITE is the only way to ask whether the read check is
     * still doing anything, so it is asked here directly.
     *
     * If this ever fails, the surface has become "publish anything and it runs",
     * and the read-only guarantee has quietly become a property of whoever writes
     * the publisher rather than of this class.
     */
    public function test_publishing_a_write_does_not_make_it_runnable(): void
    {
        $user = User::factory()->create();

        $surface = TeamsReadOnlySurface::of(
            [['name' => 'create_ticket', 'description' => 'forged into the published schema', 'input_schema' => []]],
            AssistantToolExecutor::readTools(),
            $user->id,
        );

        $this->assertFalse($surface->allows('create_ticket'), 'a published write must still be refused — the read classification is the second conjunct');

        $before = Ticket::count();

        $result = ($surface->executor())('create_ticket', [
            'subject' => 'guard probe',
            'description' => 'the ReadOnly surface must never persist this',
        ]);

        $this->assertSame(self::REFUSAL, $result, 'the surface ran a write because its schema advertised one');
        $this->assertSame($before, Ticket::count(), 'the ReadOnly surface persisted a ticket');
    }

    // ── 5. The invariant itself, pinned at the seam ──────────────────────────

    /**
     * Published == runnable for the turn, both directions, probed against the
     * REAL executor rather than a predicate that might describe it.
     *
     * Every dispatchable name is a candidate, so this fails if the turn can run
     * anything it did not publish (a write, an MCP-only read, a vendor lane) and
     * equally if it refuses something it did publish.
     */
    public function test_the_turn_executor_runs_exactly_what_that_turn_published(): void
    {
        $this->fakeLevel();
        $this->fakeNinja([false]);

        [$tools, $executor] = $this->runTurn();

        $published = array_column($tools, 'name');
        sort($published);
        $this->assertGreaterThan(15, count($published), 'the published surface has collapsed — the chat bot has lost most of its capability');

        $everyDispatchable = array_merge(
            AssistantToolExecutor::readTools(),
            AssistantToolExecutor::writeTools(),
        );

        $runnable = array_values(array_filter(
            $everyDispatchable,
            fn (string $name) => $executor($name, []) !== self::REFUSAL
        ));
        sort($runnable);

        $this->assertSame(
            $published,
            $runnable,
            "What the Teams turn will RUN is not what that same turn PUBLISHED.\n"
            .'Runnable but unpublished: '.(implode(', ', array_diff($runnable, $published)) ?: '(none)')."\n"
            .'Published but refused: '.(implode(', ', array_diff($published, $runnable)) ?: '(none)')
        );
    }
}
