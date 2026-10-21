<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetFormKeepsSyncedTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_form_offers_and_selects_a_type_written_by_an_integration(): void
    {
        // Level and LITSRMM write "Windows Workstation", which is not one of the
        // form's fixed types. The select must still carry it, selected, or any
        // save of the form clears the asset's type.
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['asset_type' => 'Windows Workstation']);

        $resp = $this->actingAs($user)->get(route('assets.edit', $asset));

        $resp->assertOk();
        $resp->assertSee('<option value="Windows Workstation" selected>Windows Workstation</option>', false);
    }

    public function test_edit_form_lists_a_fixed_type_only_once(): void
    {
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['asset_type' => 'Laptop']);

        $html = $this->actingAs($user)->get(route('assets.edit', $asset))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<option value="Laptop"'));
        $this->assertStringContainsString('<option value="Laptop" selected>Laptop</option>', $html);
    }
}
