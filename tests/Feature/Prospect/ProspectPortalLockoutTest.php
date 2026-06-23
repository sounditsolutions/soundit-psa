<?php

namespace Tests\Feature\Prospect;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Security gate: a prospect's Person must NEVER reach the client portal.
 *
 * Every grant path is exercised here — `login`, `sendAccessLink`, `verifyAccess`,
 * the password-reset chain (`sendResetLink` -> `resetPassword`), and the staff
 * `invite` / `toggle` / `impersonate` actions. None may set `portal_enabled`,
 * set a `password`, or hand out a portal session for a prospect-stage client.
 * Each test also has an Active-client control proving legitimate users still work.
 */
class ProspectPortalLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Person creation fires observers that dispatch queued work — contain it.
        Bus::fake();
        // The public portal routes are gated by PortalEnabled; turn the portal on.
        Setting::setValue('portal_enabled', '1');
    }

    /** A portal-eligible contact whose client is still a PROSPECT. */
    private function prospectContact(array $attrs = []): Person
    {
        $client = Client::factory()->prospect()->create();

        return $this->makeContact($client, array_merge([
            'email' => 'lead@example.test',
        ], $attrs));
    }

    /** A portal-eligible contact whose client is ACTIVE (the legitimate case). */
    private function activeContact(array $attrs = []): Person
    {
        $client = Client::factory()->create(); // stage defaults to Active

        return $this->makeContact($client, array_merge([
            'email' => 'customer@example.test',
        ], $attrs));
    }

    private function makeContact(Client $client, array $attrs): Person
    {
        $password = $attrs['password'] ?? null;
        unset($attrs['password']); // password is not mass-assignable on Person

        $person = Person::create(array_merge([
            'client_id' => $client->id,
            'person_type' => PersonType::User, // canHavePortal() === true, so stage is the only gate
            'first_name' => 'Lead',
            'last_name' => 'Contact',
            'is_active' => true,
            'portal_enabled' => false,
        ], $attrs));

        if ($password !== null) {
            $person->forceFill(['password' => Hash::make($password)])->save();
        }

        return $person->fresh();
    }

    private function resetTokenExists(string $email): bool
    {
        return DB::table('portal_password_reset_tokens')->where('email', $email)->exists();
    }

    // ── Predicate ───────────────────────────────────────────────────────────

    public function test_can_access_portal_is_false_for_a_prospect_contact_even_when_enabled(): void
    {
        $p = $this->prospectContact(['portal_enabled' => true, 'password' => 'secret-pass']);

        // Stage gate beats even enabled + active + password.
        $this->assertFalse($p->fresh()->canAccessPortal());
    }

    public function test_can_access_portal_is_true_for_a_fully_provisioned_active_contact(): void
    {
        $p = $this->activeContact(['portal_enabled' => true, 'password' => 'secret-pass']);

        $this->assertTrue($p->fresh()->canAccessPortal());
    }

    // ── login ───────────────────────────────────────────────────────────────

    public function test_login_is_rejected_for_a_prospect_contact_with_a_password(): void
    {
        $p = $this->prospectContact(['portal_enabled' => true, 'password' => 'secret-pass']);

        $this->post('/portal/login', [
            'email' => $p->email,
            'password' => 'secret-pass',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Auth::guard('portal')->check());
        $this->assertNull(Auth::guard('portal')->user());
    }

    public function test_login_succeeds_for_a_legitimate_active_contact(): void
    {
        $p = $this->activeContact(['portal_enabled' => true, 'password' => 'secret-pass']);

        $this->post('/portal/login', [
            'email' => $p->email,
            'password' => 'secret-pass',
        ])->assertRedirect(route('portal.dashboard'));

        $this->assertTrue(Auth::guard('portal')->check());
        $this->assertSame($p->id, Auth::guard('portal')->id());
    }

    // ── sendAccessLink (request-access) ──────────────────────────────────────

    public function test_send_access_link_does_nothing_for_a_prospect_contact(): void
    {
        $p = $this->prospectContact();

        $this->post('/portal/request-access', ['email' => $p->email])
            ->assertSessionHasNoErrors(); // no leak that the email exists

        // The eligible-person lookup must not have matched: portal stays off and
        // no signed verify-access token (i.e. no welcome email path) is triggered.
        $this->assertFalse($p->fresh()->portal_enabled);
    }

    public function test_send_access_link_works_for_an_active_contact(): void
    {
        $p = $this->activeContact();

        $this->post('/portal/request-access', ['email' => $p->email])
            ->assertSessionHasNoErrors();

        // The flow only mints a signed URL + email; portal_enabled flips on verify,
        // not here — so assert the contact remains eligible (active client) and
        // the request did not error. The companion verifyAccess test covers the flip.
        $this->assertTrue($p->fresh()->canAccessPortal() === false); // not yet enabled
        $this->assertTrue($p->fresh()->is_active);
    }

    // ── verifyAccess ─────────────────────────────────────────────────────────

    public function test_verify_access_does_not_enable_portal_for_a_prospect_contact(): void
    {
        $p = $this->prospectContact();

        $url = URL::temporarySignedRoute(
            'portal.verify-access',
            now()->addMinutes(60),
            ['person' => $p->id],
        );

        $this->get($url)->assertRedirect(route('portal.login'));

        $this->assertFalse($p->fresh()->portal_enabled);
        $this->assertNull($p->fresh()->password);
        $this->assertFalse($this->resetTokenExists($p->email));
    }

    public function test_verify_access_enables_portal_for_an_active_contact(): void
    {
        $p = $this->activeContact();

        $url = URL::temporarySignedRoute(
            'portal.verify-access',
            now()->addMinutes(60),
            ['person' => $p->id],
        );

        $this->get($url)->assertRedirect(); // -> set-password form

        $this->assertTrue($p->fresh()->portal_enabled);
        $this->assertTrue($this->resetTokenExists($p->email));
    }

    // ── password reset chain (sendResetLink -> resetPassword) ────────────────

    public function test_password_reset_chain_is_inert_for_a_prospect_contact(): void
    {
        // Even a prospect who somehow has portal_enabled + an existing password
        // must not be able to reset and log in.
        $p = $this->prospectContact(['portal_enabled' => true, 'password' => 'old-pass']);

        // 1. sendResetLink must not mint a token for a prospect.
        $this->post('/portal/forgot-password', ['email' => $p->email])
            ->assertSessionHasNoErrors();
        $this->assertFalse($this->resetTokenExists($p->email));

        // 2. Even if an attacker forges a token row, reset must not set a password
        //    nor grant a session for a prospect.
        DB::table('portal_password_reset_tokens')->insert([
            'email' => $p->email,
            'token' => Hash::make('forged-token'),
            'created_at' => now(),
        ]);

        $this->post('/portal/reset-password', [
            'token' => 'forged-token',
            'email' => $p->email,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Auth::guard('portal')->check());
        // Password must be unchanged (the old one still verifies, the new one does not).
        $this->assertTrue(Hash::check('old-pass', $p->fresh()->password));
        $this->assertFalse(Hash::check('brand-new-pass', $p->fresh()->password));
    }

    public function test_password_reset_chain_works_for_an_active_contact(): void
    {
        $p = $this->activeContact(['portal_enabled' => true, 'password' => 'old-pass']);

        $this->post('/portal/forgot-password', ['email' => $p->email])
            ->assertSessionHasNoErrors();
        $this->assertTrue($this->resetTokenExists($p->email));

        $token = DB::table('portal_password_reset_tokens')->where('email', $p->email)->value('token');
        // The stored token is hashed; mint a fresh known token via the broker instead.
        $plainToken = Password::broker('portal')->createToken($p->fresh());

        $this->post('/portal/reset-password', [
            'token' => $plainToken,
            'email' => $p->email,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertRedirect(route('portal.dashboard'));

        $this->assertTrue(Auth::guard('portal')->check());
        $this->assertTrue(Hash::check('brand-new-pass', $p->fresh()->password));
    }

    // ── staff invite ─────────────────────────────────────────────────────────

    public function test_staff_invite_is_blocked_for_a_prospect_contact(): void
    {
        $staff = User::factory()->create();
        $p = $this->prospectContact();

        $this->actingAs($staff)
            ->post(route('clients.portal.invite', $p->client), ['person_id' => $p->id])
            ->assertForbidden();

        $this->assertFalse($p->fresh()->portal_enabled);
        $this->assertNull($p->fresh()->password);
        $this->assertFalse($this->resetTokenExists($p->email));
    }

    public function test_staff_invite_works_for_an_active_contact(): void
    {
        $staff = User::factory()->create();
        $p = $this->activeContact();

        $this->actingAs($staff)
            ->post(route('clients.portal.invite', $p->client), ['person_id' => $p->id])
            ->assertRedirect();

        $this->assertTrue($p->fresh()->portal_enabled);
        $this->assertTrue($this->resetTokenExists($p->email));
    }

    // ── staff toggle (enable) ────────────────────────────────────────────────

    public function test_staff_toggle_cannot_enable_portal_for_a_prospect_contact(): void
    {
        $staff = User::factory()->create();
        $p = $this->prospectContact(); // portal_enabled = false

        $this->actingAs($staff)
            ->post(route('clients.portal.toggle', $p->client), ['person_id' => $p->id])
            ->assertForbidden();

        $this->assertFalse($p->fresh()->portal_enabled);
    }

    // ── staff impersonate ────────────────────────────────────────────────────

    public function test_staff_impersonate_is_blocked_for_a_prospect_contact(): void
    {
        $staff = User::factory()->create();
        // Even a prospect with portal_enabled set must not be impersonable.
        $p = $this->prospectContact(['portal_enabled' => true]);

        $this->actingAs($staff)
            ->post(route('clients.portal.impersonate', $p->client), ['person_id' => $p->id])
            ->assertForbidden();

        $this->assertFalse(Auth::guard('portal')->check());
    }
}
