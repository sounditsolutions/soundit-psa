<?php

namespace Tests\Unit\Support;

use App\Support\McpToolModes;
use App\Support\StagedActionLabels;
use Tests\TestCase;

/**
 * StagedActionLabels is the SINGLE source of operator-facing action labels
 * (psa-2f0bg). These tests are the structural guard that keeps it from becoming
 * yet another hand-maintained list that silently drifts — the failure class this
 * codebase keeps hitting (the #306 cockpit badge that rendered a password reset
 * as "Reply").
 */
class StagedActionLabelsTest extends TestCase
{
    public function test_every_stageable_action_type_has_a_curated_label(): void
    {
        // The staged alias names (cipp_stage_*, tactical_stage_*, stage_email, …) are
        // the action_type values a held run carries. Every one must have a real label,
        // not the de-slug fallback, or an operator gets a half-readable notification.
        $missing = array_values(array_filter(
            array_keys(McpToolModes::stagedToCanonical()),
            fn (string $type) => ! StagedActionLabels::hasCuratedLabel($type),
        ));

        $this->assertSame([], $missing, 'staged action types with no curated label: '.implode(', ', $missing));
    }

    public function test_the_cockpit_badge_labels_match_the_single_source(): void
    {
        // The cockpit badge match still carries inline label strings; the $badgeFor
        // closure overrides them from StagedActionLabels at runtime, so the two are
        // identical in production. This test proves the inline strings are kept
        // byte-identical, so a future editor cannot make the cockpit and the
        // notification disagree by touching only the blade.
        $blade = (string) file_get_contents(resource_path('views/cockpit/index.blade.php'));

        // Parse "'action_type' => ['classes', 'Label', 'icon']" rows from the match.
        preg_match_all(
            "/'([a-z_]+)'\\s*=>\\s*\\['[^']*',\\s*'([^']*)',\\s*'[^']*'\\]/",
            $blade,
            $rows,
            PREG_SET_ORDER,
        );
        $this->assertNotEmpty($rows, 'could not parse the cockpit badge map');

        $mismatched = [];
        foreach ($rows as [$_, $type, $bladeLabel]) {
            $source = StagedActionLabels::humanLabel($type);
            if ($bladeLabel !== $source) {
                $mismatched[] = "{$type}: blade='{$bladeLabel}' source='{$source}'";
            }
        }

        $this->assertSame([], $mismatched, "cockpit badge labels drifted from StagedActionLabels:\n".implode("\n", $mismatched));
    }

    public function test_an_unknown_type_is_deslugged_never_raw(): void
    {
        $this->assertSame('Some future action', StagedActionLabels::humanLabel('cipp_stage_some_future_action'));
        $this->assertFalse(StagedActionLabels::hasCuratedLabel('cipp_stage_some_future_action'));
    }
}
