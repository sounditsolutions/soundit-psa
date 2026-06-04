<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\License;
use App\Services\Ninja\NinjaClient;
use App\Services\Ninja\NinjaClientException;
use Illuminate\Http\Request;

class NinjaOrgController extends Controller
{
    public function index(NinjaClient $ninja)
    {
        try {
            $orgs = $ninja->getOrganizations();
        } catch (NinjaClientException) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Could not connect to NinjaRMM. Check credentials.');
        }

        // Sort orgs by name
        usort($orgs, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        // Build mapping: ninja_org_id → client
        $mappedClients = Client::whereNotNull('ninja_org_id')
            ->get(['id', 'name', 'ninja_org_id'])
            ->keyBy('ninja_org_id');

        return view('settings.ninja-orgs', [
            'orgs' => $orgs,
            'mappedClients' => $mappedClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);

        $previouslyMapped = Client::whereNotNull('ninja_org_id')->pluck('id');

        // First, clear all existing ninja_org_id mappings
        Client::whereNotNull('ninja_org_id')->update(['ninja_org_id' => null]);

        // Apply new mappings
        $mapped = 0;
        foreach ($mappings as $ninjaOrgId => $clientId) {
            if ($clientId) {
                Client::where('id', $clientId)->update(['ninja_org_id' => (int) $ninjaOrgId]);
                $mapped++;
            }
        }

        // Deactivate licenses for clients that lost their mapping
        $stillMapped = Client::whereNotNull('ninja_org_id')->pluck('id');
        $unmapped = $previouslyMapped->diff($stillMapped);
        License::deactivateForClients($unmapped, 'ninjaone');

        return redirect()->route('settings.ninja-orgs.index')
            ->with('success', "Saved {$mapped} organization mapping(s).");
    }

    public function apiOrgs(NinjaClient $ninja)
    {
        try {
            $orgs = $ninja->getOrganizations();
            usort($orgs, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

            return response()->json($orgs);
        } catch (NinjaClientException) {
            return response()->json([], 503);
        }
    }

    public function apiClientSearch(Request $request)
    {
        $term = $request->query('q', '');
        if (strlen($term) < 2) {
            return response()->json([]);
        }

        $clients = Client::active()
            ->search($term)
            ->limit(20)
            ->get(['id', 'name']);

        return response()->json($clients);
    }
}
