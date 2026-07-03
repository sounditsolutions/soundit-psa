# MCP Tool Description Hygiene + MSP Tool Instructions

> **Work bead:** `psa-u6gm`
> **Executor:** `developer`
> **Mode:** plan-first, then strict TDD implementation after Mayor review.

## Goal

Make the staff MCP tool surface platform-generic and MSP-configurable.

The MCP tool descriptions should describe only what each tool does, what arguments it accepts, and the concrete contract enforced by the server. They should not mention Chet as the caller and should not bake subjective MSP policy into the description text. MSP-specific guidance moves into a new Settings-controlled per-tool custom-instruction appendix, appended to that tool's description only when `tools/list` is rendered.

The plan also includes the Mayor's `SCOPE +1`: remove the legacy `teams-bot` fallback actor labels from the MCP staff path.

## Authenticated Source Context

- `psa-u6gm`: sweep `McpToolRegistry` and `AssistantToolDefinitions` descriptions to be purely functional and policy-free.
- `psa-u6gm`: add an MSP-global per-tool custom-instruction text box in settings; append that text at `tools/list` time. Per-token layering is explicitly later; token-level directive already exists via `whoami`.
- Mayor comment 2026-07-03 00:03Z: also replace literal `teams-bot` fallback strings in `McpStaffController::actorLabel()`, `McpStaffController::whoami()`, and `McpStaffToken::actorLabel()` with a platform-neutral legacy actor label.

## Current Shape

- `McpStaffController::listTools()` builds the runtime tool list, injects `client_id` into client-scoped schemas, filters by token grants, and returns `description => $t['description'] ?? ''`.
- `McpToolRegistry::groups()` feeds the token settings UI; the index and token detail pages render each registry description directly.
- `McpTokensController` owns token minting, tool grants, token directives, revocation, Signal destination links, and audit rows. It has no MSP-global tool-instruction route yet.
- `Setting` is the existing per-install key/value store for configuration. JSON-in-`Setting` is already used for similar map-style configuration, so no migration is needed for v1.
- `McpStaffToken::actorLabel()` and two controller fallbacks still emit `teams-bot` only on the legacy/labelless path. Labeled tokens already emit `mcp-staff:<label>`.

## Scope

In scope:

- Runtime staff MCP tool descriptions returned from `/api/mcp/staff` `tools/list`.
- Registry descriptions displayed on the MCP token settings pages.
- `McpToolRegistry`, `AssistantToolDefinitions`, and exposed registry providers reachable from staff MCP:
  - `OperatorBridgeTools`
  - `TeamsChatReadToolset`
  - `TriageToolDefinitions` where the same definitions are exposed through `AssistantToolDefinitions`.
- Settings UI for MSP-global per-tool custom instructions.
- Legacy MCP staff actor-label fallback cleanup.
- Focused docs update for MCP staff configuration.

Out of scope:

- Per-token custom instructions.
- New RBAC or admin gates.
- Changing MCP tool grants or exposing additional tools.
- Changing server-side safety enforcement, held-action behavior, or Chet-specific runtime boundaries.
- Broad non-MCP prompt/policy rewrites outside the descriptions exposed by this tool surface.

## Design

### 1. Add a Settings-backed tool instruction helper

New file:

- `app/Support/McpToolInstructions.php`

Responsibilities:

- Store instructions in `settings.value` under `mcp_tool_custom_instructions` as a JSON object keyed by tool name.
- Expose:
  - `all(): array<string, string>`
  - `forTool(string $toolName): ?string`
  - `replaceAll(array $instructions): void`
  - `appendToDescription(string $toolName, string $description): string`
- Trim whitespace, drop empty values, normalize unknown keys away by intersecting with `McpToolRegistry::allToolNames()`, and cap each value at the controller validation limit.
- Append with a stable heading:

```text
<base description>

MSP custom instructions:
<configured text>
```

This keeps the base tool description functional while making the policy appendix obvious to the model and easy to test.

### 2. Append only at `tools/list` time

File:

- `app/Http/Controllers/Api/McpStaffController.php`

Change:

