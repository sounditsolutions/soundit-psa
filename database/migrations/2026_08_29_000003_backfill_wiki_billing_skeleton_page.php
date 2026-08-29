<?php

use App\Enums\WikiScope;
use App\Models\Client;
use App\Services\Wiki\WikiSkeletonService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the `billing` skeleton page for clients seeded before it joined the blueprint.
 *
 * WikiFactExtractor::TARGETS gains billing/{arrangements,licensing} in this same change,
 * and every write path (mining, wiki_add_fact, LessonCapture) validates dynamically
 * against TARGETS — so the allowlist widens for EVERY client the moment this deploys,
 * while skeleton pages are otherwise created only on demand. Seeding here sequences the
 * backfill INTO the deploy (`php artisan migrate`) instead of leaving it to whoever next
 * touches each client's wiki: an accepted billing fact must always have its page and its
 * wiki:facts markers to land in, because a dropped write is permanent (the run's content
 * hash is marked processed and the ticket is never re-mined).
 *
 * Only clients that already hold a client-scope skeleton are touched — an unseeded client
 * still gets its whole skeleton on first demand, exactly as before. ensureForClient() is
 * idempotent and creates only the pages that are missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wiki_pages')) {
            return;
        }

        $seededClientIds = DB::table('wiki_pages')
            ->where('scope', WikiScope::Client->value)
            ->whereNotNull('client_id')
            ->distinct()
            ->pluck('client_id')
            ->all();

        $alreadyHaveBilling = DB::table('wiki_pages')
            ->where('scope', WikiScope::Client->value)
            ->where('slug', 'billing')
            ->whereNotNull('client_id')
            ->distinct()
            ->pluck('client_id')
            ->all();

        $needBackfill = array_values(array_diff($seededClientIds, $alreadyHaveBilling));
        if ($needBackfill === []) {
            return;
        }

        $skeleton = app(WikiSkeletonService::class);
        $backfilled = 0;

        foreach (array_chunk($needBackfill, 100) as $chunk) {
            foreach (Client::whereIn('id', $chunk)->get() as $client) {
                $skeleton->ensureForClient($client);
                $backfilled++;
            }
        }

        echo "Wiki skeleton: billing page backfilled for {$backfilled} client(s).\n";
    }

    public function down(): void
    {
        // Irreversible by design: once seeded these are ordinary wiki pages (staff may have
        // edited them, mined facts may already be composed into them), so a rollback must
        // not delete client documentation.
    }
};
