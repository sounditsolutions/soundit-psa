<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $migrated = 0;
        $orphaned = 0;

        DB::table('phone_calls')
            ->whereNotNull('halo_ticket_id')
            ->whereNull('ticket_id')
            ->orderBy('id')
            ->chunk(100, function ($calls) use (&$migrated, &$orphaned) {
                foreach ($calls as $call) {
                    $ticket = DB::table('tickets')
                        ->where('halo_id', $call->halo_ticket_id)
                        ->first();

                    if ($ticket) {
                        DB::table('phone_calls')
                            ->where('id', $call->id)
                            ->update(['ticket_id' => $ticket->id]);
                        $migrated++;
                    } else {
                        $orphaned++;
                    }
                }
            });

        if ($migrated || $orphaned) {
            echo "Phone call ticket links: {$migrated} backfilled, {$orphaned} had no matching local ticket.\n";
        }
    }

    public function down(): void
    {
        // No-op: the migration is additive (fills ticket_id without clearing halo_ticket_id)
    }
};
