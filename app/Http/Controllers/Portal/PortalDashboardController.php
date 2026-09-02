<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $person = $request->attributes->get('portal_person');
        $clientId = $request->attributes->get('portal_client_id');

        $ticketQuery = Ticket::where('client_id', $clientId);
        if (! $person->company_wide_access) {
            $ticketQuery->where('contact_id', $person->id);
        }

        $openTickets = (clone $ticketQuery)->open()->count();

        $unpaidTotal = Invoice::where('client_id', $clientId)
            ->whereIn('status', [InvoiceStatus::Posted, InvoiceStatus::Synced])
            ->sum('total');

        $recentTickets = (clone $ticketQuery)
            ->with('categoryNode.parent.parent')
            ->latest('updated_at')
            ->limit(5)
            ->get();

        $unpaidInvoices = Invoice::where('client_id', $clientId)
            ->whereIn('status', [InvoiceStatus::Posted, InvoiceStatus::Synced])
            // #1173: each row's "partially paid" note reads the last
            // QBO-sourced status change.
            ->with('latestQboStatusChange')
            ->orderByDesc('due_date')
            ->limit(5)
            ->get();

        // Prepay balance from active contracts with prepay (hours-based only for portal display)
        $prepayContracts = Contract::where('client_id', $clientId)
            ->active()
            ->whereNotNull('prepay_balance')
            ->where(function ($q) {
                $q->where('prepay_as_amount', false)->orWhereNull('prepay_as_amount');
            })
            ->get(['id', 'name', 'prepay_balance']);

        $totalPrepayHours = $prepayContracts->sum('prepay_balance');

        // Check if client has any contracts configured for portal prepaid purchases
        $hasPurchasableContracts = Contract::where('client_id', $clientId)
            ->active()
            ->whereNotNull('portal_prepay_sku_id')
            ->where(fn ($q) => $q->where('prepay_as_amount', false)->orWhereNull('prepay_as_amount'))
            ->exists();

        // #864: install tokens expire, and PortalInstallService::findByToken()
        // rejects an expired one with the same "not valid" copy an invalid
        // token gets. Apply the same predicate here or the dashboard keeps
        // advertising a link that dead-ends the moment the TTL lapses. A NULL
        // expiry is the deliberate per-row "no expiry" exception.
        $installToken = \App\Models\Client::where('id', $clientId)
            ->where(fn ($q) => $q->whereNull('portal_install_token_expires_at')
                ->orWhere('portal_install_token_expires_at', '>', now()))
            ->value('portal_install_token');

        return view('portal.dashboard', compact(
            'person',
            'openTickets',
            'unpaidTotal',
            'recentTickets',
            'unpaidInvoices',
            'prepayContracts',
            'totalPrepayHours',
            'hasPurchasableContracts',
            'clientId',
            'installToken',
        ));
    }
}
