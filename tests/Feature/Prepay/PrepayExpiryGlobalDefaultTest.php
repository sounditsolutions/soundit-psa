<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the global prepay_expiry_months default and its per-contract override,
 * mirroring the billing_skip_zero_invoices pattern (global setting + nullable
 * per-record override where null = inherit global). See psa-nte.
 */
class PrepayExpiryGlobalDefaultTest extends TestCase
{
    use RefreshDatabase;

    private function prepayContract(): Contract
    {
        $client = Client::create(['name' => 'Acme Corp']);

        return Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed Services',
            'type' => 'managed',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'prepay_as_amount' => false,
            'prepay_total' => 0,
            'prepay_used' => 0,
            'prepay_balance' => 0,
        ]);
    }

    public function test_settings_page_shows_prepay_expiration_card(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/general')
            ->assertOk()
            ->assertSee('Prepaid Time Expiration')
            ->assertSee('prepay_expiry_months', false); // form field name present in HTML
    }

    public function test_settings_form_saves_global_expiry(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/settings/general/prepay-expiry', [
            'prepay_expiry_months' => 12,
        ])->assertRedirect();

        $this->assertSame('12', Setting::getValue('prepay_expiry_months'));
    }

    public function test_settings_form_clears_global_expiry_when_blank(): void
    {
        Setting::setValue('prepay_expiry_months', '12');
        $user = User::factory()->create();

        $this->actingAs($user)->post('/settings/general/prepay-expiry', [
            'prepay_expiry_months' => '',
        ])->assertRedirect();

        // Blank → no global expiration; the effective value is "never".
        $this->assertSame('', Setting::getValue('prepay_expiry_months'));
        $this->assertNull($this->prepayContract()->effectivePrepayExpiryMonths());
    }

    public function test_settings_form_rejects_zero_global_default(): void
    {
        // The global has no "never" sentinel — blank already means no expiration,
        // so 0 is out of range (min:1) and must be rejected.
        $user = User::factory()->create();

        $this->actingAs($user)->post('/settings/general/prepay-expiry', [
            'prepay_expiry_months' => 0,
        ])->assertSessionHasErrors('prepay_expiry_months');
    }

    public function test_staff_contract_explicit_zero_stores_never_expire_override(): void
    {
        Setting::setValue('prepay_expiry_months', '6');
        $contract = $this->prepayContract();
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('contracts.update-alert-settings', $contract), [
            'prepay_alert_threshold' => 5,
            'prepay_auto_topup_enabled' => '0',
            'prepay_expiry_months' => 0, // opt this contract out of the global policy
        ])->assertRedirect();

        $contract->refresh();
        $this->assertSame(0, $contract->prepay_expiry_months);        // stored, not collapsed to null
        $this->assertNull($contract->effectivePrepayExpiryMonths());  // 0 = never, despite global 6
    }

    public function test_staff_contract_blank_stores_null_and_inherits_global(): void
    {
        Setting::setValue('prepay_expiry_months', '6');
        $contract = $this->prepayContract();
        $contract->update(['prepay_expiry_months' => 12]); // start with an override
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('contracts.update-alert-settings', $contract), [
            'prepay_alert_threshold' => 5,
            'prepay_auto_topup_enabled' => '0',
            'prepay_expiry_months' => '', // clear the override → inherit global
        ])->assertRedirect();

        $contract->refresh();
        $this->assertNull($contract->prepay_expiry_months);          // cleared to null
        $this->assertSame(6, $contract->effectivePrepayExpiryMonths()); // now inherits the global default
    }
}
