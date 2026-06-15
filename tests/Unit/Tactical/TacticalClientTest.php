<?php

namespace Tests\Unit\Tactical;

use App\Services\Tactical\TacticalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TacticalClientTest extends TestCase
{
    // The TacticalClient constructor reads config from the settings table, so the
    // schema must exist for app(TacticalClient::class) to resolve.
    use RefreshDatabase;

    public function test_tactical_client_is_a_singleton(): void
    {
        $this->assertSame(app(TacticalClient::class), app(TacticalClient::class));
    }
}
