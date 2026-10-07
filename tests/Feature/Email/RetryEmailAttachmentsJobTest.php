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

    protected function tearDown(): void
    {
        if ($this->mock !== null) {
            $this->assertSame(0, $this->mock->count(), 'G-5 (#5714): every queued Graph response was used; no spare to serve a stray request');
        }
        parent::tearDown();
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

    // ── A timeout kill and a refusal do not share or double-count one budget ──

    /**
     * What the worker's SIGALRM handler does to a job that ran past its own $timeout. In prod
     * Job::fail() first rolls the connection back to level 0 (queue.failed.database set); here
     * that would end RefreshDatabase's wrapping transaction, so it is switched off. The retry
     * itself never holds a transaction across its Graph read (EmailRetryLockProofTest).
     */
    private function timeOut(\Illuminate\Queue\Jobs\DatabaseJob $job): void
    {
        config(['queue.failed.database' => null]);
        $worker = app('queue.worker');
        $e = \Illuminate\Queue\TimeoutExceededException::forJob($job);
        // --tries=2 is the worker's option; the job's own maxTries (1) must win.
        (fn () => $this->markJobAsFailedIfWillExceedMaxAttempts('database', $job, 2, $e))->call($worker);
        (fn () => $this->markJobAsFailedIfItShouldFailOnTimeout('database', $job, $e))->call($worker);
    }

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
        Attachment::updating(function () use ($job, &$killed, $ticketId) {
            if ($killed) {
                return;
            }
            $killed = true;
            $this->assertSame(1, Attachment::count(), 'positive control: the row exists at the kill');
            $this->assertCount(1, Storage::disk('local')->allFiles('attachments'), 'and its file');
            $this->timeOut($job);
            // The worker kills the process right after failed(); what it leaves is read here.
            $this->assertSame(0, Attachment::withTrashed()->count(), 'the stored row is discarded');
            $this->assertSame([], Storage::disk('local')->allFiles('attachments'), 'and its file');
            $this->assertSame(['timed_out'], array_map(fn ($r) => $r->context['reason'], $this->withMessage(RetryEmailAttachments::MARKER)));
            $this->assertCount(1, $this->markerNotes($ticketId));
            $this->assertSame([['notifyEmailAdded', $ticketId, 0]], $this->seen->getArrayCopy());
            throw new \RuntimeException('B4I-SYNTHETIC-KILLED'); // stands in for the kill
        });

        try {
            $job->fire();
        } catch (\RuntimeException) {
        }

        $this->assertTrue($killed, 'positive control: the kill point was reached');
    }
}
