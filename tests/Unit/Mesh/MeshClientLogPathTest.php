<?php

namespace Tests\Unit\Mesh;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #5323 / #5329 (card 6ac53e69): MeshClient's failure line redacts the whole
 * path after a customers/ segment, wherever that segment sits, with the
 * literal token <customer>, which is not PSR-3 {placeholder} syntax.
 *
 * Each shape is driven through the public get() -> request() over a real
 * MeshClient whose Guzzle is a MockHandler answering 503. The handler queue
 * holds exactly one response and must be empty afterwards, so the request
 * went through the swapped-in handler and nowhere else. For EVERY shape the
 * handler also records the URI it received, and the row pins that URI's
 * scheme, user-info, host, port, exact path, query and fragment, each to a
 * row value (user-info and port default to none). Most absolute rows name
 * another host (and one another scheme) than base_uri, so a request that
 * ignored the endpoint's host or scheme fails (#5409); one names base_uri's
 * own host, so the 'host' leak form is driven (#5420); and two carry
 * user-info and a non-default port, which the request keeps and the log line
 * drops with the rest of the authority (#5419, #5425). The path keeps the id
 * in the form it was built with (upper-cased or dashless included, a newline
 * percent-encoded), so the log line is redacted and the request is not
 * (#5334). The endpoint's own query is NOT carried: get() always passes
 * Guzzle a 'query' option, which replaces it (empty here), and the query rows
 * pin that empty query. A fragment written into the endpoint is still on the
 * PSR-7 request URI the handler receives, and the log line drops it; this
 * test does not see what Guzzle's real transports send (#5405; #5378, #5379,
 * #5380, #5385, #5387). The
 * rethrown exception is checked to be a MeshClientException with Guzzle's
 * code (503) whose message is 'Mesh API error: ' plus Guzzle's own message,
 * wrapping that ServerException; this test does not make its content safe
 * (see MeshClient::request()). The ONE record logged is pinned by exact
 * equality.
 *
 * Synthetic data only (G-13): a made-up Mesh uuid, host and query marker.
 */
class MeshClientLogPathTest extends TestCase
{
    private const HOST = 'mesh-logpath.example.test';

    /** The host an absolute endpoint names; not base_uri's (#5409). */
    private const ENDPOINT_HOST = 'mesh-endpoint.example.test';

    private const MESH_ID = '7b2e9d41-5c3a-4f86-a0d2-e91c4b7f3a65';

    private const QUERY_MARKER = 'LOGPATH-QUERY-5c19e2';

    /** A fragment marker on an unredacted path (#5479). */
    private const FRAGMENT_MARKER = 'LOGPATH-FRAG-5c19e2';

    /** Synthetic user-info an absolute endpoint may carry (G-13: not a credential). */
    private const USER = 'synthuser-5c19';

    private const PASS = 'SYNTHPASS-5c19e2';

    /** A non-default port an absolute endpoint may carry. */
    private const PORT = 8443;

    /** @var list<array{level: string, message: string, context: array}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });
    }

    /**
     * Endpoint as passed to get() => the path the log line must show => the
     * exact path of the URI the handler received => its exact query (default
     * '') => its exact fragment (default '') => its exact host (default
     * base_uri's) => its exact scheme (default 'https') => its exact
     * user-info (default '') => its exact port (default null: none, or the
     * scheme's default).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3?: string, 4?: string, 5?: string, 6?: string, 7?: string, 8?: int|null}>
     */
    public static function shapes(): array
    {
        $id = self::MESH_ID;
        $up = strtoupper($id);
        $bare = str_replace('-', '', $id);
        // An id with a newline in it (mesh_customer_id is stored raw): '.+'
        // reaches past the newline only under the s flag (#5337).
        $nl = substr($id, 0, 8)."\n".substr($id, 9);
        $q = '?filter='.self::QUERY_MARKER.'&_size=1';
        $creds = self::USER.':'.self::PASS;
        $port = self::PORT;

        return [
            'customer read (getCustomer shape)' => ["api/customers/{$id}/", 'api/customers/<customer>', "/api/customers/{$id}/"],
            'leading slash' => ["/api/customers/{$id}/", '/api/customers/<customer>', "/api/customers/{$id}/"],
            // Absolute rows name ENDPOINT_HOST, not base_uri's host, and the
            // first an http scheme: the request goes to the endpoint's own
            // scheme and host (a scheme-relative one keeps base_uri's scheme).
            'absolute URL' => ['http://'.self::ENDPOINT_HOST."/api/customers/{$id}/", '/api/customers/<customer>', "/api/customers/{$id}/", '', '', self::ENDPOINT_HOST, 'http'],
            'scheme-relative URL' => ['//'.self::ENDPOINT_HOST."/api/customers/{$id}/", '/api/customers/<customer>', "/api/customers/{$id}/", '', '', self::ENDPOINT_HOST],
            // #5425: user-info and a non-default port. The request keeps both
            // (pinned in columns 7 and 8); the log line drops the whole
            // authority. The scheme-relative row takes base_uri's scheme and
            // is a customer list, so logPath() changes it ONLY by stripping
            // the authority (#5422).
            'absolute URL, user-info and port' => ["https://{$creds}@".self::ENDPOINT_HOST.":{$port}/api/customers/{$id}/", '/api/customers/<customer>', "/api/customers/{$id}/", '', '', self::ENDPOINT_HOST, 'https', $creds, $port],
            'scheme-relative customer list, user-info and port' => ['//'.self::USER.'@'.self::ENDPOINT_HOST.":{$port}/api/customers/", '/api/customers/', '/api/customers/', '', '', self::ENDPOINT_HOST, 'https', self::USER, $port],
            // #5420 / #5422: an absolute customer list on base_uri's OWN host,
            // changed only by the authority strip; it is the row that drives
            // the 'host' leak form in the changed-shape loop.
            "absolute customer list on base_uri's host" => ['https://'.self::HOST.'/api/customers/', '/api/customers/', '/api/customers/'],
            'versioned prefix' => ["api/v2/customers/{$id}/", 'api/v2/customers/<customer>', "/api/v2/customers/{$id}/"],
            'nested prefix' => ["api/partners/p-1/customers/{$id}/", 'api/partners/p-1/customers/<customer>', "/api/partners/p-1/customers/{$id}/"],
            'id containing a slash' => ["api/customers/abc/{$id}/", 'api/customers/<customer>', "/api/customers/abc/{$id}/"],
            'sub-resource' => ["api/customers/{$id}/licenses/", 'api/customers/<customer>', "/api/customers/{$id}/licenses/"],
            'id upper-cased' => ["api/customers/{$up}/", 'api/customers/<customer>', "/api/customers/{$up}/"],
            'id dashless' => ["api/customers/{$bare}/", 'api/customers/<customer>', "/api/customers/{$bare}/"],
            // #5482: holds the upper-cased dashless form, case-sensitively.
            'id dashless, upper-cased' => ['api/customers/'.strtoupper($bare).'/', 'api/customers/<customer>', '/api/customers/'.strtoupper($bare).'/'],
            // #5506: spellings no whole leak form but the head holds; the
            // request path keeps each as built (Guzzle leaves '%2D' and the
            // case alone).
            'id mixed-case' => ['api/customers/'.substr($id, 0, 19).strtoupper(substr($id, 19)).'/', 'api/customers/<customer>', '/api/customers/'.substr($id, 0, 19).strtoupper(substr($id, 19)).'/'],
            'id percent-encoded dashes' => ['api/customers/'.str_replace('-', '%2D', $id).'/', 'api/customers/<customer>', '/api/customers/'.str_replace('-', '%2D', $id).'/'],
            'id, no trailing slash' => ["api/customers/{$id}", 'api/customers/<customer>', "/api/customers/{$id}"],
            // Query rows. get() always passes Guzzle a 'query' option, and
            // that option REPLACES a query written into the endpoint: with
            // get()'s default [] the request carries the endpoint's path and
            // an empty query (measured; pinned by the '' query below).
            'id with a query' => ["api/customers/{$id}/{$q}", 'api/customers/<customer>', "/api/customers/{$id}/", ''],
            'customer list with a query' => ["api/customers/{$q}", 'api/customers/', '/api/customers/', ''],
            // A bare query resolves against base_uri: path '/', query
            // replaced by the empty option (measured).
            'leading ?' => [$q, '', '/', ''],
            // #5474: an absolute endpoint carrying a query. logPath() applies
            // the query cut AND the authority strip, and nothing else; the
            // request goes to the endpoint's host with the endpoint's query
            // replaced by get()'s empty query option (measured).
            'absolute customer list with a query' => ['https://'.self::ENDPOINT_HOST."/api/customers/{$q}", '/api/customers/', '/api/customers/', '', '', self::ENDPOINT_HOST],
            // Fragment row. The query option does not touch a fragment: the
            // PSR-7 request URI the handler receives KEEPS '#frag' (Guzzle's
            // resolver carries the relative fragment), and the log line drops
            // it. This test does not observe a transport, so what goes on the
            // wire is not measured here and no claim about it is made
            // (#5405, #5426, #5476).
            'id with a fragment' => ["api/customers/{$id}/#frag", 'api/customers/<customer>', "/api/customers/{$id}/", '', 'frag'],
            // #5479: a fragment on a path with no customers/ segment, so only
            // the '#' half of the cut keeps it out of the log line (on the
            // row above, the redaction swallows '#frag' whether or not the
            // cut did). The fragment marker is a leak form.
            'unredacted path with a fragment' => ['api/devices/#'.self::FRAGMENT_MARKER, 'api/devices/', '/api/devices/', '', self::FRAGMENT_MARKER],
            // #5337: the regex flags and the segment boundary. The request
            // path carries the newline percent-encoded.
            'id with a newline (s flag)' => ["api/customers/{$nl}/", 'api/customers/<customer>', '/api/customers/'.str_replace("\n", '%0A', $nl).'/'],
            // #5480: an absolute endpoint whose path PSR-7 percent-encodes.
            // logPath() strips the authority and keeps the bytes as given;
            // the request path carries the space as %20.
            'absolute URL, space in the path' => ['https://'.self::ENDPOINT_HOST.'/api/x y/', '/api/x y/', '/api/x%20y/', '', '', self::ENDPOINT_HOST],
            // #5502: one slash after an http(s) scheme. The request URI the
            // handler receives keeps the scheme and has no host (measured);
            // logPath()'s strip needs '//', so the scheme stays on the log
            // line and only the redaction runs (measured, not endorsed).
            'single slash after the scheme' => ["https:/api/customers/{$id}/", 'https:/api/customers/<customer>', "/api/customers/{$id}/", '', '', ''],
            'upper-case scheme (i flag, host strip)' => ['HTTPS://'.self::ENDPOINT_HOST."/api/customers/{$id}/", '/api/customers/<customer>', "/api/customers/{$id}/", '', '', self::ENDPOINT_HOST],
            'upper-case Customers/ (i flag, redaction)' => ["api/Customers/{$id}/", 'api/Customers/<customer>', "/api/Customers/{$id}/"],
            'bare customers/ at the start (^ branch)' => ["customers/{$id}/", 'customers/<customer>', "/customers/{$id}/"],
            // Not a customers/ segment, so nothing is redacted. The tail is
            // deliberately not an id, so the leak checks still hold.
            'xcustomers/ is not a segment (boundary)' => ['api/xcustomers/p-7/', 'api/xcustomers/p-7/', '/api/xcustomers/p-7/'],
            'customer list' => ['api/customers/', 'api/customers/', '/api/customers/'],
        ];
    }

    #[DataProvider('shapes')]
    public function test_the_failure_line_redacts_the_customer_tail(
        string $endpoint,
        string $expectedPath,
        string $requestPath,
        string $requestQuery = '',
        string $requestFragment = '',
        string $requestHost = self::HOST,
        string $requestScheme = 'https',
        string $requestUserInfo = '',
        ?int $requestPort = null,
    ): void {
        [$client, $mock, $seen] = $this->clientAnswering503();

        try {
            $client->get($endpoint);
            $this->fail('the 503 must throw');
        } catch (MeshClientException $e) {
            // What the rethrow is (not that its content is safe: it is not).
            $this->assertSame(MeshClientException::class, $e::class);
            $previous = $e->getPrevious();
            $this->assertInstanceOf(ServerException::class, $previous);
            $this->assertSame(ServerException::class, $previous::class);
            $this->assertSame(503, $e->getCode(), "the rethrow keeps Guzzle's code");
            $this->assertSame('Mesh API error: '.$previous->getMessage(), $e->getMessage());
        }

        $this->assertSame(0, $mock->count(), 'the request went through the swapped-in MockHandler');
        $this->assertCount(1, $seen->uris, 'exactly one request reached the handler');
        // Positive control, every shape: the REQUEST URI is not redacted.
        // Each URI component (scheme, user-info, host, port, path, query,
        // fragment) is pinned to a row value (user-info and port default to
        // none; two rows carry both, #5419, #5425), so a request sent
        // anywhere else, or carrying the endpoint's own query, fails here
        // (#5409).
        $uri = new Uri($seen->uris[0]);
        $this->assertSame($requestScheme, $uri->getScheme(), 'positive control: request scheme');
        $this->assertSame($requestUserInfo, $uri->getUserInfo(), 'positive control: request user-info');
        $this->assertSame($requestHost, $uri->getHost(), 'positive control: request host');
        $this->assertSame($requestPort, $uri->getPort(), 'positive control: request port (null: none, or the scheme default)');
        $this->assertSame($requestPath, $uri->getPath(), 'positive control: the request path keeps the id as built');
        $this->assertSame($requestQuery, $uri->getQuery(), "positive control: the request query is get()'s query option, not the endpoint's");
        $this->assertSame($requestFragment, $uri->getFragment(), 'positive control: the request fragment');

        $this->assertCount(1, $this->logged, 'records: '.json_encode($this->logged));
        $message = $this->logged[0]['message'];

        // Leaks first, so a failure names its cause before the exact pin does.
        foreach ($this->leakForms() as $what => $form) {
            $this->assertStringNotContainsStringIgnoringCase($form, $message, "{$what} reached the log");
        }
        $this->assertNull($this->idPieceIn($message), 'a piece of the Mesh id, in any spelling, reached the log (#5506)');
        $this->assertStringNotContainsString('{', $message, 'no PSR-3 placeholder syntax in the logged path');
        $this->assertStringNotContainsString('}', $message, 'no PSR-3 placeholder syntax in the logged path');

        // The complete record: one error line, exact text, no context.
        $this->assertSame('error', $this->logged[0]['level']);
        $this->assertSame([], $this->logged[0]['context']);
        $this->assertSame(
            "[MeshClient] GET {$expectedPath} failed with HTTP 503 (".ServerException::class.')',
            $message,
        );
    }

    /**
     * The kept shape the issue requires: GET api/customers/ survives. The
     * query travels as get()'s second argument, i.e. as Guzzle's 'query'
     * option, never inside $endpoint, so logPath()'s '?'/'#' cut is not what
     * keeps it out of this line (the 'customer list with a query' shape pins
     * that cut). Here the request is checked to have carried the query and
     * the line to have dropped it.
     */
    public function test_the_customer_list_path_is_kept(): void
    {
        [$client, $mock, $seen] = $this->clientAnswering503();

        try {
            $client->get('api/customers/', ['_size' => 1, 'filter' => self::QUERY_MARKER]);
            $this->fail('the 503 must throw');
        } catch (MeshClientException) {
        }

        $this->assertSame(0, $mock->count(), 'the request went through the swapped-in MockHandler');
        $this->assertCount(1, $seen->uris, 'exactly one request reached the handler');
        $uri = new Uri($seen->uris[0]);
        $this->assertSame('/api/customers/', $uri->getPath(), 'positive control: the request path');
        $this->assertSame('_size=1&filter='.self::QUERY_MARKER, $uri->getQuery(), 'positive control: the request carried the query option');

        $this->assertCount(1, $this->logged);
        $this->assertSame(
            '[MeshClient] GET api/customers/ failed with HTTP 503 ('.ServerException::class.')',
            $this->logged[0]['message'],
        );
    }

    /**
     * logPath() itself, reached by reflection, on every shape.
     *
     * On logPath()'s real output, before the row's expected value is
     * consulted, two checks run. First the leak check: no leak form the
     * endpoint holds survives into the output (leaksIn(); its control shows
     * it fails on a leaking output, #5441). Then the output is classified
     * against the ENDPOINT alone (classify(), which models the change by a
     * segment split and PSR-7's authority test, not by logPath()'s regexes),
     * so a logPath() mutant fails here rather than only at the exact pin
     * below (#5421, #5439). Then the output is pinned exactly to the row (so
     * an identity logPath() fails on every changed row, #5335).
     *
     * The piece check (idPieceIn(), #5506) runs beside the leak check.
     *
     * The rows are counted by kind with exact numbers: 22 redact, 3 are
     * query-cut only (one of them cut at '#', #5479), 3 authority-strip
     * only (one with a path PSR-7 would percent-encode, #5480), 1 is both
     * query cut and authority strip (#5474), 2 come back as given (#5383).
     * Dropping, unredacting or reclassifying a row changes a count. The
     * number of rows whose endpoint holds each leak form, matched
     * case-sensitively, is pinned exactly (#5477), so no form in
     * leakForms() is dead in this loop, and each form held by one row is
     * tied to it: dropping that row, or adding a second row that holds
     * the form, fails here (#5420, #5442, #5482). Which forms each row
     * holds is pinned by rowLeaks() (#5503).
     */
    public function test_log_path_changes_every_redacted_shape(): void
    {
        $logPath = new \ReflectionMethod(MeshClient::class, 'logPath');
        $kinds = ['redacted' => 0, 'query cut only' => 0, 'authority strip only' => 0, 'query cut and authority strip' => 0, 'kept' => 0];
        $formChecks = array_fill_keys(array_keys($this->leakForms()), 0);

        foreach (self::shapes() as $name => [$endpoint, $expectedPath]) {
            $out = $logPath->invoke(null, $endpoint);
            foreach ($this->leakForms() as $what => $form) {
                if (str_contains($endpoint, $form)) {
                    $formChecks[$what]++;
                }
            }
            $this->assertSame([], $this->leaksIn($endpoint, $out), "{$name}: leak forms that survived logPath(): ".json_encode($out));
            $this->assertNull($this->idPieceIn($out), "{$name}: a piece of the Mesh id survived logPath() (#5506): ".json_encode($out));
            $kind = $this->classify($endpoint, $out);
            if ($kind === null) {
                $this->fail("{$name}: logPath() output is none of the known kinds (#5407): ".json_encode($out));
            }
            $kinds[$kind]++;
            $this->assertSame($expectedPath, $out, "{$name}: logPath()");
        }

        $this->assertSame(
            ['redacted' => 22, 'query cut only' => 3, 'authority strip only' => 3, 'query cut and authority strip' => 1, 'kept' => 2],
            $kinds,
            'rows by kind',
        );
        // Exact per-form row counts (#5477): a count of 1 ties the form to
        // the one row that holds it, so dropping that row, or adding a
        // second row that holds it, fails here.
        $this->assertSame(
            [
                'Mesh id' => 16,
                'Mesh id, dashless' => 1,
                'Mesh id, upper-cased' => 1,
                'Mesh id before its first dash' => 20,
                'Mesh id after its first dash' => 17,
                'Mesh id after its first dash, upper-cased' => 1,
                'Mesh id, dashless, upper-cased' => 1,
                'query marker' => 4,
                'fragment marker' => 1,
                'host' => 1,
                'endpoint host' => 7,
                'user-info, user' => 2,
                'user-info, password' => 1,
                'port' => 2,
                'id prefix segment' => 1,
            ],
            $formChecks,
            'rows holding each leak form, case-sensitively (#5420, #5442, #5477)',
        );
    }

    /**
     * The leak check in the loop above can fail on a logPath() output, not
     * only on fixture data (#5441): fed an output that kept what logPath()
     * must drop, leaksIn() names exactly the forms kept. Every expected
     * list below is literal, not recomputed from leakForms():
     * - identity outputs: the base_uri-host row with its authority kept
     *   leaks 'host'; the user-info row leaks each of its six forms (#5483);
     * - partially processed outputs, not equal to the endpoint (#5471): a
     *   query cut without the redaction leaks the id forms and not the
     *   query marker; an authority strip without the redaction leaks the
     *   id forms and none of the authority's;
     * - an output whose kept id changed case is still named, since $out is
     *   matched in any case (#5481);
     * - on the upper-cased row, an output that kept the tail after the
     *   first dash is named by the tail's upper-cased form; on the
     *   upper-cased dashless row, an output that kept the whole dashless
     *   id is named by that form (#5482, #5505).
     * Then, on every row, the endpoint offered as its own output leaks
     * exactly the forms in that row's literal rowLeaks() list (#5483,
     * #5503): a wrong leakForms() value, or a leaksIn() that matches the
     * endpoint in any case, fails there. A changed row holds at least one
     * form, a kept row none, and across the rows every form in
     * leakForms() is named.
     */
    public function test_the_leak_check_fails_on_a_leaking_output(): void
    {
        $id = self::MESH_ID;
        $base = 'https://'.self::HOST.'/api/customers/';
        $this->assertSame(['host'], $this->leaksIn($base, $base), 'authority kept on the base_uri-host row');
        $this->assertSame([], $this->leaksIn($base, '/api/customers/'), 'authority stripped');
        $creds = 'https://'.self::USER.':'.self::PASS.'@'.self::ENDPOINT_HOST.':'.self::PORT."/api/customers/{$id}/";
        $this->assertSame(
            ['Mesh id', 'Mesh id before its first dash', 'Mesh id after its first dash', 'endpoint host', 'user-info, user', 'user-info, password', 'port'],
            $this->leaksIn($creds, $creds),
            'user-info row, identity output: every form it holds (#5483)',
        );

        // #5471: partially processed outputs.
        $this->assertSame(
            ['Mesh id', 'Mesh id before its first dash', 'Mesh id after its first dash'],
            $this->leaksIn("api/customers/{$id}/?filter=".self::QUERY_MARKER, "api/customers/{$id}/"),
            'query cut, redaction skipped',
        );
        $this->assertSame(
            ['Mesh id', 'Mesh id before its first dash', 'Mesh id after its first dash'],
            $this->leaksIn($creds, "/api/customers/{$id}/"),
            'authority stripped, redaction skipped',
        );
        $this->assertSame(
            ['endpoint host', 'user-info, user', 'user-info, password', 'port'],
            $this->leaksIn($creds, self::USER.':'.self::PASS.'@'.self::ENDPOINT_HOST.':'.self::PORT.'/api/customers/<customer>'),
            'redacted, scheme dropped but authority kept',
        );

        // #5481: the kept id changed case on its way to the output.
        $this->assertSame(
            ['Mesh id', 'Mesh id before its first dash', 'Mesh id after its first dash'],
            $this->leaksIn("api/customers/{$id}/", 'api/customers/'.strtoupper($id).'/'),
            'lower-case id logged upper-cased',
        );
        $this->assertSame(
            ['Mesh id, upper-cased', 'Mesh id after its first dash, upper-cased'],
            $this->leaksIn('api/customers/'.strtoupper($id).'/', "api/customers/{$id}/"),
            'upper-case id logged lower-cased',
        );

        // #5482: part of an upper-cased id kept.
        $this->assertSame(
            ['Mesh id after its first dash, upper-cased'],
            $this->leaksIn('api/customers/'.strtoupper($id).'/', 'api/customers/'.strtoupper(substr($id, 9))),
            'upper-cased row, tail after the first dash kept',
        );
        $upBare = strtoupper(str_replace('-', '', $id));
        $this->assertSame(
            ['Mesh id, dashless, upper-cased'],
            $this->leaksIn("api/customers/{$upBare}/", "api/customers/{$upBare}"),
            'upper-cased dashless row, id kept',
        );

        // #5503: each row's identity output against a LITERAL list of the
        // forms that row was built to hold (rowLeaks()), not one recomputed
        // from leakForms() with leaksIn()'s own predicate. The kept rows
        // hold none.
        $named = [];
        $rows = 0;
        foreach (self::shapes() as $name => [$endpoint, $expectedPath]) {
            $own = $this->leaksIn($endpoint, $endpoint);
            $this->assertArrayHasKey($name, self::rowLeaks(), "{$name}: the row has a literal leak list");
            $this->assertSame(self::rowLeaks()[$name], $own, "{$name}: an identity logPath() leaks exactly the forms the row was built to hold");
            if ($endpoint !== $expectedPath) {
                $this->assertNotSame([], $own, "{$name}: a changed row holds a leak form");
            } else {
                $this->assertSame([], $own, "{$name}: a kept row holds no leak form");
            }
            $named = array_merge($named, $own);
            $rows++;
        }
        $this->assertSame(count(self::rowLeaks()), $rows, 'one literal leak list per row, no stale entries');
        $this->assertEqualsCanonicalizing(array_keys($this->leakForms()), array_values(array_unique($named)), 'every form is named on some leaking output');
    }

    /**
     * #5506: the piece check (idPieceIn()) names what no whole leak form
     * does: a kept head, last group or 8-character run of the id, a
     * mixed-case id, an id with percent-encoded dashes, and the newline
     * row's id split by '%0A'. On every row, the redacted log path holds
     * no piece, and every row whose endpoint holds the id in any spelling
     * has a piece the check finds there (so it is not blind on the rows
     * the loop runs it on).
     */
    public function test_the_id_piece_check_names_shorter_and_re_spelled_pieces(): void
    {
        $id = self::MESH_ID;
        $this->assertSame([], $this->leaksIn("api/customers/{$id}/", 'api/customers/'.substr($id, 24)), 'precondition: no whole form names the last group');
        $this->assertSame('5c3a4f86', $this->idPieceIn('api/customers/'.substr($id, 9, 9)), 'a run across a dash');
        $this->assertSame('e91c4b7f', $this->idPieceIn('api/customers/'.substr($id, 24)), 'the last group');
        $this->assertSame('7b2e9d41', $this->idPieceIn('api/customers/'.substr($id, 0, 8)), 'the head');
        $this->assertSame('7b2e9d41', $this->idPieceIn('api/customers/'.substr($id, 0, 19).strtoupper(substr($id, 19))), 'mixed case');
        $this->assertSame('7b2e9d41', $this->idPieceIn('api/customers/7B2E9D41%2D5C3A'), 'percent-encoded dash, upper-cased');
        $this->assertSame('7b2e9d41', $this->idPieceIn('api/customers/7b2e9d41%0A5c3a'), 'the newline row, split by %0A');
        $this->assertNull($this->idPieceIn('api/customers/'.substr($id, 0, 7)), 'seven characters are not named');
        $this->assertNull($this->idPieceIn('api/customers/<customer>'), 'the redacted path');

        foreach (self::shapes() as $name => [$endpoint, $expectedPath, $requestPath]) {
            $this->assertNull($this->idPieceIn($expectedPath), "{$name}: the log path holds no piece of the id");
            if (str_contains($name, 'id') || str_contains($endpoint, substr($id, 0, 8))) {
                $this->assertNotNull($this->idPieceIn($endpoint), "{$name}: positive control, the endpoint holds a piece");
                $this->assertNotNull($this->idPieceIn($requestPath), "{$name}: positive control, the request path holds a piece");
            }
        }
    }

    /**
     * The leak forms each row's endpoint was built to hold, in leakForms()
     * order, written out by hand (#5503). A form added to leakForms(), or
     * a row changed so it holds another, fails the per-row comparison in
     * test_the_leak_check_fails_on_a_leaking_output until this list says
     * so.
     *
     * @return array<string, list<string>>
     */
    private static function rowLeaks(): array
    {
        $id = ['Mesh id', 'Mesh id before its first dash', 'Mesh id after its first dash'];
        $creds = ['endpoint host', 'user-info, user', 'user-info, password', 'port'];

        return [
            'customer read (getCustomer shape)' => $id,
            'leading slash' => $id,
            'absolute URL' => [...$id, 'endpoint host'],
            'scheme-relative URL' => [...$id, 'endpoint host'],
            'absolute URL, user-info and port' => [...$id, ...$creds],
            'scheme-relative customer list, user-info and port' => ['endpoint host', 'user-info, user', 'port'],
            "absolute customer list on base_uri's host" => ['host'],
            'versioned prefix' => $id,
            'nested prefix' => $id,
            'id containing a slash' => [...$id, 'id prefix segment'],
            'sub-resource' => $id,
            'id upper-cased' => ['Mesh id, upper-cased', 'Mesh id after its first dash, upper-cased'],
            'id dashless' => ['Mesh id, dashless', 'Mesh id before its first dash'],
            'id dashless, upper-cased' => ['Mesh id, dashless, upper-cased'],
            'id mixed-case' => ['Mesh id before its first dash'],
            'id percent-encoded dashes' => ['Mesh id before its first dash'],
            'id, no trailing slash' => $id,
            'id with a query' => [...$id, 'query marker'],
            'customer list with a query' => ['query marker'],
            'leading ?' => ['query marker'],
            'absolute customer list with a query' => ['query marker', 'endpoint host'],
            'id with a fragment' => $id,
            'unredacted path with a fragment' => ['fragment marker'],
            'id with a newline (s flag)' => ['Mesh id before its first dash', 'Mesh id after its first dash'],
            'absolute URL, space in the path' => ['endpoint host'],
            'single slash after the scheme' => $id,
            'upper-case scheme (i flag, host strip)' => [...$id, 'endpoint host'],
            'upper-case Customers/ (i flag, redaction)' => $id,
            'bare customers/ at the start (^ branch)' => $id,
            'xcustomers/ is not a segment (boundary)' => [],
            'customer list' => [],
        ];
    }

    /**
     * The classifier is not vacuous: each of its kinds is reached by a
     * synthetic output, and an output changed any other way is refused.
     * Refused: a cut or strip that also lost a character; an output that
     * kept the user-info, the port, or both (#5421, #5422, #5425); an
     * absolute or relative customer read left unredacted, an authority
     * left on, and a query cut deleted (nine outputs of a logPath() with
     * exactly one step deleted, #5439, #5504; on a customers/ path a
     * deleted cut leaves no query on, since the redaction swallows it,
     * #5508), including the IDENTITY output, where the deleted step was
     * the only one the endpoint needed, on three named endpoints and on
     * every changed row (#5470); six outputs that are not step-deletion
     * outputs but are changed otherwise, one of them an identity; a
     * relative or absolute path as PSR-7's Uri would percent-encode it
     * (#5443, #5480); a single slash after an http(s) scheme, which Uri
     * gives a default host but which has no authority bytes to strip
     * (#5502); and a query cut plus an authority strip is pinned to the
     * combined kind, not either 'only' kind and not null (#5437, #5475).
     */
    public function test_the_classifier_reaches_each_arm_and_refuses_other_changes(): void
    {
        $host = self::ENDPOINT_HOST;
        $this->assertSame('kept', $this->classify('api/customers/', 'api/customers/'));
        $this->assertSame('redacted', $this->classify('api/customers/x/', 'api/customers/<customer>'));
        $this->assertSame('query cut only', $this->classify('api/customers/?a=1', 'api/customers/'));
        $this->assertSame('authority strip only', $this->classify("https://{$host}/api/customers/", '/api/customers/'));
        $this->assertSame('authority strip only', $this->classify("//u@{$host}:8443/api/customers/", '/api/customers/'));
        $this->assertSame('query cut and authority strip', $this->classify("//u@{$host}:8443/api/customers/?a=1", '/api/customers/'));
        $this->assertSame('redacted', $this->classify("https://{$host}/api/v2/Customers/x/y?a=1", '/api/v2/Customers/<customer>'));

        // logPath() step-deletion outputs (#5439): exactly what logPath()
        // gives with ONE of its three steps (cut, strip, redaction) deleted,
        // nine controls (#5504).
        // Redaction deleted (three):
        $this->assertNull($this->classify("https://{$host}/api/customers/x/", '/api/customers/x/'), 'absolute customer read, redaction deleted');
        $this->assertNull($this->classify('//'.$host.'/api/customers/x/', '/api/customers/x/'), 'scheme-relative customer read, redaction deleted');
        $this->assertNull($this->classify('api/customers/x/?a=1', 'api/customers/x/'), 'query cut, redaction deleted');
        // Authority strip deleted (two):
        $this->assertNull($this->classify("https://{$host}/api/customers/x/", "https://{$host}/api/customers/<customer>"), 'redacted, authority strip deleted');
        $this->assertNull($this->classify("https://{$host}/api/customers/?a=1", "https://{$host}/api/customers/"), 'query cut, authority strip deleted');
        // Query cut deleted, on a customers/ path: the redaction's '.+$'
        // swallows the query, so the output is redacted, not the endpoint
        // (#5508); this is what that mutant gives.
        $this->assertNull($this->classify('api/customers/?a=1', 'api/customers/<customer>'), 'customer list with a query, query cut deleted');
        // #5470, IDENTITY outputs: the step deleted is the only one the
        // endpoint needs, so the output IS the endpoint (three). This
        // PR's base classifier refuses them too; the shortcut they guard
        // against is `if ($out === $endpoint) return 'kept';` at the top of
        // classify(), as an earlier revision of this file had it, and they
        // fail with that shortcut restored (#5507).
        $this->assertNull($this->classify('api/customers/x/', 'api/customers/x/'), 'relative customer read, redaction deleted (identity)');
        $this->assertNull($this->classify('api/devices/#f', 'api/devices/#f'), 'unredacted path with a fragment, cut deleted (identity)');
        $this->assertNull($this->classify("https://{$host}/api/customers/", "https://{$host}/api/customers/"), 'absolute customer list, authority strip deleted (identity)');
        // Not step-deletion outputs: changed some other way (six, #5504).
        // The first is an identity output no single step deletion gives
        // (#5508); it guards the restored shortcut all the same.
        $this->assertNull($this->classify('api/customers/?a=1', 'api/customers/?a=1'), 'customer list with a query, identity (not a step-deletion output)');
        $this->assertNull($this->classify('api/customers/x/', 'api/customers/x/x'), 'relative customer read, changed but not redacted');
        $this->assertNull($this->classify("https://{$host}/api/customers/", "https://{$host}/api/customers/".'?'), 'authority kept, changed otherwise');
        $this->assertNull($this->classify('api/customers/x/?a=1', 'api/customers/<customer>?a=1'), 'redacted, query kept');
        $this->assertNull($this->classify('api/customers/?a=1', 'api/customers/?a=1x'), 'query kept and changed');
        $this->assertNull($this->classify("https://{$host}/api/customers/?a=1", '/api/customers/?a=1'), 'authority strip, query kept');
        // #5443: no authority, so the Uri path is not an authority strip.
        $this->assertNull($this->classify('api/x y/', 'api/x%20y/'), 'relative path percent-encoded by Uri');
        $this->assertNull($this->classify('api/x y/?a=1', 'api/x%20y/'), 'relative cut percent-encoded by Uri');
        // #5480: nor an absolute one; the strip keeps the path's bytes.
        $this->assertSame('authority strip only', $this->classify("https://{$host}/api/x y/", '/api/x y/'), 'absolute path kept byte for byte');
        $this->assertNull($this->classify("https://{$host}/api/x y/", '/api/x%20y/'), 'absolute path percent-encoded by Uri');
        // #5437 / #5475: a query cut plus an authority strip is exactly the
        // combined kind (so neither 'only' kind, and not null).
        $this->assertSame('query cut and authority strip', $this->classify("https://{$host}/api/customers/?a=1", '/api/customers/'), 'https + query: both steps');
        $this->assertSame('query cut and authority strip', $this->classify("https://{$host}/api/customers/#f", '/api/customers/'), 'https + fragment: both steps');
        // #5502: an http(s) scheme followed by ONE slash. The bytes hold no
        // '//' and no authority, so nothing is stripped: the scheme stays
        // and no path segment is lost. Measured on the vendored
        // guzzlehttp/psr7: the Uri constructor leaves the host empty here,
        // and only fromParts() (and the with*() methods) give an http(s)
        // URI with no host the default 'localhost'. classify() also checks
        // for the '//' bytes, so it does not depend on which one it gets.
        $this->assertSame('', (new Uri('https:/api/customers/x/'))->getAuthority(), 'measured: the Uri constructor gives no default host');
        $this->assertSame('localhost', Uri::fromParts(['scheme' => 'https', 'path' => '/api/customers/x/'])->getAuthority(), 'measured: fromParts() gives the default host');
        $this->assertSame('redacted', $this->classify('https:/api/customers/x/', 'https:/api/customers/<customer>'), 'single slash after the scheme: redacted, nothing stripped');
        $this->assertNull($this->classify('https:/api/customers/x/', '/customers/<customer>'), 'single slash: two bytes skipped as if they were //');
        $this->assertNull($this->classify('https:/api/customers/x/', '/api/customers/<customer>'), 'single slash: the scheme stripped as if it had an authority');
        $this->assertSame('kept', $this->classify('http:/api/customers/', 'http:/api/customers/'), 'single slash, customer list: kept');

        $this->assertNull($this->classify('api/customers/?a=1', 'api/customers'), 'a cut that also trimmed');
        $this->assertNull($this->classify("https://{$host}/api/customers/", '/api/customers'), 'a strip that also trimmed');
        $this->assertNull($this->classify("https://u:p@{$host}:8443/api/customers/", 'u:p@:8443/api/customers/'), 'user-info and port kept');
        $this->assertNull($this->classify("https://{$host}:8443/api/customers/", ':8443/api/customers/'), 'port kept');
        $this->assertNull($this->classify("https://u@{$host}/api/customers/", 'u@/api/customers/'), 'user-info kept');
        $this->assertNull($this->classify('api/customers/', 'api/customers/x'), 'changed otherwise');

        // #5470, every row: on each row logPath() changes, the endpoint
        // offered as its own output (an identity logPath()) is refused.
        $identities = 0;
        foreach (self::shapes() as $name => [$endpoint, $expectedPath]) {
            if ($endpoint !== $expectedPath) {
                $this->assertNull($this->classify($endpoint, $endpoint), "{$name}: an identity output on a changed row is refused");
                $identities++;
            }
        }
        $this->assertSame(count(self::shapes()) - 2, $identities, 'every row but the two kept ones is a changed row');
    }

    /**
     * How logPath() changed $endpoint into $out, judged from the endpoint
     * alone; null unless $out is EXACTLY what every step applied to the
     * endpoint gives. The steps are modelled without logPath()'s regexes:
     * the cut at the first '?' or '#'; the authority strip, only when
     * PSR-7's Uri finds an authority in the cut AND the cut's bytes after
     * '<scheme>:' (or from its start) are '//', taken as the cut's own
     * bytes from the first '/' after that '//' (not Uri's getPath(), which
     * percent-encodes, so neither a relative nor an absolute path is
     * modelled as encoded, #5443, #5480). The '//' test keeps the model off
     * PSR-7's default host: Uri::fromParts() and the with*() methods give
     * an http(s) URI with no host the host 'localhost', so 'https:/api/...'
     * could have an authority to Uri and none in its bytes. The vendored
     * Uri constructor used here does not (measured in
     * test_the_classifier_reaches_each_arm_and_refuses_other_changes), so
     * today the test changes no result; it is never stripped either way
     * (#5502). Then the redaction, by splitting on '/' at
     * the first segment equal to 'customers' (any case) that has anything
     * after it. So an output that skipped any step (left the id, kept the
     * authority, kept the query) or did anything else is refused (#5407,
     * #5439). 'redacted' says the redaction ran and nothing about the cut
     * or the strip: it is returned whether or not they also changed the
     * endpoint (#5473). The other kinds name exactly the steps that
     * changed it, and each 'only' kind is exactly one step (#5437).
     */
    private function classify(string $endpoint, string $out): ?string
    {
        $cut = substr($endpoint, 0, strcspn($endpoint, '?#'));
        $uri = new Uri($cut);
        $stripped = $cut;
        $afterScheme = $uri->getScheme() !== '' ? strlen($uri->getScheme()) + 1 : 0;
        if ($uri->getAuthority() !== '' && substr($cut, $afterScheme, 2) === '//') {
            // The raw bytes after the authority, not $uri->getPath(), which
            // percent-encodes (#5480): skip '<scheme>:' if Uri found one,
            // then the '//' (checked to be there, #5502), then up to the
            // next '/'.
            $slash = strpos($cut, '/', $afterScheme + 2);
            $stripped = $slash === false ? '' : substr($cut, $slash);
        }

        $want = $stripped;
        $redacted = false;
        $segments = explode('/', $stripped);
        foreach ($segments as $i => $segment) {
            if (strcasecmp($segment, 'customers') === 0 && $i < count($segments) - 1) {
                if (implode('/', array_slice($segments, $i + 1)) !== '') {
                    $want = implode('/', array_slice($segments, 0, $i + 1)).'/<customer>';
                    $redacted = true;
                }
                break;
            }
        }

        if ($out !== $want) {
            return null;
        }
        if ($redacted) {
            return 'redacted';
        }
        $queryCut = $cut !== $endpoint;
        $authorityStrip = $stripped !== $cut;

        return match (true) {
            $queryCut && $authorityStrip => 'query cut and authority strip',
            $authorityStrip => 'authority strip only',
            $queryCut => 'query cut only',
            default => 'kept',
        };
    }

    /**
     * The leak forms $endpoint holds (matched case-sensitively, so each
     * case variant is its own form) that $out still contains (matched in
     * any case). Empty when $out leaks nothing the endpoint held.
     *
     * @return list<string>
     */
    private function leaksIn(string $endpoint, string $out): array
    {
        $leaks = [];
        foreach ($this->leakForms() as $what => $form) {
            if (str_contains($endpoint, $form) && stripos($out, $form) !== false) {
                $leaks[] = $what;
            }
        }

        return $leaks;
    }

    /** @return array<string, string> */
    private function leakForms(): array
    {
        return [
            'Mesh id' => self::MESH_ID,
            'Mesh id, dashless' => str_replace('-', '', self::MESH_ID),
            'Mesh id, upper-cased' => strtoupper(self::MESH_ID),
            // The two halves of the newline row's id, which replaces the
            // id's first dash with a newline (#5472, #5478). Each half is
            // also held by every row carrying the whole lower-case dashed
            // id; the dashless rows hold neither. Which rows hold which form
            // is pinned per row (#5503) and per form (#5477).
            'Mesh id before its first dash' => substr(self::MESH_ID, 0, 8),
            'Mesh id after its first dash' => substr(self::MESH_ID, 9),
            // #5482: the tail and the dashless id in the case the
            // upper-cased rows hold them, since the endpoint match is
            // case-sensitive (the second is the whole id, not a part).
            'Mesh id after its first dash, upper-cased' => strtoupper(substr(self::MESH_ID, 9)),
            'Mesh id, dashless, upper-cased' => strtoupper(str_replace('-', '', self::MESH_ID)),
            'query marker' => self::QUERY_MARKER,
            'fragment marker' => self::FRAGMENT_MARKER,
            'host' => self::HOST,
            'endpoint host' => self::ENDPOINT_HOST,
            'user-info, user' => self::USER,
            'user-info, password' => self::PASS,
            'port' => ':'.self::PORT,
            'id prefix segment' => 'abc/',
        ];
    }

    /**
     * The first piece of the Mesh id, 8 characters long, that $text holds
     * in any spelling, or null (#5506). Leak forms are whole contiguous
     * spellings; this check is not: $text is percent-decoded, lower-cased
     * and stripped of every character that is not a letter or a digit
     * (so dashes, '%2D', newlines and slashes no longer split it), and
     * every 8-character window of the dashless id is looked for. So a
     * kept head, tail or last group, a mixed-case or a percent-encoded id
     * is named. Not named: a piece shorter than 8 characters, or one
     * interleaved with other letters or digits.
     */
    private function idPieceIn(string $text): ?string
    {
        $flat = (string) preg_replace('/[^a-z0-9]/', '', strtolower(rawurldecode($text)));
        $id = str_replace('-', '', self::MESH_ID);
        for ($i = 0; $i + 8 <= strlen($id); $i++) {
            if (str_contains($flat, substr($id, $i, 8))) {
                return substr($id, $i, 8);
            }
        }

        return null;
    }

    /**
     * A real MeshClient whose Guzzle is a one-response MockHandler (503).
     *
     * @return array{0: MeshClient, 1: MockHandler, 2: object{uris: list<string>}}
     */
    private function clientAnswering503(): array
    {
        $mock = new MockHandler([new Response(503, [], 'vendor body')]);
        $seen = new class
        {
            /** @var list<string> */
            public array $uris = [];
        };
        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $next) use ($seen) {
            return function (RequestInterface $r, array $o) use ($next, $seen) {
                $seen->uris[] = (string) $r->getUri();

                return $next($r, $o);
            };
        });

        $client = new MeshClient(['api_key' => 'synthetic-key', 'base_url' => 'https://'.self::HOST]);
        (new \ReflectionProperty($client, 'http'))->setValue($client, new GuzzleClient([
            'base_uri' => 'https://'.self::HOST.'/',
            'handler' => $stack,
        ]));

        return [$client, $mock, $seen];
    }
}
