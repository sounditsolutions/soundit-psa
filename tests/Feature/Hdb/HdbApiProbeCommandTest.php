<?php

namespace Tests\Feature\Hdb;

use App\Enums\NoteType;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Support\HdbApiConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HdbApiProbeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_unconfigured_probe_refuses_without_requests(): void
    {
        $this->assertSame(1, Artisan::call('hdb:api-probe'));
        $this->assertStringContainsString('configured=false', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_probe_prints_only_measurements_not_identifiers_or_content(): void
    {
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
        $ticket = Ticket::factory()->create();
        $data = HdbApiReportClientTest::fixture();
        $note = TicketNote::create(['ticket_id' => $ticket->id, 'note_type' => NoteType::System, 'body' => 'synthetic-note', 'is_private' => true, 'noted_at' => now()]);
        $note->forceFill(['hdb_press_id' => $data['report_id']])->save();
        $data['ticket_id'] = (string) $ticket->id;
        $data['report_url'] = '/pressView.php?pressID='.$data['report_id'];
        Http::fake([
            HdbApiConfig::BASE_URL.'/reports/'.$data['report_id'] => Http::response($data),
            HdbApiConfig::BASE_URL.'/reports?*' => Http::response(['data' => [$data], 'pagination' => ['next_cursor' => null]]),
        ]);
        $this->assertSame(0, Artisan::call('hdb:api-probe', ['--notes' => 1]));
        $output = Artisan::output();
        $this->assertDoesNotMatchRegularExpression('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/i', $output);
        $this->assertDoesNotMatchRegularExpression('/\d{4,}/', $output);
        foreach (['synthetic-host', 'Synthetic Person', 'synthetic-message', 'synthetic-api-key', 'report_id', 'https://'] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        $rows = array_map(fn ($line) => json_decode($line, true), explode("\n", trim($output)));
        $this->assertTrue($rows[0]['h1_hit']);
        $this->assertTrue($rows[0]['h2_hit']);
        $this->assertSame(1, $rows[0]['h3_match_count']);
        $this->assertTrue($rows[0]['binding_ticket_id_eq']);
        $this->assertSame('complete', $rows[0]['status']);
        $this->assertSame('not_advertised', $rows[0]['sprite_reason']);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET' || parse_url($r->url(), PHP_URL_HOST) !== parse_url(HdbApiConfig::BASE_URL, PHP_URL_HOST));
        $this->assertSame(1, $rows[1]['rows']);
        Http::assertSentCount(2);
    }

    public function test_invalid_note_bound_refuses_without_requests(): void
    {
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
        $this->assertSame(1, Artisan::call('hdb:api-probe', ['--notes' => 101]));
        Http::assertNothingSent();
    }
}
