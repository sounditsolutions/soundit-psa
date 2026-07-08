<?php

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_a_staff_member_persists_the_selected_role(): void
    {
        $actor = User::factory()->admin()->create();
        $target = User::factory()->admin()->create();

        $this->actingAs($actor)
            ->patch(route('settings.staff.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => UserRole::Tech->value,
            ])
            ->assertRedirect(route('settings.staff.index'));

        $this->assertSame(UserRole::Tech, $target->refresh()->role);
    }

    public function test_role_is_required_and_must_be_a_valid_enum_value(): void
    {
        $actor = User::factory()->admin()->create();
        $target = User::factory()->tech()->create();

        $this->actingAs($actor)
            ->patch(route('settings.staff.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => 'superuser',
            ])
            ->assertSessionHasErrors('role');

        // The invalid submission is rejected, so the stored role is unchanged.
        $this->assertSame(UserRole::Tech, $target->refresh()->role);
    }

    public function test_new_staff_default_to_the_admin_role(): void
    {
        $actor = User::factory()->admin()->create();

        $this->actingAs($actor)
            ->post(route('settings.staff.store'), [
                'name' => 'New Hire',
                'email' => 'new.hire@example.test',
            ])
            ->assertRedirect(route('settings.staff.index'));

        $created = User::where('email', 'new.hire@example.test')->firstOrFail();

        $this->assertSame(UserRole::Admin, $created->role);
    }
}
