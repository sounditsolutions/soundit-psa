<?php

namespace Tests\Unit\Chet;

use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Services\Technician\PromptFence;
use App\Services\Wiki\Mining\WikiRedactor;
use Tests\TestCase;

class ChetDataSurfaceTextSanitizerTest extends TestCase
{
    public function test_normalizes_compatibility_homoglyphs_before_redacting_secrets(): void
    {
        $sanitizer = new ChetDataSurfaceTextSanitizer(new WikiRedactor, new PromptFence);

        $sanitized = $sanitizer->sanitize(
            'Teams chat message body',
            'ｐａｓｓｗｏｒｄ＝SuperSecret123',
            500,
        );

        $this->assertStringContainsString('[REDACTED:credential]', $sanitized);
        $this->assertStringNotContainsString('SuperSecret123', $sanitized);
        $this->assertStringNotContainsString('password=', $sanitized);
    }

    public function test_redacts_before_truncating_boundary_split_tokens(): void
    {
        $sanitizer = new ChetDataSurfaceTextSanitizer(new WikiRedactor, new PromptFence);
        $secret = 'LEAKYSECRETFRAGMENT123456789+tail';

        $sanitized = $sanitizer->sanitize(
            'Tactical check stdout',
            str_repeat('x', 494).' '.$secret,
            500,
        );

        $this->assertStringNotContainsString('LEAKY', $sanitized);
        $this->assertStringNotContainsString('SECRETFRAGMENT', $sanitized);
    }
}
