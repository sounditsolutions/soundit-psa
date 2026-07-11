<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\QboBankAccount;
use App\Models\QboExpense;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboClientException;
use App\Services\Qbo\QboSyncService;

class QboFinancialsController extends Controller
{
    /**
     * Read-only view of the bank balances and expenses synced from QBO.
     */
    public function index(QboClient $qboClient)
    {
        $connected = $qboClient->isConnected();

        $bankAccounts = QboBankAccount::orderBy('name')->get();
        $expenses = QboExpense::orderByDesc('txn_date')->orderByDesc('id')->limit(100)->get();

        $totalCash = (float) $bankAccounts->where('active', true)->sum('current_balance');

        $lastSyncedAt = $bankAccounts->max('qbo_synced_at')
            ?? $expenses->max('qbo_synced_at');

        return view('qbo.financials', compact(
            'connected',
            'bankAccounts',
            'expenses',
            'totalCash',
            'lastSyncedAt',
        ));
    }

    /**
     * Trigger an on-demand sync of bank balances and recent expenses.
     */
    public function sync(QboClient $qboClient, QboSyncService $syncService)
    {
        if (! $qboClient->isConnected()) {
            return redirect()->route('qbo.financials.index')
                ->with('error', 'Not connected to QuickBooks Online. Connect in Settings → Integrations.');
        }

        try {
            $balances = $syncService->syncBankBalances();
            $expenses = $syncService->syncExpenses(now()->subDays(90)->format('Y-m-d'));
        } catch (QboClientException $e) {
            return redirect()->route('qbo.financials.index')
                ->with('error', 'QBO sync failed: '.$e->getMessage());
        }

        return redirect()->route('qbo.financials.index')
            ->with('success', "Synced {$balances['synced']} bank account(s) and {$expenses['synced']} expense(s) from QuickBooks.");
    }
}
