<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Jobs\NotifyStagedActionAwaitingApproval;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Technician\Notify\OperatorNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Tier-1: notify the operator when an action is STAGED (psa-2f0bg).
 *
 * THE GAP. Nothing notifies on TechnicianRunState::AwaitingApproval. The only
 * pending-approval surface is DigestBuilder — a DAILY digest — so an action staged at
 * 09:05 waits until the next one. ProposeCloseTool is not a counter-example: it
 * notifies when its dispatch returns 'executed' (auto-close); its HELD branch returns
 * with no notification at all.
 *
 * WHY AN OBSERVER, AND WHY afterCommit(). There is no single staging call site — runs
 * reach AwaitingApproval from at least six places (the three MCP write executors,
 * DraftPipeline, SendReplyTool, ProposeCloseTool, IntakeRecorder). Notifying at each
 * call site would be a hand-maintained list that must agree with reality, which is the
 * failure class psa-g4y9f hit twice in one night. So: ONE observer on the transition,
 * dispatching a QUEUED job with ->afterCommit() — which satisfies CO-21's real
 * constraint (never send externally from inside a transaction, never notify for a
 * stage that rolls back) without giving up the single choke point.
 *
 * THE CONTENT BAR is decide-from-lockscreen: client + ticket + action type + the
 * actual proposed content, so the operator can decide from a phone without opening the
 * cockpit. The deep-link is the fallback, not the decision surface.
 *
 * THE HARD BOUNDARY: never email a credential. A staged cipp_reset_user_password
 * approval mints a temp password, and that must surface ONLY in the cockpit.
 */
class StagedActionNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(): array
    {
        $client = Client::factory()->create(['name' => 'Acme Co']);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Mailbox full']);

        return compact('client', 'ticket');
    }

    private function stage(array $fixture, string $actionType = 'stage_email', ?string $content = null): TechnicianRun
    {
        return TechnicianRun::create([
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'action_type' => $actionType,
            'content_hash' => bin2hex(random_bytes(8)),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => $content ?? "Hi Alex,\n\nI have cleared 2GB from your mailbox.\n\nReason: user reported they cannot send.",
            'proposed_meta' => ['drafted_by' => 'chet', 'reasons' => ['user reported they cannot send']],
            'confidence' => null,
            'tokens_used' => 0,
        ]);
    }

    public function test_staging_an_action_queues_one_approval_notification(): void
    {
        Bus::fake();
        $fixture = $this->fixture();

        $run = $this->stage($fixture);

        Bus::assertDispatched(
            NotifyStagedActionAwaitingApproval::class,
            fn (NotifyStagedActionAwaitingApproval $job) => $job->runId === $run->id,
        );
    }

    /**
     * The choke point must be the STATE TRANSITION, not any particular call site —
     * that is the whole reason this is an observer.
     */
    public function test_a_run_that_never_awaits_approval_does_not_notify(): void
    {
        Bus::fake();
        $fixture = $this->fixture();

        TechnicianRun::create([
            'ticket_id' => $fixture['ticket']->id,
            'client_id' => $fixture['client']->id,
            'action_type' => 'stage_email',
            'content_hash' => bin2hex(random_bytes(8)),
            'state' => TechnicianRunState::Done,
            'proposed_content' => 'already handled',
            'proposed_meta' => [],
            'confidence' => null,
            'tokens_used' => 0,
        ]);

        Bus::assertNotDispatched(NotifyStagedActionAwaitingApproval::class);
    }

    public function test_approving_or_finishing_a_run_does_not_notify_again(): void
    {
        $fixture = $this->fixture();
        $run = $this->stage($fixture);

        // Fake only AFTER staging, so we observe just the later transitions.
        Bus::fake();
        $run->update(['state' => TechnicianRunState::Done->value]);

        Bus::assertNotDispatched(NotifyStagedActionAwaitingApproval::class);
    }

    /**
     * CO-21 — the dispatch MUST be afterCommit, so a stage that rolls back never
     * notifies and the worker never sees a row that does not exist.
     *
     * This asserts the mechanism STRUCTURALLY rather than by behaviour, and that is a
     * deliberate, stated limitation rather than laziness. Two ways to test it
     * behaviourally were tried and both LIE:
     *   - Bus::fake() intercepts the dispatch before the afterCommit machinery runs, so
     *     it records a job for a rolled-back stage — it would assert the opposite of
     *     the truth while looking green.
     *   - Driving the real database queue does not work either, because RefreshDatabase
     *     already wraps every test in a transaction, so an inner DB::transaction() is a
     *     savepoint and the outermost commit that afterCommit waits on never happens.
     * A test that cannot faithfully reproduce the condition should say so, not fake a
     * pass. The real behaviour is exercised by the framework's own afterCommit tests;
     * what is ours to guarantee is that we asked for it.
     */
    public function test_the_notification_is_dispatched_after_commit(): void
    {
        $observer = (string) file_get_contents(app_path('Observers/TechnicianRunObserver.php'));

        $this->assertStringContainsString('->afterCommit()', $observer,
            'the staged-action notification must be dispatched afterCommit so a rolled-back stage never notifies');
        $this->assertStringContainsString('NotifyStagedActionAwaitingApproval::dispatch', $observer);
    }

    // ── the content bar ───────────────────────────────────────────────────────

    /** @return array{subject: string, body: string} */
    private function captureNotification(TechnicianRun $run): array
    {
        $captured = ['subject' => '', 'body' => ''];

        $notifier = Mockery::mock(OperatorNotifier::class);
        $notifier->shouldReceive('notify')->once()
            ->andReturnUsing(function (string $subject, string $body) use (&$captured) {
                $captured = ['subject' => $subject, 'body' => $body];
            });
        $this->app->instance(OperatorNotifier::class, $notifier);

        (new NotifyStagedActionAwaitingApproval($run->id))->handle(app(OperatorNotifier::class));

        return $captured;
    }

    public function test_the_email_is_decidable_without_opening_the_cockpit(): void
    {
        $fixture = $this->fixture();
        $run = $this->stage($fixture);

        $captured = $this->captureNotification($run);
        $all = $captured['subject']."\n".$captured['body'];

        // Client, ticket and what is being proposed — enough to decide from a phone.
        $this->assertStringContainsString('Acme Co', $all, 'the client must be named');
        $this->assertStringContainsString((string) $fixture['ticket']->id, $all, 'the ticket must be identified');
        $this->assertStringContainsString('Mailbox full', $all, 'the ticket subject gives the context');

        // THE POINT: the actual proposed content, not a "tap to view" teaser.
        $this->assertStringContainsString('I have cleared 2GB from your mailbox', $captured['body']);

        // The deep-link is the FALLBACK for the full view, so it is present but is not
        // what the operator has to rely on.
        $this->assertStringContainsString('/cockpit', $captured['body']);
    }

    /**
     * THE HARD BOUNDARY. 'Include the payload so it is decidable from the lockscreen'
     * and 'never email a credential' pull in opposite directions. A staged password
     * reset carries no password (none exists until approval), and the notification must
     * never grow into carrying one.
     */
    public function test_a_staged_password_reset_notification_carries_no_credential(): void
    {
        $fixture = $this->fixture();
        $run = $this->stage(
            $fixture,
            'cipp_stage_reset_user_password',
            'Reset the Microsoft 365 password for alex@acme.example (must change at next sign-in: yes).',
        );
        // Simulate a meta payload that must never be echoed wholesale into an email.
        $run->update(['proposed_meta' => array_merge($run->proposed_meta ?? [], [
            'encrypted_payload' => 'BASE64-LOOKING-SECRET-BLOB',
        ])]);

        $captured = $this->captureNotification($run->refresh());
        $all = $captured['subject']."\n".$captured['body'];

        $this->assertStringContainsString('alex@acme.example', $all, 'the operator still needs to know who');
        $this->assertStringNotContainsString('BASE64-LOOKING-SECRET-BLOB', $all, 'never echo the held payload');
        $this->assertStringNotContainsString('encrypted_payload', $all);
    }

    public function test_a_vanished_run_is_a_no_op_rather_than_a_failed_job(): void
    {
        $notifier = Mockery::mock(OperatorNotifier::class);
        $notifier->shouldNotReceive('notify');
        $this->app->instance(OperatorNotifier::class, $notifier);

        // Deleted between staging and the queue worker picking it up.
        (new NotifyStagedActionAwaitingApproval(999999))->handle(app(OperatorNotifier::class));

        $this->assertTrue(true);
    }
}
