---
type: plan
title: "MCP Token / Key Management UI — v1 Implementation Plan"
tags: [soundit-dev, psa, mcp, plan, review-me]
created: 2026-07-01
status: awaiting-review
related:
  - "[[2026-07-01-psa-ai-control-surface-scoping]]"
  - "[[soundit-dev]]"
---

# MCP Token / Key Management UI — v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use the `superpowers:test-driven-development` skill for
> every task in this plan. Each task is a strict red→green→commit loop: write the failing test first,
> run it and SEE it fail for the right reason, write the minimal implementation, run it and SEE it
> pass, then commit. Do not batch tasks. Do not write implementation before its test. If a task's test
> passes on the first run, stop and figure out why — the test is probably not exercising the change.

Tracking bead: **psa-fn58**. Subsystem 1 of the "AI control surface" (scoping brief:
[[2026-07-01-psa-ai-control-surface-scoping]]). This plan covers **only** the MCP token/key
management UI. The alert-destination subsystem is a separate, later plan.

---

## Goal

Replace the SSH-only `mcp:rotate-staff-token` CLI as the *primary* way Sound IT mints and scopes agent
MCP tokens with a first-class Settings page. An operator can, from the browser:

1. **List** every scoped staff MCP token (label, non-secret prefix, granted tools, created / last-used,
   active vs revoked).
2. **Mint** a new token: type a label, tick the tools it may call (checkboxes generated live from the
   tool registries), submit, and see the plaintext secret exactly once (GitHub-PAT model).
3. **Revoke** a token so it stops authenticating immediately.

Storage moves from the encrypted JSON-blob `Setting` (`mcp_staff_scoped_tokens`) to a first-class
`mcp_tokens` table (hash-only at rest, plus display metadata), while the existing MCP request path
(`McpConfig::resolveStaffToken` → `VerifyMcpStaffToken` middleware → `McpStaffController`) and the
`mcp:rotate-staff-token` CLI keep working unchanged.

## Architecture

The MCP boundary is untouched at the wire level. We refactor **where scoped tokens live** and add a
thin Settings CRUD on top.

```
                        ┌─────────────────────────────────────────────┐
Browser (auth staff) ──►│ McpTokensController (Web, auth group)        │
  /settings/mcp-tokens  │  index  → list + mint form + one-time secret │
                        │  store  → McpConfig::rotateStaffToken(...)    │──┐ mint plaintext (once)
                        │  revoke → mcp_tokens.revoked_at = now()       │  │
                        └───────────────┬─────────────────────────────┘  │
                                        │ groups()/allToolNames()          │ audit rows
                        ┌───────────────▼─────────┐   ┌──────────────────▼────────┐
                        │ McpToolRegistry (live)  │   │ McpAuditLog (existing)     │
                        │ AssistantToolDefinitions│   │ token/mint|rotate|revoke   │
                        │ + OperatorBridgeTools   │   └────────────────────────────┘
                        │ + TriageToolDefinitions │
                        └─────────────────────────┘
                                        ▲ read/write (hash-only)
   MCP request path (UNCHANGED):        │
   POST /api/mcp/staff                  │
     → VerifyMcpStaffToken middleware ──┤ McpConfig::resolveStaffToken($bearer)
         McpConfig::isStaffEnabled()    │   1) legacy reversible `mcp_staff_token` Setting (fallback)
         → McpStaffController           │   2) mcp_tokens: sha256 hash lookup, stamp last_used_at
             toolAllowed() via McpStaffToken (allowedTools/label) — signature unchanged
```

Key point: `McpConfig::resolveStaffToken()` and `McpConfig::rotateStaffToken()` keep their **exact
signatures and return types**; only their storage backend changes. `McpStaffToken` (the value object
the controller consumes) is not modified at all. The CLI calls the same `McpConfig` methods.

## Tech Stack

- **PHP 8.3 / Laravel 11**, Eloquent, Blade + Bootstrap 5 (Bootstrap Icons), matching the existing
  `settings/*` pages.
- **PHPUnit 11** (class-based, `extends Tests\TestCase`, `use RefreshDatabase`, `test_snake_case`
  methods). Test DB = SQLite `:memory:` (per `phpunit.xml`) — all SQL must be SQLite-safe.
- Token secret = `psa-mcp-` + `Str::random(48)`; at rest only `sha256` hex + a non-secret prefix.
- Encrypted `Setting` via `Crypt` (for the retained legacy single-token fallback only).

## Global Constraints

**Locked decisions (Charlie, 2026-07-01) — build to these:**
- **Per-install, single-tenant.** NO `tenant_id`, no multi-tenancy machinery, no reserved scope column.
- **NO admin gate / RBAC in v1.** The page sits behind the EXISTING `auth` middleware group in
  `routes/web.php`, exactly like `settings.general` / `settings.integrations`. Do **not** add
  `is_admin`, gates, or policies — RBAC is a deferred later lift, explicitly out of scope.
- **GitHub-PAT secret model.** One-time plaintext display at mint; **hash-only (sha256) at rest**;
  store a non-secret display prefix + label + tools + created_at + last_used_at for the list.
- **First-class `mcp_tokens` table** — promote scoped tokens out of the JSON-in-`Setting` blob; fold
  existing `mcp_staff_scoped_tokens` records in.
- **Tool-scoping = checkbox set generated from the LIVE registries** (`AssistantToolDefinitions` +
  `OperatorBridgeTools` + `TriageToolDefinitions`) so the UI can't drift from what the boundary allows.
- **Keep the CLI** (`mcp:rotate-staff-token`) working as a break-glass path; UI and CLI coexist.

**Engineering invariants:**
- **Keep `McpConfig` + `McpStaffController` + the middleware green.** `resolveStaffToken(string):
  ?McpStaffToken` and `rotateStaffToken(?array,?string): string` keep their signatures. The existing
  MCP feature tests (`tests/Feature/Chet/*`, `tests/Feature/Agent/McpStaffProposeCloseTest`,
  `tests/Feature/Teams/TeamsBotConfigTest`) must pass **unchanged** — they are the regression guard.
- **No new RBAC in v1** (restated because it is the single biggest scope temptation).
- **Least privilege:** the UI always mints an *allowlisted* token (≥1 tool). Minting a full-surface
  (`tools = null`) token is NOT offered in the UI in v1; that remains a CLI-only break-glass action.
- **Delivery:** executor is the **`developer`** agent on codex; **Mayor reviews + merges**. This ships
  as a **LIVE, additive** Settings page behind existing `auth` (NOT dormant / not flag-gated). It adds
  no behavior to already-live tokens beyond stamping `last_used_at`.

**Out of v1 (mention as deferred):** admin gate / RBAC; alert-destination management; in-UI audit
log viewer; HMAC webhook signing; multi-tenancy; a UI path to mint a full-surface token.

## File Structure

