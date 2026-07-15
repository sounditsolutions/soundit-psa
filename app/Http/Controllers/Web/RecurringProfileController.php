<?php

namespace App\Http\Controllers\Web;

use App\Enums\BillingPeriod;
use App\Enums\QuantityType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\LicenseType;
use App\Models\RecurringInvoiceProfile;
use App\Models\RecurringInvoiceProfileLine;
use App\Models\Sku;
use App\Services\BillingService;
use App\Support\TieredPricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

class RecurringProfileController extends Controller
{
    public function __construct(
        private readonly BillingService $billingService,
    ) {}

    public function index(Request $request)
    {
        $filters = $request->only(['client_id', 'active', 'sku_id', 'taxable']);

        $query = RecurringInvoiceProfile::with(['contract.client', 'lines.sku']);

        $this->applyFilters($query, $filters);

        // Sorting
        $sortable = ['name', 'billing_period', 'payment_terms_days', 'is_active', 'next_run_date', 'last_run_date', 'client', 'contract'];
        $sort = in_array($request->query('sort'), $sortable) ? $request->query('sort') : 'next_run_date';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        if ($sort === 'client') {
            $query->join('contracts', 'recurring_invoice_profiles.contract_id', '=', 'contracts.id')
                ->join('clients', 'contracts.client_id', '=', 'clients.id')
                ->orderBy('clients.name', $dir)
                ->select('recurring_invoice_profiles.*');
        } elseif ($sort === 'contract') {
            $query->join('contracts', 'recurring_invoice_profiles.contract_id', '=', 'contracts.id')
                ->orderBy('contracts.name', $dir)
                ->select('recurring_invoice_profiles.*');
        } else {
            $query->orderBy("recurring_invoice_profiles.{$sort}", $dir);
        }

        $profiles = $query->paginate(50)->withQueryString();

        return view('profiles.index', [
            'profiles' => $profiles,
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'clients' => Client::operational()->orderBy('name')->get(['id', 'name']),
            'skus' => Sku::active()->orderBy('name')->get(['id', 'name']),
            'quantityTypes' => QuantityType::cases(),
        ]);
    }

