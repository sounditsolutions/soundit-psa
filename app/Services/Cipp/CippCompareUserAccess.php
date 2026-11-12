<?php

namespace App\Services\Cipp;

final class CippCompareUserAccess
{
    public const MAILBOX_LABEL = 'Observed grants only; CIPP cannot prove this list is complete, so a missing grant here is not evidence of absence.';

    private const RIGHTS = ['FullAccess', 'SendAs', 'SendOnBehalf'];

    public function __construct(private CippClient $client, private CippToolContract $contract) {}

    public function compare(string $tenant, array $input, ?int $clientId, ?float $deadline = null): array
    {
        $deadline ??= $this->client->readDeadline();
        $mailbox = null;
        $read = 'api/ListUsers';
        try {
            $users = [];
            $groups = [];
            $licences = [];
            foreach (['source', 'target'] as $side) {
                $requested = $input[$side.'_user'] ?? null;
                if (! is_string($requested) || trim($requested) === '') {
                    return ['error' => $side.'_user is required'];
                }
                $resolved = CippToolContract::resolveUserId(trim($requested), $clientId);
                // Invoke-ListUsers.ps1 selects assignedLicenses for UserID. Its fallback
                // can omit the property: require the array, never invent an empty one.
                $read = 'api/ListUsers';
                $rows = $this->read($read, ['tenantFilter' => $tenant, 'UserID' => $resolved], $deadline);
                if (count($rows) !== 1) {
                    throw new \UnexpectedValueException;
                }
                $user = $rows[0];
                if (! $this->id($user['id'] ?? null) || ! is_string($user['userPrincipalName'] ?? null)
                    || (strcasecmp($resolved, $user['id']) !== 0 && strcasecmp($resolved, $user['userPrincipalName']) !== 0)
                    || ! is_array($user['assignedLicenses'] ?? null) || ! array_is_list($user['assignedLicenses'])) {
                    throw new \UnexpectedValueException;
                }
                $users[$side] = $user;
                $licences[$side] = [];
                foreach ($user['assignedLicenses'] as $license) {
                    if (! is_array($license) || ! $this->id($license['skuId'] ?? null)) {
                        throw new \UnexpectedValueException;
                    }
                    $licences[$side][strtolower($license['skuId'])] = ['skuId' => $license['skuId']];
                }
                // Invoke-ListGraphRequest.ps1 / Get-GraphRequestList.ps1 follow all
                // pages by default. Retain the envelope and refuse residual cursors.
                $read = 'api/ListGraphRequest';
                $rows = $this->read($read, [
                    'tenantFilter' => $tenant,
                    'Endpoint' => 'users/'.$user['id'].'/memberOf/microsoft.graph.group',
                    '$select' => 'id,displayName,groupTypes,membershipRule,onPremisesSyncEnabled,mailEnabled,securityEnabled',
                ], $deadline);
                $groups[$side] = [];
                foreach ($rows as $row) {
                    if (! $this->id($row['id'] ?? null) || ! is_string($row['displayName'] ?? null) || trim($row['displayName']) === '') {
                        throw new \UnexpectedValueException;
                    }
                    $row = CippGroupMembershipPredicates::normalizeGraphRow($row);
                    $reason = CippGroupMembershipPredicates::notAddableReason($row);
                    $groups[$side][strtolower($row['id'])] = [
                        'id' => $row['id'],
                        'displayName' => $this->text($row['displayName'] ?? ''),
                        'type' => $row['groupType'],
                        'addable' => $reason === null,
                        'not_addable_reason' => $reason,
                    ];
                }
            }

            // Invoke-ListMailboxes.ps1 emits recipientTypeDetails and UPN (mixed casing).
            $read = 'api/ListMailboxes';
            $rows = $this->read($read, ['tenantFilter' => $tenant], $deadline);
            $mailboxes = [];
            foreach ($rows as $row) {
                $type = $row['recipientTypeDetails'] ?? $row['RecipientTypeDetails'] ?? null;
                if (! is_string($type)) {
                    throw new \UnexpectedValueException;
                }
                if (in_array(strtolower($type), ['sharedmailbox', 'roommailbox', 'equipmentmailbox'], true)) {
                    $address = $row['UPN'] ?? $row['UserPrincipalName'] ?? $row['primarySmtpAddress'] ?? null;
                    if (! is_string($address) || trim($address) === '') {
                        throw new \UnexpectedValueException;
                    }
                    $mailboxes[] = $address;
                }
            }
            if (count($mailboxes) > 50) {
                return ['error' => 'Mailbox fan-out refused: '.count($mailboxes).' mailboxes exceeds the cap of 50.'];
            }
            $observed = ['source' => [], 'target' => []];
            foreach ($mailboxes as $mailbox) {
                // Invoke-ListmailboxPermissions.ps1 emits User + Permissions; the
                // latter is an array for Trustee rows, a joined string for AccessRights.
                $read = 'api/ListmailboxPermissions';
                $permissions = $this->read($read, ['tenantFilter' => $tenant, 'userId' => $mailbox], $deadline);
                $sentinels = [];
                $rights = ['source' => [], 'target' => []];
                foreach ($permissions as $permission) {
                    if (! is_string($permission['User'] ?? null)) {
                        throw new \UnexpectedValueException;
                    }
                    $principal = trim($permission['User']);
                    $grants = $permission['Permissions'] ?? null;
                    if (is_string($grants)) {
                        $grants = array_map('trim', explode(',', $grants));
                    }
                    if (! is_array($grants) || ! array_is_list($grants) || count(array_filter($grants, 'is_string')) !== count($grants)) {
                        throw new \UnexpectedValueException;
                    }
                    $grants = array_values(array_intersect(self::RIGHTS, $grants));
                    if (strcasecmp($principal, 'NT AUTHORITY\\SELF') === 0) {
                        $sentinels = array_merge($sentinels, $grants);
                    }
                    if (str_starts_with(strtoupper($principal), 'NT AUTHORITY\\') || str_starts_with(strtoupper($principal), 'S-1-5-')) {
                        continue;
                    }
                    foreach ($users as $side => $user) {
                        if (strcasecmp($principal, $user['id']) === 0 || strcasecmp($principal, $user['userPrincipalName']) === 0) {
                            $rights[$side] = array_values(array_unique(array_merge($rights[$side], $grants)));
                        }
                    }
                }
                if (! in_array('FullAccess', $sentinels, true)) {
                    throw new \UnexpectedValueException;
                }
                if (! in_array('SendAs', $sentinels, true)) {
                    throw new \UnexpectedValueException;
                }
                foreach ($rights as $side => $grants) {
                    $observed[$side][] = ['mailbox' => $this->text($mailbox), 'rights' => $grants];
                }
            }
            $groupDiff = $this->diff($groups);
            $licenceDiff = $this->diff($licences);
            $advisory = [];
            foreach ($groupDiff['only_in_source'] as $group) {
                if ($group['addable']) {
                    $advisory[] = 'Consider adding group '.$group['id'].' ('.$group['displayName'].').';
                }
            }
            foreach ($licenceDiff['only_in_source'] as $license) {
                $advisory[] = 'Consider assigning licence '.$license['skuId'].'.';
            }

            return [
                'groups' => ['label' => 'direct membership only (nested groups not shown)'] + $groupDiff,
                'licences' => $licenceDiff,
                'mailbox_access' => [
                    'label' => self::MAILBOX_LABEL,
                    'mailboxes_observed' => count($mailboxes),
                    'scope_note' => $mailboxes === [] ? 'The mailbox listing returned no shared, room or equipment mailboxes.' : 'Shared, room and equipment mailboxes returned by the listing.',
                    'rights_checked' => [],
                    'rights_not_checked' => array_map(fn ($right) => ['right' => $right, 'reason' => 'completeness not provable from CIPP'], self::RIGHTS),
                    'mailbox_rights_observed' => $observed,
                ],
                'to_stage' => $advisory,
                'not_included' => ['admin roles'],
            ];
        } catch (\Throwable $e) {
            if ($e instanceof CippClientException) {
                return ['error' => mb_substr('CIPP query failed: '.$read.': '.$e->getMessage(), 0, 200)];
            }

            return ['error' => 'CIPP comparison failed'.($mailbox === null ? '' : ' for mailbox '.$this->text($mailbox)).': '.(int) $e->getCode().' '.class_basename($e)];
        }
    }

