<?php

namespace Tests\Feature\Wiki;

use App\Models\Setting;
use App\Models\User;
use App\Support\WikiConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_defaults_match_spec(): void
    {
        $this->assertFalse(WikiConfig::isEnabled());
        $this->assertFalse(WikiConfig::autoMineEnabled());
        $this->assertSame(50_000, WikiConfig::maxTokensPerRun());
        $this->assertSame(500_000, WikiConfig::dailyTokenLimit());
    }

    public function test_auto_mine_requires_master_switch(): void
    {
        Setting::setValue('wiki_auto_mine', '1'); // master off

        $this->assertFalse(WikiConfig::autoMineEnabled());

        Setting::setValue('wiki_enabled', '1');
        $this->assertTrue(WikiConfig::autoMineEnabled());
    }

    public function test_settings_form_updates_wiki_keys(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/settings/general/wiki', [
            'wiki_enabled' => '1',
            'wiki_auto_mine' => '1',
            'wiki_max_tokens_per_run' => 60000,
            'wiki_daily_token_limit' => 400000,
        ])->assertRedirect();

        $this->assertTrue(WikiConfig::isEnabled());
        $this->assertTrue(WikiConfig::autoMineEnabled());
        $this->assertSame(60_000, WikiConfig::maxTokensPerRun());
        $this->assertSame(400_000, WikiConfig::dailyTokenLimit());
    }

    public function test_settings_page_shows_wiki_card(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/general')
            ->assertOk()
            ->assertSee('Client Wiki')
            ->assertSee('wiki_auto_mine', false); // form field name present in HTML
    }
}
