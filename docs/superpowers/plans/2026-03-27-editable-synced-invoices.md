# Editable Synced Invoices Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow editing invoices already synced to QBO, with automatic push-back on save.

**Architecture:** Relax the `isEditable` model accessor to allow Draft/Synced/Posted (not Paid/Void, not Stripe). Rename `InvoiceService::updateDraft()` to `updateInvoice()` and dispatch a new `PushInvoiceUpdate` job after save when `qbo_invoice_id` is set. Add an update branch to `QboSyncService::pushInvoiceToQbo()` that fetches the current SyncToken and includes `Id` in the POST payload.

**Tech Stack:** Laravel 12, PHP 8.3, QBO REST API v3

**Spec:** `docs/superpowers/specs/2026-03-27-editable-synced-invoices-design.md`

---

### Task 1: Relax Invoice Editability Gate

**Files:**
- Modify: `app/Models/Invoice.php:118-134`

- [ ] **Step 1: Replace the `isEditable` accessor**

In `app/Models/Invoice.php`, replace the existing accessor (lines 118-134):

```php
    public function getIsEditableAttribute(): bool
    {
        if ($this->status !== InvoiceStatus::Draft) {
            return false;
        }

        if ($this->qbo_invoice_id !== null) {
            return false;
        }

        // Stripe-imported invoices (have stripe_invoice_id but no contract_id)
        if ($this->stripe_invoice_id !== null && $this->contract_id === null) {
            return false;
        }

        return true;
    }
```

With:

```php
    public function getIsEditableAttribute(): bool
    {
        if (!in_array($this->status, [InvoiceStatus::Draft, InvoiceStatus::Synced, InvoiceStatus::Posted])) {
            return false;
        }

        // Stripe-synced invoices are not editable (no update path yet)
        if ($this->stripe_invoice_id) {
            return false;
        }

        return true;
    }
```

- [ ] **Step 2: Verify via tinker**

Run: `php artisan tinker --execute="use App\Models\Invoice; use App\Enums\InvoiceStatus; \$i = new Invoice(['status' => InvoiceStatus::Synced]); echo 'Synced editable: ' . (\$i->is_editable ? 'yes' : 'no') . PHP_EOL; \$i2 = new Invoice(['status' => InvoiceStatus::Paid]); echo 'Paid editable: ' . (\$i2->is_editable ? 'yes' : 'no') . PHP_EOL; \$i3 = new Invoice(['status' => InvoiceStatus::Synced, 'stripe_invoice_id' => 'x']); echo 'Stripe editable: ' . (\$i3->is_editable ? 'yes' : 'no') . PHP_EOL;"`

Expected output:
```
Synced editable: yes
Paid editable: no
Stripe editable: no
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/Invoice.php
git commit -m "Relax invoice editability to allow Draft, Synced, and Posted"
```

---

### Task 2: Create PushInvoiceUpdate Job

**Files:**
- Create: `app/Jobs/PushInvoiceUpdate.php`

- [ ] **Step 1: Create the job file**

Create `app/Jobs/PushInvoiceUpdate.php`:

```php
<?php

namespace App\Jobs;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Qbo\QboSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushInvoiceUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(
        private readonly Invoice $invoice,
    ) {}

    public function handle(): void
    {
        $invoice = $this->invoice->fresh(['client', 'lines']);

        if (!$invoice || !$invoice->qbo_invoice_id) {
            return;
        }

        if (in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Void])) {
            Log::info("[QboUpdate] Skipping invoice {$invoice->invoice_number} — status is {$invoice->status->value}");
            return;
        }

        app(QboSyncService::class)->pushInvoiceToQbo($invoice);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[QboUpdate] Failed to push invoice update: {$e->getMessage()}", [
            'invoice_id' => $this->invoice->id,
        ]);

        $this->invoice->update(['qbo_sync_error' => $e->getMessage()]);
    }
}
```

- [ ] **Step 2: Verify the file parses**

