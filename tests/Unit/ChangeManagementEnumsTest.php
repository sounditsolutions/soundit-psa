<?php

namespace Tests\Unit;

use App\Enums\CabApproval;
use App\Enums\ChangeType;
use App\Enums\RiskLevel;
use Tests\TestCase;

class ChangeManagementEnumsTest extends TestCase
{
    public function test_change_type_values_and_labels(): void
    {
        $this->assertSame('standard', ChangeType::Standard->value);
        $this->assertSame('normal', ChangeType::Normal->value);
        $this->assertSame('emergency', ChangeType::Emergency->value);

        $this->assertSame('Emergency', ChangeType::Emergency->label());
        $this->assertNotEmpty(ChangeType::Normal->description());
    }

    public function test_risk_level_values_and_labels(): void
    {
        $this->assertSame(['low', 'medium', 'high'], array_map(
            fn (RiskLevel $r) => $r->value,
            RiskLevel::cases(),
        ));

        $this->assertSame('Medium', RiskLevel::Medium->label());
    }

    public function test_cab_approval_values_and_labels(): void
    {
        $this->assertSame('not_required', CabApproval::NotRequired->value);
        $this->assertSame('Not Required', CabApproval::NotRequired->label());
        $this->assertSame('Approved', CabApproval::Approved->label());
    }

    public function test_every_case_has_a_bootstrap_badge_class(): void
    {
        foreach (ChangeType::cases() as $case) {
            $this->assertStringStartsWith('bg-', $case->badgeClass());
        }
        foreach (RiskLevel::cases() as $case) {
            $this->assertStringStartsWith('bg-', $case->badgeClass());
        }
        foreach (CabApproval::cases() as $case) {
            $this->assertStringStartsWith('bg-', $case->badgeClass());
        }
    }
}
