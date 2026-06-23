<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\License;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippClientException;
use App\Support\CippConfig;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CippTenantController extends Controller
{
    public function index()
    {
        if (! CippConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'CIPP is not configured. Add credentials first.');
        }

        try {
            $cippClient = new CippClient(
                [
                    'api_url' => CippConfig::get('api_url'),
                    'tenant_id' => CippConfig::get('tenant_id'),
                    'client_id' => CippConfig::get('client_id'),
                    'client_secret' => CippConfig::get('client_secret'),
                    'application_id' => CippConfig::get('application_id'),
                ],
                app(\Illuminate\Contracts\Cache\Repository::class),
            );
            $tenants = $cippClient->listTenants();
        } catch (CippClientException $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "Could not connect to CIPP: {$e->getMessage()}");
        }

        if (! is_array($tenants)) {
            $tenants = [];
        }

        // Sort by displayName
        usort($tenants, fn ($a, $b) => strcasecmp($a['displayName'] ?? '', $b['displayName'] ?? ''));

        // Build mapping: cipp_tenant_domain → client
        $mappedClients = Client::whereNotNull('cipp_tenant_domain')
            ->get(['id', 'name', 'cipp_tenant_domain', 'cipp_sync_group_id'])
            ->keyBy('cipp_tenant_domain');

        $allClients = Client::operational()->orderBy('name')->get(['id', 'name']);

        return view('settings.cipp-tenants', [
            'tenants' => $tenants,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);
        $groups = $request->input('groups', []);

        DB::transaction(function () use ($mappings, $groups) {
            $previouslyMapped = Client::whereNotNull('cipp_tenant_domain')->pluck('id');

            Client::whereNotNull('cipp_tenant_domain')->update([
                'cipp_tenant_domain' => null,
                'cipp_sync_group_id' => null,
            ]);

            foreach ($mappings as $tenantDomain => $clientId) {
                if ($clientId) {
                    Client::where('id', $clientId)->update([
                        'cipp_tenant_domain' => $tenantDomain,
                        'cipp_sync_group_id' => $groups[$tenantDomain] ?? null,
                    ]);
                }
            }

            $stillMapped = Client::whereNotNull('cipp_tenant_domain')->pluck('id');
            $unmapped = $previouslyMapped->diff($stillMapped);
            License::deactivateForClients($unmapped, 'cipp_m365');
        });

        $mapped = collect($mappings)->filter()->count();

        return redirect()->route('settings.cipp-tenants.index')
            ->with('success', "Saved {$mapped} CIPP tenant mapping(s).");
    }

    /**
     * AJAX endpoint — return groups for a tenant domain (cached 5 min).
     */
    public function groups(string $domain)
    {
        if (! CippConfig::isConfigured()) {
            return response()->json([], 400);
        }

        $cacheKey = "cipp-groups:{$domain}";

        $groups = Cache::remember($cacheKey, 300, function () use ($domain) {
            try {
                $cippClient = new CippClient(
                    [
                        'api_url' => CippConfig::get('api_url'),
                        'tenant_id' => CippConfig::get('tenant_id'),
                        'client_id' => CippConfig::get('client_id'),
                        'client_secret' => CippConfig::get('client_secret'),
                        'application_id' => CippConfig::get('application_id'),
                    ],
                    app(CacheInterface::class),
                );

                return $cippClient->listGroups($domain);
            } catch (\Throwable $e) {
                return [];
            }
        });

        // Sort by displayName and return id + displayName only
        $sorted = collect($groups)
            ->map(fn ($g) => [
                'id' => $g['id'] ?? $g['Id'] ?? '',
                'displayName' => $g['displayName'] ?? $g['DisplayName'] ?? 'Unknown',
            ])
            ->filter(fn ($g) => ! empty($g['id']))
            ->sortBy('displayName')
            ->values();

        return response()->json($sorted);
    }
}
