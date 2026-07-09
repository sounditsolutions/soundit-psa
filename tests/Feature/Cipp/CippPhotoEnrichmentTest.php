<?php

namespace Tests\Feature\Cipp;

use App\Enums\ClientStage;
use App\Models\Client;
use App\Models\Person;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippContactEnrichmentService;
use App\Services\SyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class CippPhotoEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_stores_photo_when_cipp_returns_image_bytes(): void
    {
        $client = $this->activeClient();
        $person = $this->person($client, ['cipp_user_id' => 'obj-1']);

        (new CippContactEnrichmentService($this->cippReturningPhoto($this->samplePng())))
            ->enrichForClient($client, new SyncResult);

        $person = $person->fresh();
        $this->assertSame("avatars/people/{$person->id}.jpg", $person->avatar_path);
        $this->assertNotNull($person->avatar_synced_at);
        Storage::disk('public')->assertExists("avatars/people/{$person->id}.jpg");

        // The stored bytes are a normalized 200px square JPEG.
        $info = getimagesizefromstring(Storage::disk('public')->get($person->avatar_path));
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertSame(200, $info[0]);
        $this->assertSame(200, $info[1]);
    }

    public function test_no_photo_stamps_synced_at_without_storing_avatar(): void
    {
        $client = $this->activeClient();
        $person = $this->person($client, ['cipp_user_id' => 'obj-2']);

        $cipp = Mockery::mock(CippClient::class)->shouldIgnoreMissing();
        $cipp->shouldReceive('getUserPhoto')->once()->andReturn([
            'status' => 200,
            'contentType' => 'application/json; charset=utf-8',
            'body' => '{"error":{"code":"ImageNotFound"}}',
        ]);

        (new CippContactEnrichmentService($cipp))->enrichForClient($client, new SyncResult);

        $person = $person->fresh();
        $this->assertNull($person->avatar_path);
        $this->assertNotNull($person->avatar_synced_at);
    }

    public function test_skips_person_checked_within_ttl(): void
    {
        $client = $this->activeClient();
        $person = $this->person($client, [
            'cipp_user_id' => 'obj-3',
            'avatar_synced_at' => now()->subDays(5),
        ]);

        $cipp = Mockery::mock(CippClient::class)->shouldIgnoreMissing();
        $cipp->shouldReceive('getUserPhoto')->never();

        (new CippContactEnrichmentService($cipp))->enrichForClient($client, new SyncResult);

        $this->assertNull($person->fresh()->avatar_path);
    }

    public function test_refetches_when_ttl_elapsed(): void
    {
        $client = $this->activeClient();
        $person = $this->person($client, [
            'cipp_user_id' => 'obj-4',
            'avatar_synced_at' => now()->subDays(31),
        ]);

        (new CippContactEnrichmentService($this->cippReturningPhoto($this->samplePng())))
            ->enrichForClient($client, new SyncResult);

        $this->assertSame("avatars/people/{$person->id}.jpg", $person->fresh()->avatar_path);
    }

    public function test_skips_persons_without_cipp_user_id(): void
    {
        $client = $this->activeClient();
        $person = $this->person($client, ['cipp_user_id' => null]);

        $cipp = Mockery::mock(CippClient::class)->shouldIgnoreMissing();
        $cipp->shouldReceive('getUserPhoto')->never();

        (new CippContactEnrichmentService($cipp))->enrichForClient($client, new SyncResult);

        $person = $person->fresh();
        $this->assertNull($person->avatar_path);
        $this->assertNull($person->avatar_synced_at);
    }

    public function test_avatar_url_prefers_synced_photo_over_gravatar(): void
    {
        $client = $this->activeClient();
        $withPhoto = $this->person($client, ['email' => 'a@example.test', 'avatar_path' => 'avatars/people/9.jpg']);
        $withoutPhoto = $this->person($client, ['email' => 'b@example.test']);

        $this->assertStringContainsString('avatars/people/9.jpg', $withPhoto->avatar_url);
        $this->assertStringContainsString('gravatar.com', $withoutPhoto->avatar_url);
    }

    private function cippReturningPhoto(string $bytes): CippClient
    {
        $cipp = Mockery::mock(CippClient::class)->shouldIgnoreMissing();
        $cipp->shouldReceive('getUserPhoto')->andReturn([
            'status' => 200,
            'contentType' => 'image/jpeg',
            'body' => $bytes,
        ]);

        return $cipp;
    }

    private function activeClient(): Client
    {
        return Client::factory()->create([
            'cipp_tenant_domain' => 'contoso.onmicrosoft.com',
            'stage' => ClientStage::Active,
            'is_active' => true,
        ]);
    }

    private function person(Client $client, array $attrs = []): Person
    {
        return Person::create(array_merge([
            'client_id' => $client->id,
            'first_name' => 'Test',
            'last_name' => 'Person',
            'email' => 'p'.uniqid().'@example.test',
            'is_active' => true,
        ], $attrs));
    }

    private function samplePng(): string
    {
        $img = imagecreatetruecolor(240, 120);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 200));
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return ob_get_clean();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
