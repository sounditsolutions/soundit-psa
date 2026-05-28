<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('cipp_upn', 320)->nullable()->after('cipp_user_id');
            $table->string('department', 255)->nullable()->after('job_title');
            $table->string('office_location', 255)->nullable()->after('department');
            $table->boolean('is_hybrid')->nullable()->after('office_location');
            $table->string('m365_user_type', 20)->nullable()->after('is_hybrid');
            $table->unsignedBigInteger('mailbox_size_bytes')->nullable()->after('m365_user_type');
            $table->unsignedInteger('mailbox_item_count')->nullable()->after('mailbox_size_bytes');
            $table->boolean('mfa_enabled')->nullable()->after('mailbox_item_count');
            $table->timestamp('cipp_enriched_at')->nullable()->after('mfa_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn([
                'cipp_upn', 'department', 'office_location', 'is_hybrid',
                'm365_user_type', 'mailbox_size_bytes', 'mailbox_item_count',
                'mfa_enabled', 'cipp_enriched_at',
            ]);
        });
    }
};
