<?php

namespace Tests\Feature\Hdb;

use App\Enums\NoteType;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Hdb\HdbReportReader;
use App\Support\HdbApiConfig;
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
        // Retired credentials can remain stored without enabling a second reader.
        Setting::setValue('hdb_email', 'synthetic@example.test');
        Setting::setEncrypted('hdb_password', 'synthetic-password');
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
            'https://portal.example.test/*' => Http::response('synthetic retired reader'),
        ]);
    }

    public static function formerFallbackOutcomes(): array
    {
        return [
            '401' => [401, 'authentication_required'],
            '403 forbidden' => [403, 'forbidden'],
            '403 version' => [403, 'api_version_forbidden'],
            '403 disabled' => [403, 'account_disabled'],
            '404' => [404, 'report_not_found'],
            '422 os' => [422, 'unsupported_report_os'],
            '500' => [500, 'internal_error'],
            '503' => [503, 'report_storage_unavailable'],
            'non json' => [200, null],
            'transport' => [null, null],
        ];
    }

    #[DataProvider('formerFallbackOutcomes')]
    public function test_every_former_fallback_is_final(?int $status, ?string $code): void
    {
        $records = [];
        $this->app['events']->listen(MessageLogged::class, function ($event) use (&$records) {
            if ($event->message === 'hdb_api_fallback') {
                $records[] = [$event->level, $event->context];
            }
        });
        $this->fake($status === null ? Http::failedConnection() : Http::response($code === null ? 'synthetic-non-json' : ['error' => ['code' => $code]], $status));
        $result = (new HdbReportReader)->fetchForNote($this->ticket->id, $this->note->id);
        $this->assertSame('api', $result->source);
        $this->assertNotSame('fetched', $result->status->value);
        $this->assertSame([], $records);
        if ($status === 404) {
            $this->assertSame('report_not_found', $result->reason);
        }
        Http::assertSentCount(1);
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
        $result = (new HdbReportReader)->fetchForNote($this->ticket->id, $this->note->id);
        $this->assertSame('api', $result->source);
        $this->assertSame($expected, $result->status->value);
        Http::assertSentCount(1);
    }

    public function test_refused_authority_never_calls_api(): void
    {
        $this->fake(Http::response($this->fixture()));
        $this->note->delete();
        $result = (new HdbReportReader)->fetchForNote($this->ticket->id, $this->note->id);
        $this->assertSame('refused', $result->status->value);
        $this->assertSame('api', $result->source);
        Http::assertNothingSent();
    }

    public function test_refusal_source_without_key_is_api(): void
    {
        Setting::where('key', 'hdb_api_key')->first()->delete();
        $this->test_refused_authority_never_calls_api();
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNotSent(fn ($r) => $r->method() !== 'GET' || ! str_starts_with($r->url(), HdbApiConfig::BASE_URL.'/'));
        } finally {
            parent::tearDown();
        }
    }
}
