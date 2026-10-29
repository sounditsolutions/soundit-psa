<?php

namespace Tests\Feature\Carbon;

use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What connect-time sync (card PEYvh4NL) must leave unchanged: the daily 06:07
 * catalogue sync registered in routes/console.php, and the Sync tools now
 * button's flash text.
 */
class CarbonMcpScheduleAndButtonTest extends TestCase
{
    use FakesCarbonMcp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_the_daily_catalogue_sync_still_runs_at_0607(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $e): bool => str_contains((string) $e->command, 'carbon:sync-mcp-catalog'));

        $this->assertCount(1, $events, 'exactly one carbon:sync-mcp-catalog schedule');
        $this->assertSame('7 6 * * *', $events->first()->expression);
    }

    public function test_the_sync_tools_now_button_flash_is_unchanged(): void
    {
        $this->seedConnected();
        Http::fake($this->tokenFake() + $this->mcpFake());

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.integrations.carbon.sync-mcp-catalog'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', 'Carbon MCP catalogue sync complete: 6 seen, 6 active, 6 created, 0 updated, 0 deactivated.');
    }
}
