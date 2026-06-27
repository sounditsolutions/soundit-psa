<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TechnicianRun;
use App\Services\Technician\Cockpit\CockpitQuery;
use App\Services\Technician\TechnicianApprovalService;
use Illuminate\Http\Request;

class TechnicianCockpitController extends Controller
{
    public function index(CockpitQuery $query)
    {
        return view('cockpit.index', [
            'drafts' => $query->pendingDrafts(),
            'flagged' => $query->flaggedForAttention(),
            'needs' => $query->needsAttention(),
        ]);
    }

    public function approve(Request $request, TechnicianRun $run, TechnicianApprovalService $service)
    {
        // Dispatch on action_type so future tools (reply, escalate) plug in without rework.
        // Fail-closed: an unrecognized action type must NOT fall through to a send.
        $result = match ($run->action_type) {
            'propose_close' => $service->approveClose($run, (int) auth()->id()),
            // Body is required only on the reply/resolution path, validated inside this arm.
            'send_reply', 'propose_resolution' => $service->approveAndSend(
                $run,
                $request->validate(['body' => ['required', 'string']])['body'],
                (int) auth()->id(),
            ),
            default => abort(422, 'Unsupported action type for approval.'),
        };

        return redirect()->route('cockpit.index')->with(
            in_array($result->status, ['sent', 'closed'], true) ? 'success' : 'error',
            match ($result->status) {
                'sent' => 'Reply approved and sent.',
                'closed' => 'Ticket closed.',
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
