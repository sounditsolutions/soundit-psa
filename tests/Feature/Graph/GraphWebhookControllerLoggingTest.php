<?php

namespace Tests\Feature\Graph;

use App\Models\Setting;
use App\Services\EmailService;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use App\Services\Graph\GraphTokenException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FlattensLogContext;
use Tests\TestCase;

/**
 * #5731 / C-56: GraphWebhookController's two failure records carry no mailbox, no endpoint and
 * no exception message.
 * - The GraphClientException arm ('Failed to fetch message') logs the HTTP status and the
 *   exception class.
 * - The Throwable arm ('Failed to import message') logs the exception class only, because its
 *   message is not GraphClient's (an import or database failure can carry any text).
 * - #5829: the fetch and the import are caught separately, so a GraphClientException from the
 *   import is an import failure (status and class), and any other exception from the fetch is a
 *   fetch failure (class only).
 * Neither logs the notification's resource (users/{mailbox}/messages/{id}). The response is
 * unchanged: 202 {"status":"accepted"} whether the fetch or the import fails.
 *
 * G-5: GraphClient runs over a scripted MockHandler, Http::preventStrayRequests() is on, and
 * every value is synthetic (G-13). Records are read from MessageLogged, which sees every level
 * and every channel written through Laravel's logger. The needle scan walks each record with
 * FlattensLogContext (no '\/' escaping; a Throwable in context is rendered, #5733/#5737).
 */
class GraphWebhookControllerLoggingTest extends TestCase
{
    use FlattensLogContext;
    use RefreshDatabase;

    private const MAILBOX = 'support@example.test';

    private const CLIENT_STATE_FIXTURE = 'b4k1-synthetic-client-state';

    private const GRAPH_ERROR_TEXT = 'B4K1-SYNTHETIC-WEBHOOK-GRAPH-ERROR';

