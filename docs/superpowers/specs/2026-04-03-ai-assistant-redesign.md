# AI Assistant Redesign — Design Spec

**Date:** 2026-04-03
**Status:** Approved

## Problem

The current AI assistant lives in a right-side panel that follows the technician across all pages. There is no way to switch between conversations — starting a new chat flushes the current one. Conversations don't persist per-ticket, so returning to a ticket means starting over. The panel is narrow, cumbersome, and squishes the ticket content when open.

## Solution

Two conversation surfaces replacing the single panel:

1. **Ticket AI** — Inline chat blocks in the ticket timeline, triggered by an "Ask AI" action button. Each block is a discrete conversation that auto-closes after 30 minutes of inactivity. Multiple conversations per ticket over its lifecycle. All staff can see all conversations. Context derived from the conversation record, not the current page URL.

2. **General Assistant** — Floating chat bubble (bottom-right corner), available on all pages. Per-tech persistent conversation for strategic and cross-ticket questions. Private to each tech.

The right-side AI panel is removed entirely.

## Ticket Notes Area Redesign

The current always-present note form (with fields showing/hiding based on note type selection) is replaced by an **action button row**. Each button expands its own set of controls below. Only one action is active at a time. Clicking an active button collapses it. The active button gets a highlighted/filled visual state.

### Action Buttons

**Note**
- Markdown editor
- Private checkbox (checked by default)
- Time entry
- Billable toggle (visible when time entered)
- Contract selector (visible when billable)
- Status dropdown (No change / allowed transitions)
- Resolution summary field (visible when transitioning to Resolved)
- "Add" submit button

**Reply**
- To / Cc email fields (To pre-filled with contact email)
- Contact email datalist
- Markdown editor
- AI Draft button (with instructions dropdown, same as current)
- Time entry
- Status dropdown (No change / allowed transitions)
- Resolution summary field (visible when transitioning to Resolved)
- "Send" submit button
- Email warning indicator

**Ask AI**
- Creates or resumes an inline AI chat block at the top of the timeline
- If an active conversation exists for this tech + ticket (last message < 30 min ago), resumes it
- Otherwise creates a new conversation
- The chat input lives inside the timeline block, not in the action area
- Clicking "Ask AI" when a chat is already active scrolls to it

**Change Status**
- Status dropdown (populated from `allowedTransitions()`)
- Resolution summary field (visible when transitioning to Resolved)
- "Update" submit button

## Inline AI Chat Blocks

### Active State (< 30 min since last message, owned by current tech)

Appears at the top of the timeline (newest first). Contains:
- Header: purple robot icon, "AI Conversation" label, tech name, "active" indicator, timestamp
- Message history: user messages and assistant responses rendered inline
- Tool usage badges on assistant messages (formatted tool names)
- Typing indicator when AI is processing
- Chat input area at the bottom of the block (textarea + send button)

### Read-Only State (> 30 min idle, or owned by another tech)

Rendered in the timeline chronologically by `created_at`. Contains:
- Collapsed view: purple robot icon, "AI conversation with {tech name} — {n} messages", timestamp
- Expandable: click to show full transcript
- No input area

### Conversation Lifecycle

1. Tech clicks "Ask AI" -> system checks for active conversation (this tech + this ticket + last message < 30 min)
2. If active conversation found -> resume it (scroll to the block, focus input)
3. If no active conversation -> create new `AssistantConversation` with `context_type='ticket'`, `context_id=ticket.id`
4. New block appears at top of timeline with chat input
5. Each message exchange: user message saved, AI processes (with tool loop), assistant response saved
6. The block renders as a timeline entry that updates live
7. After 30 min of no messages, the block becomes read-only on next page load
8. Next "Ask AI" click creates a new conversation

### Context and Tool Scoping

The system prompt and available tools are determined by the `AssistantConversation` record's `context_type` and `context_id`, NOT by the tech's current page URL. This means:
- Ticket conversations always have full ticket context (description, notes, assets, contracts, triage data) regardless of where the tech is browsing
- Client scoping for tool execution uses the ticket's `client_id`
- The existing `ContextBuilder::buildForTicket()` and `AssistantToolDefinitions::getTools()` are reused

### Visibility

