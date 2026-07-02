# CIPP ExecMCP Relay Phase 1

## Grounding

Mayor eval, 2026-07-02: CIPP ExecMCP works with client-credentials auth and server-side `?tools=` scoping, but official `ListUsers` still returns raw Graph firehose data. Baseline observed in the eval was 3 users / 19,241 bytes, about 6.4KB per user, and a larger live tenant produced about 485KB.

## Scope

Phase 1 keeps the existing `cipp_*` tool names exposed through the PSA MCP staff endpoint. It does not add the other CIPP ExecMCP tools. The relay is gated by `cipp_mcp_enabled`, default off, so deployed code keeps the legacy `api/List*` path until the Mayor stages the dedicated MCP credentials and flips the setting.

## Build

- Add `CippMcpClient` for `api://{cipp_mcp_client_id}/.default` client-credentials auth, scoped `POST /api/ExecMCP?tools=<tool>`, and SSE `data:` JSON-RPC parsing.
- Add `CippMcpToolRelay` behind the assistant CIPP dispatch path.
- Preserve legacy OFF-path byte shape by continuing to call `CippClient::get()` unchanged.
- ON path maps current tool names to ExecMCP names, keeps client tenant scoping, rejects non-schema arguments, projects returned rows to fixed bounded fields, fences projected free text with `ChetDataSurfaceTextSanitizer`, and keeps MCP boundary audit rows.
- The ExecMCP POST path validates and request-pins the configured CIPP API host before attaching the MCP bearer token.
- Store `cipp_mcp_client_id` plainly and `cipp_mcp_client_secret` encrypted in `settings`.

## Projection Evidence

The feature test fixture projects away sensitive/default-denied user fields such as password profiles and access tokens, drops raw audit blobs, and fences projected free text and nested projected strings. The production-sized measurement still needs a real post-enable smoke because the merge is intentionally dormant.
