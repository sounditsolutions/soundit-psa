# Auto-Push Invoices to Billing Backend Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-profile auto-push setting so generated invoices are immediately pushed to QBO or Stripe, with failure notifications for both generation and push errors.

**Architecture:** `InvoiceObserver::created()` dispatches a queued `PushInvoiceToBilling` job when a profile has auto-push enabled. The job determines the billing backend from client mapping and calls the existing sync service. Two new `NotificationEventType` values handle failure alerts via the existing notification infrastructure.

**Tech Stack:** Laravel 12, PHP 8.3, MariaDB, Blade, Bootstrap 5.3

**Spec:** `docs/superpowers/specs/2026-03-17-auto-push-invoices-design.md`

---

## Chunk 1: Data Model & Backend Logic

### Task 1: Migration — add `auto_push_mode` to profiles

**Files:**
- Create: `database/migrations/2026_03_17_000001_add_auto_push_mode_to_profiles.php`

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
        Schema::table('recurring_invoice_profiles', function (Blueprint $table) {
            $table->string('auto_push_mode', 20)->nullable()->after('skip_zero_invoices');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoice_profiles', function (Blueprint $table) {
            $table->dropColumn('auto_push_mode');
        });
    }
};
```

- [ ] **Step 2: Run migration**

Run: `php artisan migrate`
Expected: Migration runs successfully, column added.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_03_17_000001_add_auto_push_mode_to_profiles.php
git commit -m "Add auto_push_mode column to recurring_invoice_profiles"
```

---

### Task 2: AutoPushMode enum

**Files:**
- Create: `app/Enums/AutoPushMode.php`

- [ ] **Step 1: Create the enum**

```php
<?php

namespace App\Enums;

enum AutoPushMode: string
{
    case Push = 'push';
    case PushAndSend = 'push_and_send';

    public function label(): string
    {
        return match ($this) {
            self::Push => 'Push on generation',
            self::PushAndSend => 'Push and send on generation',
        };
    }
}
```

- [ ] **Step 2: Update RecurringInvoiceProfile model**

Modify: `app/Models/RecurringInvoiceProfile.php`

Add `'auto_push_mode'` to the `$fillable` array (after `'skip_zero_invoices'`).

Add to the `casts()` method return array:
```php
'auto_push_mode' => AutoPushMode::class,
```

Add the `use App\Enums\AutoPushMode;` import at the top of the file.

- [ ] **Step 3: Commit**

```bash
git add app/Enums/AutoPushMode.php app/Models/RecurringInvoiceProfile.php
git commit -m "Add AutoPushMode enum and wire to profile model"
```

---

### Task 3: NotificationEventType — add billing failure events

**Files:**
- Modify: `app/Enums/NotificationEventType.php`

- [ ] **Step 1: Add two new enum cases**

Add after the last existing case (`PrepayAutoTopUp = 'prepay_auto_topup'`):

```php
case InvoiceGenerationFailed = 'invoice_generation_failed';
case InvoicePushFailed = 'invoice_push_failed';
```

- [ ] **Step 2: Add entries to `label()` match**

```php
self::InvoiceGenerationFailed => 'Invoice generation failed',
self::InvoicePushFailed => 'Invoice push failed',
```

- [ ] **Step 3: Add entries to `description()` match**

```php
self::InvoiceGenerationFailed => 'When automatic invoice generation fails for a billing profile',
self::InvoicePushFailed => 'When an invoice fails to push to QBO or Stripe',
```

- [ ] **Step 4: Add entries to `icon()` match**

```php
self::InvoiceGenerationFailed => 'bi-exclamation-triangle',
self::InvoicePushFailed => 'bi-exclamation-triangle',
```

- [ ] **Step 5: Commit**

```bash
git add app/Enums/NotificationEventType.php
git commit -m "Add InvoiceGenerationFailed and InvoicePushFailed notification event types"
```

---

### Task 4: NotificationService — add failure notification methods

**Files:**
- Modify: `app/Services/NotificationService.php`

- [ ] **Step 1: Add `notifyInvoiceGenerationFailed()` method**

Add at the end of the class, before the closing brace. Follow the `notifyPrepayLowBalance()` pattern — staff-only (no portal users for billing failures):

```php
public function notifyInvoiceGenerationFailed(\App\Models\RecurringInvoiceProfile $profile, string $error): void
{
    $context = json_encode([
        'profile' => $profile->name,
        'contract' => $profile->contract?->name,
        'client' => $profile->contract?->client?->name,
        'error' => $error,
    ]);

    $users = User::where('is_active', true)->whereNotNull('email')->get();

    foreach ($users as $user) {
        if ($user->wantsNotification(NotificationEventType::InvoiceGenerationFailed)) {
            SendTicketNotification::dispatch(
                $user->id,
                NotificationEventType::InvoiceGenerationFailed->value,
                null,
                null,
                $context,
            );
        }
    }
}
```

