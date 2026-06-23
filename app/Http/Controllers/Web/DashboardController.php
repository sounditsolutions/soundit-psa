<?php

namespace App\Http\Controllers\Web;

use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\RecurringInvoiceProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityStreamService;
use App\Services\BillingService;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ActivityStreamService $activityStream,
    ) {}

    public function index()
    {
        $stats = $this->activityStream->getDashboardStats();
        $stream = $this->activityStream->getStream();
        $profitability = $this->getManagedServicesProfitability();

        $ticketService = app(TicketService::class);
        $ticketFilters = [
            'assignee_id' => 'all',
            'sort' => 'priority',
            'direction' => 'asc',
        ];
        $tickets = $ticketService->getTicketList($ticketFilters);
        $unassignedCount = Ticket::open()->whereNull('assignee_id')->count();

        return view('dashboard.index', compact(
            'stats', 'stream', 'profitability', 'tickets',
        ) + [
            'ticketFilters' => $ticketFilters,
            'ticketClients' => Client::operational()->orderBy('name')->get(['id', 'name']),
            'ticketUsers' => User::active()->orderBy('name')->get(['id', 'name']),
            'ticketStatuses' => TicketStatus::cases(),
            'ticketPriorities' => TicketPriority::cases(),
            'ticketTypes' => TicketType::cases(),
            'ticketSources' => TicketSource::cases(),
            'unassignedCount' => $unassignedCount,
        ]);
    }

    /**
     * Estimated monthly managed services profitability.
     * MRR from active recurring profiles, license costs from license types.
     * Cached 1 hour since quantity resolution can be expensive.
     */
    private function getManagedServicesProfitability(): array
    {
        return Cache::remember('dashboard:managed_profitability', 3600, function () {
            $billingService = app(BillingService::class);

            // Calculate MRR from active recurring profiles
            $mrr = 0;
            $profiles = RecurringInvoiceProfile::where('is_active', true)
                ->whereHas('contract', fn ($q) => $q->where('status', 'Active'))
                ->with(['contract.client', 'lines.sku'])
                ->get();

            foreach ($profiles as $profile) {
                try {
                    $preview = $billingService->previewInvoice($profile);
                    $periodMonths = $profile->billing_period->months();
                    $mrr += $preview['subtotal'] / $periodMonths;
                } catch (\Throwable $e) {
                    // Skip profiles that fail to preview
                }
            }

            // Calculate total monthly license costs
            $licenseCost = 0;
            $licenseTypes = LicenseType::where('is_active', true)->get();

            foreach ($licenseTypes as $type) {
                $totalQty = (int) License::where('license_type_id', $type->id)
                    ->where('status', 'active')
                    ->sum('quantity');

                $cost = $type->estimateCost($totalQty);
                if ($cost !== null && $cost > 0) {
                    $licenseCost += $cost;
                }
            }

            return [
                'mrr' => round($mrr, 2),
                'license_cost' => round($licenseCost, 2),
                'profit' => round($mrr - $licenseCost, 2),
            ];
        });
    }

    public function refreshProfitability()
    {
        Cache::forget('dashboard:managed_profitability');

        return redirect()->route('dashboard')->with('success', 'Profitability estimates refreshed.');
    }

    public function activity(Request $request)
    {
        $request->validate([
            'since' => 'nullable|date',
            'before' => 'nullable|date',
            'types' => 'nullable|string',
        ]);

        $types = $request->filled('types')
            ? array_filter(explode(',', $request->input('types')))
            : [];

        if ($request->filled('since')) {
            $since = Carbon::parse($request->input('since'));
            $stream = $this->activityStream->getStreamSince($since, $types);
            $stats = $this->activityStream->getDashboardStats();

            return response()->json([
                'html' => view('dashboard._activity-stream', compact('stream'))->render(),
                'count' => $stream->count(),
                'stats' => $stats,
            ]);
        }

        $before = $request->filled('before')
            ? Carbon::parse($request->input('before'))
            : null;

        $stream = $this->activityStream->getStream($before, 30, $types);

        return view('dashboard._activity-stream', compact('stream'));
    }
}
