<?php

namespace Tests\Unit\Support;

use App\Models\Setting;
use App\Support\HdbApiConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HdbApiConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_unset_blank_and_corrupt_keys_are_unconfigured(): void
    {
        $this->assertNull(HdbApiConfig::apiKey());
        $this->assertFalse(HdbApiConfig::isConfigured());
        Setting::setEncrypted('hdb_api_key', '   ');
        $this->assertNull(HdbApiConfig::apiKey());
        Setting::setValue('hdb_api_key', 'synthetic-invalid-ciphertext');
        $this->assertNull(HdbApiConfig::apiKey());
        $this->assertFalse(HdbApiConfig::isConfigured());
    }

    public function test_key_is_trimmed_and_base_url_is_fixed(): void
    {
        Setting::setEncrypted('hdb_api_key', ' synthetic-api-key ');
        $this->assertSame('synthetic-api-key', HdbApiConfig::apiKey());
        $this->assertTrue(HdbApiConfig::isConfigured());
        $this->assertSame('https://cyl.helpdeskbuttons.com/api/v1', HdbApiConfig::BASE_URL);
    }
}
