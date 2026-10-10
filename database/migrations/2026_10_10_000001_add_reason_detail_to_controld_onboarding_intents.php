<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive and nullable: the vendor's own sanitized `error.message` on a `rejected` Control D
 * onboarding intent (ControlDClient::vendorMessage(), at most 200 characters). Null on every
 * other outcome and on rejections whose message was absent or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controld_onboarding_intents', function (Blueprint $table): void {
            $table->string('reason_detail', 255)->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('controld_onboarding_intents', function (Blueprint $table): void {
            $table->dropColumn('reason_detail');
        });
    }
};
