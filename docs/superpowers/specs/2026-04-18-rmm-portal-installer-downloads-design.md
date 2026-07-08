# RMM Agent Installer Downloads in Client Portal — Design Spec

**Date:** 2026-04-18
**Status:** Approved

## Problem

When a client gets a new computer, the end user has no self-service way to enroll it into the MSP's RMM. They have to open a ticket, wait for a technician, get the installer emailed to them, and run it. This creates friction for onboarding and burns tech time on a trivial task.

The PSA already knows which RMM each client uses (via `ninja_org_id`, `level_group_id`, `tactical_site_id`). It can generate a branded, client-specific install page that an end user visits, downloads the agent, runs it, and their new computer appears in the MSP's RMM.

## Primary Users

Non-technical end users at the client. They want to click a button, run the installer, and be done. All technical complexity must be hidden. The UI should be inviting, branded, and explain what's about to happen.

## Solution Overview

Three pieces:

1. **Per-client install token** — random string stored on the client record. URL format: `/setup/{token}`. Persistent but rotatable.
2. **Public landing page** — no login required. Branded with MSP company name/logo. Auto-detects OS. Shows download button(s). Includes MSP support contact info.
3. **Portal-authenticated entry point** — Dashboard card for logged-in portal users that links to their own setup URL. Same backend.

## Data Model

### New columns on `clients` table

- `portal_install_token` — string, nullable, unique. Random 32-char token used in the install URL.
- `portal_primary_rmm` — string, nullable. Enum values: `ninja`, `level`, `tactical`. When multiple RMMs are mapped, this selects which one the portal install link uses.

Both columns nullable. Feature is unavailable for a client until the MSP clicks "Generate install link" in the admin UI, which populates `portal_install_token` and sets `portal_primary_rmm` (auto-picked if only one RMM is mapped).

### No new tables

Install tokens are lightweight secrets on the client record. No audit log of token generation needed in v1.

## Components

### New files

| File | Responsibility |
|------|---------------|
| `app/Http/Controllers/Portal/PortalInstallController.php` | Public routes: `show(token)` landing page, `download(token)` redirect to RMM installer |
| `app/Services/Portal/PortalInstallService.php` | Resolves token → client → primary RMM → `InstallerPackage` with per-platform `InstallerInfo` |
| `app/Services/Portal/InstallerPackage.php` | Value object: `client_name`, `rmm_label`, `platforms[platform => InstallerInfo]`, `msp_name`, `msp_logo_url`, `support_email`, `support_phone` |
| `app/Services/Portal/InstallerInfo.php` | Value object: `download_url`, `registration_key` (nullable), `install_script` (nullable), `instructions` (nullable) |
| `resources/views/portal/install/show.blade.php` | Standalone branded landing page (NOT extending `portal.layouts.app`) |
| `resources/views/portal/install/invalid.blade.php` | Friendly error page for invalid tokens, missing RMM, or RMM API failure |
| `database/migrations/YYYY_MM_DD_add_portal_install_to_clients.php` | Migration for the two new columns |

### Modified files

| File | Change |
|------|--------|
| `routes/portal.php` | Add public install routes (outside auth middleware) |
| `app/Services/Level/LevelClient.php` | Add `getInstallerInfo(string $groupId, string $platform): ?InstallerInfo` |
| `app/Services/Ninja/NinjaClient.php` | Add `getInstallerInfo(int $orgId, string $platform): ?InstallerInfo` |
| `app/Services/Tactical/TacticalClient.php` | Add `getInstallerInfo(string $siteId, string $platform): ?InstallerInfo` |
| `app/Models/Client.php` | Add new columns to `$fillable`; add `availableRmms(): array` helper returning mapped RMMs |
| `app/Http/Controllers/Web/ClientController.php` (or equivalent) | Add `generateInstallLink`, `rotateInstallLink`, `updatePortalPrimaryRmm` actions |
| `resources/views/clients/show.blade.php` | Add "Self-Service Install Link" card with URL, copy/rotate buttons, primary-RMM dropdown |
| `resources/views/portal/dashboard.blade.php` | Add "Set up a new computer" card linking to the install URL |

## Component Interfaces

### `PortalInstallService::buildPackage(Client $client): InstallerPackage|null`

