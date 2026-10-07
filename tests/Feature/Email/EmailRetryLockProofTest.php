<?php

namespace Tests\Feature\Email;

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
use App\Services\TicketService;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MariaDbGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * #5559 (card 6ac5994e, burner b4g): the row locks of the #5394 retry, proven at the query
 * grammar, since SQLite compiles lockForUpdate() to nothing and the suite has no MariaDB.
 * #5451: the same probe on the webhook import, MCP create_ticket_from_email and a caller-held
 * transaction, and on the two link paths that still read under the lock (#5452, open).
 *
 * How: the connection's query grammar is swapped for LockRecordingGrammar, an SQLiteGrammar
 * whose compileLock records every FOR UPDATE request (table, transaction level, and the same
 * query compiled by Laravel's MariaDbGrammar) and still returns '' so SQLite runs unchanged. A
 * lock counts as held from that read until the outermost transaction above RefreshDatabase's
 * wrapper ends (InnoDB releases row locks at commit or rollback, not at a savepoint release). The
 * Graph mock records which locks are held at each message read.
 *
 * What it shows: on each path driven, which locked reads the code issues, in what order, inside
 * which transaction, that MariaDbGrammar compiles each of them to "... for update", and which of
 * those locks are still held when each Graph message read is sent.
 *
 * What it does not show: that InnoDB takes or waits on those locks as intended (index use, gap
 * locks, isolation level), that no deadlock or lock-wait timeout occurs against writers this
 * test does not drive, or anything under real concurrency. Those need a MariaDB run, which the
 * gate does not have.
 *
 * Synthetic data only (example.test, MSG-1 style ids). Graph is a Guzzle MockHandler;
 * Http::preventStrayRequests() refuses any Laravel HTTP client call.
 */
class EmailRetryLockProofTest extends TestCase
{
    use RefreshDatabase;

    private const MAILBOX = 'support@example.test';

    private const CID_HTML = '<p>See the screenshot</p><p><img src="cid:img1@synthetic.example.test" alt="shot"></p>';

    private int $outside = 0;

    /** @var list<string> tables locked by reads whose outermost transaction is still open */
    private array $held = [];

    /** @var list<array<string, mixed>> lock / write / graph_read events in order */
    private array $timeline = [];

    private ?\Illuminate\Database\Query\Grammars\Grammar $originalGrammar = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        Setting::setValue('graph_mailbox', self::MAILBOX);
        // Ticket creation notifies by mail through Graph; the paths here are about locks.
        $this->app->instance(NotificationService::class, new class extends NotificationService
        {
            public function __construct() {}

            public function notifyTicketCreated(Ticket $ticket): void {}

            public function notifyEmailAdded(Ticket $ticket, Email $email): void {}
        });

        $connection = DB::connection();
        $this->originalGrammar = $connection->getQueryGrammar();
        $this->assertInstanceOf(SQLiteGrammar::class, $this->originalGrammar, 'the suite runs on SQLite');
        $connection->setQueryGrammar(new LockRecordingGrammar($connection, function (Builder $query) use ($connection) {
            $table = (string) $query->from;
            $this->held[] = $table;
            $this->timeline[] = [
                'event' => 'lock',
                'table' => $table,
                'level' => DB::transactionLevel() - $this->outside,
                'mariadb' => (new MariaDbGrammar($connection))->compileSelect(clone $query),
            ];
        }));
        $this->outside = DB::transactionLevel(); // RefreshDatabase's own wrapping transaction

        // A new outermost transaction starts with no lock held. (The committed/rolled-back
        // events fire only after Laravel has run the after-commit callbacks, so they cannot mark
        // the release; a read at level 0 holds nothing, see graph().)
        Event::listen(TransactionBeginning::class, function () {
            if (DB::transactionLevel() === $this->outside + 1) {
                $this->held = [];
            }
        });
        DB::listen(function (QueryExecuted $q) {
            if (preg_match('/^(update|insert into|delete from) "(\w+)"/', $q->sql, $m)
                && in_array($m[2], ['tickets', 'ticket_notes', 'emails'], true)) {
                $this->timeline[] = ['event' => 'write', 'table' => $m[2], 'level' => DB::transactionLevel() - $this->outside];
            }
        });
    }

    /** #5714: the Graph mock; tearDown requires every queued response to have been used. */
    private ?MockHandler $mock = null;

    protected function assertPostConditions(): void
    {
        if ($this->mock !== null) {
            $this->assertSame(0, $this->mock->count(), 'G-5 (#5714): no spare Graph response left to serve a stray request');
        }
        parent::assertPostConditions();
    }

    protected function tearDown(): void
    {
        if ($this->originalGrammar !== null) {
            DB::connection()->setQueryGrammar($this->originalGrammar);
        }
        parent::tearDown();
    }

    /** A GraphClient on $responses; each message read records the locks held when it is sent. */
    private function graph(array $responses): void
    {
        $wrapped = array_map(fn ($r) => function (\Psr\Http\Message\RequestInterface $request) use ($r) {
            if (str_ends_with($request->getUri()->getPath(), '/messages/MSG-1')) {
                $level = DB::transactionLevel() - $this->outside;
                $this->timeline[] = ['event' => 'graph_read', 'held' => $level > 0 ? $this->held : [], 'level' => $level];
            }

            return $r instanceof \Closure ? $r() : $r;
        }, $responses);
        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'test-token', 3600);
        $this->app->instance(GraphClient::class, new GraphClient([
            'tenant_id' => 'tenant-b4g-synthetic',
            'client_id' => 'client',
            'client_secret' => 'secret',
            'request_timeout' => 15,
            'token_timeout' => 10,
            'handler' => HandlerStack::create($this->mock = new MockHandler($wrapped)),
        ], $cache));
    }

    private function read(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'MSG-1',
            'attachments' => [[
                '@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'ATT-FILE-1', 'name' => 'image001.png',
                'contentType' => 'image/png', 'size' => 26, 'isInline' => true,
                'contentId' => 'img1@synthetic.example.test', 'contentBytes' => base64_encode('synthetic-png-bytes-b4g'),
            ]],
        ]));
    }

    private function failedRead(): Response
    {
        return new Response(503, [], '{}');
    }

    public function email(array $overrides = []): Email
    {
        $client = Client::create(['name' => 'Example Client']);

        return Email::create($overrides + [
            'graph_id' => 'MSG-1',
            'direction' => 'inbound',
            'from_address' => 'user@example.test',
            'from_name' => 'User',
            'subject' => 'Printer offline',
            'body_text' => 'B4G-SYNTHETIC-BODY',
            'body_html' => self::CID_HTML,
            'client_id' => $client->id,
            'received_at' => now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function events(string $event): array
    {
        return array_values(array_filter($this->timeline, fn ($e) => $e['event'] === $event));
    }

    /** The lock and write events after the $n-th Graph message read (and before the next). */
    private function afterRead(int $n): array
    {
        $seen = 0;
        $out = [];
        foreach ($this->timeline as $e) {
            if ($e['event'] === 'graph_read') {
                $seen++;

                continue;
            }
            if ($seen === $n) {
                $out[] = $e;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function lockedTables(array $events): array
    {
        return array_values(array_column(array_filter($events, fn ($e) => $e['event'] === 'lock'), 'table'));
    }

    // ── #5559: the retry's re-check, on the new-ticket path ──

    public function test_the_retry_locks_ticket_then_note_then_email_for_update_before_any_write_and_reads_graph_holding_none(): void
    {
        $this->graph([$this->failedRead(), $this->read()]);
        $email = $this->email();

        app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertSame(TicketNote::class, Attachment::sole()->attachable_type, 'positive control: the retry linked');
        $reads = $this->events('graph_read');
        $this->assertCount(2, $reads, 'first read and retry read');

        // The retry's Graph read: no transaction open, no row lock held.
        $this->assertSame([0, []], [$reads[1]['level'], $reads[1]['held']], 'the retry read runs with no transaction open and no row lock held');

        // The link transaction after it: exactly these locks, in mergeTickets' order, inside
        // the one transaction, each before the first write to any of those tables.
        $link = $this->afterRead(2);
        $locks = array_values(array_filter($link, fn ($e) => $e['event'] === 'lock'));
        $this->assertSame(['tickets', 'ticket_notes', 'emails'], array_column($locks, 'table'), 'ticket, then note, then email (TicketService::mergeTickets order)');
        $this->assertSame([1, 1, 1], array_column($locks, 'level'), 'all three inside the one link transaction');
        $kinds = array_column($link, 'event');
        $firstWrite = array_search('write', $kinds, true);
        $this->assertNotFalse($firstWrite, 'positive control: the link wrote');
        $this->assertLessThan($firstWrite, max(array_keys($kinds, 'lock', true)), 'every lock precedes the first write');
        $this->assertMariaDbForUpdate($locks);
    }

    public function test_merge_tickets_locks_its_ticket_rows_before_it_writes_notes_or_emails(): void
    {
        // The other side of the ordering claim: mergeTickets' only FOR UPDATE reads are its two
        // ticket rows, taken before it writes any note or email row, so its order is ticket
        // first, as the retry's is.
        $client = Client::create(['name' => 'Example Client']);
        $user = \App\Models\User::factory()->create();
        $a = Ticket::create(['subject' => 'A', 'client_id' => $client->id, 'type' => 'incident', 'status' => 'new', 'priority' => 'p3']);
        $b = Ticket::create(['subject' => 'B', 'client_id' => $client->id, 'type' => 'incident', 'status' => 'new', 'priority' => 'p3']);
        TicketNote::create(['ticket_id' => $b->id, 'body' => 'synthetic', 'note_type' => 'note', 'is_private' => true, 'who_type' => 0, 'noted_at' => now()]);
        Email::create(['direction' => 'inbound', 'from_address' => 'user@example.test', 'subject' => 'S', 'received_at' => now(), 'ticket_id' => $b->id]);
        $this->timeline = [];

        app(TicketService::class)->mergeTickets($a, $b, $user->id);

        $this->assertSame($a->id, $b->fresh()->parent_ticket_id, 'positive control: the merge ran');
        $locks = $this->events('lock');
        $this->assertSame(['tickets', 'tickets'], array_column($locks, 'table'), 'mergeTickets locks only its two ticket rows');
        $order = array_map(fn ($e) => $e['event'].':'.$e['table'], array_values(array_filter(
            $this->timeline, fn ($e) => $e['event'] === 'lock' || in_array($e['table'] ?? null, ['ticket_notes', 'emails'], true),
        )));
        $this->assertSame(['lock:tickets', 'lock:tickets', 'write:ticket_notes', 'write:emails'], array_slice($order, 0, 4), 'ticket locks, then notes, then emails');
        $this->assertMariaDbForUpdate($locks);
    }

    // ── #5451: the other entry points, and a caller-held transaction ──

    /** A Graph webhook import of MSG-1 for a known contact, so processInbound auto-creates. */
    private function importByWebhook(): Email
    {
        $client = Client::create(['name' => 'Example Client']);
        Person::create(['client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Ada', 'last_name' => 'Contact', 'email' => 'user@example.test', 'is_active' => true]);
        Setting::setValue('email_auto_ticket', '1');

        return app(EmailService::class)->importSingleMessage([
            'id' => 'MSG-1',
            'internetMessageId' => '<m1@example.test>',
            'from' => ['emailAddress' => ['address' => 'user@example.test', 'name' => 'User']],
            'subject' => 'Printer offline',
            'body' => ['contentType' => 'html', 'content' => self::CID_HTML],
            'hasAttachments' => false,
            'receivedDateTime' => now()->toIso8601String(),
        ]);
    }

    public function test_webhook_import_retries_after_its_own_transaction_commits_holding_no_lock(): void
    {
        $this->graph([$this->failedRead(), $this->read()]);

        $email = $this->importByWebhook();

        $this->assertNotNull($email->fresh()->ticket_id, 'positive control: the import auto-created a ticket');
        $this->assertSame(TicketNote::class, Attachment::sole()->attachable_type, 'the retry linked');
        [$first, $retry] = $this->events('graph_read');
        $this->assertContains('emails', $first['held'], '#5452 (open): the first read runs under the email row lock');
        $this->assertSame([0, []], [$retry['level'], $retry['held']], 'the retry runs after the outermost (import) commit, holding nothing');
        $this->assertSame(['tickets', 'ticket_notes', 'emails'], $this->lockedTables($this->afterRead(2)));
        $this->assertMariaDbForUpdate($this->events('lock'));
    }

    public function test_a_caller_held_transaction_defers_the_retry_to_the_callers_commit(): void
    {
        // As StaffPsaActionToolExecutor::createTicketFromEmail does: an outer DB::transaction
        // that locks the email itself, then calls autoCreateTicketFromEmail.
        $this->graph([$this->failedRead(), $this->read()]);
        $email = $this->email();
        $readsInside = null;

        DB::transaction(function () use ($email, &$readsInside) {
            Email::whereKey($email->id)->lockForUpdate()->firstOrFail();
            app(EmailService::class)->autoCreateTicketFromEmail($email);
            $readsInside = count($this->events('graph_read'));
        });

        $this->assertSame(1, $readsInside, 'no retry ran while the caller still held its transaction');
        [$first, $retry] = $this->events('graph_read');
        $this->assertSame(['emails', 'emails'], $first['held'], "#5452 (open): the first read runs under the caller's and autoCreate's email locks");
        $this->assertSame([0, []], [$retry['level'], $retry['held']], 'the retry runs after the caller commits, holding nothing');
        $this->assertSame(TicketNote::class, Attachment::sole()->attachable_type, 'the retry linked');
    }

    public function test_mcp_create_ticket_from_email_retries_after_the_tool_transaction_commits(): void
    {
        $this->graph([$this->failedRead(), $this->read()]);
        $actor = \App\Models\User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $token = \App\Support\McpConfig::rotateStaffToken(allowedTools: ['create_ticket_from_email'], label: 'b4g');
        $email = $this->email();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'create_ticket_from_email', 'arguments' => ['email_id' => $email->id, 'reason' => 'synthetic']],
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertNotNull($email->fresh()->ticket_id, 'positive control: the tool created the ticket');
        [$first, $retry] = $this->events('graph_read');
        $this->assertSame(['emails', 'emails'], $first['held'], "#5452 (open): the first read runs under the tool's and autoCreate's email locks");
        $this->assertSame([0, []], [$retry['level'], $retry['held']], 'the retry runs after the tool transaction commits, holding nothing');
        $this->assertSame(TicketNote::class, Attachment::sole()->attachable_type, 'the retry linked');
    }

    /** @return array<string, array{0: \Closure(self): void}> */
    public static function linkPathsThatReadUnderTheLock(): array
    {
        return [
            'vendor-burst dedup onto an open ticket' => [function (self $t) {
                $email = $t->email(['from_address' => 'alerts@emailsecurity.app', 'subject' => 'Email delivery request: synthetic']);
                Ticket::create(['subject' => 'Email delivery request: synthetic', 'client_id' => $email->client_id,
                    'type' => 'service_request', 'status' => 'new', 'priority' => 'p3']);
                app(EmailService::class)->autoCreateTicketFromEmail($email);
            }],
            'webhook reply to an existing ticket' => [function (self $t) {
                $client = Client::create(['name' => 'Example Client']);
                $ticket = Ticket::create(['subject' => 'Printer offline', 'client_id' => $client->id, 'type' => 'incident', 'status' => 'new', 'priority' => 'p3']);
                app(EmailService::class)->importSingleMessage([
                    'id' => 'MSG-1', 'internetMessageId' => '<m2@example.test>',
                    'from' => ['emailAddress' => ['address' => 'user@example.test', 'name' => 'User']],
                    'subject' => "Re: Printer offline [{$ticket->display_id}]",
                    'body' => ['contentType' => 'html', 'content' => '<p>reply</p>'],
                    'hasAttachments' => false, 'receivedDateTime' => now()->toIso8601String(),
                ]);
            }],
        ];
    }

    /**
     * #5452 is open: these paths read Graph through linkEmailToTicket's own fetch while an email
     * row lock is held, and a failed read is not retried. Pinned as today's behaviour, so a fix
     * (or a further regression) shows up as a change here.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('linkPathsThatReadUnderTheLock')]
    public function test_link_paths_still_read_graph_once_under_the_email_row_lock_with_no_retry(\Closure $drive): void
    {
        $this->graph([$this->failedRead()]); // #5714: no spare response; no retry may read

        $drive($this);

        $reads = $this->events('graph_read');
        $this->assertCount(1, $reads, 'one read, no retry');
        $this->assertGreaterThanOrEqual(1, $reads[0]['level']);
        $this->assertContains('emails', $reads[0]['held'], 'the read runs under an email row lock');
        $this->assertNotNull(Email::where('graph_id', 'MSG-1')->value('ticket_id'), 'positive control: the email was linked');
        $this->assertSame(0, Attachment::count());
    }

    /** Every recorded lock, compiled by MariaDbGrammar, ends in "for update" and names its table. */
    private function assertMariaDbForUpdate(array $locks): void
    {
        $this->assertNotSame([], $locks, 'positive control: locks were recorded');
        foreach ($locks as $lock) {
            $this->assertMatchesRegularExpression('/^select .* from `'.$lock['table'].'` .* for update$/', $lock['mariadb']);
        }
    }
}

/**
 * An SQLiteGrammar that reports every lockForUpdate() read to $onLock and then compiles the lock
 * exactly as SQLiteGrammar does (to nothing), so the suite's SQLite runs the same SQL.
 */
class LockRecordingGrammar extends SQLiteGrammar
{
    /** @param  \Closure(Builder): void  $onLock */
    public function __construct(\Illuminate\Database\Connection $connection, private \Closure $onLock)
    {
        parent::__construct($connection);
    }

    protected function compileLock(Builder $query, $value)
    {
        if ($value === true) {
            ($this->onLock)($query);
        }

        return parent::compileLock($query, $value);
    }
}
