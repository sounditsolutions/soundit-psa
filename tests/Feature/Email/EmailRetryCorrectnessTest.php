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

    private const SKIPPED = '[EmailService] Attachment retry after ticket creation skipped; ticket state changed since creation';

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
        $this->assertSame(['email_id', 'ticket_id', 'reason', 'discarded_attachments'], array_keys($skipped[0]->context));
        $this->assertSame($reason, $skipped[0]->context['reason']);
        $this->assertSame($discarded, $skipped[0]->context['discarded_attachments']);
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
            'exception' => \RuntimeException::class, 'discarded_attachments' => 1,
        ], $threw[0]->context, 'status-only: ids, stage, class, count');
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
        $this->assertSame(['download', false, 1], [$threw[0]->context['stage'], $threw[0]->context['linked'], $threw[0]->context['discarded_attachments']]);
        $this->assertSame(0, Attachment::withTrashed()->count());
        $this->assertSame([], $this->storedFiles());
    }

    /** Runs the retry again, with the creation-time baseline, as a duplicate after-commit delivery would. */
    private function runRetryAgain(array $baseline): void
    {
        $service = app(EmailService::class);
        $email = Email::where('graph_id', 'MSG-1')->firstOrFail();
        $noteId = TicketNote::where('email_id', $email->id)->sole()->id;
        (fn () => $this->retryMessageReadAfterCommit($email->id, (int) $email->ticket_id, $noteId, $baseline))->call($service);
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

        (fn () => $this->retryMessageReadAfterCommit($email->id, $ticket->id, null, $baseline))->call($service);

        $this->assertSame(0, $this->messageReads(), 'refused before any Graph call');
        $this->assertSkipped('client_note_missing', discarded: 0);
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
