<?php

namespace Tests\Feature\Prospect;

use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ConvertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        // Enable auto-triage so the observer dispatches RunTriagePipeline for
        // Active clients — the test verifies the prospect gate is LIFTED post-convert.
        Setting::setValue('triage_enabled', '1');
        Setting::setValue('triage_auto_new_tickets', '1');
    }

    public function test_convert_flips_stage_preserves_history_and_reenables_triage(): void
    {
        $user = User::factory()->create();
        $prospect = Client::factory()->prospect()->create();
        $ticket = Ticket::factory()->create(['client_id' => $prospect->id]);   // inert prospect ticket

        $this->actingAs($user)->post(route('prospects.convert', $prospect))->assertRedirect();

        $prospect->refresh();
        $this->assertSame(\App\Enums\ClientStage::Active, $prospect->stage);
        $this->assertTrue($ticket->fresh()->client->is($prospect));            // history preserved, same client_id

        // future ticket on the converted client now runs triage
        Ticket::factory()->create(['client_id' => $prospect->id]);
        Bus::assertDispatched(\App\Jobs\RunTriagePipeline::class);
    }
}
