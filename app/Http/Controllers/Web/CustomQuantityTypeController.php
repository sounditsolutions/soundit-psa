<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\CustomQuantityType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomQuantityTypeController extends Controller
{
    public function index()
    {
        return view('settings.quantity-types.index', [
            'customTypes' => CustomQuantityType::withCount('profileLines')->orderBy('name')->get(),
            'allAssetTypes' => $this->distinctAssetTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        CustomQuantityType::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'asset_types' => array_values($validated['asset_types']),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('settings.quantity-types.index')
            ->with('success', "Custom quantity type \"{$validated['name']}\" created.");
    }

    public function edit(CustomQuantityType $quantityType)
    {
        return view('settings.quantity-types.edit', [
            'customType' => $quantityType,
            'allAssetTypes' => $this->distinctAssetTypes(),
        ]);
    }

    public function update(Request $request, CustomQuantityType $quantityType): RedirectResponse
    {
        $validated = $this->validatePayload($request, $quantityType->id);

        $quantityType->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'asset_types' => array_values($validated['asset_types']),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('settings.quantity-types.index')
            ->with('success', "Custom quantity type \"{$quantityType->name}\" updated.");
    }

    public function destroy(CustomQuantityType $quantityType): RedirectResponse
    {
        // Protect billing integrity — a type still referenced by a profile line
        // cannot be deleted (it would silently resolve those lines to qty 0).
        // Operators deactivate instead, which hides it from new lines.
        if ($quantityType->profileLines()->exists()) {
            return redirect()->route('settings.quantity-types.index')
                ->with('error', "Cannot delete \"{$quantityType->name}\" — it is still used by one or more recurring profile lines. Deactivate it instead.");
        }

        $quantityType->delete();

        return redirect()->route('settings.quantity-types.index')
            ->with('success', "Custom quantity type \"{$quantityType->name}\" deleted.");
    }

    /**
     * @return array{name: string, description: ?string, asset_types: array<int, string>}
     */
    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('custom_quantity_types', 'name')->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'asset_types' => ['required', 'array', 'min:1'],
            'asset_types.*' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * Distinct asset_type strings present in the asset database — the same
     * source the "Billing Asset Type Mapping" settings section uses.
     *
     * @return array<int, string>
     */
    private function distinctAssetTypes(): array
    {
        return Asset::whereNotNull('asset_type')
            ->where('asset_type', '!=', '')
            ->distinct()
            ->orderBy('asset_type')
            ->pluck('asset_type')
            ->all();
    }
}
