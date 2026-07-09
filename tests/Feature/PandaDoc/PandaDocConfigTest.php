<?php

namespace Tests\Feature\PandaDoc;

use App\Models\Setting;
use App\Support\PandaDocConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PandaDocConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_configured_reflects_api_key_presence(): void
    {
        $this->assertFalse(PandaDocConfig::isConfigured());

        Setting::setEncrypted('pandadoc_api_key', 'pd-secret-key');

        $this->assertTrue(PandaDocConfig::isConfigured());
        $this->assertSame('pd-secret-key', PandaDocConfig::apiKey());
    }

    public function test_webhook_secret_is_read_encrypted(): void
    {
        $this->assertNull(PandaDocConfig::webhookSecret());

        Setting::setEncrypted('pandadoc_webhook_secret', 'shared-key');

        $this->assertSame('shared-key', PandaDocConfig::webhookSecret());
    }

    public function test_is_enabled_defaults_true_and_respects_toggle(): void
    {
        $this->assertTrue(PandaDocConfig::isEnabled());

        Setting::setValue('pandadoc_enabled', '0');
        $this->assertFalse(PandaDocConfig::isEnabled());

        Setting::setValue('pandadoc_enabled', '1');
        $this->assertTrue(PandaDocConfig::isEnabled());
    }

    public function test_base_url_is_the_public_api_host(): void
    {
        $this->assertSame('https://api.pandadoc.com', PandaDocConfig::baseUrl());
    }
}
