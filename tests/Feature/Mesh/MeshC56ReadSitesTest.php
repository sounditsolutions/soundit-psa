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
use Monolog\Handler\TestHandler;
use Psr\Http\Message\RequestInterface;
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
    use RefreshDatabase;

    private const HOST = 'mesh.example.test';

    /** The class as a record's JSON-encoded context carries it. */
    private const CLASS_IN_CONTEXT = 'App\\\\Services\\\\Mesh\\\\MeshClientException';

    private const MESH_ID = '3f2a9c1e-5b7d-4e60-9a1b-2c3d4e5f6a7b';

    private const MESH_ID_2 = '8d4b1f6a-2c9e-4a73-b5d0-6e7f8a9b0c1d';

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

    /** @var list<array{level: string, message: string}> */
    private array $logged = [];

    /**
     * A Monolog handler pushed onto the default channel's own logger (#5428):
     * it also sees a record written through that Monolog logger outside
     * Laravel's wrapper (getLogger(), withName(): a clone keeps the
     * handlers), which never dispatches MessageLogged.
     */
    private TestHandler $monolog;

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
            $this->logged[] = ['level' => $e->level, 'message' => $e->message.' '.json_encode($e->context)];
        });
        $this->monolog = new TestHandler;
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
        $this->assertNoVendorText($this->allLogs(), 'every record');
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
        $this->assertNoVendorText($this->allLogs(), 'every record');
    }

    public function test_a_foreign_failure_in_the_license_sync_logs_the_class_only(): void
    {
        $client = $this->mappedClient();

        (new MeshLicenseSyncService($this->scriptedClient($this->foreignFailure())))->syncLicenses();

        $log = $this->logsContaining('[MeshSync] Failed for client '.$client->id.':');
        $this->assertStringNotContainsString($client->name, $log);
        $this->assertNoVendorText($log, 'license sync log');
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
        $this->assertNoVendorText($this->allLogs(), 'every record');
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
        $this->assertNoVendorText($this->allLogs(), 'every record');
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
        $this->assertNoVendorText($this->allLogs(), 'every record');
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
        $this->assertNoVendorText($this->allLogs(), 'every record');
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
     * Positive controls for assertNoClientNames() (#5423, #5428): it fails on
     * a real record carrying a client name in another letter case, written
     * through the facade (seen by the listener) and through the Monolog
     * logger's withName() clone (no MessageLogged; seen by the TestHandler
     * only). Each record is real, logged at a different level, and the
     * instrument that must catch it is shown to hold it first.
     */
    public function test_the_no_client_names_assertion_fails_on_a_recased_name_on_either_route(): void
    {
        $client = $this->mappedClient();
        $this->assertNoClientNames([$client]);

        foreach ([
            'facade, upper-cased, warning' => fn () => Log::warning('[Probe] '.strtoupper($client->name)),
            'withName(), lower-cased, debug' => fn () => Log::driver()->getLogger()->withName('probe')->debug('[Probe] '.strtolower($client->name)),
        ] as $route => $write) {
            $this->logged = [];
            $this->monolog->clear();
            $write();
            $this->assertNotSame('', $this->monologLogs(), "{$route}: positive control: the TestHandler saw the record");
            try {
                $this->assertNoClientNames([$client]);
            } catch (\PHPUnit\Framework\AssertionFailedError) {
                continue;
            }
            $this->fail("positive control: a re-cased client name written via {$route} passed assertNoClientNames()");
        }
        $this->assertSame([], $this->logged, 'the withName() record dispatched no MessageLogged: only the TestHandler can see it');
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
        $this->assertInstanceOf(\GuzzleHttp\Exception\GuzzleException::class, $thrown->getPrevious(), 'precondition: the Guzzle exception is chained');
        $this->assertSame([self::$apiKey], $thrown->getPrevious()->getRequest()->getHeader('API-KEY'), 'positive control: the chained request carries the key');
        $this->assertStringContainsString(self::HOST, $thrown->getMessage(), 'positive control: the message carries the host');
        $this->assertStringContainsString(self::$queryMarker, $thrown->getMessage(), 'positive control: the message carries the query');

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
            $this->assertStringNotContainsString($c->name, $flash, 'sync flash: client name leaked');
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
            'handler' => HandlerStack::create(fn (RequestInterface $r) => $answer($r)),
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
        return implode("\n", array_map(fn (array $r) => $r['level'].' '.$r['message'], $this->logged));
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
        $lines = array_values(array_map(
            fn (array $r) => $r['level'].' '.$r['message'],
            array_filter($this->logged, fn (array $r) => str_contains($r['message'], $needle)),
        ));

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
            ? 'error [MeshClient] GET api/customers/<customer> failed with no HTTP status ('.ConnectException::class.') []'
            : 'error [MeshClient] GET api/customers/<customer> failed with HTTP 503 ('.ServerException::class.') []';
    }

    /** syncLicenses()' failure record for $client after a failed customer read in $mode, as recordsContaining() renders it (#5424). */
    private function meshSyncFailureRecord(Client $client, string $mode = '503'): string
    {
        $reason = $mode === 'connect'
            ? 'the customer read failed without an HTTP status from Mesh'
            : 'Mesh answered the customer read with HTTP 503';

        return 'error [MeshSync] Failed for client '.$client->id.': '.$reason.' ('.MeshClientException::class.') []';
    }

    /**
     * No client name, in any letter case, in any record this test captured
     * (#5423), read from TWO instruments (#5428): the MessageLogged listener
     * (allLogs()) and the TestHandler on the default channel's Monolog
     * logger, which also sees a record written through that logger outside
     * Laravel's wrapper. Not covered: a record written to another channel or
     * a logger built elsewhere (neither instrument is attached there).
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
    }

    /** Every record the default channel's Monolog logger handled, as 'level message context'. */
    private function monologLogs(): string
    {
        return implode("\n", array_map(
            fn ($r) => strtolower($r->level->getName()).' '.$r->message.' '.json_encode($r->context),
            $this->monolog->getRecords(),
        ));
    }

    /** The records whose text contains $needle, at any level; at least one must exist. */
    private function logsContaining(string $needle): string
    {
        $lines = array_filter($this->logged, fn (array $r) => str_contains($r['message'], $needle));
        $this->assertNotSame([], $lines, "positive control: a record containing '{$needle}' was logged");

        return implode("\n", array_map(fn (array $r) => $r['level'].' '.$r['message'], $lines));
    }
}
