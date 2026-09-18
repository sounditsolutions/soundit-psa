<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\AutoElevate\AutoElevateReadException;
use App\Services\AutoElevate\AutoElevateReadService;
use App\Support\AutoElevateConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Integrations → AutoElevate → Map companies. Clones HuntressOrganizationController:
 * list vendor companies with a client dropdown each, clear-then-apply save in one transaction,
 * and an auto-match that fills only unmapped rows. Admin-only (routes carry `admin`).
 * Read-only against the vendor; no license side effects (AutoElevate bills nothing here).
 */
class AutoElevateCompanyController extends Controller
{
    public function __construct(private readonly AutoElevateReadService $reads) {}

    public function index()
    {
        if (! AutoElevateConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'AutoElevate is not configured. Add an API key first.');
        }

        try {
            $companies = $this->reads->companies();
        } catch (AutoElevateReadException $e) {
            return redirect()->route('settings.integrations')
                ->with('error', "Could not read AutoElevate companies ({$e->reason}). Nothing was changed.");
        }

        $mappedClientRows = Client::whereNotNull('autoelevate_company_id')
            ->get(['id', 'name', 'autoelevate_company_id']);

        // keyBy() collapses clients that share a company id, so the keyed collection is the
        // right shape for the per-company lookup below but the WRONG thing to count: the
        // empty-state warning speaks of "client(s) still hold a mapping" and must count rows,
        // not distinct companies, or it under-reports what an empty-screen save would risk.
        $mappedClients = $mappedClientRows
            ->keyBy(fn ($c) => strtolower($c->autoelevate_company_id));
        $mappedClientCount = $mappedClientRows->count();

        // The dropdown must also offer every client that already HOLDS a mapping: a mapped
        // client that has since left the operational set would have no <option>, so the select
        // would post "" and the clear-then-apply save below would silently destroy its mapping.
        $allClients = Client::operational()->orderBy('name')->get(['id', 'name'])
            ->concat($mappedClients->values())
            ->unique('id')
            ->sortBy(fn ($c) => mb_strtolower($c->name))
            ->values();

