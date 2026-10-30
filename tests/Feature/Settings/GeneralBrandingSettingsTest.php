<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #7762: General Settings > Branding edits portal_company_name / portal_logo_url (no data
 * move); the Client Portal tab links to it and its save no longer touches those keys.
 */
class GeneralBrandingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function branding(array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('settings.general.update'), ['section' => 'branding'] + $fields);
    }

    public function test_branding_save_round_trips_both_keys_and_renders_them(): void
    {
        $this->actingAs(User::factory()->create());
        $this->branding(['portal_company_name' => 'Northwind MSP', 'portal_logo_url' => 'https://example.test/logo.png'])
            ->assertRedirect(route('settings.general'))->assertSessionHasNoErrors();
        $this->assertSame('Northwind MSP', Setting::getValue('portal_company_name'));
        $this->assertSame('https://example.test/logo.png', Setting::getValue('portal_logo_url'));
        $this->get(route('settings.general'))->assertOk()->assertSee('Branding')
            ->assertSee('value="Northwind MSP"', false)->assertSee('value="https://example.test/logo.png"', false);
        $this->assertSame('UTC', Setting::getValue('app_timezone', 'UTC'), 'branding save does not touch the timezone');
    }

    public function test_branding_validation_refuses_and_keeps_stored_values(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::setValue('portal_company_name', 'Northwind MSP');
        Setting::setValue('portal_logo_url', 'https://example.test/logo.png');
        $this->branding(['portal_company_name' => str_repeat('n', 101), 'portal_logo_url' => 'https://example.test/ok.png'])
            ->assertSessionHasErrors('portal_company_name');
        $this->branding(['portal_company_name' => 'Fine', 'portal_logo_url' => 'not a url'])
            ->assertSessionHasErrors('portal_logo_url');
        $this->branding(['portal_company_name' => 'Fine', 'portal_logo_url' => 'https://example.test/'.str_repeat('a', 480)])
            ->assertSessionHasErrors('portal_logo_url');
        $this->assertSame('Northwind MSP', Setting::getValue('portal_company_name'));
        $this->assertSame('https://example.test/logo.png', Setting::getValue('portal_logo_url'));
        $this->branding(['portal_company_name' => str_repeat('n', 100), 'portal_logo_url' => ''])->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('n', 100), Setting::getValue('portal_company_name'), 'max:100 boundary is accepted');
    }

    public function test_branding_save_has_general_settings_permission(): void
    {
        $this->branding(['portal_company_name' => 'Northwind MSP'])->assertRedirect(route('login'));
        $this->assertNull(Setting::getValue('portal_company_name'));
    }

    public function test_client_portal_save_leaves_branding_keys_unchanged_and_links_to_branding(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::setValue('portal_company_name', 'Northwind MSP');
        Setting::setValue('portal_logo_url', 'https://example.test/logo.png');
        $this->post(route('settings.integrations.portal.update'), [
            'portal_enabled' => '1', 'portal_shop_enabled' => '0', 'portal_chatbot_enabled' => '0',
            'portal_billing_url' => 'https://billing.example.test/x', 'portal_billing_label' => 'Pay', 'portal_order_url' => '',
        ])->assertRedirect(route('settings.integrations'))->assertSessionHasNoErrors();
        $this->assertSame('1', Setting::getValue('portal_enabled'), 'positive control: the portal save still writes its own fields');
        $this->assertSame('https://billing.example.test/x', Setting::getValue('portal_billing_url'));
        $this->assertSame('Northwind MSP', Setting::getValue('portal_company_name'));
        $this->assertSame('https://example.test/logo.png', Setting::getValue('portal_logo_url'));
        $html = $this->get(route('settings.integrations'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="portal_company_name"', $html);
        $this->assertStringNotContainsString('name="portal_logo_url"', $html);
        $this->assertStringContainsString(route('settings.general').'#branding', $html);
    }
}
