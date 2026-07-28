<?php

namespace Tests\Feature\Mcp;

use App\Models\Setting;
use App\Services\Graph\GraphClient;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use App\Support\McpToolSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Slice A MCP PUBLICATION WIRING for the staff Calendar/scheduling READ tools (psa-abl0i):
 * calendar_list_events + calendar_get_event become reachable on the staff MCP surface behind
 * three gates that must all hold — (1) the grant catalog is UNGATED so the tools can be
 * pre-granted before the integration is switched on; (2) the LIVE surface is gated OFF=OFF on
 * CalendarConfig::isAvailable() (switched on AND Graph configured); (3) the tools are
 * EXPLICIT-GRANT-ONLY, so the legacy full-surface token never inherits tenant-wide staff
 * mailbox reads. The SECURITY SPINE (the server-side UPN allowlist) is proven end-to-end here
 * through the real endpoint, not just the executor unit — a non-allowlisted owner is refused
 * even with a valid grant and a live toolset.
 */
class StaffCalendarToolWiringTest extends TestCase
{
    use RefreshDatabase;

    private const READ_TOOLS = ['calendar_list_events', 'calendar_get_event'];

    private function token(array $tools, string $label = 'chet'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
    }

    private function legacyToken(): string
    {
        return McpConfig::rotateStaffToken();
    }

    /** Switch the toolset on: enabled + allowlist + a configured Graph transport. */
    private function enableCalendarLive(array $allowed = ['charlie@soundit.co']): void
    {
        Setting::setValue('calendar_enabled', '1');
        Setting::setValue('calendar_allowed_owner_upns', json_encode($allowed));
        config(['services.graph' => [
            'tenant_id' => 'tenant-uuid',
            'client_id' => 'client-uuid',
            'client_secret' => 'shhh',
        ]]);
    }