Returns `null` if:
- No `portal_primary_rmm` and no RMM mapped
- Primary RMM is set but its mapping field is empty
- RMM's `getInstallerInfo()` returns `null` for all platforms

Otherwise returns an `InstallerPackage` with:
- `client_name`: the client's display name
- `rmm_label`: human-friendly RMM name (e.g., "NinjaRMM Agent")
- `platforms`: array keyed by platform (`windows`, `mac`, `linux`), values are `InstallerInfo` objects. Missing keys mean the RMM doesn't support that platform.
- `msp_name`, `msp_logo_url`: from `PortalConfig`
- `support_email`, `support_phone`: from `PortalConfig`

### Per-RMM `getInstallerInfo($orgIdentifier, string $platform): ?InstallerInfo`

Each RMM client's method takes its org identifier and a platform slug. Returns an `InstallerInfo` value object with:

- `download_url` (required) — the URL to download the installer or install script
- `registration_key` (optional) — a key the user must enter during installation. Only set when the RMM's installer requires it (e.g., Level).
- `install_script` (optional) — a copy-pasteable PowerShell/bash one-liner that handles download + key in one step. When present, the landing page prefers this over `download_url`.
- `instructions` (optional) — short text shown on the landing page specific to this RMM's install flow.

Returns `null` if:
- The RMM API doesn't expose an installer endpoint (may need to be discovered during implementation)
- The platform isn't supported by that RMM
- The API call fails (log a warning, don't throw)

**RMM-specific findings:**

- **Level** (confirmed 2026-04-18): Single generic installer binary for all customers + a per-client registration key pullable via API. Groups are passed via key suffix. Level method should return `download_url` (generic) + `registration_key` (per-group). Preferred UX: generate an `install_script` (PowerShell one-liner like Servosity's that downloads and runs with the key embedded) so the user never has to type the key manually. If that's not feasible, fall back to showing the key alongside the download button with copy-to-clipboard.
- **NinjaRMM**: Research item. Likely supports org-scoped installer URLs directly.
- **Tactical RMM**: Research item. Likely needs deployment token generation via API.

If an RMM doesn't support this via API, that RMM falls through to the invalid landing page ("Device enrollment isn't configured — contact your MSP"). The feature ships for whichever RMMs do work.

## Data Flow

### End user visits `/setup/{token}`

1. `PortalInstallController::show(string $token)`
2. Looks up `Client::where('portal_install_token', $token)->first()`. 404 (invalid.blade.php) if not found.
3. Calls `PortalInstallService::buildPackage($client)`. If null, renders invalid.blade.php with contextual error.
4. Renders `portal/install/show.blade.php` with the `InstallerPackage`.
5. Page includes JS that detects OS (`navigator.platform`) and highlights the matching download button. Other platforms available via an "Other platforms" disclosure.

### End user clicks download button

The button behavior depends on the `InstallerInfo` for the selected platform:

1. **If `install_script` is set** (preferred for Level): the landing page shows the script in a copy-to-clipboard block with instructions like "Open PowerShell as Administrator and paste this." The "download" button copies the script to clipboard. No file download.
2. **If only `download_url` is set** (Ninja/Tactical if they return direct URLs): button href is `/setup/{token}/download?platform={platform}` which 302-redirects to the RMM installer URL.
3. **If `download_url` + `registration_key` are both set but no script**: landing page shows the download button AND the key prominently with copy-to-clipboard. Instructions: "Download the installer, then when prompted enter this key: XYZ."

Controller method `download(string $token, Request $request)` only handles case 2. Cases 1 and 3 render their content directly in the landing page.

If platform invalid or URL unavailable: redirect back to the landing page with a flash message.

### Query parameter: `?download=1` on landing page URL

If present on the initial `show()` request, server-side auto-detection picks the best platform from the `User-Agent` header and immediately redirects to the RMM installer URL, skipping the landing page. For tech-savvy users who want the direct download.

### MSP rotates the token

Admin UI "Rotate link" button → controller action → generates new token via `Str::random(32)` → saves. Old URL 404s immediately. No grace period (keep it simple).

### Portal-authenticated dashboard card

1. On `portal.dashboard`, check if `auth()->user()` (a `Person`) has a client with `portal_install_token` set.
2. If yes: render a card "Set up a new computer" with link to `/setup/{token}`.
3. If no: render nothing. The MSP hasn't enabled this for this client, so we stay silent.

## Admin UI (MSP-facing)

On the client detail page (`resources/views/clients/show.blade.php`), add a card:

**Title:** "Self-Service Install Link"

**States:**

*Not configured* (no token):
- Text: "Generate a shareable link that lets end users install the RMM agent on new devices without contacting support."
- Button: "Generate install link"

*Configured:*
- URL displayed in a read-only input with a copy-to-clipboard icon
- "Primary RMM" dropdown (only shown if client has multiple RMMs mapped) — options: NinjaRMM / Level / Tactical (whichever are mapped)
- "Rotate link" button (confirmation required)
- "Disable" button (clears the token, hides the portal card)

**Hidden entirely** if no RMM is mapped to the client.

## Error Handling

All errors render the same `portal/install/invalid.blade.php` layout with a contextual message and MSP contact info:

| Condition | Message |
|-----------|---------|
| Token not found or null | "This setup link is not valid. Contact your IT support team." |
| No RMM mapped | "Device enrollment isn't configured for your organization. Contact {MSP name} at {support}." |
| `portal_primary_rmm` set but mapping field empty | Same as "No RMM mapped" |
| All platforms return null from RMM API | "Unable to retrieve installer at this time. Please contact {MSP name} at {support}." |
| RMM API throws exception | Log warning with context, render "Unable to retrieve installer..." |

All error responses return HTTP 200 with the invalid page — not 4xx — so share-linked users don't see a bare "Not Found" browser page.

## Security Considerations

- Tokens are 32-character `Str::random()` — ~192 bits of entropy, not guessable.
- URLs are not indexed (add `noindex,nofollow` meta tag to landing page).
- Download URLs served from the RMM are typically already client-scoped and long-lived. If the RMM exposes short-lived signed URLs, we re-fetch on each download to keep them fresh.
- No PII beyond the client name is exposed on the public page.
- Rate limiting: apply Laravel's default throttle to `/setup/*` routes (60/min per IP is fine).
- Logging: log each token hit with client ID, platform requested, and referrer for security audits.

## Platform Detection

Client-side JS (on the landing page):
- `navigator.platform` or `navigator.userAgentData.platform` → primary button
- Fallback: show all available platforms equally

Server-side (for `?download=1`):
- `Request::userAgent()` string matching: `Win` → windows, `Mac` → mac, `Linux` → linux
- Unknown → render landing page instead of redirect

## Research Items (Implementation Phase)

1. **Level (confirmed):** Registration key pullable via API. Exact endpoint still needs to be located in Level's API docs — look for deployment/registration/install key endpoints. Confirm whether Level offers a PowerShell one-liner format that embeds the key, or whether the user must enter the key manually.
2. **NinjaRMM:** Does the API expose installer URLs per org? Check OpenAPI spec at `~/repos/HaloClaude/docs/NinjaRMM/NinjaRMM-API-v2.json` for `/v2/organizations/{id}/installer` or similar. Fallback: if Ninja doesn't support this via API, the feature is unavailable for Ninja-mapped clients.
3. **Tactical RMM:** Does the API expose deployment tokens or installer URLs? TRMM has per-site agent deployment — check for endpoints like `/agents/installer/` or deployment config endpoints. Fallback same as Ninja.

If any RMM doesn't support this via API, that RMM falls through to the "not configured" page. We ship for whichever RMMs work. The implementation plan should gate each RMM behind its research finding and be structured so Level (the motivating case) can ship even if Ninja/Tactical turn out to be blockers.

## Out of Scope (v1)

- Multi-step wizard (e.g., "enter your email to track the install")
- Post-install verification (was the device actually enrolled?)
- SMS/email delivery of setup links from the PSA (MSP copies the URL manually)
- Rate limit protection beyond Laravel's default
- Audit log of token generations/rotations
- Per-user tokens within a client (all users share the same client-level token)

## Implementation Phases

Single plan, implemented in this order:

1. Migration + Client model changes
2. `InstallerPackage` value object and `PortalInstallService` skeleton
3. Public routes and controller
4. Landing page view (branded)
5. Invalid/error page
6. Per-RMM `getInstallerInfo()` implementations (one per RMM, each gated by research outcome)
7. Admin UI card on client detail page
8. Portal dashboard card for authenticated users
9. Deploy and test with a real client
