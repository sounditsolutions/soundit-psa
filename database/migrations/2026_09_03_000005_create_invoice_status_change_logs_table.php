<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1173 — the durable record of every invoices.status move.
 *
 * T-22802: nine invoices read Paid in the PSA while QuickBooks showed them
 * open, and NOTHING on the row recorded what had set that status. The
 * provenance had to be reconstructed from which columns were null
 * (qbo_synced_at NULL on all nine ⇒ no QBO write path ever touched them ⇒ the
 * status arrived with the Halo import). That reconstruction only worked
 * because the import happened to leave a distinguishable fingerprint; the next
 * one may not.
 *
 * Follows the #992 ticket_description_change_logs shape and its audit-seam
 * ruling: record at the MODEL level (InvoiceObserver), so every surface — the
 * QBO pull, the Stripe pull, staff Mark Paid, the void service, jobs, anything
 * added later — is captured without opting in.
 *
 * qbo_balance is the column that answers T-22802's real question. The PSA has
 * no balance/amount-paid column on invoices (Charlie's call, deferred), so
 * when a QBO pull reverts a Paid invoice this is the ONLY place the amount
 * QuickBooks still says is owed is written down — and it is what the portal
 * reads to tell a client "partially paid" instead of billing them the full
 * total. Null on every write that is not a QBO pull.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_status_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            // Stored as plain strings, not an enum column: InvoiceStatus is
            // free to gain a case (a PartiallyPaid is already contemplated on
            // T-22802) without a migration that would have to rewrite history.
            $table->string('previous_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->string('source', 20);
            // Free text stating WHY, in the words of whoever made the move —
            // "QuickBooks reports 450.00 still owed" is the sentence a
            // technician answering the client's call actually needs.
            $table->string('reason', 255)->nullable();
            // What QuickBooks reported as still owed at the moment of this
            // write. Mirrors invoices.total's decimal(10,2) so it can hold any
            // balance the invoice itself could carry.
            $table->decimal('qbo_balance', 10, 2)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['invoice_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_status_change_logs');
    }
};
