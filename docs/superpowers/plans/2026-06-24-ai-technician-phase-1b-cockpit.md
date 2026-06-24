# AI Technician — Phase 1B (Cockpit + Approval Round-Trip) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the away operator a single mobile screen — the **cockpit** — where, in a few minutes, they see everything needing them (held AI drafts to approve/edit/deny **and** the active-client tickets the AI couldn't draft), approve a client send with one deliberate, exactly-once tap, and where AI authorship is visibly disclosed in both the staff timeline and the client portal — plus the **multi-turn** fix so a client's reply re-opens drafting instead of going silent.

**Architecture:** A new authenticated `GET /cockpit` (Blade + thin controller, no Livewire) reads held `TechnicianRun`s (`state = awaiting_approval`) as the approval queue and a "Needs you" lane (open active-client tickets the AI acked but never drafted, or whose draft was denied). Approving routes through the **existing `TechnicianActionGate`** exactly as Phase 0/1A intended — the cockpit issues an identity-bound `TechnicianApprovalGrant` for `auth()->id()`, atomically claims the run (`awaiting_approval → executing`, the single-use latch), dispatches the gate with the grant + an executor that creates the AI-authored reply note and advances the run to `done`, then sends the email *after* the gate transaction (mirroring `AutoAcknowledge`). The `ai_authored` flag (persisted since Phase 0, rendered nowhere) gets a disclosure badge in `tickets/show.blade.php` and `portal/tickets/show.blade.php`. The Loop is re-triggered from `EmailService::linkEmailToTicket` + `TicketService::addPortalReply`, and `DraftPipeline` is re-keyed so an **unaddressed** client reply produces a fresh draft and supersedes the stale one.

**Tech Stack:** PHP 8.3, Laravel 12, server-rendered Blade (`@extends('layouts.app')`, Bootstrap, the `.ticket-card` mobile pattern), Eloquent, PHPUnit feature tests on sqlite `:memory:`, Laravel Pint. **No Livewire/Inertia/JS framework** — POST forms with `@csrf`, flash via `session('success'|'error')`.

## Global Constraints

These apply to **every** task; each task's requirements implicitly include this section.

