<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop FK first — MariaDB won't drop the unique index while it's backing the FK
        Schema::table('prepay_transactions', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->dropUnique(['contract_id', 'halo_id']);
        });

        // Make halo_id nullable via raw SQL (avoids doctrine/dbal requirement)
        DB::statement('ALTER TABLE prepay_transactions MODIFY halo_id INT UNSIGNED NULL');

        // Re-add unique + FK (MariaDB allows multiple NULLs in unique indexes)
        Schema::table('prepay_transactions', function (Blueprint $table) {
            $table->unique(['contract_id', 'halo_id']);
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
        });

        // Add new columns for PSA-native transactions
        Schema::table('prepay_transactions', function (Blueprint $table) {
            $table->string('source', 30)->default('halo_sync')->after('contract_id');
            $table->foreignId('invoice_id')->nullable()->after('source')
                ->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->after('invoice_id')
                ->constrained()->nullOnDelete();
            $table->string('note')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('prepay_transactions', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
            $table->dropForeign(['user_id']);
            $table->dropColumn(['source', 'invoice_id', 'user_id', 'note']);
        });

        // Only revert halo_id to NOT NULL if no PSA-native records exist
        $hasNativeRecords = DB::table('prepay_transactions')->whereNull('halo_id')->exists();
        if ($hasNativeRecords) {
            throw new RuntimeException(
                'Cannot revert: PSA-native prepay transactions exist (halo_id IS NULL). '
                . 'Delete them first or this migration is forward-only.'
            );
        }

        Schema::table('prepay_transactions', function (Blueprint $table) {
            $table->dropUnique(['contract_id', 'halo_id']);
        });

        DB::statement('ALTER TABLE prepay_transactions MODIFY halo_id INT UNSIGNED NOT NULL');

        Schema::table('prepay_transactions', function (Blueprint $table) {
            $table->unique(['contract_id', 'halo_id']);
        });
    }
};
