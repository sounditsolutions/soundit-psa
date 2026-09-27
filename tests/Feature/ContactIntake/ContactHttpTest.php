<?php

namespace Tests\Feature\ContactIntake;

use App\Models\ContactSubmission;
use App\Models\Setting;
use App\Services\ContactIntake\SubmissionAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ContactHttpTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-only-contact-intake-key-0001-abcdef';

    private const BODY = '{"submission_id":"0f8e6a52-3c1d-4b7e-9a10-5d2c7e4b9f31","name":"Test Person","email":"test.person@example.com","message":"Synthetic test message é","phone":null,"company":null,"inquiry":"managed-it","submitted_at":"2026-09-25T16:00:00Z"}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::createFromTimestampUTC(1790352000));
        Setting::setValue('contact_intake_enabled', '1');
        Setting::setEncrypted('contact_intake_keys', json_encode(['website-test' => ['secret' => self::KEY, 'revoked' => false]]));
        RateLimiter::clear('contact-intake:'.hash('sha256', 'website-test'));
    }

    private function sendBody(string $body = self::BODY, ?string $signature = null, string $timestamp = '1790352000', string $path = SubmissionAuthenticator::PATH)
    {
        $signature ??= hash_hmac('sha256', "POST\n".SubmissionAuthenticator::PATH."\nwebsite-test\n".$timestamp."\n".hash('sha256', $body), self::KEY);

        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INTAKE_KEY_ID' => 'website-test', 'HTTP_X_INTAKE_TIMESTAMP' => $timestamp, 'HTTP_X_INTAKE_SIGNATURE' => $signature], $body);
    }

    public function test_exact_shared_raw_utf8_vector_accepts_then_duplicates_without_crm_writes(): void
    {
        $this->assertSame(238, strlen(self::BODY));
        $this->assertSame('25eaf7eb039d8e22a7c23281809d5363f5e46c44a5a6349ca49abdb36e9f6ff5', hash('sha256', self::BODY));
        $response = $this->sendBody(signature: 'e0414a4e95c2f19c6414769eaa0c5939f86fb96d5aaec56c5382aa1e66c94a79');
        $response->assertCreated()->assertJsonPath('status', 'accepted')->assertJsonPath('submission_id', json_decode(self::BODY, true)['submission_id']);
        $this->assertTrue(\Illuminate\Support\Str::isUuid($response->json('receipt')));
        $this->sendBody()->assertOk()->assertJsonPath('status', 'duplicate')->assertJsonPath('receipt', $response->json('receipt'));
        $this->assertDatabaseCount('contact_submissions', 1);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertSame('managed-it', ContactSubmission::firstOrFail()->payload['inquiry']);
    }

    public function test_conflict_alert_is_durable_and_does_not_replace_payload(): void
    {
        $this->sendBody()->assertCreated();
        $this->sendBody(str_replace('managed-it', 'unknown-new-slug', self::BODY))->assertStatus(409)->assertExactJson(['status' => 'conflict']);
        $this->assertSame('managed-it', ContactSubmission::firstOrFail()->payload['inquiry']);
        $this->assertDatabaseHas('contact_intake_notifications', ['event' => 'conflict', 'sent_at' => null]);
    }

    public function test_flag_off_auth_skew_query_and_revocation_write_nothing(): void
    {
        Setting::setValue('contact_intake_enabled', '0');
        $this->sendBody()->assertNotFound();
        Setting::setValue('contact_intake_enabled', '1');
        $this->sendBody(signature: str_repeat('0', 64))->assertUnauthorized()->assertExactJson(['status' => 'unauthorized']);
        $this->sendBody(timestamp: '1790351699')->assertUnauthorized();
        $this->sendBody(path: SubmissionAuthenticator::PATH.'?unexpected=1')->assertUnauthorized();
        Setting::setEncrypted('contact_intake_keys', json_encode(['website-test' => ['secret' => self::KEY, 'revoked' => true]]));
        $this->sendBody()->assertUnauthorized()->assertExactJson(['status' => 'unauthorized']);
        $this->assertDatabaseCount('contact_submissions', 0);
    }

    public function test_invalid_and_oversize_bodies_never_echo_values(): void
    {
        $this->sendBody(str_repeat('x', 32769))->assertStatus(413);
        $this->sendBody('{')->assertStatus(422)->assertExactJson(['status' => 'invalid', 'fields' => ['envelope']]);
        $input = json_decode(self::BODY, true);
        $input['email'] = 'SYNTHETIC_INVALID_VALUE';
        $this->sendBody(json_encode($input))->assertStatus(422)->assertExactJson(['status' => 'invalid', 'fields' => ['email']]);
        unset($input['phone']);
        $this->sendBody(json_encode($input))->assertStatus(422);
        $this->assertDatabaseCount('contact_submissions', 0);
    }

    public function test_rate_limit_and_unavailable_have_retry_after(): void
    {
        for ($i = 0; $i < 60; $i++) {
            RateLimiter::hit('contact-intake:'.hash('sha256', 'website-test'), 60);
        }
        $this->sendBody()->assertStatus(429)->assertHeader('Retry-After');
        RateLimiter::clear('contact-intake:'.hash('sha256', 'website-test'));
        Setting::setEncrypted('contact_intake_keys', '{invalid');
        $this->sendBody()->assertStatus(503)->assertHeader('Retry-After', '60');
        $this->assertDatabaseCount('contact_submissions', 0);
    }
}
