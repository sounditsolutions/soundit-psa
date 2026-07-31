<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\TacticalAsset;
use App\Services\Assets\MislinkedAssetFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rule-level coverage for the READ-ONLY cross-client mislink sweep. Each Tier A
 * rule must fire on a CONSTRUCTED contradiction and stay silent on a clean fleet;
 * rule 3 is suppressed when the colliding serials differ (and deduped against
 * rule 2); Tier B stays in its own collection; scoping + include_inactive behave.
 */
class MislinkedAssetFinderTest extends TestCase
{
    use RefreshDatabase;

    private int $haloSeq = 1;

    private function finder(): MislinkedAssetFinder
    {
        return app(MislinkedAssetFinder::class);
    }

    private function asset(Client $client, array $overrides = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'client_id' => $client->id,
            'serial_number' => null,
            'ip_address' => null,
            'last_user' => null,
        ], $overrides));
    }

    private function person(Client $client, array $overrides = []): Person
    {
        return Person::create(array_merge([
            'halo_id' => $this->haloSeq++,
            'client_id' => $client->id,
            'first_name' => 'Test',
            'last_name' => 'User',
        ], $overrides));
    }

    private function rules(array $rows): array
    {
        return array_map(fn ($r) => $r['rule'], $rows);
    }

    // ── Rule 1: RMM (Tactical) contradiction ──

    public function test_rule1_fires_when_tactical_site_maps_to_a_different_client(): void
    {
        $acme = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'AcmeCo|HQ']);
        $bravo = Client::factory()->create(['name' => 'Bravo', 'tactical_site_id' => 'BravoCo|HQ']);

        // Agent snapshot says BravoCo|HQ (→ Bravo), but the asset row is filed under Acme.
        $ta = TacticalAsset::create([
            'agent_id' => 'agent-mislinked',
            'client_name' => 'BravoCo',
            'site_name' => 'HQ',
        ]);
        $asset = $this->asset($acme, ['tactical_asset_id' => $ta->id]);

        $result = $this->finder()->find(null);

        $this->assertSame(1, $result['tier_a_count']);
        $row = $result['tier_a'][0];
        $this->assertSame('rmm_client_contradiction', $row['rule']);
        $this->assertSame($asset->id, $row['asset_id']);
        $this->assertSame($acme->id, $row['client_id']);
        $this->assertSame($bravo->id, $row['other_client_id']);
        $this->assertSame('Bravo', $row['other_client_name']);
        $this->assertSame('agent-mislinked', $row['evidence']['tactical_agent_id']);
        $this->assertSame('BravoCo|HQ', $row['evidence']['tactical_site_key']);
    }

    public function test_rule1_does_not_fire_on_a_clean_fleet(): void
    {
        $bravo = Client::factory()->create(['name' => 'Bravo', 'tactical_site_id' => 'BravoCo|HQ']);
        $ta = TacticalAsset::create(['agent_id' => 'agent-ok', 'client_name' => 'BravoCo', 'site_name' => 'HQ']);
        $this->asset($bravo, ['tactical_asset_id' => $ta->id]);

        $result = $this->finder()->find(null);

        $this->assertSame(0, $result['tier_a_count']);
    }

    public function test_rule1_does_not_fire_when_the_tactical_site_maps_to_no_client(): void
    {
        // No client carries this site key → nothing to contradict (absence is not a hit).
        $acme = Client::factory()->create(['tactical_site_id' => 'AcmeCo|HQ']);
        $ta = TacticalAsset::create(['agent_id' => 'agent-unmapped', 'client_name' => 'GhostCo', 'site_name' => 'HQ']);
        $this->asset($acme, ['tactical_asset_id' => $ta->id]);

        $this->assertSame(0, $this->finder()->find(null)['tier_a_count']);
    }

    public function test_rule1_ignores_a_non_operational_client_holding_the_same_site_key(): void
    {
        // The live client owns the site key the Tactical sync maps agents to…
        $acme = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'AcmeCo|HQ']);
        // …and a churned duplicate row still carries it. The sync applies
        // ->operational(), so this row is never an authority. Created SECOND so a
        // last-wins pluck() would pick it if the finder omitted that scope.
        Client::factory()->create([
            'name' => 'Acme (old)',
            'tactical_site_id' => 'AcmeCo|HQ',
            'is_active' => false,
        ]);

        $ta = TacticalAsset::create(['agent_id' => 'agent-churn', 'client_name' => 'AcmeCo', 'site_name' => 'HQ']);
        $this->asset($acme, ['tactical_asset_id' => $ta->id]);

        $this->assertSame(
            0,
            $this->finder()->find(null)['tier_a_count'],
            'a non-operational duplicate site key must never become the rule-1 authority'
        );

        // Positive control on the SAME fleet: rule 1 still fires when the
        // OPERATIONAL owner of the agent's site key is a different client, so the
        // assertion above cannot pass merely because rule 1 is inert here.
        $bravo = Client::factory()->create(['name' => 'Bravo', 'tactical_site_id' => 'BravoCo|HQ']);
        $ta2 = TacticalAsset::create(['agent_id' => 'agent-live', 'client_name' => 'BravoCo', 'site_name' => 'HQ']);
        $this->asset($acme, ['tactical_asset_id' => $ta2->id]);

        $hits = array_values(array_filter(
            $this->finder()->find(null)['tier_a'],
            fn ($r) => $r['rule'] === 'rmm_client_contradiction'
        ));
        $this->assertCount(1, $hits);
        $this->assertSame($bravo->id, $hits[0]['other_client_id']);
    }

    // ── Rule 2: duplicate serial across clients ──

    public function test_rule2_fires_across_clients_and_not_within_one_client_or_on_junk(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create();

        $mislinked = $this->asset($a, ['serial_number' => 'SN-SHARED', 'hostname' => 'HOST-A']);
        $this->asset($b, ['serial_number' => 'SN-SHARED', 'hostname' => 'HOST-B']);

        // Same serial WITHIN one client is not a cross-client contradiction.
        $this->asset($a, ['serial_number' => 'SN-INTERNAL', 'hostname' => 'HOST-C']);
        $this->asset($a, ['serial_number' => 'SN-INTERNAL', 'hostname' => 'HOST-D']);

        // Junk placeholder serials collide honestly and must never be evidence.
        $this->asset($a, ['serial_number' => 'To Be Filled By O.E.M.', 'hostname' => 'HOST-E']);
        $this->asset($b, ['serial_number' => 'To Be Filled By O.E.M.', 'hostname' => 'HOST-F']);

        $result = $this->finder()->find(null);
        $serialHits = array_values(array_filter($result['tier_a'], fn ($r) => $r['rule'] === 'duplicate_serial_cross_client'));

        // Only the genuine cross-client pair — both sides — surfaces.
        $this->assertCount(2, $serialHits);
        $subject = collect($serialHits)->firstWhere('asset_id', $mislinked->id);
        $this->assertSame($b->id, $subject['other_client_id']);
        $this->assertSame('SN-SHARED', $subject['evidence']['duplicate_serial']);
    }

    // ── Rule 3: duplicate hostname across clients (dedupe vs rule 2, serial suppression) ──

    public function test_rule3_fires_when_serials_absent(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create();
        $this->asset($a, ['hostname' => 'DESKTOP-DUP', 'serial_number' => null]);
        $this->asset($b, ['hostname' => 'DESKTOP-DUP', 'serial_number' => null]);

        $hits = array_values(array_filter($this->finder()->find(null)['tier_a'], fn ($r) => $r['rule'] === 'duplicate_hostname_cross_client'));
        $this->assertCount(2, $hits);
        $this->assertSame('DESKTOP-DUP', $hits[0]['evidence']['duplicate_hostname']);
    }

    public function test_rule3_is_suppressed_when_colliding_serials_differ(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create();
        // Same generic hostname but DIFFERENT serials → genuinely different boxes.
        $this->asset($a, ['hostname' => 'DESKTOP-DUP', 'serial_number' => 'SERIAL-A']);
        $this->asset($b, ['hostname' => 'DESKTOP-DUP', 'serial_number' => 'SERIAL-B']);

        $rules = $this->rules($this->finder()->find(null)['tier_a']);
        $this->assertNotContains('duplicate_hostname_cross_client', $rules);
    }

    public function test_rule3_is_deduped_against_rule2_when_serials_match(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create();
        // Same hostname AND same serial → the same box: rule 2 owns it, rule 3 must not double-report.
        $this->asset($a, ['hostname' => 'DESKTOP-DUP', 'serial_number' => 'SAME-SERIAL']);
        $this->asset($b, ['hostname' => 'DESKTOP-DUP', 'serial_number' => 'SAME-SERIAL']);

        $rules = $this->rules($this->finder()->find(null)['tier_a']);
        $this->assertContains('duplicate_serial_cross_client', $rules);
        $this->assertNotContains('duplicate_hostname_cross_client', $rules);
    }

    // ── Tier B: separate collection, human-eyes rules ──

    public function test_rule4_last_user_foreign_contact_lands_in_tier_b_only(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create(['name' => 'Bravo']);
        $person = $this->person($b, ['cipp_upn' => 'jdoe@bravo.com']);
        $asset = $this->asset($a, ['last_user' => 'BRAVO\\jdoe', 'hostname' => 'HOST-LU']);

        $result = $this->finder()->find(null);

        $this->assertSame(0, $result['tier_a_count']);
        $this->assertSame(1, $result['tier_b_count']);
        $row = $result['tier_b'][0];
        $this->assertSame('last_user_foreign_contact', $row['rule']);
        $this->assertSame($asset->id, $row['asset_id']);
        $this->assertSame($b->id, $row['other_client_id']);
        $this->assertSame($person->id, $row['evidence']['matched_person_id']);
    }

    public function test_rule4_does_not_fire_when_user_also_exists_at_own_client(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create();
        $this->person($b, ['cipp_upn' => 'jdoe@shared.com']);
        $this->person($a, ['cipp_upn' => 'jdoe@shared.com']);
        $this->asset($a, ['last_user' => 'jdoe@shared.com']);

        $this->assertSame(0, $this->finder()->find(null)['tier_b_count']);
    }

    public function test_rule4_does_not_fire_when_only_the_local_part_matches_across_domains(): void
    {
        // Generic accounts (admin, info, scan, reception) exist at nearly every
        // client. Matching on the local part alone emitted one Tier B row per
        // foreign client holding the same account name — mass false hits on
        // ordinary valid data, which is what saturates the tier budget.
        $acme = Client::factory()->create(['name' => 'Acme']);
        foreach (['bravo', 'charlie', 'delta'] as $name) {
            $this->person(
                Client::factory()->create(['name' => $name]),
                ['cipp_upn' => 'admin@'.$name.'.com']
            );
        }
        $this->asset($acme, ['last_user' => 'ACMECO\\admin', 'hostname' => 'HOST-ADMIN']);

        $this->assertSame(
            0,
            $this->finder()->find(null)['tier_b_count'],
            'a shared local part on DIFFERENT domains is not a cross-client contradiction'
        );
    }

    public function test_rule4_fires_only_for_the_client_whose_domain_the_account_belongs_to(): void
    {
        $acme = Client::factory()->create(['name' => 'Acme']);
        $bravo = Client::factory()->create(['name' => 'Bravo']);
        $person = $this->person($bravo, ['cipp_upn' => 'admin@bravo.com']);
        // Same local part at a THIRD client, different domain — must stay silent.
        $this->person(Client::factory()->create(['name' => 'Delta']), ['cipp_upn' => 'admin@delta.com']);

        $this->asset($acme, ['last_user' => 'BRAVO\\admin', 'hostname' => 'HOST-ADMIN-2']);

        $hits = array_values(array_filter(
            $this->finder()->find(null)['tier_b'],
            fn ($r) => $r['rule'] === 'last_user_foreign_contact'
        ));

        $this->assertCount(1, $hits);
        $this->assertSame($bravo->id, $hits[0]['other_client_id']);
        $this->assertSame($person->id, $hits[0]['evidence']['matched_person_id']);
        // DOMAIN\user and user@domain.tld resolve to one key.
        $this->assertSame('admin@bravo', $hits[0]['evidence']['matched_account']);
    }

    public function test_rule4_does_not_fire_on_a_last_user_carrying_no_domain(): void
    {
        // A bare account name names no tenant, so it can contradict none.
        $a = Client::factory()->create();
        $b = Client::factory()->create();
        $this->person($b, ['cipp_upn' => 'jdoe@bravo.com']);
        $this->asset($a, ['last_user' => 'jdoe', 'hostname' => 'HOST-BARE']);

        $this->assertSame(0, $this->finder()->find(null)['tier_b_count']);
    }

    public function test_rule5_shared_public_ip_fires_but_private_ip_does_not(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create();

        $public = $this->asset($a, ['ip_address' => '203.0.113.7', 'hostname' => 'HOST-PUB-A']);
        $this->asset($b, ['ip_address' => '203.0.113.7', 'hostname' => 'HOST-PUB-B']);

        // Shared PRIVATE space is not evidence.
        $this->asset($a, ['ip_address' => '192.168.1.10', 'hostname' => 'HOST-PRIV-A']);
        $this->asset($b, ['ip_address' => '192.168.1.10', 'hostname' => 'HOST-PRIV-B']);

        $result = $this->finder()->find(null);
        $ipHits = array_values(array_filter($result['tier_b'], fn ($r) => $r['rule'] === 'shared_public_ip_cross_client'));

        $this->assertCount(2, $ipHits);
        $subject = collect($ipHits)->firstWhere('asset_id', $public->id);
        $this->assertSame('203.0.113.7', $subject['evidence']['shared_public_ip']);
    }

    public function test_rule6_foreign_learned_prefix_fires_and_generic_prefix_does_not(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create(['name' => 'Bravo']);

        // Bravo's learned dominant prefix: SMARTS- (3+ assets).
        foreach (['SMARTS-01', 'SMARTS-02', 'SMARTS-03'] as $h) {
            $this->asset($b, ['hostname' => $h]);
        }
        // An Acme asset wearing Bravo's fingerprint.
        $foreign = $this->asset($a, ['hostname' => 'SMARTS-99']);

        // DESKTOP- is dominant for BOTH clients → generic, owns no one.
        foreach (['DESKTOP-A1', 'DESKTOP-A2', 'DESKTOP-A3'] as $h) {
            $this->asset($a, ['hostname' => $h]);
        }
        foreach (['DESKTOP-B1', 'DESKTOP-B2', 'DESKTOP-B3'] as $h) {
            $this->asset($b, ['hostname' => $h]);
        }

        $result = $this->finder()->find(null);
        $prefixHits = array_values(array_filter($result['tier_b'], fn ($r) => $r['rule'] === 'foreign_client_hostname_prefix'));

        $this->assertCount(1, $prefixHits);
        $this->assertSame($foreign->id, $prefixHits[0]['asset_id']);
        $this->assertSame($b->id, $prefixHits[0]['other_client_id']);
        $this->assertSame('SMARTS-', $prefixHits[0]['evidence']['hostname_prefix']);
        $this->assertSame(3, $prefixHits[0]['evidence']['learned_prefix_min']);

        // Generic DESKTOP- assets are silent — the noise the criterion exists to reject.
        $desktopHits = array_values(array_filter($result['tier_b'], fn ($r) => str_starts_with((string) ($r['evidence']['hostname_prefix'] ?? ''), 'DESKTOP')));
        $this->assertCount(0, $desktopHits);
    }

    public function test_rule6_does_not_fire_below_the_learned_threshold(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create();
        // Only 2 SMARTS- at Bravo → below N=3, not learned.
        $this->asset($b, ['hostname' => 'SMARTS-01']);
        $this->asset($b, ['hostname' => 'SMARTS-02']);
        $this->asset($a, ['hostname' => 'SMARTS-99']);

        $rules = $this->rules($this->finder()->find(null)['tier_b']);
        $this->assertNotContains('foreign_client_hostname_prefix', $rules);
    }

    // ── Scoping, include_inactive, output contract ──

    public function test_scope_per_client_vs_fleet_wide(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create();
        $aAsset = $this->asset($a, ['serial_number' => 'DUP-SN', 'hostname' => 'HOST-SA']);
        $bAsset = $this->asset($b, ['serial_number' => 'DUP-SN', 'hostname' => 'HOST-SB']);

        // Per-client: only the scoped client's asset is a SUBJECT.
        $scoped = $this->finder()->find($a->id);
        $this->assertSame(1, $scoped['tier_a_count']);
        $this->assertSame($aAsset->id, $scoped['tier_a'][0]['asset_id']);
        $this->assertSame($b->id, $scoped['tier_a'][0]['other_client_id']);
        $this->assertSame('client:'.$a->id, $scoped['scope']);

        // Fleet-wide: both sides surface as subjects.
        $fleet = $this->finder()->find(null);
        $this->assertSame(2, $fleet['tier_a_count']);
        $this->assertSame('fleet', $fleet['scope']);
        $ids = array_map(fn ($r) => $r['asset_id'], $fleet['tier_a']);
        $this->assertContains($aAsset->id, $ids);
        $this->assertContains($bAsset->id, $ids);
    }

    public function test_include_inactive_passthrough(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create();
        $this->asset($a, ['serial_number' => 'INACT-SN', 'hostname' => 'HOST-IA', 'is_active' => true]);
        $this->asset($b, ['serial_number' => 'INACT-SN', 'hostname' => 'HOST-IB', 'is_active' => false]);

        // Default: the inactive counterpart is not considered → no cross-client pair.
        $this->assertSame(0, $this->finder()->find(null, includeInactive: false)['tier_a_count']);

        // include_inactive=true widens both sides → the pair surfaces.
        $this->assertGreaterThanOrEqual(1, $this->finder()->find(null, includeInactive: true)['tier_a_count']);
    }

    public function test_output_carries_rule_other_client_and_evidence_and_the_caveat(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create(['name' => 'Bravo']);
        $this->asset($a, ['serial_number' => 'SHAPE-SN', 'hostname' => 'HOST-SHAPE-A']);
        $this->asset($b, ['serial_number' => 'SHAPE-SN', 'hostname' => 'HOST-SHAPE-B']);

        $result = $this->finder()->find($a->id);
        $row = $result['tier_a'][0];

        foreach (['asset_id', 'hostname', 'client_id', 'client_name', 'rule', 'other_client_id', 'other_client_name', 'evidence'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertSame('Bravo', $row['other_client_name']);
        $this->assertArrayHasKey('tier_a', $result);
        $this->assertArrayHasKey('tier_b', $result);
        $this->assertStringContainsString('Absence of a Tier A hit is not proof', $result['caveat']);
    }

    public function test_malformed_or_unresolvable_client_scope_fails_closed(): void
    {
        $result = $this->finder()->find(999999);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('tier_a', $result);
    }

    public function test_clean_fleet_produces_no_findings(): void
    {
        $a = Client::factory()->create();
        $this->asset($a, ['serial_number' => 'UNIQUE-1', 'hostname' => 'CLEAN-01', 'ip_address' => '203.0.113.1']);
        $this->asset($a, ['serial_number' => 'UNIQUE-2', 'hostname' => 'CLEAN-02', 'ip_address' => '203.0.113.2']);

        $result = $this->finder()->find(null);
        $this->assertSame(0, $result['tier_a_count']);
        $this->assertSame(0, $result['tier_b_count']);
    }
}
