<?php

namespace Tests\Unit\Models;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_is_cast_to_the_enum_when_read_back_from_the_database(): void
    {
        $user = User::factory()->create(['role' => 'billing']);

        $this->assertInstanceOf(UserRole::class, $user->refresh()->role);
        $this->assertSame(UserRole::Billing, $user->role);
    }

    public function test_predicate_helpers_reflect_the_assigned_role(): void
    {
        $admin = User::factory()->admin()->make();
        $tech = User::factory()->tech()->make();
        $billing = User::factory()->billing()->make();
        $contractor = User::factory()->contractor()->make();

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isTech());

        $this->assertTrue($tech->isTech());
        $this->assertFalse($tech->isAdmin());

        $this->assertTrue($billing->isBilling());
        $this->assertFalse($billing->isContractorRole());

        $this->assertTrue($contractor->isContractorRole());
        $this->assertFalse($contractor->isAdmin());
    }

    public function test_has_role_matches_any_of_the_given_roles(): void
    {
        $tech = User::factory()->tech()->make();

        $this->assertTrue($tech->hasRole(UserRole::Tech));
        $this->assertTrue($tech->hasRole(UserRole::Admin, UserRole::Tech));
        $this->assertFalse($tech->hasRole(UserRole::Admin, UserRole::Billing));
    }

    public function test_contractor_factory_state_also_sets_the_time_pool_flag(): void
    {
        $contractor = User::factory()->contractor()->make();

        // The authorization role and the time-pool boolean are distinct signals,
        // but the contractor state seeds both so they start consistent.
        $this->assertSame(UserRole::Contractor, $contractor->role);
        $this->assertTrue($contractor->is_contractor);
    }

    public function test_role_scope_filters_users_by_role(): void
    {
        User::factory()->admin()->create();
        User::factory()->count(2)->tech()->create();
        User::factory()->billing()->create();

        $this->assertSame(2, User::role(UserRole::Tech)->count());
        $this->assertSame(1, User::role(UserRole::Billing)->count());
    }
}
