<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Support\PortalConfig;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalContractController extends Controller
{
    public function index(Request $request): View
    {
        $clientId = $request->attributes->get('portal_client_id');

        $contracts = Contract::where('client_id', $clientId)
            ->active()
            ->orderBy('name')
            ->paginate(25);

        return view('portal.contracts.index', compact('contracts'));
    }

    public function show(Request $request, Contract $contract): View
    {
        $clientId = $request->attributes->get('portal_client_id');
        $portalPerson = $request->attributes->get('portal_person');

        if ($contract->client_id !== $clientId) {
            abort(403);
        }

        $contract->load('assets', 'people');

        $prepayTransactions = null;
        if ($portalPerson?->company_wide_access && $contract->has_prepay && ! $contract->prepay_as_amount) {
            $prepayTransactions = $contract->prepayTransactions()
                ->orderByDesc('date')
                ->paginate(20);
        }

        return view('portal.contracts.show', compact('contract', 'prepayTransactions'));
    }
}