- In `listTools()`, after token filtering and after the final tool name is known, call `McpToolInstructions::appendToDescription($t['name'], $t['description'] ?? '')` when building the MCP `description`.
- Do not mutate registry arrays globally. The settings pages should show the base description and the separately configured appendix text, not a pre-combined description.
- Keep audit unchanged for `tools/list`.

This satisfies the bead's "appends to the description at tools/list time" requirement and avoids leaking MSP guidance into static registries or tests that inspect raw definitions.

### 3. Add a settings save action and UI

Files:

- `routes/web.php`
- `app/Http/Controllers/Web/McpTokensController.php`
- `resources/views/settings/mcp-tokens/index.blade.php`
- `tests/Feature/Settings/McpTokensPageTest.php`

Change:

- Add `PATCH /settings/mcp-tokens/tool-instructions` named `settings.mcp-tokens.tool-instructions`.
- Add `McpTokensController::updateToolInstructions(Request $request)`.
- Validate `tool_instructions` as an array and each value as nullable string, max 5000 chars.
- Persist only keys in `McpToolRegistry::allToolNames()`, trimmed, non-empty.
- Write an audit row such as:
  - `method = token/tool_instructions`
  - `tool_name = mcp_tool_custom_instructions`
  - `arguments = ['tools' => [...], 'total_length' => N]`
  - Do not store instruction bodies in audit.
- Pass `toolInstructions` from `indexView()` into the index view.
- Add a compact "MSP Tool Instructions" settings card on the MCP token index page. Render one textarea per registry tool, grouped with the same live registry groups already used for minting. The card should show the tool name and base description, then the textarea for the MSP appendix.

The token detail page remains per-token grant/directive management. The custom instructions are MSP-global, so the index page is the clearer owner.

### 4. Clean description text

Files:

- `app/Support/McpToolRegistry.php`
- `app/Services/Assistant/AssistantToolDefinitions.php`
- `app/Services/Chet/OperatorBridgeTools.php`
- `app/Services/Chet/TeamsChatReadToolset.php`
- Potentially `app/Services/Triage/TriageToolDefinitions.php` for descriptions that flow through assistant/staff MCP.

Rules:

- Remove caller-specific wording from tool descriptions, especially direct `Chet` references.
- Remove subjective MSP policy like "Always confirm..." from base descriptions.
- Keep concrete server-enforced contracts:
  - held-only behavior for `propose_close` and `send_reply`
  - scanned/sanitized/staged write behavior for wiki tools
  - recipient/category routing performed server-side
  - required/optional argument rules
  - scope limits such as client/global wiki behavior
- Convert behavior advice that is not a server contract into neutral functional wording.

Specific first-pass targets:

- `McpToolRegistry::sendReplyTool()`:
  - Remove "Chet has already written..."
  - Make `ticket_id` description caller-neutral.
- `McpToolRegistry::proposeCloseTool()`:
  - Keep held proposal contract, but avoid AI Technician branding where not needed.
- `McpToolRegistry` wiki write descriptions:
  - Keep safety scan/scope/namespace constraints as server contracts.
  - Remove reviewer-name-specific wording such as "Charlie review".
- `AssistantToolDefinitions::create_ticket()`:
  - Remove "Always confirm with the technician before creating a ticket."
  - Rewrite to "Create a new ticket for this client with subject, description, and optional priority."
- `AssistantToolDefinitions` references to "technician asks" and "current technician":
  - Rewrite as neutral "current staff user", "assigned tickets", or functional context.
- `TeamsChatReadToolset::list_teams_chats()`:
  - Replace "teammate-chet bot" with "configured Teams bot".
- `OperatorBridgeTools::poll_operator_messages()`:
  - Replace "configured Chet Teams chat" with "configured operator Teams chat".

I will not remove every word like "useful" mechanically where it describes diagnostic applicability, but I will remove hard policy, caller identity, and subjective approval/process instructions from base descriptions.

### 5. Replace legacy actor-label fallback

Files:

- `app/Support/McpStaffToken.php`
- `app/Http/Controllers/Api/McpStaffController.php`
- `tests/Feature/Mcp/McpConfigTokenStoreTest.php`
- `tests/Feature/Mcp/McpStaffWhoamiTest.php`

Change:

