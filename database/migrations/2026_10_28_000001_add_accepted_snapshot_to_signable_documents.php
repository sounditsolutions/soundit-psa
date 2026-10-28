<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signable_documents', function (Blueprint $table) {
            $table->foreignId('accepted_snapshot_id')->nullable()->after('kind')->constrained('signing_snapshots')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('signable_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_snapshot_id');
        });
    }
};
