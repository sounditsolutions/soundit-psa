<?php

namespace Tests\Feature\Hdb;

use App\Models\Setting;
use App\Services\Hdb\HdbApiReportClient;
use App\Services\Hdb\HdbReportStatus;
use App\Support\HdbApiConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class HdbApiReportClientTest extends TestCase
{
    use RefreshDatabase;

    private const ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
    }

    /** Synthetic ReportDetail from the vendor's v1.0.0-beta OpenAPI schema. */
    public static function fixture(): array
    {
        return [
            'report_id' => self::ID, 'lookup_id' => null, 'hostname' => 'synthetic-host',
            'os' => 'Windows', 'ticket_number' => null, 'ticket_id' => '17',
            'endpoint_id' => null, 'group' => null, 'created_at' => null,
            'ticket_submitted' => true, 'status' => 'complete', 'report_url' => null,
            'ticket' => ['number' => null, 'id' => '17', 'message' => 'synthetic-message', 'selection_1' => null, 'selection_2' => null, 'permit_technician_connect' => false, 'contact' => ['name' => 'Synthetic Person', 'email' => null, 'business' => null, 'phone_mobile' => null, 'phone_business' => null, 'phone_extension' => null], 'expires_at' => null],
            'user' => ['username' => null, 'name' => 'Synthetic Person', 'email' => null],
            'diagnostics_redacted' => false, 'diagnostics_redaction_reason' => null,
            'identity_verification' => ['status' => 'unknown', 'verified' => null, 'verified_at' => null],
            'breach_exposure' => ['status' => 'not_applicable', 'exposed' => null, 'breaches' => []],
            'instant_replay' => ['redacted' => false, 'redaction_reason' => null, 'sprite' => null],
            'diagnostics' => ['system' => [], 'hardware' => [], 'storage' => [], 'network' => [], 'security' => [], 'performance' => [], 'software' => [], 'processes' => [], 'events' => [], 'printers' => [], 'platform' => []],
        ];
    }

    private function fake(array $data): void
    {
        Http::fake([HdbApiConfig::BASE_URL.'/reports/'.self::ID => Http::response($data)]);
    }

    public function test_complete_is_memoized_and_only_get_to_fixed_host_with_bearer(): void
    {
        $this->fake(self::fixture());
        $client = new HdbApiReportClient;
        $result = $client->report(self::ID, 17);
        $this->assertSame(HdbReportStatus::Fetched, $result->status);
        $this->assertSame($result, $client->report(self::ID, 17));
        $this->assertArrayNotHasKey('breach_exposure', $result->ticket);
        $this->assertArrayNotHasKey('identity_verification', $result->ticket);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === HdbApiConfig::BASE_URL.'/reports/'.self::ID && $r->hasHeader('Authorization', 'Bearer synthetic-api-key'));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_absent_redaction_flag_refuses_without_payload(): void
    {
        $data = self::fixture();
        unset($data['diagnostics_redacted']);
        $this->fake($data);
        $result = (new HdbApiReportClient)->report(self::ID, 17);
        $this->assertSame('redaction_flag_unreadable', $result->reason);
        $this->assertNull($result->report);
        $this->assertNull($result->ticket);
    }

    public function test_non_boolean_redaction_refuses(): void
    {
        $data = self::fixture();
        $data['diagnostics_redacted'] = 'maybe';
        $this->fake($data);
        $this->assertSame('redaction_flag_unreadable', (new HdbApiReportClient)->report(self::ID, 17)->reason);
    }

    public static function bindings(): array
    {
        return [
            'both equal' => [17, '17', true],
            'id null' => [null, '17', true],
            'number null' => ['17', null, true],
            'id differs' => [18, '17', false],
            'number differs' => ['17', 18, false],
            'both null' => [null, null, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bindings')]
    public function test_every_non_null_binding_must_match(mixed $id, mixed $number, bool $accepted): void
    {
        $data = self::fixture();
        $data['ticket_id'] = $id;
        $data['ticket_number'] = $number;
        $this->fake($data);
        $result = (new HdbApiReportClient)->report(self::ID, 17);
        $this->assertSame($accepted ? HdbReportStatus::Fetched : HdbReportStatus::Refused, $result->status);
        $this->assertSame($accepted ? 'ok' : 'report_ticket_mismatch', $result->reason);
    }

    public function test_binding_mismatch_refuses(): void
    {
        $this->fake(self::fixture());
        $result = (new HdbApiReportClient)->report(self::ID, 18);
        $this->assertSame(HdbReportStatus::Refused, $result->status);
        $this->assertSame('report_ticket_mismatch', $result->reason);
    }

    public function test_redacted_sprite_is_never_requested(): void
    {
        $data = self::fixture();
        $data['instant_replay'] = ['redacted' => true, 'redaction_reason' => 'user', 'sprite' => '/sprite'];
        Http::fake([
            HdbApiConfig::BASE_URL.'/reports/'.self::ID => Http::response($data),
            HdbApiConfig::BASE_URL.'/reports/'.self::ID.'/instant-replay/sprite' => Http::response('synthetic-sprite'),
        ]);
        $this->assertNull((new HdbApiReportClient)->report(self::ID, 17)->screenshot);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/instant-replay/sprite'));
        Http::assertSentCount(1);
    }

    public function test_allowed_sprite_is_fixed_path_resized_and_bucketed_before_resize(): void
    {
        $data = self::fixture();
        $data['instant_replay']['sprite'] = '/do-not-follow';
        $image = imagecreatetruecolor(2000, 1000);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Http::fake([
            HdbApiConfig::BASE_URL.'/reports/'.self::ID => Http::response($data),
            HdbApiConfig::BASE_URL.'/reports/'.self::ID.'/instant-replay/sprite' => Http::response($bytes),
        ]);
        $client = new HdbApiReportClient;
        $result = $client->report(self::ID, 17);
        $this->assertSame(1568, getimagesizefromstring($result->screenshot)[0]);
        $this->assertNull($result->spriteReason);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/instant-replay/sprite') && $r->hasHeader('Accept', 'image/png') && ! $r->hasHeader('Accept', 'application/json'));
        $this->assertSame('<=4MP', $client->spriteSizeBucket());
        $this->assertSame($result, $client->report(self::ID, 17));
        $this->assertSame('<=4MP', $client->spriteSizeBucket());
        $this->assertSame('instant-replay.png', HdbApiReportClient::SPRITE_FILENAME);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => ! str_starts_with($r->url(), HdbApiConfig::BASE_URL) || $r->method() !== 'GET');
    }

    public function test_redacted_diagnostics_are_withheld(): void
    {
        $data = self::fixture();
        $data['diagnostics_redacted'] = true;
        $this->fake($data);
        $result = (new HdbApiReportClient)->report(self::ID, 17);
        $this->assertSame([], $result->report);
        $this->assertTrue($result->redaction->diagnostic);
    }

    public function test_pending_is_incomplete_and_not_memoized(): void
    {
        $data = self::fixture();
        $data['status'] = 'pending';
        $this->fake($data);
        $client = new HdbApiReportClient;
        $this->assertSame(HdbReportStatus::Incomplete, $client->report(self::ID, 17)->status);
        $this->assertSame('upload_incomplete', $client->report(self::ID, 17)->reason);
        Http::assertSentCount(2);
    }

    public function test_other_incomplete_statuses(): void
    {
        $sequence = Http::sequence();
        foreach (['requested', 'pressed', null, 'synthetic-unknown'] as $status) {
            $data = self::fixture();
            $data['status'] = $status;
            $sequence->push($data);
        }
        Http::fake([HdbApiConfig::BASE_URL.'/reports/'.self::ID => $sequence]);
        foreach (['upload_incomplete', 'upload_incomplete', 'upload_gate_missing', 'upload_gate_missing'] as $reason) {
            $this->assertSame($reason, (new HdbApiReportClient)->report(self::ID, 17)->reason);
        }
    }

    public function test_each_missing_section_is_degraded(): void
    {
        $sequence = Http::sequence();
        foreach (HdbApiReportClient::REQUIRED_SECTIONS as $section) {
            $data = self::fixture();
            unset($data['diagnostics'][$section]);
            $sequence->push($data);
        }
        Http::fake([HdbApiConfig::BASE_URL.'/reports/'.self::ID => $sequence]);
        foreach (HdbApiReportClient::REQUIRED_SECTIONS as $section) {
            $result = (new HdbApiReportClient)->report(self::ID, 17);
            $this->assertSame(HdbReportStatus::Degraded, $result->status);
            $this->assertSame([$section], $result->missingSections);
        }
    }

    public function test_long_retry_after_is_not_retried(): void
    {
        Http::fake([HdbApiConfig::BASE_URL.'/reports/'.self::ID => Http::response(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '60'])]);
        $result = (new HdbApiReportClient)->report(self::ID, 17);
        $this->assertSame('rate_limited', $result->reason);
        $this->assertSame(60, $result->retryAfterSeconds);
        $this->assertTrue($result->retryable());
        Http::assertSentCount(1);
    }

    public function test_short_retry_after_retries_once(): void
    {
        Http::fake([HdbApiConfig::BASE_URL.'/reports/'.self::ID => Http::sequence()->push([], 429, ['Retry-After' => '2'])->push(self::fixture())]);
        $this->assertSame(HdbReportStatus::Fetched, (new HdbApiReportClient)->report(self::ID, 17)->status);
        Http::assertSentCount(2);
    }

    public function test_error_mapping_without_retry_and_logs_exclude_payload(): void
    {
        $records = [];
        $this->app['events']->listen(MessageLogged::class, function ($event) use (&$records) {
            $records[] = [$event->level, $event->message, $event->context];
        });
        $statuses = [401 => 'unauthenticated', 403 => 'unauthenticated', 404 => 'unreachable', 422 => 'unreachable', 500 => 'unreachable', 503 => 'unreachable', 302 => 'malformed', 409 => 'malformed'];
        $sequence = Http::sequence();
        foreach ($statuses as $status => $expected) {
            $sequence->push(['error' => ['code' => 'forbidden', 'message' => 'synthetic-private-message', 'details' => ['name' => 'Synthetic Person']]], $status, ['X-Request-ID' => 'synthetic-request']);
        }
        Http::fake([HdbApiConfig::BASE_URL.'/reports/'.self::ID => $sequence]);
        foreach ($statuses as $expected) {
            $this->assertSame($expected, (new HdbApiReportClient)->report(self::ID, 17)->status->value);
        }
        Http::assertSentCount(count($statuses));
        $this->assertCount(count($statuses), $records);
        foreach ($records as [$level, $message, $context]) {
            $this->assertSame('warning', $level);
            $this->assertSame('hdb_api', $message);
            $this->assertSame(['http_status', 'code', 'request_id'], array_keys($context));
        }
        $this->assertStringNotContainsString('synthetic-private-message', json_encode($records));
        $this->assertStringNotContainsString('synthetic-api-key', json_encode($records));
        $this->assertStringNotContainsString('Synthetic Person', json_encode($records));
    }

    public function test_non_json_and_transport(): void
    {
        Http::fake([HdbApiConfig::BASE_URL.'/reports/'.self::ID => Http::sequence()->push('not json')->pushResponse(Http::failedConnection())]);
        $this->assertSame(HdbReportStatus::Malformed, (new HdbApiReportClient)->report(self::ID, 17)->status);
        $this->assertSame(HdbReportStatus::Unreachable, (new HdbApiReportClient)->report(self::ID, 17)->status);
    }

    public function test_empty_page_continues_until_null_cursor(): void
    {
        Http::fake([HdbApiConfig::BASE_URL.'/reports*' => Http::sequence()->push(['data' => [], 'pagination' => ['next_cursor' => 'synthetic-cursor']])->push(['data' => [self::fixture()], 'pagination' => ['next_cursor' => null]])]);
        $result = (new HdbApiReportClient)->listReports(['group' => 'ALL']);
        $this->assertTrue($result['exhausted']);
        $this->assertCount(1, $result['items']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => ($r['cursor'] ?? null) === 'synthetic-cursor' && $r['group'] === 'ALL');
    }

    public function test_failed_sprite_is_degraded_and_never_memoized(): void
    {
        $data = self::fixture();
        $data['instant_replay']['sprite'] = '/synthetic';
        $sequence = Http::sequence();
        foreach ([401, 403, 404, 429, 500] as $status) {
            $sequence->push([], $status);
        }
        $sequence->pushResponse(Http::failedConnection());
        Http::fake([
            HdbApiConfig::BASE_URL.'/reports/'.self::ID => Http::response($data),
            HdbApiConfig::BASE_URL.'/reports/'.self::ID.'/instant-replay/sprite' => $sequence,
        ]);
        $client = new HdbApiReportClient;
        for ($i = 0; $i < 6; $i++) {
            $result = $client->report(self::ID, 17);
            $this->assertSame(HdbReportStatus::Degraded, $result->status);
            $this->assertSame('fetch_failed', $result->spriteReason);
            $this->assertFalse($result->importable());
            $this->assertNotNull($result->report);
        }
        Http::assertSentCount(12);
    }

    public function test_sprite_reasons_and_ceiling_precede_decode(): void
    {
        $data = self::fixture();
        $data['instant_replay']['sprite'] = '/synthetic';
        $ihdr = 'IHDR'.pack('NNCCCCC', 6000, 6000, 8, 2, 0, 0, 0);
        $bomb = "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
        $this->mock(\App\Services\AttachmentService::class)->shouldNotReceive('resizeImageBytesForAi');
        Http::fake([
            HdbApiConfig::BASE_URL.'/reports/'.self::ID => Http::response($data),
            HdbApiConfig::BASE_URL.'/reports/'.self::ID.'/instant-replay/sprite' => Http::sequence()
                ->push($bomb)->push(str_repeat('x', \App\Services\Assistant\AssistantToolExecutor::MAX_ATTACHMENT_BYTES + 1))->push('synthetic-not-image'),
        ]);
        foreach (['oversize', 'oversize', 'not_image'] as $reason) {
            $result = (new HdbApiReportClient)->report(self::ID, 17);
            $this->assertNull($result->screenshot);
            $this->assertSame($reason, $result->spriteReason);
        }
    }

    public function test_sprite_stream_limit_is_oversize_not_fetch_failed(): void
    {
        $data = self::fixture();
        $data['instant_replay']['sprite'] = '/synthetic';
        Http::fake([
            HdbApiConfig::BASE_URL.'/reports/'.self::ID => Http::response($data),
            HdbApiConfig::BASE_URL.'/reports/'.self::ID.'/instant-replay/sprite' => fn () => throw new \RuntimeException('synthetic', 0, new \LengthException),
        ]);
        $result = (new HdbApiReportClient)->report(self::ID, 17);
        $this->assertSame('oversize', $result->spriteReason);
        $this->assertNull($result->screenshot);
    }

    public function test_unadvertised_and_redacted_sprite_reasons(): void
    {
        $data = self::fixture();
        $sequence = Http::sequence()->push($data);
        $data['instant_replay']['redacted'] = true;
        $sequence->push($data);
        Http::fake([HdbApiConfig::BASE_URL.'/reports/'.self::ID => $sequence]);
        foreach (['not_advertised', 'redacted'] as $reason) {
            $this->assertSame($reason, (new HdbApiReportClient)->report(self::ID, 17)->spriteReason);
        }
        Http::assertSentCount(2);
    }

    public function test_undecodable_png_is_not_image(): void
    {
        $data = self::fixture();
        $data['instant_replay']['sprite'] = '/synthetic';
        $ihdr = 'IHDR'.pack('NNCCCCC', 20, 20, 8, 2, 0, 0, 0);
        $bytes = "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
        Http::fake([
            HdbApiConfig::BASE_URL.'/reports/'.self::ID => Http::response($data),
            HdbApiConfig::BASE_URL.'/reports/'.self::ID.'/instant-replay/sprite' => Http::response($bytes),
        ]);
        $this->assertSame('not_image', (new HdbApiReportClient)->report(self::ID, 17)->spriteReason);
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNotSent(fn ($r) => $r->method() !== 'GET' || parse_url($r->url(), PHP_URL_HOST) !== parse_url(HdbApiConfig::BASE_URL, PHP_URL_HOST));
        } finally {
            parent::tearDown();
        }
    }

    public function test_page_bound_is_not_exhaustion(): void
    {
        Http::fake([HdbApiConfig::BASE_URL.'/reports*' => Http::response(['data' => [], 'pagination' => ['next_cursor' => 'synthetic-repeat']])]);
        $this->assertFalse((new HdbApiReportClient)->listReports([])['exhausted']);
        Http::assertSentCount(HdbApiReportClient::MAX_PAGES);
    }
}
