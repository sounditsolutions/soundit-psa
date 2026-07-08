<?php

namespace Tests\Feature\Wiki;

use App\Models\Setting;
use App\Support\WikiConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_wiki_is_disabled_by_default(): void
    {
        $this->assertFalse(WikiConfig::isEnabled());
    }

    public function test_wiki_enabled_via_setting(): void
    {
        Setting::setValue('wiki_enabled', '1');

        $this->assertTrue(WikiConfig::isEnabled());
    }
}
