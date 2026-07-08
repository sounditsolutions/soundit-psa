<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->json('cipp_transport_rules')->nullable()->after('cipp_tenant_domain');
            $table->json('cipp_safe_links_policy')->nullable()->after('cipp_transport_rules');
            $table->json('cipp_safe_attachments_filters')->nullable()->after('cipp_safe_links_policy');
            $table->timestamp('cipp_mail_security_synced_at')->nullable()->after('cipp_safe_attachments_filters');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'cipp_transport_rules',
                'cipp_safe_links_policy',
                'cipp_safe_attachments_filters',
                'cipp_mail_security_synced_at',
            ]);
        });
    }
};
