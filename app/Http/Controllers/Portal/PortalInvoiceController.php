<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalInvoiceController extends Controller
{
    /** Statuses visible to portal clients. */
    private const PORTAL_STATUSES = [
        InvoiceStatus::Posted,
        InvoiceStatus::Synced,
        InvoiceStatus::Paid,
    ];

    public function index(Request $request): View
    {
        $clientId = $request->attributes->get('portal_client_id');

        $invoices = Invoice::where('client_id', $clientId)
            ->whereIn('status', self::PORTAL_STATUSES)
            ->orderByDesc('invoice_date')
            ->paginate(25);

        return view('portal.invoices.index', compact('invoices'));
    }

    public function show(Request $request, Invoice $invoice): View
    {
        $clientId = $request->attributes->get('portal_client_id');

        if ($invoice->client_id !== $clientId) {
            abort(403);
        }

        if (! in_array($invoice->status, self::PORTAL_STATUSES, true)) {
            abort(404);
        }

        $invoice->load('lines');

        return view('portal.invoices.show', compact('invoice'));
    }
}
