<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the global default "Prepaid Time Expiration" setting on the general
 * settings page (psa-5uy). The per-contract override wins over this default;
 * see PrepayExpiryStampingTest for the resolution semantics.
 *
 * Auth gate: settings routes live inside Route::middleware('auth')->group(),
 * so actingAs($user) with any valid user is all that is required.
 */
class PrepayExpirySettingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_posting_a_month_count_saves_the_global_default(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.general.prepay-expiry'), [
                'prepay_default_expiry_months' => '12',
            ])
            ->assertRedirect(route('settings.general'));

        $this->assertSame('12', (string) Setting::getValue('prepay_default_expiry_months'));
    }

    public function test_posting_blank_stores_zero_meaning_never(): void
    {
        Setting::setValue('prepay_default_expiry_months', 12);

        $this->actingAs($this->user)
            ->post(route('settings.general.prepay-expiry'), [
                'prepay_default_expiry_months' => '',
            ])
            ->assertRedirect(route('settings.general'));

        $this->assertSame(0, (int) Setting::getValue('prepay_default_expiry_months'));
    }

    public function test_posting_explicit_zero_is_accepted(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.general.prepay-expiry'), [
                'prepay_default_expiry_months' => '0',
            ])
            ->assertRedirect(route('settings.general'));

        $this->assertSame(0, (int) Setting::getValue('prepay_default_expiry_months'));
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.general.prepay-expiry'), [
                'prepay_default_expiry_months' => '999',
            ])
            ->assertSessionHasErrors('prepay_default_expiry_months');

        $this->assertNull(Setting::getValue('prepay_default_expiry_months'));
    }

    public function test_negative_values_are_rejected(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.general.prepay-expiry'), [
                'prepay_default_expiry_months' => '-3',
            ])
            ->assertSessionHasErrors('prepay_default_expiry_months');
    }
}