| File | Status | Purpose |
| --- | --- | --- |
| `database/migrations/2026_07_01_000001_create_mcp_tokens_table.php` | NEW | `mcp_tokens` schema + fold legacy blob |
| `app/Models/McpToken.php` | NEW | Eloquent model; `active()` scope; `importLegacyBlob()` |
| `app/Support/McpConfig.php` | MODIFIED | table-backed `resolve`/`rotate`/`hasScopedStaffTokenLabel`/`isStaffEnabled`; `last_used_at`; keep legacy fallback |
| `app/Support/McpToolRegistry.php` | NEW | grouped registry-driven tool catalog + `allToolNames()` |
| `app/Http/Controllers/Web/McpTokensController.php` | NEW | `index` / `store` / `revoke` + audit hooks |
| `routes/web.php` | MODIFIED | 3 routes in the `auth` group + import |
| `resources/views/components/sidebar.blade.php` | MODIFIED | nav link in the Settings group |
| `resources/views/settings/mcp-tokens/index.blade.php` | NEW | list + mint (one-time secret) + checkboxes + revoke |
| `tests/Feature/Mcp/McpTokenModelTest.php` | NEW | model casts/scope + legacy fold |
| `tests/Feature/Mcp/McpConfigTokenStoreTest.php` | NEW | resolve/rotate/revoke/last_used/legacy fallback |
| `tests/Feature/Mcp/McpToolRegistryTest.php` | NEW | groups + `allToolNames` |
| `tests/Feature/Settings/McpTokensPageTest.php` | NEW | auth gate, index, store (one-time + hash-at-rest), revoke, audit |

---

## Task 1 — `mcp_tokens` migration + `McpToken` model (with legacy fold)

**Goal.** Create the first-class table and its Eloquent model, and provide an idempotent importer that
folds the existing encrypted `mcp_staff_scoped_tokens` JSON blob into rows. The migration delegates the
fold to the model so the fold is unit-testable.

**Files:** `database/migrations/2026_07_01_000001_create_mcp_tokens_table.php` (NEW),
`app/Models/McpToken.php` (NEW), `tests/Feature/Mcp/McpTokenModelTest.php` (NEW).

**Interfaces**
- Consumes: `App\Models\Setting::getEncrypted('mcp_staff_scoped_tokens')` → JSON array of
  `{label,hash,tools[],created_at}` (the current blob shape).
- Produces:
  - Table `mcp_tokens(id, label UNIQUE, token_hash, token_prefix NULL, tools JSON NULL, last_used_at
    NULL, revoked_at NULL, created_at, updated_at)`.
  - `McpToken` model: `casts` `tools=>array`, `last_used_at`/`revoked_at`=>`datetime`;
    `scopeActive(Builder): void` (`whereNull('revoked_at')`); `isRevoked(): bool`.
  - `McpToken::importLegacyBlob(): int` — upserts by label (idempotent), returns count imported.

### Step 1 — Write the failing test

`tests/Feature/Mcp/McpTokenModelTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\McpToken;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class McpTokenModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_casts_tools_to_array_and_dates_to_carbon(): void
    {
        $t = McpToken::create([
            'label' => 'chet',
            'token_hash' => hash('sha256', 'psa-mcp-secret'),
            'token_prefix' => 'psa-mcp-abcd…',
            'tools' => ['find_staff', 'get_staff'],
            'last_used_at' => now(),
        ]);

        $fresh = $t->fresh();
        $this->assertSame(['find_staff', 'get_staff'], $fresh->tools);
        $this->assertInstanceOf(Carbon::class, $fresh->last_used_at);
        $this->assertNull($fresh->revoked_at);
        $this->assertFalse($fresh->isRevoked());
    }

    public function test_active_scope_excludes_revoked_rows(): void
    {
        McpToken::create(['label' => 'live', 'token_hash' => 'h1', 'tools' => ['a']]);
        McpToken::create(['label' => 'dead', 'token_hash' => 'h2', 'tools' => ['a'], 'revoked_at' => now()]);

        $this->assertSame(['live'], McpToken::query()->active()->pluck('label')->all());
    }

    public function test_import_legacy_blob_folds_encrypted_setting_records(): void
    {
        Setting::setEncrypted('mcp_staff_scoped_tokens', json_encode([
            ['label' => 'chet', 'hash' => 'hash-chet', 'tools' => ['find_staff', 'get_staff'], 'created_at' => '2026-06-30T10:00:00+00:00'],
            ['label' => 'office-teams-pack', 'hash' => 'hash-pack', 'tools' => ['poll_operator_messages'], 'created_at' => '2026-06-30T11:00:00+00:00'],
        ]));

        $count = McpToken::importLegacyBlob();

        $this->assertSame(2, $count);
        $chet = McpToken::where('label', 'chet')->firstOrFail();
        $this->assertSame('hash-chet', $chet->token_hash);
        $this->assertSame(['find_staff', 'get_staff'], $chet->tools);
        $this->assertNull($chet->token_prefix, 'prefix was never stored historically → null');
        $this->assertTrue($chet->created_at->equalTo(Carbon::parse('2026-06-30T10:00:00+00:00')));
    }

    public function test_import_legacy_blob_is_idempotent(): void
    {
        Setting::setEncrypted('mcp_staff_scoped_tokens', json_encode([
            ['label' => 'chet', 'hash' => 'hash-chet', 'tools' => ['find_staff']],
        ]));

        McpToken::importLegacyBlob();
        McpToken::importLegacyBlob();

        $this->assertSame(1, McpToken::where('label', 'chet')->count());
    }

    public function test_import_legacy_blob_no_setting_is_noop(): void
    {
        $this->assertSame(0, McpToken::importLegacyBlob());
        $this->assertSame(0, McpToken::count());
    }
}
```

### Step 2 — Run it, watch it fail

`php artisan test tests/Feature/Mcp/McpTokenModelTest.php` → fails: `Class "App\Models\McpToken" not
found` / no `mcp_tokens` table. Correct failure.

### Step 3 — Implement

`database/migrations/2026_07_01_000001_create_mcp_tokens_table.php`:

```php
<?php

use App\Models\McpToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100)->unique();
            $table->string('token_hash', 64);              // sha256 hex; only the hash is ever stored
            $table->string('token_prefix', 32)->nullable(); // non-secret display hint, e.g. "psa-mcp-abcd…"
            $table->json('tools')->nullable();             // null = full-surface (legacy); array = allowlist
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('token_hash');
            $table->index('revoked_at');
        });

        // Fold the existing encrypted JSON-blob scoped tokens into the table. The blob is left in
        // place (vestigial) as a rollback safety copy; a later cleanup migration can remove it.
        McpToken::importLegacyBlob();
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tokens');
    }
};
```

