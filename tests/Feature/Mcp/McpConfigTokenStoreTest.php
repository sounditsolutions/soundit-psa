<?php

namespace Tests\Feature\Mcp;

use App\Models\McpToken;
use App\Models\Setting;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpConfigTokenStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_rotate_persists_to_table_hash_only_and_resolves(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff', 'get_staff'], label: 'chet');

        $this->assertStringStartsWith('psa-mcp-', $plain);

        $row = McpToken::where('label', 'chet')->firstOrFail();
        $this->assertSame(hash('sha256', $plain), $row->token_hash);
        $this->assertSame(['find_staff', 'get_staff'], $row->tools);
        $this->assertStringStartsWith('psa-mcp-', (string) $row->token_prefix);
        $this->assertStringNotContainsString($plain, json_encode($row->getAttributes()));

        $resolved = McpConfig::resolveStaffToken($plain);
        $this->assertNotNull($resolved);
        $this->assertSame('chet', $resolved->label);
        $this->assertSame($row->id, $resolved->id);
        $this->assertSame(McpToken::defaultDirective(), $resolved->directiveOrDefault());
        $this->assertTrue($resolved->allows('find_staff'));
        $this->assertFalse($resolved->allows('create_ticket'));
    }

    public function test_scoped_rotate_uses_safer_app_layer_trust_defaults_and_resolves_flags(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'opsbot');

        $row = McpToken::where('label', 'opsbot')->firstOrFail();
        $this->assertFalse($row->ai_actor);
        $this->assertTrue($row->require_explicit_client_scope);

        $resolved = McpConfig::resolveStaffToken($plain);
        $this->assertFalse($resolved->aiActor);
        $this->assertTrue($resolved->requireExplicitClientScope);
    }

    public function test_scoped_rotate_can_store_explicit_trust_flags(): void
    {
        $plain = McpConfig::rotateStaffToken(
            allowedTools: ['find_staff'],
            label: 'office-bot',
            aiActor: true,
            requireExplicitClientScope: false,
        );

        $row = McpToken::where('label', 'office-bot')->firstOrFail();
        $this->assertTrue($row->ai_actor);
        $this->assertFalse($row->require_explicit_client_scope);

        $resolved = McpConfig::resolveStaffToken($plain);
        $this->assertTrue($resolved->aiActor);
        $this->assertFalse($resolved->requireExplicitClientScope);
    }

    public function test_resolve_carries_token_directive(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        McpToken::where('label', 'chet')->update(['directive' => 'Use the Chet handoff rules.']);

        $resolved = McpConfig::resolveStaffToken($plain);

        $this->assertSame('Use the Chet handoff rules.', $resolved?->directiveOrDefault());
    }

    public function test_resolve_stamps_last_used_at(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $this->assertNull(McpToken::where('label', 'chet')->value('last_used_at'));

        McpConfig::resolveStaffToken($plain);

        $this->assertNotNull(McpToken::where('label', 'chet')->value('last_used_at'));
    }

    public function test_rotating_same_label_replaces_and_invalidates_old_secret(): void
    {
        $old = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $new = McpConfig::rotateStaffToken(allowedTools: ['get_staff'], label: 'chet');

        $this->assertNotSame($old, $new);
        $this->assertSame(1, McpToken::where('label', 'chet')->count());
        $this->assertNull(McpConfig::resolveStaffToken($old), 'old secret no longer authenticates');
        $this->assertNotNull(McpConfig::resolveStaffToken($new));
        $this->assertSame(['get_staff'], McpConfig::resolveStaffToken($new)->allowedTools);
    }

    public function test_rotating_revoked_label_uses_new_token_trust_defaults(): void
    {
        McpConfig::rotateStaffToken(
            allowedTools: ['find_staff'],
            label: 'office-bot',
            aiActor: false,
            requireExplicitClientScope: false,
        );
        McpToken::where('label', 'office-bot')->firstOrFail()
            ->forceFill(['revoked_at' => now()])
            ->save();

        $plain = McpConfig::rotateStaffToken(allowedTools: ['get_staff'], label: 'office-bot');

        $row = McpToken::where('label', 'office-bot')->firstOrFail();
        $this->assertNull($row->revoked_at);
        $this->assertFalse($row->ai_actor);
        $this->assertTrue($row->require_explicit_client_scope);
        $this->assertSame(['get_staff'], $row->tools);

        $resolved = McpConfig::resolveStaffToken($plain);
        $this->assertFalse($resolved->aiActor);
        $this->assertTrue($resolved->requireExplicitClientScope);
    }

    public function test_revoked_token_no_longer_resolves(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        McpToken::where('label', 'chet')->update(['revoked_at' => now()]);

        $this->assertNull(McpConfig::resolveStaffToken($plain));
    }

    public function test_legacy_full_surface_token_still_resolves_via_setting_fallback(): void
    {
        $plain = McpConfig::rotateStaffToken();

        $this->assertNotEmpty(Setting::getEncrypted('mcp_staff_token'));
        $resolved = McpConfig::resolveStaffToken($plain);
        $this->assertNotNull($resolved);
        $this->assertNull($resolved->allowedTools, 'legacy token = full surface');
        $this->assertSame('mcp-legacy', $resolved->actorLabel(), 'legacy actor label is platform-neutral');
        $this->assertFalse($resolved->aiActor);
        $this->assertFalse($resolved->requireExplicitClientScope);
    }

    public function test_legacy_actor_label_does_not_collide_with_scoped_legacy_label(): void
    {
        $legacy = McpConfig::rotateStaffToken();
        $scoped = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'legacy');

        $this->assertSame('mcp-legacy', McpConfig::resolveStaffToken($legacy)?->actorLabel());
        $this->assertSame('mcp-staff:legacy', McpConfig::resolveStaffToken($scoped)?->actorLabel());
    }

    public function test_is_staff_enabled_and_has_label_reflect_the_table(): void
    {
        $this->assertFalse(McpConfig::isStaffEnabled());

        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $this->assertTrue(McpConfig::isStaffEnabled());
        $this->assertTrue(McpConfig::hasScopedStaffTokenLabel('chet'));
        $this->assertFalse(McpConfig::hasScopedStaffTokenLabel('nope'));
    }
}
