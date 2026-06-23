<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\License;
use App\Services\Servosity\ServosityClient;
use App\Services\Servosity\ServosityClientException;
use App\Support\ServosityConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServosityCompanyController extends Controller
{
    public function index()
    {
        if (! ServosityConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Servosity is not configured. Add API credentials first.');
        }

        try {
            $client = new ServosityClient([
                'api_token' => ServosityConfig::get('api_token'),
                'base_url' => ServosityConfig::get('base_url'),
            ]);
            $companies = $client->getCompanies();
        } catch (ServosityClientException $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "Could not connect to Servosity: {$e->getMessage()}");
        }

        // Sort companies by name
        usort($companies, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        // Build mapping: servosity_company_id → client
        $mappedClients = Client::whereNotNull('servosity_company_id')
            ->get(['id', 'name', 'servosity_company_id'])
            ->keyBy('servosity_company_id');

        $allClients = Client::operational()->orderBy('name')->get(['id', 'name']);

        return view('settings.servosity-companies', [
            'companies' => $companies,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);

        DB::transaction(function () use ($mappings) {
            $previouslyMapped = Client::whereNotNull('servosity_company_id')->pluck('id');

            // Clear existing mappings
            Client::whereNotNull('servosity_company_id')->update(['servosity_company_id' => null]);

            // Apply new mappings
            foreach ($mappings as $companyId => $clientId) {
                if ($clientId) {
                    Client::where('id', $clientId)->update(['servosity_company_id' => (int) $companyId]);
                }
            }

            $stillMapped = Client::whereNotNull('servosity_company_id')->pluck('id');
            $unmapped = $previouslyMapped->diff($stillMapped);
            License::deactivateForClients($unmapped, 'servosity');
        });

        $mapped = collect($mappings)->filter()->count();

        return redirect()->route('settings.servosity-companies.index')
            ->with('success', "Saved {$mapped} Servosity company mapping(s).");
    }

    /**
     * Auto-match Servosity companies to clients by exact name match.
     * Only fills unmapped companies — never overwrites existing mappings.
     */
    public function autoMatch()
    {
        if (! ServosityConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Servosity is not configured.');
        }

        try {
            $client = new ServosityClient([
                'api_token' => ServosityConfig::get('api_token'),
                'base_url' => ServosityConfig::get('base_url'),
            ]);
            $companies = $client->getCompanies();
        } catch (ServosityClientException $e) {
            return redirect()->route('settings.servosity-companies.index')
                ->with('error', "Could not connect to Servosity: {$e->getMessage()}");
        }

        // Build lookup: lowercase client name → client
        $clientsByName = Client::operational()
            ->whereNull('servosity_company_id')
            ->get(['id', 'name'])
            ->keyBy(fn ($c) => mb_strtolower($c->name));

        $matched = 0;

        foreach ($companies as $company) {
            $companyId = $company['id'] ?? null;
            $companyName = $company['name'] ?? null;

            if (! $companyId || ! $companyName) {
                continue;
            }

            // Skip if this company is already mapped
            if (Client::where('servosity_company_id', $companyId)->exists()) {
                continue;
            }

            $matchedClient = $clientsByName->get(mb_strtolower($companyName));

            if ($matchedClient) {
                Client::where('id', $matchedClient->id)->update(['servosity_company_id' => (int) $companyId]);
                // Remove from lookup so the same client isn't matched twice
                $clientsByName->forget(mb_strtolower($companyName));
                $matched++;
            }
        }

        $message = $matched > 0
            ? "Auto-matched {$matched} company(ies) by name."
            : 'No new matches found. Companies may need manual mapping.';

        return redirect()->route('settings.servosity-companies.index')
            ->with($matched > 0 ? 'success' : 'info', $message);
    }
}
