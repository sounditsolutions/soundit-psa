<?php

namespace App\Http\Controllers\Web;

use App\Enums\ToolingGapStatus;
use App\Http\Controllers\Controller;
use App\Models\ToolingGap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Operator-facing review surface for the AI tooling-gap backlog.
 *
 * ToolingGaps are written by `request_tool` (agent self-reports: missing tools,
 * unused tools, and broken tools) and by operator corrections (LessonCapture).
 * Until now they were visible only via the `tooling-gaps:list` CLI command — this
 * page makes the feedback channel actionable from the web: filter by status,
 * inspect the abstract capability / symptom, and move an item through its lifecycle.
 */
class ToolingGapController extends Controller
{
    public function index(Request $request): View
    {
        // Status filter: a valid status value, or 'all'. Anything else falls back to Open,
        // matching the ToolingGapsList CLI (fromInput fails safe rather than erroring).
        $statusParam = (string) $request->query('status', ToolingGapStatus::Open->value);
        $showAll = ($statusParam === 'all');
        $activeStatus = $showAll ? null : ToolingGapStatus::fromInput($statusParam);

        $query = ToolingGap::query()->with('ticket')->latest();
        if ($activeStatus !== null) {
            $query->where('status', $activeStatus->value);
        }

        $gaps = $query->paginate(50)->withQueryString();

        // Per-status counts for the filter chips (single grouped query).
        $counts = ToolingGap::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return view('settings.tooling-gaps.index', [
            'gaps' => $gaps,
            'statuses' => ToolingGapStatus::cases(),
            'activeStatus' => $activeStatus,
            'showAll' => $showAll,
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
        ]);
    }

    public function update(Request $request, ToolingGap $toolingGap): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(ToolingGapStatus::class)],
        ]);

        $toolingGap->update(['status' => $validated['status']]);

        return redirect()
            ->back()
            ->with('success', "Tooling gap #{$toolingGap->id} marked ".$toolingGap->status->label().'.');
    }
}