- **Builds on Phase 1A (PR #57).** Branch off `main` **after #57 merges**, or off `feat/ai-technician-phase-1a`. All 1A symbols (`TechnicianRun` + draft columns, `TechnicianActionGate`, `TechnicianApprovalGrant`, `TechnicianDisclosure`, `DraftPipeline`, `RunTechnicianLoop`, `TechnicianConfig`) already exist.
- **Runtime:** PHP 8.3 / Laravel 12. Tests: sqlite `:memory:` (`RefreshDatabase`, factories, `Person::create([...])` — **no `PersonFactory`**; `Setting::setEncrypted('ai_api_key','test-key')` for `AiConfig::isConfigured()`). Pint-clean (`./vendor/bin/pint --dirty`) before each commit.
- **No RBAC exists (verified).** Any authenticated staff user may approve; the `auth` middleware is the only gate. The **identity** of the approver (`auth()->id()`) is bound into the grant and the audit — that is the accountability, not an authorization layer.
- **The gate stays the sole send path (spec §4.3).** The cockpit/controller hold no `EmailService` send call *inside* a gate transaction; the approved email is sent **after** `dispatch()` returns `executed`, exactly as `AutoAcknowledge` does. Every approved send still flows through `TechnicianActionGate::dispatch` with a valid grant.
- **One deliberate tap per send (operator decision).** No bulk-approve. The approval card shows the **exact outgoing text first**; the word "Send"/"approve" never appears without the full body above it. Throughput comes from a fast card + an urgency sort, not batch actions.
- **Single-use is enforced by the run-state latch, not a nonce table.** Approve performs an atomic compare-and-swap `awaiting_approval → executing` (`claimForExecution()`); a second tap (or a replayed grant within its 600s TTL) finds the run no longer `awaiting_approval` and is rejected. This resolves review backlog #3 (grants are replay-within-TTL) without new schema.
- **Structural disclosure is mandatory on every approved send.** The approved reply body is wrapped via `TechnicianDisclosure::withDisclosure($body, TechnicianConfig::aiActorName())` and `assertPresent()`-checked **before** the gate — identical to the ack. The recipient is **re-derived from `$ticket->contact?->email`**, never from the stored `proposed_meta['to']` (the 1A content_hash contract: the grant binds the body; the sender re-derives recipient + re-appends disclosure).
- **Edited drafts re-bind.** If the operator edits the body, the grant is issued against `hash` of the **submitted** body and that exact body is sent — the run row's original `content_hash` is *not* mutated (it is only the creation idempotency key; mutating it would collide with the `(ticket_id, action_type, content_hash)` unique index).
- **`ai_authored` must render distinctly (spec §6/§7).** The AI-authored badge is a label + icon (not color-only — a11y), in the staff timeline **and** the portal, so neither staff nor client mistakes an AI message for a human's.
- **Multi-turn must not double-draft.** The reply hook + the re-keyed pipeline must (a) draft a fresh reply only when there is a client message newer than the latest draft, and (b) supersede the stale held draft so the cockpit never shows two drafts for one ticket. Re-dispatch on a mere job retry must still be a no-op.
- **Dormant-safe.** Everything new is gated by `TechnicianConfig::enabled()` where it dispatches/acts; merging while disabled changes nothing the operator sees except a `/cockpit` page that lists zero items.

---

## File Structure

**Created:**

| Path | Responsibility |
|------|----------------|
| `app/Services/Technician/Cockpit/CockpitQuery.php` | The cockpit's read model: `pendingDrafts()` (held runs, urgency-sorted, eager-loaded), `needsAttention()` (active-client tickets the AI acked but has no live draft for), `pendingCount()` (nav badge). Pure queries, independently testable. |
| `app/Services/Technician/TechnicianApprovalService.php` | The send round-trip: `approveAndSend(TechnicianRun, string $body, int $approverId): TechnicianApprovalResult` — CAS-claim the run, issue the grant, dispatch the gate with the note-creating executor, send the email after `executed`. The single chokepoint for an approved send. `deny(TechnicianRun): void`. |
| `app/Http/Controllers/Web/TechnicianCockpitController.php` | Thin: `index()` (renders the console), `approve(Request, TechnicianRun)`, `deny(TechnicianRun)`. |
| `resources/views/cockpit/index.blade.php` | The console: the approval queue (send-text-first cards, editable body, Send/Hold) + the "Needs you" lane; desktop + `.ticket-card` mobile. |
| `tests/Feature/Technician/Cockpit/CockpitQueryTest.php` | Tests Task 2. |
| `tests/Feature/Technician/Cockpit/TechnicianApprovalServiceTest.php` | Tests Task 3 (the safety-critical round-trip + single-use). |
| `tests/Feature/Technician/Cockpit/CockpitControllerTest.php` | Tests Task 4 + 5 (routes, approve/deny, page render). |
| `tests/Feature/Technician/Cockpit/AiAuthoredBadgeTest.php` | Tests Task 7 + 8 (staff + portal badge). |
| `tests/Feature/Technician/Cockpit/ClientReplyReopensDraftTest.php` | Tests Task 9 + 10 (reply hook + re-keying). |

**Modified:**

| Path | Change |
|------|--------|
| `app/Enums/TechnicianRunState.php` | Add `Denied = 'denied'`, `Superseded = 'superseded'`. |
| `app/Models/TechnicianRun.php` | Add `claimForExecution(): bool` (atomic CAS latch) + `deny(): void` + `markSuperseded(): void`. |
| `routes/web.php` | Three routes in the `auth` group: `cockpit.index`, `cockpit.approve`, `cockpit.deny`. |
| `resources/views/components/sidebar.blade.php` | A "Cockpit" nav item with a pending-count badge. |
| `resources/views/tickets/show.blade.php` | Render the `ai_authored` badge on AI-authored notes. |
| `resources/views/portal/tickets/show.blade.php` | Render the `ai_authored` badge on AI-authored notes (client-visible). |
| `app/Services/EmailService.php` | In `linkEmailToTicket`, after persisting the inbound client reply note, dispatch `RunTechnicianLoop` (guarded). |
| `app/Services/TicketService.php` | In `addPortalReply`, after persisting the reply note, dispatch `RunTechnicianLoop` (guarded). |
| `app/Services/Technician/DraftPipeline.php` | Replace the blanket `alreadyDrafted` short-circuit with "draft only for an **unaddressed** client reply"; supersede the stale held `send_reply` run. |

---

## Tasks

### Task 1: Run states + the single-use latch on the model

**Files:**
- Modify: `app/Enums/TechnicianRunState.php`
- Modify: `app/Models/TechnicianRun.php`
- Test: `tests/Feature/Technician/Cockpit/CockpitQueryTest.php` (a small state-machine test block; the query tests come in Task 2 — create the file here with just the latch tests)

**Interfaces:**
- Consumes: `App\Enums\TechnicianRunState`.
- Produces:
  - `TechnicianRunState` gains `Denied = 'denied'` and `Superseded = 'superseded'`.
  - `TechnicianRun::claimForExecution(): bool` — atomic compare-and-swap: `where id=this AND state='awaiting_approval' update state='executing'`; returns true iff exactly one row changed (this caller won the latch). On true, `$this->state` is refreshed to `Executing`.
  - `TechnicianRun::deny(): void` — `advanceTo(TechnicianRunState::Denied)`.
  - `TechnicianRun::markSuperseded(): void` — `advanceTo(TechnicianRunState::Superseded)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Technician/Cockpit/CockpitQueryTest.php`:

```php
<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CockpitQueryTest extends TestCase
{
    use RefreshDatabase;

    private function heldRun(): TechnicianRun
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Hello, we can help.',
        ]);
    }

    public function test_claim_for_execution_is_won_once(): void
    {
        $run = $this->heldRun();

        $this->assertTrue($run->claimForExecution());
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state);

        // A second claim (replay / double-tap) loses — the run is no longer awaiting.
        $this->assertFalse($run->fresh()->claimForExecution());
    }

    public function test_deny_and_supersede_transitions(): void
    {
        $a = $this->heldRun();
        $a->deny();
        $this->assertSame(TechnicianRunState::Denied, $a->fresh()->state);

        $b = $this->heldRun();
        $b->markSuperseded();
        $this->assertSame(TechnicianRunState::Superseded, $b->fresh()->state);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CockpitQueryTest`
Expected: FAIL — `Denied`/`Superseded` cases and `claimForExecution`/`deny`/`markSuperseded` methods don't exist.

- [ ] **Step 3: Add the enum cases**

In `app/Enums/TechnicianRunState.php`, add the two cases (after `Done`):

```php
    case Done = 'done';
    case Denied = 'denied';
    case Superseded = 'superseded';
```

- [ ] **Step 4: Add the latch + transitions to the model**

In `app/Models/TechnicianRun.php`, add these methods (after `advanceTo`):

```php
    /**
     * Single-use latch (Plan 1B): atomically move awaiting_approval → executing.
     * Returns true only for the caller that won the race; a replayed grant or a
     * double-tap finds the run no longer awaiting and gets false (no double-send).
     */
    public function claimForExecution(): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->update(['state' => TechnicianRunState::Executing->value]) === 1;

        if ($claimed) {
            $this->state = TechnicianRunState::Executing;
        }

        return $claimed;
    }

    public function deny(): void
    {
        $this->advanceTo(TechnicianRunState::Denied);
    }

    public function markSuperseded(): void
    {
        $this->advanceTo(TechnicianRunState::Superseded);
    }
```

(`TechnicianRunState` is already imported in this model.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CockpitQueryTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Enums/TechnicianRunState.php app/Models/TechnicianRun.php tests/Feature/Technician/Cockpit/CockpitQueryTest.php
git commit -m "feat(technician): run states (denied/superseded) + single-use claim latch

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: The cockpit read model (`CockpitQuery`)

**Files:**
- Create: `app/Services/Technician/Cockpit/CockpitQuery.php`
- Test: `tests/Feature/Technician/Cockpit/CockpitQueryTest.php` (append)

**Interfaces:**
- Consumes: `App\Models\TechnicianRun`, `App\Models\Ticket`, `App\Enums\{TechnicianRunState, TicketStatus, WhoType, NoteType}`.
- Produces:
  - `App\Services\Technician\Cockpit\CockpitQuery`:
    - `public function pendingDrafts(): \Illuminate\Support\Collection` — `TechnicianRun` rows at `AwaitingApproval`, eager-loading `ticket.client` + `ticket.contact`, **urgency-sorted**: overdue (ticket `due_at` past) first, then oldest `created_at` first.
    - `public function needsAttention(): \Illuminate\Support\Collection` — open, **active-client** `Ticket`s that the AI **acked** (an `ai_authored` `Reply` note exists) but have **no** `send_reply` run currently `AwaitingApproval` (i.e. not-ownable, or the draft was denied/superseded), and where no non-AI staff reply exists after that ack. Eager-load `client` + `contact`. Oldest-activity first.
    - `public function pendingCount(): int` — `TechnicianRun::where('state', AwaitingApproval)->count()`.

- [ ] **Step 1: Write the failing test (append to `CockpitQueryTest`, before the closing brace)**

```php
    public function test_pending_drafts_are_urgency_sorted_and_count_matches(): void
    {
        $query = app(\App\Services\Technician\Cockpit\CockpitQuery::class);
        $this->assertSame(0, $query->pendingCount());

        $client = Client::factory()->create();
        $old = Ticket::factory()->create(['client_id' => $client->id, 'due_at' => now()->addDays(5)]);
        $overdue = Ticket::factory()->create(['client_id' => $client->id, 'due_at' => now()->subDay()]);

        foreach ([$old, $overdue] as $t) {
            TechnicianRun::create([
                'ticket_id' => $t->id, 'client_id' => $client->id,
                'action_type' => 'send_reply', 'content_hash' => hash('sha256', 'r'.$t->id),
                'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => 'draft',
            ]);
        }

        $drafts = $query->pendingDrafts();
        $this->assertSame(2, $query->pendingCount());
        // Overdue ticket's draft sorts first.
        $this->assertSame($overdue->id, $drafts->first()->ticket_id);
    }

    public function test_needs_attention_lists_acked_but_undrafted_active_client_tickets(): void
    {
        $query = app(\App\Services\Technician\Cockpit\CockpitQuery::class);
        $client = Client::factory()->create(); // active
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'status' => \App\Enums\TicketStatus::New]);

        // The AI acked it (ai_authored Reply note) but produced no held draft → needs a human.
        \App\Models\TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'Chet', 'who_type' => \App\Enums\WhoType::Agent,
            'ai_authored' => true, 'body' => 'ack', 'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false, 'noted_at' => now(),
        ]);

        $needs = $query->needsAttention();
        $this->assertTrue($needs->contains('id', $ticket->id));

        // Once a held draft exists, it leaves the "needs you" lane (it's in the queue instead).
        TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('b', 64), 'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => 'd',
        ]);
        $this->assertFalse($query->needsAttention()->contains('id', $ticket->id));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CockpitQueryTest`
Expected: FAIL — `Class "App\Services\Technician\Cockpit\CockpitQuery" not found`.

> Before writing the implementation, confirm two schema facts against the tree and adjust the code below if they differ: (a) the ticket due-date column name — grep `app/Models/Ticket.php` for `due_at`/`due_date`/`sla` and use the real one (the test uses `due_at`); (b) the "open" statuses — read `app/Enums/TicketStatus.php` for the non-terminal cases and use them in `openStatuses()` below. If `due_at` doesn't exist, sort by `created_at` only and drop the overdue clause (and the overdue assertion in the test).

- [ ] **Step 3: Write the query service**

Create `app/Services/Technician/Cockpit/CockpitQuery.php`:

```php
<?php

namespace App\Services\Technician\Cockpit;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * The cockpit's read model (Plan 1B). Two lanes the away operator must see in
 * one place: the held drafts to approve, and the tickets the AI could NOT draft
 * (so nothing falls through). Pure queries — no side effects.
 */
class CockpitQuery
{
    public function pendingCount(): int
    {
        return TechnicianRun::where('state', TechnicianRunState::AwaitingApproval->value)->count();
    }

    public function pendingDrafts(): Collection
    {
        return TechnicianRun::query()
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->with(['ticket.client', 'ticket.contact'])
            ->get()
            ->sortBy(fn (TechnicianRun $run) => [
                $this->isOverdue($run->ticket) ? 0 : 1,        // overdue first
                optional($run->created_at)->getTimestamp() ?? 0, // then oldest
            ])
            ->values();
    }

    public function needsAttention(): Collection
    {
        $openStatuses = $this->openStatuses();

        return Ticket::query()
            ->whereIn('status', $openStatuses)
            ->whereHas('client', fn ($q) => $q->where('is_active', true))
            // The AI acked it (an AI-authored reply note exists)...
            ->whereHas('notes', fn ($q) => $q->where('ai_authored', true)->where('note_type', NoteType::Reply->value))
            // ...but there is no LIVE held reply draft for it...
            ->whereDoesntHave('technicianRuns', fn ($q) => $q
                ->where('action_type', 'send_reply')
                ->where('state', TechnicianRunState::AwaitingApproval->value))
            // ...and no non-AI staff reply has been added since (a human already engaged).
            ->whereDoesntHave('notes', fn ($q) => $q
                ->where('note_type', NoteType::Reply->value)
                ->where('ai_authored', false)
                ->where('who_type', WhoType::Agent->value))
            ->with(['client', 'contact'])
            ->orderBy('updated_at')
            ->get();
    }

    private function isOverdue(?Ticket $ticket): bool
    {
        return $ticket?->due_at !== null && $ticket->due_at->isPast();
    }

    /** @return array<int,int> the non-terminal ticket status values */
    private function openStatuses(): array
    {
        return collect(TicketStatus::cases())
            ->reject(fn (TicketStatus $s) => in_array($s, [TicketStatus::Closed, TicketStatus::Resolved], true))
            ->map(fn (TicketStatus $s) => $s->value)
            ->all();
    }
}
```

> **`Ticket::technicianRuns()` does NOT exist yet — add it in this task** (verified absent): `public function technicianRuns(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(TechnicianRun::class); }`. `Ticket::notes()` and `due_at` (datetime cast) are confirmed present. `TicketStatus` cases (verified): `New`, `InProgress`, `PendingClient`, `PendingThirdParty`, `Resolved`, `Closed` — there is **no `Open`**; `openStatuses()` correctly rejects only `Closed` + `Resolved`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CockpitQueryTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/Technician/Cockpit/CockpitQuery.php app/Models/Ticket.php tests/Feature/Technician/Cockpit/CockpitQueryTest.php
git commit -m "feat(technician): cockpit read model — held drafts + needs-you lane

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: The approved-send round-trip (`TechnicianApprovalService`) — single-use, gated, disclosed

This is the safety-critical task: it is the only place a held draft becomes a real client send. It claims the run (single-use latch), issues an identity-bound grant, sends through the gate, and emails after the gate transaction — never sending twice, never without disclosure, never to a model-chosen address.

**Files:**
- Create: `app/Services/Technician/TechnicianApprovalService.php`
- Test: `tests/Feature/Technician/Cockpit/TechnicianApprovalServiceTest.php`

**Interfaces:**
- Consumes: `TechnicianRun::{claimForExecution(),deny(),advanceTo()}`; `TechnicianActionGate::dispatch(...)`; `TechnicianApprovalGrant::issue(...)`; `TechnicianDisclosure::{withDisclosure(string,string),assertPresent(string)}`; `TechnicianConfig::{aiActorUserId(),aiActorName()}`; `EmailService::sendTicketReplyNote(Ticket,TicketNote,?string,array)`; `App\Models\{TicketNote,User}`; `App\Enums\{WhoType,NoteType,TechnicianRunState}`.
- Produces:
  - `App\Services\Technician\TechnicianApprovalResult` (readonly DTO): `{string $status, ?int $noteId}` where `$status ∈ {sent, already_handled, gate_declined}`.
  - `App\Services\Technician\TechnicianApprovalService`:
    - `__construct(private TechnicianActionGate $gate, private TechnicianDisclosure $disclosure, private EmailService $email)`.
    - `approveAndSend(TechnicianRun $run, string $body, int $approverId): TechnicianApprovalResult` — for a `send_reply` run: trims `$body`; if the run can't be claimed (`claimForExecution()` false) → `already_handled` (no send). Else: `$hash = hash('sha256', 'send_reply:'.$run->ticket_id.':'.$body)`; `$grant = TechnicianApprovalGrant::issue('send_reply', $run->ticket_id, $hash, $approverId)`; `$disclosed = $this->disclosure->withDisclosure($body, TechnicianConfig::aiActorName()); $this->disclosure->assertPresent($disclosed)`; dispatch the gate with `actionType:'send_reply'`, `contentHash:$hash`, the grant token + `approverUserId:$approverId`, and an executor that creates the AI-authored `TicketNote` (the disclosed body) and `advanceTo(Done)`. If `result->status !== 'executed'` → revert the run to `AwaitingApproval` and return `gate_declined`. Else send the email (`$ticket->contact?->email`, after the gate, fail-soft) + link `email_id`, return `sent` with the note id.
    - `deny(TechnicianRun $run): void` — `$run->deny()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\EmailService;
use App\Services\Technician\TechnicianApprovalService;
use App\Services\Technician\TechnicianDisclosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class TechnicianApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function heldReplyRun(User $actor): TechnicianRun
    {
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode([])); // send_reply default-denies to Approve
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test', 'last_name' => 'Contact', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);

        return TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Original draft.',
        ]);
    }

    public function test_approve_sends_disclosed_ai_note_through_the_gate_and_advances_run(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')->once()->andReturnNull());

        $result = app(TechnicianApprovalService::class)->approveAndSend($run, 'Edited reply body.', $actor->id);

        $this->assertSame('sent', $result->status);
        $note = TicketNote::find($result->noteId);
        $this->assertSame(WhoType::Agent, $note->who_type);
        $this->assertTrue((bool) $note->ai_authored);
        $this->assertSame(NoteType::Reply, $note->note_type);
        $this->assertStringContainsString('Edited reply body.', $note->body);             // the EDITED body was sent
        $this->assertStringContainsString(TechnicianDisclosure::DISCLOSURE_SENTINEL, $note->body); // disclosure appended
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'send_reply', 'result_status' => 'executed']);
    }

    public function test_double_approve_sends_once(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')->once()->andReturnNull());

        $first = app(TechnicianApprovalService::class)->approveAndSend($run, 'Body.', $actor->id);
        $second = app(TechnicianApprovalService::class)->approveAndSend($run->fresh(), 'Body.', $actor->id);

        $this->assertSame('sent', $first->status);
        $this->assertSame('already_handled', $second->status); // the run-state latch rejected the replay
        $this->assertSame(1, TicketNote::where('ticket_id', $run->ticket_id)->where('ai_authored', true)->count());
    }

    public function test_deny_moves_the_run_out_of_the_queue(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldReplyRun($actor);

        app(TechnicianApprovalService::class)->deny($run);

        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianApprovalServiceTest`
Expected: FAIL — `Class "App\Services\Technician\TechnicianApprovalService" not found`.

- [ ] **Step 3: Write the result DTO + the service**

Create `app/Services/Technician/TechnicianApprovalService.php`:

```php
<?php

namespace App\Services\Technician;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\WhoType;
use App\Models\TechnicianRun;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\EmailService;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Log;

/** The outcome of an approve action. status ∈ {sent, already_handled, gate_declined}. */
final class TechnicianApprovalResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?int $noteId = null,
    ) {}
}

/**
 * Turns a held draft into a real, human-approved, single-use client send (Plan 1B).
 * The run-state CAS latch (claimForExecution) makes it exactly-once even on a
 * double-tap / replayed grant; the gate enforces the signed identity-bound grant;
 * disclosure is appended by this sending layer; the recipient is re-derived from
 * the ticket contact (never the model-suggested address). The email is sent AFTER
 * the gate transaction, never inside it.
 */
class TechnicianApprovalService
{
    public function __construct(
        private readonly TechnicianActionGate $gate,
        private readonly TechnicianDisclosure $disclosure,
        private readonly EmailService $email,
    ) {}

    public function approveAndSend(TechnicianRun $run, string $body, int $approverId): TechnicianApprovalResult
    {
        $body = trim($body);

        // Single-use latch: only the winner of the CAS proceeds.
        if ($body === '' || ! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        $ticket = $run->ticket;
        $actorId = TechnicianConfig::aiActorUserId();
        $actorName = TechnicianConfig::aiActorName();

        // The grant binds the EXACT (possibly edited) body the operator approved.
        $hash = hash('sha256', 'send_reply:'.$run->ticket_id.':'.$body);
        $token = TechnicianApprovalGrant::issue('send_reply', $run->ticket_id, $hash, $approverId);

        $disclosed = $this->disclosure->withDisclosure($body, $actorName);
        $this->disclosure->assertPresent($disclosed); // fail-closed pre-send check

        $createdNote = null;

        $result = $this->gate->dispatch(
            actionType: 'send_reply',
            ticketId: $run->ticket_id,
            clientId: $run->client_id,
            contentHash: $hash,
            summary: 'Operator-approved client reply.',
            runId: $run->id,
            executor: function () use ($ticket, $actorId, $actorName, $disclosed, $run, &$createdNote): void {
                $createdNote = TicketNote::create([
                    'ticket_id' => $ticket->id,
                    'author_id' => $actorId,
                    'author_name' => $actorName,
                    'who_type' => WhoType::Agent,
                    'ai_authored' => true,
                    'body' => $disclosed,
                    'note_type' => NoteType::Reply,
                    'is_private' => false,
                    'noted_at' => now(),
                ]);
                $run->advanceTo(TechnicianRunState::Done); // note + audit + state commit atomically
            },
            approvalToken: $token,
            approverUserId: $approverId,
        );

        if ($result->status !== 'executed' || $createdNote === null) {
            // Gate declined (kill-switch flipped in-flight, etc.) — un-latch so the operator can retry.
            $run->advanceTo(TechnicianRunState::AwaitingApproval);

            return new TechnicianApprovalResult('gate_declined');
        }

        // Recipient is the ticket's own contact — NEVER the model-suggested address. Sent after the gate tx.
        $this->sendEmail($ticket, $createdNote);

        return new TechnicianApprovalResult('sent', $createdNote->id);
    }

    public function deny(TechnicianRun $run): void
    {
        $run->deny();
    }

    private function sendEmail(\App\Models\Ticket $ticket, TicketNote $note): void
    {
        $to = $ticket->contact?->email;
        if (! $to) {
            Log::info('[Technician] Approved reply note written but no contact email', ['ticket_id' => $ticket->id]);

            return;
        }

        try {
            $email = $this->email->sendTicketReplyNote($ticket, $note, $to, []);
            if ($email) {
                $note->update(['email_id' => $email->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Technician] Approved reply email failed', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianApprovalServiceTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/Technician/TechnicianApprovalService.php tests/Feature/Technician/Cockpit/TechnicianApprovalServiceTest.php
git commit -m "feat(technician): approved-send round-trip — single-use latch, gated, disclosed

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: The cockpit controller + routes

**Files:**
- Create: `app/Http/Controllers/Web/TechnicianCockpitController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Technician/Cockpit/CockpitControllerTest.php`

**Interfaces:**
- Consumes: `CockpitQuery::{pendingDrafts(),needsAttention()}`; `TechnicianApprovalService::{approveAndSend(),deny()}`; `App\Models\TechnicianRun` (route-model-bound); `auth()->id()`.
- Produces:
  - `TechnicianCockpitController`:
    - `index(CockpitQuery $query)` → `view('cockpit.index', ['drafts' => $query->pendingDrafts(), 'needs' => $query->needsAttention()])`.
    - `approve(Request $request, TechnicianRun $run, TechnicianApprovalService $service)` — validates `body` (required string); calls `approveAndSend($run, $request->input('body'), auth()->id())`; redirects to `cockpit.index` with a flash scaled to the result status.
    - `deny(TechnicianRun $run, TechnicianApprovalService $service)` — `deny($run)`; redirect with flash.
  - Routes (inside the `auth` group): `GET /cockpit` → `cockpit.index`; `POST /cockpit/runs/{run}/approve` → `cockpit.approve`; `POST /cockpit/runs/{run}/deny` → `cockpit.deny`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CockpitControllerTest extends TestCase
{
    use RefreshDatabase;

    private function heldRun(User $actor): TechnicianRun
    {
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode([]));
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test', 'last_name' => 'Contact', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id, 'subject' => 'Printer down']);

        return TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'We will get the printer back online.',
        ]);
    }

    public function test_cockpit_index_requires_auth_and_shows_a_held_draft(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldRun($actor);

        $this->get(route('cockpit.index'))->assertRedirect(); // guest → login

        $this->actingAs(User::factory()->create())
            ->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('Printer down')
            ->assertSee('We will get the printer back online.');
    }

    public function test_approve_sends_and_clears_the_draft(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldRun($actor);
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')->once()->andReturnNull());

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.approve', $run), ['body' => 'Edited before sending.'])
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(1, TicketNote::where('ticket_id', $run->ticket_id)->where('ai_authored', true)->count());
    }

    public function test_deny_removes_the_draft_from_the_queue(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldRun($actor);

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.deny', $run))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CockpitControllerTest`
Expected: FAIL — route `cockpit.index` not defined.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Web/TechnicianCockpitController.php`:

```php
<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TechnicianRun;
use App\Services\Technician\Cockpit\CockpitQuery;
use App\Services\Technician\TechnicianApprovalService;
use Illuminate\Http\Request;

class TechnicianCockpitController extends Controller
{
    public function index(CockpitQuery $query)
    {
        return view('cockpit.index', [
            'drafts' => $query->pendingDrafts(),
            'needs' => $query->needsAttention(),
        ]);
    }

    public function approve(Request $request, TechnicianRun $run, TechnicianApprovalService $service)
    {
        $validated = $request->validate(['body' => ['required', 'string']]);

        $result = $service->approveAndSend($run, $validated['body'], (int) auth()->id());

        return redirect()->route('cockpit.index')->with(
            $result->status === 'sent' ? 'success' : 'error',
            match ($result->status) {
                'sent' => 'Reply approved and sent.',
                'already_handled' => 'That draft was already handled.',
                default => 'Could not send — the Technician declined (it may be paused). Try again.',
            },
        );
    }

    public function deny(TechnicianRun $run, TechnicianApprovalService $service)
    {
        $service->deny($run);

        return redirect()->route('cockpit.index')->with('success', 'Draft dismissed; the ticket is back with your team.');
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, inside the `Route::middleware('auth')->group(...)` block (near the tickets routes), add:

```php
    // AI Technician cockpit (Plan 1B)
    Route::get('/cockpit', [\App\Http\Controllers\Web\TechnicianCockpitController::class, 'index'])->name('cockpit.index');
    Route::post('/cockpit/runs/{run}/approve', [\App\Http\Controllers\Web\TechnicianCockpitController::class, 'approve'])->name('cockpit.approve');
    Route::post('/cockpit/runs/{run}/deny', [\App\Http\Controllers\Web\TechnicianCockpitController::class, 'deny'])->name('cockpit.deny');
```

(`{run}` route-model-binds `TechnicianRun` by id — the param name `run` matches the model's lowercased class.)

- [ ] **Step 5: Run test to verify it fails differently**

Run: `php artisan test --filter=CockpitControllerTest`
Expected: FAIL — now `View [cockpit.index] not found` (the view is Task 5). The route + controller wiring is correct; the view is next.

- [ ] **Step 6: Commit (controller + routes; the view lands in Task 5)**

```bash
./vendor/bin/pint --dirty
git add app/Http/Controllers/Web/TechnicianCockpitController.php routes/web.php tests/Feature/Technician/Cockpit/CockpitControllerTest.php
git commit -m "feat(technician): cockpit controller + routes (approve/deny round-trip)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: The cockpit console (Blade)

The operator's one screen. Two lanes: the **approval queue** (send-text-first cards — the exact body editable, "Send this" below it, never above; the "why" + age/SLA collapsed under it) and the **"Needs you"** lane (tickets the AI couldn't draft). Card-based so it reads the same on a 390px phone and a desktop.

**Files:**
- Create: `resources/views/cockpit/index.blade.php`
- Test: `tests/Feature/Technician/Cockpit/CockpitControllerTest.php` (already written in Task 4 — it goes green when this view exists)

**Interfaces:**
- Consumes: `$drafts` (Collection of `TechnicianRun` with `ticket.client`/`ticket.contact`), `$needs` (Collection of `Ticket`). Each draft's body is `$run->proposed_content`; the "why" is `$run->proposed_meta['reasons'] ?? []` + `$run->confidence`.
- Produces: the rendered console; the per-draft `<form>` posts to `cockpit.approve` with a `body` textarea; the deny `<form>` posts to `cockpit.deny`.

- [ ] **Step 1: Write the view**

Create `resources/views/cockpit/index.blade.php`:

```blade
@extends('layouts.app')

@section('title', 'Cockpit')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-robot me-2"></i>Cockpit</h1>
    <span class="text-muted small">{{ $drafts->count() }} awaiting approval · {{ $needs->count() }} need you</span>
</div>

{{-- APPROVAL QUEUE --}}
<h2 class="h6 text-muted text-uppercase mb-2">Awaiting your approval</h2>
@forelse ($drafts as $run)
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
                <a href="{{ route('tickets.show', $run->ticket_id) }}" class="fw-semibold text-decoration-none">
                    {{ optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id }}
                </a>
                @if($run->ticket?->client)
                    <span class="badge bg-light text-dark border">{{ $run->ticket->client->name }}</span>
                @endif
                <span class="badge {{ $run->action_type === 'propose_resolution' ? 'bg-info' : 'bg-primary' }}">
                    {{ $run->action_type === 'propose_resolution' ? 'Proposed resolution' : 'Reply' }}
                </span>
                <span class="ms-auto text-muted">{{ optional($run->created_at)->diffForHumans() }}</span>
            </div>

            {{-- SEND-TEXT-FIRST: the exact outgoing text, editable, ABOVE the Send button --}}
            <form method="POST" action="{{ route('cockpit.approve', $run) }}">
                @csrf
                <label class="form-label small text-muted mb-1" for="body-{{ $run->id }}">Message to the client (edit before sending):</label>
                <textarea class="form-control mb-1" id="body-{{ $run->id }}" name="body" rows="5">{{ $run->proposed_content }}</textarea>
                <p class="text-muted small mb-2">
                    <i class="bi bi-info-circle me-1"></i>A disclosure line (“— Sent by {{ \App\Support\TechnicianConfig::aiActorName() }}, an AI assistant for our team.”) is added automatically.
                </p>

                {{-- the "why", collapsed --}}
                @if(!empty($run->proposed_meta['reasons']))
                    <p class="text-muted small mb-2">Why: {{ implode(' · ', (array) $run->proposed_meta['reasons']) }}@if($run->confidence) (confidence {{ number_format($run->confidence, 2) }})@endif</p>
                @endif

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success"><i class="bi bi-send me-1"></i>Send this</button>
            </form>
                    <form method="POST" action="{{ route('cockpit.deny', $run) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Hold it</button>
                    </form>
                </div>
        </div>
    </div>
@empty
    <p class="text-muted">Nothing waiting — you're clear.</p>
@endforelse

{{-- NEEDS YOU --}}
@if($needs->isNotEmpty())
    <h2 class="h6 text-muted text-uppercase mt-4 mb-2">Needs you — the assistant couldn't draft these</h2>
    @foreach ($needs as $ticket)
        <a href="{{ route('tickets.show', $ticket->id) }}"
           class="d-block card shadow-sm mb-2 text-decoration-none text-reset cockpit-needs-card">
            <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2 small">
                <span class="fw-semibold">{{ $ticket->subject }}</span>
                @if($ticket->client)<span class="badge bg-light text-dark border">{{ $ticket->client->name }}</span>@endif
                <span class="ms-auto text-muted">{{ optional($ticket->updated_at)->diffForHumans() }}</span>
            </div>
        </a>
    @endforeach
@endif
@endsection
```

> Note on the nested forms: the "Hold it" form must NOT nest inside the approve `<form>` (HTML forbids nested forms). The structure above closes the approve `</form>` immediately after the "Send this" button and opens the deny form as a sibling inside the same flex row. Verify in the browser/QA pass that both buttons sit on one row; if the layout fights you, place the two forms side by side in a `d-flex` wrapper with each form holding one button. Keep the editable `body` textarea inside the approve form.

- [ ] **Step 2: Run the controller test (now green)**

Run: `php artisan test --filter=CockpitControllerTest`
Expected: PASS (3 tests — the index renders the subject + draft text; approve sends; deny dismisses).

- [ ] **Step 3: Commit**

```bash
./vendor/bin/pint --dirty
git add resources/views/cockpit/index.blade.php
git commit -m "feat(technician): cockpit console view — approval queue + needs-you lane

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

> **After this task ships, run the QA agent's design-audit (`impeccable`) on `/cockpit` at 390px and desktop** — this is the operator's primary surface during the trip; it must be fast, legible, and thumb-friendly. (Out of band; not a code task.)

---

### Task 6: Sidebar nav entry + pending-count badge

**Files:**
- Modify: `resources/views/components/sidebar.blade.php`
- Test: `tests/Feature/Technician/Cockpit/CockpitControllerTest.php` (append one assertion)

**Interfaces:**
- Consumes: `route('cockpit.index')`, `CockpitQuery::pendingCount()` (via an inlined `Cache::remember`, matching the existing missed-calls badge pattern).
- Produces: a "Cockpit" sidebar link with active-state + a danger badge when `pendingCount() > 0`.

- [ ] **Step 1: Write the failing test (append to `CockpitControllerTest`)**

```php
    public function test_sidebar_shows_a_cockpit_link_with_pending_badge(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $this->heldRun($actor); // one pending draft

        $this->actingAs(User::factory()->create())
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertSee('Cockpit')
            ->assertSee(route('cockpit.index'));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CockpitControllerTest::test_sidebar_shows_a_cockpit_link_with_pending_badge`
Expected: FAIL — the sidebar has no Cockpit link yet.

- [ ] **Step 3: Add the nav item**

In `resources/views/components/sidebar.blade.php`, add (in the Service group, alongside Tickets — mirror the existing missed-calls badge pattern):

```blade
<a href="{{ route('cockpit.index') }}"
   class="sidebar-link {{ request()->routeIs('cockpit.*') ? 'active' : '' }}"
   @if(request()->routeIs('cockpit.*')) aria-current="page" @endif
   data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Cockpit">
    <i class="bi bi-robot sidebar-icon"></i>
    <span class="sidebar-label">Cockpit</span>
    @php $cockpitPending = \Illuminate\Support\Facades\Cache::remember('sidebar:cockpit_pending', 60, fn () => app(\App\Services\Technician\Cockpit\CockpitQuery::class)->pendingCount()); @endphp
    @if($cockpitPending > 0)
        <span class="sidebar-badge bg-danger">{{ $cockpitPending }}</span>
    @endif
</a>
```

> The 60s cache means the badge can lag a minute after an approval — acceptable for a nav hint. If a stale badge during tests is a problem, the test above only asserts the link's presence (not the count), so it's robust to caching.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CockpitControllerTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add resources/views/components/sidebar.blade.php tests/Feature/Technician/Cockpit/CockpitControllerTest.php
git commit -m "feat(technician): cockpit sidebar nav + pending-count badge

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: `ai_authored` disclosure badge — staff timeline

The `ai_authored` flag has been persisted since Phase 0 and rendered nowhere — an AI reply looks identical to a human tech's. Make it visibly disclosed in the staff ticket timeline.

**Files:**
- Modify: `resources/views/tickets/show.blade.php`
- Test: `tests/Feature/Technician/Cockpit/AiAuthoredBadgeTest.php`

**Interfaces:**
- Consumes: `$note->ai_authored` (boolean, already cast on `TicketNote`).
- Produces: a label+icon "AI" badge on any note where `ai_authored` is true (not color-only).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\NoteType;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAuthoredBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function aiNote(Ticket $ticket): TicketNote
    {
        return TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'Chet', 'who_type' => WhoType::Agent,
            'ai_authored' => true, 'body' => 'Thanks for reaching out — disclosed AI reply.',
            'note_type' => NoteType::Reply, 'is_private' => false, 'noted_at' => now(),
        ]);
    }

    public function test_staff_timeline_marks_an_ai_authored_note(): void
    {
        $ticket = Ticket::factory()->create(['client_id' => Client::factory()->create()->id]);
        $this->aiNote($ticket);

        $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('AI', false) // the badge text/label
            ->assertSee('disclosed AI reply');
    }
}
```

- [ ] **Step 2: Run test to verify it fails or is ambiguous**

Run: `php artisan test --filter=AiAuthoredBadgeTest::test_staff_timeline_marks_an_ai_authored_note`
Expected: the page renders the note but the "AI" badge assertion is not yet guaranteed by an `ai_authored`-driven element (an AiTriage "AI Analysis" badge keys on `note_type`, not `ai_authored`). To make the test load-bearing, after Step 3 confirm the badge appears specifically for `ai_authored` Reply notes (which are NOT AiTriage). If the bare `assertSee('AI')` is satisfied incidentally, tighten it to a unique string like `'AI-authored'` in both the test and the badge below.

- [ ] **Step 3: Add the badge to the note render**

In `resources/views/tickets/show.blade.php`, in the regular note/reply branch (where `$note->display_author` is shown for non-AiTriage notes), add immediately after the author name:

```blade
@if($note->ai_authored)
    <span class="badge bg-info text-dark ms-1" title="Written by the AI Technician and disclosed to the client">
        <i class="bi bi-robot me-1"></i>AI-authored
    </span>
@endif
```

(Use the exact `'AI-authored'` string in the test's `assertSee` so it is unambiguous.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AiAuthoredBadgeTest::test_staff_timeline_marks_an_ai_authored_note`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add resources/views/tickets/show.blade.php tests/Feature/Technician/Cockpit/AiAuthoredBadgeTest.php
git commit -m "feat(technician): AI-authored disclosure badge in the staff timeline

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: `ai_authored` disclosure badge — client portal

The same disclosure, client-side: a public AI reply currently renders in the portal's `note-staff` lane showing the persona name with no indication it's AI. Add the badge so the client plainly sees it (reinforcing the in-body sentence; spec §7 "renders in email + portal, visually distinct").

**Files:**
- Modify: `resources/views/portal/tickets/show.blade.php`
- Test: `tests/Feature/Technician/Cockpit/AiAuthoredBadgeTest.php` (append)

**Interfaces:**
- Consumes: `$note->ai_authored`. The portal auth guard is `portal` (a `Person`); tests use `actingAs($person, 'portal')`.
- Produces: the same label+icon AI indicator on AI-authored portal-visible notes.

- [ ] **Step 1: Write the failing test (append)**

```php
    public function test_portal_marks_an_ai_authored_note_to_the_client(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $this->aiNote($ticket);

        // A portal-capable person for this client (adjust fields to your Person/portal requirements).
        $person = \App\Models\Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Client', 'last_name' => 'User', 'email' => 'client@example.com',
            'is_active' => true, 'portal_enabled' => true,
        ]);

        $this->actingAs($person, 'portal')
            ->get(route('portal.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('AI-authored');
    }
```

> Confirm the portal route name (`portal.tickets.show`) and the `Person` fields required for `canAccessPortal()` (`is_active`, `portal_enabled`, and an Active client) against `app/Http/Middleware/PortalAuthenticate.php`; adjust the `Person::create` fields and the `actingAs` guard name to what the portal guard actually checks. If the portal guard needs the client `stage = Active`, set it on the client.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AiAuthoredBadgeTest::test_portal_marks_an_ai_authored_note_to_the_client`
Expected: FAIL — no AI indicator in the portal view.

- [ ] **Step 3: Add the badge to the portal note render**

In `resources/views/portal/tickets/show.blade.php`, in the note loop (the staff/agent note branch where `{{ $note->display_author }}` is shown), add:

```blade
@if($note->ai_authored)
    <span class="badge bg-info text-dark ms-1"><i class="bi bi-robot me-1"></i>AI-authored</span>
@endif
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AiAuthoredBadgeTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add resources/views/portal/tickets/show.blade.php tests/Feature/Technician/Cockpit/AiAuthoredBadgeTest.php
git commit -m "feat(technician): AI-authored disclosure badge in the client portal

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Client-reply hook — re-trigger the Loop on a client reply

Without this the Loop is "draft once, then silence." Dispatch `RunTechnicianLoop` (guarded) when a client replies via email or the portal, mirroring `TicketObserver::created`. (The *re-drafting* logic is Task 10; this task is the trigger.)

**Files:**
- Modify: `app/Services/TicketService.php` (in `addPortalReply`)
- Modify: `app/Services/EmailService.php` (in `linkEmailToTicket`)
- Test: `tests/Feature/Technician/Cockpit/ClientReplyReopensDraftTest.php`

**Interfaces:**
- Consumes: `App\Jobs\RunTechnicianLoop::dispatch(int)`; `App\Support\{TechnicianConfig,TriageConfig}`; `App\Enums\ClientStage`.
- Produces: a guarded `RunTechnicianLoop::dispatch($ticket->id)` after the client-reply note is persisted in both seams. Guards (mirroring the observer): `TechnicianConfig::enabled()` AND the ticket's client is not `ClientStage::Prospect`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Jobs\RunTechnicianLoop;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ClientReplyReopensDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_reply_dispatches_the_loop_when_enabled(): void
    {
        Bus::fake();
        Setting::setValue('technician_enabled', '1');
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Client', 'last_name' => 'User', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);

        app(TicketService::class)->addPortalReply($ticket, $person, 'Any update on this?');

        Bus::assertDispatched(RunTechnicianLoop::class, fn ($job) => true);
    }

    public function test_portal_reply_does_not_dispatch_when_disabled(): void
    {
        Bus::fake(); // technician_enabled unset → disabled
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Client', 'last_name' => 'User', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);

        app(TicketService::class)->addPortalReply($ticket, $person, 'Any update?');

        Bus::assertNotDispatched(RunTechnicianLoop::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ClientReplyReopensDraftTest`
Expected: FAIL — `addPortalReply` doesn't dispatch the Loop yet.

- [ ] **Step 3: Add the guarded dispatch to `addPortalReply`**

In `app/Services/TicketService.php::addPortalReply`, after the reply note is persisted and before `return $note;`, add:

```php
        // AI Technician (Plan 1B): a client reply re-opens drafting. Same guards as
        // TicketObserver::created — enabled + not a prospect client. The pipeline's
        // own substance/idempotency logic (Task 10) decides whether to actually draft.
        if (\App\Support\TechnicianConfig::enabled()
            && $ticket->client?->stage !== \App\Enums\ClientStage::Prospect) {
            \App\Jobs\RunTechnicianLoop::dispatch($ticket->id);
        }
```

- [ ] **Step 4: Add the identical guarded dispatch to `linkEmailToTicket`**

In `app/Services/EmailService.php::linkEmailToTicket`, after the inbound client-reply `TicketNote` is created (the `WhoType::EndUser` reply note, ~line 633) — and only on that path (not when no note is created) — add the same block:

```php
        if (\App\Support\TechnicianConfig::enabled()
            && $ticket->client?->stage !== \App\Enums\ClientStage::Prospect) {
            \App\Jobs\RunTechnicianLoop::dispatch($ticket->id);
        }
```

> `linkEmailToTicket` is harder to feature-test in isolation (it needs an `Email` row + Graph mailbox wiring); the `addPortalReply` test above covers the guard logic, and this block is byte-identical. If you can cheaply construct the `Email`+`Ticket` fixtures, add a parallel `linkEmailToTicket` dispatch test; otherwise rely on the shared guard logic + the reviewer confirming the placement is on the client-reply path (not a system/AI note path).

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ClientReplyReopensDraftTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/TicketService.php app/Services/EmailService.php tests/Feature/Technician/Cockpit/ClientReplyReopensDraftTest.php
git commit -m "feat(technician): re-trigger the Loop on a client reply (email + portal)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: Re-key the pipeline so an unaddressed client reply re-drafts (and supersedes the stale draft)

The 1A pipeline short-circuits if **any** `send_reply` run exists, so the Task-9 trigger alone produces nothing. Re-key it: draft a fresh reply only when there's a client message newer than the latest draft, and supersede the stale held draft so the cockpit never shows two for one ticket. A mere job retry (no new reply) stays a no-op.

**Files:**
- Modify: `app/Services/Technician/DraftPipeline.php`
- Test: `tests/Feature/Technician/Cockpit/ClientReplyReopensDraftTest.php` (append)

**Interfaces:**
- Consumes: `App\Models\TechnicianRun`, `App\Enums\{TechnicianRunState,NoteType,WhoType}`; `Ticket::notes()`.
- Produces: in `DraftPipeline::run`, the blanket `$alreadyDrafted` early-return is replaced by `hasUnaddressedClientReply($ticket)`; before recording a new `send_reply`, prior `AwaitingApproval` `send_reply` runs for the ticket are `markSuperseded()`. New private `hasUnaddressedClientReply(Ticket): bool`.

- [ ] **Step 1: Write the failing test (append)**

```php
    public function test_a_new_client_reply_redrafts_and_supersedes_the_stale_draft(): void
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'C', 'last_name' => 'U', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id]);

        // An existing held draft from an earlier turn.
        $stale = \App\Models\TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64), 'state' => \App\Enums\TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'old draft', 'created_at' => now()->subHour(),
        ]);

        // A NEW client reply arrives (newer than the stale draft).
        \App\Models\TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'C', 'who_type' => \App\Enums\WhoType::EndUser,
            'ai_authored' => false, 'body' => 'Still broken!', 'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false, 'noted_at' => now(),
        ]);

        // Mock the collaborators so the pipeline produces a fresh held reply.
        $this->mock(\App\Services\Technician\TechnicianClassifier::class, fn ($m) => $m->shouldReceive('classify')
            ->andReturn(new \App\Services\Technician\TechnicianAssessment(0.8, true, ['x'], 10)));
        $this->mock(\App\Services\Technician\TechnicianReplyDrafter::class, fn ($m) => $m->shouldReceive('draft')
            ->andReturn(new \App\Services\Technician\TechnicianDraft('fresh draft', 'c@example.com', 50)));
        $this->mock(\App\Services\TicketResolutionDrafter::class, fn ($m) => $m->shouldReceive('draft')->andReturnNull());

        app(\App\Services\Technician\DraftPipeline::class)->run($ticket->fresh());

        // The stale draft is superseded; a fresh held draft exists.
        $this->assertSame(\App\Enums\TechnicianRunState::Superseded, $stale->fresh()->state);
        $this->assertSame(1, \App\Models\TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'send_reply')
            ->where('state', \App\Enums\TechnicianRunState::AwaitingApproval->value)->count());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ClientReplyReopensDraftTest::test_a_new_client_reply_redrafts_and_supersedes_the_stale_draft`
Expected: FAIL — the 1A `$alreadyDrafted` guard returns early (the stale run exists), so no fresh draft and the stale run is untouched.

- [ ] **Step 3: Re-key the pipeline**

In `app/Services/Technician/DraftPipeline.php`, replace the existing idempotency block:

```php
        $alreadyDrafted = TechnicianRun::where('ticket_id', $ticket->id)
            ->whereIn('action_type', ['send_reply', 'propose_resolution'])
            ->exists();
        if ($alreadyDrafted) {
            return;
        }