`app/Models/McpToken.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A minted staff MCP bearer token. Only the sha256 hash is stored (GitHub-PAT model); the plaintext
 * is shown once at mint. `tools` null = full-surface (legacy semantics); an array = the explicit
 * allowlist the /api/mcp/staff boundary enforces via McpStaffToken::allows().
 */
class McpToken extends Model
{
    protected $fillable = [
        'label',
        'token_hash',
        'token_prefix',
        'tools',
        'last_used_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @param  Builder<McpToken>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Idempotently fold the legacy encrypted `mcp_staff_scoped_tokens` JSON blob
     * ({label,hash,tools[],created_at}) into first-class rows. The plaintext prefix was never stored
     * historically, so `token_prefix` for folded rows is null. Returns the number of records imported.
     */
    public static function importLegacyBlob(): int
    {
        try {
            $raw = Setting::getEncrypted('mcp_staff_scoped_tokens');
        } catch (\Throwable) {
            return 0;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return 0;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return 0;
        }

        $imported = 0;
        foreach ($decoded as $record) {
            if (! is_array($record)) {
                continue;
            }

            $label = trim((string) ($record['label'] ?? ''));
            $hash = trim((string) ($record['hash'] ?? ''));
            if ($label === '' || $hash === '') {
                continue;
            }

            $tools = array_values(array_filter(
                array_map(fn ($t): string => trim((string) $t), (array) ($record['tools'] ?? [])),
                fn (string $t): bool => $t !== '',
            ));

            $createdAt = ! empty($record['created_at'])
                ? Carbon::parse((string) $record['created_at'])
                : now();

            // firstOrNew keeps the fold idempotent AND lets us set created_at on new rows only
            // (created_at is not mass-assignable, so it is set directly).
            $token = static::firstOrNew(['label' => $label]);
            $isNew = ! $token->exists;
            $token->token_hash = $hash;
            $token->token_prefix = null;
            $token->tools = $tools;   // [] preserved as deny-all; null reserved for true full-surface
            $token->revoked_at = null;
            if ($isNew) {
                $token->created_at = $createdAt;
            }
            $token->save();
            $imported++;
        }

        return $imported;
    }
}
```

### Step 4 — Run it, watch it pass

`php artisan test tests/Feature/Mcp/McpTokenModelTest.php` → green (5 tests). Also run `php artisan
migrate:fresh --env=testing` mentally covered by RefreshDatabase; confirm no migration error.

### Step 5 — Commit

`feat(mcp): add mcp_tokens table + McpToken model with legacy-blob fold (psa-fn58)`

---

## Task 2 — Refactor `McpConfig` to the `mcp_tokens` table

**Goal.** Point `McpConfig`'s scoped-token storage at the new table while preserving every public
signature and behavior. Scoped mint/resolve now use the table (hash-only, stamps `last_used_at`,
respects `revoked_at`). The legacy single reversible `mcp_staff_token` Setting is **retained as a
resolve fallback** so the already-deployed prod bot token keeps working and the `teams-bot` actor
label is unchanged; `rotateStaffToken(null)` still writes it (CLI break-glass). The UI never takes the
null branch.

**Files:** `app/Support/McpConfig.php` (MODIFIED), `tests/Feature/Mcp/McpConfigTokenStoreTest.php`
(NEW).

**Interfaces (unchanged signatures)**
- `resolveStaffToken(string $token): ?McpStaffToken` — legacy Setting first (bare `McpStaffToken`),
  then `mcp_tokens` sha256 lookup on **active** rows; on a table hit stamps `last_used_at`.
- `rotateStaffToken(?array $allowedTools = null, ?string $label = null): string` — `null` → legacy
  Setting (as today); array → upsert `mcp_tokens` row by label (hash-only, prefix, clears revoked).
- `hasScopedStaffTokenLabel(string $label): bool` — active table rows.
- `isStaffEnabled(): bool` — legacy Setting non-empty OR any active table row.
- `staffToken(): ?string` — unchanged (legacy reversible getter).

### Step 1 — Write the failing test

`tests/Feature/Mcp/McpConfigTokenStoreTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Models\McpToken;
use App\Models\Setting;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpConfigTokenStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_rotate_persists_to_table_hash_only_and_resolves(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff', 'get_staff'], label: 'chet');

        $this->assertStringStartsWith('psa-mcp-', $plain);

        $row = McpToken::where('label', 'chet')->firstOrFail();
        $this->assertSame(hash('sha256', $plain), $row->token_hash);
        $this->assertSame(['find_staff', 'get_staff'], $row->tools);
        $this->assertStringStartsWith('psa-mcp-', (string) $row->token_prefix);
        // hash-only at rest: the plaintext appears nowhere on the row
        $this->assertStringNotContainsString($plain, json_encode($row->getAttributes()));

        $resolved = McpConfig::resolveStaffToken($plain);
        $this->assertNotNull($resolved);
        $this->assertSame('chet', $resolved->label);
        $this->assertTrue($resolved->allows('find_staff'));
        $this->assertFalse($resolved->allows('create_ticket'));
    }

    public function test_resolve_stamps_last_used_at(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $this->assertNull(McpToken::where('label', 'chet')->value('last_used_at'));

        McpConfig::resolveStaffToken($plain);

        $this->assertNotNull(McpToken::where('label', 'chet')->value('last_used_at'));
    }

    public function test_rotating_same_label_replaces_and_invalidates_old_secret(): void
    {
        $old = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $new = McpConfig::rotateStaffToken(allowedTools: ['get_staff'], label: 'chet');

        $this->assertNotSame($old, $new);
        $this->assertSame(1, McpToken::where('label', 'chet')->count());
        $this->assertNull(McpConfig::resolveStaffToken($old), 'old secret no longer authenticates');
        $this->assertNotNull(McpConfig::resolveStaffToken($new));
        $this->assertSame(['get_staff'], McpConfig::resolveStaffToken($new)->allowedTools);
    }

    public function test_revoked_token_no_longer_resolves(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        McpToken::where('label', 'chet')->update(['revoked_at' => now()]);

        $this->assertNull(McpConfig::resolveStaffToken($plain));
    }

    public function test_legacy_full_surface_token_still_resolves_via_setting_fallback(): void
    {
        $plain = McpConfig::rotateStaffToken();   // null tools → legacy reversible Setting

        $this->assertNotEmpty(Setting::getEncrypted('mcp_staff_token'));
        $resolved = McpConfig::resolveStaffToken($plain);
        $this->assertNotNull($resolved);
        $this->assertNull($resolved->allowedTools, 'legacy token = full surface');
        $this->assertSame('teams-bot', $resolved->actorLabel(), 'legacy actor label unchanged');
    }

    public function test_is_staff_enabled_and_has_label_reflect_the_table(): void
    {
        $this->assertFalse(McpConfig::isStaffEnabled());
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $this->assertTrue(McpConfig::isStaffEnabled());
        $this->assertTrue(McpConfig::hasScopedStaffTokenLabel('chet'));
        $this->assertFalse(McpConfig::hasScopedStaffTokenLabel('nope'));
    }
}
```

### Step 2 — Run it, watch it fail

`php artisan test tests/Feature/Mcp/McpConfigTokenStoreTest.php` → fails: current `McpConfig` still
writes/reads the `mcp_staff_scoped_tokens` blob, so `McpToken::where('label','chet')` finds nothing and
`token_prefix`/`last_used_at` don't exist as concepts. Correct failure.

### Step 3 — Implement

Replace `app/Support/McpConfig.php` in full:

