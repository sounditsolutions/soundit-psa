# Backlog Agent — Increment 1: event-woken agent proposes a gated close (dormant) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove the whole "event-woken AI agent → reasons with tools → proposes ONE gated action → held for approval → operator approves → atomic gated execute + audit" spine, end-to-end, on the dead-ticket backlog — built almost entirely from existing parts, shipping **dormant**.

**Architecture:** A new `BacklogAgent` service runs the existing `AiClient::runToolLoop` over a candidate ticket with the existing **read** tools plus ONE new **gated** `propose_close` tool. The tool's executor mirrors `DraftPipeline::recordHeld` — it creates a held `TechnicianRun(action_type='propose_close')` and a held `TechnicianActionGate::dispatch('propose_close', …)` (never closes the ticket). A deterministic, paced `agent:backlog-sweep` command (mirroring `EmergencySweep`) finds stale candidates on operational clients and dispatches a `RunBacklogAgent` job per candidate. The proposal surfaces in the existing Technician cockpit; a new `approveClose` path (mirroring `TechnicianApprovalService::approveAndSend`, but **closing** the ticket instead of emailing) executes it through the gate on operator approval. Everything is gated on a new `AgentConfig::enabled()` flag → dormant.

**Tech Stack:** PHP 8.3 / Laravel 12; `App\Services\Ai\AiClient::runToolLoop` (Anthropic tool loop); `TechnicianActionGate`; `TechnicianRun` + `TechnicianRunState` + the cockpit (`CockpitQuery`, `TechnicianCockpitController`); the existing read tools in `TriageToolDefinitions`; `Setting`-backed config; PHPUnit on sqlite `:memory:` (`RefreshDatabase`, `$this->mock(...)`); Pint.

## Global Constraints

Apply to **every** task:

- **Dormant + flag-gated.** Every wake path (the sweep command, the schedule, the job) is gated on `AgentConfig::enabled()` (Setting `backlog_agent_enabled`, default false). The command early-exits when disabled; the schedule is `->when(fn () => AgentConfig::enabled())`. Merging changes nothing in prod until the flag flips.
- **Held by default — the agent NEVER closes a ticket directly.** The `propose_close` tool only records a held `TechnicianRun` + a held gate action (`awaiting_approval`). The actual close happens ONLY on operator approval, through `TechnicianActionGate`. The tool's gate-dispatch executor is a **tripwire that throws** if ever auto-run (mirror `DraftPipeline::recordHeld` `:214-216`).
- **Reuse, don't rebuild.** The gate (`TechnicianActionGate::dispatch`, signature confirmed below), the held-run pattern (`DraftPipeline::recordHeld`), the approve pattern (`TechnicianApprovalService::approveAndSend`), the cockpit (`CockpitQuery::pendingDrafts` + `TechnicianCockpitController`), the read tools (`TriageToolDefinitions`), the tool loop (`AiClient::runToolLoop`), and `ContextBuilder::buildForTicket` already exist — call/mirror them; do not duplicate.
- **Operational clients only.** The sweep scans `Ticket::open()->whereHas('client', fn ($q) => $q->operational())` (no prospects), mirroring `EmergencySweep`.
- **Append-only audit preserved.** `propose_close` writes through the gate (one append-only `TechnicianActionLog` row per dispatch); never add an update/delete path to the audit table.
- **Confirmed gate signature (do not guess):** `TechnicianActionGate::dispatch(string $actionType, int $ticketId, ?int $clientId, string $contentHash, string $summary, ?int $runId, callable $executor, ?string $approvalToken = null, ?int $approverUserId = null): TechnicianActionResult` — returns `->status ∈ {executed, awaiting_approval, blocked, held}`. With an empty `technician_action_tiers` map, `propose_close` classifies **Approve → awaiting_approval** (held, executor NOT run). (`app/Services/Technician/TechnicianActionGate.php:50-114`.)
- **Runtime/tests:** sqlite `:memory:` (`RefreshDatabase`); `Setting::setValue`; `Ticket::factory()`/`Client::factory()`; `TechnicianRun::create([...])` inline; mock `AiClient` to drive the tool loop deterministically in agent tests. Pint-clean before each commit.

---

## File Structure

**Created:**

| Path | Responsibility |
|------|----------------|
| `app/Support/AgentConfig.php` | The agent's dormant enable flag + (future) agent settings. |
| `app/Services/Agent/ProposeCloseTool.php` | The ONE gated action tool: definition (name/description/input schema) + an `execute(Ticket, array $input)` that records a held `TechnicianRun('propose_close')` + a held gate dispatch. |
| `app/Services/Agent/BacklogAgent.php` | The agent brain: `run(Ticket): void` — builds context, runs `AiClient::runToolLoop` with read tools + `propose_close`, fail-soft + budget-guarded. |
| `app/Jobs/RunBacklogAgent.php` | Queued per-ticket wake (dormancy + operational + recursion guards) → `BacklogAgent::run`. |
| `app/Console/Commands/AgentBacklogSweep.php` | `agent:backlog-sweep` — finds stale candidates, dispatches the job, paced; early-exits when disabled. |
| `tests/Feature/Agent/*Test.php` | One test file per task below. |

**Modified:**

| Path | Change |
|------|--------|
| `app/Services/Technician/TechnicianApprovalService.php` | Add `approveClose(TechnicianRun $run, int $approverId): TechnicianApprovalResult` (mirrors `approveAndSend`, but closes the ticket). |
| `app/Http/Controllers/Web/TechnicianCockpitController.php` | In `approve()`, route a `propose_close` run to `approveClose()` (else the existing reply path). |
| `routes/console.php` | Schedule `agent:backlog-sweep` `->when(AgentConfig::enabled())`. |

> **Implementer note:** read each cited file before writing — the exact internal shapes (the `TriageToolDefinitions` tool-definition array format, `AiClient::runToolLoop`'s named args, `TechnicianRunState` cases, `ContextBuilder::buildForTicket`'s return, `TicketService`'s close/changeStatus method, `CockpitQuery::pendingDrafts`'s run query, the cockpit `approve()` body) are stable and must be matched, not reinvented. Citations are `file:line` into the repo.

---

## Tasks

### Task 1: `AgentConfig` dormant enable flag

**Files:**
- Create: `app/Support/AgentConfig.php`
- Test: `tests/Feature/Agent/AgentConfigTest.php`

**Interfaces:**
- Produces: `AgentConfig::enabled(): bool` — `(bool) Setting::getValue('backlog_agent_enabled')` (absent ⇒ false). Mirror `TechnicianConfig::enabled()` (`app/Support/TechnicianConfig.php:17-20`).
- Produces: `AgentConfig::sweepBatchSize(): int` — Setting `backlog_agent_sweep_batch`, default 5, floor 1 (paced; how many candidates per sweep run).
- Produces: `AgentConfig::staleDays(): int` — Setting `backlog_agent_stale_days`, default 60, floor 14 (conservative candidate threshold).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Agent;

use App\Models\Setting;
use App\Support\AgentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_dormant_and_conservative(): void
    {
        $this->assertFalse(AgentConfig::enabled());
        $this->assertSame(5, AgentConfig::sweepBatchSize());
        $this->assertSame(60, AgentConfig::staleDays());
    }

    public function test_overrides_and_floors(): void
    {
        Setting::setValue('backlog_agent_enabled', '1');
        Setting::setValue('backlog_agent_sweep_batch', '0'); // below floor
        Setting::setValue('backlog_agent_stale_days', '3');  // below floor
        $this->assertTrue(AgentConfig::enabled());
        $this->assertSame(1, AgentConfig::sweepBatchSize());
        $this->assertSame(14, AgentConfig::staleDays());
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`php artisan test --filter=AgentConfigTest`) — class missing.
- [ ] **Step 3: Create `AgentConfig`** mirroring `TechnicianConfig`'s `Setting`-read + `max(floor, (int)$value)` idiom (read `TechnicianConfig.php` for the exact pattern; `enabled()` = `(bool) Setting::getValue('backlog_agent_enabled')`).
- [ ] **Step 4: Run it — expect PASS.** Pint + commit (`feat(agent): AgentConfig dormant enable flag + sweep config`).