```

with:

```php
        // Plan 1B: draft only when there is a client message we haven't replied to yet.
        // A job retry with no new reply stays a no-op; a genuine new client reply re-opens
        // drafting and supersedes the stale held draft (so the cockpit shows only the fresh one).
        if (! $this->hasUnaddressedClientReply($ticket)) {
            return;
        }

        TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'send_reply')
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->get()
            ->each
            ->markSuperseded();
```

and add the helper (near `hasClientSubstance`):

```php
    /**
     * True when the latest client (non-AI EndUser) reply is newer than our latest
     * reply draft — i.e. there's an unaddressed client message. At intake (no client
     * reply note yet) it's true iff we've never drafted a reply (preserves 1A behavior).
     */
    private function hasUnaddressedClientReply(Ticket $ticket): bool
    {
        $latestClientReply = $ticket->notes()
            ->where('note_type', NoteType::Reply->value)
            ->where('ai_authored', false)
            ->where('who_type', WhoType::EndUser->value)
            ->latest('noted_at')
            ->first();

        $latestReplyRun = TechnicianRun::where('ticket_id', $ticket->id)
            ->where('action_type', 'send_reply')
            ->latest('created_at')
            ->first();

        if (! $latestClientReply) {
            return $latestReplyRun === null; // intake: draft once
        }

        return $latestReplyRun === null
            || $latestReplyRun->created_at === null
            || $latestReplyRun->created_at->lt($latestClientReply->noted_at);
    }
