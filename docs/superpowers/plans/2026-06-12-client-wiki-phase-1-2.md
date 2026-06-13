# Client Wiki — Phases 1+2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the Client Wiki foundation: schema + models, a usable staff-facing wiki (CRUD, markdown rendering with wikilinks, revisions, history/diff, search, cascade views), per-client skeleton seeding, `site_notes` import, and the deterministic sync-fact writer that keeps environment pages current with zero AI cost.

**Architecture:** Facts are atoms, pages are views (spec §4). Pages are markdown in MariaDB rendered server-side in Blade; AI-managed sections are composed from `wiki_facts` rows by a deterministic template composer between HTML-comment markers. The sync-fact writer hooks the existing Ninja/CIPP sync services and upserts born-`confirmed` facts. Everything is gated by `WikiConfig::isEnabled()`. No AI calls anywhere in this plan (that's Phase 3).

**Tech Stack:** Laravel 12 / PHP 8.3, MariaDB (FULLTEXT, driver-guarded with LIKE fallback for SQLite tests), Blade + Bootstrap 5.3 (no build step), PHPUnit (SQLite `:memory:`), Pint.

**Spec:** `docs/superpowers/specs/2026-06-12-client-wiki-design.md` (read it first — §4 data model, §4.5 cascade, §8/§8.1 UI requirements, §9 config).

**Branch:** create `feat/client-wiki-phase-1-2` off `main` (use a worktree per superpowers:using-git-worktrees). Do NOT build on the `docs/client-wiki-spec` branch; the spec merges separately.

**Conventions you must follow (from this codebase):**
- Migrations: anonymous class, string columns for enum-backed fields, `foreignId()->constrained()`.
- Enums: `app/Enums`, string-backed, `label()` (+ `badgeClass()` where displayed). Models cast via `casts()`.
- Logic in `app/Services/Wiki/`, controllers stay thin, validation in FormRequests (`app/Http/Requests`).
- Markdown: always render through `App\Helpers\MarkdownRenderer::render()` (sanitizes HTML). Never echo raw HTML from user/AI content.
- Tests: `RefreshDatabase`, `User::factory()`, `$this->actingAs($user)`. Test DB is SQLite `:memory:` — anything MariaDB-only must be driver-guarded.
- Run `./vendor/bin/pint` before each commit (recent history is Pint-clean).

---

## File structure (locked)

```
app/Enums/WikiScope.php                      scope: global|client
app/Enums/WikiPageKind.php                   overview|environment|runbook|deviation|vendor|pattern|note
app/Enums/WikiFactStatus.php                 unverified|confirmed|disputed|retired
app/Enums/WikiFactSource.php                 sync|ticket|triage|human
app/Enums/WikiFactVolatility.php             durable|volatile
app/Enums/WikiAuthorType.php                 ai|human|system
app/Enums/WikiRunType.php                    mine_ticket|sync_facts|maintain|backfill
app/Enums/WikiRunStatus.php                  pending|running|completed|failed|quarantined
database/migrations/*_create_wiki_pages_table.php
database/migrations/*_create_wiki_facts_table.php
database/migrations/*_create_wiki_page_revisions_table.php
database/migrations/*_create_wiki_links_table.php
database/migrations/*_create_wiki_runs_table.php
app/Models/{WikiPage,WikiFact,WikiPageRevision,WikiLink,WikiRun}.php
database/factories/{WikiPageFactory,WikiFactFactory}.php
app/Support/WikiConfig.php                   settings gate (wiki_enabled)
app/Services/Wiki/WikiLinkParser.php         [[wikilink]] extraction
app/Services/Wiki/WikiLinkResolver.php       scoped slug→page resolution (client first, then global)
app/Services/Wiki/WikiSections.php           split/replace markdown by ## anchors; marker splice
app/Services/Wiki/WikiMarkdown.php           wikilinks→links, render via MarkdownRenderer
app/Services/Wiki/WikiPageService.php        transactional create/updateBody + revision + link rebuild + deviation validation
app/Services/Wiki/WikiCascadeService.php     section-level merged view (spec §4.5)
app/Services/Wiki/WikiSkeletonService.php    per-client skeleton seeding (idempotent)
app/Services/Wiki/WikiComposerService.php    deterministic fact→section composition between markers
app/Services/Wiki/WikiFactService.php        sync-fact upsert (reaffirm/supersede/insert, row-locked)
app/Services/Wiki/SyncFactWriter.php         asset + M365 facts, wiki_runs ledger, safe wrappers
app/Services/Wiki/WikiSearchService.php      FULLTEXT (mysql/mariadb) or LIKE (sqlite) search
app/Helpers/LineDiff.php                     minimal line diff for revision history
app/Http/Controllers/Web/WikiController.php  index/show/create/store/edit/update/history/search (+client variants)
app/Http/Requests/WikiPageStoreRequest.php
app/Http/Requests/WikiPageUpdateRequest.php
app/Console/Commands/WikiImportSiteNotes.php artisan wiki:import-site-notes
resources/views/wiki/{index,show,create,edit,history,search}.blade.php
routes/web.php                               (modify: wiki route group inside auth group)
app/Services/Ninja/NinjaSyncService.php      (modify: post-sync hook, ~3 lines)
app/Services/Cipp/CippTenantSecuritySyncService.php (modify: post-sync hook, ~3 lines)
resources/views/clients/show.blade.php       (modify: Wiki nav link)
tests/Unit/Wiki/*                            parser, sections, markdown, cascade, diff, fact upsert, composer, search
tests/Feature/Wiki/*                         pages CRUD, skeleton, import, sync writer, routes/permissions
```

Out of scope for this plan (later phases): AI mining, redaction, disputes/addenda UX, retrieval tools, hot summary injection, maintenance loop, export, backfill. The schema for all of it ships now (spec: Phase 1 delivers the full schema), so later phases are additive.

---

### Task 1: Wiki enums

**Files:**
- Create: `app/Enums/WikiScope.php`, `app/Enums/WikiPageKind.php`, `app/Enums/WikiFactStatus.php`, `app/Enums/WikiFactSource.php`, `app/Enums/WikiFactVolatility.php`, `app/Enums/WikiAuthorType.php`, `app/Enums/WikiRunType.php`, `app/Enums/WikiRunStatus.php`

- [ ] **Step 1: Write the eight enums**

`app/Enums/WikiScope.php`:
```php
<?php

namespace App\Enums;

enum WikiScope: string
{
    case Global = 'global';
    case Client = 'client';
}
```

`app/Enums/WikiPageKind.php`:
```php
<?php

namespace App\Enums;

enum WikiPageKind: string
{
    case Overview = 'overview';
    case Environment = 'environment';
    case Runbook = 'runbook';
    case Deviation = 'deviation';
    case Vendor = 'vendor';
    case Pattern = 'pattern';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Overview => 'Overview',
            self::Environment => 'Environment',
            self::Runbook => 'Runbook',
            self::Deviation => 'Runbook deviation',
            self::Vendor => 'Vendor',
            self::Pattern => 'Pattern',
            self::Note => 'Notes',
        };
    }
}
```

`app/Enums/WikiFactStatus.php`:
```php
<?php

namespace App\Enums;

enum WikiFactStatus: string
{
    case Unverified = 'unverified';
    case Confirmed = 'confirmed';
    case Disputed = 'disputed';
    case Retired = 'retired';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    // §8.1: badges must pair color with text — callers render label() text inside the badge.
    public function badgeClass(): string
    {
        return match ($this) {
            self::Unverified => 'badge bg-secondary',
            self::Confirmed => 'badge bg-success',
            self::Disputed => 'badge bg-warning text-dark',
            self::Retired => 'badge bg-light text-muted border',
        };
    }
}
```

`app/Enums/WikiFactSource.php`:
```php
<?php

namespace App\Enums;

enum WikiFactSource: string
{
    case Sync = 'sync';
    case Ticket = 'ticket';
    case Triage = 'triage';
    case Human = 'human';
}
```

`app/Enums/WikiFactVolatility.php`:
```php
<?php

namespace App\Enums;

enum WikiFactVolatility: string
{
    case Durable = 'durable';
    case Volatile = 'volatile';
}
```

`app/Enums/WikiAuthorType.php`:
```php
<?php

namespace App\Enums;

enum WikiAuthorType: string
{
    case Ai = 'ai';
    case Human = 'human';
    case System = 'system';
}
```

`app/Enums/WikiRunType.php`:
```php
<?php

namespace App\Enums;

enum WikiRunType: string
{
    case MineTicket = 'mine_ticket';
    case SyncFacts = 'sync_facts';
    case Maintain = 'maintain';
    case Backfill = 'backfill';
}
```

`app/Enums/WikiRunStatus.php`:
```php
<?php

namespace App\Enums;

enum WikiRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Quarantined = 'quarantined';
}
```

- [ ] **Step 2: Pint and commit**

```bash
./vendor/bin/pint app/Enums
git add app/Enums
git commit -m "feat(wiki): add wiki enums"
```

---

### Task 2: Migrations (five tables)

**Files:**
- Create: `database/migrations/2026_06_13_000001_create_wiki_pages_table.php`
- Create: `database/migrations/2026_06_13_000002_create_wiki_facts_table.php`
- Create: `database/migrations/2026_06_13_000003_create_wiki_page_revisions_table.php`
- Create: `database/migrations/2026_06_13_000004_create_wiki_links_table.php`
- Create: `database/migrations/2026_06_13_000005_create_wiki_runs_table.php`

- [ ] **Step 1: Write `create_wiki_pages_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_pages', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 10); // WikiScope
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->string('slug', 255); // path-style, e.g. runbooks/user-onboarding
            $table->string('title', 255);
            $table->string('kind', 20); // WikiPageKind
            $table->foreignId('parent_page_id')->nullable()->constrained('wiki_pages')->nullOnDelete();
            $table->longText('body_md');
            $table->json('meta')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->string('created_by_type', 10); // WikiAuthorType
            $table->timestamps();

            // NULL client_id rows (global scope) are NOT deduped by this index under
            // MySQL/MariaDB NULL semantics — WikiPageService enforces global uniqueness.
            $table->unique(['scope', 'client_id', 'slug']);
            $table->index(['client_id', 'kind']);
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('wiki_pages', function (Blueprint $table) {
                $table->fullText(['title', 'body_md'], 'wiki_pages_fulltext');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_pages');
    }
};
```

- [ ] **Step 2: Write `create_wiki_facts_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_facts', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 10); // WikiScope (denormalized for retrieval queries)
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignId('page_id')->constrained('wiki_pages')->cascadeOnDelete();
            $table->string('section_anchor', 100);
            $table->string('subject_key', 255);
            $table->text('statement');
            $table->string('status', 15)->default('unverified'); // WikiFactStatus
            $table->boolean('pinned')->default(false);
            $table->string('volatility', 10)->default('durable'); // WikiFactVolatility
            $table->string('source_type', 10); // WikiFactSource
            $table->json('source_refs');
            $table->decimal('confidence', 3, 2)->nullable();
            $table->timestamp('last_affirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('disputed_with_fact_id')->nullable()->constrained('wiki_facts')->nullOnDelete();
            $table->foreignId('superseded_by_fact_id')->nullable()->constrained('wiki_facts')->nullOnDelete();
            $table->json('dismissed_evidence')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['page_id', 'section_anchor']);
            $table->index(['client_id', 'subject_key']);
            $table->index('subject_key');
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('wiki_facts', function (Blueprint $table) {
                $table->fullText('statement', 'wiki_facts_fulltext');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_facts');
    }
};
```

- [ ] **Step 3: Write `create_wiki_page_revisions_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('wiki_pages')->cascadeOnDelete();
            $table->longText('body_md'); // snapshot of the page body AFTER this write
            $table->json('meta')->nullable();
            $table->string('author_type', 10); // WikiAuthorType
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_summary', 255);
            $table->json('source_refs')->nullable();
            $table->timestamps(); // rows are immutable; updated_at is never touched after insert

            $table->index('page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_page_revisions');
    }
};
```

- [ ] **Step 4: Write `create_wiki_links_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_page_id')->constrained('wiki_pages')->cascadeOnDelete();
            $table->foreignId('to_page_id')->nullable()->constrained('wiki_pages')->nullOnDelete(); // null = dead link
            $table->string('target_slug', 255);
            $table->string('anchor_text', 255)->nullable();
            $table->timestamps();

            $table->unique(['from_page_id', 'target_slug']);
            $table->index('to_page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_links');
    }
};
```

- [ ] **Step 5: Write `create_wiki_runs_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_type', 20); // WikiRunType
            $table->string('subject_type', 50)->nullable(); // e.g. 'ticket', 'client'
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('source_content_hash', 64)->nullable(); // spec §4.1/§5.3 idempotency key
            $table->string('status', 15)->default('pending'); // WikiRunStatus
            $table->json('stages_completed')->nullable();
            $table->json('stage_results')->nullable();
            $table->json('errors')->nullable();
            $table->json('ai_tokens_used')->nullable();
            $table->string('triggered_by', 20)->nullable(); // auto|manual|cron
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'source_content_hash'], 'wiki_runs_idempotency_unique');
            $table->index(['run_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_runs');
    }
};
```

- [ ] **Step 6: Run migrations against local dev DB**

Run: `php artisan migrate`
Expected: each of the five `create_wiki_*` migrations listed with `DONE`.

- [ ] **Step 7: Pint and commit**

```bash
./vendor/bin/pint database/migrations
git add database/migrations
git commit -m "feat(wiki): add wiki schema (pages, facts, revisions, links, runs)"
```

---

### Task 3: Models + factories

**Files:**
- Create: `app/Models/WikiPage.php`, `app/Models/WikiFact.php`, `app/Models/WikiPageRevision.php`, `app/Models/WikiLink.php`, `app/Models/WikiRun.php`
- Create: `database/factories/WikiPageFactory.php`, `database/factories/WikiFactFactory.php`
- Test: `tests/Feature/Wiki/WikiModelsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactStatus;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\Client;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_casts_and_relations(): void
    {
        $client = Client::factory()->create();
        $global = WikiPage::factory()->create([
            'slug' => 'runbooks/user-onboarding',
            'kind' => WikiPageKind::Runbook,
        ]);
        $deviation = WikiPage::factory()->forClient($client)->create([
            'slug' => 'runbooks/user-onboarding',
            'kind' => WikiPageKind::Deviation,
            'parent_page_id' => $global->id,
        ]);

        $this->assertSame(WikiScope::Global, $global->scope);
        $this->assertSame(WikiPageKind::Deviation, $deviation->kind);
        $this->assertTrue($deviation->parent->is($global));
        $this->assertTrue($global->deviations->first()->is($deviation));
        $this->assertTrue($deviation->client->is($client));
    }

    public function test_fact_casts_and_page_relation(): void
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);
        $fact = WikiFact::factory()->create([
            'client_id' => $client->id,
            'page_id' => $page->id,
            'subject_key' => 'asset:dc01:ram',
            'statement' => 'DC01 has 32 GB RAM',
        ]);

        $this->assertSame(WikiFactStatus::Confirmed, $fact->status);
        $this->assertTrue($fact->page->is($page));
        $this->assertSame(['type' => 'sync', 'id' => 'test'], $fact->source_refs[0]);
        $this->assertTrue($page->facts->first()->is($fact));
    }

    public function test_active_scope_excludes_archived_pages(): void
    {
        WikiPage::factory()->create(['slug' => 'a']);
        WikiPage::factory()->create(['slug' => 'b', 'is_archived' => true]);

        $this->assertSame(['a'], WikiPage::active()->pluck('slug')->all());
    }
}
```

Note: if `Client::factory()` does not exist yet (only `UserFactory` ships today), create `database/factories/ClientFactory.php` in this step:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Client> */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
        ];
    }
}
```

If `Client` lacks the `HasFactory` trait, add it. If `clients` has other NOT NULL columns without defaults, add minimal fake values for them here (check the `create_clients_table` migration).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WikiModelsTest`
Expected: FAIL — `Class "App\Models\WikiPage" not found`.

