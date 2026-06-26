<?php

namespace Tests\Feature\Teams;

use App\Models\Setting;
use App\Models\User;
use App\Services\Teams\ResolvedSender;
use App\Services\Teams\TeamsIdentityResolver;
use App\Support\TeamsBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * TeamsIdentityResolver (E1) — maps an inbound Teams/Entra sender to the REAL
 * PSA user, scoped to the MSP. The load-bearing guarantee: an unknown or
 * deactivated or cross-tenant sender resolves to NULL (and is audited) — NEVER
 * to a shared service account. This is the anti-pattern (VerifyMcpStaffToken
 * collapses every sender into the system user) we must not emulate.
 */
class TeamsIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    private string $appId = '11111111-1111-1111-1111-111111111111';

    private string $tenantId = '22222222-2222-2222-2222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('teams_bot_app_id', $this->appId);
        Setting::setValue('teams_bot_tenant_id', $this->tenantId);
        TeamsBotConfig::setClientSecret('secret');
    }

    /** A Bot Framework activity addressed to our bot from the given Entra object id. */
    private function activity(?string $aadObjectId, array $overrides = []): array
    {
        return array_replace_recursive([
            'recipient' => ['id' => $this->appId],
            'channelData' => ['tenant' => ['id' => $this->tenantId]],
            'from' => ['aadObjectId' => $aadObjectId, 'name' => 'Sender'],
            'conversation' => ['id' => 'a:conv-123'],
            'serviceUrl' => 'https://smba.trafficmanager.net/teams/',
        ], $overrides);
    }

    public function test_known_active_sender_resolves_to_the_psa_user(): void
    {
        $user = User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $resolved = app(TeamsIdentityResolver::class)->resolve($this->activity('aad-charlie'));

        $this->assertInstanceOf(ResolvedSender::class, $resolved);
        $this->assertSame($user->id, $resolved->user->id);
        $this->assertSame($this->appId, $resolved->appId);
        $this->assertSame($this->tenantId, $resolved->tenantId);
        $this->assertSame('a:conv-123', $resolved->conversationId);
        $this->assertSame('https://smba.trafficmanager.net/teams/', $resolved->serviceUrl);
    }

    public function test_unknown_sender_resolves_to_null_and_is_audited(): void
    {
        Log::spy();
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        $resolved = app(TeamsIdentityResolver::class)->resolve($this->activity('aad-stranger'));

        $this->assertNull($resolved);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_deactivated_user_does_not_resolve(): void
    {
        User::factory()->create(['microsoft_id' => 'aad-former', 'is_active' => false]);

        $this->assertNull(app(TeamsIdentityResolver::class)->resolve($this->activity('aad-former')));
    }

    public function test_never_falls_back_to_a_shared_system_user(): void
    {
        // Even with a system user configured (the shared-user anti-pattern's target),
        // an unknown sender must resolve to NULL, never that account.
        $system = User::factory()->create(['microsoft_id' => 'aad-system', 'is_active' => true]);
        Setting::setValue('triage_system_user_id', (string) $system->id);

        $resolved = app(TeamsIdentityResolver::class)->resolve($this->activity('aad-nobody'));

        $this->assertNull($resolved, 'an unknown sender must never collapse into a shared user');
    }

    public function test_activity_for_an_unregistered_bot_resolves_to_null(): void
    {
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        // recipient.id is NOT our registered bot App ID — not for us.
        $activity = $this->activity('aad-charlie', ['recipient' => ['id' => 'some-other-bot']]);

        $this->assertNull(app(TeamsIdentityResolver::class)->resolve($activity));
    }

    public function test_cross_tenant_sender_does_not_resolve(): void
    {
        User::factory()->create(['microsoft_id' => 'aad-charlie', 'is_active' => true]);

        // The activity claims a DIFFERENT tenant than the registered bot's tenant.
        $activity = $this->activity('aad-charlie', ['channelData' => ['tenant' => ['id' => 'evil-tenant']]]);

        $this->assertNull(app(TeamsIdentityResolver::class)->resolve($activity), 'a sender from another tenant must not resolve');
    }
}
