<?php

namespace App\Http\Controllers\Web;

use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;

class PrepayController extends Controller
{
    public function index()
    {
        $contracts = Contract::whereNotNull('prepay_balance')
            ->where('status', ContractStatus::Active)
            ->with(['client', 'prepayTransactions' => fn ($q) => $q->where('date', '>=', now()->subDays(30))])
            ->orderBy('prepay_balance')
            ->get();

        return view('prepay.index', [
            'contracts' => $contracts,
        ]);
    }
}
