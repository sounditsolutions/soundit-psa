<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing rows land in the LEGACY lane automatically: persona is
        // nullable (NULL = legacy lane), kind defaults to 'human'. No
        // data-backfill UPDATE needed.
        Schema::table('operator_inbox', function (Blueprint $table) {
            $table->string('persona')->nullable()->after('conversation_id');
            $table->string('kind')->default('human')->after('persona');
            $table->string('sender_persona')->nullable()->after('sender_user_id');
        });

        // Split into a second closure so MariaDB sees the new `persona` column
        // committed before it is asked to index it.
        Schema::table('operator_inbox', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'delivered_at', 'id']);
            $table->index(['persona', 'delivered_at', 'id']);
            // TeamsChatReadToolset::knownConversationIds() still groups by
            // conversation_id — keep it indexed after the lane index replaces
            // the old composite.
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::table('operator_inbox', function (Blueprint $table) {
            $table->dropIndex(['conversation_id']);
            $table->dropIndex(['persona', 'delivered_at', 'id']);
            $table->index(['conversation_id', 'delivered_at', 'id']);
        });

        Schema::table('operator_inbox', function (Blueprint $table) {
            $table->dropColumn(['persona', 'kind', 'sender_persona']);
        });
    }
};
