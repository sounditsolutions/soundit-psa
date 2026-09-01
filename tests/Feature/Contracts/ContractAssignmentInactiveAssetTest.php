<?php

namespace Tests\Feature\Contracts;

use App\Enums\AssignmentRuleType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub #971 / #972. Both asset assignment rules (AllAssets, AssetsByType)
 * filter is_active, and BillingService::countAssets does too — so an inactive
 * asset never bills and never matches a rule. Two consequences the contract
 * assignments screen has to tell the truth about:
 *
 *  - #971: the AllAssets rule type is labelled "All Assets" but only ever
 *    matches active ones.
 *  - #972: the attached-assets table showed no is_active at all, and rule
 *    evaluation only detaches assignment_source='rule' rows — so a MANUAL row
 *    on an inactive asset is the one pairing that nothing cleans up on its own.
 *
 * Billing is unaffected either way; this is a reporting/visibility fix.
 */
class ContractAssignmentInactiveAssetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The exact inactive marker the assignments table emits. Asserting the raw
     * markup rather than the bare word keeps an unrelated "Inactive" badge
     * elsewhere on the contract page from satisfying these tests.
     */
    private const INACTIVE_BADGE = '<span class="badge bg-secondary ms-1" style="font-size: 0.6rem;">Inactive</span>';

    private Client $client;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::factory()->create(['name' => 'Acme Corp']);
        $this->contract = Contract::create([
            'client_id' => $this->client->id,
            'name' => 'Managed Services MSA',
            'type' => 'managed',
            'status' => 'active',
            'start_date' => '2026-01-01',
        ]);
    }

    private function attach(Asset $asset, string $source): void
    {
        $this->contract->assets()->attach($asset->id, [
            'assignment_source' => $source,
            'assigned_at' => now(),
        ]);
    }

    private function asset(bool $active, string $hostname): Asset
    {
        return Asset::factory()->create([
            'client_id' => $this->client->id,
            'hostname' => $hostname,
            'is_active' => $active,
        ]);
    }

    private function showPage()
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('contracts.show', $this->contract));
    }

    public function test_all_assets_rule_type_is_labelled_all_active_assets(): void
    {
        // The enum is the single source of the string (#971).
        $this->assertSame('All Active Assets', AssignmentRuleType::AllAssets->label());

        // And it reaches the rule-type dropdown a human actually reads.
        $this->showPage()->assertOk()->assertSee('All Active Assets');
    }

    public function test_inactive_attached_asset_is_marked_inactive_in_the_table(): void
    {
        $this->attach($this->asset(false, 'DEAD-0001'), 'manual');

        $this->showPage()
            ->assertOk()
            ->assertSee('DEAD-0001')
            ->assertSee(self::INACTIVE_BADGE, false);
    }

    public function test_manual_row_on_an_inactive_asset_is_flagged_as_stranded(): void
    {
        $this->attach($this->asset(false, 'DEAD-0002'), 'manual');

        $this->showPage()
            ->assertOk()
            ->assertSee('table-warning', false)
            ->assertSee('Rule evaluation never detaches manual rows');
    }

    public function test_active_manual_and_inactive_rule_rows_are_not_flagged(): void
    {
        // Active + manual: nothing wrong with it.
        $this->attach($this->asset(true, 'LIVE-0001'), 'manual');
        // Inactive + rule: the next evaluation detaches it, so it is NOT stranded —
        // but it still has to show as inactive.
        $this->attach($this->asset(false, 'DEAD-0003'), 'rule');

        $this->showPage()
            ->assertOk()
            ->assertSee('LIVE-0001')
            ->assertSee('DEAD-0003')
            ->assertSee(self::INACTIVE_BADGE, false)
            ->assertDontSee('table-warning', false)
            ->assertDontSee('Rule evaluation never detaches manual rows');
    }

    public function test_all_active_assets_page_shows_no_inactive_marker(): void
    {
        $this->attach($this->asset(true, 'LIVE-0002'), 'manual');

        $this->showPage()
            ->assertOk()
            ->assertSee('LIVE-0002')
            ->assertDontSee(self::INACTIVE_BADGE, false)
            ->assertDontSee('table-warning', false);
    }
}
