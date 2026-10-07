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
            // Fragment row. The query option does not touch a fragment: the
            // PSR-7 request URI the handler receives KEEPS '#frag' (Guzzle's
            // resolver carries the relative fragment), and the log line drops
            // it. Not measured here: Guzzle's real transports send the URL
            // without its fragment (#5405).
            'id with a fragment' => ["api/customers/{$id}/#frag", 'api/customers/<customer>', "/api/customers/{$id}/", '', 'frag'],
            // #5337: the regex flags and the segment boundary. The request
            // path carries the newline percent-encoded.
            'id with a newline (s flag)' => ["api/customers/{$nl}/", 'api/customers/<customer>', '/api/customers/'.str_replace("\n", '%0A', $nl).'/'],
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
     * First the output is classified against the ENDPOINT, before the row's
     * expected value is consulted, so this arm runs on logPath()'s real
     * output and a logPath() mutant can fail here rather than only at the
     * exact pin below (#5421). A row comes back as given, or redacts a
     * customer tail to <customer>, or is changed ONLY by the query cut (the
     * output is exactly the endpoint up to its first '?' or '#'), or ONLY by
     * dropping a scheme and authority (the output is exactly that cut's
     * path as PSR-7's Uri parses it, which is not logPath()'s regex; #5422).
     * Any other change without a <customer> fails (#5407). Then the output
     * is pinned exactly to the row (so an identity logPath() fails on every
     * changed row, #5335), and a changed row is free of every leak form the
     * endpoint held.
     *
     * The rows are counted by kind with exact numbers: 18 redact, 2 are
     * query-cut only, 2 authority-strip only, 2 come back as given (#5383).
     * Dropping, unredacting or reclassifying a row changes a count. Every
     * leak form is checked on at least one changed row that holds it, so no
     * form in leakForms() is dead in this loop (#5420).
     */
    public function test_log_path_changes_every_redacted_shape(): void
    {
        $logPath = new \ReflectionMethod(MeshClient::class, 'logPath');
        $kinds = ['redacted' => 0, 'query cut only' => 0, 'authority strip only' => 0, 'kept' => 0];
        $formChecks = array_fill_keys(array_keys($this->leakForms()), 0);

        foreach (self::shapes() as $name => [$endpoint, $expectedPath]) {
            $out = $logPath->invoke(null, $endpoint);
            $kind = $this->classify($endpoint, $out);
            if ($kind === null) {
                $this->fail("{$name}: changed without a <customer> and not only by the query cut or the scheme/authority strip: ".json_encode($out));
            }
            $kinds[$kind]++;
            $this->assertSame($expectedPath, $out, "{$name}: logPath()");
            if ($kind === 'kept') {
                continue;
            }
            foreach ($this->leakForms() as $what => $form) {
                if (stripos($endpoint, $form) !== false) {
                    $formChecks[$what]++;
                    $this->assertStringNotContainsStringIgnoringCase($form, $out, "{$name}: {$what} survived logPath()");
                }
            }
        }

        $this->assertSame(
            ['redacted' => 18, 'query cut only' => 2, 'authority strip only' => 2, 'kept' => 2],
            $kinds,
            'rows by kind',
        );
        foreach ($formChecks as $what => $n) {
            $this->assertGreaterThan(0, $n, "leak form '{$what}' is never checked on a changed row that holds it (#5420)");
        }
    }

    /**
     * The classifier is not vacuous: each of its arms is reached by a
     * synthetic output, and an output changed any other way is refused.
     * The query-cut arm refuses a cut that also lost a character, and the
     * authority arm refuses an output that kept the user-info or port
     * (#5421, #5422, #5425).
     */
    public function test_the_classifier_reaches_each_arm_and_refuses_other_changes(): void
    {
        $host = self::ENDPOINT_HOST;
        $this->assertSame('kept', $this->classify('api/customers/', 'api/customers/'));
        $this->assertSame('redacted', $this->classify('api/customers/x/', 'api/customers/<customer>'));
        $this->assertSame('query cut only', $this->classify('api/customers/?a=1', 'api/customers/'));
        $this->assertSame('authority strip only', $this->classify("https://{$host}/api/customers/", '/api/customers/'));
        $this->assertSame('authority strip only', $this->classify("//u@{$host}:8443/api/customers/?a=1", '/api/customers/'));

        $this->assertNull($this->classify('api/customers/?a=1', 'api/customers'), 'a cut that also trimmed');
        $this->assertNull($this->classify("https://{$host}/api/customers/", '/api/customers'), 'a strip that also trimmed');
        $this->assertNull($this->classify("https://u:p@{$host}:8443/api/customers/", 'u:p@:8443/api/customers/'), 'user-info and port kept');
        $this->assertNull($this->classify("https://{$host}:8443/api/customers/", ':8443/api/customers/'), 'port kept');
        $this->assertNull($this->classify('api/customers/', 'api/customers/x'), 'changed otherwise');
    }

    /**
     * How logPath() changed $endpoint into $out, judged from the endpoint
     * alone; null when the change is none of the known kinds.
     */
    private function classify(string $endpoint, string $out): ?string
    {
        if ($out === $endpoint) {
            return 'kept';
        }
        if (str_contains($out, '<customer>')) {
            return 'redacted';
        }
        $cut = substr($endpoint, 0, strcspn($endpoint, '?#'));
        if ($out === $cut) {
            return 'query cut only';
        }
        $uri = new Uri($cut);
        if ($uri->getAuthority() !== '' && $out === $uri->getPath()) {
            return 'authority strip only';
        }

        return null;
    }

    /** @return array<string, string> */
    private function leakForms(): array
    {
        return [
            'Mesh id' => self::MESH_ID,
            'Mesh id, dashless' => str_replace('-', '', self::MESH_ID),
            'Mesh id, upper-cased' => strtoupper(self::MESH_ID),
            'query marker' => self::QUERY_MARKER,
            'host' => self::HOST,
            'endpoint host' => self::ENDPOINT_HOST,
            'user-info, user' => self::USER,
            'user-info, password' => self::PASS,
            'port' => ':'.self::PORT,
            'id prefix segment' => 'abc/',
        ];
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