- [ ] **Step 3: Write the models**

`app/Models/WikiPage.php`:
```php
<?php

namespace App\Models;

use App\Enums\WikiAuthorType;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WikiPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope', 'client_id', 'slug', 'title', 'kind', 'parent_page_id',
        'body_md', 'meta', 'is_archived', 'created_by_type',
    ];

    protected function casts(): array
    {
        return [
            'scope' => WikiScope::class,
            'kind' => WikiPageKind::class,
            'created_by_type' => WikiAuthorType::class,
            'meta' => 'array',
            'is_archived' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_page_id');
    }

    public function deviations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_page_id');
    }

    public function facts(): HasMany
    {
        return $this->hasMany(WikiFact::class, 'page_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(WikiPageRevision::class, 'page_id')->latest('id');
    }

    public function linksFrom(): HasMany
    {
        return $this->hasMany(WikiLink::class, 'from_page_id');
    }

    public function backlinks(): HasMany
    {
        return $this->hasMany(WikiLink::class, 'to_page_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    public function scopeGlobalScope(Builder $query): Builder
    {
        return $query->where('scope', WikiScope::Global->value);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('scope', WikiScope::Client->value)->where('client_id', $clientId);
    }
}
```

`app/Models/WikiFact.php`:
```php
<?php

namespace App\Models;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Enums\WikiScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WikiFact extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope', 'client_id', 'page_id', 'section_anchor', 'subject_key', 'statement',
        'status', 'pinned', 'volatility', 'source_type', 'source_refs', 'confidence',
        'last_affirmed_at', 'confirmed_by', 'disputed_with_fact_id',
        'superseded_by_fact_id', 'dismissed_evidence',
    ];

    protected function casts(): array
    {
        return [
            'scope' => WikiScope::class,
            'status' => WikiFactStatus::class,
            'volatility' => WikiFactVolatility::class,
            'source_type' => WikiFactSource::class,
            'source_refs' => 'array',
            'dismissed_evidence' => 'array',
            'pinned' => 'boolean',
            'confidence' => 'decimal:2',
            'last_affirmed_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'page_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function disputedWith(): BelongsTo
    {
        return $this->belongsTo(self::class, 'disputed_with_fact_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_fact_id');
    }
}
```

`app/Models/WikiPageRevision.php`:
```php
<?php

namespace App\Models;

use App\Enums\WikiAuthorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WikiPageRevision extends Model
{
    protected $fillable = [
        'page_id', 'body_md', 'meta', 'author_type', 'author_id', 'change_summary', 'source_refs',
    ];

    protected function casts(): array
    {
        return [
            'author_type' => WikiAuthorType::class,
            'meta' => 'array',
            'source_refs' => 'array',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'page_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
```

`app/Models/WikiLink.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WikiLink extends Model
{
    protected $fillable = ['from_page_id', 'to_page_id', 'target_slug', 'anchor_text'];

    public function fromPage(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'from_page_id');
    }

    public function toPage(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'to_page_id');
    }
}
```

`app/Models/WikiRun.php`:
```php
<?php

namespace App\Models;

use App\Enums\WikiRunStatus;
use App\Enums\WikiRunType;
use Illuminate\Database\Eloquent\Model;

class WikiRun extends Model
{
    protected $fillable = [
        'run_type', 'subject_type', 'subject_id', 'source_content_hash', 'status',
        'stages_completed', 'stage_results', 'errors', 'ai_tokens_used', 'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'run_type' => WikiRunType::class,
            'status' => WikiRunStatus::class,
            'stages_completed' => 'array',
            'stage_results' => 'array',
            'errors' => 'array',
            'ai_tokens_used' => 'array',
        ];
    }
}
```

- [ ] **Step 4: Write the factories**

`database/factories/WikiPageFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\WikiAuthorType;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\WikiPage> */
class WikiPageFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->words(3, true);

        return [
            'scope' => WikiScope::Global,
            'client_id' => null,
            'slug' => Str::slug($title),
            'title' => $title,
            'kind' => WikiPageKind::Note,
            'parent_page_id' => null,
            'body_md' => "## Notes\n\nSome content.",
            'meta' => null,
            'is_archived' => false,
            'created_by_type' => WikiAuthorType::System,
        ];
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => [
            'scope' => WikiScope::Client,
            'client_id' => $client->id,
        ]);
    }
}
```

`database/factories/WikiFactFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Enums\WikiScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\WikiFact> */
class WikiFactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scope' => WikiScope::Client,
            'client_id' => null, // set by caller
            'page_id' => null,   // set by caller
            'section_anchor' => 'assets',
            'subject_key' => 'asset:'.fake()->word().':os',
            'statement' => fake()->sentence(),
            'status' => WikiFactStatus::Confirmed,
            'pinned' => false,
            'volatility' => WikiFactVolatility::Durable,
            'source_type' => WikiFactSource::Sync,
            'source_refs' => [['type' => 'sync', 'id' => 'test']],
            'confidence' => null,
            'last_affirmed_at' => now(),
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=WikiModelsTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Pint and commit**

```bash
./vendor/bin/pint app/Models database/factories tests/Feature/Wiki
git add app/Models database/factories tests/Feature/Wiki
git commit -m "feat(wiki): add wiki models and factories"
```

---

### Task 4: WikiConfig settings gate

**Files:**
- Create: `app/Support/WikiConfig.php`
- Test: `tests/Feature/Wiki/WikiConfigTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Wiki;

