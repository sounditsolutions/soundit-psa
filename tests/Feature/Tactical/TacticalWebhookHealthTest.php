<?php

namespace Tests\Feature\Tactical;

use App\Models\TacticalWebhook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TacticalWebhookHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_tactical_settings_shows_webhook_health(): void
    {
        TacticalWebhook::factory()->create([
            'status' => 'processed',
            'processed_at' => now()->subMinutes(5),
        ]);
        TacticalWebhook::factory()->create(['status' => 'failed']);

        $this->actingAs(User::factory()->create())
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Last alert received')
            ->assertSee('1 failed');
    }

    public function test_tactical_settings_health_handles_no_webhooks(): void
    {
        // No webhook rows yet — the card should still render without errors,
        // show "never" for last-received, and NOT show the failed-count warning.
        // (Note: the bare word "failed" appears in unrelated cards, so assert the
        // absence of our specific failed-count badge text instead.)
        $this->actingAs(User::factory()->create())
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('Last alert received')
            ->assertSee('never')
            ->assertSee('0 processed (24h)')
            ->assertDontSee('1 failed');
    }
}
