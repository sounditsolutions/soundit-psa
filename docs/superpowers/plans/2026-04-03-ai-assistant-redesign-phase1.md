# AI Assistant Redesign Phase 1 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the monolithic ticket note form with action buttons (Note, Reply, Ask AI, Change Status) and embed AI conversations inline in the ticket timeline.

**Architecture:** The always-present note form is split into 4 collapsible action panels triggered by buttons. "Ask AI" creates inline chat blocks in the timeline that auto-close after 30 min of inactivity. AI conversations become timeline entries alongside notes and phone calls, visible to all staff. The right-side panel is NOT removed in this phase (Phase 2 handles that).

**Tech Stack:** Laravel 12 / Blade / Bootstrap 5.3 CDN / vanilla JS / Anthropic API (existing AiClient)

**Spec:** `docs/superpowers/specs/2026-04-03-ai-assistant-redesign.md`

---

## File Structure

| File | Responsibility |
|------|---------------|
| `resources/views/tickets/show.blade.php` | **Modify** — Replace note form with action buttons, add AI chat block rendering in timeline |
| `resources/views/tickets/_action-buttons.blade.php` | **Create** — Action button row (Note, Reply, Ask AI, Change Status) |
| `resources/views/tickets/_action-note.blade.php` | **Create** — Note action panel (editor, private, time, billable, contract, status, submit) |
| `resources/views/tickets/_action-reply.blade.php` | **Create** — Reply action panel (To/Cc, editor, AI Draft, time, status, submit) |
| `resources/views/tickets/_action-status.blade.php` | **Create** — Change Status action panel (status dropdown, resolution, submit) |
| `resources/views/tickets/_timeline-ai-chat.blade.php` | **Create** — AI chat block rendering (active + read-only states) |
| `public/js/ticket-actions.js` | **Create** — Action button toggle logic + note form behavior (extracted from show.blade.php inline JS) |
| `public/js/ticket-ai-chat.js` | **Create** — Inline AI chat: create/resume conversations, send messages, render responses |
| `public/css/ticket-ai-chat.css` | **Create** — Styles for inline chat blocks and action buttons |
| `app/Http/Controllers/Web/TicketController.php` | **Modify** — Add conversations to timeline in `show()` |
| `app/Http/Controllers/Web/AssistantController.php` | **Modify** — Add `forTicket()` endpoint |
| `routes/web.php` | **Modify** — Add new assistant route |

---

### Task 1: New API Endpoint — Conversations for Ticket

Add the endpoint that returns all AI conversations for a ticket, used by the timeline to know which conversations to render.

**Files:**
- Modify: `app/Http/Controllers/Web/AssistantController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Add `forTicket()` method to AssistantController**

Add after the existing `saveNote()` method in `app/Http/Controllers/Web/AssistantController.php`:

```php
/**
 * Get all AI conversations for a ticket, newest first.
 * Used by the timeline to render inline chat blocks.
 */
public function forTicket(Ticket $ticket)
{
    $conversations = AssistantConversation::where('context_type', 'ticket')
        ->where('context_id', $ticket->id)
        ->with(['user:id,name', 'messages'])
        ->orderByDesc('created_at')
        ->get()
        ->map(fn ($conv) => [
            'id' => $conv->id,
            'user_id' => $conv->user_id,
            'user_name' => $conv->user?->name ?? 'Unknown',
            'message_count' => $conv->messages->count(),
            'created_at' => $conv->created_at->toIso8601String(),
            'updated_at' => $conv->updated_at->toIso8601String(),
            'is_active' => $conv->user_id === auth()->id()
                && $conv->messages->last()?->created_at?->gt(now()->subMinutes(30)),
            'messages' => $conv->messages->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'created_at' => $m->created_at->toIso8601String(),
            ]),
        ]);

    return response()->json($conversations);
}
```

Add the import at the top of the file:

```php
use App\Models\Ticket;
```

- [ ] **Step 2: Add route**

In `routes/web.php`, add after the existing assistant routes (around line 550):

```php
Route::get('/assistant/conversations/for-ticket/{ticket}', [AssistantController::class, 'forTicket'])->name('assistant.for-ticket');
```

- [ ] **Step 3: Verify syntax**

Run: `php -l app/Http/Controllers/Web/AssistantController.php && php -l routes/web.php`

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Web/AssistantController.php routes/web.php
git commit -m "Add forTicket endpoint to return AI conversations for timeline"
```

---

### Task 2: Merge Conversations into Timeline

Update the ticket controller to include AI conversations in the timeline data passed to the view.

**Files:**
- Modify: `app/Http/Controllers/Web/TicketController.php`

- [ ] **Step 1: Update the `show()` method**

In `app/Http/Controllers/Web/TicketController.php`, find the timeline building section (around lines 116-122). The current code is:

```php
$timeline = $ticket->notes
    ->concat($ticket->phoneCalls)
    ->sortByDesc(fn ($item) => $item instanceof \App\Models\PhoneCall
        ? $item->started_at
        : $item->noted_at
    )
    ->values();
```

Replace with:

