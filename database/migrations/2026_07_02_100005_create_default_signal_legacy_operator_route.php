<?php

use App\Services\Signals\DefaultSignalRoutes;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(DefaultSignalRoutes::class)->ensureLegacyOperatorWebhookRoute();
    }

    public function down(): void
    {
        //
    }
};
