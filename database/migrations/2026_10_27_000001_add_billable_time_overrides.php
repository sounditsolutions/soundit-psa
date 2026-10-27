<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ticket_notes', 'phone_calls'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedInteger('billable_minutes')->nullable();
            });
        }

        Schema::create('billable_time_changes', function (Blueprint $table) {
            $table->id();
            $table->string('entry_type', 4);
            $table->unsignedBigInteger('entry_id');
            $table->unsignedInteger('old_billable_minutes')->nullable();
            $table->unsignedInteger('new_billable_minutes')->nullable();
            $table->text('reason');
            $table->string('source', 3);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_label');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['entry_type', 'entry_id']);
        });

        Schema::create('time_entry_billing_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('entry_type', 4);
            $table->unsignedBigInteger('entry_id');
            $table->unsignedInteger('billable_minutes')->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_label');
            $table->string('state')->default('pending');
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['entry_type', 'entry_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entry_billing_proposals');
        Schema::dropIfExists('billable_time_changes');
        foreach (['ticket_notes', 'phone_calls'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('billable_minutes');
            });
        }
    }
};