Run: `php -l app/Jobs/PushInvoiceUpdate.php`

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Jobs/PushInvoiceUpdate.php
git commit -m "Add PushInvoiceUpdate job for syncing invoice edits to QBO"
```

---

### Task 3: Add Update Branch to QboSyncService

**Files:**
- Modify: `app/Services/Qbo/QboSyncService.php:214-245`

- [ ] **Step 1: Replace `pushInvoiceToQbo()` method**

In `app/Services/Qbo/QboSyncService.php`, replace the `pushInvoiceToQbo()` method (lines 214-245):

```php
    public function pushInvoiceToQbo(Invoice $invoice): void
    {
        $invoice->loadMissing(['client', 'lines']);

        // Validate client has QBO customer linked
        if (!$invoice->client->qbo_customer_id) {
            $error = "Client \"{$invoice->client->name}\" has no QBO customer linked. Go to Settings → QBO Client Matching.";
            $invoice->update(['qbo_sync_error' => $error]);
            throw new QboClientException($error);
        }

        $qboData = $this->buildQboInvoice($invoice);

        try {
            $response = $this->qboClient->post('invoice', $qboData);
        } catch (QboClientException $e) {
            $invoice->update(['qbo_sync_error' => $e->getMessage()]);
            throw $e;
        }

        $qboInvoice = $response['Invoice'] ?? $response;

        $invoice->update([
            'qbo_invoice_id' => $qboInvoice['Id'] ?? null,
            'qbo_doc_number' => $qboInvoice['DocNumber'] ?? null,
            'tax' => $qboInvoice['TxnTaxDetail']['TotalTax'] ?? 0,
            'total' => $qboInvoice['TotalAmt'] ?? $invoice->subtotal,
            'status' => InvoiceStatus::Synced,
            'qbo_synced_at' => now(),
            'qbo_sync_error' => null,
        ]);
    }
```

With:

```php
    public function pushInvoiceToQbo(Invoice $invoice): void
    {
        $invoice->loadMissing(['client', 'lines']);

        // Validate client has QBO customer linked
        if (!$invoice->client->qbo_customer_id) {
            $error = "Client \"{$invoice->client->name}\" has no QBO customer linked. Go to Settings → QBO Client Matching.";
            $invoice->update(['qbo_sync_error' => $error]);
            throw new QboClientException($error);
        }

        $qboData = $this->buildQboInvoice($invoice);
        $isUpdate = (bool) $invoice->qbo_invoice_id;

        // UPDATE path: fetch current QBO invoice for SyncToken
        if ($isUpdate) {
            try {
                $current = $this->qboClient->get("invoice/{$invoice->qbo_invoice_id}");
            } catch (QboClientException $e) {
                $invoice->update(['qbo_sync_error' => $e->getMessage()]);
                throw $e;
            }

            $currentInvoice = $current['Invoice'] ?? $current;
            $qboData['Id'] = $currentInvoice['Id'];
            $qboData['SyncToken'] = $currentInvoice['SyncToken'];
        }

        try {
            $response = $this->qboClient->post('invoice', $qboData);
        } catch (QboClientException $e) {
            // Retry once on 409 SyncToken conflict (same pattern as voidInvoiceInQbo)
            if ($isUpdate && $e->getHttpStatus() === 409) {
                Log::warning('[QboSync] SyncToken conflict updating invoice, retrying', [
                    'invoice_id' => $invoice->id,
                ]);
                $retry = $this->qboClient->get("invoice/{$invoice->qbo_invoice_id}");
                $retryInvoice = $retry['Invoice'] ?? $retry;
                $qboData['Id'] = $retryInvoice['Id'];
                $qboData['SyncToken'] = $retryInvoice['SyncToken'];
                $response = $this->qboClient->post('invoice', $qboData);
            } else {
                $invoice->update(['qbo_sync_error' => $e->getMessage()]);
                throw $e;
            }
        }

        $qboInvoice = $response['Invoice'] ?? $response;

        if ($isUpdate) {
            // UPDATE: refresh tax/total and sync timestamp, keep current status
            $invoice->update([
                'tax' => $qboInvoice['TxnTaxDetail']['TotalTax'] ?? $invoice->tax,
                'total' => $qboInvoice['TotalAmt'] ?? $invoice->subtotal,
                'qbo_synced_at' => now(),
                'qbo_sync_error' => null,
            ]);
        } else {
            // CREATE: store QBO IDs and transition to Synced
            $invoice->update([
                'qbo_invoice_id' => $qboInvoice['Id'] ?? null,
                'qbo_doc_number' => $qboInvoice['DocNumber'] ?? null,
                'tax' => $qboInvoice['TxnTaxDetail']['TotalTax'] ?? 0,
                'total' => $qboInvoice['TotalAmt'] ?? $invoice->subtotal,
                'status' => InvoiceStatus::Synced,
                'qbo_synced_at' => now(),
                'qbo_sync_error' => null,
            ]);
        }
    }
