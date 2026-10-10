<?php

namespace Tests\Feature\ControlD;

use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use App\Models\Setting;
use App\Models\User;
use App\Support\ControlDConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #6522 remainder (P7A74iGD (a)): the client page's Control D card shows the most recent
 * rejected intent's reason_detail, escaped, labelled as Control D's message, only when
 * non-null. No vendor call is involved; Http::preventStrayRequests() guards that.
 */
class ControlDReasonDetailDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setValue('controld_enabled', '1');
        Setting::setEncrypted('controld_api_key', 'synthetic-key');
        foreach (['tactical_client_field_id' => '18', 'default_profile_id' => 'testprofile01', 'code_expiry_days' => '7',
            'code_device_limit_headroom' => '2', 'code_analytics_level' => '0', 'code_intercept_mode' => 'standard'] as $key => $value) {
            Setting::setValue('controld_'.$key, $value);
        }
        Setting::setValue(ControlDConfig::ONBOARDING_ENABLED_SETTING, '1');
    }

    private function rejected(Client $client, ?string $detail, string $at, string $operation = 'organization'): void
    {
        $intent = new ControlDOnboardingIntent;
        $intent->forceFill(['id' => (string) Str::uuid(), 'client_id' => $client->id, 'actor_id' => User::factory()->admin()->create()->id,
            'active_client_id' => null, 'operation' => $operation, 'state' => 'rejected', 'phase' => 'post', 'payload' => [],
            'reason_code' => 40001, 'reason' => 'vendor rejected the write', 'reason_detail' => $detail,
            'created_at' => $at, 'updated_at' => $at])->saveOrFail();
    }

    private function page(Client $client): string
    {
        return $this->actingAs(User::factory()->admin()->create(['is_active' => true]))->get(route('clients.show', $client))->assertOk()->getContent();
    }

    public function test_the_latest_rejected_message_is_shown_labelled_and_escaped(): void
    {
        $client = Client::factory()->create();
        $this->rejected($client, 'Older message', '2026-10-01 10:00:00');
        $this->rejected($client, '<script>alert(1)</script><b>Sub-organization limit reached</b>', '2026-10-02 10:00:00');

        $html = $this->page($client);

        $this->assertStringContainsString('data-testid="controld-reason-detail"', $html);
        $this->assertStringContainsString("Control D's message: <q>&lt;script&gt;alert(1)&lt;/script&gt;&lt;b&gt;Sub-organization limit reached&lt;/b&gt;</q>", str_replace('&#039;', "'", $html));
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'the vendor text renders inert');
        $this->assertStringNotContainsString('<b>Sub-organization', $html);
        $this->assertStringNotContainsString('Older message', $html, 'only the most recent rejected intent');
        $this->assertStringContainsString('The last rejected organization step (code 40001).', $html);
    }

    public function test_nothing_is_shown_when_the_latest_rejection_has_no_message(): void
    {
        $client = Client::factory()->create();
        $this->rejected($client, 'Older message', '2026-10-01 10:00:00');
        $this->rejected($client, null, '2026-10-02 10:00:00');

        $html = $this->page($client);

        $this->assertStringNotContainsString('data-testid="controld-reason-detail"', $html);
        $this->assertStringNotContainsString("Control D's message", str_replace('&#039;', "'", $html));
        $this->assertStringNotContainsString('Older message', $html);
    }

    public function test_nothing_is_shown_without_a_rejected_intent(): void
    {
        $html = $this->page(Client::factory()->create());

        $this->assertStringContainsString('id="controld-onboarding"', $html, 'the card renders');
        $this->assertStringNotContainsString('data-testid="controld-reason-detail"', $html);
    }
}
