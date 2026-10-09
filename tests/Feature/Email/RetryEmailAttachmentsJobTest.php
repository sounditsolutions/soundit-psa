<?php

namespace Tests\Feature\Email;

use App\Jobs\RetryEmailAttachments;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * b4i (card 6ac5994e): the queued RetryEmailAttachments job.
 *  - #5457: queued after commit; the webhook's 202 does not wait on the retry's Graph read.
 *  - #5549/#5453: the job owns notifyEmailAdded and the technician dispatch, after the retry.
 *  - #5558: a refusal is re-queued once by the job's own counter; a final refusal, failed read,
 *    throw or timeout writes a status-only WARNING and a private System note.
 *  - Jeeves 15:50Z 10/7: $timeout/$tries are the job's own, inside the worker's 30s/2.
 *
 * Synthetic data only (example.test, MSG-1 style ids). Graph is a Guzzle MockHandler whose
 * queue must be drained (no spare responses, #5714); Http::preventStrayRequests() refuses any
 * Laravel HTTP client call.
 */
class RetryEmailAttachmentsJobTest extends TestCase
{
    use RefreshDatabase;
    use ResetsRetryEmailAttachmentsStatics;

    private const MAILBOX = 'support@example.test';

    private const CID_HTML = '<p>See the screenshot</p><p><img src="cid:img1@synthetic.example.test" alt="shot"></p>';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private ?MockHandler $mock = null;

    private TestHandler $logs;

    private \ArrayObject $seen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testLevel = DB::transactionLevel();
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

        $this->seen = new \ArrayObject;
        $this->app->instance(NotificationService::class, new class($this->seen) extends NotificationService
        {
            public function __construct(private \ArrayObject $seen) {}

            public function notifyEmailAdded(Ticket $ticket, Email $email): void
            {
                $this->seen[] = ['notifyEmailAdded', $ticket->id, Attachment::whereNotNull('attachable_id')->count()];
            }

            public function notifyTicketCreated(Ticket $ticket): void {}
        });
    }

    protected function assertPostConditions(): void
    {
        if ($this->mock !== null) {
            $this->assertSame(0, $this->mock->count(), 'G-5 (#5714): every queued Graph response was used; no spare to serve a stray request');
        }
        parent::assertPostConditions();
    }

    private function graph(array $responses): void
    {
        $this->history = [];
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'test-token', 3600);
        $this->app->instance(GraphClient::class, new GraphClient([
            'tenant_id' => 'tenant-b4i-synthetic',
            'client_id' => 'client',
            'client_secret' => 'secret',
            'request_timeout' => 15,
            'token_timeout' => 10,
            'handler' => $stack,
        ], $cache));
    }

    /** Message reads with attachments expanded (the webhook's own message fetch is not one). */
    private function messageReads(): int
    {
        return count(array_filter($this->history, fn ($h) => str_ends_with($h['request']->getUri()->getPath(), '/messages/MSG-1')
            && str_contains(urldecode($h['request']->getUri()->getQuery()), '$expand=attachments')));
    }

    private function read(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'MSG-1',
            'attachments' => [[
                '@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'ATT-FILE-1', 'name' => 'image001.png',
                'contentType' => 'image/png', 'size' => 23, 'isInline' => true,
                'contentId' => 'img1@synthetic.example.test', 'contentBytes' => base64_encode('synthetic-png-bytes-b4i'),
            ]],
        ]));
    }

    private function failedRead(): Response
    {
        return new Response(503, [], '{}');
    }

    private function email(): Email
    {
        $client = Client::create(['name' => 'Example Client']);

        return Email::create([
            'graph_id' => 'MSG-1',
            'direction' => 'inbound',
            'from_address' => 'user@example.test',
            'from_name' => 'User',
            'subject' => 'Printer offline',
            'body_text' => 'B4I-SYNTHETIC-BODY please look',
            'body_html' => self::CID_HTML,
            'client_id' => $client->id,
            'received_at' => now(),
        ]);
    }

    /** @return list<LogRecord> */
    private function withMessage(string $message): array
    {
        return array_values(array_filter($this->logs->getRecords(), fn (LogRecord $r) => $r->message === $message));
    }

    /** The private System notes on $ticketId (the #5558 marker note). */
    private function markerNotes(int $ticketId): array
    {
        return TicketNote::where('ticket_id', $ticketId)->where('note_type', 'system')->where('is_private', true)->pluck('body')->all();
    }

    /** The real database queue, as prod runs it (QUEUE_CONNECTION=database). */
    private function databaseQueue(): void
    {
        config(['queue.default' => 'database']);
    }

    /** @return list<object> the queued RetryEmailAttachments rows of the jobs table */
    private function retryRows(): array
    {
        return DB::table('jobs')->orderBy('id')->get()
            ->filter(fn ($r) => (json_decode($r->payload, true)['displayName'] ?? null) === RetryEmailAttachments::class)
            ->values()->all();
    }

    /** Pops the next due RetryEmailAttachments job off the database queue (others are dropped). */
    private function popRetry(): ?\Illuminate\Queue\Jobs\DatabaseJob
    {
        $queue = app('queue')->connection('database');
        while (($job = $queue->pop('default')) !== null) {
            if ($job->resolveName() === RetryEmailAttachments::class) {
                return $job;
            }
            $job->delete();
        }

        return null;
    }

    private function commandOf(\Illuminate\Queue\Jobs\DatabaseJob $job): RetryEmailAttachments
    {
        return unserialize($job->payload()['data']['command']);
    }

    /** A new ticket whose first message read failed; its retry job is queued, not run. */
    private function ticketWithQueuedRetry(): Ticket
    {
        $this->databaseQueue();
        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($this->email());
        $this->assertCount(1, $this->retryRows(), 'positive control: the retry job was queued');

        return $ticket;
    }

    // ── Jeeves 15:50Z: the job's own budget, inside the worker's 30s / --tries=2 ──

    public function test_the_job_statics_start_empty_whatever_ran_before(): void
    {
        // #6102/#6141/#6174: at entry the statics are empty (the setUp hook ran, or nothing was
        // left). Laravel finds the hook by name: setUp.class_basename(trait), over the traits
        // it recorded for this class; that link is asserted, so a renamed trait or hook fails
        // here. Then the hook's body is exercised: entries a class run before would leave are
        // planted, and the hook empties them. The tearDown hook is not what this reaches.
        $this->assertSame(['progress' => [], 'pushing' => []], self::retryEmailAttachmentsStatics(), 'empty at entry');
        $traits = (fn () => $this->traitsUsedByTest)->call($this);
        $this->assertArrayHasKey(ResetsRetryEmailAttachmentsStatics::class, $traits, 'recorded for setUpTraits');
        $this->assertSame('setUpResetsRetryEmailAttachmentsStatics', 'setUp'.class_basename(ResetsRetryEmailAttachmentsStatics::class));
        $this->assertTrue(method_exists($this, 'setUp'.class_basename(ResetsRetryEmailAttachmentsStatics::class)), 'the hook setUpTraits calls exists');
        (fn () => [self::$progress = ['b8:leftover' => ['level' => 0]], self::$pushing = ['b8:leftover' => true]])->bindTo(null, RetryEmailAttachments::class)();
        $this->assertNotSame(['progress' => [], 'pushing' => []], self::retryEmailAttachmentsStatics(), 'positive control: planted');
        $this->setUpResetsRetryEmailAttachmentsStatics();
        $this->assertSame(['progress' => [], 'pushing' => []], self::retryEmailAttachmentsStatics());
    }

    public function test_the_job_sets_its_own_timeout_and_tries_and_the_queued_payload_carries_them(): void
    {
        $job = new RetryEmailAttachments(1, 2, 3, []);
        $this->assertSame(1, $job->tries, 'one attempt per dispatch, not the worker --tries=2');
        $this->assertSame(20, $job->timeout);
        $this->assertLessThan(30, $job->timeout, "inside the worker's --timeout=30");
        $this->assertTrue($job->failOnTimeout, 'a timeout is final, not retried by the worker');

        $this->graph([$this->failedRead()]);
        $this->ticketWithQueuedRetry();

        // What the worker reads: maxTries and timeout come from the payload, so a missing
        // property would fall back to the worker's options.
        $payload = json_decode($this->retryRows()[0]->payload, true);
        $this->assertSame([1, 20, true], [$payload['maxTries'], $payload['timeout'], $payload['failOnTimeout']]);
        $this->assertSame(0, $this->retryRows()[0]->attempts);
    }

    // ── #5457: queued after commit; nothing waits on the retry's Graph read ──

    public function test_the_retry_is_queued_only_when_the_outermost_transaction_commits(): void
    {
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $inside = null;

        DB::transaction(function () use ($email, &$inside) {
            app(EmailService::class)->autoCreateTicketFromEmail($email);
            $inside = count($this->retryRows());
        });

        $this->assertSame(0, $inside, 'afterCommit: nothing is queued while the transaction is open');
        $this->assertCount(1, $this->retryRows(), 'queued at commit');
        $this->assertSame(1, $this->messageReads(), 'only the first read ran; the retry read waits for a worker');
        $this->assertSame([], $this->seen->getArrayCopy(), '#5549: the email-added notification waits for the job');
        $command = $this->commandOf($this->popRetry());
        $note = TicketNote::where('email_id', $email->id)->sole();
        $this->assertSame([$email->id, (int) $email->fresh()->ticket_id, $note->id, 0],
            [$command->emailId, $command->ticketId, $command->noteId, $command->refusals]);
        $this->assertNotEmpty($command->baseline, '#5711: the payload carries the creation-time baseline');
    }

    public function test_the_queued_and_failed_payloads_carry_no_ticket_or_note_content(): void
    {
        // #5807: the baseline is ids and hashes only, so the client's email text never sits in
        // jobs.payload, nor in failed_jobs.payload after the job fails.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $needle = 'B4M-SYNTHETIC-BODY-NEEDLE';
        $email = $this->email();
        $email->update(['body_text' => "{$needle} text", 'body_html' => "<p>{$needle} html</p>".self::CID_HTML]);
        // A plain email ticket has no description; a vendor-parsed one does. Plant one.
        Ticket::creating(function (Ticket $t) use ($needle) {
            $t->description = "{$needle} description";
            $t->description_html = "<p>{$needle} description_html</p>";
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $note = TicketNote::where('email_id', $email->id)->sole();
        $this->assertStringContainsString($needle, (string) $ticket->fresh()->description.(string) $ticket->fresh()->description_html,
            'positive control: the ticket carries the needle');
        $this->assertStringContainsString($needle, (string) $note->body.(string) $note->body_html, 'positive control: the note carries the needle');
        $rows = $this->retryRows();
        $this->assertCount(1, $rows, 'positive control: the retry was queued');
        $this->assertStringNotContainsString($needle, $rows[0]->payload, 'jobs.payload');

        $job = $this->popRetry();
        $command = $this->commandOf($job);
        $this->assertStringNotContainsString($needle, serialize($command), 'serialize($job)');
        $this->assertSame(['ticket', 'note', 'attachments'], array_keys($command->baseline));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $command->baseline['ticket']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $command->baseline['note']);
        $this->assertSame([], $command->baseline['attachments']);

        // What the worker records for a failed job: the raw body, through the configured failer.
        app('queue.failer')->log('database', 'default', $job->getRawBody(), new \RuntimeException('B4M-SYNTHETIC-FAIL'));
        $failed = DB::table('failed_jobs')->get();
        $this->assertCount(1, $failed, 'positive control: the failed job was recorded');
        $this->assertSame(RetryEmailAttachments::class, json_decode($failed[0]->payload, true)['displayName'] ?? null, 'positive control: it is this job');
        $this->assertStringNotContainsString($needle, $failed[0]->payload, 'failed_jobs.payload');
    }

    public function test_a_payload_queued_before_5807_is_re_queued_without_its_raw_rows(): void
    {
        // #5807: a job queued before the deploy carries retryState's raw rows. A clearable
        // refusal re-queues it; the new jobs.payload (and failed_jobs.payload should that run
        // fail) carries the rows' fingerprint, never the rows.
        $needle = 'B4M-SYNTHETIC-LEGACY-NEEDLE';
        $this->graph([$this->failedRead()]); // #5714: the legacy run is refused before any read
        // A plain email ticket has no description; a vendor-parsed one does. Plant one.
        Ticket::creating(function (Ticket $t) use ($needle) {
            $t->description = "{$needle} description";
            $t->description_html = "<p>{$needle} description_html</p>";
        });
        $ticket = $this->ticketWithQueuedRetry();
        $queued = $this->popRetry();
        $current = $this->commandOf($queued);
        $queued->delete();
        $raw = (fn () => $this->retryState($current->ticketId, $current->noteId))->call(app(EmailService::class));
        RetryEmailAttachments::dispatch($current->emailId, $current->ticketId, $current->noteId, $raw);
        $legacy = $this->retryRows();
        $this->assertCount(1, $legacy, 'positive control: the legacy job was queued');
        $this->assertStringContainsString($needle, $legacy[0]->payload, 'positive control: a raw, pre-#5807 ticket row');
        $this->assertStringContainsString('B4I-SYNTHETIC-BODY', $legacy[0]->payload, 'positive control: a raw, pre-#5807 note row');
        Ticket::whereKey($ticket->id)->update(['description' => 'tech edit: legacy']);

        $this->popRetry()->fire();

        $this->assertSame(1, $this->messageReads(), 'refused at the pre-check');
        $rows = $this->retryRows();
        $this->assertCount(1, $rows, 'positive control: ticket_changed re-queued');
        foreach ([$needle, 'B4I-SYNTHETIC-BODY'] as $content) {
            $this->assertStringNotContainsString($content, $rows[0]->payload, 'the re-queued jobs.payload');
        }
        $requeued = unserialize(json_decode($rows[0]->payload, true)['data']['command']);
        $this->assertSame(1, $requeued->refusals, 'positive control: it is the re-queue');
        $this->assertSame(EmailService::retryFingerprint($raw), $requeued->baseline, 'the same baseline, as its fingerprint');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $requeued->baseline['ticket']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $requeued->baseline['note']);

        // What the worker records should the re-queued run fail: its raw body, through the configured failer.
        app('queue.failer')->log('database', 'default', $rows[0]->payload, new \RuntimeException('B4M-SYNTHETIC-FAIL'));
        $failed = DB::table('failed_jobs')->get();
        $this->assertCount(1, $failed, 'positive control: the failed job was recorded');
        foreach ([$needle, 'B4I-SYNTHETIC-BODY'] as $content) {
            $this->assertStringNotContainsString($content, $failed[0]->payload, 'failed_jobs.payload');
        }
    }

    public function test_a_rolled_back_transaction_queues_no_retry(): void
    {
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();

        try {
            DB::transaction(function () use ($email) {
                app(EmailService::class)->autoCreateTicketFromEmail($email);
                throw new \RuntimeException('B4I-SYNTHETIC-ROLLBACK');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, $this->messageReads(), 'positive control: the first read ran and failed');
        $this->assertSame([], $this->retryRows());
    }

    public function test_the_webhook_answers_202_before_the_retry_reads_graph(): void
    {
        $this->databaseQueue();
        Setting::setValue('graph_webhook_client_state', 'state-b4i-synthetic');
        Setting::setValue('email_auto_ticket', '1');
        $client = Client::create(['name' => 'Example Client']);
        Person::create(['client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Ada', 'last_name' => 'Contact', 'email' => 'user@example.test', 'is_active' => true]);
        $this->graph([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'id' => 'MSG-1', 'internetMessageId' => '<m1@example.test>',
                'from' => ['emailAddress' => ['address' => 'user@example.test', 'name' => 'User']],
                'subject' => 'Printer offline', 'body' => ['contentType' => 'html', 'content' => self::CID_HTML],
                'hasAttachments' => false, 'receivedDateTime' => now()->toIso8601String(),
            ])),
            $this->failedRead(),
        ]);

        $response = $this->postJson('/api/webhooks/graph/mail', ['value' => [[
            'clientState' => 'state-b4i-synthetic', 'resource' => 'users/MSG-OWNER/messages/MSG-1',
        ]]]);

        $response->assertStatus(202);
        $this->assertSame(1, $this->messageReads(), '#5457: the 202 came after the first read only');
        $this->assertCount(1, $this->retryRows(), 'the retry is queued for a worker');
        $ticketId = (int) Email::where('graph_id', 'MSG-1')->value('ticket_id');
        $this->assertGreaterThan(0, $ticketId, 'positive control: the webhook import created the ticket');

        // The worker then runs it: the read happens there, and the attachment is linked.
        $this->mock->append($this->read());
        $this->popRetry()->fire();
        $this->assertSame(2, $this->messageReads());
        $this->assertSame(TicketNote::class, Attachment::sole()->attachable_type);
        $this->assertSame([['notifyEmailAdded', $ticketId, 1]], $this->seen->getArrayCopy(), '#5453: notified once, after the link');
    }

    // ── #5558: re-queue once on a refusal, by the job's own counter; then a durable marker ──

    /** A refusal cause during the retry read: the description edited, so ticket_changed. */
    private function readWhileEditing(string $description): \Closure
    {
        return function () use ($description) {
            Ticket::whereNotNull('id')->firstOrFail()->update(['description' => $description]);

            return $this->read();
        };
    }

    public function test_a_refusal_is_re_queued_once_as_a_fresh_delayed_dispatch_and_a_cleared_cause_recovers(): void
    {
        $this->graph([$this->failedRead(), $this->readWhileEditing('tech edit: transient')]);
        $ticket = $this->ticketWithQueuedRetry();
        $original = (string) $ticket->fresh()->description;

        $first = $this->popRetry();
        $first->fire();

        $this->assertSame(2, $this->messageReads(), 'positive control: the retry read ran');
        $this->assertSame('ticket_changed', $this->withMessage('[EmailService] Attachment retry after ticket creation skipped')[0]->context['reason'] ?? null);
        $this->assertSame(0, Attachment::withTrashed()->count(), 'the refused read is discarded');
        $this->assertSame([], $this->markerNotes($ticket->id), 'not final yet: no marker');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::MARKER));
        $this->assertSame([], $this->seen->getArrayCopy(), 'the commit work waits for the final run');
        $this->assertFalse($first->isReleased(), "the job's own counter, not a worker release");
        $rows = $this->retryRows();
        $this->assertCount(1, $rows, 'one fresh dispatch (the first row was deleted when it finished)');
        $this->assertSame(0, $rows[0]->attempts, 'a fresh job: no worker attempt spent');
        $this->assertGreaterThanOrEqual(time() + RetryEmailAttachments::REQUEUE_DELAY_SECONDS - 5, $rows[0]->available_at, 'delayed');

        // The cause clears (the edit is undone), and the re-queued run links.
        Ticket::whereKey($ticket->id)->update(['description' => $original === '' ? null : $original]);
        $this->travel(RetryEmailAttachments::REQUEUE_DELAY_SECONDS + 1)->seconds();
        $this->mock->append($this->read());
        $second = $this->popRetry();
        $this->assertSame(1, $this->commandOf($second)->refusals);
        $second->fire();

        $this->assertSame(3, $this->messageReads());
        $this->assertSame(TicketNote::class, Attachment::sole()->attachable_type, '#5558: recovered');
        $this->assertSame([], $this->markerNotes($ticket->id));
        $this->assertSame([], $this->retryRows(), 'nothing further queued');
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy());
    }

    public function test_a_refusal_still_final_after_the_re_queue_writes_the_log_line_and_the_ticket_note(): void
    {
        $this->graph([$this->failedRead(), $this->readWhileEditing('tech edit: lasting')]);
        $ticket = $this->ticketWithQueuedRetry();

        $this->popRetry()->fire();
        $this->travel(RetryEmailAttachments::REQUEUE_DELAY_SECONDS + 1)->seconds();
        $second = $this->popRetry();
        $this->assertSame(1, $this->commandOf($second)->refusals, 'positive control: the re-queued run');
        $second->fire();

        $this->assertSame(2, $this->messageReads(), 'the re-queued run is refused at its unlocked pre-check, before any Graph read');
        $this->assertSame('tech edit: lasting', $ticket->fresh()->description, 'the edit stands');
        $this->assertSame(0, Attachment::withTrashed()->count());
        $this->assertSame([], $this->retryRows(), 'no third run: one re-queue only');
        $marker = $this->withMessage(RetryEmailAttachments::MARKER);
        $this->assertCount(1, $marker, 'one durable log line');
        $this->assertSame(Level::Warning, $marker[0]->level);
        $email = Email::where('graph_id', 'MSG-1')->sole();
        $this->assertSame([
            'email_id' => $email->id, 'ticket_id' => $ticket->id, 'reason' => 'ticket_changed',
            'refusals_requeued' => 1, 'ticket_note_written' => true, 'undiscarded_attachment_ids' => [],
        ], $marker[0]->context, 'C-56: ids, reason and counts only');
        $this->assertSame(
            ["Attachments from email #{$email->id} were not added to this ticket automatically (reason: ticket_changed)."],
            $this->markerNotes($ticket->id),
            'one private System note on the ticket',
        );
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy(), 'the commit work still runs, once');
    }

    public function test_a_failed_retry_read_is_final_without_a_re_queue(): void
    {
        $this->graph([$this->failedRead(), $this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();

        $this->popRetry()->fire();

        $this->assertSame([], $this->retryRows(), 'a failed read is not a refusal: no re-queue');
        $this->assertSame('read_failed', $this->withMessage(RetryEmailAttachments::MARKER)[0]->context['reason'] ?? null);
        $this->assertCount(1, $this->markerNotes($ticket->id));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy());
    }

    /** @return array<string, array{0: \Closure(): void, 1: string}> */
    public static function refusalsThatCannotClear(): array
    {
        return [
            'client note deleted' => [fn () => TicketNote::whereNotNull('email_id')->firstOrFail()->delete(), 'client_note_missing'],
            'email moved to another ticket' => [function () {
                $other = Ticket::factory()->create(['client_id' => Client::query()->value('id')]);
                Email::where('graph_id', 'MSG-1')->update(['ticket_id' => $other->id]);
            }, 'email_relinked'],
            'ticket soft-deleted' => [fn () => Ticket::query()->firstOrFail()->delete(), 'ticket_gone'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusalsThatCannotClear')]
    public function test_a_refusal_that_cannot_clear_is_final_at_the_first_run(\Closure $cause, string $reason): void
    {
        // #5802: a re-queued run reads the same rows and payload, so it is not re-queued; the
        // marker and the commit work run now, not 60 s later.
        $this->graph([$this->failedRead()]); // #5714: refused before any Graph read
        $ticket = $this->ticketWithQueuedRetry();
        $cause();

        $this->popRetry()->fire();

        $this->assertSame(1, $this->messageReads(), 'refused at the pre-check');
        $this->assertSame([], $this->retryRows(), 'no re-queue');
        $marker = $this->withMessage(RetryEmailAttachments::MARKER);
        $this->assertCount(1, $marker, 'final now: the marker');
        $this->assertSame([$reason, 0], [$marker[0]->context['reason'], $marker[0]->context['refusals_requeued']]);
        // A ticket that is gone gets no notification (runCommitWork finds no ticket).
        $this->assertSame($reason === 'ticket_gone' ? 0 : 1, count($this->seen), 'the commit work ran at the first run');
    }

    /** @return array<string, array{0: bool}> */
    public static function secondRunOutcomes(): array
    {
        return ['the cause clears and the second run links' => [true], 'the cause lasts' => [false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('secondRunOutcomes')]
    public function test_the_first_runs_undiscarded_ids_reach_the_final_runs_record(bool $clears): void
    {
        // #5803: the first run is refused and the force-delete of its stored row throws, so that
        // row is left undiscarded; the re-queue carries its id to the final run's record.
        $this->graph([$this->failedRead(), $this->readWhileEditing('tech edit: transient')]);
        $ticket = $this->ticketWithQueuedRetry();
        $original = $ticket->fresh()->description;
        $throws = true;
        Attachment::forceDeleting(function () use (&$throws) {
            if ($throws) {
                throw new \RuntimeException('B4M-SYNTHETIC-LOCK-WAIT');
            }
        });

        $this->popRetry()->fire();

        $left = Attachment::withTrashed()->sole();
        $this->assertSame([$left->id], $this->withMessage('[EmailService] Attachment retry after ticket creation skipped')[0]->context['undiscarded_attachment_ids'],
            'positive control: the first run left the row undiscarded');
        $throws = false;
        $this->travel(RetryEmailAttachments::REQUEUE_DELAY_SECONDS + 1)->seconds();
        $second = $this->popRetry();
        $this->assertSame([$left->id], $this->commandOf($second)->earlierUndiscarded, 'the re-queue carries the id');
        if ($clears) {
            Ticket::whereKey($ticket->id)->update(['description' => $original]);
            $this->mock->append($this->read());
        }

        $second->fire();

        if ($clears) {
            $this->assertSame(TicketNote::class, Attachment::where('id', '!=', $left->id)->sole()->attachable_type, 'positive control: the second run linked');
            $this->assertSame([], $this->withMessage(RetryEmailAttachments::MARKER), 'linked: no not-added marker');
            $earlier = $this->withMessage(RetryEmailAttachments::EARLIER_UNDISCARDED);
            $this->assertCount(1, $earlier);
            $this->assertSame(Level::Warning, $earlier[0]->level);
            $email = Email::where('graph_id', 'MSG-1')->sole();
            $this->assertSame(['email_id' => $email->id, 'ticket_id' => $ticket->id, 'refusals_requeued' => 1,
                'undiscarded_attachment_ids' => [$left->id]], $earlier[0]->context, 'C-56: ids only');
        } else {
            $marker = $this->withMessage(RetryEmailAttachments::MARKER);
            $this->assertCount(1, $marker);
            $this->assertSame(['ticket_changed', [$left->id]], [$marker[0]->context['reason'], $marker[0]->context['undiscarded_attachment_ids']]);
            $this->assertSame([], $this->withMessage(RetryEmailAttachments::EARLIER_UNDISCARDED));
        }
        $this->assertSame(1, count($this->seen), 'the commit work ran once');
    }

    public function test_a_failure_recorded_in_another_process_reports_its_undiscarded_ids_as_unknown(): void
    {
        // #5799: a worker died (OOM, SIGKILL) inside the retry after its read stored a row; the
        // next worker to reserve the job fails it as MaxAttemptsExceeded in a fresh process,
        // where what the dead one stored is not known. The marker says so: null, not [].
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        $job = $this->popRetry();
        app(\App\Services\AttachmentService::class)->storeFromContent('synthetic-orphan', 'orphan.txt', 'text/plain');

        $job->fail(\Illuminate\Queue\MaxAttemptsExceededException::forJob($job));

        $this->assertSame(1, Attachment::count(), 'positive control: the dead run\'s row is still there');
        $marker = $this->withMessage(RetryEmailAttachments::MARKER);
        $this->assertCount(1, $marker);
        $this->assertSame('job_failed', $marker[0]->context['reason']);
        $this->assertArrayHasKey('undiscarded_attachment_ids', $marker[0]->context);
        $this->assertNull($marker[0]->context['undiscarded_attachment_ids'], 'not known in this process, never []');
        $this->assertCount(1, $this->markerNotes($ticket->id));
        $this->assertSame(1, count($this->seen), 'the commit work still ran');
    }

    public function test_a_re_queued_run_failed_in_another_process_still_reports_the_earlier_runs_ids(): void
    {
        // #5803/#5799: the process that fails the final run does not know that run's own ids,
        // but the ids an earlier run left come with the payload; they get their own record.
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        $queued = $this->popRetry();
        $first = $this->commandOf($queued);
        $queued->delete();
        app(\App\Services\AttachmentService::class)->storeFromContent('synthetic-left', 'left.txt', 'text/plain');
        $leftId = Attachment::sole()->id;
        RetryEmailAttachments::dispatch($first->emailId, $first->ticketId, $first->noteId, $first->baseline, 1, [$leftId]);
        $job = $this->popRetry();
        $this->assertSame([$leftId], $this->commandOf($job)->earlierUndiscarded, 'positive control: the payload carries the id');

        $job->fail(\Illuminate\Queue\MaxAttemptsExceededException::forJob($job));

        $marker = $this->withMessage(RetryEmailAttachments::MARKER);
        $this->assertCount(1, $marker);
        $this->assertSame(['job_failed', null], [$marker[0]->context['reason'], $marker[0]->context['undiscarded_attachment_ids']],
            'the final run ids: not known in this process');
        $earlier = $this->withMessage(RetryEmailAttachments::EARLIER_UNDISCARDED);
        $this->assertCount(1, $earlier, 'the earlier run ids are not lost');
        $this->assertSame(Level::Warning, $earlier[0]->level);
        $email = Email::where('graph_id', 'MSG-1')->sole();
        $this->assertSame(['email_id' => $email->id, 'ticket_id' => $ticket->id, 'refusals_requeued' => 1,
            'undiscarded_attachment_ids' => [$leftId]], $earlier[0]->context, 'C-56: ids only');
        $this->assertSame(1, count($this->seen), 'the commit work ran once');
    }

    // ── A timeout kill and a refusal do not share or double-count one budget ──

    /**
     * What the worker's SIGALRM handler does to a job that ran past its own $timeout, in prod's
     * order (#5815): Job::fail() rolls every open transaction back (queue.failed.database set,
     * rollBack(toLevel: 0)), then calls failed(). Level 0 here would end RefreshDatabase's
     * wrapping transaction, so the framework's own rollback is switched off and this helper
     * rolls back to the test's level instead, before failed() runs. The worker then kills the
     * process; here the code under test unwinds instead, so the levels it expects are reopened
     * empty after failed() and roll back as it unwinds.
     */
    private function timeOut(\Illuminate\Queue\Jobs\DatabaseJob $job): void
    {
        config(['queue.failed.database' => null]);
        $open = DB::transactionLevel() - $this->testLevel;
        if ($open > 0) {
            DB::rollBack($this->testLevel);
        }
        $worker = app('queue.worker');
        $e = \Illuminate\Queue\TimeoutExceededException::forJob($job);
        // --tries=2 is the worker's option; the job's own maxTries (1) must win.
        (fn () => $this->markJobAsFailedIfWillExceedMaxAttempts('database', $job, 2, $e))->call($worker);
        (fn () => $this->markJobAsFailedIfItShouldFailOnTimeout('database', $job, $e))->call($worker);
        for ($i = 0; $i < $open; $i++) {
            DB::beginTransaction();
        }
    }

    /** The transaction level RefreshDatabase leaves a test at: prod's level 0. */
    private int $testLevel = 0;

    public function test_a_timeout_on_the_first_run_is_final_and_spends_no_refusal_re_queue(): void
    {
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();

        $job = $this->popRetry();
        $this->timeOut($job);

        $this->assertTrue($job->hasFailed(), "the job's own tries (1) fail it on the first timeout, not the worker's 2");
        $this->assertSame([], $this->retryRows(), 'a timeout is not re-queued as a refusal');
        $marker = $this->withMessage(RetryEmailAttachments::MARKER);
        $this->assertCount(1, $marker);
        $this->assertSame(['timed_out', 0], [$marker[0]->context['reason'], $marker[0]->context['refusals_requeued']]);
        $this->assertCount(1, $this->markerNotes($ticket->id));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy(), '#5549: a killed retry still notifies');
    }

    public function test_a_timeout_on_the_re_queued_run_keeps_its_refusal_count_and_is_counted_once(): void
    {
        $this->graph([$this->failedRead(), $this->readWhileEditing('tech edit: x')]);
        $ticket = $this->ticketWithQueuedRetry();
        $this->popRetry()->fire();
        $this->travel(RetryEmailAttachments::REQUEUE_DELAY_SECONDS + 1)->seconds();

        $job = $this->popRetry();
        $this->assertSame(1, $job->attempts(), "the re-queued run starts on a fresh worker attempt, not the refusal's");
        $this->timeOut($job);

        $this->assertTrue($job->hasFailed());
        $this->assertSame([], $this->retryRows(), 'no further run');
        $marker = $this->withMessage(RetryEmailAttachments::MARKER);
        $this->assertCount(1, $marker, 'one marker for the one final outcome');
        $this->assertSame(['timed_out', 1], [$marker[0]->context['reason'], $marker[0]->context['refusals_requeued']]);
        $this->assertCount(1, $this->markerNotes($ticket->id));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy());
    }

    public function test_a_timeout_mid_retry_discards_what_the_read_stored(): void
    {
        // The kill lands after the read stored its row and before the link: failed() runs in
        // the same process (SIGALRM), with the retry's tracking still set.
        $this->graph([$this->failedRead()]);
        $ticketId = $this->ticketWithQueuedRetry()->id;
        $job = $this->popRetry();
        $this->mock->append($this->read());
        $killed = false;
        // At the storage_path update the row is created (and tracked) and its file is written.
        $atKill = null;
        // Nothing here may assert: a failed assertion would be caught by the code under test.
        Attachment::updating(function () use ($job, &$killed, &$atKill, $ticketId) {
            if ($killed) {
                return;
            }
            $killed = true;
            $before = [Attachment::count(), count(Storage::disk('local')->allFiles('attachments'))];
            $this->timeOut($job);
            // The worker kills the process right after failed(); what it leaves is read here.
            $atKill = [
                'before' => $before,
                'rows' => Attachment::withTrashed()->count(),
                'files' => Storage::disk('local')->allFiles('attachments'),
                'markers' => array_map(fn ($r) => $r->context['reason'], $this->withMessage(RetryEmailAttachments::MARKER)),
                'notes' => count($this->markerNotes($ticketId)),
                'seen' => $this->seen->getArrayCopy(),
            ];
            // Stands in for the kill. The store's own catch and the retry's catch arm absorb it,
            // so the job unwinds; what it may still do after failed() is asserted below (#5808).
            throw new \RuntimeException('B4I-SYNTHETIC-KILLED');
        });

        $job->fire();

        $this->assertTrue($killed, 'positive control: the kill point was reached');
        $this->assertSame(
            ['rows' => 0, 'files' => [], 'markers' => ['timed_out'], 'notes' => 1, 'seen' => [['notifyEmailAdded', $ticketId, 0]]],
            ['rows' => Attachment::withTrashed()->count(), 'files' => Storage::disk('local')->allFiles('attachments'),
                'markers' => array_map(fn ($r) => $r->context['reason'], $this->withMessage(RetryEmailAttachments::MARKER)),
                'notes' => count($this->markerNotes($ticketId)), 'seen' => $this->seen->getArrayCopy()],
            '#5808: after failed(), the unwinding run writes no second marker and runs no second commit work',
        );
        $this->assertSame([], $this->retryRows(), 'and re-queues nothing');
        $this->assertSame([
            'before' => [1, 1],
            'rows' => 0,
            'files' => [],
            'markers' => ['timed_out'],
            'notes' => 1,
            'seen' => [['notifyEmailAdded', $ticketId, 0]],
        ], $atKill, 'row and file existed at the kill; failed() discarded both, wrote the marker and notified');
    }

    public function test_a_timeout_inside_the_link_transaction_rolls_back_first_then_discards(): void
    {
        // #5815: the kill lands inside the link transaction, after the link wrote. In prod the
        // worker rolls that transaction back before failed(), so abandonRetry reads the link as
        // not persisted and discards the row and file; failed() reading the uncommitted link
        // would keep an unlinked row and write no marker.
        $this->graph([$this->failedRead()]);
        $ticketId = $this->ticketWithQueuedRetry()->id;
        $job = $this->popRetry();
        $this->mock->append($this->read());
        $atKill = null;
        // Nothing here may assert: a failed assertion would be caught by the code under test.
        Attachment::updated(function (Attachment $a) use ($job, &$atKill, $ticketId) {
            if ($a->attachable_type !== TicketNote::class || $atKill !== null) {
                return;
            }
            $open = DB::transactionLevel() - $this->testLevel;
            $this->timeOut($job);
            $atKill = [
                'open' => $open,
                'rows' => Attachment::withTrashed()->count(),
                'files' => count(Storage::disk('local')->allFiles('attachments')),
                'markers' => array_map(fn ($r) => $r->context['reason'], $this->withMessage(RetryEmailAttachments::MARKER)),
                'notes' => count($this->markerNotes($ticketId)),
                'seen' => $this->seen->getArrayCopy(),
            ];
            throw new \RuntimeException('B4M-SYNTHETIC-KILLED'); // stands in for the kill
        });

        $job->fire();

        $this->assertNotNull($atKill, 'positive control: the kill point was reached');
        $expected = ['open' => 1, 'rows' => 0, 'files' => 0, 'markers' => ['timed_out'], 'notes' => 1, 'seen' => [['notifyEmailAdded', $ticketId, 0]]];
        $this->assertSame($expected, $atKill, 'inside the link transaction; rolled back, then discarded, marked and notified');
        $this->assertSame(
            ['rows' => 0, 'markers' => ['timed_out'], 'notes' => 1, 'seen' => [['notifyEmailAdded', $ticketId, 0]]],
            ['rows' => Attachment::withTrashed()->count(),
                'markers' => array_map(fn ($r) => $r->context['reason'], $this->withMessage(RetryEmailAttachments::MARKER)),
                'notes' => count($this->markerNotes($ticketId)), 'seen' => $this->seen->getArrayCopy()],
            '#5808: nothing more after failed()',
        );
    }

    public function test_a_timeout_during_the_commit_work_does_not_run_it_again_or_write_a_marker(): void
    {
        // The kill lands while handle()'s own notifyEmailAdded is sending, after the retry linked:
        // failed() runs in the same process and must not notify, dispatch or mark again.
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        $job = $this->popRetry();
        $this->mock->append($this->read());
        $calls = new \ArrayObject;
        $kill = fn () => $this->timeOut($job);
        $this->app->instance(NotificationService::class, new class($calls, $kill) extends NotificationService
        {
            public function __construct(private \ArrayObject $calls, private \Closure $kill) {}

            public function notifyEmailAdded(Ticket $ticket, Email $email): void
            {
                $this->calls[] = $ticket->id;
                if (count($this->calls) === 1) {
                    ($this->kill)();
                }
            }

            public function notifyTicketCreated(Ticket $ticket): void {}
        });

        $job->fire();

        $this->assertTrue($job->hasFailed(), 'positive control: the timeout failed the job');
        $this->assertSame([$ticket->id], $calls->getArrayCopy(), 'notifyEmailAdded once, not again from failed()');
        $this->assertSame(TicketNote::class, Attachment::sole()->attachable_type, 'positive control: the retry linked');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::MARKER), 'linked: no not-added marker');
        $this->assertSame([], $this->markerNotes($ticket->id));
    }

    public function test_a_timeout_after_the_link_committed_keeps_the_linked_attachment_and_writes_no_marker(): void
    {
        // #5708 window: a callback staged by a nested transaction runs after the link commits and
        // before the link level's own flag is set; the kill lands there.
        $this->graph([$this->failedRead()]);
        $ticketId = $this->ticketWithQueuedRetry()->id;
        $job = $this->popRetry();
        $this->mock->append($this->read());
        $atKill = null;
        // Nothing here may assert: a failed assertion would be caught by the code under test.
        Attachment::updated(function (Attachment $a) use ($job, &$atKill, $ticketId) {
            if ($a->attachable_type !== TicketNote::class || $atKill !== null) {
                return;
            }
            // Not an arrow function: it captures by value, so the inner &$atKill would bind its copy.
            DB::transaction(function () use ($job, &$atKill, $ticketId) {
                DB::afterCommit(function () use ($job, &$atKill, $ticketId) {
                    $this->timeOut($job);
                    $atKill = [
                        'linked_rows' => Attachment::where('attachable_type', TicketNote::class)->count(),
                        'files' => count(Storage::disk('local')->allFiles('attachments')),
                        'markers' => count($this->withMessage(RetryEmailAttachments::MARKER)),
                        'notes' => count($this->markerNotes($ticketId)),
                        'seen' => $this->seen->getArrayCopy(),
                    ];
                    throw new \RuntimeException('B4I-SYNTHETIC-KILLED'); // stands in for the kill
                });
            });
        });

        $job->fire();

        $this->assertNotNull($atKill, 'positive control: the kill point was reached');
        $this->assertSame([
            'linked_rows' => 1,
            'files' => 1,
            'markers' => 0,
            'notes' => 0,
            'seen' => [['notifyEmailAdded', $ticketId, 1]],
        ], $atKill, 'the committed link is read back: nothing discarded, no not-added marker, notified once');
    }
    // ── b4n fold-ins (#5782 residuals): #5800 #5820 #5810 #5811 #5816 ──

    public function test_a_timeout_inside_the_link_rolls_back_in_failed_without_a_database_failer(): void
    {
        // #5800: the framework's Job::fail rolls back only with a database failer; with the
        // failer switched off (as timeOut() does) and NO helper rollback, failed() itself must
        // roll the link transaction back before it writes, or its marker and discard ride a
        // transaction the kill then drops.
        $this->graph([$this->failedRead()]);
        $ticketId = $this->ticketWithQueuedRetry()->id;
        $job = $this->popRetry();
        $this->mock->append($this->read());
        $atKill = null;
        Attachment::updated(function (Attachment $a) use ($job, &$atKill, $ticketId) {
            if ($a->attachable_type !== TicketNote::class || $atKill !== null) {
                return;
            }
            config(['queue.failed.database' => null]);
            $open = DB::transactionLevel() - $this->testLevel;
            $e = \Illuminate\Queue\TimeoutExceededException::forJob($job);
            $worker = app('queue.worker');
            (fn () => $this->markJobAsFailedIfItShouldFailOnTimeout('database', $job, $e))->call($worker);
            $atKill = [
                'open_before' => $open,
                'open_after' => DB::transactionLevel() - $this->testLevel,
                'rows' => Attachment::withTrashed()->count(),
                'markers' => array_map(fn ($r) => $r->context['reason'], $this->withMessage(RetryEmailAttachments::MARKER)),
                'notes' => count($this->markerNotes($ticketId)),
                // The framework's delete of the queue row ran inside the rolled-back transaction.
                'queued_rows' => DB::table('jobs')->count(),
            ];
            for ($i = $atKill['open_after']; $i < $open; $i++) {
                DB::beginTransaction();
            }
            throw new \RuntimeException('B4N-SYNTHETIC-KILLED'); // stands in for the kill
        });

        $job->fire();

        $this->assertNotNull($atKill, 'positive control: the kill point was reached');
        $this->assertSame(
            ['open_before' => 1, 'open_after' => 0, 'rows' => 0, 'markers' => ['timed_out'], 'notes' => 1, 'queued_rows' => 0],
            $atKill,
            '#5800: failed() rolled the link back itself, then discarded and marked outside it, and the queue row stays deleted',
        );
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::TIMEOUT_STEP_THREW), '#5997: no step threw');
    }

    public function test_the_re_delete_targets_only_this_jobs_row(): void
    {
        // #6005: the re-delete removes this job's row only; other rows already in the queue (here
        // pushRaw'd before the job fires, outside the link transaction) survive it. #6033: rows
        // this process queues INSIDE the link transaction are discarded by failed()'s rollBack;
        // test_a_row_this_process_queues_inside_the_link_transaction_is_discarded_by_the_timeout_rollback
        // pins that.
        $this->graph([$this->failedRead()]);
        $ticketId = $this->ticketWithQueuedRetry()->id;
        $job = $this->popRetry();
        $jobId = $job->getJobId();
        $queue = app('queue')->connection('database');
        $other = [$queue->pushRaw('{"displayName":"Other"}', 'default'), $queue->pushRaw('{"displayName":"Other"}', 'other')];
        $this->mock->append($this->read());
        $atKill = null;
        Attachment::updated(function (Attachment $a) use ($job, &$atKill) {
            if ($a->attachable_type !== TicketNote::class || $atKill !== null) {
                return;
            }
            config(['queue.failed.database' => null]);
            $open = DB::transactionLevel() - $this->testLevel;
            $worker = app('queue.worker');
            (fn () => $this->markJobAsFailedIfItShouldFailOnTimeout('database', $job, \Illuminate\Queue\TimeoutExceededException::forJob($job)))->call($worker);
            $atKill = DB::table('jobs')->orderBy('id')->pluck('id')->all();
            for ($i = DB::transactionLevel() - $this->testLevel; $i < $open; $i++) {
                DB::beginTransaction();
            }
            throw new \RuntimeException('B5-SYNTHETIC-KILLED');
        });

        $job->fire();

        $this->assertNotNull($atKill, 'positive control: the kill point was reached');
        $this->assertNotContains($jobId, $atKill, 'this job row is deleted');
        $this->assertSame($other, $atKill, 'and only it: the other rows stay');
    }

    public function test_a_re_delete_that_throws_is_recorded_by_step_and_class(): void
    {
        // #5997: the re-delete after failed()'s own rollback throws (a lock wait in deleteReserved).
        $this->graph([$this->failedRead()]);
        $ticketId = $this->ticketWithQueuedRetry()->id;
        $job = $this->popRetry();
        $this->mock->append($this->read());
        $atKill = null;
        Attachment::updated(function (Attachment $a) use ($job, &$atKill) {
            if ($a->attachable_type !== TicketNote::class || $atKill !== null) {
                return;
            }
            config(['queue.failed.database' => null]);
            $open = DB::transactionLevel() - $this->testLevel;
            $deletes = 0;
            DB::beforeExecuting(function (string $sql) use (&$deletes) {
                if (str_starts_with(strtolower($sql), 'delete from "jobs"') && ++$deletes === 2) {
                    throw new \RuntimeException('B5-SYNTHETIC-LOCK-WAIT '.self::MAILBOX);
                }
            });
            $worker = app('queue.worker');
            (fn () => $this->markJobAsFailedIfItShouldFailOnTimeout('database', $job, \Illuminate\Queue\TimeoutExceededException::forJob($job)))->call($worker);
            $atKill = ['deletes' => $deletes, 'markers' => count($this->withMessage(RetryEmailAttachments::MARKER))];
            for ($i = DB::transactionLevel() - $this->testLevel; $i < $open; $i++) {
                DB::beginTransaction();
            }
            throw new \RuntimeException('B5-SYNTHETIC-KILLED');
        });

        $job->fire();

        $this->assertSame(['deletes' => 2, 'markers' => 1], $atKill, 'positive control: the second delete (the re-delete) threw, and failed() went on to mark');
        $step = $this->withMessage(RetryEmailAttachments::TIMEOUT_STEP_THREW);
        $this->assertCount(1, $step, '#5997: the skipped re-delete is recorded, not swallowed');
        $this->assertSame(Level::Warning, $step[0]->level);
        $this->assertSame(['email_id' => $this->commandOf($job)->emailId, 'ticket_id' => $ticketId, 'step' => 'requeue_delete', 'exception' => \RuntimeException::class], $step[0]->context);
        $this->assertStringNotContainsString(self::MAILBOX, json_encode($step[0]->context));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function rollbackThrows(): array
    {
        return [
            // Not a lost connection: the connection keeps its transaction count.
            'rollback refused' => ['B5-SYNTHETIC-ROLLBACK-REFUSED', false],
            // #6032: a message the framework's lost-connection detector matches, so
            // handleRollBackException resets the count to 0 before rethrowing.
            'lost connection' => ['SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rollbackThrows')]
    public function test_a_rollback_that_throws_is_recorded_as_the_rollback_step(string $message, bool $lost): void
    {
        // #5997: the rollback itself throws, so the re-delete never runs. #6032: failed() still
        // never throws, and the marker is still recorded.
        $this->graph([$this->failedRead()]);
        $ticketId = $this->ticketWithQueuedRetry()->id;
        $command = $this->commandOf($this->popRetry());
        $level = DB::transactionLevel();
        (fn () => self::$progress[$this->progressKey()] = ['level' => $level])->call($command);
        DB::beginTransaction();
        $pdo = DB::connection()->getPdo();
        DB::connection()->setPdo(new class($message) extends \PDO
        {
            public function __construct(private string $failure) {}

            public function rollBack(): bool
            {
                throw new \PDOException($this->failure);
            }

            public function exec(string $statement): int|false
            {
                throw new \PDOException($this->failure);
            }

            public function inTransaction(): bool
            {
                return true;
            }
        });
        // setPdo() resets the connection's transaction count; the link transaction is still open.
        $levels = fn (int $n) => (fn () => $this->transactions = $n)->call(DB::connection());
        $levels($level + 1);
        // The lost-connection arm drops the transactions manager's records too; restored below.
        $manager = app('db.transactions');
        $records = (fn () => [$this->pendingTransactions, $this->currentTransaction])->call($manager);
        $thrown = null;
        $after = null;
        try {
            $command->failed(new \Illuminate\Queue\TimeoutExceededException('B5-SYNTHETIC-TIMEOUT'));
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            $after = DB::transactionLevel();
            DB::connection()->setPdo($pdo);
            (fn () => [$this->pendingTransactions, $this->currentTransaction] = $records)->call($manager);
            $levels($level + 1);
            DB::rollBack($level);
        }

        $this->assertNull($thrown, '#6032: nothing throws out of failed()');
        $this->assertSame($lost ? 0 : $level + 1, $after, 'positive control: the lost-connection arm reset the count, the other kept it');
        $step = $this->withMessage(RetryEmailAttachments::TIMEOUT_STEP_THREW);
        $this->assertCount(1, $step);
        $this->assertSame(Level::Warning, $step[0]->level);
        $this->assertSame(['rollback', \PDOException::class], [$step[0]->context['step'], $step[0]->context['exception']]);
        $this->assertSame($ticketId, $step[0]->context['ticket_id']);
        $marker = $this->withMessage(RetryEmailAttachments::MARKER);
        $this->assertCount(1, $marker, 'the marker WARNING is still written');
        $this->assertSame(['timed_out', false], [$marker[0]->context['reason'], $marker[0]->context['ticket_note_written']],
            'the note could not be written on the dead connection, and the record says so');
    }

    public function test_a_throw_out_of_handle_after_the_outcome_reports_that_outcome(): void
    {
        // #5820: the refusal leaves one id undiscarded, then its re-queue dispatch throws. The
        // worker calls failed() next; it must report the refusal and the id, not job_failed [].
        $this->graph([$this->failedRead(), $this->readWhileEditing('tech edit: lasting')]);
        $ticket = $this->ticketWithQueuedRetry();
        Attachment::forceDeleting(fn () => throw new \RuntimeException('B4N-SYNTHETIC-LOCK-WAIT'));
        $job = $this->popRetry();
        $command = $this->commandOf($job);
        DB::beforeExecuting(function (string $sql) {
            if (str_starts_with(strtolower($sql), 'insert into "jobs"')) {
                throw new \RuntimeException('B4N-SYNTHETIC-JOBS-DOWN');
            }
        });

        $thrown = null;
        try {
            $command->handle(app(EmailService::class));
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertSame('B4N-SYNTHETIC-JOBS-DOWN', $thrown?->getMessage(), 'positive control: the re-queue dispatch threw');
        $command->failed($thrown);

        $left = Attachment::withTrashed()->sole();
        $marker = $this->withMessage(RetryEmailAttachments::MARKER);
        $this->assertCount(1, $marker);
        $this->assertSame(['ticket_changed', [$left->id]], [$marker[0]->context['reason'], $marker[0]->context['undiscarded_attachment_ids']],
            "#5820: handle()'s outcome and its undiscarded id, not job_failed []");
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy());
        $command->failed(new \RuntimeException('B4N-SYNTHETIC-OTHER'));
        $this->assertSame([null], array_map(fn ($r) => $r->context['undiscarded_attachment_ids'], array_slice($this->withMessage(RetryEmailAttachments::MARKER), 1)),
            'the kept progress is spent by that one failed(): a later failure reports unknown ids');
    }

    public function test_a_retry_push_that_throws_after_commit_is_recorded_and_the_commit_work_still_runs(): void
    {
        // #5810: the jobs insert fails after the ticket committed.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        DB::beforeExecuting(function (string $sql) {
            if (str_starts_with(strtolower($sql), 'insert into "jobs"')) {
                throw new \RuntimeException('B4N-SYNTHETIC-JOBS-DOWN');
            }
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertNotNull($ticket, 'the import is not reported as failed');
        $this->assertSame([], $this->retryRows(), 'positive control: nothing was queued');
        $notQueued = $this->withMessage(RetryEmailAttachments::NOT_QUEUED);
        $this->assertCount(1, $notQueued);
        // #5994: read back, no row found; #5993/#6006: ERROR, the level a monitor filtering at error sees.
        $this->assertSame(['email_id' => $email->id, 'ticket_id' => $ticket->id, 'exception' => \RuntimeException::class, 'queued_row' => false], $notQueued[0]->context);
        $this->assertSame(Level::Error, $notQueued[0]->level);
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::PUSH_THREW_ROW_FOUND));
        $marker = $this->withMessage(RetryEmailAttachments::MARKER);
        $this->assertCount(1, $marker, '#6006');
        $this->assertSame(Level::Warning, $marker[0]->level, '#6006');
        $this->assertSame(['not_queued', [], true], [$marker[0]->context['reason'], $marker[0]->context['undiscarded_attachment_ids'], $marker[0]->context['ticket_note_written']]);
        $this->assertCount(1, $this->markerNotes($ticket->id));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy(), 'the notification is not lost');
    }

    public function test_a_push_that_throws_after_its_row_was_written_leaves_the_work_to_the_queued_job(): void
    {
        // #5994: the INSERT committed, then the push threw (a JobQueued listener; #6027: a lost
        // reply is not this shape, the connection re-runs the INSERT at level 0). The row is read
        // back by push id, so no not_queued marker and no inline commit work: the queued job
        // runs them once.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $thrown = 0;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobQueued::class, function ($event) use (&$thrown) {
            if ($event->job instanceof RetryEmailAttachments && $thrown++ === 0) {
                throw new \RuntimeException('B5-SYNTHETIC-AFTER-INSERT');
            }
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(1, $thrown, 'positive control: the push threw after the insert');
        $rows = $this->retryRows();
        $this->assertCount(1, $rows, 'positive control: the row was written');
        $found = $this->withMessage(RetryEmailAttachments::PUSH_THREW_ROW_FOUND);
        $this->assertCount(1, $found);
        $this->assertSame(Level::Warning, $found[0]->level);
        $this->assertSame(['email_id' => $email->id, 'ticket_id' => $ticket->id, 'exception' => \RuntimeException::class, 'found_in' => 'jobs'], $found[0]->context);
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::NOT_QUEUED));
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::MARKER), 'no not_queued marker for a row that exists');
        $this->assertSame([], $this->seen->getArrayCopy(), 'no inline commit work');
        $pushId = $this->commandOf($this->popRetry())->pushId;
        $this->assertNotNull($pushId);
        $this->assertStringContainsString($pushId, $rows[0]->payload, 'the row carries the push id it was read back by');

        // The queued job runs: one notification in all.
        DB::table('jobs')->update(['reserved_at' => null, 'attempts' => 0]);
        $this->mock->append($this->read());
        $this->popRetry()->fire();
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the commit work ran once, from the job');
        $this->assertSame([], $this->markerNotes($ticket->id));
    }

    public function test_a_push_whose_row_cannot_be_read_back_says_so_and_runs_the_commit_work(): void
    {
        // #5994: the read-back itself throws, so whether a row exists is not known: queued_row null.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        DB::beforeExecuting(function (string $sql) {
            $sql = strtolower($sql);
            if (str_starts_with($sql, 'insert into "jobs"') || (str_contains($sql, 'from "jobs"') && str_contains($sql, 'like'))) {
                throw new \RuntimeException('B5-SYNTHETIC-JOBS-DOWN');
            }
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $notQueued = $this->withMessage(RetryEmailAttachments::NOT_QUEUED);
        $this->assertCount(1, $notQueued);
        $this->assertSame(Level::Error, $notQueued[0]->level);
        $this->assertArrayHasKey('queued_row', $notQueued[0]->context);
        $this->assertNull($notQueued[0]->context['queued_row']);
        $this->assertCount(1, $this->markerNotes($ticket->id));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy());
        // #6035: a row may have been written after all, and its run may link the attachments,
        // so the note does not say they were not added. #6101: and it names what is unknown
        // (whether a retry is queued), not a link read-back that never ran on this path.
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::MARKER));
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::MARKER_LINK_UNKNOWN), '#6101: no link was read back here');
        $unknown = $this->withMessage(RetryEmailAttachments::MARKER_QUEUE_UNKNOWN);
        $this->assertCount(1, $unknown);
        $this->assertSame('not_queued', $unknown[0]->context['reason']);
        $this->assertFalse($unknown[0]->context['queue_read_back'], '#6135: the jobs table could not be read');
        $this->assertSame(['jobs', \RuntimeException::class], [$notQueued[0]->context['read_back_step'], $notQueued[0]->context['read_back_exception']],
            '#6092: the null names the step that threw and its class');
        $note = $this->markerNotes($ticket->id)[0];
        $this->assertStringContainsString('may not have been added', $note);
        $this->assertStringContainsString('whether a retry to add them is queued could not be read back', $note);
        $this->assertStringNotContainsString('whether they were linked', $note, '#6101');
    }

    /** Runs $whenQueued (once) when this test's first-run retry is being pushed, then throws out of the push. */
    private function pushThrowsAfter(string $event, \Closure $whenQueued): void
    {
        $done = false;
        \Illuminate\Support\Facades\Event::listen($event, function ($e) use (&$done, $whenQueued) {
            if ($done || ! $e->job instanceof RetryEmailAttachments || $e->job->refusals !== 0) {
                return;
            }
            $done = true;
            $whenQueued($e->job, $e);

            throw new \RuntimeException('B6-SYNTHETIC-PUSH-THREW');
        });
    }

    public function test_a_foreign_row_carrying_this_push_id_does_not_count_as_this_dispatchs_row(): void
    {
        // #6034/#6025: the push throws before its own INSERT. Other rows in the same queue carry
        // the push id: a job whose payload embeds this command, and another retry row that
        // mentions the uuid in its payload but is not this dispatch.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueueing::class, function (RetryEmailAttachments $job) {
            $queue = app('queue')->connection('database');
            $queue->pushRaw((string) json_encode(['displayName' => 'App\\Jobs\\B6SyntheticWrapper', 'data' => ['command' => serialize($job)]]), 'default');
            $other = new RetryEmailAttachments($job->emailId + 1000, $job->ticketId + 1000, null, []);
            $queue->pushRaw((string) json_encode(['displayName' => RetryEmailAttachments::class, 'note' => $job->pushId,
                'data' => ['commandName' => RetryEmailAttachments::class, 'command' => serialize($other)]]), 'default');
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $pushId = null;
        $foreign = DB::table('jobs')->where('payload', 'like', '%B6SyntheticWrapper%')->value('payload');
        $this->assertNotNull($foreign, 'positive control: the foreign rows were written');
        preg_match('/s:6:\\\\"pushId\\\\";s:36:\\\\"([0-9a-f-]{36})/', $foreign, $m);
        $pushId = $m[1] ?? null;
        $this->assertNotNull($pushId);
        $this->assertSame(2, DB::table('jobs')->where('payload', 'like', '%'.$pushId.'%')->count(), 'positive control: both rows match a LIKE on the push id');
        $notQueued = $this->withMessage(RetryEmailAttachments::NOT_QUEUED);
        $this->assertCount(1, $notQueued, 'neither row is this dispatch\'s');
        $this->assertFalse($notQueued[0]->context['queued_row']);
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::PUSH_THREW_ROW_FOUND));
        $this->assertCount(1, $this->markerNotes($ticket->id));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy());
    }

    public function test_a_row_already_moved_to_failed_jobs_is_found_there(): void
    {
        // #6026: the INSERT committed and a worker failed the job (failed_jobs, database-uuids)
        // before the push's throw was read back; the jobs table no longer has the row.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueued::class, function (RetryEmailAttachments $job, $event) {
            $payload = DB::table('jobs')->where('id', $event->id)->value('payload');
            DB::table('jobs')->where('id', $event->id)->delete();
            app('queue.failer')->log('database', 'default', $payload, new \RuntimeException('B6-SYNTHETIC-WORKER-FAILED'));
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame([], $this->retryRows(), 'positive control: the jobs row is gone');
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'positive control: it is in failed_jobs');
        $found = $this->withMessage(RetryEmailAttachments::PUSH_THREW_ROW_FOUND);
        $this->assertCount(1, $found);
        $this->assertSame('failed_jobs', $found[0]->context['found_in']);
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::NOT_QUEUED), 'not reported as not queued');
        $this->assertSame([], $this->markerNotes($ticket->id), 'no second marker: failed() owns it');
        $this->assertSame([], $this->seen->getArrayCopy(), 'no inline commit work');
    }

    public function test_a_row_a_worker_already_ran_and_deleted_is_not_run_again_inline(): void
    {
        // #6026: the INSERT committed and a worker ran the job and deleted its row before the
        // push's throw was read back. The worker is another process: the push key is its own.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueued::class, function () {
            $pushing = self::retryEmailAttachmentsStatics()['pushing'];
            $this->mock->append($this->read());
            $this->popRetry()->fire();
            (fn () => self::$pushing = $pushing)->bindTo(null, RetryEmailAttachments::class)();
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame([], $this->retryRows(), 'positive control: the worker deleted the row');
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the commit work ran once, from the worker');
        $found = $this->withMessage(RetryEmailAttachments::PUSH_THREW_ROW_FOUND);
        $this->assertCount(1, $found);
        $this->assertSame('started', $found[0]->context['found_in']);
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::NOT_QUEUED));
        $this->assertSame([], $this->markerNotes($ticket->id));
    }

    public function test_a_push_nested_inside_another_does_not_clear_the_outer_push_key(): void
    {
        // #6038: while this retry is being pushed, another retry (another key) is pushed and
        // returns; then this push throws. It is handled here, not rethrown into the import.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueueing::class, function (RetryEmailAttachments $job) {
            (new RetryEmailAttachments($job->emailId, $job->ticketId, $job->noteId, [], 1))->queueAfterCommit();
        });

        $thrown = null;
        try {
            $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNull($thrown, 'the outer push throw is not rethrown');
        $this->assertCount(1, $this->retryRows(), 'positive control: the nested push queued its row');
        $notQueued = $this->withMessage(RetryEmailAttachments::NOT_QUEUED);
        $this->assertCount(1, $notQueued);
        $this->assertFalse($notQueued[0]->context['queued_row']);
        $this->assertSame([], self::retryEmailAttachmentsStatics()['pushing'], 'no push key is left behind');
    }

    public function test_a_push_that_throws_for_its_own_payload_still_queues_the_technician_loop(): void
    {
        // #6030: the queue is healthy and only this retry's push threw, so the loop is queued.
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        $c = $this->commandOf($this->popRetry());
        DB::table('jobs')->delete();
        Setting::setValue('technician_enabled', true);
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueueing::class, fn () => null);

        (new RetryEmailAttachments($c->emailId, $c->ticketId, $c->noteId, $c->baseline))->queueAfterCommit();

        $this->assertCount(1, $this->withMessage(RetryEmailAttachments::NOT_QUEUED), 'positive control: the push threw');
        $this->assertSame([], $this->retryRows(), 'positive control: no retry row');
        $loops = DB::table('jobs')->get()->filter(fn ($r) => (json_decode($r->payload, true)['displayName'] ?? null) === \App\Jobs\RunTechnicianLoop::class);
        $this->assertCount(1, $loops, 'the loop is queued, not lost');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::COMMIT_STEP_THREW));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy());
    }

    /** A ticket and its retry command, queued on the database queue and then run through the sync queue. */
    private function syncRetry(): array
    {
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        $command = $this->commandOf($this->popRetry());
        DB::table('jobs')->delete();
        config(['queue.default' => 'sync']);

        return [$ticket, new RetryEmailAttachments($command->emailId, $command->ticketId, $command->noteId, $command->baseline)];
    }

    public function test_under_the_sync_queue_a_throw_out_of_handle_is_rethrown_and_reported_once(): void
    {
        // #5999: the arm that tells the job's own throw from the push's throw.
        [$ticket, $job] = $this->syncRetry();
        $this->app->instance(EmailService::class, \Mockery::mock(EmailService::class, function ($m) {
            $m->shouldReceive('retryMessageRead')->once()->andThrow(new \RuntimeException('B5-SYNTHETIC-HANDLE'));
            $m->shouldReceive('abandonRetry')->andReturn(['linked' => false, 'undiscarded_attachment_ids' => []]);
        }));

        $thrown = null;
        try {
            $job->queueAfterCommit();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertSame('B5-SYNTHETIC-HANDLE', $thrown?->getMessage(), 'rethrown unchanged');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::NOT_QUEUED), 'not reported as a push that threw');
        $this->assertSame(['job_failed'], array_map(fn ($r) => $r->context['reason'], $this->withMessage(RetryEmailAttachments::MARKER)), 'one marker, from failed()');
        $this->assertCount(1, $this->markerNotes($ticket->id));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy(), 'one notification');
    }

    public function test_under_the_sync_queue_a_throw_before_handle_is_reported_once(): void
    {
        // #6004: a JobProcessing listener throws; the sync queue fails the job (failed()) before
        // handle() runs, then rethrows into the push.
        [$ticket, $job] = $this->syncRetry();
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobProcessing::class, function () {
            throw new \RuntimeException('B5-SYNTHETIC-BEFORE-HANDLE');
        });

        $thrown = null;
        try {
            $job->queueAfterCommit();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertSame('B5-SYNTHETIC-BEFORE-HANDLE', $thrown?->getMessage(), 'rethrown unchanged');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::NOT_QUEUED));
        $this->assertSame(['job_failed'], array_map(fn ($r) => $r->context['reason'], $this->withMessage(RetryEmailAttachments::MARKER)), 'one marker, not a second not_queued one');
        $this->assertCount(1, $this->markerNotes($ticket->id));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy(), 'one notification');
    }

    public function test_a_failed_push_with_the_technician_enabled_loses_the_loop_and_records_it(): void
    {
        // #5995: the docblock's claim. The notification runs; the loop's dispatch hits the same
        // failing jobs insert, so it is lost and recorded only as COMMIT_STEP_THREW.
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        $c = $this->commandOf($this->popRetry());
        DB::table('jobs')->delete();
        Setting::setValue('technician_enabled', true);
        $attempted = [];
        DB::beforeExecuting(function (string $sql, array $bindings) use (&$attempted) {
            if (str_starts_with(strtolower($sql), 'insert into "jobs"')) {
                foreach ($bindings as $b) {
                    if (is_string($b) && ($name = json_decode($b, true)['displayName'] ?? null) !== null) {
                        $attempted[] = $name;
                    }
                }
                throw new \RuntimeException('B5-SYNTHETIC-JOBS-DOWN');
            }
        });

        (new RetryEmailAttachments($c->emailId, $c->ticketId, $c->noteId, $c->baseline))->queueAfterCommit();

        $this->assertTrue(\App\Support\TechnicianConfig::enabled(), 'positive control: the loop would be dispatched');
        $this->assertCount(1, $this->withMessage(RetryEmailAttachments::NOT_QUEUED), 'positive control: the push threw');
        // #6033: the loop's push was attempted and hit the failing insert (the count of rows is
        // 0 whatever the code does, since every insert throws).
        $this->assertSame([RetryEmailAttachments::class, \App\Jobs\RunTechnicianLoop::class], $attempted, 'the loop dispatch was attempted');
        $threw = $this->withMessage(RetryEmailAttachments::COMMIT_STEP_THREW);
        $this->assertCount(1, $threw);
        $this->assertSame(Level::Warning, $threw[0]->level);
        $this->assertSame('technician_dispatch', $threw[0]->context['step']);
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy(), 'the notification still runs');
    }

    public static function commitStepThrows(): array
    {
        return [
            'lookup' => ['lookup', 'select * from "tickets" where "tickets"."id" = ?'],
            'technician dispatch' => ['technician_dispatch', null],
            'notification' => ['notification', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('commitStepThrows')]
    public function test_the_commit_work_warning_names_the_step_that_threw(string $step, ?string $sql): void
    {
        // #5811: the record names what threw, not "notification or technician dispatch".
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        Setting::setValue('technician_enabled', true);
        $command = $this->commandOf($this->popRetry());
        $this->seen->exchangeArray([]);
        if ($step === 'technician_dispatch') {
            // The loop's push onto the database queue fails (its jobs insert).
            DB::beforeExecuting(function (string $q) {
                if (str_starts_with(strtolower($q), 'insert into "jobs"')) {
                    throw new \RuntimeException('B4N-SYNTHETIC-PUSH');
                }
            });
        } elseif ($step === 'notification') {
            $this->app->instance(NotificationService::class, new class extends NotificationService
            {
                public function __construct() {}

                public function notifyEmailAdded(Ticket $ticket, Email $email): void
                {
                    throw new \RuntimeException('B4N-SYNTHETIC-NOTIFY');
                }
            });
        } else {
            DB::beforeExecuting(function (string $q) use ($sql) {
                if (str_starts_with($q, $sql)) {
                    throw new \RuntimeException('B4N-SYNTHETIC-LOOKUP');
                }
            });
        }

        (fn () => $this->runCommitWork())->call($command);

        $threw = $this->withMessage(RetryEmailAttachments::COMMIT_STEP_THREW);
        $this->assertCount(1, $threw, 'one record, from the step that threw');
        $this->assertSame(Level::Warning, $threw[0]->level, '#6006');
        $this->assertSame(['email_id' => $command->emailId, 'ticket_id' => $ticket->id, 'step' => $step, 'exception' => \RuntimeException::class],
            $threw[0]->context);
    }

    public function test_the_commit_work_records_a_missing_ticket_or_email(): void
    {
        // #5811: the arm that found no row used to return with no record.
        $command = new RetryEmailAttachments(999001, 999002, null, []);

        (fn () => $this->runCommitWork())->call($command);

        $skipped = $this->withMessage(RetryEmailAttachments::COMMIT_SKIPPED);
        $this->assertCount(1, $skipped);
        $this->assertSame(Level::Warning, $skipped[0]->level, '#6006');
        $this->assertSame(['email_id' => 999001, 'ticket_id' => 999002, 'ticket_found' => false, 'ticket_trashed' => false, 'email_found' => false], $skipped[0]->context);
    }

    public function test_the_commit_work_skip_says_a_soft_deleted_ticket_exists(): void
    {
        // #6008: the ticket row is present but trashed; the record must not read as a missing row.
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        $command = $this->commandOf($this->popRetry());
        $ticket->delete();
        $this->assertTrue(Ticket::withTrashed()->whereKey($ticket->id)->exists(), 'positive control: the row is kept, trashed');
        $this->seen->exchangeArray([]);

        (fn () => $this->runCommitWork())->call($command);

        $skipped = $this->withMessage(RetryEmailAttachments::COMMIT_SKIPPED);
        $this->assertCount(1, $skipped);
        $this->assertSame(['email_id' => $command->emailId, 'ticket_id' => $ticket->id, 'ticket_found' => true, 'ticket_trashed' => true, 'email_found' => true], $skipped[0]->context);
        $this->assertSame([], $this->seen->getArrayCopy(), 'nothing runs for a trashed ticket');
    }

    public function test_the_commit_work_dispatches_the_technician_loop_after_commit(): void
    {
        // #5816: inside an open transaction the loop is not queued until it commits, and a
        // rollback queues none.
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        Setting::setValue('technician_enabled', true);
        $command = $this->commandOf($this->popRetry());
        $loops = fn () => DB::table('jobs')->get()->filter(fn ($r) => (json_decode($r->payload, true)['displayName'] ?? null) === \App\Jobs\RunTechnicianLoop::class)->count();

        $inside = null;
        DB::transaction(function () use ($command, $loops, &$inside) {
            (fn () => $this->runCommitWork())->call($command);
            $inside = $loops();
        });
        $this->assertSame([0, 1], [$inside, $loops()], 'queued at commit, not inside the transaction');

        DB::table('jobs')->delete();
        try {
            DB::transaction(function () use ($command) {
                (fn () => $this->runCommitWork())->call($command);
                throw new \RuntimeException('B4N-SYNTHETIC-ROLLBACK');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, $loops(), 'a rolled-back transaction queues no loop');
        $rolled = $this->withMessage(RetryEmailAttachments::COMMIT_STEP_ROLLED_BACK);
        $this->assertCount(1, $rolled, '#6000: the dropped loop is recorded');
        $this->assertSame(['email_id' => $command->emailId, 'ticket_id' => $ticket->id, 'step' => 'technician_dispatch', 'exception' => null], $rolled[0]->context);
    }

    public function test_a_technician_push_that_throws_at_commit_is_recorded_and_not_thrown_to_the_committer(): void
    {
        // #6000: the loop's push runs at the open transaction's commit, outside runCommitWork's
        // own try; it is still caught and recorded under technician_dispatch, and the commit's
        // caller sees no exception.
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        Setting::setValue('technician_enabled', true);
        $command = $this->commandOf($this->popRetry());
        DB::table('jobs')->delete();
        $inside = null;
        $thrown = null;
        $loopRows = fn () => DB::table('jobs')->get()->filter(fn ($r) => (json_decode($r->payload, true)['displayName'] ?? null) === \App\Jobs\RunTechnicianLoop::class)->count();
        try {
            DB::transaction(function () use ($command, &$inside, $loopRows) {
                (fn () => $this->runCommitWork())->call($command);
                // #6145: counted in the jobs table, so a push inside the transaction shows here.
                $inside = $loopRows();
                DB::beforeExecuting(function (string $sql) {
                    if (str_starts_with(strtolower($sql), 'insert into "jobs"')) {
                        throw new \RuntimeException('B7-SYNTHETIC-PUSH-AT-COMMIT');
                    }
                });
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertSame(0, $inside, 'positive control: nothing was pushed inside the transaction');
        $this->assertNull($thrown, '#6000: the commit does not throw');
        $threw = $this->withMessage(RetryEmailAttachments::COMMIT_STEP_THREW);
        $this->assertCount(1, $threw);
        $this->assertSame(['email_id' => $command->emailId, 'ticket_id' => $ticket->id, 'step' => 'technician_dispatch', 'exception' => \RuntimeException::class], $threw[0]->context);
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::COMMIT_STEP_ROLLED_BACK));
    }

    public function test_a_row_this_process_queues_inside_the_link_transaction_is_discarded_by_the_timeout_rollback(): void
    {
        // #6033: the #6005 risk the re-delete test does not reach. A row this process queues
        // inside the link transaction (as signal routing would) is in that transaction, so
        // failed()'s rollBack under a non-database failer discards it with the link; a row
        // queued before the transaction survives. Pinned as the current behaviour.
        $this->graph([$this->failedRead()]);
        $this->ticketWithQueuedRetry();
        $job = $this->popRetry();
        $queue = app('queue')->connection('database');
        $before = $queue->pushRaw('{"displayName":"B7Before"}', 'default');
        $this->mock->append($this->read());
        $atKill = null;
        Attachment::updated(function (Attachment $a) use ($job, $queue, &$atKill) {
            if ($a->attachable_type !== TicketNote::class || $atKill !== null) {
                return;
            }
            $inside = $queue->pushRaw('{"displayName":"B7Inside"}', 'default');
            $visible = DB::table('jobs')->where('id', $inside)->exists();
            config(['queue.failed.database' => null]);
            $open = DB::transactionLevel() - $this->testLevel;
            $worker = app('queue.worker');
            (fn () => $this->markJobAsFailedIfItShouldFailOnTimeout('database', $job, \Illuminate\Queue\TimeoutExceededException::forJob($job)))->call($worker);
            $atKill = ['inside_visible' => $visible, 'rows' => DB::table('jobs')->orderBy('id')->pluck('payload')->all()];
            for ($i = DB::transactionLevel() - $this->testLevel; $i < $open; $i++) {
                DB::beginTransaction();
            }
            throw new \RuntimeException('B7-SYNTHETIC-KILLED');
        });

        $job->fire();

        $this->assertNotNull($atKill, 'positive control: the kill point was reached');
        $this->assertTrue($atKill['inside_visible'], 'positive control: the inside row was written');
        $this->assertSame(['{"displayName":"B7Before"}'], $atKill['rows'], 'the inside row went with the rollback; the earlier row stayed');
        $this->assertGreaterThan(0, $before);
    }

    // ── b7 (card 6ac7c168): the push read-back's cache entries, window and timing ──

    /** The default cache store, an array store whose put/has/forget throw for keys matching $failing. */
    private function cacheFailing(string $failing, array $methods = ['put']): void
    {
        $store = new class($failing, $methods) extends ArrayStore
        {
            public function __construct(private string $failing, private array $methods)
            {
                parent::__construct();
            }

            private function check(string $method, string $key): void
            {
                if (in_array($method, $this->methods, true) && str_contains($key, $this->failing)) {
                    throw new \RuntimeException('B7-SYNTHETIC-CACHE-DOWN '.self::class);
                }
            }

            public function put($key, $value, $seconds)
            {
                $this->check('put', $key);

                return parent::put($key, $value, $seconds);
            }

            public function get($key)
            {
                $this->check('get', $key);

                return parent::get($key);
            }

            public function forget($key)
            {
                $this->check('forget', $key);

                return parent::forget($key);
            }
        };
        \Illuminate\Support\Facades\Cache::swap(new Repository($store));
    }

    /** The read-back's cache keys still present. */
    private function readBackCacheKeys(): array
    {
        $store = \Illuminate\Support\Facades\Cache::getStore();
        $storage = (fn () => $this->storage)->call($store);

        return array_values(array_filter(array_keys($storage), fn ($k) => str_starts_with($k, 'retry-email-attachments:')));
    }

    public function test_the_webhook_answers_202_before_a_not_queued_marker_and_notification_run(): void
    {
        // #6007/#6045: on the not_queued path the marker and notifyEmailAdded run after the
        // response, in the request's terminate phase, not inside the afterCommit callback.
        $this->databaseQueue();
        Setting::setValue('graph_webhook_client_state', 'state-b7-synthetic');
        Setting::setValue('email_auto_ticket', '1');
        $client = Client::create(['name' => 'Example Client']);
        Person::create(['client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Ada', 'last_name' => 'Contact', 'email' => 'user@example.test', 'is_active' => true]);
        $this->graph([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'id' => 'MSG-1', 'internetMessageId' => '<m1@example.test>',
                'from' => ['emailAddress' => ['address' => 'user@example.test', 'name' => 'User']],
                'subject' => 'Printer offline', 'body' => ['contentType' => 'html', 'content' => self::CID_HTML],
                'hasAttachments' => false, 'receivedDateTime' => now()->toIso8601String(),
            ])),
            $this->failedRead(),
        ]);
        DB::beforeExecuting(function (string $sql, array $bindings) {
            if (str_starts_with(strtolower($sql), 'insert into "jobs"') && str_contains(implode(' ', array_filter($bindings, 'is_string')), 'RetryEmailAttachments')) {
                throw new \RuntimeException('B7-SYNTHETIC-JOBS-DOWN');
            }
        });
        $atResponse = null;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, function ($event) use (&$atResponse) {
            $atResponse = [
                'status' => $event->response->getStatusCode(),
                'not_queued' => count($this->withMessage(RetryEmailAttachments::NOT_QUEUED)),
                'markers' => count($this->withMessage(RetryEmailAttachments::MARKER)),
                'notified' => count($this->seen),
            ];
        });

        $response = $this->postJson('/api/webhooks/graph/mail', ['value' => [[
            'clientState' => 'state-b7-synthetic', 'resource' => 'users/MSG-OWNER/messages/MSG-1',
        ]]]);

        $response->assertStatus(202);
        $this->assertSame(['status' => 202, 'not_queued' => 1, 'markers' => 0, 'notified' => 0], $atResponse,
            '#6007: the push and its read-back ran before the response; the marker and the notification did not');
        $ticketId = (int) Email::where('graph_id', 'MSG-1')->value('ticket_id');
        $this->assertGreaterThan(0, $ticketId);
        $this->assertCount(1, $this->withMessage(RetryEmailAttachments::MARKER), 'the marker ran after the response');
        $this->assertCount(1, $this->markerNotes($ticketId));
        $this->assertSame([['notifyEmailAdded', $ticketId, 0]], $this->seen->getArrayCopy(), 'and the notification');
    }

    public function test_outside_a_request_the_not_queued_work_runs_inline(): void
    {
        // #6007 control: in the console or a worker there is no response to hold; nothing is deferred.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueueing::class, fn () => null);

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertCount(1, $this->markerNotes($ticket->id), 'written before autoCreateTicketFromEmail returned');
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy());
        $this->assertSame(0, count(\Illuminate\Support\defer()), 'nothing deferred');
    }

    public function test_a_run_failed_before_handle_is_found_while_its_failed_runs(): void
    {
        // #6093: a worker reserves the row and the run fails before handle(); Job::fail deletes
        // the row and calls failed(), and only after that does the failer write failed_jobs (a
        // JobFailed listener the worker registers; none here, so the window stays open). A
        // read-back in that window finds the started entry failed() wrote, not "not queued".
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueued::class, function () {
            $pushing = self::retryEmailAttachmentsStatics()['pushing'];
            $this->popRetry()->fail(new \RuntimeException('B7-SYNTHETIC-BEFORE-HANDLE'));
            (fn () => self::$pushing = $pushing)->bindTo(null, RetryEmailAttachments::class)();
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame([], $this->retryRows(), 'positive control: Job::fail deleted the row');
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'positive control: failed_jobs not written yet');
        $found = $this->withMessage(RetryEmailAttachments::PUSH_THREW_ROW_FOUND);
        $this->assertCount(1, $found);
        $this->assertSame('started', $found[0]->context['found_in']);
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::NOT_QUEUED), 'not reported as not queued');
        $this->assertSame(['job_failed'], array_map(fn ($r) => $r->context['reason'], $this->withMessage(RetryEmailAttachments::MARKER)), 'one marker, from failed()');
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy(), 'the commit work ran once');
        $this->assertSame([], $this->readBackCacheKeys(), '#6094: no entry is left');
    }

    public function test_a_normal_dispatch_and_its_run_leave_no_cache_entry(): void
    {
        // #6094: the file store deletes an expired entry only when it is read; a run of a push
        // that has finished writes nothing, so no entry is left per retry.
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        $this->assertSame([], $this->readBackCacheKeys(), 'the pending entry is removed once the push returns');
        $this->mock->append($this->read());
        $this->popRetry()->fire();

        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'positive control: the run linked');
        $this->assertSame([], $this->readBackCacheKeys(), 'no started entry is written');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED));
    }

    public function test_the_started_entry_of_a_run_inside_the_push_is_removed_after_the_read_back(): void
    {
        // #6094: the run that wrote a started entry while the push was pending; once its read-back
        // has used it, queueAfterCommit() removes both entries.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $keys = null;
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueued::class, function () use (&$keys) {
            $pushing = self::retryEmailAttachmentsStatics()['pushing'];
            $this->mock->append($this->read());
            $this->popRetry()->fire();
            $keys = $this->readBackCacheKeys();
            (fn () => self::$pushing = $pushing)->bindTo(null, RetryEmailAttachments::class)();
        });

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertCount(2, $keys ?? [], 'positive control: pending and started were both present during the push');
        $this->assertSame('started', $this->withMessage(RetryEmailAttachments::PUSH_THREW_ROW_FOUND)[0]->context['found_in'] ?? null);
        $this->assertSame([], $this->readBackCacheKeys());
    }

    public function test_a_started_write_that_fails_is_recorded_and_the_run_goes_on(): void
    {
        // #6092 / #6096 (c): the store throws on the started write. The run still links the
        // attachment; the failure is a status-only record, not silence.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->cacheFailing(':started:');
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueued::class, function () {
            $pushing = self::retryEmailAttachmentsStatics()['pushing'];
            $this->mock->append($this->read());
            $this->popRetry()->fire();
            (fn () => self::$pushing = $pushing)->bindTo(null, RetryEmailAttachments::class)();
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(TicketNote::class, Attachment::sole()->attachable_type, 'the run was not stopped by the cache');
        $failed = $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED);
        $this->assertCount(1, $failed);
        $this->assertSame(Level::Warning, $failed[0]->level);
        $this->assertSame(['email_id' => $email->id, 'ticket_id' => $ticket->id, 'step' => 'started_put', 'exception' => \RuntimeException::class], $failed[0]->context);
        $this->assertStringNotContainsString('B7-SYNTHETIC', json_encode($failed[0]->context), 'C-56: no message text');
        // #6139: a known wrong answer, pinned so a change to it is seen, not endorsed: without
        // the entry the read-back says false, the note says 'were not added' though the run
        // added them, and the commit work runs twice. The started_put record above is the
        // only sign; listed among the false arms in queueAfterCommit()'s docblock.
        $this->assertFalse($this->withMessage(RetryEmailAttachments::NOT_QUEUED)[0]->context['queued_row']);
        $this->assertCount(2, $this->seen, '#6139: the commit work ran twice (the run, then inline)');
    }

    public function test_a_pending_write_that_fails_makes_a_not_found_read_back_unknown(): void
    {
        // #6091/#6092: with no pending entry a run cannot leave its started entry, so "no row
        // found" is not "not added": queued_row null, the queue-unknown note, and the record.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->cacheFailing(':pending:');
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueueing::class, fn () => null);

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(['pending_put'], array_map(fn ($r) => $r->context['step'], $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED)));
        $notQueued = $this->withMessage(RetryEmailAttachments::NOT_QUEUED);
        $this->assertCount(1, $notQueued);
        $this->assertSame([null, 'pending', null], [$notQueued[0]->context['queued_row'], $notQueued[0]->context['read_back_step'], $notQueued[0]->context['read_back_exception']]);
        $unknown = $this->withMessage(RetryEmailAttachments::MARKER_QUEUE_UNKNOWN);
        $this->assertCount(1, $unknown);
        $this->assertTrue($unknown[0]->context['queue_read_back'], '#6135: both tables were read');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::MARKER));
        $this->assertCount(1, $this->markerNotes($ticket->id));
        // #6135: no row was queued; what is unknown is whether a run already ran.
        $note = $this->markerNotes($ticket->id)[0];
        $this->assertStringContainsString('no queued retry to add them was found', $note);
        $this->assertStringContainsString('whether a retry already ran could not be read back', $note);
        $this->assertStringNotContainsString('whether a retry to add them is queued', $note);
    }

    public function test_a_started_read_that_throws_is_unknown_and_names_the_step(): void
    {
        // #6096 (d) / #6092: both tables were read and held no row; the started read threw.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->cacheFailing(':started:', ['get']);
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueueing::class, fn () => null);

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $notQueued = $this->withMessage(RetryEmailAttachments::NOT_QUEUED);
        $this->assertCount(1, $notQueued);
        $this->assertSame([null, 'started', \RuntimeException::class], [$notQueued[0]->context['queued_row'], $notQueued[0]->context['read_back_step'], $notQueued[0]->context['read_back_exception']]);
        $unknown = $this->withMessage(RetryEmailAttachments::MARKER_QUEUE_UNKNOWN);
        $this->assertCount(1, $unknown);
        // #6173: the started arm emits the #6135 wording too: the jobs table was read and held
        // no row; whether a run already ran is what could not be read back.
        $this->assertTrue($unknown[0]->context['queue_read_back']);
        $note = $this->markerNotes($ticket->id)[0];
        $this->assertStringContainsString('no queued retry to add them was found', $note);
        $this->assertStringContainsString('whether a retry already ran could not be read back', $note);
        $this->assertStringNotContainsString('whether a retry to add them is queued', $note);
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy());
    }

    public function test_the_read_back_reads_only_rows_inserted_since_the_push_began(): void
    {
        // #6031/#6095: each table is read above the id it held before the push (the primary
        // key), on this dispatch's queue, never a scan of every payload. An older row that
        // carries this dispatch's own command (as a replay would) is outside the window.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $queue = app('queue')->connection('database');
        $old = ['jobs' => $queue->pushRaw('{"displayName":"Old"}', 'default'), 'failed_jobs' => null];
        app('queue.failer')->log('database', 'default', (string) json_encode(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'displayName' => 'Old']), new \RuntimeException('B7-SYNTHETIC-OLD'));
        $old['failed_jobs'] = (int) DB::table('failed_jobs')->max('id');
        $atPush = null;
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueueing::class, function (RetryEmailAttachments $job) use (&$atPush) {
            $atPush = (int) DB::table('jobs')->max('id');
            // A row of this very dispatch, inserted below the floors (as if before the push).
            DB::table('jobs')->insert(['id' => 0, 'queue' => 'default', 'payload' => json_encode(['displayName' => RetryEmailAttachments::class,
                'data' => ['commandName' => RetryEmailAttachments::class, 'command' => serialize($job)]]), 'attempts' => 0, 'available_at' => 0, 'created_at' => 0]);
        });
        $reads = [];
        DB::listen(function ($q) use (&$reads) {
            $sql = strtolower($q->sql);
            if (str_contains($sql, 'like')) {
                $reads[] = [$sql, $q->bindings, $q->readWriteType];
            }
        });

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertCount(2, $reads, 'jobs, then failed_jobs');
        [$jobsSql, $jobsBindings, $jobsPdo] = $reads[0];
        [$failedSql, $failedBindings, $failedPdo] = $reads[1];
        $this->assertStringContainsString('from "jobs" where "id" > ? and "queue" = ? and "payload" like ?', $jobsSql);
        $this->assertGreaterThanOrEqual($old['jobs'], $atPush, 'positive control');
        $this->assertSame([$atPush, 'default'], array_slice($jobsBindings, 0, 2), 'the floor is the newest id before the push; the queue is this one');
        $this->assertStringContainsString('from "failed_jobs" where "id" > ? and "payload" like ?', $failedSql);
        $this->assertSame($old['failed_jobs'], $failedBindings[0]);
        // #6096 (a), a regression pin (#6152: base already read on the write PDO).
        $this->assertSame(['write', 'write'], [$jobsPdo, $failedPdo], '#6096 (a): both reads on the write PDO');
        $this->assertFalse($this->withMessage(RetryEmailAttachments::NOT_QUEUED)[0]->context['queued_row'], 'the row below the floor is not read');
    }

    public function test_this_dispatchs_row_moved_to_another_queue_is_missed(): void
    {
        // #6096 (b): the jobs read is narrowed to this dispatch's queue. #6143: the row moved
        // here IS this dispatch's (it carries the push id, and a worker on that queue will run
        // it), so this pins what the narrowing trades away: a row moved off its queue is
        // missed, read back as false (the 'not added' note and the inline commit work), and the
        // row's own run then does the work again. Green at base (#6152): a regression pin.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueued::class, function (RetryEmailAttachments $job, $event) {
            DB::table('jobs')->where('id', $event->id)->update(['queue' => 'b7-other']);
        });

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(1, DB::table('jobs')->where('queue', 'b7-other')->count(), 'positive control: the row is on another queue');
        $this->assertFalse($this->withMessage(RetryEmailAttachments::NOT_QUEUED)[0]->context['queued_row']);
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::PUSH_THREW_ROW_FOUND));
    }

    public function test_the_plain_database_failer_is_read_back_too(): void
    {
        // #6096 (e): queue.failed.driver 'database' (no uuid column needed) is a database failer.
        // #6152: green at base (it already listed 'database'): a regression pin.
        config(['queue.failed.driver' => 'database']);
        app()->forgetInstance('queue.failer');
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueued::class, function (RetryEmailAttachments $job, $event) {
            $payload = DB::table('jobs')->where('id', $event->id)->value('payload');
            DB::table('jobs')->where('id', $event->id)->delete();
            DB::table('failed_jobs')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default',
                'payload' => $payload, 'exception' => 'B7-SYNTHETIC', 'failed_at' => now()]);
        });

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $found = $this->withMessage(RetryEmailAttachments::PUSH_THREW_ROW_FOUND);
        $this->assertCount(1, $found);
        $this->assertSame('failed_jobs', $found[0]->context['found_in']);
    }

    public function test_a_floor_that_cannot_be_read_makes_the_read_back_unknown(): void
    {
        // #6031: no floor means no bounded read: the jobs table is not scanned, queued_row is
        // null. #6149: failed_jobs, whose floor was read, is still scanned above it. #6150: the
        // class that threw is kept and named.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $scans = [];
        DB::beforeExecuting(function (string $sql) use (&$scans) {
            $sql = strtolower($sql);
            if (str_contains($sql, 'max("id")') && str_contains($sql, 'from "jobs"')) {
                throw new \RuntimeException('B7-SYNTHETIC-MAX-DOWN');
            }
            if (str_contains($sql, 'like')) {
                $scans[] = str_contains($sql, 'from "jobs"') ? 'jobs' : 'failed_jobs';
            }
        });
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueueing::class, fn () => null);

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(['failed_jobs'], $scans, 'no unbounded scan of jobs; failed_jobs read above its own floor');
        $notQueued = $this->withMessage(RetryEmailAttachments::NOT_QUEUED);
        $this->assertSame([null, 'jobs', \RuntimeException::class], [$notQueued[0]->context['queued_row'], $notQueued[0]->context['read_back_step'], $notQueued[0]->context['read_back_exception']],
            '#6150: read_back_exception names the floor read that threw');
    }

    public function test_a_floor_read_that_fails_is_recorded_when_the_push_returns(): void
    {
        // #6150: the push does not throw, so there is no NOT_QUEUED; the degraded read is still
        // recorded, ids, the table and the class only (C-56).
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        DB::beforeExecuting(function (string $sql) {
            $sql = strtolower($sql);
            if (str_contains($sql, 'max("id")') && str_contains($sql, 'from "failed_jobs"')) {
                throw new \RuntimeException('B8-SYNTHETIC-MAX-DOWN');
            }
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertCount(1, $this->retryRows(), 'positive control: the push returned and queued the row');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::NOT_QUEUED));
        $failed = $this->withMessage(RetryEmailAttachments::FLOOR_READ_FAILED);
        $this->assertCount(1, $failed);
        $this->assertSame(Level::Warning, $failed[0]->level);
        $this->assertSame(['email_id' => $email->id, 'ticket_id' => $ticket->id, 'step' => 'failed_jobs', 'exception' => \RuntimeException::class], $failed[0]->context);
    }

    /**
     * The read-back read to fail; true when it is a floor, read before the push (a scan is
     * failed only once the worker's run inside the push is over, so its own reads still run).
     *
     * @return array<string, array{0: \Closure(string): bool, 1: bool}>
     */
    public static function failingReadBackReads(): array
    {
        return [
            'the jobs floor' => [fn (string $sql) => str_contains($sql, 'max("id")') && str_contains($sql, 'from "jobs"'), true],
            'the failed_jobs floor' => [fn (string $sql) => str_contains($sql, 'max("id")') && str_contains($sql, 'from "failed_jobs"'), true],
            'the jobs scan' => [fn (string $sql) => str_contains($sql, 'from "jobs"') && str_contains($sql, 'like'), false],
            'the failed_jobs scan' => [fn (string $sql) => str_contains($sql, 'from "failed_jobs"') && str_contains($sql, 'like'), false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failingReadBackReads')]
    public function test_a_read_back_read_that_fails_still_finds_a_run_that_started(\Closure $failing, bool $floor): void
    {
        // #6149: a worker ran the dispatch inside the push and deleted its row, so its started
        // entry is the evidence. A floor or table read that fails no longer returns before the
        // started entry is read: found_in started, and the commit work runs once, from the run.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $hit = 0;
        $armed = $floor;
        DB::beforeExecuting(function (string $sql) use ($failing, &$hit, &$armed) {
            if ($armed && $failing(strtolower($sql))) {
                $hit++;

                throw new \RuntimeException('B8-SYNTHETIC-READ-BACK-DOWN');
            }
        });
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueued::class, function () use (&$armed) {
            $pushing = self::retryEmailAttachmentsStatics()['pushing'];
            $this->mock->append($this->read());
            $this->popRetry()->fire();
            $armed = true;
            (fn () => self::$pushing = $pushing)->bindTo(null, RetryEmailAttachments::class)();
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertGreaterThan(0, $hit, 'positive control: the read failed');
        $found = $this->withMessage(RetryEmailAttachments::PUSH_THREW_ROW_FOUND);
        $this->assertSame(['started'], array_map(fn ($r) => $r->context['found_in'], $found));
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::NOT_QUEUED), 'not reported as unknown');
        $this->assertSame([], $this->markerNotes($ticket->id), 'no second marker note');
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the commit work ran once, from the run');
    }

    // ── b8 (card 6ac7e71d): pending read, cleanup record, duplicate rows, terminate phase ──

    public function test_a_pending_read_that_throws_still_writes_the_started_entry_and_is_recorded(): void
    {
        // #6144/#6153: a run inside the push whose pending read throws writes its started entry
        // anyway (toward found), and the failed read is recorded as pending_read. r2 diff:7: the
        // run's de-dupe reads the pending entry first (laterRowOfThisPush()); that read throws
        // too, is recorded as check_read and treated as held, and the run goes on to markStarted().
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->cacheFailing(':pending:', ['get']);
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueued::class, function () {
            $pushing = self::retryEmailAttachmentsStatics()['pushing'];
            $this->mock->append($this->read());
            $this->popRetry()->fire();
            (fn () => self::$pushing = $pushing)->bindTo(null, RetryEmailAttachments::class)();
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(['started'], array_map(fn ($r) => $r->context['found_in'], $this->withMessage(RetryEmailAttachments::PUSH_THREW_ROW_FOUND)));
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::NOT_QUEUED));
        $failed = $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED);
        $this->assertSame([
            ['email_id' => $email->id, 'ticket_id' => $ticket->id, 'step' => 'check_read', 'exception' => \RuntimeException::class],
            ['email_id' => $email->id, 'ticket_id' => $ticket->id, 'step' => 'pending_read', 'exception' => \RuntimeException::class],
        ], array_map(fn ($r) => $r->context, $failed), '#6153: the failed pending read is recorded; r2 diff:7: so is the de-dupe read before it');
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the commit work ran once');
    }

    public function test_a_cleanup_that_throws_is_recorded_as_the_cleanup_step(): void
    {
        // #6144: the pending entry's forget throws once the push has returned. #6175: green at
        // base too (cleanup was already a recorded step): a regression pin.
        $this->graph([$this->failedRead()]);
        $this->cacheFailing(':pending:', ['forget']);

        $this->ticketWithQueuedRetry();

        $failed = $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED);
        $this->assertSame([['cleanup', \RuntimeException::class]], array_map(fn ($r) => [$r->context['step'], $r->context['exception']], $failed));
    }

    /**
     * #6100/#6179: runs $during once, while the first-run retry's push is in progress (its row
     * is written and the pending entry held); the push then returns normally.
     */
    private function duringPush(\Closure $during): void
    {
        $done = false;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobQueued::class, function ($e) use (&$done, $during) {
            if ($done || ! $e->job instanceof RetryEmailAttachments || $e->job->refusals !== 0) {
                return;
            }
            $done = true;
            $pushing = self::retryEmailAttachmentsStatics()['pushing'];
            $during($e->job);
            (fn () => self::$pushing = $pushing)->bindTo(null, RetryEmailAttachments::class)();
        });
    }

    /** A copy of the queued row $row (a re-run INSERT), with $changes applied; its id. */
    private function copyRow(object $row, array $changes = []): int
    {
        $copy = $changes + (array) $row;
        unset($copy['id']);

        return DB::table('jobs')->insertGetId($copy);
    }

    public function test_two_rows_of_one_push_run_the_retry_once(): void
    {
        // #6100: a lost reply to the INSERT, re-run by the connection at level 0, leaves two
        // rows of one dispatch with one pushId. A run of the earlier row while the push is still
        // in progress finds the later row and exits; the later row's run is the one that runs.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $ids = null;
        $this->duringPush(function () use (&$ids) {
            $row = $this->retryRows()[0];
            $ids = [(int) $row->id, $this->copyRow($row)];
            $first = $this->popRetry();
            $this->assertSame($ids[0], (int) $first->getJobId());
            $first->fire();
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $skipped = $this->withMessage(RetryEmailAttachments::DUPLICATE_ROW_SKIPPED);
        $this->assertCount(1, $skipped);
        $this->assertSame(['email_id' => $email->id, 'ticket_id' => $ticket->id, 'job_id' => $ids[0], 'later_job_ids' => [$ids[1]]], $skipped[0]->context);
        $this->assertSame([], $this->seen->getArrayCopy(), 'the earlier row did not run');
        $this->assertSame([$ids[1]], array_map(fn ($r) => (int) $r->id, $this->retryRows()), 'the later row is left to run');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROWS_DROPPED), 'one row, nothing to drop');

        $this->mock->append($this->read());
        $this->popRetry()->fire();
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the later row ran, once');
        $this->assertSame([], $this->markerNotes($ticket->id));
        $this->assertSame(2, $this->messageReads(), "the import's failed read and one retry read, not two");
    }

    public function test_a_lone_row_of_a_push_runs(): void
    {
        // #6100 control: one row, nothing later, so the run is not skipped. #6183: the push has
        // returned (no pending entry), so the run does not scan the queue's payloads at all.
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        $this->mock->append($this->read());
        $scans = [];
        DB::listen(function ($q) use (&$scans) {
            if (str_contains(strtolower($q->sql), 'like')) {
                $scans[] = $q->sql;
            }
        });
        $this->popRetry()->fire();

        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROW_SKIPPED));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy());
        $this->assertSame([], $scans, '#6183: no payload scan on a run after the push returned');
        // #6277: the positive control for this listener is
        // test_a_lone_row_with_its_gate_open_is_scanned (the same filter sees the check's scan).
    }

    public function test_a_lone_row_with_its_gate_open_is_scanned(): void
    {
        // #6277: positive control for test_a_lone_row_of_a_push_runs. The same row and the same
        // listener, with the gate held open (the kept entry put by hand): the listener captures
        // the check's payload scan, so the [] there is the gate's doing, not a deaf listener.
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        $this->mock->append($this->read());
        $job = $this->popRetry();
        \Illuminate\Support\Facades\Cache::put('retry-email-attachments:kept:'.$this->commandOf($job)->pushId, true, 60);
        $scans = [];
        DB::listen(function ($q) use (&$scans) {
            if (str_contains(strtolower($q->sql), 'like')) {
                $scans[] = $q->sql;
            }
        });
        $job->fire();

        $this->assertCount(1, $scans, 'the check scanned once');
        $this->assertStringContainsString('"reserved_at" is null', $scans[0]);
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy());
    }

    /** A queued retry's command, its row removed, for a push made from a test route. */
    private function retryCommand(): array
    {
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        $c = $this->commandOf($this->popRetry());
        DB::table('jobs')->delete();
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueueing::class, fn () => null);

        return [$ticket, fn () => (new RetryEmailAttachments($c->emailId, $c->ticketId, $c->noteId, $c->baseline))->queueAfterCommit()];
    }

    public function test_a_push_from_a_deferred_callback_still_runs_the_not_queued_work(): void
    {
        // #6133: the push runs in the terminate phase (a deferred callback of a routed request)
        // and throws. A defer() registered while the deferred callbacks are being run is never
        // run; the work is a terminating callback, which still runs.
        [$ticket, $push] = $this->retryCommand();
        \Illuminate\Support\Facades\Route::post('/b8-synthetic/deferred-push', function () use ($push) {
            \Illuminate\Support\defer($push);

            return response()->json(['status' => 'accepted'], 202);
        });

        $this->postJson('/b8-synthetic/deferred-push')->assertStatus(202);

        $this->assertCount(1, $this->withMessage(RetryEmailAttachments::NOT_QUEUED), 'positive control: the push threw in terminate');
        $this->assertCount(1, $this->withMessage(RetryEmailAttachments::MARKER));
        $this->assertCount(1, $this->markerNotes($ticket->id));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy());

        // The app's terminating list is never cleared: a later request in the same process
        // terminates it again, and the work must not run a second time.
        \Illuminate\Support\Facades\Route::post('/b8-synthetic/noop', fn () => response()->json([], 202));
        $this->postJson('/b8-synthetic/noop')->assertStatus(202);
        $this->assertCount(1, $this->markerNotes($ticket->id), 'run once');
        $this->assertCount(1, $this->seen);
    }

    public function test_a_request_that_fails_after_the_push_still_runs_the_not_queued_work(): void
    {
        // #6134: the push threw, then the request threw (a 500). The work still runs after the
        // response, whatever its status. #6175: green at base too (defer(..., always: true) ran
        // on a 500 as well): a regression pin for the move to a terminating callback, not a fix.
        [$ticket, $push] = $this->retryCommand();
        \Illuminate\Support\Facades\Route::post('/b8-synthetic/failing-request', function () use ($push) {
            $push();

            throw new \RuntimeException('B8-SYNTHETIC-LATER-FAILURE');
        });
        $atResponse = null;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, function () use (&$atResponse) {
            $atResponse = count($this->seen);
        });

        $this->postJson('/b8-synthetic/failing-request')->assertStatus(500);

        $this->assertSame(0, $atResponse, 'positive control: nothing ran before the response');
        $this->assertCount(1, $this->withMessage(RetryEmailAttachments::NOT_QUEUED));
        $this->assertCount(1, $this->markerNotes($ticket->id), 'the marker ran after a 500');
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy(), 'and the notification');
    }

    // ── b9 (card 6ac824d6): the #6100 duplicate check, the pass after the push, terminate isolation ──

    /**
     * #6171: rows a weakened filter would count, above this push's row, that are not this push:
     * a retry of another dispatch, and a row whose payload names this pushId but is another
     * job's (#6025). With each present the run is not skipped.
     *
     * @return array<string, array{0: string}>
     */
    public static function laterRowsOfOtherPushes(): array
    {
        return ['another dispatch of the retry' => ['other'], 'another job carrying the uuid' => ['foreign']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('laterRowsOfOtherPushes')]
    public function test_a_later_row_that_is_not_this_push_does_not_skip_the_run(string $kind): void
    {
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->duringPush(function (RetryEmailAttachments $job) use ($kind) {
            $row = $this->retryRows()[0];
            if ($kind === 'other') {
                $other = clone $job;
                $other->pushId = (string) \Illuminate\Support\Str::uuid();
                $payload = json_decode($row->payload, true);
                $payload['data']['command'] = serialize($other);
                $this->copyRow($row, ['payload' => json_encode($payload)]);
            } else {
                $this->copyRow($row, ['payload' => json_encode(['displayName' => 'Other', 'data' => ['note' => $job->pushId]])]);
            }
            $this->mock->append($this->read());
            $this->popRetry()->fire();
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROW_SKIPPED));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the run ran');
    }

    /**
     * #6182: later rows of this push that will not run handle() when popped: reserved by a
     * worker (which may have died), or at the attempt limit (failed as MaxAttemptsExceeded).
     *
     * @return array<string, array{0: array<string, int|string>, 1: bool}>
     */
    public static function laterRowsThatWillNotRun(): array
    {
        // The second value: whether the pass after the push then deletes it (#6179: a run has
        // started, so an unreserved row is deleted; a reserved one is never deleted under its worker).
        // r2 (diff:7): 'reserved' is a live worker's hold, as a pop leaves it (reserved_at now,
        // attempts 1); reserved_at 1 with attempts 0 was an expired reservation, which runs.
        // 'now' is resolved in the test: a provider runs when the suite is built, and a time()
        // taken then is past retry_after by the time a long run reaches this test. #6281: the
        // test freezes the clock, so the hold cannot expire however long it runs.
        // #6280: with $tries 1 the attempts filter alone excludes 'reserved' (attempts 1), so
        // 'reserved, attempts unspent' (attempts 0) is the row only the reserved_at filter
        // excludes: the behavioural pin for #6182's reserved branch.
        return ['reserved' => [['reserved_at' => 'now', 'attempts' => 1], false],
            'reserved, attempts unspent' => [['reserved_at' => 'now', 'attempts' => 0], false],
            'at its attempt limit' => [['attempts' => 1], true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('laterRowsThatWillNotRun')]
    public function test_a_later_row_that_will_not_run_does_not_skip_the_run(array $changes, bool $dropped): void
    {
        $this->freezeTime();
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $later = null;
        $this->duringPush(function () use ($changes, &$later) {
            $changes = array_map(fn ($v) => $v === 'now' ? now()->getTimestamp() : $v, $changes);
            $later = $this->copyRow($this->retryRows()[0], $changes);
            $this->mock->append($this->read());
            $this->popRetry()->fire();
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROW_SKIPPED), 'the later row is not counted');
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the earlier row ran the retry');
        $this->assertSame($dropped ? [] : [$later], array_map(fn ($r) => (int) $r->id, $this->retryRows()));
        if (! $dropped) {
            $this->assertNull($this->popRetry(), 'diff:7: a live hold, so no worker can pop it now');
        }
        $this->assertSame($dropped ? [[true, [$later]]] : [], array_map(fn ($r) => [$r->context['run_started'], $r->context['dropped_job_ids']],
            $this->withMessage(RetryEmailAttachments::DUPLICATE_ROWS_DROPPED)));
    }

    public function test_a_duplicate_check_that_throws_lets_the_run_go_on_and_is_recorded(): void
    {
        // #6172: the run's later-row read throws: DUPLICATE_CHECK_FAILED (ids, step and class
        // only), and the run processes rather than skips.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $hit = 0;
        $this->duringPush(function () use (&$hit) {
            $this->copyRow($this->retryRows()[0]);
            DB::beforeExecuting(function (string $sql) use (&$hit) {
                $sql = strtolower($sql);
                if ($hit === 0 && str_contains($sql, 'from "jobs"') && str_contains($sql, 'like') && str_contains($sql, '"reserved_at" is null')) {
                    $hit++;

                    throw new \RuntimeException('B9-SYNTHETIC-CHECK-DOWN');
                }
            });
            $this->mock->append($this->read());
            $this->popRetry()->fire();
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(1, $hit, 'positive control: the check threw');
        $failed = $this->withMessage(RetryEmailAttachments::DUPLICATE_CHECK_FAILED);
        $this->assertCount(1, $failed);
        $this->assertSame(Level::Warning, $failed[0]->level);
        $this->assertSame(['email_id' => $email->id, 'ticket_id' => $ticket->id, 'step' => 'run', 'exception' => \RuntimeException::class], $failed[0]->context);
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROW_SKIPPED));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the run went ahead');
        $this->assertSame([], $this->retryRows(), '#6179: the pass after the push removed the later row of a push that ran');
    }

    public function test_a_run_that_checked_before_the_re_run_insert_landed_leaves_no_second_run(): void
    {
        // #6179: the race #6100 missed: a worker pops and runs the first row while the push is in
        // progress and before the re-run INSERT has written the second, so its check finds
        // nothing. The pass after the push finds the run's started entry and deletes the second.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $later = null;
        $this->duringPush(function () use (&$later) {
            $row = $this->retryRows()[0];
            $this->mock->append($this->read());
            $this->popRetry()->fire();
            $later = $this->copyRow($row);
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'positive control: the first row ran');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROW_SKIPPED), 'its check found nothing');
        $this->assertSame([], $this->retryRows(), 'the second row is gone');
        $dropped = $this->withMessage(RetryEmailAttachments::DUPLICATE_ROWS_DROPPED);
        $this->assertCount(1, $dropped);
        $this->assertSame(Level::Warning, $dropped[0]->level);
        $this->assertSame(['email_id' => $email->id, 'ticket_id' => $ticket->id, 'run_started' => true, 'dropped_job_ids' => [$later]], $dropped[0]->context);
        $this->assertSame([], $this->readBackCacheKeys());
    }

    public function test_two_unrun_rows_of_one_push_leave_only_the_later_one(): void
    {
        // #6179: no run started during the push: of two unreserved rows the earlier is deleted,
        // so a worker that pops after the push sees one row; it runs once.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $ids = null;
        $this->duringPush(function () use (&$ids) {
            $row = $this->retryRows()[0];
            $ids = [(int) $row->id, $this->copyRow($row)];
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame([$ids[1]], array_map(fn ($r) => (int) $r->id, $this->retryRows()));
        $this->assertSame([[false, [$ids[0]]]], array_map(fn ($r) => [$r->context['run_started'], $r->context['dropped_job_ids']],
            $this->withMessage(RetryEmailAttachments::DUPLICATE_ROWS_DROPPED)));
        $this->mock->append($this->read());
        $this->popRetry()->fire();
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy());
    }

    /**
     * #6183: a worker reserves the earlier of two rows of one push as the push returns, before
     * its run's check: before the pass reads the rows, or between that read and the pass's
     * DELETE of the row (which then matches nothing). The pass leaves both rows; the reserved
     * row's run must still find the later row once the pending entry is gone.
     *
     * @return array<string, array{0: string}>
     */
    public static function earlierRowReservedAtTheEndOfThePush(): array
    {
        return ['before the pass reads' => ['before_read'], 'between the read and the delete' => ['before_delete']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('earlierRowReservedAtTheEndOfThePush')]
    public function test_an_earlier_row_reserved_at_the_end_of_the_push_still_skips_for_the_later_row(string $when): void
    {
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $ids = null;
        $reserved = null;
        $pushId = null;
        $this->duringPush(function (RetryEmailAttachments $job) use ($when, &$ids, &$reserved, &$pushId) {
            $pushId = $job->pushId;
            $row = $this->retryRows()[0];
            $ids = [(int) $row->id, $this->copyRow($row)];
            if ($when === 'before_read') {
                $reserved = $this->popRetry();

                return;
            }
            DB::beforeExecuting(function (string $sql) use (&$reserved) {
                if ($reserved === null && str_starts_with(strtolower($sql), 'delete from "jobs"')) {
                    $reserved = false;
                    $reserved = $this->popRetry();
                }
            });
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertInstanceOf(\Illuminate\Queue\Jobs\DatabaseJob::class, $reserved, 'positive control: a worker reserved a row');
        $this->assertSame($ids[0], (int) $reserved->getJobId(), 'positive control: the earlier row');
        $this->assertSame($ids, array_map(fn ($r) => (int) $r->id, $this->retryRows()), 'the pass left both rows');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROWS_DROPPED));
        $this->assertNotContains('retry-email-attachments:pending:'.$pushId, $this->readBackCacheKeys(), 'positive control: the push has returned');

        $this->mock->append($this->read());
        $reserved->fire();

        $this->assertSame([['email_id' => $email->id, 'ticket_id' => $ticket->id, 'job_id' => $ids[0], 'later_job_ids' => [$ids[1]]]],
            array_map(fn ($r) => $r->context, $this->withMessage(RetryEmailAttachments::DUPLICATE_ROW_SKIPPED)), 'the reserved row found the later one');
        $this->assertSame([], $this->seen->getArrayCopy(), 'the reserved row did not run');
        $this->popRetry()->fire();
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the later row ran, once');
        $this->assertSame([], $this->retryRows());
        $this->assertSame(2, $this->messageReads(), "the import's failed read and one retry read, not two");
        $this->assertNotContains('retry-email-attachments:kept:'.$pushId, $this->readBackCacheKeys(), '#6282: the run that ran removed the kept entry');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED));
    }

    public function test_a_reserved_row_among_three_leaves_every_row_and_the_retry_runs_once(): void
    {
        // #6274: three rows of one push, no run started, and a worker reserves the first before
        // the pass reads. Two rows are unreserved, but one is reserved, so the pass deletes
        // nothing (it deletes all but the latest only when none is reserved) and writes the
        // kept entry. Each earlier row's run then skips for a later one: the retry runs once.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $ids = null;
        $reserved = null;
        $this->duringPush(function () use (&$ids, &$reserved) {
            $row = $this->retryRows()[0];
            $ids = [(int) $row->id, $this->copyRow($row), $this->copyRow($row)];
            $reserved = $this->popRetry();
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame($ids[0], (int) $reserved->getJobId(), 'positive control: the first row is reserved');
        $this->assertSame($ids, array_map(fn ($r) => (int) $r->id, $this->retryRows()), 'no row deleted');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROWS_DROPPED));
        $this->assertCount(1, $this->keptKeys(), 'the kept entry');

        $this->mock->append($this->read());
        $reserved->fire();
        $this->popRetry()->fire();
        $this->assertSame([], $this->seen->getArrayCopy(), 'the two earlier rows skipped');
        $this->popRetry()->fire();
        $this->assertSame([[$ids[0], [$ids[1], $ids[2]]], [$ids[1], [$ids[2]]]],
            array_map(fn ($r) => [$r->context['job_id'], $r->context['later_job_ids']], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROW_SKIPPED)));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the latest row ran, once');
        $this->assertSame([], $this->retryRows());
        $this->assertSame([], $this->keptKeys(), '#6282: and removed the kept entry');
    }

    public function test_one_row_of_a_push_is_left_alone_by_the_pass_after_the_push(): void
    {
        // #6179 control: one unreserved row and no run started: nothing is deleted or recorded.
        $this->graph([$this->failedRead()]);
        $this->ticketWithQueuedRetry();

        $this->assertCount(1, $this->retryRows());
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROWS_DROPPED));
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_CHECK_FAILED));
        $this->mock->append($this->read());
        $this->popRetry()->fire();
    }

    public function test_a_pass_after_the_push_that_throws_leaves_every_row_and_is_recorded(): void
    {
        // #6179: the pass's read throws: DUPLICATE_CHECK_FAILED step push, and no row is deleted.
        // r2 (diff:3): the pass did not complete, so the kept entry is written and the earlier
        // row's run, after the push returned, still finds the later row: the retry runs once.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $ids = null;
        $pushId = null;
        $armed = true;
        $this->duringPush(function (RetryEmailAttachments $job) use (&$ids, &$pushId, &$armed) {
            $pushId = $job->pushId;
            $row = $this->retryRows()[0];
            $ids = [(int) $row->id, $this->copyRow($row)];
            DB::beforeExecuting(function (string $sql) use (&$armed) {
                $sql = strtolower($sql);
                if ($armed && str_contains($sql, 'from "jobs"') && str_contains($sql, 'like') && str_contains($sql, 'order by')) {
                    throw new \RuntimeException('B9-SYNTHETIC-PASS-DOWN');
                }
            });
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);
        $armed = false;

        $this->assertSame($ids, array_map(fn ($r) => (int) $r->id, $this->retryRows()));
        $failed = $this->withMessage(RetryEmailAttachments::DUPLICATE_CHECK_FAILED);
        $this->assertSame([['email_id' => $email->id, 'ticket_id' => $ticket->id, 'step' => 'push', 'exception' => \RuntimeException::class]],
            array_map(fn ($r) => $r->context, $failed));
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROWS_DROPPED));
        $this->assertNotContains('retry-email-attachments:pending:'.$pushId, $this->readBackCacheKeys(), 'positive control: the push has returned');
        $this->assertContains('retry-email-attachments:kept:'.$pushId, $this->readBackCacheKeys(), 'the kept entry');

        $this->assertTheEarlierRowSkipsAndTheLaterRunsOnce($email, $ticket, $ids);
    }

    /**
     * Pops the rows $ids of one push left after it returned: the earlier row's run finds the
     * later row and exits (DUPLICATE_ROW_SKIPPED), and the later row runs the retry once.
     *
     * @param  array{0: int, 1: int}  $ids
     */
    private function assertTheEarlierRowSkipsAndTheLaterRunsOnce(Email $email, Ticket $ticket, array $ids): void
    {
        $this->mock->append($this->read());
        $first = $this->popRetry();
        $this->assertSame($ids[0], (int) $first->getJobId());
        $first->fire();
        $this->assertSame([['email_id' => $email->id, 'ticket_id' => $ticket->id, 'job_id' => $ids[0], 'later_job_ids' => [$ids[1]]]],
            array_map(fn ($r) => $r->context, $this->withMessage(RetryEmailAttachments::DUPLICATE_ROW_SKIPPED)), 'the earlier row found the later one');
        $this->assertSame([], $this->seen->getArrayCopy(), 'the earlier row did not run');
        $this->assertSame(1, count($this->keptKeys()), '#6282: a run that skipped leaves the kept entry');
        $this->popRetry()->fire();
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the later row ran, once');
        $this->assertSame([], $this->retryRows());
        $this->assertSame(2, $this->messageReads(), "the import's failed read and one retry read, not two");
        $this->assertSame([], $this->keptKeys(), '#6282: the run that ran removed the kept entry');
    }

    /** #6282: the kept entries still present. */
    private function keptKeys(): array
    {
        return array_values(array_filter($this->readBackCacheKeys(), fn ($k) => str_contains($k, ':kept:')));
    }

    public function test_a_jobs_floor_that_cannot_be_read_keeps_the_runs_check_on_after_the_push(): void
    {
        // r2 (contract:2): the jobs floor read throws (FLOOR_READ_FAILED step jobs), so the pass
        // after the push cannot read the rows. It writes the kept entry instead: the earlier of
        // two rows of the push, run after the push returned, still finds the later row.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $hit = 0;
        DB::beforeExecuting(function (string $sql) use (&$hit) {
            $sql = strtolower($sql);
            if (str_contains($sql, 'max("id")') && str_contains($sql, 'from "jobs"')) {
                $hit++;

                throw new \RuntimeException('B9-SYNTHETIC-FLOOR-DOWN');
            }
        });
        $ids = null;
        $pushId = null;
        $this->duringPush(function (RetryEmailAttachments $job) use (&$ids, &$pushId) {
            $pushId = $job->pushId;
            $row = $this->retryRows()[0];
            $ids = [(int) $row->id, $this->copyRow($row)];
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(1, $hit, 'positive control: the jobs floor read threw');
        $this->assertSame([['email_id' => $email->id, 'ticket_id' => $ticket->id, 'step' => 'jobs', 'exception' => \RuntimeException::class]],
            array_map(fn ($r) => $r->context, $this->withMessage(RetryEmailAttachments::FLOOR_READ_FAILED)), 'the degraded read is recorded (C-56)');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::NOT_QUEUED), 'positive control: the push returned');
        $this->assertSame($ids, array_map(fn ($r) => (int) $r->id, $this->retryRows()), 'no pass: both rows are left');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROWS_DROPPED));
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED));
        $this->assertNotContains('retry-email-attachments:pending:'.$pushId, $this->readBackCacheKeys(), 'positive control: the push has returned');
        $this->assertContains('retry-email-attachments:kept:'.$pushId, $this->readBackCacheKeys(), 'the kept entry');

        $this->assertTheEarlierRowSkipsAndTheLaterRunsOnce($email, $ticket, $ids);
    }

    public function test_a_kept_entry_that_cannot_be_written_is_recorded_as_kept_put(): void
    {
        // r2 (c1:v1:1): no jobs floor, so the pass writes the kept entry instead, and that write
        // throws: CACHE_STEP_FAILED step kept_put, and (as queueAfterCommit() documents) no run
        // after the push checks, so both rows run.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->cacheFailing(':kept:');
        DB::beforeExecuting(function (string $sql) {
            $sql = strtolower($sql);
            if (str_contains($sql, 'max("id")') && str_contains($sql, 'from "jobs"')) {
                throw new \RuntimeException('B9-SYNTHETIC-FLOOR-DOWN');
            }
        });
        $this->duringPush(fn () => $this->copyRow($this->retryRows()[0]));

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame([['email_id' => $email->id, 'ticket_id' => $ticket->id, 'step' => 'kept_put', 'exception' => \RuntimeException::class]],
            array_map(fn ($r) => $r->context, $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED)));
        $this->assertSame([], array_values(array_filter($this->readBackCacheKeys(), fn ($k) => str_contains($k, ':kept:'))));
        $this->assertCount(2, $this->retryRows());
        $this->mock->append($this->read());
        $this->popRetry()->fire();
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROW_SKIPPED), 'the documented arm: the earlier row does not check');
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'and runs the retry, with the later row still queued');
        $this->assertCount(1, $this->retryRows());
    }

    public function test_a_pass_delete_that_throws_still_records_the_rows_it_already_dropped(): void
    {
        // r2 (diff:1/context:3): three rows of one push and no run started, so the pass deletes
        // the two earlier ones. The first DELETE succeeds and the second throws: the drop made is
        // recorded (DUPLICATE_ROWS_DROPPED with that row), next to DUPLICATE_CHECK_FAILED step
        // push, and the two rows left keep their check (the kept entry): the retry runs once.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $ids = null;
        $deletes = 0;
        $this->duringPush(function () use (&$ids, &$deletes) {
            $row = $this->retryRows()[0];
            $ids = [(int) $row->id, $this->copyRow($row), $this->copyRow($row)];
            DB::beforeExecuting(function (string $sql) use (&$deletes) {
                if (str_starts_with(strtolower($sql), 'delete from "jobs"') && ++$deletes === 2) {
                    throw new \RuntimeException('B9-SYNTHETIC-DELETE-DOWN');
                }
            });
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(2, $deletes, 'positive control: the second DELETE ran and threw');
        $this->assertSame([$ids[1], $ids[2]], array_map(fn ($r) => (int) $r->id, $this->retryRows()), 'the first DELETE took effect');
        $this->assertSame([['email_id' => $email->id, 'ticket_id' => $ticket->id, 'step' => 'push', 'exception' => \RuntimeException::class]],
            array_map(fn ($r) => $r->context, $this->withMessage(RetryEmailAttachments::DUPLICATE_CHECK_FAILED)));
        $dropped = $this->withMessage(RetryEmailAttachments::DUPLICATE_ROWS_DROPPED);
        $this->assertSame([['email_id' => $email->id, 'ticket_id' => $ticket->id, 'run_started' => false, 'dropped_job_ids' => [$ids[0]]]],
            array_map(fn ($r) => $r->context, $dropped), 'the drop made before the throw is recorded');
        $this->assertSame(Level::Warning, $dropped[0]->level);

        $this->assertTheEarlierRowSkipsAndTheLaterRunsOnce($email, $ticket, [$ids[1], $ids[2]]);
    }

    public function test_a_push_that_throws_after_a_re_run_insert_keeps_the_runs_check_on(): void
    {
        // r2 diff:1: a lost reply to the INSERT, re-run by the connection, leaves two rows of the
        // push, and then a JobQueued listener throws. The read-back finds the row (found_in jobs)
        // and no pass runs, so the kept entry is written: the earlier row's run, after the push
        // returned, still finds the later row, and the retry runs once.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $ids = null;
        $pushId = null;
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueued::class, function (RetryEmailAttachments $job) use (&$ids, &$pushId) {
            $pushId = $job->pushId;
            $row = $this->retryRows()[0];
            $ids = [(int) $row->id, $this->copyRow($row)];
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(['jobs'], array_map(fn ($r) => $r->context['found_in'], $this->withMessage(RetryEmailAttachments::PUSH_THREW_ROW_FOUND)),
            'positive control: the push threw and its row was found');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::NOT_QUEUED));
        $this->assertSame($ids, array_map(fn ($r) => (int) $r->id, $this->retryRows()), 'no pass: both rows are left');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROWS_DROPPED));
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED));
        $this->assertNotContains('retry-email-attachments:pending:'.$pushId, $this->readBackCacheKeys(), 'positive control: the push has returned');
        $this->assertContains('retry-email-attachments:kept:'.$pushId, $this->readBackCacheKeys(), 'the kept entry');

        $this->assertTheEarlierRowSkipsAndTheLaterRunsOnce($email, $ticket, $ids);
    }

    public function test_the_kept_entry_outlasts_a_long_wait_in_the_queue(): void
    {
        // r2 diff:2: the pass's DELETE throws (DUPLICATE_CHECK_FAILED step push), so both rows of
        // the push are left and the kept entry is written. The rows then wait 30 minutes in the
        // queue (a backlog, stopped workers), past the 10 minutes the entry used to live: the
        // entry is still held, the earlier row's run finds the later row, and the retry runs once.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $ids = null;
        $pushId = null;
        $armed = true;
        $this->duringPush(function (RetryEmailAttachments $job) use (&$ids, &$pushId, &$armed) {
            $pushId = $job->pushId;
            $row = $this->retryRows()[0];
            $ids = [(int) $row->id, $this->copyRow($row)];
            DB::beforeExecuting(function (string $sql) use (&$armed) {
                if ($armed && str_starts_with(strtolower($sql), 'delete from "jobs"')) {
                    throw new \RuntimeException('B9-SYNTHETIC-DELETE-DOWN');
                }
            });
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);
        $armed = false;

        $this->assertSame($ids, array_map(fn ($r) => (int) $r->id, $this->retryRows()), 'positive control: the DELETE threw, both rows are left');
        $this->assertSame([['email_id' => $email->id, 'ticket_id' => $ticket->id, 'step' => 'push', 'exception' => \RuntimeException::class]],
            array_map(fn ($r) => $r->context, $this->withMessage(RetryEmailAttachments::DUPLICATE_CHECK_FAILED)));
        $this->assertNotContains('retry-email-attachments:pending:'.$pushId, $this->readBackCacheKeys(), 'positive control: the push has returned');
        $this->assertContains('retry-email-attachments:kept:'.$pushId, $this->readBackCacheKeys(), 'the kept entry');

        $this->travel(30)->minutes();
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has('retry-email-attachments:kept:'.$pushId), 'the kept entry is still held after the wait');

        $this->assertTheEarlierRowSkipsAndTheLaterRunsOnce($email, $ticket, $ids);
    }

    /** #6282: a database-queue retry whose jobs floor read throws, so the pass writes the kept entry; its pushId. */
    private function ticketWithKeptEntry(?Ticket &$ticket = null): string
    {
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $armed = true;
        DB::beforeExecuting(function (string $sql) use (&$armed) {
            $sql = strtolower($sql);
            if ($armed && str_contains($sql, 'max("id")') && str_contains($sql, 'from "jobs"')) {
                throw new \RuntimeException('B10-SYNTHETIC-FLOOR-DOWN');
            }
        });
        $pushId = null;
        $this->duringPush(function (RetryEmailAttachments $job) use (&$pushId) {
            $pushId = $job->pushId;
        });
        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);
        $armed = false;
        $this->assertCount(1, $this->withMessage(RetryEmailAttachments::FLOOR_READ_FAILED), 'positive control: the jobs floor read threw');
        $this->assertSame(['retry-email-attachments:kept:'.$pushId], $this->keptKeys(), 'positive control: the kept entry');

        return $pushId;
    }

    public function test_the_kept_entry_expires_after_a_bounded_ttl(): void
    {
        // #6282 (r2 of #6304): the kept entry is written with KEPT_TTL_SECONDS (seven days), not
        // forever and not one day: its expiry is pinned, and it is gone once that has passed.
        $this->freezeTime();
        $pushId = $this->ticketWithKeptEntry();
        $key = 'retry-email-attachments:kept:'.$pushId;

        $store = \Illuminate\Support\Facades\Cache::getStore();
        $expiresAt = (fn () => $this->storage)->call($store)[$key]['expiresAt'];
        $this->assertEqualsWithDelta(now()->getTimestamp() + 604800, $expiresAt, 1, 'the expiry is seven days out, not 0 (forever)');

        $this->travel(86400 + 60)->seconds();
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has($key), 'still held past one day');
        $this->travel(604800 - 86400 - 120)->seconds();
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has($key), 'positive control: still held just inside the seven days');
        $this->travel(61)->seconds();
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has($key), 'expired after the seven days');
        $this->assertSame(604800, RetryEmailAttachments::KEPT_TTL_SECONDS, 'the documented constant is the one written');
    }

    public function test_a_lone_run_with_a_kept_entry_removes_it_when_it_finishes(): void
    {
        // #6282: one row of the push and the kept entry held: the run checks (no later row),
        // runs the retry, and removes the kept entry when it finishes.
        $ticket = null;
        $pushId = $this->ticketWithKeptEntry($ticket);
        $this->mock->append($this->read());
        $scans = 0;
        DB::listen(function ($q) use (&$scans) {
            if (str_contains(strtolower($q->sql), 'like')) {
                $scans++;
            }
        });
        $this->popRetry()->fire();

        $this->assertSame(1, $scans, 'positive control: the kept entry kept the check on');
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the run ran');
        $this->assertSame([], $this->keptKeys(), 'the kept entry is removed');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED));
    }

    public function test_a_kept_entry_removal_that_throws_is_recorded_as_kept_forget(): void
    {
        // #6282: the finished run's removal of the kept entry throws: CACHE_STEP_FAILED step
        // kept_forget (ids and class only), and the run's outcome stands.
        $ticket = null;
        $pushId = $this->ticketWithKeptEntry($ticket);
        $this->cacheFailing(':kept:', ['forget']);
        \Illuminate\Support\Facades\Cache::put('retry-email-attachments:kept:'.$pushId, true, 60);
        $this->mock->append($this->read());
        $this->popRetry()->fire();

        $failed = $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED);
        $this->assertSame([['email_id' => (int) Email::where('graph_id', 'MSG-1')->value('id'), 'ticket_id' => $ticket->id, 'step' => 'kept_forget', 'exception' => \RuntimeException::class]],
            array_map(fn ($r) => $r->context, $failed), 'the failed removal is recorded (C-56)');
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the run ran');
        $this->assertSame(['retry-email-attachments:kept:'.$pushId], $this->keptKeys(), 'left to expire');
    }

    /**
     * #6282 r2 (diff:4): three rows A < B < C of one push are left with the kept entry held (the
     * jobs floor read threw, so no pass ran); $ids gets their ids.
     */
    private function threeRowsWithKeptEntry(?array &$ids, ?Ticket &$ticket = null): string
    {
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $armed = true;
        DB::beforeExecuting(function (string $sql) use (&$armed) {
            $sql = strtolower($sql);
            if ($armed && str_contains($sql, 'max("id")') && str_contains($sql, 'from "jobs"')) {
                throw new \RuntimeException('B10-SYNTHETIC-FLOOR-DOWN');
            }
        });
        $pushId = null;
        $this->duringPush(function (RetryEmailAttachments $job) use (&$pushId, &$ids) {
            $pushId = $job->pushId;
            $row = $this->retryRows()[0];
            $ids = [(int) $row->id, $this->copyRow($row), $this->copyRow($row)];
        });
        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);
        $armed = false;
        $this->assertSame($ids, array_map(fn ($r) => (int) $r->id, $this->retryRows()), 'positive control: no pass, three rows left');
        $this->assertSame(['retry-email-attachments:kept:'.$pushId], $this->keptKeys(), 'positive control: the kept entry');

        return $pushId;
    }

    public function test_a_run_whose_check_threw_leaves_the_kept_entry_for_the_middle_row(): void
    {
        // #6282 r2 (diff:4, context:1/4, contract:3): A's check reads the rows and that read
        // throws (DUPLICATE_CHECK_FAILED step run), so A runs. A did not establish that no later
        // row is left, so it does not remove the kept entry: B's run still checks, finds C and
        // skips. At 599c2d36 A removed it and B ran unchecked (two runs became three).
        $ticket = null;
        $ids = null;
        $pushId = $this->threeRowsWithKeptEntry($ids, $ticket);
        $hit = 0;
        DB::beforeExecuting(function (string $sql) use (&$hit) {
            $sql = strtolower($sql);
            if ($hit === 0 && str_contains($sql, 'from "jobs"') && str_contains($sql, 'like') && str_contains($sql, '"reserved_at" is null')) {
                $hit++;

                throw new \RuntimeException('B10-SYNTHETIC-CHECK-DOWN');
            }
        });
        $this->mock->append($this->read());
        $this->popRetry()->fire();

        $this->assertSame(1, $hit, 'positive control: the check read threw');
        $this->assertSame([['run', \RuntimeException::class]],
            array_map(fn ($r) => [$r->context['step'], $r->context['exception']], $this->withMessage(RetryEmailAttachments::DUPLICATE_CHECK_FAILED)));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'A ran');
        $this->assertSame(['retry-email-attachments:kept:'.$pushId], $this->keptKeys(), 'the kept entry is still held after A');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED), 'no removal was attempted');

        $this->popRetry()->fire();
        $this->assertSame([[$ids[1], [$ids[2]]]],
            array_map(fn ($r) => [$r->context['job_id'], $r->context['later_job_ids']], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROW_SKIPPED)),
            "B's check found C");
        $this->assertCount(1, $this->seen, 'B skipped');
        $this->assertSame([$ids[2]], array_map(fn ($r) => (int) $r->id, $this->retryRows()), 'C is left to run');
        $this->assertSame(2, $this->messageReads(), "the import's failed read and A's retry read");
    }

    public function test_a_run_that_checked_clear_and_then_threw_still_removes_the_kept_entry(): void
    {
        // #6282 r2 (context:5): the run's check read the rows and found no later row, then the
        // retry work threw out of handle(). The kept entry is removed on that thrown exit, from
        // the finally. Positive control at 599c2d36 (that removal is unchanged); it kills the
        // mutants that move the removal out of the finally or gate it on the run not throwing.
        $pushId = $this->ticketWithKeptEntry();
        $this->app->instance(EmailService::class, \Mockery::mock(EmailService::class, function ($m) {
            $m->shouldReceive('retryMessageRead')->once()->andThrow(new \RuntimeException('B10-SYNTHETIC-HANDLE'));
        }));
        $scans = 0;
        DB::listen(function ($q) use (&$scans) {
            if (str_contains(strtolower($q->sql), 'like')) {
                $scans++;
            }
        });
        $job = $this->popRetry();

        $thrown = null;
        try {
            $job->fire();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertSame('B10-SYNTHETIC-HANDLE', $thrown?->getMessage(), 'positive control: handle() threw');
        $this->assertSame(1, $scans, 'positive control: the check read the rows');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_CHECK_FAILED));
        $this->assertNotContains('retry-email-attachments:kept:'.$pushId, $this->keptKeys(), 'removed on the thrown exit');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED));
    }

    public function test_a_run_that_did_not_check_attempts_no_kept_entry_removal(): void
    {
        // #6282 r2 (diff:6): after the push returned with no kept entry, a run does not check,
        // so it attempts no removal: a failing store records nothing for an entry that never
        // existed. At 599c2d36 every run removed, and recorded CACHE_STEP_FAILED kept_forget.
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        $this->cacheFailing(':kept:', ['forget']);
        $this->mock->append($this->read());
        $this->popRetry()->fire();

        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the run ran');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED), 'no kept_forget record');
    }

    public function test_a_kept_entry_removal_the_store_reports_false_for_is_recorded(): void
    {
        // #6282 r2 (contract:2): the store's removal returns false and the key is still held (a
        // file store's failed unlink): CACHE_STEP_FAILED kept_forget with no exception class. A
        // false for an absent key is not a failure (every run that checks clear with no kept
        // entry, e.g. test_two_rows_of_one_push_run_the_retry_once, records nothing).
        $ticket = null;
        $pushId = $this->ticketWithKeptEntry($ticket);
        $store = new class extends ArrayStore
        {
            public function forget($key)
            {
                return str_contains($key, ':kept:') ? false : parent::forget($key);
            }
        };
        \Illuminate\Support\Facades\Cache::swap(new Repository($store));
        \Illuminate\Support\Facades\Cache::put('retry-email-attachments:kept:'.$pushId, true, 60);
        $this->mock->append($this->read());
        $this->popRetry()->fire();

        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy(), 'the run ran');
        $this->assertSame([['email_id' => (int) Email::where('graph_id', 'MSG-1')->value('id'), 'ticket_id' => $ticket->id, 'step' => 'kept_forget', 'exception' => null]],
            array_map(fn ($r) => $r->context, $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED)), 'the failed removal is recorded (C-56)');
        $this->assertSame(['retry-email-attachments:kept:'.$pushId], $this->keptKeys(), 'positive control: the key is still held');
    }

    public function test_a_reserved_latest_row_runs_next_to_the_highest_unreserved_row(): void
    {
        // #6304 r1 diff:5, the #6182 trade-off (documented in queueAfterCommit(), 'Both still run
        // when'): three rows A < B < C, no run started, and a worker reserves C before the pass
        // reads. The pass deletes nothing (a row is reserved) and writes the kept entry. A skips
        // for B; B's check does not count the reserved C, so B runs; C's worker runs too: two
        // runs. Positive control: pins the behaviour at 599c2d36, not a fix.
        $this->freezeTime();
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $ids = null;
        $reserved = null;
        $this->duringPush(function () use (&$ids, &$reserved) {
            $row = $this->retryRows()[0];
            $ids = [(int) $row->id, $this->copyRow($row), $this->copyRow($row)];
            DB::table('jobs')->whereIn('id', [$ids[0], $ids[1]])->update(['reserved_at' => now()->getTimestamp()]);
            $reserved = $this->popRetry();
            DB::table('jobs')->whereIn('id', [$ids[0], $ids[1]])->update(['reserved_at' => null]);
        });

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame($ids[2], (int) $reserved->getJobId(), 'positive control: the latest row is reserved');
        $this->assertSame($ids, array_map(fn ($r) => (int) $r->id, $this->retryRows()), 'no row deleted');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROWS_DROPPED));
        $this->assertCount(1, $this->keptKeys(), 'the kept entry');

        $this->mock->append($this->read());
        $this->popRetry()->fire();
        $this->assertSame([[$ids[0], [$ids[1]]]],
            array_map(fn ($r) => [$r->context['job_id'], $r->context['later_job_ids']], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROW_SKIPPED)), 'A skipped for B');
        $this->popRetry()->fire();
        $this->assertSame(2, $this->messageReads(), 'B ran the retry: its check does not count the reserved C');
        $this->assertCount(1, $this->seen, 'B ran its commit work');
        $this->assertSame([], $this->withMessage('[EmailService] Attachment retry after ticket creation skipped'), 'positive control: no retry refused yet');
        $reserved->fire();
        $this->assertCount(1, $this->withMessage(RetryEmailAttachments::DUPLICATE_ROW_SKIPPED), 'only A skipped');
        $this->assertSame(['ticket_changed'], array_map(fn ($r) => $r->context['reason'], $this->withMessage('[EmailService] Attachment retry after ticket creation skipped')),
            "and C's worker ran the retry too (#6182): it reached EmailService, whose locked re-check refused it after B's link");
        $this->assertSame(2, $this->messageReads(), "the import's failed read and B's retry read: C's refusal needed no read");
    }

    public function test_a_check_read_that_throws_is_recorded_and_the_run_still_checks(): void
    {
        // r2 diff:7 (ruling 1): after the push returned, the run's read of the kept entry throws.
        // It is recorded (CACHE_STEP_FAILED step check_read, ids and class only), and the run
        // reads the rows as if the entry were held: one payload scan, no later row, so it runs.
        $this->graph([$this->failedRead()]);
        $ticket = $this->ticketWithQueuedRetry();
        $this->cacheFailing(':kept:', ['get']);
        $this->mock->append($this->read());
        $scans = 0;
        DB::listen(function ($q) use (&$scans) {
            if (str_contains(strtolower($q->sql), 'like')) {
                $scans++;
            }
        });
        $this->popRetry()->fire();

        $failed = $this->withMessage(RetryEmailAttachments::CACHE_STEP_FAILED);
        // #6282 r2 (contract:2): the run checked clear, so it removes the kept entry; the store
        // reports false (no such key) and its read to tell absent from a failed removal throws
        // as well, so that removal is recorded too: kept_forget.
        $emailId = (int) Email::where('graph_id', 'MSG-1')->value('id');
        $this->assertSame([['email_id' => $emailId, 'ticket_id' => $ticket->id, 'step' => 'check_read', 'exception' => \RuntimeException::class],
            ['email_id' => $emailId, 'ticket_id' => $ticket->id, 'step' => 'kept_forget', 'exception' => \RuntimeException::class]],
            array_map(fn ($r) => $r->context, $failed), 'the degraded read is recorded (C-56)');
        $this->assertSame(Level::Warning, $failed[0]->level);
        $this->assertSame(1, $scans, 'treated as held: the run scanned for a later row');
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::DUPLICATE_ROW_SKIPPED));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 1]], $this->seen->getArrayCopy());
    }

    public function test_an_earlier_terminating_callback_that_throws_does_not_drop_the_not_queued_work(): void
    {
        // #6170: Application::terminate runs its list in a plain loop. A callback registered
        // before the not_queued work that throws must not stop it: each is isolated, the throw
        // is reported, and the marker and the notification still run, once.
        [$ticket, $push] = $this->retryCommand();
        $earlier = 0;
        \Illuminate\Support\Facades\Route::post('/b9-synthetic/earlier-terminator', function () use ($push, &$earlier) {
            app()->terminating(function () use (&$earlier) {
                $earlier++;

                throw new \RuntimeException('B9-SYNTHETIC-EARLIER-TERMINATOR');
            });
            $push();

            return response()->json(['status' => 'accepted'], 202);
        });
        $reported = [];
        app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(function (\Throwable $e) use (&$reported) {
            $reported[] = $e->getMessage();
        });

        $this->postJson('/b9-synthetic/earlier-terminator')->assertStatus(202);

        $this->assertSame(1, $earlier, 'positive control: the earlier callback ran and threw');
        $this->assertSame(['B9-SYNTHETIC-EARLIER-TERMINATOR'], $reported, 'its throw is reported, not swallowed');
        $this->assertCount(1, $this->withMessage(RetryEmailAttachments::NOT_QUEUED));
        $this->assertCount(1, $this->withMessage(RetryEmailAttachments::MARKER));
        $this->assertCount(1, $this->markerNotes($ticket->id));
        $this->assertSame([['notifyEmailAdded', $ticket->id, 0]], $this->seen->getArrayCopy());

        // A later request terminates the same list again: the work does not run a second time.
        \Illuminate\Support\Facades\Route::post('/b9-synthetic/noop', fn () => response()->json([], 202));
        $this->postJson('/b9-synthetic/noop')->assertStatus(202);
        $this->assertCount(1, $this->markerNotes($ticket->id), 'run once');
        $this->assertCount(1, $this->seen);
    }

    public function test_an_anonymous_exception_class_is_recorded_without_its_file_path(): void
    {
        // #6178 / C-56: an anonymous class's name carries a NUL, then the path and line of the
        // file that declared it; the record keeps only the part before the NUL.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        DB::beforeExecuting(function (string $sql) {
            $sql = strtolower($sql);
            if (str_contains($sql, 'max("id")') && str_contains($sql, 'from "failed_jobs"')) {
                throw new class('B9-SYNTHETIC-ANON') extends \RuntimeException {};
            }
        });

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $failed = $this->withMessage(RetryEmailAttachments::FLOOR_READ_FAILED);
        $this->assertCount(1, $failed);
        $this->assertSame('RuntimeException@anonymous', $failed[0]->context['exception']);
        $this->assertStringNotContainsString(basename(__FILE__), json_encode($failed[0]->context));
    }

    public function test_every_failed_read_back_read_is_named_not_only_the_first(): void
    {
        // #6180: the jobs scan throws and then the started read throws: read_back_step names the
        // first, and read_back_failures names both.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        $this->cacheFailing(':started:', ['get']);
        DB::beforeExecuting(function (string $sql) {
            $sql = strtolower($sql);
            if (str_contains($sql, 'from "jobs"') && str_contains($sql, 'like')) {
                throw new \LogicException('B9-SYNTHETIC-SCAN-DOWN');
            }
        });
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueueing::class, fn () => null);

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $notQueued = $this->withMessage(RetryEmailAttachments::NOT_QUEUED);
        $this->assertCount(1, $notQueued);
        $this->assertSame(['jobs', \LogicException::class], [$notQueued[0]->context['read_back_step'], $notQueued[0]->context['read_back_exception']]);
        $this->assertSame(['jobs' => \LogicException::class, 'started' => \RuntimeException::class], $notQueued[0]->context['read_back_failures']);
        $this->assertFalse($this->withMessage(RetryEmailAttachments::MARKER_QUEUE_UNKNOWN)[0]->context['queue_read_back'], 'the jobs table was not read');
    }

    public function test_a_failed_jobs_read_that_fails_after_an_empty_jobs_read_says_nothing_is_queued(): void
    {
        // #6181: the jobs table was read above its floor and held no row; only the failed_jobs
        // scan threw. Nothing queued can still add the attachments: queue_read_back true and the
        // 'no queued retry was found' note, not the 'whether a retry is queued' one.
        $this->databaseQueue();
        $this->graph([$this->failedRead()]);
        $email = $this->email();
        DB::beforeExecuting(function (string $sql) {
            $sql = strtolower($sql);
            if (str_contains($sql, 'from "failed_jobs"') && str_contains($sql, 'like')) {
                throw new \RuntimeException('B9-SYNTHETIC-FAILED-JOBS-DOWN');
            }
        });
        $this->pushThrowsAfter(\Illuminate\Queue\Events\JobQueueing::class, fn () => null);

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $notQueued = $this->withMessage(RetryEmailAttachments::NOT_QUEUED);
        $this->assertSame([null, 'failed_jobs'], [$notQueued[0]->context['queued_row'], $notQueued[0]->context['read_back_step']]);
        $this->assertTrue($this->withMessage(RetryEmailAttachments::MARKER_QUEUE_UNKNOWN)[0]->context['queue_read_back']);
        $note = $this->markerNotes($ticket->id)[0];
        $this->assertStringContainsString('no queued retry to add them was found', $note);
        $this->assertStringNotContainsString('whether a retry to add them is queued', $note);
    }
}
