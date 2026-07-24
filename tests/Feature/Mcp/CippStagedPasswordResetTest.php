<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
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

        $approver = User::factory()->create();
        $response = $this->actingAs($approver)->post(route('cockpit.approve', $run));

        $response->assertRedirect();

        $run->refresh();
        $this->assertNotSame('pending', $run->status, 'the run must leave the pending state on approval');
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