    public function bulkAction(Request $request): RedirectResponse
    {
        $request->validate([
            'action' => ['required', 'string', 'in:edit,activate,deactivate,set_quantity_type,enable_auto_push,enable_auto_push_send,disable_auto_push'],
        ]);

        // Resolve profile IDs — either from filter or explicit list
        if ($request->boolean('select_all_filter')) {
            $filters = [
                'client_id' => $request->input('filter_client_id'),
                'active' => $request->input('filter_active'),
                'sku_id' => $request->input('filter_sku_id'),
                'taxable' => $request->input('filter_taxable'),
            ];

            $profileIds = RecurringInvoiceProfile::query()
                ->tap(fn ($q) => $this->applyFilters($q, $filters))
                ->pluck('id')
                ->all();

            if (empty($profileIds)) {
                return redirect()->route('profiles.index')
                    ->with('error', 'No profiles match the current filter.');
            }
        } else {
            $validated = $request->validate([
                'profile_ids' => ['required', 'array', 'min:1'],
                'profile_ids.*' => ['required', 'integer', 'exists:recurring_invoice_profiles,id'],
            ]);

            $profileIds = $validated['profile_ids'];
        }

        $action = $request->input('action');

        switch ($action) {
            case 'edit':
                $attributes = [];
                if ($request->filled('payment_terms_days')) {
                    $request->validate(['payment_terms_days' => ['integer', 'min:0', 'max:365']]);
                    $attributes['payment_terms_days'] = (int) $request->input('payment_terms_days');
                }
                if ($request->filled('billing_day')) {
                    $request->validate(['billing_day' => ['integer', 'min:1', 'max:28']]);
                    $attributes['billing_day'] = (int) $request->input('billing_day');
                }
                if (empty($attributes)) {
                    return redirect()->route('profiles.index')
                        ->with('error', 'No fields were changed.');
                }
                $affected = RecurringInvoiceProfile::whereIn('id', $profileIds)->update($attributes);
                $message = "{$affected} profile(s) updated.";
                break;

            case 'activate':
                $affected = RecurringInvoiceProfile::whereIn('id', $profileIds)
                    ->where('is_active', false)
                    ->update(['is_active' => true]);
                $message = "{$affected} profile(s) activated.";
                break;

            case 'deactivate':
                $affected = RecurringInvoiceProfile::whereIn('id', $profileIds)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
                $message = "{$affected} profile(s) deactivated.";
                break;

            case 'set_quantity_type':
                $request->validate([
                    'target_sku_id' => ['required', 'exists:skus,id'],
                    'new_quantity_type' => ['required', new Enum(QuantityType::class)],
                ]);
                $newQtyType = QuantityType::from($request->input('new_quantity_type'));
                $targetSkuId = (int) $request->input('target_sku_id');

                // Flipping a graduated line onto Backup Storage (GB) can put the
                // SKU's volume rate card in play under the line's bands. That is
                // a supported override (the line's bands win — see
                // BillingService::priceLineSegments()), not a refusal; the
                // profile page states the applied card on every such line.
                $affected = RecurringInvoiceProfileLine::whereIn('profile_id', $profileIds)
                    ->where('sku_id', $targetSkuId)
                    ->update(['quantity_type' => $newQtyType->value]);
                $message = "{$affected} line(s) updated to {$newQtyType->label()}.";
                break;

            case 'enable_auto_push':
                $affected = RecurringInvoiceProfile::whereIn('id', $profileIds)->update(['auto_push_mode' => 'push']);
                $message = "{$affected} profile(s) set to auto-push.";
                break;

            case 'enable_auto_push_send':
                $affected = RecurringInvoiceProfile::whereIn('id', $profileIds)->update(['auto_push_mode' => 'push_and_send']);
                $message = "{$affected} profile(s) set to auto-push and send.";
                break;

            case 'disable_auto_push':
                $affected = RecurringInvoiceProfile::whereIn('id', $profileIds)->whereNotNull('auto_push_mode')->update(['auto_push_mode' => null]);
                $message = "{$affected} profile(s) auto-push disabled.";
                break;
        }

        return redirect()->route('profiles.index')
            ->with('success', $message);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['client_id'])) {
            $query->whereHas('contract', fn ($q) => $q->where('client_id', $filters['client_id']));
        }
        if (($filters['active'] ?? '') === '1') {
            $query->where('recurring_invoice_profiles.is_active', true);
        } elseif (($filters['active'] ?? '') === '0') {
            $query->where('recurring_invoice_profiles.is_active', false);
        }
        if (! empty($filters['sku_id'])) {
            $query->whereHas('lines', fn ($q) => $q->where('sku_id', $filters['sku_id']));
        }
        if (($filters['taxable'] ?? '') === '0') {
            $query->whereHas('lines', fn ($q) => $q->where('is_taxable', false));
        } elseif (($filters['taxable'] ?? '') === '1') {
            $query->whereHas('lines', fn ($q) => $q->where('is_taxable', true));
        }
    }

    public function create(Contract $contract)
    {
        $contract->load('client');

        // Default next run date: billing_day of next month
        $defaultNextRun = now()->startOfMonth()->addMonth()->day(min($contract->billing_day, 28));

        return view('profiles.create', [
            'contract' => $contract,
            'quantityTypes' => QuantityType::cases(),
            'billingPeriods' => BillingPeriod::cases(),
            // backup_storage_tiers_count marks volume-card SKUs so the line
            // editor can say, beside the graduated toggle, which rate card
            // will price the line the operator is configuring.
            'skus' => Sku::active()->withCount('backupStorageTiers')->orderBy('name')->get(),
            'licenseTypes' => LicenseType::active()->orderBy('name')->get(),
            'defaultNextRun' => $defaultNextRun->format('Y-m-d'),
            'defaultBillingPeriod' => $contract->billing_period?->value ?? 'monthly',
            'defaultBillingDay' => $contract->billing_day ?? 1,
            'defaultPaymentTermsDays' => $contract->payment_terms_days ?? 30,
        ]);
    }

    public function store(Request $request, Contract $contract)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'billing_period' => ['required', new Enum(BillingPeriod::class)],
            'billing_day' => ['required', 'integer', 'min:1', 'max:28'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'next_run_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku_id' => ['nullable', 'integer', 'exists:skus,id'],
            'lines.*.license_type_id' => ['nullable', 'integer', 'exists:license_types,id'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.pricing_tiers' => ['nullable', 'array'],
            'lines.*.pricing_tiers.*.up_to' => ['nullable', 'integer', 'min:1'],
            'lines.*.pricing_tiers.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.quantity_type' => ['required', 'string'],
            'lines.*.fixed_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_cost_override' => ['nullable', 'numeric', 'min:0'],
            'lines.*.prepaid_time_override' => ['nullable', 'integer', 'min:0'],
            'lines.*.is_taxable' => ['boolean'],
            'lines.*.usage_license_type_id' => ['required_if:lines.*.quantity_type,overage', 'nullable', 'integer', 'exists:license_types,id'],
            'lines.*.base_license_type_id' => ['nullable', 'integer', 'exists:license_types,id'],
            'lines.*.included_per_base_unit' => ['required_if:lines.*.quantity_type,overage', 'nullable', 'integer', 'min:0'],
            'lines.*.overage_divisor' => ['nullable', 'integer', 'min:1'],
            'skip_zero_invoices' => ['nullable'],
            'auto_push_mode' => ['nullable', 'in:push,push_and_send'],
        ]);

        // Convert empty string to null for three-state boolean
        $skipZero = $validated['skip_zero_invoices'] ?? null;
        $skipZero = $skipZero === '' || $skipZero === null ? null : (bool) $skipZero;

        $profile = DB::transaction(function () use ($validated, $contract, $skipZero) {
            $profile = RecurringInvoiceProfile::create([
                'contract_id' => $contract->id,
                'name' => $validated['name'],
                'notes' => $validated['notes'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'billing_period' => $validated['billing_period'],
                'billing_day' => $validated['billing_day'],
                'payment_terms_days' => $validated['payment_terms_days'],
                'next_run_date' => $validated['next_run_date'],
                'skip_zero_invoices' => $skipZero,
                'auto_push_mode' => $validated['auto_push_mode'] ?? null,
            ]);

            foreach ($validated['lines'] as $index => $lineData) {
                RecurringInvoiceProfileLine::create(
                    $this->buildLineAttributes($profile->id, $lineData, $index)
                );
            }

            return $profile;
        });

        return redirect()->route('profiles.show', $profile)
            ->with('success', 'Recurring profile created.');
    }

    public function show(RecurringInvoiceProfile $profile)
    {
        // `lines.sku.backupStorageTiers` feeds the per-line rate-card badge and
        // the override notice (PricingModelOverride) — eager-loaded so the view
        // does not fire a query per line to work out what will price it.
        $profile->load([
            'contract.client',
            'lines.sku.backupStorageTiers',
            'lines.licenseType',
            'lines.usageLicenseType',
            'lines.baseLicenseType',
        ]);

        $invoices = $profile->invoices()
            ->with('lines.sku')
            ->orderByDesc('invoice_date')
            ->get();

        return view('profiles.show', [
            'profile' => $profile,
            'invoices' => $invoices,
            'quantityTypes' => QuantityType::cases(),
            'billingPeriods' => BillingPeriod::cases(),
            'skus' => Sku::active()->withCount('backupStorageTiers')->orderBy('name')->get(),
            'licenseTypes' => LicenseType::active()->orderBy('name')->get(),
        ]);
    }

    public function generate(RecurringInvoiceProfile $profile, BillingService $billingService)
    {
        if (! $profile->next_run_date || ! $profile->next_run_date->isPast()) {
            return redirect()->route('profiles.show', $profile)
                ->with('error', 'Cannot generate: next run date is not in the past.');
        }

        if (! $profile->is_active) {
            return redirect()->route('profiles.show', $profile)
                ->with('error', 'Cannot generate: profile is inactive.');
        }

        $invoiceDate = $profile->next_run_date->copy();

        try {
            $result = $billingService->generateInvoice($profile);
        } catch (\Throwable $e) {
            return redirect()->route('profiles.show', $profile)
                ->with('error', 'Invoice generation failed: '.$e->getMessage());
        }

        if ($result['status'] === 'skipped') {
            $reason = $result['reason'] === 'exists' ? 'Invoice already exists for this date.' : 'Nothing to bill.';

            return redirect()->route('profiles.show', $profile)
                ->with('warning', "Skipped: {$reason}");
        }

        $invoice = $result['invoice'];

        return redirect()->route('profiles.show', $profile)
            ->with('success', "Invoice {$invoice->invoice_number} generated (dated {$invoiceDate->format('M j, Y')}).");
    }

    public function update(Request $request, RecurringInvoiceProfile $profile)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'billing_period' => ['required', new Enum(BillingPeriod::class)],
            'billing_day' => ['required', 'integer', 'min:1', 'max:28'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'next_run_date' => ['required', 'date'],
            'lines' => ['nullable', 'array'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.sku_id' => ['nullable', 'integer', 'exists:skus,id'],
            'lines.*.license_type_id' => ['nullable', 'integer', 'exists:license_types,id'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.pricing_tiers' => ['nullable', 'array'],
            'lines.*.pricing_tiers.*.up_to' => ['nullable', 'integer', 'min:1'],
            'lines.*.pricing_tiers.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.quantity_type' => ['required', 'string'],
            'lines.*.fixed_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_cost_override' => ['nullable', 'numeric', 'min:0'],
            'lines.*.prepaid_time_override' => ['nullable', 'integer', 'min:0'],
            'lines.*.is_taxable' => ['boolean'],
            'lines.*.usage_license_type_id' => ['required_if:lines.*.quantity_type,overage', 'nullable', 'integer', 'exists:license_types,id'],
            'lines.*.base_license_type_id' => ['nullable', 'integer', 'exists:license_types,id'],
            'lines.*.included_per_base_unit' => ['required_if:lines.*.quantity_type,overage', 'nullable', 'integer', 'min:0'],
            'lines.*.overage_divisor' => ['nullable', 'integer', 'min:1'],
            'skip_zero_invoices' => ['nullable'],
            'auto_push_mode' => ['nullable', 'in:push,push_and_send'],
        ]);

        // Convert empty string to null for three-state boolean
        $skipZero = $validated['skip_zero_invoices'] ?? null;
        $skipZero = $skipZero === '' || $skipZero === null ? null : (bool) $skipZero;

        DB::transaction(function () use ($validated, $profile, $skipZero) {
            $profile->update([
                'name' => $validated['name'],
                'notes' => $validated['notes'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'billing_period' => $validated['billing_period'],
                'billing_day' => $validated['billing_day'],
                'payment_terms_days' => $validated['payment_terms_days'],
                'next_run_date' => $validated['next_run_date'],
                'skip_zero_invoices' => $skipZero,
                'auto_push_mode' => $validated['auto_push_mode'] ?? null,
            ]);

            // Replace all lines (only if lines were submitted)
            $lines = $validated['lines'] ?? [];
            $profile->lines()->delete();

            foreach ($lines as $index => $lineData) {
                RecurringInvoiceProfileLine::create(
                    $this->buildLineAttributes($profile->id, $lineData, $index)
                );
            }
        });

        return redirect()->route('profiles.show', $profile)
            ->with('success', 'Profile updated.');
    }

    /**
     * Build the persisted attributes for a profile line from validated request
     * data. Shared by store() and update() so line handling — including tiered
     * pricing normalization — stays in one place.
     *
     * @param  array<string, mixed>  $lineData
     * @return array<string, mixed>
     */
    private function buildLineAttributes(int $profileId, array $lineData, int $index): array
    {
        $pricingTiers = TieredPricing::normalize($lineData['pricing_tiers'] ?? []);
        $tiered = $pricingTiers !== [];

        return [
            'profile_id' => $profileId,
            'sku_id' => ! empty($lineData['sku_id']) ? $lineData['sku_id'] : null,
            'license_type_id' => ! empty($lineData['license_type_id']) ? $lineData['license_type_id'] : null,
            'usage_license_type_id' => ! empty($lineData['usage_license_type_id']) ? $lineData['usage_license_type_id'] : null,
            'base_license_type_id' => ! empty($lineData['base_license_type_id']) ? $lineData['base_license_type_id'] : null,
            'included_per_base_unit' => $lineData['included_per_base_unit'] ?? null,
            'overage_divisor' => $lineData['overage_divisor'] ?? null,
            'description' => $lineData['description'],
            // For a tiered line the first band's price is the authoritative base
            // unit price; otherwise store the submitted flat price.
            'unit_price' => $tiered ? $pricingTiers[0]['unit_price'] : $lineData['unit_price'],
            'pricing_tiers' => $tiered ? $pricingTiers : null,
            'unit_cost_override' => ! empty($lineData['unit_cost_override']) ? $lineData['unit_cost_override'] : null,
            'prepaid_time_override' => ! empty($lineData['prepaid_time_override']) ? $lineData['prepaid_time_override'] : null,
            'quantity_type' => $lineData['quantity_type'],
            'fixed_quantity' => $lineData['fixed_quantity'] ?? 1,
            'is_taxable' => $lineData['is_taxable'] ?? true,
            'sort_order' => $index,
        ];
    }

    public function preview(RecurringInvoiceProfile $profile)
    {
        $preview = $this->billingService->previewInvoice($profile);

        return response()->json($preview);
    }
}
