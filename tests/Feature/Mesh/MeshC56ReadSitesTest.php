<?php

namespace Tests\Feature\Mesh;

use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshLicenseSyncService;
use App\Services\Mesh\MeshReadTools;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Psr\Http\Message\RequestInterface;
use Tests\Support\RecordsGuzzleRejections;
use Tests\TestCase;

/**
 * C-56 (card qyR9zm3y, #5282): the Mesh read sites outside #5272's list report
 * a failed call by its HTTP status (statusPhrase()) or, for a Throwable that
 * is not a MeshClientException, by its class in the log, never by message.
 *
 *   - MeshReadTools: the email-log search and the events read (tool result
 *     and log), and the queue-id cache write (log);
 *   - MeshLicenseSyncService: the per-client failure log, by client id;
 *   - MeshCustomerController::index and IntegrationsController::syncMesh:
 *     the staff flash;
 *   - #5282: MeshClientException::report(), so the exception handler's
 *     formatted record carries neither the message nor the chained Guzzle
 *     exception (request URI, host, vendor body, API-KEY header).
 *
 * The vendor error is real Guzzle output. The read tools and the license sync
 * get a MeshClient whose Guzzle is scripted. The two controllers build their
 * own MeshClient with `new`, so their call goes through Guzzle's default
 * handler to a loopback `php -S` acting as the HTTP proxy (Guzzle honours
 * HTTP_PROXY in CLI); nothing leaves 127.0.0.1. The connect-failure arm uses
 * phpunit.xml's own proxy, the discard port, which refuses.
 *
 * Synthetic data only: example.test, RFC 5737, made-up uuids.
 */
class MeshC56ReadSitesTest extends TestCase
{
    use RecordsGuzzleRejections;
    use RefreshDatabase;

    private const HOST = 'mesh.example.test';

    /** The class as a record's JSON-encoded context carries it. */
    private const CLASS_IN_CONTEXT = 'App\\\\Services\\\\Mesh\\\\MeshClientException';

    private const MESH_ID = '3f2a9c1e-5b7d-4e60-9a1b-2c3d4e5f6a7b';

    private const MESH_ID_2 = '8d4b1f6a-2c9e-4a73-b5d0-6e7f8a9b0c1d';

    /**
     * How renderContext() encodes a record's context for both instruments
     * (#5438, #5465): '/', non-ASCII letters and U+2028/U+2029 stay as
     * written; an invalid UTF-8 byte becomes U+FFFD and a NAN becomes 0, so
     * one such value no longer blanks the whole context (json_encode()
     * would otherwise return false). Each is driven by a hiddenContexts()
     * row, U+2029 and the NAN's 0 included (#5524). JSON still escapes a double quote, a
     * backslash and a control character, so a name containing one of those
     * is not matched when it sits in context.
     */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /**
     * How deep expose() follows a context (#5518): each array level and each
     * previous exception is one level, an object two (the object, then its
     * 'public' array). Past it a value, a scalar included, renders as
     * '[depth]', so a name deeper than this is not seen, and the log scans
     * fail on the marker instead (#5697); driven by
     * test_expose_cuts_a_cycle_and_names_its_depth_cap and
     * test_a_marker_that_drops_content_fails_the_log_scans.
     */
    private const EXPOSE_DEPTH = 16;

    /** Every level Laravel's logger exposes (#5467). */
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /** Body marker; a fresh suffix per process. */
    private static string $marker = '';

    /** Query-string marker (sent as a filter value). */
    private static string $queryMarker = '';

    /** The API-KEY header value the client sends; synthetic, a fresh suffix per process (G-13). */
    private static string $apiKey = '';

    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    private static string $router = '';

    /** '503' or 'connect' */
    private string $mode = '503';

    /**
     * The listener's records, each context as expose()'s snapshot, rendered
     * when a record is READ (listenerLines(), allLogs()). Neither
     * instrument's own code runs a context object's __toString() or
     * jsonSerialize() inside the Log:: call under test (#5519, #5696): the
     * listener and the setUp() TestHandler (through its formatter) store
     * that snapshot, which runs no user code; what renderContext() says is
     * read at write and what at read (#5695). WHEN in the call each takes
     * it (#5762): Laravel's Logger::writeLog() hands the record to the
     * Monolog logger first, whose handlers run in order (the TestHandler,
     * pushed in setUp(), first on the default channel), and fires
     * MessageLogged only after every handler has run. So the listener's
     * snapshot follows the channel's own handlers: on a channel whose
     * handler formats (a LineFormatter casts a Stringable, Monolog's
     * normalizer calls jsonSerialize()), that user code has already run, and
     * a __toString() or jsonSerialize() that changes its object's public
     * state is snapshotted as changed. 'At the write' for the listener
     * means after the channel's handlers, inside the same call; the
     * no-user-code timing is measured on channel('null'), whose NullHandler
     * formats nothing. A handler the channel itself carries is not an
     * instrument and keeps its own behaviour.
     *
     * @var list<array{level: string, message: string, context: array<mixed>}>
     */
    private array $logged = [];

    /**
     * A Monolog handler pushed onto the default channel's own logger in
     * setUp() (#5428): it also sees a record written through that Monolog
     * logger outside Laravel's wrapper (getLogger() directly, or a withName()
     * clone taken after setUp()), which never dispatches MessageLogged.
     * withName() clones the logger with the handler list it has at that
     * moment, so a clone taken before the push does not carry this handler.
     * All three are driven against THIS handler (#5468):
     * test_the_setup_handler_sees_a_direct_write_but_not_a_clone_taken_before_the_push
     * (direct getLogger() write; $preSetUpClone, taken in setUp() just before
     * the push) and test_a_withname_clone_carries_only_the_handlers_it_was_cloned_with
     * (clones taken after setUp()).
     */
    private TestHandler $monolog;

    /** A withName() clone of the default channel's Monolog logger, taken in setUp() BEFORE the TestHandler push (#5468). */
    private \Monolog\Logger $preSetUpClone;

