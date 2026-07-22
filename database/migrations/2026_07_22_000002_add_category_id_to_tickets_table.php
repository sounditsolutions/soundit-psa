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
            $table->foreignId('category_id')
                ->nullable()
                ->after('subcategory')
                ->constrained('ticket_categories')
                ->nullOnDelete();
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
