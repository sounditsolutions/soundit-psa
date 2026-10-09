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

    /**
     * #6282: how long the kept entry (see keptKey()) lives: seven days (r2 of #6304: one day is
     * inside a plausible weekend or incident outage). No configuration bounds how long a row
     * can wait in the queue (a backlog, stopped workers), so this is a fixed bound, not
     * derived: far above the queue's retry_after (90 seconds by default) and the 10 minutes of
     * the pending and started entries. The expiry arm: a row of the push still queued after
     * KEPT_TTL_SECONDS runs without checking for a later row (neither entry is held), so when
     * an earlier row of the push is popped after that (#6355: one popped before it checked and
     * skipped), the earlier and the later row both run, and nothing is recorded (a missing
     * entry is not a failed read). The TTL bounds how long the entry is held, not how long its file stays
     * on CACHE_STORE=file: that store deletes an expired file only when the key is read again,
     * and no run reads the key of a push whose rows have all gone, so its file stays.
     */
    public const KEPT_TTL_SECONDS = 604800;

    public const MARKER = '[RetryEmailAttachments] Email attachments not added';

    public const EARLIER_UNDISCARDED = '[RetryEmailAttachments] Attachments an earlier run stored left undiscarded';

    /**
     * #5801: the marker when whether the retry's link committed could not be read back. #6136:
     * since b7 (#6101) the not_queued null arm writes MARKER_QUEUE_UNKNOWN instead, so a monitor
     * or saved search on this literal with reason not_queued must match that one too (no such
     * consumer was found when b7 landed; the change is recorded for any added later).
     */
    public const MARKER_LINK_UNKNOWN = '[RetryEmailAttachments] Email attachments link state unknown';

    /** #5811: one step of the email-added commit work threw; 'step' names which. */
    public const COMMIT_STEP_THREW = '[RetryEmailAttachments] Email-added commit work step threw';

    /**
     * #5810/#5994: the after-commit push of this job threw. Context queued_row says what the
     * read-back by push_id found (#6097): false when neither table's read window held this
     * dispatch's row and no started entry was found, with the pending entry recorded; null when
     * it could not be told, and then read_back_step names the first read that failed (driver:
     * not a database queue, or (#6184) reading the queue configuration threw; jobs or
     * failed_jobs: that table's read, or (#6150) the read of its floor before the push, threw;
     * started: the entry read threw; pending: the pending entry was not recorded, so a run could
     * not have left its started entry) and read_back_exception the class that threw, or null
     * (not a database queue, pending, or a floor read whose class was not kept). #6180:
     * read_back_failures maps every read that failed (driver, jobs, failed_jobs, started) to
     * its class, not only the first. #6149: a failed read no longer stops the read-back; the
     * reads after it still run, and one that finds this dispatch wins. ERROR (#5993). The marker and the commit work then run here
     * (see queueAfterCommit() for when, and for the arms on which they run a second time).
     */
    public const NOT_QUEUED = '[RetryEmailAttachments] Retry push threw';

    /**
     * #5994: the push threw, and the read-back found this dispatch; the job owns the rest.
     * Context found_in names where: jobs or (#6026) failed_jobs (its row), or (#6090) started,
     * where no row was found and the started entry its handle() or failed() writes was. Was
     * '... Retry push threw; its queue row was found' before b7 (#6090): a monitor matching the
     * old literal must be updated.
     */
    public const PUSH_THREW_ROW_FOUND = '[RetryEmailAttachments] Retry push threw; this dispatch was found';

    /**
     * #5997/#6043: failed()'s rollback or re-delete of the queue row threw; 'step' names which.
     * #6098: was '[RetryEmailAttachments] Timeout rollback step threw' before b6 (#6064); a log
     * monitor or saved search matching that literal must be updated to this one.
     */
    public const TIMEOUT_STEP_THREW = '[RetryEmailAttachments] Timeout rollback or re-delete step threw';

    /**
     * #5811/#6008: the commit work found no ticket row, a soft-deleted ticket, or no email row,
     * and ran nothing. Context ticket_found says whether the tickets row exists, ticket_trashed
     * whether it is soft-deleted.
     */
    public const COMMIT_SKIPPED = '[RetryEmailAttachments] Email-added commit work skipped';

    /**
     * #6000: the technician loop dispatch the commit work staged in an open transaction was
     * discarded by that transaction's rollback; 'step' is technician_dispatch.
     */
    public const COMMIT_STEP_ROLLED_BACK = '[RetryEmailAttachments] Email-added commit work step rolled back';

    /**
     * #6092/#6094: a step on the pending or started cache entry the push read-back uses failed
     * (threw, or the store returned false). Context step: pending_put, started_put, cleanup,
     * (#6153) pending_read, a run's read of the pending entry, after which it writes its started
     * entry anyway; (#6183) kept_put, the kept entry written after the push (see
     * dropDuplicateRows()) or after a push that threw found its row in the queue table (r2
     * diff:1), after which a run of the push checks for a later row only while the pending
     * entry is held (during the push, or after it when the cleanup could not remove it), and
     * not once it is gone; or (r2 diff:7) check_read, a run's read of the pending or
     * kept entry before its check for a later row, which then reads the rows as if one were
     * held; or (#6282) kept_forget, a finished run's removal of the kept entry (forgetKept(),
     * attempted only after that run's check read the kept entry as held (#6352), read the rows
     * and counted no later row (#6357)): the removal threw, or the store returned false and
     * the key is still held (or that read threw, #6353: then whether it is held is not known);
     * an entry left that way is held until KEPT_TTL_SECONDS; exception: the class that threw,
     * or null. A
     * failed pending_put makes a later not-found read-back report queued_row null, never false.
     */
    public const CACHE_STEP_FAILED = '[RetryEmailAttachments] Push read-back cache step failed';

    /**
     * #6100: a run of a dispatch read back by $pushId, while that push was in progress or
     * (#6183, #6275) after it returned while the kept entry was held (see keptKey()), found a
     * later queue row of the same push (a lost reply to the INSERT that the connection
     * re-ran) that is not reserved and has attempts left (#6182), and exited without running.
     * The later row is then the one left to run; it can still fail without running handle() (a
     * worker that reserves it and dies). Not reached (#6282, the expiry arm) by a run after the
     * push whose rows waited in the queue past KEPT_TTL_SECONDS, or that started after a run of
     * the push checked clear and removed the kept entry: neither entry is held, so it does not
     * check, both rows run, and nothing is recorded. Context job_id: this run's row;
     * later_job_ids: the later rows of this push.
     */
    public const DUPLICATE_ROW_SKIPPED = '[RetryEmailAttachments] Duplicate queue row of one push skipped';

    /**
     * #6100: a de-duplication read or delete threw. Context step: run (a run's check, handle(),
     * which then goes ahead, and nothing is skipped because of it, so a second row of the push,
     * if any, may run too) or (#6179) push (queueAfterCommit()'s pass after the push: the rows
     * not yet deleted are left, the kept entry is written so their runs check for a later row
     * while it is held (see keptKey(): it is removed by a run that checked clear, and expires
     * after KEPT_TTL_SECONDS) (#6278: the check cannot stop the latest row left when a run of
     * the push started before it was written), and the rows it had already deleted before
     * the throw, if any, follow as
     * DUPLICATE_ROWS_DROPPED); exception: the class that threw.
     */
    public const DUPLICATE_CHECK_FAILED = '[RetryEmailAttachments] Duplicate queue row check failed';

    /**
     * #6179: after a push that returned, queueAfterCommit() deleted unreserved queue rows of
     * that push (see dropDuplicateRows()). Context run_started: whether a run of the push had
     * started (then the pass deleted the rows it read as unreserved; otherwise, when it read
     * none as reserved, all but the latest); dropped_job_ids: the rows whose DELETE matched
     * one row. #6279: a DELETE that matched nothing (a worker reserved its row after the pass
     * read it, or the row was already gone) leaves that row out, so on either arm fewer rows
     * than that may be deleted and listed; after a DUPLICATE_CHECK_FAILED step push (a later DELETE
     * threw), these are the rows deleted before the throw. #6276: a DELETE that committed and
     * whose reply was lost, re-run by the connection (as the INSERT is, see
     * queueAfterCommit()), matches nothing on the re-run, so a row it did delete can be
     * missing from the list. Both kinds of row are listed by DUPLICATE_ROWS_NOT_DELETED (b11).
     */
    public const DUPLICATE_ROWS_DROPPED = '[RetryEmailAttachments] Duplicate queue rows of one push dropped';

    /**
     * b11 (#6273/#6276): the pass after the push (see dropDuplicateRows()) issued a DELETE for a
     * row it read as unreserved, and the DELETE matched nothing. Context run_started: as on
     * DUPLICATE_ROWS_DROPPED; unmatched_job_ids: those rows. Each one is either reserved by a
     * worker after the pass read it, so its run goes ahead (with run_started true, the retry then
     * runs a second time, #6273: that row is the latest, so its check finds nothing later), or
     * already gone: a DELETE that committed and whose reply was lost, re-run by the connection
     * (#6276), or a row a worker reserved and finished in between. The record cannot tell these
     * apart. Not written for a row the pass read as reserved (no DELETE is issued for it), nor
     * for a DELETE that threw (DUPLICATE_CHECK_FAILED step push).
     */
    public const DUPLICATE_ROWS_NOT_DELETED = '[RetryEmailAttachments] Duplicate queue rows of one push not deleted';

    /**
     * #6101: the not_queued marker when the read-back could not tell (queued_row null). #6135:
     * context queue_read_back says which unknown. #6181/#6177: true when the queue table was
     * read above its floor and held no row of this dispatch, so no queued run is left to add
     * them, but whether a run already ran (and may have added them) could not be read back:
     * failed_jobs, the started entry or the pending entry could not be read or was not
     * recorded (failed_jobs is not read at all under a failer that is not a database one).
     * False when the queue table itself was not read: its floor or its scan threw, the queue
     * configuration could not be read, or (read_back_step driver) the queue is not a database
     * one, so there is no table to read; a queued run may then still add them. The note says
     * the same. #6136: replaces MARKER_LINK_UNKNOWN on this arm since b7.
     */
    public const MARKER_QUEUE_UNKNOWN = '[RetryEmailAttachments] Email attachments retry queue state unknown';

    /**
     * #6150: the highest id of the queue table or of failed_jobs could not be read before the
     * push, so a read-back after a throw cannot read that table. Context step: jobs or
     * failed_jobs; exception: the class that threw. Recorded whether or not the push throws.
     * #6179/#6183: step jobs also means a push that returned had no pass after it
     * (dropDuplicateRows() cannot read above a floor), so it wrote the kept entry instead and
     * a run of the push after it checks for a later row while that entry is held, unless that
     * write failed (CACHE_STEP_FAILED kept_put, then no run after the push checks). The expiry
     * arm: a row that waits in the queue past KEPT_TTL_SECONDS runs without checking, so when
     * an earlier row of the push is popped after that, the earlier and the later row both run,
     * and nothing is recorded. A run that read the entry as held and checked clear removes it
     * (forgetKept()), and a run after that does not check. #6278: the check
     * cannot stop the later row when a run of the push started before that row was written
     * (see queueAfterCommit(), 'Both still run when').
     */
    public const FLOOR_READ_FAILED = '[RetryEmailAttachments] Push read-back floor read failed';

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
     * this app, a JobQueued listener that throws). #6100: a lost reply to the INSERT is handled
     * by the connection, not here: at level 0 the connection reconnects and re-runs the INSERT,
     * and when that succeeds the push returns with two rows for this dispatch. Both carry this
     * pushId. Two checks remove one: a run that starts while the push is still in progress exits
     * when it finds a later, unreserved row with attempts left (handle(), DUPLICATE_ROW_SKIPPED,
     * #6182); and once the push returns, the rows are read back here (#6179,
     * dropDuplicateRows(), DUPLICATE_ROWS_DROPPED): when a run has started (it popped the first
     * row before the re-run INSERT landed) the unreserved rows are deleted, otherwise, when no
     * row is reserved, all but the latest (#6274). Both still run when a worker reserves the
     * later row while the push is in progress and no run of the push has started; when a run that started inside the push
     * could not write its started entry (CACHE_STEP_FAILED started_put) and its row is gone;
     * when the pending entry was not recorded (CACHE_STEP_FAILED pending_put); when the pass
     * left two or more rows, or could not run or complete, or (r2 diff:1) the push threw after
     * its row was written (found_in jobs: no pass runs), and the kept entry was not recorded
     * (CACHE_STEP_FAILED kept_put), as no run after the push then checks; when a worker's run
     * of the earlier row checked before the later row was written and had not yet written its
     * started entry when the pass read the rows (the pass then keeps both, as before #6179);
     * or when a run's de-duplication read throws (DUPLICATE_CHECK_FAILED step run). #6272/
     * #6273/#6278: also, with nothing recorded unless noted, on these arms (the #6182 trade-off: a run's
     * check does not count a reserved later row, which may be a dead worker's, so a live
     * worker's hold on it is not counted either): after the push, when the pass left two or
     * more rows with the kept entry held and two workers reserve the earlier and the later row
     * before the earlier row's check reads; when a run of the push started first (its started
     * entry was read by the pass) and the later row was reserved before the pass read it, or
     * between that read and its DELETE (which then matches nothing; b11: that row is recorded,
     * DUPLICATE_ROWS_NOT_DELETED with run_started true), so the pass leaves it,
     * and its own check finds nothing later; and when a run of the push started before the
     * re-run INSERT wrote the later row and the pass did not complete (no jobs floor, a read
     * or a DELETE that threw), so the later row is left and the kept entry cannot stop it: it
     * is the latest row, and its check finds nothing above it. #6282 (the expiry arm), also with
     * nothing recorded: when the rows were left with the kept entry held and a row waits in the
     * queue past KEPT_TTL_SECONDS (stopped workers, a backlog, a failed deploy), the entry has
     * expired, so a row popped after that does not check, and when an earlier row of the push
     * is among them (#6355) the earlier and the later row both run; and when a run of the push
     * read the kept entry as held, checked clear and removed it (forgetKept()) while a later
     * row was held by a live worker. #6352: a run whose check did not read the kept entry as
     * held (it was written after that check) does not remove it. The arms that need two workers
     * reserving at once do not arise under one queue:work process. #6151:
     * when the reconnect fails, the push throws with the first row committed and this
     * read-back's reconnect fails too (queued_row null, so the commit work may run here as well
     * as in that row's run); when the reconnect succeeds and only the re-run INSERT fails, the
     * read-back runs on a live connection and finds the first row (found_in jobs).
     *
     * So this dispatch is read back by $pushId first (queuedRowFound()): its row in the queue
     * table, its row in failed_jobs (#6026: failed() already ran), or the started entry its
     * handle() or failed() writes (#6026/#6093: a worker ran it and deleted the row, or Job::fail
     * deleted it and its failed() is running before the failer's failed_jobs insert). Found:
     * PUSH_THREW_ROW_FOUND with found_in, and neither the marker nor the commit work runs here,
     * since the job owns them; found_in jobs writes the kept entry (r2 diff:1: no pass runs
     * after a push that threw, and a re-run INSERT may have left two rows, so their runs keep
     * their check once the pending entry is gone, while the kept entry is held: see
     * keptKey()). #6149: all three are read even when one of
     * them cannot be,
     * so a find in any of them wins over a read that failed. Not found (queued_row false) or not
     * told (queued_row null): NOT_QUEUED at ERROR (#5993: the record a monitor filtering at
     * error sees) and notQueued().
     *
     * #6091/#6094: the started entry is written by a run while this push's pending entry
     * (written here before the push) exists, and both are removed here once the push is done, so
     * a file cache does not fill with them; a run that reads the pending entry just as the push
     * finishes, or (#6153) whose pending read throws, can still leave one started entry, which
     * expires unread (the file store keeps its file).
     * queued_row false therefore needs the pending entry to have been recorded; when it was not
     * (CACHE_STEP_FAILED pending_put), a not-found read-back is null. Which arms may still run the
     * commit work twice: null, when a row was written after all, or a run already ran (the note
     * says which could not be read back, #6101/#6135); and false, where the note's 'were not
     * added' can then be wrong for attachments the run did add (#6139). False is reached when a
     * run's own started write failed (its CACHE_STEP_FAILED started_put names it); when the
     * cache store is not one the workers share (the array store); when it is shared but one side
     * cannot read or write the other's entries (#6138: the file store reports an unreadable
     * entry as a miss, never a throw, so web and worker must run with a cache directory both can
     * read and write: a deployment prerequisite); when the read-back runs while a live worker is
     * between Job::fail's delete of the row and failed()'s markStarted(), which runs after
     * failed()'s rollback and re-delete (#6137); or when a run is failed and failed() never runs
     * (the command cannot be unserialised), until the failer writes failed_jobs.
     *
     * #6007/#6045/#6133: in an HTTP request (the Graph webhook), notQueued() is registered as an
     * app terminating callback, so the marker writes and notifyEmailAdded run after the response
     * is sent and the 202 does not wait on them; under PHP-FPM the response is flushed
     * (fastcgi_finish_request) before terminate runs. It runs whatever the response status
     * (#6134), and also when the push itself ran in the terminate phase (a deferred or
     * terminating callback), since Application::terminate re-reads its list as it goes. #6148:
     * the work waits for the rest of the request (in the webhook, every later notification's
     * Graph fetch and import), and is lost when the process dies before terminate runs: a kill,
     * or a fatal error (max_execution_time, memory) anywhere in the rest of the request, before
     * or after the response; inline, it ran before the next notification. #6170: it is also
     * lost when terminate stops before the app's terminating list runs (a Terminating
     * listener or a terminable middleware's terminate() throws, or rendering the request's
     * exception throws so terminate is never called). A callback on that list that throws no
     * longer drops it: every callback on the list is isolated (terminatingIsolated()). Outside a routed
     * request (console, queue worker, a direct call) it runs here, inline. The push itself and
     * its read-back stay in this after-commit callback
     * (#5452 (a)).
     */
    public function queueAfterCommit(): void
    {
        \Illuminate\Support\Facades\DB::afterCommit(function (): void {
            $key = $this->progressKey();
            self::$pushing[$key] = true;
            $this->pushId = (string) \Illuminate\Support\Str::uuid();
            $pending = $this->cacheStep('pending_put', fn () => \Illuminate\Support\Facades\Cache::put(self::pendingKey($this->pushId), true, now()->addMinutes(10)));
            $floorFailures = [];
            $floors = $this->readBackFloors($floorFailures);
            try {
                dispatch($this);
                $this->dropDuplicateRows($floors);
            } catch (\Throwable $e) {
                if (! isset(self::$pushing[$key])) {
                    throw $e;
                }
                unset(self::$pushing[$key]);
                [$found, $step, $stepException, $queueRead, $readFailures] = $this->queuedRowFound($floors, $pending, $floorFailures);
                try {
                    $context = ['email_id' => $this->emailId, 'ticket_id' => $this->ticketId, 'exception' => self::classOf($e)];
                    if (is_string($found)) {
                        Log::warning(self::PUSH_THREW_ROW_FOUND, $context + ['found_in' => $found]);
                    } else {
                        Log::error(self::NOT_QUEUED, $context + ['queued_row' => $found]
                            + ($found === null ? ['read_back_step' => $step, 'read_back_exception' => $stepException, 'read_back_failures' => $readFailures] : []));
                    }
                } catch (\Throwable) {
                }
                if ($found === 'jobs') {
                    // r2 diff:1: no pass runs after a push that threw, and a re-run INSERT may
                    // have left two rows: their runs keep their check once the pending entry is
                    // gone, while the kept entry is held (see keptKey()).
                    $this->keepChecks();
                }
                if (! is_string($found)) {
                    $linked = $found === null ? null : false;
                    if (self::inRoutedRequest()) {
                        // #6133: an app terminating callback, not defer(): the deferred
                        // collection is drained once, by middleware terminate, and a callback
                        // added during or after that drain never runs; Application::terminate
                        // runs after it and re-reads its list, so one added while it runs still
                        // runs. It runs whatever the response status (#6134), and once: the
                        // list is never cleared, so a later terminate in the same process (a
                        // second request in one test) must not run it again.
                        $ran = false;
                        self::terminatingIsolated(function () use (&$ran, $linked, $queueRead): void {
                            if (! $ran) {
                                $ran = true;
                                $this->notQueued($linked, $queueRead);
                            }
                        });
                    } else {
                        $this->notQueued($linked, $queueRead);
                    }
                }
            } finally {
                unset(self::$pushing[$key]);
                $this->cacheStep('cleanup', function (): bool {
                    $forgotPending = \Illuminate\Support\Facades\Cache::forget(self::pendingKey($this->pushId));
                    \Illuminate\Support\Facades\Cache::forget(self::startedKey($this->pushId));

                    return $forgotPending || ! \Illuminate\Support\Facades\Cache::has(self::pendingKey($this->pushId));
                });
            }
        });
    }

    /**
     * #6170: registers $work as an app terminating callback, isolated as defer() isolated its
     * callbacks: each callback already on the app's terminating list is wrapped, in place, in
     * rescue() (reported, then the loop goes on), and so is $work. Application::terminate runs the
     * list in a plain loop, so without this a throw from a callback registered earlier would end
     * the loop before $work. Wrapping in place keeps every index, so it is safe while that loop
     * is running (a push in the terminate phase). Not covered: a throw before the list runs at
     * all (a Terminating listener, a terminable middleware's terminate(), or a render of the
     * request's exception that throws, so terminate is never called; see queueAfterCommit()).
     */
    private static function terminatingIsolated(\Closure $work): void
    {
        $app = app();
        self::$isolated ??= new \WeakMap;
        $isolate = function (callable|string $callback) use ($app): \Closure {
            $wrapped = function () use ($app, $callback): void {
                rescue(fn () => $app->call($callback));
            };
            self::$isolated[$wrapped] = true;

            return $wrapped;
        };
        if ($app instanceof \Illuminate\Foundation\Application) {
            $wrap = [];
            foreach ((fn () => $this->terminatingCallbacks)->call($app) as $index => $callback) {
                if (! ($callback instanceof \Closure && isset(self::$isolated[$callback]))) {
                    $wrap[$index] = $isolate($callback);
                }
            }
            // By index, in place: the list the running loop reads keeps its length and order.
            (function () use ($wrap): void {
                foreach ($wrap as $index => $callback) {
                    $this->terminatingCallbacks[$index] = $callback;
                }
            })->call($app);
        }
        $app->terminating($isolate($work));
    }

    /**
     * #6170: the terminating callbacks terminatingIsolated() has already wrapped, so a second
     * registration in one process does not wrap them twice.
     *
     * @var \WeakMap<\Closure, true>|null
     */
    private static ?\WeakMap $isolated = null;

    /**
     * #6179: after a push that returned, the rows of this push above the jobs floor, read
     * back by $pushId (a lost reply to the INSERT that the connection re-ran leaves two). When
     * a run of this push has started or failed (the started entry handle() or failed() writes
     * while the pending entry is held), every row not yet reserved is deleted, which covers a run that checked
     * before the re-run INSERT landed; otherwise, when two or more rows were read and none of
     * them is reserved, all but the highest id are (#6274: when any row read is reserved, none
     * is deleted). A DELETE matches only a row still unreserved, so a row a worker
     * has reserved is never deleted under it. When no row read is unreserved, the pass ends
     * there: nothing is deleted and (#6275) no kept entry is written, as every row is held and
     * a run's check does not count a reserved row anyway. #6183: when no run has started, at
     * least one row read is unreserved and two or more rows are left (one is reserved, or a
     * worker reserved one before its DELETE), the kept
     * entry is written (a failed write is CACHE_STEP_FAILED kept_put), so a left row's run
     * checks for a later row after the pending entry is gone while the kept entry is held (see
     * keptKey(): not once a run checked clear and removed it, nor past KEPT_TTL_SECONDS).
     * Recorded as
     * DUPLICATE_ROWS_DROPPED; a DELETE that matched nothing as DUPLICATE_ROWS_NOT_DELETED (b11,
     * #6273/#6276: reserved after the read, or already gone). A read or delete that throws is DUPLICATE_CHECK_FAILED (step
     * push): the rows not yet deleted are left, the kept entry is written, and the rows already
     * deleted before the throw are still recorded as DUPLICATE_ROWS_DROPPED. A database queue
     * whose jobs floor could not be read (FLOOR_READ_FAILED step jobs) reads nothing and writes
     * the kept entry. Never throws. Not a database queue or no pushId: nothing is read or
     * written.
     *
     * @param  array<string, int|null>  $floors
     */
    private function dropDuplicateRows(array $floors): void
    {
        if ($this->pushId === null) {
            return;
        }
        if (($floors['jobs'] ?? null) === null) {
            if (array_key_exists('jobs', $floors)) {
                // A database queue whose jobs floor could not be read (FLOOR_READ_FAILED step
                // jobs, already recorded): the pass cannot run, so the runs keep their check
                // while the kept entry is held (see keptKey()).
                $this->keepChecks();
            }

            return;
        }
        $started = false;
        $dropped = [];
        $unmatched = [];
        $failure = null;
        try {
            [$queueTable] = $this->readBackTables();
            if ($queueTable === null) {
                return;
            }
            $table = fn () => \Illuminate\Support\Facades\DB::connection($queueTable['connection'])->table($queueTable['table'])->useWritePdo();
            $rows = $table()->where('id', '>', $floors['jobs'])->where('queue', $queueTable['queue'])
                ->where('payload', 'like', '%'.$this->pushId.'%')->orderBy('id')
                ->get(['id', 'reserved_at', 'payload'])
                ->filter(fn ($row) => $this->ownRowIn([$row->payload]))->values();
            $unreserved = $rows->filter(fn ($row) => $row->reserved_at === null)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            if ($unreserved === []) {
                return;
            }
            if (\Illuminate\Support\Facades\Cache::has(self::startedKey($this->pushId))) {
                $started = true;
                $drop = $unreserved;
            } elseif ($rows->count() >= 2 && count($unreserved) === $rows->count()) {
                $drop = array_slice($unreserved, 0, -1);
            } else {
                // A row is reserved and no run has started: a worker holds it now (its run's
                // own check, handle(), sees the rest: the kept entry below keeps that check on
                // once the pending entry is gone, while it is held) or died holding it. Left
                // to run. A worker
                // whose check ran before the later row was written, and that has not yet
                // written its started entry, runs as well, as before #6179.
                $drop = [];
            }
            foreach ($drop as $id) {
                if ($table()->where('id', $id)->whereNull('reserved_at')->delete() === 1) {
                    $dropped[] = $id;
                } else {
                    // #6273/#6276: reserved by a worker after the read, or deleted by this
                    // DELETE whose reply was lost and whose re-run matched nothing.
                    $unmatched[] = $id;
                }
            }
            if (! $started && $rows->count() - count($dropped) >= 2) {
                // #6183: two or more rows are left and no run has started (a row is reserved,
                // or a worker reserved one before its DELETE): their runs check while the kept
                // entry is held (see keptKey()).
                $this->keepChecks();
            }
        } catch (\Throwable $e) {
            $failure = $e;
        }
        if ($failure !== null) {
            // The pass did not complete: which rows are left is not known, so the runs keep
            // their check; drops made before the throw are still recorded below.
            $this->warnDuplicateCheck('push', $failure);
            $this->keepChecks();
        }
        if ($unmatched !== []) {
            try {
                Log::warning(self::DUPLICATE_ROWS_NOT_DELETED, [
                    'email_id' => $this->emailId,
                    'ticket_id' => $this->ticketId,
                    'run_started' => $started,
                    'unmatched_job_ids' => $unmatched,
                ]);
            } catch (\Throwable) {
            }
        }
        if ($dropped === []) {
            return;
        }
        try {
            Log::warning(self::DUPLICATE_ROWS_DROPPED, [
                'email_id' => $this->emailId,
                'ticket_id' => $this->ticketId,
                'run_started' => $started,
                'dropped_job_ids' => $dropped,
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * #6183: writes the kept entry for KEPT_TTL_SECONDS (#6282, see keptKey()), so a run of
     * this push checks for a later row after the pending entry is gone, while the entry is
     * held; a failed write is CACHE_STEP_FAILED kept_put. Not held, so a run does not check:
     * once a run of the push read it as held, checked clear and removed it (forgetKept()), and
     * (the expiry arm) past KEPT_TTL_SECONDS, when a row still queued (stopped workers, a
     * backlog) runs unchecked: when an earlier row of the push is popped then, the earlier and
     * the later row both run, and nothing is recorded. Never throws.
     */
    private function keepChecks(): void
    {
        $this->cacheStep('kept_put', fn () => \Illuminate\Support\Facades\Cache::put(self::keptKey($this->pushId), true, self::KEPT_TTL_SECONDS));
    }

    /**
     * #6282: removes the kept entry once a run of this push whose check read that entry as
     * held, read the rows and counted no later row (laterRowOfThisPush() set $checkedClear)
     * has finished handle(), returned or thrown. #6352: only an entry the check read as held.
     * A push writes the entry at most once, and only once its INSERTs are done (the pass after
     * a push that returned, or the found_in jobs arm after one that threw), so the entry this
     * run saw is the one it removes, every row of the push was already written when it
     * checked, and (#6354) no write of the entry can land between its forget() and has(). A run that checked while only the
     * pending entry was held does not remove it: an entry written after that check is left
     * for the later rows' runs (before #6352 that removal let a later row run unchecked).
     * Not called (r2 of #6304) after a check whose rows read threw (DUPLICATE_CHECK_FAILED
     * step run: the run goes ahead without knowing whether a later row is left, so the entry
     * stays for that row's run), nor after a read of the entries that threw (CACHE_STEP_FAILED
     * check_read: whether the kept entry was held is not known), nor when no check read the
     * rows (no entry was held), nor by a run that skipped for a later row (that row's run
     * still checks). After a clear check the entry can change the outcome only where the
     * retry already runs twice: a later row that check did not count is reserved, by a live
     * worker whose run runs too (see queueAfterCommit(), 'Both still run when') or by a dead
     * one (failed without running), or has spent its attempts. A removal that throws, or whose
     * store returns false while the key is still held (a file store's failed unlink also
     * returns false), is CACHE_STEP_FAILED kept_forget, and the entry is then held until
     * KEPT_TTL_SECONDS. A false with the key absent (another run removed it, or it expired)
     * is not a failure and records nothing. #6353: a has() read after the false that throws
     * is recorded too, and then whether the entry is still held is not known. Not reached
     * when the run is killed (a timeout). Never throws.
     */
    private function forgetKept(): void
    {
        if ($this->pushId === null) {
            return;
        }
        $this->cacheStep('kept_forget', function (): bool {
            // contract:2: false is also a failed unlink on the file store; told apart from an
            // absent key as the cleanup step does.
            return \Illuminate\Support\Facades\Cache::forget(self::keptKey($this->pushId))
                || ! \Illuminate\Support\Facades\Cache::has(self::keptKey($this->pushId));
        });
    }

    /** #6100/#6172: DUPLICATE_CHECK_FAILED, step run (handle()) or push (dropDuplicateRows()). */
    private function warnDuplicateCheck(string $step, \Throwable $e): void
    {
        try {
            Log::warning(self::DUPLICATE_CHECK_FAILED, ['email_id' => $this->emailId, 'ticket_id' => $this->ticketId,
                'step' => $step, 'exception' => self::classOf($e)]);
        } catch (\Throwable) {
        }
    }

    /**
     * #6178 / C-56: the class of $e for a record. An anonymous class's name carries a NUL byte,
     * then the path and line of the file that declared it; only the part before the NUL is kept
     * ('RuntimeException@anonymous').
     */
    private static function classOf(\Throwable $e): string
    {
        $class = $e::class;
        $nul = strpos($class, "\0");

        return $nul === false ? $class : substr($class, 0, $nul);
    }

    /**
     * #6007: whether this runs inside a routed HTTP request, whose terminate phase (after the
     * response is sent) runs the app's terminating callbacks (#6133). False in the console, the queue worker and
     * a direct call, where there is no response to hold and the work runs inline.
     */
    private static function inRoutedRequest(): bool
    {
        try {
            return app()->bound('request') && app('request')->route() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** #6026: the cache key a run of a dispatch read back by $pushId sets when it starts. */
    private static function startedKey(string $pushId): string
    {
        return 'retry-email-attachments:started:'.$pushId;
    }

    /** #6094: the cache key queueAfterCommit() holds while its push of $pushId is in progress. */
    private static function pendingKey(string $pushId): string
    {
        return 'retry-email-attachments:pending:'.$pushId;
    }

    /**
     * #6183: the cache key dropDuplicateRows() writes when it left two or more rows of the push
     * of $pushId and no run had started, or could not run (no jobs floor) or complete (a read or
     * delete threw), or queueAfterCommit() writes when that push threw and its row was found in
     * the queue table (r2 diff:1), so those rows' runs check for a later row after the pending
     * entry is gone, while it is held. #6282: it lives KEPT_TTL_SECONDS (a left row can wait
     * in the queue far longer than the 10 minutes of the pending and started entries: a backlog,
     * stopped workers), and is removed when a run of the push whose check read it as held
     * (#6352), read the rows and counted no later row finishes (forgetKept()). The expiry arm:
     * a row still queued past KEPT_TTL_SECONDS runs without checking, so when an earlier row
     * of the push is popped then, the earlier and the later row both run, and nothing is
     * recorded. Unlike the pending and started entries, which are written and
     * removed around the push, it outlives the push by design. It is never removed when: it was
     * written after the check of the run that ran, inside the push (#6352); no run checks clear, as
     * every row is failed without running handle(), or the earlier rows skip for the latest
     * and that row's worker dies holding it (its next reservation fails it as
     * MaxAttemptsExceeded without running handle()), or every run that went ahead had a check
     * whose rows read threw (DUPLICATE_CHECK_FAILED step run) or whose read of the entries threw
     * (CACHE_STEP_FAILED check_read); the run that checked clear is
     * killed (a timeout); or its removal threw, or the store's removal failed (a file store's
     * failed unlink), recorded as CACHE_STEP_FAILED kept_forget. Such an entry is held until
     * KEPT_TTL_SECONDS. On CACHE_STORE=file its file stays after that: the file store deletes
     * an expired file only when that key is read again, and no run of a push whose rows have
     * all gone reads it.
     */
    private static function keptKey(string $pushId): string
    {
        return 'retry-email-attachments:kept:'.$pushId;
    }

    /**
     * #6092/#6094: runs one step on the push read-back's cache entries; a throw or a false
     * return is recorded as CACHE_STEP_FAILED (C-56: the ids, the step and the exception class
     * only) and never thrown. True when the step succeeded.
     */
    private function cacheStep(string $step, \Closure $write): bool
    {
        $thrown = null;
        try {
            if ($write() !== false) {
                return true;
            }
        } catch (\Throwable $e) {
            $thrown = self::classOf($e);
        }
        try {
            Log::warning(self::CACHE_STEP_FAILED, [
                'email_id' => $this->emailId,
                'ticket_id' => $this->ticketId,
                'step' => $step,
                'exception' => $thrown,
            ]);
        } catch (\Throwable) {
        }

        return false;
    }

    /**
     * #6093/#6094: what a run of a dispatch read back by $pushId writes when it starts (handle())
     * or fails (failed()): the started entry, while the pusher's pending entry exists, so a run
     * of a push that has already finished leaves nothing behind. #6153: also when the pending
     * read throws (recorded as CACHE_STEP_FAILED pending_read); that entry can outlive a
     * finished push until it expires. The shipped file store does not throw on that read (it
     * reports a miss), so this arm is reached on a store whose reads can throw.
     */
    private function markStarted(): void
    {
        if ($this->pushId === null) {
            return;
        }
        try {
            if (! \Illuminate\Support\Facades\Cache::has(self::pendingKey($this->pushId))) {
                return;
            }
        } catch (\Throwable $e) {
            // Whether a push is pending is not known: write the entry, so a pending read-back
            // still finds it (toward found, never a second commit run). #6153: when no push is
            // pending after all, the entry outlives it until its TTL (the file store removes it
            // only when read), so the failed read is recorded, not silent.
            try {
                Log::warning(self::CACHE_STEP_FAILED, [
                    'email_id' => $this->emailId,
                    'ticket_id' => $this->ticketId,
                    'step' => 'pending_read',
                    'exception' => self::classOf($e),
                ]);
            } catch (\Throwable) {
            }
        }
        $this->cacheStep('started_put', fn () => \Illuminate\Support\Facades\Cache::put(self::startedKey($this->pushId), true, now()->addMinutes(10)));
    }

    /**
     * #6031/#6095: the highest id in the queue table and in failed_jobs before the push, read on
     * the primary key (an index), so the read-back after a throw reads only rows inserted since.
     * Null for a table whose id could not be read; empty when this is not a database queue.
     *
     * #6150: $failures gets the class that threw for each table whose id could not be read, and
     * that failure is recorded as FLOOR_READ_FAILED whether or not the push then throws.
     *
     * @param  array<string, class-string>  $failures
     * @return array<string, int|null>
     */
    private function readBackFloors(array &$failures = []): array
    {
        [$queueConfig, $failed] = $this->readBackTables();
        if ($queueConfig === null) {
            return [];
        }
        $floors = [];
        foreach (['jobs' => $queueConfig, 'failed_jobs' => $failed] as $name => $table) {
            if ($table === null) {
                continue;
            }
            try {
                $floors[$name] = (int) \Illuminate\Support\Facades\DB::connection($table['connection'])
                    ->table($table['table'])->useWritePdo()->max('id');
            } catch (\Throwable $e) {
                // #6150: the class is kept for NOT_QUEUED's read_back_exception, and the failure
                // is recorded now, so a push that then returns still leaves a record of it.
                $floors[$name] = null;
                $failures[$name] = self::classOf($e);
                try {
                    Log::warning(self::FLOOR_READ_FAILED, [
                        'email_id' => $this->emailId,
                        'ticket_id' => $this->ticketId,
                        'step' => $name,
                        'exception' => self::classOf($e),
                    ]);
                } catch (\Throwable) {
                }
            }
        }

        return $floors;
    }

    /**
     * The queue table and the failed_jobs table the read-back reads: [connection, table, queue]
     * of the database queue (null when this dispatch's connection is not one) and
     * [connection, table] of a database failer (null when it is not one).
     *
     * @return array{0: array{connection: ?string, table: string, queue: string}|null, 1: array{connection: ?string, table: string}|null}
     */
    private function readBackTables(): array
    {
        $config = config('queue.connections.'.($this->connection ?? config('queue.default')));
        if (($config['driver'] ?? null) !== 'database') {
            return [null, null];
        }
        $queue = ['connection' => $config['connection'] ?? null, 'table' => $config['table'] ?? 'jobs',
            'queue' => $this->queue ?? $config['queue'] ?? 'default'];
        $failed = config('queue.failed');
        $failedTable = in_array($failed['driver'] ?? null, ['database', 'database-uuids'], true)
            ? ['connection' => $failed['database'] ?? null, 'table' => $failed['table'] ?? 'failed_jobs']
            : null;

        return [$queue, $failedTable];
    }

    /**
     * #5994: where this dispatch was found, read back by $pushId, as [found, step, exception]:
     * found is 'jobs' (its queue row), 'failed_jobs' (#6026: failed and recorded) or 'started'
     * (#6026/#6093: a run of it started or failed, and its row may be gone); false when none of
     * them held it and the pending entry was recorded ($pending); null when it cannot be told,
     * with step naming where the read-back stopped and exception the class that threw (#6092),
     * or null: see NOT_QUEUED.
     *
     * #6025/#6034: a row counts only when it is this job's own: its displayName is this class and
     * its command carries this pushId as its own property, so a row that embeds this command
     * (another job's payload) or merely mentions the uuid does not. #6037: read on the write PDO,
     * so a read replica cannot miss the row just inserted. #6031/#6095: each table is read only
     * above the id it held before the push ($floors, read on the primary key), so the payload
     * match scans only the rows inserted since, in the queue table on this dispatch's queue; a
     * table whose floor could not be read is not scanned (step names it, found null).
     *
     * #6181/#6177: index 3 (queue_read_back) is true when the queue table was read above its
     * floor and held no row of this dispatch, so no queued run can still add the attachments;
     * failed_jobs may not have been read (its read failed, or the failer is not a database
     * one). #6180: index 4 maps each read that failed (jobs, failed_jobs, started) to the class
     * that threw (null for a floor whose class is not known), not only the first one.
     *
     * @param  array<string, int|null>  $floors
     * @param  array<string, class-string>  $floorFailures  readBackFloors()'s $failures
     * @return array{0: string|bool|null, 1: string|null, 2: string|null, 3: bool, 4: array<string, string|null>}
     */
    private function queuedRowFound(array $floors, bool $pending, array $floorFailures = []): array
    {
        try {
            [$queueTable, $failedTable] = $this->readBackTables();
        } catch (\Throwable $e) {
            return [null, 'driver', self::classOf($e), false, ['driver' => self::classOf($e)]];
        }
        if ($queueTable === null || $this->pushId === null) {
            return [null, 'driver', null, false, []];
        }
        $failures = [];
        $jobsRead = false;
        // #6149: a table that cannot be read is remembered, not returned: the other table and
        // the started entry are still read, and any of them finding this dispatch wins. The
        // started entry stays the last read, so a run that deletes its row after the table read
        // has already written its entry (handle() and failed() write it before the row goes).
        $unknown = null;
        foreach (['jobs' => $queueTable, 'failed_jobs' => $failedTable] as $name => $table) {
            if ($table === null) {
                continue;
            }
            if (($floors[$name] ?? null) === null) {
                $unknown ??= [$name, $floorFailures[$name] ?? null];
                $failures[$name] = $floorFailures[$name] ?? null;

                continue;
            }
            try {
                $query = \Illuminate\Support\Facades\DB::connection($table['connection'])
                    ->table($table['table'])->useWritePdo()
                    ->where('id', '>', $floors[$name]);
                if ($name === 'jobs') {
                    $query->where('queue', $table['queue']);
                }
                if ($this->ownRowIn($query->where('payload', 'like', '%'.$this->pushId.'%')->pluck('payload'))) {
                    return [$name, null, null, false, []];
                }
                $jobsRead = $jobsRead || $name === 'jobs';
            } catch (\Throwable $e) {
                $unknown ??= [$name, self::classOf($e)];
                $failures[$name] = self::classOf($e);
            }
        }
        try {
            if (\Illuminate\Support\Facades\Cache::has(self::startedKey($this->pushId))) {
                return ['started', null, null, false, []];
            }
        } catch (\Throwable $e) {
            $unknown ??= ['started', self::classOf($e)];
            $failures['started'] = self::classOf($e);
        }
        if ($unknown !== null) {
            return [null, $unknown[0], $unknown[1], $jobsRead, $failures];
        }

        return $pending ? [false, null, null, false, []] : [null, 'pending', null, true, []];
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

    /**
     * #6100: whether a later queue row carries this same push (a lost reply to the INSERT that
     * the connection re-ran at level 0 leaves two rows of one dispatch). The earlier row's run
     * then exits and the later row's run is the one that runs; recorded as
     * DUPLICATE_ROW_SKIPPED. Read only for a database queue job with a pushId, on the write
     * PDO, above this row's own id and on its queue. A read that throws is recorded
     * (DUPLICATE_CHECK_FAILED) and the run goes ahead. #6183: read only while the pending or
     * kept entry is held; a read of either that throws is recorded (CACHE_STEP_FAILED
     * check_read) and the rows are read as if one were held. #6282/#6352: $checkedClear is set
     * only when the kept entry was read as held, the rows were read and no later row is
     * counted; never when only the pending entry was held, no entry was held, the entries read
     * threw (#6358) or the rows read threw. handle() removes the kept entry only then
     * (forgetKept()).
     */
    private function laterRowOfThisPush(bool &$checkedClear = false): bool
    {
        $checkedClear = false;
        if ($this->pushId === null || ! $this->job instanceof \Illuminate\Queue\Jobs\DatabaseJob) {
            return false;
        }
        // #6352: whether the kept entry was read as held at this check. Only then may this run
        // remove it: an entry written after the check (the pass after the push, or the
        // found_in jobs arm) is one this run never saw, and is left for the later rows' runs.
        $keptHeld = false;
        try {
            // #6183: only while this push is in flight (its pending entry is held), or while the
            // kept entry is held (see keptKey()). A read that throws is treated as held, and is
            // recorded here (r2 diff:7): markStarted() reads only the pending entry, and does not
            // run when the check skips the run. Both entries are read (#6352), so a run inside
            // the push knows whether the kept entry was held at its check.
            $pendingHeld = \Illuminate\Support\Facades\Cache::has(self::pendingKey($this->pushId));
            $keptHeld = \Illuminate\Support\Facades\Cache::has(self::keptKey($this->pushId));
            if (! $pendingHeld && ! $keptHeld) {
                return false;
            }
        } catch (\Throwable $e) {
            // Whether the kept entry is held is not known: this run will not remove it.
            $keptHeld = false;
            try {
                Log::warning(self::CACHE_STEP_FAILED, [
                    'email_id' => $this->emailId,
                    'ticket_id' => $this->ticketId,
                    'step' => 'check_read',
                    'exception' => self::classOf($e),
                ]);
            } catch (\Throwable) {
            }
        }
        try {
            $record = $this->job->getJobRecord();
            $config = config('queue.connections.'.$this->job->getConnectionName());
            // #6182/#6171: only a later row that will run handle() when a worker pops it: not
            // reserved (a reserved one may belong to a worker that died, and is then failed as
            // MaxAttemptsExceeded on its next reservation without running), with attempts left.
            $later = \Illuminate\Support\Facades\DB::connection($config['connection'] ?? null)
                ->table($config['table'] ?? 'jobs')->useWritePdo()
                ->where('id', '>', $record->id)
                ->where('queue', $record->queue)
                ->whereNull('reserved_at')
                ->where('attempts', '<', $this->tries)
                ->where('payload', 'like', '%'.$this->pushId.'%')
                ->get(['id', 'payload']);
            $ids = $later->filter(fn ($row) => $this->ownRowIn([$row->payload]))->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        } catch (\Throwable $e) {
            $this->warnDuplicateCheck('run', $e);

            return false;
        }
        if ($ids === []) {
            // The rows were read and no later row is counted: the one exit that lets
            // handle() remove the kept entry (forgetKept()), and (#6352) only when that entry
            // was read as held at this check.
            $checkedClear = $keptHeld;

            return false;
        }
        try {
            Log::warning(self::DUPLICATE_ROW_SKIPPED, [
                'email_id' => $this->emailId,
                'ticket_id' => $this->ticketId,
                'job_id' => (int) $record->id,
                'later_job_ids' => $ids,
            ]);
        } catch (\Throwable) {
        }

        return true;
    }

    public function handle(EmailService $emails): void
    {
        $key = $this->progressKey();
        unset(self::$pushing[$key]);
        $checkedClear = false;
        if ($this->laterRowOfThisPush($checkedClear)) {
            return;
        }
        // #6026: what queueAfterCommit()'s read-back finds once a worker has run this dispatch
        // and deleted its row; written only while that push is pending (#6094), and a failed
        // write is recorded (#6092).
        $this->markStarted();
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
            // #6282/#6352: this run's check read the kept entry as held, read the rows and
            // counted no later row, and the run has finished (returned or thrown), so the kept
            // entry has done its work. Not after a check that threw, did not run or saw only
            // the pending entry (see forgetKept()).
            if ($checkedClear) {
                $this->forgetKept();
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
                        'exception' => self::classOf($stepFailure),
                    ]);
                } catch (\Throwable) {
                }
            }
        }
        // #6093: Job::fail has deleted the queue row, and the failer writes failed_jobs only
        // after this returns; a push read-back in between finds this entry instead. Written
        // after the rollback above, which runs before anything is read or written.
        $this->markStarted();
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
     * was read or stored by a retry here, so the marker names no ids. #6035/#6101: $linked null
     * (the read-back could not tell) writes the MARKER_QUEUE_UNKNOWN marker; #6135/#6181: with
     * $queueRead (the queue table was read above its floor and held no row) its note says no
     * queued retry was found and whether one already ran could not be read back; otherwise that
     * a queued retry may still add the attachments. False says they were not
     * added. The commit work the job would have owned
     * runs here instead. #5995/#6030: the notification runs here; the technician loop is only
     * dispatched (afterCommit), onto the queue the technician job names. When that dispatch
     * throws (as it does while the same database queue's inserts are failing) the loop is lost
     * and recorded as COMMIT_STEP_THREW (technician_dispatch); when the push threw for another
     * reason, it may be queued. Never throws.
     */
    public function notQueued(?bool $linked = false, bool $queueRead = false): void
    {
        $this->writeMarker('not_queued', [], $linked, queueUnknown: $linked === null, queueRead: $queueRead);
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
    private function writeMarker(string $reason, ?array $undiscarded, ?bool $linked = false, bool $queueUnknown = false, bool $queueRead = false): void
    {
        if ($undiscarded === null && $this->earlierUndiscarded !== []) {
            $this->warnEarlierUndiscarded();
        }
        // #5801: linked null means whether the link committed could not be read back; the rows
        // were kept, so the record says the state is unknown rather than "not added". #6101: on
        // the not_queued null arm no link was attempted here; what is unknown is whether a
        // queued retry exists, and the record says that instead.
        $body = match (true) {
            $queueUnknown && $queueRead => "Attachments from email #{$this->emailId} may not have been added to this ticket: no queued retry to add them was found, and whether a retry already ran could not be read back (reason: {$reason}).",
            $queueUnknown => "Attachments from email #{$this->emailId} may not have been added to this ticket: whether a retry to add them is queued could not be read back (reason: {$reason}).",
            $linked === null => "Attachments from email #{$this->emailId} may not have been added to this ticket: whether they were linked could not be read back (reason: {$reason}).",
            default => "Attachments from email #{$this->emailId} were not added to this ticket automatically (reason: {$reason}).",
        };
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
            Log::warning($queueUnknown ? self::MARKER_QUEUE_UNKNOWN : ($linked === null ? self::MARKER_LINK_UNKNOWN : self::MARKER), [
                'email_id' => $this->emailId,
                'ticket_id' => $this->ticketId,
                'reason' => $reason,
                'refusals_requeued' => $this->refusals,
                'ticket_note_written' => $noted,
                'undiscarded_attachment_ids' => $undiscarded,
            ] + ($queueUnknown ? ['queue_read_back' => $queueRead] : []));
        } catch (\Throwable) {
            // The note, when written, is the durable half of the marker.
        }
    }

    /**
     * The technician loop dispatch, then notifyEmailAdded, as linkEmailToTicket registers them.
     * Never throws: a throw here would fail the job and run failed(), which would run it again.
     * #5811: each step is tried on its own, and a throw is recorded under the step that threw
     * (lookup, technician_dispatch, notification); a missing or soft-deleted ticket (#6008) or a
     * missing email row is recorded too. #5816: the dispatch waits for the open transaction to
     * commit, as linkEmailToTicket's does, so a loop is never queued for writes a still-open
     * transaction could roll back. #6000: it is registered as this method's own after-commit
     * callback, which catches the push's throw and records it as technician_dispatch, so a
     * throw at a later commit never escapes to that commit's caller; a rollback of that
     * transaction records COMMIT_STEP_ROLLED_BACK, so the dropped loop is not silent. A process
     * that exits with that transaction still open (a timeout kill) records neither, nor does a
     * nested transaction that commits into a parent which then rolls back.
     */
    private function runCommitWork(): void
    {
        try {
            $ticket = Ticket::withTrashed()->find($this->ticketId);
            $email = Email::find($this->emailId);
        } catch (\Throwable $e) {
            $this->warnCommitStep('lookup', $e);

            return;
        }
        if ($ticket === null || $ticket->trashed() || $email === null) {
            try {
                Log::warning(self::COMMIT_SKIPPED, [
                    'email_id' => $this->emailId,
                    'ticket_id' => $this->ticketId,
                    'ticket_found' => $ticket !== null,
                    'ticket_trashed' => $ticket?->trashed() ?? false,
                    'email_found' => $email !== null,
                ]);
            } catch (\Throwable) {
            }

            return;
        }
        try {
            if (TechnicianConfig::enabled() && ! $ticket->isUnverifiedContactIntake()) {
                $ticketId = $ticket->id;
                \Illuminate\Support\Facades\DB::afterRollBack(fn () => $this->warnCommitStep('technician_dispatch', null, self::COMMIT_STEP_ROLLED_BACK));
                \Illuminate\Support\Facades\DB::afterCommit(function () use ($ticketId): void {
                    try {
                        RunTechnicianLoop::dispatch($ticketId);
                    } catch (\Throwable $e) {
                        $this->warnCommitStep('technician_dispatch', $e);
                    }
                });
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

    /** #5811 / C-56: ids, the step that threw and the exception class only (null: none threw). */
    private function warnCommitStep(string $step, ?\Throwable $e, string $message = self::COMMIT_STEP_THREW): void
    {
        try {
            Log::warning($message, [
                'email_id' => $this->emailId,
                'ticket_id' => $this->ticketId,
                'step' => $step,
                'exception' => $e === null ? null : self::classOf($e),
            ]);
        } catch (\Throwable) {
        }
    }
}