```php
$conversations = \App\Models\AssistantConversation::where('context_type', 'ticket')
    ->where('context_id', $ticket->id)
    ->with(['user:id,name', 'messages'])
    ->get();

$timeline = $ticket->notes
    ->concat($ticket->phoneCalls)
    ->concat($conversations)
    ->sortByDesc(function ($item) {
        if ($item instanceof \App\Models\PhoneCall) {
            return $item->started_at;
        }
        if ($item instanceof \App\Models\AssistantConversation) {
            return $item->created_at;
        }
        return $item->noted_at;
    })
    ->values();
```

- [ ] **Step 2: Verify syntax**

Run: `php -l app/Http/Controllers/Web/TicketController.php`

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Web/TicketController.php
git commit -m "Merge AI conversations into ticket timeline"
```

---

### Task 3: Action Button Row Partial

Create the action button row that replaces the monolithic note form.

**Files:**
- Create: `resources/views/tickets/_action-buttons.blade.php`

- [ ] **Step 1: Create the action button row**

Create `resources/views/tickets/_action-buttons.blade.php`:

```blade
{{-- Action button row: Note, Reply, Ask AI, Change Status --}}
<div class="d-flex flex-wrap gap-2 mb-3" id="actionButtons">
    <button type="button" class="btn btn-sm btn-outline-secondary action-btn" data-action="note">
        <i class="bi bi-sticky me-1"></i>Note
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary action-btn" data-action="reply">
        <i class="bi bi-envelope me-1"></i>Reply
    </button>
    @if(\App\Support\AssistantConfig::isEnabled())
        <button type="button" class="btn btn-sm btn-outline-secondary action-btn" data-action="ask-ai" id="askAiBtn">
            <i class="bi bi-robot me-1"></i>Ask AI
        </button>
    @endif
    <button type="button" class="btn btn-sm btn-outline-secondary action-btn" data-action="status">
        <i class="bi bi-arrow-left-right me-1"></i>Change Status
    </button>
