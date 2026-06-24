<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\Setting;
use App\Services\Technician\Notify\TeamsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamsNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_webhook_configured_is_a_noop_false(): void
    {
        Http::fake();
        $this->assertFalse(app(TeamsNotifier::class)->post('Subject', 'Body'));
        Http::assertNothingSent();
    }

    public function test_posts_a_card_to_the_configured_webhook(): void
    {
        Setting::setValue('technician_teams_webhook_url', 'https://example.webhook.office.com/hook');
        Http::fake(['*' => Http::response('', 200)]);

        $this->assertTrue(app(TeamsNotifier::class)->post('Daily digest', 'You have 3 drafts.'));

        Http::assertSent(fn ($req) => $req->url() === 'https://example.webhook.office.com/hook'
            && str_contains(json_encode($req->data()), 'You have 3 drafts.'));
    }

    public function test_a_non_2xx_or_throw_returns_false_and_does_not_crash(): void
    {
        Setting::setValue('technician_teams_webhook_url', 'https://example.webhook.office.com/hook');
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->assertFalse(app(TeamsNotifier::class)->post('S', 'B'));
    }

    public function test_http_throw_is_caught_and_returns_false(): void
    {
        Setting::setValue('technician_teams_webhook_url', 'https://example.webhook.office.com/hook');
        Http::fake(['*' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('down');
        }]);

        $this->assertFalse(app(TeamsNotifier::class)->post('S', 'B'));
    }
}
