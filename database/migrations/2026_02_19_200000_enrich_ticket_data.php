<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create ticket_asset pivot table
        Schema::create('ticket_asset', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->unsignedBigInteger('halo_asset_id')->nullable();
            $table->timestamps();

            $table->unique(['ticket_id', 'asset_id']);
        });

        // 2. Migrate existing asset_id values to pivot
        DB::table('tickets')
            ->whereNotNull('asset_id')
            ->orderBy('id')
            ->each(function ($ticket) {
                DB::table('ticket_asset')->insertOrIgnore([
                    'ticket_id' => $ticket->id,
                    'asset_id' => $ticket->asset_id,
                    'is_primary' => true,
                    'halo_asset_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        // 3. Add new columns to tickets
        Schema::table('tickets', function (Blueprint $table) {
            $table->json('halo_assets')->nullable()->after('asset_id');
            $table->unsignedBigInteger('halo_contract_id')->nullable();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('total_time_minutes')->nullable();
            $table->string('sla_name', 100)->nullable();
            $table->string('reported_by', 255)->nullable();
        });

        // 4. Drop asset_id FK (data already migrated to pivot)
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_id');
        });

        // 5. Add new columns to ticket_notes
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->longText('body_html')->nullable()->after('body');
            $table->unsignedTinyInteger('who_type')->nullable();
            $table->boolean('is_billable')->nullable();
            $table->string('halo_outcome', 50)->nullable();
        });

        // 6. Change body to longText (some Halo notes are huge)
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->longText('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverse ticket_notes changes
        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->dropColumn(['body_html', 'who_type', 'is_billable', 'halo_outcome']);
        });

        Schema::table('ticket_notes', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
        });

        // Re-add asset_id to tickets
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
        });

        // Migrate primary assets back from pivot
        DB::table('ticket_asset')
            ->where('is_primary', true)
            ->orderBy('ticket_id')
            ->each(function ($pivot) {
                DB::table('tickets')
                    ->where('id', $pivot->ticket_id)
                    ->update(['asset_id' => $pivot->asset_id]);
            });

        // Remove new ticket columns
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['halo_assets', 'halo_contract_id', 'total_time_minutes', 'sla_name', 'reported_by']);
            $table->dropConstrainedForeignId('contract_id');
        });

        Schema::dropIfExists('ticket_asset');
    }
};
