<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Setting;
use Illuminate\Http\Request;

class GeneralSettingsController extends Controller
{
    public function index()
    {
        // Get all distinct asset types from the database
        $allAssetTypes = Asset::whereNotNull('asset_type')
            ->where('asset_type', '!=', '')
            ->distinct()
            ->orderBy('asset_type')
            ->pluck('asset_type')
            ->all();

        // Current selections (from Settings, fall back to config)
        $workstationTypes = $this->getSettingArray('billing_workstation_types',
            config('billing.quantity_sources.per_workstation.asset_types', []));
        $serverTypes = $this->getSettingArray('billing_server_types',
            config('billing.quantity_sources.per_server.asset_types', []));

        return view('settings.general', [
            'appTimezone' => Setting::getValue('app_timezone', 'UTC'),
            'invoicePrefix' => config('billing.invoice_prefix', 'INV'),
            'invoiceNextNumber' => Setting::getValue('billing_invoice_next_number', ''),
            'billingSkipZero' => (bool) Setting::getValue('billing_skip_zero_invoices', false),
            'allAssetTypes' => $allAssetTypes,
            'workstationTypes' => $workstationTypes,
            'serverTypes' => $serverTypes,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'app_timezone' => ['required', 'string', 'timezone'],
        ]);

        Setting::setValue('app_timezone', $validated['app_timezone']);

        return redirect()->route('settings.general')
            ->with('success', 'General settings saved.');
    }

    public function updateBillingNumbering(Request $request)
    {
        $validated = $request->validate([
            'next_number' => ['required', 'integer', 'min:1'],
        ]);

        Setting::setValue('billing_invoice_next_number', $validated['next_number']);

        $prefix = config('billing.invoice_prefix', 'INV');

        return redirect()->route('settings.general')
            ->with('success', "Invoice numbering will continue from {$prefix}-" . sprintf('%05d', $validated['next_number']) . ".");
    }

    public function updateBillingSkipZero(Request $request)
    {
        Setting::setValue('billing_skip_zero_invoices', $request->boolean('billing_skip_zero_invoices') ? '1' : '0');

        return redirect()->route('settings.general')
            ->with('success', 'Empty invoice setting saved.');
    }

    public function updateBillingTypes(Request $request)
    {
        $workstationTypes = $request->input('workstation_types', []);
        $serverTypes = $request->input('server_types', []);

        Setting::setValue('billing_workstation_types', json_encode(array_values($workstationTypes)));
        Setting::setValue('billing_server_types', json_encode(array_values($serverTypes)));

        return redirect()->route('settings.general')
            ->with('success', 'Billing asset type mappings saved.');
    }

    private function getSettingArray(string $key, array $fallback): array
    {
        $json = Setting::getValue($key);
        if ($json) {
            $arr = json_decode($json, true);
            if (is_array($arr) && count($arr) > 0) {
                return $arr;
            }
        }

        return $fallback;
    }
}
