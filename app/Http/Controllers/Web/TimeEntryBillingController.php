<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TimeEntryBillingProposal;
use App\Services\BillableTimeService;
use App\Services\PhoneCallActionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TimeEntryBillingController extends Controller
{
    public function approve(Request $request, TimeEntryBillingProposal $proposal, BillableTimeService $billing)
    {
        abort_unless(app(PhoneCallActionService::class)->canApprove($request->user()), 403);
        try {
            $billing->approve($proposal, $request->user());
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        return back()->with('success', 'Billing change approved.');
    }

    public function deny(Request $request, TimeEntryBillingProposal $proposal, BillableTimeService $billing)
    {
        abort_unless(app(PhoneCallActionService::class)->canApprove($request->user()), 403);
        abort_unless($billing->reject($proposal, $request->user()), 422);

        return back()->with('success', 'Billing change denied.');
    }
}
