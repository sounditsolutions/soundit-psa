<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiImportSiteNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_site_notes_into_notes_page_once(): void
    {
        $client = Client::factory()->create([
            'site_notes' => "VPN is FortiClient.\n\nServer room code 4521 is in Keeper.",
        ]);
        $empty = Client::factory()->create(['site_notes' => null]);

        $this->artisan('wiki:import-site-notes')->assertSuccessful();

        $notes = WikiPage::forClient($client->id)->where('slug', 'notes')->first();
        $this->assertStringContainsString('VPN is FortiClient.', $notes->body_md);
        $this->assertNotNull($notes->meta['site_notes_imported_at'] ?? null);

        $emptyNotes = WikiPage::forClient($empty->id)->where('slug', 'notes')->first();
        $this->assertNull($emptyNotes?->meta['site_notes_imported_at'] ?? null);

        $revisionCount = $notes->revisions()->count();
        $this->artisan('wiki:import-site-notes')->assertSuccessful();
        $this->assertSame($revisionCount, $notes->fresh()->revisions()->count());
    }
}
