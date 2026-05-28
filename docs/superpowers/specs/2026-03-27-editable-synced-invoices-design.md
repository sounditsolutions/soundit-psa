# Editable Synced Invoices — Design Spec

**Date:** 2026-03-27
**Status:** Approved

## Problem

Invoices synced to QuickBooks Online become permanently locked in the PSA. If a billing mistake is discovered after pushing (e.g., 5 hours of prepaid time should have been 7.5), the only workaround is voiding the invoice and creating a new one. This creates clutter in QBO, changes the invoice number, and is unnecessarily cumbersome for a simple line-item correction.

## Solution

Allow editing of invoices that have already been synced to QBO (statuses: Draft, Synced, Posted). On save, automatically push the updated invoice back to QBO using the QBO API's native update mechanism (same POST endpoint, but with `Id` + `SyncToken` in the payload). Paid and Void invoices remain locked.

Stripe-synced invoices remain non-editable — the Stripe API requires a different update pattern (individual line item manipulation) that is out of scope.

## Design

### 1. Invoice Model — Editability Gate

**File:** `app/Models/Invoice.php` (lines 118-134)

Replace the current `isEditable` accessor:

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

**What changes:**
- Removes the `status !== Draft` early return — now allows Draft, Synced, and Posted.
- Removes the `qbo_invoice_id !== null` block — QBO-synced invoices are now editable.
- Simplifies the Stripe guard — any invoice with a `stripe_invoice_id` is non-editable (whether imported or PSA-originated), since we have no Stripe update path.

**What doesn't change:**
- Paid and Void invoices remain locked.
- The accessor is still used by `InvoiceController::edit()` and `update()` to gate access.

### 2. InvoiceService — Rename and Extend

**File:** `app/Services/InvoiceService.php` (lines 98-203)

Rename `updateDraft()` to `updateInvoice()`. The internal logic is unchanged — it already handles header updates, line add/remove/update, total recalculation, and `quantity_source` audit trail. The only addition is dispatching a QBO push job after save when the invoice is synced.

```php
public function updateInvoice(Invoice $invoice, array $validated, User $user): void
{
    DB::transaction(function () use ($invoice, $validated, $user) {
        // ... existing header + line update logic, completely unchanged ...
    });

    // Auto-push to QBO if already synced (outside transaction — fire-and-forget job)
    if ($invoice->qbo_invoice_id) {
        PushInvoiceUpdate::dispatch($invoice);
    }
}
```

**New job: `PushInvoiceUpdate`** (`app/Jobs/PushInvoiceUpdate.php`)

A small queued job purpose-built for pushing updates to already-synced invoices. Separate from `PushInvoiceToBilling` because that job is designed for initial push of Draft invoices from recurring profiles (it checks `status === Draft` and requires a profile with `auto_push_mode`). The update job has different preconditions:

- Requires `qbo_invoice_id` to be set (this is an update, not a create).
- Skips if status is Paid or Void (invoice was paid/voided between save and job execution).
- Calls `QboSyncService::pushInvoiceToQbo()` which now handles both create and update.
- 2 retries, 60s timeout (same as `PushInvoiceToBilling`).
- On final failure, sets `qbo_sync_error` on the invoice and logs. No notification dispatch (the user will see the sync error on the invoice show page).

```php
class PushInvoiceUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(private readonly Invoice $invoice) {}

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

### 3. QboSyncService — Update Path

**File:** `app/Services/Qbo/QboSyncService.php`, method `pushInvoiceToQbo()` (lines 214-245)

Add an update branch. The QBO API uses the same `POST /v3/company/{realmId}/invoice` endpoint for both create and update. Including `Id` and `SyncToken` in the payload makes it an update.

```php
public function pushInvoiceToQbo(Invoice $invoice): void
{
    $invoice->loadMissing(['client', 'lines']);

    if (!$invoice->client->qbo_customer_id) {
        $error = "Client \"{$invoice->client->name}\" has no QBO customer linked.";
        $invoice->update(['qbo_sync_error' => $error]);
        throw new QboClientException($error);
    }

    $qboData = $this->buildQboInvoice($invoice);

    // UPDATE path: fetch current QBO invoice for SyncToken
    if ($invoice->qbo_invoice_id) {
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
        if ($invoice->qbo_invoice_id && $e->getHttpStatus() === 409) {
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

    if ($invoice->qbo_invoice_id) {
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

**Key decisions:**
- On update, status is **not changed** — stays Synced or Posted.
- On update, `qbo_invoice_id` and `qbo_doc_number` are not overwritten (they're the same).
- Tax and total are refreshed from QBO response (QBO recalculates tax server-side).
- SyncToken conflict (409) is retried once, matching the existing pattern in `voidInvoiceInQbo()`.

### 4. InvoiceController — Wire Up Renamed Method

**File:** `app/Http/Controllers/Web/InvoiceController.php`

`update()` method (line 158): Change `updateDraft()` call to `updateInvoice()`.

```php
$this->invoiceService->updateInvoice($invoice, $request->validated(), $request->user());
```

No other controller changes needed. The `edit()` method already gates on `is_editable`.

### 5. Edit View — QBO Sync Banner

**File:** `resources/views/invoices/edit.blade.php`

Add an info banner between the error block (line 31) and the form (line 33):

```blade
@if($invoice->qbo_invoice_id)
    <div class="alert alert-info d-flex align-items-center mb-3">
        <i class="bi bi-cloud-arrow-up me-2"></i>
        This invoice is synced to QuickBooks. Changes will be pushed automatically on save.
    </div>
@endif
```

No other view changes. The edit form, line item management, and JavaScript all work as-is.

### 6. InvoiceUpdateRequest — No Changes

**File:** `app/Http/Requests/InvoiceUpdateRequest.php`

The validation rules are already correct for this use case. They validate line structure, dates, and line ownership (lines.*.id must belong to the invoice). No status-specific validation exists here — that's handled by the `is_editable` guard in the controller.

## Files Modified

| File | Change |
|------|--------|
| `app/Models/Invoice.php` | Relax `isEditable` accessor |
| `app/Services/InvoiceService.php` | Rename `updateDraft` → `updateInvoice`, add auto-push dispatch |
| `app/Services/Qbo/QboSyncService.php` | Add update branch to `pushInvoiceToQbo()` |
| `app/Jobs/PushInvoiceUpdate.php` | **New file** — queued job for pushing invoice updates to QBO |
| `app/Http/Controllers/Web/InvoiceController.php` | Call renamed method |
| `resources/views/invoices/edit.blade.php` | Add QBO sync info banner |

## What Doesn't Change

- No database migrations
- No new routes
- Invoice line editing UI (same form, same JavaScript)
- Totals recalculation logic
- `quantity_source` audit trail
- Prepay time behavior (deposits on Paid, not on edit)
- InvoiceObserver
- QBO webhook pull path (`syncInvoiceStatusFromQbo`)
- Stripe sync (remains non-editable)
- Portal invoice views (read-only)
- Bulk actions

## Edge Cases

- **User edits, QBO push fails**: Invoice is saved locally with updated values. `qbo_sync_error` is set. User sees the error on the show page and can retry via the existing "Sync to QBO" button, or edit again (which triggers another auto-push).
- **Invoice paid between edit and job execution**: Job checks status and skips if Paid/Void.
- **Concurrent edits**: QBO's SyncToken prevents silent overwrites. 409 conflict retried once.
- **QBO webhook arrives after local edit**: `syncInvoiceStatusFromQbo` pulls the latest QBO state. Since our push already updated QBO, the webhook will reflect our changes — no conflict.