```php
<?php

namespace App\Support;

use App\Models\McpToken;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * MCP server config helpers. Scoped staff tokens live in the first-class `mcp_tokens` table
 * (hash-only, GitHub-PAT model). One legacy full-surface token remains on the reversibly-encrypted
 * `mcp_staff_token` Setting for backward-compat with the already-deployed bot and the CLI break-glass
 * path; the management UI only ever mints scoped (allowlisted) tokens.
 */
class McpConfig
{
    public static function staffToken(): ?string
    {
        return Setting::getEncrypted('mcp_staff_token');
    }

    public static function isStaffEnabled(): bool
    {
        return ! empty(self::staffToken()) || McpToken::query()->active()->exists();
    }

    public static function resolveStaffToken(string $token): ?McpStaffToken
    {
        // 1) Legacy full-surface bot token (reversibly encrypted; predates the table). Kept so the
        //    currently-deployed bot token and its "teams-bot" actor label are unaffected.
        $legacy = self::staffToken();
        if (is_string($legacy) && $legacy !== '' && hash_equals($legacy, $token)) {
            return new McpStaffToken;
        }

        // 2) Scoped tokens: indexed sha256 lookup on active rows (same model Laravel Sanctum uses).
        $hash = hash('sha256', $token);
        $record = McpToken::query()->active()->where('token_hash', $hash)->first();
        if ($record === null) {
            return null;
        }

        $record->forceFill(['last_used_at' => now()])->saveQuietly();

        return new McpStaffToken(
            allowedTools: $record->tools === null ? null : self::normalizeToolList($record->tools),
            label: (string) $record->label,
        );
    }

    /**
     * Generate and store a new staff token. Returns the plaintext (shown only once). A null
     * $allowedTools rotates the legacy full-surface token on the Setting (CLI break-glass only); an
     * array upserts a hash-only scoped row keyed by label (rotating a label replaces the old secret
     * and reactivates a revoked label).
     *
     * @param  array<int, string>|null  $allowedTools
     */
    public static function rotateStaffToken(?array $allowedTools = null, ?string $label = null): string
    {
        $token = 'psa-mcp-'.Str::random(48);

        if ($allowedTools === null) {
            Setting::setEncrypted('mcp_staff_token', $token);

            return $token;
        }

        $tools = self::normalizeToolList($allowedTools);
        if ($tools === []) {
            throw new \InvalidArgumentException('At least one allowed MCP tool is required for a scoped token.');
        }

        $label = self::normalizeLabel($label);

        $record = McpToken::firstOrNew(['label' => $label]);
        $record->token_hash = hash('sha256', $token);
        $record->token_prefix = self::tokenPrefix($token);
        $record->tools = $tools;
        $record->last_used_at = null;
        $record->revoked_at = null;
        $record->save();

        return $token;
    }

    public static function hasScopedStaffTokenLabel(string $label): bool
    {
        return McpToken::query()->active()
            ->where('label', self::normalizeLabel($label))
            ->exists();
    }

    /** Non-secret display hint: "psa-mcp-" (8) + 4 body chars + "…" — far too little to brute-force. */
    private static function tokenPrefix(string $token): string
    {
        return Str::substr($token, 0, 12).'…';
    }

    /** @param array<int, mixed> $tools */
    private static function normalizeToolList(array $tools): array
    {
        $normalized = [];
        foreach ($tools as $tool) {
            foreach (explode(',', (string) $tool) as $part) {
                $name = trim($part);
                if ($name !== '') {
                    $normalized[$name] = true;
                }
            }
        }

        return array_keys($normalized);
    }

    private static function normalizeLabel(?string $label): string
    {
        $label = trim((string) $label);
        if ($label === '') {
            return 'scoped';
        }

        $label = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $label) ?? 'scoped';

        return trim($label, '-') !== '' ? trim($label, '-') : 'scoped';
    }
}
```

Notes: `saveQuietly()` avoids firing model events on the hot resolve path; `forceFill` is used because
`last_used_at` writes on read should not require re-validating fillable intent. The private
`scopedStaffTokenRecords()` and `SCOPED_STAFF_TOKENS_KEY` are removed (both were private; nothing
external referenced them).

### Step 4 — Run it, watch it pass — AND run the regression guard

```
php artisan test tests/Feature/Mcp/McpConfigTokenStoreTest.php
php artisan test tests/Feature/Chet tests/Feature/Agent/McpStaffProposeCloseTest.php tests/Feature/Teams/TeamsBotConfigTest.php
```

All must be green. The Chet/Agent/Teams suites mint via `rotateStaffToken(allowedTools:[...],label:)`
and resolve through the middleware → they now transparently use the table. The one legacy test
(`test_legacy_full_surface_tokens_do_not_get_new_bridge_tools_by_default`) uses `rotateStaffToken()`
(null) → Setting → fallback → bridge denied, unchanged.

### Step 5 — Commit

`refactor(mcp): back McpConfig scoped tokens with mcp_tokens table, keep CLI/controller green (psa-fn58)`

---

## Task 3 — `McpToolRegistry` (live, grouped tool catalog)

**Goal.** A single helper that enumerates the selectable tool surface from the live registries, grouped
for the UI, so the checkbox set can never drift from what `/api/mcp/staff` actually allows.

**Files:** `app/Support/McpToolRegistry.php` (NEW), `tests/Feature/Mcp/McpToolRegistryTest.php` (NEW).

**Interfaces**
- Consumes: `AssistantToolDefinitions::getTools(bool $hasClient): array`,
  `OperatorBridgeTools::definitions(): array`, and `TriageToolDefinitions::{ninja,level,mesh,cipp}Tools():
  array` (all return `[['name'=>, 'description'=>, 'input_schema'=>], ...]`).
- Produces:
  - `groups(): array<string, array{label:string, sensitive:bool, tools:array<int,array{name:string,description:string}>}>`
    with ordered keys `general`, `client`, `integration`, `bridge`.
  - `allToolNames(): array<int,string>` — the flat, deduped set for validation.

### Step 1 — Write the failing test

`tests/Feature/Mcp/McpToolRegistryTest.php`:

```php
<?php

namespace Tests\Feature\Mcp;

use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpToolRegistryTest extends TestCase
{
    use RefreshDatabase; // getTools(hasClient:true) consults integration availability (Settings/config)

    public function test_groups_are_ordered_and_classify_tools(): void
    {
        $groups = McpToolRegistry::groups();

        $this->assertSame(['general', 'client', 'integration', 'bridge'], array_keys($groups));

        $names = fn (string $g): array => array_column($groups[$g]['tools'], 'name');

        $this->assertContains('list_open_tickets', $names('general'));
        $this->assertContains('create_ticket', $names('client'));
        $this->assertContains('ninja_get_device', $names('integration'));   // adjust to a real ninja tool name if different
        $this->assertContains('post_to_operator', $names('bridge'));
        $this->assertTrue($groups['bridge']['sensitive']);
        $this->assertFalse($groups['general']['sensitive']);
    }

    public function test_tools_carry_descriptions_and_no_group_overlap(): void
    {
        $groups = McpToolRegistry::groups();

        $bridge = collect($groups['bridge']['tools'])->firstWhere('name', 'post_to_operator');
        $this->assertNotEmpty($bridge['description']);

        $seen = [];
        foreach ($groups as $group) {
            foreach ($group['tools'] as $tool) {
                $this->assertArrayNotHasKey($tool['name'], $seen, "duplicate across groups: {$tool['name']}");
                $seen[$tool['name']] = true;
            }
        }
    }

    public function test_all_tool_names_is_flat_deduped_superset(): void
    {
        $all = McpToolRegistry::allToolNames();

        $this->assertContains('list_open_tickets', $all);
        $this->assertContains('create_ticket', $all);
        $this->assertContains('post_to_operator', $all);
        $this->assertSame(array_values(array_unique($all)), $all, 'no duplicates');
    }
}
```