---

### Task 2: `ProposeCloseTool` — the one gated action tool

**Files:**
- Create: `app/Services/Agent/ProposeCloseTool.php`
- Test: `tests/Feature/Agent/ProposeCloseToolTest.php`

**Interfaces:**
- Consumes: `TechnicianActionGate::dispatch(...)` (signature in Global Constraints); `TechnicianRun` + `TechnicianRunState` (read `app/Models/TechnicianRun.php` + `app/Enums/TechnicianRunState.php` for the `AwaitingApproval` case + fillable cols `ticket_id, client_id, action_type, content_hash, state, proposed_content, proposed_meta, confidence, tokens_used` — confirmed from `DraftPipeline::recordHeld:169-183`).
- Produces:
  - `ProposeCloseTool::definition(): array` — the Anthropic tool-definition array for `propose_close`, matching the shape used in `TriageToolDefinitions` (read `app/Services/Triage/TriageToolDefinitions.php` for the exact `['name'=>..., 'description'=>..., 'input_schema'=>[...]]` format). Inputs: `reason` (string, required — why this ticket is dead/resolved, with evidence) + `confidence` (number 0–1, required).
  - `ProposeCloseTool::execute(Ticket $ticket, array $input): string` — records a held `TechnicianRun('propose_close', AwaitingApproval)` carrying the reason + confidence, dispatches a held `propose_close` gate action (tripwire executor), and returns a short string the model sees (e.g. `"Recorded a close proposal for ticket #{id}; held for operator approval."`). **Never closes the ticket.**

This is the gated-tool pattern that mirrors `DraftPipeline::recordHeld` (`app/Services/Technician/DraftPipeline.php:158-218`) — the ONE place to copy. Concretely, `execute()`:

```php
$reason = trim((string) ($input['reason'] ?? ''));
$confidence = (float) ($input['confidence'] ?? 0.0);
$hash = hash('sha256', 'propose_close:'.$ticket->id.':'.$reason);

$run = \App\Models\TechnicianRun::firstOrCreate(
    ['ticket_id' => $ticket->id, 'action_type' => 'propose_close', 'content_hash' => $hash],
    [
        'client_id' => $ticket->client_id,
        'state' => \App\Enums\TechnicianRunState::AwaitingApproval,
        'proposed_content' => $reason,
        'proposed_meta' => ['confidence' => $confidence],
        'confidence' => $confidence,
        'tokens_used' => 0,
    ],
);

$this->gate->dispatch(
    actionType: 'propose_close',
    ticketId: $ticket->id,
    clientId: $ticket->client_id,
    contentHash: $hash,
    summary: 'Proposed closing a stale ticket (awaiting approval).',
    runId: $run->id,
    executor: function (): void {
        throw new \LogicException('[Agent] propose_close must not auto-execute — it is hold-for-approval.');
    },
);

return "Recorded a close proposal for ticket #{$ticket->id}; held for your approval.";
```

Constructor injects `TechnicianActionGate $gate` (resolve via the container in tests).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Agent;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Agent\ProposeCloseTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposeCloseToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_definition_describes_propose_close(): void
    {
        $def = ProposeCloseTool::definition();
        $this->assertSame('propose_close', $def['name']);
        $this->assertArrayHasKey('reason', $def['input_schema']['properties']);
        $this->assertArrayHasKey('confidence', $def['input_schema']['properties']);
    }

    public function test_execute_records_a_held_run_and_a_held_gate_action_without_closing(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'status' => \App\Enums\TicketStatus::InProgress->value]);

        $out = app(ProposeCloseTool::class)->execute($ticket, ['reason' => 'Client confirmed resolved 90d ago.', 'confidence' => 0.95]);

        // Held run created
        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_close')->first();
        $this->assertNotNull($run);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertStringContainsString('resolved 90d ago', $run->proposed_content);

        // Held gate audit row (awaiting_approval), NOT executed
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'propose_close', 'result_status' => 'awaiting_approval']);
        $this->assertDatabaseMissing('technician_action_logs', ['action_type' => 'propose_close', 'result_status' => 'executed']);

        // Ticket is NOT closed
        $this->assertNotContains($ticket->fresh()->status, [\App\Enums\TicketStatus::Resolved->value, \App\Enums\TicketStatus::Closed->value]);
        $this->assertStringContainsString('held', $out);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.** **Step 3: Write `ProposeCloseTool`** per the code above; `definition()` matching the real `TriageToolDefinitions` tool shape. **Step 4: Run — expect PASS.** Pint + commit (`feat(agent): gated propose_close tool (held, never auto-closes)`).

---

### Task 3: `BacklogAgent` — the event-woken brain (tool loop)

**Files:**
- Create: `app/Services/Agent/BacklogAgent.php`
- Test: `tests/Feature/Agent/BacklogAgentTest.php`

**Interfaces:**
- Consumes: `AiClient::runToolLoop(string $system, string $userMessage, array $tools, callable $executor, int $maxRounds, int $maxTokenBudget, int $wallClockSeconds)` — **confirm the exact named-arg names** in `app/Services/Ai/AiClient.php:104` (the triage caller is `app/Services/Triage/TechnicalTriager.php:72-91` — copy its call shape). `ContextBuilder::buildForTicket(Ticket): string` (`app/Services/Triage/ContextBuilder.php`). `TriageToolDefinitions` read tools. `ProposeCloseTool`. `AiConfig::isConfigured()/isEnabled()`.
- Produces: `BacklogAgent::run(Ticket $ticket): void` — fail-soft, AI-config-guarded. Builds a system prompt ("You are a junior MSP technician doing an onboarding pass over an OLD ticket. Read it. If it is clearly resolved or abandoned with no further action needed, call `propose_close` with a one-line reason quoting the evidence and a confidence. If it is awaiting us, awaiting the client, or still active, do NOTHING — leave it. When unsure, leave it."), the ticket context as the user message, the tool set = the existing **read** tools + `ProposeCloseTool::definition()`, and an executor that dispatches `propose_close` → `ProposeCloseTool::execute($ticket, $input)` and read-tool names → the existing read executor (reuse `TriageToolExecutor` for reads, or a thin read-only executor — read `TriageToolExecutor.php:60-138` to wire the read arms). Wrap the whole loop in try/catch + `Log::warning` (fail-soft: a model/tool error never throws out of `run()`).

> The implementer writes the loop body to satisfy the test; the key invariants are pinned by the test: a clearly-resolved ticket ⇒ a held `propose_close` run exists; a clearly-active ticket ⇒ no run; `run()` never throws.

- [ ] **Step 1: Write the failing test** — mock `AiClient` so the tool loop is deterministic (no real LLM):

