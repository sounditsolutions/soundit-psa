<?php

namespace Tests\Feature\Teams;

use App\Models\McpToken;
use App\Models\Setting;
use App\Models\TeamsPersona;
use App\Support\TeamsBotConfig;
use App\Support\TeamsPersonaConfig;
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

    protected function setUp(): void
    {
        parent::setUp();

        // TeamsPersonaConfig::enabled() memoizes in a bare PHP static, which
        // (unlike the DB) RefreshDatabase does NOT reset between test methods
        // running in the same process. Without this, a prior test that reads
        // the registry after its own writes can leave a warm memo that leaks
        // into the next test's "before I create anything" assertions.
        TeamsPersonaConfig::flush();
    }

    /** @return array<string, mixed> */
    private function personaAttributes(array $overrides = []): array
    {
        return array_merge([
            'persona_key' => 'gus',
            'display_name' => 'Gus',
            'bot_app_id' => 'persona-app',
            'tenant_id' => 'default-tenant',
            'bot_client_secret' => 'default-secret',
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

    // ── P2 hardening (bd psa-7drx, Task 1): credential-complete audience gating ─

    /**
     * enabled() alone (item T1 pre-P2) let an operator flip enabled=true before
     * finishing the credential wizard, and that half-configured row would have
     * joined the JWT audience set. active() is the credential-complete subset —
     * bot_app_id, tenant_id, AND a stored secret all present — that byAppId(),
     * appIds(), and forAppId() must actually gate on.
     */
    public function test_active_excludes_enabled_but_credential_incomplete_personas(): void
    {
        // Enabled, but missing tenant_id.
        $this->makePersona([
            'persona_key' => 'no-tenant',
            'bot_app_id' => 'no-tenant-app',
            'bot_client_secret' => 'secret',
            'tenant_id' => null,
            'enabled' => true,
        ]);

        // Enabled, but missing bot_client_secret.
        $this->makePersona([
            'persona_key' => 'no-secret',
            'bot_app_id' => 'no-secret-app',
            'bot_client_secret' => null,
            'tenant_id' => 'tenant-1',
            'enabled' => true,
        ]);

        // Enabled, but bot_app_id blank. The column is NOT NULL + unique, so a
        // blank string is the only way to represent "unset" at the DB layer.
        $this->makePersona([
            'persona_key' => 'no-app-id',
            'bot_app_id' => '',
            'bot_client_secret' => 'secret',
            'tenant_id' => 'tenant-2',
            'enabled' => true,
        ]);

        // Enabled AND credential-complete: the only persona that should surface.
        $this->makePersona([
            'persona_key' => 'gus',
            'bot_app_id' => 'persona-app',
            'bot_client_secret' => 'secret',
            'tenant_id' => 'tenant-3',
            'enabled' => true,
        ]);

        $this->assertSame(['persona-app'], TeamsPersonaConfig::active()->pluck('bot_app_id')->all());
        $this->assertSame(['persona-app'], TeamsBotConfig::appIds());

        $this->assertNull(TeamsBotConfig::forAppId('no-tenant-app'));
        $this->assertNull(TeamsBotConfig::forAppId('no-secret-app'));
        $this->assertNotNull(TeamsBotConfig::forAppId('persona-app'));
    }

    /**
     * TeamsPersonaConfig::enabled() memoizes per-request (item 4). A persona
     * created (or deleted) mid-request must be visible on the very next
     * accessor call — the booted() saved/deleted hooks bust the memo, so no
     * caller anywhere in the same request can observe a stale snapshot.
     */
    public function test_enabled_memo_is_busted_on_persona_save(): void
    {
        // Warm the memo while the registry is empty.
        $this->assertCount(0, TeamsPersonaConfig::enabled());
        $this->assertSame([], TeamsBotConfig::appIds());

        $persona = $this->makePersona([
            'persona_key' => 'gus',
            'bot_app_id' => 'persona-app',
            'bot_client_secret' => 'secret',
            'tenant_id' => 'tenant-1',
            'enabled' => true,
        ]);

        // The static memo must not serve the pre-create empty snapshot.
        $this->assertCount(1, TeamsPersonaConfig::enabled());
        $this->assertSame(['persona-app'], TeamsBotConfig::appIds());

        // Deleting must also bust the memo — booted() wires both saved and deleted.
        $persona->delete();
        $this->assertCount(0, TeamsPersonaConfig::enabled());
        $this->assertSame([], TeamsBotConfig::appIds());
    }

    /**
     * Item 1 (RULED — reject, not warn): an enabled persona may never claim the
     * legacy single-bot's bot_app_id. Two distinct rows both routing on the same
     * App ID is an unresolvable JWT-audience collision, so this is a save-time
     * hard reject with a message pointing at the clean migration path (clear the
     * legacy setting first, then register the persona) — never a silent warning.
     */
    public function test_enabled_persona_cannot_claim_the_legacy_bot_app_id(): void
    {
        // With no legacy app_id configured yet, any bot_app_id is allowed.
        $preLegacy = $this->makePersona([
            'persona_key' => 'no-legacy-yet',
            'bot_app_id' => 'whatever-app',
            'enabled' => true,
        ]);
        $this->assertTrue($preLegacy->fresh()->enabled);

        Setting::setValue('teams_bot_app_id', 'legacy-app');

        // An enabled persona with a DISTINCT app_id saves fine alongside the legacy id.
        $distinct = $this->makePersona([
            'persona_key' => 'gus',
            'bot_app_id' => 'persona-app',
            'enabled' => true,
        ]);
        $this->assertTrue($distinct->fresh()->enabled);

        // A DISABLED persona may share the legacy app_id — only an ENABLED claim is rejected.
        $twin = $this->makePersona([
            'persona_key' => 'legacy-twin',
            'bot_app_id' => 'legacy-app',
            'enabled' => false,
        ]);
        $this->assertSame('legacy-app', $twin->fresh()->bot_app_id);

        // Enabling that SAME persona — still sharing the legacy bot_app_id — must be rejected.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Clear the legacy/');

        $twin->enabled = true;
        $twin->save();
    }

    /**
     * Item 7b: the mcp_token_label existence check must only fire when
     * mcp_token_label is the field actually being changed. Without the dirty
     * guard, an unrelated edit (e.g. toggling enabled) on a persona whose
     * previously-valid label now points at a deleted McpToken would throw for
     * no reason connected to the edit being made.
     */
    public function test_token_label_check_only_fires_when_label_is_dirty(): void
    {
        McpToken::create(['label' => 'gus-mcp', 'token_hash' => hash('sha256', 'gus-mcp-token')]);

        $persona = $this->makePersona([
            'persona_key' => 'gus',
            'bot_app_id' => 'labeled-app',
            'mcp_token_label' => 'gus-mcp',
            'enabled' => false,
        ]);

        McpToken::where('label', 'gus-mcp')->delete();

        // Unrelated change — mcp_token_label is untouched (not dirty) even though
        // its referenced McpToken is now gone — must not throw.
        $persona->enabled = true;
        $persona->save();
        $this->assertTrue($persona->fresh()->enabled);

        // Setting a NEW non-existent label DOES throw (the field is now dirty).
        $this->expectException(InvalidArgumentException::class);

        $persona->mcp_token_label = 'still-does-not-exist';
        $persona->save();
    }
}