> Executor note: before running, confirm the exact ninja tool name via
> `grep -n "'name' =>" app/Services/Triage/TriageToolDefinitions.php` (the `ninjaTools()` block) and
> use a real one in `test_groups_are_ordered_and_classify_tools` (e.g. `ninja_get_device` /
> `ninja_list_devices` — whatever the registry actually defines).

### Step 2 — Run it, watch it fail

`php artisan test tests/Feature/Mcp/McpToolRegistryTest.php` → `Class "App\Support\McpToolRegistry"
not found`. Correct.

### Step 3 — Implement

`app/Support/McpToolRegistry.php`:

```php
<?php

namespace App\Support;

use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Chet\OperatorBridgeTools;
use App\Services\Triage\TriageToolDefinitions;

/**
 * Enumerates the live MCP tool surface a staff token can be scoped to, grouped for the management UI.
 * Sourced directly from the tool registries so the checkbox set can never drift from what the
 * /api/mcp/staff boundary allows. Integration tools are enumerated from their registry methods
 * directly (not gated on current availability) so a token can be pre-scoped before an RMM is enabled.
 */
class McpToolRegistry
{
    /**
     * @return array<string, array{label: string, sensitive: bool, tools: array<int, array{name: string, description: string}>}>
     */
    public static function groups(): array
    {
        $general = self::shape(AssistantToolDefinitions::getTools(hasClient: false));
        $generalNames = array_column($general, 'name');

        $integration = self::shape(array_merge(
            TriageToolDefinitions::ninjaTools(),
            TriageToolDefinitions::levelTools(),
            TriageToolDefinitions::meshTools(),
            TriageToolDefinitions::cippTools(),
        ));
        $integrationNames = array_column($integration, 'name');

        // Client-scoped = the per-client PSA tools, i.e. whatever the client context adds beyond the
        // general set and the integration set (DNS/wiki live in the general set and are filtered out).
        $client = array_values(array_filter(
            self::shape(AssistantToolDefinitions::getTools(hasClient: true)),
            fn (array $t): bool => ! in_array($t['name'], $generalNames, true)
                && ! in_array($t['name'], $integrationNames, true),
        ));

        $bridge = self::shape(OperatorBridgeTools::definitions());

        return [
            'general' => ['label' => 'General (no client context)', 'sensitive' => false, 'tools' => $general],
            'client' => ['label' => 'Client-scoped', 'sensitive' => false, 'tools' => $client],
            'integration' => ['label' => 'Integration (RMM / M365)', 'sensitive' => false, 'tools' => $integration],
            'bridge' => ['label' => 'Operator bridge (sensitive)', 'sensitive' => true, 'tools' => $bridge],
        ];
    }

    /** @return array<int, string> */
    public static function allToolNames(): array
    {
        $names = [];
        foreach (self::groups() as $group) {
            foreach ($group['tools'] as $tool) {
                $names[$tool['name']] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array{name: string, description: string}>
     */
    private static function shape(array $tools): array
    {
        $shaped = [];
        foreach ($tools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $shaped[$name] = [
                'name' => $name,
                'description' => (string) ($tool['description'] ?? ''),
            ];
        }

        return array_values($shaped);
    }
}
```

### Step 4 — Run it, watch it pass

`php artisan test tests/Feature/Mcp/McpToolRegistryTest.php` → green.

### Step 5 — Commit

`feat(mcp): add McpToolRegistry grouped tool catalog for scoping UI (psa-fn58)`

---

## Task 4 — `McpTokensController` + routes (auth group) + sidebar nav

**Goal.** Wire the Settings CRUD: list, mint (delegating to `McpConfig::rotateStaffToken`, flashing the
one-time secret), revoke (model `revoked_at` stamp). Register routes inside the existing `auth` group
(no new gate) and add the sidebar link. (Audit hooks come in Task 6.)

**Files:** `app/Http/Controllers/Web/McpTokensController.php` (NEW), `routes/web.php` (MODIFIED),
`resources/views/components/sidebar.blade.php` (MODIFIED), `tests/Feature/Settings/McpTokensPageTest.php`
(NEW). The Blade view (Task 5) is created alongside so `index` renders; keep it minimal here and flesh
it out in Task 5, OR do Task 5 first — either order works since both land before Step 4 passes. This
plan writes the view in Task 5; for Task 4's green run, a minimal `index.blade.php` that renders the
required strings is sufficient (Task 5 replaces it).

**Interfaces**
- Routes (names): `settings.mcp-tokens.index` (GET), `settings.mcp-tokens.store` (POST),
  `settings.mcp-tokens.revoke` (DELETE `/{token}`).
- `index()` → view `settings.mcp-tokens.index` with `tokens` (Collection<McpToken>), `groups`
  (`McpToolRegistry::groups()`), `newToken`/`newTokenLabel` (flashed one-time plaintext).
- `store(Request)` → validates `label` (regex `^[A-Za-z0-9_.:-]+$`, max 100) + `tools[]`
  (`Rule::in(McpToolRegistry::allToolNames())`, min 1) → `McpConfig::rotateStaffToken` → redirect with
  `mcp_new_token` flash.
- `revoke(McpToken $token)` → stamp `revoked_at`, redirect.

### Step 1 — Write the failing test

`tests/Feature/Settings/McpTokensPageTest.php`:

```php
<?php

namespace Tests\Feature\Settings;

use App\Models\McpToken;
use App\Models\User;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTokensPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_page_requires_authentication(): void
    {
        $this->get(route('settings.mcp-tokens.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_tokens_and_renders_registry_checkboxes(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff', 'get_staff'], label: 'chet');

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee('chet')
            ->assertSee('post_to_operator')   // bridge checkbox from the live registry
            ->assertSee('list_open_tickets'); // general checkbox from the live registry
    }

    public function test_store_mints_a_scoped_token_shown_once_and_hashed_at_rest(): void
    {
        $resp = $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'chet',
            'tools' => ['find_staff', 'get_staff'],
        ]);

        $resp->assertRedirect(route('settings.mcp-tokens.index'));
        $plain = $resp->getSession()->get('mcp_new_token');
        $this->assertIsString($plain);
        $this->assertStringStartsWith('psa-mcp-', $plain);

        $row = McpToken::where('label', 'chet')->firstOrFail();
        $this->assertSame(hash('sha256', $plain), $row->token_hash);
        $this->assertStringNotContainsString($plain, json_encode($row->getAttributes()));

        // one-time: a fresh GET (flash consumed) must not render the plaintext
        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertDontSee($plain);
    }

    public function test_store_rejects_unknown_tool_names(): void
    {
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'evil',
            'tools' => ['definitely_not_a_tool'],
        ])->assertSessionHasErrors('tools.0');

        $this->assertSame(0, McpToken::count());
    }

    public function test_store_requires_at_least_one_tool(): void
    {
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'empty',
            'tools' => [],
        ])->assertSessionHasErrors('tools');
    }

    public function test_revoke_stamps_revoked_at_and_blocks_resolution(): void
    {
        $plain = McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)
            ->delete(route('settings.mcp-tokens.revoke', $token))
            ->assertRedirect(route('settings.mcp-tokens.index'));

        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertNull(McpConfig::resolveStaffToken($plain));
    }
}
```

