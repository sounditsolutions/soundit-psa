<?php

namespace Tests\Feature\Email;

use App\Enums\NotificationEventType;
use App\Jobs\SendTicketNotification;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
use App\Models\Setting;
use App\Models\SignalEvent;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Graph\GraphClient;
use App\Services\Signals\SignalHub;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class EmailUnresolvedOriginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        Bus::fake();
        Setting::setValue('graph_mailbox', 'support@example.test');
        Setting::setValue('email_auto_ticket', '0');
        User::factory()->create([
            'email' => 'operator@example.test',
            'is_active' => true,
            'notification_preferences' => [NotificationEventType::UnresolvedInboundEmail->value => true],
        ]);
    }

    private function serviceReturning(array $messages = []): EmailService
    {
        $graph = Mockery::mock(GraphClient::class);
        $graph->shouldReceive('getMailboxMessages')->andReturn($messages);
        $this->app->instance(GraphClient::class, $graph);

        return $this->app->make(EmailService::class);
    }

    private function message(string $id, int $hours = 1): array
    {
        // Graph v1.0 $metadata: message, recipient, emailAddress and itemBody types.
        // Synthetic values; the producer declares receivedDateTime as DateTimeOffset.
        return [
            'id' => $id,
            'internetMessageId' => '<'.$id.'@example.test>',
            'conversationId' => 'conversation-'.$id,
            'from' => ['emailAddress' => ['address' => 'sender@example.test', 'name' => 'Sender']],
            'toRecipients' => [['emailAddress' => ['address' => 'support@example.test']]],
            'subject' => 'Invented issue '.$id,
            'bodyPreview' => 'Please help with the printer.',
            'body' => ['contentType' => 'html', 'content' => '<p>Please help with the printer.</p>'],
            'hasAttachments' => false,
            'importance' => 'normal',
            'receivedDateTime' => now()->subHours($hours)->toIso8601String(),
        ];
    }

    public function test_a_old_live_webhook_signals_once(): void
    {
        $email = $this->serviceReturning()->importSingleMessage($this->message('old-live', 72));

        $this->assertNotNull($email);
        $this->assertNull($email->fresh()->ticket_id);
        $this->assertSame(1, SignalEvent::where('type_key', 'intake.email_unresolved')
            ->where('entity_type', $email->getMorphClass())->where('entity_id', $email->id)->count());
    }

    public function test_b_explicit_backfill_emits_only_one_summary(): void
    {
        $result = $this->serviceReturning([
            $this->message('backfill-one'), $this->message('backfill-two'), $this->message('backfill-three'),
        ])->pollMailbox(now()->subDays(4)->toIso8601String());

        $this->assertSame(0, $result->errors);
        $this->assertSame(3, Email::whereNull('ticket_id')->whereNull('dismissed_at')->count());
        $events = SignalEvent::where('type_key', 'intake.email_unresolved')->get();
        $this->assertCount(0, $events->whereNotNull('entity_id'));
        $this->assertCount(1, $events);
        $this->assertNull($events->sole()->entity_type);
        $this->assertNull($events->sole()->entity_id);
        $this->assertSame('3 unresolved imported', $events->sole()->summary);
        $this->assertSame([], $events->sole()->context);
        Bus::assertNotDispatched(SendTicketNotification::class);
    }

    public function test_b_zero_unresolved_backfill_emits_no_summary(): void
    {
        $result = $this->serviceReturning()->pollMailbox(now()->subDays(4)->toIso8601String());

        $this->assertSame(0, $result->errors);
        $this->assertSame(0, SignalEvent::where('type_key', 'intake.email_unresolved')->count());
        Bus::assertNotDispatched(SendTicketNotification::class);
    }

    public function test_c_live_poll_retry_does_not_repeat_outputs(): void
    {
        Setting::setValue('graph_last_poll_at', now()->subHours(2)->toIso8601String());
        $records = [];
        Log::listen(function (MessageLogged $record) use (&$records): void {
            if (str_contains($record->message, 'UNWATCHED SUPPORT EMAIL')) {
                $records[] = $record;
            }
        });
        $message = $this->message('retry');
        $service = $this->serviceReturning([$message]);
        $email = $service->importSingleMessage($message);
        $result = $service->pollMailbox();

        $this->assertSame(0, $result->errors);
        // PIN: E1 still emits on each processing pass.
        $this->assertSame(2, SignalEvent::where('type_key', 'intake.email_received')->count());
        $this->assertSame(1, SignalEvent::where('type_key', 'intake.email_unresolved')
            ->where('entity_type', $email->getMorphClass())->where('entity_id', $email->id)->count());
        Bus::assertDispatchedTimes(SendTicketNotification::class, 1);
        $this->assertCount(1, $records);
        $this->assertSame('warning', $records[0]->level);
        $this->assertSame($email->id, $records[0]->context['email_id']);
    }

    public function test_d_auto_ticket_still_requires_mail_within_24_hours(): void
    {
        Setting::setValue('email_auto_ticket', '1');
        $client = Client::factory()->create();
        Person::create(['client_id' => $client->id, 'first_name' => 'Synthetic', 'last_name' => 'Sender', 'email' => 'sender@example.test']);
        $service = $this->serviceReturning();
        $old = $service->importSingleMessage($this->message('old-known', 25));
        $recent = $service->importSingleMessage($this->message('recent-known', 1));

        $this->assertSame($client->id, $old->fresh()->client_id);
        $this->assertNull($old->fresh()->ticket_id);
        $this->assertNotNull($recent->fresh()->ticket_id);
    }

    public function test_initial_poll_is_backfill_and_later_live_pass_signals_each_email(): void
    {
        $service = $this->serviceReturning([$this->message('initial')]);
        $this->assertSame(0, $service->pollMailbox()->errors);
        $this->assertSame(1, SignalEvent::where('type_key', 'intake.email_unresolved')->whereNull('entity_id')->count());
        $this->assertSame(0, SignalEvent::where('type_key', 'intake.email_unresolved')->whereNotNull('entity_id')->count());
        Bus::assertNotDispatched(SendTicketNotification::class);

        $this->assertSame(0, $service->pollMailbox()->errors);
        $this->assertSame(1, SignalEvent::where('type_key', 'intake.email_unresolved')->whereNotNull('entity_id')->count());
        Bus::assertDispatchedTimes(SendTicketNotification::class, 1);
    }

    public function test_operator_reprocess_emits_one_summary_without_per_email_outputs(): void
    {
        foreach (['one', 'two', 'three'] as $id) {
            Email::create([
                'direction' => 'inbound', 'from_address' => 'sender@example.test',
                'subject' => 'Invented reprocess '.$id, 'received_at' => now(),
                'client_id' => Client::factory()->create()->id,
            ]);
        }
        $this->artisan('emails:reprocess-unmatched')->assertSuccessful();
        $events = SignalEvent::where('type_key', 'intake.email_unresolved')->get();
        $this->assertCount(1, $events);
        $this->assertNull($events->sole()->entity_id);
        $this->assertNull($events->sole()->entity_type);
        $this->assertSame('3 unresolved imported', $events->sole()->summary);
        $this->assertSame([], $events->sole()->context);
        Bus::assertNotDispatched(SendTicketNotification::class);
    }

    public function test_default_origin_does_not_emit_unresolved(): void
    {
        $email = Email::create([
            'direction' => 'inbound',
            'from_address' => 'sender@example.test',
            'subject' => 'Invented direct issue',
            'received_at' => now(),
            'client_id' => Client::factory()->create()->id,
        ]);
        $this->serviceReturning()->processInbound($email);

        $this->assertSame(0, SignalEvent::where('type_key', 'intake.email_unresolved')->count());
        Bus::assertNotDispatched(SendTicketNotification::class);
    }

    public function test_initial_backfill_poll_advances_the_cursor_when_the_summary_emit_throws(): void
    {
        $service = $this->serviceReturning([$this->message('summary-throws')]);
        $this->app->instance(SignalHub::class, new class extends SignalHub
        {
            public function __construct() {}

            public function emit(
                string $typeKey,
                ?Model $entity,
                string $summary,
                array $context = [],
                ?int $originEventId = null,
            ): ?SignalEvent {
                throw new \RuntimeException('summary emit failed');
            }
        });
        $records = [];
        Log::listen(function (MessageLogged $record) use (&$records): void {
            $records[] = $record;
        });

        $thrown = null;
        try {
            $result = $service->pollMailbox();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNull($thrown, 'a failed summary emit must not abort the poll');
        $this->assertSame(0, $result->errors);
        $this->assertNotNull(Setting::getValue('graph_last_poll_at'), 'the cursor must advance so the next poll is live');
        $summaryWarnings = array_values(array_filter(
            $records,
            fn (MessageLogged $r): bool => ($r->context['type_key'] ?? null) === 'intake.email_unresolved'
                && ($r->context['count'] ?? null) === 1
        ));
        $this->assertCount(1, $summaryWarnings);
        $this->assertSame('warning', $summaryWarnings[0]->level);
    }
}
