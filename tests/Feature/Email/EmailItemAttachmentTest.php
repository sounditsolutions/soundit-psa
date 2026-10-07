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

    /** A non-Graph Throwable's message; it must not reach a log record either (C-56, #5400). */
    private const NON_GRAPH_DETAIL = 'non-graph failure detail MSG-1';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /**
     * Every record the swapped Log manager resolves a logger for, at every level: configured
     * channels, the default stack, stack(), Log::build() on-demand loggers and unconfigured
     * channel names (the emergency logger). The limits are listed on captureAllLogChannels().
     */
    private TestHandler $logs;

    /**
     * Real files a broken capture would write: the on-demand logger the controls build and the
     * manager's emergency logger. Per process, so a parallel runner cannot see another's file;
     * removed in tearDown() whatever the outcome (#5539, #5540).
     *
     * @var array{ondemand: string, emergency: string}
     */
    private array $strayLogPaths;

    /** zend.exception_ignore_args before setUp() pinned it; restored in tearDown(). */
    private string|false $exceptionIgnoreArgs = false;

    /**
     * Base Throwable slots that walk() does not read through the (array) cast (#5542): message,
     * code and the previous chain are read through their getters, file and line carry no data,
     * and the trace (whose frames carry call arguments when zend.exception_ignore_args is Off)
     * and the cached string form are not printed by any formatter in render().
     */
    private const THROWABLE_BASE_SLOTS = [
        "\0*\0message" => true, "\0*\0code" => true, "\0*\0file" => true, "\0*\0line" => true,
        "\0Exception\0string" => true, "\0Exception\0trace" => true, "\0Exception\0previous" => true,
        "\0Error\0string" => true, "\0Error\0trace" => true, "\0Error\0previous" => true,
    ];

    /** Deepest nesting walk() reads; anything deeper fails the scan instead of passing it (#5536). */
    private const WALK_MAX_DEPTH = 12;

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
        // #5542: production php.ini sets this On (no call arguments in traces); pin it so the
        // scan does not depend on the runner's ini.
        $this->exceptionIgnoreArgs = ini_set('zend.exception_ignore_args', '1');
        $this->strayLogPaths = [
            'ondemand' => storage_path('logs/email-item-attachment-capture-ondemand-'.getmypid().'.log'),
            'emergency' => storage_path('logs/email-item-attachment-capture-emergency-'.getmypid().'.log'),
        ];
        foreach ($this->strayLogPaths as $path) {
            @unlink($path);
        }
        $this->captureAllLogChannels();
    }

    protected function tearDown(): void
    {
        foreach ($this->strayLogPaths ?? [] as $path) {
            @unlink($path);
        }
        if ($this->exceptionIgnoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->exceptionIgnoreArgs);
        }
        parent::tearDown();
        // #5540: Laravel clears resolved facades only in the NEXT Laravel test's setUp; a plain
        // TestCase running after this class would otherwise resolve the swapped manager.
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('log');
    }

    /**
     * Send the records of every logger the swapped Log manager resolves to one TestHandler
     * (#5138, #5402):
     *  - every configured channel (the default stack included) is rewritten, for this test only,
     *    to a custom driver whose only handler is the TestHandler, and the emergency path is
     *    pinned to a per-process file that tearDown() removes;
     *  - the Log facade and the container's 'log' instance are swapped to a LogManager whose
     *    get(), createEmergencyLogger() and stack() give each resolved Monolog logger the
     *    TestHandler as its only handler. That covers what the config rewrite cannot reach: an
     *    on-demand Log::build([...]) logger, a Log::channel() name missing from
     *    config/logging.php (the manager's emergency logger, together with its own
     *    'Unable to create configured logger' record), and the stack() of either;
     *  - the swapped manager starts from the original's shared context and Log::extend()
     *    creators, so a Log::shareContext() made before setUp() still reaches every record
     *    (#5532);
     *  - a resolved logger that is not Monolog (a custom 'via' returning another PSR-3 logger)
     *    cannot be given the handler, so captured() fails the test instead of passing it
     *    through uncaptured (#5531).
     * Not captured (#5530): a logger the code builds itself, outside the Log manager
     * (new Monolog\Logger(...)); any object that took the ORIGINAL manager, or a logger from it,
     * before this method ran (for example a singleton built at boot with LoggerInterface or
     * LogManager injected): its configured channels reach the TestHandler through the config
     * rewrite, but its build(), stack(), unconfigured-channel and emergency loggers keep their
     * real handlers; and a handler pushed onto a resolved logger after resolution, which can
     * see a record first and stop it with bubble=false. What is driven is listed in
     * test_capture_sees_every_write_shape_at_its_level; the handler's level is DEBUG, so every
     * level is kept and the assertions read each record's level.
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
        config(['logging.channels.emergency' => ['path' => $this->strayLogPaths['emergency']]]);

        $original = Log::getFacadeRoot();
        Log::swap(new class($this->app, $handler, $original) extends \Illuminate\Log\LogManager
        {
            public function __construct($app, private readonly TestHandler $capture, \Illuminate\Log\LogManager $original)
            {
                parent::__construct($app);
                $this->sharedContext = $original->sharedContext();
                $this->customCreators = (fn () => $this->customCreators)->call($original);
            }

            protected function get($name, ?array $config = null)
            {
                return $this->captured(parent::get($name, $config));
            }

            protected function createEmergencyLogger()
            {
                return $this->captured(parent::createEmergencyLogger());
            }

            // One handler, not one per stacked channel: a record is captured exactly once.
            public function stack(array $channels, $channel = null)
            {
                return $this->captured(parent::stack($channels, $channel));
            }

            private function captured($logger)
            {
                $monolog = $logger instanceof \Illuminate\Log\Logger ? $logger->getLogger() : $logger;
                if (! $monolog instanceof \Monolog\Logger) {
                    throw new \LogicException('captureAllLogChannels cannot capture a '.get_debug_type($monolog).' logger');
                }
                if ($monolog->getHandlers() !== [$this->capture]) {
                    $monolog->setHandlers([$this->capture]);
                }

                return $logger;
            }
        });
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
     *  - no record at any level carries a forbiddenNeedles() needle (C-56; assertNoLeakInLogs).
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

    /**
     * What no record may carry (C-56), by label. Matching is case-insensitive substring
     * (#5534), so a re-cased, truncated-at-the-end or embedded copy of a needle is caught; each
     * needle is chosen so that a partial copy of the secret still contains one:
     *  - the mailbox raw, its local part with '@', URL-encoded (whole and local part), double
     *    encoded and base64;
     *  - Graph's error text, its opening words (a Str::limit() cut keeps them) and Graph's error
     *    codes used by the fixtures (vendor text);
     *  - the message body (#5541): the item's name (which is the MIME Subject), MIME fragments
     *    (sender, sending IP, phishing URL) and the email's own subject and body.
     * Not covered: a re-encoding not listed here (hex, a hash, any other transform), a fragment
     * shorter than every needle, or the item name's slug: '[Attachment] Stored from content'
     * logs the stored filename ('urgent-verify-your-payroll-account.eml') today, so a slug
     * needle would fail on that record.
     *
     * @return array<string, string>
     */
    private static function forbiddenNeedles(): array
    {
        return [
            'mailbox' => self::MAILBOX,
            'mailbox local part' => 'support@',
            'mailbox urlencoded' => rawurlencode(self::MAILBOX),
            'mailbox local part urlencoded' => 'support%40',
            'mailbox double-urlencoded' => 'support%2540',
            'mailbox base64' => base64_encode(self::MAILBOX),
            'graph error text' => self::GRAPH_ERROR_TEXT,
            'graph error text prefix' => 'The specified object',
            'graph error code ErrorItemNotFound' => 'ErrorItemNotFound',
            'graph error code InternalServerError' => 'InternalServerError',
            'graph error code ServiceUnavailable' => 'ServiceUnavailable',
            'item name / MIME subject' => 'Urgent: verify your payroll account',
            'MIME sender' => 'payroll@phish.example.test',
            'MIME sending IP' => '192.0.2.10',
            'MIME phishing URL' => 'http://198.51.100.7/login',
            'email subject' => 'FW: suspicious',
            'email body' => 'Is this legit?',
        ];
    }

    /**
     * No record at any level carries a forbiddenNeedles() needle or any of $alsoAbsent (matched
     * the same way). Each record is read through renderParts(): the message, the context and
     * extra walked to walk()'s depth limit (deeper fails the scan; #5536), and Monolog's
     * LineFormatter output.
     *
     * @param  list<string>  $alsoAbsent
     */
    private function assertNoLeakInLogs(array $alsoAbsent = []): void
    {
        foreach ($this->records() as $r) {
            $hits = $this->leakHits($r, $alsoAbsent);
            $this->assertSame([], $hits, "{$r->level->getName()} record '{$r->message}' leaks: ".json_encode($hits));
        }
    }

    /**
     * Which needles each render part of $r carries: [needle label => [part, ...]].
     *
     * @param  list<string>  $alsoAbsent
     * @return array<string, list<string>>
     */
    private function leakHits(LogRecord $r, array $alsoAbsent = []): array
    {
        $needles = self::forbiddenNeedles();
        foreach ($alsoAbsent as $i => $needle) {
            $needles["caller needle {$i}"] = $needle;
        }
        $hits = [];
        foreach ($this->renderParts($r) as $part => $text) {
            foreach ($needles as $label => $needle) {
                if (stripos($text, $needle) !== false) {
                    $hits[$label][] = $part;
                }
            }
        }

        return $hits;
    }

    /**
     * Everything a log formatter could print for $r, by carrier:
     *  - message: the record's message;
     *  - context, extra: walk() of each (array keys and values; a Throwable's class, message,
     *    code, previous chain and its own non-base properties; JsonSerializable output;
     *    __toString; every property of any other object, private ones included), strings kept
     *    byte for byte so invalid UTF-8 cannot blank the text out;
     *  - formatter: Monolog's LineFormatter output for the record.
     * The planted-needle rows name the part that must see each carrier, so a carrier that only
     * one part reads is pinned to that part (#5535).
     *
     * @return array{message: string, context: string, extra: string, formatter: string}
     */
    private function renderParts(LogRecord $r): array
    {
        $seen = new \SplObjectStorage;

        return [
            'message' => $r->message,
            'context' => $this->walk($r->context, $seen),
            'extra' => $this->walk($r->extra, $seen),
            'formatter' => (new \Monolog\Formatter\LineFormatter(null, null, true))->format($r),
        ];
    }

    /**
     * Each array, object and its (array) cast is one level deeper, so an object costs two.
     * Past WALK_MAX_DEPTH the scan fails rather than returning nothing (#5536): a needle there
     * would be unread. A Throwable's base slots are read through their getters only, never the
     * private trace (#5542), so the result does not depend on frame arguments.
     */
    private function walk(mixed $value, \SplObjectStorage $seen, int $depth = 0): string
    {
        if ($depth > self::WALK_MAX_DEPTH) {
            $this->fail('walk() passed depth '.self::WALK_MAX_DEPTH.'; a needle below it would be unread, so the scan refuses');
        }
        if (is_string($value)) {
            return $value;
        }
        if ($value === null || is_scalar($value)) {
            return var_export($value, true);
        }
        if (is_array($value)) {
            $out = '';
            foreach ($value as $k => $v) {
                $out .= $k.'='.$this->walk($v, $seen, $depth + 1).' ';
            }

            return $out;
        }
        if (! is_object($value) || $seen->contains($value)) {
            return '';
        }
        $seen->attach($value);
        $out = $value::class.' ';
        if ($value instanceof \Throwable) {
            $out .= $value->getMessage().' '.$value->getCode().' '.$this->walk($value->getPrevious(), $seen, $depth + 1).' ';

            return $out.$this->walk(array_diff_key((array) $value, self::THROWABLE_BASE_SLOTS), $seen, $depth + 1);
        }
        if ($value instanceof \JsonSerializable) {
            $out .= $this->walk($value->jsonSerialize(), $seen, $depth + 1).' ';
        }
        if ($value instanceof \Stringable) {
            $out .= (string) $value.' ';
        }

        // (array) cast exposes private and protected properties too.
        return $out.$this->walk((array) $value, $seen, $depth + 1);
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
        // #5541: the stored item's name, MIME and the email's subject and body reach no record.
        $this->assertNotSame([], $this->records(), 'positive control: the store path logs');
        $this->assertNoLeakInLogs();
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

    /**
     * Each row is [the failing $value response, the HTTP status the not-stored WARNING must carry].
     *
     * @return array<string, array{0: Response, 1: int}>
     */
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
        $this->assertNoLeakInLogs();
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
        $this->assertNoLeakInLogs(['timed out']);
    }

    public function test_non_graph_throwable_reports_its_class_not_its_code(): void
    {
        // A Throwable that is not a GraphClientException carries an arbitrary code; it is
        // not an HTTP status and must not be reported as one.
        $graph = \Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getMessageAttachments')->andReturn([$this->itemAttachment()]);
        $graph->shouldReceive('getMessageAttachmentRaw')->andThrow(new \RuntimeException(self::NON_GRAPH_DETAIL, 503));

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertSame([], $stored);
        $context = $this->assertNotStoredWarning('status_unknown', 'ATT-ITEM-1');
        $this->assertNull($context['status']);
        $this->assertSame(\RuntimeException::class, $context['exception'] ?? null);
        // #5400: the exception's message reaches no record, under any context key, at any level.
        $this->assertNoLeakInLogs([self::NON_GRAPH_DETAIL]);
    }

    // ── #5140: the slash replacement keeps a word separator; no path survives either way ──

    /**
     * [item name, stored filename]. A separator between two words becomes '-', so both words
     * are kept; a '..' segment slugs to nothing and is dropped (#5401).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function itemNamesWithSeparators(): array
    {
        return [
            'slash' => ['Q1/Q2 results', 'q1-q2-results.eml'],
            'backslash' => ['Q1\\Q2 results', 'q1-q2-results.eml'],
            'path-like' => ['../../etc/passwd', 'etc-passwd.eml'],
        ];
    }

    #[DataProvider('itemNamesWithSeparators')]
    public function test_item_name_with_a_path_separator_keeps_its_words_and_drops_dot_segments(string $name, string $expected): void
    {
        $graph = $this->graph([
            $this->expandResponse([$this->itemAttachment(['name' => $name])]),
            new Response(200, [], self::MIME),
        ]);

        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX);

        $this->assertSame($expected, $stored[0]->filename);
        $this->assertStringNotContainsString('/', $stored[0]->filename);
        $this->assertStringNotContainsString('\\', $stored[0]->filename);
        $this->assertStringNotContainsString('..', $stored[0]->filename);
    }

    // ── #5138 / #5396 / #5400 / #5402 / #5538: the capture sees every write shape it claims ──

    public function test_capture_sees_every_write_shape_at_its_level(): void
    {
        // Positive control for captureAllLogChannels(): each shape must land here once, with its
        // own level, or the absence assertions prove nothing for it. The on-demand and emergency
        // loggers' files must stay unwritten: their real handlers were replaced, not added to.
        $onDemandPath = $this->strayLogPaths['ondemand'];

        Log::error('facade level method');
        Log::channel('stack')->error('configured channel', ['reason' => 'x']);
        Log::stack(['single'])->critical('stack of one');
        Log::stack(['single', 'stack'])->info('stack of two, captured once');
        Log::write('notice', 'facade write');
        Log::log('warning', 'facade log');
        Log::channel('single')->log('alert', 'channel log');
        Log::getLogger()->debug('getLogger');
        Log::channel('single')->withName('renamed')->info('withName');
        Log::build(['driver' => 'single', 'path' => $onDemandPath])->warning('on-demand build');
        Log::channel('not-in-logging-config')->error('unconfigured channel');
        // #5538: every level method the logger exposes, called directly, on the facade and on
        // a resolved channel.
        Log::emergency('facade emergency');
        Log::alert('facade alert');
        Log::critical('facade critical');
        Log::warning('facade warning');
        Log::notice('facade notice');
        Log::info('facade info');
        Log::debug('facade debug');
        Log::channel('single')->emergency('channel emergency');
        Log::channel('single')->alert('channel alert');
        Log::channel('single')->notice('channel notice');

        $this->assertSame([
            ['ERROR', 'facade level method'],
            ['ERROR', 'configured channel'],
            ['CRITICAL', 'stack of one'],
            ['INFO', 'stack of two, captured once'],
            ['NOTICE', 'facade write'],
            ['WARNING', 'facade log'],
            ['ALERT', 'channel log'],
            ['DEBUG', 'getLogger'],
            ['INFO', 'withName'],
            ['WARNING', 'on-demand build'],
            ['EMERGENCY', 'Unable to create configured logger. Using emergency logger.'],
            ['ERROR', 'unconfigured channel'],
            ['EMERGENCY', 'facade emergency'],
            ['ALERT', 'facade alert'],
            ['CRITICAL', 'facade critical'],
            ['WARNING', 'facade warning'],
            ['NOTICE', 'facade notice'],
            ['INFO', 'facade info'],
            ['DEBUG', 'facade debug'],
            ['EMERGENCY', 'channel emergency'],
            ['ALERT', 'channel alert'],
            ['NOTICE', 'channel notice'],
        ], array_map(fn (LogRecord $r) => [$r->level->getName(), $r->message], $this->records()));
        foreach ($this->strayLogPaths as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_capture_keeps_context_shared_before_the_swap(): void
    {
        // #5532: a Log::shareContext() made before the swap still reaches every record.
        Log::shareContext(['mailbox' => self::MAILBOX]);
        $this->captureAllLogChannels();

        Log::debug('after the swap');
        Log::stack(['single'])->debug('stack after the swap');

        $this->assertSame(
            [self::MAILBOX, self::MAILBOX],
            array_map(fn (LogRecord $r) => $r->context['mailbox'] ?? null, $this->records()),
        );
    }

    public function test_capture_refuses_a_logger_it_cannot_capture(): void
    {
        // #5531: a non-Monolog logger cannot be given the handler, so it fails the test.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot capture');

        Log::build(['driver' => 'custom', 'via' => fn () => new \Psr\Log\NullLogger])->debug('unseen');
    }

    // ── #5392 / #5535: assertNoLeakInLogs sees a needle in every carrier, and in the right part ──

    /** $leaf wrapped in $levels arrays: past LineFormatter's 9-level normaliser, inside walk()'s. */
    private static function nested(int $levels, mixed $leaf): array
    {
        $v = $leaf;
        for ($i = 0; $i < $levels; $i++) {
            $v = ['n' => $v];
        }

        return $v;
    }

    /**
     * Each row is [the plant, the renderParts() parts that must carry a needle: exactly these].
     * A row whose needle only one part reads pins that part's carrier (#5535): deleting the
     * carrier from render empties the row's set or changes it, and the row fails.
     *
     * @return array<string, array{0: \Closure, 1: list<string>}>
     */
    public static function plantedLeaks(): array
    {
        $mailbox = self::MAILBOX;
        $graphText = self::GRAPH_ERROR_TEXT;

        return [
            'message' => [fn () => Log::debug("fetch failed for {$mailbox}"), ['message', 'formatter']],
            // The standard Laravel shape: json_encode renders a Throwable as {}.
            'exception-in-context' => [fn () => Log::debug('fetch failed', [
                'exception' => new \App\Services\Graph\GraphClientException('Graph API error: GET users/'.rawurlencode($mailbox).'/messages/MSG-1 returned 404', 404),
            ]), ['context', 'formatter']],
            'previous-exception' => [fn () => Log::debug('fetch failed', [
                'exception' => new \RuntimeException('wrapped', 0, new \RuntimeException($graphText)),
            ]), ['context', 'formatter']],
            // Below the formatter's depth: only walk()'s Throwable branch reads the message ...
            'deep-exception' => [fn () => Log::debug('fetch failed', self::nested(9, new \RuntimeException($graphText))), ['context']],
            // ... and only its getPrevious() walk reads the chain (the cast skips base slots).
            'deep-previous-exception' => [fn () => Log::debug('fetch failed', self::nested(9, new \RuntimeException('wrapped', 0, new \RuntimeException($graphText)))), ['context']],
            // A Throwable's own properties: no formatter prints them; walk()'s cast does.
            'exception-own-property' => [fn () => Log::debug('fetch failed', [
                'exception' => new \App\Services\Graph\GraphClientException('Graph API error', 404, ['error' => ['message' => $graphText]]),
            ]), ['context']],
            'private-object-property' => [fn () => Log::debug('fetch failed', [
                'target' => new class($mailbox)
                {
                    public function __construct(private readonly string $mailbox) {}
                },
            ]), ['context']],
            // The stored property is reversed, so only jsonSerialize() / __toString() yield the needle.
            'json-serializable' => [fn () => Log::debug('fetch failed', [
                'target' => new class(strrev($mailbox)) implements \JsonSerializable
                {
                    public function __construct(private readonly string $reversed) {}

                    public function jsonSerialize(): mixed
                    {
                        return ['m' => strrev($this->reversed)];
                    }
                },
            ]), ['context', 'formatter']],
            'stringable' => [fn () => Log::debug('fetch failed', [
                'target' => new class(strrev($mailbox))
                {
                    public function __construct(private readonly string $reversed) {}

                    public function __toString(): string
                    {
                        return strrev($this->reversed);
                    }
                },
            ]), ['context', 'formatter']],
            'deep-array-key' => [fn () => Log::debug('fetch failed', self::nested(9, [$mailbox => 1])), ['context']],
            // json_encode returns false on malformed UTF-8; the old check then read '' for context.
            'invalid-utf8-beside-it' => [fn () => Log::debug('fetch failed', ['detail' => $mailbox."\xB1\x31"]), ['context', 'formatter']],
            // Processors fill extra; the old check never read it.
            'record-extra' => [function () use ($mailbox) {
                $logger = Log::getLogger();
                $logger->pushProcessor(fn (LogRecord $r) => $r->with(extra: ['mailbox' => $mailbox]));
                $logger->debug('fetch failed');
                $logger->popProcessor();
            }, ['extra', 'formatter']],
            'deep-extra' => [function () use ($mailbox) {
                $logger = Log::getLogger();
                $logger->pushProcessor(fn (LogRecord $r) => $r->with(extra: self::nested(9, $mailbox)));
                $logger->debug('fetch failed');
                $logger->popProcessor();
            }, ['extra']],
            'nested-array' => [fn () => Log::debug('fetch failed', ['a' => ['b' => ['c' => $mailbox]]]), ['context', 'formatter']],
            'facade-log' => [fn () => Log::log('debug', 'fetch failed', ['mailbox' => $mailbox]), ['context', 'formatter']],
            'stack-of-two' => [fn () => Log::stack(['single', 'stack'])->debug('fetch failed', ['mailbox' => $mailbox]), ['context', 'formatter']],
            // Loggers the config-only capture never saw (#5402).
            'on-demand-logger' => [fn () => Log::build(['driver' => 'single', 'path' => storage_path('logs/email-item-attachment-capture-ondemand-'.getmypid().'.log')])->debug('fetch failed', ['mailbox' => $mailbox]), ['context', 'formatter']],
            'unconfigured-channel' => [fn () => Log::channel('not-in-logging-config')->debug('fetch failed', ['mailbox' => $mailbox]), ['context', 'formatter']],
            // #5534: matching is case-insensitive substring, so re-cased and cut copies are caught.
            'mailbox upper-cased' => [fn () => Log::debug('fetch failed', ['m' => strtoupper($mailbox)]), ['context', 'formatter']],
            'graph text cut' => [fn () => Log::debug('fetch failed', ['m' => \Illuminate\Support\Str::limit($graphText, 25)]), ['context', 'formatter']],
            'graph error code' => [fn () => Log::debug('fetch failed', ['code' => 'InternalServerError']), ['context', 'formatter']],
            'mailbox base64' => [fn () => Log::debug('fetch failed', ['m' => base64_encode($mailbox)]), ['context', 'formatter']],
            // #5541: the message body, item name and subject are needles too.
            'MIME fragment' => [fn () => Log::debug('fetch failed', ['preview' => substr(self::MIME, 0, 60)]), ['context', 'formatter']],
            'item name' => [fn () => Log::info('stored', ['detail' => 'Urgent: verify your payroll account']), ['context', 'formatter']],
            'email body' => [fn () => Log::info('stored', ['body' => 'Is this legit?']), ['context', 'formatter']],
        ];
    }

    /** @param  list<string>  $parts */
    #[DataProvider('plantedLeaks')]
    public function test_no_leak_check_catches_a_planted_needle(\Closure $plant, array $parts): void
    {
        $this->assertNoLeakInLogs(); // nothing logged yet: the check passes on a clean capture

        $plant();

        $this->assertNotSame([], $this->records(), 'positive control: the planted record was captured');
        $caught = false;
        try {
            $this->assertNoLeakInLogs();
        } catch (\PHPUnit\Framework\ExpectationFailedException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'assertNoLeakInLogs missed a planted needle');

        $seenIn = [];
        foreach ($this->records() as $r) {
            foreach ($this->leakHits($r) as $hitParts) {
                $seenIn = array_merge($seenIn, $hitParts);
            }
        }
        $seenIn = array_values(array_unique($seenIn));
        sort($seenIn);
        $expected = $parts;
        sort($expected);
        $this->assertSame($expected, $seenIn, 'render parts that carry the planted needle');
    }

    public function test_no_leak_check_catches_a_caller_supplied_needle(): void
    {
        Log::debug('fetch failed', ['exception' => new \RuntimeException(self::NON_GRAPH_DETAIL)]);

        $this->assertNoLeakInLogs(); // not a default needle
        $this->expectException(\PHPUnit\Framework\ExpectationFailedException::class);
        $this->assertNoLeakInLogs([self::NON_GRAPH_DETAIL]);
    }

    public function test_no_leak_check_refuses_context_deeper_than_it_reads(): void
    {
        // #5536: past WALK_MAX_DEPTH the scan fails instead of passing an unread needle.
        Log::debug('fetch failed', self::nested(13, self::MAILBOX));

        try {
            $this->assertNoLeakInLogs();
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('walk() passed depth', $e->getMessage());

            return;
        }
        $this->fail('a needle below walk()\'s depth limit passed the scan');
    }

    /** A Throwable built in a frame that received $arg; its trace frame holds $arg when args are kept. */
    private static function throwableBuiltWith(string $arg): \RuntimeException
    {
        return new \RuntimeException('built');
    }

    public function test_walk_does_not_read_a_throwable_trace(): void
    {
        // #5542: frame args are not printed by any formatter in render(); walk() must not read them.
        ini_set('zend.exception_ignore_args', '0');
        $e = self::throwableBuiltWith(self::MAILBOX);
        ini_set('zend.exception_ignore_args', '1');

        // Instrument control: the trace really holds the argument under this ini.
        $this->assertSame(self::MAILBOX, $e->getTrace()[0]['args'][0] ?? null);
        $this->assertStringNotContainsString(self::MAILBOX, $this->walk(['exception' => $e], new \SplObjectStorage));
    }

    // ── #5393 / #5533: #5144 keeps throwFromGuzzle's record on every other Graph call ──
    //
    // GraphClient::throwFromGuzzle() writes 'Graph API request failed' at ERROR with exactly two
    // context keys, method and status (#5533, C-56). It used to carry 'endpoint' (the request
    // path, which holds the raw mailbox on the 'message read (expand)' row) and 'error' (Guzzle's
    // message: the request URI and the start of Graph's response body); the tests below require
    // that no forbiddenNeedles() needle is in any part of the record.

    /**
     * A record's leaks: which context keys carry each needle. The message and extra are scanned
     * whole; the context key by key (each value through walk()), so a needle in any key, new or
     * old, is reported under that key.
     *
     * @return array<string, list<string>>
     */
    private function graphFailureLeaks(LogRecord $r): array
    {
        $hits = [];
        $parts = ['(message)' => $r->message, '(extra)' => $this->walk($r->extra, new \SplObjectStorage)];
        foreach ($r->context as $key => $value) {
            $parts[$key] = $key.'='.$this->walk($value, new \SplObjectStorage);
        }
        foreach ($parts as $key => $text) {
            foreach (self::forbiddenNeedles() as $label => $needle) {
                if (stripos($text, $needle) !== false) {
                    $hits[$label][] = (string) $key;
                }
            }
        }
        ksort($hits);

        return $hits;
    }

    /** @return array<string, array{0: \Closure, 1: list<Response>, 2: string, 3: string, 4: bool}> */
    public static function otherGraphCallFailures(): array
    {
        $fail = fn () => new Response(500, [], json_encode(['error' => ['code' => 'InternalServerError', 'message' => self::GRAPH_ERROR_TEXT]]));

        // [call, responses, method, the failing request's URI, whether the path holds the mailbox]
        return [
            'get' => [fn (GraphClient $g) => $g->get('users/MSG-OWNER/messages'), [$fail()], 'GET', 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/messages', false],
            'post' => [fn (GraphClient $g) => $g->post('users/MSG-OWNER/sendMail', ['x' => 1]), [$fail()], 'POST', 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/sendMail', false],
            'patch' => [fn (GraphClient $g) => $g->patch('users/MSG-OWNER/messages/MSG-1', ['isRead' => true]), [$fail()], 'PATCH', 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/messages/MSG-1', false],
            'delete' => [fn (GraphClient $g) => $g->delete('users/MSG-OWNER/messages/MSG-1'), [$fail()], 'DELETE', 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/messages/MSG-1', false],
            'getRaw' => [fn (GraphClient $g) => $g->getRaw('users/MSG-OWNER/photo/$value'), [$fail()], 'GET', 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/photo/$value', false],
            'message read (expand)' => [fn (GraphClient $g) => $g->getMessageAttachments(self::MAILBOX, 'MSG-1'), [$fail()], 'GET', 'https://graph.microsoft.com/v1.0/users/support@example.test/messages/MSG-1?%24expand=attachments', true],
            'calendar event' => [fn (GraphClient $g) => $g->getEvent('MSG-OWNER', 'EVT-1'), [$fail()], 'GET', 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/events/EVT-1', false],
            // The failing request is the absolute nextLink URL (checked against the history).
            'nextLink page' => [fn (GraphClient $g) => $g->getAllPages('users/MSG-OWNER/messages'), [
                new Response(200, ['Content-Type' => 'application/json'], json_encode(['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/messages?$skip=10'])),
                $fail(),
            ], 'GET', 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/messages?$skip=10', false],
        ];
    }

    #[DataProvider('otherGraphCallFailures')]
    public function test_other_graph_call_failure_still_writes_the_request_failed_error(\Closure $call, array $responses, string $method, string $uri, bool $pathHoldsMailbox): void
    {
        $graph = $this->graph($responses);

        try {
            $call($graph);
            $this->fail('the failing Graph call did not throw');
        } catch (\App\Services\Graph\GraphClientException $e) {
            $this->assertSame(500, $e->getHttpStatus());
        }
        $this->assertSame($uri, (string) end($this->history)['request']->getUri(), 'the failing request');

        $failed = array_values(array_filter($this->records(), fn (LogRecord $r) => $r->message === 'Graph API request failed'));
        $this->assertCount(1, $failed, 'records: '.$this->describe($this->records()));
        $this->assertCount(1, $this->records(), 'records: '.$this->describe($this->records()));
        $record = $failed[0];
        $this->assertSame(Level::Error, $record->level);
        $this->assertSame([$method, 500], [$record->context['method'] ?? null, $record->context['status'] ?? null]);
        // #5533: exactly method and status, and no needle anywhere in the record. Any new key or
        // any needle in any part fails here.
        $this->assertSame(['method' => $method, 'status' => 500], $record->context);
        $this->assertSame([], $this->graphFailureLeaks($record), '#5533: the record carries no forbidden needle');
        // Positive control: the failure this record reports did carry the needles, in the request
        // path ($pathHoldsMailbox) and in Graph's body; the record leaves them out.
        $sent = end($this->history);
        $this->assertStringContainsString(self::GRAPH_ERROR_TEXT, (string) $sent['response']->getBody());
        // #5663: read the request actually sent, not the provider's $uri.
        $this->assertSame($pathHoldsMailbox, str_contains((string) $sent['request']->getUri(), self::MAILBOX));
    }

    /** #5533 control: graphFailureLeaks() reports a needle under the key that carries it. */
    public function test_the_graph_failure_scan_reports_each_carrier_key(): void
    {
        $record = new LogRecord(new \DateTimeImmutable, 'testing', Level::Error, 'Graph API request failed', [
            'method' => 'GET', 'endpoint' => 'users/'.self::MAILBOX.'/messages/MSG-1', 'status' => 500, 'error' => self::GRAPH_ERROR_TEXT,
        ]);
        $leaks = $this->graphFailureLeaks($record);

        $this->assertSame(['endpoint'], $leaks['mailbox'] ?? null);
        $this->assertSame(['error'], $leaks['graph error text'] ?? null);
    }

    public function test_message_read_failure_records_carry_no_mailbox_or_graph_text(): void
    {
        // The expand read through AttachmentService: GraphClient's record plus the service's own
        // WARNING, whose 'error' is the GraphClientException message.
        $graph = $this->graph([new Response(500, [], json_encode(['error' => ['code' => 'InternalServerError', 'message' => self::GRAPH_ERROR_TEXT]]))]);

        $this->assertNull(app(AttachmentService::class)->downloadEmailAttachments($this->email(), $graph, self::MAILBOX));

        $records = $this->records();
        $this->assertSame(
            [['ERROR', 'Graph API request failed'], ['WARNING', '[AttachmentService] Failed to fetch email attachments']],
            array_map(fn (LogRecord $r) => [$r->level->getName(), $r->message], $records),
        );
        // #5533: GraphClient's record is status-only.
        $this->assertSame(['method' => 'GET', 'status' => 500], $records[0]->context);
        $this->assertSame([], $this->graphFailureLeaks($records[0]), '#5533: GraphClient record');
        // #5679: AttachmentService::downloadEmailAttachments' message-read catch still logs the
        // GraphClientException message (that catch is the email batch's to change), but the
        // message no longer carries the endpoint, so the WARNING carries no needle either.
        $this->assertSame(['email_id', 'graph_id', 'error'], array_keys($records[1]->context));
        $this->assertSame('Graph API error: GET returned 500', $records[1]->context['error']);
        $this->assertSame([], $this->graphFailureLeaks($records[1]), '#5679: the WARNING carries no forbidden needle');
        // Positive control: the request that failed did hold the mailbox in its path.
        $this->assertStringContainsString('users/'.self::MAILBOX.'/messages/MSG-1', urldecode((string) end($this->history)['request']->getUri()));
    }

    public function test_item_value_read_failure_writes_no_request_failed_error(): void
    {
        // Contrast row for the provider above: the item $value read passes logFailure: false,
        // so its failure writes no record at all. Whether any other GraphClient method also
        // passes it is not established here; the provider samples eight call shapes.
        $graph = $this->graph([new Response(500, [], json_encode(['error' => ['message' => self::GRAPH_ERROR_TEXT]]))]);

        try {
            $graph->getMessageAttachmentRaw(self::MAILBOX, 'MSG-1', 'ATT-ITEM-1');
            $this->fail('the failing $value read did not throw');
        } catch (\App\Services\Graph\GraphClientException $e) {
            $this->assertSame(500, $e->getHttpStatus());
        }

        $this->assertSame([], $this->records(), $this->describe($this->records()));
    }
}