```

- [ ] **Step 2: Verify the file parses**

Run: `php -l app/Services/Qbo/QboSyncService.php`

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Services/Qbo/QboSyncService.php
git commit -m "Add QBO invoice update path with SyncToken and 409 retry"
```

---

### Task 4: Rename updateDraft and Add Auto-Push Dispatch

**Files:**
- Modify: `app/Services/InvoiceService.php:98`
- Modify: `app/Http/Controllers/Web/InvoiceController.php:158`

- [ ] **Step 1: Rename method and add job dispatch in InvoiceService**

In `app/Services/InvoiceService.php`, make two changes:

1. Add the import at the top of the file (after the existing `use` statements around line 6-13):

```php
use App\Jobs\PushInvoiceUpdate;
```

2. Rename the method on line 98 from `updateDraft` to `updateInvoice`, and add the auto-push dispatch after the transaction closure (after line 202's closing `});`):

Replace:
```php
    public function updateDraft(Invoice $invoice, array $validated, User $user): void
    {
        DB::transaction(function () use ($invoice, $validated, $user) {
```

With:
```php
    public function updateInvoice(Invoice $invoice, array $validated, User $user): void
    {
        DB::transaction(function () use ($invoice, $validated, $user) {
```

Then after the closing of the transaction (after `});` on line 202), before the method's closing brace, add:

```php

        // Auto-push to QBO if already synced (outside transaction — fire-and-forget job)
        if ($invoice->qbo_invoice_id) {
            PushInvoiceUpdate::dispatch($invoice);
        }
```

- [ ] **Step 2: Update the controller call**

In `app/Http/Controllers/Web/InvoiceController.php`, line 158, replace:

```php
        $this->invoiceService->updateDraft($invoice, $request->validated(), $request->user());
```

With:

```php
        $this->invoiceService->updateInvoice($invoice, $request->validated(), $request->user());
```

- [ ] **Step 3: Verify both files parse**

Run: `php -l app/Services/InvoiceService.php && php -l app/Http/Controllers/Web/InvoiceController.php`

Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add app/Services/InvoiceService.php app/Http/Controllers/Web/InvoiceController.php
git commit -m "Rename updateDraft to updateInvoice and auto-push edits to QBO"
```

---

### Task 5: Add QBO Sync Banner to Edit View

**Files:**
- Modify: `resources/views/invoices/edit.blade.php`

- [ ] **Step 1: Add the info banner**

In `resources/views/invoices/edit.blade.php`, add the following block between the error block closing `@endif` (line 31) and the `<form>` tag (line 33):

```blade

@if($invoice->qbo_invoice_id)
    <div class="alert alert-info d-flex align-items-center mb-3">
        <i class="bi bi-cloud-arrow-up me-2"></i>
        This invoice is synced to QuickBooks. Changes will be pushed automatically on save.
    </div>
@endif

```

- [ ] **Step 2: Commit**

```bash
git add resources/views/invoices/edit.blade.php
git commit -m "Add QBO sync info banner on invoice edit form"
```

---

### Task 6: End-to-End Verification

- [ ] **Step 1: Start the dev server**

Run: `php -S 127.0.0.1:8080 -t public` (background)

- [ ] **Step 2: Verify a Synced invoice shows the Edit button**

Use the dev login and visit a synced invoice's show page. The Edit button should now appear for invoices with status Synced or Posted (it was previously hidden). Confirm:

1. Navigate to `/invoices` and find an invoice with status Synced or Posted that has a `qbo_invoice_id`.
2. Click to view it — the Edit button should be visible.
3. Click Edit — the edit form should load with the QBO sync info banner at the top.
4. Confirm the banner text reads: "This invoice is synced to QuickBooks. Changes will be pushed automatically on save."

- [ ] **Step 3: Verify a Paid invoice does NOT show Edit**

Find a Paid invoice and confirm the Edit button is not shown.

- [ ] **Step 4: Verify a Stripe invoice does NOT show Edit**

If any Stripe-synced invoices exist, confirm Edit is not shown.

- [ ] **Step 5: Verify a Draft invoice still works as before**

Edit a Draft invoice (no QBO sync). Confirm:
- No QBO banner is shown
- Save works normally
- No job is dispatched (check `php artisan queue:work` output — nothing should fire)

- [ ] **Step 6: Stop dev server**

Kill the server process.
