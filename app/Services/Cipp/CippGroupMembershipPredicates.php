<?php

namespace App\Services\Cipp;

final class CippGroupMembershipPredicates
{
    public static function notAddableReason(array $row): ?string
    {
        $groupTypes = array_values(array_filter(is_array($row['groupTypes'] ?? null) ? $row['groupTypes'] : [], 'is_string'));
        if ((bool) ($row['dynamicGroupBool'] ?? false)
            || in_array('DynamicMembership', $groupTypes, true)
            || trim((string) ($row['membershipRule'] ?? '')) !== '') {
            return 'dynamic';
        }
        if (($row['onPremisesSyncEnabled'] ?? null) === true) {
            return 'on_premises';
        }
        $type = trim((string) ($row['groupType'] ?? ''));
        foreach (['Microsoft 365', 'Mail-Enabled Security', 'Security', 'Distribution List'] as $known) {
            if (strcasecmp($type, $known) === 0) {
                return null;
            }
        }

        return 'unrecognized_type';
    }

    /** Mirrors Invoke-ListGroups.ps1's groupType projection from Graph fields. */
    public static function normalizeGraphRow(array $row): array
    {
        $types = $row['groupTypes'] ?? [];
        $mail = $row['mailEnabled'] ?? null;
        $security = $row['securityEnabled'] ?? null;
        $row['groupType'] = match (true) {
            is_array($types) && in_array('Unified', $types, true) => 'Microsoft 365',
            $mail === true && $security === true => 'Mail-Enabled Security',
            $mail === false && $security === true => 'Security',
            $types === [] && $mail === true && $security === false => 'Distribution List',
            default => '',
        };

        return $row;
    }
}