### Step 2 — Run it, watch it fail

`php artisan test tests/Feature/Settings/McpTokensPageTest.php` → route/controller/view missing.
Correct.

### Step 3 — Implement

`app/Http/Controllers/Web/McpTokensController.php`:

```php
<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\McpToken;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings UI for staff MCP tokens (the "AI control surface", subsystem 1). Behind the existing
 * `auth` middleware group — no RBAC in v1. Mints hash-only, allowlisted tokens (GitHub-PAT model);
 * the plaintext is shown exactly once via a session flash and never persisted.
 */
class McpTokensController extends Controller
{
    public function index()
    {
        return view('settings.mcp-tokens.index', [
            'tokens' => McpToken::query()
                ->orderByRaw('(revoked_at IS NULL) DESC')  // active first (SQLite + MariaDB safe)
                ->orderByDesc('created_at')
                ->get(),
            'groups' => McpToolRegistry::groups(),
            'newToken' => session('mcp_new_token'),
            'newTokenLabel' => session('mcp_new_token_label'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'tools' => ['required', 'array', 'min:1'],
            'tools.*' => ['string', Rule::in(McpToolRegistry::allToolNames())],
        ]);

        $token = McpConfig::rotateStaffToken(
            allowedTools: array_values($validated['tools']),
            label: $validated['label'],
        );

        // GitHub-PAT one-time display: flash the plaintext, never store it.
        return redirect()->route('settings.mcp-tokens.index')
            ->with('mcp_new_token', $token)
            ->with('mcp_new_token_label', $validated['label'])
            ->with('success', 'Token "'.$validated['label'].'" created. Copy it now — it will not be shown again.');
    }

    public function revoke(McpToken $token)
    {
        $label = $token->label;
        $token->forceFill(['revoked_at' => now()])->save();

        return redirect()->route('settings.mcp-tokens.index')
            ->with('success', 'Token "'.$label.'" revoked.');
    }
}
```

`routes/web.php` — add the import near the other `Web\*` imports:

```php
use App\Http\Controllers\Web\McpTokensController;
```

and inside the existing `Route::middleware('auth')->group(function () { ... })`, next to the
`// Settings — Integrations` block:

```php
    // Settings — MCP Tokens (AI control surface)
    Route::get('/settings/mcp-tokens', [McpTokensController::class, 'index'])->name('settings.mcp-tokens.index');
    Route::post('/settings/mcp-tokens', [McpTokensController::class, 'store'])->name('settings.mcp-tokens.store');
    Route::delete('/settings/mcp-tokens/{token}', [McpTokensController::class, 'revoke'])->name('settings.mcp-tokens.revoke');
```

`resources/views/components/sidebar.blade.php` — add after the Integrations link, inside the Settings
`sidebar-group`:

```blade
            <a href="{{ route('settings.mcp-tokens.index') }}"
               class="sidebar-link {{ request()->routeIs('settings.mcp-tokens*') ? 'active' : '' }}"
               @if(request()->routeIs('settings.mcp-tokens*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="MCP Tokens">
                <i class="bi bi-key sidebar-icon"></i>
                <span class="sidebar-label">MCP Tokens</span>
            </a>
```

Minimal `resources/views/settings/mcp-tokens/index.blade.php` (replaced in full by Task 5) so the
`index`/`store` tests render:

```blade
@extends('layouts.app')
@section('title', 'MCP Tokens')
@section('content')
    @foreach($groups as $group)
        @foreach($group['tools'] as $tool)
            <input type="checkbox" name="tools[]" value="{{ $tool['name'] }}"> {{ $tool['name'] }}
        @endforeach
    @endforeach
    @foreach($tokens as $t){{ $t->label }}@endforeach
@endsection
```

### Step 4 — Run it, watch it pass

`php artisan test tests/Feature/Settings/McpTokensPageTest.php` → green. Run `php artisan route:list
--name=mcp-tokens` to eyeball the three routes.

### Step 5 — Commit

`feat(mcp): MCP tokens settings controller + routes + nav (auth group, psa-fn58)`

---

## Task 5 — Blade: list + mint (one-time secret) + registry checkboxes + revoke

**Goal.** Replace the minimal view with the real page, matching the `settings/*` conventions (card
layout, `session('success')` alert, `@error`, one-time secret card, grouped checkboxes with tool
descriptions, tokens table with revoke). No controller changes.

**Files:** `resources/views/settings/mcp-tokens/index.blade.php` (REPLACE),
`tests/Feature/Settings/McpTokensPageTest.php` (EXTEND with rendering assertions).

**Interfaces:** consumes `$tokens`, `$groups`, `$newToken`, `$newTokenLabel` from `index()`. Produces
the mint form (POST `settings.mcp-tokens.store`) and per-row revoke form (DELETE
`settings.mcp-tokens.revoke`).

### Step 1 — Write the failing test (extend)

Add to `McpTokensPageTest`:

```php
    public function test_one_time_secret_card_renders_after_mint(): void
    {
        $this->actingAs($this->user)
            ->withSession(['mcp_new_token' => 'psa-mcp-EXAMPLEONETIME', 'mcp_new_token_label' => 'chet'])
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee('psa-mcp-EXAMPLEONETIME')
            ->assertSee('will not be shown again');
    }

    public function test_tool_descriptions_and_sensitive_group_are_rendered(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee('Operator bridge (sensitive)')
            ->assertSee('Post a message to the operator Teams chat', false); // post_to_operator description
    }

    public function test_revoked_tokens_show_as_revoked_without_a_revoke_button(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'dead');
        McpToken::where('label', 'dead')->update(['revoked_at' => now()]);

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee('Revoked');
    }
```

### Step 2 — Run it, watch it fail

`php artisan test tests/Feature/Settings/McpTokensPageTest.php` → the new assertions fail against the
minimal stub view (no one-time card, no descriptions, no "Revoked" badge). Correct.

### Step 3 — Implement

Replace `resources/views/settings/mcp-tokens/index.blade.php`:

