<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LicenseType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResellerReportController extends Controller
{
    public function index(Request $request)
    {
        $resellerId = $request->query('reseller_id');
        $licenseTypeId = $request->query('license_type_id');

        // Resellers = clients that have at least one child
        $resellers = Client::whereHas('resellerChildren')
            ->orderBy('name')
            ->get(['id', 'name']);

        $data = null;

        if ($resellerId) {
            $reseller = Client::find($resellerId);

            if ($reseller) {
                $childIds = Client::where('reseller_id', $reseller->id)
                    ->where('is_active', true)
                    ->pluck('id');

                $childClients = Client::whereIn('id', $childIds)
                    ->orderBy('name')
                    ->get(['id', 'name']);

                // Aggregate licenses by type across all children in a single query
                $query = DB::table('licenses')
                    ->join('license_types', 'licenses.license_type_id', '=', 'license_types.id')
                    ->join('clients', 'licenses.client_id', '=', 'clients.id')
                    ->whereIn('licenses.client_id', $childIds)
                    ->where('licenses.status', 'active');

                if ($licenseTypeId) {
                    $query->where('licenses.license_type_id', $licenseTypeId);
                }

                // Per-type totals
                $typeTotals = (clone $query)
                    ->select(
                        'license_types.id as license_type_id',
                        'license_types.name as license_type_name',
                        'license_types.vendor',
                        DB::raw('SUM(licenses.quantity) as total_quantity'),
                        DB::raw('COUNT(DISTINCT licenses.client_id) as client_count'),
                    )
                    ->groupBy('license_types.id', 'license_types.name', 'license_types.vendor')
                    ->orderBy('license_types.name')
                    ->get();

                // Per-client per-type breakdown
                $clientBreakdown = (clone $query)
                    ->select(
                        'licenses.client_id',
                        'clients.name as client_name',
                        'licenses.license_type_id',
                        'license_types.name as license_type_name',
                        DB::raw('SUM(licenses.quantity) as quantity'),
                    )
                    ->groupBy('licenses.client_id', 'clients.name', 'licenses.license_type_id', 'license_types.name')
                    ->orderBy('clients.name')
                    ->orderBy('license_types.name')
                    ->get()
                    ->groupBy('license_type_id');

                $data = [
                    'reseller' => $reseller,
                    'childClients' => $childClients,
                    'typeTotals' => $typeTotals,
                    'clientBreakdown' => $clientBreakdown,
                    'grandTotal' => $typeTotals->sum('total_quantity'),
                ];
            }
        }

        $licenseTypes = LicenseType::orderBy('name')->get(['id', 'name']);

        return view('reseller-report.index', [
            'resellers' => $resellers,
            'licenseTypes' => $licenseTypes,
            'selectedResellerId' => $resellerId,
            'selectedLicenseTypeId' => $licenseTypeId,
            'data' => $data,
        ]);
    }
}
