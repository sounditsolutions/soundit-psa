<?php

namespace Tests\Feature\Email;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Email;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\AttachmentService;
use App\Services\EmailService;
use App\Services\Graph\GraphClient;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Forward-as-attachment (card gHRatMtd). Outlook's "Forward as attachment" reaches the
 * mailbox as a Graph #microsoft.graph.itemAttachment with no contentBytes; before this
 * change downloadEmailAttachments() skipped it silently, so a forwarded phishing sample
 * never reached the ticket.
 *
 * Fixtures follow the vendor's own examples in attachment-get (Microsoft Graph v1.0):
 * Example 1 (fileAttachment), Example 2 (itemAttachment: contentType null, no contentBytes),
 * Example 5 (referenceAttachment: name/size/contentType, no link), and Example 9 (the
 * $value of a message item is MIME text). Hosts are example.test, IPs RFC 5737.
 *
 * GraphClient is Guzzle, so the wire is a MockHandler (the GraphClientPathSafetyTest idiom);
 * Http::preventStrayRequests() additionally refuses any Laravel HTTP client call.
 */
class EmailItemAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private const MAILBOX = 'support@example.test';

    private const NOT_STORED = '[AttachmentService] Email attachment content not stored';

    /** Graph's error text in the failing fixtures; it must never reach a log record (C-56). */
    private const GRAPH_ERROR_TEXT = 'The specified object was not found in the store.';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /** Every record written through any configured log channel, at every level. */
    private TestHandler $logs;

    private const MIME = "From: Payroll Team <payroll@phish.example.test>\r\n"
        ."Return-Path: <bounce@phish.example.test>\r\n"
        ."Received: from mail.phish.example.test (192.0.2.10) by mx.example.test\r\n"
        ."Authentication-Results: mx.example.test; spf=fail smtp.mailfrom=phish.example.test\r\n"
        ."To: User <user@example.test>\r\n"
        ."Subject: Urgent: verify your payroll account\r\n"
        ."Content-Type: text/plain; charset=\"us-ascii\"\r\n"
        ."MIME-Version: 1.0\r\n\r\n"
        ."Click http://198.51.100.7/login to keep your pay.\r\n";

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        Setting::setValue('graph_mailbox', self::MAILBOX);
        $this->captureAllLogChannels();
    }

    /**
     * Point EVERY configured channel (the default stack included) at one TestHandler, so a record
     * is captured whether it goes through the facade's level methods, log()/write(), or a logger
     * returned by channel()/stack()/getLogger() (#5138). The handler's level is DEBUG, so every
     * level is kept, and the assertions read each record's level.
     */
    private function captureAllLogChannels(): void
    {
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

    /** A GraphClient whose wire is $responses in order, with a pre-seeded token. */
    private function graph(array $responses): GraphClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'test-token', 3600);

        $graph = new GraphClient([
            'tenant_id' => 'tenant',
            'client_id' => 'client',
            'client_secret' => 'secret',
            'request_timeout' => 15,
            'token_timeout' => 10,
            'handler' => $stack,
        ], $cache);
        $this->app->instance(GraphClient::class, $graph);

        return $graph;
    }

    /** The message read ($expand=attachments) carrying $attachments. */
    private function expandResponse(array $attachments): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'MSG-1',
            'attachments' => $attachments,
        ]));
    }

    private function itemAttachment(array $overrides = []): array
    {
        return $overrides + [
            '@odata.type' => '#microsoft.graph.itemAttachment',
            'id' => 'ATT-ITEM-1',
            'lastModifiedDateTime' => '2026-10-05T10:00:00Z',
            'name' => 'Urgent: verify your payroll account',
            'contentType' => null,
            'size' => 32005,
            'isInline' => false,
        ];
    }

    private function fileAttachment(): array
    {
        return [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'id' => 'ATT-FILE-1',
            'lastModifiedDateTime' => '2026-10-05T10:00:00Z',
            'name' => 'Invoice Template.docx',
            'contentType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => 13068,
            'isInline' => false,
            'contentId' => null,
            'contentLocation' => null,
            'contentBytes' => base64_encode('docx-bytes'),
        ];
    }

    private function referenceAttachment(): array
    {
        return [
            '@odata.type' => '#microsoft.graph.referenceAttachment',
            'id' => 'ATT-REF-1',
            'lastModifiedDateTime' => '2026-10-05T10:00:00Z',
            'name' => 'Sales Invoice Template.docx',
            'contentType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => 1060,
            'isInline' => true,
        ];
    }

    private function email(array $overrides = []): Email
    {
        return Email::create($overrides + [
            'graph_id' => 'MSG-1',
            'direction' => 'inbound',
            'from_address' => 'user@example.test',
            'from_name' => 'User',
            'subject' => 'FW: suspicious',
            'body_text' => 'Is this legit?',
            'received_at' => now(),
        ]);
    }

    private function ticket(): Ticket
    {
        $client = Client::create(['name' => 'Example Client']);

        return Ticket::create([
            'client_id' => $client->id,
            'subject' => 'Suspicious email',
            'type' => TicketType::Incident,
            'status' => TicketStatus::New,
            'priority' => TicketPriority::P3,
        ]);
    }

    private function valueRequests(): array
    {
        return array_values(array_filter(
            $this->history,
            fn ($h) => str_ends_with($h['request']->getUri()->getPath(), '/$value'),
        ));
    }

    /**
     * Reads the captured records (every channel, every level; see captureAllLogChannels):
     *  - exactly ONE record at WARNING or above was written, whatever its message or reason
     *    (#5153: one warning per attachment; #5144: no error record beside it);
     *  - that record is the not-stored WARNING for $attachmentId with $reason and ids only;
     *  - no record at any other level carries the not-stored message, with or without context
     *    and through any channel (#5138);
     *  - no record at any level carries the mailbox address or Graph's error text (C-56).
     *
     * @return array<string, mixed> the warning's context
     */
    private function assertNotStoredWarning(string $reason, string $attachmentId): array
    {
        $loud = $this->records(Level::Warning);
        $this->assertCount(1, $loud, 'records at WARNING or above: '.$this->describe($loud));
        $w = $loud[0];
        $this->assertSame(Level::Warning, $w->level);
        $this->assertSame(self::NOT_STORED, $w->message);
        $this->assertSame($reason, $w->context['reason'] ?? null);
        $this->assertSame($attachmentId, $w->context['attachment_id'] ?? null);
        $this->assertSame([], array_intersect(array_keys($w->context), ['name', 'subject', 'content', 'body', 'error', 'message']));

        $sameMessage = array_filter($this->records(), fn (LogRecord $r) => $r->message === self::NOT_STORED);
        $this->assertCount(1, $sameMessage, 'not-stored records at any level: '.$this->describe($sameMessage));

        $this->assertNoLeakInLogs();

        return $w->context;
    }

    /** No record at any level carries the mailbox (raw or encoded) or Graph's error text. */
    private function assertNoLeakInLogs(): void
    {
        foreach ($this->records() as $r) {
            $text = $r->message.' '.json_encode($r->context);
            foreach ([self::MAILBOX, rawurlencode(self::MAILBOX), self::GRAPH_ERROR_TEXT] as $needle) {
                $this->assertStringNotContainsString($needle, $text, "{$r->level->getName()} record leaks");
            }
        }
    }

    /** @param  array<int, LogRecord>  $records */
    private function describe(array $records): string
    {
        return json_encode(array_map(fn (LogRecord $r) => [$r->level->getName(), $r->message, $r->context['reason'] ?? null], array_values($records)));
    }

    public function test_item_attachment_is_stored_as_eml_through_value(): void
    {
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            new Response(200, [], self::MIME),
        ]);
        $email = $this->email();

        $stored = app(AttachmentService::class)->downloadEmailAttachments($email, $graph, self::MAILBOX);

        $this->assertCount(1, $stored);
        $a = $stored[0];
        $this->assertSame('message/rfc822', $a->mime_type);
        $this->assertSame('urgent-verify-your-payroll-account.eml', $a->filename);
        $this->assertSame(strlen(self::MIME), $a->size_bytes);
        $this->assertFalse($a->is_inline);
        $this->assertSame(self::MIME, Storage::disk('local')->get($a->storage_path));

        $value = $this->valueRequests();
        $this->assertCount(1, $value);
        $this->assertSame(
            '/v1.0/users/support%40example.test/messages/MSG-1/attachments/ATT-ITEM-1/$value',
            $value[0]['request']->getUri()->getPath(),
        );
        $this->assertSame('Bearer test-token', $value[0]['request']->getHeaderLine('Authorization'));
    }

    public function test_item_without_a_usable_name_is_named_forwarded_message(): void
    {
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment(['name' => '!!!'])]),
            new Response(200, [], self::MIME),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertSame('forwarded-message.eml', $stored[0]->filename);
    }

    public function test_item_on_linked_ticket_lands_on_the_note(): void
    {
        $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            new Response(200, [], self::MIME),
        ]);
        $ticket = $this->ticket();
        $email = $this->email();

        app(EmailService::class)->linkEmailToTicket($email, $ticket);

        $note = TicketNote::where('email_id', $email->id)->firstOrFail();
        $a = Attachment::where('attachable_type', TicketNote::class)->where('attachable_id', $note->id)->sole();
        $this->assertSame('message/rfc822', $a->mime_type);
        $this->assertStringEndsWith('.eml', $a->filename);
    }

    /** @return array<string, array{0: Response}> */
    public static function failingValueResponses(): array
    {
        return [
            'http-404' => [new Response(404, [], json_encode(['error' => ['code' => 'ErrorItemNotFound', 'message' => self::GRAPH_ERROR_TEXT]])), 404],
            'http-500' => [new Response(500, [], json_encode(['error' => ['code' => 'InternalServerError', 'message' => self::GRAPH_ERROR_TEXT]])), 500],
            'http-503' => [new Response(503, [], json_encode(['error' => ['code' => 'ServiceUnavailable', 'message' => self::GRAPH_ERROR_TEXT]])), 503],
        ];
    }

    #[DataProvider('failingValueResponses')]
    public function test_value_fetch_failure_warns_stores_nothing_and_email_still_ingests(Response $failure, int $status): void
    {
        $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            $failure,
        ]);
        $ticket = $this->ticket();
        $email = $this->email();

        app(EmailService::class)->linkEmailToTicket($email, $ticket);

        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
        $this->assertNotNull(TicketNote::where('email_id', $email->id)->first());
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $context = $this->assertNotStoredWarning('fetch_failed', 'ATT-ITEM-1');
        $this->assertSame($status, $context['status'] ?? null);
        $this->assertCount(1, $this->valueRequests());
    }

    public function test_item_declared_over_ceiling_is_refused_before_any_fetch(): void
    {
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment(['size' => AttachmentService::MAX_ITEM_ATTACHMENT_BYTES + 1])]),
            new Response(200, [], self::MIME),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertSame([], $stored);
        $this->assertSame([], $this->valueRequests());
        $this->assertSame(0, Attachment::count());
        $this->assertNotStoredWarning('over_size_ceiling', 'ATT-ITEM-1');
    }

    public function test_item_whose_returned_bytes_exceed_ceiling_is_refused(): void
    {
        // Graph's declared size is under the ceiling; the bytes it returns are not.
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment(['size' => 1000])]),
            new Response(200, [], str_repeat('a', AttachmentService::MAX_ITEM_ATTACHMENT_BYTES + 1)),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertSame([], $stored);
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNotStoredWarning('over_size_ceiling', 'ATT-ITEM-1');
    }

    public function test_item_exactly_at_ceiling_is_stored(): void
    {
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment(['size' => AttachmentService::MAX_ITEM_ATTACHMENT_BYTES])]),
            new Response(200, [], str_repeat('a', AttachmentService::MAX_ITEM_ATTACHMENT_BYTES)),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertCount(1, $stored);
    }

    public function test_reference_attachment_records_a_named_placeholder_and_is_not_fetched(): void
    {
        $graph = $this->graph([$this->expandResponse([$this->referenceAttachment()])]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertCount(1, $stored);
        $a = $stored[0];
        $this->assertSame('text/plain', $a->mime_type);
        $this->assertSame('sales-invoice-templatedocx-linked-file.txt', $a->filename);
        $this->assertSame(
            "Linked cloud attachment (not downloaded): Sales Invoice Template.docx\n",
            Storage::disk('local')->get($a->storage_path),
        );
        $this->assertSame([], $this->valueRequests());
        $this->assertNotStoredWarning('reference_not_downloaded', 'ATT-REF-1');
    }

    public function test_file_attachment_behaves_as_before_and_does_not_warn(): void
    {
        $graph = $this->graph([$this->expandResponse([$this->fileAttachment()])]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertCount(1, $stored);
        $a = $stored[0];
        $this->assertSame('invoice-template.docx', $a->filename);
        $this->assertSame('Invoice Template.docx', $a->original_filename);
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $a->mime_type);
        $this->assertSame('docx-bytes', Storage::disk('local')->get($a->storage_path));
        $this->assertSame([], $this->valueRequests());
        $this->assertSame([], $this->records(Level::Warning), $this->describe($this->records(Level::Warning)));
    }

    public function test_mixed_message_keeps_every_kind(): void
    {
        $graph = $this->graph([
            $this->expandResponse([$this->fileAttachment(), $this->itemAttachment(), $this->referenceAttachment()]),
            new Response(200, [], self::MIME),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertSame(
            ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'message/rfc822', 'text/plain'],
            array_map(fn ($a) => $a->mime_type, $stored),
        );
    }

    public function test_redelivery_of_a_ticketed_email_does_not_store_the_item_twice(): void
    {
        // A second full download is queued too, so a re-download WOULD store a duplicate
        // (an empty queue would throw inside the caught attachment read and hide it).
        $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            new Response(200, [], self::MIME),
            $this->expandResponse([$this->itemAttachment()]),
            new Response(200, [], self::MIME),
        ]);
        $ticket = $this->ticket();
        // The subject token makes the ticket match, so only the already-ticketed guard
        // stands between a redelivery and a second download.
        $email = $this->email([
            'internet_message_id' => '<m1@example.test>',
            'subject' => "FW: suspicious [{$ticket->display_id}]",
        ]);
        app(EmailService::class)->linkEmailToTicket($email, $ticket);
        $this->assertSame(1, Attachment::count());

        // Redelivery: the webhook path re-imports the same internet_message_id.
        $again = app(EmailService::class)->importSingleMessage([
            'id' => 'MSG-1',
            'internetMessageId' => '<m1@example.test>',
            'from' => ['emailAddress' => ['address' => 'user@example.test', 'name' => 'User']],
            'subject' => "FW: suspicious [{$ticket->display_id}]",
            'body' => ['content' => '<p>Is this legit?</p>'],
            'hasAttachments' => true,
            'receivedDateTime' => now()->toIso8601String(),
        ]);
        // And the poll path's retry branch sees an already-ticketed row.
        app(EmailService::class)->processInbound($email->fresh());

        $this->assertSame($email->id, $again->id);
        $this->assertSame(1, Email::count());
        $this->assertSame(1, Attachment::count());
        $this->assertCount(1, $this->valueRequests());
    }

    // ── #5143: the new-ticket path fetches and warns once ──

    /** @return array<string, array{0: array<string, mixed>, 1: list<Response>, 2: string, 3: int}> */
    public static function newTicketSoleItemFailures(): array
    {
        return [
            // A second $value answer is queued, so a re-download WOULD be observed as a second
            // request (an empty queue would throw inside the caught read and hide nothing).
            'http-503' => [
                [],
                [
                    new Response(503, [], json_encode(['error' => ['code' => 'ServiceUnavailable', 'message' => self::GRAPH_ERROR_TEXT]])),
                    new Response(503, [], json_encode(['error' => ['code' => 'ServiceUnavailable', 'message' => self::GRAPH_ERROR_TEXT]])),
                ],
                'fetch_failed',
                1,
            ],
            // Declared over the ceiling: refused before any $value request, on each download.
            'oversize' => [
                ['size' => AttachmentService::MAX_ITEM_ATTACHMENT_BYTES + 1],
                [],
                'over_size_ceiling',
                0,
            ],
        ];
    }

    #[DataProvider('newTicketSoleItemFailures')]
    public function test_new_ticket_path_fetches_and_warns_once_for_a_sole_refused_item(
        array $itemOverrides, array $valueResponses, string $reason, int $expectedValueRequests,
    ): void {
        $item = $this->itemAttachment($itemOverrides);
        // Two message reads are queued: a second downloadEmailAttachments() would consume the
        // second one and is counted below, so a re-download cannot hide behind an empty queue.
        $this->graph(array_merge(
            [$this->expandResponse([$item])],
            $valueResponses === [] ? [] : [$valueResponses[0]],
            [$this->expandResponse([$item])],
            $valueResponses === [] ? [] : [$valueResponses[1]],
        ));
        $client = Client::create(['name' => 'Example Client']);
        $email = $this->email(['client_id' => $client->id]);

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
        $this->assertNotNull(TicketNote::where('email_id', $email->id)->first());
        $this->assertSame(0, Attachment::count());
        $messageReads = array_filter(
            $this->history,
            fn ($h) => str_ends_with($h['request']->getUri()->getPath(), '/messages/MSG-1'),
        );
        $this->assertCount(1, $messageReads, 'message read count');
        $this->assertCount($expectedValueRequests, $this->valueRequests());
        $this->assertNotStoredWarning($reason, 'ATT-ITEM-1');
    }

    public function test_new_ticket_path_still_links_a_stored_item_to_ticket_and_note(): void
    {
        $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            new Response(200, [], self::MIME),
            $this->expandResponse([$this->itemAttachment()]),
            new Response(200, [], self::MIME),
        ]);
        $client = Client::create(['name' => 'Example Client']);
        $email = $this->email(['client_id' => $client->id]);

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $note = TicketNote::where('email_id', $email->id)->firstOrFail();
        $a = Attachment::sole();
        $this->assertSame('message/rfc822', $a->mime_type);
        $this->assertSame([TicketNote::class, $note->id], [$a->attachable_type, $a->attachable_id]);
        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
        $this->assertCount(1, $this->valueRequests());
    }

    public function test_link_without_predownloaded_attachments_still_downloads(): void
    {
        // Every other caller passes nothing (null): the link path keeps downloading itself.
        $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            new Response(200, [], self::MIME),
        ]);
        $email = $this->email();

        app(EmailService::class)->linkEmailToTicket($email, $this->ticket());

        $this->assertSame(1, Attachment::count());
        $this->assertCount(1, $this->valueRequests());
    }

    public function test_link_with_an_explicit_empty_predownload_does_not_fetch(): void
    {
        // An empty array means the caller already attempted the download; nothing is fetched.
        $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            new Response(200, [], self::MIME),
        ]);
        $email = $this->email();

        app(EmailService::class)->linkEmailToTicket($email, $this->ticket(), []);

        $this->assertSame(0, Attachment::count());
        $this->assertSame([], $this->history);
        $this->assertNotNull(TicketNote::where('email_id', $email->id)->first());
    }

    // ── #5136: status is an HTTP status only when Graph answered ──

    public function test_connect_failure_reports_no_http_status_and_no_fetch_failed_claim(): void
    {
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment()]),
            fn ($request) => throw new ConnectException('cURL error 28: timed out after 15000 ms', $request),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertSame([], $stored);
        $context = $this->assertNotStoredWarning('status_unknown', 'ATT-ITEM-1');
        $this->assertArrayHasKey('status', $context);
        $this->assertNull($context['status']);
        $this->assertSame(\App\Services\Graph\GraphClientException::class, $context['exception'] ?? null);
        foreach ($this->records() as $r) {
            $this->assertStringNotContainsString('timed out', $r->message.' '.json_encode($r->context));
        }
    }

    public function test_non_graph_throwable_reports_its_class_not_its_code(): void
    {
        // A Throwable that is not a GraphClientException carries an arbitrary code; it is
        // not an HTTP status and must not be reported as one.
        $graph = \Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getMessageAttachments')->andReturn([$this->itemAttachment()]);
        $graph->shouldReceive('getMessageAttachmentRaw')->andThrow(new \RuntimeException('boom', 503));

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertSame([], $stored);
        $context = $this->assertNotStoredWarning('status_unknown', 'ATT-ITEM-1');
        $this->assertNull($context['status']);
        $this->assertSame(\RuntimeException::class, $context['exception'] ?? null);
    }

    // ── #5140: the slash replacement keeps a word separator; no path survives either way ──

    /** @return array<string, array{0: string, 1: string}> */
    public static function itemNamesWithSeparators(): array
    {
        return [
            'slash' => ['Q1/Q2 results', 'q1-q2-results.eml'],
            'backslash' => ['Q1\\Q2 results', 'q1-q2-results.eml'],
            'path-like' => ['../../etc/passwd', 'etc-passwd.eml'],
        ];
    }

    #[DataProvider('itemNamesWithSeparators')]
    public function test_item_name_with_a_path_separator_keeps_every_part(string $name, string $expected): void
    {
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment(['name' => $name])]),
            new Response(200, [], self::MIME),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertSame($expected, $stored[0]->filename);
    }

    // ── #5138: the controls see a context-less record and a channel/stack record ──

    public function test_capture_sees_contextless_and_channel_records_at_their_level(): void
    {
        // Positive control for captureAllLogChannels(): each write shape the old per-signature
        // spy missed must land here with its own level, or the absence assertions prove nothing.
        Log::error(self::NOT_STORED);
        Log::channel('stack')->error(self::NOT_STORED, ['reason' => 'x']);
        Log::stack(['single'])->critical(self::NOT_STORED);
        Log::write('notice', self::NOT_STORED);
        Log::getLogger()->debug(self::NOT_STORED);

        $this->assertSame(
            ['ERROR', 'ERROR', 'CRITICAL', 'NOTICE', 'DEBUG'],
            array_map(fn (LogRecord $r) => $r->level->getName(), $this->records()),
        );
    }
}
