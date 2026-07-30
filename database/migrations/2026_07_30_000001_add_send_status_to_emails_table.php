<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// psa-330 redesign: the emails table is the outbound ledger, but today an outbound row is
// written only AFTER a successful Graph post and carries no state — so a missing row can't
// tell "never attempted" from "accepted then crashed", which is the false-receipt bug.
// Give outbound sends an explicit lifecycle the send path stamps, plus an idempotency key so
// a retried send maps to the same row instead of minting a second "success". graph_id stays
// null on outbound (Graph sendMail returns 202, no id) — it is NOT the send signal.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            // pending -> accepted (Graph returned 202) | failed (pre-dispatch or post error).
            // 'accepted' is deliberately NOT 'delivered': Graph never gives a delivery receipt.
            $table->string('send_status', 12)->nullable()->index()->after('direction');
            $table->string('idempotency_key')->nullable()->unique()->after('send_status');
            $table->timestamp('send_attempted_at')->nullable()->after('idempotency_key');
            $table->timestamp('send_accepted_at')->nullable()->after('send_attempted_at');
            $table->timestamp('send_failed_at')->nullable()->after('send_accepted_at');
            $table->text('send_error')->nullable()->after('send_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn([
                'send_status', 'idempotency_key', 'send_attempted_at',
                'send_accepted_at', 'send_failed_at', 'send_error',
            ]);
        });
    }
};
