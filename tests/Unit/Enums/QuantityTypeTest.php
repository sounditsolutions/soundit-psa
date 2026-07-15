<?php

namespace Tests\Unit\Enums;

use App\Enums\QuantityType;
use PHPUnit\Framework\TestCase;

class QuantityTypeTest extends TestCase
{
    /**
     * Graduated band labels are printed on client-facing invoices, so every
     * quantity type has to name what it counts. `unitNoun()` is a match with no
     * default arm — a new case added without a noun would throw
     * \UnhandledMatchError in the middle of invoice generation, so cover them all.
     */
    public function test_every_quantity_type_names_the_thing_it_counts(): void
    {
        foreach (QuantityType::cases() as $type) {
            $this->assertNotSame('', $type->unitNoun(), "{$type->value} has no unit noun.");
        }
    }

    public function test_known_domains_are_named_and_unknown_ones_fall_back_to_units(): void
    {
        $this->assertSame('GB', QuantityType::PerBackupStorageGb->unitNoun());
        $this->assertSame('workstations', QuantityType::PerWorkstation->unitNoun());
        $this->assertSame('servers', QuantityType::PerServer->unitNoun());
        $this->assertSame('users', QuantityType::PerUser->unitNoun());
        $this->assertSame('licenses', QuantityType::PerLicenseType->unitNoun());

        // Fixed counts whatever the operator decided it counts; Overage counts
        // divisor-scaled billing units. Neither has a domain we can name.
        $this->assertSame('units', QuantityType::Fixed->unitNoun());
        $this->assertSame('units', QuantityType::Overage->unitNoun());
    }
}
