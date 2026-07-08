# Device-to-User Assignment Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a many-to-many `asset_person` pivot linking devices to users, with automated assignment from RMM data and manual management in the UI.

**Architecture:** New `asset_person` pivot table with `is_primary` and `assignment_source` fields. `AssetUserAssignmentService` handles tiered matching (UPN/email exact → name fuzzy). Artisan command runs daily after NinjaRMM sync. Asset detail gets a Users card, asset index gets an assignment filter, person detail gets a Devices section.

**Tech Stack:** Laravel 12, PHP 8.3, MariaDB, Blade, Bootstrap 5.3

**Spec:** `docs/superpowers/specs/2026-03-19-device-user-assignment-design.md`

---

## Chunk 1: Data Model & Service

### Task 1: Migration and enum

**Files:**
- Create: `database/migrations/2026_03_19_000001_create_asset_person_table.php`
- Create: `app/Enums/AssetAssignmentSource.php`

- [ ] **Step 1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_person', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->string('assignment_source', 10)->default('auto');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_person');
    }
};
```

- [ ] **Step 2: Create the enum**

```php
<?php

namespace App\Enums;

enum AssetAssignmentSource: string
{
    case Auto = 'auto';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Auto',
            self::Manual => 'Manual',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Auto => 'bg-secondary',
            self::Manual => 'bg-primary',
        };
    }
}
```

- [ ] **Step 3: Run migration**

Run: `php artisan migrate`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_03_19_000001_create_asset_person_table.php app/Enums/AssetAssignmentSource.php
git commit -m "Add asset_person pivot table and AssetAssignmentSource enum"
```

---

### Task 2: Model relationships

**Files:**
- Modify: `app/Models/Asset.php`
- Modify: `app/Models/Person.php`

- [ ] **Step 1: Add relationships to Asset model**

Add `use App\Enums\AssetAssignmentSource;` import.

Add after the existing `contracts()` relationship:

```php
public function users(): BelongsToMany
{
    return $this->belongsToMany(Person::class, 'asset_person')
        ->withPivot('is_primary', 'assignment_source', 'last_seen_at')
        ->withTimestamps();
}

public function primaryUser(): ?Person
{
    return $this->users()->wherePivot('is_primary', true)->first();
}
```

