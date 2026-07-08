# Prepay Low-Balance Alerts & Auto Top-Up Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Notify clients when prepay balance drops below a threshold and optionally auto-generate a top-up invoice.

**Architecture:** New columns on `contracts` table for alert config. New `PrepayAlertService` orchestrates threshold checks, triggered both real-time (from `PrepayService::debitFromTicketNote()`) and hourly via cron. Auto top-up reuses `PrepayOrderService::createPrepayInvoice()`. Notifications go to company-wide portal users and opted-in staff.

**Tech Stack:** Laravel 12, Blade, Bootstrap 5.3 CDN, MariaDB

---

### Task 1: Migration — Add alert columns to contracts table

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_prepay_alert_columns_to_contracts.php`
- Modify: `app/Models/Contract.php`

**Step 1: Create migration**

```bash
php artisan make:migration add_prepay_alert_columns_to_contracts --table=contracts
```

**Step 2: Write migration**

```php
public function up(): void
{
    Schema::table('contracts', function (Blueprint $table) {
        $table->decimal('prepay_alert_threshold', 10, 2)->nullable()->after('portal_prepay_sku_id');
        $table->unsignedInteger('prepay_auto_topup_qty')->nullable()->after('prepay_alert_threshold');
        $table->boolean('prepay_auto_topup_enabled')->default(false)->after('prepay_auto_topup_qty');
        $table->timestamp('prepay_alert_notified_at')->nullable()->after('prepay_auto_topup_enabled');
    });
}

public function down(): void
{
    Schema::table('contracts', function (Blueprint $table) {
        $table->dropColumn(['prepay_alert_threshold', 'prepay_auto_topup_qty', 'prepay_auto_topup_enabled', 'prepay_alert_notified_at']);
    });
}
```

**Step 3: Update Contract model**

In `app/Models/Contract.php`:

Add to `$fillable` array (after `portal_prepay_sku_id` on line 41):
```php
'prepay_alert_threshold',
'prepay_auto_topup_qty',
'prepay_auto_topup_enabled',
'prepay_alert_notified_at',
```

Add to `casts()` method (after `prepay_as_amount` cast on line 61):
```php
'prepay_alert_threshold' => 'decimal:2',
'prepay_auto_topup_qty' => 'integer',
'prepay_auto_topup_enabled' => 'boolean',
'prepay_alert_notified_at' => 'datetime',
```

**Step 4: Run migration**

```bash
php artisan migrate
```

**Step 5: Commit**

```bash
git add database/migrations/*prepay_alert* app/Models/Contract.php
git commit -m "Add prepay alert threshold and auto top-up columns to contracts"
```

---

### Task 2: NotificationEventType — Add new event types

**Files:**
- Modify: `app/Enums/NotificationEventType.php`

**Step 1: Add enum cases**

After line 17 (`case NewVoicemail`), add:
```php
case PrepayLowBalance = 'prepay_low_balance';
case PrepayAutoTopUp = 'prepay_auto_topup';
```

**Step 2: Add labels to `label()` match**

After `self::NewVoicemail` (line 32):
```php
self::PrepayLowBalance => 'Low prepay balance alerts',
self::PrepayAutoTopUp => 'Prepay auto top-up invoices',
```

**Step 3: Add descriptions to `description()` match**

After `self::NewVoicemail` (line 49):
```php
self::PrepayLowBalance => 'When a client\'s prepay balance drops below their alert threshold',
self::PrepayAutoTopUp => 'When an auto top-up invoice is generated for a client',
```

**Step 4: Add icons to `icon()` match**

After `self::NewVoicemail` (line 66):
```php
self::PrepayLowBalance => 'bi-exclamation-diamond',
self::PrepayAutoTopUp => 'bi-arrow-repeat',
```

**Step 5: Commit**

```bash
git add app/Enums/NotificationEventType.php
git commit -m "Add PrepayLowBalance and PrepayAutoTopUp notification event types"
```

---

### Task 3: NotificationService — Add low-balance and auto top-up notification methods

**Files:**
- Modify: `app/Services/NotificationService.php`

**Step 1: Add `notifyPrepayLowBalance()` method**

Add after `notifyPrepayPurchase()` (after line 237):

```php
/**
 * Notify company-wide portal users and opted-in staff about low prepay balance.
 */
