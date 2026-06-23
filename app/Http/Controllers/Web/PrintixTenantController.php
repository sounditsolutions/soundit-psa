<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\License;
use App\Services\Printix\PrintixClient;
use App\Services\Printix\PrintixClientException;
use App\Support\PrintixConfig;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrintixTenantController extends Controller
{
    public function index()
    {
        if (! PrintixConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Printix is not configured. Add credentials first.');
        }

        try {
            $client = new PrintixClient(
                [
                    'client_id' => PrintixConfig::get('client_id'),
                    'client_secret' => PrintixConfig::get('client_secret'),
                    'partner_id' => PrintixConfig::get('partner_id'),
                ],
                app(CacheInterface::class),
            );
            $tenants = $client->getTenants();
        } catch (PrintixClientException $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "Could not connect to Printix: {$e->getMessage()}");
        }

        if (! is_array($tenants)) {
            $tenants = [];
        }

        usort($tenants, fn ($a, $b) => strcasecmp($a['tenant_name'] ?? '', $b['tenant_name'] ?? ''));

        $mappedClients = Client::whereNotNull('printix_tenant_id')
            ->get(['id', 'name', 'printix_tenant_id'])
            ->keyBy('printix_tenant_id');

        $allClients = Client::operational()->orderBy('name')->get(['id', 'name']);

        return view('settings.printix-tenants', [
            'tenants' => $tenants,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);

        DB::transaction(function () use ($mappings) {
            $previouslyMapped = Client::whereNotNull('printix_tenant_id')->pluck('id');

            Client::whereNotNull('printix_tenant_id')->update(['printix_tenant_id' => null]);

            foreach ($mappings as $tenantId => $clientId) {
                if ($clientId) {
                    Client::where('id', $clientId)->update(['printix_tenant_id' => $tenantId]);
                }
            }

            $stillMapped = Client::whereNotNull('printix_tenant_id')->pluck('id');
            $unmapped = $previouslyMapped->diff($stillMapped);
            License::deactivateForClients($unmapped, 'printix');
        });

        $mapped = collect($mappings)->filter()->count();

        return redirect()->route('settings.printix-tenants.index')
            ->with('success', "Saved {$mapped} Printix tenant mapping(s).");
    }

    public function autoMatch()
    {
        if (! PrintixConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Printix is not configured.');
        }

        try {
            $client = new PrintixClient(
                [
                    'client_id' => PrintixConfig::get('client_id'),
                    'client_secret' => PrintixConfig::get('client_secret'),
                    'partner_id' => PrintixConfig::get('partner_id'),
                ],
                app(CacheInterface::class),
            );
            $tenants = $client->getTenants();
        } catch (PrintixClientException $e) {
            return redirect()->route('settings.printix-tenants.index')
                ->with('error', "Could not connect to Printix: {$e->getMessage()}");
        }

        $clients = Client::operational()->get(['id', 'name'])->keyBy(fn ($c) => mb_strtolower($c->name));
        $matched = 0;

        foreach ($tenants as $tenant) {
            $tenantId = $tenant['id'] ?? null;
            $tenantName = $tenant['tenant_name'] ?? '';
            if (! $tenantId || ! $tenantName) {
                continue;
            }

            $already = Client::where('printix_tenant_id', $tenantId)->exists();
            if ($already) {
                continue;
            }

            $match = $clients->get(mb_strtolower($tenantName));
            if ($match && ! $match->printix_tenant_id) {
                $match->update(['printix_tenant_id' => $tenantId]);
                $matched++;
            }
        }

        return redirect()->route('settings.printix-tenants.index')
            ->with('success', "Auto-matched {$matched} tenant(s) by name.");
    }
}
