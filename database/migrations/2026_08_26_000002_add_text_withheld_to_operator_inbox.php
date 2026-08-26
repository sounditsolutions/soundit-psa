<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operator_inbox', function (Blueprint $table) {
            // Whether the safety pipeline withheld the body at ingest, so the
            // body stored is a placeholder rather than the message. This has
            // to be recorded beside the row: the placeholder is a public
            // literal that an operator can send verbatim and that passes the
            // scan unchanged, so recognising a withheld row by its content
            // reports delivered messages as withheld. Defaults false — a
            // written row always knows which it is.
            $table->boolean('text_withheld')->default(false)->after('text_chars');
        });

        // One-shot backfill for rows written before this column existed: for
        // those the stored placeholder is the only surviving signal, and
        // defaulting them to false would silently downgrade a real withhold.
        // This is not a content check on the live path — it never runs again.
        DB::table('operator_inbox')
            ->where('text', '[operator message withheld - unsafe content]')
            ->update(['text_withheld' => true]);
    }

    public function down(): void
    {
        Schema::table('operator_inbox', function (Blueprint $table) {
            $table->dropColumn('text_withheld');
        });
    }
};
