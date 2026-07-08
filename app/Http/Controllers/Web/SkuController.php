<?php

namespace App\Http\Controllers\Web;

use App\Enums\QuantityType;
use App\Http\Controllers\Controller;
use App\Models\LicenseType;
use App\Models\Sku;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboClientException;
use App\Services\Qbo\QboSyncService;
use App\Services\SkuService;
use App\Services\Stripe\StripeClientException;
use App\Services\Stripe\StripeSyncService;
use App\Support\StripeConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Enum;

class SkuController extends Controller
{
    public function __construct(
        private readonly SkuService $skuService,
    ) {}

    public function index(Request $request)
    {
        $skus = $this->skuService->getList([
            'search' => $request->query('search'),
            'is_active' => $request->query('active', '1') === '1' ? true : ($request->query('active') === 'all' ? null : false),
            'category' => $request->query('category'),
            'quantity_type' => $request->query('quantity_type'),
        ]);

        $categories = Sku::whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return view('skus.index', [
            'skus' => $skus,
            'categories' => $categories,
            'quantityTypes' => QuantityType::cases(),
            'licenseTypes' => LicenseType::active()->orderBy('name')->get(['id', 'name']),
            'search' => $request->query('search'),
            'active' => $request->query('active', '1'),
            'category' => $request->query('category'),
            'quantity_type' => $request->query('quantity_type'),
        ]);
    }

    public function bulkAction(Request $request): RedirectResponse
    {
        $request->validate([
            'action' => ['required', 'string', 'in:edit,activate,deactivate'],
        ]);

        // Resolve SKU IDs — either from filter or explicit list
        if ($request->boolean('select_all_filter')) {
            $filters = [
                'search' => $request->input('filter_search'),
                'category' => $request->input('filter_category'),
                'quantity_type' => $request->input('filter_quantity_type'),
                'is_active' => $request->input('filter_active', '1') === '1' ? true : ($request->input('filter_active') === 'all' ? null : false),
            ];

            $skuIds = $this->skuService->buildFilteredQuery($filters)->pluck('skus.id')->all();

            if (empty($skuIds)) {
                return redirect()->route('skus.index')
                    ->with('error', 'No SKUs match the current filter.');
            }
        } else {
            $validated = $request->validate([
                'sku_ids' => ['required', 'array', 'min:1'],
                'sku_ids.*' => ['required', 'integer', 'exists:skus,id'],
            ]);

            $skuIds = $validated['sku_ids'];
        }

        $action = $request->input('action');

        switch ($action) {
            case 'edit':
                $attributes = [];
                if ($request->filled('category')) {
                    $request->validate(['category' => ['string', 'max:50']]);
                    $attributes['category'] = $request->input('category');
                }
                if ($request->boolean('clear_category')) {
                    $attributes['category'] = null;
                }
                if ($request->filled('default_quantity_type')) {
                    $request->validate(['default_quantity_type' => [new Enum(QuantityType::class)]]);
                    $attributes['default_quantity_type'] = $request->input('default_quantity_type');
                }
                if ($request->filled('default_license_type_id')) {
                    $request->validate(['default_license_type_id' => ['integer', 'exists:license_types,id']]);
                    $attributes['default_license_type_id'] = (int) $request->input('default_license_type_id');
                }
                if (empty($attributes)) {
                    return redirect()->route('skus.index')
                        ->with('error', 'No fields were changed.');
                }
                $affected = Sku::whereIn('id', $skuIds)->update($attributes);
                $message = "{$affected} SKU(s) updated.";
                break;

            case 'activate':
                $affected = Sku::whereIn('id', $skuIds)
                    ->where('is_active', false)
                    ->update(['is_active' => true]);
                $message = "{$affected} SKU(s) activated.";
                break;

            case 'deactivate':
                $affected = Sku::whereIn('id', $skuIds)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
                $message = "{$affected} SKU(s) deactivated.";
                break;
        }

        return redirect()->route('skus.index')
            ->with('success', $message);
    }

