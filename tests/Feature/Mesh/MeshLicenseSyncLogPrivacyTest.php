<?php

namespace Tests\Feature\Mesh;

use App\Models\Client;
use App\Models\License;
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshLicenseSyncService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #5298 / #5305 (card 6ac52e6f): on the paths driven here, every log record a
 * Mesh license sync writes names the client by its PSA id only. No record
 * captured on those paths, at any level and from any class they reach,
 * carries the client's name, its Mesh customer id (raw, dashless or
 * upper-cased), or the vendor's body.
 *
 * The paths driven (#5326): ONE mapped client per arm, with no license
 * beforehand; the customer read failing, degraded, billing 0, or creating
 * one license (the normal arm); and deactivateOrphaned() with nothing to
 * deactivate. NOT driven, so not covered by the first half below: a sync that
 * updates an existing license (quantity or status change), several mapped
 * clients in one run, an orphaned license actually being deactivated, and
 * the onProgress callback. A record written on one of those paths, by the
 * service or by a model observer or hook, would not fail this test.
 *
 * Two halves:
 *
 *  1. test_every_arm_logs_the_client_by_id_only drives syncLicenses() through
 *     each arm that logs, through a real MeshClient over a scripted Guzzle,
 *     and captures every record with a MessageLogged listener. Each arm pins
 *     its COMPLETE record list: every record's level, its whole message by
 *     exact equality (the [MeshClient] line through its exception class) and
 *     its context (empty), so a new record on a covered arm, a moved level,
 *     a dropped line, a changed tail or a new context key fails here too.
 *     The request is accounted for: the scripted handler swapped into
 *     MeshClient by reflection must receive exactly the one customer read,
 *     so a MeshClient that stopped using that handler fails the arm.
 *
 *  2. test_every_log_call_in_the_service_is_pinned_to_a_covering_arm scans
 *     MeshLicenseSyncService.php with PHP's tokenizer and pins the exact set
 *     of `Log::` calls to the arms that cover them. A new or edited log call
 *     fails it until it is listed here with an arm that drives it. It also
 *     refuses the routes a listener cannot see or that the pin would miss:
 *     a Log method that returns a logger (channel(), stack(), getLogger()
 *     and the like) and the logging helpers (logger(), info(), report(), ...).
 *
 * Synthetic data only (G-13): a made-up name, Mesh uuid, host and marker.
 */
class MeshLicenseSyncLogPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'mesh-logids.example.test';

    private const MESH_ID = '3f9a6c2e-81b4-4d7a-9e05-c4b2a1d8e7f3';

    private const NAME = 'Synthetic Logprivacy Client 6e3a';

    private const SERVICE = 'Synthetic Service';

    private const SERVICE_PATH = 'app/Services/Mesh/MeshLicenseSyncService.php';

    /** Body marker; a fresh suffix per process. */
    private static string $marker = '';

    /** @var list<array{level: string, message: string, context: array, text: string}> */
    private array $logged = [];

    /** @var list<string> the request paths the scripted Mesh received */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Guards Laravel Http-facade calls on the driven paths (the service,
        // model observers or hooks, deactivateOrphaned()): any such request
        // throws instead of leaving the box (G-5, #5332), which
        // test_a_stray_http_facade_request_throws drives (#5386). It does NOT see
        // MeshClient's raw Guzzle (#5327); that request is isolated by the
        // scripted handler, and each arm asserts it received the one request
        // ($this->requested).
        Http::preventStrayRequests();
        if (self::$marker === '') {
            self::$marker = 'LOGPRIV-BODY-'.bin2hex(random_bytes(6));
        }
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = [
                'level' => $e->level,
                'message' => $e->message,
                'context' => $e->context,
                'text' => $e->message.' '.json_encode($e->context),
            ];
        });
    }

    // ---- the arms ---------------------------------------------------------------

    /**
     * Each arm: how the scripted Mesh answers the customer read, the sync
     * counts it must produce, and the COMPLETE list of records it must log,
     * as [level, whole message], each with an empty context. The test
     * replaces '{psa_id}' with the mapped client's PSA id (its primary key)
     * on every row before comparing. '<customer>' is NOT substituted: it is
     * the literal token MeshClient::logPath() writes in place of the Mesh
     * customer id and everything after it, and it appears in the log as is.
     *
     * @return array<string, array{0: string, 1: array{errors: int, created: int}, 2: list<array{0: string, 1: string}>}>
     */
    public static function arms(): array
    {
        $catch = '[MeshSync] Failed for client {psa_id}: ';

        return [
            'catch: MeshClientException, HTTP error' => ['http503', ['errors' => 1, 'created' => 0], [
                ['error', '[MeshClient] GET api/customers/<customer> failed with HTTP 503 ('.ServerException::class.')'],
                ['error', $catch.'Mesh answered the customer read with HTTP 503 ('.MeshClientException::class.')'],
            ]],
            'catch: MeshClientException, connect error' => ['connect', ['errors' => 1, 'created' => 0], [
                ['error', '[MeshClient] GET api/customers/<customer> failed with no HTTP status (cURL errno 7, '.ConnectException::class.')'],
                ['error', $catch.'the customer read failed without an HTTP status from Mesh ('.MeshClientException::class.')'],
            ]],
            'catch: foreign Throwable' => ['foreign', ['errors' => 1, 'created' => 0], [
                ['error', $catch.'an unexpected error (RuntimeException)'],
            ]],
            'catch: TypeError from a JSON scalar' => ['scalar', ['errors' => 1, 'created' => 0], [
                ['error', $catch.'an unexpected error (TypeError)'],
            ]],
            'degraded: empty read' => ['empty', ['errors' => 1, 'created' => 0], [
                ['error', $catch.'the customer read returned no usable data'],
            ]],
            'degraded: unreadable licenses_billed' => ['unreadable', ['errors' => 1, 'created' => 0], [
                ['error', $catch.'the customer read had no readable licenses_billed field'],
            ]],
            'skip: present 0' => ['zero', ['errors' => 0, 'created' => 0], [
                ['info', '[MeshSync] Client {psa_id}: 0 licenses billed, skipping'],
            ]],
            'normal sync' => ['normal', ['errors' => 0, 'created' => 1], []],
        ];
    }

    /**
     * @param  array{errors: int, created: int}  $counts
     * @param  list<array{0: string, 1: string}>  $expected
     */
    #[DataProvider('arms')]
    public function test_every_arm_logs_the_client_by_id_only(string $answer, array $counts, array $expected): void
    {
        $client = $this->mappedClient();

        $result = (new MeshLicenseSyncService($this->scriptedClient($answer)))->syncLicenses();

        $this->assertSame($counts, ['errors' => $result->errors, 'created' => $result->created], 'the arm was reached');
        $this->assertSame($counts['created'], License::count(), 'license writes match the arm');
        // Positive control: the request itself still names the Mesh customer;
        // only the log line redacts it.
        $this->assertSame(['/api/customers/'.self::MESH_ID.'/'], $this->requested, 'the customer read went to the real path');

        // No record names the client, its Mesh id in any form, or the body.
        $this->assertNoClientData($this->dump());

        // The complete record list: level, whole message and context, in order.
        $this->assertCount(count($expected), $this->logged, 'every record this arm logs: '.$this->dump());
        foreach ($expected as $i => [$level, $message]) {
            $message = str_replace('{psa_id}', (string) $client->getKey(), $message);
            $this->assertSame($level, $this->logged[$i]['level'], "record {$i}: level");
            $this->assertSame($message, $this->logged[$i]['message'], "record {$i}: message");
            $this->assertSame([], $this->logged[$i]['context'], "record {$i}: context");
        }

        // The [MeshSync] line names the client by its PSA id.
        $sync = array_values(array_filter($this->logged, fn (array $r) => str_starts_with($r['text'], '[MeshSync]')));
        foreach ($sync as $r) {
            $this->assertMatchesRegularExpression('/^\[MeshSync\] (Failed for client|Client) '.$client->getKey().':/', $r['text'], 'named by id');
        }
        $this->assertSame($expected === [] ? 0 : 1, count($sync), 'one [MeshSync] line per logging arm');
    }

    /**
     * Positive control for setUp()'s Http::preventStrayRequests() (#5386):
     * an Http-facade request in this test class throws Laravel's
     * StrayRequestException before anything is sent.
     */
    public function test_a_stray_http_facade_request_throws(): void
    {
        try {
            Http::get('https://stray.example.test/');
            $this->fail('the stray Http-facade request was not refused');
        } catch (StrayRequestException $e) {
            $this->assertStringContainsString('stray.example.test', $e->getMessage(), 'the refusal names the stray request');
        }
    }

    /** The instrument can see a leak: the same assertion fails on a line that carries one. */
    public function test_the_no_client_data_assertion_fails_on_each_leak_form(): void
    {
        foreach ($this->leakForms() as $what => $form) {
            try {
                $this->assertNoClientData("[MeshSync] Failed for client 1 ({$form})");
            } catch (\PHPUnit\Framework\AssertionFailedError) {
                continue;
            }
            $this->fail("positive control: a line carrying the {$what} passed the assertion");
        }
        $this->assertCount(6, $this->leakForms());
    }

    // ---- the structural guard ---------------------------------------------------

    /**
     * Every `Log::` call in the service, as [method, first-argument source with
     * whitespace removed] => the arms() keys that drive it. A call added,
     * removed or edited changes this set and fails the test until the pin and
     * a covering arm are updated together.
     *
     * @return array<string, list<string>>
     */
    private static function pinnedLogCalls(): array
    {
        return [
            'error|"[MeshSync]Failedforclient{$client->getKey()}:{$reason}(".$e::class.\')\'' => [
                'catch: MeshClientException, HTTP error',
                'catch: MeshClientException, connect error',
                'catch: foreign Throwable',
                'catch: TypeError from a JSON scalar',
            ],
            'info|"[MeshSync]Client{$client->getKey()}:0licensesbilled,skipping"' => [
                'skip: present 0',
            ],
            'error|"[MeshSync]Failedforclient{$client->getKey()}:{$reason}"' => [
                'degraded: empty read',
                'degraded: unreadable licenses_billed',
            ],
        ];
    }

    public function test_every_log_call_in_the_service_is_pinned_to_a_covering_arm(): void
    {
        $scan = self::scanLogCalls((string) file_get_contents(base_path(self::SERVICE_PATH)));

        $this->assertSame([], $scan['refused'], 'a logging route the pin cannot cover: '.implode('; ', $scan['refused']));
        $this->assertCount(3, $scan['calls'], 'positive control: the scan finds the three Log:: calls the service has today');
        $pinned = array_keys(self::pinnedLogCalls());
        $this->assertSame(
            array_values(array_diff($scan['calls'], $pinned)),
            [],
            'a Log:: call with no covering arm: pin it in pinnedLogCalls() and drive it in arms()',
        );
        $this->assertSame(
            array_values(array_diff($pinned, $scan['calls'])),
            [],
            'a pinned Log:: call no longer exists in the service',
        );
        $this->assertSame(count($scan['calls']), count(array_unique($scan['calls'])), 'each call is pinned once');

        $arms = array_keys(self::arms());
        foreach (self::pinnedLogCalls() as $call => $covering) {
            $this->assertNotSame([], $covering, "{$call}: no covering arm");
            foreach ($covering as $arm) {
                $this->assertContains($arm, $arms, "{$call}: covering arm '{$arm}' is not in arms()");
            }
        }
    }

    /** The scan refuses each route around the pin, and finds an added call. */
    public function test_the_scan_sees_an_added_call_and_refuses_routes_around_it(): void
    {
        $base = "<?php\nuse Illuminate\\Support\\Facades\\Log;\nclass X { function f() { %s } }\n";

        $added = self::scanLogCalls(sprintf($base, 'Log::warning("[MeshSync] new line");'));
        $this->assertSame(['warning|"[MeshSync]newline"'], $added['calls']);
        $this->assertSame([], $added['refused']);

        $fq = self::scanLogCalls(sprintf($base, '\\Illuminate\\Support\\Facades\\Log::debug("x");'));
        $this->assertSame(['debug|"x"'], $fq['calls'], 'a fully-qualified facade call is a Log:: call');

        foreach ([
            'Log::channel("stack")->warning("x");',
            'Log::stack(["a"])->info("x");',
            'Log::getLogger()->info("x");',
            'Log::withContext([])->info("x");',
            'logger("x");',
            'logger()->info("x");',
            'info("x");',
            'report($e);',
            'error_log("x");',
            'app("log")->info("x");',
            '$this->logger->info("x");',
        ] as $route) {
            $this->assertNotSame([], self::scanLogCalls(sprintf($base, $route))['refused'], "not refused: {$route}");
        }
        $this->assertNotSame([], self::scanLogCalls("<?php\nuse Illuminate\\Support\\Facades\\Log as L;\n")['refused'], 'an aliased facade is refused');
    }

    /**
     * Tokenize PHP source. calls: every `Log::method(` as "method|first
     * argument, whitespace removed". refused: every logging route the pin
     * cannot see — a Log method that returns a logger, a logging helper, a
     * `log` container lookup, a logger property, an aliased facade import.
     *
     * @return array{calls: list<string>, refused: list<string>}
     */
    private static function scanLogCalls(string $source): array
    {
        $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log', 'write'];
        $helpers = ['logger', 'info', 'report', 'error_log', 'syslog'];
        $toks = array_values(array_filter(
            \PhpToken::tokenize($source),
            fn (\PhpToken $t) => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $calls = [];
        $refused = [];
        $n = count($toks);

        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            $text = ltrim($t->text, '\\');
            $prev = $toks[$i - 1]->text ?? '';
            $next = $toks[$i + 1]->text ?? '';

            if ($t->is(T_USE) && str_contains(self::until($toks, $i, ';'), 'Facades\\Log') && str_contains(self::until($toks, $i, ';'), ' as ')) {
                $refused[] = 'aliased Log facade import';
            }
            if (($text === 'Log' || str_ends_with($text, 'Facades\\Log')) && $next === '::' && $prev !== 'use') {
                $method = $toks[$i + 2]->text ?? '';
                if (! in_array($method, $levels, true)) {
                    $refused[] = "Log::{$method}";

                    continue;
                }
                $calls[] = $method.'|'.self::firstArgument($toks, $i + 3);
            }
            if ($t->is(T_STRING) && in_array(strtolower($text), $helpers, true) && $next === '(' && ! in_array($prev, ['->', '::', '?->', 'function'], true)) {
                $refused[] = "helper {$text}()";
            }
            if ($t->is(T_CONSTANT_ENCAPSED_STRING) && trim($t->text, '\'"') === 'log' && in_array($prev, ['(', '['], true)) {
                $refused[] = 'container lookup of log';
            }
            if ($t->is(T_STRING) && in_array(strtolower($text), ['logger', 'loggerinterface', 'logmanager'], true) && in_array($prev, ['->', '?->', 'new', ':', '(', ','], true)) {
                $refused[] = "logger object {$text}";
            }
        }

        return ['calls' => $calls, 'refused' => array_values(array_unique($refused))];
    }

    /** The source of the first argument of the call whose '(' is at $open, whitespace removed. */
    private static function firstArgument(array $toks, int $open): string
    {
        $depth = 0;
        $out = '';
        for ($i = $open; $i < count($toks); $i++) {
            $text = $toks[$i]->text;
            if (in_array($text, ['(', '[', '{'], true) || $toks[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            }
            if (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            }
            if ($depth === 0 || ($depth === 1 && $text === ',')) {
                break;
            }
            if ($i > $open) {
                $out .= $text;
            }
        }

        return preg_replace('/\s+/', '', $out);
    }

    private static function until(array $toks, int $from, string $stop): string
    {
        $out = '';
        for ($i = $from; $i < count($toks) && $toks[$i]->text !== $stop; $i++) {
            $out .= ' '.$toks[$i]->text;
        }

        return $out.' ';
    }

    // ---- helpers ----------------------------------------------------------------

    /** @return array<string, string> each form the Mesh id or the name could take in a log line */
    private function leakForms(): array
    {
        return [
            'client name' => self::NAME,
            'Mesh id' => self::MESH_ID,
            'Mesh id, dashless' => str_replace('-', '', self::MESH_ID),
            'Mesh id, upper-cased' => strtoupper(self::MESH_ID),
            'Mesh id, dashless upper-cased' => strtoupper(str_replace('-', '', self::MESH_ID)),
            'vendor body marker' => self::$marker,
        ];
    }

    private function assertNoClientData(string $text): void
    {
        foreach ($this->leakForms() as $what => $form) {
            $this->assertStringNotContainsStringIgnoringCase($form, $text, "{$what} reached the log");
        }
    }

    private function dump(): string
    {
        return implode("\n", array_map(fn (array $r) => $r['level'].' '.$r['text'], $this->logged));
    }

    private function mappedClient(): Client
    {
        return Client::factory()->create([
            'name' => self::NAME,
            'mesh_customer_id' => self::MESH_ID,
            'is_active' => true,
        ]);
    }

    /**
     * A real MeshClient whose Guzzle is scripted, answering every request
     * per $answer. Every failure text and body carries the marker, the Mesh
     * id and the name, so a log line that quoted any of them would show it.
     */
    private function scriptedClient(string $answer): MeshClient
    {
        $leaky = self::$marker.' '.self::MESH_ID.' '.self::NAME;
        $json = fn (mixed $body, int $status = 200) => Create::promiseFor(
            new Response($status, ['Content-Type' => 'application/json'], is_string($body) ? $body : (string) json_encode($body))
        );
        $handler = match ($answer) {
            'http503' => fn () => $json(['detail' => $leaky], 503),
            'connect' => fn (RequestInterface $r) => Create::rejectionFor(new ConnectException(
                'cURL error 7: Failed to connect to '.self::HOST.' '.$leaky.' for '.$r->getUri(), $r, null, ['errno' => 7],
            )),
            'foreign' => function () use ($leaky): never {
                throw new \RuntimeException('cache backend down: '.$leaky);
            },
            'scalar' => fn () => $json((string) json_encode($leaky)),
            'empty' => fn () => $json(''),
            'unreadable' => fn () => $json(['detail' => $leaky, 'company_name' => self::NAME]),
            'zero' => fn () => $json(['licenses_billed' => 0, 'service_name' => self::SERVICE, 'company_name' => self::NAME]),
            'normal' => fn () => $json(['licenses_billed' => 3, 'service_name' => self::SERVICE, 'company_name' => self::NAME, 'active' => true]),
        };

        $client = new MeshClient(['api_key' => 'synthetic-key', 'base_url' => 'https://'.self::HOST]);
        $guzzle = new GuzzleClient([
            'base_uri' => 'https://'.self::HOST.'/',
            'handler' => HandlerStack::create(function (RequestInterface $r) use ($handler) {
                $this->requested[] = $r->getUri()->getPath();

                return $handler($r);
            }),
            'http_errors' => true,
        ]);
        (new \ReflectionProperty($client, 'http'))->setValue($client, $guzzle);

        return $client;
    }
}
