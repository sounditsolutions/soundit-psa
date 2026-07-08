<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TimeReportController extends Controller
{
    public function index(Request $request)
    {
        $users = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $clients = Client::operational()->orderBy('name')->get(['id', 'name']);

        // Date range defaults to current month
        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : now()->startOfMonth();
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : now()->endOfDay();

        $filters = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'user_id' => $request->query('user_id'),
            'client_id' => $request->query('client_id'),
        ];

        $query = DB::table('ticket_notes')
            ->join('users', 'ticket_notes.author_id', '=', 'users.id')
            ->join('tickets', 'ticket_notes.ticket_id', '=', 'tickets.id')
            ->leftJoin('clients', 'tickets.client_id', '=', 'clients.id')
            ->where('ticket_notes.time_minutes', '>', 0)
            ->whereNull('ticket_notes.deleted_at')
            ->whereBetween('ticket_notes.noted_at', [$from, $to]);

        if ($filters['user_id']) {
            $query->where('ticket_notes.author_id', $filters['user_id']);
        }

        if ($filters['client_id']) {
            $query->where('tickets.client_id', $filters['client_id']);
        }

        // Summary: per-technician totals
        $summary = (clone $query)
            ->select(
                'users.id as user_id',
                'users.name as user_name',
                'users.is_contractor',
                DB::raw('SUM(ticket_notes.time_minutes) as total_minutes'),
                DB::raw('COUNT(DISTINCT ticket_notes.ticket_id) as ticket_count'),
                DB::raw('COUNT(ticket_notes.id) as note_count'),
            )
            ->groupBy('users.id', 'users.name', 'users.is_contractor')
            ->orderByDesc('total_minutes')
            ->get();

        $grandTotalMinutes = $summary->sum('total_minutes');

        return view('reports.time', [
            'users' => $users,
            'clients' => $clients,
            'filters' => $filters,
            'summary' => $summary,
            'grandTotalMinutes' => $grandTotalMinutes,
        ]);
    }
}
