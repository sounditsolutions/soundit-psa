<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Unifi\UnifiClient;
use App\Support\UnifiConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings > Integrations > UniFi > Site Mapping (psa-g5l80).
 *
 * Mirrors HuntressOrganizationController, with one deliberate difference forced by the
 * vendor's data model: a mapping writes TWO columns as a pair — unifi_site_id (the
 * telemetry grain) and unifi_host_id (the owning console, which unifi_list_devices
 * requires because /v1/devices is host-grained). The console id is resolved SERVER-SIDE
 * from the vendor's own /v1/sites listing at save time, never from the submitted form:
 * UnifiReadOnlyToolset's device-attribution guards trust the pair, and letting the
 * browser supply the console id would let a tampered request bind a client to an
 * arbitrary console.
 *
 * Field names in the projection below come from the vendor's OpenAPI spec
 * (https://developer.ui.com/site-manager/v1.0.0/openapi.json) via UnifiClient::listSites
 * — see that docblock and tests/Fixtures/unifi/list_sites.json.
 */
class UnifiSiteController extends Controller
{
    public function index()
    {
        if (! UnifiConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'UniFi is not configured. Add an API key first.');
        }

        try {
            $sites = $this->fetchSites();
        } catch (\Throwable $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "Could not list UniFi sites: {$e->getMessage()}");
        }

        $mappedClients = Client::whereNotNull('unifi_site_id')
            ->get(['id', 'name', 'unifi_site_id', 'unifi_host_id'])
            ->keyBy('unifi_site_id');

        $allClients = Client::operational()->orderBy('name')->get(['id', 'name']);

        return view('settings.unifi-sites', [
            'sites' => $sites,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        if (! UnifiConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'UniFi is not configured. Add an API key first.');
        }

        $selected = collect((array) $request->input('mappings', []))
            ->map(fn ($clientId) => trim((string) $clientId))
            ->filter(fn ($clientId) => $clientId !== '');

        // A client row carries ONE site pair, so the same client on two sites cannot be
        // stored — and silently letting the last one win would misattribute telemetry.
        // Refuse the whole save with the offender named.
        $duplicateClientIds = $selected->countBy()->filter(fn ($count) => $count > 1)->keys();
        if ($duplicateClientIds->isNotEmpty()) {
            $names = Client::whereIn('id', $duplicateClientIds)->pluck('name')->implode(', ');

            return redirect()->route('settings.unifi-sites.index')
                ->with('error', 'A client can only be mapped to one UniFi site, but '.($names !== '' ? $names : 'a client')
                    .' was selected for more than one. Nothing was saved.');
        }

        // Console ids come from the live vendor listing at save time (see class
        // docblock). If the listing cannot be fetched, save nothing — a save that
        // wrote site ids with stale or missing console ids would half-break device
        // reads while looking successful.
        try {
            $sites = $this->fetchSites();
        } catch (\Throwable $e) {
            return redirect()->route('settings.unifi-sites.index')
                ->with('error', "Could not save mappings — the UniFi site listing failed ({$e->getMessage()}). Existing mappings were left untouched.");
        }

        $skipped = [];

        DB::transaction(function () use ($selected, $sites, &$skipped) {
            // Clear where EITHER column is set: the pair is the invariant, and a
            // host-only orphan (possible via manual DB edits) is unusable state.
            // withTrashed matters — unifi_site_id is UNIQUE across all client rows,
            // so a soft-deleted client still occupies its site id and remapping that
            // site to a live client would otherwise hit the unique index.
            Client::withTrashed()
                ->where(function ($query) {
                    $query->whereNotNull('unifi_site_id')->orWhereNotNull('unifi_host_id');
                })
                ->update(['unifi_site_id' => null, 'unifi_host_id' => null]);

            foreach ($selected as $siteId => $clientId) {
                $site = $sites[(string) $siteId] ?? null;

                if ($site === null) {
                    // Submitted for a site the API key can no longer see — writing it
                    // would store a console id we cannot verify. Skip and say so.
                    $skipped[] = (string) $siteId;

                    continue;
                }

                Client::where('id', (int) $clientId)->update([
                    'unifi_site_id' => $site['site_id'],
                    'unifi_host_id' => $site['host_id'],
                ]);
            }
        });

        $message = 'Saved '.($selected->count() - count($skipped)).' UniFi site mapping(s).';
        if ($skipped !== []) {
            $message .= ' Skipped '.count($skipped).' site(s) no longer visible to this API key: '.implode(', ', $skipped).'.';
        }

        return redirect()->route('settings.unifi-sites.index')
            ->with('success', $message);
    }

    /**
     * Auto-match UniFi sites to clients by exact name match against the site's display
     * label (meta.desc — what the UniFi UI shows) or its internal name (meta.name).
     * Only fills unmapped sites and unmapped clients — never overwrites existing
     * mappings. Writes the site + console pair, same as a manual save.
     */
    public function autoMatch()
    {
        if (! UnifiConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'UniFi is not configured. Add an API key first.');
        }

        try {
            $sites = $this->fetchSites();
        } catch (\Throwable $e) {
            return redirect()->route('settings.unifi-sites.index')
                ->with('error', "Could not list UniFi sites: {$e->getMessage()}");
        }

        // Lookup: lowercase client name → client, unmapped clients only.
        $clientsByName = Client::operational()
            ->whereNull('unifi_site_id')
            ->get(['id', 'name'])
            ->keyBy(fn ($client) => mb_strtolower(trim($client->name)));

        $matched = 0;

        foreach ($sites as $site) {
            // withTrashed: a soft-deleted client still occupies its (unique) site id,
            // and writing that id onto a live client would hit the unique index.
            if (Client::withTrashed()->where('unifi_site_id', $site['site_id'])->exists()) {
                continue;
            }

            foreach ([$site['description'], $site['internal_name']] as $label) {
                if (! is_string($label) || trim($label) === '') {
                    continue;
                }

                $key = mb_strtolower(trim($label));
                $client = $clientsByName->get($key);

                if ($client) {
                    Client::where('id', $client->id)->update([
                        'unifi_site_id' => $site['site_id'],
                        'unifi_host_id' => $site['host_id'],
                    ]);
                    // Remove from lookup so the same client isn't matched twice.
                    $clientsByName->forget($key);
                    $matched++;

                    break;
                }
            }
        }

        $message = $matched > 0
            ? "Auto-matched {$matched} site(s) by name."
            : 'No new matches found. Sites may need manual mapping.';

        return redirect()->route('settings.unifi-sites.index')
            ->with($matched > 0 ? 'success' : 'info', $message);
    }

    /**
     * All sites the API key can see, projected for this page and keyed by siteId,
     * sorted by display label. UnifiClient::allSites() walks the cursor and FAILS LOUD
     * if it cannot fetch everything — a partial table here would read as "those sites
     * are gone".
     *
     * @return array<string, array{site_id: string, host_id: ?string, label: string, internal_name: ?string, description: ?string, device_count: ?int, isp_name: ?string}>
     */
    private function fetchSites(): array
    {
        $rows = app(UnifiClient::class)->allSites();

        $sites = [];
        foreach ($rows as $row) {
            $siteId = $row['siteId'] ?? null;
            if (! is_string($siteId) || $siteId === '') {
                continue;
            }

            $hostId = $row['hostId'] ?? null;
            $description = $row['meta']['desc'] ?? null;
            $internalName = $row['meta']['name'] ?? null;
            $deviceCount = $row['statistics']['counts']['totalDevice'] ?? null;
            $ispName = $row['statistics']['ispInfo']['name'] ?? null;

            $label = $siteId;
            foreach ([$description, $internalName] as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    $label = trim($candidate);

                    break;
                }
            }

            $sites[$siteId] = [
                'site_id' => $siteId,
                'host_id' => is_string($hostId) && $hostId !== '' ? $hostId : null,
                'label' => $label,
                'internal_name' => is_string($internalName) ? $internalName : null,
                'description' => is_string($description) ? $description : null,
                'device_count' => is_int($deviceCount) ? $deviceCount : null,
                'isp_name' => is_string($ispName) ? $ispName : null,
            ];
        }

        uasort($sites, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return $sites;
    }
}
