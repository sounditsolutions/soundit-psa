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
            'needs' => $query->needsAttention(),
        ]);
    }

    public function approve(Request $request, TechnicianRun $run, TechnicianApprovalService $service)
    {
        // Dispatch on action_type so future tools (reply, escalate) plug in without rework.
        if ($run->action_type === 'propose_close') {
            $result = $service->approveClose($run, (int) auth()->id());
        } else {
            // Body is required only on the reply/resolution path, not the close path.
            $validated = $request->validate(['body' => ['required', 'string']]);
            $result = $service->approveAndSend($run, $validated['body'], (int) auth()->id());
        }

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
}
