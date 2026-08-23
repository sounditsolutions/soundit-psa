<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\License;
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
                    ->operational()
                    ->pluck('id');

                $childClients = Client::whereIn('id', $childIds)
                    ->orderBy('name')
                    ->get(['id', 'name']);

                // Aggregate licenses by type across all children in a single query.
                //
                // Vendor-held rows (License::VENDOR_HELD_STATUSES — the vendor has
                // affirmatively reported the subscription Suspended/Pending) stay VISIBLE
                // on this surface but their quantity is OUT of every total: `total_quantity`
                // and `quantity` below are billable-only conditional sums, `held_quantity`
                // carries the held seats for annotation. This is the reseller-facing
                // surface, so — unlike the customer report — it may name the vendor's own
                // status word; the two surfaces deliberately do not share a string.
                // Raw query builder: no Eloquent scope reaches this site, hence the
                // explicit conditional aggregation (see License::vendorBillable()).
                $heldIn = "'".implode("','", License::VENDOR_HELD_STATUSES)."'";
                $billableSum = "SUM(CASE WHEN licenses.vendor_status IS NULL OR licenses.vendor_status NOT IN ({$heldIn}) THEN licenses.quantity ELSE 0 END)";
                $heldSum = "SUM(CASE WHEN licenses.vendor_status IN ({$heldIn}) THEN licenses.quantity ELSE 0 END)";

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
                        DB::raw("{$billableSum} as total_quantity"),
                        DB::raw("{$heldSum} as held_quantity"),
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
                        DB::raw("{$billableSum} as quantity"),
                        DB::raw("{$heldSum} as held_quantity"),
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
                    'heldStatusLabel' => implode('/', License::VENDOR_HELD_STATUSES),
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
