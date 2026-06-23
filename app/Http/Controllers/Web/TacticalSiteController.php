<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use Illuminate\Http\Request;

class TacticalSiteController extends Controller
{
    public function index(TacticalClient $tactical)
    {
        try {
            $tacticalClients = $tactical->getClients();
        } catch (TacticalClientException) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Could not connect to Tactical RMM. Check credentials.');
        }

        // Build flat list of client/site combinations, sorted by client name then site name
        $sites = [];
        foreach ($tacticalClients as $tc) {
            $clientName = $tc['name'] ?? 'Unknown';
            foreach ($tc['sites'] ?? [] as $site) {
                $siteName = $site['name'] ?? 'Default';
                $siteKey = $clientName.'|'.$siteName;
                $sites[] = [
                    'client_name' => $clientName,
                    'site_name' => $siteName,
                    'site_key' => $siteKey,
                ];
            }
        }

        usort($sites, function ($a, $b) {
            $cmp = strcasecmp($a['client_name'], $b['client_name']);

            return $cmp !== 0 ? $cmp : strcasecmp($a['site_name'], $b['site_name']);
        });

        // Build mapping: tactical_site_id → client
        $mappedClients = Client::whereNotNull('tactical_site_id')
            ->get(['id', 'name', 'tactical_site_id'])
            ->keyBy('tactical_site_id');

        $allClients = Client::operational()->orderBy('name')->get(['id', 'name']);

        return view('settings.tactical-sites', [
            'sites' => $sites,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);

        // Clear all existing tactical_site_id mappings
        Client::whereNotNull('tactical_site_id')->update(['tactical_site_id' => null]);

        // Apply new mappings
        $mapped = 0;
        foreach ($mappings as $siteKey => $clientId) {
            if ($clientId) {
                Client::where('id', $clientId)->update(['tactical_site_id' => $siteKey]);
                $mapped++;
            }
        }

        return redirect()->route('settings.tactical-sites.index')
            ->with('success', "Saved {$mapped} site mapping(s).");
    }
}
