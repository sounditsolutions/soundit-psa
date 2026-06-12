<?php

namespace Tests\Unit\Wiki;

use App\Services\Wiki\WikiLinkParser;
use PHPUnit\Framework\TestCase;

class WikiLinkParserTest extends TestCase
{
    public function test_parses_plain_and_labeled_wikilinks(): void
    {
        $md = 'See [[network]] and [[runbooks/user-onboarding|the onboarding runbook]].';

        $links = (new WikiLinkParser)->parse($md);

        $this->assertSame([
            ['target' => 'network', 'label' => null],
            ['target' => 'runbooks/user-onboarding', 'label' => 'the onboarding runbook'],
        ], $links);
    }

    public function test_deduplicates_targets_and_ignores_empty(): void
    {
        $md = '[[a]] then [[a|again]] and [[]]';

        $links = (new WikiLinkParser)->parse($md);

        $this->assertSame([['target' => 'a', 'label' => null]], $links);
    }

    public function test_returns_empty_for_no_links(): void
    {
        $this->assertSame([], (new WikiLinkParser)->parse('no links here'));
    }
}
