<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\License;
use App\Services\Zorus\ZorusClient;
use App\Services\Zorus\ZorusClientException;
use App\Support\ZorusConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ZorusCustomerController extends Controller
{
    public function index()
    {
        if (! ZorusConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Zorus is not configured. Add API credentials first.');
        }

        try {
            $client = new ZorusClient([
                'api_key' => ZorusConfig::get('api_key'),
            ]);

            // Fetch all customers (paginate if >100)
            $customers = [];
            $page = 1;
            do {
                $batch = $client->searchCustomers([], $page, 100);
                $customers = array_merge($customers, $batch);
                $page++;
            } while (count($batch) === 100);
        } catch (ZorusClientException $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "Could not connect to Zorus: {$e->getMessage()}");
        }

        // Sort by name
        usort($customers, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        // Build mapping: zorus_customer_id → client
        $mappedClients = Client::whereNotNull('zorus_customer_id')
            ->get(['id', 'name', 'zorus_customer_id'])
            ->keyBy('zorus_customer_id');

        $allClients = Client::active()->orderBy('name')->get(['id', 'name']);

        return view('settings.zorus-customers', [
            'customers' => $customers,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);

        DB::transaction(function () use ($mappings) {
            $previouslyMapped = Client::whereNotNull('zorus_customer_id')->pluck('id');

            // Clear existing mappings
            Client::whereNotNull('zorus_customer_id')->update(['zorus_customer_id' => null]);

            // Apply new mappings
            foreach ($mappings as $customerUuid => $clientId) {
                if ($clientId) {
                    Client::where('id', $clientId)->update(['zorus_customer_id' => $customerUuid]);
                }
            }

            $stillMapped = Client::whereNotNull('zorus_customer_id')->pluck('id');
            $unmapped = $previouslyMapped->diff($stillMapped);
            License::deactivateForClients($unmapped, 'zorus');
        });

        $mapped = collect($mappings)->filter()->count();

        return redirect()->route('settings.zorus-customers.index')
            ->with('success', "Saved {$mapped} Zorus customer mapping(s).");
    }

    /**
     * Auto-match Zorus customers to clients by exact name match (case-insensitive).
     * Only fills unmapped customers — never overwrites existing mappings.
     */
    public function autoMatch()
    {
        if (! ZorusConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Zorus is not configured.');
        }

        try {
            $client = new ZorusClient([
                'api_key' => ZorusConfig::get('api_key'),
            ]);

            $customers = [];
            $page = 1;
            do {
                $batch = $client->searchCustomers([], $page, 100);
                $customers = array_merge($customers, $batch);
                $page++;
            } while (count($batch) === 100);
        } catch (ZorusClientException $e) {
            return redirect()->route('settings.zorus-customers.index')
                ->with('error', "Could not connect to Zorus: {$e->getMessage()}");
        }

        // Build lookup: lowercase client name → client
        $clientsByName = Client::active()
            ->whereNull('zorus_customer_id')
            ->get(['id', 'name'])
            ->keyBy(fn ($c) => mb_strtolower($c->name));

        $matched = 0;

        foreach ($customers as $customer) {
            $customerUuid = $customer['uuid'] ?? null;
            $customerName = $customer['name'] ?? null;

            if (! $customerUuid || ! $customerName) {
                continue;
            }

            // Skip if this customer is already mapped
            if (Client::where('zorus_customer_id', $customerUuid)->exists()) {
                continue;
            }

            $matchedClient = $clientsByName->get(mb_strtolower($customerName));

            if ($matchedClient) {
                Client::where('id', $matchedClient->id)->update(['zorus_customer_id' => $customerUuid]);
                $clientsByName->forget(mb_strtolower($customerName));
                $matched++;
            }
        }

        $message = $matched > 0
            ? "Auto-matched {$matched} customer(s) by name."
            : 'No new matches found. Customers may need manual mapping.';

        return redirect()->route('settings.zorus-customers.index')
            ->with($matched > 0 ? 'success' : 'info', $message);
    }
}
