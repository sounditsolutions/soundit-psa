<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDClientException;
use App\Services\ControlD\ControlDOrganizationMapping;
use App\Support\ControlDConfig;
use Illuminate\Http\Request;

class ControlDOrganizationController extends Controller
{
    public function index()
    {
        if (! ControlDConfig::isEnabled()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Control D is switched off. Turn it on in Settings > Integrations first.');
        }

        if (! ControlDConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Control D is not configured. Add API credentials first.');
        }

        try {
            $client = app()->make(ControlDClient::class, ['config' => [
                'api_key' => ControlDConfig::get('api_key'),
            ]]);
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

        $allClients = Client::operational()->orderBy('name')->get(['id', 'name']);

        return view('settings.controld-organizations', [
            'subOrgs' => $subOrgs,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        // `listed[]` is what the FORM rendered a select for (#2010). Without it, the absence of
        // an org pk from `mappings` cannot be told apart from a deliberate clear, so a mapping
        // this page never offered (org gone upstream, owner not selectable) refused every save.
        // A caller that declares nothing still fails closed.
        $validated = $request->validate([
            'mappings' => ['sometimes', 'array'],
            'listed' => ['sometimes', 'array'],
            'listed.*' => ['nullable', 'string', 'max:50'],
        ]);
        $listed = $request->has('listed')
            ? array_values(array_filter(
                array_map(static fn ($pk): string => (string) $pk, $validated['listed'] ?? []),
                static fn (string $pk): bool => $pk !== '',
            ))
            : null;
        $mapped = app(ControlDOrganizationMapping::class)->replace($validated['mappings'] ?? [], $listed);

        return redirect()->route('settings.controld-orgs.index')
            ->with('success', "Saved {$mapped} Control D organization mapping(s).");
    }

    /**
     * Auto-match Control D sub-organizations to clients by exact name match (case-insensitive).
     * Only fills unmapped organizations — never overwrites existing mappings.
     */
    public function autoMatch()
    {
        if (! ControlDConfig::isEnabled()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Control D is switched off. Turn it on in Settings > Integrations first.');
        }

        if (! ControlDConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Control D is not configured.');
        }

        try {
            $client = app()->make(ControlDClient::class, ['config' => [
                'api_key' => ControlDConfig::get('api_key'),
            ]]);
            $subOrgs = $client->getSubOrganizations();
        } catch (ControlDClientException $e) {
            return redirect()->route('settings.controld-orgs.index')
                ->with('error', "Could not connect to Control D: {$e->getMessage()}");
        }

        // Build lookup: lowercase client name → client
        $clientsByName = Client::operational()
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
            if (Client::withTrashed()->where('controld_org_id', $orgPk)->exists()) {
                continue;
            }

            $client = $clientsByName->get(mb_strtolower($orgName));

            if ($client && app(ControlDOrganizationMapping::class)->autoMatch($client->id, (string) $orgPk)) {
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
