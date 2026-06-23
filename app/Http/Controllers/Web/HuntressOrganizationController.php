<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\License;
use App\Services\Huntress\HuntressClient;
use App\Services\Huntress\HuntressClientException;
use App\Support\HuntressConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HuntressOrganizationController extends Controller
{
    public function index()
    {
        if (! HuntressConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Huntress is not configured. Add API credentials first.');
        }

        try {
            $client = new HuntressClient([
                'api_key' => HuntressConfig::get('api_key'),
                'api_secret' => HuntressConfig::get('api_secret'),
            ]);
            $organizations = $client->getOrganizations(['id', 'name', 'agents_count', 'billable_identity_count', 'sat_learner_count']);
        } catch (HuntressClientException $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "Could not connect to Huntress: {$e->getMessage()}");
        }

        // Sort organizations by name
        usort($organizations, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        // Build mapping: huntress_organization_id → client
        $mappedClients = Client::whereNotNull('huntress_organization_id')
            ->get(['id', 'name', 'huntress_organization_id'])
            ->keyBy('huntress_organization_id');

        $allClients = Client::operational()->orderBy('name')->get(['id', 'name']);

        return view('settings.huntress-organizations', [
            'organizations' => $organizations,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);

        DB::transaction(function () use ($mappings) {
            $previouslyMapped = Client::whereNotNull('huntress_organization_id')->pluck('id');

            // Clear existing mappings
            Client::whereNotNull('huntress_organization_id')->update(['huntress_organization_id' => null]);

            // Apply new mappings
            foreach ($mappings as $huntressOrgId => $clientId) {
                if ($clientId) {
                    Client::where('id', $clientId)->update(['huntress_organization_id' => (int) $huntressOrgId]);
                }
            }

            $stillMapped = Client::whereNotNull('huntress_organization_id')->pluck('id');
            $unmapped = $previouslyMapped->diff($stillMapped);
            License::deactivateForClients($unmapped, 'huntress');
        });

        $mapped = collect($mappings)->filter()->count();

        return redirect()->route('settings.huntress-orgs.index')
            ->with('success', "Saved {$mapped} Huntress organization mapping(s).");
    }

    /**
     * Auto-match Huntress organizations to clients by exact name match.
     * Only fills unmapped organizations — never overwrites existing mappings.
     */
    public function autoMatch()
    {
        if (! HuntressConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Huntress is not configured.');
        }

        try {
            $client = new HuntressClient([
                'api_key' => HuntressConfig::get('api_key'),
                'api_secret' => HuntressConfig::get('api_secret'),
            ]);
            $organizations = $client->getOrganizations(['id', 'name']);
        } catch (HuntressClientException $e) {
            return redirect()->route('settings.huntress-orgs.index')
                ->with('error', "Could not connect to Huntress: {$e->getMessage()}");
        }

        // Build lookup: lowercase client name → client
        $clientsByName = Client::operational()
            ->whereNull('huntress_organization_id')
            ->get(['id', 'name'])
            ->keyBy(fn ($c) => mb_strtolower($c->name));

        $matched = 0;

        foreach ($organizations as $org) {
            $orgId = $org['id'] ?? null;
            $orgName = $org['name'] ?? null;

            if (! $orgId || ! $orgName) {
                continue;
            }

            // Skip if this org is already mapped
            if (Client::where('huntress_organization_id', $orgId)->exists()) {
                continue;
            }

            $client = $clientsByName->get(mb_strtolower($orgName));

            if ($client) {
                Client::where('id', $client->id)->update(['huntress_organization_id' => (int) $orgId]);
                // Remove from lookup so the same client isn't matched twice
                $clientsByName->forget(mb_strtolower($orgName));
                $matched++;
            }
        }

        $message = $matched > 0
            ? "Auto-matched {$matched} organization(s) by name."
            : 'No new matches found. Organizations may need manual mapping.';

        return redirect()->route('settings.huntress-orgs.index')
            ->with($matched > 0 ? 'success' : 'info', $message);
    }
}
