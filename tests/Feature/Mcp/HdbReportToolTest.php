<?php

namespace Tests\Feature\Mcp;

use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Hdb\HdbApiReportClient;
use App\Services\Mcp\HdbReportTool;
use App\Support\HdbApiConfig;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Real staff MCP route, API reader and authorizer; only HTTP is faked.
 * Synthetic fixtures follow the API contract in HdbApiReportClientTest.
 */
class HdbReportToolTest extends TestCase
{
    use RefreshDatabase;

    // Deliberately synthetic; never an id copied from a client ticket.
    private const PRESS = 'deadbeef-0000-4000-8000-0000000000c5';

    private const SIBLING_PRESS = 'deadbeef-0000-4000-8000-0000000000c6';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');

        Http::preventStrayRequests();
    }

    // ---------------------------------------------------------------- harness

    /** @param  list<string>|null  $grants  null = the legacy full-surface token */
    private function mcp(string $method, array $params, ?array $grants = [HdbReportTool::NAME]): TestResponse
    {
        $token = McpConfig::rotateStaffToken(allowedTools: $grants, label: 'synthetic-agent');

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
        ]);
    }

    private function callTool(array $args, ?array $grants = [HdbReportTool::NAME]): TestResponse
    {
        return $this->mcp('tools/call', ['name' => HdbReportTool::NAME, 'arguments' => $args], $grants);
    }

    /** @return array<string, mixed> */
    private function decoded(TestResponse $r): array
    {
        $r->assertOk();

        return json_decode((string) $r->json('result.content.0.text'), true) ?? [];
    }

    private function helpdeskTicket(?Client $client = null): Ticket
    {
        return Ticket::factory()->create([
            'source' => TicketSource::HelpdeskButton->value,
            'client_id' => ($client ?? Client::factory()->create())->id,
        ]);
    }

    /** A note on $ticket, keyed with $pressId the way capture keys it (or unkeyed). */
    private function note(Ticket $ticket, ?string $pressId = self::PRESS, array $attributes = []): TicketNote
    {
        $note = TicketNote::create([
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::System,
            'body' => $pressId !== null
                ? "View report: https://beta.helpdeskbuttons.com/pressView.php?pressID={$pressId}"
                : 'A synthetic note with no report link.',
            'is_private' => true,
            'noted_at' => now(),
        ] + $attributes);

        if ($pressId !== null) {
            TicketNote::whereKey($note->id)->toBase()->update(['hdb_press_id' => $pressId]);
        }

        return $note->fresh();
    }

    /** Point the ticket-level display cache at $pressId (never an authority). */
    private function cacheOnTicket(Ticket $ticket, string $pressId = self::PRESS): void
    {
        Ticket::whereKey($ticket->id)->toBase()->update(['hdb_press_id' => $pressId]);
    }

    private function png(int $w = 64, int $h = 48): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    private function fakeWholeReport(?string $screenshot = null, array $overrides = []): void
    {
        $data = \Tests\Feature\Hdb\HdbApiReportClientTest::fixture();
        $data['diagnostics']['system'] = ['hostname' => 'WS-INVENTED-01'];
        $data['diagnostics']['processes'] = ['explorer' => ['PID' => 4242]];
        $data['instant_replay']['sprite'] = '/synthetic';
        $data = array_replace_recursive($data, $overrides);
        Http::fake(function (Request $request) use ($data, $screenshot) {
            if ($request->url() === HdbApiConfig::BASE_URL.'/reports/'.self::PRESS) {
                $data['ticket_id'] = (string) TicketNote::where('hdb_press_id', self::PRESS)->firstOrFail()->ticket_id;

                return Http::response($data);
            }
            if ($request->url() === HdbApiConfig::BASE_URL.'/reports/'.self::PRESS.'/instant-replay/sprite') {
                return Http::response($screenshot ?? $this->png());
            }
            throw new \LogicException('Unexpected synthetic request');
        });
    }

    // ------------------------------------------------------------- happy path

    /**
     * Positive direction first: a refuse-everything tool would pass every
     * refusal below. Text sections plus the screenshot as an image in
     * get_ticket_attachment's shape, decoded back to a real PNG.
     */
    public function test_a_captured_note_returns_the_report_text_and_the_screenshot_image(): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        $this->fakeWholeReport($this->png(64, 48));

        $out = $this->decoded($this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id]));

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('fetched', $out['status']);
        $this->assertSame([$ticket->id, $note->id], [$out['ticket_id'], $out['note_id']]);
        $this->assertSame('WS-INVENTED-01', $out['report']['system']['hostname']);
        $this->assertSame(['explorer' => ['PID' => 4242]], $out['report']['processes']);
        $this->assertSame('complete', $out['report_metadata']['status']);
        $this->assertSame('api', $out['source']);
        $this->assertSame(HdbApiReportClient::SPRITE_FILENAME, $out['screenshot']['filename']);
        $this->assertSame([], $out['missing_sections']);
        $this->assertSame(['diagnostic' => false, 'screenshots' => false], $out['redaction']);

        $shot = $out['screenshot'];
        ksort($shot);
        $this->assertSame(['data_base64', 'filename', 'is_image', 'media_type'], array_keys($shot));
        $this->assertTrue($shot['is_image']);
        $this->assertSame('image/png', $shot['media_type']);
        $info = getimagesizefromstring((string) base64_decode($shot['data_base64'], true));
        $this->assertSame([64, 48, 'image/png'], [$info[0], $info[1], $info['mime']]);

        // The press id never leaves (LinkedIds keeps it off reads: paste-to-fetch).
        $this->assertStringNotContainsString(self::PRESS, (string) $this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id])->getContent());
        Http::assertSent(fn (Request $request) => $request->url() === HdbApiConfig::BASE_URL.'/reports/'.self::PRESS);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    // ------------------------------------------------------------ authority

    public function test_a_ticket_of_another_client_is_refused_like_get_ticket_attachment_and_nothing_is_fetched(): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        $other = Client::factory()->create();
        $this->fakeWholeReport();

        $out = $this->decoded($this->callTool(['client_id' => $other->id, 'ticket_id' => $ticket->id, 'note_id' => $note->id]));

        $this->assertSame(['error' => 'Ticket not found or belongs to a different client'], $out);
        Http::assertNothingSent();
    }

    public function test_a_note_keyed_on_another_ticket_is_refused_even_when_this_ticket_cache_names_the_press(): void
    {
        $ticket = $this->helpdeskTicket();
        $elsewhere = $this->helpdeskTicket(Client::find($ticket->client_id));
        $foreignNote = $this->note($elsewhere);
        $this->cacheOnTicket($ticket);
        $this->fakeWholeReport();

        $out = $this->decoded($this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $foreignNote->id]));

        $this->assertSame('refused', $out['status']);
        $this->assertSame('no_keyed_note', $out['refusal']);
        $this->assertStringContainsString('no report was fetched', $out['error']);
        $this->assertArrayNotHasKey('report', $out);
        Http::assertNothingSent();
    }

    /**
     * #1360: a removed note has withdrawn its link. A live sibling note on the
     * same ticket carries another press and the ticket cache names it, so a
     * tool that fetched by the ticket cache instead of by the named live note
     * would return a report here; so would an authorizer that let a trashed
     * note authorize.
     */
    public function test_a_soft_deleted_note_is_refused_even_though_the_ticket_cache_still_names_the_press(): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        $this->note($ticket, self::SIBLING_PRESS);
        $this->cacheOnTicket($ticket, self::SIBLING_PRESS);
        $note->delete();
        $this->assertSoftDeleted($note);
        $this->fakeWholeReport();

        $out = $this->decoded($this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id]));

        $this->assertSame('refused', $out['status']);
        $this->assertSame('no_keyed_note', $out['refusal']);
        Http::assertNothingSent();
    }

    public function test_a_note_with_no_captured_press_is_refused_even_when_the_ticket_cache_names_one(): void
    {
        $ticket = $this->helpdeskTicket();
        $plain = $this->note($ticket, null);
        // A live keyed sibling the cache points at: fetching by the cache
        // rather than by the named note would succeed.
        $this->note($ticket);
        $this->cacheOnTicket($ticket);
        $this->fakeWholeReport();

        $out = $this->decoded($this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $plain->id]));

        $this->assertSame('refused', $out['status']);
        $this->assertSame('no_keyed_note', $out['refusal']);
        Http::assertNothingSent();
    }

    /**
     * A pasted press id or link is never input. The schema declares neither,
     * and the boundary refuses the argument before the tool runs, even when the
     * named note is unkeyed and another note on the ticket carries the press.
     */
    public function test_a_raw_press_id_or_link_is_refused_as_input_and_nothing_is_fetched(): void
    {
        $ticket = $this->helpdeskTicket();
        $this->note($ticket);
        $plain = $this->note($ticket, null);
        $this->fakeWholeReport();

        $props = array_keys(HdbReportTool::definition()['input_schema']['properties']);
        sort($props);
        $this->assertSame(['client_id', 'note_id', 'ticket_id'], $props);

        foreach (['press_id' => self::PRESS, 'link' => 'https://beta.helpdeskbuttons.com/pressView.php?pressID='.self::PRESS] as $key => $value) {
            $r = $this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $plain->id, $key => $value]);
            $r->assertOk();
            $this->assertTrue($r->json('result.isError'));
            $this->assertStringContainsString("Unsupported MCP argument(s): {$key}", (string) $r->json('result.content.0.text'));
        }
        Http::assertNothingSent();

        // Behind the boundary too: the tool itself never reads a press id from
        // its input, so a stray one cannot authorize an unkeyed note.
        $direct = app(HdbReportTool::class)->execute(['ticket_id' => $ticket->id, 'note_id' => $plain->id, 'press_id' => self::PRESS], $ticket->client_id);
        $this->assertSame('no_keyed_note', $direct['refusal'] ?? null);
        Http::assertNothingSent();
    }

    public function test_an_unverified_contact_intake_ticket_is_refused_like_get_ticket_attachment(): void
    {
        $ticket = $this->helpdeskTicket();
        Ticket::whereKey($ticket->id)->toBase()->update(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $note = $this->note($ticket);
        $this->fakeWholeReport();

        $out = $this->decoded($this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id]));

        $this->assertSame(['error' => 'Unverified contact intake.'], $out);
        Http::assertNothingSent();
    }

    public function test_an_unverified_contact_intake_note_is_refused_in_the_no_keyed_note_shape(): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        TicketNote::whereKey($note->id)->toBase()->update(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $this->fakeWholeReport();

        $out = $this->decoded($this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id]));

        $this->assertSame('no_keyed_note', $out['refusal'] ?? null, json_encode($out));
        $this->assertSame('api', $out['source'] ?? null);
        $this->assertStringStartsWith('note_id does not name a live, visible note', (string) ($out['error'] ?? ''));
        Http::assertNothingSent();
    }

    public function test_failed_sprite_with_redacted_diagnostics_warns_only_of_the_sprite(): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
        $data = \Tests\Feature\Hdb\HdbApiReportClientTest::fixture();
        $data['ticket_id'] = (string) $ticket->id;
        $data['diagnostics_redacted'] = true;
        $data['instant_replay']['sprite'] = '/synthetic';
        Http::fake([
            \App\Support\HdbApiConfig::BASE_URL.'/reports/'.self::PRESS => Http::response($data),
            \App\Support\HdbApiConfig::BASE_URL.'/reports/'.self::PRESS.'/instant-replay/sprite' => Http::response([], 500),
        ]);
        $out = $this->decoded($this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id]));
        $this->assertSame('degraded', $out['status']);
        $this->assertSame('sprite_fetch_failed', $out['reason']);
        $this->assertNull($out['report']);
        $this->assertArrayHasKey('report_note', $out);
        $this->assertSame('The Instant Replay sprite fetch failed.', $out['warning']);
    }

    public function test_api_configuration_keeps_the_integration_off_gate(): void
    {
        Setting::setEncrypted('hdb_api_key', 'synthetic-api-key');
        Setting::setValue('t2t_enabled', '0');
        $this->assertNotContains(HdbReportTool::NAME, array_column($this->mcp('tools/list', [])->json('result.tools'), 'name'));
        Setting::setValue('t2t_enabled', '1');
        $this->assertContains(HdbReportTool::NAME, array_column($this->mcp('tools/list', [])->json('result.tools'), 'name'));
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- grants

    /**
     * Explicit grant only: a token without it cannot call or list it, and the
     * legacy full-surface token (allowedTools null) does not inherit it.
     */
    public function test_without_an_explicit_grant_the_tool_is_neither_callable_nor_listed(): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        $this->fakeWholeReport();
        $args = ['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id];

        foreach (['other-grant' => ['get_ticket_attachment'], 'legacy-full-surface' => null] as $label => $grants) {
            $r = $this->callTool($args, $grants);
            $r->assertOk();
            $this->assertTrue($r->json('result.isError'), $label);
            $this->assertStringStartsWith('Tool not allowed for this token: get_hdb_report', (string) $r->json('result.content.0.text'), $label);

            $listed = array_column($this->mcp('tools/list', [], $grants)->json('result.tools'), 'name');
            $this->assertNotContains(HdbReportTool::NAME, $listed, $label);
        }
        Http::assertNothingSent();

        // Positive control on the same list call: granted, it is listed with its schema.
        $tools = collect($this->mcp('tools/list', [])->json('result.tools'))->keyBy('name');
        $this->assertTrue($tools->has(HdbReportTool::NAME));
        $this->assertSame(['client_id', 'ticket_id', 'note_id'], $tools[HdbReportTool::NAME]['inputSchema']['required']);
    }

    public function test_it_is_registered_as_a_sensitive_raw_file_read(): void
    {
        $this->assertContains(HdbReportTool::NAME, McpToolRegistry::RAW_FILE_CONTENT_TOOLS);
        $this->assertContains(HdbReportTool::NAME, array_column(McpToolRegistry::groups()['psa_raw_file']['tools'], 'name'));
        foreach (McpToolRegistry::groups() as $key => $group) {
            if ($key !== 'psa_raw_file') {
                $this->assertNotContains(HdbReportTool::NAME, array_column($group['tools'], 'name'), "must not also sit in {$key}");
            }
        }
    }

    // -------------------------------------------------------- non-fetched

    public static function readOnlyApiFailures(): array
    {
        return [
            'pending' => [['status' => 'pending'], 'upload_incomplete'],
            'unknown gate' => [['status' => 'synthetic-unknown'], 'upload_gate_missing'],
            'unreadable redaction' => [['diagnostics_redacted' => 'maybe'], 'redaction_flag_unreadable'],
            'no key' => ['no-key', 'not_authenticated'],
            'transport' => ['connection', 'transport_error'],
        ];
    }

    #[DataProvider('readOnlyApiFailures')]
    public function test_api_failures_leave_ticket_link_note_and_note_count_unchanged(array|string $answer, string $reason): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        if (is_array($answer)) {
            $this->fakeWholeReport(null, $answer);
        } elseif ($answer === 'no-key') {
            Setting::where('key', 'hdb_api_key')->delete();
            Http::fake();
        } else {
            Http::fake([HdbApiConfig::BASE_URL.'/reports/'.self::PRESS => fn () => throw new \Illuminate\Http\Client\ConnectionException('synthetic transport failure')]);
        }
        $beforeTicket = $ticket->fresh()->getRawOriginal();
        $beforeNote = $note->fresh()->getRawOriginal();
        $beforeCount = TicketNote::withTrashed()->where('ticket_id', $ticket->id)->count();

        // Direct tool execution reaches the client's no-key guard even though
        // the MCP publication gate hides an unconfigured tool.
        $out = app(HdbReportTool::class)->execute(['ticket_id' => $ticket->id, 'note_id' => $note->id], $ticket->client_id);

        $this->assertSame($reason, $out['reason']);
        $this->assertSame('api', $out['source']);
        $this->assertNotEmpty($out['error']);
        if ($answer === 'no-key') {
            $this->assertSame('api_key_missing', $out['auth_reason']);
            Http::assertNothingSent();
        } elseif (is_array($answer)) {
            Http::assertSentCount(1);
        }
        $this->assertSame($beforeTicket, $ticket->fresh()->getRawOriginal());
        $this->assertSame($beforeNote, $note->fresh()->getRawOriginal());
        $this->assertSame($beforeCount, TicketNote::withTrashed()->where('ticket_id', $ticket->id)->count());
    }

    public static function vendorBodyFailures(): array
    {
        return [
            'pending' => [['status' => 'pending'], 200, 'upload_incomplete'],
            'unknown gate' => [['status' => 'synthetic-unknown'], 200, 'upload_gate_missing'],
            'unreadable redaction' => [['diagnostics_redacted' => 'maybe'], 200, 'redaction_flag_unreadable'],
            'rejected' => [[], 401, 'not_authenticated'],
            'forbidden' => [[], 403, 'not_authenticated'],
            'not found' => [[], 404, 'report_not_found'],
            'unsupported' => [[], 422, 'unsupported_report_os'],
            'rate limited' => [[], 429, 'rate_limited'],
            'server failure' => [[], 503, 'transport_error'],
            'unexpected' => [[], 409, 'unexpected_response'],
        ];
    }

    #[DataProvider('vendorBodyFailures')]
    public function test_api_failure_error_excludes_vendor_body_text(array $overrides, int $status, string $reason): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        $message = 'synthetic-vendor-error-canary';
        $hostname = 'synthetic-vendor-host.example.test';
        $data = array_replace_recursive(\Tests\Feature\Hdb\HdbApiReportClientTest::fixture(), $overrides);
        $data['ticket_id'] = (string) $ticket->id;
        $data['error'] = ['code' => 'internal_error', 'message' => $message];
        $data['hostname'] = $hostname;
        Http::fake([HdbApiConfig::BASE_URL.'/reports/'.self::PRESS => Http::response($data, $status)]);

        $out = $this->decoded($this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id]));

        $this->assertSame($reason, $out['reason']);
        $this->assertSame('api', $out['source']);
        $this->assertNotEmpty($out['error']);
        $this->assertStringNotContainsString($message, $out['error']);
        $this->assertStringNotContainsString($hostname, $out['error']);
        Http::assertSentCount(1);
    }

    public static function nonFetchedApiAnswers(): array
    {
        return [
            'unreachable' => ['connection', 'unreachable', 'transport_error'],
            'malformed' => ['malformed', 'malformed', 'unreadable_payload'],
            'server error' => [503, 'unreachable', 'transport_error'],
            'not found' => [404, 'unreachable', 'report_not_found'],
            'rejected' => [401, 'unauthenticated', 'not_authenticated'],
            'forbidden' => [403, 'unauthenticated', 'not_authenticated'],
            'incomplete' => [['status' => 'pending'], 'incomplete', 'upload_incomplete'],
            'upload gate missing' => [['status' => null], 'incomplete', 'upload_gate_missing'],
            'redaction unreadable' => [['diagnostics_redacted' => 'no'], 'refused', 'redaction_flag_unreadable'],
        ];
    }

    #[DataProvider('nonFetchedApiAnswers')]
    public function test_every_non_fetched_status_is_an_error_with_its_reason_never_an_empty_success(mixed $answer, string $status, string $reason): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        if (is_array($answer)) {
            $this->fakeWholeReport(null, $answer);
        } else {
            Http::fake([HdbApiConfig::BASE_URL.'/reports/'.self::PRESS => match (true) {
                $answer === 'connection' => Http::failedConnection(),
                is_int($answer) => Http::response([], $answer),
                default => Http::response('<html>not json</html>'),
            }]);
        }
        $r = $this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id]);
        $out = $this->decoded($r);
        $this->assertTrue($r->json('result.isError'));
        $this->assertSame($status, $out['status']);
        $this->assertSame($reason, $out['reason']);
        $this->assertSame('api', $out['source']);
        $this->assertStringContainsString($reason, $out['error']);
        $this->assertStringNotContainsString('portal', $out['error']);
        $this->assertArrayNotHasKey('report', $out);
        $this->assertArrayNotHasKey('screenshot', $out);
        if ($answer === 404) {
            $this->assertSame('HelpDesk Buttons has no report for this press yet (report_not_found). Nothing was fetched; try again later.', $out['error']);
        }
        Http::assertSentCount(1);
    }

    public function test_a_degraded_report_returns_what_arrived_with_a_warning_naming_the_missing_sections(): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        $data = \Tests\Feature\Hdb\HdbApiReportClientTest::fixture();
        $data['ticket_id'] = (string) $ticket->id;
        unset($data['diagnostics']['events']);
        Http::fake([HdbApiConfig::BASE_URL.'/reports/'.self::PRESS => Http::response($data)]);
        $out = $this->decoded($this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id]));
        $this->assertSame('degraded', $out['status']);
        $this->assertSame(['events'], $out['missing_sections']);
        $this->assertStringContainsString('not a complete report', $out['warning']);
        $this->assertSame($data['diagnostics'], $out['report']);
    }

    // ------------------------------------------------------------- ceilings

    /**
     * get_ticket_attachment's byte ceiling, in its words, checked before any
     * decode. The diagnostics are still returned (the client's fail-soft rule
     * for the image).
     */
    public function test_an_oversize_screenshot_is_refused_at_get_ticket_attachments_ceiling(): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        $oversize = $this->png().str_repeat("\0", AssistantToolExecutor::MAX_ATTACHMENT_BYTES);
        $this->fakeWholeReport($oversize);

        $out = $this->decoded($this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id]));

        $this->assertSame('fetched', $out['status']);
        $this->assertNull($out['screenshot']);
        $this->assertSame('oversize', $out['screenshot_note']);
        $this->assertSame('WS-INVENTED-01', $out['report']['system']['hostname']);
    }

    public function test_a_screenshot_over_the_pixel_ceiling_is_refused_before_decode(): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        // A valid PNG header declaring 6000x6000 (36 MP) over a tiny body: the
        // decompression-bomb shape the header-only pixel check exists for.
        $ihdr = 'IHDR'.pack('NNCCCCC', 6000, 6000, 8, 2, 0, 0, 0);
        $bomb = "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
        $this->fakeWholeReport($bomb);

        $out = $this->decoded($this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id]));

        $this->assertNull($out['screenshot']);
        $this->assertSame('oversize', $out['screenshot_note']);
    }

    public function test_a_screenshot_the_end_user_withheld_is_not_fetched_and_says_why(): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        $this->fakeWholeReport(null, ['instant_replay' => ['redacted' => true]]);

        $out = $this->decoded($this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id]));

        $this->assertNull($out['screenshot']);
        $this->assertSame('redacted', $out['screenshot_note']);
        Http::assertSentCount(1);
    }

    public function test_diagnostic_redaction_withholds_the_report_sections_and_says_so(): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        $this->fakeWholeReport(null, ['diagnostics_redacted' => true]);

        $out = $this->decoded($this->callTool(['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id]));

        $this->assertNull($out['report']);
        $this->assertSame(['diagnostic' => true, 'screenshots' => false], $out['redaction']);
        $this->assertStringContainsString('limited', $out['report_note']);
    }

    // -------------------------------------------------------- discoverability

    /** @return array<string, array{0: string}> */
    public static function discoveryQueries(): array
    {
        return ['helpdesk' => ['helpdesk'], 'hdb' => ['hdb'], 'help button' => ['help button'], 'report' => ['report'], 'screenshot' => ['screenshot']];
    }

    #[DataProvider('discoveryQueries')]
    public function test_search_tools_finds_it_as_granted(string $query): void
    {
        $out = $this->decoded($this->mcp('tools/call', ['name' => 'search_tools', 'arguments' => ['query' => $query]]));

        $match = collect($out['matches'])->firstWhere('name', HdbReportTool::NAME);
        $this->assertNotNull($match, "search_tools '{$query}' must find get_hdb_report");
        $this->assertSame('granted', $match['grant_state']);
    }

    public function test_portal_only_credentials_do_not_publish_the_tool(): void
    {
        Setting::where('key', 'hdb_api_key')->delete();
        Setting::setValue('t2t_enabled', '1');
        Setting::setValue('hdb_base_url', 'https://portal.example.test');
        Setting::setValue('hdb_email', 'synthetic@example.test');
        Setting::setEncrypted('hdb_password', 'synthetic-password');
        Setting::setEncrypted('hdb_totp_secret', 'synthetic-seed');
        $this->assertNotContains(HdbReportTool::NAME, array_column($this->mcp('tools/list', [])->json('result.tools'), 'name'));

        $out = $this->decoded($this->mcp('tools/call', ['name' => 'search_tools', 'arguments' => ['query' => 'helpdesk']]));
        $this->assertSame('unavailable_config', collect($out['matches'])->firstWhere('name', HdbReportTool::NAME)['grant_state'] ?? null);

        $r = $this->callTool(['client_id' => 1, 'ticket_id' => 1, 'note_id' => 1]);
        $this->assertStringStartsWith('Tool not allowed for this token: get_hdb_report', (string) $r->json('result.content.0.text'));
        Http::assertNothingSent();
    }

    /**
     * OFF=OFF (C-47): the API credentials sit on the Tier2Tickets / HelpDesk
     * Buttons card, so switching that integration off unpublishes the tool even
     * with the credentials still saved, and no request is attempted.
     */
    public function test_with_the_integration_switched_off_it_is_not_live_even_with_api_credentials_saved(): void
    {
        $ticket = $this->helpdeskTicket();
        $note = $this->note($ticket);
        $this->fakeWholeReport();
        $args = ['client_id' => $ticket->client_id, 'ticket_id' => $ticket->id, 'note_id' => $note->id];
        $this->assertTrue(HdbApiConfig::isConfigured());

        Setting::setValue('t2t_enabled', '0');

        $out = $this->decoded($this->mcp('tools/call', ['name' => 'search_tools', 'arguments' => ['query' => 'helpdesk']]));
        $this->assertSame('unavailable_config', collect($out['matches'])->firstWhere('name', HdbReportTool::NAME)['grant_state'] ?? null);
        $this->assertNotContains(HdbReportTool::NAME, array_column($this->mcp('tools/list', [])->json('result.tools'), 'name'));

        $r = $this->callTool($args);
        $r->assertOk();
        $this->assertTrue($r->json('result.isError'));
        $this->assertStringStartsWith('Tool not allowed for this token: get_hdb_report', (string) $r->json('result.content.0.text'));
        Http::assertNothingSent();

        // Positive control: switched back on, the same token lists it again.
        Setting::setValue('t2t_enabled', '1');
        $this->assertContains(HdbReportTool::NAME, array_column($this->mcp('tools/list', [])->json('result.tools'), 'name'));
    }
}
