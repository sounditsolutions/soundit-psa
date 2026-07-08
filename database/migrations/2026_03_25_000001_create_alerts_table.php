<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('source', 20);
            $table->string('source_alert_id');
            $table->string('severity', 20);
            $table->string('status', 20)->default('active');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('hostname')->nullable();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('refired_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('fired_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_alert_id']);
            $table->index(['client_id', 'status']);
            $table->index(['asset_id', 'status']);
            $table->index(['status', 'severity']);
            $table->index('ticket_id');
        });

        // Migrate ninja_alerts data
        if (Schema::hasTable('ninja_alerts')) {
            $ninjaAlerts = DB::table('ninja_alerts')->get();
            foreach ($ninjaAlerts as $na) {
                $clientId = null;
                if ($na->asset_id) {
                    $clientId = DB::table('assets')->where('id', $na->asset_id)->value('client_id');
                }

                // Map Ninja severity to unified
                $severity = match (strtolower($na->severity ?? '')) {
                    'critical' => 'critical',
                    'major' => 'error',
                    'moderate' => 'warning',
                    'minor' => 'info',
                    default => 'warning',
                };

                // Get hostname from asset
                $hostname = null;
                if ($na->asset_id) {
                    $hostname = DB::table('assets')->where('id', $na->asset_id)->value('hostname');
                }

                DB::table('alerts')->insert([
                    'asset_id' => $na->asset_id,
                    'client_id' => $clientId,
                    'source' => 'ninja',
                    'source_alert_id' => $na->ninja_alert_uid,
                    'severity' => $severity,
                    'status' => $na->status,
                    'title' => $na->condition_name ?? 'NinjaRMM Alert',
                    'message' => $na->message,
                    'hostname' => $hostname,
                    'ticket_id' => $na->ticket_id,
                    'resolved_at' => $na->resolved_at,
                    'metadata' => json_encode(['ninja_device_id' => $na->ninja_device_id]),
                    'fired_at' => $na->fired_at,
                    'created_at' => $na->created_at,
                    'updated_at' => $na->updated_at,
                ]);
            }

            Schema::drop('ninja_alerts');
        }
    }

    public function down(): void
    {
        // Recreate ninja_alerts if rolling back (without data)
        Schema::create('ninja_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->unsignedInteger('ninja_device_id')->index();
            $table->string('ninja_alert_uid')->unique();
            $table->string('severity', 20)->nullable();
            $table->string('condition_name')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->timestamp('fired_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['asset_id', 'status']);
            $table->index(['ticket_id']);
        });

        Schema::dropIfExists('alerts');
    }
};