Add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` if not already imported.

- [ ] **Step 2: Add relationship to Person model**

Add after the existing `contracts()` relationship:

```php
public function assets(): BelongsToMany
{
    return $this->belongsToMany(Asset::class, 'asset_person')
        ->withPivot('is_primary', 'assignment_source', 'last_seen_at')
        ->withTimestamps();
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/Asset.php app/Models/Person.php
git commit -m "Add users/assets many-to-many relationships via asset_person pivot"
```

---

### Task 3: AssetUserAssignmentService

**Files:**
- Create: `app/Services/AssetUserAssignmentService.php`

- [ ] **Step 1: Create the service**

```php
<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AssetUserAssignmentService
{
    /**
     * Run auto-assignment for all assets (or scoped to a client).
     *
     * @return array{processed: int, matched: int, already_linked: int, no_match: int, ambiguous: int}
     */
    public function assignAll(?int $clientId = null, bool $dryRun = false): array
    {
        $query = Asset::whereNotNull('last_user')
            ->where('last_user', '!=', '')
            ->where('is_active', true)
            ->whereNotNull('client_id');

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $stats = ['processed' => 0, 'matched' => 0, 'already_linked' => 0, 'no_match' => 0, 'ambiguous' => 0];

        $query->chunk(100, function ($assets) use (&$stats, $dryRun) {
            foreach ($assets as $asset) {
                $stats['processed']++;
                $result = $this->assignForAsset($asset, $dryRun);
                $stats[$result]++;
            }
        });

        return $stats;
    }

    /**
     * Attempt to auto-assign a user for a single asset.
     *
     * @return string One of: matched, already_linked, no_match, ambiguous
     */
    public function assignForAsset(Asset $asset, bool $dryRun = false): string
    {
        $lastUser = trim($asset->last_user);
        if (!$lastUser) {
            return 'no_match';
        }

        $username = $this->parseUsername($lastUser);
        $clientId = $asset->client_id;

        // Tier 1: Exact UPN/email match
        $person = $this->matchByUpnOrEmail($lastUser, $username, $clientId);

        // Tier 2: Name-based fuzzy match
        if (!$person) {
            $result = $this->matchByName($username, $clientId);
            if ($result === 'ambiguous') {
                return 'ambiguous';
            }
            $person = $result;
        }

        if (!$person) {
            return 'no_match';
        }

        // Check if already linked
        $existing = DB::table('asset_person')
            ->where('asset_id', $asset->id)
            ->where('person_id', $person->id)
            ->first();

        if ($existing) {
            if (!$dryRun) {
                DB::table('asset_person')
                    ->where('id', $existing->id)
                    ->update(['last_seen_at' => now(), 'updated_at' => now()]);
            }
            return 'already_linked';
        }

        if (!$dryRun) {
            DB::table('asset_person')->insert([
                'asset_id' => $asset->id,
                'person_id' => $person->id,
                'is_primary' => false,
                'assignment_source' => 'auto',
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::debug('[AssetUserAssignment] Linked', [
                'asset' => $asset->hostname ?? $asset->name,
                'person' => $person->full_name,
                'last_user' => $lastUser,
            ]);
        }

        return 'matched';
    }

    /**
     * Parse a last_user string into a normalized username.
     *
     * Handles: DOMAIN\username, username@domain.com, plain username
     */
    private function parseUsername(string $lastUser): string
    {
        // DOMAIN\username
        if (str_contains($lastUser, '\\')) {
            return Str::after($lastUser, '\\');
        }

        // username@domain — return the username part for name matching
        // (keep full string for UPN matching in tier 1)
        if (str_contains($lastUser, '@')) {
            return Str::before($lastUser, '@');
        }

        return $lastUser;
    }

    /**
     * Tier 1: Exact match by UPN or email address.
     */
    private function matchByUpnOrEmail(string $lastUser, string $username, int $clientId): ?Person
    {
        // If last_user contains @, try as full UPN/email
        if (str_contains($lastUser, '@')) {
            $person = Person::where('client_id', $clientId)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereRaw('LOWER(cipp_upn) = ?', [strtolower($lastUser)])
                    ->orWhereRaw('LOWER(email) = ?', [strtolower($lastUser)]))
                ->first();

            if ($person) {
                return $person;
            }
        }

        // Also try username@% pattern against UPN/email (for DOMAIN\user format)
        if (!str_contains($lastUser, '@')) {
            $person = Person::where('client_id', $clientId)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereRaw('LOWER(cipp_upn) LIKE ?', [strtolower($username) . '@%'])
                    ->orWhereRaw('LOWER(email) LIKE ?', [strtolower($username) . '@%']))
                ->first();

            if ($person) {
                return $person;
            }
        }

        return null;
    }

    /**
     * Tier 2: Fuzzy match by first name, full name, or common username patterns.
     *
     * @return Person|string|null  Person if unambiguous match, 'ambiguous' if multiple, null if none
     */
    private function matchByName(string $username, int $clientId): Person|string|null
    {
        $username = strtolower($username);

        $candidates = Person::where('client_id', $clientId)
            ->where('is_active', true)
            ->get(['id', 'first_name', 'last_name', 'email', 'cipp_upn']);

        $matches = [];

        foreach ($candidates as $person) {
            $firstName = strtolower($person->first_name ?? '');
            $lastName = strtolower($person->last_name ?? '');

            // Exact first name match
            if ($firstName && $username === $firstName) {
                $matches[] = $person;
                continue;
            }

            // Full name patterns: jsmith, john.smith, johnsmith
            if ($firstName && $lastName) {
                $patterns = [
                    $firstName[0] . $lastName,           // jsmith
                    $firstName . '.' . $lastName,        // john.smith
                    $firstName . $lastName,              // johnsmith
                    $lastName . $firstName[0],           // smithj
                    $firstName . '_' . $lastName,        // john_smith
                ];

                if (in_array($username, $patterns, true)) {
                    $matches[] = $person;
                }
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        if (count($matches) > 1) {
            return 'ambiguous';
        }

        return null;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/AssetUserAssignmentService.php
git commit -m "Add AssetUserAssignmentService with tiered UPN/email and name matching"
```

---

### Task 4: Artisan command

**Files:**
- Create: `app/Console/Commands/AssignAssetUsers.php`
- Modify: `routes/console.php`

- [ ] **Step 1: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\AssetUserAssignmentService;
use Illuminate\Console\Command;

class AssignAssetUsers extends Command
{
    protected $signature = 'assets:assign-users
        {--client= : Scope to a single client ID}
        {--dry-run : Log matches without writing}';

    protected $description = 'Auto-assign device users based on RMM last-logged-on-user data';

    public function handle(AssetUserAssignmentService $service): int
    {
        $clientId = $this->option('client');
        $dryRun = $this->option('dry-run');

        if ($clientId) {
            $client = Client::find($clientId);
            if (!$client) {
                $this->error("Client {$clientId} not found.");
                return 1;
            }
            $this->info("Scoping to client: {$client->name}");
        }

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be written.');
        }

        $stats = $service->assignAll($clientId ? (int) $clientId : null, $dryRun);

        $this->info("Processed: {$stats['processed']}");
        $this->info("  Matched (new):    {$stats['matched']}");
        $this->info("  Already linked:   {$stats['already_linked']}");
        $this->info("  No match:         {$stats['no_match']}");
        $this->info("  Ambiguous:        {$stats['ambiguous']}");

        return 0;
    }
}
```

- [ ] **Step 2: Schedule the command**

In `routes/console.php`, find the daily sync commands section (around the 05:00-06:00 window). Add after the NinjaRMM sync commands:

```php
Schedule::command('assets:assign-users')
    ->dailyAt('06:15')
    ->withoutOverlapping()
    ->runInBackground();
```

Place it after `billing:generate` (06:00) so it runs after all vendor syncs have completed.

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/AssignAssetUsers.php routes/console.php
git commit -m "Add assets:assign-users artisan command, schedule daily at 06:15"
```

---

## Chunk 2: UI — Asset Detail & Management

### Task 5: Asset user management routes and controller methods

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Web/AssetController.php`

- [ ] **Step 1: Add routes**

In `routes/web.php`, find the existing asset routes (after the asset CRUD routes). Add:

```php
Route::post('/assets/{asset}/users', [AssetController::class, 'addUser'])->name('assets.add-user');
Route::delete('/assets/{asset}/users/{person}', [AssetController::class, 'removeUser'])->name('assets.remove-user');
Route::post('/assets/{asset}/users/{person}/primary', [AssetController::class, 'setPrimaryUser'])->name('assets.set-primary-user');
```

- [ ] **Step 2: Add controller methods**

Add these imports to `AssetController.php`:
```php
use App\Models\Person;
use App\Enums\AssetAssignmentSource;
```

Add these methods:

```php
public function addUser(Request $request, Asset $asset)
{
    $request->validate([
        'person_id' => ['required', 'exists:people,id'],
    ]);

    $personId = $request->input('person_id');

    // Verify person belongs to same client
    $person = Person::where('id', $personId)->where('client_id', $asset->client_id)->firstOrFail();

    // Check if already linked
    if ($asset->users()->where('person_id', $personId)->exists()) {
        return redirect()->route('assets.show', $asset)
            ->with('warning', "{$person->full_name} is already linked to this device.");
    }

    $asset->users()->attach($personId, [
        'is_primary' => false,
        'assignment_source' => AssetAssignmentSource::Manual->value,
        'last_seen_at' => null,
    ]);

    return redirect()->route('assets.show', $asset)
        ->with('success', "{$person->full_name} linked to this device.");
}

public function removeUser(Asset $asset, Person $person)
{
    $asset->users()->detach($person->id);

    return redirect()->route('assets.show', $asset)
        ->with('success', "{$person->full_name} removed from this device.");
}

public function setPrimaryUser(Asset $asset, Person $person)
{
    // Clear existing primary
    DB::table('asset_person')
        ->where('asset_id', $asset->id)
        ->where('is_primary', true)
        ->update(['is_primary' => false]);

    // Set new primary
    DB::table('asset_person')
        ->where('asset_id', $asset->id)
        ->where('person_id', $person->id)
        ->update(['is_primary' => true]);

    return redirect()->route('assets.show', $asset)
        ->with('success', "{$person->full_name} set as primary user.");
}
```

Add `use Illuminate\Support\Facades\DB;` import if not already present.

- [ ] **Step 3: Update show() to eager-load users**

In the `show()` method, find where the asset is loaded with relationships. Add `'users'` to the eager load. Also load client people for the "Add User" dropdown:

After the existing asset loading, add:
```php
$clientPeople = Person::where('client_id', $asset->client_id)
    ->where('is_active', true)
    ->orderBy('last_name')
    ->orderBy('first_name')
    ->get(['id', 'first_name', 'last_name']);
```

Pass `$clientPeople` to the view.

- [ ] **Step 4: Commit**

```bash
git add routes/web.php app/Http/Controllers/Web/AssetController.php
git commit -m "Add asset user management routes and controller methods"
```

---

### Task 6: Asset detail page — Users card

**Files:**
- Modify: `resources/views/assets/show.blade.php`

- [ ] **Step 1: Add Users card to the Overview tab**

Read the file first to find the right location. Add a Users card in the Overview tab, after the existing Device Identity / Hardware / Status cards. Place it where it fits the two-column layout.

```blade
{{-- Users --}}
<div class="card shadow-sm card-static mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people me-2"></i>Users</span>
    </div>
    @if($asset->users->isEmpty())
        <div class="card-body text-muted text-center py-3 small">
            No users assigned to this device.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <tbody>
                    @foreach($asset->users->sortByDesc('pivot.is_primary') as $user)
                        <tr>
                            <td>
                                <x-person-badge :person="$user" :size="20" />
                                @if($user->pivot->is_primary)
                                    <span class="badge bg-warning text-dark ms-1">Primary</span>
                                @endif
                            </td>
                            <td class="small text-muted">
                                <span class="badge {{ $user->pivot->assignment_source === 'manual' ? 'bg-primary' : 'bg-secondary' }}">
                                    {{ ucfirst($user->pivot->assignment_source) }}
                                </span>
                            </td>
                            <td class="small text-muted">
                                @if($user->pivot->last_seen_at)
                                    {{ \Carbon\Carbon::parse($user->pivot->last_seen_at)->diffForHumans() }}
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @unless($user->pivot->is_primary)
                                    <form method="POST" action="{{ route('assets.set-primary-user', [$asset, $user]) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-warning btn-sm py-0 px-1" title="Set as primary">
                                            <i class="bi bi-star"></i>
                                        </button>
                                    </form>
                                @endunless
                                <form method="POST" action="{{ route('assets.remove-user', [$asset, $user]) }}" class="d-inline"
                                      onsubmit="return confirm('Remove {{ $user->full_name }} from this device?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Remove">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <div class="card-footer">
        <form method="POST" action="{{ route('assets.add-user', $asset) }}" class="d-flex gap-2">
            @csrf
            <select name="person_id" class="form-select form-select-sm" required>
                <option value="">Add user...</option>
                @foreach($clientPeople as $p)
                    <option value="{{ $p->id }}">{{ $p->last_name }}, {{ $p->first_name }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary btn-sm text-nowrap">
                <i class="bi bi-plus-lg me-1"></i>Add
            </button>
        </form>
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/assets/show.blade.php
git commit -m "Add Users card to asset detail page with add/remove/set-primary actions"
```

---

## Chunk 3: Asset Index Filter, Person Detail, Verification

### Task 7: Asset index — assignment filter and primary user column

**Files:**
- Modify: `resources/views/assets/_list.blade.php`
- Modify: `app/Http/Controllers/Web/AssetController.php` (the `indexAll()` method)

- [ ] **Step 1: Add filter to the controller**

Read `AssetController::indexAll()` to see how filters are built. Add support for a `user_assignment` filter:

In the query building section, add:
```php
if (!empty($filters['user_assignment'])) {
    if ($filters['user_assignment'] === 'unassigned') {
        $query->whereDoesntHave('users');
    } elseif ($filters['user_assignment'] === 'assigned') {
        $query->whereHas('users');
    }
}
```

Add `'user_assignment' => $request->query('user_assignment')` to the `$filters` array.

Eager-load the primary user for display: add `'users'` to the `with()` call, or use a subquery to load just the primary user efficiently:
```php
->with(['users' => fn ($q) => $q->wherePivot('is_primary', true)])
```

- [ ] **Step 2: Add filter dropdown to the view**

In the filter card section of `assets/_list.blade.php`, add a dropdown after the existing filters:

```blade
<div class="col-lg-2 col-md-3">
    <select name="user_assignment" class="form-select form-select-sm">
        <option value="">User: Any</option>
        <option value="assigned" {{ ($filters['user_assignment'] ?? '') === 'assigned' ? 'selected' : '' }}>Assigned</option>
        <option value="unassigned" {{ ($filters['user_assignment'] ?? '') === 'unassigned' ? 'selected' : '' }}>Unassigned</option>
    </select>
</div>
```

- [ ] **Step 3: Add Primary User column to the table**

Add a `<th>` header for "Primary User" in the table header (after the Status column or wherever fits best).

Add the corresponding `<td>` in the row:
```blade
<td class="small">
    @php $primaryUser = $asset->users->first(); @endphp
    @if($primaryUser)
        <x-person-badge :person="$primaryUser" :size="18" />
    @else
        <span class="text-muted">—</span>
    @endif
</td>
```

Wrap both in `@if(!$columns || in_array('primary_user', $columns))` to support column configurability.

- [ ] **Step 4: Update the `ClientController::assetList()` method** (if it exists)

Check if there's a `ClientController::assetList()` or similar method that passes asset data for the client detail page. If so, add the same `user_assignment` filter support and primary user eager loading.

- [ ] **Step 5: Commit**

```bash
git add resources/views/assets/_list.blade.php app/Http/Controllers/Web/AssetController.php app/Http/Controllers/Web/ClientController.php
git commit -m "Add user assignment filter and primary user column to asset index"
```

---

### Task 8: Person detail page — Devices section

**Files:**
- Modify: `resources/views/people/show.blade.php`

- [ ] **Step 1: Add Devices card to the Overview tab**

Read the file to find the right location (after the Recent Tickets section). Add:

```blade
{{-- Devices --}}
@if($person->assets->isNotEmpty())
<div class="card shadow-sm card-static mb-4">
    <div class="card-header">
        <i class="bi bi-pc-display me-2"></i>Devices
        <span class="badge bg-light text-dark ms-1">{{ $person->assets->count() }}</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Device</th>
                    <th>Type</th>
                    <th class="text-center">Role</th>
                    <th>Last Seen</th>
                </tr>
            </thead>
            <tbody>
                @foreach($person->assets->sortByDesc('pivot.is_primary') as $asset)
                    <tr class="cursor-pointer" onclick="window.location='{{ route('assets.show', $asset) }}'">
                        <td>
                            <strong>{{ $asset->hostname ?: $asset->name }}</strong>
                            @if($asset->hostname && $asset->hostname !== $asset->name)
                                <br><small class="text-muted">{{ $asset->name }}</small>
                            @endif
                        </td>
                        <td class="small">{{ $asset->asset_type ?: '—' }}</td>
                        <td class="text-center">
                            @if($asset->pivot->is_primary)
                                <span class="badge bg-warning text-dark">Primary</span>
                            @else
                                <span class="text-muted small">User</span>
                            @endif
                        </td>
                        <td class="small">
                            @if($asset->pivot->last_seen_at)
                                {{ \Carbon\Carbon::parse($asset->pivot->last_seen_at)->diffForHumans() }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
```

- [ ] **Step 2: Eager-load assets in PersonController::show()**

In `PersonController::show()`, add `'assets'` to the person's eager loading (or load it separately):

```php
$person->load('assets');
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/people/show.blade.php app/Http/Controllers/Web/PersonController.php
git commit -m "Add Devices section to person detail page"
```

---

### Task 9: Verification and deploy

- [ ] **Step 1: Run the auto-assignment in dry-run mode**

```bash
php artisan assets:assign-users --dry-run
```

Verify it reports stats without writing.

- [ ] **Step 2: Run the auto-assignment for real**

```bash
php artisan assets:assign-users
```

Verify it reports matches and creates `asset_person` rows.

- [ ] **Step 3: Start dev server and verify asset detail**

Verify the Users card appears on an asset detail page. Test:
- Add a user manually
- Set as primary
- Remove a user

- [ ] **Step 4: Verify asset index**

Check the "User: Any / Assigned / Unassigned" filter works. Verify the Primary User column shows.

- [ ] **Step 5: Verify person detail**

Navigate to a person with linked devices. Verify the Devices card shows with correct data.

- [ ] **Step 6: Commit any fixes and deploy**

Use `/deploy` slash command.
