<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Services\Technician\Notify\SmsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsNotifierTest extends TestCase
{
    use RefreshDatabase;

    private function configurePlivo(): void
    {
        Setting::setValue('plivo_auth_id', 'MAXXXX');
        Setting::setEncrypted('plivo_auth_token', 'secret');
        Setting::setValue('plivo_did_number', '+15551230000');
    }

    public function test_noop_when_unconfigured(): void
    {
        Http::fake();
        $this->assertFalse(app(SmsNotifier::class)->send('+15557654321', 'hi'));
        Http::assertNothingSent();
    }

    public function test_posts_to_plivo_messages_api(): void
    {
        $this->configurePlivo();
        Http::fake(['*' => Http::response('{"message_uuid":["x"]}', 202)]);

        $this->assertTrue(app(SmsNotifier::class)->send('+15557654321', 'AI Tech: you are needed on #123'));

        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/Account/MAXXXX/Message/')
            && $req['dst'] === '+15557654321'
            && str_contains($req['text'], '#123'));
    }

    public function test_failure_returns_false_and_does_not_throw(): void
    {
        $this->configurePlivo();
        Http::fake(['*' => Http::response('nope', 401)]);
        $this->assertFalse(app(SmsNotifier::class)->send('+15557654321', 'x'));
    }

    public function test_http_exception_is_caught_and_returns_false(): void
    {
        $this->configurePlivo();
        Http::fake(['*' => function () {
            throw new \RuntimeException('boom');
        }]);

        $this->assertFalse(app(SmsNotifier::class)->send('+15557654321', 'x'));
    }
}