    private const IMPORT_ERROR_TEXT = 'B4K1-SYNTHETIC-IMPORT-FAILURE';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Event::listen(MessageLogged::class, function (MessageLogged $m): void {
            $this->logged[] = $m;
        });
        Setting::setValue('graph_webhook_client_state', self::CLIENT_STATE_FIXTURE);
    }

    private static function resource(): string
    {
        return 'users/'.self::MAILBOX.'/messages/MSG-1';
    }

    /** Bind a real GraphClient whose only network is this scripted queue. */
    private function graph(Response|\Throwable ...$responses): void
    {
        $this->graphWithCache(true, ...$responses);
    }

    private function graphWithCache(bool $seeded, Response|\Throwable ...$responses): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $cache = new Repository(new ArrayStore);
        if ($seeded) {
            $cache->put('graph_api_token', 'b4k1-synthetic-seeded-access', 3600);
        }

        $this->app->instance(GraphClient::class, new GraphClient([
            'tenant_id' => 'tenant-b4k1-synthetic',
            'client_id' => 'b4k1-client',
            'client_secret' => 'b4k1-synthetic-secret-not-real',
            'request_timeout' => 5,
            'token_timeout' => 5,
            'handler' => $stack,
        ], $cache));
    }

    private function notify(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/webhooks/graph/mail', ['value' => [[
            'clientState' => self::CLIENT_STATE_FIXTURE,
            'resource' => self::resource(),
        ]]]);
    }

    /** @return list<string> */
    private static function needles(): array
    {
        return [self::MAILBOX, rawurlencode(self::MAILBOX), 'support@', 'users/', 'MSG-1', self::GRAPH_ERROR_TEXT, self::IMPORT_ERROR_TEXT, 'Graph API error', 'cURL'];
    }

    /** @return list<string> the needles $text carries */
    private static function carried(string $text): array
    {
        return array_values(array_filter(self::needles(), fn (string $n) => str_contains($text, $n)));
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> every [GraphWebhook] record */
    private function webhookRecords(): array
    {
        return array_values(array_map(
            fn (MessageLogged $m) => [$m->level, $m->message, $m->context],
            array_filter($this->logged, fn (MessageLogged $m) => str_starts_with($m->message, '[GraphWebhook]')),
        ));
    }

    /** @return list<array{0: string, 1: list<string>}> [message, needles] for each record that carries one */
    private function recordsCarryingANeedle(): array
    {
        $out = [];
        foreach ($this->logged as $m) {
            $carried = self::carried($m->message.' '.self::flattenForScan($m->context));
            if ($carried !== []) {
                $out[] = ["{$m->level} {$m->message}", $carried];
            }
        }

        return $out;
    }

    private function assertAccepted(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(202);
        $this->assertSame(['status' => 'accepted'], $response->json());
    }

    public function test_a_failed_fetch_logs_status_and_class_only(): void
    {
        $this->graph(new Response(404, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => ['code' => 'ErrorItemNotFound', 'message' => self::GRAPH_ERROR_TEXT.' for '.self::MAILBOX],
        ])));

        $this->assertAccepted($this->notify());

        $this->assertSame('/v1.0/'.self::resource(), urldecode($this->history[0]['request']->getUri()->getPath()), 'positive control: the mailbox was in the request');
        $this->assertSame(
            [['error', '[GraphWebhook] Failed to fetch message', ['status' => 404, 'exception' => GraphClientException::class]]],
            $this->webhookRecords(),
        );
        $this->assertSame([], $this->recordsCarryingANeedle());
    }

    public function test_a_fetch_with_no_response_logs_status_zero_and_class_only(): void
    {
        $this->graph(new ConnectException('cURL error 7: refused for https://graph.microsoft.com/v1.0/'.self::resource(), new Request('GET', self::resource())));

        $this->assertAccepted($this->notify());

        $this->assertNotNull($this->history[0]['error'] ?? null, 'positive control: rejected without a response');
        $this->assertSame(
            [['error', '[GraphWebhook] Failed to fetch message', ['status' => 0, 'exception' => GraphClientException::class]]],
            $this->webhookRecords(),
        );
        $this->assertSame([], $this->recordsCarryingANeedle());
    }

    public function test_a_failed_import_logs_the_class_only(): void
    {
        $this->graph(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'MSG-1', 'subject' => 'synthetic',
        ])));
        $this->mock(EmailService::class, function ($m): void {
            $m->shouldReceive('importSingleMessage')->once()
                ->andThrow(new \RuntimeException(self::IMPORT_ERROR_TEXT.' for '.self::resource()));
        });

        $this->assertAccepted($this->notify());

        $this->assertSame(
            [['error', '[GraphWebhook] Failed to import message', ['exception' => \RuntimeException::class]]],
            $this->webhookRecords(),
        );
        $this->assertSame([], $this->recordsCarryingANeedle());
    }

    /**
     * #5829: a GraphClientException thrown inside the import (here an attachment read) is
     * recorded as an import failure with its status and class. Before #5829 the fetch and the
     * import shared one try, and this record said 'Failed to fetch message'.
     */
    public function test_a_graph_failure_inside_the_import_is_recorded_as_an_import_failure(): void
    {
        $this->graph(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'MSG-1', 'subject' => 'synthetic',
        ])));
        $this->mock(EmailService::class, function ($m): void {
            $m->shouldReceive('importSingleMessage')->once()
                ->andThrow(new GraphClientException('Graph API error: GET returned 503', 503));
        });

        $this->assertAccepted($this->notify());

        $this->assertCount(1, $this->history, 'positive control: the fetch succeeded');
        $this->assertSame(
            [['error', '[GraphWebhook] Failed to import message', ['status' => 503, 'exception' => GraphClientException::class]]],
            $this->webhookRecords(),
        );
        $this->assertSame([], $this->recordsCarryingANeedle());
    }

    /**
     * #5839: on a cold cache a token failure reaches the fetch arm as a GraphTokenException with
     * status 0; the class tells it apart from a fetch that received no response (also status 0,
     * GraphClientException; pinned above).
     */
    public function test_a_cold_cache_token_failure_is_recorded_on_the_fetch_arm_with_its_class(): void
    {
        $this->graphWithCache(false, new Response(400, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => 'invalid_client', 'error_description' => self::GRAPH_ERROR_TEXT,
        ])));

        $this->assertAccepted($this->notify());

        $this->assertSame('login.microsoftonline.com', $this->history[0]['request']->getUri()->getHost(), 'positive control: the token request was the one that failed');
        $this->assertCount(1, $this->history, 'no Graph request was sent');
        $this->assertSame(
            [['error', '[GraphWebhook] Failed to fetch message', ['status' => 0, 'exception' => GraphTokenException::class]]],
            $this->webhookRecords(),
        );
        $this->assertSame([], $this->recordsCarryingANeedle());
    }

    /** The responses the controller gives outside the failure arms are unchanged. */
    public function test_the_handshake_empty_and_mismatch_responses_are_unchanged(): void
    {
        $this->graph();

        $handshake = $this->post('/api/webhooks/graph/mail?validationToken=b4k1-synthetic-validation');
        $handshake->assertStatus(200);
        $this->assertSame('b4k1-synthetic-validation', $handshake->getContent());
        $this->assertStringStartsWith('text/plain', (string) $handshake->headers->get('Content-Type'));

        $empty = $this->postJson('/api/webhooks/graph/mail', ['value' => []]);
        $empty->assertStatus(200);
        $this->assertSame(['status' => 'ok'], $empty->json());

        $this->assertAccepted($this->postJson('/api/webhooks/graph/mail', ['value' => [[
            'clientState' => 'not-the-stored-state', 'resource' => self::resource(),
        ]]]));
        $this->assertSame([], $this->history, 'a mismatched clientState makes no Graph request');
        $this->assertSame(
            [['warning', '[GraphWebhook] clientState mismatch, skipping notification', []]],
            $this->webhookRecords(),
        );
    }

    /**
     * Positive control (#5733/#5737): the scan goes red on the record shape #5731 removed and
     * on a Throwable in context, two plants json_encode cannot see.
     */
    public function test_the_needle_scan_fires_on_the_old_shape_and_a_throwable_in_context(): void
    {
        $old = ['resource' => self::resource(), 'error' => 'Graph API error: GET returned 404'];
        $this->assertSame([self::MAILBOX, 'support@', 'users/', 'MSG-1', 'Graph API error'], self::carried(self::flattenForScan($old)));
        $this->assertNotContains('users/', self::carried((string) json_encode($old)), 'json_encode escapes the slash');

        $thrown = ['error' => new \RuntimeException(self::IMPORT_ERROR_TEXT, 0, new \LogicException(self::MAILBOX))];
        $this->assertSame([self::MAILBOX, 'support@', self::IMPORT_ERROR_TEXT], self::carried(self::flattenForScan($thrown)));
        $this->assertSame([], self::carried((string) json_encode($thrown)), 'json_encode renders a Throwable as {}');

        // #5779: through recordsCarryingANeedle() itself, with exact needle lists, so a helper
        // that went back to json_encode() fails here: it would miss 'users/' in $old and the
        // whole Throwable plant.
        $this->logged = [
            new MessageLogged('error', '[GraphWebhook] Failed to fetch message', $old),
            new MessageLogged('error', 'Graph probe', $thrown),
        ];
        $this->assertSame([
            ['error [GraphWebhook] Failed to fetch message', [self::MAILBOX, 'support@', 'users/', 'MSG-1', 'Graph API error']],
            ['error Graph probe', [self::MAILBOX, 'support@', self::IMPORT_ERROR_TEXT]],
        ], $this->recordsCarryingANeedle(), 'the planted records turn the scan red');
    }

    /**
     * #5770 positive control: three objects the scan must read, one per branch of
     * flattenForScan()'s object walk.
     * - A PSR-7 Uri. It is JsonSerializable and its jsonSerialize() returns the whole URL, so it
     *   takes the jsonSerialize() branch, as in Monolog's NormalizerFormatter; the scan already
     *   read it before #5770 (#5841). It stays as a plant for the JsonSerializable branch.
     * - A Stringable that is not JsonSerializable and whose properties hold only an encoded form,
     *   so only the __toString() branch yields the needles.
     * - An object with only private properties, read by allProperties().
     * Before #5770 the last two flattened to [] and a needle inside them was missed.
     */
    public function test_the_needle_scan_reads_a_stringable_and_a_private_property_object(): void
    {
        $uri = new \GuzzleHttp\Psr7\Uri('https://graph.microsoft.com/v1.0/'.self::resource());
        $this->assertInstanceOf(\JsonSerializable::class, $uri, 'positive control: the Uri takes the jsonSerialize() branch');
        $this->assertSame((string) $uri, $uri->jsonSerialize(), 'positive control: its jsonSerialize() is the whole URL');
        $private = new class(self::resource())
        {
            public function __construct(private string $resource) {}
        };
        // Its properties hold the resource encoded, so only __toString() yields the needles.
        $stringable = new class(base64_encode(self::resource())) implements \Stringable
        {
            public function __construct(private string $encoded) {}

            public function __toString(): string
            {
                return (string) base64_decode($this->encoded);
            }
        };
        $this->assertNotInstanceOf(\JsonSerializable::class, $stringable, 'positive control: the Stringable takes the __toString() branch');
        $this->logged = [
            new MessageLogged('error', 'Graph probe', ['uri' => $uri]),
            new MessageLogged('error', 'Graph probe', ['held' => $private]),
            new MessageLogged('error', 'Graph probe', ['rendered' => $stringable]),
        ];
        $this->assertSame([
            ['error Graph probe', [self::MAILBOX, 'support@', 'users/', 'MSG-1']],
            ['error Graph probe', [self::MAILBOX, 'support@', 'users/', 'MSG-1']],
            ['error Graph probe', [self::MAILBOX, 'support@', 'users/', 'MSG-1']],
        ], $this->recordsCarryingANeedle());
        // And the line Laravel's formatter writes for the Uri does carry the URL.
        $formatter = (fn () => $this->formatter())->call(app('log'));
        $line = $formatter->format(new \Monolog\LogRecord(new \DateTimeImmutable, 'testing', \Monolog\Level::Error, 'Graph probe', ['uri' => $uri]));
        $this->assertStringContainsString(self::MAILBOX, str_replace('\\/', '/', $line), 'the log line prints the Uri');
    }
}
