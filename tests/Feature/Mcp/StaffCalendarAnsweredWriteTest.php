<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Graph\GraphClient;
use App\Services\Mcp\StaffCalendarToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #6264: Graph answered a create/update with an event that passed its shape check (a string id),
 * and projecting that event then threw (here 'attendees' is not an array, so array_map throws a
 * TypeError). The write landed, so it is reported as executed with a fault, never as an unknown
 * outcome, and the event id is recorded.
 *
 * #6265: the immediate write path tracks whether the write call was entered, as the approval
 * path does. A non-Graph throwable after that point is an unresolved outcome (audited, logged by
 * class, returned as a tool error); one raised before it still propagates.
 *
 * GraphClient is mocked and Http::preventStrayRequests() is on (G-5). Synthetic values only (G-13).
 */
class StaffCalendarAnsweredWriteTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'owner6264@example.test';

    private const CREATE = ['user_upn' => self::OWNER, 'subject' => 'Onsite', 'start' => '2026-07-29T15:00:00', 'end' => '2026-07-29T16:00:00', 'reason' => 'Asked.'];

    /** An event that passes CalendarGraphShapes::assertEvent() but that projectEvent() cannot read. */
    private const UNREADABLE_EVENT = ['id' => 'EVT-NEW-6264', 'subject' => 'Onsite', 'attendees' => 'not-a-list'];

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $this->approver = User::factory()->create(['name' => 'Gus']);
        Setting::setValue('calendar_enabled', '1');
        Setting::setValue('calendar_allowed_owner_upns', json_encode([self::OWNER]));
    }

    /** @return \Closure(): list<MessageLogged> */
    private function captureLogs(): \Closure
    {
        $logged = new \ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $m) use ($logged): void {
            $logged[] = $m;
        });

        return fn () => $logged->getArrayCopy();
    }

    /** @param list<MessageLogged> $logged @return list<MessageLogged> */
    private static function matching(array $logged, string $needle): array
    {
        return array_values(array_filter($logged, fn (MessageLogged $m) => str_contains($m->message, $needle)));
    }

    private function staged(array $arguments, string $stagedTool): TechnicianRun
    {
        $ticket = Ticket::factory()->create();
        $staged = app(StaffCalendarToolExecutor::class)->execute($stagedTool, $arguments + ['ticket_id' => $ticket->id], 0, 'mcp-staff:chet', 'chet');

        return TechnicianRun::findOrFail($staged['run_id']);
    }

    /**
     * Rows: [arguments, staged tool, direct tool, write method]. The update carries no body, so it
     * makes no pre-read.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string}>
     */
    public static function answeredWrites(): array
    {
        return [
            'create' => [self::CREATE, 'calendar_stage_create_event', 'calendar_create_event', 'createEvent'],
            'update' => [['user_upn' => self::OWNER, 'event_id' => 'EVT-NEW-6264', 'subject' => 'Moved', 'reason' => 'Asked.'], 'calendar_stage_update_event', 'calendar_update_event', 'updateEvent'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('answeredWrites')]
    public function test_an_approved_write_graph_answered_but_the_psa_could_not_read_is_executed_with_a_fault(array $arguments, string $stagedTool, string $directTool, string $writeMethod): void
    {
        $run = $this->staged($arguments, $stagedTool);
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive($writeMethod)->once()->andReturn(self::UNREADABLE_EVENT));
        $logged = $this->captureLogs();

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id);

        $this->assertSame('executed_with_fault', $r->status, 'the write landed: not outcome_unknown');
        $this->assertSame('Calendar write executed (event EVT-NEW-6264), but the event Microsoft Graph returned could not be read. Do NOT re-approve it; check the calendar.', $r->message);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(
            ['Operator-approved calendar write EXECUTED (event EVT-NEW-6264), but the event Microsoft Graph returned could not be read (TypeError). Do NOT re-approve; check the calendar.'],
            TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'executed_with_fault')->pluck('summary')->all(),
        );
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'error')->count(), 'never a failure row');
        $this->assertSame(0, TechnicianActionLog::where('run_id', $run->id)->where('summary', 'like', '%UNRESOLVED%')->count());
        $this->assertStringContainsString('calendar event EVT-NEW-6264 in', (string) TicketNote::where('ticket_id', $run->ticket_id)->value('body'), 'the back-link records the event id');
        $records = self::matching($logged(), 'could not be projected');
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame(['tool' => $directTool, 'exception' => \TypeError::class, 'file' => 'app/Services/Mcp/StaffCalendarToolExecutor.php'], array_diff_key($records[0]->context, ['line' => 0]));
        $this->assertSame([], self::matching($logged(), 'unresolved'));

        // The landed write is not re-staged: its run is Done, which stageWrite() never revives.
        $again = app(StaffCalendarToolExecutor::class)->execute($stagedTool, $arguments + ['ticket_id' => $run->ticket_id], 0, 'mcp-staff:chet', 'chet');
        $this->assertTrue($again['already_executed'] ?? false);
    }

    public function test_an_immediate_create_graph_answered_but_the_psa_could_not_read_is_reported_landed(): void
    {
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('createEvent')->once()->andReturn(self::UNREADABLE_EVENT));
        $logged = $this->captureLogs();

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_create_event', self::CREATE + ['ticket_id' => $ticket->id], 0, 'mcp-staff:chet', 'chet');

        $this->assertTrue($result['success'] ?? false);
        $this->assertSame(['id' => 'EVT-NEW-6264'], $result['event']);
        $this->assertSame('The calendar write landed (event EVT-NEW-6264), but the event Microsoft Graph returned could not be read. Do not retry; check the calendar.', $result['warning']);
        $rows = TechnicianActionLog::where('ticket_id', $ticket->id)->orderBy('id')->get();
        $this->assertSame(['executed', 'executed_with_fault'], $rows->pluck('result_status')->all());
        $this->assertSame('Calendar write EXECUTED (event EVT-NEW-6264), but the event Microsoft Graph returned could not be read (TypeError). Do NOT retry; check the calendar.', $rows[1]->summary);
        $this->assertStringContainsString('calendar event EVT-NEW-6264 in', (string) TicketNote::where('ticket_id', $ticket->id)->value('body'));
        $this->assertCount(1, self::matching($logged(), 'could not be projected'));
    }

    /**
     * #6265: rows: [direct tool, arguments, write method]. Update carries a body, so its pre-read
     * succeeds first.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function immediateEnteredWrites(): array
    {
        return [
            'create' => ['calendar_create_event', self::CREATE, 'createEvent'],
            'update with a body' => ['calendar_update_event', ['user_upn' => self::OWNER, 'event_id' => 'EVT-6265', 'body' => 'New agenda', 'reason' => 'Asked.'], 'updateEvent'],
            'cancel' => ['calendar_cancel_event', ['user_upn' => self::OWNER, 'event_id' => 'EVT-6265', 'comment' => 'x', 'reason' => 'Resolved.'], 'cancelEvent'],
            'respond' => ['calendar_respond_event', ['user_upn' => self::OWNER, 'event_id' => 'EVT-6265', 'response' => 'accept', 'reason' => 'Asked.'], 'respondEvent'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('immediateEnteredWrites')]
    public function test_an_immediate_write_that_throws_after_the_write_call_was_entered_is_unresolved(string $directTool, array $arguments, string $writeMethod): void
    {
        $ticket = Ticket::factory()->create();
        $failure = new \TypeError('synthetic non-Graph failure '.self::OWNER);
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('getEvent')->andReturn(['id' => 'EVT-6265', 'isOnlineMeeting' => false])
            ->shouldReceive($writeMethod)->once()->andThrow($failure));
        $logged = $this->captureLogs();

        $result = app(StaffCalendarToolExecutor::class)->execute($directTool, $arguments + ['ticket_id' => $ticket->id], 0, 'mcp-staff:chet', 'chet');

        $this->assertSame(['error' => 'The calendar write call was entered and an unexpected error was then raised, so whether the write reached the calendar is unknown. Check the calendar before any retry.'], $result);
        $this->assertSame(
            ['Graph calendar write outcome UNRESOLVED: an unexpected failure was raised after the write call was entered: TypeError'],
            TechnicianActionLog::where('ticket_id', $ticket->id)->where('result_status', 'error')->pluck('summary')->all(),
        );
        $this->assertSame(0, TechnicianActionLog::where('ticket_id', $ticket->id)->where('result_status', 'executed')->count());
        $records = self::matching($logged(), 'outcome unresolved');
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]->level);
        $this->assertSame('[Calendar] immediate calendar write outcome unresolved: an unexpected failure was raised after the write call was entered', $records[0]->message);
        $this->assertSame(['tool' => $directTool, 'ticket_id' => $ticket->id, 'exception' => \TypeError::class, 'file' => 'tests/Feature/Mcp/StaffCalendarAnsweredWriteTest.php', 'line' => $failure->getLine()], $records[0]->context);
        $this->assertStringNotContainsString(self::OWNER, json_encode($records[0]->context) ?: '');
    }

    /** #6265: before the write call is entered (the update's pre-read throws) nothing was sent, so it propagates as before. */
    public function test_an_immediate_write_that_throws_before_the_write_call_still_propagates(): void
    {
        $ticket = Ticket::factory()->create();
        $this->mock(GraphClient::class, fn ($m) => $m->shouldReceive('getEvent')->andThrow(new \RuntimeException('synthetic non-Graph failure'))
            ->shouldReceive('updateEvent')->never());
        $logged = $this->captureLogs();

        $thrown = rescue(fn () => app(StaffCalendarToolExecutor::class)->execute('calendar_update_event', ['user_upn' => self::OWNER, 'event_id' => 'EVT-6265', 'body' => 'New agenda', 'reason' => 'Asked.', 'ticket_id' => $ticket->id], 0, 'mcp-staff:chet', 'chet'), fn ($e) => $e, false);

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame(0, TechnicianActionLog::where('ticket_id', $ticket->id)->where('summary', 'like', '%UNRESOLVED%')->count());
        $this->assertSame([], self::matching($logged(), 'unresolved'));
    }
}
