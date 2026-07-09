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
            ->assertSee('wiki_auto_mine', false) // form field name present in HTML
            // The live-sync script is wired to the dependent wrapper (keeps the gate
            // reactive as the master switch is toggled without a reload).
            ->assertSee("getElementById('wiki-dependent-fields')", false);
    }

    public function test_dependent_controls_render_inactive_when_module_off(): void
    {
        // Bug wiki-gate: master off, yet a stale mining flag is still stored on.
        Setting::setValue('wiki_enabled', '0');
        Setting::setValue('wiki_auto_mine', '1');

        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/settings/general')
            ->assertOk()
            ->getContent();

        // The dependent controls live inside their own gated wrapper.
        $this->assertStringContainsString('id="wiki-dependent-fields"', $html);

        // The mining toggle must read as inactive: disabled and NOT checked, even though
        // the raw wiki_auto_mine setting is still '1'. This is the reported contradiction.
        $mining = $this->extractInput($html, 'wiki_auto_mine');
        $this->assertStringContainsString('disabled', $mining);
        $this->assertStringNotContainsString('checked', $mining);

        // Budget inputs are gated inactive too, so the whole card reads as "module off".
        $this->assertStringContainsString('disabled', $this->extractInput($html, 'wiki_daily_token_limit'));
        $this->assertStringContainsString('disabled', $this->extractInput($html, 'wiki_maintenance_enabled'));
    }

    public function test_dependent_controls_render_active_when_module_on(): void
    {
        Setting::setValue('wiki_enabled', '1');
        Setting::setValue('wiki_auto_mine', '1');

        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/settings/general')
            ->assertOk()
            ->getContent();

        $mining = $this->extractInput($html, 'wiki_auto_mine');
        $this->assertStringContainsString('checked', $mining);
        $this->assertStringNotContainsString('disabled', $mining);

        $this->assertStringNotContainsString('disabled', $this->extractInput($html, 'wiki_daily_token_limit'));
    }

    public function test_saving_with_module_off_preserves_budgets_and_clears_mining(): void
    {
        // Operator had the module fully configured with non-default budgets...
        Setting::setValue('wiki_enabled', '1');
        Setting::setValue('wiki_auto_mine', '1');
        Setting::setValue('wiki_max_tokens_per_run', '120000');
        Setting::setValue('wiki_daily_token_limit', '2000000');
        Setting::setValue('wiki_staleness_days_volatile', '45');
        Setting::setValue('wiki_backfill_batch_size', '100');

        $user = User::factory()->create();

        // ...then disables the module. The disabled dependent inputs are not submitted,
        // mirroring what the browser posts once the master switch is off.
        $this->actingAs($user)->post('/settings/general/wiki', [
            'wiki_enabled' => '0',
        ])->assertRedirect();

        // Module + mining are off, so re-enabling later won't silently resume AI spend.
        $this->assertFalse(WikiConfig::isEnabled());
        $this->assertSame('0', Setting::getValue('wiki_auto_mine'));
        $this->assertFalse(WikiConfig::autoMineEnabled());

        // Budgets are preserved, not reset to the hard-coded defaults.
        $this->assertSame(120_000, WikiConfig::maxTokensPerRun());
        $this->assertSame(2_000_000, WikiConfig::dailyTokenLimit());
        $this->assertSame(45, WikiConfig::stalenessDaysVolatile());
        $this->assertSame(100, WikiConfig::backfillBatchSize());
    }

    /** Isolate a single <input> tag by id from rendered HTML so attribute assertions can't leak across fields. */
    private function extractInput(string $html, string $id): string
    {
        preg_match('/<input\b[^>]*\bid="'.preg_quote($id, '/').'"[^>]*>/', $html, $m);
        $this->assertNotEmpty($m, "Expected an <input> with id=\"{$id}\" in the rendered page.");

        return $m[0];
    }
}
