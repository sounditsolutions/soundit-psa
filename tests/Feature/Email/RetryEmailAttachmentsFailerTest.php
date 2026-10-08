<?php

namespace Tests\Feature\Email;

use App\Jobs\RetryEmailAttachments;
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
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * #5998 (b5, card 6ac5994e): a timeout inside the retry's link transaction under the SHIPPED
 * failer (queue.failed.driver database-uuids on the default connection, read from config, not
 * set here). Job::fail rolls the connection back to level 0 before its delete, so failed()'s
 * own rollback/re-delete branch (#5800) is skipped. Not RefreshDatabase: that rollback to
 * level 0 would end its wrapping transaction, so the test migrates its own fresh database and
 * runs at prod's level 0.
 *
 * Synthetic data only (example.test, MSG-1 style ids).
 */
class RetryEmailAttachmentsFailerTest extends TestCase
{
    private TestHandler $logs;

    private ?MockHandler $mock = null;

    private \ArrayObject $seen;

    protected function setUp(): void
    {
        parent::setUp();
        // A fresh :memory: connection of this app's own, not RefreshDatabase's cached one.
        $this->artisan('migrate:fresh');
        Http::preventStrayRequests();
        Storage::fake('local');
        Setting::setValue('graph_mailbox', 'support@example.test');
        $this->logs = new TestHandler;
        $handler = $this->logs;
        foreach (array_keys(config('logging.channels')) as $name) {
            config(["logging.channels.{$name}" => ['driver' => 'custom', 'via' => fn () => new \Monolog\Logger($name, [$handler])]]);
            Log::forgetChannel($name);
        }
        $this->seen = new \ArrayObject;
        $this->app->instance(NotificationService::class, new class($this->seen) extends NotificationService
        {
            public function __construct(private \ArrayObject $seen) {}

            public function notifyEmailAdded(Ticket $ticket, Email $email): void
            {
                $this->seen[] = $ticket->id;
            }

            public function notifyTicketCreated(Ticket $ticket): void {}
        });
        $this->mock = new MockHandler([new Response(503, [], '{}')]);
        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'test-token', 3600);
        $this->app->instance(GraphClient::class, new GraphClient([
            'tenant_id' => 'tenant-b5-synthetic', 'client_id' => 'client', 'client_secret' => 'SECRET_FIXTURE',
            'request_timeout' => 15, 'token_timeout' => 10, 'handler' => HandlerStack::create($this->mock),
        ], $cache));
    }

    protected function assertPostConditions(): void
    {
        $this->assertSame(0, $this->mock->count(), 'G-5: every queued Graph response was used');
        parent::assertPostConditions();
    }

    /** @return list<LogRecord> */
    private function withMessage(string $message): array
    {
        return array_values(array_filter($this->logs->getRecords(), fn (LogRecord $r) => $r->message === $message));
    }

    public function test_under_the_shipped_failer_the_framework_rolls_back_and_failed_skips_its_own_rollback_and_re_delete(): void
    {
        $this->assertSame(['database-uuids', config('database.default')], [config('queue.failed.driver'), config('queue.failed.database')],
            'precondition: the shipped failer config, not set by this test');
        $this->assertSame(0, DB::transactionLevel(), "precondition: prod's level 0");
        config(['queue.default' => 'database']);
        $client = Client::create(['name' => 'Example Client']);
        $email = Email::create([
            'graph_id' => 'MSG-1', 'direction' => 'inbound', 'from_address' => 'user@example.test', 'from_name' => 'User',
            'subject' => 'Printer offline', 'body_text' => 'B5-SYNTHETIC-BODY', 'body_html' => '<p>B5</p>',
            'client_id' => $client->id, 'received_at' => now(),
        ]);
        $ticketId = app(EmailService::class)->autoCreateTicketFromEmail($email)->id;
        $queue = app('queue')->connection('database');
        while (($job = $queue->pop('default')) !== null && $job->resolveName() !== RetryEmailAttachments::class) {
            $job->delete(); // other jobs the import queued (signal routing)
        }
        $this->assertSame(RetryEmailAttachments::class, $job?->resolveName(), 'positive control: the retry was queued');
        $jobId = $job->getJobId();
        $this->mock->append(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'MSG-1',
            'attachments' => [['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'ATT-FILE-1', 'name' => 'image001.png',
                'contentType' => 'image/png', 'size' => 4, 'isInline' => false, 'contentBytes' => base64_encode('b5px')]],
        ])));

        $deleteAttempts = 0;
        $atKill = null;
        // Nothing here may assert: a failed assertion would be caught by the code under test.
        Attachment::updated(function (Attachment $a) use ($job, $jobId, &$deleteAttempts, &$atKill) {
            if ($a->attachable_type !== TicketNote::class || $atKill !== null) {
                return;
            }
            $open = DB::transactionLevel();
            // DatabaseQueue::deleteReserved reads the row by id (lockForUpdate) before deleting it,
            // so each delete attempt is one such read, even when the row is already gone: one is
            // Job::fail's, a second would be failed()'s re-delete.
            DB::listen(function ($q) use (&$deleteAttempts) {
                if (str_starts_with(strtolower($q->sql), 'select * from "jobs" where "id" = ?')) {
                    $deleteAttempts++;
                }
            });
            $worker = app('queue.worker');
            (fn () => $this->markJobAsFailedIfItShouldFailOnTimeout('database', $job, \Illuminate\Queue\TimeoutExceededException::forJob($job)))->call($worker);
            $atKill = [
                'open_before' => $open,
                'open_after' => DB::transactionLevel(),
                'delete_attempts' => $deleteAttempts,
                'queued' => DB::table('jobs')->where('id', $jobId)->count(),
            ];
            // The worker kills the process here; reopen what the code under test unwinds.
            for ($i = 0; $i < $open; $i++) {
                DB::beginTransaction();
            }
            throw new \RuntimeException('B5-SYNTHETIC-KILLED');
        });

        $job->fire();

        $this->assertSame(
            ['open_before' => 1, 'open_after' => 0, 'delete_attempts' => 1, 'queued' => 0],
            $atKill,
            '#5998: Job::fail rolled back to level 0 and deleted the row once; failed() ran no rollback and no second delete',
        );
        $this->assertSame([], $this->withMessage(RetryEmailAttachments::TIMEOUT_STEP_THREW));
        $marker = $this->withMessage(RetryEmailAttachments::MARKER);
        $this->assertCount(1, $marker);
        $this->assertSame('timed_out', $marker[0]->context['reason']);
        $this->assertSame([$ticketId], $this->seen->getArrayCopy());
        $this->assertSame(0, Attachment::withTrashed()->count(), 'the rolled-back read is discarded');
    }
}
