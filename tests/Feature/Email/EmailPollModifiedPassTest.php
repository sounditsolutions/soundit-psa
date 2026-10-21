<?php

namespace Tests\Feature\Email;

use App\Enums\NotificationEventType;
use App\Jobs\SendTicketNotification;
use App\Models\Email;
use App\Models\Setting;
use App\Models\SignalEvent;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class EmailPollModifiedPassTest extends TestCase
{
    use RefreshDatabase;

    private const CURSOR = '2026-10-20T12:00:00+00:00';

    private const WINDOW = '2026-10-20T08:00:00+00:00';

    private const SELECT = 'id,internetMessageId,conversationId,from,toRecipients,ccRecipients,subject,bodyPreview,body,hasAttachments,importance,receivedDateTime,internetMessageHeaders';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Bus::fake();
        Setting::setValue('graph_mailbox', 'support@example.test');
        Setting::setValue('graph_last_poll_at', self::CURSOR);
        Setting::setValue('email_auto_ticket', '0');
        User::factory()->create([
            'email' => 'operator@example.test', 'is_active' => true,
            'notification_preferences' => [NotificationEventType::UnresolvedInboundEmail->value => true],
        ]);
    }

    private function message(string $id, string $received = '2026-10-19T06:00:00+00:00'): array
    {
        // Graph v1.0 $metadata: message, recipient, emailAddress and itemBody; synthetic values.
        return [
            'id' => $id, 'internetMessageId' => '<'.$id.'@example.test>',
            'conversationId' => 'conversation-'.$id,
            'from' => ['emailAddress' => ['address' => 'sender@example.test', 'name' => 'Sender']],
            'toRecipients' => [['emailAddress' => ['address' => 'support@example.test']]],
            'subject' => 'Synthetic issue '.$id, 'bodyPreview' => 'Please help.',
            'body' => ['contentType' => 'text', 'content' => 'Please help.'],
            'hasAttachments' => false, 'importance' => 'normal',
            'receivedDateTime' => $received, 'lastModifiedDateTime' => '2026-10-20T09:00:00+00:00',
        ];
    }

    private function service(array $first, array|GraphClientException $second): EmailService
    {
        $graph = Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getMailboxMessages')->once()->ordered()->with('support@example.test', [
            '$select' => self::SELECT, '$filter' => 'receivedDateTime ge '.self::WINDOW,
            '$orderby' => 'receivedDateTime asc', '$top' => 50,
        ])->andReturn($first);
        $expectation = $graph->shouldReceive('getMailboxMessages')->atMost()->once()->ordered()->with('support@example.test', [
            '$select' => self::SELECT, '$filter' => 'lastModifiedDateTime ge '.self::WINDOW,
            '$orderby' => 'lastModifiedDateTime asc', '$top' => 50,
        ]);
        if ($second instanceof GraphClientException) {
            $expectation->andThrow($second);
        } else {
            $expectation->andReturn($second);
        }
        $this->app->instance(GraphClient::class, $graph);

        return $this->app->make(EmailService::class);
    }

    public function test_a_old_received_recently_modified_imports_once(): void
    {
        $result = $this->service([], [$this->message('moved')])->pollMailbox();
        $this->assertSame(0, $result->errors);
        $this->assertSame(1, $result->created);
        $this->assertSame(1, Email::count());
        $this->assertSame(1, SignalEvent::where('type_key', 'intake.email_unresolved')->count());
        Bus::assertDispatchedTimes(SendTicketNotification::class, 1);
    }

    public function test_b1_webhook_internet_message_id_dedup_does_not_signal_twice(): void
    {
        $message = $this->message('webhook');
        $moved = array_replace($message, ['id' => 'moved-webhook']);
        $service = $this->service([], [$moved]);
        $email = $service->importSingleMessage($message);
        $this->assertNotNull($email);
        $this->assertSame(1, Email::count());
        $this->assertSame(1, SignalEvent::where('type_key', 'intake.email_unresolved')->count());
        $result = $service->pollMailbox();
        $this->assertSame(0, $result->errors);
        $this->assertSame(0, $result->created);
        $this->assertSame(1, Email::count());
        $this->assertSame('moved-webhook', $email->fresh()->graph_id);
        $this->assertSame(1, SignalEvent::where('type_key', 'intake.email_unresolved')->count());
        Bus::assertDispatchedTimes(SendTicketNotification::class, 1);
    }

    public function test_b2_pass_one_graph_id_is_not_processed_twice(): void
    {
        $message = $this->message('overlap', '2026-10-20T13:00:00+00:00');
        $result = $this->service([$message], [$message])->pollMailbox();
        $this->assertSame(0, $result->errors);
        $this->assertSame(1, $result->created);
        $this->assertSame(1, Email::count());
        $this->assertSame(1, SignalEvent::where('type_key', 'intake.email_received')->count());
        $this->assertSame(1, SignalEvent::where('type_key', 'intake.email_unresolved')->count());
        Bus::assertDispatchedTimes(SendTicketNotification::class, 1);
    }

    public function test_c_pin_received_request_is_unchanged(): void
    {
        $graph = Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getMailboxMessages')->once()->with('support@example.test', [
            '$select' => self::SELECT, '$filter' => 'receivedDateTime ge '.self::WINDOW,
            '$orderby' => 'receivedDateTime asc', '$top' => 50,
        ])->andReturn([]);
        $graph->shouldReceive('getMailboxMessages')->with('support@example.test', Mockery::on(
            fn (array $params): bool => str_starts_with($params['$filter'] ?? '', 'lastModifiedDateTime')
        ))->andReturn([]);
        $this->app->instance(GraphClient::class, $graph);
        $this->assertSame(0, $this->app->make(EmailService::class)->pollMailbox()->errors);
    }

    public function test_d_modified_graph_failure_keeps_first_import_and_cursor(): void
    {
        $message = $this->message('first', '2026-10-20T13:00:00+00:00');
        $result = $this->service([$message], new GraphClientException('synthetic failure'))->pollMailbox();
        $this->assertSame(1, Email::count());
        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->errors);
        $this->assertSame(['Graph API error: synthetic failure'], $result->errorMessages);
        $this->assertSame($message['receivedDateTime'], Setting::getValue('graph_last_poll_at'));
    }

    public function test_e_modified_pass_cannot_advance_or_rewind_cursor(): void
    {
        $first = $this->message('first', '2026-10-20T13:00:00+00:00');
        $result = $this->service([$first], [$this->message('later', '2026-10-21T13:00:00+00:00')])->pollMailbox();
        $this->assertSame(0, $result->errors);
        $this->assertSame(2, Email::count());
        $this->assertSame($first['receivedDateTime'], Setting::getValue('graph_last_poll_at'));
    }

    public function test_e_empty_first_pass_keeps_cursor(): void
    {
        $result = $this->service([], [$this->message('old')])->pollMailbox();
        $this->assertSame(0, $result->errors);
        $this->assertSame(1, Email::count());
        $this->assertSame(self::CURSOR, Setting::getValue('graph_last_poll_at'));
    }

    public function test_e_modified_import_failure_does_not_clamp_first_cursor(): void
    {
        Event::listen('eloquent.creating: '.Email::class, function (Email $email): void {
            if ($email->graph_id === 'poison') {
                throw new \RuntimeException('synthetic import failure');
            }
        });
        $first = $this->message('first', '2026-10-21T13:00:00+00:00');
        $result = $this->service([$first], [$this->message('poison', '2026-10-20T10:00:00+00:00')])->pollMailbox();
        $this->assertSame(1, $result->errors);
        $this->assertSame(['Failed to import message poison: synthetic import failure'], $result->errorMessages);
        $this->assertSame(1, Email::count());
        $this->assertSame($first['receivedDateTime'], Setting::getValue('graph_last_poll_at'));
    }

    public function test_f_real_graph_modified_pass_follows_next_link(): void
    {
        $next = 'https://graph.microsoft.com/v1.0/users/support@example.test/mailFolders/inbox/messages?$skip=50';
        $mock = new MockHandler([
            new Response(200, [], json_encode(['value' => []])),
            new Response(200, [], json_encode(['value' => [$this->message('page-one')], '@odata.nextLink' => $next])),
            new Response(200, [], json_encode(['value' => [$this->message('page-two')]])),
        ]);
        $history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $cache = new Repository(new ArrayStore);
        $cache->put('graph_api_token', 'synthetic-cached-value', 3600);
        $graph = new GraphClient(['request_timeout' => 5, 'token_timeout' => 5, 'handler' => $stack], $cache);
        foreach (['http', 'authHttp'] as $property) {
            $this->assertSame($stack, (new \ReflectionProperty(GraphClient::class, $property))->getValue($graph)->getConfig('handler'));
        }
        $this->app->instance(GraphClient::class, $graph);
        $result = $this->app->make(EmailService::class)->pollMailbox();
        $this->assertSame(0, $result->errors);
        $this->assertSame(2, Email::count());
        $this->assertSame(2, $result->created);
        $this->assertCount(3, $history);
        parse_str($history[1]['request']->getUri()->getQuery(), $params);
        $this->assertSame('lastModifiedDateTime ge '.self::WINDOW, $params['$filter']);
        $this->assertSame('lastModifiedDateTime asc', $params['$orderby']);
        $this->assertSame(self::SELECT, $params['$select']);
        $this->assertSame('50', $params['$top']);
        $this->assertSame($next, (string) $history[2]['request']->getUri());
        $this->assertSame(0, $mock->count());
    }

    public function test_g_explicit_backfill_counts_both_passes_in_one_summary(): void
    {
        $result = $this->service([$this->message('first')], [$this->message('modified')])->pollMailbox(self::CURSOR);
        $this->assertSame(0, $result->errors);
        $this->assertSame(2, Email::count());
        $events = SignalEvent::where('type_key', 'intake.email_unresolved')->get();
        $this->assertCount(1, $events);
        $this->assertNull($events->sole()->entity_id);
        $this->assertSame('2 unresolved imported', $events->sole()->summary);
        Bus::assertNotDispatched(SendTicketNotification::class);
    }

    public function test_h_modified_pass_page_limit_is_reported_and_keeps_cursor(): void
    {
        $records = [];
        Log::listen(function (MessageLogged $record) use (&$records): void {
            if (str_contains($record->message, 'page limit')) {
                $records[] = $record;
            }
        });
        $first = $this->message('first', '2026-10-20T13:00:00+00:00');
        $result = $this->service([$first], array_fill(0, 2500, []))->pollMailbox();
        $this->assertSame(1, $result->errors);
        $this->assertSame(['Hit page limit during modified pass'], $result->errorMessages);
        $this->assertSame(1, Email::count());
        $this->assertSame($first['receivedDateTime'], Setting::getValue('graph_last_poll_at'));
        $this->assertCount(1, $records);
        $this->assertSame('warning', $records[0]->level);
        $this->assertSame('[EmailService] Hit page limit during modified pass', $records[0]->message);
    }

    public function test_i_modified_pass_does_not_retry_backfill_imported_row_live(): void
    {
        $this->assertSame(0, $this->service([$this->message('touched')], [])->pollMailbox(self::CURSOR)->errors);
        $this->assertSame(1, Email::count());
        Setting::setValue('graph_last_poll_at', self::CURSOR);
        Setting::where('key', 'graph_poll_backfill_pending')->delete();

        $result = $this->service([], [$this->message('touched')])->pollMailbox();
        $this->assertSame(0, $result->errors);
        $this->assertSame(0, $result->created);
        $this->assertSame(1, Email::count());
        $this->assertSame(['1 unresolved imported'], SignalEvent::where('type_key', 'intake.email_unresolved')
            ->orderBy('id')->pluck('summary')->all());
        $this->assertSame(0, SignalEvent::where('type_key', 'intake.email_unresolved')->whereNotNull('entity_id')->count());
        Bus::assertNotDispatched(SendTicketNotification::class);
    }

    public function test_g_pending_backfill_uses_backfill_origin_for_modified_import(): void
    {
        Setting::setValue('graph_poll_backfill_pending', '1');
        $result = $this->service([], [$this->message('pending')])->pollMailbox();
        $this->assertSame(0, $result->errors);
        $this->assertSame(1, Email::count());
        $events = SignalEvent::where('type_key', 'intake.email_unresolved')->get();
        $this->assertCount(1, $events);
        $this->assertNull($events->sole()->entity_id);
        $this->assertSame('1 unresolved imported', $events->sole()->summary);
        Bus::assertNotDispatched(SendTicketNotification::class);
    }
}