    private function id(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) === 1;
    }

    private function text(mixed $value): string
    {
        if (! is_string($value)) {
            throw new \UnexpectedValueException;
        }

        return $this->contract->fence('cipp_compare_user_access', 'displayName', $value);
    }

    private function diff(array $sides): array
    {
        return [
            'only_in_source' => array_values(array_diff_key($sides['source'], $sides['target'])),
            'only_in_target' => array_values(array_diff_key($sides['target'], $sides['source'])),
        ];
    }

    private function read(string $endpoint, array $query, float $deadline): array
    {
        $response = $this->client->getRawOnce($endpoint, $query, $deadline);
        $identity = json_decode($response['body'], false, 512, JSON_THROW_ON_ERROR);
        $body = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if ($response['status'] !== 200 || ! is_array($body)) {
            throw new \UnexpectedValueException('', $response['status']);
        }
        CippQueueGuard::assertNotQueueBacked($body);
        $this->assertNoCursor($body);
        $rows = $identity instanceof \stdClass ? ($identity->Results ?? null) : $identity;
        if (! is_array($rows)) {
            throw new \UnexpectedValueException('', $response['status']);
        }
        foreach ($rows as $row) {
            if (! $row instanceof \stdClass) {
                throw new \UnexpectedValueException('', $response['status']);
            }
            if ($endpoint === 'api/ListUsers' && ! is_array($row->assignedLicenses ?? null)) {
                throw new \UnexpectedValueException('', $response['status']);
            }
        }

        return $identity instanceof \stdClass ? $body['Results'] : $body;
    }

    private function assertNoCursor(array $body): void
    {
        foreach ($body as $key => $value) {
            if (($key === '@odata.nextLink' || $key === 'nextLink') && $value !== null && $value !== '') {
                throw new \UnexpectedValueException;
            }
            if (is_array($value)) {
                $this->assertNoCursor($value);
            }
        }
    }
}
