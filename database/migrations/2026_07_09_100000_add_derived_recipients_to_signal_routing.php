<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A route step now carries EITHER a fixed destination_id OR a derived_from
        // recipient kind (resolved to a real destination at route time). Make
        // destination_id nullable so derived steps can omit it.
        Schema::table('signal_route_steps', function (Blueprint $table) {
            $table->foreignId('destination_id')->nullable()->change();
            $table->string('derived_from', 50)->nullable()->after('destination_id');
        });

        // Auto-provisioned per-user destinations (the concrete row a derived
        // recipient resolves to) are keyed to a user. NULL for manually-created
        // destinations; unique per user for the derived ones.
        Schema::table('signal_destinations', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('signal_destinations', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('signal_route_steps', function (Blueprint $table) {
            $table->dropColumn('derived_from');
            $table->foreignId('destination_id')->nullable(false)->change();
        });
    }
};
