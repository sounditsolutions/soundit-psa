<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Carbon\CarbonHardwareService;
use App\Support\CarbonHardwareConfig;
use App\Support\CarbonMcpConfig;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClientHardwareController extends Controller
{
    public function show(Client $client, CarbonHardwareService $hardware)
    {
        $view = app(ClientController::class)->show($client);
        $customers = [];
        $lookupError = null;
        if (CarbonMcpConfig::isLive()) {
            try {
                $customers = $hardware->customers();
            } catch (\Throwable $e) {
                $lookupError = __('carbon_hardware.failed').' ('.class_basename($e).', '.(int) $e->getCode().')';
            }
        }

        return $view->with([
            'activeTab' => 'hardware',
            'hardwareModels' => $client->hardwareModels()->orderBy('id')->get(),
            'hardwareCustomers' => $customers,
            'hardwareLookupError' => $lookupError,
            'hardwareLive' => CarbonMcpConfig::isLive(),
            'hardwareEnabled' => CarbonHardwareConfig::isEnabled(),
        ]);
    }

    public function mapping(Request $request, Client $client, CarbonHardwareService $hardware)
    {
        $data = $request->validate([
            'carbon_customer_id' => ['nullable', 'integer', 'min:1'],
            'enabled' => ['required', 'boolean'],
        ]);
        try {
            $hardware->map($client, isset($data['carbon_customer_id']) ? (int) $data['carbon_customer_id'] : null, (bool) $data['enabled']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->with('error', __('carbon_hardware.failed').' ('.class_basename($e).', '.(int) $e->getCode().')');
        }

        return back()->with('success', __('carbon_hardware.saved'));
    }

    public function model(Request $request, Client $client, string $operation)
    {
        $names = ['add' => 'hardware_add_approved_model', 'price' => 'hardware_set_sell_price', 'remove' => 'hardware_remove_approved_model'];
        abort_unless(isset($names[$operation]), 404);
        $result = app(\App\Services\Mcp\StaffCarbonHardwareExecutor::class)->execute($names[$operation], $request->only(['mfg_part_no', 'model_id', 'sell_price', 'confirm_below_cost']), $client->id);

        return back()->with(isset($result['error']) ? 'error' : 'success', $result['error'] ?? __('carbon_hardware.saved'));
    }

    public function settings(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'country' => ['required', 'string', 'max:100']]);
        CarbonHardwareConfig::save((bool) $data['enabled'], $data['country']);

        return back()->with('success', __('carbon_hardware.saved'));
    }
}
