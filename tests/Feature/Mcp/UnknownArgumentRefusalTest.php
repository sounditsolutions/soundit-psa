<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Support\McpConfig;
use App\Support\McpToolSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * An argument the tool does not declare is REFUSED, not ignored (card
 * Z4PdzEZ1, PSA ticket T-22846).
 *
 * The defect these pin is not a crash: it is a confident wrong answer. A read
 * tool handed an undeclared filter used to drop it and run UNFILTERED, so a
 * caller mistake came back as well-formed data. That shape produced a false
 * bug report against the wrong component, and it is the same shape that would
 * let someone cite "no matching records found" as evidence of absence when
 * nothing was ever filtered.
 *
 * The controls that matter here are the NEGATIVE ones: a guard that refuses
 * everything would pass the first test and be useless. Every refusal control
 * below is paired with a call that must still succeed.
 */
class UnknownArgumentRefusalTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        $grants = [];
        foreach (McpToolSurface::liveToolNames() as $name) {
            $grants[] = $name;
            $grants[] = $name.':immediate';
        }

        return McpConfig::rotateStaffToken(allowedTools: $grants, label: 'unknown-arg');
    }

    /** @param array<string, mixed> $arguments */
    private function invoke(string $token, string $tool, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ]);
    }

    /**
     * THE DEFECT ITSELF, in the shape the ticket hit: a fielded search handed
     * a free-text argument that does not exist on it.
     */
    public function test_an_undeclared_argument_on_a_search_tool_is_refused(): void
    {
        $token = $this->token();

        $response = $this->invoke($token, 'find_clients', ['query' => 'acme', 'bogus_filter' => 'zzz']);

        $text = (string) $response->json('result.content.0.text');

        $this->assertTrue((bool) $response->json('result.isError'),
            'an undeclared argument must be an error, not a result');
        $this->assertStringContainsString('bogus_filter', $text,
            'the refusal must NAME the offending argument, or the caller cannot self-correct');
        $this->assertStringContainsString('REFUSED, not ignored', $text,
            'the caller must be told nothing ran, so this is not read as an empty result');
    }

    /**
     * THE CONTROL THAT KEEPS THE TEST ABOVE HONEST. A guard that refused every
     * call would satisfy the refusal assertions and break the product.
     */
    public function test_a_fully_declared_call_still_succeeds(): void
    {
        $token = $this->token();

        $response = $this->invoke($token, 'find_clients', ['query' => 'acme']);

        $this->assertFalse((bool) $response->json('result.isError'),
            'positive control: a call using only declared arguments must still run');
    }

    /**
     * The boundary consumes client_id itself and injects it into the published
     * schema, so a caller following tools/list must never be refused for it.
     */
    public function test_the_boundarys_own_injected_arguments_are_accepted(): void
    {
        $client = Client::factory()->create();
        $token = $this->token();

        $response = $this->invoke($token, 'find_persons', ['client_id' => $client->id, 'query' => 'someone']);

        $this->assertFalse((bool) $response->json('result.isError'),
            'client_id is injected into the published schema and must be accepted');
    }

    /**
     * A tool whose properties map is EMPTY declares no arguments, and an
     * argument handed to it is refused like any other.
     *
     * The definitions render an empty map as `(object) []` so it encodes as {}
     * rather than []. That is an encoding artifact, not a contract, and
     * listTools() normalises it with `(array)` before publishing. Read raw it
     * failed `is_array` and the tool fell out of the guard entirely - fail
     * open by ACCIDENT, on reads (whoami, get_queue_stats, get_client,
     * wiki_list_pages), which is the surface this change exists to protect.
     */
    public function test_a_tool_publishing_an_empty_properties_map_still_refuses(): void
    {
        $token = $this->token();

        $response = $this->invoke($token, 'whoami', ['bogus_filter' => 'zzz']);

        $text = (string) $response->json('result.content.0.text');

        $this->assertTrue((bool) $response->json('result.isError'),
            'an empty properties map is a contract ("no arguments"), not an unresolved schema');
        $this->assertStringContainsString('bogus_filter', $text,
            'the refusal must NAME the offending argument, or the caller cannot self-correct');
        $this->assertStringContainsString('accepts no arguments', $text,
            'the zero-argument arm of the message must be reachable, not dead code');
    }

    /** Paired positive control: the same tool, called correctly, still runs. */
    public function test_a_zero_argument_tool_still_runs_when_called_with_none(): void
    {
        $token = $this->token();

        $response = $this->invoke($token, 'whoami', []);

        $this->assertFalse((bool) $response->json('result.isError'),
            'positive control: a zero-argument tool must still answer an empty call');
    }

    /**
     * Fail OPEN on ignorance - the bound this guard keeps. A name no assembly
     * here resolves yields null ("do not judge this call") and is never
     * refused. Distinct from the empty map above, which IS a contract.
     */
    public function test_an_unresolvable_schema_is_never_judged(): void
    {
        $controller = app(\App\Http\Controllers\Api\McpStaffController::class);
        $method = new \ReflectionMethod($controller, 'declaredArgumentNamesOrNull');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($controller, 'no_such_tool_on_this_boundary'),
            'an unresolved schema must stay null, so the guard stands off rather than refusing');
    }

    /**
     * `staged` is the boundary's key only where the boundary can stage.
     *
     * callTool() unsets it on the alias and stageable paths and NOWHERE else,
     * so on a non-stageable read it arrives as a live undeclared key. Dropping
     * it silently is this defect on its highest-stakes argument: the caller
     * records that it filed a proposal for human approval, and the call has
     * already run.
     */
    public function test_staged_on_a_non_stageable_tool_is_refused(): void
    {
        $token = $this->token();

        $response = $this->invoke($token, 'find_clients', ['query' => 'acme', 'staged' => true]);

        $text = (string) $response->json('result.content.0.text');

        $this->assertTrue((bool) $response->json('result.isError'),
            'find_clients cannot stage anything, so `staged` must be refused, not dropped');
        $this->assertStringContainsString('staged', $text,
            'the refusal must name `staged`, so the caller learns nothing was held');
    }

    /**
     * The message is this change's deliverable, so it must quote the PUBLISHED
     * contract exactly. Naming a key the caller was never handed steers the
     * retry back into the silent-drop shape: client_id on a general tool is
     * accepted, unset by the boundary, never seen by the executor - a second
     * invisible no-op, recommended by the refusal itself.
     */
    public function test_the_refusal_names_only_what_the_tool_publishes(): void
    {
        $token = $this->token();

        $listed = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
            ])->json('result.tools') ?? [];

        $published = null;
        foreach ($listed as $tool) {
            if ($tool['name'] === 'find_clients') {
                $published = array_keys($tool['inputSchema']['properties'] ?? []);
            }
        }

        $this->assertNotNull($published, 'precondition: find_clients must be on the published surface');
        $this->assertNotContains('client_id', $published,
            'precondition: find_clients is general, so tools/list injects no client_id');

        $text = (string) $this->invoke($token, 'find_clients', ['bogus_filter' => 'zzz'])
            ->json('result.content.0.text');

        foreach ($published as $property) {
            $this->assertStringContainsString($property, $text,
                'every argument the server publishes must appear in the refusal');
        }
        $this->assertStringNotContainsString('client_id', $text,
            'the refusal must not advertise an argument this tool does not publish');
    }

    /**
     * Tools that already refuse with a message teaching the semantics of the
     * mistake must keep it. The generic guard is strictly a fallback, and
     * displacing a better message with a worse one is a regression.
     */
    public function test_a_self_validating_write_tool_keeps_its_own_message(): void
    {
        \App\Models\Setting::setValue('cipp_enabled', '1');
        \App\Models\Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        \App\Models\Setting::setValue('cipp_tenant_id', 'tenant-1');
        \App\Models\Setting::setValue('cipp_client_id', 'client-1');
        \App\Models\Setting::setEncrypted('cipp_client_secret', 'secret');

        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.onmicrosoft.com']);
        $token = $this->token();

        $response = $this->invoke($token, 'cipp_create_user', [
            'client_id' => $client->id,
            'username' => 'a',
            'display_name' => 'A',
            'confirm_upn' => 'a@example.test',
            'reason' => 'r',
            'tenantFilter' => 'attacker.example',
        ]);

        $text = (string) $response->json('result.content.0.text');

        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('upstream CIPP identifiers are not accepted', $text,
            'the executor\'s semantic refusal must not be displaced by the generic guard');
    }

    /**
     * Where dropping an undeclared key IS the security contract, it must keep
     * dropping it.
     *
     * send_reply deliberately does not declare `to`: a prompt-injected
     * recipient must be IGNORED, not obeyed, and not converted into a failed
     * call. This is the boundary between the accident this change fixes (a
     * read tool dropping a filter) and a deliberate containment that must not
     * be disturbed. Written because widening the guard to cover it was the
     * first thing I tried, and the full suite caught it.
     */
    /**
     * The two CIPP predicates read alike and cover DIFFERENT sets.
     *
     * App\Models\CippMcpTool::handles() asks the registry for DB-backed
     * dynamic tools; App\Services\Cipp\CippMcpToolRelay::handles() asks its
     * own static TOOL_MAP. Exempting only the first left every relay-mapped
     * tool to the generic guard, which fired first and DISPLACED the relay's
     * specific "Unsupported CIPP MCP argument(s): ..." refusal with a generic
     * one - a strictly worse message on a tool that already refuses correctly.
     * The FULL suite caught it; a --filter=Mcp run stayed green.
     *
     * Names the relay directly so the exemption cannot be dropped without a
     * failure that says why - and pins its CONDITION, because the relay's
     * refusal only exists while the relay runs. handles() is a static map;
     * isMcpRelayEnabled() is what decides whether CippMcpToolRelay ever sees
     * the call at all.
     */
    public function test_a_relay_mapped_cipp_tool_keeps_its_own_refusal(): void
    {
        $relayMapped = null;
        foreach (['cipp_list_users', 'cipp_list_sign_ins'] as $candidate) {
            if (\App\Services\Cipp\CippMcpToolRelay::handles($candidate)) {
                $relayMapped = $candidate;
                break;
            }
        }

        $this->assertNotNull($relayMapped, 'precondition: the relay must map at least one tool');

        \App\Models\Setting::setValue('cipp_enabled', '1');
        \App\Models\Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        \App\Models\Setting::setValue('cipp_tenant_id', 'tenant-1');
        \App\Models\Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        \App\Models\Setting::setEncrypted('cipp_mcp_client_secret', 'mcp-secret');
        \App\Models\Setting::setValue('cipp_mcp_enabled', '1');

        $controller = app(\App\Http\Controllers\Api\McpStaffController::class);
        $method = (new \ReflectionClass($controller))->getMethod('selfValidatingTool');
        $method->setAccessible(true);

        $this->assertTrue(
            (bool) $method->invoke($controller, $relayMapped, false),
            $relayMapped.' is refused by the CIPP relay itself; the generic guard must defer '
                .'or it replaces a specific refusal with a worse one'
        );

        // The paired NEGATIVE half, and the whole reason this arm carries a
        // config conjunct: with the sub-switch off the relay never runs, so
        // there is no better message to defer TO and the guard must not stand
        // down. Without this half the assertion above passes on a predicate
        // that exempts the tool in a state where nothing refuses.
        \App\Models\Setting::setValue('cipp_mcp_enabled', '0');

        $this->assertFalse(
            (bool) $method->invoke($controller, $relayMapped, false),
            'with cipp_mcp_enabled off the call goes straight to CippClient, which inspects '
                .'no keys; deferring there is a silent drop on a read'
        );
    }

    /**
     * The config conjunct above must not be able to take the boundary down
     * with it.
     *
     * isMcpRelayEnabled() is two uncached Setting reads plus a
     * Crypt::decryptString of cipp_mcp_client_secret, so a rotated APP_KEY -
     * or a settings table restored from another environment - makes it THROW,
     * not return false. selfValidatingTool() runs before dispatch and OUTSIDE
     * callTool()'s try, so an escape is a -32603 on that call.
     *
     * One assertion per half of the guard. ORDER: a general read must never
     * reach the config at all, because the static map answers first - that is
     * what keeps the fault off whoami / list_tool_surface / search_tools,
     * which toolAllowed() passes before the liveness check precisely so a
     * caller can find out why something was refused. CATCH: on a relay-mapped
     * name the relay is not going to run, so the generic guard applies rather
     * than the call failing.
     *
     * Asserted on the predicate rather than over HTTP deliberately:
     * McpToolSurface::liveClientScopedToolDefinitions() reads
     * isMcpRelayEnabled() unguarded too, one frame EARLIER on the same
     * request via toolAllowed()'s liveness conjunct. That is a separate gap,
     * outside this change, and a behavioural assertion here would be
     * measuring it instead of this arm.
     */
    public function test_a_cipp_config_fault_cannot_take_down_the_generic_guard(): void
    {
        \App\Models\Setting::setValue('cipp_enabled', '1');
        \App\Models\Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        \App\Models\Setting::setValue('cipp_tenant_id', 'tenant-1');
        \App\Models\Setting::setValue('cipp_mcp_client_id', 'mcp-client');
        \App\Models\Setting::setValue('cipp_mcp_enabled', '1');
        // setValue, NOT setEncrypted: a stored value this APP_KEY cannot open,
        // exactly as a key rotation leaves one. getEncrypted() throws on read.
        \App\Models\Setting::setValue('cipp_mcp_client_secret', 'not-a-payload-this-key-can-open');

        $controller = app(\App\Http\Controllers\Api\McpStaffController::class);
        $method = (new \ReflectionClass($controller))->getMethod('selfValidatingTool');
        $method->setAccessible(true);

        $this->assertFalse(
            (bool) $method->invoke($controller, 'find_clients', false),
            'a general read must not evaluate the CIPP relay config at all; the static map '
                .'answering first is what keeps a decrypt fault off this path'
        );

        $this->assertFalse(
            (bool) $method->invoke($controller, 'cipp_list_users', false),
            'a relay-mapped tool whose config cannot be read is not self-validating: the '
                .'relay will not run, so the generic guard must apply rather than throw'
        );
    }

    /**
     * The behavioural half of the arm above, in the state the unconditional
     * exemption used to cover: CIPP REST on, MCP relay sub-switch off - the
     * documented fallback (psa-dbrw / psa-idii).
     *
     * cipp_list_users is published under isEnabled() && isConfigured(), a
     * STRICTLY WIDER predicate than isMcpRelayEnabled(), so it is live here.
     * cippDispatch() gets null from cippMcpRelay() and takes the direct
     * CippClient path, which never calls unknownArguments(). An undeclared
     * graphFilter would come back as the FULL enabled+disabled user list
     * rendered as a filtered answer - this card's originating defect.
     */
    public function test_a_cipp_read_refuses_an_undeclared_filter_when_the_relay_is_off(): void
    {
        \App\Models\Setting::setValue('cipp_enabled', '1');
        \App\Models\Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        \App\Models\Setting::setValue('cipp_tenant_id', 'tenant-1');
        \App\Models\Setting::setValue('cipp_client_id', 'client-1');
        \App\Models\Setting::setEncrypted('cipp_client_secret', 'secret');
        // cipp_mcp_enabled deliberately left unset: REST live, relay off.

        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.example']);
        $token = $this->token();

        $cipp = \Mockery::mock(\App\Services\Cipp\CippClient::class);
        $cipp->shouldNotReceive('get');
        $this->app->instance(\App\Services\Cipp\CippClient::class, $cipp);

        $response = $this->invoke($token, 'cipp_list_users', [
            'client_id' => $client->id,
            'graphFilter' => 'accountEnabled eq false',
        ]);

        $text = (string) $response->json('result.content.0.text');

        $this->assertTrue((bool) $response->json('result.isError'), $text);
        $this->assertStringContainsString('graphFilter', $text,
            'the refusal must NAME the dropped filter, or an unfiltered list reads as a filtered one');
        $this->assertStringContainsString('REFUSED, not ignored', $text);
    }

    /** Paired positive control: the same read, declared, still reaches CIPP. */
    public function test_a_cipp_read_still_runs_on_the_direct_path_with_declared_arguments_only(): void
    {
        \App\Models\Setting::setValue('cipp_enabled', '1');
        \App\Models\Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        \App\Models\Setting::setValue('cipp_tenant_id', 'tenant-1');
        \App\Models\Setting::setValue('cipp_client_id', 'client-1');
        \App\Models\Setting::setEncrypted('cipp_client_secret', 'secret');

        $client = Client::factory()->create(['cipp_tenant_domain' => 'acme.example']);
        $token = $this->token();

        $cipp = \Mockery::mock(\App\Services\Cipp\CippClient::class);
        $cipp->shouldReceive('readDeadline')->andReturn(120.0);
        $cipp->shouldReceive('get')->andReturn([
            [
                'id' => 'user-1',
                'userPrincipalName' => 'alex@acme.example',
                'displayName' => 'Alex Acme',
                'accountEnabled' => true,
            ],
        ]);
        $this->app->instance(\App\Services\Cipp\CippClient::class, $cipp);

        $response = $this->invoke($token, 'cipp_list_users', ['client_id' => $client->id]);

        $this->assertFalse((bool) $response->json('result.isError'),
            'positive control: with the relay off a declared CIPP read must still run - '
                .(string) $response->json('result.content.0.text'));
    }

    /**
     * The exemption list is a claim about the EXECUTOR, and the operator
     * bridge never made it: OperatorBridgeToolExecutor::findStaff() reads
     * `query` and `limit`, refuses nothing, and find_staff's own description
     * advertises is_active in the result shape - so an is_active filter is
     * exactly the mistake a caller makes. Dropped, the caller cannot tell
     * "no inactive staff matched" from "the filter was discarded".
     */
    public function test_a_read_on_the_operator_bridge_refuses_an_undeclared_filter(): void
    {
        $token = $this->token();

        $response = $this->invoke($token, 'find_staff', ['query' => 'smith', 'is_active' => true]);

        $text = (string) $response->json('result.content.0.text');

        $this->assertTrue((bool) $response->json('result.isError'),
            'find_staff declares only query and limit, and nothing downstream refuses the rest');
        $this->assertStringContainsString('is_active', $text,
            'the refusal must NAME the dropped filter, or the caller reads the result as filtered');
        $this->assertStringContainsString('REFUSED, not ignored', $text);
    }

    /** Paired positive control: the declared search still answers. */
    public function test_an_operator_bridge_read_still_runs_with_only_declared_arguments(): void
    {
        $token = $this->token();

        $response = $this->invoke($token, 'find_staff', ['query' => 'smith', 'limit' => 5]);

        $this->assertFalse((bool) $response->json('result.isError'),
            'positive control: find_staff must still answer a fully declared call');
    }

    public function test_a_deliberate_accept_and_ignore_contract_is_not_disturbed(): void
    {
        $token = $this->token();

        $listed = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
            ])->json('result.tools') ?? [];

        $sendReply = null;
        foreach ($listed as $tool) {
            if ($tool['name'] === 'send_reply') {
                $sendReply = $tool;
            }
        }

        $this->assertNotNull($sendReply, 'precondition: send_reply must be on the published surface');
        $this->assertArrayNotHasKey('to', $sendReply['inputSchema']['properties'] ?? [],
            'send_reply must never advertise a caller-supplied recipient');

        // The paired half - that supplying `to` anyway is ignored rather than
        // refused, and the reply still lands as a held run - is pinned by
        // ChetSendReplyTest. This control exists so that widening the generic
        // guard over this tool fails HERE, next to the reason, rather than in
        // a distant suite.
        $this->assertTrue(
            (new \ReflectionMethod(\App\Http\Controllers\Api\McpStaffController::class, 'selfValidatingTool'))
                ->getDeclaringClass()->getConstant('ACCEPT_AND_IGNORE_TOOLS') !== false,
            'the accept-and-ignore set must remain declared'
        );
        $this->assertContains('send_reply',
            (new \ReflectionClass(\App\Http\Controllers\Api\McpStaffController::class))
                ->getConstant('ACCEPT_AND_IGNORE_TOOLS'),
            'send_reply must stay exempt from the generic unknown-argument refusal');
    }

    /**
     * Every argument the server PUBLISHES must be one the server ACCEPTS.
     *
     * This is the control against the guard drifting away from the advertised
     * contract: it sweeps the entire live surface rather than a sample, and it
     * states its own denominator so a zero cannot be read as coverage of
     * nothing.
     */
    public function test_every_published_argument_is_accepted_across_the_whole_surface(): void
    {
        $token = $this->token();

        $listed = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
            ])->json('result.tools') ?? [];

        $controller = app(\App\Http\Controllers\Api\McpStaffController::class);
        $method = new \ReflectionMethod($controller, 'declaredArgumentNamesOrNull');
        $method->setAccessible(true);

        $rejected = [];
        $checked = 0;

        foreach ($listed as $tool) {
            $declared = $method->invoke($controller, (string) $tool['name']);

            if ($declared === null) {
                continue; // schema unresolvable: guard stands off, nothing to check
            }

            foreach (array_keys($tool['inputSchema']['properties'] ?? []) as $property) {
                $checked++;
                if (! in_array($property, $declared, true)) {
                    $rejected[] = $tool['name'].'.'.$property;
                }
            }
        }

        $this->assertGreaterThan(200, $checked,
            'denominator control: the sweep must actually cover the surface, '
            .'or an empty $rejected proves nothing');
        $this->assertSame([], $rejected,
            'the server must never refuse an argument it publishes');
    }

    /**
     * The mirror of the sweep above, on the MESSAGE side: every argument the
     * server PUBLISHES must also be NAMED by that tool's refusal.
     *
     * The message drops the boundary's own keys, because naming one reads as
     * an invitation and lands the retry back in the silent-drop shape. But a
     * key is the BOUNDARY'S only where the tool did not declare it itself.
     * The general PSA reads declare client_id in their own properties -
     * REQUIRED on get_contract and list_client_contracts, an optional filter
     * on list_mislinked_assets and list_invoices, where an ABSENT client_id
     * means a fleet-wide sweep. Dropping it by name concealed a required
     * argument on one and steered a retry across the tenant fence on the
     * other, which is worse than a failed call.
     */
    public function test_the_refusal_names_every_argument_the_tool_publishes(): void
    {
        $token = $this->token();

        $listed = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
            ])->json('result.tools') ?? [];

        $controller = app(\App\Http\Controllers\Api\McpStaffController::class);
        $quotedNames = new \ReflectionMethod($controller, 'declaredArgumentNames');
        $quotedNames->setAccessible(true);
        $selfValidating = new \ReflectionMethod($controller, 'selfValidatingTool');
        $selfValidating->setAccessible(true);
        $injectsClientId = new \ReflectionMethod($controller, 'publishesClientId');
        $injectsClientId->setAccessible(true);

        $missing = [];
        $checked = 0;
        $declaresOwnClientId = [];

        foreach ($listed as $tool) {
            $name = (string) $tool['name'];

            if ($selfValidating->invoke($controller, $name, false)) {
                continue; // its own validator answers; this message never runs
            }

            $published = array_keys($tool['inputSchema']['properties'] ?? []);
            $quoted = $quotedNames->invoke($controller, $name);

            if (in_array('client_id', $published, true) && ! $injectsClientId->invoke($controller, $name)) {
                $declaresOwnClientId[] = $name;
            }

            foreach ($published as $property) {
                $checked++;
                if (! in_array($property, $quoted, true)) {
                    $missing[] = $name.'.'.$property;
                }
            }
        }

        $this->assertGreaterThan(50, $checked,
            'denominator control: the sweep must cover the guarded surface, '
            .'or an empty $missing proves nothing');
        $this->assertNotSame([], $declaresOwnClientId,
            'denominator control: at least one guarded tool must declare client_id in its OWN '
            .'schema rather than have it injected, or this control never exercises the case');
        $this->assertSame([], $missing,
            'the refusal must name every argument the server published for that tool');
    }
}