public function notifyPrepayLowBalance(Contract $contract): void
{
    $context = json_encode([
        'contract' => $contract->name,
        'balance' => (float) $contract->prepay_balance,
        'threshold' => (float) $contract->prepay_alert_threshold,
        'client' => $contract->client?->name,
        'contract_id' => $contract->id,
    ]);

    // Notify company-wide portal users
    $portalUsers = \App\Models\Person::where('client_id', $contract->client_id)
        ->where('portal_enabled', true)
        ->where('company_wide_access', true)
        ->where('is_active', true)
        ->whereNotNull('email')
        ->get();

    foreach ($portalUsers as $person) {
        \App\Jobs\SendPrepayAlertEmail::dispatch(
            $person->email,
            $person->full_name,
            'low_balance',
            $context,
        );
    }

    // Notify opted-in staff
    $users = User::where('is_active', true)->whereNotNull('email')->get();

    foreach ($users as $user) {
        if ($user->wantsNotification(NotificationEventType::PrepayLowBalance)) {
            SendTicketNotification::dispatch(
                $user->id,
                NotificationEventType::PrepayLowBalance->value,
                null,
                null,
                $context,
            );
        }
    }
}

/**
 * Notify company-wide portal users and opted-in staff about auto top-up invoice.
 */
public function notifyPrepayAutoTopUp(Contract $contract, \App\Models\Invoice $invoice, float $hours): void
{
    $context = json_encode([
        'contract' => $contract->name,
        'balance' => (float) $contract->prepay_balance,
        'hours' => $hours,
        'client' => $contract->client?->name,
        'contract_id' => $contract->id,
        'invoice_number' => $invoice->invoice_number,
        'amount' => (float) $invoice->total,
        'invoice_id' => $invoice->id,
    ]);

    // Notify company-wide portal users
    $portalUsers = \App\Models\Person::where('client_id', $contract->client_id)
        ->where('portal_enabled', true)
        ->where('company_wide_access', true)
        ->where('is_active', true)
        ->whereNotNull('email')
        ->get();

    foreach ($portalUsers as $person) {
        \App\Jobs\SendPrepayAlertEmail::dispatch(
            $person->email,
            $person->full_name,
            'auto_topup',
            $context,
        );
    }

    // Notify opted-in staff
    $users = User::where('is_active', true)->whereNotNull('email')->get();

    foreach ($users as $user) {
        if ($user->wantsNotification(NotificationEventType::PrepayAutoTopUp)) {
            SendTicketNotification::dispatch(
                $user->id,
                NotificationEventType::PrepayAutoTopUp->value,
                null,
                null,
                $context,
            );
        }
    }
}
```

**Step 2: Add use statements at top of file if not already present**

Ensure these are imported:
```php
use App\Models\Contract;
```

**Step 3: Commit**

```bash
git add app/Services/NotificationService.php
git commit -m "Add low-balance and auto top-up notification methods"
```

---

### Task 4: SendTicketNotification — Handle new notification types for staff emails

**Files:**
- Modify: `app/Jobs/SendTicketNotification.php`

**Step 1: Add subject handling**

In `buildSubject()`, after the `NewVoicemail` block (after line 101), add:

```php
if ($event === NotificationEventType::PrepayLowBalance) {
    $ctx = json_decode($this->extraContext ?? '{}', true);
    $client = $ctx['client'] ?? 'Unknown';
    $balance = $ctx['balance'] ?? '?';
    return "Low prepay balance: {$client} — {$balance}h remaining";
}

if ($event === NotificationEventType::PrepayAutoTopUp) {
    $ctx = json_decode($this->extraContext ?? '{}', true);
    $client = $ctx['client'] ?? 'Unknown';
    $invoiceNumber = $ctx['invoice_number'] ?? '?';
    return "Auto top-up invoice generated: {$client} — Invoice #{$invoiceNumber}";
}
```

**Step 2: Add body handling**

In `buildBody()`, after the `NewVoicemail` block (find it after line 149), add:

```php
if ($event === NotificationEventType::PrepayLowBalance) {
    $ctx = json_decode($this->extraContext ?? '{}', true);
    $lines[] = ($ctx['client'] ?? 'A client') . '\'s prepay balance has dropped below their alert threshold.';
    $lines[] = '';
    $lines[] = 'Contract: ' . ($ctx['contract'] ?? 'Unknown');
    $lines[] = 'Current Balance: ' . ($ctx['balance'] ?? '?') . 'h';
    $lines[] = 'Alert Threshold: ' . ($ctx['threshold'] ?? '?') . 'h';
    $lines[] = '';
    if ($contractId = $ctx['contract_id'] ?? null) {
        $lines[] = 'View contract:';
        $lines[] = route('contracts.show', $contractId);
    }
    return implode("\n", $lines);
}