- [ ] **Step 2: Add `notifyInvoicePushFailed()` method**

```php
public function notifyInvoicePushFailed(\App\Models\Invoice $invoice, string $backend, string $error): void
{
    $context = json_encode([
        'invoice_number' => $invoice->invoice_number,
        'client' => $invoice->client?->name,
        'backend' => $backend,
        'error' => $error,
    ]);

    $users = User::where('is_active', true)->whereNotNull('email')->get();

    foreach ($users as $user) {
        if ($user->wantsNotification(NotificationEventType::InvoicePushFailed)) {
            SendTicketNotification::dispatch(
                $user->id,
                NotificationEventType::InvoicePushFailed->value,
                null,
                null,
                $context,
            );
        }
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/NotificationService.php
git commit -m "Add notification methods for invoice generation and push failures"
```

---

### Task 5: PushInvoiceToBilling job

**Files:**
- Create: `app/Jobs/PushInvoiceToBilling.php`

- [ ] **Step 1: Create the queued job**

```php
<?php

namespace App\Jobs;

use App\Enums\AutoPushMode;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\NotificationService;
use App\Services\Qbo\QboSyncService;
use App\Services\Stripe\StripeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushInvoiceToBilling implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(
        private readonly Invoice $invoice,
    ) {}

    public function handle(NotificationService $notificationService): void
    {
        $invoice = $this->invoice->fresh(['client', 'profile']);

        if (!$invoice || $invoice->status !== InvoiceStatus::Draft) {
            Log::info("[AutoPush] Skipping invoice {$this->invoice->id} — status is no longer Draft");
            return;
        }

        $mode = $invoice->profile?->auto_push_mode;
        if (!$mode) {
            return;
        }

        $client = $invoice->client;
        $backend = null;

        try {
            if ($client->stripe_customer_id) {
                $backend = 'Stripe';
                $sendEmail = $mode === AutoPushMode::PushAndSend;
                app(StripeSyncService::class)->pushInvoiceToStripe($invoice, $sendEmail);
            } elseif ($client->qbo_customer_id) {
                $backend = 'QBO';
                app(QboSyncService::class)->pushInvoiceToQbo($invoice);
            } else {
                Log::warning("[AutoPush] Invoice {$invoice->invoice_number}: client \"{$client->name}\" not mapped to any billing backend");
                $notificationService->notifyInvoicePushFailed(
                    $invoice,
                    'None',
                    "Client \"{$client->name}\" is not mapped to QBO or Stripe.",
                );
                return;
            }

            Log::info("[AutoPush] Invoice {$invoice->invoice_number} pushed to {$backend}" . ($mode === AutoPushMode::PushAndSend ? ' (sent)' : ''));
        } catch (\Throwable $e) {
            Log::error("[AutoPush] Failed to push invoice {$invoice->invoice_number} to {$backend}: {$e->getMessage()}");

            // Only notify on final attempt to avoid duplicate notifications
            if ($this->attempts() >= $this->tries) {
                $notificationService->notifyInvoicePushFailed($invoice, $backend, $e->getMessage());
            }

            throw $e; // Re-throw so the job retries
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Jobs/PushInvoiceToBilling.php
git commit -m "Add PushInvoiceToBilling queued job"
```

---

### Task 6: InvoiceObserver — dispatch auto-push on creation

**Files:**
- Modify: `app/Observers/InvoiceObserver.php`

- [ ] **Step 1: Add `created()` method**

Add the `use App\Jobs\PushInvoiceToBilling;` import at the top.

Add this method before the existing `updated()` method:

```php
public function created(Invoice $invoice): void
{
    if ($invoice->profile_id && $invoice->profile?->auto_push_mode) {
        PushInvoiceToBilling::dispatch($invoice)->afterCommit();
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Observers/InvoiceObserver.php
git commit -m "Dispatch PushInvoiceToBilling from InvoiceObserver::created()"
```

---

### Task 7: BillingService — add generation failure notifications

**Files:**
- Modify: `app/Services/BillingService.php`

- [ ] **Step 1: Add notification call in the catch block of `generateInvoicesForDueProfiles()`**

Find the `catch (\Throwable $e)` block inside the foreach loop in `generateInvoicesForDueProfiles()` (around line 222). After the existing `Log::error(...)` line, add:

```php
try {
    app(NotificationService::class)->notifyInvoiceGenerationFailed($profile, $e->getMessage());
} catch (\Throwable $notifyError) {
    Log::warning("[Billing] Failed to send generation failure notification: {$notifyError->getMessage()}");
}
```

Add the `use App\Services\NotificationService;` import at the top of the file if not already present.

- [ ] **Step 2: Commit**

```bash
git add app/Services/BillingService.php
git commit -m "Notify staff on invoice generation failures"
```

---

## Chunk 2: Controller & UI

### Task 8: RecurringProfileController — handle auto_push_mode in store/update

**Files:**
- Modify: `app/Http/Controllers/Web/RecurringProfileController.php`

- [ ] **Step 1: Add validation and handling in `store()`**

In the `store()` validation rules array, add after the `skip_zero_invoices` rule:

```php
'auto_push_mode' => ['nullable', 'in:push,push_and_send'],
```

In the profile creation data (the array passed to `RecurringInvoiceProfile::create()`), add:

```php
'auto_push_mode' => $validated['auto_push_mode'] ?? null,
```

- [ ] **Step 2: Add validation and handling in `update()`**

Same changes as store — add the validation rule and include `auto_push_mode` in the profile update data.

In the `$profile->update([...])` call, add:

```php
'auto_push_mode' => $validated['auto_push_mode'] ?? null,
```

- [ ] **Step 3: Add bulk action cases in `bulkAction()`**

Add `enable_auto_push,enable_auto_push_send,disable_auto_push` to the action validation `in:` rule:

```php
'action' => ['required', 'in:edit,activate,deactivate,set_quantity_type,enable_auto_push,enable_auto_push_send,disable_auto_push'],
```

Add the new cases inside the switch/match on `$action` (after the existing `set_quantity_type` case):

```php
case 'enable_auto_push':
    $affected = RecurringInvoiceProfile::whereIn('id', $profileIds)->update(['auto_push_mode' => 'push']);
    $message = "{$affected} profile(s) set to auto-push.";
    break;

case 'enable_auto_push_send':
    $affected = RecurringInvoiceProfile::whereIn('id', $profileIds)->update(['auto_push_mode' => 'push_and_send']);
    $message = "{$affected} profile(s) set to auto-push and send.";
    break;

case 'disable_auto_push':
    $affected = RecurringInvoiceProfile::whereIn('id', $profileIds)->whereNotNull('auto_push_mode')->update(['auto_push_mode' => null]);
    $message = "{$affected} profile(s) auto-push disabled.";
    break;
```

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Web/RecurringProfileController.php
git commit -m "Handle auto_push_mode in profile store, update, and bulk actions"
```

---

### Task 9: Profile detail page — add auto-push select

**Files:**
- Modify: `resources/views/profiles/show.blade.php`

- [ ] **Step 1: Add auto-push select dropdown**

Find the `skip_zero_invoices` `<div class="mb-3">` block in the Settings card (around line 122). After the closing `</div>` of that block, add:

```blade
@php $pushVal = old('auto_push_mode', $profile->auto_push_mode?->value); @endphp
<div class="mb-3">
    <label for="auto_push_mode" class="form-label">Auto-push to billing</label>
    <select class="form-select" id="auto_push_mode" name="auto_push_mode">
        <option value="" {{ $pushVal === null || $pushVal === '' ? 'selected' : '' }}>Disabled</option>
        <option value="push" {{ $pushVal === 'push' ? 'selected' : '' }}>Push on generation</option>
        <option value="push_and_send" {{ $pushVal === 'push_and_send' ? 'selected' : '' }}>Push and send on generation</option>
    </select>
    <div class="form-text">
        Automatically push generated invoices to QBO or Stripe. "Push and send" also emails the invoice to the customer via Stripe. Client must be mapped to a billing backend.
    </div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/profiles/show.blade.php
git commit -m "Add auto-push select dropdown to profile detail page"
```

---

### Task 10: Profiles index page — add auto-push badge and bulk actions

**Files:**
- Modify: `resources/views/profiles/index.blade.php`

- [ ] **Step 1: Add auto-push badge to table rows**

In the table row for each profile, after the Source column `<td>` (around line 152-154), add a new column:

```blade
<td class="small text-center">
    @if($profile->auto_push_mode)
        <span class="badge bg-info" title="{{ $profile->auto_push_mode->label() }}">
            <i class="bi bi-arrow-up-circle me-1"></i>{{ $profile->auto_push_mode === \App\Enums\AutoPushMode::PushAndSend ? 'Push+Send' : 'Push' }}
        </span>
    @endif
