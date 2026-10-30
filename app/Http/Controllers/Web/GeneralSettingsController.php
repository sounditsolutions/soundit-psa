<?php

namespace App\Http\Controllers\Web;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Setting;
use App\Models\User;
use App\Support\WikiConfig;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'brandingCompanyName' => (string) Setting::getValue('portal_company_name', ''),
            'brandingLogoUrl' => (string) Setting::getValue('portal_logo_url', ''),
            'invoicePrefix' => config('billing.invoice_prefix', 'INV'),
            'invoiceNextNumber' => Setting::getValue('billing_invoice_next_number', ''),
            'billingSkipZero' => (bool) Setting::getValue('billing_skip_zero_invoices', false),
            'allAssetTypes' => $allAssetTypes,
            'workstationTypes' => $workstationTypes,
            'serverTypes' => $serverTypes,
            'contactIntakeOwnerId' => (string) Setting::getValue('contact_intake_owner_id', ''),
            'contactIntakeEditable' => self::canEditContactIntake(request()->user()),
            'contactIntakeStaff' => self::canEditContactIntake(request()->user())
                ? self::contactIntakeStaff()->get(['id', 'name', 'role']) : collect(),
        ]);
    }

    public function update(Request $request)
    {
        if ($request->input('section') === 'branding') {
            return $this->updateBranding($request);
        }
        if ($request->input('section') === 'contact_intake') {
            return $this->updateContactIntake($request);
        }

        $validated = $request->validate([
            'app_timezone' => ['required', 'string', 'timezone'],
        ]);

        Setting::setValue('app_timezone', $validated['app_timezone']);

        return redirect()->route('settings.general')
            ->with('success', 'General settings saved.');
    }

    /**
     * General Settings > Branding (#7762): the MSP's company name and logo. Same keys the
     * Client Portal tab used to edit (portal_company_name / portal_logo_url), so no data
     * move; validation copied from IntegrationsController::updatePortal. Posted to
     * settings.general.update with section=branding, so it carries that route's permission.
     */
    private function updateBranding(Request $request)
    {
        $validated = $request->validate([
            'portal_company_name' => 'nullable|string|max:100',
            'portal_logo_url' => 'nullable|url|max:500',
        ]);

        Setting::setValue('portal_company_name', $validated['portal_company_name'] ?? '');
        Setting::setValue('portal_logo_url', $validated['portal_logo_url'] ?? '');

        return redirect()->route('settings.general')
            ->with('success', 'Branding saved.');
    }

    /** The contact intake owner routes every new web form ticket and fallback alert: admins only (r1 context:1). */
    private static function canEditContactIntake(?User $user): bool
    {
        return $user?->role === UserRole::Admin;
    }

    /** Active Admin and Tech staff: the same set StaffWorkflow::authorize admits (card JyBDeHPz). */
    private static function contactIntakeStaff(): \Illuminate\Database\Eloquent\Builder
    {
        return User::query()->where('is_active', true)
            ->whereIn('role', [UserRole::Admin->value, UserRole::Tech->value])->orderBy('name');
    }

    /**
     * Settings > General > Contact intake (card JyBDeHPz): writes contact_intake_owner_id, the
     * default assignee of new web form tickets and the fallback alert recipient. Blank stores
     * '' so ContactIntakeConfig::ownerId() keeps its existing default.
     */
    private function updateContactIntake(Request $request)
    {
        abort_unless(self::canEditContactIntake($request->user()), 403);
        $validated = $request->validate([
            'contact_intake_owner_id' => ['nullable', 'integer',
                Rule::exists('users', 'id')->where('is_active', true)
                    ->whereIn('role', [UserRole::Admin->value, UserRole::Tech->value])],
        ]);

        Setting::setValue('contact_intake_owner_id', isset($validated['contact_intake_owner_id'])
            ? (string) $validated['contact_intake_owner_id'] : '');

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

        // The config inputs are disabled in the UI while the module is off, so a save can omit them.
        // Fall back to the current stored value (not a hard-coded default) so turning the module off
        // and saving never silently resets an operator's configured budgets, limits, or model override.
        Setting::setValue('wiki_model', $validated['wiki_model'] ?? (string) Setting::getValue('wiki_model', ''));
        Setting::setValue('wiki_max_tokens_per_run', (string) ($validated['wiki_max_tokens_per_run'] ?? WikiConfig::maxTokensPerRun()));
        Setting::setValue('wiki_daily_token_limit', (string) ($validated['wiki_daily_token_limit'] ?? WikiConfig::dailyTokenLimit()));
        Setting::setValue('wiki_staleness_days_volatile', (string) ($validated['wiki_staleness_days_volatile'] ?? WikiConfig::stalenessDaysVolatile()));
        Setting::setValue('wiki_backfill_batch_size', (string) ($validated['wiki_backfill_batch_size'] ?? WikiConfig::backfillBatchSize()));

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
