<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\License;
use App\Services\AppRiver\AppRiverClient;
use App\Services\AppRiver\AppRiverClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppRiverCustomerController extends Controller
{
    public function index()
    {
        if (! AppRiverClient::isConnected()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'AppRiver is not connected. Click "Connect to AppRiver" first.');
        }

        try {
            $client = new AppRiverClient;

            $customers = $client->getCustomers();
        } catch (AppRiverClientException $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "Could not connect to AppRiver: {$e->getMessage()}");
        }

        // Sort by name
        usort($customers, fn ($a, $b) => strcasecmp($a['Name'] ?? '', $b['Name'] ?? ''));

        // Build mapping: appriver_customer_id → client
        $mappedClients = Client::whereNotNull('appriver_customer_id')
            ->get(['id', 'name', 'appriver_customer_id'])
            ->keyBy('appriver_customer_id');

        $allClients = Client::active()->orderBy('name')->get(['id', 'name']);

        return view('settings.appriver-customers', [
            'customers' => $customers,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);

        DB::transaction(function () use ($mappings) {
            $previouslyMapped = Client::whereNotNull('appriver_customer_id')->pluck('id');

            // Clear existing mappings
            Client::whereNotNull('appriver_customer_id')->update(['appriver_customer_id' => null]);

            // Apply new mappings
            foreach ($mappings as $customerId => $clientId) {
                if ($clientId) {
                    Client::where('id', $clientId)->update(['appriver_customer_id' => $customerId]);
                }
            }

            $stillMapped = Client::whereNotNull('appriver_customer_id')->pluck('id');
            $unmapped = $previouslyMapped->diff($stillMapped);
            License::deactivateForClients($unmapped, 'appriver');
        });

        $mapped = collect($mappings)->filter()->count();

        return redirect()->route('settings.appriver-customers.index')
            ->with('success', "Saved {$mapped} AppRiver customer mapping(s).");
    }

    /**
     * Auto-match AppRiver customers to clients by exact name match (case-insensitive).
     * Only fills unmapped customers — never overwrites existing mappings.
     */
    public function autoMatch()
    {
        if (! AppRiverClient::isConnected()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'AppRiver is not connected.');
        }

        try {
            $client = new AppRiverClient;

            $customers = $client->getCustomers();
        } catch (AppRiverClientException $e) {
            return redirect()->route('settings.appriver-customers.index')
                ->with('error', "Could not connect to AppRiver: {$e->getMessage()}");
        }

        // Build lookup: lowercase client name → client
        $clientsByName = Client::active()
            ->whereNull('appriver_customer_id')
            ->get(['id', 'name'])
            ->keyBy(fn ($c) => mb_strtolower($c->name));

        $matched = 0;

        foreach ($customers as $customer) {
            $customerId = $customer['CustomerId'] ?? null;
            $customerName = $customer['Name'] ?? null;

            if (! $customerId || ! $customerName) {
                continue;
            }

            // Skip if this customer is already mapped
            if (Client::where('appriver_customer_id', $customerId)->exists()) {
                continue;
            }

            $matchedClient = $clientsByName->get(mb_strtolower($customerName));

            if ($matchedClient) {
                Client::where('id', $matchedClient->id)->update(['appriver_customer_id' => $customerId]);
                $clientsByName->forget(mb_strtolower($customerName));
                $matched++;
            }
        }

        $message = $matched > 0
            ? "Auto-matched {$matched} customer(s) by name."
            : 'No new matches found. Customers may need manual mapping.';

        return redirect()->route('settings.appriver-customers.index')
            ->with($matched > 0 ? 'success' : 'info', $message);
    }
}
