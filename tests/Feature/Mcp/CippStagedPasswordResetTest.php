<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Support\McpConfig;
use App\Support\McpToolModes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Staged path for cipp_reset_user_password (psa-g4y9f part 2).
 *
 * THE HOLE. Password reset was the ONLY CIPP write capability with no staged twin:
 * 21 others were in STAGED_TO_DIRECT (including wipe_device), it was not, and
 * StaffCippWriteToolExecutor::execute() special-cased it ahead of all staged dispatch
 * and called the vendor directly. So a ':staged' grant on it was not merely ignored —
 * there was nothing to dispatch to. A live token held a bare immediate grant.
 *
 * WHY THE SPECIAL CASE STAYS (reshaped, not deleted). It is a GUARD, not the bug:
 * a password reset is NON-IDEMPOTENT, so it deliberately skips executeDirect()'s
 * alreadyExecuted() short-circuit — a second reset must mint a NEW password, not
 * return a stale "already done". Deleting the special case and falling through to
 * executeDirect() would make a repeat reset answer {success, idempotent} with NO
 * PASSWORD: a silent failure on a credential-issuing operation, worse than the hole.
 *
 * THE SECURITY WIN. Staged, the credential surfaces to the APPROVING HUMAN in the
 * cockpit and never to the agent. Today the agent receives it directly.
 */
class CippStagedPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const TOOL = 'cipp_reset_user_password';

    private const STAGED = 'cipp_stage_reset_user_password';

    private function configureCipp(): void
    {
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'client-1');
        Setting::setEncrypted('cipp_client_secret', 'secret');
    }

    private function configureAiActor(): User
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

    /** @return array{client: Client, contact: Person, ticket: Ticket} */
    private function cippFixture(): array
    {
        $client = Client::factory()->create([
            'name' => 'Acme',
            'cipp_tenant_domain' => 'acme.onmicrosoft.com',
        ]);

        $contact = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Alex',
            'last_name' => 'Acme',
            'email' => 'alex@acme.example',
            'cipp_user_id' => 'user-123',
            'cipp_upn' => 'alex@acme.example',
            'is_active' => true,
        ]);

        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $contact->id,
            'subject' => 'User locked out, needs a password reset',
        ]);

        return compact('client', 'contact', 'ticket');
    }

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

    /** @return array<string, mixed> */
    private function decoded(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    // ── the capability now HAS a staged path ──────────────────────────────────

    public function test_password_reset_is_stageable_and_pairs_with_its_staged_alias(): void
    {
        $this->assertTrue(McpToolModes::isStageable(self::TOOL));
        $this->assertSame(self::STAGED, McpToolModes::stagedInternalFor(self::TOOL));
        $this->assertSame(self::TOOL, McpToolModes::canonicalForAlias(self::STAGED));
    }

    public function test_a_staged_call_holds_the_action_and_never_touches_the_vendor(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $vendor = Mockery::mock(CippRestWriteClient::class);
        $vendor->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $vendor);

        $token = McpConfig::rotateStaffToken(allowedTools: [self::TOOL.':staged'], label: 'opsbot');

        $response = $this->callTool($token, self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['contact']->id,
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'User locked out; reset requested on the ticket.',
            'staged' => true,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $run = TechnicianRun::query()->where('action_type', self::STAGED)->first();
        $this->assertNotNull($run, 'a staged reset must create a held TechnicianRun');
        $this->assertSame($fixture['client']->id, (int) $run->client_id);

        // The proposal must not carry a credential — none exists yet.
        $result = $this->decoded($response);
        $this->assertArrayNotHasKey('temporary_password', $result);
    }

    /**
     * A staged-only grant must AUTO-DOWNGRADE an immediate call rather than executing
     * it. This is the behaviour that actually closes the live exposure: the grant says
     * staged, so the vendor is never called even when the agent asks for immediate.
     */
    public function test_a_staged_only_grant_downgrades_an_immediate_call_instead_of_executing(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $vendor = Mockery::mock(CippRestWriteClient::class);
        $vendor->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $vendor);

        $token = McpConfig::rotateStaffToken(allowedTools: [self::TOOL.':staged'], label: 'opsbot');

        $response = $this->callTool($token, self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['contact']->id,
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Agent asked for immediate; grant says staged.',
            'staged' => false,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertNotNull(
            TechnicianRun::query()->where('action_type', self::STAGED)->first(),
            'an immediate call under a staged-only grant must be held, not executed',
        );
    }

    // ── approval executes it, and the credential goes to the HUMAN ────────────

    public function test_approving_the_held_reset_executes_it_and_returns_the_password_to_the_approver(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $staging = Mockery::mock(CippRestWriteClient::class);
        $staging->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $staging);

        $token = McpConfig::rotateStaffToken(allowedTools: [self::TOOL.':staged'], label: 'opsbot');
        $this->callTool($token, self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['contact']->id,
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'User locked out; reset requested on the ticket.',
            'staged' => true,
        ])->assertOk();

        $run = TechnicianRun::query()->where('action_type', self::STAGED)->firstOrFail();

        // Only NOW does the vendor get called — on human approval.
        $approving = Mockery::mock(CippRestWriteClient::class);
        $approving->shouldReceive('resetUserPassword')->once()
            ->andReturn(['success' => true, 'status' => 200, 'body' => [
                'Results' => ['copyField' => 'Temp-Pass-123!', 'state' => 'success'],
            ]]);
        $this->app->instance(CippRestWriteClient::class, $approving);

        // postJson, NOT post: the JSON path is the one that actually delivers the
        // one-time secret. The redirect fallback discards it, so asserting against
        // that would have proven nothing about credential delivery (arch review
        // psa-oqfc1 R1 caught exactly that in my first version of this test).
        $approver = User::factory()->create();
        $approval = $this->actingAs($approver)->postJson(route('cockpit.approve', $run));

        $this->assertTrue((bool) $approval->json('ok'));
        $this->assertSame('executed', $approval->json('status'));

        // The credential reaches the APPROVER here...
        $this->assertSame('Temp-Pass-123!', $approval->json('secret'));
        // ...and nowhere else: not in the human-readable message, not on the run.
        $this->assertStringNotContainsString('Temp-Pass-123!', (string) $approval->json('message'));

        $run->refresh();
        $this->assertSame(TechnicianRunState::Done->value, (string) $run->state->value);
        $this->assertStringNotContainsString('Temp-Pass-123!', json_encode($run->proposed_meta) ?: '');
        $this->assertStringNotContainsString('Temp-Pass-123!', (string) $run->proposed_content);
    }

    /**
     * SECURITY review (psa-smh26) R1, second gate: a recent reset for the same target
     * must block approval of a held one, so a duplicate proposal staged from another
     * ticket cannot mint a second credential moments later.
     */
    public function test_approval_is_refused_while_the_target_is_in_reset_cooldown(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $run = $this->stageAndGetRun($fixture);

        // A direct reset for this same person happens before the held one is approved.
        $direct = Mockery::mock(CippRestWriteClient::class);
        $direct->shouldReceive('resetUserPassword')->once()
            ->andReturn(['success' => true, 'status' => 200, 'body' => [
                'Results' => ['copyField' => 'First-Pass-1!', 'state' => 'success'],
            ]]);
        $this->app->instance(CippRestWriteClient::class, $direct);

        $this->callTool(
            McpConfig::rotateStaffToken(allowedTools: [self::TOOL.':immediate'], label: 'opsbot'),
            self::TOOL,
            [
                'client_id' => $fixture['client']->id,
                'person_id' => $fixture['contact']->id,
                'confirm_upn' => 'alex@acme.example',
                'reason' => 'Reset directly first.',
                'staged' => false,
            ],
        )->assertOk();

        // Now approving the held proposal must be refused, not mint a second password.
        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $approver = User::factory()->create();
        $approval = $this->actingAs($approver)->postJson(route('cockpit.approve', $run));

        $this->assertFalse((bool) $approval->json('ok'));
        $this->assertNull($approval->json('secret'));
    }

    /**
     * NON-IDEMPOTENCY, preserved on the approve path. The direct executor deliberately
     * skips the alreadyExecuted() short-circuit because a second reset must mint a new
     * password. Approving the SAME held run twice must not run the vendor twice either
     * — the run is claimed once — and must never answer with a stale success.
     */
    public function test_approving_the_same_held_reset_twice_does_not_reset_twice(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $staging = Mockery::mock(CippRestWriteClient::class);
        $staging->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $staging);

        $token = McpConfig::rotateStaffToken(allowedTools: [self::TOOL.':staged'], label: 'opsbot');
        $this->callTool($token, self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['contact']->id,
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Reset once.',
            'staged' => true,
        ])->assertOk();

        $run = TechnicianRun::query()->where('action_type', self::STAGED)->firstOrFail();

        $approving = Mockery::mock(CippRestWriteClient::class);
        $approving->shouldReceive('resetUserPassword')->once()
            ->andReturn(['success' => true, 'status' => 200, 'body' => [
                'Results' => ['copyField' => 'Temp-Pass-123!', 'state' => 'success'],
            ]]);
        $this->app->instance(CippRestWriteClient::class, $approving);

        $approver = User::factory()->create();
        $this->actingAs($approver)->post(route('cockpit.approve', $run));
        // Second approval of the same run: the claim is already taken, so no second
        // upstream call. Mockery's ->once() is the assertion.
        $this->actingAs($approver)->post(route('cockpit.approve', $run));
    }

    /**
     * Stage a reset and return the held run, with the vendor mocked to refuse any call.
     */
    private function stageAndGetRun(array $fixture): TechnicianRun
    {
        $staging = Mockery::mock(CippRestWriteClient::class);
        $staging->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $staging);

        $token = McpConfig::rotateStaffToken(allowedTools: [self::TOOL.':staged'], label: 'opsbot');
        $this->callTool($token, self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['contact']->id,
            'ticket_id' => $fixture['ticket']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Held for approval.',
            'staged' => true,
        ])->assertOk();

        return TechnicianRun::query()->where('action_type', self::STAGED)->firstOrFail();
    }

    /**
     * SECURITY review (psa-smh26) R1. A proposal can sit held for hours. If the
     * kill-switch is engaged in the meantime, approving it must NOT punch through the
     * emergency stop — and this is a credential-changing operation, so it is the worst
     * one to let through. The gates the STAGING call passed are not a substitute for
     * re-checking at approval time.
     */
    public function test_approval_is_refused_while_the_kill_switch_is_engaged(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $run = $this->stageAndGetRun($fixture);

        // Engaged AFTER the proposal was staged.
        Setting::setValue('technician_kill_switch', '1');

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('resetUserPassword');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $approver = User::factory()->create();
        $this->actingAs($approver)->post(route('cockpit.approve', $run));

        // The proposal must remain live rather than being consumed by the refusal.
        $run->refresh();
        $this->assertNotSame(TechnicianRunState::Done->value, (string) $run->state->value);
    }

    // ── review findings: the lists that must agree, and the agent contract ────

    /**
     * UX review (psa-u2yjj) R1: a staged password reset rendered as "Reply" in the
     * cockpit. resources/views/cockpit/index.blade.php carries a badge map for staged
     * action types and anything absent falls through to the default
     * ['Reply', bi-send, primary-subtle] arm — so a destructive account action was
     * labelled like a customer email, and the badge is the scan affordance under load.
     *
     * This is the FOURTH hand-maintained list that must agree with STAGED_TO_DIRECT
     * (with the executor's definitions() and TechnicianCockpitController's approve
     * match). Guard all of them structurally rather than fixing this one entry, so the
     * next staged action cannot be mislabelled the same way.
     */
    public function test_every_staged_action_type_has_its_own_cockpit_badge(): void
    {
        $blade = (string) file_get_contents(resource_path('views/cockpit/index.blade.php'));

        $missing = [];
        foreach (array_keys(McpToolModes::stagedToCanonical()) as $stagedType) {
            if (! str_contains($blade, "'{$stagedType}'")) {
                $missing[] = $stagedType;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'These staged action types have no cockpit badge, so they fall through to the',
            'default "Reply" badge and a destructive action reads like a customer email.',
            'Add an arm to the badge map in resources/views/cockpit/index.blade.php.',
            'Offenders: '.implode(', ', $missing),
        ]));
    }

    public function test_every_staged_action_type_can_actually_be_approved(): void
    {
        // The third list: TechnicianCockpitController's approve match. An unlisted type
        // fails closed with a 422 — right default, but it means a staged action nobody
        // can approve, which is a dead proposal rather than a safe one.
        $controller = (string) file_get_contents(app_path('Http/Controllers/Web/TechnicianCockpitController.php'));

        $missing = [];
        foreach (array_keys(McpToolModes::stagedToCanonical()) as $stagedType) {
            if (! str_contains($controller, "'{$stagedType}'")) {
                $missing[] = $stagedType;
            }
        }

        $this->assertSame([], $missing, 'staged types with no approve arm (they would 422): '.implode(', ', $missing));
    }

    /**
     * UX review (psa-u2yjj) R1: the canonical tool's description opened with "return a
     * newly generated temporary password", so an agent calling it with staged=true
     * could sit waiting for a credential that will never arrive.
     */
    public function test_the_canonical_tool_description_says_a_staged_call_returns_no_password(): void
    {
        $definition = collect(\App\Services\Mcp\StaffCippWriteToolExecutor::definitions())
            ->firstWhere('name', self::TOOL);

        $this->assertNotNull($definition);
        $description = (string) $definition['description'];

        $this->assertStringContainsString('NO PASSWORD', $description);
        $this->assertStringContainsString('approv', $description, 'must say a human approves first');
        $this->assertStringContainsString('do not wait for a credential', $description);
    }

    // ── the immediate path still works when explicitly granted ────────────────

    public function test_an_explicit_immediate_grant_still_executes_directly(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();

        $vendor = Mockery::mock(CippRestWriteClient::class);
        $vendor->shouldReceive('resetUserPassword')->once()
            ->andReturn(['success' => true, 'status' => 200, 'body' => [
                'Results' => ['copyField' => 'Temp-Pass-999!', 'state' => 'success'],
            ]]);
        $this->app->instance(CippRestWriteClient::class, $vendor);

        $token = McpConfig::rotateStaffToken(allowedTools: [self::TOOL.':immediate'], label: 'opsbot');

        $response = $this->callTool($token, self::TOOL, [
            'client_id' => $fixture['client']->id,
            'person_id' => $fixture['contact']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Explicitly granted immediate.',
            'staged' => false,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));
        $this->assertSame('Temp-Pass-999!', $this->decoded($response)['temporary_password'] ?? null);
    }
}
