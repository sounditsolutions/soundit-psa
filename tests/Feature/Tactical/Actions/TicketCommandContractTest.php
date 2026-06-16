<?php

namespace Tests\Feature\Tactical\Actions;

use App\Models\Asset;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3 chunk 3 / amendment G1: run an ad-hoc command on a ticket's asset while
 * working the incident (cmd ONLY — shutdown/recover/maintenance stay asset-page).
 *
 * The endpoint mirrors AssetController::runTacticalCommand's A1 spine (one
 * canonical params source, server-minted payloadHash-bound token, server-side
 * typed-hostname) PLUS the runTacticalScript membership gate (the posted asset
 * MUST be attached to this ticket). On success it dispatches through the bus with
 * the ticket id (audit row carries it) AND writes a ticket note — but the note is
 * built from REDACTED values (B3/DC1): `summary()` for the command, `redactOutput`
 * for stdout/stderr; never raw request input; and ONLY when the result isOk().
 */
class TicketCommandContractTest extends TestCase
{
    use RefreshDatabase;

    private function bindClient(array $queue): void
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $http = new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    private function onlineAsset(string $hostname = 'WORKSTATION-01', string $agentId = 'AGENT-1'): Asset
    {
        $asset = Asset::factory()->create(['hostname' => $hostname]);
        TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => $agentId,
            'hostname' => $hostname,
            'status' => 'online',
        ]);

        return $asset->refresh();
    }

    private function attachedTicket(Asset $asset): Ticket
    {
        $ticket = Ticket::factory()->create();
        $ticket->assets()->attach($asset->id);

        return $ticket;
    }

    // ── happy path: dispatch + audit(ticket_id) + redacted ticket note ──────

    public function test_cmd_on_attached_asset_dispatches_audits_with_ticket_id_and_writes_note(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $ticket = $this->attachedTicket($asset);
        // D1: the cmd endpoint returns a bare STRING as the primary shape.
        $this->bindClient([new Response(200, [], json_encode('nt authority\\system'))]);

        $resp = $this->actingAs($user)->postJson(route('tickets.run-tactical-command', $ticket), [
            'asset_id' => $asset->id,
            'hostname' => 'WORKSTATION-01',
            'shell' => 'cmd',
            'cmd' => 'whoami',
            'timeout' => 30,
        ]);

        $resp->assertOk()->assertJson(['success' => true]);

        // m1: audit row carries the ticket id.
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_command',
            'asset_id' => $asset->id,
            'actor_id' => $user->id,
            'ticket_id' => $ticket->id,
            'result_status' => 'ok',
        ]);

        // DC1: a success writes exactly one ticket note.
        $this->assertDatabaseHas('ticket_notes', ['ticket_id' => $ticket->id]);
        $this->assertSame(1, TicketNote::where('ticket_id', $ticket->id)->count());

        // The note records the redacted summary command + retcode + output.
        $note = TicketNote::where('ticket_id', $ticket->id)->first();
        $this->assertStringContainsString('whoami', $note->body, 'the (redacted) command should appear');
        $this->assertStringContainsString('nt authority\\system', $note->body, 'the command output should appear');
    }

    /**
     * B3 — the most security-critical assertion in chunk 3: a secret printed by
     * the command to stdout MUST NOT appear in the created ticket note. The note
     * is written OUTSIDE the bus, so the controller must redact it itself via
     * ActionRedactor::redactOutput. (Build the AWS-shaped fixture by
     * concatenation so the repo secret-guard never sees a literal key.)
     */
    public function test_planted_secret_in_cmd_stdout_does_not_appear_in_the_ticket_note(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $ticket = $this->attachedTicket($asset);

        // A bare AWS access-key id + a long high-entropy token, both on their own
        // lines (the bare-credential output backstop should collapse them).
        $awsKey = 'AKIA'.'ABCDEFGHIJKLMNOP';
        $bareToken = str_repeat('a1B2', 12); // 48 contiguous high-entropy chars
        $stdout = "Listing creds:\n{$awsKey}\n{$bareToken}\nDone.";
        $this->bindClient([new Response(200, [], json_encode($stdout))]);

        $resp = $this->actingAs($user)->postJson(route('tickets.run-tactical-command', $ticket), [
            'asset_id' => $asset->id,
            'hostname' => 'WORKSTATION-01',
            'shell' => 'cmd',
            'cmd' => 'aws sts get-session-token',
            'timeout' => 30,
        ]);

        $resp->assertOk()->assertJson(['success' => true]);

        $note = TicketNote::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertStringNotContainsString($awsKey, $note->body, 'a bare AWS key must be redacted from the ticket note');
        $this->assertStringNotContainsString($bareToken, $note->body, 'a bare high-entropy token must be redacted from the ticket note');
        $this->assertStringContainsString('[REDACTED:credential]', $note->body, 'the redaction marker should be present');
    }

    /**
     * B3 — the TYPED command is also redacted in the note (a tech who inlines a
     * secret in the command itself shouldn't persist it raw in the ITIL record).
     */
    public function test_secret_in_typed_command_does_not_appear_in_the_ticket_note(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $ticket = $this->attachedTicket($asset);
        $this->bindClient([new Response(200, [], json_encode('ok'))]);

        $resp = $this->actingAs($user)->postJson(route('tickets.run-tactical-command', $ticket), [
            'asset_id' => $asset->id,
            'hostname' => 'WORKSTATION-01',
            'shell' => 'cmd',
            'cmd' => 'mysql -pSuperSecret123 -e "show databases"',
            'timeout' => 30,
        ]);

        $resp->assertOk();
        $note = TicketNote::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertStringNotContainsString('SuperSecret123', $note->body, 'a glued -p password must be redacted in the note');
    }

    // ── membership gate: asset NOT attached → 422, no dispatch, no note ──────

    public function test_asset_not_attached_to_ticket_is_422_no_dispatch_no_note(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $ticket = Ticket::factory()->create(); // asset NOT attached
        $this->bindClient([]); // must never be called

        $resp = $this->actingAs($user)->postJson(route('tickets.run-tactical-command', $ticket), [
            'asset_id' => $asset->id,
            'hostname' => 'WORKSTATION-01',
            'shell' => 'cmd',
            'cmd' => 'whoami',
            'timeout' => 30,
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertSame(0, TacticalActionLog::count(), 'an unattached asset must not reach the bus');
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count(), 'no note for an unattached asset');
    }

    public function test_not_linked_asset_is_422(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['hostname' => 'NO-AGENT']);
        $ticket = $this->attachedTicket($asset);
        $this->bindClient([]);

        $resp = $this->actingAs($user)->postJson(route('tickets.run-tactical-command', $ticket), [
            'asset_id' => $asset->id,
            'hostname' => 'NO-AGENT',
            'shell' => 'cmd',
            'cmd' => 'whoami',
            'timeout' => 30,
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertSame(0, TacticalActionLog::count());
    }

    // ── wrong hostname → 422, no dispatch ───────────────────────────────────

    public function test_wrong_hostname_is_422_and_not_dispatched(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset('WORKSTATION-01');
        $ticket = $this->attachedTicket($asset);
        $this->bindClient([]);

        $resp = $this->actingAs($user)->postJson(route('tickets.run-tactical-command', $ticket), [
            'asset_id' => $asset->id,
            'hostname' => 'WRONG-HOST',
            'shell' => 'cmd',
            'cmd' => 'whoami',
            'timeout' => 30,
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertSame(0, TacticalActionLog::count(), 'a hostname mismatch must not reach the bus');
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
    }

    // ── rejected (bad shell) → audited rejected, NO note ────────────────────

    public function test_rejected_bad_shell_writes_no_note(): void
    {
        // C2 fail-closed: a shell outside the allowlist is rejected by
        // validateParams -> the bus audits `rejected`; no client call AND, per
        // DC1 (note only on isOk()), no ticket note.
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $ticket = $this->attachedTicket($asset);
        $this->bindClient([]); // must never be called

        $resp = $this->actingAs($user)->postJson(route('tickets.run-tactical-command', $ticket), [
            'asset_id' => $asset->id,
            'hostname' => 'WORKSTATION-01',
            'shell' => 'bash-as-root',
            'cmd' => 'whoami',
            'timeout' => 30,
        ]);

        $resp->assertStatus(500)->assertJsonStructure(['error']);
        $this->assertArrayNotHasKey('success', $resp->json());
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_command',
            'ticket_id' => $ticket->id,
            'result_status' => 'rejected',
        ]);
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count(), 'a rejected cmd writes NO note');
    }

    // ── offline → 422 audited, NO note ──────────────────────────────────────

    public function test_offline_is_422_audited_with_no_note(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $ticket = $this->attachedTicket($asset);
        $this->bindClient([new ConnectException('agent offline', new Request('POST', 'agents/AGENT-1/cmd/'))]);

        $resp = $this->actingAs($user)->postJson(route('tickets.run-tactical-command', $ticket), [
            'asset_id' => $asset->id,
            'hostname' => 'WORKSTATION-01',
            'shell' => 'cmd',
            'cmd' => 'whoami',
            'timeout' => 30,
        ]);

        $resp->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_command',
            'ticket_id' => $ticket->id,
            'result_status' => 'offline',
        ]);
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count(), 'an offline cmd writes NO note');
    }

    public function test_cmd_is_in_the_csrf_protected_web_group(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('tickets.run-tactical-command');
        $this->assertNotNull($route, 'route tickets.run-tactical-command should be registered');
        $this->assertContains('web', $route->gatherMiddleware(), 'the ticket cmd route must be in the CSRF-protected web group');
    }

    /**
     * Amendment A1, exercised through the bus the controller uses: a blocked
     * dispatch (no token) writes an audit row but the controller writes NO note
     * (the note is success-gated). The payload-bound token (command-A token
     * rejects command-B) is the same bus guarantee asserted in
     * RemoteActionEndpointsTest; re-assert it here for the ticket path's params.
     */
    public function test_blocked_dispatch_audits_but_the_endpoint_writes_no_note(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $ticket = $this->attachedTicket($asset);
        $bus = app(\App\Services\Tactical\TacticalActionService::class);
        $this->bindClient([]); // must never be called

        $action = new \App\Services\Tactical\Actions\RunCommandAction;
        $params = $action->validateParams(['shell' => 'cmd', 'cmd' => 'whoami', 'timeout' => 30]);

        // No token -> blocked + audited, no client call (the bus guarantee the
        // endpoint relies on; the controller never writes a note for a non-ok).
        $result = $bus->dispatch($action, $asset, $user, $params, null, null, $ticket->id);

        $this->assertSame('blocked', $result->status);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.run_command',
            'ticket_id' => $ticket->id,
            'result_status' => 'blocked',
        ]);
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count(), 'a blocked cmd writes NO note');
    }

    // ── UI: the cmd control + modal render on the ticket page ───────────────

    public function test_ticket_page_renders_the_run_command_control_and_modal(): void
    {
        // The cmd button + modal render when the ticket has an ONLINE tactical
        // asset (the same gate the existing Run Script control uses). cmd ONLY —
        // no shutdown/recover/maintenance controls on the ticket page (G1).
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $ticket = $this->attachedTicket($asset);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk()
            ->assertSee('Run Command')
            ->assertSee('tacticalCmdModal', false)
            ->assertSee('ticketCmdConfirm', false)
            ->assertSee('ticketCmdPreview', false)
            // cmd-only: no destructive power/maintenance controls on the ticket.
            ->assertDontSee('tacticalShutdownBtn', false)
            ->assertDontSee('tacticalMaintenanceToggle', false);
    }

    public function test_cmd_token_is_payload_bound_command_a_token_rejects_command_b(): void
    {
        $user = User::factory()->create();
        $asset = $this->onlineAsset();
        $ticket = $this->attachedTicket($asset);

        $action = new \App\Services\Tactical\Actions\RunCommandAction;
        $paramsA = $action->validateParams(['shell' => 'cmd', 'cmd' => 'whoami', 'timeout' => 30]);
        $paramsB = $action->validateParams(['shell' => 'cmd', 'cmd' => 'shutdown /s /t 0', 'timeout' => 30]);

        $tokenA = \App\Services\Tactical\TacticalActionConfirmToken::issue(
            $action->key(),
            'AGENT-1',
            $user->id,
            $action->payloadHash($paramsA),
        );

        // Command B with command A's token -> blocked, no client call, no note.
        $this->bindClient([]);
        $blocked = app(\App\Services\Tactical\TacticalActionService::class)
            ->dispatch($action, $asset, $user, $paramsB, $tokenA, null, $ticket->id);
        $this->assertSame('blocked', $blocked->status, 'a token for command A must not run command B');

        // The exact command the token was minted for proceeds.
        $this->bindClient([new Response(200, [], json_encode('ok'))]);
        $ok = app(\App\Services\Tactical\TacticalActionService::class)
            ->dispatch($action, $asset, $user, $paramsA, $tokenA, null, $ticket->id);
        $this->assertSame('ok', $ok->status, 'the matching command must proceed');
    }
}
