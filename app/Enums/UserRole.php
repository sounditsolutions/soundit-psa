<?php

namespace App\Enums;

/**
 * Staff authorization role.
 *
 * This is the first slice of the app-wide authorization layer (see the
 * "Authorization middleware (policies/gates)" epic). It only models the role
 * itself — policies and gates that read it are added in follow-up work. Every
 * existing user is migrated to {@see self::Admin} so behaviour is unchanged
 * until enforcement lands.
 *
 * Note: {@see self::Contractor} is the authorization role and is distinct from
 * the `is_contractor` boolean on the users table, which drives contractor
 * time-pool billing. The two are seeded consistently by the migration but are
 * conceptually separate signals.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Tech = 'tech';
    case Contractor = 'contractor';
    case Billing = 'billing';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Tech => 'Technician',
            self::Contractor => 'Contractor',
            self::Billing => 'Billing',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Full access to every module, including staff, billing, and contract terms.',
            self::Tech => 'Day-to-day service delivery — tickets, clients, and assets.',
            self::Contractor => 'External contractor — intended to be scoped to their own assigned work.',
            self::Billing => 'Billing and invoicing, without full administrative access.',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Admin => 'bg-danger',
            self::Tech => 'bg-primary',
            self::Contractor => 'bg-info text-dark',
            self::Billing => 'bg-success',
        };
    }
}
