<?php

namespace App\Jobs;

use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Models\Email;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\EmailService;
use App\Services\NotificationService;
use App\Support\TechnicianConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * #5394/#5457/#5549/#5558: the one retry of a new ticket's failed message read, queued after the
 * creating transaction commits, so the Graph webhook's 202 and the creating request never wait
 * on it. EmailService::retryMessageRead does the read and the locked re-check (#5447/#5460).
 *
 * Budget. $timeout and $tries are this job's own, never the worker's (prod runs
 * `queue:work --tries=2 --timeout=30`): one attempt, killed after 20 seconds. The retry is one
 * Graph message read (the expand read carries the file attachments' bytes) plus one $value read
 * per attached Outlook item; a 429 backoff can still outrun 20 seconds, and that kill is final
 * (failed() below). A refusal whose cause can clear (#5802, EmailService::refusalCanClear) is a
 * separate budget: it is re-queued once as a fresh, delayed
 * dispatch carrying $refusals + 1, which starts its own single attempt. So a timeout never
 * spends the refusal re-queue, and a refusal never spends a worker try.
 *
 * The re-queued run checks against the SAME creation-time baseline, so it never writes over an
 * edit made since (#5447/#5460): it recovers a refusal whose cause has cleared, and a lasting
 * edit stays refused. A final outcome that links nothing (refused twice, a failed read, a
 * throw or a timeout before the link committed) writes a durable marker: one status-only WARNING
 * and a private System note on the ticket naming the email id and the reason (#5558).
 *
 * #5453/#5549: the email-added commit work (notifyEmailAdded and the RunTechnicianLoop
 * dispatch) is this job's, run once the retry has finished, on every final path, timeout
 * included, so it sees what the retry linked and is not lost when the read is killed. The
 * re-queue, the marker and the commit work each run with SIGALRM held back together with the
 * flag that records them, so a timeout lands before or after one, never inside it.
 */
class RetryEmailAttachments implements ShouldQueue
{
    use Queueable;

    /** One attempt per dispatch; never the worker's --tries. */
    public int $tries = 1;

    /** Well inside the worker's 30s, never the worker's --timeout. */
    public int $timeout = 20;

    public bool $failOnTimeout = true;

    /** Refusals re-queued at most this many times, by this job's own counter (#5558). */
    public const MAX_REFUSAL_REQUEUES = 1;

    /** Seconds before the re-queued run, so a transient cause can clear. */
    public const REQUEUE_DELAY_SECONDS = 60;

    public const MARKER = '[RetryEmailAttachments] Email attachments not added';

    public const EARLIER_UNDISCARDED = '[RetryEmailAttachments] Attachments an earlier run stored left undiscarded';

    /** #5801: the marker when whether the retry's link committed could not be read back. */
    public const MARKER_LINK_UNKNOWN = '[RetryEmailAttachments] Email attachments link state unknown';

    /** #5811: one step of the email-added commit work threw; 'step' names which. */
    public const COMMIT_STEP_THREW = '[RetryEmailAttachments] Email-added commit work step threw';

    /**
     * #5810/#5994: the after-commit push of this job threw. Context queued_row says what the
     * read-back by push_id found: false (no row of this dispatch in jobs or failed_jobs), null
     * (not read back). ERROR (#5993). The commit work then runs inline; it runs a second time
     * when a row was written after all: with null, or (#6026) with false when a worker had
     * already run and deleted the row before the read-back (see queueAfterCommit()).
     */
    public const NOT_QUEUED = '[RetryEmailAttachments] Retry push threw';

    /**
     * #5994: the push threw, and the read-back found this dispatch's row; the job owns the rest.
     * Context found_in names the table: jobs, or (#6026) failed_jobs, where failed() ran.
     */
    public const PUSH_THREW_ROW_FOUND = '[RetryEmailAttachments] Retry push threw; its queue row was found';

    /** #5997/#6043: failed()'s rollback or re-delete of the queue row threw; 'step' names which. */
    public const TIMEOUT_STEP_THREW = '[RetryEmailAttachments] Timeout rollback or re-delete step threw';

    /** #5811: the commit work found no ticket or no email row and ran nothing. */
    public const COMMIT_SKIPPED = '[RetryEmailAttachments] Email-added commit work skipped';

    /**
     * @param  array<string, mixed>|null  $baseline  EmailService::retryBaseline as the creating transaction left it
     */
    /**
     * #5803: the attachment ids earlier runs of this retry left undiscarded, carried through a
     * re-queue so the final run reports them. A plain property with a default, so a payload
     * queued before it existed unserialises with [].
     *
     * @var list<int>
     */
    public array $earlierUndiscarded = [];

    /**
     * #5994: a uuid set by queueAfterCommit() just before its push, carried in the queued
     * payload, so a push that throws can be read back by it. Null on any other dispatch.
     */
    public ?string $pushId = null;

    /** @param  list<int>  $earlierUndiscarded */
    public function __construct(
        public readonly int $emailId,
        public readonly int $ticketId,
        public readonly ?int $noteId,
        public readonly ?array $baseline,
        public readonly int $refusals = 0,
        array $earlierUndiscarded = [],
    ) {
        $this->earlierUndiscarded = $earlierUndiscarded;
    }

    /**
     * $ids with the earlier runs' undiscarded ids (#5803); null (not known, #5799) stays null,
     * and writeMarker then records the earlier runs' ids on their own.
     *
     * @param  list<int>|null  $ids
     * @return list<int>|null
     */
    private function withEarlier(?array $ids): ?array
    {
        if ($ids === null) {
            return null;
        }
        $all = array_values(array_unique(array_merge($this->earlierUndiscarded, $ids)));
        sort($all);

        return $all;
    }

    /**
     * What handle() has reached for each dispatch running in this process, by progressKey(): the
     * retry's outcome once it returned, then requeued, marked and committed. A timeout kill runs
     * failed() in this process, on a fresh copy of the job, while handle() is still on the stack
     * (the worker's SIGALRM handler), so failed() reads this and runs only what handle() had not
     * started: the marker and the commit work run exactly once, and an outcome handle() already
     * had is not reported as a timeout. Each flag is set together with its step under
     * uninterrupted(), so a flag that is set means its step ran, or is the step a kill cut short.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $progress = [];

    /**
     * The progressKey()s of the dispatches queueAfterCommit() is pushing (#6038: a set, so a push
     * nested inside another, for another key, leaves the outer key in place). Set before the
     * push; removed by handle() or failed() of that dispatch when the sync queue runs it inside
     * the push, and by queueAfterCommit() itself when the push throws and when it returns (#6041).
     *
     * @var array<string, true>
     */
    private static array $pushing = [];

    /**
     * #5457/#5810: queues this job once the outermost transaction commits (nothing on a
     * rollback). The push's throw is not rethrown, so the import is not reported as failed. A
     * throw from a run of the job itself (the sync queue runs it inside the push) is rethrown
     * unchanged: that run's failed() has already reported it, and it clears the push key so it
     * is not reported again here (#6004).
     *
     * #5994: a throw means only that the push threw; the row may still have been written (in
     * this app, a JobQueued listener that throws; #6027: a lost reply to the INSERT is not this
     * shape, since at level 0 the connection re-runs the INSERT and the push returns, leaving two
     * rows and no read-back). So this dispatch is read back by $pushId first (queuedRowFound()):
     * its row in the queue table, its row in failed_jobs (#6026: failed() already ran), or the
     * started marker its handle() writes (#6026: a worker ran it and deleted the row). Found:
     * PUSH_THREW_ROW_FOUND with found_in, and nothing more runs here, since the job owns the
     * marker and the commit work. Not found (queued_row false), or not read back (queued_row
     * null: another driver, or a read-back step threw): NOT_QUEUED at ERROR (#5993: the record a
     * monitor filtering at error sees) and notQueued().
     *
     * Which arms may run the commit work twice: queued_row null, when a row was written after
     * all; and queued_row false when the row was consumed but its started marker is missing (the
     * cache write in handle() threw, the entry expired before the read-back, or the cache store
     * is not one the workers share, such as the array store). With null the
     * note says the attachments may not have been added (#6035), since the queued run may add them.
     *
     * #6007: on that path the marker and the commit work run inside this callback, so in the
     * webhook they run before its 202 is sent.
     */
    public function queueAfterCommit(): void
    {
        \Illuminate\Support\Facades\DB::afterCommit(function (): void {
            $key = $this->progressKey();
            self::$pushing[$key] = true;
            $this->pushId = (string) \Illuminate\Support\Str::uuid();
            try {
                dispatch($this);
            } catch (\Throwable $e) {
                if (! isset(self::$pushing[$key])) {
                    throw $e;
                }
                unset(self::$pushing[$key]);
                $found = $this->queuedRowFound();
                try {
                    $context = ['email_id' => $this->emailId, 'ticket_id' => $this->ticketId, 'exception' => $e::class];
                    if (is_string($found)) {
                        Log::warning(self::PUSH_THREW_ROW_FOUND, $context + ['found_in' => $found]);
                    } else {
                        Log::error(self::NOT_QUEUED, $context + ['queued_row' => $found]);
                    }
                } catch (\Throwable) {
                }
                if (! is_string($found)) {
                    $this->notQueued($found === null ? null : false);
                }
            } finally {
                unset(self::$pushing[$key]);
            }
        });
    }

    /** #6026: the cache key handle() sets when a dispatch read back by $pushId starts. */
    private static function startedKey(string $pushId): string
    {
        return 'retry-email-attachments:started:'.$pushId;
    }

    /**
     * #5994: where this dispatch was found, read back by $pushId: 'jobs' (its queue row),
     * 'failed_jobs' (#6026: failed and recorded) or 'started' (#6026: its handle() ran, and the
     * row may be gone). False: none of them. Null when it cannot be told: not a database queue,
     * or a step threw before anything was found.
     *
     * #6025/#6034: a row counts only when it is this job's own: its displayName is this class and
     * its command carries this pushId as its own property, so a row that embeds this command
     * (another job's payload) or merely mentions the uuid does not. #6037: read on the write PDO,
     * so a read replica cannot miss the row just inserted. #6031: narrowed to this dispatch's
     * queue (an indexed column); the payload match itself is still a LIKE scan of that queue.
     */
    private function queuedRowFound(): string|bool|null
    {
        try {
            $config = config('queue.connections.'.($this->connection ?? config('queue.default')));
            if (($config['driver'] ?? null) !== 'database' || $this->pushId === null) {
                return null;
            }
            $rows = \Illuminate\Support\Facades\DB::connection($config['connection'] ?? null)
                ->table($config['table'] ?? 'jobs')->useWritePdo()
                ->where('queue', $this->queue ?? $config['queue'] ?? 'default')
                ->where('payload', 'like', '%'.$this->pushId.'%')
                ->pluck('payload');
            if ($this->ownRowIn($rows)) {
                return 'jobs';
            }

            $failed = config('queue.failed');
            if (in_array($failed['driver'] ?? null, ['database', 'database-uuids'], true)) {
                $rows = \Illuminate\Support\Facades\DB::connection($failed['database'] ?? null)
                    ->table($failed['table'] ?? 'failed_jobs')->useWritePdo()
                    ->where('payload', 'like', '%'.$this->pushId.'%')
                    ->pluck('payload');
                if ($this->ownRowIn($rows)) {
                    return 'failed_jobs';
                }
            }

            return \Illuminate\Support\Facades\Cache::has(self::startedKey($this->pushId)) ? 'started' : false;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether one of $payloads is this dispatch's own: a job of this class whose command, itself
     * this class, carries this pushId as its own property. Only this class is unserialised.
     *
     * @param  iterable<string>  $payloads
     */
    private function ownRowIn(iterable $payloads): bool
    {
        foreach ($payloads as $payload) {
            $decoded = json_decode((string) $payload, true);
            $command = $decoded['data']['command'] ?? null;
            if (($decoded['displayName'] ?? null) !== self::class || ! is_string($command)
                || ! str_starts_with($command, 'O:'.strlen(self::class).':"'.self::class.'"')) {
                continue;
            }
            $job = @unserialize($command, ['allowed_classes' => [self::class]]);
            if ($job instanceof self && $job->pushId === $this->pushId) {
                return true;
            }
        }

        return false;
    }

    public function handle(EmailService $emails): void
    {
        $key = $this->progressKey();
        unset(self::$pushing[$key]);
        if ($this->pushId !== null) {
            // #6026: what queueAfterCommit()'s read-back finds once a worker has run this
            // dispatch and deleted its row; the read-back follows the push at once, so the entry
            // is short-lived. Best effort: a miss here is the false arm's remainder.
            try {
                \Illuminate\Support\Facades\Cache::put(self::startedKey($this->pushId), true, now()->addMinutes(10));
            } catch (\Throwable) {
            }
        }
        // #5800: the level failed() rolls back to, whatever queue.failed says.
        self::$progress[$key] = ['level' => \Illuminate\Support\Facades\DB::transactionLevel()];
        $threw = false;
        try {
            $outcome = $emails->retryMessageRead($this->emailId, $this->ticketId, $this->noteId, $this->baseline);
            if (self::$progress[$key]['failed'] ?? false) {
                // #5808: failed() already ran for this dispatch in this process. The worker exits
                // right after it on a timeout; should this run go on regardless, failed() owns
                // the outcome, and nothing more is written, re-queued or notified here.
                return;
            }
            self::$progress[$key]['outcome'] = $outcome;

            if ($outcome !== null && $outcome['requeueable'] && $this->refusals < self::MAX_REFUSAL_REQUEUES) {
                self::uninterrupted(function () use ($key, $outcome): void {
                    // #5807: a payload queued before #5807 carries retryState's raw rows; the re-queue
                    // carries their fingerprint, which compares exactly as they did, never the rows.
                    self::dispatch($this->emailId, $this->ticketId, $this->noteId, EmailService::retryFingerprint($this->baseline), $this->refusals + 1,
                        $this->withEarlier($outcome['undiscarded_attachment_ids']))
                        ->delay(self::REQUEUE_DELAY_SECONDS);
                    self::$progress[$key]['requeued'] = true;
                });

                return;
            }

            if ($outcome !== null) {
                self::uninterrupted(function () use ($key, $outcome): void {
                    self::$progress[$key]['marked'] = true;
                    $this->writeMarker($outcome['reason'], $this->withEarlier($outcome['undiscarded_attachment_ids']), self::linkedOf($outcome));
                });
            } elseif ($this->earlierUndiscarded !== []) {
                self::uninterrupted(function () use ($key): void {
                    self::$progress[$key]['marked'] = true;
                    $this->warnEarlierUndiscarded();
                });
            }
            self::uninterrupted(function () use ($key): void {
                self::$progress[$key]['committed'] = true;
                $this->runCommitWork();
            });
        } catch (\Throwable $e) {
            // #5820: kept for the failed() the worker calls next in this process, so an outcome
            // handle() already had (a refusal whose re-queue dispatch threw) is the one reported.
            $threw = true;

            throw $e;
        } finally {
            if (! $threw) {
                unset(self::$progress[$key]);
            }
        }
    }

    /**
     * The job failed: a timeout kill, a throw out of handle(), or a failure the worker records in
     * another process (#5799: a job whose worker died is failed by the next worker that reserves
     * it, as MaxAttemptsExceededException). Final, never re-queued. On a timeout the worker calls
     * this in the killed process. The framework rolls an open transaction back first only when
     * queue.failed names a database failer on this connection (Job::fail); #5800/#6040: when it
     * did not, so a timeout finds the connection still above the level handle() started at, it
     * rolls back here to that level before anything is read or written, and deletes the queue
     * row again, since the framework's delete of it was inside that transaction. Under the
     * shipped database-uuids failer neither runs here. When the handle() of this dispatch already had the retry's outcome (it is still
     * on the stack under a kill, or it threw after the outcome, #5820), that outcome is the one
     * reported: a re-queued run owns what follows; otherwise only the marker and the commit work
     * handle() had not started are run. When the kill landed inside the retry, abandonRetry
     * discards what the read stored unless the link committed, and the marker is written unless
     * the link is known to have committed. When the failure is not a timeout and no handle() of
     * this dispatch had the retry's outcome in this process (it ran in another, or threw before
     * the outcome), what its retry stored is not known here: the marker's
     * undiscarded_attachment_ids is null, not [].
     */
    public function failed(?\Throwable $e): void
    {
        $key = $this->progressKey();
        // #6004: when the sync queue failed this run inside the push, before handle() ran (a
        // JobProcessing listener, or resolving handle()'s arguments), it is reported here, so
        // queueAfterCommit() rethrows the throw rather than reporting it as not queued.
        unset(self::$pushing[$key]);
        $here = array_key_exists($key, self::$progress);
        $progress = self::$progress[$key] ?? [];
        $timedOut = $e instanceof \Illuminate\Queue\TimeoutExceededException;
        if ($here && $timedOut && \Illuminate\Support\Facades\DB::transactionLevel() > $progress['level']) {
            // #5998/#6039: reached when this connection is still above handle()'s level after
            // Job::fail. Job::fail rolls back to level 0 only for a timeout, and only on the
            // connection queue.failed.database names, when queue.failed.driver is database or
            // database-uuids; so this runs with no database failer ('null' in the b4n queue:work
            // kill evidence), with queue.failed.database null, or naming another connection.
            // Under the shipped database-uuids failer on the job's connection Job::fail has
            // already rolled back to level 0 and this branch is skipped
            // (RetryEmailAttachmentsFailerTest).
            $step = 'rollback';
            try {
                \Illuminate\Support\Facades\DB::rollBack($progress['level']);
                // Job::fail deleted the queue row inside the transaction just rolled back;
                // without this the row comes back and a later worker fails it a second time, as
                // MaxAttemptsExceeded, never re-running handle() (#5996): a second marker and
                // commit work, measured with a real queue:work kill under the 'null' failer.
                $step = 'requeue_delete';
                $this->job?->delete();
            } catch (\Throwable $stepFailure) {
                // #5997: the re-delete did not run or did not finish, so the queue row may come
                // back. C-56: ids, the step and the exception class only.
                try {
                    Log::warning(self::TIMEOUT_STEP_THREW, [
                        'email_id' => $this->emailId,
                        'ticket_id' => $this->ticketId,
                        'step' => $step,
                        'exception' => $stepFailure::class,
                    ]);
                } catch (\Throwable) {
                }
            }
        }
        if ($here) {
            self::$progress[$key]['failed'] = true;
        }
        if (! $timedOut) {
            // A throw: handle() kept its progress for this call only (#5820).
            unset(self::$progress[$key]);
        }
        if (array_key_exists('outcome', $progress)) {
            if ($progress['requeued'] ?? false) {
                return;
            }
            $outcome = $progress['outcome'];
            if ($outcome !== null && ! ($progress['marked'] ?? false)) {
                $this->writeMarker($outcome['reason'], $this->withEarlier($outcome['undiscarded_attachment_ids']), self::linkedOf($outcome));
            } elseif ($outcome === null && $this->earlierUndiscarded !== [] && ! ($progress['marked'] ?? false)) {
                $this->warnEarlierUndiscarded();
            }
            if (! ($progress['committed'] ?? false)) {
                $this->runCommitWork();
            }

            return;
        }

        $discard = ['linked' => false, 'undiscarded_attachment_ids' => []];
        if (! $timedOut) {
            // #5799: not this process's run, or (#5820) a handle() here that threw before it had
            // the retry's outcome; what that retry stored is not tracked any more.
            $discard['undiscarded_attachment_ids'] = null;
        } else {
            try {
                $discard = app(EmailService::class)->abandonRetry($this->noteId);
            } catch (\Throwable) {
            }
        }
        if ($discard['linked'] !== true) {
            $this->writeMarker($timedOut ? 'timed_out' : 'job_failed', $this->withEarlier($discard['undiscarded_attachment_ids']), $discard['linked']);
        } elseif ($this->earlierUndiscarded !== []) {
            $this->warnEarlierUndiscarded();
        }
        $this->runCommitWork();
    }

    /**
     * Runs $step with SIGALRM held back, so the worker's timeout handler (failed()) runs before or
     * after it, never inside: one already pending is handled before the step, one that arrives
     * during it once the mask is restored. Without pcntl the worker installs no timeout handler.
     */
    private static function uninterrupted(\Closure $step): void
    {
        $old = [];
        $masked = function_exists('pcntl_sigprocmask') && pcntl_sigprocmask(SIG_BLOCK, [SIGALRM], $old);
        try {
            $step();
        } finally {
            if ($masked) {
                pcntl_sigprocmask(SIG_SETMASK, $old);
            }
        }
    }

    private function progressKey(): string
    {
        return "{$this->emailId}:{$this->ticketId}:{$this->refusals}";
    }

    /** #5801: null when the retry's outcome says its link state could not be read back. */
    private static function linkedOf(array $outcome): ?bool
    {
        return $outcome['reason'] === EmailService::RETRY_LINK_UNKNOWN ? null : false;
    }

    /**
     * #5810/#5994: this retry's push threw after the creating transaction committed, and no
     * queue row for it was found (or none could be read back; see queueAfterCommit()). Nothing
     * was read or stored by a retry here, so the marker names no ids. #6035: $linked null (not
     * read back) writes the link-unknown marker, since a row written after all may still link
     * the attachments; false says they were not added. The commit work the job would have owned
     * runs here instead. #5995/#6030: the notification runs here; the technician loop is only
     * dispatched (afterCommit), onto the queue the technician job names. When that dispatch
     * throws (as it does while the same database queue's inserts are failing) the loop is lost
     * and recorded as COMMIT_STEP_THREW (technician_dispatch); when the push threw for another
     * reason, it may be queued. Never throws.
     */
    public function notQueued(?bool $linked = false): void
    {
        $this->writeMarker('not_queued', [], $linked);
        $this->runCommitWork();
    }

    /**
     * #5803: an earlier run left rows undiscarded, and the final run linked or its marker carries
     * null (its own ids not known in this process, #5799). C-56: ids only.
     */
    private function warnEarlierUndiscarded(): void
    {
        try {
            Log::warning(self::EARLIER_UNDISCARDED, [
                'email_id' => $this->emailId,
                'ticket_id' => $this->ticketId,
                'refusals_requeued' => $this->refusals,
                'undiscarded_attachment_ids' => $this->earlierUndiscarded,
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * @param  list<int>|null  $undiscarded  null when not known in this process (#5799); the
     *                                       earlier runs' ids (#5803), known from the payload, then
     *                                       go in their own EARLIER_UNDISCARDED record
     */
    private function writeMarker(string $reason, ?array $undiscarded, ?bool $linked = false): void
    {
        if ($undiscarded === null && $this->earlierUndiscarded !== []) {
            $this->warnEarlierUndiscarded();
        }
        // #5801: linked null means whether the link committed could not be read back; the rows
        // were kept, so the record says the state is unknown rather than "not added".
        $body = $linked === null
            ? "Attachments from email #{$this->emailId} may not have been added to this ticket: whether they were linked could not be read back (reason: {$reason})."
            : "Attachments from email #{$this->emailId} were not added to this ticket automatically (reason: {$reason}).";
        $noted = false;
        try {
            if (Ticket::whereKey($this->ticketId)->exists()) {
                TicketNote::create([
                    'ticket_id' => $this->ticketId,
                    'author_id' => null,
                    'author_name' => 'System',
                    'who_type' => WhoType::System,
                    'body' => $body,
                    'note_type' => NoteType::System,
                    'is_private' => true,
                    'noted_at' => now(),
                ]);
                $noted = true;
            }
        } catch (\Throwable) {
            // The WARNING below still records the outcome; noted says the note is missing.
        }

        // C-56: ids, the reason, the re-queue count and whether the note was written only.
        try {
            Log::warning($linked === null ? self::MARKER_LINK_UNKNOWN : self::MARKER, [
                'email_id' => $this->emailId,
                'ticket_id' => $this->ticketId,
                'reason' => $reason,
                'refusals_requeued' => $this->refusals,
                'ticket_note_written' => $noted,
                'undiscarded_attachment_ids' => $undiscarded,
            ]);
        } catch (\Throwable) {
            // The note, when written, is the durable half of the marker.
        }
    }

    /**
     * The technician loop dispatch, then notifyEmailAdded, as linkEmailToTicket registers them.
     * Never throws: a throw here would fail the job and run failed(), which would run it again.
     * #5811: each step is tried on its own, and a throw is recorded under the step that threw
     * (lookup, technician_dispatch, notification); a missing ticket or email row is recorded
     * too. #5816: the dispatch is afterCommit, as linkEmailToTicket's is, so a loop is never
     * queued for writes a still-open transaction could roll back.
     */
    private function runCommitWork(): void
    {
        try {
            $ticket = Ticket::find($this->ticketId);
            $email = Email::find($this->emailId);
        } catch (\Throwable $e) {
            $this->warnCommitStep('lookup', $e);

            return;
        }
        if ($ticket === null || $email === null) {
            try {
                Log::warning(self::COMMIT_SKIPPED, [
                    'email_id' => $this->emailId,
                    'ticket_id' => $this->ticketId,
                    'ticket_found' => $ticket !== null,
                    'email_found' => $email !== null,
                ]);
            } catch (\Throwable) {
            }

            return;
        }
        try {
            if (TechnicianConfig::enabled() && ! $ticket->isUnverifiedContactIntake()) {
                RunTechnicianLoop::dispatch($ticket->id)->afterCommit();
            }
        } catch (\Throwable $e) {
            $this->warnCommitStep('technician_dispatch', $e);
        }
        try {
            app(NotificationService::class)->notifyEmailAdded($ticket, $email);
        } catch (\Throwable $e) {
            $this->warnCommitStep('notification', $e);
        }
    }

    /** #5811 / C-56: ids, the step that threw and the exception class only. */
    private function warnCommitStep(string $step, \Throwable $e): void
    {
        try {
            Log::warning(self::COMMIT_STEP_THREW, [
                'email_id' => $this->emailId,
                'ticket_id' => $this->ticketId,
                'step' => $step,
                'exception' => $e::class,
            ]);
        } catch (\Throwable) {
        }
    }
}
