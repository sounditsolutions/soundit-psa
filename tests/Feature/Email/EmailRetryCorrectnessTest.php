<?php

namespace Tests\Feature\Email;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Email;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\EmailService;
use App\Services\Graph\GraphClient;
use App\Services\NotificationService;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * #5417 residuals, b4b batch 1 (card 6ac5994e): the #5394 after-commit retry of a new ticket's
 * failed message read.
 *  - #5447/#5460: it writes only when, re-checked under lock after its unlocked Graph read, the
 *    email is still on the ticket and the ticket, note and their attachments are unchanged.
 *  - #5454: what its read stored is removed when it links nothing; run twice, it adds nothing.
 *  - #5453: the after-commit notification and technician-loop dispatch see its result.
 *  - #5456/#5446: a missing client note is a refusal with a record, and a throw is recorded.
 *
 * Synthetic data only (example.test, MSG-1 style ids). Graph is a Guzzle MockHandler;
 * Http::preventStrayRequests() refuses any Laravel HTTP client call.
 */
class EmailRetryCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    private const MAILBOX = 'support@example.test';

    private const CID_HTML = '<p>See the screenshot</p><p><img src="cid:img1@synthetic.example.test" alt="shot"></p>';

    private const BODY_TEXT = 'B4B-SYNTHETIC-BODY please look';

    private const SKIPPED = '[EmailService] Attachment retry after ticket creation skipped';

    private const THREW = '[EmailService] Attachment retry after ticket creation threw';

    private const TICKET = ['type' => 'incident', 'status' => 'new', 'priority' => 'p3'];

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        Setting::setValue('graph_mailbox', self::MAILBOX);
        // The queue is sync (phpunit.xml): the first RetryEmailAttachments run executes at
        // commit. Its #5558 re-queue after a refusal is faked here, so each test sees one run;
        // RetryEmailAttachmentsJobTest drives the re-queued run itself.
        Bus::fake([fn ($job) => $job instanceof \App\Jobs\RetryEmailAttachments && $job->refusals > 0]);

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
    private function withMessage(string $message): array
    {
        return array_values(array_filter($this->logs->getRecords(), fn (LogRecord $r) => $r->message === $message));
    }

    private function text(LogRecord $r): string
    {
        return $r->level->getName().' '.$r->message.' '.json_encode($r->context).' '.json_encode($r->extra);
    }

    /** A GraphClient on $responses (Responses or callables run when the request is sent). */
    private function graph(array $responses): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'test-token', 3600);
        $this->app->instance(GraphClient::class, new GraphClient([
            'tenant_id' => 'tenant-b4b-synthetic',
            'client_id' => 'client',
            'client_secret' => 'secret',
            'request_timeout' => 15,
            'token_timeout' => 10,
            'handler' => $stack,
        ], $cache));
    }

    private function messageReads(): int
    {
        return count(array_filter($this->history, fn ($h) => str_ends_with($h['request']->getUri()->getPath(), '/messages/MSG-1')));
    }

    /** A message read whose attachment is an inline image (referenced by CID_HTML) or a plain file. */
    private function read(bool $inline = true): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'MSG-1',
            'attachments' => [$inline ? [
                '@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'ATT-FILE-1', 'name' => 'image001.png',
                'contentType' => 'image/png', 'size' => 26, 'isInline' => true,
                'contentId' => 'img1@synthetic.example.test', 'contentBytes' => base64_encode('synthetic-png-bytes-b4b'),
            ] : [
                '@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'ATT-FILE-2', 'name' => 'report.txt',
                'contentType' => 'text/plain', 'size' => 20, 'isInline' => false,
                'contentBytes' => base64_encode('synthetic-report-b4b'),
            ]],
        ]));
    }

    /** The retry's read, running $during first: the write a concurrent actor makes in its window. */
    private function readDuring(\Closure $during, bool $inline = true): \Closure
    {
        return function () use ($during, $inline) {
            $during();

            return $this->read($inline);
        };
    }

    private function failedRead(): Response
    {
        return new Response(503, [], '{}');
    }

    private function email(array $overrides = []): Email
    {
        $client = Client::create(['name' => 'Example Client']);

        return Email::create($overrides + [
            'graph_id' => 'MSG-1',
            'direction' => 'inbound',
            'from_address' => 'user@example.test',
            'from_name' => 'User',
            'subject' => 'Printer offline',
            'body_text' => self::BODY_TEXT,
            'body_html' => self::CID_HTML,
            'client_id' => $client->id,
            'received_at' => now(),
        ]);
    }

    private function ticketOf(): Ticket
    {
        return Ticket::findOrFail(Email::where('graph_id', 'MSG-1')->firstOrFail()->ticket_id);
    }

    /** @return list<string> */
    private function storedFiles(): array
    {
        return Storage::disk('local')->allFiles('attachments');
    }

    /** Exactly one skip WARNING, status-only, with $reason; nothing linked, nothing left stored. */
    private function assertSkipped(string $reason, int $discarded): void
    {
        $skipped = $this->withMessage(self::SKIPPED);
        $this->assertCount(1, $skipped, 'one skip record');
        $this->assertSame(Level::Warning, $skipped[0]->level);
        $this->assertSame([
            'email_id' => Email::where('graph_id', 'MSG-1')->value('id') ?? $skipped[0]->context['email_id'],
            'ticket_id' => $skipped[0]->context['ticket_id'],
            'reason' => $reason,
            'stored_attachments' => $discarded,
            'discarded_attachments' => $discarded,
            'undiscarded_attachment_ids' => [],
        ], $skipped[0]->context, 'status-only: ids, the reason and counts (#5545)');
        foreach ([self::MAILBOX, self::BODY_TEXT, 'synthetic.example.test', 'image001', 'tech edit'] as $needle) {
            $this->assertStringNotContainsString($needle, $this->text($skipped[0]), 'status-only (C-56)');
        }
        $this->assertSame([], $this->withMessage(self::THREW));
        $this->assertSame(0, Attachment::withTrashed()->count(), 'no Attachment row is left behind');
        $this->assertSame([], $this->storedFiles(), 'no stored file is left behind');
    }

    // ── #5447/#5460: re-check under lock after the unlocked read; write only if unchanged ──

    public function test_control_an_unchanged_ticket_still_gets_the_retry_through_the_same_harness(): void
    {
        $this->graph([$this->failedRead(), $this->readDuring(fn () => null)]);
        $email = $this->email();

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(2, $this->messageReads(), 'positive control: the retry read ran');
        $a = Attachment::sole();
        $note = TicketNote::where('email_id', $email->id)->sole();
        $this->assertSame([TicketNote::class, $note->id], [$a->attachable_type, $a->attachable_id]);
        $this->assertStringContainsString($a->url, (string) $this->ticketOf()->description_html);
        $this->assertStringContainsString($a->url, (string) $note->fresh()->body_html);
        $this->assertSame([], $this->withMessage(self::SKIPPED));
        $this->assertSame([], $this->withMessage(self::THREW));
        $this->assertCount(1, $this->storedFiles());
    }

    /** @return array<string, array{0: \Closure(): void, 1: string, 2: \Closure(EmailRetryCorrectnessTest): void}> */
    public static function editsDuringTheRetryRead(): array
    {
        return [
            'technician rewrites the description' => [
                fn () => Ticket::whereNotNull('id')->firstOrFail()->update(['description' => 'tech edit: replaced toner']),
                'ticket_changed',
                function (self $t) {
                    $ticket = $t->ticketOf();
                    $t->assertSame('tech edit: replaced toner', $ticket->description);
                    $t->assertNull($ticket->description_html, 'the retry did not write description_html back');
                },
            ],
            'description_html written by another writer' => [
                fn () => Ticket::whereNotNull('id')->firstOrFail()->update(['description_html' => '<p>tech edit html</p>']),
                'ticket_changed',
                fn (self $t) => $t->assertSame('<p>tech edit html</p>', $t->ticketOf()->description_html),
            ],
            'email hard-deleted (#5557)' => [
                fn () => Email::where('graph_id', 'MSG-1')->delete(),
                'email_gone',
                fn (self $t) => $t->assertSame(0, Email::count()),
            ],
            'technician edits the client note' => [
                fn () => TicketNote::whereNotNull('email_id')->firstOrFail()->update(['body' => 'tech edit body', 'body_html' => '<p>tech edit note</p>']),
                'note_changed',
                function (self $t) {
                    $note = TicketNote::whereNotNull('email_id')->sole();
                    $t->assertSame('<p>tech edit note</p>', $note->body_html);
                    $t->assertNull($t->ticketOf()->description_html, 'nothing of the retry was written');
                },
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('editsDuringTheRetryRead')]
    public function test_an_edit_made_during_the_retry_read_survives_and_the_retry_writes_nothing(
        \Closure $edit, string $reason, \Closure $survives,
    ): void {
        $this->graph([$this->failedRead(), $this->readDuring($edit)]);

        app(EmailService::class)->autoCreateTicketFromEmail($this->email());

        $this->assertSame(2, $this->messageReads(), 'positive control: the retry read ran after the pre-check');
        $survives($this);
        $this->assertSkipped($reason, discarded: 1);
    }

    public function test_an_email_relinked_during_the_retry_read_gets_no_attachments_on_either_ticket(): void
    {
        $other = null;
        $this->graph([$this->failedRead(), $this->readDuring(function () use (&$other) {
            $email = Email::where('graph_id', 'MSG-1')->firstOrFail();
            $other = Ticket::create(['subject' => 'Merged target', 'client_id' => $email->client_id] + self::TICKET);
            $email->update(['ticket_id' => $other->id]);
        })]);
        $email = $this->email();

        $original = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(2, $this->messageReads(), 'positive control: the retry read ran');
        $this->assertNotNull($other);
        $this->assertSame($other->id, $email->fresh()->ticket_id, 'the relink stands');
        $this->assertNull(Ticket::findOrFail($original->id)->description_html);
        $this->assertNull(TicketNote::where('email_id', $email->id)->sole()->body_html);
        $this->assertSkipped('email_relinked', discarded: 1);
    }

    public function test_an_email_relinked_before_the_retry_makes_no_graph_read(): void
    {
        // An outer transaction defers the retry to ITS commit; the email is unlinked before
        // that, as a merge in the same outer transaction would.
        $this->graph([$this->failedRead(), $this->read()]);
        $email = $this->email();

        DB::transaction(function () use ($email) {
            app(EmailService::class)->autoCreateTicketFromEmail($email);
            Email::whereKey($email->id)->update(['ticket_id' => null]);
        });

        $this->assertSame(1, $this->messageReads(), 'the unlocked pre-check refused before any Graph call');
        $this->assertSkipped('email_relinked', discarded: 0);
    }

    public function test_the_link_re_check_takes_ticket_then_note_then_email_as_merge_tickets_does(): void
    {
        // TicketService::mergeTickets locks tickets, then moves their notes, then their emails.
        // SQLite emits no FOR UPDATE, so the order is read from the link transaction's first
        // statement against each of those tables.
        $reading = false;
        $tables = [];
        $this->graph([$this->failedRead(), $this->readDuring(function () use (&$reading) {
            $reading = true;
        })]);
        $outside = DB::transactionLevel(); // RefreshDatabase's own wrapping transaction
        DB::listen(function (QueryExecuted $q) use (&$reading, &$tables, $outside) {
            if ($reading && DB::transactionLevel() === $outside + 1
                && preg_match('/^select .*? from "(\w+)"/', $q->sql, $m) && in_array($m[1], ['tickets', 'ticket_notes', 'emails'], true)) {
                $tables[] = $m[1];
            }
        });
        $email = $this->email();

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $a = Attachment::sole();
        $this->assertSame(
            [TicketNote::class, TicketNote::where('email_id', $email->id)->sole()->id],
            [$a->attachable_type, $a->attachable_id],
            'positive control: the link transaction ran and linked',
        );
        $this->assertSame(['tickets', 'ticket_notes', 'emails'], array_slice(array_values(array_unique($tables)), 0, 3));
    }

    // ── #5454: no orphans when the retry links nothing; idempotent when it runs again ──

    public function test_a_failing_link_transaction_leaves_no_attachment_row_or_file(): void
    {
        $this->graph([$this->failedRead(), $this->read()]);
        // The link transaction's first write is the ticket's description_html; make it throw.
        Ticket::updating(function (Ticket $t) {
            if ($t->isDirty('description_html') && $t->description_html !== null) {
                throw new \RuntimeException('B4B-SYNTHETIC-DEADLOCK');
            }
        });
        $email = $this->email();

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(2, $this->messageReads(), 'positive control: the retry read ran and stored');
        $threw = $this->withMessage(self::THREW);
        $this->assertCount(1, $threw);
        $this->assertSame(Level::Warning, $threw[0]->level);
        $this->assertSame([
            'email_id' => $email->id, 'ticket_id' => $ticket->id, 'stage' => 'link', 'linked' => false,
            'exception' => \RuntimeException::class,
            'stored_attachments' => 1, 'discarded_attachments' => 1, 'undiscarded_attachment_ids' => [],
        ], $threw[0]->context, 'status-only: ids, stage, class, counts');
        $this->assertStringNotContainsString('B4B-SYNTHETIC-DEADLOCK', $this->text($threw[0]));
        $this->assertSame(0, Attachment::withTrashed()->count(), 'no orphan Attachment row');
        $this->assertSame([], $this->storedFiles(), 'no orphan stored file');
        $this->assertNull(Ticket::findOrFail($ticket->id)->description_html, 'rolled back');
    }

    public function test_a_throw_while_storing_the_read_discards_what_was_already_stored(): void
    {
        // Two attachments; storing the second throws after the first is stored.
        $this->graph([$this->failedRead(), new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'MSG-1',
            'attachments' => [
                ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'ATT-1', 'name' => 'a.txt',
                    'contentType' => 'text/plain', 'isInline' => false, 'contentBytes' => base64_encode('synthetic-a')],
                ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'ATT-2', 'name' => 'b.txt',
                    'contentType' => 'text/plain', 'isInline' => false, 'contentBytes' => base64_encode('synthetic-b')],
            ],
        ]))]);
        $email = $this->email();
        $made = 0;
        Attachment::creating(function () use (&$made) {
            if (++$made === 2) {
                throw new \RuntimeException('B4B-SYNTHETIC-DISK-FULL');
            }
        });

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $threw = $this->withMessage(self::THREW);
        $this->assertCount(1, $threw);
        $this->assertSame(['download', false, 1, 1, []], [$threw[0]->context['stage'], $threw[0]->context['linked'],
            $threw[0]->context['stored_attachments'], $threw[0]->context['discarded_attachments'], $threw[0]->context['undiscarded_attachment_ids']]);
        $this->assertSame(0, Attachment::withTrashed()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_discard_whose_lookup_throws_is_recorded_and_the_later_commit_callbacks_still_run(): void
    {
        $this->graph([$this->failedRead(), $this->read()]);
        $notified = new \ArrayObject;
        $this->app->instance(NotificationService::class, new class($notified) extends NotificationService
        {
            public function __construct(private \ArrayObject $notified) {}

            public function notifyEmailAdded(Ticket $ticket, Email $email): void
            {
                $this->notified[] = $ticket->id;
            }

            public function notifyTicketCreated(Ticket $ticket): void {}
        });
        // The link transaction's first write throws (rolled back); then the discard's own read of
        // the stored rows throws, as on a connection that has gone away.
        $armed = false;
        Ticket::updating(function (Ticket $t) use (&$armed) {
            if ($t->isDirty('description_html') && $t->description_html !== null) {
                $armed = true;
                throw new \RuntimeException('B4B-SYNTHETIC-DEADLOCK');
            }
        });
        DB::beforeExecuting(function (string $sql) use (&$armed) {
            if ($armed && str_contains($sql, 'from "attachments"') && str_contains($sql, '"id" in (')) {
                $armed = false;
                throw new \RuntimeException('B4B-SYNTHETIC-CONNECTION-LOST');
            }
        });
        $email = $this->email();

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(2, $this->messageReads(), 'positive control: the retry read ran and stored');
        $this->assertFalse($armed, 'positive control: the discard lookup was reached and threw');
        $threw = $this->withMessage(self::THREW);
        $this->assertCount(1, $threw);
        $this->assertSame(Level::Warning, $threw[0]->level);
        $this->assertSame([
            'email_id' => $email->id, 'ticket_id' => $ticket->id, 'stage' => 'link', 'linked' => false,
            'exception' => \RuntimeException::class,
            'stored_attachments' => 1, 'discarded_attachments' => 0, 'undiscarded_attachment_ids' => [Attachment::withTrashed()->sole()->id],
        ], $threw[0]->context, 'status-only: ids, stage, class, counts; #5554: the ids the failed lookup could not discard');
        foreach (['B4B-SYNTHETIC-DEADLOCK', 'B4B-SYNTHETIC-CONNECTION-LOST'] as $needle) {
            $this->assertStringNotContainsString($needle, $this->text($threw[0]));
        }
        $this->assertSame([$ticket->id], $notified->getArrayCopy(), 'notifyEmailAdded, registered after the retry, still ran');
        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
        $this->assertSame(1, Attachment::withTrashed()->count(), 'the row the failed lookup could not discard is left; the record counts 0 discarded');
    }

    /** Runs the retry again, with the creation-time baseline, as a duplicate after-commit delivery would. */
    private function runRetryAgain(array $baseline): void
    {
        $service = app(EmailService::class);
        $email = Email::where('graph_id', 'MSG-1')->firstOrFail();
        $noteId = TicketNote::where('email_id', $email->id)->sole()->id;
        (fn () => $this->retryMessageRead($email->id, (int) $email->ticket_id, $noteId, $baseline))->call($service);
    }

    /** retryBaseline() for the email's ticket and note, read now. */
    private function baselineNow(): array
    {
        $email = Email::where('graph_id', 'MSG-1')->firstOrFail();
        $noteId = TicketNote::where('email_id', $email->id)->sole()->id;

        return (fn () => $this->retryBaseline((int) $email->ticket_id, $noteId))->call(app(EmailService::class));
    }

    public function test_a_retry_delivered_again_after_success_creates_no_duplicate_rows_or_files(): void
    {
        $baseline = null;
        $this->graph([$this->failedRead(), $this->readDuring(function () use (&$baseline) {
            $baseline = $this->baselineNow(); // the creation-time state, before the retry writes
        }), $this->read()]);
        app(EmailService::class)->autoCreateTicketFromEmail($this->email());
        $this->assertSame(1, Attachment::count(), 'positive control: the first retry linked');
        $files = $this->storedFiles();

        $this->runRetryAgain($baseline);

        $this->assertSame(2, $this->messageReads(), 'the pre-check refused the second run before any Graph call');
        $this->assertSame(1, Attachment::withTrashed()->count(), 'no duplicate Attachment row');
        $this->assertSame($files, $this->storedFiles(), 'no duplicate stored file');
        $skipped = $this->withMessage(self::SKIPPED);
        $this->assertCount(1, $skipped);
        // #5556: the first run wrote description_html (inline image), so the second is refused on the ticket.
        $this->assertSame(['ticket_changed', 0], [$skipped[0]->context['reason'], $skipped[0]->context['discarded_attachments']]);
    }

    public function test_two_overlapping_retries_link_one_set_and_discard_the_other(): void
    {
        // The first delivery's Graph read is slow; a second delivery runs to completion inside it.
        // A plain (not inline) attachment, so neither run rewrites description_html or the note's
        // body_html, and the linked attachments are the only state that tells them apart.
        $this->graph([$this->failedRead(), $this->readDuring(function () {
            $this->runRetryAgain($this->baselineNow());
        }, inline: false), $this->read(inline: false)]);
        $email = $this->email();

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(3, $this->messageReads(), 'positive control: both deliveries read Graph');
        $this->assertSame(1, Attachment::withTrashed()->count(), 'one linked set, no duplicate row');
        $this->assertCount(1, $this->storedFiles(), 'no duplicate stored file');
        $a = Attachment::sole();
        $this->assertSame(TicketNote::class, $a->attachable_type);
        $this->assertSame(TicketNote::where('email_id', $email->id)->sole()->id, $a->attachable_id, 'the surviving set is the linked one');
        $this->assertSame([$a->storage_path], $this->storedFiles(), '#5550: the file left is the linked row\'s own');
        $this->assertSame('synthetic-report-b4b', Storage::disk('local')->get($a->storage_path));
        $skipped = $this->withMessage(self::SKIPPED);
        $this->assertCount(1, $skipped);
        $this->assertSame(['attachments_changed', 1], [$skipped[0]->context['reason'], $skipped[0]->context['discarded_attachments']]);
    }

    // ── #5453: the after-commit notification and technician loop see the retry's result ──

    /** @return array<string, array{0: bool}> */
    public static function firstReadOutcomes(): array
    {
        return ['first read succeeds' => [false], 'first read fails, retry succeeds' => [true]];
    }

    /** What a commit-time consumer sees: linked attachments, description_html and note body_html. */
    public static function snapshot(int $ticketId): array
    {
        $ticket = Ticket::findOrFail($ticketId);
        $note = TicketNote::where('ticket_id', $ticketId)->whereNotNull('email_id')->first();

        return [
            'level' => DB::transactionLevel(),
            'linked' => Attachment::whereNotNull('attachable_id')->count(),
            'description_resolved' => str_contains((string) $ticket->description_html, '/attachments/'),
            'note_resolved' => str_contains((string) $note?->body_html, '/attachments/'),
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('firstReadOutcomes')]
    public function test_commit_time_notification_and_technician_loop_see_the_attachments(bool $firstReadFails): void
    {
        Setting::setValue('technician_enabled', true);
        $seen = new \ArrayObject;
        $this->app->instance(NotificationService::class, new class($seen) extends NotificationService
        {
            public function __construct(private \ArrayObject $seen) {}

            public function notifyEmailAdded(Ticket $ticket, Email $email): void
            {
                $this->seen['notifyEmailAdded'] = EmailRetryCorrectnessTest::snapshot($ticket->id);
            }

            public function notifyTicketCreated(Ticket $ticket): void {}
        });
        // The real queue is sync (phpunit.xml), so the afterCommit dispatch runs the job at
        // commit; the mapped handler records what it sees instead of running the loop.
        $this->app->instance('b4b.seen', $seen);
        Bus::map([\App\Jobs\RunTechnicianLoop::class => B4bTechnicianLoopSpy::class]);
        $this->graph(array_merge($firstReadFails ? [$this->failedRead()] : [], [$this->read()]));

        $outside = DB::transactionLevel(); // RefreshDatabase's own wrapping transaction
        app(EmailService::class)->autoCreateTicketFromEmail($this->email());

        $this->assertSame($firstReadFails ? 2 : 1, $this->messageReads());
        $done = ['level' => $outside, 'linked' => 1, 'description_resolved' => true, 'note_resolved' => true];
        $this->assertSame($done, $seen['notifyEmailAdded'] ?? null, 'notifyEmailAdded sees the attachments');
        $this->assertSame($done, $seen['RunTechnicianLoop:after-commit'] ?? null, 'the after-commit technician loop sees them');
    }

    // ── #5456/#5446: a missing client note is refused with a record; a throw is recorded ──

    public function test_a_client_note_deleted_before_the_retry_is_refused_with_a_status_only_record(): void
    {
        $this->graph([$this->failedRead(), $this->readDuring(fn () => TicketNote::whereNotNull('email_id')->firstOrFail()->delete())]);
        $email = $this->email();

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(2, $this->messageReads(), 'positive control: the retry read ran');
        $this->assertNull(Ticket::findOrFail($ticket->id)->description_html, 'the ticket half is not written either');
        $this->assertSkipped('client_note_missing', discarded: 1);
    }

    public function test_a_retry_scheduled_without_a_client_note_is_refused_with_a_status_only_record(): void
    {
        // The only way the retry is reached with no note: it is always scheduled with one
        // (linkEmailToTicket creates a note whenever graph_id is set), so drive it directly.
        $this->graph([$this->read()]);
        $email = $this->email();
        $ticket = Ticket::create(['subject' => 'Synthetic', 'client_id' => $email->client_id] + self::TICKET);
        $email->update(['ticket_id' => $ticket->id]);
        $service = app(EmailService::class);
        $baseline = (fn () => $this->retryBaseline($ticket->id, null))->call($service);

        (fn () => $this->retryMessageRead($email->id, $ticket->id, null, $baseline))->call($service);

        $this->assertSame(0, $this->messageReads(), 'refused before any Graph call');
        $this->assertSkipped('client_note_missing', discarded: 0);
    }

    // ── #5557/#5551/#5550: every pre-check refusal is recorded, with its own reason ──

    /** @return array<string, array{0: \Closure(): void, 1: string}> */
    public static function stateGoneBeforeTheRetry(): array
    {
        return [
            'email hard-deleted' => [fn () => Email::where('graph_id', 'MSG-1')->delete(), 'email_gone'],
            'graph_id cleared' => [fn () => Email::where('graph_id', 'MSG-1')->update(['graph_id' => null]), 'graph_id_missing'],
            'mailbox setting cleared' => [fn () => Setting::setValue('graph_mailbox', ''), 'mailbox_unset'],
            'ticket soft-deleted' => [fn () => Ticket::query()->firstOrFail()->delete(), 'ticket_gone'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stateGoneBeforeTheRetry')]
    public function test_state_gone_before_the_retry_is_refused_with_its_own_reason_and_no_graph_read(\Closure $gone, string $reason): void
    {
        // An outer transaction defers the retry to its commit; the state goes before that.
        $this->graph([$this->failedRead(), $this->read()]);
        $email = $this->email();

        DB::transaction(function () use ($email, $gone) {
            app(EmailService::class)->autoCreateTicketFromEmail($email);
            $gone();
        });

        $this->assertSame(1, $this->messageReads(), 'refused before any Graph call');
        $skipped = $this->withMessage(self::SKIPPED);
        $this->assertCount(1, $skipped, '#5557: never a silent return');
        $this->assertSame($email->id, $skipped[0]->context['email_id']);
        $this->assertSame($reason, $skipped[0]->context['reason']);
        $this->assertSame([0, 0, []], [$skipped[0]->context['stored_attachments'], $skipped[0]->context['discarded_attachments'], $skipped[0]->context['undiscarded_attachment_ids']]);
        $this->assertSame(0, Attachment::withTrashed()->count());
    }

    public function test_a_retry_whose_baseline_was_never_set_is_refused_as_baseline_missing(): void
    {
        // #5551: the callback reads the baseline when it runs; a throw between registration and
        // the baseline that a caller catches before committing leaves it unset.
        $this->graph([$this->failedRead(), $this->read()]);
        $email = $this->email();
        $service = app(EmailService::class);

        DB::transaction(function () use ($email) {
            \App\Models\TicketNote::creating(function () {
                throw new \RuntimeException('B4G-SYNTHETIC-NOTE-FAIL');
            });
            try {
                app(EmailService::class)->autoCreateTicketFromEmail($email);
            } catch (\RuntimeException) {
                // A caller that swallows the throw and commits. autoCreate's own nested
                // transaction rolled back to its savepoint; its after-commit callback was
                // registered at that level and is discarded with it.
            }
        });
        $this->assertSame(1, $this->messageReads(), 'positive control: the first read ran and failed');
        $this->assertSame([], $this->withMessage(self::SKIPPED), 'a rolled-back level runs no retry');

        // The callback as registered, run with the baseline it would hold when unset.
        (fn () => $this->retryMessageRead($email->id, 1, null, null))->call($service);

        $this->assertSame(1, $this->messageReads(), 'refused before any Graph call');
        $skipped = $this->withMessage(self::SKIPPED);
        $this->assertCount(1, $skipped);
        $this->assertSame('baseline_missing', $skipped[0]->context['reason']);
    }

    public function test_a_retry_with_model_events_suppressed_is_refused_as_tracking_unavailable(): void
    {
        // #5555: with no dispatcher nothing the read stores could be found again for discard.
        $this->graph([$this->failedRead(), $this->read()]);
        $email = $this->email();

        DB::transaction(function () use ($email) {
            app(EmailService::class)->autoCreateTicketFromEmail($email);
            Attachment::unsetEventDispatcher();
        });
        Attachment::setEventDispatcher($this->app['events']);

        $this->assertSame(1, $this->messageReads(), 'refused before any Graph call');
        $this->assertSame('tracking_unavailable', $this->withMessage(self::SKIPPED)[0]->context['reason'] ?? null);
        $this->assertSame(0, Attachment::withTrashed()->count());
    }

    private function twoFileRead(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'MSG-1',
            'attachments' => [
                ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'ATT-1', 'name' => 'a.txt',
                    'contentType' => 'text/plain', 'isInline' => false, 'contentBytes' => base64_encode('synthetic-a')],
                ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'ATT-2', 'name' => 'b.txt',
                    'contentType' => 'text/plain', 'isInline' => false, 'contentBytes' => base64_encode('synthetic-b')],
            ],
        ]));
    }

    public function test_flushed_attachment_listeners_are_re_registered_so_a_partial_store_is_still_discarded(): void
    {
        // #5555: after Attachment::flushEventListeners() on the same dispatcher (a long-running
        // worker, a test helper), the created-listener is the only record of a row stored before
        // a later store threw (the read never returns it). It must be registered again.
        $this->graph([$this->failedRead(), $this->read(inline: false), $this->failedRead(), $this->twoFileRead()]);
        app(EmailService::class)->autoCreateTicketFromEmail($this->email()); // registers the listener
        $this->assertSame(1, Attachment::count(), 'positive control: the first retry linked');
        $first = Attachment::sole();
        Attachment::flushEventListeners();
        // flush removes Attachment::booted's own forceDeleting hook too; put the same hook back.
        Attachment::forceDeleting(fn (Attachment $a) => Storage::disk('local')->delete($a->storage_path));
        $made = 0;
        Attachment::creating(function () use (&$made) {
            if (++$made === 2) {
                throw new \RuntimeException('B4G-SYNTHETIC-DISK-FULL');
            }
        });
        $this->logs->clear();
        Email::where('graph_id', 'MSG-1')->update(['graph_id' => null]); // graph_id is unique

        app(EmailService::class)->autoCreateTicketFromEmail($this->email(['subject' => 'Second']));

        $threw = $this->withMessage(self::THREW);
        $this->assertCount(1, $threw);
        $this->assertSame(['download', 1, 1, []], [$threw[0]->context['stage'], $threw[0]->context['stored_attachments'],
            $threw[0]->context['discarded_attachments'], $threw[0]->context['undiscarded_attachment_ids']]);
        $this->assertSame([$first->id], Attachment::withTrashed()->pluck('id')->all(), 'only the first ticket\'s attachment is left');
        $this->assertCount(1, $this->storedFiles());
    }

    public function test_listeners_flushed_during_the_retry_read_still_leave_what_the_read_returned_discarded(): void
    {
        // #5555: the listener is gone while the read stores; the rows the read returns are
        // tracked anyway, so a refusal still removes them.
        $this->graph([$this->failedRead(), $this->readDuring(function () {
            Attachment::flushEventListeners();
            Attachment::forceDeleting(fn (Attachment $a) => Storage::disk('local')->delete($a->storage_path));
            Ticket::query()->firstOrFail()->update(['description' => 'tech edit']);
        })]);

        app(EmailService::class)->autoCreateTicketFromEmail($this->email());

        $this->assertSame(2, $this->messageReads(), 'positive control: the retry read ran');
        $this->assertSkipped('ticket_changed', discarded: 1);
    }

    // ── #5544/#5545/#5550/#5553: what the records say on a refusal, a failed discard and a late throw ──

    public function test_a_refusal_whose_record_throws_says_linked_false_and_does_not_discard_twice(): void
    {
        // #5544: the refusal's (empty) link transaction commits; that must not read as a link.
        $this->graph([$this->failedRead(), $this->readDuring(
            fn () => Ticket::query()->firstOrFail()->update(['description' => 'tech edit']),
        )]);
        $email = $this->email();
        $once = false;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($e) use (&$once) {
            if ($e->message === self::SKIPPED && ! $once) {
                $once = true;
                throw new \RuntimeException('B4G-SYNTHETIC-LOG-FAIL');
            }
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertTrue($once, 'positive control: the skip record threw');
        $threw = $this->withMessage(self::THREW);
        $this->assertCount(1, $threw);
        $this->assertSame([
            'email_id' => $email->id, 'ticket_id' => $ticket->id, 'stage' => 'discard', 'linked' => false,
            'exception' => \RuntimeException::class,
            'stored_attachments' => 1, 'discarded_attachments' => 1, 'undiscarded_attachment_ids' => [],
        ], $threw[0]->context, 'linked false, and the discard that already ran is reported, not repeated');
        $this->assertSame(0, Attachment::withTrashed()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_nested_level_callback_that_throws_after_the_link_commits_keeps_the_linked_attachment(): void
    {
        // #5708: a callback registered inside a nested transaction is staged ahead of the link
        // level's $committed flag, so it throws with the flag still false although the link
        // committed. The committed link is read back; nothing linked is discarded.
        $this->graph([$this->failedRead(), $this->read()]);
        $email = $this->email();
        Attachment::updated(function (Attachment $a) {
            if ($a->attachable_type === TicketNote::class) {
                DB::transaction(fn () => DB::afterCommit(fn () => throw new \RuntimeException('B4I-SYNTHETIC-NESTED')));
            }
        });

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $threw = $this->withMessage(self::THREW);
        $this->assertCount(1, $threw, 'positive control: the nested callback threw into the retry');
        $this->assertSame([true, 0, []], [$threw[0]->context['linked'], $threw[0]->context['discarded_attachments'],
            $threw[0]->context['undiscarded_attachment_ids']]);
        $a = Attachment::sole();
        $this->assertSame([TicketNote::class, TicketNote::where('email_id', $email->id)->sole()->id], [$a->attachable_type, $a->attachable_id]);
        $this->assertSame([$a->storage_path], $this->storedFiles());
        $this->assertSame([], $this->withMessage(\App\Jobs\RetryEmailAttachments::MARKER), 'linked: no not-added marker');
    }

    public function test_a_logger_that_always_fails_does_not_escape_the_retry(): void
    {
        // #5710: the refusal's record throws, and so does the catch arm's. The retry still
        // returns, so the job writes its note and runs the commit work.
        $notified = new \ArrayObject;
        $this->app->instance(NotificationService::class, new class($notified) extends NotificationService
        {
            public function __construct(private \ArrayObject $notified) {}

            public function notifyEmailAdded(Ticket $ticket, Email $email): void
            {
                $this->notified[] = $ticket->id;
            }

            public function notifyTicketCreated(Ticket $ticket): void {}
        });
        $this->graph([$this->failedRead(), $this->readDuring(
            fn () => Ticket::query()->firstOrFail()->update(['description' => 'tech edit']),
        )]);
        $email = $this->email();
        $failed = 0;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($e) use (&$failed) {
            if (str_starts_with($e->message, '[EmailService] Attachment retry')) {
                $failed++;
                throw new \RuntimeException('B4I-SYNTHETIC-LOGGER');
            }
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(2, $failed, 'positive control: the skip record and the catch arm record both threw');
        $this->assertSame([$ticket->id], $notified->getArrayCopy(), 'the commit work still ran');
        $this->assertSame(1, TicketNote::where('ticket_id', $ticket->id)->where('note_type', 'system')->count(), 'the marker note was written');
    }

    public function test_a_throw_after_the_link_commits_says_linked_true_and_keeps_the_linked_attachment(): void
    {
        // #5550: an after-commit callback registered inside the link transaction (after the
        // $committed flag) throws once the link has committed; nothing linked may be discarded.
        $this->graph([$this->failedRead(), $this->read()]);
        $email = $this->email();
        Attachment::updated(function (Attachment $a) {
            if ($a->attachable_type === TicketNote::class) {
                DB::afterCommit(fn () => throw new \RuntimeException('B4G-SYNTHETIC-LATE'));
            }
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $threw = $this->withMessage(self::THREW);
        $this->assertCount(1, $threw);
        $this->assertSame([
            'email_id' => $email->id, 'ticket_id' => $ticket->id, 'stage' => 'link', 'linked' => true,
            'exception' => \RuntimeException::class,
            'stored_attachments' => 1, 'discarded_attachments' => 0, 'undiscarded_attachment_ids' => [],
        ], $threw[0]->context, 'linked true: one stored, none discarded');
        $a = Attachment::sole();
        $this->assertSame([TicketNote::class, TicketNote::where('email_id', $email->id)->sole()->id], [$a->attachable_type, $a->attachable_id]);
        $this->assertSame([$a->storage_path], $this->storedFiles(), 'the linked row\'s own file is kept');
    }

    public function test_a_force_delete_that_throws_is_reported_by_id(): void
    {
        // #5545: of two stored rows, the second's force-delete throws.
        $this->graph([$this->failedRead(), function () {
            Ticket::query()->firstOrFail()->update(['description' => 'tech edit']);

            return $this->twoFileRead();
        }]);
        $email = $this->email();
        $seen = 0;
        Attachment::forceDeleting(function () use (&$seen) {
            if (++$seen === 2) {
                throw new \RuntimeException('B4G-SYNTHETIC-LOCK-WAIT');
            }
        });

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $left = Attachment::withTrashed()->sole();
        $skipped = $this->withMessage(self::SKIPPED);
        $this->assertCount(1, $skipped);
        $this->assertSame(['ticket_changed', 2, 1, [$left->id]], [$skipped[0]->context['reason'], $skipped[0]->context['stored_attachments'],
            $skipped[0]->context['discarded_attachments'], $skipped[0]->context['undiscarded_attachment_ids']]);
    }

    public function test_a_halted_force_delete_keeps_the_row_and_is_reported_by_id_not_counted_discarded(): void
    {
        // #5718: a later forceDeleting listener returns false after the model's own hook removed
        // the file; forceDelete() returns false and the row stays.
        $this->graph([$this->failedRead(), $this->readDuring(
            fn () => Ticket::query()->firstOrFail()->update(['description' => 'tech edit']),
        )]);
        $email = $this->email();
        // The file goes first (as the model's own forceDeleting hook does), then a guard halts.
        Attachment::forceDeleting(function (Attachment $a) {
            Storage::disk('local')->delete($a->storage_path);

            return false;
        });

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $left = Attachment::withTrashed()->sole();
        $this->assertSame([], $this->storedFiles(), 'positive control: the file is gone, only the row stays');
        $skipped = $this->withMessage(self::SKIPPED);
        $this->assertSame([1, 0, [$left->id]], [$skipped[0]->context['stored_attachments'],
            $skipped[0]->context['discarded_attachments'], $skipped[0]->context['undiscarded_attachment_ids']]);
    }

    public function test_a_check_that_throws_after_a_successful_force_delete_reports_the_id_as_left_and_does_not_escape(): void
    {
        // #5717 (kept conservative, not changed): the delete succeeds and the files() check
        // afterwards throws. What is left cannot be confirmed, so the id is reported as left;
        // the throw does not escape the discard.
        $this->graph([$this->failedRead(), $this->readDuring(function () {
            Ticket::query()->firstOrFail()->update(['description' => 'tech edit']);
            // Only files() fails; the writes, deletes and exists() pass through.
            $real = Storage::disk('local');
            $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class, [$real->getDriver(), $real->getAdapter(), $real->getConfig()])->makePartial();
            $disk->shouldReceive('files')->andThrow(new \RuntimeException('B4I-SYNTHETIC-FS'));
            Storage::set('local', $disk);
        })]);
        $email = $this->email();
        $deleted = null;
        Attachment::forceDeleted(function (Attachment $a) use (&$deleted) {
            $deleted = $a->id;
        });

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertNotNull($deleted, 'positive control: the delete itself succeeded');
        $this->assertSame(0, Attachment::withTrashed()->count(), 'the row is gone');
        $skipped = $this->withMessage(self::SKIPPED);
        $this->assertSame([1, 0, [$deleted]], [$skipped[0]->context['stored_attachments'],
            $skipped[0]->context['discarded_attachments'], $skipped[0]->context['undiscarded_attachment_ids']]);
        $this->assertSame([], $this->withMessage(self::THREW), 'the check failure did not escape the discard');
    }

    public function test_a_file_the_disk_did_not_delete_is_reported_by_id(): void
    {
        // #5545: Storage::delete returns false rather than throwing; the row goes, the file stays.
        $this->graph([$this->failedRead(), $this->readDuring(
            fn () => Ticket::query()->firstOrFail()->update(['description' => 'tech edit']),
        )]);
        $email = $this->email();
        $kept = null;
        Attachment::forceDeleting(function (Attachment $a) use (&$kept) {
            $kept = $a->id;
            $a->storage_path = 'attachments/elsewhere/none'; // the model hook deletes a path that is not the file
        });

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertNotNull($kept, 'positive control: the discard ran');
        $this->assertCount(1, $this->storedFiles(), 'the file is still on disk');
        $skipped = $this->withMessage(self::SKIPPED);
        $this->assertSame([1, 0, [$kept]], [$skipped[0]->context['stored_attachments'],
            $skipped[0]->context['discarded_attachments'], $skipped[0]->context['undiscarded_attachment_ids']]);
    }

    public function test_a_failed_storage_path_update_leaves_no_placeholder_row_and_no_orphan_file(): void
    {
        // #5553: storeFromContent's put succeeds, its storage_path update throws.
        $this->graph([$this->failedRead(), $this->read()]);
        $email = $this->email();
        Attachment::updating(function (Attachment $a) {
            if ($a->isDirty('storage_path')) {
                throw new \RuntimeException('B4G-SYNTHETIC-UPDATE-FAIL');
            }
        });

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(2, $this->messageReads(), 'positive control: the retry read ran and stored');
        $threw = $this->withMessage(self::THREW);
        $this->assertCount(1, $threw);
        $this->assertSame(['download', 1, 1, []], [$threw[0]->context['stage'], $threw[0]->context['stored_attachments'],
            $threw[0]->context['discarded_attachments'], $threw[0]->context['undiscarded_attachment_ids']]);
        $this->assertSame(0, Attachment::withTrashed()->where('storage_path', 'attachments/tmp')->count(), 'no row names the placeholder');
        $this->assertSame(0, Attachment::withTrashed()->count());
        $this->assertSame([], $this->storedFiles(), 'no orphan file under attachments/{id}/');
    }

    public function test_store_from_content_removes_its_row_and_file_when_the_storage_path_update_fails(): void
    {
        // #5553, every caller: no row is left naming 'attachments/tmp', no file at attachments/{id}/.
        Attachment::updating(function (Attachment $a) {
            if ($a->isDirty('storage_path')) {
                throw new \RuntimeException('B4G-SYNTHETIC-UPDATE-FAIL');
            }
        });
        $made = null;
        Attachment::created(function (Attachment $a) use (&$made) {
            $made = $a->id;
        });

        try {
            app(\App\Services\AttachmentService::class)->storeFromContent('synthetic-c', 'c.txt', 'text/plain');
            $this->fail('the update failure is rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('B4G-SYNTHETIC-UPDATE-FAIL', $e->getMessage(), 'rethrown unchanged');
        }

        $this->assertNotNull($made, 'positive control: the row was created');
        $this->assertSame(0, Attachment::withTrashed()->count(), 'no placeholder row');
        $this->assertSame([], $this->storedFiles(), 'no file');
    }

    // ── #5445: the retry builds the forward-provenance line exactly as a first read does ──

    #[\PHPUnit\Framework\Attributes\DataProvider('firstReadOutcomes')]
    public function test_a_forwarded_email_gets_the_same_note_and_description_on_a_retry_as_on_a_first_read(bool $firstReadFails): void
    {
        $this->graph(array_merge($firstReadFails ? [$this->failedRead()] : [], [$this->read()]));
        $email = $this->email([
            'from_address' => 'tech@example.test',
            'from_name' => 'Tech Person',
            'subject' => 'FW: Printer offline',
            'body_text' => "FYI\n\nFrom: Jane Doe <jane@client.example.test>\nSent: Thursday, May 28, 2026\nTo: Tech Person\nSubject: Printer offline\n\nThe printer is still offline.",
        ]);

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame($firstReadFails ? 2 : 1, $this->messageReads());
        $ticket = $this->ticketOf();
        $note = TicketNote::where('email_id', $email->id)->sole();
        $a = Attachment::sole();
        $resolved = str_replace('cid:img1@synthetic.example.test', $a->url, self::CID_HTML);
        $expectedDescription = \App\Helpers\HtmlSanitizer::sanitize($resolved);
        $this->assertSame($expectedDescription, $ticket->description_html, 'description_html byte-for-byte');
        $this->assertSame(
            '<p>'.e("[Forwarded into {$ticket->display_id} by Tech Person]").'</p>'.$expectedDescription,
            $note->body_html,
            'note body_html byte-for-byte, provenance line first',
        );
        $this->assertSame('Jane Doe', $note->author_name);
    }
}

/** Mapped as RunTechnicianLoop's handler in one test: records what the job would see. */
class B4bTechnicianLoopSpy
{
    public function handle(\App\Jobs\RunTechnicianLoop $job): void
    {
        $ticketId = (fn () => $this->ticketId)->call($job);
        $seen = app('b4b.seen');
        // RefreshDatabase holds one wrapping transaction; inside autoCreate's own it is 2.
        $key = DB::transactionLevel() <= 1 ? 'RunTechnicianLoop:after-commit' : 'RunTechnicianLoop:in-transaction';
        $seen[$key] = EmailRetryCorrectnessTest::snapshot($ticketId);
    }
}
