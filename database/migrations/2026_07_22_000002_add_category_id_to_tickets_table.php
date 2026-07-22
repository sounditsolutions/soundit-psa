<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * so-0ftg — link a ticket to a structured taxonomy leaf. Additive to the legacy
 * free-text tickets.category / tickets.subcategory columns, which stay. Nulls on
 * delete so retiring/deleting a category never destroys a ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // constrained() already indexes category_id (MariaDB auto-creates the
            // foreign-key index, tickets_category_id_foreign), so no explicit
            // ->index() is added: a second index would be a redundant duplicate on
            // MariaDB and, on SQLite, leaves category_id "indexed" so DROP COLUMN in
            // down() fails the rollback (the FK index is dropped with the constraint).
            $table->foreignId('category_id')
                ->nullable()
                ->after('subcategory')
                ->constrained('ticket_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