</td>
```

- [ ] **Step 2: Add column header**

Add a new `<th>` in the table header row, after the "Source" `<th>` (around line 98):

```blade
<th class="text-center">Auto-push</th>
```

- [ ] **Step 3: Update colspan on the expandable line-items row**

Find the `<td colspan="12"` in the collapsible profile lines row (around line 158) and update the colspan to `"13"` to account for the new column.

- [ ] **Step 4: Add bulk action buttons**

In the bulk action bar `<div id="bulkBar">` (around line 206-221), add three new buttons after the "Set Qty Type" button:

```blade
<button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('enable_auto_push')">
    <i class="bi bi-arrow-up-circle me-1"></i>Auto-push
</button>
<button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('enable_auto_push_send')">
    <i class="bi bi-send me-1"></i>Auto-push+Send
</button>
<button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('disable_auto_push')">
    <i class="bi bi-x-circle me-1"></i>No auto-push
</button>
```

- [ ] **Step 5: Add modal content for the new bulk actions**

In the `openBulkModal()` JavaScript function (around line 352), add `else if` branches after the `set_quantity_type` branch:

```javascript
} else if (action === 'enable_auto_push') {
    title.textContent = 'Enable Auto-Push on ' + count + ' Profile(s)';
    body.innerHTML = '<p>Enable <strong>push on generation</strong> for <strong>' + count + '</strong> profile(s)?</p>' +
        '<p class="text-muted small">Invoices will be automatically pushed to QBO or Stripe when generated. Clients must be mapped to a billing backend.</p>';
    btn.textContent = 'Enable';
    btn.className = 'btn btn-primary btn-sm';
} else if (action === 'enable_auto_push_send') {
    title.textContent = 'Enable Auto-Push+Send on ' + count + ' Profile(s)';
    body.innerHTML = '<p>Enable <strong>push and send on generation</strong> for <strong>' + count + '</strong> profile(s)?</p>' +
        '<p class="text-muted small">Invoices will be automatically pushed and emailed to the customer via Stripe when generated. For QBO clients, this behaves the same as push-only.</p>';
    btn.textContent = 'Enable';
    btn.className = 'btn btn-primary btn-sm';
} else if (action === 'disable_auto_push') {
    title.textContent = 'Disable Auto-Push on ' + count + ' Profile(s)';
    body.innerHTML = '<p>Disable auto-push for <strong>' + count + '</strong> profile(s)?</p>' +
        '<p class="text-muted small">Invoices will remain as Draft after generation until manually pushed.</p>';
    btn.textContent = 'Disable';
    btn.className = 'btn btn-warning btn-sm';
}
```

- [ ] **Step 6: Commit**

```bash
git add resources/views/profiles/index.blade.php
git commit -m "Add auto-push badge and bulk actions to profiles index"
```

---

## Chunk 3: Verification & Deploy

### Task 11: Manual verification

- [ ] **Step 1: Start dev server and verify profile detail page**

Run: `php -S 127.0.0.1:8080 -t public &`

Log in via dev bypass, navigate to a profile detail page. Verify the auto-push select dropdown appears below "Skip Empty Invoices" with three options: Disabled, Push on generation, Push and send on generation.

- [ ] **Step 2: Verify profile save round-trip**

Set auto-push to "Push on generation" on a profile, save, reload the page. Verify the selection persists.

Set it to "Push and send on generation", save, reload. Verify.

Set it back to "Disabled", save, reload. Verify it shows Disabled.

- [ ] **Step 3: Verify profiles index page**

Navigate to the profiles index page. Verify:
- Auto-push column header appears
- Profiles with auto-push enabled show the appropriate badge
- Profiles without auto-push show no badge
- Bulk action buttons appear: "Auto-push", "Auto-push+Send", "No auto-push"

- [ ] **Step 4: Test bulk action**

Select a few profiles, click "Auto-push". Confirm in the modal. Verify the profiles now show the "Push" badge.

Select them again, click "No auto-push". Confirm. Verify badges are removed.

- [ ] **Step 5: Verify notification preferences**

Navigate to the notification preferences page. Verify two new checkboxes appear:
- "Invoice generation failed"
- "Invoice push failed"

- [ ] **Step 6: Kill dev server**

Run: `fuser -k 8080/tcp`

- [ ] **Step 7: Commit any fixes**

If any issues were found and fixed during verification, commit the fixes.

---

### Task 12: Deploy

- [ ] **Step 1: Deploy to VPS**

Use `/deploy` slash command.
