<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('password')->nullable()->after('is_active');
            $table->boolean('portal_enabled')->default(false)->after('password');
            $table->boolean('company_wide_access')->default(false)->after('portal_enabled');
            $table->timestamp('portal_last_login_at')->nullable()->after('company_wide_access');
            $table->rememberToken()->after('portal_last_login_at');

            $table->index('portal_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex(['portal_enabled']);
            $table->dropColumn([
                'password',
                'portal_enabled',
                'company_wide_access',
                'portal_last_login_at',
                'remember_token',
            ]);
        });
    }
};
