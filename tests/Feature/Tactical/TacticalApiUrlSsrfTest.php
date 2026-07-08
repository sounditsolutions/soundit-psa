<?php

namespace Tests\Feature\Tactical;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 7 / amendment B2: the updateTactical save path rejects SSRF-prone API
 * URLs. The full DNS matrix lives in SafeUrlInspectorTest (deterministic stub);
 * here we confirm the controller wires the rule and persists only safe values.
 * Cases here need NO real DNS (scheme / IP literals).
 */
class TacticalApiUrlSsrfTest extends TestCase
{
    use RefreshDatabase;

    private function save(User $user, string $url)
    {
        return $this->actingAs($user)->from(route('settings.integrations'))->post(
            route('settings.integrations.tactical.update'),
            ['api_url' => $url],
        );
    }

    public function test_rejects_http_scheme(): void
    {
        $user = User::factory()->create();
        Setting::setValue('tactical_api_url', 'https://existing.example.com');

        $this->save($user, 'http://rmm.example.com')->assertSessionHasErrors('api_url');

        // Unchanged.
        $this->assertSame('https://existing.example.com', Setting::getValue('tactical_api_url'));
    }

    public function test_rejects_ipv6_loopback_literal(): void
    {
        $user = User::factory()->create();
        $this->save($user, 'https://[::1]/')->assertSessionHasErrors('api_url');
    }

    public function test_rejects_ipv4_mapped_metadata_literal(): void
    {
        $user = User::factory()->create();
        $this->save($user, 'https://[::ffff:169.254.169.254]/')->assertSessionHasErrors('api_url');
    }

    public function test_rejects_decimal_encoded_loopback(): void
    {
        $user = User::factory()->create();
        $this->save($user, 'https://2130706433/')->assertSessionHasErrors('api_url');
    }

    public function test_rejects_metadata_link_local_literal(): void
    {
        $user = User::factory()->create();
        $this->save($user, 'https://169.254.169.254/')->assertSessionHasErrors('api_url');
    }

    public function test_rejects_private_literal(): void
    {
        $user = User::factory()->create();
        $this->save($user, 'https://10.1.2.3/')->assertSessionHasErrors('api_url');
    }

    public function test_accepts_a_public_https_url(): void
    {
        $user = User::factory()->create();

        // A public IP literal needs no DNS and is routable -> accepted + saved.
        $this->save($user, 'https://93.184.216.34')
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('https://93.184.216.34', Setting::getValue('tactical_api_url'));
    }
}
