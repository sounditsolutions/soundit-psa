<?php

namespace Tests\Feature\Portal;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Card FhGlCXPw (T-22977): contacts at one Active client share one email.
 *
 * The portal password broker resolves the Person by email alone
 * (EloquentUserProvider::retrieveByCredentials -> first(), no portal_enabled
 * filter, no order), while login() resolves by email + portal_enabled +
 * is_active. With a lower-id, NOT portal-enabled sibling on the same email,
 * a token minted for the portal user (staff invite / verifyAccess both call
 * createToken($person)) is redeemed onto the sibling: the password lands on
 * the wrong row and the portal user can never log in.
 *
 * These tests assert the CORRECT behaviour and are expected to FAIL at
 * a18b98a6. They are the proof for the card, not a fix.
 */
class PortalSharedEmailResetLoginTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'shared-inbox@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Setting::setValue('portal_enabled', '1');
    }

    private function contact(Client $client, bool $portalEnabled, ?string $password = null): Person
    {
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Synthetic',
            'last_name' => $portalEnabled ? 'PortalUser' : 'Sibling',
            'email' => self::EMAIL,
            'is_active' => true,
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
        $sibling = $this->contact($client, false);
        $portalUser = $this->contact($client, true);
        $this->assertLessThan($portalUser->id, $sibling->id, 'fixture: sibling must sort first');

        return [$sibling, $portalUser];
    }

    public function test_reset_and_login_resolve_the_same_person_for_a_shared_email(): void
    {
        [$sibling, $portalUser] = $this->prodShape();

        $resetResolves = Password::broker('portal')->getUser(['email' => self::EMAIL]);
        $loginResolves = Auth::guard('portal')->getProvider()->retrieveByCredentials([
            'email' => self::EMAIL, 'portal_enabled' => true, 'is_active' => true,
        ]);

        $this->assertSame($portalUser->id, $loginResolves?->id, 'login resolves the portal user');
        $this->assertSame(
            $loginResolves?->id,
            $resetResolves?->id,
            "reset broker resolves person {$resetResolves?->id}, login resolves person {$loginResolves?->id}"
        );
    }

    public function test_invite_token_redeemed_sets_the_invited_persons_password_and_login_works(): void
    {
        [$sibling, $portalUser] = $this->prodShape();

        // Same call PortalManagementController::invite and verifyAccess make.
        $token = Password::broker('portal')->createToken($portalUser);

        $this->post('/portal/reset-password', [
            'token' => $token,
            'email' => self::EMAIL,
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'brand-new-pass-1',
        ])->assertRedirect();

        $this->assertFalse(filled($sibling->fresh()->password), 'the non-portal sibling must not receive the password');
        $this->assertTrue(filled($portalUser->fresh()->password), 'the invited portal user must receive the password');

        Auth::guard('portal')->logout();
        $this->post('/portal/login', ['email' => self::EMAIL, 'password' => 'brand-new-pass-1'])
            ->assertSessionHasNoErrors();
        $this->assertSame($portalUser->id, Auth::guard('portal')->id());
    }

    /** Control: the single-contact path works, so the failures above are the shared email. */
    public function test_control_single_contact_reset_then_login_succeeds(): void
    {
        $portalUser = $this->contact(Client::factory()->create(), true);
        $token = Password::broker('portal')->createToken($portalUser);

        $this->post('/portal/reset-password', [
            'token' => $token,
            'email' => self::EMAIL,
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'brand-new-pass-1',
        ])->assertRedirect(route('portal.dashboard'));

        Auth::guard('portal')->logout();
        $this->post('/portal/login', ['email' => self::EMAIL, 'password' => 'brand-new-pass-1'])
            ->assertSessionHasNoErrors();
        $this->assertSame($portalUser->id, Auth::guard('portal')->id());
    }
}
