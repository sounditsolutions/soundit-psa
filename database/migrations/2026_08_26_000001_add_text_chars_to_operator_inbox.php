<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operator_inbox', function (Blueprint $table) {
            // Character count of the original inbound message (post
            // mention-strip, pre redaction/cap). Nullable: rows ingested
            // before this column exists have no recorded original length.
            $table->unsignedInteger('text_chars')->nullable()->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('operator_inbox', function (Blueprint $table) {
            $table->dropColumn('text_chars');
        });
    }
};
