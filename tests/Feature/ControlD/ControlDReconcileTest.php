<?php

namespace Tests\Feature\ControlD;

use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P7A74iGD (a): the Admin's GET-only reconcile of an uncertain Control D onboarding
 * intent (ControlDOnboardingStaged::reconcile(), client-page route). Per operation: match ->
 * bound with bind()'s end state; strict absence -> released; anything else -> refused with
 * nothing changed. Every arm: no PUT, POST or DELETE is sent, one audit row (ids and status),
 * and the lock is asserted. The user-facing text of each outcome is asserted on its emitting
 * path (G-14): each must be true for every path that emits it. Every vendor exchange is a Guzzle MockHandler;
 * Http::preventStrayRequests() guards the rest. Synthetic data only (G-13).
 */
class ControlDReconcileTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-key';

    private const ORG = 'testorg001';

    private const CODE_PK = 'fixture001';

    private const NAME = 'Synthetic Organization';

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
        Setting::setValue('controld_enabled', '1');
        Setting::setEncrypted('controld_api_key', self::KEY);
        Setting::setValue('controld_stats_endpoint', 'synthetic-region');
        foreach (['tactical_client_field_id' => '18', 'default_profile_id' => 'testprofile01', 'code_expiry_days' => '7',
            'code_device_limit_headroom' => '2', 'code_analytics_level' => '0', 'code_intercept_mode' => 'standard'] as $key => $value) {
            Setting::setValue('controld_'.$key, $value);
        }
        Setting::setValue(ControlDConfig::ONBOARDING_ENABLED_SETTING, '1');
    }

    private function ok(array $body): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'body' => $body]));
    }

    /** @param  array<int, Response|\Closure>  $responses */
    private function bindVendor(array $responses): void
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([...$responses, ...array_fill(0, 4, new Response(503))]));
        $stack->push(Middleware::history($this->history));
        $transport = new ControlDClient(['api_key' => self::KEY, 'handler' => $stack]);
        $this->app->instance(ControlDOnboardingStaged::class, new ControlDOnboardingStaged($transport, new ControlDProvisioning($transport)));
    }

    /** @return array<int, string> method + path of every request sent */
    private function sent(): array
    {
        return array_map(fn ($h) => $h['request']->getMethod().' '.$h['request']->getUri()->getPath(), $this->history);
    }

    /** No PUT, POST or DELETE was sent: reconcile makes no vendor write on any arm. */
    private function assertNoVendorWrite(): void
    {
        foreach ($this->history as $exchange) {
            $this->assertSame('GET', $exchange['request']->getMethod(), 'reconcile sent a vendor write');
        }
    }

    private function subOrg(string $pk = self::ORG, string $name = self::NAME, ?string $global = 'testprofile01'): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/sub-organization.json')), true)['body']['sub_organizations'][0];
        $row['PK'] = $pk;
        $row['name'] = $name;
        if ($global === null) {
            unset($row['parent_profile']);
        } else {
            $row['parent_profile']['PK'] = $global;
        }

        return $row;
    }

    private function tsExp(): int
    {
        return 1900000000;
    }

    /** The exact wire fields a code intent keeps on its payload (code() since this change). */
    private function fields(): array
    {
        return ['icon' => 'desktop-windows', 'profile_id' => 'testprofile01', 'max' => 12, 'ts_exp' => $this->tsExp(), 'stats' => 0, 'intercept_mode' => 'standard'];
    }

    private function provision(array $overrides = []): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/provision.json')), true);
        $row['ts_exp'] = $this->tsExp();

        return array_merge($row, $overrides);
    }

    private function intent(Client $client, string $operation, string $state, array $payload, ?string $orgPk = null, ?string $vendorPk = null, bool $locked = true): ControlDOnboardingIntent
    {
        $intent = new ControlDOnboardingIntent;
        $intent->forceFill(['id' => (string) Str::uuid(), 'client_id' => $client->id, 'actor_id' => User::factory()->admin()->create()->id,
            'active_client_id' => $locked ? $client->id : null, 'operation' => $operation, 'state' => $state,
            'phase' => $state === 'posted' ? 'post' : 'readback', 'payload' => $payload, 'org_pk' => $orgPk, 'vendor_pk' => $vendorPk,
            'reason' => $state === 'uncertain' ? 'outcome requires reconciliation; do not retry' : null])->saveOrFail();

        return $intent;
    }

    private function orgPayload(): array
    {
        return ['name' => self::NAME, 'contact_email' => 'synthetic@example.test', 'twofa_req' => 1, 'stats_endpoint' => 'synthetic-region'];
    }

    private function codePayload(bool $withFields = true): array
    {
        return ['icon' => 'desktop-windows', 'pin' => null, 'name_prefix' => null] + ($withFields ? ['fields' => $this->fields()] : []);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['is_active' => true]);
    }

    private function reconcile(Client $client, ControlDOnboardingIntent $intent, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin())->post(route('clients.controld.intent.reconcile', [$client, $intent->id]));
    }

    /** @return array<int, mixed> the intent columns bind() writes */
    private function state(ControlDOnboardingIntent $intent): array
    {
        $fresh = $intent->fresh();

        return [$fresh->state, $fresh->phase, $fresh->org_pk, $fresh->vendor_pk, $fresh->active_client_id, $fresh->reason, $fresh->reason_code];
    }

    private function audit(ControlDOnboardingIntent $intent): TechnicianActionLog
    {
        return TechnicianActionLog::where('action_type', ControlDOnboardingStaged::RECONCILE_ACTION)->sole();
    }

    private function assertAudited(ControlDOnboardingIntent $intent, string $outcome, string $status): void
    {
        $row = $this->audit($intent);
        $this->assertSame($status, $row->result_status);
        $this->assertStringContainsString("controld-intent:{$intent->id}: reconcile {$intent->operation} for client #{$intent->client_id}", $row->summary);
        $this->assertStringContainsString(": {$outcome} (", $row->summary);
        $this->assertStringContainsString('no Control D write', $row->summary);
    }

    /** A refusal leaves the intent, the lock and the client exactly as they were. */
    private function assertRefusedUnchanged($response, ControlDOnboardingIntent $intent, Client $client, array $intentBefore, array $clientBefore): void
    {
        $response->assertSessionHasErrors('controld_onboarding');
        $this->assertSame($intentBefore, $this->state($intent));
        $this->assertSame((int) $client->id, (int) $intent->fresh()->active_client_id, 'the lock is kept');
        $this->assertSame($clientBefore, $this->clientState($client));
        $this->assertAudited($intent, 'refused', 'blocked');
        $this->assertNoVendorWrite();
    }

    /** G-14: the refusal text shown for this response contains $text. */
    private function assertRefusalSays(string $text): void
    {
        $this->assertStringContainsString($text, session('errors')->first('controld_onboarding'));
    }

    /** G-14: the success text shown for this response contains $text and no claim of a full payload match. */
    private function assertSuccessSays(string $text): void
    {
        $this->assertStringContainsString($text, (string) session('success'));
        $this->assertStringNotContainsString('exactly what this intent sent', (string) session('success'));
    }

    private const MISMATCH = 'Control D shows a record that differs on what reconcile compares for this step';

    private const NOT_SAVED = 'The outcome could not be saved locally, so it was rolled back and nothing was changed by this reconcile.';

    private const BY_HAND = 'An uncertain invalidate intent, or a posted intent (its write may still be in flight, or the process stopped after it), is held for a by-hand ruling: there is no in-app way out for it yet.';

    /** Between loop cases: no audit rows, intents or mapped clients left over. */
    private function reset(): void
    {
        TechnicianActionLog::query()->delete();
        ControlDOnboardingIntent::query()->delete();
        Client::query()->forceDelete();
    }

    private function clientState(Client $client): array
    {
        $fresh = Client::findOrFail($client->id);

        return [$fresh->controld_org_id, $fresh->getRawOriginal('controld_provisioning_code'), $fresh->getRawOriginal('controld_deactivation_pin')];
    }

    // ── organization ──────────────────────────────────────────────────────────────

    public function test_organization_match_binds_with_binds_end_state(): void
    {
        $client = Client::factory()->create();
        $intent = $this->intent($client, 'organization', 'uncertain', $this->orgPayload());
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->subOrg('otherorg01', 'Another Org'), $this->subOrg()]])]);

        $this->reconcile($client, $intent)->assertSessionHas('success');
        $this->assertSuccessSays('Control D lists exactly one sub-organization with this intent\'s name (and its recorded PK, when one was recorded), carrying the currently configured global profile, so it was bound. The contact email, MFA and stats settings this intent sent were not compared.');

        // bind() for organization: client.controld_org_id = PK; intent bound, org_pk = vendor_pk = PK, lock and reasons cleared.
        $this->assertSame(['bound', 'local-persistence', self::ORG, self::ORG, null, null, null], $this->state($intent));
        $this->assertSame([self::ORG, null, null], $this->clientState($client));
        $this->assertAudited($intent, 'bound', 'executed');
        $this->assertSame(['GET /organizations/sub_organizations'], $this->sent());
        $this->assertNoVendorWrite();
    }

    public function test_organization_match_with_a_recorded_pk_binds(): void
    {
        $client = Client::factory()->create();
        $intent = $this->intent($client, 'organization', 'uncertain', $this->orgPayload(), self::ORG, self::ORG);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->subOrg()]])]);

        $this->reconcile($client, $intent)->assertSessionHas('success');

        $this->assertSame(['bound', 'local-persistence', self::ORG, self::ORG, null, null, null], $this->state($intent));
        $this->assertSame([self::ORG, null, null], $this->clientState($client));
        $this->assertNoVendorWrite();
    }

    public function test_organization_absent_releases(): void
    {
        $client = Client::factory()->create();
        $intent = $this->intent($client, 'organization', 'uncertain', $this->orgPayload());
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->subOrg('otherorg01', 'Another Org')]])]);

        $this->reconcile($client, $intent)->assertSessionHas('success');
        $this->assertSuccessSays('Control D\'s list (one read, treated as complete) shows nothing from this intent, so it was released');

        $fresh = $intent->fresh();
        $this->assertSame([ControlDOnboardingStaged::RELEASED, null], [$fresh->state, $fresh->active_client_id]);
        $this->assertSame([null, null, null], $this->clientState($client));
        $this->assertAudited($intent, 'released', 'executed');
        $this->assertNoVendorWrite();
    }

    public function test_organization_ambiguous_name_is_refused_unchanged(): void
    {
        $client = Client::factory()->create();
        $intent = $this->intent($client, 'organization', 'uncertain', $this->orgPayload());
        $before = $this->state($intent);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->subOrg(), $this->subOrg('testorg002')]])]);

        $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [null, null, null]);
    }

    public function test_organization_malformed_list_is_refused_unchanged(): void
    {
        $client = Client::factory()->create();
        $intent = $this->intent($client, 'organization', 'uncertain', $this->orgPayload());
        $intent->refresh();
        $before = $this->state($intent);
        $bad = $this->subOrg();
        unset($bad['name']);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->subOrg('otherorg01', 'Another Org'), $bad]])]);

        $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [null, null, null]);
    }

    public function test_organization_listed_under_another_name_than_the_payload_is_refused(): void
    {
        $client = Client::factory()->create();
        $intent = $this->intent($client, 'organization', 'uncertain', $this->orgPayload(), self::ORG, self::ORG);
        $before = $this->state($intent);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->subOrg(self::ORG, 'Renamed Org')]])]);

        $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [null, null, null]);
    }

    /** G-14 (diff:5): the org is compared against the CURRENT configured global profile, and the text says so rather than "what this intent sent". */
    public function test_organization_carrying_another_global_profile_is_refused_with_the_compared_fields_named(): void
    {
        $client = Client::factory()->create();
        $intent = $this->intent($client, 'organization', 'uncertain', $this->orgPayload());
        $before = $this->state($intent);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->subOrg(self::ORG, self::NAME, 'otherprofile9')]])]);

        $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [null, null, null]);
        $this->assertRefusalSays(self::MISMATCH.' (organization: the name, the recorded PK and the currently configured global profile;');
        $this->assertStringNotContainsString('does not match what this intent sent', session('errors')->first('controld_onboarding'));
    }

    // ── global profile ───────────────────────────────────────────────────────────

    private function profilePayload(): array
    {
        return ['org_pk' => self::ORG, 'profile_pk' => 'testprofile01'];
    }

    public function test_global_profile_match_binds_with_binds_end_state(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $intent = $this->intent($client, 'global-profile', 'uncertain', $this->profilePayload(), self::ORG, self::ORG);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->subOrg()]])]);

        $this->reconcile($client, $intent)->assertSessionHas('success');

        $this->assertSuccessSays('Control D shows the organization\'s global profile equal to the profile this intent sent, so it was bound.');
        // bind() for global-profile: nothing local changes; intent bound, org_pk = vendor_pk = org PK.
        $this->assertSame(['bound', 'local-persistence', self::ORG, self::ORG, null, null, null], $this->state($intent));
        $this->assertSame([self::ORG, null, null], $this->clientState($client));
        $this->assertAudited($intent, 'bound', 'executed');
        $this->assertNoVendorWrite();
    }

    public function test_global_profile_absent_releases(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $intent = $this->intent($client, 'global-profile', 'uncertain', $this->profilePayload(), self::ORG);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->subOrg(self::ORG, self::NAME, null)]])]);

        $this->reconcile($client, $intent)->assertSessionHas('success');

        $this->assertSame([ControlDOnboardingStaged::RELEASED, null], [$intent->fresh()->state, $intent->fresh()->active_client_id]);
        $this->assertSame([self::ORG, null, null], $this->clientState($client));
        $this->assertAudited($intent, 'released', 'executed');
        $this->assertNoVendorWrite();
    }

    public function test_global_profile_different_profile_is_refused_unchanged(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $intent = $this->intent($client, 'global-profile', 'uncertain', $this->profilePayload(), self::ORG, self::ORG);
        $before = $this->state($intent);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->subOrg(self::ORG, self::NAME, 'otherprofile9')]])]);

        $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [self::ORG, null, null]);
    }

    public function test_global_profile_malformed_field_is_refused_unchanged(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $intent = $this->intent($client, 'global-profile', 'uncertain', $this->profilePayload(), self::ORG, self::ORG);
        $before = $this->state($intent);
        $row = $this->subOrg();
        $row['parent_profile'] = '';
        $this->bindVendor([$this->ok(['sub_organizations' => [$row]])]);

        $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [self::ORG, null, null]);
    }

    // ── code ─────────────────────────────────────────────────────────────────────

    private function codeIntent(Client $client, ?string $vendorPk = self::CODE_PK, bool $withFields = true, string $state = 'uncertain'): ControlDOnboardingIntent
    {
        return $this->intent($client, 'code', $state, $this->codePayload($withFields), self::ORG, $vendorPk);
    }

    public function test_code_match_binds_with_binds_end_state(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $intent = $this->codeIntent($client);
        $other = $this->provision(['PK' => 'fixture002', 'code' => str_repeat('b', 32)]);
        $this->bindVendor([$this->ok(['provisions' => [$other, $this->provision()]])]);

        $this->reconcile($client, $intent)->assertSessionHas('success');

        $this->assertSuccessSays('Control D lists this intent\'s code record once, active, with the enforced profile, device limit, expiry, analytics level, intercept mode, icon, prefix and PIN this intent sent, so it was bound.');
        // bind() for code: code and PIN columns = the read-back code and the sent PIN (null);
        // intent bound, org_pk = org, vendor_pk = the code's PK.
        $this->assertSame(['bound', 'local-persistence', self::ORG, self::CODE_PK, null, null, null], $this->state($intent));
        $fresh = Client::findOrFail($client->id);
        $this->assertSame(['0123456789abcdef0123456789abcdef', null], [$fresh->controld_provisioning_code, $fresh->controld_deactivation_pin]);
        $this->assertAudited($intent, 'bound', 'executed');
        $this->assertStringNotContainsString('0123456789abcdef', $this->audit($intent)->summary);
        $this->assertSame(['GET /provision'], $this->sent());
        $this->assertSame(self::ORG, $this->history[0]['request']->getHeaderLine('X-Force-Org-Id'));
        $this->assertNoVendorWrite();
    }

    public function test_code_absent_with_a_recorded_pk_on_a_complete_list_releases(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $intent = $this->codeIntent($client);
        $this->bindVendor([$this->ok(['provisions' => [$this->provision(['PK' => 'fixture002'])]])]);

        $this->reconcile($client, $intent)->assertSessionHas('success');

        $this->assertSame([ControlDOnboardingStaged::RELEASED, null], [$intent->fresh()->state, $intent->fresh()->active_client_id]);
        $this->assertSame([self::ORG, null, null], $this->clientState($client));
        $this->assertAudited($intent, 'released', 'executed');
        $this->assertNoVendorWrite();
    }

    public function test_code_without_a_vendor_pk_and_an_empty_complete_list_releases(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $intent = $this->codeIntent($client, null, true, 'uncertain');
        $this->bindVendor([$this->ok(['provisions' => []])]);

        $this->reconcile($client, $intent)->assertSessionHas('success');

        $this->assertSame([ControlDOnboardingStaged::RELEASED, null], [$intent->fresh()->state, $intent->fresh()->active_client_id]);
        $this->assertSame([self::ORG, null, null], $this->clientState($client));
        $this->assertAudited($intent, 'released', 'executed');
        $this->assertNoVendorWrite();
    }

    public function test_code_without_a_vendor_pk_and_any_row_present_is_refused(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $intent = $this->codeIntent($client, null);
        $before = $this->state($intent);
        $this->bindVendor([$this->ok(['provisions' => [$this->provision(['PK' => 'fixture002'])]])]);

        $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [self::ORG, null, null]);
    }

    /** Jeeves addition 2: a failed list is never strict absence; a lost code is worse than a held lock. */
    public function test_code_list_error_is_refused_unchanged(): void
    {
        foreach ([[new Response(503)], [new Response(200, [], 'not json')], [$this->ok(['provisions' => 'nope'])],
            [$this->ok(['other' => []])], [new Response(200, [], json_encode(['success' => false, 'body' => ['provisions' => []]]))]] as $i => $responses) {
            foreach ([null, self::CODE_PK] as $pk) {
                $this->reset();
                $client = Client::factory()->create(['controld_org_id' => self::ORG]);
                $intent = $this->codeIntent($client, $pk);
                $before = $this->state($intent);
                $this->bindVendor($responses);

                $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [self::ORG, null, null]);
                $this->assertSame(['GET /provision'], $this->sent(), "case {$i}");
                $this->assertRefusalSays('Control D could not be read (a failed, unconfirmed or malformed response), or this intent\'s stored payload could not be read;');
                $this->assertStringNotContainsString('completely', session('errors')->first('controld_onboarding'));
            }
        }
    }

    public function test_code_list_with_a_malformed_or_foreign_row_is_refused_unchanged(): void
    {
        $noPk = $this->provision();
        unset($noPk['PK']);
        foreach ([[$this->provision(['PK' => 'fixture002']), $noPk], [$this->provision(['PK' => 'fixture002', 'org' => 'elsewhere01'])], ['not-a-row']] as $rows) {
            $this->reset();
            $client = Client::factory()->create(['controld_org_id' => self::ORG]);
            $intent = $this->codeIntent($client);
            $before = $this->state($intent);
            $this->bindVendor([$this->ok(['provisions' => $rows])]);

            $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [self::ORG, null, null]);
            $this->assertRefusalSays('Control D could not be read (a failed, unconfirmed or malformed response)');
        }
    }

    public function test_code_row_differing_from_the_sent_fields_is_refused_unchanged(): void
    {
        foreach ([['max' => 13], ['ts_exp' => 1900000001], ['profile_id' => 'otherprofile9'], ['stats' => 1], ['intercept_mode' => 'intercept-dns'],
            ['deactivation_pin' => 1234], ['name_prefix' => 'pre']] as $drift) {
            $this->reset();
            $client = Client::factory()->create(['controld_org_id' => self::ORG]);
            $intent = $this->codeIntent($client);
            $before = $this->state($intent);
            $this->bindVendor([$this->ok(['provisions' => [$this->provision($drift)]])]);

            $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [self::ORG, null, null]);
            $this->assertRefusalSays(self::MISMATCH);
            $this->assertRefusalSays('code: the fields this intent sent');
        }
    }

    /** G-14 (context:4): a listed code that is expired or used is said to be so, held for a by-hand ruling, not a fault of the intent. */
    public function test_code_row_expired_or_used_at_control_d_is_refused_as_such_and_held(): void
    {
        foreach ([['status' => -1], ['expired' => 1], ['status' => 2, 'max' => 13]] as $i => $drift) {
            $this->reset();
            $client = Client::factory()->create(['controld_org_id' => self::ORG]);
            $intent = $this->codeIntent($client);
            $before = $this->state($intent);
            $this->bindVendor([$this->ok(['provisions' => [$this->provision($drift)]])]);

            $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [self::ORG, null, null]);
            $this->assertRefusalSays('Control D lists this intent\'s code record, but it is expired or used (not an active code) at Control D; nothing was changed, the intent keeps its lock and is held for a by-hand ruling.');
            $this->assertStringNotContainsString(self::MISMATCH, session('errors')->first('controld_onboarding'), "case {$i}");
        }
    }

    /** G-14 (contract-s1:12): a missing LOCAL global-profile setting is not blamed on Control D, and nothing is read. */
    public function test_organization_with_the_global_profile_setting_malformed_is_refused_without_a_read(): void
    {
        // Unset makes the route inert (404, isOnboardingConfigured()); a malformed value reaches this arm.
        Setting::setValue('controld_default_profile_id', 'not a profile!');
        $client = Client::factory()->create();
        $intent = $this->intent($client, 'organization', 'uncertain', $this->orgPayload());
        $before = $this->state($intent);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->subOrg()]])]);

        $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [null, null, null]);
        $this->assertRefusalSays('The configured global profile setting is missing or malformed, so the organization could not be compared; Control D was not read');
        $this->assertStringNotContainsString('Control D could not be read', session('errors')->first('controld_onboarding'));
        $this->assertSame([], $this->history);
    }

    public function test_code_row_listed_twice_is_refused_unchanged(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $intent = $this->codeIntent($client);
        $before = $this->state($intent);
        $this->bindVendor([$this->ok(['provisions' => [$this->provision(), $this->provision()]])]);

        $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [self::ORG, null, null]);
    }

    /** An intent staged before the sent fields were kept cannot be rebuilt exactly: refused, held. */
    public function test_code_intent_without_its_sent_fields_cannot_be_reconstructed_and_is_refused(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $intent = $this->codeIntent($client, self::CODE_PK, false);
        $before = $this->state($intent);
        $this->bindVendor([$this->ok(['provisions' => [$this->provision()]])]);

        $response = $this->reconcile($client, $intent);
        $this->assertRefusedUnchanged($response, $intent, $client, $before, [self::ORG, null, null]);
        $this->assertStringContainsString('cannot reconstruct; leave held', session('errors')->first('controld_onboarding'));
    }

    /**
     * G-14 (diff:8): every record written through the application logger on the code reconcile
     * arms (bound, released, mismatch, expired, list error, and a refusal whose audit row fails)
     * is captured and must not carry the code value. A positive control proves capture.
     */
    public function test_no_log_record_carries_the_code_on_any_code_reconcile_arm(): void
    {
        $code = '0123456789abcdef0123456789abcdef';
        $records = [];
        \Illuminate\Support\Facades\Log::listen(function (\Illuminate\Log\Events\MessageLogged $e) use (&$records): void {
            $records[] = $e->level.' '.$e->message.' '.json_encode($e->context);
        });
        foreach ([[$this->ok(['provisions' => [$this->provision()]])], [$this->ok(['provisions' => [$this->provision(['PK' => 'fixture002'])]])],
            [$this->ok(['provisions' => [$this->provision(['max' => 13])]])], [$this->ok(['provisions' => [$this->provision(['expired' => 1])]])],
            [new Response(503)]] as $responses) {
            $this->reset();
            $client = Client::factory()->create(['controld_org_id' => self::ORG]);
            $intent = $this->codeIntent($client);
            $this->bindVendor($responses);
            $this->reconcile($client, $intent);
        }
        // A refusal whose audit row cannot be written logs (ids and exception class only).
        $this->reset();
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $intent = $this->codeIntent($client);
        $this->bindVendor([$this->ok(['provisions' => [$this->provision(['max' => 13])]])]);
        $fail = true;
        TechnicianActionLog::creating(function () use (&$fail): void {
            if ($fail) {
                throw new \RuntimeException('synthetic audit failure');
            }
        });
        $this->reconcile($client, $intent);
        $fail = false;
        $this->assertNotEmpty(array_filter($records, fn ($r) => str_contains($r, 'Reconcile refusal audit row failed')), 'the swallowed audit failure is logged');

        \Illuminate\Support\Facades\Log::warning('positive control');
        $this->assertNotEmpty(array_filter($records, fn ($r) => str_contains($r, 'positive control')), 'the listener captures records');
        $this->assertGreaterThan(1, count($records), 'the reconcile arms log through the listener');
        foreach ($records as $record) {
            $this->assertStringNotContainsString($code, $record);
        }
    }

    // ── guards ───────────────────────────────────────────────────────────────────

    public function test_a_non_admin_is_refused(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $intent = $this->codeIntent($client);
        $before = $this->state($intent);
        $this->bindVendor([]);

        $this->reconcile($client, $intent, User::factory()->tech()->create(['is_active' => true]))->assertForbidden();

        $this->assertSame($before, $this->state($intent));
        $this->assertSame([], $this->history);
        $this->assertSame(0, TechnicianActionLog::count());
    }

    public function test_the_service_refuses_a_non_admin_actor(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $intent = $this->codeIntent($client);
        $this->bindVendor([]);

        try {
            app(ControlDOnboardingStaged::class)->reconcile(User::factory()->tech()->create(['is_active' => true]), (int) $client->id, $intent->id);
            $this->fail('a non-Admin reconciled');
        } catch (\App\Services\ControlD\ControlDClientException $e) {
            $this->assertSame('Onboarding requires an active Admin user.', $e->getMessage());
        }
        $this->assertSame('uncertain', $intent->fresh()->state);
        $this->assertSame([], $this->history);
    }

    public function test_an_intent_of_another_client_is_a_404(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $other = Client::factory()->create(['controld_org_id' => 'testorg002']);
        $intent = $this->codeIntent($other);
        $before = $this->state($intent);
        $this->bindVendor([]);

        $this->reconcile($client, $intent)->assertNotFound();

        $this->assertSame($before, $this->state($intent));
        $this->assertSame([], $this->history);
        $this->assertSame(0, TechnicianActionLog::count());
    }

    /** A posted intent may still have its write in flight (finish() is unguarded): refused before any read. */
    public function test_posted_staged_bound_rejected_released_and_lockless_intents_are_refused_unchanged(): void
    {
        foreach ([['posted', true], ['staged', true], ['bound', false], ['rejected', false], [ControlDOnboardingStaged::RELEASED, false], ['uncertain', false]] as [$state, $locked]) {
            $this->reset();
            $client = Client::factory()->create(['controld_org_id' => self::ORG]);
            $intent = $this->intent($client, 'code', $state, $this->codePayload(), self::ORG, self::CODE_PK, $locked);
            $before = $this->state($intent);
            $this->bindVendor([$this->ok(['provisions' => [$this->provision()]])]);

            $this->reconcile($client, $intent)->assertSessionHasErrors('controld_onboarding');

            $this->assertSame($before, $this->state($intent), $state);
            $this->assertSame([self::ORG, null, null], $this->clientState($client));
            $this->assertSame([], $this->history, "{$state}: refused before any read");
            $this->assertAudited($intent, 'refused', 'blocked');
            $this->assertRefusalSays(self::BY_HAND);
            $this->assertStringNotContainsString('Only a posted or uncertain', session('errors')->first('controld_onboarding'));
        }
    }

    /** G-14 (diff:2): an uncertain invalidate intent is refused and the text says it is held for a by-hand ruling with no in-app way out. */
    public function test_an_uncertain_invalidate_intent_is_refused_and_said_to_be_held_by_hand(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $intent = $this->intent($client, 'invalidate', 'uncertain', ['org_pk' => self::ORG, 'code_pk' => self::CODE_PK], self::ORG, self::CODE_PK);
        $before = $this->state($intent);
        $this->bindVendor([]);

        $this->reconcile($client, $intent)->assertSessionHasErrors('controld_onboarding');

        $this->assertSame($before, $this->state($intent));
        $this->assertSame([], $this->history);
        $this->assertAudited($intent, 'refused', 'blocked');
        $this->assertRefusalSays('Only an uncertain organization, global-profile or code intent of this client that still holds its onboarding lock can be reconciled here; nothing was changed.');
        $this->assertRefusalSays(self::BY_HAND);
    }

    /** The FOR UPDATE re-check: an intent whose payload changes while Control D is read is not bound. */
    public function test_an_intent_changed_during_the_read_is_refused_by_the_locked_recheck(): void
    {
        $client = Client::factory()->create();
        $intent = $this->intent($client, 'organization', 'uncertain', $this->orgPayload());
        $this->bindVendor([function () use ($intent) {
            $row = ControlDOnboardingIntent::findOrFail($intent->id);
            $row->payload = [...$this->orgPayload(), 'name' => 'Another Org'];
            $row->saveOrFail();

            return $this->ok(['sub_organizations' => [$this->subOrg()]]);
        }]);
        $before = $this->state($intent);

        $this->reconcile($client, $intent)->assertSessionHasErrors('controld_onboarding');

        $this->assertSame($before, $this->state($intent));
        $this->assertSame([null, null, null], $this->clientState($client));
        $this->assertAudited($intent, 'refused', 'blocked');
        $this->assertNoVendorWrite();
        $this->assertRefusalSays(self::NOT_SAVED);
    }

    /** A match whose local end state is no longer allowed (org taken meanwhile) rolls back whole. */
    public function test_a_match_whose_bind_recheck_fails_rolls_back_and_is_refused(): void
    {
        $client = Client::factory()->create();
        $intent = $this->intent($client, 'organization', 'uncertain', $this->orgPayload());
        Client::factory()->create(['controld_org_id' => self::ORG]);
        $before = $this->state($intent);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->subOrg()]])]);

        $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [null, null, null]);
        // diff:9: nothing changed here (the org was already mapped before the read), so the text must not assert a change.
        $this->assertRefusalSays(self::NOT_SAVED);
        $this->assertRefusalSays('the organization is already mapped to another client');
        $this->assertStringNotContainsString('The intent or the client changed while Control D was being read', session('errors')->first('controld_onboarding'));
    }

    /** diff:9: an audit insert failure on a match rolls back; the text names it as a possible cause rather than asserting a change. */
    public function test_a_match_whose_audit_row_cannot_be_written_rolls_back_with_the_not_saved_text(): void
    {
        $client = Client::factory()->create();
        $intent = $this->intent($client, 'organization', 'uncertain', $this->orgPayload());
        $before = $this->state($intent);
        $this->bindVendor([$this->ok(['sub_organizations' => [$this->subOrg()]])]);
        $calls = 0;
        TechnicianActionLog::creating(function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('synthetic audit failure');
            }
        });

        $this->assertRefusedUnchanged($this->reconcile($client, $intent), $intent, $client, $before, [null, null, null]);
        $this->assertRefusalSays(self::NOT_SAVED);
        $this->assertRefusalSays('or the audit row could not be written');
    }

    public function test_the_client_page_offers_reconcile_only_for_a_locked_uncertain_intent(): void
    {
        $client = Client::factory()->create(['controld_org_id' => self::ORG]);
        $held = $this->codeIntent($client);
        $other = Client::factory()->create(['controld_org_id' => 'testorg002']);
        $lockless = $this->intent($other, 'code', 'uncertain', $this->codePayload(), 'testorg002', self::CODE_PK, false);

        $html = $this->actingAs($this->admin())->get(route('clients.show', $client))->assertOk()->getContent();
        $this->assertStringContainsString(route('clients.controld.intent.reconcile', [$client, $held->id]), $html);
        $this->assertStringContainsString('Reconcile reads Control D only (no write)', $html);
        // G-14 (diff:5): the page names what is compared per step, never "exactly what this intent sent".
        $this->assertStringContainsString('compares, for this step: an organization\'s name, recorded PK and the currently configured global profile; a global profile\'s profile against the one this intent sent; a code\'s fields against the ones this intent sent. If those match, the intent is bound;', $html);
        $this->assertStringNotContainsString('exactly what this intent sent', $html);
        $html = $this->actingAs($this->admin())->get(route('clients.show', $other))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('clients.controld.intent.reconcile', [$other, $lockless->id]), $html);

        // A posted intent may still have its write in flight: never offered.
        $third = Client::factory()->create(['controld_org_id' => 'testorg003']);
        $posted = $this->intent($third, 'code', 'posted', $this->codePayload(), 'testorg003', self::CODE_PK);
        $html = $this->actingAs($this->admin())->get(route('clients.show', $third))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('clients.controld.intent.reconcile', [$third, $posted->id]), $html);
    }
}
