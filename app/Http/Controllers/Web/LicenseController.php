<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\License;
use App\Models\LicenseType;
use App\Services\AppRiver\AppRiverClientException;
use App\Services\AppRiver\AppRiverLicenseSyncService;
use App\Services\ContractAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LicenseController extends Controller
{
    public function index(Request $request)
    {
        $query = License::with(['licenseType', 'client'])
            ->orderBy('updated_at', 'desc');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->query('client_id'));
        }

        if ($request->filled('license_type_id')) {
            $query->where('license_type_id', $request->query('license_type_id'));
        }

        if ($request->filled('vendor')) {
            $query->whereHas('licenseType', fn ($q) => $q->where('vendor', $request->query('vendor')));
        }

        if ($request->boolean('waste_only')) {
            $query->whereNotNull('assigned_quantity')
                ->whereColumn('assigned_quantity', '<', 'quantity');
        }

        $licenses = $query->paginate(50)->withQueryString();

        return view('licenses.index', [
            'licenses' => $licenses,
            'clients' => Client::operational()->orderBy('name')->get(['id', 'name']),
            'licenseTypes' => LicenseType::active()->orderBy('name')->get(['id', 'name']),
            'vendors' => LicenseType::distinct()->orderBy('vendor')->pluck('vendor'),
            'clientId' => $request->query('client_id'),
            'licenseTypeId' => $request->query('license_type_id'),
            'vendor' => $request->query('vendor'),
            'wasteOnly' => $request->boolean('waste_only'),
        ]);
    }

    public function create()
    {
        return view('licenses.create', [
            'clients' => Client::operational()->orderBy('name')->get(['id', 'name']),
            'licenseTypes' => LicenseType::active()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'license_type_id' => ['required', 'exists:license_types,id'],
            'client_id' => ['required', 'exists:clients,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'vendor_ref' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:active,suspended,cancelled'],
            'notes' => ['nullable', 'string'],
        ]);

        $license = License::create($validated);

        return redirect()->route('licenses.index')
            ->with('success', 'License created.');
    }

    public function update(Request $request, License $license)
    {
        if (! $license->is_manual) {
            return back()->with('error', 'Synced licenses cannot be edited. Update them from the integration source.');
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
            'status' => ['nullable', 'string', 'in:active,suspended,cancelled'],
            'notes' => ['nullable', 'string'],
        ]);

        $license->update($validated);

        return back()->with('success', 'License updated.');
    }

    public function destroy(License $license)
    {
        if ($license->synced_at) {
            return back()->with('error', 'Cannot delete a synced license. Remove it from the integration source instead.');
        }

        $license->contracts()->detach();
        $license->delete();

        return redirect()->route('licenses.index')
            ->with('success', 'License deleted.');
    }

    /**
     * Assign a license to a contract (from contract assignments panel).
     */
    public function assignToContract(Request $request, Contract $contract)
    {
        $request->validate(['license_id' => ['required', 'exists:licenses,id']]);

        $license = License::findOrFail($request->license_id);
        if ($license->client_id !== $contract->client_id) {
            return back()->with('error', 'License does not belong to this contract\'s client.');
        }

        if (! $contract->licenses()->where('license_id', $request->license_id)->exists()) {
            $contract->licenses()->attach($request->license_id, [
                'assignment_source' => 'manual',
                'assigned_at' => now(),
            ]);
        }

        return back()->with('success', 'License assigned to contract.');
    }

    /**
     * Assign all unassigned client licenses to a contract.
     */
    public function assignAllToContract(Contract $contract, ContractAssignmentService $assignmentService)
    {
        $count = $assignmentService->assignAllLicenses($contract);

        if ($count === 0) {
            return back()->with('info', 'No unassigned licenses to add.');
        }

        return back()->with('success', "{$count} license(s) assigned to contract.");
    }

    /**
     * Push a seat count change to AppRiver (thin controller — logic in service).
     */
    public function updateQuantity(Request $request, License $license)
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $license->loadMissing(['licenseType', 'client']);

        if (! $license->seat_manageable) {
            return back()->with('error', 'Seat management is not available for this license.');
        }

        try {
            $syncService = app(AppRiverLicenseSyncService::class);
            $syncService->updateQuantity($license, $validated['quantity'], auth()->id());

            return back()->with('success', "Seat count updated to {$validated['quantity']}.");
        } catch (AppRiverClientException $e) {
            // Queued reductions show as warning, not error
            if (str_contains($e->getMessage(), 'Reduction queued')) {
                return back()->with('warning', $e->getMessage());
            }

            Log::error("[AppRiver] Seat update failed: {$e->getMessage()}");

            return back()->with('error', 'Failed to update seat count: '.$e->getMessage());
        }
    }

    public function unassignFromContract(Contract $contract, License $license)
    {
        $contract->licenses()->detach($license->id);

        return back()->with('success', 'License removed from contract.');
    }
}