```php
<?php

namespace Tests\Feature\Agent;

use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Agent\BacklogAgent;
use App\Services\Ai\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class BacklogAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_that_decides_to_close_records_a_held_proposal(): void
    {
        \App\Models\Setting::setValue('ai_api_key', 'x'); // make AiConfig::isConfigured() true (confirm the real key in AiConfig)
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        // Drive the loop: make runToolLoop invoke the injected executor for 'propose_close'.
        $this->mock(AiClient::class, function (MockInterface $m) {
            $m->shouldReceive('runToolLoop')->once()->andReturnUsing(function ($system, $user, $tools, $executor) {
                $executor('propose_close', ['reason' => 'Client said "all sorted" 100d ago.', 'confidence' => 0.96]);
                return 'done';
            });
        });

        app(BacklogAgent::class)->run($ticket);

        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_close')->count());
    }

    public function test_agent_that_leaves_it_records_nothing_and_never_throws(): void
    {
        \App\Models\Setting::setValue('ai_api_key', 'x');
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        $this->mock(AiClient::class, function (MockInterface $m) {
            $m->shouldReceive('runToolLoop')->once()->andReturnUsing(function () {
                throw new \RuntimeException('model hiccup'); // fail-soft path
            });
        });

        app(BacklogAgent::class)->run($ticket); // must NOT throw
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.** **Step 3: Write `BacklogAgent`** (the `runToolLoop` call shape copied from `TechnicalTriager`; the executor routes `propose_close`→`ProposeCloseTool`, read tools→the read executor; try/catch fail-soft). Confirm the real `AiConfig` key name + `runToolLoop` arg names. **Step 4: Run — expect PASS.** Pint + commit (`feat(agent): BacklogAgent tool-loop brain (read tools + propose_close, fail-soft)`).

---

### Task 4: `RunBacklogAgent` job + `agent:backlog-sweep` command + schedule

**Files:**
- Create: `app/Jobs/RunBacklogAgent.php`
- Create: `app/Console/Commands/AgentBacklogSweep.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Agent/AgentBacklogSweepTest.php`

**Interfaces:**
- `RunBacklogAgent` (queued, `$ticketId`): early-return unless `AgentConfig::enabled()`; load the ticket; skip if not open / not operational client / already has an open `propose_close` `TechnicianRun` (don't re-propose); else `app(BacklogAgent::class)->run($ticket)`. Mirror the guards in `RunTriagePipeline` (`app/Jobs/RunTriagePipeline.php:31-78`).
- `AgentBacklogSweep` (`signature='agent:backlog-sweep'`): if `! AgentConfig::enabled()` → SUCCESS (no work). Else select up to `AgentConfig::sweepBatchSize()` candidate tickets — `Ticket::open()->whereHas('client', fn ($q) => $q->operational())`, no activity in ≥ `AgentConfig::staleDays()` days (`updated_at < now()->subDays(...)`), and NOT already carrying an open `propose_close` run — oldest first; dispatch `RunBacklogAgent` per candidate. Mirror `EmergencySweep`'s operational-only scan + the candidate-skip pattern (`app/Services/Technician/Emergency/EmergencySweep.php`).
- `routes/console.php`: `Schedule::command('agent:backlog-sweep')->everyFifteenMinutes()->withoutOverlapping()->runInBackground()->when(fn () => \App\Support\AgentConfig::enabled());` (paced; mirror the technician schedules).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Agent;

use App\Jobs\RunBacklogAgent;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AgentBacklogSweepTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_does_nothing(): void
    {
        Queue::fake();
        $this->artisan('agent:backlog-sweep')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_enabled_dispatches_for_a_stale_operational_ticket_only(): void
    {
        Queue::fake();
        Setting::setValue('backlog_agent_enabled', '1');
        $client = Client::factory()->create(); // operational by default — confirm the factory; else set stage=active,is_active=true
        $stale = Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => \App\Enums\TicketStatus::InProgress->value,
            'updated_at' => now()->subDays(90),
        ]);
        $fresh = Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => \App\Enums\TicketStatus::InProgress->value,
            'updated_at' => now()->subDay(),
        ]);

        $this->artisan('agent:backlog-sweep')->assertSuccessful();

        Queue::assertPushed(RunBacklogAgent::class, fn ($job) => $job->ticketId === $stale->id);
        Queue::assertNotPushed(RunBacklogAgent::class, fn ($job) => $job->ticketId === $fresh->id);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.** **Step 3:** Write `RunBacklogAgent` (guards) + `AgentBacklogSweep` (candidate scan + dispatch) + the schedule. **Step 4: Run — expect PASS.** Pint + commit (`feat(agent): backlog-sweep command + RunBacklogAgent job + dormant schedule`).

---

### Task 5: Approve a `propose_close` from the cockpit (closes the ticket through the gate)

**Files:**
- Modify: `app/Services/Technician/TechnicianApprovalService.php`
- Modify: `app/Http/Controllers/Web/TechnicianCockpitController.php`
- Test: `tests/Feature/Agent/ApproveProposeCloseTest.php`

**Interfaces:**
- `TechnicianApprovalService::approveClose(TechnicianRun $run, int $approverId): TechnicianApprovalResult` — mirrors `approveAndSend` (`:39-103`) but **closes** instead of emailing: single-use `claimForExecution()` latch; `hash = hash('sha256','propose_close:'.$run->ticket_id.':'.$run->proposed_content)`; `token = TechnicianApprovalGrant::issue('propose_close', $run->ticket_id, $hash, $approverId)`; `gate->dispatch('propose_close', ticketId, clientId, hash, 'Operator-approved close.', runId, executor: fn () => { <close the ticket to Resolved via the canonical service — read `TicketService` for the close/changeStatus method + actor stamping; stamp the AI actor `TechnicianConfig::aiActorUserId()`>; $run->advanceTo(TechnicianRunState::Done); }, approvalToken: $token, approverUserId: $approverId)`; on non-`executed` → `releaseClaim()` + `gate_declined`; on success → `TechnicianApprovalResult('closed', null)`. No email path.
- `TechnicianCockpitController::approve()` — read the existing body (`:21-42`); branch: if `$run->action_type === 'propose_close'` → `approvalService->approveClose($run, $request->user()->id)`; else the existing reply path. `deny()` is unchanged (it already calls `$run->deny()` for any run).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Agent;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Technician\TechnicianApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApproveProposeCloseTest extends TestCase
{
    use RefreshDatabase;

    private function heldProposeClose(Ticket $ticket): TechnicianRun
    {
        return TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'action_type' => 'propose_close', 'content_hash' => hash('sha256', 'propose_close:'.$ticket->id.':reason'),
            'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => 'reason',
            'proposed_meta' => ['confidence' => 0.95], 'confidence' => 0.95, 'tokens_used' => 0,
        ]);
    }

    public function test_approve_closes_the_ticket_through_the_gate(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'status' => \App\Enums\TicketStatus::InProgress->value]);
        $run = $this->heldProposeClose($ticket);
        $approver = User::factory()->create();

        $result = app(TechnicianApprovalService::class)->approveClose($run, $approver->id);

        $this->assertSame('closed', $result->status);
        $this->assertContains($ticket->fresh()->status, [\App\Enums\TicketStatus::Resolved->value, \App\Enums\TicketStatus::Closed->value]);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'propose_close', 'result_status' => 'executed']);
    }

    public function test_double_approve_is_single_use(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $run = $this->heldProposeClose($ticket);
        $approver = User::factory()->create();
        app(TechnicianApprovalService::class)->approveClose($run, $approver->id);
        $second = app(TechnicianApprovalService::class)->approveClose($run->fresh(), $approver->id);
        $this->assertSame('already_handled', $second->status);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.** **Step 3:** Add `approveClose` (mirror `approveAndSend`; close via the canonical `TicketService` method) + the controller branch. **Step 4: Run — expect PASS.** Pint + commit (`feat(agent): cockpit approve closes a propose_close through the gate`).

---

### Task 6: End-to-end spine + dormancy

**Files:**
- Test: `tests/Feature/Agent/BacklogAgentSpineTest.php`

**Interfaces:** none new — wires Tasks 1–5 together.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Agent;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Technician\TechnicianApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class BacklogAgentSpineTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_spine_event_to_close(): void
    {
        Setting::setValue('backlog_agent_enabled', '1');
        Setting::setValue('ai_api_key', 'x');
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id, 'status' => \App\Enums\TicketStatus::InProgress->value, 'updated_at' => now()->subDays(90),
        ]);

        // Agent decides to close.
        $this->mock(AiClient::class, fn (MockInterface $m) => $m->shouldReceive('runToolLoop')->andReturnUsing(
            fn ($s, $u, $t, $executor) => $executor('propose_close', ['reason' => 'resolved long ago', 'confidence' => 0.97]) ?: 'done'
        ));

        // Wake → agent → held proposal (run the job directly).
        (new \App\Jobs\RunBacklogAgent($ticket->id))->handle();
        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_close')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertNotContains($ticket->fresh()->status, [\App\Enums\TicketStatus::Resolved->value, \App\Enums\TicketStatus::Closed->value]); // still open until approved

        // Operator approves → closed.
        app(TechnicianApprovalService::class)->approveClose($run, User::factory()->create()->id);
        $this->assertContains($ticket->fresh()->status, [\App\Enums\TicketStatus::Resolved->value, \App\Enums\TicketStatus::Closed->value]);
    }

    public function test_dormant_when_disabled(): void
    {
        // flag unset
        Setting::setValue('ai_api_key', 'x');
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $this->mock(AiClient::class, fn (MockInterface $m) => $m->shouldReceive('runToolLoop')->never());
        (new \App\Jobs\RunBacklogAgent($ticket->id))->handle();
        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->count());
    }
}
```

