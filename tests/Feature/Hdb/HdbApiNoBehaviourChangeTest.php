<?php

namespace Tests\Feature\Hdb;

use App\Enums\NoteType;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Hdb\HdbReportClient;
use App\Services\Mcp\HdbReportTool;
use App\Support\HdbPortalConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HdbApiNoBehaviourChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_set_tool_still_uses_only_portal(): void
    {
        Http::preventStrayRequests();
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
        Setting::setValue('hdb_base_url', HdbPortalConfig::DEFAULT_BASE_URL);
        Setting::setValue('hdb_email', 'synthetic@example.test');
        Setting::setEncrypted('hdb_password', 'synthetic-password');
        $this->app->instance(HdbPortalConfig::HOST_RESOLVER, fn () => ['93.184.216.34']);
        $ticket = Ticket::factory()->create(['client_id' => Client::factory()->create()->id]);
        $note = TicketNote::create(['ticket_id' => $ticket->id, 'note_type' => NoteType::System, 'body' => 'synthetic-note', 'is_private' => true, 'noted_at' => now()]);
        $note->forceFill(['hdb_press_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'])->save();
        Http::fake([
            HdbPortalConfig::DEFAULT_BASE_URL.'/login' => Http::response('<html><h1>Dashboard</h1></html>'),
            HdbPortalConfig::DEFAULT_BASE_URL.'/gatekeeper_auth.php?*getFile=ticket.json*' => Http::response(['uploadComplete' => true, 'redactDiagnostic' => false, 'redactScreenshots' => true]),
            HdbPortalConfig::DEFAULT_BASE_URL.'/gatekeeper_auth.php?*getFile=report.json*' => Http::response(array_fill_keys(HdbReportClient::REQUIRED_REPORT_SECTIONS, [])),
        ]);
        $result = app(HdbReportTool::class)->execute(['ticket_id' => $ticket->id, 'note_id' => $note->id], $ticket->client_id);
        $this->assertSame('fetched', $result['status']);
        $this->assertArrayNotHasKey('source', $result);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'getFile=report.json'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'cyl.helpdeskbuttons.com'));
    }
}
