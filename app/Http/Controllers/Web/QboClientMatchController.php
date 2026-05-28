<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboClientException;
use App\Services\Qbo\QboSyncService;
use Illuminate\Http\Request;

class QboClientMatchController extends Controller
{
    public function index(QboClient $qboClient, QboSyncService $syncService)
    {
        if (!$qboClient->isConnected()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Connect to QuickBooks first.');
        }

        try {
            $qboCustomers = $syncService->fetchQboCustomers();
        } catch (QboClientException) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Could not fetch QBO customers. Check connection.');
        }

        // Build mapping: qbo_customer_id → client
        $mappedClients = Client::whereNotNull('qbo_customer_id')
            ->get(['id', 'name', 'qbo_customer_id'])
            ->keyBy('qbo_customer_id');

        $allClients = Client::active()->orderBy('name')->get(['id', 'name']);

        return view('settings.qbo-clients', [
            'qboCustomers' => $qboCustomers,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);

        // Clear all existing QBO customer mappings
        Client::whereNotNull('qbo_customer_id')->update([
            'qbo_customer_id' => null,
            'qbo_display_name' => null,
        ]);

        // Apply new mappings
        $mapped = 0;
        foreach ($mappings as $qboCustomerId => $data) {
            $clientId = $data['client_id'] ?? null;
            $displayName = $data['display_name'] ?? null;

            if ($clientId) {
                Client::where('id', $clientId)->update([
                    'qbo_customer_id' => $qboCustomerId,
                    'qbo_display_name' => $displayName,
                ]);
                $mapped++;
            }
        }

        return redirect()->route('settings.qbo-clients.index')
            ->with('success', "Saved {$mapped} QBO customer mapping(s).");
    }

    public function autoMatch(QboSyncService $syncService)
    {
        try {
            $result = $syncService->autoMatchClients();
        } catch (QboClientException $e) {
            return redirect()->route('settings.qbo-clients.index')
                ->with('error', 'Could not fetch QBO customers: ' . $e->getMessage());
        }

        $matchedCount = count($result['matched']);
        $unmatchedCount = count($result['unmatched']);
        $ambiguousCount = count($result['ambiguous']);

        $msg = "Auto-matched {$matchedCount} client(s).";
        if ($unmatchedCount > 0) {
            $msg .= " {$unmatchedCount} unmatched.";
        }
        if ($ambiguousCount > 0) {
            $msg .= " {$ambiguousCount} ambiguous (multiple QBO matches — review manually).";
        }

        return redirect()->route('settings.qbo-clients.index')
            ->with('success', $msg);
    }
}
