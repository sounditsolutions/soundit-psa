<?php

namespace App\Enums;

enum AssignmentRuleType: string
{
    case AllAssets = 'all_assets';
    case AssetsByType = 'assets_by_type';
    case AllPeople = 'all_people';
    case AllActivePeople = 'all_active_people';

    public function label(): string
    {
        return match ($this) {
            self::AllAssets => 'All Assets',
            self::AssetsByType => 'Assets by Type',
            self::AllPeople => 'All People',
            self::AllActivePeople => 'All Active People',
        };
    }

    public function entityType(): string
    {
        return match ($this) {
            self::AllAssets, self::AssetsByType => 'asset',
            self::AllPeople, self::AllActivePeople => 'person',
        };
    }

    public function needsFilterValues(): bool
    {
        return $this === self::AssetsByType;
    }
}
