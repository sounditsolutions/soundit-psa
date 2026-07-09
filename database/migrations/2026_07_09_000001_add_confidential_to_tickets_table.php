<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a confidential flag to tickets.
     *
     * A confidential ticket is visible in the client portal ONLY to the
     * specific contact it is assigned to — even when other contacts at the
     * same company hold company-wide portal access. Used for sensitive
     * matters (HR/termination, employee investigations, a pending sale)
     * where Sound IT must be involved but coworkers must not see the ticket.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->boolean('confidential')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('confidential');
        });
    }
};
