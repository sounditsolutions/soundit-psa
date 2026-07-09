<?php

namespace Tests\Feature\Email;

use App\Models\Email;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Regression for psa-3ek1: /emails?preset=all returned a 500 (TypeError
 * "Cannot access offset of type string on string" at Email.php) whenever any
 * listed email stored its to_recipients as plain address strings — the shape
 * DevDataSeeder writes — because primaryRecipientDisplay()/…Address() assumed
 * the ['name' => .., 'address' => ..] map shape.
 */
class EmailIndexAllPresetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_all_preset_renders_with_string_shaped_recipients(): void
    {
        $user = User::factory()->create();

        // Seeded/legacy shape: to_recipients is an array of plain strings.
        Email::create([
            'graph_id' => 'test-string-shape',
            'direction' => 'inbound',
            'from_address' => 'sender@example.com',
            'from_name' => 'Sender Example',
            'to_recipients' => ['string-recipient@example.com'],
            'subject' => 'String-shaped recipients',
            'received_at' => now(),
        ]);

        // Graph shape: to_recipients is an array of name/address maps.
        Email::create([
            'graph_id' => 'test-map-shape',
            'direction' => 'inbound',
            'from_address' => 'other@example.com',
            'to_recipients' => [['name' => 'Map Recipient', 'address' => 'map@example.com']],
            'subject' => 'Map-shaped recipients',
            'received_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('emails.index', ['preset' => 'all']));

        $response->assertOk();
        $response->assertSee('string-recipient@example.com');
        $response->assertSee('Map Recipient');
    }
}
