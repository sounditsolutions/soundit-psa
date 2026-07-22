<?php

namespace Tests\Feature\Mcp;

use App\Models\Setting;
use App\Models\User;
use App\Services\Mcp\PortalMcpToolDefinitions;
use App\Support\McpConfig;
use App\Support\McpToolSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * psa-vydpz — the staff MCP grant gate never consulted the LIVE surface.
 *
 * McpStaffController::toolAllowed() enforced the TOKEN GRANT and nothing else. tools/list
 * publishes McpToolSurface::liveGeneral/ClientScopedToolDefinitions() filtered by that gate,
 * but tools/call consulted only the gate — so the two paths answered different questions and
 * a tool that was GRANTED but NEVER PUBLISHED stayed callable by name.
 *
 * The advertised surface therefore UNDERSTATED what an existing token would actually do.
 * Enumerated at the time of filing: grant catalog 208, live 73, granted-but-never-published
 * 137, of which 52 passed straight through to real work — three of them proven to reach live
 * outbound vendor HTTP (cipp_list_users, cipp_list_audit_logs, mesh_search_email_logs).
 *
 * *** THIS IS THE MCP-LAYER ANALOGUE OF psa-ejzjd. *** That bead made AiClient refuse any
 * tool name it had not published to the model. This does the same one layer out, at
 * tools/call. Same defect class — publish and dispatch derived from different sources —
 * different seam. It is also what makes Charlie's OFF=OFF ruling real on MCP: psa-wzjzz
 * removes a switched-off integration's tools from the LIVE surface, but until the call path
 * consults that surface, a token holding the grant can still call them. Gating publication
 * alone HIDES a tool; only this DISABLES it.
 *
 * WHY THE ASSERTIONS BELOW LOOK AT *WHICH* ERROR CAME BACK. A refusal alone proves nothing —
 * an executor that happens to reject the arguments also returns an error, and that is exactly
 * what the original probe had to rule out. The two paths have unmistakably different shapes:
 * the GATE says "not allowed for this token", while the EXECUTOR fails on its own argument
 * validation ("No client context"). Seeing the executor's own error back is proof the call
 * reached it. So these tests assert the gate's shape AND the absence of the executor's.
 */
class McpCallPathLivenessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Granted to the token, absent from tools/list. Ninja is the honest choice here: its
     * master switch defaults to '0' (offboarding, psa-u97k), so it is not live in a default
     * deployment and needs no contrivance to be off.
     */
    private const GRANTED_BUT_NOT_LIVE = 'ninja_search_devices';

    /**
     * The executor's own argument-validation failure for the tool above. If this string
     * comes back, the call reached the executor and the gate did not stop it.
     */
    private const EXECUTOR_WAS_REACHED = 'No client context';

    private function callTool(string $token, string $name, array $arguments = []): TestResponse
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
    private function listTools(string $token): array
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

    private function resultText(TestResponse $response): string
    {
        return (string) $response->json('result.content.0.text');
    }

    // -----------------------------------------------------------------------
    // 1. THE DEFECT — a granted tool that was never published must not run
    // -----------------------------------------------------------------------

    public function test_a_granted_tool_that_is_not_live_is_refused_before_the_executor(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: [self::GRANTED_BUT_NOT_LIVE], label: 'opsbot');

        // Preconditions. Without these the test could pass because the tool was never
        // granted, or because it was live all along — neither of which is the property
        // under test.
        $this->assertNotContains(
            self::GRANTED_BUT_NOT_LIVE,
            McpToolSurface::liveToolNames(),
            'precondition: the tool must NOT be live, or this asserts nothing'
        );
        $this->assertNotContains(
            self::GRANTED_BUT_NOT_LIVE,
            array_column($this->listTools($token), 'name'),
            'precondition: the tool must be absent from what tools/list publishes to this token'
        );

        $response = $this->callTool($token, self::GRANTED_BUT_NOT_LIVE);
        $text = $this->resultText($response);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'), "calling a not-live tool must be an error, got: {$text}");

        // *** THE LOAD-BEARING ASSERTION. *** Not "an error came back" — an executor that
        // rejects the arguments would satisfy that while still having RUN. This asserts the
        // call never got that far.
        $this->assertStringNotContainsString(
            self::EXECUTOR_WAS_REACHED,
            $text,
            'the call REACHED THE EXECUTOR: it came back with the executor\'s own argument '
            .'validation error, so the gate did not stop a tool that was never published. Got: '.$text
        );

        $this->assertStringContainsString(
            'not allowed for this token',
            $text,
            "the refusal must come from the gate, got: {$text}"
        );
    }

    /**
     * The same property stated as a rule rather than an example: nothing outside the live
     * surface is callable, however the token was granted. A legacy full-surface token (no
     * allowedTools allowlist at all) is the sharpest case — it grants everything by
     * construction, so liveness is the ONLY thing standing between it and a dormant tool.
     */
    public function test_a_legacy_full_surface_token_still_cannot_call_a_not_live_tool(): void
    {
        $token = McpConfig::rotateStaffToken();

        $this->assertNotContains(
            self::GRANTED_BUT_NOT_LIVE,
            McpToolSurface::liveToolNames(),
            'precondition: the tool must NOT be live'
        );

        $text = $this->resultText($this->callTool($token, self::GRANTED_BUT_NOT_LIVE));

        $this->assertStringNotContainsString(
            self::EXECUTOR_WAS_REACHED,
            $text,
            'a legacy full-surface token reached the executor for a tool that is not live: '.$text
        );
    }

    // -----------------------------------------------------------------------
    // 2. THE NO-OVER-BLOCK MIRROR
    // -----------------------------------------------------------------------

    /**
     * The half a refusal-only test cannot cover. Proving a not-live tool is refused says
     * nothing about whether a LIVE one still runs, and silently removing a working capability
     * from an operator's token is the regression this bead's guardrails single out as the
     * hard one. Every tool that IS live and IS granted must still reach its executor.
     */
    public function test_a_granted_tool_that_is_live_still_runs(): void
    {
        $live = McpToolSurface::liveToolNames();
        $this->assertNotEmpty($live, 'precondition: the live surface must not be empty');

        $token = McpConfig::rotateStaffToken(allowedTools: ['get_queue_stats'], label: 'opsbot');

        $this->assertContains('get_queue_stats', $live, 'precondition: get_queue_stats must be live for this to be a mirror');

        $response = $this->callTool($token, 'get_queue_stats');
        $text = $this->resultText($response);

        $response->assertOk();
        $this->assertStringNotContainsString(
            'not allowed for this token',
            $text,
            'a LIVE, GRANTED tool was refused — the liveness conjunct over-blocked: '.$text
        );
    }

    /**
     * Stated over the whole surface rather than one example, so a fix that over-blocks a
     * single family cannot slip through. Everything tools/list publishes to a token must be
     * callable by that token: publish and dispatch must agree in BOTH directions, which is
     * the invariant whose absence caused this bead.
     *
     * *** THE BUILT-INS ARE EXEMPT, AND FINDING OUT WHY MATTERS MORE THAN THE EXEMPTION. ***
     * whoami and list_tool_surface ARE published by tools/list but are deliberately NOT in
     * liveToolNames() — they are assembled as transport built-ins, outside the grant catalog
     * the live surface is derived from. So they are a genuine, intended exception to
     * "published implies live", and a liveness conjunct that did not exempt them would refuse
     * two tools that tools/list had just advertised. toolAllowed() exempts them before the
     * grant check for exactly this reason; test_the_transport_built_ins_remain_callable pins
     * that they survive.
     */
    public function test_everything_published_to_a_token_is_callable_by_that_token(): void
    {
        $token = McpConfig::rotateStaffToken();

        $published = array_column($this->listTools($token), 'name');
        $this->assertNotEmpty($published, 'precondition: tools/list published nothing');

        $live = McpToolSurface::liveToolNames();
        $builtIns = ['whoami', 'list_tool_surface'];

        foreach (array_diff($published, $builtIns) as $name) {
            $this->assertContains(
                $name,
                $live,
                "tools/list published {$name} but it is not in the live surface — publish and dispatch ".
                'would disagree again, in the opposite direction'
            );
        }

        // The exemption must stay narrow. If a future built-in is added to the published set
        // without landing in the live surface, it should show up here as a decision to make
        // rather than be silently absorbed by the array_diff above.
        foreach ($builtIns as $builtIn) {
            $this->assertContains($builtIn, $published, "precondition: {$builtIn} is expected to be published");
        }
    }

    // -----------------------------------------------------------------------
    // 3. THE TRANSPORT BUILT-INS MUST SURVIVE THE CONJUNCT
    // -----------------------------------------------------------------------

    /**
     * whoami and list_tool_surface are always-callable by design — a token that cannot see
     * its own scope cannot self-heal, and list_tool_surface exists precisely to tell a caller
     * what is NOT available. They are assembled as transport built-ins rather than catalog
     * entries, so a liveness conjunct that forgets to exempt them would take out the very
     * tools an operator uses to diagnose a refusal.
     */
    public function test_the_transport_built_ins_remain_callable(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: [self::GRANTED_BUT_NOT_LIVE], label: 'opsbot');

        foreach (['whoami', 'list_tool_surface'] as $builtIn) {
            $text = $this->resultText($this->callTool($token, $builtIn));

            $this->assertStringNotContainsString(
                'not allowed for this token',
                $text,
                "the transport built-in {$builtIn} was refused — a token must always be able to ".
                'ask who it is and what it can do: '.$text
            );
        }
    }

    // -----------------------------------------------------------------------
    // 4. THE OFF=OFF TIE-IN — this is what psa-wzjzz cannot deliver alone
    // -----------------------------------------------------------------------

    /**
     * Charlie's ruling extended OFF=OFF to MCP: "if the integration's master switch is off,
     * that should disable that integration's MCP tools too." psa-wzjzz takes a switched-off
     * vendor's tools out of the LIVE surface; on its own that only HIDES them from tools/list,
     * because a token holding the grant still reaches the executor. This is the half that
     * actually disables them.
     *
     * Asserted with the switch written explicitly rather than left at its default, so the
     * test states the operator's action instead of relying on which way the default points.
     */
    public function test_switching_an_integration_off_makes_its_granted_tools_uncallable(): void
    {
        Setting::setValue('ninja_enabled', '0');
        Setting::setValue('ninja_client_id', 'ninja-client-id');
        Setting::setEncrypted('ninja_client_secret', 'ninja-client-secret');

        User::factory()->create();

        $token = McpConfig::rotateStaffToken(allowedTools: [self::GRANTED_BUT_NOT_LIVE], label: 'opsbot');

        $text = $this->resultText($this->callTool($token, self::GRANTED_BUT_NOT_LIVE));

        $this->assertStringNotContainsString(
            self::EXECUTOR_WAS_REACHED,
            $text,
            'the vendor master switch is OFF and a granted token still reached the Ninja executor — '
            .'the tools are hidden, not disabled: '.$text
        );
    }

    // -----------------------------------------------------------------------
    // 5. THE PORTAL SERVER — the same split, closed before it can be reached
    // -----------------------------------------------------------------------

    /**
     * The portal server had the same publish-vs-dispatch split as the staff server:
     * listTools() dropped tools whose input schema failed validation, while callTool()
     * gated on handles(), which read the UNFILTERED list. A dropped tool stayed callable.
     *
     * It was LATENT, never exploitable — all six shipped schemas validate and tools() is
     * static PHP literals, so nothing at runtime can invalidate one. It is fixed anyway
     * because this surface carries WRITES (create_ticket, add_my_ticket_reply): the day a
     * seventh tool lands with a malformed schema, the hazard becomes a written ticket row.
     *
     * This pins the property that makes it safe — the two paths read ONE source — rather
     * than the current happy accident that nothing is droppable.
     */
    public function test_the_portal_publish_and_dispatch_paths_read_one_source(): void
    {
        $publishable = array_column(PortalMcpToolDefinitions::publishableTools(), 'name');

        $this->assertNotEmpty($publishable, 'precondition: the portal must publish something');

        // handles() — the call path's gate — must accept exactly what is publishable.
        foreach ($publishable as $name) {
            $this->assertTrue(
                PortalMcpToolDefinitions::handles($name),
                "{$name} is publishable but the call path would reject it"
            );
        }

        // ...and nothing beyond it. If a tool is ever dropped from publication, handles()
        // must drop it too, or it becomes unpublished-but-callable on a surface with writes.
        foreach (PortalMcpToolDefinitions::names() as $name) {
            $this->assertContains(
                $name,
                $publishable,
                "the call path accepts {$name} but it is not publishable — the portal split is back"
            );
        }
    }
}
