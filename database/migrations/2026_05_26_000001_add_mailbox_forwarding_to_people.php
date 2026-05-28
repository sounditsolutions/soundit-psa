<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('mailbox_forwarding_smtp', 320)->nullable()->after('mailbox_item_count');
            $table->string('mailbox_forwarding_internal', 320)->nullable()->after('mailbox_forwarding_smtp');
            $table->boolean('mailbox_deliver_and_forward')->nullable()->after('mailbox_forwarding_internal');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn([
                'mailbox_forwarding_smtp',
                'mailbox_forwarding_internal',
                'mailbox_deliver_and_forward',
            ]);
        });
    }
};