</div>
```

- [ ] **Step 2: Verify syntax**

Run: `php -l resources/views/tickets/_action-buttons.blade.php`

- [ ] **Step 3: Commit**

```bash
git add resources/views/tickets/_action-buttons.blade.php
git commit -m "Create action button row partial for ticket notes area"
```

---

### Task 4: Note Action Panel

Extract the Note action into its own partial.

**Files:**
- Create: `resources/views/tickets/_action-note.blade.php`

- [ ] **Step 1: Create the Note action panel**

Create `resources/views/tickets/_action-note.blade.php`:

```blade
{{-- Note action panel --}}
<div class="action-panel d-none" id="actionNote">
    <form method="POST" action="{{ route('tickets.notes.store', $ticket) }}">
        @csrf
        <input type="hidden" name="note_type" value="note">
        <div class="mb-2">
            <x-markdown-editor name="body" id="noteBody" rows="3" placeholder="Add a note..." :required="true"
                :upload-url="route('tickets.attachments.store', $ticket)" />
        </div>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="form-check">
                <input type="checkbox" name="is_private" value="1" class="form-check-input"
                       id="notePrivate" checked>
                <label class="form-check-label small" for="notePrivate">Private</label>
            </div>
            <div class="input-group input-group-sm" style="width: 140px;">
                <span class="input-group-text"><i class="bi bi-clock"></i></span>
                <input type="text" name="time" class="form-control" placeholder="0h 15m" id="noteTimeInput">
            </div>
            <div class="form-check d-none" id="noteBillableGroup">
                <input type="checkbox" name="is_billable" value="1" class="form-check-input"
                       id="noteBillable" {{ ($defaultBillable ?? true) ? 'checked' : '' }}>
                <label class="form-check-label small" for="noteBillable">Billable</label>
            </div>
            <div class="d-none" id="noteContractGroup">
                <select name="contract_id" class="form-select form-select-sm" style="max-width: 180px;">
                    <option value="">Ticket default</option>
                    @foreach($ticket->client?->contracts ?? [] as $ct)
                        <option value="{{ $ct->id }}" {{ $ticket->contract_id == $ct->id ? 'selected' : '' }}>
                            {{ $ct->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="input-group input-group-sm" style="width: auto;">
                <span class="input-group-text"><i class="bi bi-arrow-left-right"></i></span>
                <select name="new_status" class="form-select form-select-sm" id="noteStatusSelect" style="width: auto;">
                    <option value="">No change</option>
                    @foreach($ticket->status->allowedTransitions() as $s)
                        @if($s !== \App\Enums\TicketStatus::Closed)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div class="d-none" id="noteResolutionGroup">
                <input type="text" name="resolution" class="form-control form-control-sm"
                       placeholder="Brief resolution summary...">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Add</button>
        </div>
    </form>
</div>
```

- [ ] **Step 2: Verify syntax**

Run: `php -l resources/views/tickets/_action-note.blade.php`

- [ ] **Step 3: Commit**

```bash
git add resources/views/tickets/_action-note.blade.php
git commit -m "Create Note action panel partial"
```

---

### Task 5: Reply Action Panel

Extract the Reply action into its own partial.

**Files:**
- Create: `resources/views/tickets/_action-reply.blade.php`

- [ ] **Step 1: Create the Reply action panel**

Create `resources/views/tickets/_action-reply.blade.php`:

```blade
{{-- Reply action panel --}}
@php $contactEmail = $ticket->contact?->email; @endphp
<div class="action-panel d-none" id="actionReply">
    <form method="POST" action="{{ route('tickets.notes.store', $ticket) }}">
        @csrf
        <input type="hidden" name="note_type" value="reply">
        <input type="hidden" name="is_private" value="0">
        <div class="row g-2 mb-2">
            <div class="col-md-6">
                <div class="input-group input-group-sm">
                    <span class="input-group-text" style="width: 38px;">To</span>
                    <input type="email" name="to_email" class="form-control" id="replyToEmail"
                           value="{{ $contactEmail }}" placeholder="recipient@example.com"
                           list="contactEmails">
                </div>
            </div>
            <div class="col-md-6">
                <div class="input-group input-group-sm">
                    <span class="input-group-text" style="width: 38px;">Cc</span>
                    <input type="text" name="cc_emails" class="form-control"
                           placeholder="comma-separated emails">
                </div>
            </div>
        </div>
        <datalist id="contactEmails"></datalist>
        <div class="mb-2">
            <x-markdown-editor name="body" id="replyBody" rows="3" placeholder="Write a reply..." :required="true"
                :upload-url="route('tickets.attachments.store', $ticket)" />
        </div>
        <div class="d-flex flex-wrap align-items-center gap-3">
            @if(\App\Support\AiConfig::isConfigured())
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-secondary" id="draftReplyBtn" title="AI Draft Reply">
                    <i class="bi bi-robot me-1"></i>Draft
                </button>
                <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split"
                        data-bs-toggle="dropdown" aria-expanded="false" title="Draft with instructions">
                    <span class="visually-hidden">Toggle instructions</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end p-2" style="min-width: 320px;" id="draftInstructionsDropdown">
                    <label class="form-label small mb-1">Instructions for AI</label>
                    <input type="text" class="form-control form-control-sm" id="draftInstructions"
                           placeholder="e.g., Tell them we replaced the drive" maxlength="500">
                    <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-2" id="draftWithInstructionsBtn">
                        <i class="bi bi-robot me-1"></i>Draft with instructions
                    </button>
                </div>
            </div>
            @endif
            <div class="input-group input-group-sm" style="width: 140px;">
                <span class="input-group-text"><i class="bi bi-clock"></i></span>
                <input type="text" name="time" class="form-control" placeholder="0h 15m" id="replyTimeInput">
            </div>
            <div class="input-group input-group-sm" style="width: auto;">
                <span class="input-group-text"><i class="bi bi-arrow-left-right"></i></span>
                <select name="new_status" class="form-select form-select-sm" id="replyStatusSelect" style="width: auto;">
                    <option value="">No change</option>
                    @foreach($ticket->status->allowedTransitions() as $s)
                        @if($s !== \App\Enums\TicketStatus::Closed)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div class="d-none" id="replyResolutionGroup">
                <input type="text" name="resolution" class="form-control form-control-sm"
                       placeholder="Brief resolution summary...">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Send</button>
        </div>
        <div class="mt-1 small">
            <span class="text-muted">
                <i class="bi bi-envelope me-1"></i>This reply will be emailed to the recipients above.
            </span>
        </div>
    </form>
</div>
```

- [ ] **Step 2: Verify syntax**

Run: `php -l resources/views/tickets/_action-reply.blade.php`

- [ ] **Step 3: Commit**

```bash
git add resources/views/tickets/_action-reply.blade.php
git commit -m "Create Reply action panel partial"
```

---

### Task 6: Change Status Action Panel

Extract the Change Status action into its own partial.

**Files:**
- Create: `resources/views/tickets/_action-status.blade.php`

- [ ] **Step 1: Create the Change Status action panel**

Create `resources/views/tickets/_action-status.blade.php`:

```blade
{{-- Change Status action panel --}}
<div class="action-panel d-none" id="actionStatus">
    <form method="POST" action="{{ route('tickets.notes.store', $ticket) }}">
        @csrf
        <input type="hidden" name="note_type" value="note">
        <input type="hidden" name="body" value="">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="input-group input-group-sm" style="width: auto;">
                <span class="input-group-text"><i class="bi bi-arrow-left-right"></i></span>
                <select name="new_status" class="form-select form-select-sm" id="statusOnlySelect" style="width: auto;" required>
                    <option value="">Select status...</option>
                    @foreach($ticket->status->allowedTransitions() as $s)
                        @if($s !== \App\Enums\TicketStatus::Closed)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div class="d-none flex-grow-1" id="statusResolutionGroup" style="max-width: 400px;">
                <input type="text" name="resolution" class="form-control form-control-sm"
                       placeholder="Brief resolution summary...">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Update</button>
        </div>
    </form>
</div>
```

- [ ] **Step 2: Verify syntax**

Run: `php -l resources/views/tickets/_action-status.blade.php`

- [ ] **Step 3: Commit**

```bash
git add resources/views/tickets/_action-status.blade.php
git commit -m "Create Change Status action panel partial"
```

---

### Task 7: AI Chat Block Timeline Partial

Create the partial that renders AI conversations in the timeline — both active (with input) and read-only (collapsed).

**Files:**
- Create: `resources/views/tickets/_timeline-ai-chat.blade.php`

- [ ] **Step 1: Create the AI chat block partial**

Create `resources/views/tickets/_timeline-ai-chat.blade.php`. This partial receives `$conversation` (an `AssistantConversation` model with `user` and `messages` loaded) and `$loop` from the timeline `@forelse`.

```blade
{{-- AI conversation timeline entry --}}
@php
    $isOwner = auth()->id() === $conversation->user_id;
    $lastMessage = $conversation->messages->last();
    $isActive = $isOwner && $lastMessage?->created_at?->gt(now()->subMinutes(30));
    $messageCount = $conversation->messages->count();
@endphp

<div class="d-flex gap-3 py-3 {{ !$loop->last ? 'border-bottom' : '' }}"
     id="ai-chat-{{ $conversation->id }}"
     data-conversation-id="{{ $conversation->id }}"
     data-is-active="{{ $isActive ? '1' : '0' }}">
    <div class="flex-shrink-0">
        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
             style="width: 36px; height: 36px; background: #6f42c1; font-size: 0.9rem;">
            <i class="bi bi-robot"></i>
        </div>
    </div>
    <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <strong class="small">{{ $conversation->user?->name ?? 'Unknown' }}</strong>
            <span class="badge" style="background: #6f42c1; color: white; font-size: 0.7rem;">
                <i class="bi bi-robot me-1"></i>AI Conversation
            </span>
            @if($isActive)
                <span class="badge bg-success" style="font-size: 0.65rem;">Active</span>
            @endif
            <span class="text-muted small ms-auto" title="{{ $conversation->created_at->toAppTz()->format('Y-m-d H:i T') }}">
                {{ $conversation->created_at->diffForHumans() }}
            </span>
        </div>

        @if($isActive)
            {{-- Active chat: show all messages + input --}}
            <div class="ai-chat-messages mt-2" id="ai-chat-messages-{{ $conversation->id }}">
                @foreach($conversation->messages as $msg)
                    <div class="ai-chat-msg ai-chat-msg-{{ $msg->role }} mb-2">
                        <div class="ai-chat-msg-bubble">
                            @if($msg->role === 'user')
                                {{ $msg->content }}
                            @else
                                <div class="note-body">{!! \App\Helpers\MarkdownRenderer::render($msg->content) !!}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="ai-chat-typing d-none mt-2" id="ai-chat-typing-{{ $conversation->id }}">
                <div class="d-flex align-items-center gap-2 text-muted small">
                    <div class="spinner-border spinner-border-sm" role="status" style="width: 14px; height: 14px;"></div>
                    <span>Thinking...</span>
                </div>
            </div>
            <div class="ai-chat-input mt-2" id="ai-chat-input-{{ $conversation->id }}">
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control ai-chat-text" placeholder="Ask a question..."
                           data-conversation-id="{{ $conversation->id }}">
                    <button class="btn btn-outline-primary ai-chat-send" type="button"
                            data-conversation-id="{{ $conversation->id }}">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>
            </div>
        @else
            {{-- Read-only: collapsed summary --}}
            <div class="small text-muted">{{ $messageCount }} messages</div>
            <a class="small text-decoration-none" data-bs-toggle="collapse"
               href="#ai-chat-history-{{ $conversation->id }}">
                <i class="bi bi-chevron-down me-1"></i>Show conversation
            </a>
            <div class="collapse" id="ai-chat-history-{{ $conversation->id }}">
                <div class="mt-2 p-2 bg-light rounded">
                    @foreach($conversation->messages as $msg)
                        <div class="mb-2 small">
                            <strong class="{{ $msg->role === 'user' ? 'text-primary' : 'text-purple' }}">
                                {{ $msg->role === 'user' ? ($conversation->user?->name ?? 'Tech') : 'AI' }}:
                            </strong>
                            @if($msg->role === 'user')
                                {{ $msg->content }}
                            @else
                                <div class="note-body">{!! \App\Helpers\MarkdownRenderer::render($msg->content) !!}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
```

- [ ] **Step 2: Verify syntax**

Run: `php -l resources/views/tickets/_timeline-ai-chat.blade.php`

- [ ] **Step 3: Commit**

```bash
git add resources/views/tickets/_timeline-ai-chat.blade.php
git commit -m "Create AI chat block timeline partial (active + read-only)"
```

---

### Task 8: Ticket Show View — Replace Form + Render Chat Blocks

Replace the monolithic note form with action button includes, and add AI conversation rendering to the timeline loop.

**Files:**
- Modify: `resources/views/tickets/show.blade.php`

- [ ] **Step 1: Replace the note form with action buttons and panels**

In `resources/views/tickets/show.blade.php`, replace lines 75-181 (the entire note form, from `{{-- Add note form --}}` through the closing `</form>`) with:

```blade
                {{-- Action buttons --}}
                @include('tickets._action-buttons')

                {{-- Action panels (one visible at a time) --}}
                @include('tickets._action-note')
                @include('tickets._action-reply')
                @include('tickets._action-status')
```

- [ ] **Step 2: Add AI conversation rendering to the timeline loop**

In the timeline `@forelse` loop (starts around line 184 after the form replacement, now earlier), find the opening of the loop:

```blade
@forelse($timeline as $item)
    @if($item instanceof App\Models\PhoneCall)
```

Add a new condition before the PhoneCall check:

```blade
@forelse($timeline as $item)
    @if($item instanceof App\Models\AssistantConversation)
        @include('tickets._timeline-ai-chat', ['conversation' => $item])
    @elseif($item instanceof App\Models\PhoneCall)
```

- [ ] **Step 3: Remove the `data-assistant-context` div**

Remove line 6 from the file:

```blade
<div data-assistant-context="ticket" data-assistant-context-id="{{ $ticket->id }}"></div>
```

- [ ] **Step 4: Verify syntax**

Run: `php -l resources/views/tickets/show.blade.php`

- [ ] **Step 5: Commit**

```bash
git add resources/views/tickets/show.blade.php
git commit -m "Replace monolithic note form with action buttons, add AI chat to timeline"
```

---

### Task 9: Action Button Toggle JavaScript

Create the JS that handles action button toggling and per-panel form behavior.

**Files:**
- Create: `public/js/ticket-actions.js`

- [ ] **Step 1: Create the action button toggle JS**

Create `public/js/ticket-actions.js`:

```javascript
/**
 * Ticket action buttons: Note, Reply, Ask AI, Change Status
 * Handles toggling panels and per-panel form behavior.
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var buttons = document.querySelectorAll('.action-btn');
        var panels = document.querySelectorAll('.action-panel');
        var activeAction = null;

        // Map action names to panel IDs
        var panelMap = {
            'note': 'actionNote',
            'reply': 'actionReply',
            'status': 'actionStatus'
            // 'ask-ai' is handled separately by ticket-ai-chat.js
        };

        buttons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var action = this.dataset.action;

                // Ask AI is handled by ticket-ai-chat.js
                if (action === 'ask-ai') return;

                if (activeAction === action) {
                    // Toggle off: collapse current panel
                    closeAllPanels();
                    activeAction = null;
                    return;
                }

                // Close any open panel, open the new one
                closeAllPanels();
                var panelId = panelMap[action];
                if (panelId) {
                    document.getElementById(panelId).classList.remove('d-none');
                    this.classList.remove('btn-outline-secondary');
                    this.classList.add('btn-secondary');
                    activeAction = action;
                }
            });
        });

        function closeAllPanels() {
            panels.forEach(function(p) { p.classList.add('d-none'); });
            buttons.forEach(function(b) {
                if (b.dataset.action !== 'ask-ai') {
                    b.classList.remove('btn-secondary');
                    b.classList.add('btn-outline-secondary');
                }
            });
        }

        // Note panel: show/hide billable and contract when time entered
        var noteTimeInput = document.getElementById('noteTimeInput');
        if (noteTimeInput) {
            noteTimeInput.addEventListener('input', function() {
                var hasTime = this.value.trim();
                var billable = document.getElementById('noteBillableGroup');
                var contract = document.getElementById('noteContractGroup');
                if (billable) billable.classList.toggle('d-none', !hasTime);
                if (contract) contract.classList.toggle('d-none', !hasTime);
            });
        }

        // Note panel: show resolution when Resolved selected
        var noteStatusSelect = document.getElementById('noteStatusSelect');
        if (noteStatusSelect) {
            noteStatusSelect.addEventListener('change', function() {
                var group = document.getElementById('noteResolutionGroup');
                if (group) group.classList.toggle('d-none', this.value !== 'resolved');
            });
        }

        // Reply panel: show resolution when Resolved selected
        var replyStatusSelect = document.getElementById('replyStatusSelect');
        if (replyStatusSelect) {
            replyStatusSelect.addEventListener('change', function() {
                var group = document.getElementById('replyResolutionGroup');
                if (group) group.classList.toggle('d-none', this.value !== 'resolved');
            });
        }

        // Status-only panel: show resolution when Resolved selected
        var statusOnlySelect = document.getElementById('statusOnlySelect');
        if (statusOnlySelect) {
            statusOnlySelect.addEventListener('change', function() {
                var group = document.getElementById('statusResolutionGroup');
                if (group) group.classList.toggle('d-none', this.value !== 'resolved');
            });
        }

        // Populate contact email datalist
        var ticketClientId = document.body.dataset.clientId;
        if (ticketClientId) {
            fetch('/api/clients/' + ticketClientId + '/contacts')
                .then(function(r) { return r.json(); })
                .then(function(contacts) {
                    var datalist = document.getElementById('contactEmails');
                    if (datalist) {
                        contacts.forEach(function(c) {
                            if (c.email) {
                                var opt = document.createElement('option');
                                opt.value = c.email;
                                opt.textContent = c.name;
                                datalist.appendChild(opt);
                            }
                        });
                    }
                })
                .catch(function() {}); // Datalist is a convenience
        }
    });
})();
```

- [ ] **Step 2: Commit**

```bash
git add public/js/ticket-actions.js
git commit -m "Create ticket action button toggle JavaScript"
```

---

### Task 10: Inline AI Chat JavaScript

Create the JS that handles the "Ask AI" action — creating/resuming conversations and sending messages inline in the timeline.

**Files:**
- Create: `public/js/ticket-ai-chat.js`

- [ ] **Step 1: Create the inline AI chat JS**

Create `public/js/ticket-ai-chat.js`:

```javascript
/**
 * Inline AI chat for ticket timeline.
 * Handles: Ask AI button, create/resume conversations, send messages,
 * render responses in timeline chat blocks.
 */
(function() {
    'use strict';

    var CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content;
    var TIMEOUT_MS = 180000; // 3 minutes
    var ticketId = null;
    var activeConversationId = null;
    var sending = false;

    document.addEventListener('DOMContentLoaded', function() {
        // Get ticket ID from the page
        var match = window.location.pathname.match(/\/tickets\/(\d+)/);
        if (!match) return;
        ticketId = match[1];

        // Wire up Ask AI button
        var askAiBtn = document.getElementById('askAiBtn');
        if (askAiBtn) {
            askAiBtn.addEventListener('click', handleAskAi);
        }

        // Wire up any active chat inputs already on page (from server render)
        wireUpChatInputs();

        // Detect active conversation from server-rendered blocks
        var activeBlock = document.querySelector('[data-is-active="1"]');
        if (activeBlock) {
            activeConversationId = parseInt(activeBlock.dataset.conversationId);
        }
    });

    function handleAskAi() {
        // If there's an active conversation, scroll to it and focus input
        if (activeConversationId) {
            var block = document.getElementById('ai-chat-' + activeConversationId);
            if (block) {
                block.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var input = block.querySelector('.ai-chat-text');
                if (input) input.focus();

                // Highlight the Ask AI button
                var btn = document.getElementById('askAiBtn');
                if (btn) {
                    btn.classList.remove('btn-outline-secondary');
                    btn.classList.add('btn-secondary');
                }
                return;
            }
        }

        // Create a new conversation
        createConversation();
    }

    function createConversation() {
        fetch('/assistant/conversations', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                context_type: 'ticket',
                context_id: parseInt(ticketId)
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            activeConversationId = data.id;
            insertActiveChatBlock(data.id);

            // Highlight Ask AI button
            var btn = document.getElementById('askAiBtn');
            if (btn) {
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-secondary');
            }
        })
        .catch(function(err) {
            console.error('Failed to create conversation:', err);
        });
    }

    function insertActiveChatBlock(conversationId) {
        var timeline = document.querySelector('#notes .card-body');
        if (!timeline) return;

        // Find the first timeline entry (after action panels)
        var firstEntry = timeline.querySelector('.d-flex.gap-3, .d-flex.align-items-center.text-muted');
        // Also skip past action buttons and panels
        var panels = timeline.querySelectorAll('#actionButtons, .action-panel');
        var insertBefore = null;
        for (var i = 0; i < timeline.children.length; i++) {
            var child = timeline.children[i];
            if (child.id === 'actionButtons' || child.classList.contains('action-panel') || child.classList.contains('action-btn')) continue;
            if (child.classList.contains('d-flex') || child.classList.contains('border-bottom')) {
                insertBefore = child;
                break;
            }
        }

        var block = document.createElement('div');
        block.className = 'd-flex gap-3 py-3 border-bottom';
        block.id = 'ai-chat-' + conversationId;
        block.dataset.conversationId = conversationId;
        block.dataset.isActive = '1';

        block.innerHTML = '<div class="flex-shrink-0">' +
            '<div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" ' +
            'style="width: 36px; height: 36px; background: #6f42c1; font-size: 0.9rem;">' +
            '<i class="bi bi-robot"></i></div></div>' +
            '<div class="flex-grow-1">' +
            '<div class="d-flex align-items-center gap-2 mb-1 flex-wrap">' +
            '<strong class="small">AI Conversation</strong>' +
            '<span class="badge" style="background: #6f42c1; color: white; font-size: 0.7rem;">' +
            '<i class="bi bi-robot me-1"></i>Active</span></div>' +
            '<div class="ai-chat-messages mt-2" id="ai-chat-messages-' + conversationId + '"></div>' +
            '<div class="ai-chat-typing d-none mt-2" id="ai-chat-typing-' + conversationId + '">' +
            '<div class="d-flex align-items-center gap-2 text-muted small">' +
            '<div class="spinner-border spinner-border-sm" role="status" style="width: 14px; height: 14px;"></div>' +
            '<span>Thinking...</span></div></div>' +
            '<div class="ai-chat-input mt-2" id="ai-chat-input-' + conversationId + '">' +
            '<div class="input-group input-group-sm">' +
            '<input type="text" class="form-control ai-chat-text" placeholder="Ask a question..." ' +
            'data-conversation-id="' + conversationId + '">' +
            '<button class="btn btn-outline-primary ai-chat-send" type="button" ' +
            'data-conversation-id="' + conversationId + '">' +
            '<i class="bi bi-send-fill"></i></button></div></div></div>';

        if (insertBefore) {
            timeline.insertBefore(block, insertBefore);
        } else {
            timeline.appendChild(block);
        }

        wireUpChatInputs();
        block.querySelector('.ai-chat-text').focus();
    }

    function wireUpChatInputs() {
        // Send buttons
        document.querySelectorAll('.ai-chat-send').forEach(function(btn) {
            if (btn.dataset.wired) return;
            btn.dataset.wired = '1';
            btn.addEventListener('click', function() {
                var convId = this.dataset.conversationId;
                sendMessage(convId);
            });
        });

        // Enter key on input fields
        document.querySelectorAll('.ai-chat-text').forEach(function(input) {
            if (input.dataset.wired) return;
            input.dataset.wired = '1';
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    var convId = this.dataset.conversationId;
                    sendMessage(convId);
                }
            });
        });
    }

    function sendMessage(conversationId) {
        if (sending) return;

        var input = document.querySelector('#ai-chat-input-' + conversationId + ' .ai-chat-text');
        var text = input ? input.value.trim() : '';
        if (!text) return;

        sending = true;
        input.value = '';
        input.disabled = true;

        // Append user message
        appendMessage(conversationId, 'user', text);

        // Show typing
        var typing = document.getElementById('ai-chat-typing-' + conversationId);
        if (typing) typing.classList.remove('d-none');

        var controller = new AbortController();
        var timeoutId = setTimeout(function() { controller.abort(); }, TIMEOUT_MS);

        fetch('/assistant/conversations/' + conversationId + '/messages', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ message: text }),
            signal: controller.signal
        })
        .then(function(r) {
            clearTimeout(timeoutId);
            if (!r.ok) return r.json().then(function(d) { throw new Error(d.error || 'Request failed'); });
            return r.json();
        })
        .then(function(data) {
            appendMessage(conversationId, 'assistant', data.message.content, data.tools_used);
        })
        .catch(function(err) {
            appendMessage(conversationId, 'error', err.name === 'AbortError'
                ? 'Request timed out. The AI may still be processing.'
                : (err.message || 'An error occurred'));
        })
        .finally(function() {
            sending = false;
            if (typing) typing.classList.add('d-none');
            if (input) {
                input.disabled = false;
                input.focus();
            }
        });
    }

    function appendMessage(conversationId, role, content, toolsUsed) {
        var container = document.getElementById('ai-chat-messages-' + conversationId);
        if (!container) return;

        var div = document.createElement('div');
        div.className = 'ai-chat-msg ai-chat-msg-' + role + ' mb-2';

        if (role === 'error') {
            div.innerHTML = '<div class="alert alert-danger py-1 px-2 mb-0 small">' + escapeHtml(content) + '</div>';
        } else if (role === 'user') {
            div.innerHTML = '<div class="ai-chat-msg-bubble ai-chat-msg-user-bubble">' + escapeHtml(content) + '</div>';
        } else {
            var html = '<div class="ai-chat-msg-bubble ai-chat-msg-assistant-bubble note-body">' + renderMarkdown(content) + '</div>';
            if (toolsUsed && toolsUsed.length > 0) {
                html += '<div class="mt-1">';
                toolsUsed.forEach(function(t) {
                    html += '<span class="badge bg-light text-dark border me-1" style="font-size: 0.65rem;">' + escapeHtml(formatToolName(t)) + '</span>';
                });
                html += '</div>';
            }
            div.innerHTML = html;
        }

        container.appendChild(div);

        // Scroll the chat block into view
        div.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatToolName(name) {
        var prefixes = { ninja: 'NINJA', level: 'LEVEL', mesh: 'MESH', cipp: 'CIPP', controld: 'CTRL-D', zorus: 'ZORUS' };
        for (var prefix in prefixes) {
            if (name.startsWith(prefix + '_')) {
                return prefixes[prefix] + ': ' + name.substring(prefix.length + 1).replace(/_/g, ' ');
            }
        }
        return name.replace(/_/g, ' ');
    }

    function renderMarkdown(text) {
        // Basic markdown rendering — same approach as assistant-chat.js
        var html = escapeHtml(text);
        // Code blocks
        html = html.replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>');
        // Inline code
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        // Bold
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        // Italic
        html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        // Headers
        html = html.replace(/^### (.+)$/gm, '<h6>$1</h6>');
        html = html.replace(/^## (.+)$/gm, '<h5>$1</h5>');
        html = html.replace(/^# (.+)$/gm, '<h4>$1</h4>');
        // Lists
        html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
        html = html.replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>');
        html = html.replace(/<\/ul>\s*<ul>/g, '');
        // Paragraphs
        html = html.replace(/\n\n/g, '</p><p>');
        html = html.replace(/\n/g, '<br>');
        html = '<p>' + html + '</p>';
        return html;
    }
})();
```

- [ ] **Step 2: Commit**

```bash
git add public/js/ticket-ai-chat.js
git commit -m "Create inline AI chat JavaScript for ticket timeline"
```

---

### Task 11: AI Chat CSS

Create styles for the inline chat blocks and action buttons.

**Files:**
- Create: `public/css/ticket-ai-chat.css`

- [ ] **Step 1: Create the CSS**

Create `public/css/ticket-ai-chat.css`:

```css
/* Action button row */
.action-btn.btn-secondary {
    background-color: var(--primary, #1a365d);
    border-color: var(--primary, #1a365d);
    color: #fff;
}

/* Action panels */
.action-panel {
    padding-bottom: 1rem;
    margin-bottom: 1rem;
    border-bottom: 1px solid #e5e7eb;
}

/* AI chat message bubbles */
.ai-chat-msg-user-bubble {
    background: var(--primary, #1a365d);
    color: #fff;
    padding: 6px 12px;
    border-radius: 10px 10px 2px 10px;
    display: inline-block;
    max-width: 85%;
    font-size: 0.875rem;
}

.ai-chat-msg-assistant-bubble {
    background: #f3f4f6;
    color: #374151;
    padding: 8px 12px;
    border-radius: 10px 10px 10px 2px;
    display: inline-block;
    max-width: 95%;
    font-size: 0.875rem;
}

.ai-chat-msg-user {
    text-align: right;
}

.ai-chat-msg-assistant {
    text-align: left;
}

/* Markdown inside assistant bubbles */
.ai-chat-msg-assistant-bubble h4,
.ai-chat-msg-assistant-bubble h5,
.ai-chat-msg-assistant-bubble h6 {
    font-size: 0.9rem;
    font-weight: 600;
    margin: 8px 0 4px;
}

.ai-chat-msg-assistant-bubble p {
    margin: 4px 0;
}

.ai-chat-msg-assistant-bubble ul,
.ai-chat-msg-assistant-bubble ol {
    margin: 4px 0;
    padding-left: 20px;
}

.ai-chat-msg-assistant-bubble code {
    background: rgba(0, 0, 0, 0.06);
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 0.8rem;
}

.ai-chat-msg-assistant-bubble pre {
    background: rgba(0, 0, 0, 0.06);
    padding: 8px 10px;
    border-radius: 6px;
    margin: 6px 0;
    overflow-x: auto;
}

/* Collapsed read-only conversation */
.text-purple {
    color: #6f42c1;
}
```

- [ ] **Step 2: Commit**

```bash
git add public/css/ticket-ai-chat.css
git commit -m "Create inline AI chat CSS styles"
```

---

### Task 12: Load New JS/CSS in Ticket Show View

Add the new JS and CSS files to the ticket show page, and remove the inline JS that's been replaced by the partials and new JS files.

**Files:**
- Modify: `resources/views/tickets/show.blade.php`

- [ ] **Step 1: Add CSS to the head section**

At the top of the `@section('content')` block (or in a `@push('styles')` section if one exists), add:

```blade
@push('styles')
<link rel="stylesheet" href="{{ asset('css/ticket-ai-chat.css') }}?v={{ filemtime(public_path('css/ticket-ai-chat.css')) }}">
@endpush
```

- [ ] **Step 2: Add JS files to the scripts section**

In the `@push('scripts')` section at the bottom of the file, add before the existing `<script>` tag:

```blade
<script src="{{ asset('js/ticket-actions.js') }}?v={{ filemtime(public_path('js/ticket-actions.js')) }}"></script>
<script src="{{ asset('js/ticket-ai-chat.js') }}?v={{ filemtime(public_path('js/ticket-ai-chat.js')) }}"></script>
```

- [ ] **Step 3: Add data-client-id to body for contact datalist**

In the `@push('scripts')` section, add a small script block that sets the client ID for the action JS:

```blade
<script>document.body.dataset.clientId = '{{ $ticket->client_id }}';</script>
```

- [ ] **Step 4: Clean up replaced inline JS**

In the `@push('scripts')` section, remove the following blocks that have been moved to `ticket-actions.js`:
- The note status/resolution toggle (lines ~1161-1171 in the original)
- The note type toggle for private/reply (lines ~1173-1189)
- The time input billable toggle (lines ~1191-1201)
- The contact email datalist population (lines ~1203-1221)

Keep the AI Draft Reply JS (it still lives on the page since it interacts with the Reply panel's EasyMDE editor). Keep the move ticket, merge ticket, category cascade, toggle system notes, and run script JS — those are unrelated to this change.

- [ ] **Step 5: Verify syntax**

Run: `php -l resources/views/tickets/show.blade.php`

- [ ] **Step 6: Commit**

```bash
git add resources/views/tickets/show.blade.php
git commit -m "Load new JS/CSS, clean up replaced inline JavaScript"
```

---

### Task 13: Deploy and Test

Deploy all changes and verify the full flow.

**Files:** None (deployment and testing only)

- [ ] **Step 1: Push and deploy**

```bash
git push
ssh your-vps "cd /var/www/psa && git pull && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan queue:restart || true"
ssh your-vps "echo '<?php opcache_reset(); echo \"ok\";' > /var/www/psa/public/opcache-clear.php && curl -s https://your-psa-domain/opcache-clear.php && rm /var/www/psa/public/opcache-clear.php"
```

- [ ] **Step 2: Verify action buttons**

Navigate to any ticket. Confirm:
- Four action buttons visible (Note, Reply, Ask AI, Change Status)
- No always-present form — just the button row
- Clicking "Note" expands the note panel with editor, private, time, status
- Clicking "Reply" expands the reply panel with To/Cc, editor, AI Draft, status
- Clicking "Change Status" expands the status panel with dropdown
- Clicking an active button collapses it
- Only one panel open at a time

- [ ] **Step 3: Test Note creation**

Open Note panel, write a note, add time, change status → verify note appears in timeline, status changes.

- [ ] **Step 4: Test Reply creation**

Open Reply panel, write a reply, verify email is sent and note appears in timeline.

- [ ] **Step 5: Test Ask AI**

Click "Ask AI" → verify new chat block appears at top of timeline. Type a question → verify response renders inline with tool badges. Close the page and return → verify the conversation renders as read-only (collapsed) in the timeline. Verify other staff can see the conversation.

- [ ] **Step 6: Test 30-minute auto-close**

If an old AI conversation exists (or wait 30 min), verify it renders as read-only collapsed in the timeline, and clicking "Ask AI" creates a new conversation.
