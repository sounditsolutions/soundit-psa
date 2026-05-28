<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Sku;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LicenseTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = LicenseType::with('sku')
            ->withSum('licenses as total_quantity', 'quantity')
            ->withCount(['licenses as client_count' => fn ($q) => $q->select(DB::raw('COUNT(DISTINCT client_id)'))])
            ->orderBy('name');

        if ($request->filled('vendor')) {
            $query->where('vendor', $request->query('vendor'));
        }

        if ($request->query('active', '1') !== 'all') {
            $query->where('is_active', $request->query('active', '1') === '1');
        }

        $licenseTypes = $query->paginate(50)->withQueryString();
        $vendors = LicenseType::distinct()->orderBy('vendor')->pluck('vendor');

        return view('license-types.index', [
            'licenseTypes' => $licenseTypes,
            'vendors' => $vendors,
            'vendor' => $request->query('vendor'),
            'active' => $request->query('active', '1'),
        ]);
    }

    public function create()
    {
        return view('license-types.create', [
            'skus' => Sku::active()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'vendor' => ['required', 'string', 'max:50'],
            'vendor_sku_id' => ['nullable', 'string', 'max:255'],
            'sku_id' => ['nullable', 'integer', 'exists:skus,id'],
            'default_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'cost_divisor' => ['nullable', 'integer', 'min:1'],
            'minimum_quantity' => ['nullable', 'integer', 'min:1'],
            'minimum_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ]);

        $validated['sku_id'] = ! empty($validated['sku_id']) ? $validated['sku_id'] : null;
        $validated['is_active'] = $request->boolean('is_active', true);

        $type = LicenseType::create($validated);

        return redirect()->route('license-types.show', $type)
            ->with('success', "License type \"{$type->name}\" created.");
    }

    public function show(LicenseType $licenseType)
    {
        $licenses = License::where('license_type_id', $licenseType->id)
            ->with('client')
            ->orderByDesc('quantity')
            ->get();

        return view('license-types.show', [
            'licenseType' => $licenseType,
            'licenses' => $licenses,
            'skus' => Sku::active()->orderBy('name')->get(),
        ]);
    }

    public function edit(LicenseType $licenseType)
    {
        return redirect()->route('license-types.show', $licenseType);
    }

    public function update(Request $request, LicenseType $licenseType)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'vendor' => ['required', 'string', 'max:50'],
            'vendor_sku_id' => ['nullable', 'string', 'max:255'],
            'sku_id' => ['nullable', 'integer', 'exists:skus,id'],
            'default_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'cost_divisor' => ['nullable', 'integer', 'min:1'],
            'minimum_quantity' => ['nullable', 'integer', 'min:1'],
            'minimum_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ]);

        $validated['sku_id'] = ! empty($validated['sku_id']) ? $validated['sku_id'] : null;
        $validated['is_active'] = $request->boolean('is_active');

        $licenseType->update($validated);

        return redirect()->route('license-types.show', $licenseType)
            ->with('success', 'License type updated.');
    }
}
