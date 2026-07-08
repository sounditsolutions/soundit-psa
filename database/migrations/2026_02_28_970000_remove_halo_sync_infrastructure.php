<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('halo_webhooks');

        DB::table('settings')->where('key', 'like', 'halo_%')->delete();
    }

    public function down(): void
    {
        // no-op — table and settings are not recoverable
    }
};
