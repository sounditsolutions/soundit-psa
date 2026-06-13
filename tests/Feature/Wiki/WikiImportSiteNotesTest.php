<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\WikiPage;
use App\Services\Wiki\WikiSkeletonService;
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

    public function test_skips_client_whose_notes_page_already_has_imported_section(): void
    {
        $client = Client::factory()->create(['site_notes' => 'Fresh notes from CRM.']);
        app(WikiSkeletonService::class)->ensureForClient($client);

        $notes = WikiPage::forClient($client->id)->where('slug', 'notes')->first();
        $notes->update(['body_md' => $notes->body_md."\n## Imported site notes\n\nHand-written by a tech.\n"]);
        $before = $notes->fresh()->body_md;

        $this->artisan('wiki:import-site-notes')
            ->expectsOutputToContain("'Imported site notes' section already exists")
            ->assertSuccessful();

        $notes->refresh();
        $this->assertSame($before, $notes->body_md);
        $this->assertNull($notes->meta['site_notes_imported_at'] ?? null);
    }
}
