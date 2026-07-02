<?php

namespace Tests\Feature\Mcp;

use App\Models\McpToken;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class McpTokenModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_casts_tools_to_array_and_dates_to_carbon(): void
    {
        $token = McpToken::create([
            'label' => 'chet',
            'token_hash' => hash('sha256', 'psa-mcp-secret'),
            'token_prefix' => 'psa-mcp-abcd...',
            'tools' => ['find_staff', 'get_staff'],
            'last_used_at' => now(),
        ]);

        $fresh = $token->fresh();

        $this->assertSame(['find_staff', 'get_staff'], $fresh->tools);
        $this->assertInstanceOf(Carbon::class, $fresh->last_used_at);
        $this->assertNull($fresh->revoked_at);
        $this->assertFalse($fresh->isRevoked());
    }

    public function test_active_scope_excludes_revoked_rows(): void
    {
        McpToken::create(['label' => 'live', 'token_hash' => 'h1', 'tools' => ['a']]);
        McpToken::create(['label' => 'dead', 'token_hash' => 'h2', 'tools' => ['a'], 'revoked_at' => now()]);

        $this->assertSame(['live'], McpToken::query()->active()->pluck('label')->all());
    }

    public function test_import_legacy_blob_folds_encrypted_setting_records(): void
    {
        Setting::setEncrypted('mcp_staff_scoped_tokens', json_encode([
            [
                'label' => 'chet',
                'hash' => 'hash-chet',
                'tools' => ['find_staff', 'get_staff'],
                'created_at' => '2026-06-30T10:00:00+00:00',
            ],
            [
                'label' => 'office-teams-pack',
                'hash' => 'hash-pack',
                'tools' => ['poll_operator_messages'],
                'created_at' => '2026-06-30T11:00:00+00:00',
            ],
        ]));

        $count = McpToken::importLegacyBlob();

        $this->assertSame(2, $count);
        $chet = McpToken::where('label', 'chet')->firstOrFail();
        $this->assertSame('hash-chet', $chet->token_hash);
        $this->assertSame(['find_staff', 'get_staff'], $chet->tools);
        $this->assertNull($chet->token_prefix);
        $this->assertTrue($chet->created_at->equalTo(Carbon::parse('2026-06-30T10:00:00+00:00')));
    }

    public function test_import_legacy_blob_is_idempotent(): void
    {
        Setting::setEncrypted('mcp_staff_scoped_tokens', json_encode([
            ['label' => 'chet', 'hash' => 'hash-chet', 'tools' => ['find_staff']],
        ]));

        McpToken::importLegacyBlob();
        McpToken::importLegacyBlob();

        $this->assertSame(1, McpToken::where('label', 'chet')->count());
    }

    public function test_import_legacy_blob_no_setting_is_noop(): void
    {
        $this->assertSame(0, McpToken::importLegacyBlob());
        $this->assertSame(0, McpToken::count());
    }
}
