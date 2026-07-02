<?php

namespace Tests\Feature\Settings;

use App\Models\McpToken;
use App\Models\SignalDestination;
use App\Models\User;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class McpTokenSignalDestinationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'operator@soundit.co']);
    }

    public function test_detail_page_shows_linked_signal_destinations(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();
        SignalDestination::create([
            'label' => 'Chet signal inbox',
            'type' => 'mcp',
            'mcp_token_id' => $token->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.mcp-tokens.show', $token))
            ->assertOk()
            ->assertSee('Alerts Hub Destinations')
            ->assertSee('Chet signal inbox');
    }

    public function test_signal_destination_secret_fields_are_encrypted_at_rest(): void
    {
        $destination = SignalDestination::create([
            'label' => 'Wake receiver',
            'type' => 'mcp',
            'address' => 'https://example.test/hook',
            'wake_url' => 'https://example.test/wake',
            'wake_secret' => 'wake-secret',
        ]);

        $this->assertSame('https://example.test/hook', $destination->fresh()->address);
        $this->assertSame('https://example.test/wake', $destination->fresh()->wake_url);
        $this->assertSame('wake-secret', $destination->fresh()->wake_secret);

        $raw = DB::table('signal_destinations')->where('id', $destination->id)->first();
        $this->assertNotSame('https://example.test/hook', $raw->address);
        $this->assertNotSame('https://example.test/wake', $raw->wake_url);
        $this->assertNotSame('wake-secret', $raw->wake_secret);
    }

    public function test_can_link_and_unlink_mcp_signal_destination_to_token(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();
        $destination = SignalDestination::create([
            'label' => 'Office pack signal inbox',
            'type' => 'mcp',
        ]);

        $this->actingAs($this->user)
            ->post(route('settings.mcp-tokens.signal-destinations.link', $token), [
                'signal_destination_id' => $destination->id,
            ])
            ->assertRedirect(route('settings.mcp-tokens.show', $token));

        $this->assertSame($token->id, $destination->fresh()->mcp_token_id);
        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'token/destination_link',
            'tool_name' => 'chet',
        ]);

        $this->actingAs($this->user)
            ->delete(route('settings.mcp-tokens.signal-destinations.unlink', [$token, $destination]))
            ->assertRedirect(route('settings.mcp-tokens.show', $token));

        $this->assertNull($destination->fresh()->mcp_token_id);
        $this->assertDatabaseHas('mcp_audit_logs', [
            'method' => 'token/destination_unlink',
            'tool_name' => 'chet',
        ]);
    }

    public function test_link_rejects_non_mcp_destinations(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $token = McpToken::where('label', 'chet')->firstOrFail();
        $destination = SignalDestination::create([
            'label' => 'Webhook receiver',
            'type' => 'webhook',
        ]);

        $this->actingAs($this->user)
            ->post(route('settings.mcp-tokens.signal-destinations.link', $token), [
                'signal_destination_id' => $destination->id,
            ])
            ->assertSessionHasErrors('signal_destination_id');

        $this->assertNull($destination->fresh()->mcp_token_id);
    }
}
