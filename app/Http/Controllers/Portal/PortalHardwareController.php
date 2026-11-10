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

        return view('portal.hardware', [
            'models' => $client->hardwareModels()->get(),
            'orders' => ClientHardwareOrder::where('client_id', $client->id)->latest()->paginate(25),
            'reason' => $service->reason($client, $person),
            'address' => $service->address($client), 'token' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, CarbonHardwareOrderService $service)
    {
        $service->submit($request->attributes->get('portal_person'), $request->all());

        return redirect()->route('portal.hardware')->with('success', __('carbon_hardware.recorded'));
    }
}
