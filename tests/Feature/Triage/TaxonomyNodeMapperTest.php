<?php

namespace Tests\Feature\Triage;

use App\Models\TicketCategory;
use App\Services\Triage\TaxonomyNodeMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * so-0ftg Part 4 — the coarse triage->taxonomy resolver. A legacy free-text
 * (category, subcategory) pair either resolves through the configured map to
 * an ACTIVE taxonomy node, or the answer is null and the ticket stays a
 * visible gap. No fuzziness beyond case-insensitivity and one trailing
 * parenthetical: a wrong SOP served confidently is worse than an honest gap.
 */
class TaxonomyNodeMapperTest extends TestCase
{
    use RefreshDatabase;

    private function seedTree(): array
    {
        $security = TicketCategory::create(['name' => 'Security & EDR']);
        $phishing = TicketCategory::create(['name' => 'Phishing/BEC', 'parent_id' => $security->id]);
        $email = TicketCategory::create(['name' => 'Email & M365 Tenant']);
        // Chet-style name with a trailing clarifier the config omits.
        $quarantine = TicketCategory::create(['name' => 'Email security & quarantine (Mesh)', 'parent_id' => $email->id]);

        return compact('security', 'phishing', 'email', 'quarantine');
    }

    private function useMap(array $map): void
    {
        config(['tickets.taxonomy_map' => $map]);
    }

    public function test_resolves_a_mapped_pair_to_its_node(): void
    {
        ['phishing' => $phishing] = $this->seedTree();
        $this->useMap(['Security' => ['Phishing' => ['Security & EDR', 'Phishing/BEC']]]);

        $node = TaxonomyNodeMapper::resolve('Security', 'Phishing');

        $this->assertNotNull($node);
        $this->assertSame($phishing->id, $node->id);
    }

    public function test_matches_names_case_insensitively_and_ignores_a_trailing_parenthetical(): void
    {
        ['quarantine' => $quarantine] = $this->seedTree();
        // Config uses the short lowercase form; the DB node carries "(Mesh)".
        $this->useMap(['Email' => ['Spam' => ['email & m365 tenant', 'EMAIL SECURITY & QUARANTINE']]]);

        $node = TaxonomyNodeMapper::resolve('Email', 'Spam');

        $this->assertNotNull($node);
        $this->assertSame($quarantine->id, $node->id);
    }

    public function test_subcategory_entry_wins_over_the_category_fallback(): void
    {
        ['phishing' => $phishing, 'security' => $security] = $this->seedTree();
        $this->useMap([
            'Security' => [
                '' => ['Security & EDR'],
                'Phishing' => ['Security & EDR', 'Phishing/BEC'],
            ],
        ]);

        $this->assertSame($phishing->id, TaxonomyNodeMapper::resolve('Security', 'Phishing')->id);
        // Any other subcategory (or none) falls back to the '' entry.
        $this->assertSame($security->id, TaxonomyNodeMapper::resolve('Security', 'Access Review')->id);
        $this->assertSame($security->id, TaxonomyNodeMapper::resolve('Security', null)->id);
    }

    public function test_unmapped_pairs_degrade_to_null(): void
    {
        $this->seedTree();
        $this->useMap(['Security' => ['Phishing' => ['Security & EDR', 'Phishing/BEC']]]);

        // Category absent from the map entirely.
        $this->assertNull(TaxonomyNodeMapper::resolve('Cloud', 'Azure'));
        // Subcategory without an entry and no '' fallback.
        $this->assertNull(TaxonomyNodeMapper::resolve('Security', 'Malware'));
        // No classification at all.
        $this->assertNull(TaxonomyNodeMapper::resolve(null, null));
        $this->assertNull(TaxonomyNodeMapper::resolve('', 'Phishing'));
    }

    public function test_a_path_segment_missing_from_the_db_resolves_to_null(): void
    {
        $this->seedTree();
        $this->useMap(['Security' => ['Malware' => ['Security & EDR', 'Malware/ransomware']]]);

        // 'Malware/ransomware' was never authored — the whole path gaps out.
        $this->assertNull(TaxonomyNodeMapper::resolve('Security', 'Malware'));
    }

