# Automated Device-to-User Assignment

**Date:** 2026-03-19
**Bead:** psa-bk4
**Status:** Approved

## Problem

Devices (assets) have no persistent link to the people who use them. The `last_user` field from NinjaRMM captures who last logged in, but this is a raw string with no FK to the people table. Person-to-device matching is computed on the fly in triage and email parsing, which is slow and inconsistent. Staff have no way to see or manage device-to-user assignments.

## Solution

A many-to-many `asset_person` pivot table linking assets to people, with a `is_primary` flag that only staff can set. A scheduled command auto-assigns users based on `last_user` data using tiered confidence matching. The asset detail page gets a Users card for viewing and managing assignments, and the asset index gets an "unassigned" filter.

## Data Model

### New Table: `asset_person`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `asset_id` | FK → assets | |
| `person_id` | FK → people | |
| `is_primary` | boolean, default false | Only set manually by staff |
| `assignment_source` | string(10) | `auto` or `manual` |
| `last_seen_at` | datetime, nullable | Updated by auto-assignment when user seen on device |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

- Unique constraint on `(asset_id, person_id)`
- `is_primary` uniqueness per asset enforced in application code (setting one clears others)
- Cascade delete on both FKs

### New Enum: `AssetAssignmentSource`

- `Auto` (`'auto'`) — assigned by the scheduled command
- `Manual` (`'manual'`) — assigned by staff

### Model Relationships

**Asset:**
- `users(): BelongsToMany(Person)` via `asset_person` with pivot `is_primary`, `assignment_source`, `last_seen_at`
- `primaryUser(): ?Person` — accessor that returns the `is_primary = true` user, or null

**Person:**
- `assets(): BelongsToMany(Asset)` via `asset_person` with pivot data

## Auto-Assignment Command

**Command:** `php artisan assets:assign-users`

**Schedule:** Daily, after NinjaRMM device sync (e.g., 06:30)

**Flags:**
- `--client={id}` — scope to a single client
- `--dry-run` — log what would be assigned without writing

**Algorithm per asset with `last_user` set:**

1. Parse `last_user` to extract username:
   - `DOMAIN\username` → `username`
   - `username@domain.com` → keep as-is (UPN format)
   - Plain `username` → keep as-is

2. **Tier 1 — Exact UPN/email match** (high confidence, auto-assign):
   - If `last_user` contains `@`: match against `Person.cipp_upn` or `Person.email` (case-insensitive, scoped to `asset.client_id`)
   - If exactly one match → assign

3. **Tier 2 — Name match** (medium confidence, auto-assign):
   - Extract username portion (before `@` or after `\`)
   - Match against `Person.first_name` (case-insensitive, scoped to client)
   - Also try `CONCAT(first_name, '.', last_name)` and `CONCAT(first_initial, last_name)` patterns (e.g., `jsmith` → `John Smith`)
   - If exactly one match → assign
   - If multiple matches → skip (ambiguous)

4. **Upsert into `asset_person`:**
   - If person already linked to this asset: update `last_seen_at` only
   - If new link: insert with `assignment_source = 'auto'`, `is_primary = false`, `last_seen_at = now()`
   - Never modify `is_primary` — only staff can set this
   - Never remove `assignment_source = 'manual'` links

**Logging:** `[AssetUserAssignment]` prefix. Summary stats: total assets processed, matched, already linked, no match, ambiguous.

## UI Changes

### Asset Detail Page (`assets/show.blade.php`)

**New "Users" card** in the Overview tab (or sidebar):
- Table: Person name (badge), Primary (star icon if primary), Source (auto/manual badge), Last Seen (diffForHumans)
- Actions per row:
  - "Set as Primary" button (only if not already primary) — sets this user as primary, clears `is_primary` on all others for this asset
  - "Remove" button — deletes the `asset_person` row (with confirmation)
- "Add User" form at bottom: person dropdown (scoped to client) + "Add" button, creates `asset_person` with `source = manual`
- If no users linked: "No users assigned" message

### Asset Index (`assets/_list.blade.php`)

- New filter dropdown: "User Assignment" — All (default) / Unassigned / Assigned
- Add "Primary User" column (or show primary user name inline with the device name)
- When prefiltered columns are configured, `primary_user` should be a valid column key

### Person Detail Page (`people/show.blade.php`)

- New "Devices" section in Overview tab showing assets linked to this person via `asset_person`
- Simple table: Device name, Type, Primary (badge), Last Seen

## Scope Boundaries

- Auto-assignment is additive only — never removes links
- `is_primary` is never set or changed by automation
- Contract assignments (`contract_asset`, `contract_person`) are unrelated and unchanged
- The `last_user` field on the asset continues to be updated by NinjaRMM sync as before
- `Asset::resolveLastUserPerson()` helper can optionally be updated to check `asset_person` first before falling back to string matching

## Files to Create

| File | Purpose |
|------|---------|
| `database/migrations/XXXX_create_asset_person_table.php` | Pivot table |
| `app/Enums/AssetAssignmentSource.php` | Auto / Manual enum |
| `app/Services/AssetUserAssignmentService.php` | Matching logic and upsert |
| `app/Console/Commands/AssignAssetUsers.php` | Artisan command |

## Files to Modify

| File | Change |
|------|--------|
| `app/Models/Asset.php` | Add `users()` relationship and `primaryUser` accessor |
| `app/Models/Person.php` | Add `assets()` relationship |
| `app/Http/Controllers/Web/AssetController.php` | Add user management actions (add, remove, set-primary) |
| `resources/views/assets/show.blade.php` | Add Users card |
| `resources/views/assets/_list.blade.php` | Add assignment filter and primary user column |
| `resources/views/people/show.blade.php` | Add Devices section |
| `routes/web.php` | Add asset user management routes |
| `routes/console.php` | Schedule `assets:assign-users` daily |
