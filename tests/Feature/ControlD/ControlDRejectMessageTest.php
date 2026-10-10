<?php

namespace Tests\Feature\ControlD;

use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDOnboardingStaged;
use App\Services\ControlD\ControlDProvisioning;
use App\Services\ControlD\ControlDWriteRejectedException;
use App\Support\ControlDConfig;
use App\Support\McpConfig;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * eGIRKv51 PR (1): a Control D onboarding write refused in the vendor error envelope keeps
 * the vendor's own error.message (sanitized, capped, redacted; ControlDClient::vendorMessage)
 * on the rejected intent's reason_detail, and the run result and action log quote it,
 * attributed to Control D. Uncertain, transport and non-envelope outcomes keep nothing.
 * Every vendor exchange is a Guzzle MockHandler; Http::preventStrayRequests() guards the
 * rest. All values are synthetic (G-13).
 */
class ControlDRejectMessageTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-key';

    private const ORG = 'testorg001';

    private array $history = [];

    /** B3 refuses an ambient transaction; same construction as ControlDOnboardingStagedTest. */
    public function beginDatabaseTransaction(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function configure(): void
    {
        Setting::setValue('controld_enabled', '1');
        Setting::setEncrypted('controld_api_key', self::KEY);
        Setting::setValue('controld_stats_endpoint', 'synthetic-region');
        foreach (['tactical_client_field_id' => '18', 'default_profile_id' => 'testprofile01', 'code_expiry_days' => '7',
            'code_device_limit_headroom' => '2', 'code_analytics_level' => '0', 'code_intercept_mode' => 'standard'] as $key => $value) {
            Setting::setValue('controld_'.$key, $value);
        }
        Setting::setValue(ControlDConfig::ONBOARDING_ENABLED_SETTING, '1');
        Setting::setValue('triage_system_user_id', (string) User::factory()->create(['name' => 'AI Actor'])->id);
    }

    private function ok(array $body): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'body' => $body]));
    }

    private function envelope(int $code, mixed $message, int $status = 400): Response
    {
        $error = ['code' => $code];
        if ($message !== '__absent__') {
            $error['message'] = $message;
        }

        return new Response($status, [], json_encode(['body' => [], 'success' => false, 'error' => $error]));
    }

    private function transport(array $responses): ControlDClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([...$responses, ...array_fill(0, 8, new Response(503))]));
        $stack->push(Middleware::history($this->history));

        return new ControlDClient(['api_key' => self::KEY, 'handler' => $stack]);
    }

    private function bindVendor(array $responses): void
    {
        $transport = $this->transport($responses);
        $this->app->instance(ControlDOnboardingStaged::class, new ControlDOnboardingStaged($transport, new ControlDProvisioning($transport)));
    }

    private function listed(?string $global = 'testprofile01'): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/sub-organization.json')), true)['body']['sub_organizations'][0];
        $row['PK'] = self::ORG;
        if ($global === null) {
            unset($row['parent_profile']);
        } else {
            $row['parent_profile']['PK'] = $global;
        }

        return $row;
    }

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(array $client = []): array
    {
        $client = Client::factory()->create(array_merge(['name' => 'Synthetic Organization', 'email' => 'synthetic@example.invalid'], $client));
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Onboard to Control D', 'status' => \App\Enums\TicketStatus::New->value]);

        return compact('client', 'ticket');
    }

    private function stageRun(array $fixture): TechnicianRun
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['controld_onboard_client:staged'], label: 'opsbot', aiActor: true);
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'controld_onboard_client', 'arguments' => ['client_id' => $fixture['client']->id, 'ticket_id' => $fixture['ticket']->id, 'reason' => 'new client', 'staged' => true]],
        ]);
        $result = json_decode((string) $response->json('result.content.0.text'), true) ?? [];
        $this->assertTrue($result['success'] ?? false, json_encode($result));

        return TechnicianRun::findOrFail($result['run_id']);
    }

    private function approve(TechnicianRun $run): string
    {
        $this->actingAs(User::factory()->admin()->create(['is_active' => true]))->post(route('cockpit.approve', $run));

        return (string) session('error');
    }

    private function summary(TechnicianRun $run): string
    {
        return (string) TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'error')->sole()->summary;
    }

    /** The code step's approval exchange up to the POST: step re-derivation GET, devices/types, profiles. */
    private function codeUpToPost(): array
    {
        return [
            $this->ok(['sub_organizations' => [$this->listed()]]),
            $this->ok(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]),
            $this->ok(['profiles' => [['PK' => 'testprofile01']]]),
        ];
    }

    // ── end to end: rejected + message -> stored and shown, per step ───────────────────

    public function test_code_step_rejection_stores_the_vendor_message_and_the_run_result_and_action_log_quote_it(): void
    {
        $this->configure();
        $fixture = $this->fixture(['controld_org_id' => self::ORG]);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);
        $run = $this->stageRun($fixture);
        $this->assertSame('code', $run->proposed_meta['redacted_params']['step']);
        $this->bindVendor([...$this->codeUpToPost(), $this->envelope(40003, 'Invalid parameter: max')]);
        $error = $this->approve($run);

        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['rejected', 'post', 40003, 'vendor rejected the write', 'Invalid parameter: max', null],
            [$intent->state, $intent->phase, $intent->reason_code, $intent->reason, $intent->reason_detail, $intent->active_client_id]);
        $this->assertSame('Control D rejected the code write: vendor rejected the write. Nothing created. Control D\'s message (code 40003): "Invalid parameter: max"', $error);
        $this->assertSame("onboard:{$fixture['client']->id}:code: Control D rejected the 'code' write — vendor rejected the write (code 40003); intent {$intent->id} rejected, nothing created. Control D's message (code 40003): \"Invalid parameter: max\"", $this->summary($run));
        $this->assertSame('POST', $this->history[3]['request']->getMethod());
        $this->assertCount(4, $this->history);
    }

    public function test_organization_step_rejection_stores_and_shows_the_vendor_message(): void
    {
        $this->configure();
        $fixture = $this->fixture();
        $run = $this->stageRun($fixture);
        $this->assertSame('organization', $run->proposed_meta['redacted_params']['step']);
        $this->bindVendor([$this->envelope(40001, 'Sub-organization limit reached')]);
        $error = $this->approve($run);

        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['organization', 'rejected', 40001, 'Sub-organization limit reached'], [$intent->operation, $intent->state, $intent->reason_code, $intent->reason_detail]);
        $this->assertSame('Control D rejected the organization write: vendor rejected the write. Nothing created. Control D\'s message (code 40001): "Sub-organization limit reached"', $error);
        $this->assertSame("onboard:{$fixture['client']->id}:organization: Control D rejected the 'organization' write — vendor rejected the write (code 40001); intent {$intent->id} rejected, nothing created. Control D's message (code 40001): \"Sub-organization limit reached\"", $this->summary($run));
        $this->assertNull($fixture['client']->fresh()->controld_org_id);
        $this->assertSame(['POST /organizations/suborg'], array_map(fn ($h) => $h['request']->getMethod().' '.$h['request']->getUri()->getPath(), $this->history));
    }

    public function test_global_profile_step_rejection_stores_and_shows_the_vendor_message(): void
    {
        $this->configure();
        $fixture = $this->fixture(['controld_org_id' => self::ORG]);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed(null)]])]);
        $run = $this->stageRun($fixture);
        $this->assertSame('global-profile', $run->proposed_meta['redacted_params']['step']);
        $this->bindVendor([
            $this->ok(['sub_organizations' => [$this->listed(null)]]),
            $this->ok(['sub_organizations' => [$this->listed(null)]]),
            $this->envelope(40000, 'Profile not found'),
        ]);
        $error = $this->approve($run);

        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['global-profile', 'rejected', 40000, 'Profile not found'], [$intent->operation, $intent->state, $intent->reason_code, $intent->reason_detail]);
        $this->assertSame('Control D rejected the global-profile write: vendor rejected the write. Nothing changed. Control D\'s message (code 40000): "Profile not found"', $error);
        $this->assertSame("onboard:{$fixture['client']->id}:global-profile: Control D rejected the 'global-profile' write — vendor rejected the write (code 40000); intent {$intent->id} rejected, nothing changed. Control D's message (code 40000): \"Profile not found\"", $this->summary($run));
        $this->assertSame('PUT', $this->history[2]['request']->getMethod());
    }

    // ── rejected without a usable message -> null, old text byte-identical ─────────────

    public static function unusableMessages(): array
    {
        return [
            'absent' => ['__absent__'],
            'null' => [null],
            'integer' => [40003],
            'array' => [['text' => 'nested']],
            'empty' => [''],
            'only-whitespace-and-controls' => [" \t\r\n\x00 "],
            'only-format-characters' => ["\u{200B}\u{FEFF}\u{2028}"],
        ];
    }

    #[DataProvider('unusableMessages')]
    public function test_rejection_without_a_usable_message_stores_null_and_keeps_the_old_text(mixed $message): void
    {
        $this->configure();
        $fixture = $this->fixture(['controld_org_id' => self::ORG]);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);
        $run = $this->stageRun($fixture);
        $this->bindVendor([...$this->codeUpToPost(), $this->envelope(40003, $message)]);
        $error = $this->approve($run);

        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['rejected', 40003, null], [$intent->state, $intent->reason_code, $intent->reason_detail]);
        // Byte-identical to the text before this change.
        $this->assertSame('Control D rejected the code write: vendor rejected the write. Nothing created.', $error);
        $this->assertSame("onboard:{$fixture['client']->id}:code: Control D rejected the 'code' write — vendor rejected the write (code 40003); intent {$intent->id} rejected, nothing created.", $this->summary($run));
    }

    /** The read-only-key fixture keeps its fixed reason and its fix text; its vendor message is quoted after them. */
    public function test_read_only_key_rejection_keeps_its_fix_text_and_quotes_the_vendor_message(): void
    {
        $this->configure();
        $fixture = $this->fixture();
        $run = $this->stageRun($fixture);
        $this->bindVendor([new Response(403, [], file_get_contents(base_path('tests/Fixtures/ControlD/read-only-rejection.json')))]);
        $error = $this->approve($run);
        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['vendor key is read-only', 'This read-only token does not have access to this endpoint'], [$intent->reason, $intent->reason_detail]);
        $this->assertSame('Control D rejected the organization write: vendor key is read-only — the API key is a Read token; replace it with a Write token in Settings > Integrations, then stage again. Nothing created. Control D\'s message (code 40301): "This read-only token does not have access to this endpoint"', $error);
    }

    // ── uncertain / transport / non-envelope -> nothing stored, nothing shown ─────────

    public static function notRejections(): array
    {
        $msg = 'Invalid parameter: max';

        return [
            'server-5xx-envelope' => [fn () => new Response(503, [], json_encode(['success' => false, 'error' => ['code' => 40003, 'message' => $msg]]))],
            'string-code-4xx' => [fn () => new Response(400, [], json_encode(['success' => false, 'error' => ['code' => '40003', 'message' => $msg]]))],
            'success-true-4xx' => [fn () => new Response(400, [], json_encode(['success' => true, 'error' => ['code' => 40003, 'message' => $msg]]))],
            'error-not-object-4xx' => [fn () => new Response(400, [], json_encode(['success' => false, 'error' => $msg]))],
            'html-4xx' => [fn () => new Response(403, [], "<html>{$msg}</html>")],
            '2xx-unconfirmed' => [fn () => new Response(200, [], json_encode(['success' => false, 'error' => ['code' => 40003, 'message' => $msg]]))],
            'transport' => [fn () => new \GuzzleHttp\Exception\ConnectException($msg, new \GuzzleHttp\Psr7\Request('POST', 'provision'))],
        ];
    }

    #[DataProvider('notRejections')]
    public function test_uncertain_transport_and_non_envelope_failures_store_and_show_nothing(\Closure $make): void
    {
        $this->configure();
        $fixture = $this->fixture(['controld_org_id' => self::ORG]);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);
        $run = $this->stageRun($fixture);
        $this->bindVendor([...$this->codeUpToPost(), $make()]);
        $error = $this->approve($run);

        $intent = ControlDOnboardingIntent::sole();
        $this->assertSame(['uncertain', null, null], [$intent->state, $intent->reason_code, $intent->reason_detail]);
        $this->assertStringStartsWith('HARD FAULT', $error);
        $this->assertStringNotContainsString('Invalid parameter', $error);
        $this->assertStringNotContainsString("Control D's message", $error);
        $this->assertStringNotContainsString('Invalid parameter', $this->summary($run));
    }

    /** A vendor envelope rejection AFTER the write (a read-back phase) is uncertain, and keeps no message. */
    public function test_finish_keeps_a_detail_only_on_a_rejected_outcome(): void
    {
        $this->configure();
        $actor = User::factory()->admin()->create(['is_active' => true]);
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $transport = $this->transport([]);
        $writer = new ControlDOnboardingStaged($transport, new ControlDProvisioning($transport));
        $id = $writer->stageCode($actor, $client->id, 'desktop-windows');
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $finish = new \ReflectionMethod(ControlDOnboardingStaged::class, 'finish');
        $finish->invoke($writer, $intent, 'uncertain', 'post', self::ORG, null, 40003, null, 'Invalid parameter: max');
        $this->assertNull($intent->fresh()->reason_detail);
        $finish->invoke($writer, $intent, 'rejected', 'post', null, null, 40003, 'vendor rejected the write', 'Invalid parameter: max');
        $this->assertSame('Invalid parameter: max', $intent->fresh()->reason_detail);
    }

    // ── transport: sanitize, cap, redact (scoped POST, organization PUT, parent POST) ──

    private function rejectedBy(\Closure $call): ControlDWriteRejectedException
    {
        try {
            $call();
        } catch (ControlDWriteRejectedException $e) {
            $this->assertNull($e->getPrevious());

            return $e;
        }
        $this->fail('Expected ControlDWriteRejectedException.');
    }

    /** @return array<string, \Closure(ControlDClient): mixed> */
    private function writes(array $body): array
    {
        return [
            'scoped-post' => fn (ControlDClient $t) => $t->requestForOrg('POST', 'provision', self::ORG, $body),
            'organization-put' => fn (ControlDClient $t) => $t->requestForOrg('PUT', 'organizations', self::ORG, $body),
            'parent-post' => fn (ControlDClient $t) => $t->requestParent('POST', 'organizations/suborg', $body),
        ];
    }

    private function messageVia(string $route, mixed $message, array $body = ['parent_profile' => 'testprofile01']): ?string
    {
        $transport = $this->transport([$this->envelope(40003, $message)]);
        $e = $this->rejectedBy(fn () => $this->writes($body)[$route]($transport));
        $this->assertSame(40003, $e->reasonCode);
        // The exception's own message stays PSA-written; the vendor text rides only in vendorMessage.
        $this->assertStringStartsWith('Control D ', $e->getMessage());
        $this->assertStringNotContainsString('Invalid', $e->getMessage());

        return $e->vendorMessage;
    }

    public static function routes(): array
    {
        return ['scoped-post' => ['scoped-post'], 'organization-put' => ['organization-put'], 'parent-post' => ['parent-post']];
    }

    #[DataProvider('routes')]
    public function test_each_rejecting_route_carries_the_message_with_controls_stripped_and_whitespace_collapsed(string $route): void
    {
        $this->assertSame('Invalid parameter: max', $this->messageVia($route, 'Invalid parameter: max'));
        $this->assertSame('Invalid param max (limit) end', $this->messageVia($route, "  Invalid\x00 param\r\n\tmax\u{202E} (limit)\x1b end \x7f"));
    }

    #[DataProvider('routes')]
    public function test_an_over_long_message_is_capped_mb_safe(string $route): void
    {
        $long = $this->messageVia($route, str_repeat('é', 300));
        $this->assertSame(ControlDClient::VENDOR_MESSAGE_MAX, mb_strlen($long));
        $this->assertSame(str_repeat('é', ControlDClient::VENDOR_MESSAGE_MAX - 1).'…', $long);
        $this->assertTrue(mb_check_encoding($long, 'UTF-8'));
        $exact = str_repeat('a ', 100);
        $this->assertSame(rtrim($exact), $this->messageVia($route, $exact), 'at or under the cap is kept whole');
    }

    public static function echoedSecrets(): array
    {
        $body = ['name' => 'Synthetic Organization', 'contact_email' => 'synthetic@example.invalid', 'deactivation_pin' => 4821, 'name_prefix' => 'synthpfx'];

        return [
            'api-key' => [$body, 'Bad credential synthetic-key supplied'],
            'api-key-split-by-control' => [$body, "Bad credential synthetic-\x00key supplied"],
            'contact-email' => [$body, 'Email synthetic@example.invalid is already used'],
            'contact-email-case' => [$body, 'Email SYNTHETIC@EXAMPLE.INVALID is already used'],
            'organization-name' => [$body, 'Name Synthetic Organization is taken'],
            'organization-name-split-by-whitespace' => [$body, "Name Synthetic\n  Organization is taken"],
            'pin' => [$body, 'deactivation_pin 4821 is invalid'],
            'name-prefix' => [$body, 'prefix synthpfx is invalid'],
            'code-shaped-run' => [$body, 'code 0123456789abcdef0123456789abcdef exists'],
        ];
    }

    #[DataProvider('echoedSecrets')]
    public function test_a_message_echoing_a_secret_or_request_value_is_dropped_on_every_route(array $body, string $message): void
    {
        foreach (array_keys($this->writes($body)) as $route) {
            $this->assertNull($this->messageVia($route, $message, $body), $route);
        }
        // Positive control: the same request with an innocuous message keeps it.
        foreach (array_keys($this->writes($body)) as $route) {
            $this->assertSame('Invalid parameter: max', $this->messageVia($route, 'Invalid parameter: max', $body), $route);
        }
        // A PIN is matched as a whole number, so a longer number that contains it is kept.
        $this->assertSame('limit 148210 exceeded', $this->messageVia('scoped-post', 'limit 148210 exceeded', $body));
    }

    public function test_a_message_echoing_the_pin_is_not_stored_on_the_intent_end_to_end(): void
    {
        $this->configure();
        $actor = User::factory()->admin()->create(['is_active' => true]);
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $transport = $this->transport([
            $this->ok(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]),
            $this->ok(['profiles' => [['PK' => 'testprofile01']]]),
            $this->envelope(40003, 'deactivation_pin 4821 is out of range'),
        ]);
        $writer = new ControlDOnboardingStaged($transport, new ControlDProvisioning($transport));
        $id = $writer->stageCode($actor, $client->id, 'desktop-windows', '4821');
        $writer->execute($actor, $id);
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame(['rejected', 40003, null], [$intent->state, $intent->reason_code, $intent->reason_detail]);
        $this->assertSame(4821, json_decode((string) $this->history[2]['request']->getBody(), true)['deactivation_pin'], 'the PIN was in the request');
    }

    /** Logs stay status-only: the vendor's message never reaches a log record on the rejected path. */
    public function test_the_vendor_message_is_never_logged(): void
    {
        $records = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($e) use (&$records): void {
            $records[] = $e->level.' '.$e->message.' '.json_encode($e->context);
        });
        $this->configure();
        $fixture = $this->fixture(['controld_org_id' => self::ORG]);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);
        $run = $this->stageRun($fixture);
        $this->bindVendor([...$this->codeUpToPost(), $this->envelope(40003, 'Zq vendor sentence')]);
        $this->approve($run);
        $this->assertSame('Zq vendor sentence', ControlDOnboardingIntent::sole()->reason_detail);
        foreach ($records as $record) {
            $this->assertStringNotContainsString('Zq vendor sentence', $record);
        }
        // Positive control: the listener sees a record written through the facade.
        Log::warning('control record');
        $this->assertStringContainsString('control record', end($records));
    }
}