```blade
@extends('layouts.app')

@section('title', 'MCP Tokens')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-9">
        <h2 class="section-title">MCP Tokens</h2>
        <p class="text-muted small">
            Bearer tokens for the staff MCP server (<code>{{ url('/api/mcp/staff') }}</code>). Each token
            is scoped to the tools it may call. Secrets are shown once at creation and stored only as a
            hash — if a token is lost, revoke it and mint a new one. The
            <code>php artisan mcp:rotate-staff-token</code> CLI remains available as a break-glass path.
        </p>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if($newToken)
            <div class="card border-success shadow-sm mb-4">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-check-circle me-2"></i>Token "{{ $newTokenLabel }}" created
                </div>
                <div class="card-body">
                    <p class="small mb-2 fw-bold text-danger">
                        Copy this now — it will not be shown again.
                    </p>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace" id="mcp_new_token"
                               value="{{ $newToken }}" readonly>
                        <button type="button" class="btn btn-outline-secondary" onclick="copyMcpToken()">
                            <i class="bi bi-clipboard me-1"></i>Copy
                        </button>
                    </div>
                </div>
            </div>
        @endif

        {{-- Mint --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Create a token</div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.mcp-tokens.store') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="label" class="form-label">Label</label>
                        <input type="text" class="form-control @error('label') is-invalid @enderror"
                               id="label" name="label" value="{{ old('label') }}"
                               placeholder="e.g. chet, office-teams-pack" required>
                        <div class="form-text">Letters, numbers and <code>_ . : -</code>. Reusing a label rotates that token.</div>
                        @error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <label class="form-label">Allowed tools</label>
                    @error('tools')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                    @error('tools.0')<div class="text-danger small mb-2">{{ $message }}</div>@enderror

                    @foreach($groups as $key => $group)
                        <div class="card mb-2 {{ $group['sensitive'] ? 'border-warning' : '' }}">
                            <div class="card-body py-2">
                                <div class="d-flex align-items-center mb-2">
                                    <span class="fw-semibold">{{ $group['label'] }}</span>
                                    @if($group['sensitive'])
                                        <span class="badge bg-warning text-dark ms-2">sensitive</span>
                                    @endif
                                </div>
                                @forelse($group['tools'] as $tool)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="tools[]"
                                               value="{{ $tool['name'] }}"
                                               id="tool_{{ $tool['name'] }}"
                                               {{ in_array($tool['name'], old('tools', []), true) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="tool_{{ $tool['name'] }}">
                                            <code>{{ $tool['name'] }}</code>
                                            <span class="text-muted small d-block">{{ $tool['description'] }}</span>
                                        </label>
                                    </div>
                                @empty
                                    <p class="text-muted small mb-0">No tools in this group.</p>
                                @endforelse
                            </div>
                        </div>
                    @endforeach

                    <button type="submit" class="btn btn-primary mt-2">
                        <i class="bi bi-key me-1"></i>Create Token
                    </button>
                </form>
            </div>
        </div>

        {{-- List --}}
        <div class="card card-static shadow-sm">
            <div class="card-header"><i class="bi bi-list-ul me-2"></i>Existing tokens</div>
            @if($tokens->isEmpty())
                <div class="card-body"><p class="text-muted mb-0">No tokens yet.</p></div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="thead-brand">
                            <tr>
                                <th>Label</th>
                                <th>Prefix</th>
                                <th>Tools</th>
                                <th>Created</th>
                                <th>Last used</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tokens as $t)
                                <tr class="{{ $t->isRevoked() ? 'text-muted' : '' }}">
                                    <td class="fw-semibold">{{ $t->label }}</td>
                                    <td class="font-monospace small">{{ $t->token_prefix ?? '—' }}</td>
                                    <td class="small">
                                        @if($t->tools === null)
                                            <span class="badge bg-danger">full surface</span>
                                        @else
                                            <span class="text-muted">{{ count($t->tools) }}:</span>
                                            @foreach(array_slice($t->tools, 0, 6) as $tool)
                                                <span class="badge bg-light text-dark border">{{ $tool }}</span>
                                            @endforeach
                                            @if(count($t->tools) > 6)<span class="text-muted">+{{ count($t->tools) - 6 }}</span>@endif
                                        @endif
                                    </td>
                                    <td class="small">{{ $t->created_at?->format('Y-m-d') }}</td>
                                    <td class="small">{{ $t->last_used_at ? $t->last_used_at->diffForHumans() : 'never' }}</td>
                                    <td class="text-center">
                                        @if($t->isRevoked())
                                            <span class="badge bg-secondary">Revoked</span>
                                        @else
                                            <span class="badge bg-success">Active</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @unless($t->isRevoked())
                                            <form method="POST" action="{{ route('settings.mcp-tokens.revoke', $t) }}"
                                                  onsubmit="return confirm('Revoke token &quot;{{ $t->label }}&quot;? It will stop authenticating immediately.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                                    <i class="bi bi-x-circle me-1"></i>Revoke
                                                </button>
                                            </form>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function copyMcpToken() {
    const input = document.getElementById('mcp_new_token');
    if (!input) return;
    navigator.clipboard.writeText(input.value).then(() => {
        const btn = input.nextElementSibling;
        if (btn) {
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check me-1"></i>Copied';
            setTimeout(() => { btn.innerHTML = original; }, 2000);
        }
    });
}
</script>
@endpush
```

### Step 4 — Run it, watch it pass

`php artisan test tests/Feature/Settings/McpTokensPageTest.php` → green (all cases, incl. the extended
rendering ones). Optional manual smoke: `php artisan serve`, log in, visit `/settings/mcp-tokens`, mint
a token, confirm the one-time secret + copy, revoke it.

### Step 5 — Commit

`feat(mcp): MCP tokens page — list, mint (one-time secret), revoke, registry checkboxes (psa-fn58)`

---

## Task 6 — Audit hooks (mint / rotate / revoke → `McpAuditLog`)

**Goal.** Record token lifecycle events in the existing `mcp_audit_logs` table (reused; no schema
change), with the acting web user + source IP. Mint vs rotate is distinguished by whether the label
already existed.

**Files:** `app/Http/Controllers/Web/McpTokensController.php` (MODIFIED),
`tests/Feature/Settings/McpTokensPageTest.php` (EXTEND).

**Interfaces**
- Consumes: `App\Models\McpAuditLog::create([...])` with columns
  `server_name, method, tool_name, arguments(json,null), status, error_message(null), duration_ms(int,
  NOT null → 0), actor_label, source_ip`.
- Produces audit rows: `server_name='staff'`; `method ∈ {token/mint, token/rotate, token/revoke}`;
  `tool_name` = the token label; `arguments = {tools:[...]}`; `actor_label = 'web:'+user email`.

### Step 1 — Write the failing test (extend)

```php
    public function test_minting_writes_a_token_mint_audit_row(): void
    {
        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'chet',
            'tools' => ['find_staff', 'get_staff'],
        ]);

        $this->assertDatabaseHas('mcp_audit_logs', [
            'server_name' => 'staff',
            'method' => 'token/mint',
            'tool_name' => 'chet',
            'status' => 'success',
        ]);
        $row = \App\Models\McpAuditLog::where('method', 'token/mint')->firstOrFail();
        $this->assertStringContainsString($this->user->email, (string) $row->actor_label);
        $this->assertSame(['tools' => ['find_staff', 'get_staff']], $row->arguments);
    }

    public function test_re_minting_an_existing_label_is_audited_as_rotate(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $this->actingAs($this->user)->post(route('settings.mcp-tokens.store'), [
            'label' => 'chet',
            'tools' => ['get_staff'],
        ]);

        $this->assertDatabaseHas('mcp_audit_logs', ['method' => 'token/rotate', 'tool_name' => 'chet']);
    }

    public function test_revoking_writes_a_token_revoke_audit_row(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();

        $this->actingAs($this->user)->delete(route('settings.mcp-tokens.revoke', $token));

        $this->assertDatabaseHas('mcp_audit_logs', [
            'server_name' => 'staff',
            'method' => 'token/revoke',
            'tool_name' => 'chet',
        ]);
    }
```

