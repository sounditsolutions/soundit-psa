# Huntress P1 read tools (MCP) — psa-shej design

**Bead:** psa-shej (child of epic psa-ppl9, the Huntress MCP surface). **Unblocks:** psa-oe19 (escalation status-sync bug — needs the escalations read).
**Branch:** `psa-shej-huntress-mcp-reads` off origin/main (cd16807).
**Bar:** TDD, full suite green, pint clean, **HOLD MERGE for Mayor review**. Ships dormant (read-only account key).

## Scope (exactly the bead's P1 reads)
Six read tools on the staff MCP surface (`/api/mcp/staff`), prefix `huntress_`, all **general** (no PSA `client_id` boundary scope):

| Tool | Purpose |
|------|---------|
| `huntress_list_incident_reports` | List incident reports (filters: status, severity, platform, organization_id, agent_id, indicator_type) |
| `huntress_get_incident_report` | Get one incident report by id (malware/incident detail) |
| `huntress_list_escalations` | List SOC escalations (filters: status, severity, subtype, organization_id) — incl. integration-health "Failed to Deliver" |
| `huntress_get_escalation` | Get one escalation by id (with entities) |
| `huntress_list_organizations` | List Huntress orgs, each annotated with its mapped PSA client — the org↔client mapping helper |
| `huntress_get_organization` | Get one Huntress org by id, annotated with mapped PSA client |

## API facts (authoritative — api.huntress.io/v1/swagger_doc.json)
- Base `https://api.huntress.io/v1/`, HTTP Basic (`huntress_api_key:huntress_api_secret`). `HuntressClient` already wraps this.
- Token pagination: response `pagination.{next_page_token, next_page_url}`; request `limit` (max 500) + `page_token` + `sort_field`/`sort_direction`.
- List envelopes wrap under the plural key: `{incident_reports:[…],pagination}`, `{escalations:[…],pagination}`, `{organizations:[…],pagination}`.
- Get-by-id envelopes: `{incident_report:{…}}`, `{organization:{…}}` — **but `GET /escalations/{id}` returns the object with NO wrapper key** (gotcha).
- Incident `status ∈ {sent, closed, dismissed, auto_remediating, deleting, partner_dismissed}`, `severity ∈ {low, high, critical}`, `platform ∈ {windows, darwin, microsoft_365, google, linux, email_security, other}`.

## Data-boundary decision (cross-MSP shared account) — **flag for Mayor**
The Huntress account can be **shared across MSPs** (documented: Charlie + Leif IT). The existing codebase posture:
- **Org metadata** is read account-wide (the org-mapping UI shows every org so the operator can map theirs).
- **Incident/security data** is read **mapped-orgs-only** (`HuntressIncidentReconcileService` iterates `clients.huntress_organization_id` and queries per mapped org — never a bulk account-wide body read).

This PR **mirrors that posture** (the safe, intent-aligned default — "pull OUR clients' incidents in"; account-wide security reads would be the deviation needing sign-off):
- `huntress_list_organizations` / `get_organization`: **account-wide**, annotated with the mapped PSA client (or `null`). Serves the mapping helper and lets Chet discover unmapped orgs.
- `huntress_list_incident_reports` / `get_incident_report`: **mapped-orgs-only.** If `organization_id` is supplied it must map to a PSA client; if omitted, an account-wide page is fetched and client-side filtered to mapped orgs. `get_incident_report` denies (not-found) a report whose `organization_id` is unmapped.
- `huntress_list_escalations` / `get_escalation`: escalations carry an `organizations[]` array. Return only escalations that touch ≥1 mapped org **or** have no org association (account-level integration-health — what psa-oe19 needs). `get_escalation` denies an escalation touching only unmapped orgs.

If the Mayor wants account-wide security reads instead, it is a one-line loosening (drop the mapped filter).

## Redaction (per-sink, on everything fed to AI/audit)
- Free-text fields (incident `subject`/`body`/`summary`, escalation `subject`, org `name`) → `ChetDataSurfaceTextSanitizer::sanitize(…)` (normalize-untrusted → `WikiRedactor` → truncate → `PromptFence` fence).
- Raw API sub-objects (`indicator_counts`, `remediations`, `entities`, `report_recipients`) → mapped to explicit safe shapes / counts; never spilled verbatim.
- MCP audit already redacts via `ActionRedactor::redactParams`; read args here are ids/enums/limits (low-sensitivity), so default audit path suffices.

## Throttle / 60 rpm
Interpreted as **429 backoff, like other integrations** (CIPP does 401/429 exponential backoff). Add bounded 429 retry (honor `Retry-After`) to `HuntressClient::request()`. Each MCP read fetches a **single bounded page** (default limit 25, max 100 — below the API's 500) and returns the `next_page_token` cursor for the caller to page — so no runaway pagination burns the budget.

## Files
- **`app/Services/Huntress/HuntressClient.php`** — add `getEscalation(int)` (no-wrapper), `getEscalations(array)` single-page + `pageList()` helper; add 429 backoff to `request()`. (getIncidentReport/getIncidentReports/getOrganization(s) already exist — do not change their semantics.)
- **`app/Services/Huntress/HuntressReadOnlyToolset.php`** — NEW. The triad toolset (definitions/handles/requiresClient/execute + per-tool methods + mappers + mapped-org resolution + sanitize). Modeled on `TacticalReadOnlyToolset`.
- **`app/Services/Chet/ChetDataSurfaceTools.php`** — merge Huntress into `generalTools()` (gated `HuntressConfig::isConfigured()`), `registryIntegrationTools()` (ungated), `handles()`, `requiresClient()`.
- **`app/Services/Chet/ChetDataSurfaceToolExecutor.php`** — route `HuntressReadOnlyToolset::handles()`.
- **`app/Support/McpToolRegistry.php`** — map `huntress_` in `integrationForToolName()`; add `huntress` card to `integrationMeta()`.

## Test plan (TDD)
1. `HuntressReadOnlyToolsetTest` — each tool: happy path (Mockery `HuntressClient`), envelope unwrap (esp. escalation no-wrapper), mapped-scope enforcement (unmapped org denied / filtered), org-mapping annotation, redaction of a poisoned free-text field, bounded limit, cursor passthrough, dormant when unconfigured.
2. `McpToolRegistryTest` additions — the 6 tools are in the `integration` group + `allToolNames()`; `huntress_` prefix maps to the `huntress` integration; no group overlap.
3. MCP `tools/call` integration — grant-gated (scoped token sees them, legacy full-surface token does not); `tools/list` omits them when Huntress unconfigured (dormant).
