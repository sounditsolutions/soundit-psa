<?php

namespace Tests\Feature\Wiki;

use App\Models\Client;
use App\Models\Ticket;
use App\Services\Wiki\Mining\WikiTicketContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiTicketContextTest extends TestCase
{
    use RefreshDatabase;

    private function makeTicket(array $attrs = []): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->create(array_merge([
            'client_id' => $client->id,
            'subject' => 'VPN drops daily',
            'description' => 'User reports VPN disconnects every afternoon.',
            'resolution' => 'Replaced FortiClient 7.0 with 7.2; disabled DTLS. Stable since.',
        ], $attrs));
    }

    public function test_builds_bounded_context_with_core_fields(): void
    {
        $ticket = $this->makeTicket();

        $context = app(WikiTicketContext::class)->build($ticket);

        $this->assertStringContainsString('VPN drops daily', $context);
        $this->assertStringContainsString('Replaced FortiClient 7.0 with 7.2', $context);
        $this->assertStringContainsString('RESOLUTION', $context);
    }

    public function test_truncates_oversized_bodies(): void
    {
        $ticket = $this->makeTicket(['description' => str_repeat('x', 20_000)]);

        $context = app(WikiTicketContext::class)->build($ticket);

        $this->assertLessThan(12_000, strlen($context));
        $this->assertStringContainsString('[truncated]', $context);
    }

    public function test_redacts_secrets_in_gathered_material(): void
    {
        $ticket = $this->makeTicket(['resolution' => 'Reset the admin password to Hunter2 and rebooted.']);

        $context = app(WikiTicketContext::class)->build($ticket);

        $this->assertStringNotContainsString('Hunter2', $context);
        $this->assertStringContainsString('[REDACTED:credential]', $context);
    }
}
