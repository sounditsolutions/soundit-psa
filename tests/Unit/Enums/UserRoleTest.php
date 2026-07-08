<?php

namespace Tests\Unit\Enums;

use App\Enums\UserRole;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    public function test_it_exposes_the_expected_roles(): void
    {
        $values = array_map(fn (UserRole $r) => $r->value, UserRole::cases());

        $this->assertSame(['admin', 'tech', 'contractor', 'billing'], $values);
    }

    public function test_every_role_has_a_label_description_and_badge_class(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->assertNotEmpty($role->label(), "{$role->value} label");
            $this->assertNotEmpty($role->description(), "{$role->value} description");
            $this->assertNotEmpty($role->badgeClass(), "{$role->value} badge class");
        }
    }

    public function test_it_resolves_from_a_stored_string_value(): void
    {
        $this->assertSame(UserRole::Billing, UserRole::from('billing'));
        $this->assertNull(UserRole::tryFrom('nonsense'));
    }
}
