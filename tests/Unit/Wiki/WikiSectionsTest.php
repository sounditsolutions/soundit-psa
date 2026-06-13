<?php

namespace Tests\Unit\Wiki;

use App\Services\Wiki\WikiSections;
use PHPUnit\Framework\TestCase;

class WikiSectionsTest extends TestCase
{
    private string $md = "Intro line.\n\n## Assets\n\n- one\n\n## Known Issues\n\nnone\n";

    public function test_split_keys_sections_by_anchor_with_preamble(): void
    {
        $sections = WikiSections::split($this->md);

        $this->assertSame(['', 'assets', 'known-issues'], array_keys($sections));
        $this->assertSame('Assets', $sections['assets']['heading']);
        $this->assertStringContainsString('- one', $sections['assets']['content']);
        $this->assertStringContainsString('Intro line.', $sections['']['content']);
    }

    public function test_replace_swaps_one_section_body_and_keeps_the_rest(): void
    {
        $out = WikiSections::replace($this->md, 'assets', "- two\n");

        $this->assertStringContainsString("## Assets\n\n- two", $out);
        $this->assertStringContainsString('Intro line.', $out);
        $this->assertStringContainsString("## Known Issues\n\nnone", $out);
        $this->assertStringNotContainsString('- one', $out);
    }

    public function test_replace_between_markers_only_touches_marked_region(): void
    {
        $md = "## Assets\n\nkeep this\n\n<!-- wiki:facts:assets:start -->\nold\n<!-- wiki:facts:assets:end -->\n\ntail\n";

        $out = WikiSections::spliceMarkers($md, 'assets', "new\n");

        $this->assertStringContainsString('keep this', $out);
        $this->assertStringContainsString("<!-- wiki:facts:assets:start -->\nnew\n<!-- wiki:facts:assets:end -->", $out);
        $this->assertStringContainsString('tail', $out);
        $this->assertStringNotContainsString('old', $out);
    }

    public function test_splice_appends_markers_inside_section_when_missing(): void
    {
        $out = WikiSections::spliceMarkers($this->md, 'assets', "facts here\n");

        $this->assertStringContainsString("<!-- wiki:facts:assets:start -->\nfacts here\n<!-- wiki:facts:assets:end -->", $out);
        $this->assertStringContainsString('- one', $out);
    }

    public function test_anchor_for_slugifies_heading(): void
    {
        $this->assertSame('known-issues', WikiSections::anchorFor('Known Issues'));
    }

    public function test_join_split_round_trip_is_identity_for_newline_terminated_docs(): void
    {
        $this->assertSame($this->md, WikiSections::join(WikiSections::split($this->md)));
    }

    public function test_splice_self_heals_orphaned_start_marker(): void
    {
        $md = "## Assets\n\n<!-- wiki:facts:assets:start -->\norphan content\n\nhuman text after\n";

        $out = WikiSections::spliceMarkers($md, 'assets', "fresh\n");

        $this->assertSame(1, substr_count($out, '<!-- wiki:facts:assets:start -->'));
        $this->assertSame(1, substr_count($out, '<!-- wiki:facts:assets:end -->'));
        $this->assertStringContainsString('human text after', $out);
        $this->assertStringContainsString("<!-- wiki:facts:assets:start -->\nfresh\n<!-- wiki:facts:assets:end -->", $out);
    }

    public function test_splice_treats_dollar_sequences_as_literals(): void
    {
        $md = "## Assets\n\n<!-- wiki:facts:assets:start -->\nold\n<!-- wiki:facts:assets:end -->\n";

        $out = WikiSections::spliceMarkers($md, 'assets', "- SRV \$0 has 8 GB RAM\n");

        $this->assertStringContainsString('- SRV $0 has 8 GB RAM', $out);
        $this->assertSame(1, substr_count($out, '<!-- wiki:facts:assets:start -->'));
        $this->assertStringNotContainsString('old', $out);
    }
}
