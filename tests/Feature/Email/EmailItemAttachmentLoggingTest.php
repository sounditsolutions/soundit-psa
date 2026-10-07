<?php

namespace Tests\Feature\Email;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Email;
use App\Models\Setting;
use App\Models\TicketNote;
use App\Services\AttachmentService;
use App\Services\EmailService;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * #5388 residuals, b4a (card 6ac5994e): what the item $value read and the new-ticket path log
 * and retry.
 *  - #5391: the 429 backoff record never carries the mailbox.
 *  - #5397: a token failure's record and exception message carry no vendor text.
 *  - #5398: a Graph 401 whose token refresh fails is reported as Graph's 401.
 *  - #5399: a failure with no HTTP status is reported as status_unknown.
 *  - #5394: a failed MESSAGE read on the new-ticket path is read once more; a failed or
 *    oversize ITEM is still fetched once (#5143).
 *
 * Synthetic data only (example.test, MSG-1 style ids). GraphClient is Guzzle, so the wire is
 * a MockHandler shared by the Graph and token clients; Http::preventStrayRequests() refuses
 * any Laravel HTTP client call. Records are captured from every configured channel at every
 * level, as in EmailItemAttachmentTest.
 */
class EmailItemAttachmentLoggingTest extends TestCase
{
    use RefreshDatabase;

    private const MAILBOX = 'support@example.test';

    private const TENANT = 'tenant-b4a-synthetic';

    private const NOT_STORED = '[AttachmentService] Email attachment content not stored';

    /** The identity provider's error text in the failing token fixture; never logged (C-56). */
    private const IDP_ERROR_TEXT = 'AADSTS7000215-B4A synthetic invalid client secret provided';

    private const MIME = "From: Sender <sender@phish.example.test>\r\n"
        ."To: User <user@example.test>\r\n"
        ."Subject: Synthetic forwarded item\r\n"
        ."Content-Type: text/plain; charset=\"us-ascii\"\r\n"
        ."MIME-Version: 1.0\r\n\r\n"
        ."Synthetic body.\r\n";

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        Setting::setValue('graph_mailbox', self::MAILBOX);

        $this->logs = new TestHandler;
        $handler = $this->logs;
        foreach (array_keys(config('logging.channels')) as $name) {
            config(["logging.channels.{$name}" => [
                'driver' => 'custom',
                'via' => fn () => new \Monolog\Logger($name, [$handler]),
            ]]);
            Log::forgetChannel($name);
        }
    }

    /** @return list<LogRecord> */
    private function records(?Level $atLeast = null): array
    {
        return array_values(array_filter(
            $this->logs->getRecords(),
            fn (LogRecord $r) => $atLeast === null || $r->level->value >= $atLeast->value,
        ));
    }

    private function text(LogRecord $r): string
    {
        return $r->level->getName().' '.$r->message.' '.json_encode($r->context).' '.json_encode($r->extra);
    }

    /** A GraphClient on $responses in order; the token is pre-seeded unless $seedToken is false. */
    private function graph(array $responses, bool $seedToken = true): GraphClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $cache = new Repository(new ArrayStore);
        if ($seedToken) {
            $cache->put('graph_api_token', 'test-token', 3600);
        }

        $graph = new GraphClient([
            'tenant_id' => self::TENANT,
            'client_id' => 'client',
            'client_secret' => 'secret',
            'request_timeout' => 15,
            'token_timeout' => 10,
            'handler' => $stack,
        ], $cache);
        $this->app->instance(GraphClient::class, $graph);

        return $graph;
    }

    private function expandResponse(array $attachments): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'MSG-1',
            'attachments' => $attachments,
        ]));
    }

    private function itemAttachment(array $overrides = []): array
    {
        return $overrides + [
            '@odata.type' => '#microsoft.graph.itemAttachment',
            'id' => 'ATT-ITEM-1',
            'name' => 'Synthetic forwarded item',
            'contentType' => null,
            'size' => 32005,
            'isInline' => false,
        ];
    }

    /** The token endpoint refusing, with the identity provider's error body. */
    private function tokenRefusal(): Response
    {
        return new Response(400, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => 'invalid_client',
            'error_description' => self::IDP_ERROR_TEXT,
        ]));
    }

    private function email(array $overrides = []): Email
    {
        return Email::create($overrides + [
            'graph_id' => 'MSG-1',
            'direction' => 'inbound',
            'from_address' => 'user@example.test',
            'from_name' => 'User',
            'subject' => 'FW: synthetic',
            'body_text' => 'Is this legit?',
            'received_at' => now(),
        ]);
    }

    /** @return list<\Psr\Http\Message\RequestInterface> */
    private function requestsEndingWith(string $suffix): array
    {
        return array_values(array_map(
            fn ($h) => $h['request'],
            array_filter($this->history, fn ($h) => str_ends_with($h['request']->getUri()->getPath(), $suffix)),
        ));
    }

    /** No record at any level carries any of $needles in its message, context or extra. */
    private function assertNoRecordContains(array $needles): void
    {
        $this->assertNotEmpty($this->records(), 'positive control: the path under test wrote no record at all');
        foreach ($this->records() as $r) {
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $this->text($r), "{$r->level->getName()} record leaks");
            }
        }
    }

    /** @return list<LogRecord> */
    private function withMessage(string $message): array
    {
        return array_values(array_filter($this->records(), fn (LogRecord $r) => $r->message === $message));
    }

    // ── #5391: the 429 backoff record never carries the mailbox ──

    public function test_item_read_429_backoff_record_carries_no_mailbox(): void
    {
        // Retry-After 1 keeps the real sleep() in the backoff to one second.
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            new Response(429, ['Retry-After' => '1'], '{}'),
            new Response(200, [], self::MIME),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertCount(1, $stored, 'the retried item read is stored');
        $this->assertCount(2, $this->requestsEndingWith('/$value'));
        $backoff = $this->withMessage('[GraphClient] Rate limited, backing off');
        $this->assertCount(1, $backoff, 'positive control: the 429 branch ran and logged');
        $this->assertSame(Level::Warning, $backoff[0]->level);
        $this->assertSame(
            'users/{redacted}/messages/MSG-1/attachments/ATT-ITEM-1/$value',
            $backoff[0]->context['endpoint'] ?? null,
        );
        $this->assertSame(1, $backoff[0]->context['attempt'] ?? null);
        $this->assertNoRecordContains([self::MAILBOX, rawurlencode(self::MAILBOX), 'support%40', 'support@']);
    }

    public function test_message_read_429_backoff_redacts_an_unencoded_mailbox_too(): void
    {
        // getMessageAttachments interpolates the mailbox without seg(); the redaction covers it.
        $graph = $this->graph([
            new Response(429, ['Retry-After' => '1'], '{}'),
            $this->expandResponse([]),
        ]);

        $this->assertSame([], $graph->getMessageAttachments(self::MAILBOX, 'MSG-1'));

        $backoff = $this->withMessage('[GraphClient] Rate limited, backing off');
        $this->assertCount(1, $backoff, 'positive control: the 429 branch ran and logged');
        $this->assertSame('users/{redacted}/messages/MSG-1', $backoff[0]->context['endpoint'] ?? null);
        $this->assertNoRecordContains([self::MAILBOX, rawurlencode(self::MAILBOX)]);
    }

    // ── #5397: a token failure logs and throws no vendor text ──

    public function test_token_failure_record_and_exception_carry_no_vendor_text(): void
    {
        $graph = $this->graph([$this->tokenRefusal()], seedToken: false);

        $thrown = null;
        try {
            $graph->getMessageAttachmentRaw(self::MAILBOX, 'MSG-1', 'ATT-ITEM-1');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(GraphClientException::class, $thrown);
        $this->assertSame(0, $thrown->getHttpStatus(), 'Graph itself was never reached');
        foreach ([self::IDP_ERROR_TEXT, 'AADSTS', 'invalid_client', self::TENANT, 'login.microsoftonline.com'] as $needle) {
            $this->assertStringNotContainsString($needle, $thrown->getMessage());
        }
        $this->assertCount(1, $this->requestsEndingWith('/oauth2/v2.0/token'), 'positive control: the token leg ran');

        $tokenRecords = $this->withMessage('Graph API token request failed');
        $this->assertCount(1, $tokenRecords);
        $this->assertSame(Level::Error, $tokenRecords[0]->level);
        $this->assertSame(['status' => 400, 'exception' => \GuzzleHttp\Exception\ClientException::class], $tokenRecords[0]->context);
        $this->assertNoRecordContains([self::IDP_ERROR_TEXT, 'AADSTS', 'invalid_client', self::TENANT, self::MAILBOX, rawurlencode(self::MAILBOX)]);
    }

    public function test_token_connect_failure_records_class_and_null_status(): void
    {
        $graph = $this->graph([
            fn ($request) => throw new ConnectException('cURL error 6: could not resolve '.self::TENANT, $request),
        ], seedToken: false);

        try {
            $graph->getMessageAttachmentRaw(self::MAILBOX, 'MSG-1', 'ATT-ITEM-1');
            $this->fail('expected a GraphClientException');
        } catch (GraphClientException $e) {
            $this->assertStringNotContainsString(self::TENANT, $e->getMessage());
            $this->assertStringNotContainsString('cURL', $e->getMessage());
        }

        $tokenRecords = $this->withMessage('Graph API token request failed');
        $this->assertCount(1, $tokenRecords);
        $this->assertSame(['status' => null, 'exception' => ConnectException::class], $tokenRecords[0]->context);
        $this->assertNoRecordContains([self::TENANT, 'cURL', self::MAILBOX]);
    }

    // ── #5398: a 401 whose token refresh fails is reported as Graph's 401 ──

    public function test_item_401_then_failed_refresh_is_reported_as_fetch_failed_401(): void
    {
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            new Response(401, [], (string) json_encode(['error' => ['code' => 'InvalidAuthenticationToken']])),
            $this->tokenRefusal(),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertSame([], $stored);
        $this->assertCount(1, $this->requestsEndingWith('/$value'));
        $this->assertCount(1, $this->requestsEndingWith('/oauth2/v2.0/token'), 'positive control: the refresh ran');

        $notStored = $this->withMessage(self::NOT_STORED);
        $this->assertCount(1, $notStored);
        $this->assertSame(Level::Warning, $notStored[0]->level);
        $this->assertSame('fetch_failed', $notStored[0]->context['reason'] ?? null);
        $this->assertSame(401, $notStored[0]->context['status'] ?? null);
        $this->assertArrayNotHasKey('exception', $notStored[0]->context);

        // The only other loud record is the refresh's own status-only token ERROR; the item
        // read's failure itself writes no error record (#5144).
        $loud = array_map(fn (LogRecord $r) => [$r->level->getName(), $r->message], $this->records(Level::Warning));
        $this->assertEqualsCanonicalizing([
            ['ERROR', 'Graph API token request failed'],
            ['WARNING', self::NOT_STORED],
        ], $loud);
        $this->assertNoRecordContains([self::IDP_ERROR_TEXT, self::TENANT, self::MAILBOX, rawurlencode(self::MAILBOX)]);
    }

    public function test_graph_call_401_then_failed_refresh_throws_graphs_401(): void
    {
        $graph = $this->graph([
            new Response(401, [], '{}'),
            $this->tokenRefusal(),
        ]);

        try {
            $graph->get('organization');
            $this->fail('expected a GraphClientException');
        } catch (GraphClientException $e) {
            $this->assertSame(401, $e->getHttpStatus());
        }
    }

    // ── #5399: no HTTP status is reported as status_unknown on every arm ──

    public function test_connect_failure_on_item_read_reports_status_unknown(): void
    {
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            fn ($request) => throw new ConnectException('cURL error 28: timed out after 15000 ms', $request),
        ]);

        app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $notStored = $this->withMessage(self::NOT_STORED);
        $this->assertCount(1, $notStored);
        $this->assertSame('status_unknown', $notStored[0]->context['reason'] ?? null);
        $this->assertNull($notStored[0]->context['status']);
        $this->assertSame(GraphClientException::class, $notStored[0]->context['exception'] ?? null);
    }

    public function test_non_graph_throwable_on_item_read_reports_status_unknown(): void
    {
        $graph = \Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getMessageAttachments')->andReturn([$this->itemAttachment()]);
        $graph->shouldReceive('getMessageAttachmentRaw')->andThrow(new \RuntimeException('boom-b4a', 503));

        app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $notStored = $this->withMessage(self::NOT_STORED);
        $this->assertCount(1, $notStored);
        $this->assertSame('status_unknown', $notStored[0]->context['reason'] ?? null);
        $this->assertNull($notStored[0]->context['status']);
        $this->assertSame(\RuntimeException::class, $notStored[0]->context['exception'] ?? null);
        $this->assertNoRecordContains(['boom-b4a']);
    }

    // ── #5394: a failed message read is read once more; a failed item is not ──

    public function test_failed_message_read_returns_null_not_an_empty_list(): void
    {
        $graph = $this->graph([new Response(503, [], '{}')]);

        $this->assertNull(app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX));
    }

    public function test_message_with_no_attachments_returns_an_empty_list(): void
    {
        $graph = $this->graph([$this->expandResponse([])]);

        $this->assertSame([], app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX));
    }

    public function test_new_ticket_path_reads_the_message_again_after_a_failed_read(): void
    {
        $this->graph([
            new Response(503, [], '{}'),
            $this->expandResponse([$this->itemAttachment()]),
            new Response(200, [], self::MIME),
        ]);
        $client = Client::create(['name' => 'Example Client']);
        $email = $this->email(['client_id' => $client->id]);

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertCount(2, $this->requestsEndingWith('/messages/MSG-1'), 'message read count');
        $this->assertCount(1, $this->requestsEndingWith('/$value'));
        $note = TicketNote::where('email_id', $email->id)->firstOrFail();
        $a = Attachment::sole();
        $this->assertSame('message/rfc822', $a->mime_type);
        $this->assertSame([TicketNote::class, $note->id], [$a->attachable_type, $a->attachable_id]);
        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: list<Response>, 2: int}> */
    public static function soleItemFailures(): array
    {
        $fail = fn () => new Response(503, [], '{}');

        return [
            // A second $value answer is queued, so a re-fetch would be seen as a second request.
            'http-503' => [[], [$fail(), $fail()], 1],
            'oversize' => [['size' => AttachmentService::MAX_ITEM_ATTACHMENT_BYTES + 1], [], 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('soleItemFailures')]
    public function test_new_ticket_path_still_does_not_refetch_a_failed_or_oversize_item(
        array $itemOverrides, array $valueResponses, int $expectedValueRequests,
    ): void {
        $item = $this->itemAttachment($itemOverrides);
        // A second message read is queued too, so a re-download cannot hide behind an empty queue.
        $this->graph(array_merge(
            [$this->expandResponse([$item])],
            array_slice($valueResponses, 0, 1),
            [$this->expandResponse([$item])],
            array_slice($valueResponses, 1, 1),
        ));
        $client = Client::create(['name' => 'Example Client']);
        $email = $this->email(['client_id' => $client->id]);

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertCount(1, $this->requestsEndingWith('/messages/MSG-1'), 'message read count');
        $this->assertCount($expectedValueRequests, $this->requestsEndingWith('/$value'));
        $this->assertSame(0, Attachment::count());
        $this->assertCount(1, $this->withMessage(self::NOT_STORED));
    }
}
