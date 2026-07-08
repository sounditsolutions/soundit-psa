<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->timestamp('last_sign_in_at')->nullable()->after('mailbox_deliver_and_forward');
            $table->boolean('cipp_inactive')->default(false)->after('last_sign_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['last_sign_in_at', 'cipp_inactive']);
        });
    }
};
