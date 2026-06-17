<?php

namespace App\Services\Tactical;

use App\Models\Asset;
use App\Services\Wiki\Mining\WikiRedactor;

final class TacticalContextProvider
{
    public const DEFAULT_TOKEN_BUDGET = 1500;

    public function __construct(
        private TacticalInsightService $insights,
        private WikiRedactor $redactor,
    ) {}

    public function forAsset(Asset $asset, int $maxTokens = self::DEFAULT_TOKEN_BUDGET): ?PromptBlock
    {
        // live:true => bounded refresh (LIVE_TIMEOUT_SECONDS), degrades to snapshot, never throws (G5).
        $insight = $this->insights->forAsset($asset, live: true);
        if (! $insight->linked) {
            return null;
        }

        // G1: flatten to PLAIN TEXT (never json_encode), then redact the assembled text.
        $plain = $insight->toPlainText();
        $redacted = $this->redactor->redact($plain);

        return new PromptBlock(
            text: $redacted,
            estimatedTokens: (int) ceil(mb_strlen($redacted) / 4),
            freshAsOf: $insight->freshAsOf,
        );
    }
}