```

Add the imports if missing: `use App\Enums\WhoType;` (and confirm `NoteType`, `TechnicianRunState`, `TechnicianRun`, `Ticket` are already imported from Task 9/1A).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ClientReplyReopensDraftTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Run the full Technician suite (no regression — 1A intake behavior preserved)**

Run: `php artisan test --filter=Technician`
Expected: PASS — including the 1A `DraftPipelineTest` (intake still drafts once; idempotent on retry; not-ownable still skips).

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/Technician/DraftPipeline.php tests/Feature/Technician/Cockpit/ClientReplyReopensDraftTest.php
git commit -m "feat(technician): re-draft on an unaddressed client reply + supersede the stale draft

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Plan Self-Review

**Spec coverage (Phase 1 "safe core", §9 cockpit/experience + §10 augment + §15 success):**
- *Cockpit = a purpose-built approval queue, not a filtered ticket list; each row shows client/subject/draft/age, approve/edit/deny without opening the ticket* → Tasks 2–5 (the read model + console; send-text-first cards with an editable body).
- *The word "approve" never appears without the full text above it* → Task 5 (the body textarea is above the "Send this" button).
- *Operator's daily obligation is a bounded window; nothing falls through* → the **"Needs you" lane** (Task 2/5) is the forest decision that makes this real.
- *Hold all substantive sends; human approves, rarely authors; zero un-approved sends* → Tasks 3–4 (every send is one deliberate, gated, single-use tap; the run-state CAS latch makes it exactly-once — resolving review backlog #3).
- *No client mistakes the AI for a human (structural disclosure renders in email + portal)* → Tasks 7–8 (the `ai_authored` badge, staff + portal) + the in-body sentinel from 1A re-appended at send (Task 3).
- *Multi-turn service while away* → Tasks 9–10 (client-reply hook + re-keyed pipeline; the stale draft is superseded).

**Deferred (explicitly out of 1B — tracked):**
- **The signed one-click "I'd prefer a person" link.** 1A already ships the *prose* affordance ("just reply and ask — a member of our team will take over") in every disclosed message, so clients can reach a human today; the one-click link (a `URL::temporarySignedRoute('technician.prefer-human', …)` landing that flags the ticket + suppresses further auto + posts an honest status) is a UX enhancement, scoped as the **1B fast-follow**. Note: it touches `TechnicianDisclosure::withDisclosure` (to carry the URL) + a public signed route — keep it small and self-contained.
- **The aging "honest interim update" on a held/denied draft** (spec §9: an approved-but-not-completed or denied item triggers an AUTO honest "still working on this") — this is aging/scheduled-sweep machinery, so it belongs with **Plan 1C** (digest/heartbeat), not the synchronous cockpit. Until then, "Hold it" returns the ticket to the team (visible in the "Needs you" lane) and the client retains the initial ack.
- **The classifier-`reason` output scan** before the cockpit renders `proposed_meta['reasons']` (carried from the 1A reviews) — fold into Task 5 or a fast-follow: run `WikiRedactor::scan` (or escape) on each reason string before display, since it is model-authored text now reaching a UI.

**Forest check — will a human achieve the goal with this?** With 1B, the operator opens **one** screen, sees **everything** needing them (drafts + the can't-draft lane), sends with one safe tap, and the client experience is honest (visible AI disclosure) and continuous (multi-turn). The remaining goal dependencies are **out of 1B by design and must still land before Aug 1**: **1C** (the notify/digest — without a push, the operator must remember to open the cockpit) and **Phase 2** (the deterministic emergency backstop — the relied-on net for a genuinely urgent ticket). 1B is the operator's hands; it is necessary, not sufficient.

**Placeholder scan:** none — every step has real code + commands. Implementer-confirm notes are flagged inline where a local schema detail must be verified (the ticket due-date column + open statuses in Task 2; `Ticket::technicianRuns()` existence; the portal `Person`/guard fields in Task 8; the `linkEmailToTicket` test feasibility in Task 9) — each names the exact fallback.

**Type consistency:** `TechnicianRunState` (`awaiting_approval`/`executing`/`done`/`denied`/`superseded`), the approval result statuses (`sent`/`already_handled`/`gate_declined`), the `content_hash = sha256("send_reply:{ticketId}:{body}")` binding (Task 3, matching the gate + grant), `ai_authored`, and the `'AI-authored'` badge string are used identically across Tasks 1–10. The approve send mirrors `AutoAcknowledge` (executor creates the note + advances the run inside the gate tx; email after); the reply-hook guards mirror `TicketObserver::created`.