if ($event === NotificationEventType::PrepayAutoTopUp) {
    $ctx = json_decode($this->extraContext ?? '{}', true);
    $lines[] = 'An auto top-up invoice was generated for ' . ($ctx['client'] ?? 'a client') . '.';
    $lines[] = '';
    $lines[] = 'Contract: ' . ($ctx['contract'] ?? 'Unknown');
    $lines[] = 'Hours: ' . ($ctx['hours'] ?? '?') . 'h';
    $lines[] = 'Amount: $' . number_format($ctx['amount'] ?? 0, 2);
    $lines[] = 'Invoice: #' . ($ctx['invoice_number'] ?? '?');
    $lines[] = '';
    if ($invoiceId = $ctx['invoice_id'] ?? null) {
        $lines[] = 'View invoice:';
        $lines[] = route('invoices.show', $invoiceId);
    }
    return implode("\n", $lines);
}
```

**Step 3: Commit**

```bash
git add app/Jobs/SendTicketNotification.php
git commit -m "Handle PrepayLowBalance and PrepayAutoTopUp in staff notification emails"
```

---

### Task 5: SendPrepayAlertEmail job — Client-facing email notifications

**Files:**
- Create: `app/Jobs/SendPrepayAlertEmail.php`

**Step 1: Create the job**

This job sends emails to portal users (not staff). It uses `EmailService::sendNew()` (same as `SendPortalNotification`).

```php
<?php

namespace App\Jobs;

