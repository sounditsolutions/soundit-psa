<?php

namespace Tests\Feature\Technician;

use App\Models\McpToken;
use App\Models\Setting;
use App\Models\TeamsPersona;
use App\Models\User;
use App\Services\Technician\TechnicianDisclosure;
use App\Support\TeamsPersonaConfig;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-u51h — the client-facing AI-email tagline.
 *
 * Part 1: the AI name comes from the acting TOKEN's persona, not the global
 * TechnicianConfig::aiActorName() (an instance of the so-bp4f white-label defect).
 * Part 2: an AI-STAGED -> HUMAN-APPROVED send credits both the AI drafter and the
 * approving technician; an AI auto-send credits the AI alone.
 *
 * The load-bearing invariant across every variant: DISCLOSURE_SENTINEL survives
 * verbatim, so the name-independent pre-send scan (assertPresent) still passes.
 */
class TechnicianDualCreditDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private function persona(string $tokenLabel, string $displayName): TeamsPersona
    {
        TeamsPersonaConfig::flush();

        // TeamsPersona::saving() enforces that mcp_token_label references a real
        // McpToken.label — the persona is a facet of an actual token, not free text.
        McpToken::create([
            'label' => $tokenLabel,
            'token_hash' => hash('sha256', $tokenLabel),
            'tools' => ['send_email'],
        ]);

        return TeamsPersona::create([
            'persona_key' => strtolower($tokenLabel),
            'display_name' => $displayName,
            'mcp_token_label' => $tokenLabel,
            'enabled' => true,
            // TeamsPersonaConfig::active() = enabled + credential-complete
            // (bot_app_id + tenant_id + hasSecret()); byTokenLabel() is active()-scoped.
            'bot_app_id' => '11111111-1111-1111-1111-111111111111',
            'tenant_id' => '22222222-2222-2222-2222-222222222222',
            'bot_client_secret' => 'secret',
        ]);
    }

    // ---- Part 2: the dual-credit body -------------------------------------

    /**
     * The exact client-facing banner. Charlie's example was "Drafted by <AI>, an AI
     * member of our team, reviewed and sent by <Tech>"; the manager's ruling was to
     * keep DISCLOSURE_SENTINEL (which terminates in a period) and take the "or
     * similar" latitude on phrasing — hence the sentence break before "Reviewed".
     */
    public function test_dual_credit_names_the_ai_drafter_and_the_approving_technician(): void
    {
        $out = (new TechnicianDisclosure)->withDualDisclosure('Your mailbox is migrated.', 'Robin', 'Jane Smith');

        $this->assertStringContainsString(
            '— Drafted by Robin, an AI assistant for our team. Reviewed and sent by Jane Smith.',
            $out,
        );
    }

    public function test_dual_credit_preserves_the_name_independent_sentinel_so_the_pre_send_scan_passes(): void
    {
        $disclosure = new TechnicianDisclosure;
        $out = $disclosure->withDualDisclosure('Body.', 'Robin', 'Jane Smith');

        // The exact sentinel substring — the thing assertPresent() keys on.
        $this->assertStringContainsString(TechnicianDisclosure::DISCLOSURE_SENTINEL, $out);
        $disclosure->assertPresent($out);
        $this->assertTrue(true);
    }

    public function test_dual_credit_falls_back_to_ai_only_when_the_approver_is_not_a_named_human(): void
    {
        $disclosure = new TechnicianDisclosure;

        // Manager ruling (psa-u51h Q3): no named/human approver => AI-only credit,
        // byte-identical to the auto-sent tagline.
        $out = $disclosure->withDualDisclosure('Body.', 'Robin', '   ');

        $this->assertSame($disclosure->withDisclosure('Body.', 'Robin'), $out);
        $this->assertStringNotContainsString('Reviewed and sent by', $out);
    }

    public function test_blank_ai_name_still_uses_the_safe_default_in_dual_credit(): void
    {
        $out = (new TechnicianDisclosure)->withDualDisclosure('Body.', '  ', 'Jane Smith');

        $this->assertStringContainsString(
            '— Drafted by our virtual assistant, an AI assistant for our team. Reviewed and sent by Jane Smith.',
            $out,
        );
    }

    // ---- Part 1: the per-token persona seam --------------------------------

    public function test_token_label_resolves_the_personas_display_name(): void
    {
        User::factory()->create(['name' => 'Global Chet']);
        $this->persona('robin-token', 'Robin');

        $this->assertSame('Robin', TechnicianConfig::actorNameForTokenLabel('robin-token'));
    }

    public function test_unknown_or_absent_token_label_falls_back_to_the_global_actor_name(): void
    {
        $chet = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $chet->id);

        // Byte-identical to today's behaviour when no persona resolves.
        $this->assertSame('Chet', TechnicianConfig::actorNameForTokenLabel('no-such-token'));
        $this->assertSame('Chet', TechnicianConfig::actorNameForTokenLabel(null));
        $this->assertSame('Chet', TechnicianConfig::actorNameForTokenLabel(''));
    }

    public function test_a_disabled_persona_does_not_resolve_and_falls_back(): void
    {
        $chet = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $chet->id);

        $persona = $this->persona('ghost-token', 'Ghost');
        $persona->update(['enabled' => false]);
        TeamsPersonaConfig::flush();

        $this->assertSame('Chet', TechnicianConfig::actorNameForTokenLabel('ghost-token'));
    }

    /**
     * The trap this feature is built to avoid: McpStaffToken::actorLabel() returns the
     * PREFIXED audit label ('mcp-staff:robin-token'), which is NOT what byTokenLabel()
     * matches on. Wiring the prefixed label into persona resolution would silently
     * resolve nothing and fall back to the global name for ever — a no-op that looks
     * like it works. Pin the distinction so nobody re-introduces it.
     */
    public function test_the_prefixed_mcp_actor_label_is_not_a_persona_token_label(): void
    {
        User::factory()->create(['name' => 'Global Chet']);
        $this->persona('robin-token', 'Robin');

        $this->assertSame('Robin', TechnicianConfig::actorNameForTokenLabel('robin-token'));
        $this->assertSame('Global Chet', TechnicianConfig::actorNameForTokenLabel('mcp-staff:robin-token'));
    }
}
