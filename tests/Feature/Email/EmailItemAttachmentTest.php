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

    /*
     * Fixture values that are also needle sources (#5629): the fixtures below and
     * forbiddenNeedles() both read these, and needleControls() plants copies taken from the
     * fixtures' own output, so a needle that stops matching its fixture fails its control.
     */
    private const ITEM_NAME = 'Urgent: verify your payroll account';

    private const EMAIL_SUBJECT = 'FW: suspicious';

    private const EMAIL_BODY = 'Is this legit?';

    private const EMAIL_FROM = 'user@example.test';

    private const FILE_NAME = 'Invoice Template.docx';

    private const REFERENCE_NAME = 'Sales Invoice Template.docx';

    private const MIME_SENDER = 'payroll@phish.example.test';

    private const PHISH_URL = 'http://198.51.100.7/login';

    /** #5625: distinctive, so they can be needles; G-13 synthetic. */
    private const GRAPH_BEARER = 'SECRET_FIXTURE_b4n_graph_bearer';

    private const TENANT = 'tenant-b4n-synthetic';

    private const CLIENT_CREDENTIAL = 'SECRET_FIXTURE_b4n_client_credential';

    private const MIME = 'From: Payroll Team <'.self::MIME_SENDER.">\r\n"
        ."Return-Path: <bounce@phish.example.test>\r\n"
        ."Received: from mail.phish.example.test (192.0.2.10) by mx.example.test\r\n"
        ."Authentication-Results: mx.example.test; spf=fail smtp.mailfrom=phish.example.test\r\n"
        .'To: User <'.self::EMAIL_FROM.">\r\n"
        .'Subject: '.self::ITEM_NAME."\r\n"
        ."Content-Type: text/plain; charset=\"us-ascii\"\r\n"
        ."MIME-Version: 1.0\r\n\r\n"
        .'Click '.self::PHISH_URL." to keep your pay.\r\n";

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

    /**
     * #5626: a write through the ORIGINAL manager (the #5530 limit) lands in a stray file, not
     * the TestHandler, so every passing test checks the files before tearDown() removes them.
     * #5627: a logger captured() refused fails the test even when the code under test caught
     * the LogicException.
     */
    protected function assertPostConditions(): void
    {
        foreach ($this->strayLogPaths as $kind => $path) {
            $this->assertFileDoesNotExist($path, "#5626: a record reached the {$kind} file, outside the capture");
        }
        $this->assertNull($this->uncapturable, '#5627: captureAllLogChannels refused a logger the code under test then used');
        parent::assertPostConditions();
    }

    /** #5627: what captured() refused, set even when the code under test swallows the throw. */
    private ?string $uncapturable = null;

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
     *    through uncaptured (#5531); it also records the refusal, and assertPostConditions()
     *    fails the test on it, so code under test that catches the LogicException cannot
     *    drop the record silently (#5627);
     *  - #5631: each rewritten channel keeps its configured 'processors' (and the
     *    PsrLogMessageProcessor 'replace_placeholders' adds) and its 'tap' list, so what they
     *    put in the record's extra or message in production is in the scanned record. Not kept:
     *    a processor configured on a handler or formatter rather than the channel (the handler
     *    is replaced), and a 'monolog' channel's 'formatter'.
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
        foreach (config('logging.channels') as $name => $channel) {
            // #5631: keep what the channel adds to a record: its processors and its taps.
            $kept = array_intersect_key($channel, array_flip(['processors', 'replace_placeholders', 'tap']));
            config(["logging.channels.{$name}" => [
                'driver' => 'custom',
                'via' => fn (array $config) => new \Monolog\Logger($name, [$handler], self::channelProcessors($config)),
            ] + $kept]);
            Log::forgetChannel($name);
        }
        config(['logging.channels.emergency' => ['path' => $this->strayLogPaths['emergency']]]);

        $original = Log::getFacadeRoot();
        $refused = function (string $type): void {
            $this->uncapturable ??= $type;
        };
        Log::swap(new class($this->app, $handler, $original, $refused) extends \Illuminate\Log\LogManager
        {
            public function __construct($app, private readonly TestHandler $capture, \Illuminate\Log\LogManager $original, private readonly \Closure $refused)
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
                    ($this->refused)(get_debug_type($monolog));
                    throw new \LogicException('captureAllLogChannels cannot capture a '.get_debug_type($monolog).' logger');
                }
                if ($monolog->getHandlers() !== [$this->capture]) {
                    $monolog->setHandlers([$this->capture]);
                }

                return $logger;
            }
        });
    }

    /**
     * #5631: the processors a channel's config gives its logger, as LogManager builds them: the
     * 'processors' list (a class, or ['processor' => class, 'with' => [...]]) and the
     * PsrLogMessageProcessor that 'replace_placeholders' adds.
     *
     * @return list<\Monolog\Processor\ProcessorInterface|callable>
     */
    private static function channelProcessors(array $config): array
    {
        $processors = array_map(
            fn ($p) => app()->make($p['processor'] ?? $p, $p['with'] ?? []),
            $config['processors'] ?? [],
        );
        if ($config['replace_placeholders'] ?? false) {
            $processors[] = new \Monolog\Processor\PsrLogMessageProcessor;
        }

        return $processors;
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
        $cache->put('graph_api_token', self::GRAPH_BEARER, 3600);

        $graph = new GraphClient([
            'tenant_id' => self::TENANT,
            'client_id' => 'client',
            'client_secret' => self::CLIENT_CREDENTIAL,
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
            'name' => self::ITEM_NAME,
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
            'name' => self::FILE_NAME,
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
            'name' => self::REFERENCE_NAME,
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
            'from_address' => self::EMAIL_FROM,
            'from_name' => 'User',
            'subject' => self::EMAIL_SUBJECT,
            'body_text' => self::EMAIL_BODY,
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
     * (#5534). Every needle is derived from the fixture constants the fixtures themselves use,
     * and needleControls() plants a fixture-derived copy for each label (#5628, #5629):
     *  - the mailbox raw, its local part with '@', URL-encoded (whole and local part) and double
     *    encoded;
     *  - base64 (#5623): for the mailbox, the MIME sender and the item name, the encoding of the
     *    secret at each of the three byte alignments, cut to whole 4-character groups and
     *    unpadded. That is the substring any base64 of a longer text containing the secret
     *    holds (a Graph contentBytes of the MIME, 'To: <mailbox>', a trailing newline), and
     *    base64url or unpadded copies hold the same characters while a core has no '+' or '/'
     *    (needleControls() asserts it has none);
     *  - text secrets (#5624): the item name, Graph's error text, the email's subject and body,
     *    and the file and reference attachment names (#5634) each have a needle for their first
     *    and their last TRUNCATION_KEEP characters, so a cut copy (Str::limit, a tail) is caught
     *    when it keeps at least that many characters from either end (closing punctuation
     *    aside);
     *  - transport forms (#5630): the item name and the email body with spaces as RFC 2047 / QP
     *    '=20' and as Q-encoding '_' (subject and body), the phishing URL JSON-escaped and its
     *    bare host; a folded header is caught by the start or end needle when the fold falls
     *    outside them;
     *  - the other MIME headers (#5630, #5634): the display name, the sending domain (the
     *    Return-Path, Received host and smtp.mailfrom all carry it), the sending IP, and the
     *    To address, which is the email's own sender address;
     *  - the token, tenant and client credential the Graph fixture is built with (#5625);
     *  - the item name's and file name's stored-filename slugs (#5818: '[Attachment] Stored
     *    from content' logs ids, size and type only since #5719, so no record carries them);
     *  - Graph's error codes used by the fixtures (vendor text).
     * Not covered: a middle fragment, or a cut that keeps fewer than TRUNCATION_KEEP characters
     * at both ends; a fold inside a start or end needle; base64 of any secret not listed above;
     * every other transform (hex, a hash, HTML entities, percent-encoding of the text secrets,
     * RFC 2047 'B' words split across a fold); the mailbox's domain alone (every fixture address
     * shares it).
     *
     * @return array<string, string>
     */
    private static function forbiddenNeedles(): array
    {
        $needles = [
            'mailbox' => self::MAILBOX,
            'mailbox local part' => strstr(self::MAILBOX, '@', true).'@',
            'mailbox urlencoded' => rawurlencode(self::MAILBOX),
            'mailbox local part urlencoded' => rawurlencode(strstr(self::MAILBOX, '@', true).'@'),
            'mailbox double-urlencoded' => rawurlencode(rawurlencode(strstr(self::MAILBOX, '@', true).'@')),
        ];
        foreach (['mailbox' => self::MAILBOX, 'MIME sender' => self::MIME_SENDER, 'item name' => self::ITEM_NAME] as $label => $secret) {
            foreach (self::base64Cores($secret) as $after => $core) {
                $needles["{$label} base64 (after {$after} bytes)"] = $core;
            }
        }
        foreach ([
            'item name' => self::ITEM_NAME,
            'graph error text' => self::GRAPH_ERROR_TEXT,
            'email subject' => self::EMAIL_SUBJECT,
            'email body' => self::EMAIL_BODY,
            'file attachment name' => self::FILE_NAME,
            'reference attachment name' => self::REFERENCE_NAME,
        ] as $label => $secret) {
            $needles["{$label} (start)"] = substr($secret, 0, self::TRUNCATION_KEEP);
            // The end needle leaves out closing punctuation, which a quoted tail often drops.
            $needles["{$label} (end)"] = substr(rtrim($secret, '.?!'), -self::TRUNCATION_KEEP);
        }

        return $needles + [
            'item name QP (start)' => substr(str_replace(' ', '=20', self::ITEM_NAME), 0, self::TRUNCATION_KEEP),
            'item name Q underscore (start)' => substr(str_replace(' ', '_', self::ITEM_NAME), 0, self::TRUNCATION_KEEP),
            'email subject Q underscore' => str_replace(' ', '_', self::EMAIL_SUBJECT),
            'email body QP (start)' => substr(str_replace(' ', '=20', self::EMAIL_BODY), 0, self::TRUNCATION_KEEP),
            'item name slug' => \Illuminate\Support\Str::slug(self::ITEM_NAME),
            'file attachment name slug' => \Illuminate\Support\Str::slug(pathinfo(self::FILE_NAME, PATHINFO_FILENAME)),
            'MIME sender' => self::MIME_SENDER,
            'MIME display name' => 'Payroll Team',
            'MIME sending domain' => substr(strstr(self::MIME_SENDER, '@'), 1),
            'MIME sending IP' => '192.0.2.10',
            'MIME phishing URL' => self::PHISH_URL,
            'MIME phishing URL JSON-escaped' => trim(json_encode(self::PHISH_URL), '"'),
            'MIME phishing URL host' => parse_url(self::PHISH_URL, PHP_URL_HOST),
            'email sender / MIME To' => self::EMAIL_FROM,
            'graph bearer token' => self::GRAPH_BEARER,
            'graph tenant' => self::TENANT,
            'graph client credential' => self::CLIENT_CREDENTIAL,
            'graph error code ErrorItemNotFound' => 'ErrorItemNotFound',
            'graph error code InternalServerError' => 'InternalServerError',
            'graph error code ServiceUnavailable' => 'ServiceUnavailable',
        ];
    }

    /** Characters of a text secret's start and end that are needles (#5624). */
    private const TRUNCATION_KEEP = 12;

    /**
     * #5623: base64 of $secret preceded by 0, 1 and 2 other bytes, keeping only the 4-character
     * groups made of $secret's bytes alone: the characters any base64 of a text containing
     * $secret at that alignment carries.
     *
     * @return array<int, string> keyed by the number of bytes before the secret
     */
    private static function base64Cores(string $secret): array
    {
        $cores = [];
        foreach ([0, 1, 2] as $after) {
            $skip = (3 - $after) % 3;
            $whole = intdiv(strlen($secret) - $skip, 3) * 3;
            $cores[$after] = base64_encode(substr($secret, $skip, $whole));
        }

        return $cores;
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
        $this->assertSame('Bearer '.self::GRAPH_BEARER, $value[0]['request']->getHeaderLine('Authorization'));
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
        // #5632: the scan below is not vacuous: the run wrote records.
        $this->assertNotSame([], $this->records(), 'positive control: the mixed-message path logs');
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
        // #5538: every level method called directly on the facade; on a resolved channel
        // ('single'), emergency, alert and notice here, and the other five in
        // test_capture_sees_each_level_method_on_a_resolved_channel (#5636).
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

    public function test_capture_sees_each_level_method_on_a_resolved_channel(): void
    {
        // #5636: each of the eight level methods called directly on Log::channel('single').
        $channel = Log::channel('single');
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
            $channel->{$level}("channel {$level}");
        }

        $this->assertSame(
            [['EMERGENCY', 'channel emergency'], ['ALERT', 'channel alert'], ['CRITICAL', 'channel critical'], ['ERROR', 'channel error'],
                ['WARNING', 'channel warning'], ['NOTICE', 'channel notice'], ['INFO', 'channel info'], ['DEBUG', 'channel debug']],
            array_map(fn (LogRecord $r) => [$r->level->getName(), $r->message], $this->records()),
        );
    }

    public function test_capture_keeps_a_channels_processors_and_taps(): void
    {
        // #5631: what a channel's configured processor or tap adds to a record in production is
        // in the scanned record. Configured before the capture, as config/logging.php would be.
        $this->app->instance('b4n.tap-extra', ['tapped' => self::MAILBOX]);
        config(['logging.channels.single.processors' => [B4nExtraProcessor::class]]);
        config(['logging.channels.single.tap' => [B4nExtraTap::class]]);
        $this->captureAllLogChannels();

        Log::channel('single')->info('with processors');

        $r = $this->records()[0];
        $extra = array_intersect_key($r->extra, ['processed' => 1, 'tapped' => 1]);
        ksort($extra);
        $this->assertSame(['processed' => 'b4n', 'tapped' => self::MAILBOX], $extra);
        $this->assertArrayHasKey('mailbox', $this->leakHits($r), 'the tap\'s extra is scanned');
    }

    public function test_capture_keeps_a_custom_creator_registered_before_the_swap(): void
    {
        // #5633: a Log::extend() driver registered before the swap still resolves through the
        // swapped manager, and its logger is captured.
        Log::extend('b4n-extended', fn ($app, array $config) => new \Monolog\Logger('b4n-extended'));
        $this->captureAllLogChannels();

        Log::build(['driver' => 'b4n-extended'])->warning('through the extended driver');

        $this->assertSame([['WARNING', 'through the extended driver', 'b4n-extended']],
            array_map(fn (LogRecord $r) => [$r->level->getName(), $r->message, $r->channel], $this->records()),
            'the extended driver\'s own logger, not the emergency fallback');
    }

    public function test_a_write_through_an_uncaptured_manager_fails_the_post_condition(): void
    {
        // #5626: an object holding a manager that is not the swapped one (the #5530 limit) logs
        // to an unconfigured channel; its emergency logger writes the stray file. The
        // post-condition every test runs must fail on it before tearDown() deletes it.
        (new \Illuminate\Log\LogManager($this->app))->channel('not-in-logging-config')->warning('held before the swap');

        $this->assertSame([], $this->records(), 'positive control: the capture did not see it');
        $this->assertFileExists($this->strayLogPaths['emergency'], 'positive control: it reached the stray file');
        try {
            $this->assertPostConditions();
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('#5626', $e->getMessage());
            @unlink($this->strayLogPaths['emergency']); // spent: this control expected it

            return;
        }
        $this->fail('#5626: the post-condition passed a write outside the capture');
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
        // #5531: a non-Monolog logger cannot be given the handler, so it throws on the logging
        // call's own stack. #5627: code under test that catches it, as a catch (\Throwable) arm
        // in AttachmentService or EmailService would, still leaves the refusal recorded, and
        // assertPostConditions() fails the test on it.
        $swallowed = null;
        try {
            Log::build(['driver' => 'custom', 'via' => fn () => new \Psr\Log\NullLogger])->debug('unseen');
        } catch (\Throwable $e) {
            $swallowed = $e;
        }

        $this->assertInstanceOf(\LogicException::class, $swallowed);
        $this->assertStringContainsString('cannot capture', $swallowed->getMessage());
        $this->assertSame(\Psr\Log\NullLogger::class, $this->uncapturable, 'the swallowed refusal is still recorded');
        try {
            $this->assertPostConditions();
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('#5627', $e->getMessage());
            $this->uncapturable = null; // spent: this control expected it

            return;
        }
        $this->fail('#5627: the post-condition passed a refused logger');
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

    /**
     * #5628/#5629: one planted control per needle, taken from the fixtures' OWN output (the
     * MIME the $value fixture serves, the email() and attachment factories, the Graph error
     * body, the GraphClient the fixture builds) through the transform the needle claims to
     * catch. Each row must be caught under its own label (other needles may hit too), so a
     * needle that is deleted, mistyped or no longer matches its fixture fails its row.
     *
     * @return array<string, array{0: \Closure(self): string, 1: string}>
     */
    public static function needleControls(): array
    {
        $mime = fn (self $t) => self::MIME;
        $mailbox = fn (self $t) => (string) Setting::getValue('graph_mailbox');
        $sender = fn (self $t) => preg_match('/^From: [^<]*<([^>]+)>/m', $mime($t), $m) ? $m[1] : '';
        $item = fn (self $t) => $t->itemAttachment()['name'];
        $email = fn (self $t) => $t->email()->only(['subject', 'body_text', 'from_address']);
        $graphText = fn (self $t) => json_decode((string) self::failingValueResponses()['http-404'][0]->getBody(), true)['error']['message'];
        $header = fn (string $mime, string $name) => preg_match('/^'.$name.': (.*)$/m', $mime, $m) ? rtrim($m[1], "\r") : '';
        $keep = self::TRUNCATION_KEEP;

        $rows = [
            'mailbox' => [fn (self $t) => 'm '.$mailbox($t).' m', 'mailbox'],
            'mailbox local part' => [fn (self $t) => strstr($mailbox($t), '@', true).'@', 'mailbox local part'],
            'mailbox urlencoded' => [fn (self $t) => 'x/'.rawurlencode($mailbox($t)), 'mailbox urlencoded'],
            'mailbox local part urlencoded' => [fn (self $t) => rawurlencode(strstr($mailbox($t), '@', true).'@').'elsewhere', 'mailbox local part urlencoded'],
            'mailbox double-urlencoded' => [fn (self $t) => rawurlencode(rawurlencode(strstr($mailbox($t), '@', true).'@')).'x', 'mailbox double-urlencoded'],
        ];
        // #5623: each alignment, embedded in a longer text, plain/unpadded/base64url.
        foreach ([
            'mailbox' => fn (self $t) => $mailbox($t),
            'MIME sender' => $sender,
            'item name' => fn (self $t) => $header($t->valueFixtureMime(), 'Subject'),
        ] as $label => $secret) {
            foreach ([0, 1, 2] as $after) {
                $rows["{$label} base64 (after {$after} bytes)"] = [
                    fn (self $t) => rtrim(strtr(base64_encode(str_repeat('~', 3 + $after).$secret($t)."\r\n"), '+/', '-_'), '='),
                    "{$label} base64 (after {$after} bytes)",
                ];
            }
        }
        // #5624: Str::limit keeps the start; a tail keeps the end.
        foreach ([
            'item name' => $item,
            'graph error text' => $graphText,
            'email subject' => fn (self $t) => $email($t)['subject'],
            'email body' => fn (self $t) => $email($t)['body_text'],
            'file attachment name' => fn (self $t) => $t->fileAttachment()['name'],
            'reference attachment name' => fn (self $t) => $t->referenceAttachment()['name'],
        ] as $label => $secret) {
            $rows["{$label} (start)"] = [fn (self $t) => \Illuminate\Support\Str::limit($secret($t), $keep, '...'), "{$label} (start)"];
            $rows["{$label} (end)"] = [fn (self $t) => '...'.substr(rtrim($secret($t), '.?!'), -$keep), "{$label} (end)"];
        }

        return $rows + [
            // #5630: transport forms of the fixtures' text.
            'item name QP (start)' => [fn (self $t) => iconv_mime_encode('Subject', $header($t->valueFixtureMime(), 'Subject'), ['scheme' => 'Q', 'line-length' => 998]), 'item name QP (start)'],
            'item name Q underscore (start)' => [fn (self $t) => '=?UTF-8?Q?'.substr(str_replace(' ', '_', $item($t)), 0, $keep).'?=', 'item name Q underscore (start)'],
            'email subject Q underscore' => [fn (self $t) => '=?UTF-8?Q?'.str_replace(' ', '_', $email($t)['subject']).'?=', 'email subject Q underscore'],
            'email body QP (start)' => [fn (self $t) => iconv_mime_encode('X', $email($t)['body_text'], ['scheme' => 'Q', 'line-length' => 998]), 'email body QP (start)'],
            // #5818: the slugs, as AttachmentService would name the stored file.
            'item name slug' => [fn (self $t) => 'stored '.\Illuminate\Support\Str::slug($item($t)).'.eml', 'item name slug'],
            'file attachment name slug' => [fn (self $t) => 'stored '.\Illuminate\Support\Str::slug(pathinfo($t->fileAttachment()['name'], PATHINFO_FILENAME)).'.docx', 'file attachment name slug'],
            // #5630/#5634: the MIME's own header values, read out of the MIME.
            'MIME sender' => [fn (self $t) => 'from '.$sender($t), 'MIME sender'],
            'MIME display name' => [fn (self $t) => trim(strstr($header($mime($t), 'From'), '<', true)), 'MIME display name'],
            'MIME sending domain' => [fn (self $t) => 'via '.(preg_match('/^Received: from mail\.(\S+)/m', $mime($t), $m) ? $m[1] : ''), 'MIME sending domain'],
            'MIME sending IP' => [fn (self $t) => preg_match('/\((\d+\.\d+\.\d+\.\d+)\)/', $mime($t), $m) ? 'ip '.$m[1] : '', 'MIME sending IP'],
            'MIME phishing URL' => [fn (self $t) => preg_match('#https?://\S+#', $mime($t), $m) ? $m[0] : '', 'MIME phishing URL'],
            'MIME phishing URL JSON-escaped' => [fn (self $t) => preg_match('#https?://\S+#', $mime($t), $m) ? json_encode(['u' => $m[0]]) : '', 'MIME phishing URL JSON-escaped'],
            'MIME phishing URL host' => [fn (self $t) => preg_match('#https?://([^/\s]+)#', $mime($t), $m) ? 'host '.$m[1] : '', 'MIME phishing URL host'],
            'email sender / MIME To' => [fn (self $t) => preg_match('/^To: .*<([^>]+)>/m', $mime($t), $m) && $m[1] === $email($t)['from_address'] ? 'to '.$m[1] : '', 'email sender / MIME To'],
            // #5625: what the Graph fixture really sends and is built with.
            'graph bearer token' => [fn (self $t) => $t->sentAuthorization(), 'graph bearer token'],
            'graph tenant' => [fn (self $t) => 'tenant '.$t->graphConfig('tenant_id'), 'graph tenant'],
            'graph client credential' => [fn (self $t) => 'cred '.$t->graphConfig('client_secret'), 'graph client credential'],
            'graph error code ErrorItemNotFound' => [fn (self $t) => 'code '.json_decode((string) self::failingValueResponses()['http-404'][0]->getBody(), true)['error']['code'], 'graph error code ErrorItemNotFound'],
            'graph error code InternalServerError' => [fn (self $t) => 'code '.json_decode((string) self::failingValueResponses()['http-500'][0]->getBody(), true)['error']['code'], 'graph error code InternalServerError'],
            'graph error code ServiceUnavailable' => [fn (self $t) => 'code '.json_decode((string) self::failingValueResponses()['http-503'][0]->getBody(), true)['error']['code'], 'graph error code ServiceUnavailable'],
        ];
    }

    /** The MIME the item's $value read serves in test_item_attachment_is_stored_as_eml_through_value. */
    private function valueFixtureMime(): string
    {
        $graph = $this->graph([$this->expandResponse([$this->itemAttachment()]), new Response(200, [], self::MIME)]);
        $stored = app(AttachmentService::class)->downloadEmailAttachments($this->email(['graph_id' => 'MSG-CTL-'.uniqid()]), $graph, self::MAILBOX);

        return Storage::disk('local')->get($stored[0]->storage_path);
    }

    /** The Authorization header the Graph fixture sends. */
    private function sentAuthorization(): string
    {
        $graph = $this->graph([$this->expandResponse([])]);
        $graph->getMessageAttachments(self::MAILBOX, 'MSG-1');

        return $this->history[0]['request']->getHeaderLine('Authorization');
    }

    private function graphConfig(string $key): string
    {
        return (string) ((fn () => $this->config[$key])->call($this->graph([])));
    }

    #[DataProvider('needleControls')]
    public function test_each_needle_catches_a_fixture_derived_copy(\Closure $plant, string $label): void
    {
        $value = $plant($this);
        $this->logs->clear();
        $this->assertNotSame('', $value, 'positive control: the fixture yields the planted value');

        Log::debug('planted', ['v' => $value]);

        $hit = array_keys($this->leakHits($this->records()[0]));
        $this->assertContains($label, $hit, "needle '{$label}' missed a fixture-derived copy: ".json_encode($value));
        if (str_contains($label, 'base64')) {
            $this->assertDoesNotMatchRegularExpression('#[+/]#', self::forbiddenNeedles()[$label], 'a core with + or / would miss base64url');
        }
    }

    /** #5628: every needle has a control row, and every row names a needle. */
    public function test_every_needle_has_a_control_row(): void
    {
        $rows = array_map(fn ($row) => $row[1], self::needleControls());
        $labels = array_keys(self::forbiddenNeedles());
        sort($rows);
        sort($labels);

        $this->assertSame($labels, $rows);
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

    public function test_walk_reads_exactly_to_its_depth_limit(): void
    {
        // #5635: a leaf at exactly WALK_MAX_DEPTH is read (and its needle caught); one level
        // deeper fails the scan. walk() of nested($n) reaches the leaf at depth $n.
        $this->assertSame(12, self::WALK_MAX_DEPTH, 'the documented limit');
        $atLimit = $this->walk(self::nested(self::WALK_MAX_DEPTH, self::MAILBOX), new \SplObjectStorage);
        $this->assertStringContainsString(self::MAILBOX, $atLimit, 'the leaf at the deepest level read');

        try {
            $this->walk(self::nested(self::WALK_MAX_DEPTH + 1, self::MAILBOX), new \SplObjectStorage);
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->assertStringContainsString('walk() passed depth 12', $e->getMessage());

            return;
        }
        $this->fail('one level past the limit was read instead of refused');
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

    /** @return array<string, array{0: \Closure, 1: list<Response>, 2: string, 3: string, 4: bool, 5: string}> */
    public static function otherGraphCallFailures(): array
    {
        $fail = fn () => new Response(500, [], json_encode(['error' => ['code' => 'InternalServerError', 'message' => self::GRAPH_ERROR_TEXT]]));

        // [call, responses, method, the failing request's URI, whether the path holds the mailbox,
        // the record's operation label (#5671)]
        return [
            'get' => [fn (GraphClient $g) => $g->get('users/MSG-OWNER/messages'), [$fail()], 'GET', 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/messages', false, 'messages'],
            'post' => [fn (GraphClient $g) => $g->post('users/MSG-OWNER/sendMail', ['x' => 1]), [$fail()], 'POST', 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/sendMail', false, 'sendMail'],
            'patch' => [fn (GraphClient $g) => $g->patch('users/MSG-OWNER/messages/MSG-1', ['isRead' => true]), [$fail()], 'PATCH', 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/messages/MSG-1', false, 'messages'],
            'delete' => [fn (GraphClient $g) => $g->delete('users/MSG-OWNER/messages/MSG-1'), [$fail()], 'DELETE', 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/messages/MSG-1', false, 'messages'],
            'getRaw' => [fn (GraphClient $g) => $g->getRaw('users/MSG-OWNER/photo/$value'), [$fail()], 'GET', 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/photo/$value', false, 'photo'],
            'message read (expand)' => [fn (GraphClient $g) => $g->getMessageAttachments(self::MAILBOX, 'MSG-1'), [$fail()], 'GET', 'https://graph.microsoft.com/v1.0/users/support@example.test/messages/MSG-1?%24expand=attachments', true, 'messages'],
            'calendar event' => [fn (GraphClient $g) => $g->getEvent('MSG-OWNER', 'EVT-1'), [$fail()], 'GET', 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/events/EVT-1', false, 'events'],
            // The failing request is the absolute nextLink URL (checked against the history).
            'nextLink page' => [fn (GraphClient $g) => $g->getAllPages('users/MSG-OWNER/messages'), [
                new Response(200, ['Content-Type' => 'application/json'], json_encode(['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/messages?$skip=10'])),
                $fail(),
            ], 'GET', 'https://graph.microsoft.com/v1.0/users/MSG-OWNER/messages?$skip=10', false, 'messages'],
        ];
    }

    #[DataProvider('otherGraphCallFailures')]
    public function test_other_graph_call_failure_still_writes_the_request_failed_error(\Closure $call, array $responses, string $method, string $uri, bool $pathHoldsMailbox, string $operation): void
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
        // #5533 / #5671: exactly method, operation label and status, and no needle anywhere in the
        // record. Any new key or any needle in any part fails here.
        $this->assertSame(['method' => $method, 'operation' => $operation, 'status' => 500], $record->context);
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
        $this->assertSame(['error'], $leaks['graph error text (start)'] ?? null);
    }

    public function test_message_read_failure_records_carry_no_mailbox_or_graph_text(): void
    {
        // The expand read through AttachmentService: GraphClient's record plus the service's own
        // WARNING, whose 'error' is the GraphClientException message.
        $graph = $this->graph([new Response(500, [], json_encode(['error' => ['code' => 'InternalServerError', 'message' => self::GRAPH_ERROR_TEXT]]))]);

        $email = $this->email();
        $this->assertNull(app(AttachmentService::class)->downloadEmailAttachments($email, $graph, self::MAILBOX));

        $records = $this->records();
        $this->assertSame(
            [['ERROR', 'Graph API request failed'], ['WARNING', '[AttachmentService] Failed to fetch email attachments']],
            array_map(fn (LogRecord $r) => [$r->level->getName(), $r->message], $records),
        );
        // #5533 / #5671: GraphClient's record is status-only plus the endpoint-free operation label.
        $this->assertSame(['method' => 'GET', 'operation' => 'messages', 'status' => 500], $records[0]->context);
        $this->assertSame([], $this->graphFailureLeaks($records[0]), '#5533: GraphClient record');
        // b4i / C-56: AttachmentService::downloadEmailAttachments' message-read catch logs ids,
        // the HTTP status and the exception class only; the exception message is not a carrier
        // any more. Exactly these keys and values, so a returning 'error' key fails here.
        $this->assertSame([
            'email_id' => $email->id,
            'status' => 500,
            'exception' => \App\Services\Graph\GraphClientException::class,
        ], $records[1]->context);
        // #5804: the email id is enough; the Graph message id is not in the record.
        $this->assertStringNotContainsString('MSG-1', $records[1]->message.json_encode($records[1]->context));
        $this->assertSame([], $this->graphFailureLeaks($records[1]), 'the WARNING carries no forbidden needle');
        // Positive control: the request that failed did hold the mailbox in its path.
        $this->assertStringContainsString('users/'.self::MAILBOX.'/messages/MSG-1', urldecode((string) end($this->history)['request']->getUri()));
    }

    public function test_a_non_graph_message_read_throw_is_recorded_by_class_with_no_status_and_no_message(): void
    {
        // b4i: the message-read catch takes any Throwable; a non-Graph one's message can name the
        // mailbox, and its code is not an HTTP status.
        $graph = \Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getMessageAttachments')->andThrow(new \RuntimeException(self::NON_GRAPH_DETAIL.' '.self::MAILBOX, 503));
        $email = $this->email();

        $this->assertNull(app(AttachmentService::class)->downloadEmailAttachments($email, $graph, self::MAILBOX));

        $records = $this->records();
        $this->assertCount(1, $records, $this->describe($records));
        $this->assertSame('[AttachmentService] Failed to fetch email attachments', $records[0]->message);
        $this->assertSame(
            ['email_id' => $email->id, 'status' => null, 'exception' => \RuntimeException::class],
            $records[0]->context,
        );
        $this->assertNoLeakInLogs([self::NON_GRAPH_DETAIL, self::MAILBOX]);
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

/** #5631: a configured channel processor (test only). */
class B4nExtraProcessor implements \Monolog\Processor\ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: $record->extra + ['processed' => 'b4n']);
    }
}

/** #5631: a configured channel tap (test only); adds what the container holds to extra. */
class B4nExtraTap
{
    public function __invoke(\Illuminate\Log\Logger $logger): void
    {
        $add = app('b4n.tap-extra');
        $logger->getLogger()->pushProcessor(fn (LogRecord $r) => $r->with(extra: $r->extra + $add));
    }
}