    public function create()
    {
        return view('skus.create', [
            'licenseTypes' => LicenseType::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sku_code' => ['required', 'string', 'max:50', 'unique:skus,sku_code'],
            'category' => ['nullable', 'string', 'max:50'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'prepaid_time_minutes' => ['nullable', 'integer', 'min:0'],
            'included_per_unit' => ['nullable', 'integer', 'min:0'],
            'default_quantity_type' => ['nullable', new Enum(QuantityType::class)],
            'default_license_type_id' => ['nullable', 'integer', 'exists:license_types,id'],
            'is_taxable' => ['boolean'],
            'is_active' => ['boolean'],
            'tiers' => ['nullable', 'array'],
            'tiers.*.up_to_gb' => ['nullable', 'integer', 'min:1'],
            'tiers.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $validated['is_taxable'] = $request->boolean('is_taxable');
        $validated['is_active'] = $request->boolean('is_active', true);
        unset($validated['tiers']);

        $sku = DB::transaction(function () use ($validated, $request) {
            $sku = $this->skuService->createSku($validated);
            $this->syncBackupStorageTiers($sku, $request->input('tiers', []));

            return $sku;
        });

        return redirect()->route('skus.edit', $sku)
            ->with('success', "SKU \"{$sku->name}\" created.");
    }

    public function edit(Sku $sku)
    {
        $profileLines = $sku->profileLines()
            ->with(['profile.contract.client'])
            ->get();

        $invoiceLines = $sku->invoiceLines()
            ->with(['invoice.client'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $qboIncomeAccounts = [];
        $qboExpenseAccounts = [];
        if (app(QboClient::class)->isConnected()) {
            try {
                $qboSync = app(QboSyncService::class);
                $qboIncomeAccounts = $qboSync->listIncomeAccounts();
                $qboExpenseAccounts = $qboSync->listExpenseAccounts();
            } catch (\Throwable $e) {
                Log::warning('[SkuController] Failed to list QBO accounts', ['error' => $e->getMessage()]);
            }
        }

        return view('skus.edit', [
            'sku' => $sku,
            'profileLines' => $profileLines,
            'invoiceLines' => $invoiceLines,
            'licenseTypes' => LicenseType::active()->orderBy('name')->get(['id', 'name']),
            'qboIncomeAccounts' => $qboIncomeAccounts,
            'qboExpenseAccounts' => $qboExpenseAccounts,
        ]);
    }

    public function update(Request $request, Sku $sku)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sku_code' => ['required', 'string', 'max:50', "unique:skus,sku_code,{$sku->id}"],
            'category' => ['nullable', 'string', 'max:50'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'prepaid_time_minutes' => ['nullable', 'integer', 'min:0'],
            'included_per_unit' => ['nullable', 'integer', 'min:0'],
            'default_quantity_type' => ['nullable', new Enum(QuantityType::class)],
            'default_license_type_id' => ['nullable', 'integer', 'exists:license_types,id'],
            'is_taxable' => ['boolean'],
            'is_active' => ['boolean'],
            'qbo_income_account_id' => ['nullable', 'string', 'max:50'],
            'qbo_expense_account_id' => ['nullable', 'string', 'max:50'],
            'tiers' => ['nullable', 'array'],
            'tiers.*.up_to_gb' => ['nullable', 'integer', 'min:1'],
            'tiers.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $validated['is_taxable'] = $request->boolean('is_taxable');
        $validated['is_active'] = $request->boolean('is_active');
        $validated['qbo_income_account_id'] = $request->input('qbo_income_account_id') ?: null;
        $validated['qbo_expense_account_id'] = $request->input('qbo_expense_account_id') ?: null;
        unset($validated['tiers']);

        DB::transaction(function () use ($sku, $validated, $request) {
            $this->skuService->updateSku($sku, $validated);
            $this->syncBackupStorageTiers($sku, $request->input('tiers', []));
        });

        return redirect()->route('skus.edit', $sku)
            ->with('success', 'SKU updated.');
    }

    /**
     * Replace a SKU's backup-storage tier rate card from submitted rows.
     * Fully-empty template rows are ignored; a blank `up_to_gb` marks the
     * unbounded catch-all tier.
     */
    private function syncBackupStorageTiers(Sku $sku, array $tiers): void
    {
        $sku->backupStorageTiers()->delete();

        $order = 0;
        foreach ($tiers as $tier) {
            $upTo = $tier['up_to_gb'] ?? null;
            $price = $tier['unit_price'] ?? null;

            $upTo = ($upTo === '' || $upTo === null) ? null : (int) $upTo;
            $priceBlank = ($price === '' || $price === null);

            // Skip rows with no data at all (leftover add-row templates).
            if ($upTo === null && $priceBlank) {
                continue;
            }

            $sku->backupStorageTiers()->create([
                'up_to_gb' => $upTo,
                'unit_price' => $priceBlank ? 0 : $price,
                'sort_order' => $order++,
            ]);
        }
    }

    public function destroy(Sku $sku)
    {
        $sku->delete();

        return redirect()->route('skus.index')
            ->with('success', "SKU \"{$sku->name}\" deleted.");
    }

    public function importFromQbo(Request $request, QboSyncService $qboSync)
    {
        try {
            $result = $qboSync->importQboItems();
        } catch (QboClientException $e) {
            return back()->with('error', "QBO import failed: {$e->getMessage()}");
        }

        return back()->with('success', "Imported {$result['created']} new SKUs, updated {$result['updated']}, skipped {$result['skipped']}.");
    }

    public function pushToQbo(Sku $sku, QboSyncService $qboSync)
    {
        try {
            $qboSync->pushItemToQbo($sku);
        } catch (QboClientException $e) {
            return back()->with('error', "QBO push failed: {$e->getMessage()}");
        }

        return back()->with('success', "SKU \"{$sku->name}\" pushed to QuickBooks.");
    }

    public function importFromStripe()
    {
        if (! StripeConfig::isConfigured()) {
            return back()->with('error', 'Stripe is not configured.');
        }

        $client = new \App\Services\Stripe\StripeClient([
            'secret_key' => StripeConfig::get('secret_key'),
        ]);
        $service = new StripeSyncService($client);

        try {
            $result = $service->importStripeProducts();
        } catch (StripeClientException $e) {
            return back()->with('error', "Stripe import failed: {$e->getMessage()}");
        }

        return back()->with('success', "Imported {$result['created']} new SKUs, updated {$result['updated']}.");
    }

    public function pushToStripe(Sku $sku)
    {
        if (! StripeConfig::isConfigured()) {
            return back()->with('error', 'Stripe is not configured.');
        }

        $client = new \App\Services\Stripe\StripeClient([
            'secret_key' => StripeConfig::get('secret_key'),
        ]);
        $service = new StripeSyncService($client);

        try {
            $service->pushProductToStripe($sku);
        } catch (StripeClientException $e) {
            return back()->with('error', "Stripe push failed: {$e->getMessage()}");
        }

        return back()->with('success', "SKU \"{$sku->name}\" pushed to Stripe.");
    }

    /**
     * JSON endpoint for SKU autocomplete in profile line editor.
     */
    public function apiSearch(Request $request)
    {
        $term = $request->query('q', '');

        $skus = Sku::active()
            ->search($term)
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'sku_code', 'unit_price', 'unit_cost', 'prepaid_time_minutes', 'included_per_unit', 'default_quantity_type', 'default_license_type_id', 'is_taxable']);

        return response()->json($skus);
    }
}
