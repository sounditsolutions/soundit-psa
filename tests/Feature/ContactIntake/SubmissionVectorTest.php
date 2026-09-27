<?php

namespace Tests\Feature\ContactIntake;

use App\Models\Setting;
use App\Services\ContactIntake\SubmissionAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubmissionVectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_v1_raw_utf8_vector(): void
    {
        $this->travelTo(\Carbon\Carbon::createFromTimestampUTC(1790352000));
        Setting::setValue('contact_intake_enabled', '1');
        // Published synthetic interoperability key, never a deployment credential.
        $key = 'test-only-contact-intake-key-0001-abcdef';
        Setting::setEncrypted('contact_intake_keys', json_encode(['website-test' => ['secret' => $key, 'revoked' => false]]));
        $body = '{"submission_id":"0f8e6a52-3c1d-4b7e-9a10-5d2c7e4b9f31","name":"Test Person","email":"test.person@example.com","message":"Synthetic test message é","phone":null,"company":null,"inquiry":"managed-it","submitted_at":"2026-09-25T16:00:00Z"}';
        $this->assertSame(238, strlen($body));
        $this->assertSame('25eaf7eb039d8e22a7c23281809d5363f5e46c44a5a6349ca49abdb36e9f6ff5', hash('sha256', $body));
        $signature = 'e0414a4e95c2f19c6414769eaa0c5939f86fb96d5aaec56c5382aa1e66c94a79';
        $auth = new SubmissionAuthenticator;
        $this->assertTrue($auth->accepts('POST', SubmissionAuthenticator::PATH, $body, 'website-test', '1790352000', $signature));
        $this->assertFalse($auth->accepts('POST', SubmissionAuthenticator::PATH, $body."\n", 'website-test', '1790352000', $signature));
    }
}
