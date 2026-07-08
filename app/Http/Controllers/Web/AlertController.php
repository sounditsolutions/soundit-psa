<?php

namespace App\Http\Controllers\Web;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Client;
use App\Services\AlertService;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function __construct(
        private readonly AlertService $alertService,
    ) {}

    public function index(Request $request)
    {
        $query = Alert::with(['asset', 'client', 'ticket', 'acknowledgedByUser'])
            ->orderByDesc('fired_at');

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } else {
            // Default: show open alerts only
            $query->open();
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->filled('source')) {
            $query->where('source', $request->input('source'));
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->input('client_id'));
        }

        $alerts = $query->paginate(50)->withQueryString();

        // Counts for the summary bar (open alerts by severity)
        $counts = Alert::open()
            ->selectRaw('severity, count(*) as count')
            ->groupBy('severity')
            ->pluck('count', 'severity');

        return view('alerts.index', [
            'alerts' => $alerts,
            'counts' => $counts,
            'statuses' => AlertStatus::cases(),
            'severities' => AlertSeverity::cases(),
            'sources' => AlertSource::cases(),
            'clients' => Client::operational()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['status', 'severity', 'source', 'client_id']),
        ]);
    }

    public function acknowledge(Alert $alert)
    {
        $this->alertService->acknowledge($alert, auth()->user());

        return back()->with('success', 'Alert acknowledged: '.$alert->title);
    }

    public function createTicket(Alert $alert)
    {
        if ($alert->ticket_id) {
            return redirect()->route('tickets.show', $alert->ticket)
                ->with('success', 'Ticket already exists for this alert.');
        }

        $ticket = $this->alertService->createTicket($alert, auth()->id());

        if ($ticket) {
            return redirect()->route('tickets.show', $ticket)
                ->with('success', "Ticket #{$ticket->id} created from alert.");
        }

        return back()->with('error', 'Failed to create ticket from alert.');
    }

    public function attachTicket(Request $request, Alert $alert)
    {
        $request->validate(['ticket_id' => 'required|exists:tickets,id']);

        $this->alertService->attachToTicket($alert, (int) $request->input('ticket_id'), auth()->id());

        return back()->with('success', "Alert attached to ticket #{$request->input('ticket_id')}.");
    }

    public function resolve(Alert $alert)
    {
        $this->alertService->resolve($alert, 'Manually resolved by '.auth()->user()->name);

        return back()->with('success', 'Alert resolved: '.$alert->title);
    }

    public function bulkAcknowledge(Request $request)
    {
        $ids = $request->input('alert_ids', []);
        $count = $this->alertService->bulkAcknowledge($ids, auth()->user());

        return back()->with('success', "{$count} alert(s) acknowledged.");
    }

    public function bulkCreateTickets(Request $request)
    {
        $ids = $request->input('alert_ids', []);
        $count = $this->alertService->bulkCreateTickets($ids, auth()->id());

        return back()->with('success', "{$count} ticket(s) created.");
    }

    public function bulkResolve(Request $request)
    {
        $ids = $request->input('alert_ids', []);
        $count = $this->alertService->bulkResolve($ids);

        return back()->with('success', "{$count} alert(s) resolved.");
    }
}
