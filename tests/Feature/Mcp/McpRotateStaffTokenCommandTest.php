<?php

namespace Tests\Feature\Mcp;

use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpRotateStaffTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_scoped_token_rotation_requires_confirmation_before_replacing_existing_label(): void
    {
        $existing = McpConfig::rotateStaffToken(
            allowedTools: ['get_ticket_detail'],
            label: 'scoped',
        );

        $this->artisan('mcp:rotate-staff-token', ['--tool' => ['list_open_tickets']])
            ->expectsConfirmation('Rotate this scoped staff MCP token?', 'no')
            ->assertSuccessful();

        $resolved = McpConfig::resolveStaffToken($existing);

        $this->assertNotNull($resolved);
        $this->assertSame('scoped', $resolved->label);
        $this->assertSame(['get_ticket_detail'], $resolved->allowedTools);
    }
}
