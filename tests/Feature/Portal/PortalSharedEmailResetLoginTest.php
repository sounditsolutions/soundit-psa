<?php

namespace Tests\Feature\Portal;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Card FhGlCXPw: contacts at one Active client share one email.
 *
 * Login resolves the Person by email + portal_enabled + is_active. The portal
 * password broker used to resolve by email alone (first row, no order), so a
 * reset link or invite token was redeemed onto a lower-id sibling who is not
 * portal-enabled: the password landed on the wrong row and the portal user
 * could never log in. PortalUserProvider::retrieveByCredentials now applies
 * login's predicate to every broker lookup.
 *
 * These tests drive the real routes (forgot-password, the staff reset and
 * invite actions, reset-password, login). Mail goes through a mocked
 * EmailService that records who each message was addressed to. The
 * passphrase constants below are synthetic test values.
 */
class PortalSharedEmailResetLoginTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'shared-inbox@example.test';

    private const NEW_PASSPHRASE = 'brand-new-pass-1';

    private const OLD_PASSPHRASE = 'old-sibling-pass';

    /** @var list<array{to: string, name: ?string, body: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Http::fake();
        Setting::setValue('portal_enabled', '1');
        $this->mock(EmailService::class, function (MockInterface $m): void {
            $m->shouldReceive('sendNew')->andReturnUsing(function (string $to, string $subject, string $body, ?string $name = null) {
                $this->sent[] = ['to' => $to, 'name' => $name, 'body' => $body];

                return new Email;
            });
        });
    }

    private function contact(Client $client, bool $portalEnabled, string $lastName, bool $active = true, ?string $password = null): Person
    {
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Synthetic',
            'last_name' => $lastName,
            'email' => self::EMAIL,
            'is_active' => $active,
            'portal_enabled' => $portalEnabled,
        ]);
        if ($password !== null) {
            $person->forceFill(['password' => Hash::make($password)])->save();
        }

        return $person->fresh();
    }

    /** Prod shape: lower-id sibling NOT portal-enabled, portal user has no password yet. */
    private function prodShape(): array
    {
        $client = Client::factory()->create(); // Active stage
        $sibling = $this->contact($client, false, 'Sibling');
        $portalUser = $this->contact($client, true, 'PortalUser');
        $this->assertLessThan($portalUser->id, $sibling->id, 'fixture: sibling must sort first');

        return [$sibling, $portalUser];
    }

    /** The one message sent: assert its addressee, return the token in its link. */
    private function sentTokenFor(Person $person): string
    {
        $this->assertCount(1, $this->sent, 'exactly one portal message is sent');
        $this->assertSame(self::EMAIL, $this->sent[0]['to']);
        $this->assertSame($person->full_name, $this->sent[0]['name'], 'the message is addressed to '.$person->full_name);
        $this->assertSame(1, preg_match('~/portal/reset-password/([^\s?"<>]+)~', $this->sent[0]['body'], $m), 'the message carries a reset link');

        return $m[1];
    }

    /** Redeem through the real reset route, then prove the password and login landed on $owner only. */
    private function redeemAndAssertOwner(string $token, Person $owner, Person ...$others): void
    {
        $this->post('/portal/reset-password', [
            'token' => $token,
            'email' => self::EMAIL,
            'password' => self::NEW_PASSPHRASE,
            'password_confirmation' => self::NEW_PASSPHRASE,
        ])->assertRedirect(route('portal.dashboard'));

        $this->assertTrue(Hash::check(self::NEW_PASSPHRASE, (string) $owner->fresh()->password), 'the portal user receives the password');
        foreach ($others as $other) {
            $this->assertFalse(filled($other->fresh()->password), "contact {$other->last_name} must not receive the password");
        }

        Auth::guard('portal')->logout();
        $this->post('/portal/login', ['email' => self::EMAIL, 'password' => self::NEW_PASSPHRASE])
            ->assertSessionHasNoErrors();
        $this->assertSame($owner->id, Auth::guard('portal')->id(), 'login resolves the same person the reset wrote');
    }

    public function test_reset_and_login_resolve_the_same_person_for_a_shared_email(): void
    {
        [$sibling, $portalUser] = $this->prodShape();

        $this->post('/portal/forgot-password', ['email' => self::EMAIL])
            ->assertRedirect()
            ->assertSessionHas('status', 'If an account exists with that email, a password reset link has been sent.');

        $this->redeemAndAssertOwner($this->sentTokenFor($portalUser), $portalUser, $sibling);
    }

    public function test_staff_send_reset_on_a_shared_email_resets_the_portal_user(): void
    {
        [$sibling, $portalUser] = $this->prodShape();

        $this->actingAs(User::factory()->create())
            ->post(route('clients.portal.reset-password', $portalUser->client_id), ['person_id' => $portalUser->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->redeemAndAssertOwner($this->sentTokenFor($portalUser), $portalUser, $sibling);
    }

    public function test_reset_skips_an_inactive_portal_enabled_sibling(): void
    {
        $client = Client::factory()->create();
        $inactive = $this->contact($client, true, 'Inactive', active: false);
        $portalUser = $this->contact($client, true, 'PortalUser');
        $this->assertLessThan($portalUser->id, $inactive->id, 'fixture: inactive sibling must sort first');

        $this->post('/portal/forgot-password', ['email' => self::EMAIL])->assertRedirect();

        $this->redeemAndAssertOwner($this->sentTokenFor($portalUser), $portalUser, $inactive);
    }

    public function test_forgot_password_with_no_portal_user_on_the_email_sends_nothing_and_answers_the_same(): void
    {
        $this->contact(Client::factory()->create(), false, 'Sibling', password: self::OLD_PASSPHRASE);

        $this->post('/portal/forgot-password', ['email' => self::EMAIL])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'If an account exists with that email, a password reset link has been sent.');

        $this->assertSame([], $this->sent, 'no reset link goes to a contact who is not portal-enabled');
        $this->assertSame(0, \DB::table('portal_password_reset_tokens')->count(), 'no token is minted');
    }

    public function test_invite_token_redeemed_sets_the_invited_persons_password_and_login_works(): void
    {
        [$sibling, $portalUser] = $this->prodShape();

        // Same call PortalManagementController::invite and verifyAccess make.
        $token = Password::broker('portal')->createToken($portalUser);

        $this->redeemAndAssertOwner($token, $portalUser, $sibling);
    }

    public function test_staff_re_invite_on_a_shared_email_puts_the_password_on_the_invited_person(): void
    {
        $client = Client::factory()->create();
        $sibling = $this->contact($client, false, 'Sibling');
        // Re-invite: the invited person is not yet portal-enabled; invite() enables
        // them before minting the token, so the redeem must find them, not the sibling.
        $invited = $this->contact($client, false, 'Invited');
        $this->assertLessThan($invited->id, $sibling->id, 'fixture: sibling must sort first');

        $this->actingAs(User::factory()->create())
            ->post(route('clients.portal.invite', $client), ['person_id' => $invited->id])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertTrue($invited->fresh()->portal_enabled);
        $this->assertFalse($sibling->fresh()->portal_enabled);

        $this->redeemAndAssertOwner($this->sentTokenFor($invited), $invited->fresh(), $sibling);
    }

    public function test_a_lookup_with_no_identifying_credential_finds_nobody(): void
    {
        $this->contact(Client::factory()->create(), true, 'PortalUser', password: self::NEW_PASSPHRASE);

        // The provider adds portal_enabled/is_active to every credentials lookup;
        // a password-only lookup must still find nobody, as the parent provider does.
        $this->assertNull(Auth::guard('portal')->getProvider()->retrieveByCredentials(['password' => self::NEW_PASSPHRASE]));
        $this->assertFalse(Auth::guard('portal')->attempt(['password' => self::NEW_PASSPHRASE]));
    }

    /** Control: the single-contact path works, so the failures above are the shared email. */
    public function test_control_single_contact_reset_then_login_succeeds(): void
    {
        $portalUser = $this->contact(Client::factory()->create(), true, 'PortalUser');
        $token = Password::broker('portal')->createToken($portalUser);

        $this->redeemAndAssertOwner($token, $portalUser);
    }
}