All ticket AI conversations are visible to all staff in the ticket timeline. A tech viewing a ticket sees every AI conversation that has happened on that ticket, regardless of who initiated it. Only the conversation owner can continue an active conversation.

## General Assistant (Chat Bubble)

### UI

- Floating chat bubble: bottom-right corner, available on all pages when `AssistantConfig::isEnabled()`
- Click to open a chat popover/flyout above the bubble
- Popover contains: message history, typing indicator, text input
- Close button or click-away to dismiss (conversation persists)

### Behavior

- Each tech has one general assistant conversation (`context_type = null`)
- Created on first use, reused across all sessions
- No ticket/client context — the system prompt notes limited tool access
- Tools: only available if the tech happens to be on a client page (detected from URL as fallback), otherwise general knowledge only
- Persists indefinitely (no 30-min timeout — this is the tech's ongoing assistant)

### Use Cases

- "What should I work on next?"
- "Show me the oldest non-alert tickets"
- "How many tickets did we close this week?"
- General MSP workflow questions

## Data Model Changes

### AssistantConversation (existing table, no schema changes)

The existing schema already supports this design:
- `user_id` — conversation owner
- `context_type` — 'ticket', 'client', or null (general)
- `context_id` — ticket ID, client ID, or null
- `title` — auto-set from first message
- Token tracking columns

**Behavioral change:** Multiple conversations per user per ticket are now allowed (previously the JS enforced one at a time via localStorage). The 30-minute gap between messages delimits separate conversations.

### AssistantMessage (existing table, no schema changes)

No changes needed.

### Timeline Integration

AI chat blocks are `AssistantConversation` records rendered inline in the timeline alongside notes and phone calls. The timeline query joins conversations with notes and phone calls, ordered chronologically. No new NoteType enum value needed — conversations are their own entity.

## What Gets Removed

- **Right-side AI panel** (`aside#assistant-panel` in layouts/app.blade.php)
- **Panel JavaScript** (`public/js/assistant-chat.js`) — replaced by inline chat JS and bubble JS
- **Panel CSS** (`public/css/assistant-chat.css`) — replaced by inline chat styles and bubble styles
- **localStorage session tracking** (`psa-assistant`, `psa-assistant-open`) — no longer needed
- **`data-assistant-context` div** on ticket/client show pages — context comes from conversation record
- **`data-assistant-toggle` buttons** — replaced by "Ask AI" action button

## What Stays

- `AssistantController` — endpoints for create, messages, save-note (may need minor adjustments)
- `AssistantService` — core AI logic, tool loop, context building (unchanged)
- `AssistantToolDefinitions` — tool registry (unchanged)
- `AssistantToolExecutor` — tool execution (unchanged)
- `AssistantConfig` — configuration (unchanged)
- All AI provider integration (`AiClient`, `AiConfig`, etc.)

## API Changes

### Existing Endpoints (kept, minor adjustments)

- `POST /assistant/conversations` — create conversation (unchanged)
- `GET /assistant/conversations/{id}` — get messages (unchanged)
- `POST /assistant/conversations/{id}/messages` — send message (unchanged)
- `POST /assistant/conversations/{id}/save-note` — save as ticket note (unchanged)

### New Endpoints

- `GET /assistant/conversations/for-ticket/{ticket}` — returns all conversations for a ticket, newest first. Used by the timeline to render AI chat blocks. Returns: conversation ID, user name, message count, created_at, updated_at, whether it's active (last message < 30 min ago and owned by current user).

- `GET /assistant/general` — returns the current user's general conversation (creates if none exists). Used by the chat bubble on page load.

## Implementation Phases

### Phase 1: Notes area redesign + Ask AI inline
- Refactor note input into action button row (Note, Reply, Ask AI, Change Status)
- Each action expands its own controls; Note and Reply include status dropdown
- Implement inline AI chat blocks in ticket timeline
- 30-minute auto-close logic
- All-staff visibility of conversations in timeline

### Phase 2: General assistant bubble
- Floating chat bubble component
- General conversation persistence
- Remove right-side panel

### Phase 3: Cleanup
- Remove panel HTML, JS, CSS
- Remove localStorage tracking
- Update any references to the old panel