    /** @param  array<string, mixed>  $arguments */
    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function tools(string $token): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->json('result.tools') ?? [];
    }

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** A documented-shape MS Graph calendarView event (camelCase, per the event resource). */
    private function graphEvent(): array
    {
        return [
            'id' => 'AAMkAG',
            'subject' => 'Onsite: printer swap',
            'bodyPreview' => 'Swap the MFP at reception.',
            'start' => ['dateTime' => '2026-07-28T15:00:00.0000000', 'timeZone' => 'UTC'],
            'end' => ['dateTime' => '2026-07-28T16:00:00.0000000', 'timeZone' => 'UTC'],
            'isAllDay' => false,
            'showAs' => 'busy',
            'webLink' => 'https://outlook.office365.com/owa/?itemid=AAMkAG',
            'isOnlineMeeting' => true,
            'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/xyz'],
            'location' => ['displayName' => 'Reception'],
            'organizer' => ['emailAddress' => ['name' => 'Charlie Coutts', 'address' => 'charlie@soundit.co']],
            'responseStatus' => ['response' => 'organizer'],
            'attendees' => [[
                'type' => 'required',
                'status' => ['response' => 'accepted', 'time' => '2026-07-27T10:00:00Z'],
                'emailAddress' => ['name' => 'Client Contact', 'address' => 'contact@clientco.example'],
            ]],
        ];
    }

    // ---- (1) grant catalog: UNGATED, sensitive, single-integration placement -------------

    public function test_calendar_tools_are_registry_backed_and_grouped_sensitive(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('calendar', $groups);
        $this->assertTrue($groups['calendar']['sensitive'], 'staff-calendar reads are sensitive');

        $groupNames = array_column($groups['calendar']['tools'], 'name');
        foreach (self::READ_TOOLS as $name) {
            $this->assertContains($name, $groupNames, "{$name} in the calendar grant group");
            $this->assertContains($name, McpToolRegistry::allToolNames(), "{$name} token-grantable");
        }
    }

    public function test_calendar_tools_route_to_a_calendar_integration_card(): void
    {
        foreach (self::READ_TOOLS as $name) {
            $this->assertSame('calendar', McpToolRegistry::integrationForToolName($name));
        }

        $this->assertArrayHasKey('calendar', McpToolRegistry::integrationMeta(), 'a Calendar card on the token page');
        $this->assertArrayHasKey('calendar', McpToolRegistry::integrationGroups(), 'calendar reads render under their own card');

        // Every calendar tool lands exactly once under the calendar integration.
        $placed = [];
        foreach (McpToolRegistry::integrationGroups()['calendar']['tiers'] as $tier) {
            foreach ($tier['tools'] as $tool) {
                $placed[] = $tool['name'];
            }
        }
        foreach (self::READ_TOOLS as $name) {
            $this->assertContains($name, $placed);
        }
    }

    // ---- (2) live surface: OFF=OFF on isAvailable() --------------------------------------

    public function test_off_equals_off_calendar_tools_absent_from_live_surface_when_disabled(): void
    {
        Setting::setValue('calendar_enabled', '0');
        config(['services.graph' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']]);

        $live = McpToolSurface::liveToolNames();
        foreach (self::READ_TOOLS as $name) {
            $this->assertNotContains($name, $live, "{$name} must NOT be live when calendar is switched off");
        }
    }

    public function test_off_equals_off_calendar_tools_absent_from_live_surface_when_graph_unconfigured(): void
    {
        Setting::setValue('calendar_enabled', '1');
        config(['services.graph' => ['tenant_id' => null, 'client_id' => null, 'client_secret' => null]]);

        $live = McpToolSurface::liveToolNames();
        foreach (self::READ_TOOLS as $name) {
            $this->assertNotContains($name, $live, "{$name} must NOT be live when Graph is not configured");
        }
    }

    public function test_calendar_tools_are_live_when_available(): void
    {
        $this->enableCalendarLive();

        $live = McpToolSurface::liveToolNames();
        foreach (self::READ_TOOLS as $name) {
            $this->assertContains($name, $live, "{$name} must be live when enabled + Graph configured");
        }
    }

    // ---- (3) tools/list: explicit-grant-only, UPN-scoped (no client_id) -------------------

    public function test_tools_list_hides_calendar_from_legacy_token_and_shows_it_to_a_grant(): void
    {
        $this->enableCalendarLive();

        // Explicit-grant-only: the legacy full-surface token never inherits tenant-wide
        // staff-mailbox reads.
        $legacyNames = collect($this->tools($this->legacyToken()))->pluck('name')->all();
        foreach (self::READ_TOOLS as $name) {
            $this->assertNotContains($name, $legacyNames, "{$name} must not be inherited by the legacy token");
        }

        // A granted token sees them; they are UPN-scoped, so client_id is NOT injected.
        $scoped = collect($this->tools($this->token(self::READ_TOOLS)))->keyBy('name');
        foreach (self::READ_TOOLS as $name) {
            $this->assertTrue($scoped->has($name), "{$name} visible to a granted token");
            $this->assertArrayNotHasKey('client_id', (array) ($scoped[$name]['inputSchema']['properties'] ?? []), "{$name} is UPN-scoped, not client-scoped");
        }
    }

    public function test_tools_list_hides_calendar_when_switched_off_even_if_granted(): void
    {
        Setting::setValue('calendar_enabled', '0');
        config(['services.graph' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']]);

        $names = collect($this->tools($this->token(self::READ_TOOLS)))->pluck('name')->all();
        foreach (self::READ_TOOLS as $name) {
            $this->assertNotContains($name, $names, "{$name} must be hidden when the toolset is off");
        }
    }

    // ---- (3) tools/call: the allowlist SECURITY SPINE proven through the endpoint ---------

    public function test_granted_call_reads_an_allowlisted_mailbox_and_projects_events(): void
    {
        $this->enableCalendarLive(['charlie@soundit.co']);
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('calendarView')
                ->once()
                ->with('charlie@soundit.co', '2026-07-28T00:00:00Z', '2026-07-29T00:00:00Z')
                ->andReturn([$this->graphEvent()]);
        });

        $response = $this->callTool($this->token(['calendar_list_events']), 'calendar_list_events', [
            'user_upn' => 'charlie@soundit.co',
            'start' => '2026-07-28T00:00:00Z',
            'end' => '2026-07-29T00:00:00Z',
        ]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $this->assertSame('charlie@soundit.co', $result['user_upn']);
        $this->assertCount(1, $result['events']);
        $event = $result['events'][0];
        $this->assertSame('Onsite: printer swap', $event['subject']);
        $this->assertSame('2026-07-28T15:00:00.0000000', $event['start']['date_time']);
        $this->assertSame('charlie@soundit.co', $event['organizer']['email']);
        // The allowlist gates the OWNER, never attendees: an external attendee is present.
        $this->assertSame('contact@clientco.example', $event['attendees'][0]['email']);
    }

    public function test_granted_call_refuses_a_non_allowlisted_owner_through_the_endpoint(): void
    {
        $this->enableCalendarLive(['charlie@soundit.co']);
        // The allowlist must refuse BEFORE any Graph call — assert calendarView is never hit.
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('calendarView')->never();
        });

        $response = $this->callTool($this->token(['calendar_list_events']), 'calendar_list_events', [
            'user_upn' => 'billing@soundit.co', // internal, but NOT on the allowlist
            'start' => '2026-07-28T00:00:00Z',
            'end' => '2026-07-29T00:00:00Z',
        ]);
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('allowlist', mb_strtolower((string) $response->json('result.content.0.text')));
    }

    public function test_get_event_reads_an_allowlisted_mailbox(): void
    {
        $this->enableCalendarLive(['charlie@soundit.co']);
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('getEvent')
                ->once()
                ->with('charlie@soundit.co', 'AAMkAG')
                ->andReturn($this->graphEvent());
        });

        $response = $this->callTool($this->token(['calendar_get_event']), 'calendar_get_event', [
            'user_upn' => 'charlie@soundit.co',
            'event_id' => 'AAMkAG',
        ]);
        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame('Onsite: printer swap', $this->decodedResult($response)['event']['subject']);
    }

    public function test_switched_off_calendar_call_is_refused_as_not_live(): void
    {
        Setting::setValue('calendar_enabled', '0');
        Setting::setValue('calendar_allowed_owner_upns', json_encode(['charlie@soundit.co']));
        config(['services.graph' => ['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']]);
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('calendarView')->never();
        });

        $response = $this->callTool($this->token(['calendar_list_events']), 'calendar_list_events', [
            'user_upn' => 'charlie@soundit.co',
            'start' => '2026-07-28T00:00:00Z',
            'end' => '2026-07-29T00:00:00Z',
        ]);
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
    }

    public function test_legacy_token_cannot_call_calendar_even_when_live(): void
    {
        $this->enableCalendarLive(['charlie@soundit.co']);
        $this->mock(GraphClient::class, function ($m) {
            $m->shouldReceive('calendarView')->never();
        });

        $response = $this->callTool($this->legacyToken(), 'calendar_list_events', [
            'user_upn' => 'charlie@soundit.co',
            'start' => '2026-07-28T00:00:00Z',
            'end' => '2026-07-29T00:00:00Z',
        ]);
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
    }
}