- Add a single constant, likely `McpStaffToken::LEGACY_ACTOR_LABEL = 'mcp-staff:legacy'`.
- Use it in:
  - `McpStaffToken::actorLabel()` for null/blank labels.
  - `McpStaffController::actorLabel()` fallback when middleware did not attach a token object.
  - `McpStaffController::whoami()` fallback label.
- Keep labeled token attribution unchanged as `mcp-staff:<label>`.

## TDD Sequence

### Task 1: Lock in custom instruction append behavior

Red tests:

- New/updated MCP feature test: configure `mcp_tool_custom_instructions` with `find_staff => "Escalate VIP names."`; `tools/list` for a token scoped to `find_staff` returns the base `find_staff` description plus the `MSP custom instructions:` appendix.
- Assert another listed tool does not receive that appendix.
- Assert raw registry descriptions are not mutated by the helper.

Green:

- Add `McpToolInstructions`.
- Wire `McpStaffController::listTools()` to append descriptions at render time.

### Task 2: Add settings UI persistence

Red tests:

- `tests/Feature/Settings/McpTokensPageTest` verifies the index page renders an MSP Tool Instructions form with a textarea for at least `find_staff` and `send_reply`.
- Posting a valid instruction saves JSON in `Setting`, redirects to index, and writes an audit row with lengths/tool names but not body text.
- Empty values are removed.
- Unknown tool keys are ignored or rejected; preferred implementation is ignored after validation only permits known keys, with an assertion that unknown keys are not stored.

Green:

- Add route, controller action, view card, and `indexView()` data.

### Task 3: Clean base descriptions

Red tests:

- Add focused registry/definition assertions that exposed descriptions do not contain forbidden platform-specific/policy phrases:
  - `Chet`
  - `teammate-chet`
  - `teams-bot`
  - `Always confirm`
  - `Charlie review`
- Assert `create_ticket` still describes creating a ticket and includes expected parameters.
- Assert `send_reply` still describes a held draft and optional body without naming Chet.

Green:

- Rewrite the targeted descriptions in the exposed definition providers.

### Task 4: Replace legacy actor fallback

Red tests:

- Update `McpConfigTokenStoreTest` legacy full-surface token assertion from `teams-bot` to `mcp-staff:legacy`.
- Add/update `McpStaffWhoamiTest` coverage for a legacy/labelless token returning platform-neutral fallback label.
- Add/update audit test proving the fallback actor label is `mcp-staff:legacy`.

Green:

- Add/use the shared fallback constant.

### Task 5: Docs and final verification

Docs:

- Update `docs/INSTALL.md` MCP staff section with:
  - per-tool MSP custom instructions are configured on the MCP Tokens page
  - they append to `tools/list` descriptions
  - token directives remain per-token and visible through `whoami`
  - base descriptions remain platform defaults

Focused tests:

```bash
php artisan test \
  tests/Feature/Mcp/McpToolRegistryTest.php \
  tests/Feature/Mcp/McpConfigTokenStoreTest.php \
  tests/Feature/Mcp/McpStaffWhoamiTest.php \
  tests/Feature/Settings/McpTokensPageTest.php \
  tests/Feature/Chet/ChetSendReplyTest.php \
  tests/Feature/Chet/DataSurfaceToolsTest.php
```

Broader pre-PR:

```bash
php artisan test tests/Feature/Chet tests/Feature/Mcp tests/Feature/Settings/McpTokensPageTest.php
vendor/bin/pint --dirty
vendor/bin/pint --test
```

Operational:

- Restart `soundit-psa-queue.service` and `soundit-psa-dev.service` after code changes.
- Smoke `/settings/mcp-tokens` locally if the dev service is active.
- Run `/soundpsa-review-pr <branch>` before reporting PR-ready.

## Review Focus

- Confirm base descriptions are functional and platform-generic without removing real server-enforced contracts.
- Confirm MSP custom instructions are appended only at runtime `tools/list`, not baked into registry definitions.
- Confirm MSP instruction audit stores only tool names/counts/lengths, never custom instruction bodies.
- Confirm the settings UI is MSP-global per tool, not per-token.
- Confirm `teams-bot` no longer appears on the legacy MCP staff path while labeled tokens remain `mcp-staff:<label>`.
