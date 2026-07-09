# MCP tool-surface refresh (staff server)

How a client's view of its granted tools stays correct when an operator changes
a token's grants mid-session.

## The problem

An MCP client reads `tools/list` **once at startup** and caches it. The Sound PSA
staff MCP endpoint (`POST /api/mcp/staff`) is stateless request/response — there
is no SSE/streaming channel and no session registry, so the server cannot push
an `notifications/tools/list_changed` to a live client. If an operator grants or
revokes a tool on the token (Settings → MCP Tokens) after the client connected,
the client's cached list is stale.

The cached list is a **cache, not truth**. The truth is re-checked on every call.

## Removal is already safe — zero refresh needed

`McpStaffController::toolAllowed()` re-reads the token's current grants from the
DB on **every** `tools/call`. A tool the operator revoked fails the grant check
immediately, regardless of whether the client still lists it. So the dangerous
direction (a tool that *should* be gone still being callable) cannot happen — the
call is denied at invocation time. The only cost of staleness is UX: the client
may still *offer* a tool that will now be refused, or *not offer* one that was
just granted.

This makes the whole problem symmetric cache-invalidation UX, not a security gap.

## What ships today

### (b) Stale-call self-heal — the denial is the refresh signal

When a `tools/call` fails the grant check, the error text carries a drift hint
(`McpStaffController::TOOL_SURFACE_DRIFT_HINT`):

> Tool not allowed for this token: `<name>`. The token's allowed-tool surface may
> have changed since this client cached tools/list; whoami returns the current
> allowed tools, and your token directive governs how to proceed.

The failure itself becomes the signal the model can act on: if a call is refused,
the surface may have drifted — reconcile it. The audit ledger keeps only the bare
reason; the hint is client-facing UX.

### (c) whoami-on-wake drift check — usable today

The `whoami` tool already returns the **live** `allowed_tools` (computed from
current grants at call time, not from any snapshot). A client that calls `whoami`
on wake/resume and reconciles the result against its startup `tools/list`
snapshot detects both additions and removals with no server change. This is a
practical habit an agent (e.g. the Chet bridge token) can adopt now.

## Authority / trigger separation (design invariant)

Refresh **triggers** — the stale-call hint above, and the grant-change signal in
(a) below — stay **instruction-free**. They carry facts (a tool was denied; the
surface may have changed) and at most a pointer to the token **directive**, which
is the durable, operator-authored **authority**. They never issue imperatives.

Why: a trigger can arrive on an untrusted pipe. A trigger that carries no
authority fails safe — a forged copy cannot make the agent act, because the
authority is the directive and the directive does not change based on the trigger.
whoami is the sanctioned in-band verification (`whoami` returns the live set); the
directive governs what to do about drift. Note this is also why `whoami`'s own
description and output are kept posture-free (see `McpStaffWhoamiTest`).

## (a) Grant-change signal emission — lands with the wake spec

The fuller fix wakes a live session automatically when its grants change, instead
of waiting for the next call or wake. Grant mutations (`updateTools` / `revoke` /
destination link) would emit a reference-only signal event
(`msp.mcp.tool_surface_changed`, payload = token label, `changed=true`) on the
Signal plane. That event becomes a first-class **wake trigger**: grant change →
wake/nudge the token's bound session → the runtime re-fetches `tools/list`.

The signal payload stays instruction-free per the invariant above — it names the
changed token and nothing more; the bound session's directive supplies the
authority.

This half is **sequenced to land with the wake spec** (psa-rgo5 / psa-59pt), whose
counterpart town-side bead owns the GC-runtime re-projection (wake → re-fetch)
half. Emitting the signal ahead of an agreed producer/consumer contract would be
speculative, so it is tracked separately and not shipped here.