use App\Models\Setting;
use App\Support\WikiConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_wiki_is_disabled_by_default(): void
    {
        $this->assertFalse(WikiConfig::isEnabled());
    }

    public function test_wiki_enabled_via_setting(): void
    {
        Setting::setValue('wiki_enabled', '1');

        $this->assertTrue(WikiConfig::isEnabled());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WikiConfigTest`
Expected: FAIL — `Class "App\Support\WikiConfig" not found`.

- [ ] **Step 3: Write WikiConfig (mirror TriageConfig's static style)**

`app/Support/WikiConfig.php`:
```php
<?php

namespace App\Support;

use App\Models\Setting;

class WikiConfig
{
    // Spec §9: wiki_enabled defaults OFF. Further keys (wiki_auto_mine, budgets,
    // staleness windows) arrive with the phases that consume them — YAGNI here.
    public static function isEnabled(): bool
    {
        return (bool) Setting::getValue('wiki_enabled');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WikiConfigTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Support tests/Feature/Wiki
git add app/Support/WikiConfig.php tests/Feature/Wiki/WikiConfigTest.php
git commit -m "feat(wiki): add WikiConfig settings gate"
```

---

### Task 5: WikiLinkParser

**Files:**
- Create: `app/Services/Wiki/WikiLinkParser.php`
- Test: `tests/Unit/Wiki/WikiLinkParserTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Wiki;

use App\Services\Wiki\WikiLinkParser;
use PHPUnit\Framework\TestCase;

class WikiLinkParserTest extends TestCase
{
    public function test_parses_plain_and_labeled_wikilinks(): void
    {
        $md = 'See [[network]] and [[runbooks/user-onboarding|the onboarding runbook]].';

        $links = (new WikiLinkParser)->parse($md);

        $this->assertSame([
            ['target' => 'network', 'label' => null],
            ['target' => 'runbooks/user-onboarding', 'label' => 'the onboarding runbook'],
        ], $links);
    }

    public function test_deduplicates_targets_and_ignores_empty(): void
    {
        $md = '[[a]] then [[a|again]] and [[]]';

        $links = (new WikiLinkParser)->parse($md);

        $this->assertSame([['target' => 'a', 'label' => null]], $links);
    }

    public function test_returns_empty_for_no_links(): void
    {
        $this->assertSame([], (new WikiLinkParser)->parse('no links here'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WikiLinkParserTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the parser**

`app/Services/Wiki/WikiLinkParser.php`:
```php
<?php

namespace App\Services\Wiki;

class WikiLinkParser
{
    /**
     * Extract [[target]] / [[target|label]] links.
     *
     * @return array<int, array{target: string, label: ?string}>
     */
    public function parse(string $markdown): array
    {
        if (! preg_match_all('/\[\[([^\]\|\n]+)(?:\|([^\]\n]+))?\]\]/', $markdown, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $links = [];
        foreach ($matches as $match) {
            $target = trim($match[1]);
            if ($target === '' || isset($links[$target])) {
                continue;
            }
            $links[$target] = [
                'target' => $target,
                'label' => isset($match[2]) && trim($match[2]) !== '' ? trim($match[2]) : null,
            ];
        }

        return array_values($links);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WikiLinkParserTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Services/Wiki tests/Unit/Wiki
git add app/Services/Wiki tests/Unit/Wiki
git commit -m "feat(wiki): add wikilink parser"
```

---

### Task 6: WikiSections (split / replace / marker splice)

This helper is shared by the cascade renderer (Task 9) and the fact composer (Task 12). Anchors are `Str::slug()` of the `##` heading text.

**Files:**
- Create: `app/Services/Wiki/WikiSections.php`
- Test: `tests/Unit/Wiki/WikiSectionsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Wiki;

use App\Services\Wiki\WikiSections;
use PHPUnit\Framework\TestCase;

class WikiSectionsTest extends TestCase
{
    private string $md = "Intro line.\n\n## Assets\n\n- one\n\n## Known Issues\n\nnone\n";

    public function test_split_keys_sections_by_anchor_with_preamble(): void
    {
        $sections = WikiSections::split($this->md);

        $this->assertSame(['', 'assets', 'known-issues'], array_keys($sections));
        $this->assertSame('Assets', $sections['assets']['heading']);
        $this->assertStringContainsString('- one', $sections['assets']['content']);
        $this->assertStringContainsString('Intro line.', $sections['']['content']);
    }

    public function test_replace_swaps_one_section_body_and_keeps_the_rest(): void
    {
        $out = WikiSections::replace($this->md, 'assets', "- two\n");

        $this->assertStringContainsString("## Assets\n\n- two", $out);
        $this->assertStringContainsString('Intro line.', $out);
        $this->assertStringContainsString("## Known Issues\n\nnone", $out);
        $this->assertStringNotContainsString('- one', $out);
    }

    public function test_replace_between_markers_only_touches_marked_region(): void
    {
        $md = "## Assets\n\nkeep this\n\n<!-- wiki:facts:assets:start -->\nold\n<!-- wiki:facts:assets:end -->\n\ntail\n";

        $out = WikiSections::spliceMarkers($md, 'assets', "new\n");

        $this->assertStringContainsString('keep this', $out);
        $this->assertStringContainsString("<!-- wiki:facts:assets:start -->\nnew\n<!-- wiki:facts:assets:end -->", $out);
        $this->assertStringContainsString('tail', $out);
        $this->assertStringNotContainsString("old", $out);
    }

    public function test_splice_appends_markers_inside_section_when_missing(): void
    {
        $out = WikiSections::spliceMarkers($this->md, 'assets', "facts here\n");

        $this->assertStringContainsString("<!-- wiki:facts:assets:start -->\nfacts here\n<!-- wiki:facts:assets:end -->", $out);
        $this->assertStringContainsString('- one', $out); // existing section content kept
    }

    public function test_anchor_for_slugifies_heading(): void
    {
        $this->assertSame('known-issues', WikiSections::anchorFor('Known Issues'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WikiSectionsTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the helper**

`app/Services/Wiki/WikiSections.php`:
```php
<?php

namespace App\Services\Wiki;

use Illuminate\Support\Str;

class WikiSections
{
    /**
     * Split markdown into ## sections. Key '' holds the preamble before the first ##.
     *
     * @return array<string, array{heading: string, content: string}>
     */
    public static function split(string $markdown): array
    {
        $lines = explode("\n", $markdown);
        $sections = ['' => ['heading' => '', 'content' => '']];
        $current = '';

        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+)$/', $line, $m)) {
                $current = self::anchorFor($m[1]);
                $sections[$current] = ['heading' => trim($m[1]), 'content' => ''];

                continue;
            }
            $sections[$current]['content'] .= $line."\n";
        }

        return $sections; // '' preamble key is always present and first
    }

    /** Rebuild markdown from split() output. */
    public static function join(array $sections): string
    {
        $out = '';
        foreach ($sections as $anchor => $section) {
            if ($anchor === '') {
                $out .= $section['content'];

                continue;
            }
            $out .= '## '.$section['heading']."\n".$section['content'];
        }

        return $out;
    }

    /** Replace the body of one section (heading kept), returning the new document. */
    public static function replace(string $markdown, string $anchor, string $newContent): string
    {
        $sections = self::split($markdown);
        if (! isset($sections[$anchor])) {
            return $markdown;
        }
        $sections[$anchor]['content'] = "\n".rtrim($newContent)."\n\n";

        return self::join($sections);
    }

    /**
     * Replace content between <!-- wiki:facts:{anchor}:start/end --> markers.
     * If the markers are missing, append them (with content) at the end of that section.
     */
    public static function spliceMarkers(string $markdown, string $anchor, string $content): string
    {
        $start = "<!-- wiki:facts:{$anchor}:start -->";
        $end = "<!-- wiki:facts:{$anchor}:end -->";
        $block = $start."\n".rtrim($content)."\n".$end;

        if (str_contains($markdown, $start) && str_contains($markdown, $end)) {
            $pattern = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/s';

            return preg_replace($pattern, $block, $markdown, 1);
        }

        $sections = self::split($markdown);
        if (! isset($sections[$anchor])) {
            // No such section: append a new one named after the anchor.
            return rtrim($markdown)."\n\n## ".Str::headline($anchor)."\n\n".$block."\n";
        }
        $sections[$anchor]['content'] = rtrim($sections[$anchor]['content'])."\n\n".$block."\n\n";

        return self::join($sections);
    }

    public static function anchorFor(string $heading): string
    {
        return Str::slug($heading);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WikiSectionsTest`
Expected: PASS (5 tests). If the preamble assertion fails on exact key order, adjust the test expectation order only after confirming split() emits preamble first — the contract is: preamble key `''` always present and first.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Services/Wiki tests/Unit/Wiki
git add app/Services/Wiki/WikiSections.php tests/Unit/Wiki/WikiSectionsTest.php
git commit -m "feat(wiki): add markdown section splitter/splicer"
```

---

### Task 7: WikiLinkResolver + WikiMarkdown rendering

Wikilinks become normal markdown links *before* rendering, so the existing `MarkdownRenderer` (Str::markdown + HtmlSanitizer) stays the single render/sanitize path. Unresolved targets render as plain text (no link) and are recorded as dead links by Task 8's rebuild.

**Files:**
- Create: `app/Services/Wiki/WikiLinkResolver.php`, `app/Services/Wiki/WikiMarkdown.php`
- Test: `tests/Feature/Wiki/WikiMarkdownTest.php` (DB-backed: resolver queries pages)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\WikiPage;
use App\Services\Wiki\WikiLinkResolver;
use App\Services\Wiki\WikiMarkdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiMarkdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_prefers_client_scope_then_global(): void
    {
        $client = Client::factory()->create();
        $global = WikiPage::factory()->create(['slug' => 'network']);
        $clientPage = WikiPage::factory()->forClient($client)->create(['slug' => 'network']);

        $resolver = app(WikiLinkResolver::class);

        $this->assertTrue($resolver->resolve('network', $client->id)->is($clientPage));
        $this->assertTrue($resolver->resolve('network', null)->is($global));
        $this->assertNull($resolver->resolve('missing', $client->id));
    }

    public function test_render_converts_wikilinks_to_anchors_and_sanitizes(): void
    {
        $client = Client::factory()->create();
        WikiPage::factory()->forClient($client)->create(['slug' => 'network', 'title' => 'Network']);
        $page = WikiPage::factory()->forClient($client)->create([
            'slug' => 'overview',
            'body_md' => "See [[network|the network]].\n\n<script>alert(1)</script>",
        ]);

        $html = app(WikiMarkdown::class)->render($page);

        $this->assertStringContainsString('the network</a>', $html);
        $this->assertStringContainsString(route('clients.wiki.show', [$client, 'network']), $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_unresolved_wikilink_renders_as_plain_text(): void
    {
        $page = WikiPage::factory()->create(['body_md' => 'See [[nowhere|missing page]].']);

        $html = app(WikiMarkdown::class)->render($page);

        $this->assertStringContainsString('missing page', $html);
        $this->assertStringNotContainsString('<a', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WikiMarkdownTest`
Expected: FAIL — `WikiLinkResolver` not found (and `clients.wiki.show` route missing — the route assertion will pass only after Task 13; for now stub the named route in this task's Step 3 routes snippet below, view comes later).

- [ ] **Step 3: Write resolver + renderer, and stub the two show routes**

`app/Services/Wiki/WikiLinkResolver.php`:
```php
<?php

namespace App\Services\Wiki;

use App\Models\WikiPage;

class WikiLinkResolver
{
    public function resolve(string $slug, ?int $clientId): ?WikiPage
    {
        if ($clientId !== null) {
            $clientPage = WikiPage::active()->forClient($clientId)->where('slug', $slug)->first();
            if ($clientPage) {
                return $clientPage;
            }
        }

        return WikiPage::active()->globalScope()->where('slug', $slug)->first();
    }
}
```

`app/Services/Wiki/WikiMarkdown.php`:
```php
<?php

namespace App\Services\Wiki;

use App\Helpers\MarkdownRenderer;
use App\Models\WikiPage;

class WikiMarkdown
{
    public function __construct(
        private readonly WikiLinkParser $parser,
        private readonly WikiLinkResolver $resolver,
    ) {}

    /** Render a page body (or an explicit markdown string in the page's scope) to sanitized HTML. */
    public function render(WikiPage $page, ?string $markdown = null): string
    {
        $markdown ??= $page->body_md;
        $clientId = $page->client_id;

        foreach ($this->parser->parse($markdown) as $link) {
            $target = $this->resolver->resolve($link['target'], $clientId);
            $label = $link['label'] ?? ($target?->title ?? $link['target']);
            $replacement = $target
                ? '['.$label.']('.$this->urlFor($target).')'
                : $label; // unresolved: plain text; wiki_links records the dead link

            // Replace both [[t]] and [[t|label]] occurrences of this target.
            $markdown = preg_replace(
                '/\[\[\s*'.preg_quote($link['target'], '/').'\s*(\|[^\]]*)?\]\]/',
                $replacement,
                $markdown
            );
        }

        return MarkdownRenderer::render($markdown) ?? '';
    }

    private function urlFor(WikiPage $page): string
    {
        return $page->client_id
            ? route('clients.wiki.show', [$page->client_id, $page->slug])
            : route('wiki.show', $page->slug);
    }
}
```

Add to `routes/web.php` inside the existing `Route::middleware('auth')->group(...)` (full wiki group lands in Task 13; these two named routes are needed now and Task 13 extends this block):

```php
use App\Http\Controllers\Web\WikiController;

Route::get('/wiki/{slug}', [WikiController::class, 'show'])
    ->where('slug', '.*')->name('wiki.show');
Route::get('/clients/{client}/wiki/{slug}', [WikiController::class, 'clientShow'])
    ->where('slug', '.*')->name('clients.wiki.show');
```

And a minimal controller so routes resolve (filled out in Task 13):

`app/Http/Controllers/Web/WikiController.php`:
```php
<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;

class WikiController extends Controller
{
    public function show(string $slug)
    {
        abort(404); // implemented in Task 13
    }

    public function clientShow(Client $client, string $slug)
    {
        abort(404); // implemented in Task 13
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WikiMarkdownTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app tests routes
git add app/Services/Wiki app/Http/Controllers/Web/WikiController.php routes/web.php tests/Feature/Wiki/WikiMarkdownTest.php
git commit -m "feat(wiki): scoped wikilink resolution and sanitized markdown rendering"
```

---

### Task 8: WikiPageService (transactional writes, revisions, link rebuild, deviation validation)

**Files:**
- Create: `app/Services/Wiki/WikiPageService.php`
- Test: `tests/Feature/Wiki/WikiPageServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiAuthorType;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\Client;
use App\Models\WikiLink;
use App\Models\WikiPage;
use App\Services\Wiki\WikiPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiPageServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): WikiPageService
    {
        return app(WikiPageService::class);
    }

    public function test_create_writes_initial_revision_and_links(): void
    {
        $target = WikiPage::factory()->create(['slug' => 'network']);

        $page = $this->service()->create([
            'scope' => WikiScope::Global,
            'slug' => 'overview-page',
            'title' => 'Overview',
            'kind' => WikiPageKind::Note,
            'body_md' => 'Linked: [[network]] and [[missing-page]].',
        ], WikiAuthorType::Human, authorId: null);

        $this->assertCount(1, $page->revisions);
        $this->assertSame('Created', $page->revisions->first()->change_summary);

        $links = WikiLink::where('from_page_id', $page->id)->get()->keyBy('target_slug');
        $this->assertTrue($links['network']->to_page_id === $target->id);
        $this->assertNull($links['missing-page']->to_page_id); // dead link recorded
    }

    public function test_update_body_writes_revision_and_rebuilds_links(): void
    {
        $page = WikiPage::factory()->create(['body_md' => '[[a]]']);
        WikiPage::factory()->create(['slug' => 'b', 'title' => 'B']);

        $this->service()->updateBody($page, 'now [[b]]', WikiAuthorType::Human, null, 'Edited');

        $this->assertSame('now [[b]]', $page->fresh()->body_md);
        $this->assertSame(['b'], WikiLink::where('from_page_id', $page->id)->pluck('target_slug')->all());
        $this->assertSame('Edited', $page->fresh()->revisions->first()->change_summary);
    }

    public function test_create_rejects_duplicate_global_slug(): void
    {
        WikiPage::factory()->create(['slug' => 'dup']);

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->create([
            'scope' => WikiScope::Global,
            'slug' => 'dup',
            'title' => 'Dup',
            'kind' => WikiPageKind::Note,
            'body_md' => '',
        ], WikiAuthorType::Human, null);
    }

    public function test_deviation_requires_global_root_parent(): void
    {
        $client = Client::factory()->create();
        $globalRunbook = WikiPage::factory()->create([
            'slug' => 'runbooks/onboarding', 'kind' => WikiPageKind::Runbook,
        ]);

        // Valid: deviation under a global, parentless page.
        $deviation = $this->service()->create([
            'scope' => WikiScope::Client,
            'client_id' => $client->id,
            'slug' => 'runbooks/onboarding',
            'title' => 'Onboarding (deviation)',
            'kind' => WikiPageKind::Deviation,
            'parent_page_id' => $globalRunbook->id,
            'body_md' => '## Steps\n\nExcept step 3.',
        ], WikiAuthorType::Human, null);
        $this->assertTrue($deviation->parent->is($globalRunbook));

        // Invalid: deviation chained under a deviation (depth > 1, spec §4.5).
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->create([
            'scope' => WikiScope::Client,
            'client_id' => $client->id,
            'slug' => 'runbooks/onboarding-2',
            'title' => 'Bad chain',
            'kind' => WikiPageKind::Deviation,
            'parent_page_id' => $deviation->id,
            'body_md' => '',
        ], WikiAuthorType::Human, null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WikiPageServiceTest`
Expected: FAIL — `WikiPageService` not found.

- [ ] **Step 3: Write the service**

`app/Services/Wiki/WikiPageService.php`:
```php
<?php

namespace App\Services\Wiki;

use App\Enums\WikiAuthorType;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\WikiLink;
use App\Models\WikiPage;
use Illuminate\Support\Facades\DB;

class WikiPageService
{
    public function __construct(
        private readonly WikiLinkParser $parser,
        private readonly WikiLinkResolver $resolver,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, WikiAuthorType $author, ?int $authorId = null, string $changeSummary = 'Created', ?array $sourceRefs = null): WikiPage
    {
        $scope = $attributes['scope'] instanceof WikiScope ? $attributes['scope'] : WikiScope::from($attributes['scope']);
        $clientId = $attributes['client_id'] ?? null;

        // The DB unique index does not dedupe NULL client_id (global) rows — enforce here.
        $exists = WikiPage::query()
            ->where('scope', $scope->value)
            ->where('client_id', $clientId)
            ->where('slug', $attributes['slug'])
            ->exists();
        if ($exists) {
            throw new \InvalidArgumentException("Wiki page '{$attributes['slug']}' already exists in this scope.");
        }

        $this->validateDeviation($attributes, $scope);

        return DB::transaction(function () use ($attributes, $author, $authorId, $changeSummary, $sourceRefs) {
            $page = WikiPage::create([
                ...$attributes,
                'created_by_type' => $author,
            ]);
            $this->writeRevision($page, $author, $authorId, $changeSummary, $sourceRefs);
            $this->rebuildLinks($page);

            return $page;
        });
    }

    public function updateBody(WikiPage $page, string $bodyMd, WikiAuthorType $author, ?int $authorId, string $changeSummary, ?array $sourceRefs = null): WikiPage
    {
        return DB::transaction(function () use ($page, $bodyMd, $author, $authorId, $changeSummary, $sourceRefs) {
            $page->update(['body_md' => $bodyMd]);
            $this->writeRevision($page, $author, $authorId, $changeSummary, $sourceRefs);
            $this->rebuildLinks($page); // spec §5.2: synchronous, same transaction

            return $page->refresh();
        });
    }

    public function archive(WikiPage $page, WikiAuthorType $author, ?int $authorId): void
    {
        DB::transaction(function () use ($page, $author, $authorId) {
            $page->update(['is_archived' => true]);
            $this->writeRevision($page, $author, $authorId, 'Archived');
        });
    }

    public function rebuildLinks(WikiPage $page): void
    {
        WikiLink::where('from_page_id', $page->id)->delete();

        foreach ($this->parser->parse($page->body_md) as $link) {
            $target = $this->resolver->resolve($link['target'], $page->client_id);
            WikiLink::create([
                'from_page_id' => $page->id,
                'to_page_id' => $target?->id,
                'target_slug' => $link['target'],
                'anchor_text' => $link['label'],
            ]);
        }
    }

    private function writeRevision(WikiPage $page, WikiAuthorType $author, ?int $authorId, string $changeSummary, ?array $sourceRefs = null): void
    {
        $page->revisions()->create([
            'body_md' => $page->body_md,
            'meta' => $page->meta,
            'author_type' => $author,
            'author_id' => $authorId,
            'change_summary' => $changeSummary,
            'source_refs' => $sourceRefs,
        ]);
    }

    /** Spec §4.5: deviations are client-scoped, parent must be a global page with no parent. */
    private function validateDeviation(array $attributes, WikiScope $scope): void
    {
        $kind = $attributes['kind'] instanceof WikiPageKind ? $attributes['kind'] : WikiPageKind::from($attributes['kind']);
        if ($kind !== WikiPageKind::Deviation) {
            return;
        }

        $parent = isset($attributes['parent_page_id']) ? WikiPage::find($attributes['parent_page_id']) : null;
        if ($scope !== WikiScope::Client || ($attributes['client_id'] ?? null) === null) {
            throw new \InvalidArgumentException('Deviation pages must be client-scoped.');
        }
        if (! $parent || $parent->scope !== WikiScope::Global || $parent->parent_page_id !== null) {
            throw new \InvalidArgumentException('Deviation parent must be a global page with no parent (depth 1).');
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WikiPageServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Services/Wiki tests/Feature/Wiki
git add app/Services/Wiki/WikiPageService.php tests/Feature/Wiki/WikiPageServiceTest.php
git commit -m "feat(wiki): transactional page writes with revisions, link rebuild, deviation validation"
```

---

### Task 9: WikiCascadeService (section-level merged view)

Spec §4.5: unit of override is the `##` section, joined by anchor. Matching anchor → deviation section replaces the global one (marked). New anchors → appended under a marked deviations area. Returned anchors let the view mark deviation content (§8.1 advisory: left-border + label comes later; v1 marks with an italic label line, which the renderer emits as part of the merged markdown).

**Files:**
- Create: `app/Services/Wiki/WikiCascadeService.php`
- Test: `tests/Feature/Wiki/WikiCascadeServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiPageKind;
use App\Models\Client;
use App\Models\WikiPage;
use App\Services\Wiki\WikiCascadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiCascadeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_merged_view_replaces_matching_sections_and_appends_new_ones(): void
    {
        $client = Client::factory()->create();
        $global = WikiPage::factory()->create([
            'slug' => 'runbooks/onboarding',
            'kind' => WikiPageKind::Runbook,
            'body_md' => "## Accounts\n\nCreate M365 user.\n\n## Hardware\n\nStandard laptop.\n",
        ]);
        WikiPage::factory()->forClient($client)->create([
            'slug' => 'runbooks/onboarding',
            'kind' => WikiPageKind::Deviation,
            'parent_page_id' => $global->id,
            'body_md' => "## Hardware\n\nMac only — no Windows laptops.\n\n## VPN\n\nAlways issue FortiClient.\n",
        ]);

        $merged = app(WikiCascadeService::class)->mergedView($global, $client->id);

        $this->assertStringContainsString('Create M365 user.', $merged['body_md']);          // untouched global section
        $this->assertStringContainsString('Mac only — no Windows laptops.', $merged['body_md']); // replaced section
        $this->assertStringNotContainsString('Standard laptop.', $merged['body_md']);        // global content overridden
        $this->assertStringContainsString('Always issue FortiClient.', $merged['body_md']);  // appended new section
        $this->assertStringContainsString('*Client deviation*', $merged['body_md']);         // visible marker
        $this->assertEqualsCanonicalizing(['hardware', 'vpn'], $merged['deviation_anchors']);
    }

    public function test_merged_view_without_deviation_returns_global_body(): void
    {
        $client = Client::factory()->create();
        $global = WikiPage::factory()->create([
            'slug' => 'runbooks/offboarding',
            'kind' => WikiPageKind::Runbook,
            'body_md' => "## Steps\n\nDisable accounts.\n",
        ]);

        $merged = app(WikiCascadeService::class)->mergedView($global, $client->id);

        $this->assertSame($global->body_md, $merged['body_md']);
        $this->assertSame([], $merged['deviation_anchors']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WikiCascadeServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the service**

`app/Services/Wiki/WikiCascadeService.php`:
```php
<?php

namespace App\Services\Wiki;

use App\Enums\WikiPageKind;
use App\Models\WikiPage;

class WikiCascadeService
{
    /**
     * Spec §4.5 merged view, most specific wins, section-level.
     *
     * @return array{body_md: string, deviation_anchors: array<int, string>}
     */
    public function mergedView(WikiPage $globalPage, int $clientId): array
    {
        $deviation = WikiPage::active()
            ->forClient($clientId)
            ->where('kind', WikiPageKind::Deviation->value)
            ->where('parent_page_id', $globalPage->id)
            ->first();

        if (! $deviation) {
            return ['body_md' => $globalPage->body_md, 'deviation_anchors' => []];
        }

        $globalSections = WikiSections::split($globalPage->body_md);
        $deviationSections = WikiSections::split($deviation->body_md);
        unset($deviationSections['']); // deviation preamble is ignored; deltas live in sections

        $marker = "*Client deviation* — overrides the standard runbook.\n\n";
        $anchors = [];
        $appendix = [];

        foreach ($deviationSections as $anchor => $section) {
            $anchors[] = $anchor;
            if (isset($globalSections[$anchor])) {
                $globalSections[$anchor]['content'] = "\n".$marker.trim($section['content'])."\n\n";
            } else {
                $appendix[$anchor] = $section;
            }
        }

        $merged = WikiSections::join($globalSections);
        foreach ($appendix as $section) {
            $merged = rtrim($merged)."\n\n## ".$section['heading']."\n\n".$marker.trim($section['content'])."\n";
        }

        return ['body_md' => $merged, 'deviation_anchors' => $anchors];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WikiCascadeServiceTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Services/Wiki tests/Feature/Wiki
git add app/Services/Wiki/WikiCascadeService.php tests/Feature/Wiki/WikiCascadeServiceTest.php
git commit -m "feat(wiki): section-level cascade merge for runbook deviations"
```

---

### Task 10: WikiSkeletonService (idempotent per-client seeding)

Spec §4.6: skeleton seeded per client on first activation; managed-fact sections carry splice markers from birth so the composer has a home.

**Files:**
- Create: `app/Services/Wiki/WikiSkeletonService.php`
- Test: `tests/Feature/Wiki/WikiSkeletonServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\WikiPage;
use App\Services\Wiki\WikiSkeletonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiSkeletonServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_standard_pages_once(): void
    {
        $client = Client::factory()->create();

        app(WikiSkeletonService::class)->ensureForClient($client);
        app(WikiSkeletonService::class)->ensureForClient($client); // idempotent

        $slugs = WikiPage::forClient($client->id)->pluck('slug')->sort()->values()->all();
        $this->assertSame([
            'applications', 'backup', 'history', 'infrastructure', 'known-issues',
            'm365', 'network', 'notes', 'overview', 'security',
        ], $slugs);

        $infra = WikiPage::forClient($client->id)->where('slug', 'infrastructure')->first();
        $this->assertStringContainsString('<!-- wiki:facts:assets:start -->', $infra->body_md);
        $this->assertCount(1, $infra->revisions); // second ensure did not rewrite
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WikiSkeletonServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the service**

`app/Services/Wiki/WikiSkeletonService.php`:
```php
<?php

namespace App\Services\Wiki;

use App\Enums\WikiAuthorType;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\Client;
use App\Models\WikiPage;

class WikiSkeletonService
{
    public function __construct(private readonly WikiPageService $pages) {}

    /** @return array<string, array{title: string, kind: WikiPageKind, body: string}> */
    public static function blueprint(): array
    {
        $factsBlock = fn (string $anchor) => "<!-- wiki:facts:{$anchor}:start -->\n_No facts recorded yet._\n<!-- wiki:facts:{$anchor}:end -->";

        return [
            'overview' => ['title' => 'Overview', 'kind' => WikiPageKind::Overview,
                'body' => "_Hot summary — maintained automatically once mining is enabled._\n"],
            'network' => ['title' => 'Network', 'kind' => WikiPageKind::Environment,
                'body' => "## Topology\n\n## Equipment\n\n"],
            'infrastructure' => ['title' => 'Infrastructure', 'kind' => WikiPageKind::Environment,
                'body' => "## Assets\n\n".$factsBlock('assets')."\n"],
            'm365' => ['title' => 'Microsoft 365', 'kind' => WikiPageKind::Environment,
                'body' => "## Security posture\n\n".$factsBlock('security-posture')."\n"],
            'security' => ['title' => 'Security stack', 'kind' => WikiPageKind::Environment,
                'body' => "## Tooling\n\n"],
            'backup' => ['title' => 'Backup', 'kind' => WikiPageKind::Environment,
                'body' => "## Coverage\n\n"],
            'applications' => ['title' => 'Applications', 'kind' => WikiPageKind::Environment,
                'body' => "## Line of business\n\n"],
            'known-issues' => ['title' => 'Known issues', 'kind' => WikiPageKind::Environment,
                'body' => "## Active\n\n## Resolved\n\n"],
            'history' => ['title' => 'History', 'kind' => WikiPageKind::Environment,
                'body' => "## Decisions\n\n"],
            'notes' => ['title' => 'Notes', 'kind' => WikiPageKind::Note,
                'body' => "_Free-form staff notes. The AI annotates but never rewrites this page._\n"],
        ];
    }

    public function ensureForClient(Client $client): void
    {
        $existing = WikiPage::forClient($client->id)->pluck('slug')->all();

        foreach (self::blueprint() as $slug => $def) {
            if (in_array($slug, $existing, true)) {
                continue;
            }
            $this->pages->create([
                'scope' => WikiScope::Client,
                'client_id' => $client->id,
                'slug' => $slug,
                'title' => $def['title'],
                'kind' => $def['kind'],
                'body_md' => $def['body'],
            ], WikiAuthorType::System, null, 'Skeleton seeded');
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WikiSkeletonServiceTest`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Services/Wiki tests/Feature/Wiki
git add app/Services/Wiki/WikiSkeletonService.php tests/Feature/Wiki/WikiSkeletonServiceTest.php
git commit -m "feat(wiki): idempotent per-client skeleton seeding"
```

---

### Task 11: `wiki:import-site-notes` command

Spec §4.6: existing `clients.site_notes` content seeds each client's `notes` page. Idempotent via a meta flag; `site_notes` columns are left untouched (deprecation is a post-Phase-5 decision, spec §6).

**Files:**
- Create: `app/Console/Commands/WikiImportSiteNotes.php`
- Test: `tests/Feature/Wiki/WikiImportSiteNotesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiImportSiteNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_site_notes_into_notes_page_once(): void
    {
        $client = Client::factory()->create([
            'site_notes' => "VPN is FortiClient.\n\nServer room code 4521 is in Keeper.",
        ]);
        $empty = Client::factory()->create(['site_notes' => null]);

        $this->artisan('wiki:import-site-notes')->assertSuccessful();

        $notes = WikiPage::forClient($client->id)->where('slug', 'notes')->first();
        $this->assertStringContainsString('VPN is FortiClient.', $notes->body_md);
        $this->assertNotNull($notes->meta['site_notes_imported_at'] ?? null);

        // Client without notes: skeleton may exist or not, but no import marker.
        $emptyNotes = WikiPage::forClient($empty->id)->where('slug', 'notes')->first();
        $this->assertNull($emptyNotes?->meta['site_notes_imported_at'] ?? null);

        // Second run does not duplicate or rewrite.
        $revisionCount = $notes->revisions()->count();
        $this->artisan('wiki:import-site-notes')->assertSuccessful();
        $this->assertSame($revisionCount, $notes->fresh()->revisions()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WikiImportSiteNotesTest`
Expected: FAIL — command not found.

- [ ] **Step 3: Write the command**

`app/Console/Commands/WikiImportSiteNotes.php`:
```php
<?php

namespace App\Console\Commands;

use App\Enums\WikiAuthorType;
use App\Models\Client;
use App\Models\WikiPage;
use App\Services\Wiki\WikiPageService;
use App\Services\Wiki\WikiSkeletonService;
use Illuminate\Console\Command;

class WikiImportSiteNotes extends Command
{
    protected $signature = 'wiki:import-site-notes';

    protected $description = 'Seed each client\'s wiki notes page from clients.site_notes (idempotent)';

    public function handle(WikiSkeletonService $skeleton, WikiPageService $pages): int
    {
        $imported = 0;

        Client::query()->whereNotNull('site_notes')->where('site_notes', '!=', '')
            ->each(function (Client $client) use ($skeleton, $pages, &$imported) {
                $skeleton->ensureForClient($client);

                $notes = WikiPage::forClient($client->id)->where('slug', 'notes')->first();
                if (! $notes || ($notes->meta['site_notes_imported_at'] ?? null)) {
                    return;
                }

                $body = rtrim($notes->body_md)."\n\n## Imported site notes\n\n".trim($client->site_notes)."\n";
                $pages->updateBody($notes, $body, WikiAuthorType::System, null, 'Imported from clients.site_notes');
                $notes->update(['meta' => array_merge($notes->meta ?? [], [
                    'site_notes_imported_at' => now()->toIso8601String(),
                ])]);
                $imported++;
            });

        $this->info("Imported site notes for {$imported} client(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WikiImportSiteNotesTest`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Console tests/Feature/Wiki
git add app/Console/Commands/WikiImportSiteNotes.php tests/Feature/Wiki/WikiImportSiteNotesTest.php
git commit -m "feat(wiki): site_notes import command"
```

---

### Task 12: WikiComposerService (deterministic fact→section composition)

Spec §5.2 step 5 (template-first): fact-backed sections render deterministically between splice markers. Writes only when content actually changed (no revision spam per sync). No AI calls.

**Files:**
- Create: `app/Services/Wiki/WikiComposerService.php`
- Test: `tests/Feature/Wiki/WikiComposerServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactStatus;
use App\Models\Client;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Wiki\WikiComposerService;
use App\Services\Wiki\WikiSkeletonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiComposerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_composes_active_facts_into_marked_section(): void
    {
        $client = Client::factory()->create();
        app(WikiSkeletonService::class)->ensureForClient($client);
        $page = WikiPage::forClient($client->id)->where('slug', 'infrastructure')->first();

        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id, 'section_anchor' => 'assets',
            'subject_key' => 'asset:dc01:os', 'statement' => 'DC01 runs Windows Server 2022',
        ]);
        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id, 'section_anchor' => 'assets',
            'subject_key' => 'asset:dc01:ram', 'statement' => 'DC01 has 32 GB RAM',
            'status' => WikiFactStatus::Retired, // must NOT render
        ]);

        $changed = app(WikiComposerService::class)->composeSection($page, 'assets');

        $body = $page->fresh()->body_md;
        $this->assertTrue($changed);
        $this->assertStringContainsString('- DC01 runs Windows Server 2022', $body);
        $this->assertStringNotContainsString('32 GB RAM', $body);
        $this->assertStringNotContainsString('_No facts recorded yet._', $body);
    }

    public function test_no_write_when_content_unchanged(): void
    {
        $client = Client::factory()->create();
        app(WikiSkeletonService::class)->ensureForClient($client);
        $page = WikiPage::forClient($client->id)->where('slug', 'infrastructure')->first();
        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id, 'section_anchor' => 'assets',
            'subject_key' => 'asset:dc01:os', 'statement' => 'DC01 runs Windows Server 2022',
        ]);

        app(WikiComposerService::class)->composeSection($page, 'assets');
        $revisions = $page->fresh()->revisions()->count();

        $changedAgain = app(WikiComposerService::class)->composeSection($page->fresh(), 'assets');

        $this->assertFalse($changedAgain);
        $this->assertSame($revisions, $page->fresh()->revisions()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WikiComposerServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the service**

`app/Services/Wiki/WikiComposerService.php`:
```php
<?php

namespace App\Services\Wiki;

use App\Enums\WikiAuthorType;
use App\Enums\WikiFactStatus;
use App\Models\WikiPage;

class WikiComposerService
{
    public function __construct(private readonly WikiPageService $pages) {}

    /** Recompose one marked section from its facts. Returns true when the page changed. */
    public function composeSection(WikiPage $page, string $anchor): bool
    {
        $facts = $page->facts()
            ->where('section_anchor', $anchor)
            ->whereNot('status', WikiFactStatus::Retired->value)
            ->orderBy('subject_key')
            ->get();

        $content = $facts->isEmpty()
            ? "_No facts recorded yet._"
            : $facts->map(fn ($fact) => '- '.$fact->statement)->implode("\n");

        $newBody = WikiSections::spliceMarkers($page->body_md, $anchor, $content);
        if ($newBody === $page->body_md) {
            return false;
        }

        $this->pages->updateBody(
            $page, $newBody, WikiAuthorType::System, null,
            "Recomposed '{$anchor}' from facts",
        );

        return true;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WikiComposerServiceTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Services/Wiki tests/Feature/Wiki
git add app/Services/Wiki/WikiComposerService.php tests/Feature/Wiki/WikiComposerServiceTest.php
git commit -m "feat(wiki): deterministic fact-to-section composer"
```

---

### Task 13: WikiFactService (sync-fact upsert: reaffirm / supersede / insert, row-locked)

Spec §4.1 merge concurrency: `SELECT … FOR UPDATE` on `(client_id, subject_key)` inside one transaction. Sync is ground truth for its own facts (§5.1): same statement → reaffirm; changed statement from the same sync source → supersede (old retired, chained via `superseded_by_fact_id`). Cross-source disputes are Phase 3.

**Files:**
- Create: `app/Services/Wiki/WikiFactService.php`
- Test: `tests/Feature/Wiki/WikiFactServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Models\Client;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Wiki\WikiFactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiFactServiceTest extends TestCase
{
    use RefreshDatabase;

    private function setUpPage(): array
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);

        return [$client, $page];
    }

    public function test_inserts_new_sync_fact_as_confirmed(): void
    {
        [$client, $page] = $this->setUpPage();

        $fact = app(WikiFactService::class)->upsertSyncFact(
            $page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']],
        );

        $this->assertSame(WikiFactStatus::Confirmed, $fact->status);
        $this->assertSame($client->id, $fact->client_id);
        $this->assertNotNull($fact->last_affirmed_at);
    }

    public function test_reaffirms_unchanged_statement_without_new_row(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $first = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);
        $this->travel(1)->days();
        $second = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, WikiFact::count());
        $this->assertTrue($second->last_affirmed_at->gt($first->last_affirmed_at));
    }

    public function test_supersedes_changed_statement(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $old = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 16 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);
        $new = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);

        $old->refresh();
        $this->assertSame(WikiFactStatus::Retired, $old->status);
        $this->assertSame($new->id, $old->superseded_by_fact_id);
        $this->assertSame(WikiFactStatus::Confirmed, $new->status);
        $this->assertSame(2, WikiFact::count());
    }

    public function test_pinned_fact_is_never_auto_superseded(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $pinned = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 16 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);
        $pinned->update(['pinned' => true]);

        $result = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);

        // Spec §5.2: pinned facts are never auto-superseded. The sync writer leaves the
        // pinned fact in place untouched (the Phase 3 addendum path will challenge it).
        $this->assertTrue($result->is($pinned->fresh()));
        $this->assertSame(WikiFactStatus::Confirmed, $pinned->fresh()->status);
        $this->assertSame('DC01 has 16 GB RAM', $pinned->fresh()->statement);
        $this->assertSame(1, WikiFact::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WikiFactServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the service**

`app/Services/Wiki/WikiFactService.php`:
```php
<?php

namespace App\Services\Wiki;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Enums\WikiScope;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Support\Facades\DB;

class WikiFactService
{
    /**
     * Upsert a deterministic sync-sourced fact (spec §5.1 trigger 1).
     * Row-locked on (client_id, subject_key) per spec §4.1 merge concurrency.
     */
    public function upsertSyncFact(
        WikiPage $page,
        string $anchor,
        string $subjectKey,
        string $statement,
        WikiFactVolatility $volatility,
        array $sourceRefs,
    ): WikiFact {
        $subjectKey = self::normalizeSubjectKey($subjectKey);

        return DB::transaction(function () use ($page, $anchor, $subjectKey, $statement, $volatility, $sourceRefs) {
            $existing = WikiFact::query()
                ->where('client_id', $page->client_id)
                ->where('subject_key', $subjectKey)
                ->whereNot('status', WikiFactStatus::Retired->value)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($existing?->pinned) {
                return $existing; // never auto-supersede a pinned fact (spec §5.2)
            }

            if ($existing && trim($existing->statement) === trim($statement)) {
                $existing->update(['last_affirmed_at' => now()]);

                return $existing;
            }

            $new = WikiFact::create([
                'scope' => $page->client_id ? WikiScope::Client : WikiScope::Global,
                'client_id' => $page->client_id,
                'page_id' => $page->id,
                'section_anchor' => $anchor,
                'subject_key' => $subjectKey,
                'statement' => $statement,
                'status' => WikiFactStatus::Confirmed,
                'volatility' => $volatility,
                'source_type' => WikiFactSource::Sync,
                'source_refs' => $sourceRefs,
                'last_affirmed_at' => now(),
            ]);

            if ($existing) {
                $existing->update([
                    'status' => WikiFactStatus::Retired,
                    'superseded_by_fact_id' => $new->id,
                ]);
            }

            return $new;
        });
    }

    /** Spec §5.2: deterministic normalization so wording drift can't defeat dedup. */
    public static function normalizeSubjectKey(string $key): string
    {
        return strtolower(trim(preg_replace('/\s+/', '-', $key)));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WikiFactServiceTest`
Expected: PASS (4 tests). (`lockForUpdate` is a no-op on SQLite; the test exercises the logic, the lock guards MariaDB production.)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Services/Wiki tests/Feature/Wiki
git add app/Services/Wiki/WikiFactService.php tests/Feature/Wiki/WikiFactServiceTest.php
git commit -m "feat(wiki): row-locked sync fact upsert (reaffirm/supersede/insert)"
```

---

### Task 14: SyncFactWriter + hooks into Ninja and CIPP sync

The writer maps synced data to facts, recomposes the marked sections, and records a `wiki_runs` ledger row. `safe*` wrappers guarantee a wiki failure can never break a sync (catch Throwable, log, continue). Hooks are 3 lines at the end of each sync service method.

**Files:**
- Create: `app/Services/Wiki/SyncFactWriter.php`
- Modify: `app/Services/Ninja/NinjaSyncService.php` (end of `syncDevicesForClient`, after orphan handling, before return)
- Modify: `app/Services/Cipp/CippTenantSecuritySyncService.php` (end of `syncForClient`)
- Test: `tests/Feature/Wiki/SyncFactWriterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiRunStatus;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Models\WikiRun;
use App\Services\Wiki\SyncFactWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncFactWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_noop_when_wiki_disabled(): void
    {
        $client = Client::factory()->create();

        app(SyncFactWriter::class)->safeWriteAssetFacts($client);

        $this->assertSame(0, WikiRun::count());
        $this->assertSame(0, WikiPage::count());
    }

    public function test_writes_asset_facts_and_recomposes_infrastructure(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create();
        Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'DC01',
            'os' => 'Windows Server 2022',
            'ram_gb' => 32,
            'asset_type' => 'Server',
        ]);

        app(SyncFactWriter::class)->safeWriteAssetFacts($client);

        $this->assertSame(1, WikiRun::count());
        $this->assertSame(WikiRunStatus::Completed, WikiRun::first()->status);

        $facts = WikiFact::where('client_id', $client->id)->pluck('statement', 'subject_key');
        $this->assertSame('DC01 runs Windows Server 2022', $facts['asset:dc01:os']);
        $this->assertSame('DC01 has 32 GB RAM', $facts['asset:dc01:ram']);
        $this->assertSame('DC01 is a Server', $facts['asset:dc01:type']);

        $infra = WikiPage::forClient($client->id)->where('slug', 'infrastructure')->first();
        $this->assertStringContainsString('- DC01 runs Windows Server 2022', $infra->body_md);
    }

    public function test_writes_m365_facts_from_cipp_snapshot(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create([
            'cipp_conditional_access_policies' => [
                ['displayName' => 'Require MFA', 'state' => 'enabled'],
                ['displayName' => 'Block legacy auth', 'state' => 'enabled'],
            ],
            'cipp_transport_rules' => [['Name' => 'External tag', 'State' => 'Enabled']],
        ]);

        app(SyncFactWriter::class)->safeWriteM365Facts($client);

        $facts = WikiFact::where('client_id', $client->id)->pluck('statement', 'subject_key');
        $this->assertSame('M365 tenant has 2 conditional access policies', $facts['m365:ca-policies']);
        $this->assertSame('M365 tenant has 1 mail transport rule', $facts['m365:transport-rules']);
    }

    public function test_safe_wrapper_swallows_exceptions(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->make(); // unsaved → writer will throw internally

        app(SyncFactWriter::class)->safeWriteAssetFacts($client); // must not throw

        $this->assertTrue(true);
    }
}
```

Note: if `Asset::factory()` does not exist, create `database/factories/AssetFactory.php` in this step with minimal fields (`client_id` set by caller, `hostname`, `os`, `ram_gb`, `asset_type`, plus any NOT NULL columns from the assets migration — check `2026_02_18_000000_create_assets_table.php` and add `HasFactory` to the Asset model if missing):

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Asset> */
class AssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'hostname' => strtoupper(fake()->bothify('HOST-##')),
            'os' => 'Windows 11 Pro',
            'ram_gb' => 16,
            'asset_type' => 'Workstation',
        ];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SyncFactWriterTest`
Expected: FAIL — `SyncFactWriter` not found.

- [ ] **Step 3: Write the writer**

`app/Services/Wiki/SyncFactWriter.php`:
```php
<?php

namespace App\Services\Wiki;

use App\Enums\WikiFactVolatility;
use App\Enums\WikiRunStatus;
use App\Enums\WikiRunType;
use App\Models\Client;
use App\Models\WikiPage;
use App\Models\WikiRun;
use App\Support\WikiConfig;
use Illuminate\Support\Facades\Log;

class SyncFactWriter
{
    public function __construct(
        private readonly WikiSkeletonService $skeleton,
        private readonly WikiFactService $facts,
        private readonly WikiComposerService $composer,
    ) {}

    public function safeWriteAssetFacts(Client $client): void
    {
        try {
            $this->writeAssetFacts($client);
        } catch (\Throwable $e) {
            Log::warning('wiki: asset fact write failed', ['client_id' => $client->id ?? null, 'error' => $e->getMessage()]);
        }
    }

    public function safeWriteM365Facts(Client $client): void
    {
        try {
            $this->writeM365Facts($client);
        } catch (\Throwable $e) {
            Log::warning('wiki: m365 fact write failed', ['client_id' => $client->id ?? null, 'error' => $e->getMessage()]);
        }
    }

    public function writeAssetFacts(Client $client): ?WikiRun
    {
        if (! WikiConfig::isEnabled()) {
            return null;
        }

        return $this->run($client, function (WikiPage $infra) use ($client) {
            $count = 0;
            foreach ($client->assets()->whereNull('deleted_at')->get() as $asset) {
                $host = $asset->hostname;
                $key = strtolower($host);
                $map = array_filter([
                    "asset:{$key}:type" => $asset->asset_type ? "{$host} is a {$asset->asset_type}" : null,
                    "asset:{$key}:os" => $asset->os ? "{$host} runs {$asset->os}" : null,
                    "asset:{$key}:ram" => $asset->ram_gb ? "{$host} has {$asset->ram_gb} GB RAM" : null,
                    "asset:{$key}:cpu" => $asset->cpu ? "{$host} CPU: {$asset->cpu}" : null,
                    "asset:{$key}:serial" => $asset->serial_number ? "{$host} serial number is {$asset->serial_number}" : null,
                    "asset:{$key}:ip" => $asset->ip_address ? "{$host} last reported IP {$asset->ip_address}" : null,
                ]);
                foreach ($map as $subjectKey => $statement) {
                    $volatility = str_ends_with($subjectKey, ':ip') ? WikiFactVolatility::Volatile : WikiFactVolatility::Durable;
                    $this->facts->upsertSyncFact($infra, 'assets', $subjectKey, $statement, $volatility, [['type' => 'sync', 'id' => 'assets']]);
                    $count++;
                }
            }

            $this->composer->composeSection($infra->fresh(), 'assets');

            return ['facts_written' => $count];
        }, 'infrastructure');
    }

    public function writeM365Facts(Client $client): ?WikiRun
    {
        if (! WikiConfig::isEnabled()) {
            return null;
        }

        return $this->run($client, function (WikiPage $m365) use ($client) {
            $counts = array_filter([
                'm365:ca-policies' => $this->countStatement($client->cipp_conditional_access_policies, 'conditional access policy', 'conditional access policies'),
                'm365:transport-rules' => $this->countStatement($client->cipp_transport_rules, 'mail transport rule', 'mail transport rules'),
                'm365:safe-links' => $this->countStatement($client->cipp_safe_links_policy, 'Safe Links policy', 'Safe Links policies'),
                'm365:compliance-policies' => $this->countStatement($client->cipp_compliance_policies, 'compliance policy', 'compliance policies'),
            ]);

            foreach ($counts as $subjectKey => $statement) {
                $this->facts->upsertSyncFact($m365, 'security-posture', $subjectKey, $statement, WikiFactVolatility::Volatile, [['type' => 'sync', 'id' => 'cipp']]);
            }

            $this->composer->composeSection($m365->fresh(), 'security-posture');

            return ['facts_written' => count($counts)];
        }, 'm365');
    }

    private function countStatement(mixed $items, string $singular, string $plural): ?string
    {
        if (! is_array($items)) {
            return null;
        }
        $n = count($items);

        return "M365 tenant has {$n} ".($n === 1 ? $singular : $plural);
    }

    /** Shared run scaffolding: skeleton, page lookup, wiki_runs ledger. */
    private function run(Client $client, callable $work, string $pageSlug): WikiRun
    {
        $run = WikiRun::create([
            'run_type' => WikiRunType::SyncFacts,
            'subject_type' => 'client',
            'subject_id' => $client->id,
            'status' => WikiRunStatus::Running,
            'triggered_by' => 'auto',
        ]);

        try {
            $this->skeleton->ensureForClient($client);
            $page = WikiPage::forClient($client->id)->where('slug', $pageSlug)->firstOrFail();
            $results = $work($page);
            $run->update([
                'status' => WikiRunStatus::Completed,
                'stages_completed' => ['gather', 'write_facts', 'compose'],
                'stage_results' => [$pageSlug => $results],
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => WikiRunStatus::Failed,
                'errors' => [['stage' => $pageSlug, 'message' => $e->getMessage()]],
            ]);
            throw $e;
        }

        return $run;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SyncFactWriterTest`
Expected: PASS (4 tests). Adjust the `assets()` relation call if the Asset soft-delete column differs (check the Asset model for `SoftDeletes`; if present use `$client->assets` which auto-excludes trashed).

- [ ] **Step 5: Wire the hooks (3 lines each)**

In `app/Services/Ninja/NinjaSyncService.php`, at the end of `syncDevicesForClient(Client $client)` immediately before the final `return $result;`:

```php
// Wiki Phase 2: deterministic environment facts from this sync (never breaks the sync).
app(\App\Services\Wiki\SyncFactWriter::class)->safeWriteAssetFacts($client);
```

In `app/Services/Cipp/CippTenantSecuritySyncService.php`, at the end of `syncForClient(Client $client, SyncResult $result)` after the client update:

```php
// Wiki Phase 2: deterministic environment facts from this sync (never breaks the sync).
app(\App\Services\Wiki\SyncFactWriter::class)->safeWriteM365Facts($client);
```

- [ ] **Step 6: Run the full wiki test group**

Run: `php artisan test tests/Feature/Wiki tests/Unit/Wiki`
Expected: ALL PASS.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint app tests database
git add app/Services/Wiki/SyncFactWriter.php app/Services/Ninja/NinjaSyncService.php app/Services/Cipp/CippTenantSecuritySyncService.php database/factories tests/Feature/Wiki
git commit -m "feat(wiki): sync fact writer with Ninja/CIPP hooks and wiki_runs ledger"
```

---

### Task 15: Routes, controller (index + show), and the two main views

Spec §8 + §8.1: clean default rendering, section-level fact summary line as the ambient provenance signal (zero-state silent), health counters secondary and muted, backlinks panel, deviation merge view, search box at the top of the index.

**Files:**
- Modify: `routes/web.php` (replace the Task 7 stub block with the full wiki group)
- Modify: `app/Http/Controllers/Web/WikiController.php` (replace stub)
- Create: `resources/views/wiki/index.blade.php`, `resources/views/wiki/show.blade.php`
- Test: `tests/Feature/Wiki/WikiRoutesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\User;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    public function test_routes_require_auth(): void
    {
        $this->get('/wiki')->assertRedirect(); // to login
    }

    public function test_global_index_lists_pages_grouped_by_kind(): void
    {
        WikiPage::factory()->create(['slug' => 'vendors/fortinet', 'title' => 'Fortinet', 'kind' => 'vendor']);

        $this->actingAs($this->user())->get('/wiki')
            ->assertOk()
            ->assertSee('Fortinet')
            ->assertSee('Vendor');
    }

    public function test_client_index_seeds_skeleton_and_shows_health_counters(): void
    {
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user())->get("/clients/{$client->id}/wiki");

        $response->assertOk()->assertSee('Infrastructure');
        $this->assertSame(10, WikiPage::forClient($client->id)->count()); // skeleton seeded lazily
    }

    public function test_show_renders_markdown_backlinks_and_fact_summary(): void
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create([
            'slug' => 'infrastructure', 'title' => 'Infrastructure',
            'body_md' => "## Assets\n\n- DC01 runs Windows Server 2022\n",
        ]);
        $linker = WikiPage::factory()->forClient($client)->create([
            'slug' => 'overview', 'title' => 'Overview', 'body_md' => 'See [[infrastructure]]',
        ]);
        app(\App\Services\Wiki\WikiPageService::class)->rebuildLinks($linker);
        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id,
            'section_anchor' => 'assets', 'status' => 'unverified',
            'statement' => 'DC01 runs Windows Server 2022', 'subject_key' => 'asset:dc01:os',
        ]);

        $this->actingAs($this->user())->get("/clients/{$client->id}/wiki/infrastructure")
            ->assertOk()
            ->assertSee('DC01 runs Windows Server 2022')
            ->assertSee('Overview')          // backlink panel shows linking page title
            ->assertSee('1 unverified');     // §8.1 section summary line
    }

    public function test_global_show_renders_deviation_merge_in_client_context(): void
    {
        $client = Client::factory()->create();
        $global = WikiPage::factory()->create([
            'slug' => 'runbooks/onboarding', 'title' => 'Onboarding', 'kind' => 'runbook',
            'body_md' => "## Hardware\n\nStandard laptop.\n",
        ]);
        WikiPage::factory()->forClient($client)->create([
            'slug' => 'runbooks/onboarding', 'kind' => 'deviation', 'parent_page_id' => $global->id,
            'body_md' => "## Hardware\n\nMac only.\n",
        ]);

        $this->actingAs($this->user())
            ->get("/clients/{$client->id}/wiki/runbooks/onboarding?merged=1")
            ->assertOk()
            ->assertSee('Mac only.')
            ->assertDontSee('Standard laptop.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WikiRoutesTest`
Expected: FAIL — index route not defined / controller aborts 404.

- [ ] **Step 3: Write the full route group**

In `routes/web.php`, replace the Task 7 stub block with (keep it inside the `auth` group; the catch-all `{slug}` routes MUST be declared after the literal ones):

```php
use App\Http\Controllers\Web\WikiController;

// Client Wiki (spec docs/superpowers/specs/2026-06-12-client-wiki-design.md §8)
Route::get('/wiki', [WikiController::class, 'index'])->name('wiki.index');
Route::get('/wiki-search', [WikiController::class, 'search'])->name('wiki.search');
Route::get('/wiki-pages/create', [WikiController::class, 'create'])->name('wiki.create');
Route::post('/wiki-pages', [WikiController::class, 'store'])->name('wiki.store');
Route::get('/wiki-pages/{page}/edit', [WikiController::class, 'edit'])->name('wiki.edit');
Route::patch('/wiki-pages/{page}', [WikiController::class, 'update'])->name('wiki.update');
Route::get('/wiki-pages/{page}/history', [WikiController::class, 'history'])->name('wiki.history');
Route::get('/wiki/{slug}', [WikiController::class, 'show'])
    ->where('slug', '.*')->name('wiki.show');
Route::get('/clients/{client}/wiki', [WikiController::class, 'clientIndex'])->name('clients.wiki.index');
Route::get('/clients/{client}/wiki/{slug}', [WikiController::class, 'clientShow'])
    ->where('slug', '.*')->name('clients.wiki.show');
```

- [ ] **Step 4: Write the controller (index/show halves; create/edit/history/search filled in Tasks 16–18 — include the method stubs now so routes resolve)**

`app/Http/Controllers/Web/WikiController.php` (full replacement):
```php
<?php

namespace App\Http\Controllers\Web;

use App\Enums\WikiFactStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Wiki\WikiCascadeService;
use App\Services\Wiki\WikiMarkdown;
use App\Services\Wiki\WikiSections;
use App\Services\Wiki\WikiSkeletonService;
use Illuminate\Http\Request;

class WikiController extends Controller
{
    public function index()
    {
        $pages = WikiPage::active()->globalScope()->orderBy('kind')->orderBy('title')->get()->groupBy('kind');
        $health = $this->healthCounts(null);

        return view('wiki.index', ['pages' => $pages, 'client' => null, 'health' => $health]);
    }

    public function clientIndex(Client $client, WikiSkeletonService $skeleton)
    {
        $skeleton->ensureForClient($client); // lazy skeleton on first visit (spec §4.6)
        $pages = WikiPage::active()->forClient($client->id)->orderBy('kind')->orderBy('title')->get()->groupBy('kind');
        $health = $this->healthCounts($client->id);

        return view('wiki.index', ['pages' => $pages, 'client' => $client, 'health' => $health]);
    }

    public function show(string $slug, WikiMarkdown $renderer)
    {
        $page = WikiPage::active()->globalScope()->where('slug', $slug)->firstOrFail();

        return $this->renderShow($page, null, $renderer);
    }

    public function clientShow(Client $client, string $slug, WikiMarkdown $renderer, WikiCascadeService $cascade, Request $request)
    {
        $page = WikiPage::active()->forClient($client->id)->where('slug', $slug)->first();

        // Cascade fallback (spec §4.5): client slug missing → global page, merged when asked.
        if (! $page) {
            $global = WikiPage::active()->globalScope()->where('slug', $slug)->firstOrFail();
            $merged = $cascade->mergedView($global, $client->id);
            $html = $renderer->render($global, $merged['body_md']);

            return view('wiki.show', [
                'page' => $global, 'client' => $client, 'html' => $html,
                'sectionSummaries' => [], 'backlinks' => $global->backlinks()->with('fromPage')->get(),
                'deviationAnchors' => $merged['deviation_anchors'],
            ]);
        }

        // A deviation page viewed directly in client context renders merged with its parent.
        if ($page->parent_page_id && $request->boolean('merged', true)) {
            $merged = $cascade->mergedView($page->parent, $client->id);

            return view('wiki.show', [
                'page' => $page, 'client' => $client,
                'html' => $renderer->render($page, $merged['body_md']),
                'sectionSummaries' => [], 'backlinks' => $page->backlinks()->with('fromPage')->get(),
                'deviationAnchors' => $merged['deviation_anchors'],
            ]);
        }

        return $this->renderShow($page, $client, $renderer);
    }

    private function renderShow(WikiPage $page, ?Client $client, WikiMarkdown $renderer)
    {
        return view('wiki.show', [
            'page' => $page,
            'client' => $client,
            'html' => $renderer->render($page),
            'sectionSummaries' => $this->sectionSummaries($page),
            'backlinks' => $page->backlinks()->with('fromPage')->get(),
            'deviationAnchors' => [],
        ]);
    }

    /**
     * §8.1.1: ambient per-section counts ("3 unverified · 1 disputed"), zero-state silent.
     *
     * @return array<string, string>
     */
    private function sectionSummaries(WikiPage $page): array
    {
        $rows = $page->facts()
            ->whereIn('status', [WikiFactStatus::Unverified->value, WikiFactStatus::Disputed->value])
            ->get()
            ->groupBy('section_anchor');

        $summaries = [];
        foreach ($rows as $anchor => $facts) {
            $parts = [];
            $unverified = $facts->where('status', WikiFactStatus::Unverified)->count();
            $disputed = $facts->where('status', WikiFactStatus::Disputed)->count();
            if ($unverified) {
                $parts[] = "{$unverified} unverified";
            }
            if ($disputed) {
                $parts[] = "{$disputed} disputed";
            }
            $summaries[$anchor] = implode(' · ', $parts);
        }

        return $summaries;
    }

    /** @return array{unverified: int, disputed: int} */
    private function healthCounts(?int $clientId): array
    {
        $query = WikiFact::query()->when(
            $clientId,
            fn ($q) => $q->where('client_id', $clientId),
            fn ($q) => $q->whereNull('client_id'),
        );

        return [
            'unverified' => (clone $query)->where('status', WikiFactStatus::Unverified->value)->count(),
            'disputed' => (clone $query)->where('status', WikiFactStatus::Disputed->value)->count(),
        ];
    }

    // ── Implemented in Task 16 ──
    public function create(Request $request)
    {
        abort(404);
    }

    public function store()
    {
        abort(404);
    }

    public function edit(WikiPage $page)
    {
        abort(404);
    }

    public function update(WikiPage $page)
    {
        abort(404);
    }

    // ── Implemented in Task 17 ──
    public function history(WikiPage $page)
    {
        abort(404);
    }

    // ── Implemented in Task 18 ──
    public function search(Request $request)
    {
        abort(404);
    }
}
```

- [ ] **Step 5: Write the two views**

`resources/views/wiki/index.blade.php`:
```blade
@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h3 mb-0">{{ $client ? $client->name.' — Wiki' : 'Wiki' }}</h1>
        <a href="{{ route('wiki.create', $client ? ['client_id' => $client->id] : []) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-plus-lg"></i> New page
        </a>
    </div>

    {{-- §8.1 advisory: search is the primary affordance at the top --}}
    <form action="{{ route('wiki.search') }}" method="get" class="mb-4">
        @if ($client)<input type="hidden" name="client_id" value="{{ $client->id }}">@endif
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="q" class="form-control" placeholder="Search the wiki…" autofocus>
        </div>
    </form>

    @foreach ($pages as $kind => $group)
        <h2 class="h6 text-uppercase text-muted mt-4">{{ \App\Enums\WikiPageKind::from($kind)->label() }}</h2>
        <div class="list-group">
            @foreach ($group as $page)
                <a class="list-group-item list-group-item-action"
                   href="{{ $client ? route('clients.wiki.show', [$client, $page->slug]) : route('wiki.show', $page->slug) }}">
                    {{ $page->title }}
                    <span class="text-muted small ms-2">{{ $page->slug }}</span>
                </a>
            @endforeach
        </div>
    @endforeach

    @if ($pages->isEmpty())
        <p class="text-muted">No pages yet. Create the first one.</p>
    @endif

    {{-- §8.1.4: health counters BELOW the content index, muted, zero-state silent --}}
    @if (($health['unverified'] ?? 0) > 0 || ($health['disputed'] ?? 0) > 0)
        <div class="mt-4 small text-muted">
            Needs review:
            @if ($health['unverified'] > 0)
                <span class="badge bg-secondary">{{ $health['unverified'] }} unverified</span>
            @endif
            @if ($health['disputed'] > 0)
                <span class="badge bg-secondary">{{ $health['disputed'] }} disputed</span>
            @endif
        </div>
    @endif
</div>
@endsection
```

`resources/views/wiki/show.blade.php`:
```blade
@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h3 mb-0">{{ $page->title }}</h1>
            <div class="small text-muted">
                {{ $page->kind->label() }} · {{ $page->slug }}
                @if ($client) · {{ $client->name }} @endif
                @if (! empty($deviationAnchors))
                    · <span class="badge bg-secondary">client deviations applied</span>
                @endif
            </div>
        </div>
        <div class="btn-group">
            <a href="{{ route('wiki.edit', $page) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> Edit</a>
            <a href="{{ route('wiki.history', $page) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history"></i> History</a>
        </div>
    </div>

    {{-- §8.1.1: ambient per-section provenance counts (zero-state silent) --}}
    @if (! empty($sectionSummaries))
        <div class="small text-muted mb-2">
            @foreach ($sectionSummaries as $anchor => $summary)
                <span class="me-3">{{ \Illuminate\Support\Str::headline($anchor) }}: {{ $summary }}</span>
            @endforeach
        </div>
    @endif

    <div class="row">
        <div class="col-lg-9">
            <div class="card"><div class="card-body wiki-content">{!! $html !!}</div></div>
        </div>
        <div class="col-lg-3">
            @if ($backlinks->isNotEmpty())
                <div class="card">
                    <div class="card-header small text-uppercase text-muted">Linked from</div>
                    <ul class="list-group list-group-flush">
                        @foreach ($backlinks as $link)
                            <li class="list-group-item">
                                <a href="{{ $link->fromPage->client_id
                                    ? route('clients.wiki.show', [$link->fromPage->client_id, $link->fromPage->slug])
                                    : route('wiki.show', $link->fromPage->slug) }}">
                                    {{ $link->fromPage->title }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
```

Note: `{!! $html !!}` is safe ONLY because every render path goes through `MarkdownRenderer` → `HtmlSanitizer` (Task 7). Never bypass `WikiMarkdown::render()` to fill `$html`.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=WikiRoutesTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint app routes resources tests
git add app/Http routes/web.php resources/views/wiki tests/Feature/Wiki/WikiRoutesTest.php
git commit -m "feat(wiki): wiki routes, controller, index and show views"
```

---

### Task 16: Create / edit / update (FormRequests, concurrency guard)

Mirrors the `site_notes` editing pattern: plain textarea markdown editor, optimistic concurrency via an `expected_updated_at` hidden field (compare-and-reject like `ClientService::updateSiteNotes`).

**Files:**
- Create: `app/Http/Requests/WikiPageStoreRequest.php`, `app/Http/Requests/WikiPageUpdateRequest.php`
- Create: `resources/views/wiki/create.blade.php`, `resources/views/wiki/edit.blade.php`
- Modify: `app/Http/Controllers/Web/WikiController.php` (replace create/store/edit/update stubs)
- Test: `tests/Feature/Wiki/WikiPageEditingTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiPageEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_global_page_with_revision(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/wiki-pages', [
            'title' => 'Fortinet', 'slug' => 'vendors/fortinet', 'kind' => 'vendor',
            'body_md' => "## Quirks\n\nFortiOS 7.4 DHCP bug.",
        ])->assertRedirect('/wiki/vendors/fortinet');

        $page = WikiPage::where('slug', 'vendors/fortinet')->first();
        $this->assertSame('human', $page->created_by_type->value);
        $this->assertCount(1, $page->revisions);
    }

    public function test_store_creates_client_page(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $this->actingAs($user)->post('/wiki-pages', [
            'client_id' => $client->id, 'title' => 'Printers', 'slug' => 'printers',
            'kind' => 'environment', 'body_md' => '## Fleet',
        ])->assertRedirect("/clients/{$client->id}/wiki/printers");

        $this->assertNotNull(WikiPage::forClient($client->id)->where('slug', 'printers')->first());
    }

    public function test_update_writes_revision_and_detects_concurrent_edit(): void
    {
        $user = User::factory()->create();
        $page = WikiPage::factory()->create(['body_md' => 'v1']);
        $stamp = $page->updated_at->toIso8601String();

        $this->actingAs($user)->patch("/wiki-pages/{$page->id}", [
            'body_md' => 'v2', 'change_summary' => 'tweak', 'expected_updated_at' => $stamp,
        ])->assertRedirect();
        $this->assertSame('v2', $page->fresh()->body_md);

        // Stale stamp → rejected with an error, body unchanged.
        $this->actingAs($user)->patch("/wiki-pages/{$page->id}", [
            'body_md' => 'v3', 'change_summary' => 'stale', 'expected_updated_at' => $stamp,
        ])->assertSessionHas('error');
        $this->assertSame('v2', $page->fresh()->body_md);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WikiPageEditingTest`
Expected: FAIL — store aborts 404.

- [ ] **Step 3: Write FormRequests**

`app/Http/Requests/WikiPageStoreRequest.php`:
```php
<?php

namespace App\Http\Requests;

use App\Enums\WikiPageKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WikiPageStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // staff-only app; auth middleware gates access
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9\/\-]*$/'],
            'kind' => ['required', Rule::enum(WikiPageKind::class)],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'parent_page_id' => ['nullable', 'integer', 'exists:wiki_pages,id'],
            'body_md' => ['nullable', 'string', 'max:200000'],
        ];
    }
}
```

`app/Http/Requests/WikiPageUpdateRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WikiPageUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'body_md' => ['required', 'string', 'max:200000'],
            'change_summary' => ['nullable', 'string', 'max:255'],
            'expected_updated_at' => ['required', 'string'],
        ];
    }
}
```

- [ ] **Step 4: Replace the four controller stubs**

In `app/Http/Controllers/Web/WikiController.php` replace the create/store/edit/update stubs with (add `use App\Enums\WikiAuthorType; use App\Enums\WikiScope; use App\Http\Requests\WikiPageStoreRequest; use App\Http\Requests\WikiPageUpdateRequest; use App\Services\Wiki\WikiPageService;` to the imports):

```php
    public function create(Request $request)
    {
        $client = $request->filled('client_id') ? Client::findOrFail($request->integer('client_id')) : null;

        return view('wiki.create', ['client' => $client, 'kinds' => \App\Enums\WikiPageKind::cases()]);
    }

    public function store(WikiPageStoreRequest $request, WikiPageService $pages)
    {
        $data = $request->validated();
        $clientId = $data['client_id'] ?? null;

        try {
            $page = $pages->create([
                'scope' => $clientId ? WikiScope::Client : WikiScope::Global,
                'client_id' => $clientId,
                'slug' => $data['slug'],
                'title' => $data['title'],
                'kind' => $data['kind'],
                'parent_page_id' => $data['parent_page_id'] ?? null,
                'body_md' => $data['body_md'] ?? '',
            ], WikiAuthorType::Human, auth()->id());
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect($this->pageUrl($page))->with('success', 'Page created.');
    }

    public function edit(WikiPage $page)
    {
        return view('wiki.edit', ['page' => $page]);
    }

    public function update(WikiPage $page, WikiPageUpdateRequest $request, WikiPageService $pages)
    {
        $data = $request->validated();

        // Optimistic concurrency, same pattern as ClientService::updateSiteNotes.
        if ($page->updated_at->toIso8601String() !== $data['expected_updated_at']) {
            return back()->withInput()->with('error', 'This page changed while you were editing. Review and retry.');
        }

        if (isset($data['title'])) {
            $page->update(['title' => $data['title']]);
        }
        $pages->updateBody($page, $data['body_md'], WikiAuthorType::Human, auth()->id(),
            $data['change_summary'] ?: 'Edited');

        return redirect($this->pageUrl($page))->with('success', 'Page updated.');
    }

    private function pageUrl(WikiPage $page): string
    {
        return $page->client_id
            ? route('clients.wiki.show', [$page->client_id, $page->slug])
            : route('wiki.show', $page->slug);
    }
```

- [ ] **Step 5: Write the two form views**

`resources/views/wiki/create.blade.php`:
```blade
@extends('layouts.app')

@section('content')
<div class="container-fluid" style="max-width: 860px;">
    <h1 class="h3 mb-3">New wiki page {{ $client ? 'for '.$client->name : '(global)' }}</h1>
    <form action="{{ route('wiki.store') }}" method="post">
        @csrf
        @if ($client)<input type="hidden" name="client_id" value="{{ $client->id }}">@endif
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="title">Title</label>
                <input id="title" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title') }}" required>
                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label class="form-label" for="slug">Slug</label>
                <input id="slug" name="slug" class="form-control @error('slug') is-invalid @enderror" value="{{ old('slug') }}" placeholder="vendors/fortinet" required>
                @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label class="form-label" for="kind">Kind</label>
                <select id="kind" name="kind" class="form-select">
                    @foreach ($kinds as $kind)
                        <option value="{{ $kind->value }}" @selected(old('kind') === $kind->value)>{{ $kind->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="body_md">Content (Markdown — link pages with [[slug]])</label>
                <textarea id="body_md" name="body_md" rows="16" class="form-control font-monospace">{{ old('body_md') }}</textarea>
            </div>
        </div>
        <div class="mt-3">
            <button class="btn btn-primary">Create page</button>
            <a href="{{ $client ? route('clients.wiki.index', $client) : route('wiki.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
@endsection
```

`resources/views/wiki/edit.blade.php`:
```blade
@extends('layouts.app')

@section('content')
<div class="container-fluid" style="max-width: 860px;">
    <h1 class="h3 mb-3">Edit: {{ $page->title }}</h1>
    <form action="{{ route('wiki.update', $page) }}" method="post">
        @csrf
        @method('PATCH')
        <input type="hidden" name="expected_updated_at" value="{{ $page->updated_at->toIso8601String() }}">
        <div class="mb-3">
            <label class="form-label" for="title">Title</label>
            <input id="title" name="title" class="form-control" value="{{ old('title', $page->title) }}">
        </div>
        <div class="mb-3">
            <label class="form-label" for="body_md">Content (Markdown — link pages with [[slug]])</label>
            <textarea id="body_md" name="body_md" rows="20" class="form-control font-monospace" required>{{ old('body_md', $page->body_md) }}</textarea>
        </div>
        <div class="mb-3">
            <label class="form-label" for="change_summary">Change summary</label>
            <input id="change_summary" name="change_summary" class="form-control" placeholder="What changed?">
        </div>
        <button class="btn btn-primary">Save</button>
        <a href="{{ $page->client_id ? route('clients.wiki.show', [$page->client_id, $page->slug]) : route('wiki.show', $page->slug) }}" class="btn btn-outline-secondary">Cancel</a>
    </form>
</div>
@endsection
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=WikiPageEditingTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint app resources tests
git add app/Http resources/views/wiki tests/Feature/Wiki/WikiPageEditingTest.php
git commit -m "feat(wiki): page create/edit with revisions and concurrency guard"
```

---

### Task 17: History view + LineDiff

§8.1 advisory: diffs pair color with `+`/`−` markers (never color alone). LineDiff is a minimal LCS over lines — wiki pages are small documents; O(n·m) is fine.

**Files:**
- Create: `app/Helpers/LineDiff.php`
- Create: `resources/views/wiki/history.blade.php`
- Modify: `app/Http/Controllers/Web/WikiController.php` (replace history stub)
- Test: `tests/Unit/Wiki/LineDiffTest.php`, extend `tests/Feature/Wiki/WikiRoutesTest.php`

- [ ] **Step 1: Write the failing unit test**

```php
<?php

namespace Tests\Unit\Wiki;

use App\Helpers\LineDiff;
use PHPUnit\Framework\TestCase;

class LineDiffTest extends TestCase
{
    public function test_diff_marks_added_removed_and_unchanged_lines(): void
    {
        $old = "a\nb\nc";
        $new = "a\nB\nc\nd";

        $diff = LineDiff::diff($old, $new);

        $this->assertSame([
            ['type' => 'same', 'line' => 'a'],
            ['type' => 'del', 'line' => 'b'],
            ['type' => 'add', 'line' => 'B'],
            ['type' => 'same', 'line' => 'c'],
            ['type' => 'add', 'line' => 'd'],
        ], $diff);
    }

    public function test_identical_inputs_are_all_same(): void
    {
        $diff = LineDiff::diff("x\ny", "x\ny");

        $this->assertSame([['type' => 'same', 'line' => 'x'], ['type' => 'same', 'line' => 'y']], $diff);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LineDiffTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write LineDiff**

`app/Helpers/LineDiff.php`:
```php
<?php

namespace App\Helpers;

class LineDiff
{
    /**
     * Minimal LCS line diff. Deletions are emitted before additions at each divergence.
     *
     * @return array<int, array{type: 'same'|'add'|'del', line: string}>
     */
    public static function diff(string $old, string $new): array
    {
        $a = $old === '' ? [] : explode("\n", $old);
        $b = $new === '' ? [] : explode("\n", $new);
        $n = count($a);
        $m = count($b);

        // LCS length table.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        // Walk the table.
        $out = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['type' => 'same', 'line' => $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['type' => 'del', 'line' => $a[$i]];
                $i++;
            } else {
                $out[] = ['type' => 'add', 'line' => $b[$j]];
                $j++;
            }
        }
        for (; $i < $n; $i++) {
            $out[] = ['type' => 'del', 'line' => $a[$i]];
        }
        for (; $j < $m; $j++) {
            $out[] = ['type' => 'add', 'line' => $b[$j]];
        }

        return $out;
    }
}
```

- [ ] **Step 4: Replace the history stub and add the view**

Controller (replace `history` stub; add `use App\Helpers\LineDiff;` import):
```php
    public function history(WikiPage $page)
    {
        $revisions = $page->revisions()->with('author')->get();

        // Diff each revision against its predecessor (revisions are ordered newest-first).
        $diffs = [];
        foreach ($revisions as $index => $revision) {
            $previous = $revisions[$index + 1] ?? null;
            $diffs[$revision->id] = LineDiff::diff($previous?->body_md ?? '', $revision->body_md);
        }

        return view('wiki.history', ['page' => $page, 'revisions' => $revisions, 'diffs' => $diffs]);
    }
```

`resources/views/wiki/history.blade.php`:
```blade
@extends('layouts.app')

@section('content')
<div class="container-fluid" style="max-width: 980px;">
    <h1 class="h3 mb-3">History: {{ $page->title }}</h1>

    @foreach ($revisions as $revision)
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">
                <span>
                    <strong>{{ $revision->change_summary }}</strong>
                    <span class="text-muted small ms-2">
                        {{ $revision->author_type->value }}{{ $revision->author ? ' · '.$revision->author->name : '' }}
                    </span>
                </span>
                <span class="text-muted small">{{ $revision->created_at->format('Y-m-d H:i') }}</span>
            </div>
            <div class="card-body p-0">
                <pre class="mb-0 small" style="max-height: 320px; overflow:auto;">@foreach ($diffs[$revision->id] as $row)@if ($row['type'] === 'add')<div class="bg-success-subtle">+ {{ $row['line'] }}</div>@elseif ($row['type'] === 'del')<div class="bg-danger-subtle">− {{ $row['line'] }}</div>@else<div class="text-muted">  {{ $row['line'] }}</div>@endif @endforeach</pre>
            </div>
        </div>
    @endforeach
</div>
@endsection
```

- [ ] **Step 5: Add a feature assertion to WikiRoutesTest**

Append this test method to `tests/Feature/Wiki/WikiRoutesTest.php`:
```php
    public function test_history_shows_revision_diff(): void
    {
        $user = User::factory()->create();
        $page = WikiPage::factory()->create(['body_md' => 'v1']);
        app(\App\Services\Wiki\WikiPageService::class)
            ->updateBody($page, 'v2', \App\Enums\WikiAuthorType::Human, $user->id, 'Edited');

        $this->actingAs($user)->get("/wiki-pages/{$page->id}/history")
            ->assertOk()
            ->assertSee('Edited')
            ->assertSee('+ v2')
            ->assertSee('− v1');
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter='LineDiffTest|WikiRoutesTest'`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint app resources tests
git add app/Helpers/LineDiff.php app/Http resources/views/wiki/history.blade.php tests
git commit -m "feat(wiki): revision history with line diffs"
```

---

### Task 18: WikiSearchService + search route/view

Spec §6/§9: FULLTEXT on mysql/mariadb (`whereFullText`), LIKE fallback otherwise (SQLite dev/tests). Staff search spans pages + facts. Client context searches client + global scope; the global page searches everything (staff are cross-client by design — the hard isolation rule in spec §6 binds the AI tool layer in Phase 4, not the staff UI).

**Files:**
- Create: `app/Services/Wiki/WikiSearchService.php`
- Create: `resources/views/wiki/search.blade.php`
- Modify: `app/Http/Controllers/Web/WikiController.php` (replace search stub)
- Test: `tests/Feature/Wiki/WikiSearchTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\User;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Wiki\WikiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_finds_pages_and_facts_with_like_fallback(): void
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create([
            'slug' => 'network', 'title' => 'Network', 'body_md' => 'FortiGate 60F at the edge.',
        ]);
        WikiFact::factory()->create([
            'client_id' => $client->id, 'page_id' => $page->id,
            'subject_key' => 'asset:fw01:model', 'statement' => 'FW01 is a FortiGate 60F',
        ]);
        WikiPage::factory()->create(['slug' => 'unrelated', 'title' => 'Unrelated', 'body_md' => 'nothing here']);

        $results = app(WikiSearchService::class)->search('FortiGate', $client->id);

        $this->assertCount(1, $results['pages']);
        $this->assertTrue($results['pages']->first()->is($page));
        $this->assertCount(1, $results['facts']);
    }

    public function test_client_scope_includes_global_pages(): void
    {
        $client = Client::factory()->create();
        WikiPage::factory()->create(['slug' => 'vendors/fortinet', 'title' => 'Fortinet', 'body_md' => 'FortiGate quirks']);

        $results = app(WikiSearchService::class)->search('FortiGate', $client->id);

        $this->assertCount(1, $results['pages']);
    }

    public function test_search_route_renders_results(): void
    {
        $user = User::factory()->create();
        WikiPage::factory()->create(['slug' => 'vendors/fortinet', 'title' => 'Fortinet', 'body_md' => 'FortiGate quirks']);

        $this->actingAs($user)->get('/wiki-search?q=FortiGate')
            ->assertOk()
            ->assertSee('Fortinet');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WikiSearchTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the service, controller method, and view**

`app/Services/Wiki/WikiSearchService.php`:
```php
<?php

namespace App\Services\Wiki;

use App\Enums\WikiFactStatus;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Support\Facades\DB;

class WikiSearchService
{
    /** @return array{pages: \Illuminate\Support\Collection, facts: \Illuminate\Support\Collection} */
    public function search(string $query, ?int $clientId = null, int $limit = 25): array
    {
        $pages = WikiPage::active()
            ->where(function ($q) use ($clientId) {
                $q->where('scope', 'global');
                if ($clientId !== null) {
                    $q->orWhere(fn ($qq) => $qq->where('scope', 'client')->where('client_id', $clientId));
                }
                // Global search page (clientId null in staff UI) intentionally spans all
                // client pages too — staff are cross-client by design:
                if ($clientId === null) {
                    $q->orWhere('scope', 'client');
                }
            })
            ->where(fn ($q) => $this->textMatch($q, ['title', 'body_md'], $query))
            ->limit($limit)
            ->get();

        $facts = WikiFact::query()
            ->whereNot('status', WikiFactStatus::Retired->value)
            ->when($clientId !== null, fn ($q) => $q->where(
                fn ($qq) => $qq->where('client_id', $clientId)->orWhereNull('client_id')
            ))
            ->where(fn ($q) => $this->textMatch($q, ['statement'], $query))
            ->with('page')
            ->limit($limit)
            ->get();

        return ['pages' => $pages, 'facts' => $facts];
    }

    /** FULLTEXT on mysql/mariadb, LIKE elsewhere (SQLite dev/tests). Spec §9. */
    private function textMatch($query, array $columns, string $term)
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $query->whereFullText($columns, $term);
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
        foreach ($columns as $index => $column) {
            $index === 0 ? $query->where($column, 'like', $like) : $query->orWhere($column, 'like', $like);
        }

        return $query;
    }
}
```

Controller (replace `search` stub; add `use App\Services\Wiki\WikiSearchService;`):
```php
    public function search(Request $request, WikiSearchService $searcher)
    {
        $query = trim((string) $request->query('q', ''));
        $clientId = $request->filled('client_id') ? $request->integer('client_id') : null;
        $client = $clientId ? Client::find($clientId) : null;

        $results = $query === ''
            ? ['pages' => collect(), 'facts' => collect()]
            : $searcher->search($query, $clientId);

        return view('wiki.search', ['query' => $query, 'client' => $client, 'results' => $results]);
    }
```

`resources/views/wiki/search.blade.php`:
```blade
@extends('layouts.app')

@section('content')
<div class="container-fluid" style="max-width: 860px;">
    <h1 class="h3 mb-3">Wiki search {{ $client ? '— '.$client->name : '' }}</h1>

    <form method="get" class="mb-4">
        @if ($client)<input type="hidden" name="client_id" value="{{ $client->id }}">@endif
        <div class="input-group">
            <input type="search" name="q" value="{{ $query }}" class="form-control" placeholder="Search pages and facts…" autofocus>
            <button class="btn btn-outline-secondary">Search</button>
        </div>
    </form>

    @if ($query !== '')
        <h2 class="h6 text-uppercase text-muted">Pages ({{ $results['pages']->count() }})</h2>
        <div class="list-group mb-4">
            @forelse ($results['pages'] as $page)
                <a class="list-group-item list-group-item-action"
                   href="{{ $page->client_id ? route('clients.wiki.show', [$page->client_id, $page->slug]) : route('wiki.show', $page->slug) }}">
                    {{ $page->title }}
                    <span class="text-muted small ms-2">{{ $page->client_id ? $page->client?->name : 'global' }} · {{ $page->slug }}</span>
                </a>
            @empty
                <div class="list-group-item text-muted">No matching pages.</div>
            @endforelse
        </div>

        <h2 class="h6 text-uppercase text-muted">Facts ({{ $results['facts']->count() }})</h2>
        <div class="list-group">
            @forelse ($results['facts'] as $fact)
                <a class="list-group-item list-group-item-action"
                   href="{{ $fact->page->client_id ? route('clients.wiki.show', [$fact->page->client_id, $fact->page->slug]) : route('wiki.show', $fact->page->slug) }}">
                    {{ $fact->statement }}
                    <span class="{{ $fact->status->badgeClass() }} ms-2">{{ $fact->status->label() }}</span>
                </a>
            @empty
                <div class="list-group-item text-muted">No matching facts.</div>
            @endforelse
        </div>
    @endif
</div>
@endsection
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WikiSearchTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app resources tests
git add app/Services/Wiki/WikiSearchService.php app/Http resources/views/wiki/search.blade.php tests/Feature/Wiki/WikiSearchTest.php
git commit -m "feat(wiki): driver-aware search across pages and facts"
```

---

### Task 19: Client-page nav link, full suite, PR

**Files:**
- Modify: `resources/views/clients/show.blade.php` (Wiki link alongside the existing action buttons/tabs in the client header)
- Modify: `README.md` (one line in the services/feature list mentioning the wiki module and `wiki_enabled` setting)

- [ ] **Step 1: Add the nav link**

In `resources/views/clients/show.blade.php`, locate the client header's action button row (the area rendering buttons like edit/site-notes near the client name) and add:

```blade
<a href="{{ route('clients.wiki.index', $client) }}" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-journal-text"></i> Wiki
</a>
```

Spec §8: the wiki must be reachable from the client detail page without a context switch.

- [ ] **Step 2: README note**

Add to the feature/services table or list in `README.md`:

```markdown
| `app/Services/Wiki/` | Client Wiki — auto-maintained environment documentation (enable with the `wiki_enabled` setting; see docs/superpowers/specs/2026-06-12-client-wiki-design.md) |
```

- [ ] **Step 3: Run the FULL test suite**

Run: `php artisan test`
Expected: ALL PASS — zero failures, including all pre-existing suites.

- [ ] **Step 4: Pint everything touched**

Run: `./vendor/bin/pint`
Expected: no remaining style issues (clean or only wiki files fixed).

- [ ] **Step 5: Manual smoke check (local dev, MariaDB)**

```bash
php artisan migrate
php artisan tinker --execute="App\Models\Setting::setValue('wiki_enabled','1');"
php artisan wiki:import-site-notes
```

Then in the browser: open a client → Wiki → confirm skeleton + imported notes render; create a page with a `[[wikilink]]`; edit it; check History shows the diff; search for a term. If the deployment has Ninja configured, run `php artisan ninja:sync-devices` and confirm the Infrastructure page's Assets section populated and a `wiki_runs` row exists.

- [ ] **Step 6: Final commit and PR**

```bash
git add resources/views/clients/show.blade.php README.md
git commit -m "feat(wiki): client page wiki link and README note"
git push -u origin feat/client-wiki-phase-1-2
gh pr create --repo sounditsolutions/soundit-psa --base main \
  --title "feat: Client Wiki — Phases 1+2 (manual wiki + deterministic sync facts)" \
  --body "Implements Phases 1+2 of docs/superpowers/specs/2026-06-12-client-wiki-design.md (see PR #12 for the reviewed spec). Manual wiki with revisions/links/search/cascade, per-client skeleton, site_notes import, deterministic sync-fact writer (Ninja assets + CIPP M365) with wiki_runs ledger. No AI calls; wiki_enabled defaults off."
```

---

## Plan self-review notes (already applied)

- Every service/class name cross-checked across tasks: `WikiPageService.updateBody/create/rebuildLinks/archive`, `WikiSections.split/join/replace/spliceMarkers/anchorFor`, `WikiFactService.upsertSyncFact/normalizeSubjectKey`, `WikiComposerService.composeSection`, `WikiCascadeService.mergedView`, `SyncFactWriter.safeWriteAssetFacts/safeWriteM365Facts/writeAssetFacts/writeM365Facts`, `WikiSearchService.search`, `LineDiff.diff`.
- Spec coverage for Phases 1+2: schema (T2), models (T3), config gate §9 (T4), wikilinks + sanitized render (T5–T7), revisions + synchronous link rebuild §5.2 (T8), cascade §4.5 (T9), skeleton §4.6 (T10), site_notes import §4.6/§6 (T11), template composition §5.2-step-5 (T12), merge concurrency §4.1 + pinned protection §5.2 (T13), sync triggers §5.1 + runs ledger §10 (T14), UI §8/§8.1 items 1 & 4 + search-first advisory (T15–T18), client-page reachability §8 (T19).
- Known deferral: fact confirm/correct/retire actions (§8.1 item 3) ship with Phase 3's unverified facts — Phase 2 produces only `confirmed` sync facts, so the actions have no subject yet. The Phase 3 plan MUST include them.
- `clients.site_notes` stays untouched and authoritative for triage context until Phase 4's transition rules (spec §6).
