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
        // #6005: unrelated rows queued while the link transaction is open (another worker's
        // delayed loop, here inserted at the test's level so the rollback keeps them) survive.
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

    public function test_a_rollback_that_throws_is_recorded_as_the_rollback_step(): void
    {
        // #5997: the rollback itself throws (a lost connection rethrows from rollBack), so the
        // re-delete never runs.
        $this->graph([$this->failedRead()]);
        $ticketId = $this->ticketWithQueuedRetry()->id;
        $command = $this->commandOf($this->popRetry());
        $level = DB::transactionLevel();
        (fn () => self::$progress[$this->progressKey()] = ['level' => $level])->call($command);
        DB::beginTransaction();
        $pdo = DB::connection()->getPdo();
        DB::connection()->setPdo(new class extends \PDO
        {
            public function __construct() {}

            public function rollBack(): bool
            {
                throw new \PDOException('B5-SYNTHETIC-GONE-AWAY');
            }

            public function exec(string $statement): int|false
            {
                throw new \PDOException('B5-SYNTHETIC-GONE-AWAY');
            }

            public function inTransaction(): bool
            {
                return true;
            }
        });
        // setPdo() resets the connection's transaction count; the link transaction is still open.
        $levels = fn (int $n) => (fn () => $this->transactions = $n)->call(DB::connection());
        $levels($level + 1);
        try {
            $command->failed(new \Illuminate\Queue\TimeoutExceededException('B5-SYNTHETIC-TIMEOUT'));
        } catch (\Throwable) {
            // The marker and commit work then run on the dead connection; what matters is the record.
        } finally {
            DB::connection()->setPdo($pdo);
            $levels($level + 1);
            DB::rollBack($level);
        }

        $step = $this->withMessage(RetryEmailAttachments::TIMEOUT_STEP_THREW);
        $this->assertCount(1, $step);
        $this->assertSame(Level::Warning, $step[0]->level);
        $this->assertSame(['rollback', \PDOException::class], [$step[0]->context['step'], $step[0]->context['exception']]);
        $this->assertSame($ticketId, $step[0]->context['ticket_id']);
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
        // #5994: the INSERT committed, then the push threw (here a JobQueued listener; the lost
        // reply after a committed INSERT is the same shape). The row is read back by push id, so
        // no not_queued marker and no inline commit work: the queued job runs them once.
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
        $this->assertSame(['email_id' => $email->id, 'ticket_id' => $ticket->id, 'exception' => \RuntimeException::class], $found[0]->context);
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
        DB::beforeExecuting(function (string $sql) {
            if (str_starts_with(strtolower($sql), 'insert into "jobs"')) {
                throw new \RuntimeException('B5-SYNTHETIC-JOBS-DOWN');
            }
        });

        (new RetryEmailAttachments($c->emailId, $c->ticketId, $c->noteId, $c->baseline))->queueAfterCommit();

        $this->assertTrue(\App\Support\TechnicianConfig::enabled(), 'positive control: the loop would be dispatched');
        $this->assertCount(1, $this->withMessage(RetryEmailAttachments::NOT_QUEUED), 'positive control: the push threw');
        $this->assertSame(0, DB::table('jobs')->count(), 'no technician loop was queued');
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
        $this->assertSame(['email_id' => 999001, 'ticket_id' => 999002, 'ticket_found' => false, 'email_found' => false], $skipped[0]->context);
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
    }
}