use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPrepayAlertEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(
        private string $recipientEmail,
        private string $recipientName,
        private string $alertType, // 'low_balance' or 'auto_topup'
        private string $contextJson,
    ) {}

    public function handle(EmailService $emailService): void
    {
        $ctx = json_decode($this->contextJson, true) ?? [];
        $subject = $this->buildSubject($ctx);
        $body = $this->buildBody($ctx);

        try {
            $emailService->sendNew(
                to: $this->recipientEmail,
                subject: $subject,
                body: $body,
            );
        } catch (\Throwable $e) {
            Log::warning('[PrepayAlert] Failed to send email', [
                'recipient' => $this->recipientEmail,
                'type' => $this->alertType,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function buildSubject(array $ctx): string
    {
        $contract = $ctx['contract'] ?? 'your contract';

        return match ($this->alertType) {
            'low_balance' => "Low prepaid balance on {$contract} — {$ctx['balance']}h remaining",
            'auto_topup' => "Prepaid time invoice generated — {$contract}",
            default => "Prepaid time update — {$contract}",
        };
    }

    private function buildBody(array $ctx): string
    {
        $lines = ["Hi {$this->recipientName},", ''];

        if ($this->alertType === 'low_balance') {
            $lines[] = "Your prepaid support hours on {$ctx['contract']} are running low.";
            $lines[] = '';
            $lines[] = "Current Balance: {$ctx['balance']}h";
            $lines[] = "Alert Threshold: {$ctx['threshold']}h";
            $lines[] = '';
            if ($ctx['contract_id'] ?? null) {
                $lines[] = 'You can purchase additional time in the client portal:';
                $lines[] = route('portal.contracts.show', $ctx['contract_id']);
            }
        } elseif ($this->alertType === 'auto_topup') {
            $lines[] = "A prepaid time invoice has been automatically generated for {$ctx['contract']}.";
            $lines[] = '';
            $lines[] = 'Hours: ' . ($ctx['hours'] ?? '?') . 'h';
            $lines[] = 'Amount: $' . number_format($ctx['amount'] ?? 0, 2);
            $lines[] = 'Invoice: #' . ($ctx['invoice_number'] ?? '?');
            $lines[] = '';
            $lines[] = 'Please pay this invoice to add the hours to your balance.';
        }

        $lines[] = '';
        $lines[] = 'Thank you,';
        $lines[] = \App\Support\PortalConfig::companyName();

        return implode("\n", $lines);
    }
}
```

**Step 2: Commit**

```bash
git add app/Jobs/SendPrepayAlertEmail.php
git commit -m "Add SendPrepayAlertEmail job for client-facing low-balance notifications"
```

---

### Task 6: PrepayAlertService — Core threshold checking and auto top-up logic

**Files:**
- Create: `app/Services/PrepayAlertService.php`

**Step 1: Create the service**

```php
<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Contract;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

class PrepayAlertService
{
    public function __construct(
        private NotificationService $notificationService,
        private PrepayOrderService $prepayOrderService,
    ) {}

    /**
     * Check if a contract's balance has crossed below its alert threshold.
     * Called after every debit and by the hourly cron.
     */
    public function checkThreshold(Contract $contract): void
    {
        // No threshold configured
        if ($contract->prepay_alert_threshold === null) {
            return;
        }

        // Not a hours-based prepay contract
        if (! $contract->has_prepay || $contract->prepay_as_amount) {
            return;
        }

        $balance = (float) $contract->prepay_balance;
        $threshold = (float) $contract->prepay_alert_threshold;

        // Balance is above threshold — clear any previous notification flag
        if ($balance > $threshold) {
            if ($contract->prepay_alert_notified_at) {
                $contract->update(['prepay_alert_notified_at' => null]);
            }
            return;
        }

        // Balance is at or below threshold — check if already notified for this dip
        if ($contract->prepay_alert_notified_at) {
            return;
        }

        Log::info('[PrepayAlert] Balance below threshold', [
            'contract_id' => $contract->id,
            'balance' => $balance,
            'threshold' => $threshold,
        ]);

        // Mark as notified
        $contract->update(['prepay_alert_notified_at' => now()]);

        // Send low-balance notifications
        $this->notificationService->notifyPrepayLowBalance($contract);

        // Attempt auto top-up if enabled
        if ($contract->prepay_auto_topup_enabled && $contract->prepay_auto_topup_qty > 0) {
            $this->triggerAutoTopUp($contract);
        }
    }

    /**
     * Generate an auto top-up invoice for the contract.
     */
    private function triggerAutoTopUp(Contract $contract): void
    {
        $sku = $contract->portalPrepaySku;

        if (! $sku) {
            Log::warning('[PrepayAlert] Auto top-up skipped — no portal prepay SKU', [
                'contract_id' => $contract->id,
            ]);
            return;
        }

        // Guard: don't generate another invoice if there's an unpaid one from auto top-up
        $hasPendingInvoice = Invoice::where('contract_id', $contract->id)
            ->where('notes', 'like', '%auto top-up%')
            ->whereIn('status', [InvoiceStatus::Draft, InvoiceStatus::Posted])
            ->exists();

        if ($hasPendingInvoice) {
            Log::info('[PrepayAlert] Auto top-up skipped — unpaid invoice exists', [
                'contract_id' => $contract->id,
            ]);
            return;
        }

        $quantity = $contract->prepay_auto_topup_qty;

        try {
            // Create a system Person placeholder for the invoice notes
            // (auto top-up is system-initiated, not person-initiated)
            $invoice = $this->prepayOrderService->createAutoTopUpInvoice($contract, $sku, $quantity);

            $hoursPerUnit = ($sku->prepaid_time_minutes ?? 0) / 60;
            $totalHours = $hoursPerUnit * $quantity;

            Log::info('[PrepayAlert] Auto top-up invoice created', [
                'contract_id' => $contract->id,
                'invoice_id' => $invoice->id,
                'quantity' => $quantity,
                'hours' => $totalHours,
            ]);

            // Push to billing backend
            $this->prepayOrderService->pushToBillingBackend($invoice);

            // Notify
            $this->notificationService->notifyPrepayAutoTopUp($contract, $invoice, $totalHours);
        } catch (\Throwable $e) {
            Log::error('[PrepayAlert] Auto top-up failed', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

**Step 2: Commit**

```bash
git add app/Services/PrepayAlertService.php
git commit -m "Add PrepayAlertService for threshold checking and auto top-up"
```

---

### Task 7: PrepayOrderService — Add createAutoTopUpInvoice method

**Files:**
- Modify: `app/Services/PrepayOrderService.php`

**Step 1: Add method**

Add after `pushToBillingBackend()`:

```php
/**
 * Create an invoice for auto top-up (system-initiated, not portal-initiated).
 */
public function createAutoTopUpInvoice(Contract $contract, \App\Models\Sku $sku, int $quantity): \App\Models\Invoice
{
    $client = $contract->client;

    return \Illuminate\Support\Facades\DB::transaction(function () use ($contract, $client, $sku, $quantity) {
        $invoiceNumber = $this->billingService->nextInvoiceNumber();

        for ($attempt = 0; ; $attempt++) {
            try {
                $invoice = \App\Models\Invoice::create([
                    'client_id' => $client->id,
                    'contract_id' => $contract->id,
                    'invoice_number' => $invoiceNumber,
                    'invoice_date' => now()->toDateString(),
                    'due_date' => now()->toDateString(),
                    'status' => \App\Enums\InvoiceStatus::Posted,
                    'notes' => "Prepaid time auto top-up for {$contract->name}",
                ]);
                break;
            } catch (\Illuminate\Database\QueryException $e) {
                if ($attempt >= 1 || ! str_contains($e->getMessage(), 'Duplicate entry')) {
                    throw $e;
                }
                $invoiceNumber = $this->billingService->nextInvoiceNumber();
            }
        }

        $prepaidMinutes = ($sku->prepaid_time_minutes ?? 0) * $quantity;

        $invoice->lines()->create([
            'sku_id' => $sku->id,
            'description' => $sku->name,
            'quantity' => $quantity,
            'unit_price' => $sku->unit_price,
            'amount' => $sku->unit_price * $quantity,
            'taxable' => $sku->taxable,
            'prepaid_time_minutes' => $prepaidMinutes,
        ]);

        $invoice->update([
            'subtotal' => $sku->unit_price * $quantity,
            'total' => $sku->unit_price * $quantity,
        ]);

        \App\Models\ContractActivity::create([
            'contract_id' => $contract->id,
            'action' => 'prepay_auto_topup',
            'description' => "Auto top-up: {$quantity} × {$sku->name} (Invoice #{$invoiceNumber})",
        ]);

        return $invoice;
    });
}
```

**Step 2: Commit**

```bash
git add app/Services/PrepayOrderService.php
git commit -m "Add createAutoTopUpInvoice method to PrepayOrderService"
```

---

### Task 8: PrepayService — Trigger threshold check after debits

**Files:**
- Modify: `app/Services/PrepayService.php`

**Step 1: Add PrepayAlertService dependency**

Add to constructor (or inject via method). Since PrepayService may not have a constructor, add the alert check at the end of `debitFromTicketNote()`.

After line 308 (`return $txn;`) but before the closing `});` of the DB::transaction on line 309, the transaction return value flows out. We need to check the threshold *after* the transaction commits, not inside it.

Replace lines 267-309 (the DB::transaction block) with:

```php
$txn = DB::transaction(function () use ($contract, $note, $hours, $description) {
    $existing = PrepayTransaction::where('ticket_note_id', $note->id)->first();

    if ($existing) {
        $oldHours = abs((float) $existing->hours);
        $existing->update([
            'hours' => -$hours,
            'description' => $description,
            'date' => $note->noted_at ?? $note->created_at,
        ]);

        $diff = $hours - $oldHours;
        if ($diff != 0) {
            $contract->increment('prepay_used', $diff);
            $contract->decrement('prepay_balance', $diff);
        }

        return $existing;
    }

    $txn = PrepayTransaction::create([
        'contract_id' => $contract->id,
        'source' => PrepayTransactionSource::TicketTime,
        'ticket_note_id' => $note->id,
        'user_id' => $note->author_id,
        'date' => $note->noted_at ?? $note->created_at,
        'hours' => -$hours,
        'description' => $description,
    ]);

    $contract->increment('prepay_used', $hours);
    $contract->decrement('prepay_balance', $hours);

    Log::info('[Prepay] Ticket time debit', [
        'contract_id' => $contract->id,
        'ticket_note_id' => $note->id,
        'hours' => $hours,
    ]);

    return $txn;
});

// Check alert threshold after transaction commits
$contract->refresh();
app(PrepayAlertService::class)->checkThreshold($contract);

return $txn;
```

**Step 2: Add use statement at top of file**

```php
use App\Services\PrepayAlertService;
```

**Step 3: Commit**

```bash
git add app/Services/PrepayService.php
git commit -m "Trigger prepay alert threshold check after ticket time debits"
```

---

### Task 9: CheckPrepayBalances command — Hourly safety net

**Files:**
- Create: `app/Console/Commands/CheckPrepayBalances.php`
- Modify: `routes/console.php`

**Step 1: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Services\PrepayAlertService;
use Illuminate\Console\Command;

class CheckPrepayBalances extends Command
{
    protected $signature = 'prepay:check-balances';

    protected $description = 'Check prepay contracts for low balances and trigger alerts/auto-top-ups';

    public function handle(PrepayAlertService $alertService): int
    {
        $contracts = Contract::whereNotNull('prepay_alert_threshold')
            ->whereNotNull('prepay_balance')
            ->where(fn ($q) => $q->where('prepay_as_amount', false)->orWhereNull('prepay_as_amount'))
            ->whereColumn('prepay_balance', '<=', 'prepay_alert_threshold')
            ->get();

        $this->info("Checking {$contracts->count()} contracts below threshold...");

        $alerted = 0;
        foreach ($contracts as $contract) {
            $alertService->checkThreshold($contract);
            if ($contract->wasChanged('prepay_alert_notified_at')) {
                $alerted++;
                $this->line("  {$contract->name}: {$contract->prepay_balance}h (threshold: {$contract->prepay_alert_threshold}h)");
            }
        }

        $this->info("Done. {$alerted} new alerts triggered.");

        return self::SUCCESS;
    }
}
```

**Step 2: Schedule the command**

In `routes/console.php`, after the triage block (after line 195), add:

```php
// Prepay — check for low balances and trigger alerts/auto-top-ups
Schedule::command('prepay:check-balances')
    ->hourly()
    ->withoutOverlapping(5)
    ->runInBackground();
```

**Step 3: Commit**

```bash
git add app/Console/Commands/CheckPrepayBalances.php routes/console.php
git commit -m "Add hourly prepay:check-balances command as safety net"
```

---

### Task 10: Staff UI — Alert settings on contract detail page

**Files:**
- Modify: `resources/views/contracts/show.blade.php`
- Modify: `app/Http/Controllers/Web/ContractController.php`
- Modify: `routes/web.php`

**Step 1: Add settings form to contract detail view**

In `resources/views/contracts/show.blade.php`, after the Portal Purchases card closing `@endif` (line 581), add:

```blade
{{-- Prepay Alert Settings --}}
@if($contract->has_prepay && !$contract->prepay_as_amount)
    <div class="card shadow-sm mt-3">
        <div class="card-header">
            <i class="bi bi-bell me-2"></i>Low Balance Alerts
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('contracts.update-alert-settings', $contract) }}">
                @csrf
                @method('PUT')
                <div class="mb-3">
                    <label for="prepayAlertThreshold" class="form-label text-muted small">
                        Alert when balance drops below (hours):
                    </label>
                    <input type="number" name="prepay_alert_threshold" id="prepayAlertThreshold"
                           class="form-control form-control-sm" style="max-width: 150px;"
                           value="{{ $contract->prepay_alert_threshold }}"
                           min="0" step="0.25" placeholder="Disabled">
                    <div class="form-text">Leave blank to disable alerts.</div>
                </div>
                @if($contract->portal_prepay_sku_id)
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="hidden" name="prepay_auto_topup_enabled" value="0">
                            <input type="checkbox" name="prepay_auto_topup_enabled" value="1"
                                   class="form-check-input" id="autoTopUpEnabled"
                                   {{ $contract->prepay_auto_topup_enabled ? 'checked' : '' }}>
                            <label class="form-check-label" for="autoTopUpEnabled">
                                Auto top-up when balance is low
                            </label>
                        </div>
                    </div>
                    <div class="mb-3" id="autoTopUpQtyRow" style="{{ $contract->prepay_auto_topup_enabled ? '' : 'display:none;' }}">
                        <label for="prepayAutoTopupQty" class="form-label text-muted small">
                            Auto top-up quantity (units of {{ $contract->portalPrepaySku?->name ?? 'SKU' }}):
                        </label>
                        <input type="number" name="prepay_auto_topup_qty" id="prepayAutoTopupQty"
                               class="form-control form-control-sm" style="max-width: 150px;"
                               value="{{ $contract->prepay_auto_topup_qty ?? 1 }}"
                               min="1" max="99">
                        @if($contract->portalPrepaySku?->prepaid_time_minutes)
                            <div class="form-text">
                                {{ number_format($contract->portalPrepaySku->prepaid_time_minutes / 60, 1) }}h per unit
                            </div>
                        @endif
                    </div>
                @endif
                <button type="submit" class="btn btn-sm btn-primary">Save</button>
                @if($contract->prepay_alert_notified_at)
                    <span class="text-muted small ms-2">
                        Last alert: {{ $contract->prepay_alert_notified_at->toAppTz()->format('M j, Y g:i A') }}
                    </span>
                @endif
            </form>
        </div>
    </div>
    <script>
        document.getElementById('autoTopUpEnabled')?.addEventListener('change', function() {
            document.getElementById('autoTopUpQtyRow').style.display = this.checked ? '' : 'none';
        });
    </script>
@endif
```

**Step 2: Add route**

In `routes/web.php`, find the existing contract routes (near `contracts.update-portal-sku`) and add:

```php
Route::put('/contracts/{contract}/alert-settings', [ContractController::class, 'updateAlertSettings'])->name('contracts.update-alert-settings');
```

**Step 3: Add controller method**

In `app/Http/Controllers/Web/ContractController.php`, add after `updatePortalSku()`:

```php
public function updateAlertSettings(Request $request, Contract $contract)
{
    $validated = $request->validate([
        'prepay_alert_threshold' => 'nullable|numeric|min:0',
        'prepay_auto_topup_enabled' => 'boolean',
        'prepay_auto_topup_qty' => 'nullable|integer|min:1|max:99',
    ]);

    $contract->update([
        'prepay_alert_threshold' => $validated['prepay_alert_threshold'] ?: null,
        'prepay_auto_topup_enabled' => $validated['prepay_auto_topup_enabled'] ?? false,
        'prepay_auto_topup_qty' => $validated['prepay_auto_topup_qty'] ?? null,
        // Clear notification flag when settings change
        'prepay_alert_notified_at' => null,
    ]);

    return redirect()->route('contracts.show', $contract)->with('success', 'Alert settings updated.');
}
```

**Step 4: Commit**

```bash
git add resources/views/contracts/show.blade.php app/Http/Controllers/Web/ContractController.php routes/web.php
git commit -m "Add prepay alert settings to staff contract detail page"
```

---

### Task 11: Portal UI — Alert settings on portal contract page

**Files:**
- Modify: `resources/views/portal/contracts/show.blade.php`
- Modify: `app/Http/Controllers/Portal/PortalPrepayController.php`
- Modify: `routes/portal.php`

**Step 1: Add settings section to portal contract view**

In `resources/views/portal/contracts/show.blade.php`, after the prepay balance card closing `@endif` (line 71) and before the ledger section (line 73), add:

```blade
{{-- Prepay alert settings (company-wide access only) --}}
@if($contract->prepay_balance !== null && !$contract->prepay_as_amount && ($portalPerson->company_wide_access ?? false))
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-bell me-1"></i>Low Balance Alerts</h6>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('portal.prepaid.update-alert-settings', $contract) }}">
                @csrf
                @method('PUT')
                <div class="row g-3 align-items-end">
                    <div class="col-auto">
                        <label for="prepayAlertThreshold" class="form-label small">Alert when below (hours):</label>
                        <input type="number" name="prepay_alert_threshold" id="prepayAlertThreshold"
                               class="form-control form-control-sm" style="max-width: 120px;"
                               value="{{ $contract->prepay_alert_threshold }}"
                               min="0" step="0.25" placeholder="Off">
                    </div>
                    @if($contract->is_portal_purchasable)
                        <div class="col-auto">
                            <div class="form-check">
                                <input type="hidden" name="prepay_auto_topup_enabled" value="0">
                                <input type="checkbox" name="prepay_auto_topup_enabled" value="1"
                                       class="form-check-input" id="portalAutoTopUp"
                                       {{ $contract->prepay_auto_topup_enabled ? 'checked' : '' }}>
                                <label class="form-check-label small" for="portalAutoTopUp">Auto top-up</label>
                            </div>
                        </div>
                        <div class="col-auto" id="portalTopUpQty" style="{{ $contract->prepay_auto_topup_enabled ? '' : 'display:none;' }}">
                            <label for="prepayAutoTopupQty" class="form-label small">Units:</label>
                            <input type="number" name="prepay_auto_topup_qty" id="prepayAutoTopupQty"
                                   class="form-control form-control-sm" style="max-width: 80px;"
                                   value="{{ $contract->prepay_auto_topup_qty ?? 1 }}"
                                   min="1" max="99">
                        </div>
                    @endif
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    </div>
                </div>
                @if($contract->is_portal_purchasable && $contract->portalPrepaySku)
                    <div class="form-text mt-2">
                        {{ $contract->portalPrepaySku->name }} — ${{ number_format($contract->portalPrepaySku->unit_price, 2) }} / {{ number_format($contract->portalPrepaySku->prepaid_time_minutes / 60, 1) }}h per unit
                    </div>
                @endif
            </form>
        </div>
    </div>
    <script>
        document.getElementById('portalAutoTopUp')?.addEventListener('change', function() {
            document.getElementById('portalTopUpQty').style.display = this.checked ? '' : 'none';
        });
    </script>
@endif
```

**Step 2: Add route**

In `routes/portal.php`, after line 78 (prepaid payment-status route), add:

```php
Route::put('/prepaid/{contract}/alert-settings', [PortalPrepayController::class, 'updateAlertSettings'])->name('portal.prepaid.update-alert-settings');
```

**Step 3: Add controller method**

In `app/Http/Controllers/Portal/PortalPrepayController.php`, add:

```php
public function updateAlertSettings(Request $request, Contract $contract)
{
    // Verify this contract belongs to the portal user's client
    $portalClientId = $request->attributes->get('portal_client_id');
    if ($contract->client_id !== $portalClientId) {
        abort(403);
    }

    // Only company-wide access users can configure alerts
    $portalPerson = $request->attributes->get('portal_person');
    if (! $portalPerson?->company_wide_access) {
        abort(403);
    }

    $validated = $request->validate([
        'prepay_alert_threshold' => 'nullable|numeric|min:0',
        'prepay_auto_topup_enabled' => 'boolean',
        'prepay_auto_topup_qty' => 'nullable|integer|min:1|max:99',
    ]);

    $contract->update([
        'prepay_alert_threshold' => $validated['prepay_alert_threshold'] ?: null,
        'prepay_auto_topup_enabled' => $validated['prepay_auto_topup_enabled'] ?? false,
        'prepay_auto_topup_qty' => $validated['prepay_auto_topup_qty'] ?? null,
        'prepay_alert_notified_at' => null,
    ]);

    return redirect()->route('portal.contracts.show', $contract)->with('success', 'Alert settings saved.');
}
```

**Step 4: Commit**

```bash
git add resources/views/portal/contracts/show.blade.php app/Http/Controllers/Portal/PortalPrepayController.php routes/portal.php
git commit -m "Add prepay alert settings to client portal contract page"
```

---

### Task 12: Deploy and verify

**Step 1: Deploy**

```bash
/deploy
```

**Step 2: Verify migration ran**

Check that the new columns exist on the contracts table.

**Step 3: Manual testing checklist**

- [ ] Staff: visit a prepay contract detail page, see "Low Balance Alerts" card
- [ ] Staff: set threshold to a value above current balance, verify notification triggers
- [ ] Staff: enable auto top-up, set quantity, verify invoice is generated when threshold is crossed
- [ ] Staff: verify duplicate invoice guard — debit again, confirm no second invoice
- [ ] Staff: verify notification dedup — debit again while below threshold, confirm no repeat notification
- [ ] Staff: verify threshold reset — manually credit balance above threshold, then debit below again, confirm fresh notification
- [ ] Portal: log in as company-wide user, visit contract page, see alert settings
- [ ] Portal: configure threshold, verify it saves
- [ ] Portal: verify non-company-wide user does NOT see settings
- [ ] Cron: run `php artisan prepay:check-balances` manually, verify it catches contracts below threshold
- [ ] Notifications: verify staff get email for PrepayLowBalance (if opted in)
- [ ] Notifications: verify portal users get client-facing email

**Step 4: Commit any fixes from testing**
