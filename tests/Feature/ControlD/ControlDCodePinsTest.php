<?php

namespace Tests\Feature\ControlD;

use App\Enums\TechnicianRunState;
use App\Models\Asset;
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
use App\Services\Mcp\StaffControlDOnboardingToolExecutor;
use App\Support\ControlDConfig;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P7A74iGD (a), item E (Jeeves 2026-10-09 21:45 PT): the code step pins its derived values
 * (max, expiry days, analytics level, intercept mode, enforced profile) on the sealed staged
 * payload; approval re-derives them and refuses (re-stage, nothing written, nothing changed
 * at Control D: only the step-ordering GET) on any drift; a card staged without pins refuses the same way; the values that execute are
 * the pinned ones. Guzzle MockHandler for every vendor exchange; Http::preventStrayRequests().
 */
class ControlDCodePinsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-key';

    private const ORG = 'testorg001';

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
        $this->travelTo(now()->startOfSecond());
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

    private function listed(): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/sub-organization.json')), true)['body']['sub_organizations'][0];
        $row['PK'] = self::ORG;
        $row['parent_profile']['PK'] = 'testprofile01';

        return $row;
    }

    /** @return array{client: Client, ticket: Ticket, stager: User} */
    private function fixture(int $assets = 1): array
    {
        $client = Client::factory()->create(['name' => 'Synthetic Organization', 'email' => 'synthetic@example.test', 'controld_org_id' => self::ORG]);
        Asset::factory()->count($assets)->create(['client_id' => $client->id]);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Onboard to Control D', 'status' => \App\Enums\TicketStatus::New->value]);

        return ['client' => $client, 'ticket' => $ticket, 'stager' => User::factory()->admin()->create(['is_active' => true])];
    }

    private function stage(array $fixture): TechnicianRun
    {
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);
        $this->actingAs($fixture['stager'])->post(route('clients.controld.onboard', $fixture['client']), ['ticket_id' => $fixture['ticket']->id, 'reason' => 'new client'])
            ->assertSessionHas('success');
        $run = TechnicianRun::where('client_id', $fixture['client']->id)->sole();
        $this->assertSame(['plan', ['code']], [$run->proposed_meta['redacted_params']['step'], $run->proposed_meta['redacted_params']['steps']]);

        return $run;
    }

    private function payload(TechnicianRun $run): array
    {
        return json_decode(Crypt::decryptString($run->fresh()->proposed_meta['encrypted_payload']), true);
    }

    /** The full code exchange for a code cut with $max / $days / $stats / $mode / $profile. */
    private function codeExchange(int $max, int $days, int $stats, string $mode, string $profile): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/provision.json')), true);
        $row = array_merge($row, ['max' => $max, 'ts_exp' => now()->getTimestamp() + $days * 86400, 'stats' => $stats, 'intercept_mode' => $mode, 'profile_id' => $profile]);
        $responses = [
            $this->ok(['sub_organizations' => [$this->listed()]]),
            $this->ok(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]),
            $this->ok(['profiles' => [['PK' => $profile]]]),
        ];
        if ($stats !== 0) {
            $responses[] = $this->ok(['organization' => ['PK' => self::ORG, 'stats_endpoint' => 'synthetic-region']]);
        }

        return [...$responses, $this->ok(['provision' => $row]), $this->ok(['provisions' => [$row]])];
    }

    private function approve(TechnicianRun $run): string
    {
        $this->actingAs(User::factory()->admin()->create(['is_active' => true]))->post(route('cockpit.approve', $run));

        return (string) (session('error') ?? session('success'));
    }

    /** No intent, no stored code, no vendor write or read beyond the step-ordering GET. */
    private function assertRefusedWithNothingWritten(TechnicianRun $run, array $fixture, string $message): void
    {
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertSame(0, ControlDOnboardingIntent::count(), 'nothing staged at B3');
        $this->assertNull($fixture['client']->fresh()->getRawOriginal('controld_provisioning_code'));
        foreach ($this->history as $exchange) {
            $this->assertSame('GET', $exchange['request']->getMethod(), 'no vendor write');
            $this->assertSame('/organizations/sub_organizations', $exchange['request']->getUri()->getPath(), 'no code-step vendor call');
        }
        $this->assertStringContainsString('stage again', $message);
        $this->assertStringContainsString('Nothing was created', $message);
        $this->assertStringNotContainsString('no Control D call', $message, 'G-14: approval already made the step-ordering GET');
    }

    public function test_staging_pins_the_five_derived_values_and_the_card_shows_them(): void
    {
        $fixture = $this->fixture(3);
        $run = $this->stage($fixture);

        $this->assertSame(['max' => 5, 'expiry_days' => 7, 'stats' => 0, 'intercept_mode' => 'standard', 'profile_id' => 'testprofile01'], $this->payload($run)['code_pins']);
        // P7A74iGD (b): the plan card shows the pinned values and says that approval re-derives the plan and refuses on any difference.
        $this->assertStringContainsString('with these pinned values: enforced profile testprofile01, expiry 7 days, device limit 5 (asset count 3 + headroom 2), analytics level 0, intercept mode standard', $run->proposed_content);
        $this->assertStringContainsString('Approval derives the plan again from the client\'s current state and refuses, with nothing written, if it differs from this card (a step already done, a changed setting or pinned value, or a changed device link); stage again then.', $run->proposed_content);
        $this->assertStringNotContainsString('leaves all five unchanged does not refuse', $run->proposed_content, 'G-14 (contract:1): underivable values and other checks still refuse');
        $this->assertStringNotContainsString('if a setting or the client\'s asset count changes before approval, approval refuses', $run->proposed_content);
        $this->assertArrayNotHasKey('code_pins', $run->proposed_meta, 'pins live only in the sealed payload');
    }

    public function test_equal_pins_execute_with_exactly_the_pinned_values(): void
    {
        Setting::setValue('controld_code_analytics_level', '1');
        Setting::setValue('controld_code_intercept_mode', 'intercept-dns');
        $fixture = $this->fixture(3);
        $run = $this->stage($fixture);
        $this->bindVendor($this->codeExchange(5, 7, 1, 'intercept-dns', 'testprofile01'));

        $this->approve($run);

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame('bound', ControlDOnboardingIntent::sole()->state);
        $post = collect($this->history)->first(fn ($h) => $h['request']->getMethod() === 'POST');
        $sent = json_decode((string) $post['request']->getBody(), true);
        $this->assertSame(['icon' => 'desktop-windows', 'profile_id' => 'testprofile01', 'max' => 5, 'ts_exp' => now()->getTimestamp() + 7 * 86400, 'stats' => 1, 'intercept_mode' => 'intercept-dns'], $sent);
    }

    /** The pinned values, not live ones, reach stageCode() and the POST (mutant: execute with live values). */
    public function test_the_service_executes_the_pins_even_when_live_values_differ(): void
    {
        $fixture = $this->fixture(3);
        $pins = ['max' => 9, 'expiry_days' => 30, 'stats' => 0, 'intercept_mode' => 'intercept-dns', 'profile_id' => 'testprofile01'];
        $this->bindVendor(array_slice($this->codeExchange(9, 30, 0, 'intercept-dns', 'testprofile01'), 1));
        $service = app(ControlDOnboardingStaged::class);
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $id = $service->stageCode($admin, (int) $fixture['client']->id, 'desktop-windows', null, null, $pins);
        $service->execute($admin, $id);

        $this->assertSame('bound', ControlDOnboardingIntent::findOrFail($id)->state);
        $post = collect($this->history)->first(fn ($h) => $h['request']->getMethod() === 'POST');
        $sent = json_decode((string) $post['request']->getBody(), true);
        $this->assertSame([9, now()->getTimestamp() + 30 * 86400, 'intercept-dns'], [$sent['max'], $sent['ts_exp'], $sent['intercept_mode']]);
        $this->assertSame($sent, ControlDOnboardingIntent::findOrFail($id)->payload['fields'], 'the exact sent fields are kept for reconcile');
    }

    public static function drifts(): array
    {
        return [
            'max (headroom setting)' => [fn () => Setting::setValue('controld_code_device_limit_headroom', '3')],
            'max (asset count)' => [fn (Client $client) => Asset::factory()->create(['client_id' => $client->id])],
            'expiry days' => [fn () => Setting::setValue('controld_code_expiry_days', '8')],
            'analytics level' => [fn () => Setting::setValue('controld_code_analytics_level', '2')],
            'intercept mode' => [fn () => Setting::setValue('controld_code_intercept_mode', 'intercept-dns')],
            'profile id' => [fn () => Setting::setValue('controld_default_profile_id', 'testprofile02')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('drifts')]
    public function test_any_drifted_pin_refuses_with_a_restage_and_nothing_written(\Closure $drift): void
    {
        $fixture = $this->fixture(3);
        $run = $this->stage($fixture);
        $drift($fixture['client']);
        // A drifted profile changes the step ordering read too; keep the org on the live profile so only the pin moves.
        $listed = $this->listed();
        $listed['parent_profile']['PK'] = ControlDConfig::defaultProfileId();
        $this->bindVendor([$this->ok(['sub_organizations' => [$listed]]), ...array_fill(0, 6, $this->ok(['provisions' => []]))]);

        $message = $this->approve($run);

        $this->assertRefusedWithNothingWritten($run, $fixture, $message);
        $this->assertStringContainsString('no longer match the current Control D settings or the client\'s asset count', $message);
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'blocked')
            ->where('summary', 'like', '%the derived code values (device limit, expiry, analytics level, intercept mode or enforced profile) differ from the ones pinned on this card; nothing was changed at Control D or in Tactical.%')->count());
        $this->assertSame(0, TechnicianActionLog::where('summary', 'like', '%no Control D call%')->count(), 'G-14: the drift audit row does not claim no call');
    }

    /** G-14 (contract-s1:6): only the five pinned values are checked; a change that leaves them all equal does not refuse. */
    public function test_a_change_that_leaves_every_pin_equal_does_not_refuse(): void
    {
        $fixture = $this->fixture(3);
        $run = $this->stage($fixture);
        // asset count 3 -> 2 and headroom 2 -> 3: max stays 5, so no pin moves.
        Asset::where('client_id', $fixture['client']->id)->first()->delete();
        Setting::setValue('controld_code_device_limit_headroom', '3');
        $this->bindVendor($this->codeExchange(5, 7, 0, 'standard', 'testprofile01'));

        $this->approve($run);

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame('bound', ControlDOnboardingIntent::sole()->state);
    }

    public function test_a_legacy_card_without_pins_refuses_with_the_restage_wording(): void
    {
        $fixture = $this->fixture(3);
        $run = $this->stage($fixture);
        $payload = $this->payload($run);
        unset($payload['code_pins']);
        $meta = $run->proposed_meta;
        $meta['encrypted_payload'] = Crypt::encryptString(json_encode($payload));
        $run->forceFill(['proposed_meta' => $meta])->save();
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);

        $message = $this->approve($run);

        $this->assertRefusedWithNothingWritten($run, $fixture, $message);
        // P7A74iGD (b): a plan card whose pins are missing differs from the fresh derivation, so it refuses as drift.
        $this->assertStringContainsString('The code values pinned on this card', $message);
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'blocked')
            ->where('summary', 'like', '%differ from the ones pinned on this card; nothing was changed at Control D or in Tactical.%')->count());
    }

    public function test_malformed_pins_are_treated_as_legacy_and_refused(): void
    {
        $fixture = $this->fixture(3);
        $run = $this->stage($fixture);
        $payload = $this->payload($run);
        $payload['code_pins']['max'] = '5';
        $meta = $run->proposed_meta;
        $meta['encrypted_payload'] = Crypt::encryptString(json_encode($payload));
        $run->forceFill(['proposed_meta' => $meta])->save();
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);

        $this->assertStringContainsString('The code values pinned on this card', $this->approve($run));
        $this->assertSame(0, ControlDOnboardingIntent::count());
    }

    public static function underivable(): array
    {
        return [
            'analytics level not 0, 1 or 2' => [fn () => Setting::setValue('controld_code_analytics_level', '7')],
            'asset count plus headroom below 1' => [function (Client $client): void {
                Setting::setValue('controld_code_device_limit_headroom', '0');
                Asset::where('client_id', $client->id)->delete();
            }],
        ];
    }

    /** G-14 (contract:1): when codePins() cannot derive the values, approval refuses with its own text, not the drift text. */
    #[\PHPUnit\Framework\Attributes\DataProvider('underivable')]
    public function test_values_that_can_no_longer_be_derived_refuse_with_their_own_text(\Closure $break): void
    {
        $fixture = $this->fixture(3);
        $run = $this->stage($fixture);
        $break($fixture['client']);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->listed()]])]);

        $message = $this->approve($run);

        $this->assertRefusedWithNothingWritten($run, $fixture, $message);
        $this->assertSame(StaffControlDOnboardingToolExecutor::CODE_PINS_UNDERIVABLE, $message);
        $this->assertStringNotContainsString('no longer match', $message);
        $this->assertSame(1, TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'blocked')
            ->where('summary', 'like', '%approval refused%nothing was changed at Control D or in Tactical.%')->count());
        $this->assertSame(0, TechnicianActionLog::where('summary', 'like', '%differ from the ones pinned%')->count());
    }
}
