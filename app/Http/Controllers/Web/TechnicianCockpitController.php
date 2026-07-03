<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PhoneDirectoryEntry;
use App\Models\TechnicianRun;
use App\Services\PhoneCallService;
use App\Services\Technician\Cockpit\CockpitQuery;
use App\Services\Technician\TechnicianApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TechnicianCockpitController extends Controller
{
    public function index(CockpitQuery $query)
    {
        return view('cockpit.index', [
            'drafts' => $query->pendingDrafts(),
            'flagged' => $query->flaggedForAttention(),
            'needs' => $query->needsAttention(),
            'intake' => $query->intakeReview(),
            'intakeSpam' => $query->intakeSpamReview(),
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
            'tactical_stage_maintenance' => $service->approveStagedTacticalAction($run, (int) auth()->id()),
            // Body is required only on the reply/resolution path, validated inside this arm.
            'send_reply', 'propose_resolution' => $service->approveAndSend(
                $run,
                $request->validate(['body' => ['required', 'string']])['body'],
                (int) auth()->id(),
            ),
            default => abort(422, 'Unsupported action type for approval.'),
        };

        return redirect()->route('cockpit.index')->with(
            in_array($result->status, ['sent', 'closed', 'published', 'merged', 'executed'], true) ? 'success' : 'error',
            match ($result->status) {
                'sent' => 'Reply approved and sent.',
                'closed' => 'Ticket closed.',
                'published' => 'Public note published.',
                'merged' => 'Tickets merged.',
                'executed' => 'Tactical action approved and executed.',
                'already_handled' => 'That draft was already handled.',
                default => 'Could not send — the Technician declined (it may be paused). Try again.',
            },
        );
    }

    public function deny(TechnicianRun $run, TechnicianApprovalService $service)
    {
        $service->deny($run);

        return redirect()->route('cockpit.index')->with('success', 'Draft dismissed; the ticket is back with your team.');
    }

    /**
     * Acknowledge a held flag (Increment H): the operator has it. Flagged → Done,
     * no execution, no client-facing consequence. The CAS guard on the model makes
     * this a safe no-op on anything that is not a held flag.
     */
    public function acknowledge(TechnicianRun $run)
    {
        $run->acknowledgeFlag();

        return redirect()->route('cockpit.index')->with('success', 'Flag acknowledged — it’s with you now.');
    }

    /** Dismiss a held flag: not something a person needs after all. Flagged → Denied. */
    public function dismiss(TechnicianRun $run)
    {
        $run->dismissFlag();

        return redirect()->route('cockpit.index')->with('success', 'Flag dismissed.');
    }

    /**
     * Dismiss a held intake suggestion (operator has reviewed the calibration signal).
     * Transitions intake_route AwaitingApproval → Done via CAS guard (no-op if already
     * resolved or if the run is not an intake_route). Visibility only — no merge action.
     */
    public function intakeDismiss(TechnicianRun $run)
    {
        $run->dismissIntake();

        return redirect()->route('cockpit.index')->with('success', 'Intake suggestion dismissed.');
    }

    /**
     * One-tap "mark followed-up + block number" for a suspected-spam call (psa-xcyo Task 6b).
     *
     * Stamps followed_up_at (removing the call from the spam lane) AND adds the caller to the
     * Blocked phone directory. Both writes are wrapped in a transaction. The action is idempotent:
     * a repeat tap re-stamps followed_up_at (harmless) and updateOrCreate is a no-op-or-update
     * (no duplicate / no crash). A null from_number (non-normalizable) blocks nothing but still
     * marks the call followed-up.
     */
    public function intakeSpamBlock(\App\Models\PhoneCall $call, PhoneCallService $phoneCallService)
    {
        DB::transaction(function () use ($call, $phoneCallService) {
            $phoneCallService->markFollowedUp($call, (int) auth()->id());
            $normalized = \App\Support\PhoneNumber::normalize($call->from_number);
            if ($normalized !== null) {
                PhoneDirectoryEntry::updateOrCreate(
                    ['phone_number' => $normalized],
                    [
                        'list_type' => \App\Enums\PhoneDirectoryListType::Blocked,
                        'reason' => 'AI intake: marked spam by operator',
                        'added_by_user_id' => (int) auth()->id(),
                    ],
                );
            }
        });

        return redirect()->route('cockpit.index')->with('success', 'Caller marked followed-up and the number was blocked.');
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

        return redirect()->route('cockpit.index')
            ->with('success', "Re-assessing #{$ticket->id} with your correction.");
    }
}