### Step 2 — Run it, watch it fail

`php artisan test tests/Feature/Settings/McpTokensPageTest.php` → the three audit assertions fail (no
rows written). Correct.

### Step 3 — Implement

Modify `McpTokensController`: detect rotate-vs-mint, add audit calls, add the helper. Full updated
`store`/`revoke` + new imports/helper:

```php
use App\Models\McpAuditLog;                    // add to the imports block
use Illuminate\Support\Facades\Log;            // add to the imports block

    public function store(Request $request)
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'tools' => ['required', 'array', 'min:1'],
            'tools.*' => ['string', Rule::in(McpToolRegistry::allToolNames())],
        ]);

        $isRotate = McpConfig::hasScopedStaffTokenLabel($validated['label']);

        $token = McpConfig::rotateStaffToken(
            allowedTools: array_values($validated['tools']),
            label: $validated['label'],
        );

        $this->audit(
            $request,
            $isRotate ? 'token/rotate' : 'token/mint',
            $validated['label'],
            ['tools' => array_values($validated['tools'])],
        );

        return redirect()->route('settings.mcp-tokens.index')
            ->with('mcp_new_token', $token)
            ->with('mcp_new_token_label', $validated['label'])
            ->with('success', 'Token "'.$validated['label'].'" created. Copy it now — it will not be shown again.');
    }

    public function revoke(Request $request, McpToken $token)
    {
        $label = $token->label;
        $tools = $token->tools;
        $token->forceFill(['revoked_at' => now()])->save();

        $this->audit($request, 'token/revoke', $label, ['tools' => $tools]);

        return redirect()->route('settings.mcp-tokens.index')
            ->with('success', 'Token "'.$label.'" revoked.');
    }

    /** @param array<string, mixed> $arguments */
    private function audit(Request $request, string $method, string $label, array $arguments): void
    {
        try {
            McpAuditLog::create([
                'server_name' => 'staff',
                'method' => $method,
                'tool_name' => mb_substr($label, 0, 100),
                'arguments' => $arguments,
                'status' => 'success',
                'error_message' => null,
                'duration_ms' => 0,                     // column is NOT NULL; lifecycle events are instantaneous
                'actor_label' => mb_substr('web:'.((string) ($request->user()?->email ?? $request->user()?->id ?? 'unknown')), 0, 100),
                'source_ip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Settings/McpTokens] Audit write failed: '.$e->getMessage());
        }
    }
```

> Note the `revoke` signature gains a `Request $request` first parameter — route-model binding still
> injects `McpToken $token` as the second. Update the route/method accordingly (Laravel resolves both).

### Step 4 — Run it, watch it pass

`php artisan test tests/Feature/Settings/McpTokensPageTest.php` → green. Then run the **full guard**:

```
php artisan test tests/Feature/Mcp tests/Feature/Settings tests/Feature/Chet \
  tests/Feature/Agent/McpStaffProposeCloseTest.php tests/Feature/Teams/TeamsBotConfigTest.php
```

and finally `./vendor/bin/pint --dirty` (project convention: Pint before PR) and the full suite
`php artisan test` to confirm no regressions.

### Step 5 — Commit

`feat(mcp): audit token mint/rotate/revoke to mcp_audit_logs (psa-fn58)`

---

## Deferred (explicitly NOT in v1)

Admin gate / RBAC; alert-destination management (separate plan); in-UI audit log viewer; HMAC webhook
signing; multi-tenancy / tenant scope column; a UI path to mint a full-surface (`tools=null`) token
(CLI break-glass only); removing the vestigial `mcp_staff_scoped_tokens` Setting (a later cleanup
migration). The legacy reversible `mcp_staff_token` single token is intentionally retained and not
surfaced in the UI.

---

## Self-Review (performed)

**Spec coverage — every v1 scope item maps to a task:**
- `mcp_tokens` table + `McpToken` model with the exact columns (id, label unique, token_hash,
  token_prefix, tools JSON nullable[null=full], created_at, last_used_at nullable, revoked_at nullable)
  → **Task 1**. Data migration folding `mcp_staff_scoped_tokens` → **Task 1** (`importLegacyBlob`,
  called by the migration, unit-tested independently).
- `McpConfig` refactor to the table (rotate=create/replace by label; resolve=hash match on non-revoked,
  stamp last_used_at) with CLI + controller tests green → **Task 2** (+ regression run of Chet/Agent/
  Teams suites). Legacy `mcp_staff_token`: **decision made + justified** — retained as a resolve
  fallback and the CLI null-branch, so the deployed bot + `teams-bot` actor label are unchanged; the
  UI only mints scoped tokens.
- Web controller + routes in the existing `auth` group (NO new gate) + Blade list/mint(one-time)/revoke
  + sidebar nav → **Tasks 4 & 5**. Registry-driven checkboxes → **Task 3** (`McpToolRegistry`).
- Audit mint/rotate/revoke to `McpAuditLog` → **Task 6**.
- OUT of v1 (admin gate, alert destinations, audit viewer, HMAC, multi-tenancy) → listed under
  Deferred; none built.

**Placeholders:** none. Every task ships complete, runnable PHP + Blade + PHPUnit code. The only
executor lookup flagged is the exact ninja tool name in the Task 3 assertion (a `grep` one-liner is
given) — the implementation code itself has no placeholders.

**Type consistency:** `resolveStaffToken(string): ?McpStaffToken` and
`rotateStaffToken(?array,?string): string` keep their signatures (verified against
`VerifyMcpStaffToken` and `McpStaffController`, which consume `McpStaffToken->allowedTools/label/
allows()/actorLabel()` — untouched). `McpToken` casts (`tools=>array`) match how `resolveStaffToken`
reads `$record->tools` (array|null). `McpAuditLog::create` payload matches the model `$fillable` and the
NOT-NULL `duration_ms` (passed `0`) and length caps (`method` ≤ 50, `tool_name`/`actor_label` ≤ 100 via
`mb_substr`). SQLite-safe SQL confirmed (`(revoked_at IS NULL) DESC`). `McpToolRegistry::groups()`
return shape matches the Blade consumption (`label`, `sensitive`, `tools[].name/.description`).

**Risk notes for the reviewer (Mayor):** (1) This is LIVE-additive — after deploy, `resolveStaffToken`
begins stamping `last_used_at` on every MCP request (one extra quiet write per call; acceptable). (2)
The migration decrypts + folds the prod `mcp_staff_scoped_tokens` blob at deploy time (needs `APP_KEY`,
which is present); the blob is left intact for rollback. (3) Indexed sha256 lookup replaces the
hash_equals loop for scoped tokens — this matches Laravel Sanctum's model and is safe for the 48-char
random secret.
