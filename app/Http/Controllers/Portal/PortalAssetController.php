<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalAssetController extends Controller
{
    public function index(Request $request): View
    {
        $clientId = $request->attributes->get('portal_client_id');

        $query = Asset::where('client_id', $clientId)
            ->where('is_active', true);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('hostname', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%");
            });
        }

        $assets = $query->orderBy('hostname')->paginate(25)->withQueryString();

        return view('portal.assets.index', compact('assets', 'search'));
    }

    public function show(Request $request, Asset $asset): View
    {
        $clientId = $request->attributes->get('portal_client_id');

        if ($asset->client_id !== $clientId) {
            abort(403);
        }

        $asset->load('contracts');

        return view('portal.assets.show', compact('asset'));
    }
}
