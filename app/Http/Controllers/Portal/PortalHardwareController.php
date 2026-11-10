<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientHardwareOrder;
use App\Services\Carbon\CarbonHardwareOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PortalHardwareController extends Controller
{
    public function index(Request $request, CarbonHardwareOrderService $service)
    {
        $person = $request->attributes->get('portal_person');
        $client = Client::findOrFail($person->client_id);
        abort_unless(CarbonHardwareOrderService::available($client), 404);
        $orders = ClientHardwareOrder::where('client_id', $client->id);
        if (! $person->company_wide_access) {
            $orders->where('person_id', $person->id);
        }

        return view('portal.hardware', [
            'models' => $client->hardwareModels()->get(),
            'orders' => $orders->latest()->paginate(25),
            'reason' => $service->reason($client, $person),
            'address' => $service->address($client), 'token' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, CarbonHardwareOrderService $service)
    {
        $person = $request->attributes->get('portal_person');
        abort_unless(CarbonHardwareOrderService::available(Client::findOrFail($person->client_id)), 404);
        // #8311: staff viewing the portal as a contact never place a vendor order in that contact's name.
        abort_if($request->session()->has('portal_impersonator_id'), 403);
        $service->submit($person, $request->all());

        return redirect()->route('portal.hardware')->with('success', __('carbon_hardware.recorded'));
    }
}
