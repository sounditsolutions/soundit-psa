<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\License;
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
use App\Support\MeshConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeshCustomerController extends Controller
{
    public function index()
    {
        if (! MeshConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Mesh is not configured. Add API key first.');
        }

        try {
            $meshClient = new MeshClient([
                'api_key' => MeshConfig::get('api_key'),
                'base_url' => MeshConfig::get('base_url'),
            ]);
            $customers = $meshClient->getCustomers(size: 200);
        } catch (MeshClientException $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "Could not connect to Mesh: {$e->getMessage()}");
        }

        // Sort customers by company_name
        usort($customers, fn ($a, $b) => strcasecmp($a['company_name'] ?? '', $b['company_name'] ?? ''));

        // Build mapping: mesh_customer_id → client
        $mappedClients = Client::whereNotNull('mesh_customer_id')
            ->get(['id', 'name', 'mesh_customer_id'])
            ->keyBy('mesh_customer_id');

        $allClients = Client::operational()->orderBy('name')->get(['id', 'name']);

        return view('settings.mesh-customers', [
            'customers' => $customers,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);

        DB::transaction(function () use ($mappings) {
            // Capture previously mapped client IDs before clearing
            $previouslyMapped = Client::whereNotNull('mesh_customer_id')->pluck('id');

            // Clear existing mappings
            Client::whereNotNull('mesh_customer_id')->update(['mesh_customer_id' => null]);

            // Apply new mappings
            foreach ($mappings as $meshCustomerId => $clientId) {
                if ($clientId) {
                    Client::where('id', $clientId)->update(['mesh_customer_id' => $meshCustomerId]);
                }
            }

            // Deactivate licenses for clients that lost their mapping
            $stillMapped = Client::whereNotNull('mesh_customer_id')->pluck('id');
            $unmapped = $previouslyMapped->diff($stillMapped);
            License::deactivateForClients($unmapped, 'mesh');
        });

        $mapped = collect($mappings)->filter()->count();

        return redirect()->route('settings.mesh-customers.index')
            ->with('success', "Saved {$mapped} Mesh customer mapping(s).");
    }
}