- [ ] **Step 2: Run it — expect FAIL.** **Step 3:** fix any wiring so the spine passes (no new features — this is the integration gate). **Step 4: Run — expect PASS.** Then run the full suite: `php artisan test --filter=Agent`, `--filter=Technician`, `--filter=Triage`, then `php artisan test` (no regression). Pint + commit (`test(agent): end-to-end backlog-agent spine + dormancy`).

---

## Plan Self-Review

- **Spec coverage:** the increment = §7.5 of the recon (event-wake → agent → one gated `propose_close` → held → cockpit approve → gated close), dormant. Task 1 = the flag; Task 2 = the gated tool (the body's first ACT tool, gate-routed — closes the recon's "no ACT tool is gated" gap for one tool); Task 3 = the brain (tool loop); Task 4 = the event-source (paced sweep) + the wake job; Task 5 = the approve→execute path; Task 6 = the spine + dormancy. The architectural principles hold: adaptive judgment (the agent decides via the loop), controlled consequences (held + gate + operator approval), deterministic event-source (the sweep), dormant.
- **Deliberately deferred (NOT this increment):** the unified gate-aware tool registry (Task 2 establishes the *pattern* with one tool); the other action tools (draft_reply/escalate/mine_wiki_fact/ask_for_guidance); cross-ticket/decision memory; per-person identity over MCP; the Teams conversation/guidance loop; the update-observer event-source (this increment uses the paced sweep — the natural trigger for the *backlog onboarding pass*; live-ticket events come later). Each is its own later increment.
- **Placeholder scan:** the integration arms (Task 3's loop body, Task 5's close call) are specified by interface + the exact existing pattern to mirror (`recordHeld`, `approveAndSend`, the gate) + a behavior-pinning test, with the cited file to read — not vague TODOs. The implementer reads the cited file and matches the real signature (flagged explicitly where a name must be confirmed: `AiClient::runToolLoop` arg names, the `AiConfig` key, `TicketService`'s close method, `TechnicianRunState` cases, the client factory's operational default, the `TriageToolDefinitions` tool-def shape).
- **Type consistency:** `propose_close` is the single action_type string across Tasks 2/5/6; `AgentConfig::enabled()`, `sweepBatchSize()`, `staleDays()` are used identically; `TechnicianRun(action_type='propose_close', AwaitingApproval)` and the `hash('sha256','propose_close:'.ticketId.':'.content)` scheme match between the tool (Task 2) and the approve path (Task 5).
- **Blast radius:** dormant — the sweep + schedule + job all gate on `AgentConfig::enabled()` (false in prod); `propose_close` only ever records a held run + an `awaiting_approval` audit (its gate executor throws if auto-run); the cockpit branch only triggers on a `propose_close` run, of which none exist until the (gated) agent runs. Merging changes nothing until the flag flips.

## Sequencing note
Build Tasks 1→6 in order (each builds on the prior). After Task 6, full suite + an opus whole-branch review before the PR. Ships **dormant**; dog-food on the seeded dev backlog (the 50 tickets + assessments are still there) before any prod enable. This increment is the spine; subsequent increments grow the toolkit (more gated action tools), add memory, and tackle per-person identity for the Teams conversation.

---

# Change-Order — multi-lens panel review (2026-06-25)

> Five reviewers (forest / architecture / security / correctness / feasibility) verified the plan against the real repo. **All five verdicts: PROCEED-WITH-CHANGES.** The architecture, action choice (`propose_close`), deferrals, dormancy, and the reuse targets (gate / `recordHeld` / `approveAndSend` / cockpit / scopes / casts / factory defaults / sqlite trigger-skip / tool-def shape / `runToolLoop` arg order — empirically validated) **all hold**. One BLOCKER + a cluster of converged MAJORs must be folded in **before** the build. Apply CO-1..CO-13, then build Tasks 1→6 via subagent-driven-development. Each CO notes the lenses it converges from.

