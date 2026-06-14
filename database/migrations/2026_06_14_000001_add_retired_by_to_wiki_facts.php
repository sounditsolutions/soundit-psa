<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wiki_facts', function (Blueprint $table) {
            // Anchor: confirmed_by already exists (§4.1). retired_by sits right after it.
            $table->foreignId('retired_by')->nullable()->after('confirmed_by')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wiki_facts', fn (Blueprint $t) => $t->dropConstrainedForeignId('retired_by'));
    }
};
