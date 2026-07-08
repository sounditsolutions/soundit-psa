<?php

namespace App\Http\Controllers\Web;

use App\Enums\ContractorTimeSource;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ContractorTimeService;
use Illuminate\Http\Request;

class ContractorTimePoolController extends Controller
{
    public function __construct(
        private ContractorTimeService $contractorTimeService
    ) {}

    public function show(User $user)
    {
        abort_unless($user->is_contractor, 404);

        $balance = $this->contractorTimeService->getBalance($user);
        $creditTotal = $this->contractorTimeService->getCreditTotal($user);
        $debitTotal = $this->contractorTimeService->getDebitTotal($user);
        $consumed = $this->contractorTimeService->getConsumedHours($user);
        $burnRate = $this->contractorTimeService->getBurnRate($user);
        $timeEntries = $this->contractorTimeService->getTimeEntries($user);
        $transactions = $this->contractorTimeService->getTransactions($user);

        return view('contractors.time-pool', [
            'contractor' => $user,
            'balance' => $balance,
            'creditTotal' => $creditTotal,
            'debitTotal' => $debitTotal,
            'consumed' => $consumed,
            'burnRate' => $burnRate,
            'timeEntries' => $timeEntries,
            'transactions' => $transactions,
            'sources' => ContractorTimeSource::cases(),
        ]);
    }

    public function store(Request $request, User $user)
    {
        abort_unless($user->is_contractor, 404);

        $validated = $request->validate([
            'source' => ['required', 'in:manual_credit,manual_debit,initial_balance'],
            'hours' => ['required', 'numeric', 'min:0.01', 'max:9999'],
            'description' => ['required', 'string', 'max:500'],
        ]);

        $source = ContractorTimeSource::from($validated['source']);

        match ($source) {
            ContractorTimeSource::ManualCredit => $this->contractorTimeService->addCredit(
                $user,
                (float) $validated['hours'],
                $validated['description'],
                $request->user()
            ),
            ContractorTimeSource::ManualDebit => $this->contractorTimeService->addDebit(
                $user,
                (float) $validated['hours'],
                $validated['description'],
                $request->user()
            ),
            ContractorTimeSource::InitialBalance => $this->contractorTimeService->addInitialBalance(
                $user,
                (float) $validated['hours'],
                $validated['description'],
                $request->user()
            ),
        };

        $action = $source->isCredit() ? 'credited' : 'debited';

        return redirect()->route('contractors.time-pool', $user)
            ->with('success', "Successfully {$action} {$validated['hours']} hours.");
    }
}