## CO-1 — BLOCKER — Fence the agent's tool surface (read-only allowlist + read-only executor) [ARCH-1, SEC-1, FEAS-1]
**Task 3.** The plan says "the existing **read** tools + `propose_close`" and "reuse `TriageToolExecutor` for reads," copying `TechnicalTriager:72-73`. But there is **no read-only accessor**: `TriageToolDefinitions::getTools()` (`:23-25`) **always** prepends `psaTools()`, which contains the **un-gated** `set_ticket_priority/status/category/keywords` (+ `tactical_run_diagnostic`); and `TriageToolExecutor::execute()` is a single `match` with no read/act split — `set_ticket_status` does a raw `$ticket->save()` close (`:236-248`) **bypassing the gate entirely** (no hold, no approval, no audit row). The executor closure — not the definitions — is the only enforcement seam (`AiClient` passes the model's tool name straight to it). As literally worded, the agent whose job is "decide whether to close" can just call `set_ticket_status: closed` and close directly, **defeating the entire increment's thesis** — and every plan test mocks `runToolLoop` driving only `propose_close`, so the tool list is never asserted and a leaking build is green.
- **Definitions:** build the agent's tool list from an **explicit read allowlist** + `ProposeCloseTool::definition()`. For this increment the "is this old ticket resolved/abandoned?" judgment needs ticket + notes + wiki: **`search_tickets`, `get_ticket_notes`, `wiki_list_pages`, `wiki_search`, `wiki_get_page`**. Implement as a named `TriageToolDefinitions::readTools()` (= `getTools()` minus every `set_ticket_*` and `tactical_run_diagnostic`) so the read set has one source of truth; do **NOT** call `getTools()` wholesale. *(Deliberately minimal — vendor/RMM read getters are out of scope for a close decision; widen later if dog-fooding shows the agent needs device telemetry to judge.)*
- **Executor:** new `app/Services/Agent/BacklogAgentToolExecutor.php` (read-only). `match`: `propose_close` → `ProposeCloseTool::execute`; an allowlisted **read** name → delegate to `TriageToolExecutor`'s read method; **`default => ['error' => 'tool not available to the backlog agent']`** — hard-refuse any other name (every `set_ticket_*`, `tactical_run_diagnostic`), **never** a default that delegates to `TriageToolExecutor::execute()`. Default-deny on both sides.
- **Tests (Task 3, the regression guard for the whole thesis):** (a) **adversarial** — drive `$executor('set_ticket_status', ['status'=>'closed'])` and `$executor('tactical_run_diagnostic', [...])` → assert `$ticket->fresh()->status` UNCHANGED, no `technician_action_logs` row, error returned; (b) **tool-list** — the mocked `runToolLoop` closure already receives `$tools` as arg #3; assert it contains `propose_close` and **no** name matching `/^set_ticket_/` and not `tactical_run_diagnostic`.

## CO-2 — MAJOR — Cockpit: action_type-aware controller + blade + flash + a route-level test [FOREST-1, ARCH-2, FEAS-2, CORR-3]
The feared "`pendingDrafts()` filters out `propose_close`" BLOCKER **does not exist** — `CockpitQuery::pendingDrafts()` has **no** `action_type` filter (`:25-36`), so the held proposal **does** surface. The real gaps are the reply-shaped operator surface, untested:
- `TechnicianCockpitController::approve()` runs `$request->validate(['body'=>['required','string']])` **before** any branch (`:23`) → a bodyless `propose_close` approval **422s**; and the flash selector is `status==='sent' ? success : error` with a `match` that has no `'closed'` arm (`:28-33`) → a **successful** `'closed'` shows the operator a **scary error**. FIX: branch on `$run->action_type==='propose_close'` **before** validating body; on that path call `approveClose($run, (int) $request->user()->id)` (no body required); treat `'closed'` as success and add a `'closed' => 'Ticket closed.'` arm. *(Preferred shape, also pre-empts if/else creep: push routing into `TechnicianApprovalService::approve($run, $approverId, ?string $body = null)` that dispatches on `action_type` and returns a result the controller maps generically, validating `body` required only on the reply arm. Minimum acceptable: the controller `if` branch.)*
- Add **`resources/views/cockpit/index.blade.php`** to the File-Structure "Modified" table with a `propose_close` arm: badge **"Proposed close"**, render `proposed_content` (the reason) **READ-ONLY**, an **"Approve close"** button (not "Send this"), and **suppress** the editable client textarea + the auto-disclosure note (`:23-25, :30-33, :43-46`).
- Add a **route-level test** (Task 5 or 6): `actingAs($user)->post(route('cockpit.approve', $run))` with **no** body for a held `propose_close` → `assertRedirect`, ticket closed, `technician_action_logs` `executed` row. Proves the operator-facing path, not just the service.
- Update `TechnicianApprovalResult` docblock (`:14`, currently `{sent, already_handled, gate_declined}`) → add `closed`.

## CO-3 — MAJOR — `approveClose`: releaseClaim-on-throw + stale-ticket race guard [ARCH-3, SEC-2, FEAS-4]
**Task 5.** `TicketService::changeStatus` **throws** `InvalidArgumentException` on a disallowed transition (`:95-100`); from an open status → `Resolved` is allowed (happy path), but if a human resolved/closed the ticket **between propose and approve**, `Resolved→Resolved` is not allowed → it throws **inside** the gate's `DB::transaction`.
- `approveClose` MUST wrap the gate dispatch in `try { … } catch (\Throwable $e) { $run->releaseClaim(); throw $e; }` (mirror `approveAndSend:86-90`) **and** `releaseClaim()` on any non-`executed` status — so a transient decline/throw leaves the run **retryable** (`AwaitingApproval`), never stranded in `Executing` (which `pendingDrafts()` filters out → operator can't retry/deny → bricked + 500).
- **Guard the race** in the gate executor: `if (! $ticket->fresh()->status->isOpen()) { return; }` (or map the `InvalidArgumentException` to a `gate_declined`-style no-op) **before** `changeStatus($ticket, TicketStatus::Resolved, TechnicianConfig::aiActorUserId(), …)`, then `$run->advanceTo(Done)`.
- **Tests:** (a) held `propose_close` whose ticket a human already resolved → `approveClose` returns `already_handled`/`gate_declined`, **no throw**, no duplicate `executed` row, run retryable; (b) kill-switch decline (`technician_kill_switch` on → `approveClose` → run back to `AwaitingApproval`, ticket open; off → approve → closed).
- **Verify-item:** confirm `changeStatus`'s `notifyStatusChanged` does no **client-facing** outbound send inside the gate tx (gate contract = external sends only after `executed`). CORR confirms it's a no-op for test tickets (no assignee/contact); in prod confirm it's staff-internal.

## CO-4 — MAJOR — Idempotency: replicate `recordHeld`'s revive guard (prefer a shared recorder) + propose-once [ARCH-4, SEC-6, FEAS-3]
**Task 2/3.** `ProposeCloseTool::execute` calls `gate->dispatch` **unconditionally** after `firstOrCreate`, but `recordHeld` (`:185-202`) only dispatches on fresh-create or revive (`wasRecentlyCreated`/state guard). So a second `execute()` with the same `(ticket, reason)` finds the existing run (no new row) **but dispatches again → a duplicate `awaiting_approval` audit row** — the exact 1B bug class.
- **PREFERRED:** extract `recordHeld`'s body into a shared `HeldActionRecorder::record(Ticket, actionType, content, meta, confidence, tokens, summary)` (carrying the `wasRecentlyCreated`/revive guard + the throwing tripwire) called by **both** `DraftPipeline` and `ProposeCloseTool` — one source of truth (closes the recon's "copied not shared" divergence one level down). **MINIMUM:** replicate the `wasRecentlyCreated`/revive guard inside `execute()`.
- `BacklogAgent` stops the tool loop **after the first `propose_close`** (its job is to propose once or leave it) — prevents the model emitting multiple `propose_close` with varied reasons (distinct hashes → multiple held runs) in one loop.
- **Test:** two `execute()` calls with the same reason → exactly **one** `awaiting_approval` log row.

## CO-5 — MAJOR — Test the dedup guard (sweep + job skip an existing held proposal) [FEAS-3]
**Task 4.** The "skip a ticket already carrying an open `propose_close` run" guard (sweep + job) is currently **untested**, and it's the only thing preventing a per-15-min-sweep storm. Add: (a) **sweep** test — a stale operational ticket that already has an `AwaitingApproval` `propose_close` run is **not** dispatched; (b) **job** test — `RunBacklogAgent::handle()` skips (`runToolLoop` `never()`) when an open `propose_close` run exists. Pin the predicate: `whereDoesntHave('technicianRuns', fn ($q) => $q->where('action_type','propose_close')->where('state', TechnicianRunState::AwaitingApproval->value))`.

## CO-6 — MAJOR — Fix the embedded test bugs: enum-vs-string + factory-defaults-Closed [CORR-1, CORR-2]
- **Enum comparison (Tasks 2, 5, 6):** `$ticket->fresh()->status` is a `TicketStatus` **enum instance**, not a string. PHPUnit 11 `assertContains` is strict-identity, so `assertContains($ticket->fresh()->status, [TicketStatus::Resolved->value, …])` **fails** on the positive asserts (Tasks 5/6) and the `assertNotContains` variants **pass vacuously** (Tasks 2/6 — testing nothing). Replace with **enum-to-enum**: `assertContains($ticket->fresh()->status, [TicketStatus::Resolved, TicketStatus::Closed])` / `assertNotContains(...)` (or `$status->isOpen()/isTerminal()` helpers).
- **`Ticket::factory()` defaults to `Closed`** (`status='closed'`, `closed_at=now()` — `TicketFactory.php:25,28`). Every test ticket that will be closed MUST set an explicit **open** status — fix Task 5 `test_double_approve_is_single_use` (`:406`) to `['client_id'=>$client->id, 'status'=>TicketStatus::InProgress->value]` (else the first `approveClose` throws on `Closed→Resolved` and the `'already_handled'` assertion is never reached). Add to the Global-Constraints runtime note: **"`Ticket::factory()` defaults to CLOSED — any open-ticket/close test MUST set an open status (e.g. `InProgress`)."**

## CO-7 — MAJOR — Tripwire test: `propose_close → auto` still never closes [SEC-3, SEC-4]
**Task 2.** The defense-in-depth holds against the real gate (a fat-fingered `propose_close→auto` runs the tripwire executor inside the tx → `LogicException` → rollback → no close, no committed `executed` row) but **no test pins it** (1A shipped the equivalent for sends). Add `test_propose_close_mapped_auto_still_never_closes`: `Setting::setValue('technician_action_tiers', json_encode(['propose_close'=>'auto']))`, call the tool, assert ticket **not** closed and **no** `result_status='executed'` row.
- *(Optional, recommended belt-and-suspenders [SEC-4]: additionally clamp `propose_close` to never classify as Auto — a tiny never-auto set the classifier honors, or `ProposeCloseTool` asserts `TechnicianTierClassifier::classify('propose_close') !== Auto` and fails closed. Keep the test regardless.)*

## CO-8 — MAJOR — Execute-time operational re-check in the job [SEC-5, CORR-6]
**Task 4.** `RunBacklogAgent::handle()` must re-check `operational()` (**Active AND is_active**) **and** `open` at execute time — not just the prospect check `RunTriagePipeline` uses (`:71` is prospect-only; a client deactivated between sweep and job would slip through, since the sweep selects with `operational()`). *(Optional: have the sweep also skip operator-excluded clients [EmergencySweep parity, `:83-85`] to avoid wasting an AI run on a client the gate will only ever hold for.)*

## CO-9 — MINOR — Job / DI wiring constraints (pinned by the tests) [CORR-4, CORR-5, FEAS-7]
- `RunBacklogAgent::$ticketId` MUST be **`public readonly int`** (Task 4 reads `$job->ticketId`; `RunTriagePipeline` declares it `private` — copying verbatim throws).
- `RunBacklogAgent::handle()` MUST be **parameterless** and resolve `app(BacklogAgent::class)->run($ticket)` internally (Task 6 calls `->handle()` with no args; the Task 3 mock binds only via the container). Do **not** mirror `RunTriagePipeline::handle(TriagePipeline $pipeline)` method-injection.
- `BacklogAgent` **constructor-injects `AiClient`** (never `new AiClient`) so the mock binds.

## CO-10 — MINOR — Negative-path + surfacing coverage [FOREST-5, FEAS-5, FEAS-6]
- **Deny test:** deny a held `propose_close` → ticket stays **OPEN**, run state `Denied`, no `executed` row (the defining negative guarantee for a close proposal).
- **Task 6:** assert `app(CockpitQuery::class)->pendingDrafts()->pluck('id')->contains($run->id)` — proves the read model surfaces the proposal (guards a future `action_type`-filter regression that would silently hide proposals).
- *(Optional)* assert a read name (`get_ticket_notes`) routes through the executor without throwing (the read arm is otherwise prod-exercised-only; note `TriageToolExecutor` requires the ticket to have a client — backlog tickets are on operational clients, so OK).

## CO-11 — MINOR — Proposal-storm DEPTH cap + cockpit lane ordering [FOREST-4]
- Add `AgentConfig::maxPendingProposals()` (Setting `backlog_agent_max_pending`, default **10**, floor 1). The sweep **stops dispatching** once that many `propose_close` runs are already `AwaitingApproval` — converts the rate-limit into a depth-limit so a backlog drain can't accumulate an unbounded approval pile.
- Once CO-2 makes the cockpit `propose_close`-aware, **sort/segment `propose_close` after client-facing sends** in `pendingDrafts()` (or give proposals their own count/section) so a backlog drain can never bury a time-sensitive reply approval — the flood lesson at the cockpit-depth layer. Add to the Sequencing note: **"Before any trip-time enable, confirm close proposals cannot bury client-reply approvals."**

## CO-12 — MINOR (no-build, legibility) — Make the architecture legible [FOREST-2, FOREST-3, ARCH-5]
- Add a paragraph (Architecture / Self-Review): *"We deviate from recon §7.5 (extend the triage agent) and instead start a dedicated `app/Services/Agent/` home, because (a) backlog-onboarding semantics differ from live-ticket triage, (b) blast-radius stays off the live triage path, (c) extending `TriageToolExecutor` would force-inherit its un-gated `set_ticket_*` mutators (CO-1) — the dedicated read-only executor is the safety boundary, and (d) this namespace is the intended home of the single unified agent the vision targets. `BacklogAgent` is that agent's first behavior, not a permanent task-specific fork."*
- **Pin Task 3's read-executor decision** to the one option from CO-1 (dedicated read-only executor + `readTools()` allowlist); delete the "reuse `TriageToolExecutor` for reads, **or** a thin read-only executor" ambiguity.
- Qualify **"event-woken" → "deterministically-woken (paced scheduled sweep)"** in the Goal/Self-Review; add a deferral line: *"Deferred (named next increment): the live `TicketObserver::updated()` event-class wake — the load-bearing seam for reacting to live tickets (recon §1.5/§6)."*
- *(Optional self-review line)* name `ProposeCloseTool::definition()` + the read-allowlist as the first entries destined for the future unified tool registry with explicit READ/ACT flags (recon §7.1.2) — the consolidation seam the next increment hoists rather than forks.

## CO-13 — NIT (notes) [SEC-7, CORR-7]
- **Append-only note** (Global Constraints / Task 6): append-only is a **prod-DB-trigger** guarantee (the `BEFORE UPDATE/DELETE … SIGNAL` triggers are `mysql`/`mariadb`-only and skipped on the sqlite test DB); on sqlite the invariant holds **by construction** because the gate is the **sole** writer and only `::create()`s. Keep it that way — no update/delete path on `technician_action_logs` in Tasks 2/5.
- *(NIT)* Task 5: `aiActorUserId()` falls back to the first user, which in the test equals the approver — harmless; optionally seed a distinct actor for realism.

## Fast-follows (post-merge, NOT this increment)
- **Retract/skip** held `propose_close` proposals whose ticket is no longer open (operator-noise + idempotent close) [FOREST-5].
- If CO-4 ships the minimum (guard-replication, not the shared `HeldActionRecorder`), **hoist** to the shared recorder when the next action tool lands.
- The unified gate-aware tool registry (READ/ACT-flagged) the read-allowlist + `ProposeCloseTool` seed (recon §7.1).

## Build note
Net-new surface added by this change-order: `BacklogAgentToolExecutor`, `TriageToolDefinitions::readTools()`, the cockpit `propose_close` controller branch + blade arm, `AgentConfig::maxPendingProposals()`, (preferred) `HeldActionRecorder`, and ~8 new/changed tests (adversarial mutator-refusal, tool-list assertion, tripwire-auto, dedup sweep+job, enum/factory fixes, stale-ticket race, deny, route-level cockpit, surfacing). This roughly doubles the test surface but every item is load-bearing for a **correct + safe** spine — CO-1 especially is non-negotiable before Task 3 builds.

---

# Design Revision 2 — review-ping entry + significance gate + confidence-tiered close (2026-06-25, post-panel, Charlie-directed)

> After the panel, Charlie made two architecture calls that **supersede** parts of the plan + CO above. This section is **authoritative where it conflicts** with anything earlier. Net effect: the agent's wake unifies onto the existing open-ticket review pass, and `propose_close` becomes a **confidence-tiered** action (auto+notify / held-approve / leave) — built now, but shipped with the **auto band OFF** so the dog-food phase stays held-only (the panel-validated posture) until the confidence score is calibrated. Everything in CO-1..CO-6, CO-8..CO-13 still applies unchanged; only the items called out below are superseded.

## R2.1 — Wake unifies onto the existing review pass [SUPERSEDES Task 4's standalone sweep + the Goal's "event-woken"/"sweep" language]
Drop the standalone `agent:backlog-sweep` command and the sweep-as-event-source. Stale dead tickets are just *open tickets*, which `triage:review-open` already visits every cycle (recon §1.5). The agent becomes a new, **flag-gated, additive consumer** of that existing pass — **NOT** a change to `ConversationReviewer`/`runReviewMode` (flag off → review behaves exactly as today; this preserves the blast-radius isolation the panel valued in the separate namespace).

Three-tier wake ladder, hung on the review entry:
1. **Deterministic pre-filter (free, 0 tokens):** open + operational + no existing open `propose_close` run. Only bounds tier-2 volume; **replaces the hard `staleDays` cutoff** — staleness becomes a tier-2 judgment (a ticket can be abandoned at 20d or alive at 90d).
2. **`SignificanceGate` (Haiku, single-shot, no tools):** *"is this open ticket plausibly resolved/abandoned and worth the agent's deeper look?"* → yes/no. Lightweight input (subject + last note + age + status), **escalate-when-unsure** (opposite of the Teams bot's silence-bias). **Strong reuse candidate:** `ConversationReviewer::review()` is already the cheap single-shot open-ticket judgment — recast it on Haiku to emit an "escalate to agent" outcome alongside its existing leave/recommend/close, OR a focused new gate; build's choice, but keep it cheap + isolated.
3. **The agent (Opus, read tools + `propose_close`):** fires only when tier-2 says yes. Produces a close recommendation **with a `confidence` score** (the field already in `ProposeCloseTool`) → drives the tier in R2.2.

(The deferred live-`TicketObserver::updated()` wake [CO-12] becomes a *second feeder* into the same gate in a later increment — same ladder, more event-sources. That's the "AI across more triggers, cost-effectively" payoff.)

## R2.2 — `propose_close` becomes confidence-tiered [SUPERSEDES Global Constraint #2 "the agent NEVER closes directly", Task 2's tripwire executor, and CO-7]
The Opus agent's `confidence` maps to one of three bands via config thresholds:
- **Auto band** (`confidence ≥ propose_close_auto_threshold`): the gate runs the **real close** immediately + **notifies** the operator (`OperatorNotifier`, Teams+email) + audits. "Closes on its own, with notification" — like today's review auto-close, but **audited + notified**.
- **Approve band** (`propose_close_approve_floor ≤ confidence < auto_threshold`): **held** → cockpit → `approveClose` (the panel-reviewed path).
- **Leave band** (`confidence < approve_floor`): the agent does **NOT** dispatch — leaves the ticket.

**Safety reconciliation (this deliberately relaxes "never auto-close" — and does it safely):**
- **Auto band DEFAULTS OFF.** `propose_close_auto_threshold` is **unset by default = +∞ = never-auto** → with the agent enabled but the threshold unset, **every** proposal holds. So Increment 1's *enabled* behavior is still **held-only** — the panel-validated posture. The auto band is a deliberate post-calibration config flip, mirroring how `technician_action_tiers` opt-in works.
- **Threshold floor:** `auto_threshold` cannot be set below a hard floor (e.g. **0.90**) — you can't accidentally auto-close low-confidence even by misconfig.
- **Existing gate guards still PRE-EMPT the auto band (no new code):** kill-switch → held; per-client **always-human** → held regardless of confidence (recon §5.1); per-client exclusion → held.
- The `propose_close` executor is now the **real close** (`changeStatus` → Resolved, **with CO-3's re-check-open guard + releaseClaim-on-throw**), **NOT** a tripwire. It runs only when the gate authorizes it (auto-above-threshold, or post-approval).
- **Implementation:** keep the gate the **SOLE** execute/audit chokepoint. Make `TechnicianTierClassifier` **confidence-aware for `propose_close` only** (reads the two thresholds; **all other action types keep the existing static-config path, unchanged** — no behavior change for `send_ack`/`send_reply`/etc.). Carry the agent's confidence to the gate (add an optional `?float $confidence` to `dispatch`, or read it from the run's `confidence`).
- **Everything else stays required:** CO-1 (read-only fence), CO-2 (cockpit), CO-3 (approveClose robustness), CO-4 (idempotency) are **unchanged**.

## R2.3 — Close-ownership: the agent's path supersedes the review's confidence-auto-close
When the agent is enabled, **disable `ConversationReviewer`'s confidence-auto-close** — the agent's confidence-tiered, audited, notified path replaces it (one close path, not two; the audited human-approvable one wins). Review keeps its non-close work (recommendation notes, contact-resolve, etc.). Make this an explicit config interaction so the two never double-handle a ticket.

## R2.4 — CO-7′ [SUPERSEDES CO-7] — confidence-band tests
- **Auto band OFF (default):** any confidence → **held** (never auto-closes), incl. confidence `1.0`. *(Preserves the dog-food posture — this is the new "core claim" test.)*
- **Auto band ON + confidence ≥ threshold + normal client →** auto-closes + notifies + exactly one `executed` audit row.
- **Auto band ON + confidence in the approve band →** held.
- **Auto band ON + high confidence + always-human client →** **held** (always-human overrides confidence).
- **Kill-switch ON + high confidence →** held.
- **`auto_threshold` set below the floor →** rejected/clamped to the floor.

## R2.5 — Enablement / rollout (data-driven, dormant-first)
Ship **dormant** (`agent_enabled` off) AND auto band off (threshold unset). Dog-food on the 50 seeded tickets in **held-only** mode → every approve/deny is a labeled calibration point → measure approve-rate by confidence band → set `propose_close_auto_threshold` where the held approve-rate is ~100% → enable the auto band. **The held-only phase IS the calibration that earns the auto band.**

## R2.6 — Re-review before build
The confidence-tiered auto-close is the most safety-sensitive surface in the project (first time the agent can close without a synchronous human — even if gated by opt-in + threshold + floor + notify + audit + always-human override). **Before building, run a focused security + correctness review of R2.1–R2.4 only** (the rest already cleared the full panel).

## R2.7 — Task deltas (what changes in Tasks 1–6)
- **Task 1 (`AgentConfig`):** add `proposeCloseAutoThreshold()` (Setting `propose_close_auto_threshold`, default **unset/null = never-auto**, floor 0.90 when set) + `proposeCloseApproveFloor()` (Setting `propose_close_approve_floor`, default e.g. 0.50). Drop `staleDays()` (no longer a hard cutoff). Keep `enabled()`, `maxPendingProposals()` (CO-11).
- **Task 2 (`ProposeCloseTool`):** executor is the **real close** path (no tripwire); dispatch carries `confidence`; keep the CO-4 idempotency guard.
- **Task 4 (was sweep+job):** **replaced** by the review-ping additive branch + the `SignificanceGate` (Haiku). The per-ticket wake job stays (now dispatched from the review pass under the dormant flag), with CO-8's execute-time operational re-check.
- **Task 5 (`approveClose` + cockpit):** unchanged from CO-2/CO-3 (the approve band still flows here).
- **Tasks 3 & 6:** unchanged except the spine test now also covers the auto band (R2.4) and the held band.
- **New:** `SignificanceGate` service (Haiku) + its tests; the `TechnicianTierClassifier` confidence-aware extension + its tests (R2.4); the review-ping branch + its test (additive, flag-gated, dormant-off → no-op).

---

# Design Revision 2 — re-review change-order (CO-14..CO-25, 2026-06-25)

> Focused security (`sec2`) + correctness/integration (`corr2`) re-review of R2.1–R2.4. **Both PROCEED-WITH-CHANGES**; every R2 seam verified to EXIST + compose against real code (no fictional mechanism). Two BLOCKERs (both silently invert "auto OFF by default") + the coverage-starvation finding must land before Task 1/2/4 build. Applies alongside CO-1..CO-13 (still required). Three items carry a **product/scope decision** for Charlie — flagged inline.

## CO-14 — BLOCKER — `propose_close` tier decided EXCLUSIVELY by the confidence path (no `tierMap` fallthrough) [SEC2-1, CORR2-1]
`TechnicianTierClassifier::classify()` must, **for `propose_close` only**, short-circuit and `return` BEFORE the static `technician_action_tiers` match: `tier = (autoThreshold !== null && confidence >= autoThreshold) ? Auto : Approve` (optionally still honor an explicit `'block'` as a kill). Otherwise an operator setting `technician_action_tiers={"propose_close":"auto"}` (the only "make it auto" UI they know) → Auto at ANY confidence, bypassing the threshold AND the 0.90 floor. All other action types keep the existing `tierMap` path unchanged. **Test (R2.4 case 1+):** threshold UNSET **and** `technician_action_tiers={"propose_close":"auto"}` → still **HELD** (no `executed` row) — proves the threshold, not the map, owns the auto decision.

## CO-15 — BLOCKER — null-preserving auto-threshold reader (absent ≠ 0.90) [SEC2-2]
`AgentConfig::proposeCloseAutoThreshold(): ?float` MUST NOT mirror the int-floor `max(floor,(int)$value)` idiom — that collapses absent→null→`0.0`→`max(0.90,0.0)=0.90` = **auto ON by default** (the inverse of the design's linchpin). Implement null-preserving: `$raw = Setting::getValue('propose_close_auto_threshold'); if ($raw === null || trim((string)$raw) === '') return null; return max(0.90, (float)$raw);`. The confidence-aware classifier treats `null` as never-auto (+∞). **Pin in Task 1:** "this reader deliberately does NOT mirror the int-floor idiom — absent stays null." **Test:** Setting genuinely ABSENT → held at confidence `1.0` (kept DISTINCT from "set below floor → clamped to 0.90").

## CO-16 — MAJOR — agent-side per-ticket cooldown (coverage + Haiku spend) [CORR2-3, the single biggest integration concern]
`TriageReviewOpen` iterates `priority_order, updated_at, take($limit)` with only a 4h-human-touch filter; the review's own cooldown lives downstream in `ConversationReviewer::isWithinCooldown()` (inside the job), which the agent branch does NOT inherit. R2.1's pre-filter only skips tickets with an existing open `propose_close` run — which never fires for should-STAY tickets. So without an agent cooldown, the deterministic head batch is re-judged by Haiku **every pass forever** and the agent **never advances past the head** → dog-food only ever touches ~`reviewBatchSize` tickets. **Add a per-ticket "last-evaluated-at" cooldown checked FIRST in `RunBacklogAgent::handle()` (before the Haiku gate)** — mirror `ConversationReviewer::COOLDOWN_HOURS` or persist a marker — so the agent sweeps the whole backlog and a should-stay pile can't generate recurring Haiku spend. Pin in R2.1's pre-filter.

## CO-17 — MAJOR — document the wake's coupling to the review pass [CORR2-2]
The agent rides `TriageReviewOpen`, which early-returns unless `TriageConfig::isEnabled()` + `autoReviewEnabled()` + `AiConfig::isConfigured()` (`:21,:27,:33`); the schedule is `->when(autoReviewEnabled() && throttle)`. So `agent_enabled=true` + `triage_auto_review=false` ⇒ the agent never wakes. **Document in R2.5 as a hard enablement prerequisite:** the agent wakes only when the review pass runs (`triage_enabled` + `triage_auto_review` + AI configured). Accept the coupling — it's the intended consequence of unifying onto the review pass.

## CO-18 — MAJOR (＊product decision) — close to `Closed`, not `Resolved`, to avoid an autonomous client "resolved" email [SEC2-3]
`changeStatus → Resolved` queues a **client-facing** `SendPortalNotification('status_resolved')` (`NotificationService.php:184-190`, no `Closed` arm); the existing review auto-close goes to `Closed` and sends nothing to the client. With the auto band ON that's an autonomous client send during the trip. **DECIDED (Charlie, 2026-06-25): close to `Closed`** — silent, no client email (matches the existing review auto-close, fits the hold-client-sends posture). Supersedes CO-3/the plan's `Resolved`; CO-3's race-guard + releaseClaim still apply. **Test that the auto AND approve close paths send NO client `status_resolved` notification.**

## CO-19 — MAJOR (＊scope decision) — deterministic auto-eligibility backstop (don't trust a self-reported scalar) [SEC2-5]
The auto trigger is the Opus agent's own `confidence`, and the floor is on that same scalar — a prompt-injected ticket can inflate it past 0.90. Add a **deterministic, model-independent precondition** for the auto band (mirror `EmergencyDetector`'s clamp — signals the AI can't lower): e.g. **no inbound `EndUser` note in ≥ N days AND ticket not in an awaiting-us state.** Confidence becomes necessary-not-sufficient. **DECIDED (Charlie, 2026-06-25): build the deterministic precondition WITH the auto band.** The auto path refuses the Auto tier unless deterministic signals agree (e.g. **no inbound `EndUser` note in ≥ N days AND the ticket is not in an awaiting-us state**); confidence is necessary-not-sufficient, so an injection-inflated scalar alone can never auto-close. **Test:** high confidence + recent inbound client activity / awaiting-us state → held, never auto.

## CO-20 — MAJOR (＊product decision) — implement & pin R2.3 (review auto-close disable); reconcile with R2.1 [SEC2-6, CORR2-7]
The review auto-close is `ConversationReviewer::takeAction()` behind `if (! reviewAutoCloseEnabled()) return null;` (`:96`), closing UN-GATED via `changeStatus(Closed)` (`:131`). Disabling it is a one-line guard there — but it IS a `ConversationReviewer` edit, so **reconcile R2.1's "no change to ConversationReviewer" → scope it to the WAKE/iteration; the one surgical edit is gating off the auto-close arm.** **DECIDED (Charlie, 2026-06-25): disable review auto-close on `agent_enabled`** — `ConversationReviewer::takeAction:96` gains `|| AgentConfig::enabled()`. The agent owns ALL closes, so the held-only phase generates full-range approve/deny calibration data; held-proposal volume is paced by CO-11 `maxPendingProposals` + CO-16 cooldown. **Document the volume + the flag-flip behavior change in R2.5. Test:** agent enabled → review auto-close is a no-op (even for a high-confidence `resolved` assessment).

## CO-21 — MAJOR — `OperatorNotifier` fires post-`executed`, in the caller, never in the executor [SEC2-4, CORR2-5]
`OperatorNotifier::notify()` is a synchronous Teams POST + email. **Inject it into `ProposeCloseTool`**; on the auto path call `notify(...)` **after** `gate->dispatch` returns, **guarded by `$result->status === 'executed'`** — never inside the executor closure (external I/O in the gate's `DB::transaction`; and a false "auto-closed" notice if always-human/kill-switch flips it to held). The CO-3 re-check-open guard belongs in **both** the tool's auto executor AND `approveClose`'s executor. **Test:** always-human client + auto threshold + high confidence → held + **no** "auto-closed" notification.

## CO-22 — MINOR — `SignificanceGate` is a NEW class with an injectable Haiku model (don't recast `ConversationReviewer`) [CORR2-4]
`AiClient` model selection is per-instance (constructor `?string $modelOverride`), not per-call, and `AiConfig::DEFAULTS` has **no Haiku id**. Add `AgentConfig::significanceModel()` (Setting, default a Haiku id added to config) and **constructor-inject a Haiku-configured `AiClient`** into `SignificanceGate` (so it's mockable in tests). **Build a NEW gate** — recasting `ConversationReviewer` conflicts with R2.1's "no change" + R2.3 (use it only as a single-shot reference shape).

## CO-23 — MINOR — auto no-op stale guard advances the run [SEC2-7]
On the auto path, if a human closed the ticket between agent-read and execute, the executor's `if (! $ticket->fresh()->status->isOpen()) return;` fires without throwing → the gate still commits an `executed` row but the `AwaitingApproval` run lingers (cockpit shows "close an already-closed ticket"). In the guard, `advanceTo(Done)` (the goal state is reached) so the run doesn't linger.

## CO-24 — MINOR — housekeeping: delete dangling sweep / `staleDays` / `sweepBatchSize` [CORR2-6]
Remove `AgentConfig::staleDays()` + its Task 1 test assertions (replace with the two threshold assertions); drop/repurpose `sweepBatchSize()` (the agent rides `TriageConfig::reviewBatchSize()`); **DELETE** the `AgentBacklogSweep` command + its `routes/console.php` schedule line + `AgentBacklogSweepTest` (the dropped `agent:backlog-sweep`), replaced by the review-ping branch + a command-level test (branch dispatches `RunBacklogAgent` iff `AgentConfig::enabled()`). Confirm no `staleDays`/`sweepBatchSize`/`agent:backlog-sweep` references survive.

## CO-25 — NIT — R2.5 reassurance: flipping auto ON does not retro-close the held backlog [SEC2-8]
The pre-filter skips tickets with an open `propose_close` run (CO-5) and CO-4 won't re-dispatch an existing `AwaitingApproval` run, so held proposals only ever close via operator approval — no mass-close when the threshold is first set. State this in R2.5.

## Confirmed sound (both re-reviewers, vs real code)
Every R2 seam exists + composes: the review-ping additive dispatch spot (`TriageReviewOpen.php:64-68`), `runReviewMode` untouchable, per-instance Haiku `AiClient` override (precedent `TriagePipeline.php:71`), the confidence-aware classifier (single caller `Gate:62`; appending `?float $confidence=null` to `dispatch` breaks no caller — all 4 use named args; `TechnicianRun.confidence` exists for the read-from-run alternative), the gate guards pre-empting Auto (kill-switch `:65,98` / exclusion `:70` / always-human `:80-81`), atomic+audited close, `OperatorNotifier` injectable post-gate, the null-when-unset threshold idiom, and all six R2.4 cases expressible on sqlite once CO-14 pins classifier self-containment.
