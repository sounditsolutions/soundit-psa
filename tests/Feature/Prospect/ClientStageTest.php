<?php

namespace Tests\Feature\Prospect;

use App\Enums\ClientStage;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_clients_default_to_active_stage(): void
    {
        $c = Client::factory()->create();
        $this->assertSame(ClientStage::Active, $c->fresh()->stage);
    }

    public function test_scope_operational_excludes_prospects_and_suspended_but_active_scope_keeps_prospects(): void
    {
        $active = Client::factory()->create(['is_active' => true]);
        $prospect = Client::factory()->prospect()->create();              // stage=Prospect, is_active=true
        $suspended = Client::factory()->create(['is_active' => false]);    // stage=Active, is_active=false

        $operational = Client::operational()->pluck('id');
        $this->assertTrue($operational->contains($active->id));
        $this->assertFalse($operational->contains($prospect->id));   // prospect excluded from "real customer"
        $this->assertFalse($operational->contains($suspended->id));  // suspended excluded too

        $listed = Client::active()->pluck('id');                     // scopeActive UNCHANGED = is_active=true
        $this->assertTrue($listed->contains($prospect->id));         // prospect still appears in the Clients list
        $this->assertFalse($listed->contains($suspended->id));
    }
}
