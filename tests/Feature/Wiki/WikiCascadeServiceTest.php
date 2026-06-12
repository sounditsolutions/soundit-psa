<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiPageKind;
use App\Models\Client;
use App\Models\WikiPage;
use App\Services\Wiki\WikiCascadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiCascadeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_merged_view_replaces_matching_sections_and_appends_new_ones(): void
    {
        $client = Client::factory()->create();
        $global = WikiPage::factory()->create([
            'slug' => 'runbooks/onboarding',
            'kind' => WikiPageKind::Runbook,
            'body_md' => "## Accounts\n\nCreate M365 user.\n\n## Hardware\n\nStandard laptop.\n",
        ]);
        WikiPage::factory()->forClient($client)->create([
            'slug' => 'runbooks/onboarding',
            'kind' => WikiPageKind::Deviation,
            'parent_page_id' => $global->id,
            'body_md' => "## Hardware\n\nMac only — no Windows laptops.\n\n## VPN\n\nAlways issue FortiClient.\n",
        ]);

        $merged = app(WikiCascadeService::class)->mergedView($global, $client->id);

        $this->assertStringContainsString('Create M365 user.', $merged['body_md']);
        $this->assertStringContainsString('Mac only — no Windows laptops.', $merged['body_md']);
        $this->assertStringNotContainsString('Standard laptop.', $merged['body_md']);
        $this->assertStringContainsString('Always issue FortiClient.', $merged['body_md']);
        $this->assertStringContainsString('*Client deviation*', $merged['body_md']);
        $this->assertEqualsCanonicalizing(['hardware', 'vpn'], $merged['deviation_anchors']);
    }

    public function test_merged_view_without_deviation_returns_global_body(): void
    {
        $client = Client::factory()->create();
        $global = WikiPage::factory()->create([
            'slug' => 'runbooks/offboarding',
            'kind' => WikiPageKind::Runbook,
            'body_md' => "## Steps\n\nDisable accounts.\n",
        ]);

        $merged = app(WikiCascadeService::class)->mergedView($global, $client->id);

        $this->assertSame($global->body_md, $merged['body_md']);
        $this->assertSame([], $merged['deviation_anchors']);
    }
}
