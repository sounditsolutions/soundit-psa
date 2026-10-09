<?php

namespace Tests\Feature\Mesh;

use App\Models\Setting;
use App\Models\User;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshLicenseSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #6114: a client-detected statusPhrase() is the PSA's own sentence and
 * already ends in '.'. The Mesh customers page and the sync button print
 * one full stop after it, as mesh:test does (#6053), and one after an
 * upstream phrase, which has none.
 *
 * The customers page is driven with a base URL PSR-7 refuses, so the read
 * client refuses before any transport exists (nothing can be sent). The
 * sync button's service is a mock. Synthetic data (G-13).
 */
class MeshFlashFullStopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setEncrypted('mesh_api_key', 'test-placeholder-not-a-key');
    }

    public function test_the_customers_page_prints_one_full_stop_after_a_client_detected_phrase(): void
    {
        Setting::setValue('mesh_base_url', '//synthuser-6114:SYNTHPASS-6114@mesh-6114.example.test:99999');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.mesh-customers.index'))
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame('Could not load Mesh customers: The Mesh base URL could not be parsed; nothing was sent.', session('error'));
    }

    /** @return array<string, array{0: MeshClientException, 1: string}> */
    public static function syncFailures(): array
    {
        return [
            'client-detected' => [
                new MeshClientException('The Mesh base URL could not be parsed; nothing was sent.', clientDetected: true, nothingSent: true),
                'Mesh sync failed: The Mesh base URL could not be parsed; nothing was sent.',
            ],
            'upstream, with a status' => [
                new MeshClientException('Mesh API error: GET api/customers/ failed with HTTP 503', 503),
                'Mesh sync failed: the Mesh host (or something in front of it) answered the license sync with HTTP 503.',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('syncFailures')]
    public function test_the_sync_button_prints_one_full_stop(MeshClientException $thrown, string $expected): void
    {
        $mock = \Mockery::mock(MeshLicenseSyncService::class);
        $mock->shouldReceive('syncLicenses')->andThrow($thrown);
        $this->app->bind(MeshLicenseSyncService::class, fn () => $mock);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('settings.integrations'))
            ->post(route('settings.integrations.mesh.sync'))
            ->assertRedirect(route('settings.integrations'));

        $this->assertSame($expected, session('error'));
    }
}
