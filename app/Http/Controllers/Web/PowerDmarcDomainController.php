<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientPowerdmarcDomain;
use App\Services\PowerDmarc\PowerDmarcClient;
use App\Support\PowerDmarcConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings > Integrations > PowerDMARC > Domain Mapping (issue #689).
 *
 * Mirrors UnifiSiteController. The mapping stores the PAIR powerdmarc_domain_id
 * (the vendor's numeric domain id — the read grain) + domain_name (the display
 * copy the tool surface matches a `domain` argument against). The name is
 * resolved SERVER-SIDE from the vendor's own /api/v1/domains listing at save
 * time, never from the submitted form: PowerDmarcReadOnlyToolset's domain
 * resolution trusts the pair, and letting the browser supply the name would let
 * a tampered request alias a client onto an arbitrary domain string.
 *
 * Mappings live in the client_powerdmarc_domains pivot: a client may map to
 * MANY domains (several mail domains), while a DOMAIN still maps to at most one
 * client (the pivot's UNIQUE on powerdmarc_domain_id). The page is one row per
 * domain with a client dropdown, so N domains → 1 client is expressed by
 * choosing the same client on several rows.
 *
 * Field names in the projection below come from the vendor's OpenAPI spec via
 * PowerDmarcClient::listDomains — see that docblock and
 * tests/Fixtures/powerdmarc/list_domains.json.
 */
class PowerDmarcDomainController extends Controller
{
    public function index()
    {
        if (! PowerDmarcConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'PowerDMARC is not configured. Add an API key first.');
        }

        try {
            $domains = $this->fetchDomains();
        } catch (\Throwable $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "Could not list PowerDMARC domains: {$e->getMessage()}");
        }

        // domain id => the client mapped to it, for per-row preselection. Joining
        // through Client::query() keeps the soft-delete scope (a trashed client
        // reads unmapped).
        $mappedClients = Client::query()
            ->join('client_powerdmarc_domains', 'client_powerdmarc_domains.client_id', '=', 'clients.id')
            ->get(['clients.id', 'clients.name', 'client_powerdmarc_domains.powerdmarc_domain_id'])
            ->keyBy('powerdmarc_domain_id');

        $allClients = Client::operational()->orderBy('name')->get(['id', 'name']);

        // A client that has LEFT the operational() scope (inactive, prospect, …)
        // can still hold mappings. It must stay in the dropdown: otherwise its row
        // renders '— Not mapped —' while the Status column says 'Mapped', and the
        // next save of ANY row posts '' for it and silently deletes the mapping.
        $missingClientIds = $mappedClients->pluck('id')->diff($allClients->pluck('id'));
        if ($missingClientIds->isNotEmpty()) {
            $allClients = $allClients
                ->concat(Client::whereIn('id', $missingClientIds->all())->get(['id', 'name']))
                ->sortBy('name')
                ->values();
        }

        return view('settings.powerdmarc-domains', [
            'domains' => $domains,
            'mappedClients' => $mappedClients,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        if (! PowerDmarcConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'PowerDMARC is not configured. Add an API key first.');
        }

        // The form is one row per VISIBLE domain. Keep every submitted domain id
        // (including deselections, value '') so we re-assert exactly the rows the
        // operator saw; domains not on the form are left untouched.
        $submitted = collect((array) $request->input('mappings', []))
            ->mapWithKeys(fn ($clientId, $domainId) => [(string) $domainId => trim((string) $clientId)]);

        $selected = $submitted->filter(fn ($clientId) => $clientId !== '');

        // Domain names come from the live vendor listing at save time (see class
        // docblock). If the listing cannot be fetched, save nothing — a save that
        // wrote domain ids with stale or missing names would half-break the tool
        // surface's domain resolution while looking successful.
        try {
            $domains = $this->fetchDomains();
        } catch (\Throwable $e) {
            return redirect()->route('settings.powerdmarc-domains.index')
                ->with('error', "Could not save mappings — the PowerDMARC domain listing failed ({$e->getMessage()}). Existing mappings were left untouched.");
        }

        // A domain that dropped out of the live listing between render and save
        // cannot be re-asserted (its name is unverifiable) — so it must not be
        // DELETED either. Wiping a row we then refuse to rewrite would destroy a
        // mapping under a success flash. Only still-visible domains are re-asserted.
        $visible = $submitted->keys()->filter(fn ($domainId) => isset($domains[(string) $domainId]));

        // EVERY submitted domain that dropped out of the listing — not only the
        // ones still carrying a client. A row the operator set back to
        // '— Not mapped —' is excluded from the delete above along with the
        // rest, so its mapping SURVIVES; reporting only the still-selected ones
        // would drop an explicit revoke under a green "Saved N" flash, and the
        // row is gone from the reload too, so the operator could never see it.
        $unresolvable = $submitted->reject(fn ($clientId, $domainId) => isset($domains[(string) $domainId]));
        $skipped = $unresolvable->keys()->map(fn ($domainId) => (string) $domainId)->values()->all();
        $revoked = $unresolvable
            ->filter(fn ($clientId) => $clientId === '')
            ->keys()
            ->map(fn ($domainId) => (string) $domainId)
            ->values()
            ->all();

        DB::transaction(function () use ($visible, $selected, $domains) {
            // Re-assert only the domains shown in this form AND still visible to
            // the API key: drop their current pivot rows, then insert the chosen
            // ones. Deleting by domain id (the pivot is not soft-deleted) also
            // clears a row held by a soft-deleted client, so remapping that domain
            // to a live client cannot collide on the UNIQUE.
            ClientPowerdmarcDomain::whereIn('powerdmarc_domain_id', $visible->all())->delete();

            foreach ($selected as $domainId => $clientId) {
                $domain = $domains[(string) $domainId] ?? null;

                if ($domain === null) {
                    // No longer visible: its existing mapping was deliberately
                    // excluded from the delete above, so it survives untouched.
                    continue;
                }

                ClientPowerdmarcDomain::create([
                    'client_id' => (int) $clientId,
                    'powerdmarc_domain_id' => $domain['domain_id'],
                    'domain_name' => $domain['name'],
                ]);
            }
        });

        // Saved = the rows actually re-asserted: selected AND still visible.
        // Deriving it by subtracting $skipped would now under-count, because
        // $skipped also holds deselections that were never in $selected.
        $saved = $selected->keys()->filter(fn ($domainId) => isset($domains[(string) $domainId]))->count();

        $message = "Saved {$saved} PowerDMARC domain mapping(s).";
        if ($skipped !== []) {
            $message .= ' Skipped '.count($skipped).' domain(s) no longer visible to this API key: '.implode(', ', $skipped).'. Any existing mapping for those domains was left untouched.';
        }
        if ($revoked !== []) {
            // The operator explicitly asked to UNMAP these and we could not do
            // it. Say so by name: a silent drop here leaves every powerdmarc_*
            // read for that client resolving to a domain the key cannot see.
            $message .= ' NOT unmapped: '.implode(', ', $revoked).' — you cleared the client for '
                .(count($revoked) === 1 ? 'that domain' : 'those domains')
                .', but it is no longer in the PowerDMARC listing, so the existing mapping could not be removed and is still in force.';
        }

        return redirect()->route('settings.powerdmarc-domains.index')
            ->with('success', $message);
    }

    /**
     * Auto-match PowerDMARC domains to clients by exact (lowercased) match of the
     * vendor domain name against the client's name. Only fills unmapped domains
     * and unmapped clients — never overwrites existing mappings. Writes the
     * id + name pair, same as a manual save.
     */
    public function autoMatch()
    {
        if (! PowerDmarcConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'PowerDMARC is not configured. Add an API key first.');
        }

        try {
            $domains = $this->fetchDomains();
        } catch (\Throwable $e) {
            return redirect()->route('settings.powerdmarc-domains.index')
                ->with('error', "Could not list PowerDMARC domains: {$e->getMessage()}");
        }

        // Lookup: lowercase client name → client, clients with no domain yet only.
        $clientsByName = Client::operational()
            ->whereDoesntHave('powerdmarcDomains')
            ->get(['id', 'name'])
            ->keyBy(fn ($client) => mb_strtolower(trim($client->name)));

        $matched = 0;

        foreach ($domains as $domain) {
            // A domain already mapped to any client (the pivot's UNIQUE holds it)
            // is never re-assigned by auto-match.
            if (ClientPowerdmarcDomain::where('powerdmarc_domain_id', $domain['domain_id'])->exists()) {
                continue;
            }

            $key = mb_strtolower(trim($domain['name']));
            if ($key === '') {
                continue;
            }

            $client = $clientsByName->get($key);

            if ($client) {
                ClientPowerdmarcDomain::create([
                    'client_id' => $client->id,
                    'powerdmarc_domain_id' => $domain['domain_id'],
                    'domain_name' => $domain['name'],
                ]);
                // Remove from lookup so the same client isn't matched twice.
                $clientsByName->forget($key);
                $matched++;
            }
        }

        $message = $matched > 0
            ? "Auto-matched {$matched} domain(s) by name."
            : 'No new matches found. Domains may need manual mapping.';

        return redirect()->route('settings.powerdmarc-domains.index')
            ->with($matched > 0 ? 'success' : 'info', $message);
    }

    /**
     * All domains the API key can see, projected for this page and keyed by the
     * vendor domain id (as a string, for form round-tripping), sorted by name.
     * PowerDmarcClient::allDomains() walks meta.last_page and FAILS LOUD if it
     * cannot fetch everything — a partial table here would read as "those domains
     * are gone".
     *
     * @return array<string, array{domain_id: int, name: string, is_dmarc_record_correct: ?bool, is_setup_completed: ?bool}>
     */
    private function fetchDomains(): array
    {
        $rows = app(PowerDmarcClient::class)->allDomains();

        $domains = [];
        foreach ($rows as $row) {
            $domainId = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            if (! is_int($domainId) || ! is_string($name) || trim($name) === '') {
                continue;
            }

            $isDmarcCorrect = $row['is_dmarc_record_correct'] ?? null;
            $isSetupCompleted = $row['is_setup_completed'] ?? null;

            $domains[(string) $domainId] = [
                'domain_id' => $domainId,
                'name' => trim($name),
                'is_dmarc_record_correct' => is_bool($isDmarcCorrect) ? $isDmarcCorrect : null,
                'is_setup_completed' => is_bool($isSetupCompleted) ? $isSetupCompleted : null,
            ];
        }

        uasort($domains, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $domains;
    }
}
