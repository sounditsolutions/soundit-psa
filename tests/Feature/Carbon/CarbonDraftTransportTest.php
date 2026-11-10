<?php

namespace Tests\Feature\Carbon;

use App\Services\Carbon\CarbonMcpClient;
use App\Services\Carbon\CarbonMcpDraftAuthException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CarbonDraftTransportTest extends TestCase
{
    use FakesCarbonMcp;
    use RefreshDatabase;

    public function test_draft_401_posts_once_and_throws_distinct_exception(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        $this->seedConnected();
        Http::fake($this->tokenFake() + [
            'https://api.carbonsys.com/mcp' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18']])
                ->push('', 202)
                ->push('', 401)
                ->push('', 401),
        ]);
        try {
            app(CarbonMcpClient::class)->createDraftOrderOnce(['items' => []]);
            $this->fail('Expected draft auth exception.');
        } catch (CarbonMcpDraftAuthException $e) {
            $this->assertSame('Carbon MCP draft HTTP 401', $e->getMessage());
        } finally {
            $calls = Http::recorded(fn ($r) => ($r['params']['name'] ?? null) === 'create_draft_order');
            $this->assertCount(1, $calls);
        }
    }

    public function test_draft_seam_has_fixed_tool_and_replay_disabled(): void
    {
        $reflection = new \ReflectionMethod(CarbonMcpClient::class, 'createDraftOrderOnce');
        $lines = file($reflection->getFileName());
        $source = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
        $this->assertStringContainsString("'name' => 'create_draft_order'", $source);
        $this->assertStringContainsString('replay: false', $source);
        $this->assertSame(1, substr_count($source, '$this->post('));
        $this->assertStringNotContainsString('$this->request(', $source);
        $this->assertStringNotContainsString('$this->callTool(', $source);
    }
}
