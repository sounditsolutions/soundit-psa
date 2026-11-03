<?php

namespace Tests\Feature\Hdb;

use App\Enums\NoteType;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Hdb\HdbReportClient;
use App\Services\Hdb\HdbReportFetchAuthorizer;
use App\Support\HdbApiConfig;
use App\Support\HdbPortalConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HdbReportSourceOrderTest extends TestCase
{
    use RefreshDatabase;

    private const PRESS = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private Ticket $ticket;

    private TicketNote $note;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setEncrypted('hdb_api_key', 'synthetic-key');
        Setting::setValue('hdb_email', 'synthetic@example.test');
        Setting::setEncrypted('hdb_password', 'synthetic-password');
        $this->app->instance(HdbPortalConfig::HOST_RESOLVER, fn () => ['93.184.216.34']);
        $this->ticket = Ticket::factory()->create(['client_id' => Client::factory()->create()->id]);
        $this->note = TicketNote::create(['ticket_id' => $this->ticket->id, 'note_type' => NoteType::System, 'body' => 'synthetic', 'is_private' => true, 'noted_at' => now()]);
        $this->note->forceFill(['hdb_press_id' => self::PRESS])->save();
    }

    private function fixture(): array
    {
        $data = HdbApiReportClientTest::fixture();
        $data['ticket_id'] = (string) $this->ticket->id;

        return $data;
    }

    private function fake($api): void
    {
        Http::fake([
            HdbApiConfig::BASE_URL.'/reports/'.self::PRESS => $api,
            HdbPortalConfig::DEFAULT_BASE_URL.'/login' => Http::response('<html><h1>Dashboard</h1></html>'),
            HdbPortalConfig::DEFAULT_BASE_URL.'/gatekeeper_auth.php?*getFile=ticket.json*' => Http::response(['uploadComplete' => true, 'redactDiagnostic' => false, 'redactScreenshots' => true]),
            HdbPortalConfig::DEFAULT_BASE_URL.'/gatekeeper_auth.php?*getFile=report.json*' => Http::response(array_fill_keys(HdbReportClient::REQUIRED_REPORT_SECTIONS, [])),
        ]);
    }

    public static function fallbackOutcomes(): array
    {
        return [
            '401' => [401, 'authentication_required'],
            '403 forbidden' => [403, 'forbidden'],
            '403 version' => [403, 'api_version_forbidden'],
            '403 disabled' => [403, 'account_disabled'],
            '404 proving' => [404, 'report_not_found'],
            '422 os' => [422, 'unsupported_report_os'],
            '500' => [500, 'internal_error'],
            '503' => [503, 'report_storage_unavailable'],
            'non json' => [200, null],
            'transport' => [null, null],
        ];
    }

    #[DataProvider('fallbackOutcomes')]
    public function test_fallback_is_once_and_counted(?int $status, ?string $code): void
    {
        $records = [];
        $this->app['events']->listen(MessageLogged::class, function ($event) use (&$records) {
            if ($event->message === 'hdb_api_fallback') {
                $records[] = [$event->level, $event->context];
            }
        });
        $this->fake($status === null ? Http::failedConnection() : Http::response($code === null ? 'synthetic-non-json' : ['error' => ['code' => $code]], $status));
        $result = (new HdbReportClient)->fetchForNote($this->ticket->id, $this->note->id);
        $this->assertSame('portal', $result->source);
        $this->assertSame('fetched', $result->status->value);
        $this->assertSame([['info', ['http_status' => $status, 'code' => $code]]], $records);
        Http::assertSentCount(5);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/login')));
    }

    public static function finalOutcomes(): array
    {
        return array_map(fn ($case) => [$case], ['ok', 'redaction', 'incomplete', '429', 'mismatch', 'redaction unreadable', '404 other', '422 other', '403 redacted']);
    }

    #[DataProvider('finalOutcomes')]
    public function test_final_api_outcomes_never_fallback(string $case): void
    {
        $data = $this->fixture();
        $status = 200;
        $expected = 'fetched';
        if ($case === 'redaction') {
            $data['diagnostics_redacted'] = true;
        } elseif ($case === 'incomplete') {
            $data['status'] = 'pending';
            $expected = 'incomplete';
        } elseif ($case === 'mismatch') {
            $data['ticket_number'] = 'synthetic-wrong-ticket';
            $expected = 'refused';
        } elseif ($case === 'redaction unreadable') {
            unset($data['diagnostics_redacted']);
            $expected = 'refused';
        } elseif ($case === '429') {
            $status = 429;
            $data = ['error' => ['code' => 'rate_limited']];
            $expected = 'unreachable';
        } elseif ($case === '404 other' || $case === '422 other' || $case === '403 redacted') {
            $status = (int) $case;
            $data = ['error' => ['code' => $status === 403 ? 'instant_replay_redacted' : 'report_data_not_found']];
            $expected = $status === 403 ? 'unauthenticated' : 'unreachable';
        }
        $this->fake(Http::response($data, $status));
        $result = (new HdbReportClient)->fetchForNote($this->ticket->id, $this->note->id);
        $this->assertSame('api', $result->source);
        $this->assertSame($expected, $result->status->value);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => ! str_starts_with($r->url(), HdbApiConfig::BASE_URL.'/'));
    }

    public function test_unset_key_is_portal_only(): void
    {
        Setting::where('key', 'hdb_api_key')->first()->delete();
        $this->fake(Http::response($this->fixture()));
        $result = (new HdbReportClient)->fetchForNote($this->ticket->id, $this->note->id);
        $this->assertSame('portal', $result->source);
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($r) => str_starts_with($r->url(), HdbApiConfig::BASE_URL));
    }

    public function test_unconfigured_portal_cannot_fallback(): void
    {
        Setting::where('key', 'hdb_password')->first()->delete();
        $this->fake(Http::response(['error' => ['code' => 'report_not_found']], 404));
        $result = (new HdbReportClient)->fetchForNote($this->ticket->id, $this->note->id);
        $this->assertSame('api', $result->source);
        $this->assertSame('unreachable', $result->status->value);
        Http::assertSentCount(1);
    }

    public function test_authorizer_runs_once_before_any_api_request(): void
    {
        $this->fake(Http::response($this->fixture()));
        $authorizer = \Mockery::mock(HdbReportFetchAuthorizer::class)->makePartial();
        $authorizer->shouldReceive('authorize')->once()->with($this->ticket->id, self::PRESS)->passthru();
        $client = new HdbReportClient($authorizer);
        $this->assertSame('fetched', $client->fetchForNote($this->ticket->id, $this->note->id)->status->value);
        Http::assertSentCount(1);
    }

    public function test_refused_authority_never_calls_api(): void
    {
        $this->fake(Http::response($this->fixture()));
        $this->note->delete();
        $this->assertSame('refused', (new HdbReportClient)->fetch($this->ticket->id, self::PRESS)->status->value);
        Http::assertNothingSent();
    }

    protected function tearDown(): void
    {
        try {
            // The unchanged portal handshake posts its login; API requests remain GET-only.
            Http::assertNotSent(fn ($r) => str_starts_with($r->url(), HdbApiConfig::BASE_URL) && $r->method() !== 'GET');
            Http::assertNotSent(fn ($r) => ! in_array(parse_url($r->url(), PHP_URL_HOST), [parse_url(HdbApiConfig::BASE_URL, PHP_URL_HOST), parse_url(HdbPortalConfig::DEFAULT_BASE_URL, PHP_URL_HOST)], true));
        } finally {
            parent::tearDown();
        }
    }
}
