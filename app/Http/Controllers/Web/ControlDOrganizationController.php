<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\License;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDClientException;
use App\Support\ControlDConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ControlDOrganizationController extends Controller
{
    public function index()
    {
        if (! ControlDConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Control D is not configured. Add API credentials first.');
        }

        try {
            $client = new ControlDClient([
                'api_key' => ControlDConfig::get('api_key'),
            ]);
            $subOrgs = $client->getSubOrganizations();
        } catch (ControlDClientException $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "Could not connect to Control D: {$e->getMessage()}");
        }

        // Sort by name
        usort($subOrgs, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        // Build mapping: controld_org_id → client
        $mappedClients = Client::whereNotNull('controld_org_id')
            ->get(['id', 'name', 'controld_org_id'])
            ->keyBy('controld_org_id');

        $allClients = Client::active()->orderBy('name')->get(['id', 'name']);

        return view('settings.controld-organizations', [
            'subOrgs' => $subOrgs,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);

        DB::transaction(function () use ($mappings) {
            $previouslyMapped = Client::whereNotNull('controld_org_id')->pluck('id');

            // Clear existing mappings
            Client::whereNotNull('controld_org_id')->update(['controld_org_id' => null]);

            // Apply new mappings — do NOT cast org ID to (int), Control D PKs are strings
            foreach ($mappings as $orgPk => $clientId) {
                if ($clientId) {
                    Client::where('id', $clientId)->update(['controld_org_id' => $orgPk]);
                }
            }

            $stillMapped = Client::whereNotNull('controld_org_id')->pluck('id');
            $unmapped = $previouslyMapped->diff($stillMapped);
            License::deactivateForClients($unmapped, 'controld');
        });

        $mapped = collect($mappings)->filter()->count();

        return redirect()->route('settings.controld-orgs.index')
            ->with('success', "Saved {$mapped} Control D organization mapping(s).");
    }

    /**
     * Auto-match Control D sub-organizations to clients by exact name match (case-insensitive).
     * Only fills unmapped organizations — never overwrites existing mappings.
     */
    public function autoMatch()
    {
        if (! ControlDConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Control D is not configured.');
        }

        try {
            $client = new ControlDClient([
                'api_key' => ControlDConfig::get('api_key'),
            ]);
            $subOrgs = $client->getSubOrganizations();
        } catch (ControlDClientException $e) {
            return redirect()->route('settings.controld-orgs.index')
                ->with('error', "Could not connect to Control D: {$e->getMessage()}");
        }

        // Build lookup: lowercase client name → client
        $clientsByName = Client::active()
            ->whereNull('controld_org_id')
            ->get(['id', 'name'])
            ->keyBy(fn ($c) => mb_strtolower($c->name));

        $matched = 0;

        foreach ($subOrgs as $org) {
            $orgPk = $org['PK'] ?? null;
            $orgName = $org['name'] ?? null;

            if (! $orgPk || ! $orgName) {
                continue;
            }

            // Skip if this org is already mapped
            if (Client::where('controld_org_id', $orgPk)->exists()) {
                continue;
            }

            $client = $clientsByName->get(mb_strtolower($orgName));

            if ($client) {
                Client::where('id', $client->id)->update(['controld_org_id' => $orgPk]);
                $clientsByName->forget(mb_strtolower($orgName));
                $matched++;
            }
        }

        $message = $matched > 0
            ? "Auto-matched {$matched} sub-organization(s) by name."
            : 'No new matches found. Sub-organizations may need manual mapping.';

        return redirect()->route('settings.controld-orgs.index')
            ->with($matched > 0 ? 'success' : 'info', $message);
    }
}
