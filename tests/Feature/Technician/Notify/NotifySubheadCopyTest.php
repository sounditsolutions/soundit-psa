<?php

namespace Tests\Feature\Technician\Notify;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-uabb: the AI Technician "Notify" settings subhead must read as a plain,
 * operator-facing label. It previously shipped as "Notify (Plan 1C)" — internal
 * dev-roadmap nomenclature that means nothing to an MSP operator and is
 * inconsistent with every sibling settings subhead (none carry a phase/plan tag).
 * Guard the copy so the phase parenthetical cannot creep back in.
 */
class NotifySubheadCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_notify_subhead_renders_a_plain_label_without_roadmap_jargon(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('settings.integrations'))->assertOk();

        // The Notify section still renders — its Teams webhook field anchors it,
        // so this test cannot pass trivially by the whole section disappearing.
        $response->assertSee('technician_teams_webhook_url', false);

        // …under a plain "Notify" subhead, not the internal phase tag.
        $response->assertSee('<h6 class="text-muted text-uppercase small mb-2">Notify</h6>', false);
        $response->assertDontSee('Plan 1C');
    }
}
