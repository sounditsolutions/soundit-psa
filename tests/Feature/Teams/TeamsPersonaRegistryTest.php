<?php

namespace Tests\Feature\Teams;

use App\Models\McpToken;
use App\Models\Setting;
use App\Models\TeamsPersona;
use App\Support\TeamsBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * teams_personas registry (Teams AI-Staff Personas P1) — the single source of
 * persona identity for the multi-bot Teams feature (Gus first). Everything is
 * DORMANT until a persona has creds + enabled=true: with zero enabled
 * personas, TeamsBotConfig::appIds()/forAppId() must behave exactly like the
 * legacy single-bot implementation (persona_key null marks the legacy bot),
 * so the existing JWT-audience SET check keeps working with zero change.
 */
class TeamsPersonaRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function personaAttributes(array $overrides = []): array
    {
        return array_merge([
            'persona_key' => 'gus',
            'display_name' => 'Gus',
            'bot_app_id' => 'persona-app',
            'enabled' => true,
        ], $overrides);
    }

    private function makePersona(array $overrides = []): TeamsPersona
    {
        return TeamsPersona::create($this->personaAttributes($overrides));
    }

    public function test_app_ids_union_legacy_and_enabled_personas(): void
    {
        Setting::setValue('teams_bot_app_id', 'legacy-app');

        $this->makePersona(['persona_key' => 'gus', 'bot_app_id' => 'persona-app', 'enabled' => true]);
        $this->makePersona(['persona_key' => 'disabled', 'bot_app_id' => 'disabled-app', 'enabled' => false]);

        $this->assertSame(['legacy-app', 'persona-app'], TeamsBotConfig::appIds());
    }

    public function test_for_app_id_returns_persona_row_for_persona_app(): void
    {
        $persona = $this->makePersona([
            'persona_key' => 'gus',
            'bot_app_id' => 'persona-app',
            'tenant_id' => 'tenant-123',
            'enabled' => true,
        ]);

        $this->assertSame(
            ['app_id' => 'persona-app', 'tenant_id' => 'tenant-123', 'persona_key' => 'gus'],
            TeamsBotConfig::forAppId('persona-app'),
        );

        // Sanity: the persona really did persist tenant_id as given.
        $this->assertSame('tenant-123', $persona->fresh()->tenant_id);
    }

    public function test_for_app_id_returns_legacy_marker_for_legacy_app(): void
    {
        Setting::setValue('teams_bot_app_id', 'legacy-app');
        Setting::setValue('teams_bot_tenant_id', 'legacy-tenant');

        $this->assertSame(
            ['app_id' => 'legacy-app', 'tenant_id' => 'legacy-tenant', 'persona_key' => null],
            TeamsBotConfig::forAppId('legacy-app'),
        );
    }

    public function test_for_app_id_null_for_unknown(): void
    {
        Setting::setValue('teams_bot_app_id', 'legacy-app');
        $this->makePersona(['persona_key' => 'gus', 'bot_app_id' => 'persona-app', 'enabled' => true]);

        $this->assertNull(TeamsBotConfig::forAppId('nope'));
    }

    public function test_secret_non_exposure(): void
    {
        $persona = $this->makePersona([
            'persona_key' => 'gus',
            'bot_app_id' => 'secret-app',
            'bot_client_secret' => 's3cr3t',
        ]);

        // Encrypted at rest: the raw stored column is NOT the plaintext.
        // (Deliberately not whereKey() — that's an Eloquent Builder method; on a
        // plain DB::table() query builder it silently resolves to where('key', ...)
        // via the dynamic-where magic method instead of the primary key.)
        $raw = DB::table('teams_personas')->where('id', $persona->id)->value('bot_client_secret');
        $this->assertNotSame('s3cr3t', $raw);
        $this->assertNotEmpty($raw);

        // Reads back decrypted via the model cast.
        $this->assertSame('s3cr3t', $persona->fresh()->bot_client_secret);
        $this->assertTrue($persona->fresh()->hasSecret());

        $noSecret = $this->makePersona([
            'persona_key' => 'no-secret',
            'bot_app_id' => 'no-secret-app',
            'bot_client_secret' => null,
        ]);
        $this->assertFalse($noSecret->fresh()->hasSecret());
    }

    public function test_secret_is_hidden_from_array_and_json_serialization(): void
    {
        $persona = $this->makePersona([
            'persona_key' => 'gus',
            'bot_app_id' => 'serialize-app',
            'bot_client_secret' => 'LEAKME-PLAINTEXT',
        ]);

        // The `encrypted` cast DECRYPTS on toArray()/toJson(), so without a
        // $hidden guard the plaintext secret leaks through model serialization.
        // No P1 path serializes a persona, but the P2 CRUD/wizard is exactly
        // where response()->json($persona) / @json / ->toArray() tends to
        // appear — defense-in-depth for the cardinal "no reveal, ever" rule.
        $this->assertArrayNotHasKey('bot_client_secret', $persona->toArray());
        $this->assertStringNotContainsString('LEAKME-PLAINTEXT', $persona->toJson());
        $this->assertStringNotContainsString('LEAKME-PLAINTEXT', (string) json_encode($persona));

        // The guard must not disturb the internal presence check (raw column,
        // no decrypt) or the serialization of SAFE fields.
        $this->assertTrue($persona->hasSecret());
        $this->assertArrayHasKey('display_name', $persona->toArray());
    }

    public function test_label_existence_validation(): void
    {
        McpToken::create(['label' => 'gus-mcp', 'token_hash' => hash('sha256', 'gus-mcp-token')]);

        // An mcp_token_label that matches an existing McpToken.label succeeds.
        $persona = $this->makePersona([
            'persona_key' => 'gus',
            'bot_app_id' => 'labeled-app',
            'mcp_token_label' => 'gus-mcp',
        ]);
        $this->assertSame('gus-mcp', $persona->fresh()->mcp_token_label);

        // An mcp_token_label with no matching McpToken fails closed.
        $this->expectException(InvalidArgumentException::class);

        $this->makePersona([
            'persona_key' => 'bad-label',
            'bot_app_id' => 'bad-label-app',
            'mcp_token_label' => 'does-not-exist',
        ]);
    }
}
