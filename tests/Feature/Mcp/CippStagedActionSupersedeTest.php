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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * bd psa-k4s0: two prod runs (161/162) of DIFFERENT cipp_stage_set_mailbox_forwarding
 * actions on the SAME ticket — one for each of two different forwarding targets. Run
 * 161 was silently superseded by run 162 (Root A: the post-create supersede query keyed
 * only on ticket_id + action_type + AwaitingApproval, ignoring content_hash). After 162
 * was approved, restaging the lost 161 action falsely returned "Already staged; awaiting
 * approval" with run_id: null (Root B: the dedup guard consulted the IMMUTABLE audit log
 * — whose stale 'awaiting_approval' row survives a supersede by design — instead of the
 * live runs table, then reported idempotent:true even though no live run matched).
 *
 * These tests pin: (1) distinct-content same-type staged actions coexist as separate
 * cockpit cards; (2) identical-content restage while still live returns the REAL run_id;
 * (3) identical-content restage after the live run is gone (superseded) stages FRESH
 * rather than lying; (4) the executed-dedup path (audit-log-driven, unchanged) still
 * short-circuits and now also surfaces the real run_id instead of null.
 */
class CippStagedActionSupersedeTest extends TestCase
{
    use RefreshDatabase;

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

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
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
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** @return array{client: Client, person: Person, personTwo: Person, target: Person, ticket: Ticket} */
    private function cippFixture(): array
    {
        $client = Client::factory()->create([
            'name' => 'Acme',
            'cipp_tenant_domain' => 'acme.onmicrosoft.com',
        ]);

        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Alex',
            'last_name' => 'Acme',
            'email' => 'alex@acme.example',
            'cipp_user_id' => 'user-123',
            'cipp_upn' => 'alex@acme.example',
            'is_active' => true,
        ]);

        // A SECOND, unrelated mailbox owner. Real prod repro (bd psa-k4s0, ticket 22647):
        // two forwarding actions ~10s apart both succeeded in STAGING (only one silently
        // superseded the other afterward), which requires distinct source persons — the
        // per-target proposal cooldown keys on the mailbox owner, so a same-owner second
        // stage within the cooldown window would be refused outright, not superseded.
        $personTwo = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Blake',
            'last_name' => 'Acme',
            'email' => 'blake@acme.example',
            'cipp_user_id' => 'user-456',
            'cipp_upn' => 'blake@acme.example',
            'is_active' => true,
        ]);

        $target = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Jordan',
            'last_name' => 'Acme',
            'email' => 'jordan@acme.example',
            'cipp_user_id' => 'target-456',
            'cipp_upn' => 'jordan@acme.example',
            'is_active' => true,
        ]);

        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $person->id,
            'subject' => 'Mailbox forwarding requests',
        ]);

        return compact('client', 'person', 'personTwo', 'target', 'ticket');
    }

    /** @return array<string, mixed> */
    private function stageForwardingArgs(array $fixture, Person $owner, Person $target, string $reason): array
    {
        return [
            'client_id' => $fixture['client']->id,
            'person_id' => $owner->id,
            'ticket_id' => $fixture['ticket']->id,
            'mode' => 'internal',
            'target_person_id' => $target->id,
            'keep_copy' => true,
            'confirm_upn' => $owner->cipp_upn,
            'reason' => $reason,
        ];
    }

    public function test_two_different_content_staged_forwarding_actions_coexist(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_set_mailbox_forwarding']);

        $blockedClient = Mockery::mock(CippRestWriteClient::class);
        $blockedClient->shouldNotReceive('setMailboxForwardingInternal');
        $this->app->instance(CippRestWriteClient::class, $blockedClient);

        $first = $this->callTool($token, 'cipp_stage_set_mailbox_forwarding', $this->stageForwardingArgs(
            $fixture, $fixture['person'], $fixture['target'], 'Forward Alex to Jordan while Alex is out.',
        ));
        $first->assertOk();
        $this->assertFalse((bool) $first->json('result.isError'), (string) $first->json('result.content.0.text'));
        $firstResult = $this->decodedResult($first);
        $this->assertArrayNotHasKey('idempotent', $firstResult);
        $runIdA = $firstResult['run_id'];

        // A materially DIFFERENT forwarding action (different mailbox owner) on the SAME ticket.
        $second = $this->callTool($token, 'cipp_stage_set_mailbox_forwarding', $this->stageForwardingArgs(
            $fixture, $fixture['personTwo'], $fixture['target'], 'Forward Blake to Jordan too, per the same coverage plan.',
        ));
        $second->assertOk();
        $this->assertFalse((bool) $second->json('result.isError'), (string) $second->json('result.content.0.text'));
        $secondResult = $this->decodedResult($second);
        $this->assertArrayNotHasKey('idempotent', $secondResult);
        $runIdB = $secondResult['run_id'];

        $this->assertNotSame($runIdA, $runIdB, 'distinct-content staged actions must produce distinct runs');

        $runA = TechnicianRun::findOrFail($runIdA);
        $runB = TechnicianRun::findOrFail($runIdB);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $runA->fresh()->state, 'run A must NOT be superseded by the unrelated run B');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $runB->fresh()->state);

        $this->assertSame(2, TechnicianRun::where('ticket_id', $fixture['ticket']->id)
            ->where('action_type', 'cipp_stage_set_mailbox_forwarding')
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->count());

        // Approving A must leave B fully intact and still approvable.
        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('setMailboxForwardingInternal')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'jordan@acme.example', true)
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $runA))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $runA->fresh()->state);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $runB->fresh()->state, 'approving A must not disturb the unrelated B run');
    }

    public function test_restaging_identical_content_while_awaiting_returns_idempotent_with_real_run_id(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_set_mailbox_forwarding']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('setMailboxForwardingInternal');
        $this->app->instance(CippRestWriteClient::class, $client);

        $first = $this->callTool($token, 'cipp_stage_set_mailbox_forwarding', $this->stageForwardingArgs(
            $fixture, $fixture['person'], $fixture['target'], 'Forward to Jordan while Alex is out.',
        ));
        $firstResult = $this->decodedResult($first);
        $runId = $firstResult['run_id'];
        $this->assertNotNull($runId);

        $again = $this->callTool($token, 'cipp_stage_set_mailbox_forwarding', $this->stageForwardingArgs(
            $fixture, $fixture['person'], $fixture['target'], 'Chet re-sent the identical staging call.',
        ));
        $again->assertOk();
        $this->assertFalse((bool) $again->json('result.isError'), (string) $again->json('result.content.0.text'));
        $againResult = $this->decodedResult($again);

        $this->assertTrue($againResult['idempotent'] ?? false);
        $this->assertNotNull($againResult['run_id'], 'idempotent:true must never be paired with a null run_id');
        $this->assertSame($runId, $againResult['run_id']);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $fixture['ticket']->id)
            ->where('action_type', 'cipp_stage_set_mailbox_forwarding')
            ->count(), 'no duplicate row should be created for an identical, still-live restage');
    }

    public function test_restaging_after_supersede_stages_fresh_not_false_idempotent(): void
    {
        $this->configureCipp();
        $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_set_mailbox_forwarding']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('setMailboxForwardingInternal');
        $this->app->instance(CippRestWriteClient::class, $client);

        $first = $this->callTool($token, 'cipp_stage_set_mailbox_forwarding', $this->stageForwardingArgs(
            $fixture, $fixture['person'], $fixture['target'], 'Forward to Jordan while Alex is out.',
        ));
        $firstRunId = $this->decodedResult($first)['run_id'];
        $originalRun = TechnicianRun::findOrFail($firstRunId);

        // Simulate the run being invalidated out from under the stale audit trail (e.g. a
        // content-hash-scoped supersede, or an operator deny) — the audit log still has an
        // 'awaiting_approval' row for this exact content_hash, but no LIVE run matches it.
        $originalRun->markSuperseded();
        $this->assertSame(TechnicianRunState::Superseded, $originalRun->fresh()->state);

        // Past the unrelated per-target proposal cooldown (300s) — the restage below must
        // succeed on the FIX being tested (live-run liveness), not merely on timing.
        $this->travel(301)->seconds();

        $restaged = $this->callTool($token, 'cipp_stage_set_mailbox_forwarding', $this->stageForwardingArgs(
            $fixture, $fixture['person'], $fixture['target'], 'Chet needs to restage the lost forwarding action.',
        ));
        $restaged->assertOk();
        $this->assertFalse((bool) $restaged->json('result.isError'), (string) $restaged->json('result.content.0.text'));
        $restagedResult = $this->decodedResult($restaged);

        // Never the idempotent lie: either it is not "idempotent", or if it claims success
        // it must carry a REAL run_id — never idempotent:true with run_id:null.
        $this->assertFalse($restagedResult['idempotent'] ?? false, 'restaging after the live run is gone must not report a false idempotent hit');
        $this->assertNotNull($restagedResult['run_id']);

        // technician_runs has a UNIQUE(ticket_id, action_type, content_hash) idempotency
        // key (technician_runs_idempotency) — a SECOND row with this exact content is
        // impossible by design. The fix REVIVES the same row (same id) back to
        // AwaitingApproval rather than reporting a false idempotent hit or crashing on
        // the unique constraint.
        $this->assertSame($firstRunId, $restagedResult['run_id'], 'restaging after supersede revives the SAME idempotency-keyed row');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $originalRun->fresh()->state);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $fixture['ticket']->id)
            ->where('action_type', 'cipp_stage_set_mailbox_forwarding')
            ->count(), 'the idempotency key forbids a second row for identical content — the run must be revived in place');
    }

    public function test_executed_dedup_still_short_circuits_off_the_audit_log_with_a_real_run_id(): void
    {
        $this->configureCipp();
        $actor = $this->configureAiActor();
        $fixture = $this->cippFixture();
        $token = $this->token(['cipp_stage_set_mailbox_forwarding']);

        $stageClient = Mockery::mock(CippRestWriteClient::class);
        $stageClient->shouldNotReceive('setMailboxForwardingInternal');
        $this->app->instance(CippRestWriteClient::class, $stageClient);

        $first = $this->callTool($token, 'cipp_stage_set_mailbox_forwarding', $this->stageForwardingArgs(
            $fixture, $fixture['person'], $fixture['target'], 'Forward to Jordan while Alex is out.',
        ));
        $runId = $this->decodedResult($first)['run_id'];
        $run = TechnicianRun::findOrFail($runId);

        $approveClient = Mockery::mock(CippRestWriteClient::class);
        $approveClient->shouldReceive('setMailboxForwardingInternal')
            ->once()
            ->with('acme.onmicrosoft.com', 'alex@acme.example', 'jordan@acme.example', true)
            ->andReturn(['success' => true, 'status' => 200]);
        $this->app->instance(CippRestWriteClient::class, $approveClient);

        $this->actingAs($actor)
            ->post(route('cockpit.approve', $run))
            ->assertRedirect(route('cockpit.index'));
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);

        $restageClient = Mockery::mock(CippRestWriteClient::class);
        $restageClient->shouldNotReceive('setMailboxForwardingInternal');
        $this->app->instance(CippRestWriteClient::class, $restageClient);

        $again = $this->callTool($token, 'cipp_stage_set_mailbox_forwarding', $this->stageForwardingArgs(
            $fixture, $fixture['person'], $fixture['target'], 'Restaging the same forwarding after it already ran.',
        ));
        $again->assertOk();
        $this->assertFalse((bool) $again->json('result.isError'), (string) $again->json('result.content.0.text'));
        $againResult = $this->decodedResult($again);

        $this->assertTrue($againResult['idempotent'] ?? false);
        $this->assertNotNull($againResult['run_id'], 'idempotent:true must never be paired with a null run_id, even for the executed-dedup path');
        $this->assertSame($run->id, $againResult['run_id']);

        // No new row: the executed dedup must still short-circuit BEFORE creating anything.
        $this->assertSame(1, TechnicianRun::where('ticket_id', $fixture['ticket']->id)
            ->where('action_type', 'cipp_stage_set_mailbox_forwarding')
            ->count());
    }
}
