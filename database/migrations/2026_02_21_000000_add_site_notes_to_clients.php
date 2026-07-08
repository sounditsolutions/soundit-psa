<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->longText('site_notes')->nullable()->after('is_active');
            $table->longText('site_notes_html')->nullable()->after('site_notes');
            $table->timestamp('site_notes_updated_at')->nullable()->after('site_notes_html');
            $table->foreignId('site_notes_updated_by')->nullable()->after('site_notes_updated_at')
                ->constrained('users')->nullOnDelete();
            $table->longText('credentials')->nullable()->after('site_notes_updated_by');
            $table->timestamp('credentials_updated_at')->nullable()->after('credentials');
            $table->foreignId('credentials_updated_by')->nullable()->after('credentials_updated_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['site_notes_updated_by']);
            $table->dropForeign(['credentials_updated_by']);
            $table->dropColumn([
                'site_notes',
                'site_notes_html',
                'site_notes_updated_at',
                'site_notes_updated_by',
                'credentials',
                'credentials_updated_at',
                'credentials_updated_by',
            ]);
        });
    }
};
