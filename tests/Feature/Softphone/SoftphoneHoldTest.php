<?php

namespace Tests\Feature\Softphone;

use App\Models\Setting;
use App\Models\User;
use App\Support\PlivoConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SoftphoneHoldTest extends TestCase
{
    use RefreshDatabase;

    private const CALL_UUID = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

    private function configurePlivo(): void
    {
        Setting::setValue('plivo_auth_id', 'MAXXXX');
        Setting::setEncrypted('plivo_auth_token', 'secret');
        Setting::setValue('plivo_did_number', '+15551230000');
    }

    private function staff(): User
    {
        return User::factory()->create();
    }

    public function test_softphone_page_renders_for_staff(): void
    {
        $this->actingAs($this->staff())
            ->get('/softphone')
            ->assertOk();
    }

    public function test_hold_requires_authentication(): void
    {
        $this->configurePlivo();
        Http::fake();

        $this->postJson('/softphone/hold', ['call_uuid' => self::CALL_UUID])
            ->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_hold_plays_looping_music_to_the_remote_leg(): void
    {
        $this->configurePlivo();
        Setting::setValue('plivo_hold_music_url', 'https://cdn.example.test/hold.mp3');
        Http::fake(['*' => Http::response('{}', 202)]);

        $this->actingAs($this->staff())
            ->postJson('/softphone/hold', ['call_uuid' => self::CALL_UUID])
            ->assertOk()
            ->assertJson(['success' => true]);

        Http::assertSent(function ($req) {
            return $req->method() === 'POST'
                && str_contains($req->url(), '/v1/Account/MAXXXX/Call/'.self::CALL_UUID.'/Play/')
                && $req['urls'] === 'https://cdn.example.test/hold.mp3'
                && $req['legs'] === 'bleg'
                && $req['loop'] === true
                && $req['mix'] === false;
        });
    }

    public function test_hold_falls_back_to_default_music_when_unset(): void
    {
        $this->configurePlivo();
        Http::fake(['*' => Http::response('{}', 202)]);

        $this->actingAs($this->staff())
            ->postJson('/softphone/hold', ['call_uuid' => self::CALL_UUID])
            ->assertOk()
            ->assertJson(['success' => true]);

        Http::assertSent(fn ($req) => $req['urls'] === PlivoConfig::DEFAULT_HOLD_MUSIC_URL);
    }

    public function test_unhold_stops_the_audio_via_delete(): void
    {
        $this->configurePlivo();
        Http::fake(['*' => Http::response('{}', 204)]);

        $this->actingAs($this->staff())
            ->postJson('/softphone/unhold', ['call_uuid' => self::CALL_UUID])
            ->assertOk()
            ->assertJson(['success' => true]);

        Http::assertSent(function ($req) {
            return $req->method() === 'DELETE'
                && str_contains($req->url(), '/v1/Account/MAXXXX/Call/'.self::CALL_UUID.'/Play/');
        });
    }

    public function test_hold_validates_call_uuid(): void
    {
        $this->configurePlivo();
        Http::fake();

        // Missing entirely
        $this->actingAs($this->staff())
            ->postJson('/softphone/hold', [])
            ->assertStatus(422);

        // Junk that would be unsafe to interpolate into the API URL path
        $this->actingAs($this->staff())
            ->postJson('/softphone/hold', ['call_uuid' => '../../etc/passwd'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_hold_reports_failure_when_plivo_unconfigured(): void
    {
        Http::fake();

        $this->actingAs($this->staff())
            ->postJson('/softphone/hold', ['call_uuid' => self::CALL_UUID])
            ->assertOk()
            ->assertJson(['success' => false]);

        Http::assertNothingSent();
    }

    public function test_hold_reports_failure_on_plivo_error(): void
    {
        $this->configurePlivo();
        Http::fake(['*' => Http::response('nope', 404)]);

        $this->actingAs($this->staff())
            ->postJson('/softphone/hold', ['call_uuid' => self::CALL_UUID])
            ->assertOk()
            ->assertJson(['success' => false]);
    }
}
