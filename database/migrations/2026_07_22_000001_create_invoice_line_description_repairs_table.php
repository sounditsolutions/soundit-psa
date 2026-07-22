<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit + idempotency ledger for invoices:repair-double-escaped-descriptions
        // (psa-946hr). One row per invoice line the repair command has ever
        // rewritten. The UNIQUE key on invoice_line_id is the at-most-once
        // guarantee: a repaired description whose *legitimate* text still looks
        // entity-encoded would match the corruption signatures again on a later
        // run, and this ledger is what stops it from being decoded twice.
        //
        // Deliberately NO foreign keys: this is a historical audit record and
        // must survive deletion of the line (draft line edits delete rows) and
        // of the invoice.
        Schema::create('invoice_line_description_repairs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_line_id')->unique();
            $table->unsignedBigInteger('invoice_id');
            $table->text('description_before');
            $table->text('description_after');
            $table->string('invoice_status_at_repair', 20);
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_line_description_repairs');
    }
};
