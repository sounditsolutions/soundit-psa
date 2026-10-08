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
     * $ids with the earlier runs' undiscarded ids (#5803); null (not known, #5799) stays null.
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

    public function handle(EmailService $emails): void
    {
        $key = $this->progressKey();
        self::$progress[$key] = [];
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
                    self::dispatch($this->emailId, $this->ticketId, $this->noteId, $this->baseline, $this->refusals + 1,
                        $this->withEarlier($outcome['undiscarded_attachment_ids']))
                        ->delay(self::REQUEUE_DELAY_SECONDS);
                    self::$progress[$key]['requeued'] = true;
                });

                return;
            }

            if ($outcome !== null) {
                self::uninterrupted(function () use ($key, $outcome): void {
                    self::$progress[$key]['marked'] = true;
                    $this->writeMarker($outcome['reason'], $this->withEarlier($outcome['undiscarded_attachment_ids']));
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
        } finally {
            unset(self::$progress[$key]);
        }
    }

    /**
     * The job failed: a timeout kill, a throw out of handle(), or a failure the worker records in
     * another process (#5799: a job whose worker died is failed by the next worker that reserves
     * it, as MaxAttemptsExceededException). Final, never re-queued. On a timeout the worker calls
     * this in the killed process, after rolling back any open transaction. When handle() already
     * had the retry's outcome, that outcome stands: a re-queued run owns what follows; otherwise
     * only the marker and the commit work handle() had not started are run. When the kill landed
     * inside the retry, abandonRetry discards what the read stored unless the link committed, and
     * the marker is written unless the link is known to have committed. When the failure is not a
     * timeout and no handle() of this dispatch is on this process's stack (it ran in another
     * process, or already returned or threw), what its retry stored is not known here: the
     * marker's undiscarded_attachment_ids is null, not [].
     */
    public function failed(?\Throwable $e): void
    {
        $key = $this->progressKey();
        $here = array_key_exists($key, self::$progress);
        $progress = self::$progress[$key] ?? [];
        if ($here) {
            self::$progress[$key]['failed'] = true;
        }
        if (array_key_exists('outcome', $progress)) {
            if ($progress['requeued'] ?? false) {
                return;
            }
            $outcome = $progress['outcome'];
            if ($outcome !== null && ! ($progress['marked'] ?? false)) {
                $this->writeMarker($outcome['reason'], $this->withEarlier($outcome['undiscarded_attachment_ids']));
            } elseif ($outcome === null && $this->earlierUndiscarded !== [] && ! ($progress['marked'] ?? false)) {
                $this->warnEarlierUndiscarded();
            }
            if (! ($progress['committed'] ?? false)) {
                $this->runCommitWork();
            }

            return;
        }

        $timedOut = $e instanceof \Illuminate\Queue\TimeoutExceededException;
        $discard = ['linked' => false, 'undiscarded_attachment_ids' => []];
        if (! $here && ! $timedOut) {
            // #5799: not this process's run; its tracking died with the process that stored.
            $discard['undiscarded_attachment_ids'] = null;
        } else {
            try {
                $discard = app(EmailService::class)->abandonRetry($this->noteId);
            } catch (\Throwable) {
            }
        }
        if ($discard['linked'] !== true) {
            $this->writeMarker($timedOut ? 'timed_out' : 'job_failed', $this->withEarlier($discard['undiscarded_attachment_ids']));
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

    /**
     * #5803: the final run linked, and an earlier run left rows undiscarded. C-56: ids only.
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

    /** @param  list<int>|null  $undiscarded  null when not known in this process (#5799) */
    private function writeMarker(string $reason, ?array $undiscarded): void
    {
        $noted = false;
        try {
            if (Ticket::whereKey($this->ticketId)->exists()) {
                TicketNote::create([
                    'ticket_id' => $this->ticketId,
                    'author_id' => null,
                    'author_name' => 'System',
                    'who_type' => WhoType::System,
                    'body' => "Attachments from email #{$this->emailId} were not added to this ticket automatically (reason: {$reason}).",
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
            Log::warning(self::MARKER, [
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
     */
    private function runCommitWork(): void
    {
        try {
            $ticket = Ticket::find($this->ticketId);
            $email = Email::find($this->emailId);
            if ($ticket === null || $email === null) {
                return;
            }
            if (TechnicianConfig::enabled() && ! $ticket->isUnverifiedContactIntake()) {
                RunTechnicianLoop::dispatch($ticket->id);
            }
            app(NotificationService::class)->notifyEmailAdded($ticket, $email);
        } catch (\Throwable $e) {
            try {
                Log::warning('[RetryEmailAttachments] Email-added notification or technician dispatch threw', [
                    'email_id' => $this->emailId,
                    'ticket_id' => $this->ticketId,
                    'exception' => $e::class,
                ]);
            } catch (\Throwable) {
            }
        }
    }
}
