# Persona conversation-binding reset (unbind) — design

**Bead:** psa-3vr5 · **Base:** origin/main (bb073df) · **Status:** HOLD MERGE for Mayor review

## Problem

A Teams AI-staff persona's operator lane is bound by an **atomic, write-once
auto-capture**: the first allowlist-gated inbound turn writes
`teams_personas.conversation_refs = {conversation_id, service_url}` via
`whereNull('conversation_refs')->update(...)` and it is never overwritten
(`TeamsMessagesController::captureConversationRefs`). That bind is **permanent
by design** — there is no correction path today if:

- the wrong first sender claims the lane (the *fail-open window*: when the
  operator allowlist is empty/unconfigured, capture fails **open** and
  first-sender-wins), or
- the operator conversation must simply move.

This is a prerequisite before a 2nd persona or the self-service provisioning
wizard. We need an admin action to clear the binding.

## Decision: unbind only (no explicit manual rebind)

The bead title says "unbind + rebind," but Mayor constraint (3) — *"unbind is the
ONLY sanctioned path to a re-capture"* — forbids a second write path to
`conversation_refs`. An explicit "type in the new conversation_id/service_url"
rebind would be exactly that second path, and it is a footgun besides: those are
opaque Bot Framework values an operator can't reasonably hand-enter.

So **"rebind" is the existing auto-capture re-firing** after an unbind: clear the
binding → the next allowlist-gated inbound turn re-establishes it (`whereNull`
matches null refs again). The deliverable is the **unbind** action; re-capture is
already built and tested (`PersonaConversationCaptureTest`).

## Why unbind is safe (blast radius)

Clearing `conversation_refs` returns the persona to its pristine, pre-capture
state. Every consumer already null-guards:

- `OperatorDelivery::send()` — a persona with null targets **fails closed**
  (drops + logs, never misroutes to the legacy lane); the direct-to-user email
  fallback still runs.
- `TeamsMessagesController::routedToPersona()` — null refs never match an inbound
  conversation, so nothing routes to the persona's operator lane.

There is no destructive data loss — only a re-capturable reset.

## Components

### Route (staff `web`/`auth` group, beside `teams-bot.update`)

```
DELETE /settings/integrations/personas/{persona}/conversation-binding
  name: settings.integrations.persona.unbind-conversation
```

`DELETE` (not POST) matches house style for destructive settings actions (MCP
token revoke) and is semantically precise — we delete the conversation-binding
sub-resource, not the persona.

### Controller — `IntegrationsController::unbindPersonaConversation(Request, TeamsPersona)`

1. Snapshot `old = $persona->conversation_refs`.
2. **Idempotent early-out:** if already unbound (`old` has no `conversation_id`),
   redirect back with a neutral message; write **no** audit row (nothing changed).
   Mirrors `McpTokensController::revoke()`'s already-revoked early-out.
3. **Atomic clear:**
   ```php
   $cleared = TeamsPersona::whereKey($persona->id)
       ->whereNotNull('conversation_refs')
       ->update(['conversation_refs' => null]);
   ```
   The `whereNotNull` guard is the inverse of capture's `whereNull` — race-safe,
   returns the affected-row count for free.
4. On `$cleared === 1`: `TeamsPersonaConfig::flush()` (query-builder updates fire
   no model events, so the per-request `enabled()`/`active()` memo must be busted
   explicitly — exactly as the capture path does).
5. **Audit** via `McpAuditLog` (see below), then
   `redirect()->route('settings.integrations')->with('success', ...)`.

**Why query-builder + explicit flush, not `->save()`:** symmetric-inverse of the
capture path, which deliberately mutates `conversation_refs` via the query
builder (its docblock warns against `forceFill` on the memoized `byKey()`
instance). It also avoids re-running the model `saving` hook's unrelated
validations (`mcp_token_label` existence, legacy-app_id collision) on a save that
only touches `conversation_refs`.

### Audit — `McpAuditLog` (pattern mirrors `McpTokensController::audit()`)

The settings-surface audit sink; the MCP-token-revoke precedent (settings +
destructive + audited) writes here, and personas are already coupled to
`McpToken` via `mcp_token_label`. No new migration.

```php
McpAuditLog::create([
    'server_name' => 'staff',
    'method'      => 'persona/unbind_conversation',
    'tool_name'   => mb_substr($persona->persona_key, 0, 100),
    'arguments'   => ['old_conversation_id' => ..., 'old_service_url' => ...],
    'status'      => 'success',
    'actor_label' => 'web:'.$request->user()?->email,   // WHO
    'source_ip'   => $request->ip(),
    // created_at timestamp                              // WHEN
]);                                                       // old->new in arguments
```

Wrapped in the same fail-soft `try/catch` as the precedent.

### Roster feed — `IntegrationsController::index()` (~296–305)

Extend the SAFE-fields map with `'id' => $p->id` and
`'conversation_bound' => filled((($p->conversation_refs ?? [])['conversation_id'] ?? null))`,
plus the `conversation_id` for display (a Bot Framework conversation id — not a
secret; the roster already surfaces `bot_app_id`). `bot_client_secret` stays out,
as before.

### View — AI-Staff roster (`integrations.blade.php` ~3262–3322)

Per persona, add an "Operator conversation" line:

- **Bound** → show a short/monospace `conversation_id` + a confirm-gated
  **Reset conversation binding** control:
  ```blade
  <form method="POST"
        action="{{ route('settings.integrations.persona.unbind-conversation', $persona['id']) }}"
        onsubmit="return confirm(@js('Reset '.$persona['display_name'].'\'s operator conversation binding? The next allowlisted contact will re-establish it.'))">
      @csrf @method('DELETE')
      <button class="btn btn-sm btn-outline-danger">Reset conversation binding</button>
  </form>
  ```
- **Not bound** → a muted "Not bound" note; no control.

## Tests (TDD, `tests/Feature/Teams/PersonaConversationUnbindTest.php`)

1. `test_unbind_clears_conversation_refs_and_flushes_the_registry_memo` — bound
   persona → DELETE → refs null AND `TeamsPersonaConfig::byKey()` sees the change
   (memo flushed).
2. `test_unbind_writes_an_audit_record_with_actor_and_old_conversation_id` —
   `McpAuditLog` row: `actor_label=web:{email}`, `method=persona/unbind_conversation`,
   `arguments.old_conversation_id` = the pre-unbind id.
3. `test_unbind_on_an_already_unbound_persona_is_a_safe_noop_with_no_audit` — null
   refs → DELETE → still null, zero `McpAuditLog` rows, clean redirect.
4. `test_after_unbind_the_next_allowlisted_inbound_recaptures` — end-to-end proof
   of the whole loop: bind → unbind → replay a real allowlisted inbound (JWT/aud/
   serviceUrl-pinned, reusing the capture test's machinery) → re-captured. This is
   the empirical "webhook replay" verification of the Mayor's core requirement.
5. `test_roster_shows_reset_control_and_binding_only_for_bound_personas` — bound
   shows the id + Reset control; unbound shows "Not bound", no control; secret
   never rendered.
6. `test_unbind_requires_authentication` — guest → redirect to login.

## Out of scope (handoff findings, not blockers)

- **No UI editor for the operator allowlist** (`teams_operator_allowlist_user_ids`
  is read-only, configured out-of-band). Unbind's effectiveness against the
  fail-open window depends on the allowlist being set correctly; without an
  editor, the same wrong sender could re-capture. The Mayor's brief already
  assumes allowlist-gating. Candidate follow-up bead.
- Persona create/edit/delete provisioning wizard (P2) — unchanged.