        return view('settings.autoelevate-companies', [
            'companies' => $companies,
            'mappedClients' => $mappedClients,
            'mappedClientCount' => $mappedClientCount,
            'allClients' => $allClients,
        ]);
    }

    public function update(Request $request)
    {
        $mappings = $request->input('mappings', []);
        if (! is_array($mappings)) {
            $mappings = [];
        }

        // A submission carrying NO company keys is refused before the clear-then-apply write.
        //
        // Clear-then-apply derives the new state from the rendered form, so "no keys" and
        // "unmap everything" are the same request on the wire — but they are not the same
        // intent. The screen renders the Save button even when the vendor returned zero
        // companies -- which index() reaches only when the vendor genuinely lists none, since
        // an unconfigured key and an AutoElevateReadException both redirect away before the
        // view renders -- so an admin who presses
        // Save on a visibly empty table silently nulls every existing mapping and is told
        // "Saved 0 mapping(s)" — the flash reports success for a destructive write.
        //
        // Refusing the empty post is the conservative direction: the only intent it can block
        // is "unmap every company at once", which is still reachable one dropdown at a time on
        // a form that actually lists them. Nothing is cleared on this path.
        if ($mappings === []) {
            return back()->withErrors(['mappings' => 'No AutoElevate companies were submitted, so nothing was changed. Existing mappings were kept. To remove a mapping, set its dropdown to "Not mapped" on a form that lists the company.']);
        }

        // Every key must be a company UUID; every non-empty value a client id. A client holds
        // exactly one company id, so the same client under two company keys is a refusal — both
        // UPDATEs would hit one row and the last write would silently win.
        $requested = [];
        $seenCompanies = [];
        foreach ($mappings as $companyId => $clientId) {
            if (! is_string($companyId) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $companyId)) {
                return back()->withErrors(['mappings' => 'Invalid AutoElevate company id.']);
            }
            // The uuid regex accepts either hex case and the write path folds the key, so two
            // keys differing only in case are ONE company. Accepting both would land two
            // UPDATEs on the same lowercased autoelevate_company_id and map two clients to one
            // company — the invariant this controller owns (see the migration docblock) and that
            // index()'s keyBy and the client panel rely on. Normalize, then refuse a repeat.
            $companyKey = strtolower($companyId);
            if (in_array($companyKey, $seenCompanies, true)) {
                return back()->withErrors(['mappings' => 'The same AutoElevate company was submitted twice. Map each company once.']);
            }
            $seenCompanies[] = $companyKey;
            if ($clientId === null || $clientId === '') {
                continue;
            }
            if ((! is_string($clientId) && ! is_int($clientId)) || ! ctype_digit((string) $clientId)) {
                return back()->withErrors(['mappings' => 'Invalid client id.']);
            }
            $clientId = (int) $clientId;
            if (in_array($clientId, $requested, true)) {
                return back()->withErrors(['mappings' => 'One client cannot be mapped to two AutoElevate companies. Pick a different client for each company.']);
            }
            $requested[] = $clientId;
        }

        // A posted id matching no client would write nothing while the flash claimed a save.
        if ($requested !== [] && Client::whereIn('id', $requested)->count() !== count($requested)) {
            return back()->withErrors(['mappings' => 'Unknown client id.']);
        }

        $applied = 0;

        DB::transaction(function () use ($mappings, &$applied) {
            // Clear existing mappings, then apply the submitted set (Huntress shape).
            Client::whereNotNull('autoelevate_company_id')->update(['autoelevate_company_id' => null]);

            foreach ($mappings as $companyId => $clientId) {
                if ($clientId) {
                    // Count rows actually written, never the submitted payload.
                    $applied += Client::where('id', (int) $clientId)->update(['autoelevate_company_id' => strtolower($companyId)]);
                }
            }
        });

        return redirect()->route('settings.autoelevate-companies.index')
            ->with('success', "Saved {$applied} AutoElevate company mapping(s).");
    }

    /**
     * Auto-match companies to clients by normalized name (lowercase, alphanumerics only).
     * Only fills unmapped companies — never overwrites an existing mapping — and never
     * guesses: a normalized name shared by two or more clients is ambiguous and is left
     * for the manual dropdown.
     */
    public function autoMatch()
    {
        if (! AutoElevateConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'AutoElevate is not configured.');
        }

        try {
            $companies = $this->reads->companies();
        } catch (AutoElevateReadException $e) {
            return redirect()->route('settings.autoelevate-companies.index')
                ->with('error', "Could not read AutoElevate companies ({$e->reason}). Nothing was changed.");
        }

        // normalized client name → [client ids]; a bucket with 2+ entries is ambiguous.
        $buckets = [];
        foreach (Client::operational()->whereNull('autoelevate_company_id')->get(['id', 'name']) as $c) {
            $buckets[AutoElevateReadService::normalizeName($c->name)][] = $c->id;
        }

        $matched = 0;
        $ambiguous = 0;

        foreach ($companies as $company) {
            $key = AutoElevateReadService::normalizeName($company['name']);
            if ($key === '') {
                continue;
            }

            // Skip if this company is already mapped
            if (Client::where('autoelevate_company_id', $company['id'])->exists()) {
                continue;
            }

            $candidates = $buckets[$key] ?? [];
            if (count($candidates) > 1) {
                $ambiguous++;

                continue;
            }
            if (count($candidates) === 1) {
                Client::where('id', $candidates[0])->update(['autoelevate_company_id' => $company['id']]);
                // Remove from lookup so the same client isn't matched twice
                unset($buckets[$key]);
                $matched++;
            }
        }

        $message = $matched > 0
            ? "Auto-matched {$matched} company(ies) by name."
            : 'No new matches found. Companies may need manual mapping.';
        if ($ambiguous > 0) {
            $message .= " {$ambiguous} company(ies) left unmapped: more than one client shares that name.";
        }

        return redirect()->route('settings.autoelevate-companies.index')
            ->with($matched > 0 ? 'success' : 'info', $message);
    }
}