    public function test_inactive_nodes_never_resolve(): void
    {
        ['phishing' => $phishing, 'security' => $security] = $this->seedTree();
        $this->useMap(['Security' => ['Phishing' => ['Security & EDR', 'Phishing/BEC']]]);

        $phishing->update(['is_active' => false]);
        $this->assertNull(TaxonomyNodeMapper::resolve('Security', 'Phishing'));

        // A retired ANCESTOR also kills the path — the walk is active-only.
        $phishing->update(['is_active' => true]);
        $security->update(['is_active' => false]);
        $this->assertNull(TaxonomyNodeMapper::resolve('Security', 'Phishing'));
    }

    public function test_a_non_leaf_node_is_assignable_coarse_by_design(): void
    {
        ['phishing' => $phishing] = $this->seedTree();
        // Chet authors symptom children under the tier-2 node; the coarse map
        // still lands on the tier-2 parent (its SOP is what gets served).
        TicketCategory::create(['name' => 'BEC/impersonation', 'parent_id' => $phishing->id]);
        $this->useMap(['Security' => ['Phishing' => ['Security & EDR', 'Phishing/BEC']]]);

        $node = TaxonomyNodeMapper::resolve('Security', 'Phishing');

        $this->assertSame($phishing->id, $node->id);
        $this->assertFalse($node->isLeaf());
    }

    public function test_a_three_level_path_resolves_to_the_item_node(): void
    {
        ['phishing' => $phishing] = $this->seedTree();
        $item = TicketCategory::create(['name' => 'BEC/impersonation', 'parent_id' => $phishing->id]);
        $this->useMap(['Security' => ['Phishing' => ['Security & EDR', 'Phishing/BEC', 'BEC/impersonation']]]);

        $this->assertSame($item->id, TaxonomyNodeMapper::resolve('Security', 'Phishing')->id);
    }

    public function test_malformed_map_entries_degrade_to_null_not_an_exception(): void
    {
        $this->seedTree();

        $this->useMap(['Security' => ['Phishing' => 'Security & EDR']]); // string, not array
        $this->assertNull(TaxonomyNodeMapper::resolve('Security', 'Phishing'));

        $this->useMap(['Security' => ['Phishing' => []]]); // empty path
        $this->assertNull(TaxonomyNodeMapper::resolve('Security', 'Phishing'));

        $this->useMap(['Security' => ['Phishing' => ['a', 'b', 'c', 'd']]]); // deeper than the tree allows
        $this->assertNull(TaxonomyNodeMapper::resolve('Security', 'Phishing'));

        $this->useMap(['Security' => ['Phishing' => ['Security & EDR', '  ']]]); // blank segment
        $this->assertNull(TaxonomyNodeMapper::resolve('Security', 'Phishing'));
    }

    public function test_shipped_map_entries_are_well_formed(): void
    {
        // Guards the real config file: every entry must be a 1-3 element list
        // of non-empty node names, keyed under a legacy category that exists
        // in the legacy menu — so a config edit can't silently break mapping.
        $legacyMenu = config('tickets.categories');
        $map = config('tickets.taxonomy_map');

        $this->assertIsArray($map);
        $this->assertNotEmpty($map);

        foreach ($map as $legacyCategory => $entries) {
            $this->assertArrayHasKey($legacyCategory, $legacyMenu, "taxonomy_map key '{$legacyCategory}' is not a legacy category");
            foreach ($entries as $legacySubcategory => $path) {
                if ($legacySubcategory !== '') {
                    $this->assertContains(
                        $legacySubcategory,
                        $legacyMenu[$legacyCategory],
                        "taxonomy_map '{$legacyCategory}/{$legacySubcategory}' is not a legacy subcategory"
                    );
                }
                $this->assertIsArray($path);
                $this->assertGreaterThanOrEqual(1, count($path));
                $this->assertLessThanOrEqual(3, count($path));
                foreach ($path as $segment) {
                    $this->assertIsString($segment);
                    $this->assertNotSame('', trim($segment));
                }
            }
        }
    }
}
