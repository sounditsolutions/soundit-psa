<?php

namespace Tests\Feature\ControlD;

use App\Enums\TechnicianRunState;
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
use App\Support\ControlDConfig;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P7A74iGD (a), item 5: the Admin "invalidate code" undo. Staged from the client page by one
 * Admin, approved in the cockpit by a second; on approval one PUT provision/{PK}/invalidate and
 * the read-back that must show status -1. Confirmed: the code and PIN columns are nulled in one
 * guarded transaction. Unconfirmed read-back or a vendor error: uncertain, code KEPT, never
 * retried. The code value never appears in the proposal, the audit, the result or the logs.
 * Guzzle MockHandler for every vendor exchange; Http::preventStrayRequests().
 */
class ControlDInvalidateCodeTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-key';

    private const ORG = 'testorg001';

    private const CODE_PK = 'fixture001';

    private const CODE = '0123456789abcdef0123456789abcdef';

    private const PIN = '4821';

    private array $history = [];

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

    private function bindVendor(array $responses): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([...$responses, ...array_fill(0, 4, new Response(503))]));
        $stack->push(Middleware::history($this->history));
        $transport = new ControlDClient(['api_key' => self::KEY, 'handler' => $stack]);
        $this->app->instance(ControlDOnboardingStaged::class, new ControlDOnboardingStaged($transport, new ControlDProvisioning($transport)));
    }

    private function row(int $status): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/provision.json')), true);
        $row['status'] = $status;

        return $row;
    }

    /** An onboarded client: mapped, code and PIN stored, and the bound code intent that recorded the code's PK. */
    private function fixture(bool $boundIntent = true, string $org = self::ORG): array
    {
        $client = Client::factory()->create(['name' => 'Synthetic Organization', 'email' => 'synthetic@example.test', 'controld_org_id' => $org]);
        $client->forceFill(['controld_provisioning_code' => self::CODE, 'controld_deactivation_pin' => self::PIN])->save();
        if ($boundIntent) {
            $intent = new ControlDOnboardingIntent;
            $intent->forceFill(['id' => (string) Str::uuid(), 'client_id' => $client->id, 'actor_id' => User::factory()->admin()->create()->id,
                'active_client_id' => null, 'operation' => 'code', 'state' => 'bound', 'phase' => 'local-persistence',
                'payload' => ['icon' => 'desktop-windows', 'pin' => self::PIN, 'name_prefix' => null], 'org_pk' => $org, 'vendor_pk' => self::CODE_PK])->saveOrFail();
        }
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Control D code', 'status' => \App\Enums\TicketStatus::New->value]);

        return ['client' => $client, 'ticket' => $ticket, 'stager' => User::factory()->admin()->create(['is_active' => true])];
    }

    private function stage(array $fixture): TechnicianRun
    {
        $this->bindVendor([]);
        $this->actingAs($fixture['stager'])->post(route('clients.controld.invalidate', $fixture['client']), ['ticket_id' => $fixture['ticket']->id, 'reason' => 'code leaked'])
            ->assertSessionHas('success');
        $this->assertSame([], $this->history, 'staging makes no vendor call');

        return TechnicianRun::where('client_id', $fixture['client']->id)->sole();
    }

    private function approve(TechnicianRun $run, ?User $approver = null): string
    {
        $this->actingAs($approver ?? User::factory()->admin()->create(['is_active' => true]))->post(route('cockpit.approve', $run));

        return (string) (session('error') ?? session('success'));
    }

    private function stored(Client $client): array
    {
        $fresh = Client::findOrFail($client->id);

        return [$fresh->controld_provisioning_code, $fresh->controld_deactivation_pin];
    }

    private function sent(): array
    {
        return array_map(fn ($h) => $h['request']->getMethod().' '.$h['request']->getUri()->getPath(), $this->history);
    }

    /** The code and PIN never appear in the proposal, the audit, the run, the session or the intent. */
    private function assertSecretsAbsent(TechnicianRun $run, string $message): void
    {
        foreach ([self::CODE, self::PIN] as $secret) {
            $this->assertStringNotContainsString($secret, $run->fresh()->proposed_content);
            $this->assertStringNotContainsString($secret, json_encode($run->fresh()->toArray()));
            $this->assertSame(0, TechnicianActionLog::where('summary', 'like', "%{$secret}%")->count());
            $this->assertStringNotContainsString($secret, $message);
            $this->assertStringNotContainsString($secret, json_encode(session()->all()));
            $this->assertStringNotContainsString($secret, Crypt::decryptString($run->fresh()->proposed_meta['encrypted_payload']));
        }
    }

    public function test_the_proposal_pins_the_code_record_and_says_what_invalidating_does(): void
    {
        $fixture = $this->fixture();
        $run = $this->stage($fixture);

        $payload = json_decode(Crypt::decryptString($run->proposed_meta['encrypted_payload']), true);
        $this->assertSame(['invalidate', self::ORG, self::CODE_PK, $fixture['stager']->id], [$payload['step'], $payload['org_pk'], $payload['code_pk'], $payload['staged_by_user_id']]);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        // G-14: the code stops working for new enrollments; already-enrolled devices are NOT removed.
        $this->assertStringContainsString('the code stops working for new enrollments', $run->proposed_content);
        $this->assertStringContainsString('Devices already enrolled with it are NOT removed', $run->proposed_content);
        $this->assertStringContainsString('only when the read-back shows it invalidated are the code and deactivation PIN removed from the client record', $run->proposed_content);
        $this->assertStringContainsString('the step ends uncertain, the code stays on the client record, and nothing is retried', $run->proposed_content);
        $this->assertSecretsAbsent($run, '');
    }

    public function test_confirmed_invalidation_nulls_the_code_and_pin(): void
    {
        $fixture = $this->fixture();
        $run = $this->stage($fixture);
        $this->bindVendor([$this->ok([]), $this->ok(['provisions' => [$this->row(-1)]])]);

        $message = $this->approve($run);

        $this->assertSame([null, null], $this->stored($fixture['client']));
        $this->assertSame(['PUT /provision/'.self::CODE_PK.'/invalidate', 'GET /provision'], $this->sent());
        $this->assertSame(self::ORG, $this->history[0]['request']->getHeaderLine('X-Force-Org-Id'));
        $intent = ControlDOnboardingIntent::where('operation', 'invalidate')->sole();
        $this->assertSame(['bound', self::ORG, self::CODE_PK, null], [$intent->state, $intent->org_pk, $intent->vendor_pk, $intent->active_client_id]);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertStringContainsString('invalidated and confirmed by read-back; the code and deactivation PIN were removed from the client record', $message);
        $this->assertStringContainsString('Devices already enrolled with it are NOT removed', $message);
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'executed')->where('summary', 'like', '%confirmed invalidated by read-back%')->count());
        $this->assertSecretsAbsent($run, $message);
    }

    public function test_an_unconfirmed_read_back_ends_uncertain_and_keeps_the_code(): void
    {
        $fixture = $this->fixture();
        $run = $this->stage($fixture);
        $this->bindVendor([$this->ok([]), $this->ok(['provisions' => [$this->row(1)]])]);

        $message = $this->approve($run);

        $this->assertSame([self::CODE, self::PIN], $this->stored($fixture['client']), 'the code is kept');
        $intent = ControlDOnboardingIntent::where('operation', 'invalidate')->sole();
        $this->assertSame(['uncertain', (int) $fixture['client']->id], [$intent->state, (int) $intent->active_client_id], 'uncertain keeps the lock');
        $this->assertSame(['PUT /provision/'.self::CODE_PK.'/invalidate', 'GET /provision'], $this->sent(), 'one PUT, never retried');
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state, 'terminal; never re-armed');
        $this->assertStringStartsWith('HARD FAULT', $message);
        $this->assertStringContainsString('the code and PIN were kept on the client record', $message);
        $this->assertSecretsAbsent($run, $message);
    }

    public function test_a_vendor_error_ends_uncertain_and_keeps_the_code(): void
    {
        foreach ([[new Response(503)], [new Response(400, [], json_encode(['success' => false, 'body' => [], 'error' => ['code' => 40000, 'message' => 'nope']]))],
            [$this->ok([]), new Response(503)], [$this->ok([]), $this->ok(['provisions' => []])]] as $i => $responses) {
            $fixture = $this->fixture(true, 'testorg1'.$i);
            $run = $this->stage($fixture);
            $this->bindVendor($responses);

            $message = $this->approve($run);

            $this->assertSame([self::CODE, self::PIN], $this->stored($fixture['client']), "case {$i}: the code is kept");
            $this->assertSame('uncertain', ControlDOnboardingIntent::where('operation', 'invalidate')->where('client_id', $fixture['client']->id)->sole()->state, "case {$i}");
            $this->assertSame(1, collect($this->sent())->filter(fn ($s) => str_starts_with($s, 'PUT '))->count(), "case {$i}: one PUT, never retried");
            $this->assertStringStartsWith('HARD FAULT', $message);
            $this->assertSecretsAbsent($run, $message);
        }
    }

    public function test_a_non_admin_cannot_stage_or_approve(): void
    {
        $fixture = $this->fixture();
        $tech = User::factory()->tech()->create(['is_active' => true]);
        $this->bindVendor([]);
        $this->actingAs($tech)->post(route('clients.controld.invalidate', $fixture['client']), ['ticket_id' => $fixture['ticket']->id, 'reason' => 'x'])->assertForbidden();
        $this->assertSame(0, TechnicianRun::count());

        $run = $this->stage($fixture);
        $this->approve($run, $tech);
        $this->assertSame([self::CODE, self::PIN], $this->stored($fixture['client']));
        $this->assertSame(0, ControlDOnboardingIntent::where('operation', 'invalidate')->count());
        $this->assertSame([], $this->history);
    }

    public function test_the_stager_cannot_approve_their_own_invalidation(): void
    {
        $fixture = $this->fixture();
        $run = $this->stage($fixture);
        $this->bindVendor([]);

        $message = $this->approve($run, $fixture['stager']);

        $this->assertStringContainsString('needs a second Admin', $message);
        $this->assertSame([self::CODE, self::PIN], $this->stored($fixture['client']));
        $this->assertSame([], $this->history);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    /** No stored PK: the code is never looked up at Control D by value; staging refuses. */
    public function test_a_code_without_a_recorded_pk_is_refused_at_staging(): void
    {
        $fixture = $this->fixture(false);
        $this->bindVendor([]);
        $this->actingAs($fixture['stager'])->post(route('clients.controld.invalidate', $fixture['client']), ['ticket_id' => $fixture['ticket']->id, 'reason' => 'x'])
            ->assertSessionHasErrors('controld_onboarding');
        $this->assertSame(0, TechnicianRun::count());
        $this->assertSame([], $this->history);
    }

    public function test_a_changed_code_record_since_staging_refuses_with_nothing_sent(): void
    {
        $fixture = $this->fixture();
        $run = $this->stage($fixture);
        ControlDOnboardingIntent::where('operation', 'code')->update(['vendor_pk' => 'fixture002']);
        $this->bindVendor([]);

        $message = $this->approve($run);

        $this->assertStringContainsString('stored code no longer matches the values on this card', $message);
        $this->assertSame([self::CODE, self::PIN], $this->stored($fixture['client']));
        $this->assertSame([], $this->history);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_the_client_page_offers_invalidate_only_for_a_code_with_a_recorded_pk(): void
    {
        $fixture = $this->fixture();
        $html = $this->actingAs($fixture['stager'])->get(route('clients.show', $fixture['client']))->assertOk()->getContent();
        $this->assertStringContainsString(route('clients.controld.invalidate', $fixture['client']), $html);
        $this->assertStringContainsString('Devices already enrolled with it are NOT removed', $html);
        $this->assertStringNotContainsString(self::CODE, $html);

        $other = $this->fixture(false, 'testorg002');
        $html = $this->actingAs($fixture['stager'])->get(route('clients.show', $other['client']))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('clients.controld.invalidate', $other['client']), $html);
    }
}