    /** Request paths bindRealSync()'s scripted Mesh received, in order. @var list<string> */
    private array $syncRequestPaths = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $rand = bin2hex(random_bytes(4));
        self::$marker = 'SYNTHETIC-VENDOR-BODY-'.$rand;
        self::$queryMarker = 'SYNTHETIC-QUERY-'.$rand;
        self::$apiKey = sprintf('SYNTHETIC-KEY-%s', $rand);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$router !== '' && is_file(self::$router)) {
            unlink(self::$router);
        }
        self::$server = null;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = ['level' => $e->level, 'message' => $e->message, 'context' => self::expose($e->context)];
        });
        $this->monolog = new TestHandler;
        // #5695, #5696: the handler's formatter takes expose()'s write-time
        // snapshot as the record's 'formatted' value, in place of the
        // default LineFormatter, which casts a Stringable inside the write.
        $this->monolog->setFormatter(new class(fn (LogRecord $r) => self::expose($r->context)) implements FormatterInterface
        {
            public function __construct(private \Closure $snapshot) {}

            public function format(LogRecord $record): mixed
            {
                return ($this->snapshot)($record);
            }

            public function formatBatch(array $records): mixed
            {
                return array_map(fn (LogRecord $r) => $this->format($r), $records);
            }
        });
        $this->preSetUpClone = Log::driver()->getLogger()->withName('probe-pre-setup');
        Log::driver()->getLogger()->pushHandler($this->monolog);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_PROXY']);
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function modes(): array
    {
        return ['HTTP 503' => ['503'], 'connect failure, no status' => ['connect']];
    }

    // ---- MeshReadTools ----------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_failed_log_search_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $client = $this->mappedClient();
        $this->app->instance(MeshClient::class, $this->scriptedClient(fn (RequestInterface $r) => $this->failure($r)));

        $out = (new MeshReadTools)->search($client, $client->id, ['subject' => self::$queryMarker], '[Test]');

        $this->assertArrayHasKey('error', $out);
        $this->assertStringStartsWith('Mesh query failed: ', $out['error'], 'the PSA prose is kept');
        $this->assertStatusOnly($out['error'], 'search tool result');
        $this->assertStringContainsString('email-log search', $out['error']);

        $log = $this->logsContaining('Mesh log search failed');
        $this->assertStatusOnly($log, 'search log');
        $this->assertStringContainsString(self::CLASS_IN_CONTEXT, $log, 'search log: the exception class is named');
        $this->assertNoVendorTextInLogs();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_failed_events_read_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $client = $this->mappedClient();
        $queueId = 'SYNTHQ'.self::$queryMarker;
        $this->app->instance(MeshClient::class, $this->scriptedClient(function (RequestInterface $r) use ($queueId) {
            if (str_ends_with($r->getUri()->getPath(), 'emaillogs/')) {
                return Create::promiseFor(new Response(200, [], json_encode(['list' => [
                    ['Customer-ID' => self::MESH_ID, 'queue_id' => $queueId],
                ]])));
            }

            return $this->failure($r);
        }));
        $tools = new MeshReadTools;
        $this->assertSame(1, $tools->search($client, $client->id, [], '[Test]')['total'] ?? null, 'precondition: this client\'s search served the queue id');

        $out = $tools->events($client, $client->id, ['queue_id' => $queueId], '[Test]');

        $this->assertArrayHasKey('error', $out);
        $this->assertStringStartsWith('Mesh query failed: ', $out['error']);
        $this->assertStatusOnly(str_replace($queueId, '', $out['error']), 'events tool result');
        $this->assertStringContainsString('email-events read', $out['error']);

        $log = $this->logsContaining('Mesh events query failed');
        $this->assertStringContainsString($queueId, $log, 'the queue id stays in the log context');
        $this->assertStatusOnly(str_replace($queueId, '', $log), 'events log');
        $this->assertStringContainsString(self::CLASS_IN_CONTEXT, $log);
    }

    /** A Throwable that is not a MeshClientException: no message out, its class in the log. */
    public function test_a_foreign_failure_in_either_read_tool_reports_no_message(): void
    {
        $client = $this->mappedClient();
        $this->app->instance(MeshClient::class, $this->scriptedClient($this->foreignFailure()));
        $tools = new MeshReadTools;

        $search = $tools->search($client, $client->id, [], '[Test]');
        Cache::put('mesh-read-scope:queue:'.$client->id.':'.sha1('q-1'), MeshReadTools::normaliseId(self::MESH_ID), 60);
        $events = $tools->events($client, $client->id, ['queue_id' => 'q-1'], '[Test]');

        foreach (['search' => $search, 'events' => $events] as $where => $out) {
            $this->assertArrayHasKey('error', $out, $where);
            $this->assertNoVendorText($out['error'], "{$where} tool result");
            $this->assertStringContainsString('failed with an unexpected error', $out['error'], $where);
        }
        $log = $this->logsContaining('[Test] Mesh');
        $this->assertNoVendorText($log, 'read tool logs');
        $this->assertStringContainsString('RuntimeException', $this->logsContaining('Mesh log search failed'), 'search: the class is logged');
        $this->assertStringContainsString('RuntimeException', $this->logsContaining('Mesh events query failed'), 'events: the class is logged');
    }

    public function test_a_queue_id_cache_failure_logs_the_class_only(): void
    {
        $client = $this->mappedClient();
        $this->app->instance(MeshClient::class, $this->scriptedClient(fn () => Create::promiseFor(new Response(200, [], json_encode(['list' => [
            ['Customer-ID' => self::MESH_ID, 'queue_id' => 'q-2'],
        ]])))));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('redis at '.self::HOST.' said '.self::$marker));

        $out = (new MeshReadTools)->search($client, $client->id, [], '[Test]');

        $this->assertSame(1, $out['total'] ?? null, 'the rows are still served');
        $log = $this->logsContaining('Mesh queue id could not be recorded');
        $this->assertNoVendorText($log, 'queue-id log');
        $this->assertStringContainsString('RuntimeException', $log, 'the class is logged');
    }

    // ---- MeshLicenseSyncService -------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_failed_license_sync_logs_the_client_id_and_status_only(string $mode): void
    {
        $this->mode = $mode;
        $client = $this->mappedClient();

        $result = (new MeshLicenseSyncService($this->scriptedClient(fn (RequestInterface $r) => $this->failure($r))))->syncLicenses();

        $this->assertSame(1, $result->errors);
        $log = $this->logsContaining('[MeshSync] Failed for client');
        $this->assertStringContainsString('[MeshSync] Failed for client '.$client->id.':', $log, 'the client is named by id');
        $this->assertNoClientNames([$client]);
        $this->assertStatusOnly($log, 'license sync log');
        $this->assertStringContainsString('('.MeshClientException::class.')', $log, 'the class is named');
        $this->assertNoVendorTextInLogs();
    }

    public function test_a_foreign_failure_in_the_license_sync_logs_the_class_only(): void
    {
        $client = $this->mappedClient();

        (new MeshLicenseSyncService($this->scriptedClient($this->foreignFailure())))->syncLicenses();

        $log = $this->logsContaining('[MeshSync] Failed for client '.$client->id.':');
        // #5440: the name in any letter case, in any record either instrument
        // captured, not only this line; vendor text likewise.
        $this->assertNoClientNames([$client]);
        $this->assertNoVendorText($log, 'license sync log');
        $this->assertNoVendorTextInLogs();
        $this->assertStringContainsString('RuntimeException', $log, 'the class is logged');
    }

    // ---- staff flashes ----------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_the_customer_mapping_page_flashes_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->loopbackMesh();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.mesh-customers.index'));

        $response->assertRedirect(route('settings.integrations'));
        $flash = (string) session('error');
        $this->assertStringStartsWith('Could not load Mesh customers: ', $flash, 'the PSA prose is kept');
        $this->assertStringNotContainsString('connect', $flash, 'not "could not connect" when Mesh may have answered');
        $this->assertStatusOnly($flash, 'mapping page flash');
        $this->assertNoVendorTextInLogs();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_the_mesh_sync_button_keeps_vendor_text_out_end_to_end(string $mode): void
    {
        $this->mode = $mode;
        $this->loopbackMesh();
        $client = $this->mappedClient();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.mesh.sync'));

        // syncLicenses() absorbs a per-client failure into $result->errors and
        // logs it by client id; the button must still flash a failure (#5293).
        $response->assertRedirect(route('settings.integrations'));
        $this->assertNull(session('success'), 'a sync whose only client failed is not a success');
        $this->assertSame(
            'Mesh sync finished with 1 client error(s): 0 created, 0 updated. The PSA log names each failed client by id.',
            session('error'),
        );
        $this->assertNoVendorText((string) session('success').(string) session('error'), 'sync flash');
        $log = $this->logsContaining('[MeshSync] Failed for client '.$client->id.':');
        $this->assertStatusOnly($log, 'sync log');
        $this->assertNoClientNames([$client]);
        $this->assertNoVendorTextInLogs();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_mesh_failure_reaching_the_sync_button_flashes_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        Setting::setEncrypted('mesh_api_key', self::$apiKey);
        $thrown = $this->realFailure();
        $this->bindSync()->shouldReceive('syncLicenses')->andThrow($thrown);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.mesh.sync'))
            ->assertRedirect(route('settings.integrations'));

        $flash = (string) session('error');
        $this->assertStringStartsWith('Mesh sync failed: ', $flash);
        $this->assertStatusOnly($flash, 'sync flash');
    }

    public function test_a_foreign_failure_reaching_the_sync_button_flashes_no_message(): void
    {
        Setting::setEncrypted('mesh_api_key', self::$apiKey);
        $this->bindSync()->shouldReceive('syncLicenses')
            ->andThrow(new \RuntimeException('at '.self::HOST.' '.self::$marker));

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.mesh.sync'));

        $flash = (string) session('error');
        $this->assertStringStartsWith('Mesh sync failed', $flash);
        $this->assertNoVendorText($flash, 'sync flash');
        $log = $this->logsContaining('[MeshSync] Sync failed');
        $this->assertNoVendorText($log, 'sync log');
        $this->assertStringContainsString('RuntimeException', $log, 'the class is logged');
    }

    // ---- #5293: per-client failures reach the sync button -----------------------

    /**
     * Driven in both modes (#5424): the 503 arm and the status-less
     * (connect-failure) arm of BOTH failure lines, MeshClient::request()'s
     * and syncLicenses()', are pinned with their level and full text.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_the_sync_button_flashes_a_failure_when_every_client_fails(string $mode): void
    {
        $a = $this->mappedClient();
        $b = $this->mappedClient(self::MESH_ID_2, 'Synthetic Client Name 9e4b');
        $this->bindRealSync([self::MESH_ID, self::MESH_ID_2], $mode);

        $flash = $this->pressSync('error');

        $this->assertSame(
            'Mesh sync finished with 2 client error(s): 0 created, 0 updated. The PSA log names each failed client by id.',
            $flash,
        );
        $this->assertNull(session('success'), 'no green flash when every client failed');
        $this->assertFlashHasNoClientData($flash, [$a, $b]);
        // Positive controls (#5382): a customer read carrying EACH Mesh id
        // reached the scripted vendor, so the MESH_ID and MESH_ID_2 absence
        // checks below are about ids that were really in flight.
        $this->assertSyncRequested([self::MESH_ID, self::MESH_ID_2]);
        // Every MeshClient and MeshSync failure record, level and full text:
        // one MeshClient line and one MeshSync line per failed client, all at
        // 'error' (a demoted line fails here, #5404, in either mode, #5424),
        // and each MeshSync line is exactly the PSA client id plus the status
        // phrase and class, so it carries no other client identifier (C-56,
        // #5406).
        $this->assertSame([
            $this->meshClientFailureRecord($mode),
            $this->meshClientFailureRecord($mode),
        ], $this->recordsContaining('[MeshClient] '), 'MeshClient failure records');
        $this->assertSame(
            $this->sorted([$this->meshSyncFailureRecord($a, $mode), $this->meshSyncFailureRecord($b, $mode)]),
            $this->recordsContaining('[MeshSync] Failed for client '),
            'MeshSync failure records: one per failed client, by PSA id only',
        );
        $this->assertNoClientNames([$a, $b]);
        $this->assertNoVendorTextInLogs();
    }

    public function test_the_sync_button_flashes_a_failure_when_one_of_two_clients_fails(): void
    {
        $ok = $this->mappedClient();
        $bad = $this->mappedClient(self::MESH_ID_2, 'Synthetic Client Name 9e4b');
        $this->bindRealSync([self::MESH_ID_2]);

        $flash = $this->pressSync('error');

        $this->assertSame(
            'Mesh sync finished with 1 client error(s): 1 created, 0 updated. The PSA log names each failed client by id.',
            $flash,
        );
        $this->assertNull(session('success'), 'no green flash when a client failed');
        $this->assertFlashHasNoClientData($flash, [$ok, $bad]);
        $this->assertSame(1, \App\Models\License::where('client_id', $ok->id)->count(), 'positive control: the healthy client synced');
        // Positive controls (#5382): both ids were requested; only the
        // MESH_ID_2 client failed, so exactly one failure record of each
        // kind, at 'error' (#5404), the MeshSync one being exactly the failed
        // client's PSA id plus the status phrase and class (C-56, #5406).
        $this->assertSyncRequested([self::MESH_ID, self::MESH_ID_2]);
        $this->assertSame([$this->meshClientFailureRecord()], $this->recordsContaining('[MeshClient] '), 'MeshClient failure records');
        $this->assertSame(
            [$this->meshSyncFailureRecord($bad)],
            $this->recordsContaining('[MeshSync] Failed for client '),
            'MeshSync failure records: the failed client only, by PSA id only',
        );
        $this->assertNoClientNames([$ok, $bad]);
        $this->assertNoVendorTextInLogs();
    }

    public function test_the_sync_button_flashes_success_unchanged_when_no_client_fails(): void
    {
        $this->mappedClient();
        $this->mappedClient(self::MESH_ID_2, 'Synthetic Client Name 9e4b');
        $this->bindRealSync([]);

        $flash = $this->pressSync('success');

        $this->assertSame('Mesh sync complete: 2 created, 0 updated.', $flash);
        $this->assertNull(session('error'), 'a clean sync flashes no error');
    }

    /**
     * Positive controls for assertNoClientNames() (#5423, #5428, #5430,
     * #5466): it fails on a real record carrying a client name in another
     * letter case, and each of its two arms is driven ALONE, so deleting
     * either arm fails here. Per route, assertCaughtOnRoute() reads each
     * instrument's records exactly (the holder holds exactly the record, at
     * its level and as written; the other holds nothing), then the failure
     * message names the arm that caught it.
     *   - Log::channel('null'): another LogManager channel; its Monolog logger
     *     has no TestHandler, so only the listener (allLogs()) sees it.
     *   - withName() on the default channel's Monolog logger, taken after
     *     setUp(): no MessageLogged, so only the TestHandler sees it.
     *   - the facade on the default channel: both see it; the listener arm,
     *     read first, catches it.
     */
    public function test_the_no_client_names_assertion_fails_on_a_recased_name_on_each_route(): void
    {
        $client = $this->mappedClient();
        $this->assertNoClientNames([$client]);
        $check = fn () => $this->assertNoClientNames([$client]);
        $up = strtoupper($client->name);
        $down = strtolower($client->name);

        $this->assertCaughtOnRoute('channel(null), upper-cased, warning', fn () => Log::channel('null')->warning('[Probe] '.$up),
            "warning [Probe] {$up} []", true, false, $check, 'MessageLogged records: a client name reached the logs');
        $this->assertCaughtOnRoute('withName(), lower-cased, debug', fn () => Log::driver()->getLogger()->withName('probe')->debug('[Probe] '.$down),
            "debug [Probe] {$down} []", false, true, $check, 'default-channel Monolog records: a client name reached the logs');
        $this->assertCaughtOnRoute('facade, upper-cased, error', fn () => Log::error('[Probe] '.$up),
            "error [Probe] {$up} []", true, true, $check, 'MessageLogged records: a client name reached the logs');
    }

    /**
     * The write routes the two instruments are claimed to see (#5467), each
     * as [target, via, listener sees, TestHandler sees]: target names the
     * logger the test resolves with match() ('null', 'facade', 'withName',
     * 'getLogger'), and via names the entry writeVia() calls ('method',
     * 'log', 'write') (#5525).
     *
     * @return array<string, array{string, string, bool, bool}>
     */
    public static function writeRoutes(): array
    {
        return [
            'channel(null), level method' => ['null', 'method', true, false],
            'channel(null), log()' => ['null', 'log', true, false],
            'channel(null), write()' => ['null', 'write', true, false],
            'facade, level method' => ['facade', 'method', true, true],
            'facade, log()' => ['facade', 'log', true, true],
            'facade, write()' => ['facade', 'write', true, true],
            'withName() clone, level method' => ['withName', 'method', false, true],
            'withName() clone, log()' => ['withName', 'log', false, true],
            'getLogger() direct, level method' => ['getLogger', 'method', false, true],
            'getLogger() direct, log()' => ['getLogger', 'log', false, true],
        ];
    }

    /**
     * #5467, #5468: at EVERY level the logger exposes, and through the
     * generic log()/write() entries, each route's record reaches exactly the
     * instruments claimed (read as 'level text', so a record dropped or moved
     * to another level fails), and both assertNoClientNames() and
     * assertNoVendorTextInLogs() fail on it through each instrument that
     * holds it: where both hold it, the listener's records are then cleared
     * and the TestHandler arm must catch it too. (Monolog's own Logger has no
     * write(), so the two raw-Monolog routes drive the level method and
     * log() only.)
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('writeRoutes')]
    public function test_both_assertions_fail_at_every_level_and_generic_entry_on_each_route(string $target, string $via, bool $listenerSees, bool $handlerSees): void
    {
        $client = $this->mappedClient();
        $up = strtoupper($client->name);
        foreach (self::LEVELS as $level) {
            $message = "[Probe] {$up} ".self::$marker;
            $logger = match ($target) {
                'null' => Log::channel('null'),
                'facade' => Log::getFacadeRoot(),
                'withName' => Log::driver()->getLogger()->withName('probe'),
                'getLogger' => Log::driver()->getLogger(),
            };
            $write = fn () => self::writeVia($logger, $via, $level, $message);
            $expected = "{$level} {$message} []";
            $arms = array_keys(array_filter(['MessageLogged records' => $listenerSees, 'default-channel Monolog records' => $handlerSees]));
            $vendorArms = ['MessageLogged records' => 'every MessageLogged record', 'default-channel Monolog records' => 'every default-channel Monolog record'];

            $this->assertCaughtOnRoute("{$target} {$via} {$level}, names", $write, $expected, $listenerSees, $handlerSees,
                fn () => $this->assertNoClientNames([$client]), "{$arms[0]}: a client name reached the logs");
            $this->assertCaughtOnRoute("{$target} {$via} {$level}, vendor text", $write, $expected, $listenerSees, $handlerSees,
                fn () => $this->assertNoVendorTextInLogs(), "{$vendorArms[$arms[0]]}: vendor body leaked");
            if (count($arms) === 2) {
                // Both hold it: with the listener's records cleared, the
                // TestHandler arm alone must catch the same record.
                $this->logged = [];
                $this->assertSame([$expected], $this->monologLines(), "{$target} {$via} {$level}: the TestHandler still holds the record");
                $this->assertArmFails(fn () => $this->assertNoClientNames([$client]), 'default-channel Monolog records: a client name reached the logs', "{$target} {$via} {$level}");
                $this->assertArmFails(fn () => $this->assertNoVendorTextInLogs(), 'every default-channel Monolog record: vendor body leaked', "{$target} {$via} {$level}");
            }
        }
    }

    /**
     * Positive controls for assertNoVendorTextInLogs() (#5434, #5466): the
     * vendor body marker and an upper-cased Mesh id, each written on a route
     * only ONE instrument sees, fail it through that instrument's arm. Per
     * route, assertCaughtOnRoute() reads that the holder holds exactly the
     * record at 'error' and that the other instrument holds nothing.
     */
    public function test_the_vendor_text_assertion_fails_on_each_route(): void
    {
        $this->assertNoVendorTextInLogs();

        $routes = [
            'channel(null)' => [fn (string $t) => Log::channel('null')->error('[Probe] '.$t), true, false, 'every MessageLogged record'],
            'withName()' => [fn (string $t) => Log::driver()->getLogger()->withName('probe')->error('[Probe] '.$t), false, true, 'every default-channel Monolog record'],
        ];
        $payloads = [
            'vendor body' => [self::$marker, 'vendor body leaked'],
            'Mesh id, upper-cased' => [strtoupper(self::MESH_ID), 'request path (Mesh id, raw) leaked'],
        ];
        foreach ($routes as $route => [$write, $listenerSees, $handlerSees, $arm]) {
            foreach ($payloads as $what => [$text, $why]) {
                $this->assertCaughtOnRoute("{$route}, {$what}", fn () => $write($text), "error [Probe] {$text} []",
                    $listenerSees, $handlerSees, fn () => $this->assertNoVendorTextInLogs(), "{$arm}: {$why}");
            }
        }
    }

    /**
     * #5438, #5466: a name carrying '/' and a non-ASCII letter, logged as a
     * CONTEXT value on each instrument's own route (and re-cased on one), is
     * still caught by that instrument's arm: the record is read exactly on
     * its holder, at 'warning' and with the name unescaped (plain
     * json_encode() would write 'A\/B' and 'Caf\u00e9'), and the other
     * instrument is read empty.
     */
    public function test_the_no_client_names_assertion_matches_a_name_with_a_slash_or_non_ascii_letter_in_context(): void
    {
        $client = $this->mappedClient(self::MESH_ID, 'Synthetic A/B Café 5e2a');
        $this->assertNoClientNames([$client]);
        $check = fn () => $this->assertNoClientNames([$client]);
        $upper = mb_strtoupper($client->name);

        $this->assertCaughtOnRoute('channel(null), upper-cased',
            fn () => Log::channel('null')->warning('[Probe]', ['client' => $upper]),
            'warning [Probe] {"client":"'.$upper.'"}', true, false, $check, 'MessageLogged records: a client name reached the logs');
        $this->assertCaughtOnRoute('withName(), as written',
            fn () => Log::driver()->getLogger()->withName('probe')->warning('[Probe]', ['client' => $client->name]),
            'warning [Probe] {"client":"'.$client->name.'"}', false, true, $check, 'default-channel Monolog records: a client name reached the logs');
    }

    /**
     * #5433: withName() clones the logger with the handlers it has at that
     * moment. A clone taken before a handler is pushed does not carry it; one
     * taken after does. Driven here with a $late handler; the same pair for
     * the setUp() handler itself is driven by
     * test_the_setup_handler_sees_a_direct_write_but_not_a_clone_taken_before_the_push.
     */
    public function test_a_withname_clone_carries_only_the_handlers_it_was_cloned_with(): void
    {
        $logger = Log::driver()->getLogger();
        $before = $logger->withName('probe-before');
        $late = new TestHandler;
        $logger->pushHandler($late);
        try {
            $after = $logger->withName('probe-after');
            $before->info('[Probe] cloned before the push');
            $after->notice('[Probe] cloned after the push');
        } finally {
            // Restore only; the check is below, so a throw above is never
            // replaced by this one (#5464).
            $popped = $logger->popHandler();
        }

        $this->assertSame($late, $popped, 'the late handler is removed again');
        $this->assertSame(['notice [Probe] cloned after the push'], self::levelMessages($late), 'only the clone taken after the push reached the late handler');
        $this->assertSame(
            ['info [Probe] cloned before the push', 'notice [Probe] cloned after the push'],
            self::levelMessages($this->monolog),
            'positive control (#5469): each clone, taken after setUp(), wrote its own record, at its level, to the setUp() TestHandler',
        );
    }

    /**
     * #5468: the setUp() TestHandler itself, both halves. A write through
     * getLogger() directly reaches it (and not the listener: no
     * MessageLogged); a withName() clone taken in setUp() just before the
     * push ($preSetUpClone, standing in for one taken during boot) does not
     * reach it, nor the listener. A $witness handler pushed onto that
     * clone, and read alone, receives the write: a positive control that
     * the clone really wrote. No handler the default logger already carried
     * is read (#5527).
     */
    public function test_the_setup_handler_sees_a_direct_write_but_not_a_clone_taken_before_the_push(): void
    {
        $client = $this->mappedClient();
        $this->assertCaughtOnRoute('getLogger() direct, info', fn () => Log::driver()->getLogger()->info('[Probe] '.$client->name),
            "info [Probe] {$client->name} []", false, true, fn () => $this->assertNoClientNames([$client]),
            'default-channel Monolog records: a client name reached the logs');

        $this->logged = [];
        $this->monolog->clear();
        $witness = new TestHandler;
        $this->preSetUpClone->pushHandler($witness);
        try {
            $this->preSetUpClone->info('[Probe] cloned before the setUp() push');
        } finally {
            $popped = $this->preSetUpClone->popHandler();
        }
        $this->assertSame($witness, $popped, 'the witness is removed again');
        $this->assertSame(['info [Probe] cloned before the setUp() push'], self::levelMessages($witness), 'positive control: the clone wrote');
        $this->assertSame([], $this->monolog->getRecords(), 'a clone taken before the setUp() push does not reach the setUp() TestHandler');
        $this->assertSame([], $this->logged, 'nor the listener');
    }

    /**
     * A withName() clone of the default channel's Monolog logger whose only
     * handler is the setUp() TestHandler: the instrument driven alone, with
     * none of the channel's own handlers (#5696).
     */
    private function handlerOnlyClone(): \Monolog\Logger
    {
        return Log::driver()->getLogger()->withName('probe')->setHandlers([$this->monolog]);
    }

    /** @return list<string> a TestHandler's records, 'level message' each */
    private static function levelMessages(TestHandler $handler): array
    {
        return array_map(fn ($r) => strtolower($r->level->getName()).' '.$r->message, $handler->getRecords());
    }

    /**
     * Context shapes the instruments must render so the name is seen, each
     * as [context builder over the client name, the rendered fragment that
     * must appear] (#5704): shapes plain json_encode() hides (a Throwable's
     * message and previous chain, a Stringable's string form, a value
     * beside or inside an invalid UTF-8 byte or a NAN, U+2028/U+2029
     * escaped) and shapes it shows that expose() must not drop (a
     * Throwable's public property and jsonSerialize() output, a plain or
     * Stringable object's public property) (#5465, #5520, #5522, #5524).
     *
     * @return array<string, array{\Closure(string): array<mixed>, \Closure(string): string}>
     */
    public static function hiddenContexts(): array
    {
        return [
            // json_encode() writes a Throwable as its public properties alone: {} for this one.
            'a Throwable whose message carries the name' => [
                fn (string $n) => ['exception' => new \RuntimeException('failed for '.$n)],
                fn (string $n) => '"message":"failed for '.$n.'"',
            ],
            'a name only in the previous exception' => [
                fn (string $n) => ['exception' => new \LogicException('outer', 0, new \RuntimeException('inner '.$n))],
                fn (string $n) => '"message":"inner '.$n.'"',
            ],
            // json_encode() writes these two; expose() must not drop them.
            "a name only in a Throwable's public property" => [
                fn (string $n) => ['exception' => new class($n) extends \RuntimeException
                {
                    public function __construct(public string $client)
                    {
                        parent::__construct('failed');
                    }
                }],
                fn (string $n) => '"public":{"client":"'.$n.'"}',
            ],
            "a name only in a JsonSerializable Throwable's jsonSerialize()" => [
                fn (string $n) => ['exception' => new class($n) extends \RuntimeException implements \JsonSerializable
                {
                    public function __construct(private string $c)
                    {
                        parent::__construct('failed');
                    }

                    public function jsonSerialize(): mixed
                    {
                        return ['client' => $this->c];
                    }
                }],
                fn (string $n) => '"json":{"client":"'.$n.'"}',
            ],
            // json_encode() writes only public properties; a Stringable's string form is not one.
            'a Stringable object' => [
                fn (string $n) => ['who' => new class($n) implements \Stringable
                {
                    public function __construct(private string $n) {}

                    public function __toString(): string
                    {
                        return 'client '.$this->n;
                    }
                }],
                fn (string $n) => '"string":"client '.$n.'"',
            ],
            // Without the substitute/partial flags json_encode() returns false and the context renders as ''.
            'the name beside an invalid UTF-8 value' => [
                fn (string $n) => ['client' => $n, 'raw' => "bad \xC3\x28 byte"],
                fn (string $n) => '"client":"'.$n.'"',
            ],
            // Without the substitute flag the partial output writes that whole string as null.
            'the name in a string carrying an invalid UTF-8 byte' => [
                fn (string $n) => ['client' => $n." \xC3\x28"],
                fn (string $n) => '"client":"'.$n." \u{FFFD}(".'"',
            ],
            // The NAN is read: it renders as 0 (#5524).
            'the name beside a NAN' => [
                fn (string $n) => ['client' => $n, 'ratio' => NAN],
                fn (string $n) => '"client":"'.$n.'","ratio":0}',
            ],
            // #5520: the 'public' element for a plain object, and for a
            // Stringable whose string form does not hold the name (#5522).
            "a name only in a plain object's public property" => [
                fn (string $n) => ['dto' => (object) ['client' => $n]],
                fn (string $n) => '"dto":{"class":"stdClass","string":null,"public":{"client":"'.$n.'"}}',
            ],
            "a name only in a Stringable's public property" => [
                fn (string $n) => ['who' => new class($n) implements \Stringable
                {
                    public function __construct(public string $client) {}

                    public function __toString(): string
                    {
                        return 'someone';
                    }
                }],
                fn (string $n) => '"string":"someone","public":{"client":"'.$n.'"}}',
            ],
            // Without JSON_UNESCAPED_LINE_TERMINATORS U+2028 is written as \u2028.
            'a name carrying U+2028' => [
                fn (string $n) => ['client' => $n."\u{2028}"],
                fn (string $n) => '"client":"'.$n."\u{2028}".'"',
            ],
            // Likewise U+2029 (#5524).
            'a name carrying U+2029' => [
                fn (string $n) => ['client' => $n."\u{2029}"],
                fn (string $n) => '"client":"'.$n."\u{2029}".'"',
            ],
        ];
    }

    /**
     * #5465: each hiddenContexts() shape, hidden by plain json_encode() or
     * shown by it and kept by expose() (#5704), is rendered by BOTH
     * instruments so the name is seen: on each
     * instrument's own route
     * the holder holds one record at 'error' whose text carries the
     * fragment, the other holds nothing, and assertNoClientNames() fails
     * through the holder's arm. (For U+2028 the client's stored name carries
     * the separator, so the match is on the name as written; likewise
     * U+2029.)
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hiddenContexts')]
    public function test_the_instruments_render_context_that_plain_json_encode_hides(\Closure $context, \Closure $fragment): void
    {
        $client = $this->mappedClient(self::MESH_ID, 'Synthetic Client Name 3b8f');
        $name = $client->name;
        foreach (["\u{2028}", "\u{2029}"] as $separator) {
            if (str_contains($fragment('X'), $separator)) {
                $client->update(['name' => $name.$separator]);
            }
        }
        $routes = [
            'channel(null)' => [fn () => Log::channel('null')->error('[Probe]', $context($name)), true, 'MessageLogged records'],
            'withName()' => [fn () => Log::driver()->getLogger()->withName('probe')->error('[Probe]', $context($name)), false, 'default-channel Monolog records'],
        ];
        foreach ($routes as $route => [$write, $listener, $arm]) {
            $this->logged = [];
            $this->monolog->clear();
            $write();
            [$holder, $other] = $listener ? [$this->listenerLines(), $this->monologLines()] : [$this->monologLines(), $this->listenerLines()];
            $this->assertCount(1, $holder, "{$route}: the holder holds one record");
            $this->assertStringStartsWith('error [Probe] {', $holder[0], "{$route}: at 'error', with its context rendered");
            $this->assertStringContainsString($fragment($name), $holder[0], "{$route}: the hidden value is rendered");
            $this->assertSame([], $other, "{$route}: the other instrument did not see it");
            $this->assertArmFails(fn () => $this->assertNoClientNames([$client]), "{$arm}: a client name reached the logs", $route);
        }
    }

    /**
     * #5519, #5695, #5696: on EACH instrument's own route, the write runs no
     * user code and takes a snapshot; reading runs the deferred user code.
     * The listener is driven on channel('null') (its NullHandler formats
     * nothing) and the setUp() TestHandler on handlerOnlyClone() (no
     * MessageLogged, and not the channel's own file handler, whose
     * LineFormatter does cast a Stringable during the write), each alone. A Stringable that counts its casts is not
     * cast by the write, on either route; reading casts it. Likewise a
     * JsonSerializable, and a JsonSerializable Throwable, that count their
     * jsonSerialize() calls: no call at the write, one at the read, and
     * the output shows the state at the read (#5759). A public
     * property, a value in a nested array held by PHP reference (so the
     * change reaches the context array the record was given, #5760) and a
     * Throwable's message are read
     * at the write: the client name they held is still seen after each is
     * changed, and the changed value is not (#5695). The string form is
     * read at read time: it shows the later state. A __toString() that
     * throws does not throw out of the write, and reads as a marker.
     */
    public function test_context_is_snapshotted_at_write_and_user_code_runs_at_read(): void
    {
        $client = $this->mappedClient(self::MESH_ID, 'Synthetic Client Name 4d0e');
        $routes = [
            'channel(null)' => [fn (array $c) => Log::channel('null')->error('[Probe]', $c), true, 'MessageLogged records'],
            'withName(), TestHandler only' => [fn (array $c) => $this->handlerOnlyClone()->error('[Probe]', $c), false, 'default-channel Monolog records'],
        ];
        foreach ($routes as $route => [$write, $listener, $arm]) {
            $counted = new class
            {
                public int $casts = 0;

                public string $label = 'before';

                public function __toString(): string
                {
                    $this->casts++;

                    return 'counted '.$this->label;
                }
            };
            $throwing = new class implements \Stringable
            {
                public function __toString(): string
                {
                    throw new \LogicException('synthetic __toString failure');
                }
            };
            $json = new class implements \JsonSerializable
            {
                public int $calls = 0;

                public string $label = 'before';

                public function jsonSerialize(): mixed
                {
                    $this->calls++;

                    return ['json' => 'json '.$this->label];
                }
            };
            $jsonError = new class('synthetic') extends \RuntimeException implements \JsonSerializable
            {
                public int $calls = 0;

                public string $label = 'before';

                public function jsonSerialize(): mixed
                {
                    $this->calls++;

                    return ['json' => 'error json '.$this->label];
                }
            };
            $dto = (object) ['client' => $client->name];
            // #5760: a plain nested array is copied into the context, so a
            // later change to the local copy could never reach the record.
            // An element held by reference is shared with the context array
            // Log:: was given, so only a snapshot taken at the write keeps it.
            $referenced = $client->name;
            $nested = ['row' => ['client' => &$referenced]];
            $error = new class('failed for '.$client->name) extends \RuntimeException
            {
                public function rename(string $m): void
                {
                    $this->message = $m;
                }
            };

            $this->logged = [];
            $this->monolog->clear();
            $context = ['counted' => $counted, 'throwing' => $throwing, 'json' => $json, 'je' => $jsonError, 'dto' => $dto, 'nested' => $nested, 'e' => $error];
            $write($context);
            $this->assertSame(0, $counted->casts, "{$route}: the write did not cast the context object");
            $this->assertSame([0, 0], [$json->calls, $jsonError->calls], "{$route}: the write did not call jsonSerialize() (#5759)");
            // Changed after the write: the record carried the name.
            $counted->label = 'after';
            $json->label = 'after';
            $jsonError->label = 'after';
            $dto->client = 'changed-after-write';
            $referenced = 'changed-after-write';
            $this->assertSame('changed-after-write', $context['nested']['row']['client'], "{$route}: positive control, the change reached the context array the write was given (#5760)");
            $error->rename('changed-after-write');

            [$holder, $other] = $listener ? [$this->listenerLines(), $this->monologLines()] : [$this->monologLines(), $this->listenerLines()];
            $this->assertSame([], $other, "{$route}: the other instrument did not see it");
            $this->assertCount(1, $holder, "{$route}: one record");
            $this->assertSame(1, $counted->casts, "{$route}: reading cast it once");
            $this->assertSame([1, 1], [$json->calls, $jsonError->calls], "{$route}: reading called each jsonSerialize() once (#5759)");
            $this->assertStringContainsString('"json":{"json":"json after"}', $holder[0], "{$route}: jsonSerialize() output at read (#5759)");
            $this->assertStringContainsString('"json":{"json":"error json after"}', $holder[0], "{$route}: a Throwable's jsonSerialize() output at read (#5759)");
            $this->assertStringContainsString('"string":"counted after","public":{"casts":0,"label":"before"}}', $holder[0], "{$route}: the string form at read, the public properties at write");
            $this->assertStringContainsString('"string":"[__toString() threw LogicException]","public":[]}', $holder[0], "{$route}: the throw named");
            $this->assertSame(3, substr_count($holder[0], $client->name), "{$route}: the name in the public property, the array and the message, each as written: ".$holder[0]);
            $this->assertStringContainsString('"message":"failed for '.$client->name.'"', $holder[0], "{$route}: the Throwable's message as written");
            $this->assertStringNotContainsString('changed-after-write', $holder[0], "{$route}: no value changed after the write is read");
            $this->assertArmFails(fn () => $this->assertNoClientNames([$client]), "{$arm}: a client name reached the logs", $route);
        }
    }

    /**
     * #5697, #5701: a marker that drops content fails the scans. A name
     * behind [depth] (nested past EXPOSE_DEPTH), behind a __toString() that
     * throws, or behind a jsonSerialize() that throws is not seen, so
     * assertNoClientNames() and assertNoVendorTextInLogs() fail on the
     * marker itself, on each instrument's own route, naming the marker.
     * A [cycle] marker drops nothing (the object is rendered above it) and
     * passes, with the name it holds still seen once, on each route
     * (#5765). A jsonSerialize() that returns its own object is not cut as
     * a cycle: it is rendered as its public properties, so the name is
     * seen and the scans fail on it (#5758).
     */
    public function test_a_marker_that_drops_content_fails_the_log_scans(): void
    {
        $client = $this->mappedClient(self::MESH_ID, 'Synthetic Client Name 8e2f');
        $deep = ['v' => $client->name];
        for ($i = 0; $i < self::EXPOSE_DEPTH; $i++) {
            $deep = [$deep];
        }
        $markers = [
            '[depth]' => ['nest' => $deep],
            '[__toString() threw LogicException]' => ['who' => new class implements \Stringable
            {
                public function __toString(): string
                {
                    throw new \LogicException('synthetic');
                }
            }],
            '[jsonSerialize() threw LogicException]' => ['row' => new class implements \JsonSerializable
            {
                public function jsonSerialize(): mixed
                {
                    throw new \LogicException('synthetic');
                }
            }],
        ];
        $routes = [
            'channel(null)' => [fn (array $c) => Log::channel('null')->error('[Probe]', $c), 'MessageLogged records', 'every MessageLogged record'],
            'withName(), TestHandler only' => [fn (array $c) => $this->handlerOnlyClone()->error('[Probe]', $c), 'default-channel Monolog records', 'every default-channel Monolog record'],
        ];
        foreach ($markers as $marker => $context) {
            foreach ($routes as $route => [$write, $names, $vendor]) {
                $this->logged = [];
                $this->monolog->clear();
                $write($context);
                $this->assertStringContainsString('"'.$marker.'"', $this->allLogs().$this->monologLogs(), "{$route} {$marker}: positive control, the marker was rendered");
                $this->assertArmFails(fn () => $this->assertNoClientNames([$client]), "{$names}: content dropped behind {$marker}", "{$route} {$marker}");
                $this->assertArmFails(fn () => $this->assertNoVendorTextInLogs(), "{$vendor}: content dropped behind {$marker}", "{$route} {$marker}");
            }
        }

        // A cycle drops nothing, on each route (#5765): the scan passes, and
        // the name is seen once.
        $node = (object) ['client' => $client->name];
        $node->self = $node;
        // #5758: a jsonSerialize() that returns its own object is written
        // by json_encode() as its public properties, so it is rendered so,
        // not cut as a cycle: the name in it is seen.
        $selfReturn = new class($client->name) implements \JsonSerializable
        {
            public function __construct(public string $client) {}

            public function jsonSerialize(): mixed
            {
                return $this;
            }
        };
        $this->assertSame('{"client":"'.$client->name.'"}', json_encode($selfReturn), 'measured: json_encode() writes a self-returning jsonSerialize() as its public properties');
        foreach (['a cyclic object' => [['n' => $node], '"self":"[cycle stdClass]"'], 'a self-returning jsonSerialize()' => [['c' => $selfReturn], '","public":{"client":"'.$client->name.'"}}}']] as $case => [$context, $fragment]) {
            foreach ($routes as $route => [$write, $names]) {
                $this->logged = [];
                $this->monolog->clear();
                $write($context);
                $holder = $route === 'channel(null)' ? $this->allLogs() : $this->monologLogs();
                $this->assertStringContainsString($fragment, $holder, "{$route} {$case}: positive control, rendered: {$holder}");
                $this->assertSame(1, substr_count($holder, $client->name), "{$route} {$case}: the name is rendered once");
                $this->assertArmFails(fn () => $this->assertNoClientNames([$client]), "{$names}: a client name reached the logs", "{$route} {$case}");
                $this->assertNoClientNames([]);
                $this->assertNoVendorTextInLogs();
            }
        }
    }

    /**
     * #5701, #5523: a JsonSerializable that is not a Throwable has its
     * jsonSerialize() output rendered by expose()'s own rules, at read
     * time: a name in a Throwable or a Stringable inside that output is
     * seen, a jsonSerialize() whose output holds its own object is a cycle
     * there, one that returns its own object is rendered as its public
     * properties (#5758), and one that throws is a marker, not an error
     * out of the scan. Each case is rendered by renderContext() and by
     * BOTH instruments, each on its own route, to the same text (#5764).
     */
    public function test_a_json_serializable_context_is_rendered_by_the_same_rules(): void
    {
        $name = 'Synthetic Client Name 2c7b';
        $holding = new class($name) implements \JsonSerializable
        {
            public function __construct(private string $n) {}

            public function jsonSerialize(): mixed
            {
                return ['errors' => [new \RuntimeException('failed for '.$this->n)], 'who' => new class($this->n) implements \Stringable
                {
                    public function __construct(private string $n) {}

                    public function __toString(): string
                    {
                        return 'client '.$this->n;
                    }
                }];
            }
        };
        foreach ($this->renderedEachWay(['c' => $holding]) as $way => $rendered) {
            $this->assertStringContainsString('"message":"failed for '.$name.'"', $rendered, "{$way}: a Throwable inside jsonSerialize() output");
            $this->assertStringContainsString('"string":"client '.$name.'"', $rendered, "{$way}: a Stringable inside jsonSerialize() output");
        }

        $self = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['me' => $this];
            }
        };
        foreach ($this->renderedEachWay(['c' => $self]) as $way => $rendered) {
            $this->assertSame(['c' => ['me' => '[cycle '.$self::class.']']], json_decode($rendered, true), "{$way}: itself inside its output is a cycle");
        }

        $selfReturn = new class($name) implements \JsonSerializable
        {
            public function __construct(public string $client) {}

            public function jsonSerialize(): mixed
            {
                return $this;
            }
        };
        foreach ($this->renderedEachWay(['c' => $selfReturn]) as $way => $rendered) {
            $this->assertSame(['c' => ['class' => $selfReturn::class, 'public' => ['client' => $name]]], json_decode($rendered, true), "{$way}: returning itself renders its public properties (#5758)");
        }

        $throws = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                throw new \LogicException('synthetic');
            }
        };
        foreach ($this->renderedEachWay(['c' => $throws]) as $way => $rendered) {
            $this->assertSame('{"c":"[jsonSerialize() threw LogicException]"}', $rendered, "{$way}: a throw is a marker");
        }
    }

    /**
     * #5763: a stored snapshot handed to renderContext() (which would expose
     * it again and render each deferred part as an empty Closure object no
     * scan flags) is refused loudly; render() renders it, deferred parts
     * resolved. Both instruments' stored snapshots are driven.
     */
    public function test_render_context_refuses_a_stored_snapshot(): void
    {
        $name = 'Synthetic Client Name 5e3d';
        $context = ['who' => new class($name) implements \Stringable
        {
            public function __construct(private string $n) {}

            public function __toString(): string
            {
                return 'client '.$this->n;
            }
        }];
        $this->logged = [];
        $this->monolog->clear();
        Log::channel('null')->error('[Probe]', $context);
        $this->handlerOnlyClone()->error('[Probe]', $context);
        $this->assertCount(1, $this->logged);
        $this->assertCount(1, $this->monolog->getRecords());
        $snapshots = ['listener' => $this->logged[0]['context'], 'TestHandler' => $this->monolog->getRecords()[0]->formatted];
        foreach ($snapshots as $instrument => $snapshot) {
            $this->assertSame(['who' => ['class' => $context['who']::class, 'string' => 'client '.$name, 'public' => []]], json_decode(self::render($snapshot), true), "{$instrument}: render() resolves the deferred string form");
            try {
                self::renderContext($snapshot);
                $this->fail("{$instrument}: renderContext() accepted a stored snapshot");
            } catch (\LogicException $e) {
                $this->assertStringStartsWith('renderContext() was given a stored snapshot', $e->getMessage(), $instrument);
            }
        }
    }

    /**
     * #5767, a documented limit pinned as measured, not endorsed: what
     * jsonSerialize() returns is read at READ time as a whole subtree, so a
     * public property of an object inside that output shows its state at
     * the read; and an Eloquent model (JsonSerializable, its attributes
     * protected) is read entirely at read time, through toArray(). On each
     * instrument's route the value the name was changed to is seen and
     * the name held at the write is not. A snapshot that read these at the
     * write would fail here, and this pin and the docblocks move with it.
     */
    public function test_what_json_serialize_returns_and_an_eloquent_model_are_read_at_read_time(): void
    {
        $client = $this->mappedClient(self::MESH_ID, 'Synthetic Client Name 9a4b');
        $routes = [
            'channel(null)' => [fn (array $c) => Log::channel('null')->error('[Probe]', $c), true],
            'withName(), TestHandler only' => [fn (array $c) => $this->handlerOnlyClone()->error('[Probe]', $c), false],
        ];
        foreach ($routes as $route => [$write, $listener]) {
            $client->name = 'Synthetic Client Name 9a4b';
            $inner = (object) ['client' => $client->name];
            $wrapper = new class($inner) implements \JsonSerializable
            {
                public function __construct(private object $inner) {}

                public function jsonSerialize(): mixed
                {
                    return ['inner' => $this->inner];
                }
            };
            $this->logged = [];
            $this->monolog->clear();
            $write(['w' => $wrapper, 'client' => $client]);
            $inner->client = 'Synthetic Renamed 9a4b';
            $client->name = 'Synthetic Renamed 9a4b';
            [$holder] = $listener ? [$this->listenerLines()] : [$this->monologLines()];
            $this->assertCount(1, $holder, "{$route}: one record");
            $this->assertStringContainsString('"inner":{"class":"stdClass","string":null,"public":{"client":"Synthetic Renamed 9a4b"}}', $holder[0], "{$route}: a public property inside jsonSerialize() output, at read");
            $this->assertStringContainsString('"name":"Synthetic Renamed 9a4b"', $holder[0], "{$route}: the model's attribute, at read");
            $this->assertStringNotContainsString('Synthetic Client Name 9a4b', $holder[0], "{$route}: the name held at the write is not seen (the limit)");
        }
    }

    /**
     * #5764: $context's rendered JSON three ways: renderContext() directly,
     * the listener's record after a channel('null') write, and the setUp()
     * TestHandler's record after a handlerOnlyClone() write. On each route
     * the holder holds exactly one record and the other instrument none.
     *
     * @param  array<mixed>  $context
     * @return array<string, string>
     */
    private function renderedEachWay(array $context): array
    {
        $out = ['renderContext()' => self::renderContext($context)];
        foreach (['channel(null)' => true, 'withName(), TestHandler only' => false] as $route => $listener) {
            $this->logged = [];
            $this->monolog->clear();
            $listener ? Log::channel('null')->error('[Probe]', $context) : $this->handlerOnlyClone()->error('[Probe]', $context);
            [$holder, $other] = $listener ? [$this->listenerLines(), $this->monologLines()] : [$this->monologLines(), $this->listenerLines()];
            $this->assertCount(1, $holder, "{$route}: the holder holds one record");
            $this->assertSame([], $other, "{$route}: the other instrument did not see it");
            $this->assertStringStartsWith('error [Probe] {', $holder[0], "{$route}: at 'error', with its context rendered");
            $out[$route] = substr($holder[0], strlen('error [Probe] '));
        }

        return $out;
    }

    /**
     * #5521, #5518: expose() cuts a cycle at the first repeat, so a cyclic
     * public graph renders at once with the name in it seen; and its depth
     * cap, EXPOSE_DEPTH, is where a value stops being rendered: a name
     * nested EXPOSE_DEPTH array levels deep is seen, one level deeper it is
     * '[depth]' and not seen; likewise in a previous chain.
     */
    public function test_expose_cuts_a_cycle_and_names_its_depth_cap(): void
    {
        $name = 'Synthetic Client Name 6a1c';
        $node = new \stdClass;
        $node->client = $name;
        $node->left = $node;
        $node->right = $node;
        $this->assertSame(
            '{"n":{"class":"stdClass","string":null,"public":{"client":"'.$name.'","left":"[cycle stdClass]","right":"[cycle stdClass]"}}}',
            self::renderContext(['n' => $node]),
            'a self-referencing object: each repeat is cut, the name is seen',
        );
        // Not a cycle: the same object twice side by side is rendered twice.
        $leaf = (object) ['client' => $name];
        $this->assertSame(2, substr_count(self::renderContext(['a' => $leaf, 'b' => $leaf]), $name), 'a shared, acyclic object is not cut');

        $nest = function (mixed $v, int $levels): array {
            for ($i = 1; $i < $levels; $i++) {
                $v = [$v];
            }

            return ['v' => $v];
        };
        $this->assertSame(16, self::EXPOSE_DEPTH);
        $this->assertStringContainsString($name, self::renderContext($nest($name, self::EXPOSE_DEPTH)), 'a name EXPOSE_DEPTH levels deep is seen');
        $deeper = self::renderContext($nest($name, self::EXPOSE_DEPTH + 1));
        $this->assertStringNotContainsString($name, $deeper, 'one level deeper it is not seen (#5518)');
        $this->assertStringContainsString('"[depth]"', $deeper, 'and renders as [depth]');

        // A previous chain: the exception at context level k is at depth k.
        $chain = function (int $length) use ($name): \Throwable {
            $e = new \RuntimeException('innermost '.$name);
            for ($i = 1; $i < $length; $i++) {
                $e = new \RuntimeException('outer '.$i, 0, $e);
            }

            return $e;
        };
        $this->assertStringContainsString('innermost '.$name, self::renderContext(['e' => $chain(self::EXPOSE_DEPTH)]), 'a chain EXPOSE_DEPTH long is rendered to its end');
        $this->assertStringNotContainsString($name, self::renderContext(['e' => $chain(self::EXPOSE_DEPTH + 1)]), 'one exception longer, the last is [depth] (#5518)');
    }

    /**
     * #5526: each instrument is read one entry per RECORD, so a message
     * holding a newline is one record on both, read exactly.
     */
    public function test_a_multi_line_message_is_one_record_on_both_instruments(): void
    {
        $client = $this->mappedClient();
        $this->assertCaughtOnRoute('facade, two-line message', fn () => Log::error("[Probe] line one\n".$client->name),
            "error [Probe] line one\n{$client->name} []", true, true, fn () => $this->assertNoClientNames([$client]),
            'MessageLogged records: a client name reached the logs');
    }

    // ---- #5282: the exception handler and the previous chain ---------------------

    /**
     * report() on a real, chained MeshClientException, through the default
     * handler into a real Monolog channel with Laravel's own LineFormatter
     * (stack traces and the [previous exception] walk on). The formatted file
     * is what is checked, so exception normalisation is included.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_reported_mesh_exception_logs_no_vendor_text_down_the_previous_chain(string $mode): void
    {
        $this->mode = $mode;
        $thrown = $this->realFailure();
        // #5978: the Guzzle exception is no longer chained; the positive
        // controls read it where the client caught it.
        $this->assertNull($thrown->getPrevious(), 'the Guzzle exception is not chained (#5978)');
        $raw = $this->lastRejection();
        $this->assertInstanceOf(\GuzzleHttp\Exception\GuzzleException::class, $raw, 'precondition: the client caught a Guzzle exception');
        $this->assertSame([self::$apiKey], $raw->getRequest()->getHeader('API-KEY'), 'positive control: the caught request carries the key');
        // #5761: the rethrow's own message is status-only; the vendor text
        // the handler must keep out is on the chained Guzzle exception.
        $this->assertSame('Mesh API error: GET api/customers/ failed with '.($mode === '503' ? 'HTTP 503 ('.ServerException::class.')' : 'no HTTP status (cURL errno 7, '.ConnectException::class.')'), $thrown->getMessage(), 'the rethrown message is status-only (#5761)');
        $this->assertStringContainsString(self::HOST, $raw->getMessage(), 'positive control: the caught message carries the host');
        $this->assertStringContainsString(self::$queryMarker, $raw->getMessage(), 'positive control: the caught message carries the query');
        $this->assertStringNotContainsString(self::HOST, $thrown->getMessage(), 'the rethrown message does not carry the host (#5761)');
        $this->assertStringNotContainsString(self::$queryMarker, $thrown->getMessage(), 'the rethrown message does not carry the query (#5761)');

        // Configured only now, so the client's own failure line is not in it.
        $path = tempnam(sys_get_temp_dir(), 'c56log');
        config(['logging.channels.c56probe' => ['driver' => 'single', 'path' => $path, 'level' => 'debug'], 'logging.default' => 'c56probe']);
        Log::forgetChannel('c56probe');

        $this->app->make(ExceptionHandler::class)->report($thrown);

        $written = (string) file_get_contents($path);
        unlink($path);
        $this->assertNotSame('', trim($written), 'positive control: the handler wrote to this channel');
        $this->assertNoVendorText($written, 'reported record');
        $this->assertStringContainsString(MeshClientException::class.' reported', $written, 'the record is the status-only report() line');
        $this->assertStatusOnly($written, 'reported record');
        $this->assertStringNotContainsString('[previous exception]', $written, 'the chain is not walked');
    }

    /**
     * The controller resolves the service with its client as a parameter,
     * and make() with parameters skips instance(), so bind a closure.
     */
    private function bindSync(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(MeshLicenseSyncService::class);
        $this->app->bind(MeshLicenseSyncService::class, fn () => $mock);

        return $mock;
    }

    /**
     * The real MeshLicenseSyncService over a scripted MeshClient (bound as a
     * closure for the same reason as bindSync()). A customer read for a Mesh
     * id in $failing fails as $mode calls for (HTTP 503, or a connect failure
     * with no status); any other answers one billed license.
     *
     * @param  list<string>  $failing
     */
    private function bindRealSync(array $failing, string $mode = '503'): void
    {
        Setting::setEncrypted('mesh_api_key', self::$apiKey);
        $this->mode = $mode;
        $mesh = $this->scriptedClient(function (RequestInterface $r) use ($failing) {
            $this->syncRequestPaths[] = $r->getUri()->getPath();
            foreach ($failing as $id) {
                if (str_contains($r->getUri()->getPath(), $id)) {
                    return $this->failure($r);
                }
            }

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'licenses_billed' => 3,
                'service_name' => 'Synthetic Service',
                'active' => true,
            ])));
        });
        $this->app->bind(MeshLicenseSyncService::class, fn () => new MeshLicenseSyncService($mesh));
    }

    /**
     * The scripted Mesh behind bindRealSync() received exactly one customer
     * read per id in $meshIds (order-free), each at its exact path.
     *
     * @param  list<string>  $meshIds
     */
    private function assertSyncRequested(array $meshIds): void
    {
        $expected = array_map(fn (string $id) => "/api/customers/{$id}/", $meshIds);
        $seen = $this->syncRequestPaths;
        sort($expected);
        sort($seen);
        $this->assertSame($expected, $seen, 'positive control: one customer read per mapped Mesh id reached the vendor');
    }

    /** POST the sync button; return the flash under $key (which must be set). */
    private function pressSync(string $key): string
    {
        $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.mesh.sync'))
            ->assertRedirect(route('settings.integrations'));
        $this->assertTrue(session()->has($key), "the sync flashed '{$key}'");

        return (string) session($key);
    }

    /** @param  list<Client>  $clients */
    private function assertFlashHasNoClientData(string $flash, array $clients): void
    {
        $this->assertNoVendorText($flash, 'sync flash');
        $this->assertStringNotContainsString(self::MESH_ID_2, $flash, 'sync flash: Mesh id leaked');
        foreach ($clients as $c) {
            $this->assertStringNotContainsStringIgnoringCase($c->name, $flash, 'sync flash: client name leaked');
        }
    }

    /** A MeshClientException thrown by the real client over the scripted Guzzle. */
    private function realFailure(): MeshClientException
    {
        try {
            $this->scriptedClient(fn (RequestInterface $r) => $this->failure($r))
                ->get('api/customers/', ['_size' => 1, 'filter' => self::$queryMarker]);
        } catch (MeshClientException $e) {
            return $e;
        }
        $this->fail('the scripted read was expected to fail');
    }

    // ---- the scripted vendor ----------------------------------------------------

    /**
     * A real MeshClient whose Guzzle is scripted: $answer decides per request.
     * The API-KEY header the client sets is the synthetic key.
     *
     * @param  \Closure(RequestInterface): mixed  $answer
     */
    private function scriptedClient(\Closure $answer): MeshClient
    {
        $client = new MeshClient(['api_key' => self::$apiKey, 'base_url' => 'https://'.self::HOST]);
        $guzzle = new GuzzleClient([
            'base_uri' => 'https://'.self::HOST.'/',
            'handler' => $this->recordRejections(HandlerStack::create(fn (RequestInterface $r) => $answer($r))),
            'http_errors' => true,
        ]);
        (new \ReflectionProperty($client, 'http'))->setValue($client, $guzzle);

        return $client;
    }

    /** The failure this test's mode calls for, as Guzzle itself words it. */
    private function failure(RequestInterface $request): mixed
    {
        if ($this->mode === 'connect') {
            return Create::rejectionFor(new ConnectException(
                'cURL error 7: Failed to connect to '.self::HOST.' '.self::$marker.' for '.$request->getUri(),
                $request,
                null,
                ['errno' => 7],
            ));
        }

        return Create::promiseFor(new Response((int) $this->mode, ['Content-Type' => 'application/json'], json_encode([
            'detail' => self::$marker,
        ])));
    }

    /** A Throwable that is not a MeshClientException, raised inside the transport. */
    private function foreignFailure(): \Closure
    {
        return function (): never {
            throw new \RuntimeException('cache backend down at '.self::HOST.' '.self::$marker);
        };
    }

    /**
     * Point Guzzle's default handler at a loopback proxy (Guzzle honours
     * HTTP_PROXY under CLI and reads $_SERVER first). '503': a php -S router
     * answering every request 503 with the body marker. 'connect': the discard
     * port phpunit.xml already uses, which refuses (errno 7).
     */
    private function loopbackMesh(): void
    {
        Setting::setEncrypted('mesh_api_key', self::$apiKey);
        Setting::setValue('mesh_base_url', 'http://'.self::HOST);

        if ($this->mode === 'connect') {
            $_SERVER['HTTP_PROXY'] = 'http://127.0.0.1:9';

            return;
        }

        if (! is_resource(self::$server)) {
            $probe = stream_socket_server('tcp://127.0.0.1:0');
            self::$port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
            fclose($probe);

            self::$router = tempnam(sys_get_temp_dir(), 'c56mesh').'.php';
            file_put_contents(self::$router, '<?php http_response_code(503); header("Content-Type: application/json"); echo json_encode(["detail" => '.var_export(self::$marker, true).']);');
            self::$server = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:'.self::$port, self::$router],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
            for ($i = 0; $i < 100; $i++) {
                if (($s = @fsockopen('127.0.0.1', self::$port, $en, $es, 0.1)) !== false) {
                    fclose($s);
                    break;
                }
                usleep(50_000);
            }
        }

        $_SERVER['HTTP_PROXY'] = 'http://127.0.0.1:'.self::$port;
    }

    private function mappedClient(string $meshId = self::MESH_ID, string $name = 'Synthetic Client Name 7c1d'): Client
    {
        return Client::factory()->create([
            'name' => $name,
            'mesh_customer_id' => $meshId,
            'is_active' => true,
        ]);
    }

    // ---- assertions -------------------------------------------------------------

    /** No vendor text, host, query, key or Mesh id; the status, or that there was none. */
    private function assertStatusOnly(string $text, string $where): void
    {
        $this->assertNoVendorText($text, $where);
        $this->assertStringNotContainsString('Mesh API', $text, "{$where}: the exception message was used");

        if (is_numeric($this->mode)) {
            $this->assertStringContainsString('with HTTP '.$this->mode, $text, "{$where}: the status is the report");
        } else {
            $this->assertStringContainsString('failed without an HTTP status from Mesh', $text, "{$where}: a status-less failure says so");
            $this->assertStringNotContainsString('HTTP 0', $text, $where);
        }
    }

    /**
     * Also holds over ALL records together, MeshClient's own failure line
     * included: that line logs a customer read's path as
     * api/customers/<customer> (#5298/#5305, #5323). Both Mesh customer ids
     * used here (MESH_ID, MESH_ID_2) are checked in two forms, raw and
     * dashless, each case-insensitively, so an upper-cased or mixed-case form
     * of either is caught by the same check (#5339, #5381).
     */
    private function assertNoVendorText(string $text, string $where): void
    {
        $this->assertStringNotContainsString(self::$marker, $text, "{$where}: vendor body leaked");
        $this->assertStringNotContainsString(self::$queryMarker, $text, "{$where}: request query leaked");
        $this->assertStringNotContainsString(self::HOST, $text, "{$where}: request host leaked");
        $this->assertStringNotContainsString(self::$apiKey, $text, "{$where}: API key leaked");
        foreach ([self::MESH_ID, self::MESH_ID_2] as $id) {
            foreach (['raw' => $id, 'dashless' => str_replace('-', '', $id)] as $form => $value) {
                $this->assertStringNotContainsStringIgnoringCase($value, $text, "{$where}: request path (Mesh id, {$form}) leaked");
            }
        }
        $this->assertStringNotContainsString('_size', $text, "{$where}: request query leaked");
    }

    /** Every record logged, at any level, joined. */
    private function allLogs(): string
    {
        return implode("\n", $this->listenerLines());
    }

    /** One listener record as 'text': its message, a space, its context snapshot rendered now. */
    private static function text(array $r): string
    {
        return $r['message'].' '.self::render($r['context']);
    }

    /**
     * Every record whose text contains $needle, as 'level text' (the text
     * being the message plus its JSON context), sorted. Reading the level
     * with the text means a record moved to another level is a different
     * string, so an exact list pins both (#5404).
     *
     * @return list<string>
     */
    private function recordsContaining(string $needle): array
    {
        $lines = array_values(array_filter($this->listenerLines(), fn (string $l) => str_contains(substr($l, strpos($l, ' ') + 1), $needle)));

        return $this->sorted($lines);
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function sorted(array $lines): array
    {
        sort($lines);

        return $lines;
    }

    /**
     * MeshClient::request()'s failure record for one failed customer read,
     * as recordsContaining() renders it: HTTP 503 or, for 'connect', the
     * status-less arm (#5424).
     */
    private function meshClientFailureRecord(string $mode = '503'): string
    {
        return $mode === 'connect'
            ? 'error [MeshClient] GET api/customers/<customer> failed with no HTTP status (cURL errno 7, '.ConnectException::class.') []'
            : 'error [MeshClient] GET api/customers/<customer> failed with HTTP 503 ('.ServerException::class.') []';
    }

    /** syncLicenses()' failure record for $client after a failed customer read in $mode, as recordsContaining() renders it (#5424). */
    private function meshSyncFailureRecord(Client $client, string $mode = '503'): string
    {
        $reason = $mode === 'connect'
            ? 'the customer read failed without an HTTP status from Mesh'
            : 'the Mesh host (or something in front of it) answered the customer read with HTTP 503';

        return 'error [MeshSync] Failed for client '.$client->id.': '.$reason.' ('.MeshClientException::class.') []';
    }

    /**
     * No client name, in any letter case, in any record either instrument
     * captured (#5423), read from TWO instruments (#5428):
     *   - the MessageLogged listener (allLogs()), which sees a write made
     *     through a channel LogManager::get() resolves, the default one or
     *     another: get() wraps each in Illuminate\Log\Logger with the event
     *     dispatcher. Driven on Log::channel('null') and the default channel
     *     at all eight levels and through log() and write() (#5467);
     *   - the TestHandler on the default channel's Monolog logger, which also
     *     sees a write made through that logger outside Laravel's wrapper.
     *     Driven at all eight levels through the facade (level method, log()
     *     and write()) and through a withName() clone and a direct
     *     getLogger() write (level method and log(); Monolog's Logger has no
     *     write()) (#5467, #5468).
     * Each arm is driven alone by
     * test_the_no_client_names_assertion_fails_on_a_recased_name_on_each_route.
     * Both render context with renderContext(), so a name with '/', a
     * non-ASCII letter or U+2028/U+2029 is matched as written (#5438), a name in a
     * Throwable's message (or its previous chain), public properties or
     * jsonSerialize() output, or a Stringable's string form in context is
     * seen, and an invalid UTF-8 byte or NAN elsewhere in
     * the context does not blank the record (#5465; each driven by
     * test_the_instruments_render_context_that_plain_json_encode_hides).
     * A name in a Stringable's public property, or in a plain object's, is
     * seen too (#5520, #5522).
     * Not covered: a write through another channel's Monolog logger outside
     * the wrapper, a logger built outside LogManager, or a withName() clone
     * of the default logger taken before setUp() pushed the TestHandler (none
     * of these reaches either instrument; the last is driven); a name
     * containing a double quote, a backslash or a control character when it
     * sits in context (JSON escapes those); a name split by an invalid UTF-8
     * byte (U+FFFD replaces the byte); a name held only in a non-public
     * property of a context object (other than a Throwable's message and
     * previous chain, or what a Stringable's string form shows); a name
     * behind a JsonSerializable that is not a Throwable, when its
     * jsonSerialize() output omits it (its output is rendered by the same
     * rules, so a Throwable or Stringable inside it is seen, #5523, #5701);
     * and a name as it was at the write, when it changed before the read,
     * in anything read at read time (#5695, #5767): an object's string form,
     * and the WHOLE subtree a jsonSerialize() returns (the public
     * properties, messages and arrays of every object inside that output
     * are read at read time too), which takes in every Eloquent model and
     * Collection in context, since each is JsonSerializable (a model's
     * attributes are protected, so nothing of it is snapshotted at the
     * write; driven by
     * test_what_json_serialize_returns_and_an_eloquent_model_are_read_at_read_time).
     * Snapshotted at the write: scalars, arrays, a non-JsonSerializable
     * object's class and public properties, a Throwable's message and
     * previous chain. Where rendering DROPS content (a
     * name deeper than EXPOSE_DEPTH levels or past that depth in a previous
     * chain, #5518; a __toString() or jsonSerialize() that throws), the
     * marker it leaves fails this assertion and assertNoVendorTextInLogs()
     * (assertNothingDroppedIn(), #5697; driven by
     * test_a_marker_that_drops_content_fails_the_log_scans), so no name
     * hides behind one silently.
     *
     * @param  list<Client>  $clients
     */
    private function assertNoClientNames(array $clients): void
    {
        $this->assertNoClientNamesIn($this->allLogs(), $clients, 'MessageLogged records');
        $this->assertNoClientNamesIn($this->monologLogs(), $clients, 'default-channel Monolog records');
    }

    /** @param  list<Client>  $clients */
    private function assertNoClientNamesIn(string $text, array $clients, string $where): void
    {
        foreach ($clients as $c) {
            $this->assertStringNotContainsStringIgnoringCase($c->name, $text, "{$where}: a client name reached the logs");
        }
        $this->assertNothingDroppedIn($text, $where);
    }

    /**
     * #5697: no marker for DROPPED content in $text: '[depth]',
     * '[__toString() threw <class>]' or '[jsonSerialize() threw <class>]',
     * each a whole rendered JSON string. A scan cannot vouch for what was
     * not rendered, so it fails, naming the marker. '[cycle <class>]' is
     * not one of them: the object it repeats is rendered above it, by the
     * same rules (for a JsonSerializable that means its jsonSerialize()
     * output, not its public properties, as json_encode() writes it). A
     * jsonSerialize() that returns its own object is not a cycle; it is
     * rendered as its public properties (jsonOf(), #5758).
     */
    private function assertNothingDroppedIn(string $text, string $where): void
    {
        if (preg_match('/"(\[(?:depth|__toString\(\) threw [^"\]]+|jsonSerialize\(\) threw [^"\]]+)\])"/', $text, $m) === 1) {
            $this->fail("{$where}: content dropped behind {$m[1]}");
        }
    }

    /**
     * assertNoVendorText() over every record from BOTH instruments (#5434), as
     * assertNoClientNames() reads them: vendor body, query, host, API key and
     * both Mesh ids (raw and dashless, any case), and no marker for dropped
     * content (#5697). Each arm is driven alone by
     * test_the_vendor_text_assertion_fails_on_each_route.
     */
    private function assertNoVendorTextInLogs(): void
    {
        foreach (['every MessageLogged record' => $this->allLogs(), 'every default-channel Monolog record' => $this->monologLogs()] as $where => $text) {
            $this->assertNoVendorText($text, $where);
            $this->assertNothingDroppedIn($text, $where);
        }
    }

    /** Every record the default channel's Monolog logger handled, as 'level message context'. */
    private function monologLogs(): string
    {
        return implode("\n", $this->monologLines());
    }

    /**
     * A record's context as both instruments render it (#5465): JSON_FLAGS
     * over the context after expose() has replaced each Throwable and each
     * other object, and resolve() has filled in what expose() deferred.
     * A Throwable becomes its class, message, previous chain, public
     * properties and, when JsonSerializable, its jsonSerialize() output
     * (json_encode() writes one as its public properties or jsonSerialize()
     * output alone, so its message was unseen); any other object that is not
     * JsonSerializable becomes its class, its string form (null unless it is
     * Stringable) and its public properties: both are rendered, Stringable
     * or not (#5522). A JsonSerializable that is not a Throwable becomes its
     * jsonSerialize() output, itself rendered by these rules, so a Throwable
     * or Stringable inside it is seen (#5523); one whose jsonSerialize()
     * returns its own object becomes its class and public properties, as
     * json_encode() writes it (#5758). Private and protected state
     * is still not rendered.
     *
     * WHEN each part is read (#5519, #5695, #5696, #5767): expose() runs
     * inside the Log:: call (each instrument stores its snapshot there; for
     * the listener that is after the channel's own handlers, see $logged)
     * and reads only what no user code produces: scalars, arrays, a class,
     * public properties (get_object_vars()), a Throwable's message and
     * previous exception (final methods). The two parts that run user
     * code, a __toString() and a jsonSerialize(), are deferred to
     * resolve(), which runs when a record is READ. So a public property of
     * a context object, a value in an array (one held by PHP reference
     * included, #5760) or a Throwable's message changed after the write is
     * scanned as it was written. Read at read time: an object's string
     * form, and EVERYTHING a jsonSerialize() returns, all the way down
     * (expose() runs on that output at read time, so the public
     * properties, messages and arrays of objects inside it are read then
     * too). Every Eloquent model and Collection is JsonSerializable, so a
     * model or Collection in context is read wholly at read time (a
     * model's attributes are protected: nothing of it is snapshotted).
     * Pinned by
     * test_what_json_serialize_returns_and_an_eloquent_model_are_read_at_read_time.
     * Given a stored snapshot (it holds \Closure placeholders), this
     * method throws rather than render each as an empty object (#5763);
     * render() is the reader for a snapshot.
     *
     * Markers: a __toString() that throws renders as '[__toString() threw
     * <class>]' (#5519) and a jsonSerialize() that throws as
     * '[jsonSerialize() threw <class>]' (#5701); anything past EXPOSE_DEPTH
     * levels, including a previous chain longer than that, as '[depth]'
     * (#5518). Each drops content, and the log scans fail on each
     * (assertNothingDroppedIn(), #5697). An object met again inside its own
     * expansion renders as '[cycle <class>]' (#5521); that drops nothing,
     * since the object is being rendered above it by these same rules. A
     * jsonSerialize() that returns its own object is not such a repeat: it
     * is rendered as its public properties (#5758).
     *
     * @param  array<mixed>  $context
     */
    private static function renderContext(array $context): string
    {
        // #5763: a stored snapshot holds \Closure placeholders; exposed
        // again, each would render as an empty Closure object that no scan
        // flags. A raw test context holds none, so refuse one loudly.
        array_walk_recursive($context, function (mixed $v): void {
            if ($v instanceof \Closure) {
                throw new \LogicException('renderContext() was given a stored snapshot (it holds a \Closure); render it with render()');
            }
        });

        return self::render(self::expose($context));
    }

    /** A snapshot expose() took, rendered now: resolve() runs the deferred user code. */
    private static function render(mixed $snapshot): string
    {
        return (string) json_encode(self::resolve($snapshot), self::JSON_FLAGS);
    }

    /**
     * The write-time half of renderContext(): runs no user code, so it is
     * safe inside the Log:: call. A part that needs user code is left as a
     * \Closure for resolve(); expose() itself never returns a context's own
     * closure, which it turns into ['class' => 'Closure', ...].
     *
     * @param  list<object>  $path  the objects being expanded above $value,
     *                              so a cycle is cut at once (#5521)
     */
    private static function expose(mixed $value, int $depth = 0, array $path = []): mixed
    {
        if ($depth > self::EXPOSE_DEPTH) {
            return '[depth]';
        }
        if (is_array($value)) {
            return array_map(fn ($v) => self::expose($v, $depth + 1, $path), $value);
        }
        if (is_object($value) && in_array($value, $path, true)) {
            return '[cycle '.$value::class.']';
        }
        if ($value instanceof \Throwable) {
            $path[] = $value;

            return [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'previous' => $value->getPrevious() === null ? null : self::expose($value->getPrevious(), $depth + 1, $path),
                'public' => self::expose(get_object_vars($value), $depth + 1, $path),
                'json' => $value instanceof \JsonSerializable ? fn () => self::jsonOf($value, $depth + 1, $path) : null,
            ];
        }
        if ($value instanceof \JsonSerializable) {
            $path[] = $value;

            return fn () => self::jsonOf($value, $depth + 1, $path);
        }
        if (is_object($value)) {
            $path[] = $value;

            return [
                'class' => $value::class,
                'string' => $value instanceof \Stringable ? fn () => self::stringOf($value) : null,
                'public' => self::expose(get_object_vars($value), $depth + 1, $path),
            ];
        }

        return $value;
    }

    /** The read-time half: each deferred part, run now, and what it returns resolved in turn. */
    private static function resolve(mixed $snapshot): mixed
    {
        if ($snapshot instanceof \Closure) {
            return self::resolve($snapshot());
        }

        return is_array($snapshot) ? array_map(fn ($v) => self::resolve($v), $snapshot) : $snapshot;
    }

    private static function stringOf(\Stringable $value): string
    {
        try {
            return (string) $value;
        } catch (\Throwable $t) {
            return '[__toString() threw '.$t::class.']';
        }
    }

    /**
     * jsonSerialize() run at read time, its output put through expose() one
     * level down with $value on the path, so an object met again INSIDE the
     * output (['me' => $this]) is a cycle. An output that IS $value itself
     * is rendered as its class and public properties, read now (#5758):
     * json_encode(), which Monolog's formatters reach for such an output,
     * writes it as its public properties, so a '[cycle]' there would hide
     * what a real log line holds.
     *
     * @param  list<object>  $path
     */
    private static function jsonOf(\JsonSerializable $value, int $depth, array $path): mixed
    {
        try {
            $out = $value->jsonSerialize();
        } catch (\Throwable $t) {
            return '[jsonSerialize() threw '.$t::class.']';
        }
        if ($out === $value) {
            return ['class' => $value::class, 'public' => self::expose(get_object_vars($value), $depth + 1, $path)];
        }

        return self::expose($out, $depth, $path);
    }

    /** The records whose text contains $needle, at any level; at least one must exist. */
    private function logsContaining(string $needle): string
    {
        $lines = array_filter($this->listenerLines(), fn (string $l) => str_contains(substr($l, strpos($l, ' ') + 1), $needle));
        $this->assertNotSame([], $lines, "positive control: a record containing '{$needle}' was logged");

        return implode("\n", $lines);
    }

    /**
     * One positive-control step (#5466, #5467): clear both instruments, run
     * $write, then read EACH instrument's records exactly, as 'level text'.
     * The instrument that must hold the record holds exactly [$expected] (so
     * its level and text are read, not a count), the one that must not see it
     * holds nothing, and $assertion then fails with a message starting
     * $caughtBy (the arm's name and the check that fired).
     */
    private function assertCaughtOnRoute(string $route, \Closure $write, string $expected, bool $listenerSees, bool $handlerSees, \Closure $assertion, string $caughtBy): void
    {
        $this->logged = [];
        $this->monolog->clear();
        $write();
        $this->assertSame($listenerSees ? [$expected] : [], $this->listenerLines(), "{$route}: the listener's records");
        $this->assertSame($handlerSees ? [$expected] : [], $this->monologLines(), "{$route}: the TestHandler's records");
        $this->assertArmFails($assertion, $caughtBy, $route);
    }

    /** $assertion fails, with a message starting $caughtBy. */
    private function assertArmFails(\Closure $assertion, string $caughtBy, string $route): void
    {
        try {
            $assertion();
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringStartsWith($caughtBy, $e->getMessage(), "{$route}: the arm and check named '{$caughtBy}' caught it");

            return;
        }
        $this->fail("positive control: {$route} passed the assertion");
    }

    /** @return list<string> the listener's records, 'level text' each */
    private function listenerLines(): array
    {
        return array_map(fn (array $r) => $r['level'].' '.self::text($r), $this->logged);
    }

    /**
     * @return list<string> the setUp() TestHandler's records, 'level text'
     *                      each: one entry per record, as listenerLines(),
     *                      so a message holding a newline is still one
     *                      record (#5526)
     */
    private function monologLines(): array
    {
        return array_map(
            fn ($r) => strtolower($r->level->getName()).' '.$r->message.' '.self::render($r->formatted),
            $this->monolog->getRecords(),
        );
    }

    /** Write $message at $level through $via: the level method, log() or write(). */
    private static function writeVia(object $logger, string $via, string $level, string $message, array $context = []): void
    {
        match ($via) {
            'method' => $logger->{$level}($message, $context),
            'log' => $logger->log($level, $message, $context),
            'write' => $logger->write($level, $message, $context),
        };
    }
}
