<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\ClientReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Weekly client report — a QBR-ready service summary generated on demand from
 * the client's own data. Thin controller: report building, AI, and email live
 * in {@see ClientReportService}.
 */
class ClientReportController extends Controller
{
    public function __construct(
        private readonly ClientReportService $reportService,
    ) {}

    /**
     * Render the weekly report page for a client. An optional `?week=YYYY-MM-DD`
     * query param selects any day within the target week (defaults to the
     * current week).
     */
    public function show(Request $request, Client $client)
    {
        $report = $this->reportService->weeklyReport($client, $this->parseWeekStart($request));

        return view('clients.weekly-report', [
            'client' => $client,
            'report' => $report,
        ]);
    }

    /**
     * Regenerate the report for the selected week and email it to the client's
     * primary contact, then return to the report page with a status message.
     */
    public function email(Request $request, Client $client)
    {
        $report = $this->reportService->weeklyReport($client, $this->parseWeekStart($request));

        $result = $this->reportService->emailReport(
            $client,
            $report['markdown'],
            $report['week_start'],
            $report['week_end'],
        );

        $back = redirect()->route('clients.weekly-report', [
            'client' => $client,
            'week' => $report['week_start']->toDateString(),
        ]);

        return $result['sent']
            ? $back->with('success', "Weekly report emailed to {$result['to']}.")
            : $back->with('error', $result['reason']);
    }

    /**
     * Parse the optional week selector into a Carbon date, ignoring garbage.
     */
    private function parseWeekStart(Request $request): ?Carbon
    {
        // input() covers both the GET query string (show) and the POST body (email).
        $week = $request->input('week');

        if (! is_string($week) || trim($week) === '') {
            return null;
        }

        try {
            return Carbon::parse($week);
        } catch (\Throwable) {
            return null;
        }
    }
}
