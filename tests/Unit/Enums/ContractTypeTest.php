<?php

namespace Tests\Unit\Enums;

use App\Enums\ContractType;
use PHPUnit\Framework\TestCase;

class ContractTypeTest extends TestCase
{
    /**
     * routingPriority() encodes the coverage precedence used to pick a client's
     * headline tier for pre-ring call routing: Managed > Custom > Break-Fix.
     */
    public function test_routing_priority_orders_managed_above_custom_above_breakfix(): void
    {
        $this->assertGreaterThan(
            ContractType::Custom->routingPriority(),
            ContractType::Managed->routingPriority(),
            'Managed must outrank Custom'
        );

        $this->assertGreaterThan(
            ContractType::BreakFix->routingPriority(),
            ContractType::Custom->routingPriority(),
            'Custom must outrank Break-Fix'
        );
    }

    /** Every case returns a distinct positive rank so sorting is total (no ties). */
    public function test_routing_priority_is_distinct_and_positive_for_every_case(): void
    {
        $ranks = array_map(
            fn (ContractType $type) => $type->routingPriority(),
            ContractType::cases(),
        );

        foreach ($ranks as $rank) {
            $this->assertGreaterThan(0, $rank);
        }

        $this->assertCount(count($ranks), array_unique($ranks), 'Ranks must be unique');
    }
}
