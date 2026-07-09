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
            'prepayExpiryMonths' => Setting::getValue('prepay_expiry_months', ''),
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
            ->with('success', "Invoice numbering will continue from {$prefix}-".sprintf('%05d', $validated['next_number']).'.');
    }

    public function updateBillingSkipZero(Request $request)
    {
        Setting::setValue('billing_skip_zero_invoices', $request->boolean('billing_skip_zero_invoices') ? '1' : '0');

        return redirect()->route('settings.general')
            ->with('success', 'Empty invoice setting saved.');
    }

    public function updatePrepayExpiry(Request $request)
    {
        $validated = $request->validate([
            // Blank → no global expiration. A positive value sets the default
            // term; individual contracts can override it (including 0 = never).
            'prepay_expiry_months' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        Setting::setValue(
            'prepay_expiry_months',
            ($validated['prepay_expiry_months'] ?? null) !== null
                ? (string) $validated['prepay_expiry_months']
                : ''
        );

        return redirect()->route('settings.general')
            ->with('success', 'Prepaid time expiration default saved.');
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

    public function updateWiki(Request $request)
    {
        $validated = $request->validate([
            'wiki_enabled' => ['nullable', 'boolean'],
            'wiki_auto_mine' => ['nullable', 'boolean'],
            'wiki_maintenance_enabled' => ['nullable', 'boolean'],
            'wiki_model' => ['nullable', 'string', 'max:100'],
            'wiki_max_tokens_per_run' => ['nullable', 'integer', 'min:1000', 'max:200000'],
            'wiki_daily_token_limit' => ['nullable', 'integer', 'min:10000', 'max:5000000'],
            'wiki_staleness_days_volatile' => ['nullable', 'integer', 'min:1'],
            'wiki_backfill_batch_size' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        Setting::setValue('wiki_enabled', $request->boolean('wiki_enabled') ? '1' : '0');
        Setting::setValue('wiki_auto_mine', $request->boolean('wiki_auto_mine') ? '1' : '0');
        Setting::setValue('wiki_maintenance_enabled', $request->boolean('wiki_maintenance_enabled') ? '1' : '0');
        Setting::setValue('wiki_model', $validated['wiki_model'] ?? '');
        Setting::setValue('wiki_max_tokens_per_run', (string) ($validated['wiki_max_tokens_per_run'] ?? 50000));
        Setting::setValue('wiki_daily_token_limit', (string) ($validated['wiki_daily_token_limit'] ?? 500000));
        Setting::setValue('wiki_staleness_days_volatile', (string) ($validated['wiki_staleness_days_volatile'] ?? 90));
        Setting::setValue('wiki_backfill_batch_size', (string) ($validated['wiki_backfill_batch_size'] ?? 25));

        return redirect()->route('settings.general')->with('success', 'Wiki settings updated.');
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
