<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tactical_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('agent_id')->unique();
            $table->string('hostname')->nullable();
            $table->string('os')->nullable();
            $table->string('os_version')->nullable();
            $table->string('public_ip')->nullable();
            $table->json('local_ips')->nullable();
            $table->string('last_user')->nullable();
            $table->string('cpu')->nullable();
            $table->string('make_model')->nullable();
            $table->text('disk_summary')->nullable();
            $table->decimal('ram_gb', 8, 1)->nullable();
            $table->string('serial_number')->nullable();
            $table->string('status', 20)->default('offline');
            $table->string('agent_version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('client_name')->nullable();
            $table->string('site_name')->nullable();
            $table->boolean('needs_reboot')->default(false);
            $table->boolean('has_patches_pending')->default(false);
            $table->string('graphics')->nullable();
            $table->string('monitoring_type', 20)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('tactical_asset_id')->nullable()->after('ninja_synced_at')
                ->constrained('tactical_assets')->nullOnDelete();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('tactical_site_id')->nullable()->after('ninja_org_id');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tactical_asset_id');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('tactical_site_id');
        });

        Schema::dropIfExists('tactical_assets');
    }
};
