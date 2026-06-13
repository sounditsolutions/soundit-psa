<?php

namespace Tests\Feature\Wiki;

use App\Services\Ai\AiClient;
use App\Services\Wiki\Mining\WikiFactExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiFactExtractorTest extends TestCase
{
    use RefreshDatabase;

    private function mockAi(array $payload): void
    {
        $mock = $this->mock(AiClient::class);
        $mock->shouldReceive('completeJson')->once()->andReturn($payload);
        $mock->shouldReceive('cumulativeInputTokens')->andReturn(1200);
        $mock->shouldReceive('cumulativeOutputTokens')->andReturn(300);
    }

    public function test_returns_validated_candidates(): void
    {
        $this->mockAi(['facts' => [
            ['page' => 'network', 'anchor' => 'equipment', 'subject_key' => 'network:edge-firewall',
                'statement' => 'Edge firewall is a FortiGate 60F', 'volatility' => 'durable', 'confidence' => 0.9],
            ['page' => 'known-issues', 'anchor' => 'active', 'subject_key' => 'issue:vpn-dtls',
                'statement' => 'FortiClient DTLS causes afternoon VPN drops; keep DTLS disabled', 'volatility' => 'volatile', 'confidence' => 0.8],
        ]]);

        $result = app(WikiFactExtractor::class)->extract('CONTEXT');

        $this->assertCount(2, $result['facts']);
        $this->assertSame(0, $result['discarded']);
        $this->assertSame(['input' => 1200, 'output' => 300], $result['tokens']);
    }

    public function test_discards_low_confidence_bad_targets_and_malformed(): void
    {
        $this->mockAi(['facts' => [
            ['page' => 'network', 'anchor' => 'equipment', 'subject_key' => 'a', 'statement' => 'low conf', 'volatility' => 'durable', 'confidence' => 0.3],
            ['page' => 'overview', 'anchor' => 'summary', 'subject_key' => 'b', 'statement' => 'bad target', 'volatility' => 'durable', 'confidence' => 0.9],
            ['statement' => 'missing keys'],
        ]]);

        $result = app(WikiFactExtractor::class)->extract('CONTEXT');

        $this->assertCount(0, $result['facts']);
        $this->assertSame(3, $result['discarded']);
    }

    public function test_zero_facts_is_a_valid_outcome(): void
    {
        $this->mockAi(['facts' => []]);

        $result = app(WikiFactExtractor::class)->extract('CONTEXT');

        $this->assertSame([], $result['facts']);
    }
}
