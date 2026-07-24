<?php

namespace App\Http\Controllers\Web;

use App\Enums\NoteType;
use App\Enums\PhoneDirectoryListType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\PhoneCall;
use App\Models\PhoneDirectoryEntry;
use App\Models\TechnicianRun;
use App\Models\TicketNote;
use App\Services\Technician\Cockpit\CockpitQuery;
use App\Services\Technician\Cockpit\CockpitUndoToken;
use App\Services\Technician\TechnicianApprovalService;
use App\Services\Technician\TechnicianDisclosure;
use App\Services\TicketService;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TechnicianCockpitController extends Controller
{
    private const UNDO_WINDOW_MINUTES = 5;

    public function index(CockpitQuery $query, \App\Services\Technician\Cockpit\CockpitRecipientView $recipients, TechnicianDisclosure $disclosure)
    {
        $drafts = $query->pendingDrafts();
        // psa-u51h.2: the EXACT disclosure each approval will append, keyed by run id. Every
        // body-bearing action here is human-approved, so it credits the approver by name —
        // the card must show that, not the old global AI-only line. Text comes from the
        // disclosure service itself so the preview cannot drift from the send.
        $approverName = (string) (auth()->user()?->name ?? '');

        return view('cockpit.index', [
            'drafts' => $drafts,
            'disclosurePreviews' => $drafts->mapWithKeys(fn ($run) => [
                $run->id => $disclosure->dualBanner($run->drafterDisplayName(), $approverName),
            ]),
            // psa-kt82 PR B (gate 4): resolved recipient block data keyed by run id.
            'recipientViews' => $drafts->mapWithKeys(fn ($run) => [$run->id => $recipients->for($run)])->filter(),
            'flagged' => $query->flaggedForAttention(),
            'needs' => $query->needsAttention(),
            // psa-3q0c: correction-driven "left as-is" outcomes so a correction never looks silent.
            'reassessedLeftAsIs' => $query->reassessedLeftAsIs(),
            // psa-y4ft.1: autonomous DIRECT closes still Closed — each card offers one-click Reopen.
            'directCloses' => $query->recentDirectCloses(),
            'intake' => $query->intakeReview(),
            'intakeSpam' => $query->intakeSpamReview(),
            'queued' => $query->queuedOffline(),
            'expired' => $query->expiredQueue(),
            'counts' => $query->counts(),
        ]);
    }

    public function approve(Request $request, TechnicianRun $run, TechnicianApprovalService $service)
    {
        // Dispatch on action_type so future tools (reply, escalate) plug in without rework.
        // Fail-closed: an unrecognized action type must NOT fall through to a send.
        $result = match ($run->action_type) {
            'propose_close' => $service->approveClose($run, (int) auth()->id()),
            'propose_merge' => $service->approveMerge($run, (int) auth()->id()),
            'stage_email' => $service->approveStagedEmail(
                $run,
                $request->validate(['body' => ['required', 'string']])['body'],
                (int) auth()->id(),
                ...$this->recipientInputs($request),
            ),
            'stage_public_note' => $service->approveStagedPublicNote(
                $run,
                $request->validate(['body' => ['required', 'string']])['body'],
                (int) auth()->id(),
            ),
            'tactical_stage_script',
            'tactical_stage_command',
            'tactical_stage_reboot',
            'tactical_stage_shutdown',
            'tactical_stage_recover_mesh',
            'tactical_stage_maintenance',
            'tactical_stage_start_service',
            'tactical_stage_stop_service',
            'tactical_stage_restart_service',
            'tactical_stage_install_approved_patches' => $service->approveStagedTacticalAction($run, (int) auth()->id()),
            'tactical_stage_reset_patch_policies',
            'tactical_stage_run_policy_task_all' => $service->approveStagedTacticalAdminAction($run, (int) auth()->id()),
            'cipp_stage_reset_user_password',
            'cipp_stage_disable_user_sign_in',
            'cipp_stage_enable_user_sign_in',
            'cipp_stage_revoke_user_sessions',
            'cipp_stage_remove_user_mfa_methods',
            'cipp_stage_set_legacy_per_user_mfa',
            'cipp_stage_assign_user_license',
            'cipp_stage_remove_user_license',
            'cipp_stage_convert_mailbox',
            'cipp_stage_set_mailbox_forwarding',
            'cipp_stage_set_mailbox_gal_visibility',
            'cipp_stage_set_mailbox_out_of_office',
            'cipp_stage_set_mailbox_delegate',
            'cipp_stage_remove_directory_role',
            'cipp_stage_release_quarantine_message',
            'cipp_stage_add_tenant_allow_entry',
            'cipp_stage_wipe_device',
            'cipp_stage_reassign_onedrive',
            'cipp_stage_create_user',
            'cipp_stage_edit_user',
            'cipp_stage_set_group_membership' => $service->approveStagedCippWriteAction(
                $run,
                (int) auth()->id(),
                $this->cippApprovalInputs($request, $run),
            ),
            // Body is required only on the reply/resolution path, validated inside this arm.
            'send_reply', 'propose_resolution' => $service->approveAndSend(
                $run,
                $request->validate(['body' => ['required', 'string']])['body'],
                (int) auth()->id(),
                ...$this->recipientInputs($request),
            ),
            default => abort(422, 'Unsupported action type for approval.'),
        };

        $ok = in_array($result->status, ['sent', 'closed', 'published', 'merged', 'executed', 'queued_offline'], true);
        $message = match ($result->status) {
            'sent' => 'Reply approved and sent.',
            'closed' => 'Ticket closed.',
            'published' => 'Public note published.',
            'merged' => 'Tickets merged.',
            // An executed action may carry its own operator-facing summary
            // (e.g. the created UPN + one-time password contract).
            'executed' => $result->message ?? 'Held action approved and executed.',
            // bd psa-xr84: approved but the device was offline — parked to auto-run on reconnect.
            'queued_offline' => 'Device offline — queued to run automatically when it comes back online.',
            'already_handled' => $this->handledMessage($run, 'That draft was already handled.'),
            'recipient_invalid' => $result->message ?? 'One or more recipients are no longer valid — re-check the To/CC and try again.',
            // psa-zjpd deep-review: a held destructive action can decline for a
            // specific recoverable reason (typed-id mismatch, identity drift,
            // lost mapping, kill-switch, cooldown) — surface it when provided.
            'gate_declined' => $result->message ?? 'Could not send — the Technician declined (it may be paused). Try again.',
            default => 'Could not send — the Technician declined (it may be paused). Try again.',
        };

        return $this->actionResponse(
            $request,
            $ok,
            $result->status,
            $message,
            undo: $ok && $run->action_type === 'propose_close'
                ? $this->runUndoPayload($run, 'approve-close', ['status_note_id' => $result->noteId])
                : null,
            secret: $ok ? $result->secret : null,
        );
    }

    public function deny(Request $request, TechnicianRun $run, TechnicianApprovalService $service)
    {
        $ok = $service->deny($run);

        return $this->actionResponse(
            $request,
            $ok,
            $ok ? 'denied' : 'already_handled',
            $ok ? 'Draft dismissed; the ticket is back with your team.' : $this->handledMessage($run, 'That draft was already handled.'),
            undo: $ok ? $this->runUndoPayload($run, 'hold') : null,
        );
    }

    /**
     * Operator cancels a queued offline action from the cockpit (bd psa-xr84).
     * CAS-guarded on the model: a no-op on anything not currently queued_offline
     * (it already ran on reconnect, already expired, or was already cancelled).
     */
    public function cancel(Request $request, TechnicianRun $run)
    {
        $ok = $run->cancelQueued();

        return $this->actionResponse(
            $request,
            $ok,
            $ok ? 'cancelled' : 'already_handled',
            $ok ? 'Queued action cancelled.' : 'That queued action was already handled.',
        );
    }

    /**
     * Operator re-confirms an expired queued action (bd psa-xr84), re-arming the
     * normal approval flow (expired → awaiting_approval). CAS-guarded on the
     * model: a no-op if the run is no longer Expired.
     */
    public function reconfirm(Request $request, TechnicianRun $run)
    {
        $ok = $run->reconfirmExpired();

        return $this->actionResponse(
            $request,
            $ok,
            $ok ? 'reconfirmed' : 'already_handled',
            $ok ? 'Back in the approval queue.' : 'That expired action was already handled.',
        );
    }

    /**
     * Acknowledge a held flag (Increment H): the operator has it. Flagged → Done,
     * no execution, no client-facing consequence. The CAS guard on the model makes
     * this a safe no-op on anything that is not a held flag.
     */
    public function acknowledge(Request $request, TechnicianRun $run)
    {
        $ok = $run->acknowledgeFlag();

        return $this->actionResponse(
            $request,
            $ok,
            $ok ? 'done' : 'already_handled',
            $ok ? 'Flag acknowledged — it’s with you now.' : 'That flag was already handled.',
            undo: $ok ? $this->runUndoPayload($run, 'ack-flag') : null,
        );
    }

    /** Dismiss a held flag: not something a person needs after all. Flagged → Denied. */
    public function dismiss(Request $request, TechnicianRun $run)
    {
        $ok = $run->dismissFlag();

        return $this->actionResponse(
            $request,
            $ok,
            $ok ? 'denied' : 'already_handled',
            $ok ? 'Flag dismissed.' : 'That flag was already handled.',
            undo: $ok ? $this->runUndoPayload($run, 'dismiss-flag') : null,
        );
    }

    /**
     * Dismiss a held intake suggestion (operator has reviewed the calibration signal).
     * Transitions intake_route AwaitingApproval → Done via CAS guard (no-op if already
     * resolved or if the run is not an intake_route). Visibility only — no merge action.
     */
    public function intakeDismiss(Request $request, TechnicianRun $run)
    {
        $ok = $run->dismissIntake();

        return $this->actionResponse(
            $request,
            $ok,
            $ok ? 'done' : 'already_handled',
            $ok ? 'Intake suggestion dismissed.' : 'That intake suggestion was already handled.',
            undo: $ok ? $this->runUndoPayload($run, 'dismiss-intake') : null,
        );
    }

    /**
     * One-tap "mark followed-up + block number" for a suspected-spam call (psa-xcyo Task 6b).
     *
     * Stamps followed_up_at (removing the call from the spam lane) AND adds the caller to the
     * Blocked phone directory when the number is not already listed. Both writes are wrapped in a
     * transaction. A repeat tap becomes a no-op and returns already_handled; a null from_number
     * (non-normalizable) blocks nothing but still marks the call followed-up.
     */
    public function intakeSpamBlock(Request $request, PhoneCall $call)
    {
        $directoryEntryId = null;
        $handled = DB::transaction(function () use ($call, &$directoryEntryId): bool {
            $updated = PhoneCall::query()
                ->whereKey($call->id)
                ->whereNull('followed_up_at')
                ->whereNull('ticket_id')
                ->whereNull('client_id')
                ->update([
                    'followed_up_at' => now(),
                    'followed_up_by' => (int) auth()->id(),
                ]) === 1;

            if (! $updated) {
                return false;
            }

            $call->refresh();
            $normalized = PhoneNumber::normalize($call->from_number);
            if ($normalized !== null) {
                $entry = PhoneDirectoryEntry::where('phone_number', $normalized)->first();

                if (! $entry) {
                    $entry = PhoneDirectoryEntry::create([
                        'phone_number' => $normalized,
                        'list_type' => \App\Enums\PhoneDirectoryListType::Blocked,
                        'reason' => 'AI intake: marked spam by operator',
                        'added_by_user_id' => (int) auth()->id(),
                    ]);
                    $directoryEntryId = $entry->id;
                }
            }

            return true;
        });

        return $this->actionResponse(
            $request,
            $handled,
            $handled ? 'done' : 'already_handled',
            $handled ? 'Caller marked followed-up and the number was blocked.' : 'That call was already handled.',
            undo: $handled ? $this->callUndoPayload(
                $call,
                'block-number',
                $directoryEntryId !== null ? ['directory_entry_id' => $directoryEntryId] : [],
            ) : null,
        );
    }

    /**
     * Record an operator correction on a held proposal and trigger an immediate
     * correction-driven re-assessment. The cockpit's single "Decline & re-assess"
     * control posts here (psa-gt66 collapsed the prior two same-behaviour buttons).
     */
    public function correct(Request $request, TechnicianRun $run)
    {
        $validated = $request->validate(['correction' => ['required', 'string', 'max:2000']]);

        // A run whose ticket was (soft-)deleted has nothing to re-assess — fail gracefully
        // rather than 500 on the non-nullable CorrectionRecorder/ReassessTrigger signatures.
        $ticket = $run->ticket;
        abort_unless($ticket, 404, 'The ticket for this proposal no longer exists.');

        // Record first so the conversation exists before re-assessment starts.
        app(\App\Services\Agent\Steering\CorrectionRecorder::class)
            ->record($ticket, $request->user(), $validated['correction'], $run);

        // Supersede the current run and dispatch a correctionDriven RunTechnicianAgent.
        app(\App\Services\Agent\Steering\ReassessTrigger::class)->reassess($ticket, $run);

        return $this->actionResponse($request, true, 'reassessing', "Re-assessing #{$ticket->id} with your correction.");
    }

    /**
     * One-click reopen for an autonomous DIRECT close (psa-y4ft.1). The held
     * propose_close path gets its undo as a 5-minute toast on the approve response;
     * a direct close executes with no operator in the loop, so its undo is this
     * durable lane action instead — same safety guards as undoApprovedClose (ticket
     * still Closed, the agent's close is still the LATEST status change) but
     * windowed to the lane (48h), no cache token. Done → Denied records the human
     * reversal so calibration reads it as a veto, and the resulting InProgress
     * status makes an immediate agent re-close ineligible (CloseAutoEligibility).
     */
    public function reopenDirectClose(Request $request, TechnicianRun $run)
    {
        $ok = DB::transaction(function () use ($run): bool {
            $locked = TechnicianRun::query()->lockForUpdate()->find($run->id);

            if (! $locked
                || $locked->action_type !== 'direct_close'
                || $locked->state !== TechnicianRunState::Done
                || $locked->created_at === null
                || $locked->created_at->lt(now()->subHours(CockpitQuery::DIRECT_CLOSE_WINDOW_HOURS))) {
                return false;
            }

            $ticket = $locked->ticket;
            $statusNoteId = (int) data_get($locked->proposed_meta, 'status_note_id', 0);
            $statusNote = $statusNoteId > 0
                ? TicketNote::query()
                    ->whereKey($statusNoteId)
                    ->where('ticket_id', $locked->ticket_id)
                    ->where('note_type', NoteType::StatusChange->value)
                    ->where('status_to', TicketStatus::Closed->value)
                    ->first()
                : null;

            if (! $ticket || $ticket->status !== TicketStatus::Closed || ! $statusNote) {
                return false;
            }

            if ($this->laterStatusChangeExists($statusNote)) {
                return false;
            }

            app(TicketService::class)->changeStatus(
                $ticket,
                TicketStatus::InProgress,
                (int) auth()->id(),
                'Reopened by cockpit undo.',
            );

            $locked->advanceTo(TechnicianRunState::Denied);

            return true;
        });

        return $this->actionResponse(
            $request,
            $ok,
            $ok ? 'reopened' : 'already_handled',
            $ok ? 'Ticket reopened — it’s back with your team.' : 'That close was already handled or can no longer be safely reopened.',
        );
    }

    public function undo(Request $request)
    {
        $tokens = app(CockpitUndoToken::class);

        if (! $tokens->isValidRequest($request, (int) auth()->id())) {
            return $this->actionResponse($request, false, 'invalid', 'Undo link is invalid or expired.', 'error', 403);
        }

        $undo = $tokens->consume((string) $request->query('token'));
        if ($undo === null || (int) ($undo['user_id'] ?? 0) !== (int) auth()->id()) {
            return $this->actionResponse($request, false, 'expired', 'Undo window expired or the item already changed.', 'error', 409);
        }

        $action = (string) ($undo['action'] ?? '');
        $targetType = (string) ($undo['target_type'] ?? '');
        $targetId = (int) ($undo['target_id'] ?? 0);

        if ($targetId <= 0 || ! in_array($targetType, ['run', 'call'], true)) {
            return $this->actionResponse($request, false, 'invalid', 'Undo target was not valid.', 'error', 422);
        }

        $undone = match ($action) {
            'approve-close' => $targetType === 'run' && $this->undoApprovedClose($targetId, (int) ($undo['status_note_id'] ?? 0), (string) ($undo['run_updated_at'] ?? '')),
            'hold' => $targetType === 'run' && $this->undoRunState($targetId, null, TechnicianRunState::Denied, TechnicianRunState::AwaitingApproval, (string) ($undo['run_updated_at'] ?? '')),
            'ack-flag' => $targetType === 'run' && $this->undoRunState($targetId, 'flag_attention', TechnicianRunState::Done, TechnicianRunState::Flagged, (string) ($undo['run_updated_at'] ?? '')),
            'dismiss-flag' => $targetType === 'run' && $this->undoRunState($targetId, 'flag_attention', TechnicianRunState::Denied, TechnicianRunState::Flagged, (string) ($undo['run_updated_at'] ?? '')),
            'dismiss-intake' => $targetType === 'run' && $this->undoRunState($targetId, 'intake_route', TechnicianRunState::Done, TechnicianRunState::AwaitingApproval, (string) ($undo['run_updated_at'] ?? '')),
            'block-number' => $targetType === 'call' && $this->undoSpamCall($targetId, removeBlock: true, callFollowedUpAt: (string) ($undo['call_followed_up_at'] ?? ''), directoryEntryId: (int) ($undo['directory_entry_id'] ?? 0)),
            'not-spam' => $targetType === 'call' && $this->undoSpamCall($targetId, removeBlock: false, callFollowedUpAt: (string) ($undo['call_followed_up_at'] ?? ''), directoryEntryId: 0),
            default => null,
        };

        if ($undone === null) {
            return $this->actionResponse($request, false, 'unsupported', 'Undo is only available for reversible cockpit actions.', 'error', 422);
        }

        if (! $undone) {
            return $this->actionResponse($request, false, 'expired', 'Undo window expired or the item already changed.', 'error', 409);
        }

        return $this->actionResponse($request, true, 'undone', 'Action undone.');
    }

    /** @return array<string, mixed> */
    private function cippApprovalInputs(Request $request, TechnicianRun $run): array
    {
        $inputs = (array) ($run->proposed_meta['sensitive_inputs'] ?? []);
        $rules = [];

        if (in_array('external_smtp', $inputs, true)) {
            $rules['external_smtp'] = ['required', 'email:rfc', 'max:254'];
        }

        if (in_array('internal_message', $inputs, true)) {
            $rules['internal_message'] = ['required', 'string', 'max:2000'];
        }

        if (in_array('external_message', $inputs, true)) {
            $rules['external_message'] = ['required', 'string', 'max:2000'];
        }

        if (in_array('confirm_device_id', $inputs, true)) {
            $rules['confirm_device_id'] = ['required', 'string', 'max:64'];
        }

        return $rules === [] ? [] : $request->validate($rules);
    }

    /**
     * Operator-edited recipient references from the approval form (gate 4 → gate 3).
     * The service re-resolves these against the ticket's validated sources at execution.
     *
     * @return array{0: array<int,string>, 1: array<int,string>}
     */
    private function recipientInputs(Request $request): array
    {
        $v = $request->validate([
            'to' => ['sometimes', 'array'],
            'to.*' => ['string', 'max:320'],
            'cc' => ['sometimes', 'array'],
            'cc.*' => ['string', 'max:320'],
        ]);

        return [array_values($v['to'] ?? []), array_values($v['cc'] ?? [])];
    }

    /**
     * psa-xz0z: 'already_handled' is honest for a run that genuinely reached a terminal state
     * (Done / Denied / Superseded / …), but a LIE for one wedged in 'executing' — a claim
     * stranded by a process death or a deploy. Tell the operator the truth in that case: the
     * stale-claim reaper (technician:reap-stale-claims) returns wedged runs to the queue
     * automatically within a few minutes, so "already handled" would send them away from an
     * action that is actually still recoverable.
     */
    private function handledMessage(TechnicianRun $run, string $terminalMessage): string
    {
        $fresh = $run->fresh();

        if ($fresh?->state !== TechnicianRunState::Executing) {
            return $terminalMessage;
        }

        // A recovery-safe run auto-returns to the queue (the reaper reopens it); a side-effecting
        // vendor run does NOT — it may already have partly run, so it is flagged for manual review
        // and the operator must not blindly retry (that could duplicate the vendor action).
        return $fresh->isRecoverySafeToReopen()
            ? 'This action is still finishing — if it’s stuck, it returns to your approval queue automatically within a few minutes. Try again shortly.'
            : 'This action is still finishing — if it stays stuck it’s flagged for manual review, because it may already have partly run. Check with your admin before retrying.';
    }

    private function actionResponse(
        Request $request,
        bool $ok,
        string $status,
        string $message,
        ?string $flashKey = null,
        int $httpStatus = 200,
        ?array $undo = null,
        ?string $secret = null,
    ) {
        if ($request->expectsJson()) {
            $payload = [
                'ok' => $ok,
                'status' => $status,
                'message' => $message,
                'counts' => app(CockpitQuery::class)->counts(),
            ];

            if ($ok && $undo !== null) {
                $payload['undo'] = $undo;
            }

            // One-time credential delivery (e.g. the CIPP create-user temp
            // password): JSON response only, marked no-store. It is NEVER
            // flashed to the session on the non-JS fallback below — a session
            // file is still storage — so a JS-less approval loses it by design
            // (the remedy is a password reset, not a persisted secret).
            if ($ok && $secret !== null) {
                $payload['secret'] = $secret;

                return response()->json($payload, $httpStatus)
                    ->header('Cache-Control', 'no-store');
            }

            return response()->json($payload, $httpStatus);
        }

        return redirect()
            ->route('cockpit.index')
            ->with($flashKey ?? ($ok ? 'success' : 'error'), $message);
    }

    private function undoApprovedClose(int $runId, int $statusNoteId, string $runUpdatedAt): bool
    {
        return DB::transaction(function () use ($runId, $statusNoteId, $runUpdatedAt): bool {
            $run = TechnicianRun::query()->lockForUpdate()->find($runId);

            if (! $run
                || $statusNoteId <= 0
                || $run->getRawOriginal('updated_at') !== $runUpdatedAt
                || $run->action_type !== 'propose_close'
                || $run->state !== TechnicianRunState::Done
                || ! $this->withinUndoWindow($run)) {
                return false;
            }

            $ticket = $run->ticket;
            $statusNote = TicketNote::query()
                ->whereKey($statusNoteId)
                ->where('ticket_id', $run->ticket_id)
                ->where('note_type', NoteType::StatusChange->value)
                ->where('status_to', TicketStatus::Closed->value)
                ->where('body', TechnicianApprovalService::OPERATOR_APPROVED_CLOSE_NOTE)
                ->first();

            if (! $ticket || $ticket->status !== TicketStatus::Closed || ! $statusNote) {
                return false;
            }

            if ($this->laterStatusChangeExists($statusNote)) {
                return false;
            }

            app(TicketService::class)->changeStatus(
                $ticket,
                TicketStatus::InProgress,
                (int) auth()->id(),
                'Reopened by cockpit undo.',
            );

            $run->advanceTo(TechnicianRunState::AwaitingApproval);

            return true;
        });
    }

    /**
     * True iff ANY status-change note postdates the given one (later noted_at, or
     * same instant with a higher id). Shared reopen guard: if the anchor close note
     * is no longer the ticket's latest status change, the current Closed state is
     * someone else's transition and an undo/reopen must not clobber it.
     */
    private function laterStatusChangeExists(TicketNote $statusNote): bool
    {
        return TicketNote::query()
            ->where('ticket_id', $statusNote->ticket_id)
            ->where('note_type', NoteType::StatusChange->value)
            ->where(function ($query) use ($statusNote) {
                $query->where('noted_at', '>', $statusNote->noted_at)
                    ->orWhere(function ($sameInstant) use ($statusNote) {
                        $sameInstant
                            ->where('noted_at', $statusNote->noted_at)
                            ->where('id', '>', $statusNote->id);
                    });
            })
            ->exists();
    }

    private function undoRunState(
        int $runId,
        ?string $actionType,
        TechnicianRunState $from,
        TechnicianRunState $to,
        string $runUpdatedAt,
    ): bool {
        $query = TechnicianRun::query()
            ->whereKey($runId)
            ->where('state', $from->value)
            ->where('updated_at', $runUpdatedAt)
            ->where('updated_at', '>=', now()->subMinutes(self::UNDO_WINDOW_MINUTES));

        if ($actionType !== null) {
            $query->where('action_type', $actionType);
        } else {
            $query->whereNotIn('action_type', ['flag_attention', 'intake_route']);
        }

        return $query->update(['state' => $to->value]) === 1;
    }

    private function undoSpamCall(int $callId, bool $removeBlock, string $callFollowedUpAt, int $directoryEntryId): bool
    {
        return DB::transaction(function () use ($callId, $removeBlock, $callFollowedUpAt, $directoryEntryId): bool {
            $call = PhoneCall::query()->lockForUpdate()->find($callId);

            if (! $call
                || $call->followed_up_at === null
                || $call->getRawOriginal('followed_up_at') !== $callFollowedUpAt
                || ! $call->followed_up_at->greaterThanOrEqualTo(now()->subMinutes(self::UNDO_WINDOW_MINUTES))) {
                return false;
            }

            if ((int) $call->followed_up_by !== (int) auth()->id()) {
                return false;
            }

            $normalized = PhoneNumber::normalize($call->from_number);
            if ($removeBlock && $normalized !== null && $directoryEntryId > 0) {
                PhoneDirectoryEntry::query()
                    ->whereKey($directoryEntryId)
                    ->where('phone_number', $normalized)
                    ->where('list_type', PhoneDirectoryListType::Blocked->value)
                    ->where('reason', 'AI intake: marked spam by operator')
                    ->where('added_by_user_id', (int) auth()->id())
                    ->delete();
            }

            $call->forceFill([
                'followed_up_at' => null,
                'followed_up_by' => null,
            ])->save();

            return true;
        });
    }

    private function withinUndoWindow($model): bool
    {
        return $model->updated_at !== null
            && $model->updated_at->greaterThanOrEqualTo(now()->subMinutes(self::UNDO_WINDOW_MINUTES));
    }

    /** @return array{action: string, url: string} */
    private function runUndoPayload(TechnicianRun $run, string $action, array $extra = []): array
    {
        $run->refresh();

        return app(CockpitUndoToken::class)->issue(
            'run',
            $run->id,
            $action,
            (int) auth()->id(),
            array_merge(['run_updated_at' => $run->getRawOriginal('updated_at')], $extra),
        );
    }

    /** @return array{action: string, url: string} */
    private function callUndoPayload(PhoneCall $call, string $action, array $extra = []): array
    {
        $call->refresh();

        return app(CockpitUndoToken::class)->issue(
            'call',
            $call->id,
            $action,
            (int) auth()->id(),
            array_merge(['call_followed_up_at' => $call->getRawOriginal('followed_up_at')], $extra),
        );
    }
}
