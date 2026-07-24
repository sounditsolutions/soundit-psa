<?php

namespace App\Services\Mcp;

use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Models\Client;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippClientException;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Cipp\CippWriteScopeException;
use App\Services\Cipp\CippWriteScopeResolver;
use App\Services\Cipp\ResolvedCippLicense;
use App\Services\Cipp\ResolvedCippPerson;
use App\Services\Cipp\ResolvedIntuneDevice;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Services\Technician\TechnicianApprovalResult;
use App\Support\CippConfig;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class StaffCippWriteToolExecutor
{
    private const DIRECT_DEDUP_HOURS = 24;

    /** @var array<string, string> */
    private const STAGED_TO_DIRECT = [
        'cipp_stage_reset_user_password' => 'cipp_reset_user_password',
        'cipp_stage_disable_user_sign_in' => 'cipp_disable_user_sign_in',
        'cipp_stage_enable_user_sign_in' => 'cipp_enable_user_sign_in',
        'cipp_stage_revoke_user_sessions' => 'cipp_revoke_user_sessions',
        'cipp_stage_remove_user_mfa_methods' => 'cipp_remove_user_mfa_methods',
        'cipp_stage_set_legacy_per_user_mfa' => 'cipp_set_legacy_per_user_mfa',
        'cipp_stage_assign_user_license' => 'cipp_assign_user_license',
        'cipp_stage_remove_user_license' => 'cipp_remove_user_license',
        'cipp_stage_convert_mailbox' => 'cipp_convert_mailbox',
        'cipp_stage_set_mailbox_forwarding' => 'cipp_set_mailbox_forwarding',
        'cipp_stage_set_mailbox_gal_visibility' => 'cipp_set_mailbox_gal_visibility',
        'cipp_stage_set_mailbox_out_of_office' => 'cipp_set_mailbox_out_of_office',
        'cipp_stage_set_mailbox_delegate' => 'cipp_set_mailbox_delegate',
        'cipp_stage_remove_directory_role' => 'cipp_remove_directory_role',
        'cipp_stage_release_quarantine_message' => 'cipp_release_quarantine_message',
        'cipp_stage_add_tenant_allow_entry' => 'cipp_add_tenant_allow_entry',
        'cipp_stage_wipe_device' => 'cipp_wipe_device',
        'cipp_stage_reassign_onedrive' => 'cipp_reassign_onedrive',
        'cipp_stage_create_user' => 'cipp_create_user',
        'cipp_stage_edit_user' => 'cipp_edit_user',
        'cipp_stage_set_group_membership' => 'cipp_set_group_membership',
    ];

    /**
     * Provisioning writes (bead psa-pbvy.1). These create a NEW upstream
     * identity, so there is no person_id to resolve — they run through their
     * own context/stage/approve path where the tenant AND the new UPN's
     * domain are both server-derived from the client's CIPP tenant mapping,
     * and the CIPP-generated temp password is delivered exactly once (tool
     * result on the immediate path, cockpit approval response on the staged
     * path) and never stored or audited.
     *
     * @var array<int, string>
     */
    private const PROVISIONING_TOOLS = [
        'cipp_create_user',
    ];

    /**
     * Email-security remediation writes (bead psa-t08l). These act on
     * tenant-level Exchange objects, not on one mapped person, so they run
     * through their own context/stage/approve path: no person_id resolution,
     * and the quarantine release replaces it with a server-side verification
     * read (the identity must be present in the resolved tenant's live
     * quarantine listing before anything is staged or executed).
     *
     * @var array<int, string>
     */
    private const EMAIL_SECURITY_TOOLS = [
        'cipp_release_quarantine_message',
        'cipp_add_tenant_allow_entry',
    ];

    /**
     * Group-membership writes (bead psa-pbvy.3). Person-scoped like the
     * delegate tool, but the GROUP half of the target lives upstream only, so
     * these run through their own context/stage/approve path: the group is
     * verified against the resolved tenant's LIVE CIPP group listing
     * (quarantine-release precedent) on the direct path, at staging, and
     * again fresh at approval — deriving the group name and type server-side
     * and refusing dynamic-membership, on-prem-synced, and unrecognized-type
     * groups before anything reaches upstream. Adds to security-privileged
     * types (PRIVILEGED_GROUP_TYPES) are held-only on top of that.
     *
     * @var array<int, string>
     */
    private const GROUP_MEMBERSHIP_TOOLS = [
        'cipp_set_group_membership',
    ];

    /** @var array<string, int> */
    private const COOLDOWNS = [
        'cipp_disable_user_sign_in' => 300,
        'cipp_stage_disable_user_sign_in' => 300,
        'cipp_enable_user_sign_in' => 300,
        'cipp_stage_enable_user_sign_in' => 300,
        'cipp_revoke_user_sessions' => 300,
        'cipp_stage_revoke_user_sessions' => 300,
        'cipp_remove_user_mfa_methods' => 300,
        'cipp_stage_remove_user_mfa_methods' => 300,
        'cipp_set_legacy_per_user_mfa' => 300,
        'cipp_stage_set_legacy_per_user_mfa' => 300,
        'cipp_assign_user_license' => 300,
        'cipp_stage_assign_user_license' => 300,
        'cipp_remove_user_license' => 300,
        'cipp_stage_remove_user_license' => 300,
        'cipp_convert_mailbox' => 300,
        'cipp_stage_convert_mailbox' => 300,
        'cipp_set_mailbox_forwarding' => 300,
        'cipp_stage_set_mailbox_forwarding' => 300,
        'cipp_set_mailbox_gal_visibility' => 300,
        'cipp_stage_set_mailbox_gal_visibility' => 300,
        'cipp_set_mailbox_out_of_office' => 300,
        'cipp_stage_set_mailbox_out_of_office' => 300,
        'cipp_set_mailbox_delegate' => 300,
        'cipp_stage_set_mailbox_delegate' => 300,
        'cipp_remove_directory_role' => 300,
        'cipp_stage_remove_directory_role' => 300,
        'cipp_release_quarantine_message' => 300,
        'cipp_stage_release_quarantine_message' => 300,
        'cipp_add_tenant_allow_entry' => 300,
        'cipp_stage_add_tenant_allow_entry' => 300,
        'cipp_wipe_device' => 300,
        'cipp_stage_wipe_device' => 300,
        'cipp_reassign_onedrive' => 300,
        'cipp_stage_reassign_onedrive' => 300,
        'cipp_reset_user_password' => 300,
        'cipp_create_user' => 300,
        'cipp_stage_create_user' => 300,
        'cipp_edit_user' => 300,
        'cipp_stage_edit_user' => 300,
        'cipp_set_group_membership' => 300,
        'cipp_stage_set_group_membership' => 300,
    ];

    private const OOO_MESSAGE_MAX = 2000;

    /** @var array<int, string> */
    private const MAILBOX_TYPES = ['Shared', 'Regular', 'Room', 'Equipment'];

    /** @var array<int, string> */
    private const DIRECT_FORWARDING_MODES = ['disabled', 'internal'];

    /** @var array<int, string> */
    private const STAGED_FORWARDING_MODES = ['disabled', 'internal', 'external'];

    /** @var array<int, string> */
    private const OOO_STATES = ['Disabled', 'Enabled', 'Scheduled'];

    /** @var array<int, string> */
    private const DELEGATE_PERMISSIONS = ['full_access', 'send_as', 'send_on_behalf'];

    /** @var array<int, string> */
    private const DELEGATE_OPERATIONS = ['grant', 'remove'];

    /** @var array<int, string> */
    private const GROUP_MEMBERSHIP_OPERATIONS = ['add', 'remove'];

    /**
     * The group types CIPP's own ListGroups projection derives (source:
     * CIPP-API Invoke-ListGroups.ps1 groupType expression). These exact
     * strings route Invoke-EditGroup's Exchange-vs-Graph arms, so anything
     * else fails closed rather than guessing an upstream routing arm.
     *
     * @var array<int, string>
     */
    private const GROUP_TYPES = ['Microsoft 365', 'Mail-Enabled Security', 'Security', 'Distribution List'];

    /**
     * Group types whose ADD is structurally held-only (external-forwarding /
     * device-wipe precedent): security-enabled membership is an access grant
     * to whatever the group gates, and CIPP's ListGroups projection carries
     * no isAssignableToRole — an add to a role-assignable group (an Entra
     * ADMIN ROLE grant) is indistinguishable from an ordinary security-group
     * add at verification time. So adds to these VERIFIED types never execute
     * immediately, whatever mode was granted; only the staged path (a human
     * cockpit approval) reaches upstream. REMOVES are revocation and stay
     * immediate-capable, as do adds to the remaining (collaboration) types.
     *
     * @var array<int, string>
     */
    private const PRIVILEGED_GROUP_TYPES = ['Security', 'Mail-Enabled Security'];

    private const GROUP_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const GROUP_NAME_MAX = 256;

    /** @var array<int, string> */
    private const WIPE_ACTIONS = ['wipe', 'retire'];

    private const ROLE_TEMPLATE_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const ROLE_NAME_MAX = 200;

    /** @var array<int, string> */
    private const ALLOW_LIST_TYPES = ['Sender', 'Url'];

    private const ALLOW_ENTRY_MAX = 250;

    private const QUARANTINE_IDENTITY_MAX = 200;

    private const QUARANTINE_SUBJECT_PREVIEW_MAX = 120;

    /** Entra UPN limits: local part ≤ 64 chars, whole UPN ≤ 113 chars. */
    private const CREATE_USERNAME_MAX = 64;

    private const CREATE_UPN_MAX = 113;

    private const CREATE_DISPLAY_NAME_MAX = 256;

    private const CREATE_NAME_MAX = 64;

    /**
     * Conservative UPN-local-part / mailNickname allowlist: alphanumeric with
     * interior dots, underscores, and hyphens, never leading/trailing a
     * separator. CIPP reuses the username as BOTH the UPN local part and the
     * Exchange mailNickname, so this stays inside the stricter alias rules.
     */
    private const CREATE_USERNAME_PATTERN = '/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/i';

    /**
     * Editable profile / directory attribute allowlist for cipp_edit_user —
     * tool argument (snake_case) → [upstream UserObj key, max length]. Settled
     * from the CIPP edit-user form (CIPP src/components/CippFormPages/
     * CippAddEditUser.jsx validators) intersected with the Graph PATCH body
     * Set-CIPPUser.ps1 actually builds; country has no form bound, so it takes
     * Graph's own 128 limit. officeLocation is absent DELIBERATELY — the CIPP
     * edit path does not carry it at all. otherMails and aliases are excluded
     * from this AI-facing surface (account-recovery / mail-routing adjacent);
     * passwords, licenses, and group membership have their own curated tools.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private const EDIT_FIELDS = [
        'display_name' => ['displayName', 256],
        'given_name' => ['givenName', 64],
        'surname' => ['surname', 64],
        'job_title' => ['jobTitle', 128],
        'department' => ['department', 64],
        'company_name' => ['companyName', 64],
        'street_address' => ['streetAddress', 1024],
        'city' => ['city', 128],
        'state' => ['state', 128],
        'postal_code' => ['postalCode', 40],
        'country' => ['country', 128],
        'mobile_phone' => ['mobilePhone', 64],
        'business_phone' => ['businessPhones', 64],
        'usage_location' => ['usageLocation', 2],
    ];

    /**
     * Fields an edit may explicitly CLEAR — the intersection of EDIT_FIELDS
     * with Set-CIPPUser.ps1's own $ClearableFields whitelist. display_name is
     * upstream-refused (Graph rejects a null display name) and usage_location
     * is not clearable through the vendor path at all.
     *
     * @var array<int, string>
     */
    private const EDIT_CLEARABLE = [
        'given_name',
        'surname',
        'job_title',
        'department',
        'company_name',
        'street_address',
        'city',
        'state',
        'postal_code',
        'country',
        'mobile_phone',
        'business_phone',
    ];

    /** @var array<int, string> */
    private const UPSTREAM_IDENTIFIER_KEYS = [
        'tenantFilter',
        'TenantFilter',
        'tenant_filter',
        'tenant',
        'tenant_domain',
        'cipp_tenant_domain',
        'customerId',
        'customer_id',
        'ID',
        'id',
        'userId',
        'userID',
        'UserID',
        'userPrincipalName',
        'Username',
        'upstream_user_id',
        'cipp_user_id',
        'cipp_upn',
        'skuId',
        'sku_id',
        'licenseSku',
        'license_sku',
        'licenseSkuId',
        'Licenses',
        'LicensesToRemove',
        'LicenseOperation',
        'RemoveAllLicenses',
        'ReplaceAllLicenses',
        'removeAllLicenses',
        'replaceAllLicenses',
        'mailbox',
        'mailbox_id',
        'mailbox_identity',
        'MailboxType',
        'ForwardInternal',
        'ForwardExternal',
        'forwardOption',
        'KeepCopy',
        'HideFromGAL',
        'AutoReplyState',
        'AddFullAccess',
        'AddFullAccessNoAutoMap',
        'RemoveFullAccess',
        'AddSendAs',
        'RemoveSendAs',
        'AddSendOnBehalf',
        'RemoveSendOnBehalf',
        'RoleId',
        'roleId',
        'role_id',
        'RoleName',
        'roleName',
        'Users',
        'users',
        'GUID',
        'guid',
        'Action',
        'action',
        'device_id',
        'm365_device_id',
        'intune_device_id',
        'UPN',
        'upn',
        'onedriveAccessUser',
        'OnedriveAccessUser',
        'onedrive_access_user',
        'RemovePermission',
        'removePermission',
        'URL',
        'StartTime',
        'EndTime',
        'target_upn',
        'target_user_id',
        'Identity',
        'Identities',
        'Type',
        'ReleaseToAll',
        'AllowSender',
        'SenderAddress',
        'RecipientAddress',
        'PolicyName',
        'tenantID',
        'entries',
        'Entries',
        'listType',
        'ListType',
        'listMethod',
        'ListMethod',
        'NoExpiration',
        'RemoveAfter',
        'notes',
        'Notes',
        'Domain',
        'PrimDomain',
        'displayName',
        'DisplayName',
        'givenName',
        'GivenName',
        'usageLocation',
        'UsageLocation',
        'mailNickname',
        'MustChangePass',
        'mustChangePass',
        'password',
        'Password',
        'licenses',
        'AddedAliases',
        'copyFrom',
        'CopyFrom',
        'AddToGroups',
        'setManager',
        'setSponsor',
        'Scheduled',
        'otherMails',
        'sherwebLicense',
        'defaultAttributes',
        'customData',
        'PostExecution',
        'jobTitle',
        'mobilePhone',
        'streetAddress',
        'postalCode',
        'companyName',
        'businessPhones',
        'clearProperties',
        'removeLicenses',
        'RemoveFromGroups',
        'groupId',
        'GroupId',
        'groupID',
        'groupType',
        'GroupType',
        'groupName',
        'GroupName',
        'AddMember',
        'RemoveMember',
        'AddOwner',
        'RemoveOwner',
        'AddContact',
        'RemoveContact',
        'Member',
        'Members',
        'membershipRules',
        'tenantId',
        'endpoint',
        'Endpoint',
        'cipp_endpoint',
        'body',
        'request',
    ];

    public function __construct(
        private readonly CippRestWriteClient $client,
        private readonly CippWriteScopeResolver $resolver,
        private readonly ActionRedactor $redactor,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            self::disableSignInTool(),
            self::stageDisableSignInTool(),
            self::enableSignInTool(),
            self::stageEnableSignInTool(),
            self::revokeSessionsTool(),
            self::stageRevokeSessionsTool(),
            self::removeMfaTool(),
            self::stageRemoveMfaTool(),
            self::setLegacyMfaTool(),
            self::stageSetLegacyMfaTool(),
            self::assignLicenseTool(),
            self::stageAssignLicenseTool(),
            self::removeLicenseTool(),
            self::stageRemoveLicenseTool(),
            self::convertMailboxTool(),
            self::stageConvertMailboxTool(),
            self::setMailboxForwardingTool(),
            self::stageSetMailboxForwardingTool(),
            self::setMailboxGalVisibilityTool(),
            self::stageSetMailboxGalVisibilityTool(),
            self::setMailboxOutOfOfficeTool(),
            self::stageSetMailboxOutOfOfficeTool(),
            self::setMailboxDelegateTool(),
            self::stageSetMailboxDelegateTool(),
            self::removeDirectoryRoleTool(),
            self::stageRemoveDirectoryRoleTool(),
            self::releaseQuarantineMessageTool(),
            self::stageReleaseQuarantineMessageTool(),
            self::addTenantAllowEntryTool(),
            self::stageAddTenantAllowEntryTool(),
            self::wipeDeviceTool(),
            self::stageWipeDeviceTool(),
            self::reassignOneDriveTool(),
            self::stageReassignOneDriveTool(),
            self::resetUserPasswordTool(),
            self::stageResetUserPasswordTool(),
            self::createUserTool(),
            self::stageCreateUserTool(),
            self::editUserTool(),
            self::stageEditUserTool(),
            self::setGroupMembershipTool(),
            self::stageSetGroupMembershipTool(),
        ];
    }

    /** @return array<int, string> */
    public static function toolNames(): array
    {
        return array_column(self::definitions(), 'name');
    }

    public static function handles(string $toolName): bool
    {
        return in_array($toolName, self::toolNames(), true);
    }

    public static function requiresClient(string $toolName): bool
    {
        return self::handles($toolName);
    }

    public static function isStagedActionType(string $actionType): bool
    {
        return array_key_exists($actionType, self::STAGED_TO_DIRECT);
    }

    /** @return array<string, string> */
    public static function stagedToDirectMap(): array
    {
        return self::STAGED_TO_DIRECT;
    }

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel): array
    {
        if (! CippConfig::isEnabled() || ! CippConfig::isConfigured()) {
            return ['error' => 'CIPP is not enabled or configured'];
        }

        // Password reset keeps a DEDICATED pair of paths rather than falling through to
        // the generic stage/direct tail — but it is now shaped like every other family
        // (psa-g4y9f). The direct executor must stay bespoke because a reset is
        // NON-IDEMPOTENT: executeDirect()'s alreadyExecuted() short-circuit would answer
        // a repeat reset with {success, idempotent} and NO PASSWORD, which is a silent
        // failure on a credential-issuing operation. The staging half is what was
        // missing: without it the capability had no staged twin at all, so a ':staged'
        // grant had nothing to dispatch to and the mode gate never engaged.
        if ((self::STAGED_TO_DIRECT[$name] ?? $name) === 'cipp_reset_user_password') {
            return isset(self::STAGED_TO_DIRECT[$name])
                ? $this->stageResetPasswordAction($name, $arguments, $clientId, $actorLabel)
                : $this->executeResetPassword($name, $arguments, $clientId, $actorLabel);
        }

        if (in_array(self::STAGED_TO_DIRECT[$name] ?? $name, self::PROVISIONING_TOOLS, true)) {
            return isset(self::STAGED_TO_DIRECT[$name])
                ? $this->stageCreateUserAction($name, $arguments, $clientId, $actorLabel)
                : $this->executeCreateUserDirect($name, $arguments, $clientId, $actorLabel);
        }

        if (in_array(self::STAGED_TO_DIRECT[$name] ?? $name, self::EMAIL_SECURITY_TOOLS, true)) {
            return isset(self::STAGED_TO_DIRECT[$name])
                ? $this->stageEmailSecurityAction($name, $arguments, $clientId, $actorLabel)
                : $this->executeEmailSecurityDirect($name, $arguments, $clientId, $actorLabel);
        }

        if (in_array(self::STAGED_TO_DIRECT[$name] ?? $name, self::GROUP_MEMBERSHIP_TOOLS, true)) {
            return isset(self::STAGED_TO_DIRECT[$name])
                ? $this->stageGroupMembershipAction($name, $arguments, $clientId, $actorLabel)
                : $this->executeGroupMembershipDirect($name, $arguments, $clientId, $actorLabel);
        }

        if (isset(self::STAGED_TO_DIRECT[$name])) {
            return $this->stageAction($name, $arguments, $clientId, $actorLabel);
        }

        return $this->executeDirect($name, $arguments, $clientId, $actorLabel);
    }

    public function approveStagedRun(TechnicianRun $run, int $approverId, array $approvalInputs = []): TechnicianApprovalResult
    {
        if (! self::isStagedActionType($run->action_type) || ! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        // Password reset needs its own approve path for the same reason it needs its own
        // direct path: it reads a CREDENTIAL back from upstream. The generic tail calls
        // executeUpstream(), which returns void, so the temp password would be minted and
        // then dropped. TechnicianApprovalResult::$secret is the existing one-time
        // delivery channel (built for cipp_create_user) — the credential reaches the
        // approving human and is never stored, logged, or audited.
        if ((self::STAGED_TO_DIRECT[$run->action_type] ?? '') === 'cipp_reset_user_password') {
            return $this->approveResetPasswordStagedRun($run, $approverId);
        }

        if (in_array(self::STAGED_TO_DIRECT[$run->action_type] ?? '', self::PROVISIONING_TOOLS, true)) {
            return $this->approveCreateUserStagedRun($run, $approverId);
        }

        if (in_array(self::STAGED_TO_DIRECT[$run->action_type] ?? '', self::EMAIL_SECURITY_TOOLS, true)) {
            return $this->approveEmailSecurityStagedRun($run, $approverId);
        }

        if (in_array(self::STAGED_TO_DIRECT[$run->action_type] ?? '', self::GROUP_MEMBERSHIP_TOOLS, true)) {
            return $this->approveGroupMembershipStagedRun($run, $approverId);
        }

        try {
            $payload = $this->decryptRunPayload($run);
            if ($payload === null) {
                $run->releaseClaim();

                return $this->declined('The held payload could not be read; deny this proposal and re-stage it.');
            }

            $directTool = (string) ($payload['direct_tool'] ?? '');
            if ((self::STAGED_TO_DIRECT[$run->action_type] ?? null) !== $directTool) {
                $run->releaseClaim();

                return $this->declined('The held payload does not match this action type; deny this proposal and re-stage it.');
            }

            $client = Client::find((int) ($payload['client_id'] ?? 0));
            if (! $client || (int) $client->id !== (int) $run->client_id) {
                $run->releaseClaim();

                return $this->declined('The proposal\'s client could not be re-verified; deny this proposal and re-stage it.');
            }

            $tenant = $this->resolver->resolveCippTenant($client);
            $person = $this->resolver->resolveCippPerson($client->id, $payload['person_id'] ?? null);
            $ticket = $this->resolver->resolveTicketForHeldAction($client->id, $payload['ticket_id'] ?? null);
            $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
            $license = $this->licenseForTool($directTool, $client->id, $params['license_type_id'] ?? null);
            $state = $this->stateForTool($directTool, $params['state'] ?? null);
            $mailbox = $this->mailboxParamsForTool($directTool, $client->id, $params, $approvalInputs, heldApproval: true, person: $person);

            if (TechnicianConfig::killSwitchEngaged()) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $person, $license, $run->content_hash, 'Technician kill-switch engaged; staged CIPP write refused.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('Technician kill-switch engaged; the staged CIPP write was refused.');
            }

            // A re-fired approval of an already-executed device wipe/retire is a
            // LOGGED NO-OP, never a second upstream action (bead psa-zjpd). Keyed
            // on the device identity rather than the content hash so a duplicate
            // staged from a different ticket can never double-wipe. Checked before
            // the cooldown so the duplicate leaves the queue terminally (Done)
            // instead of bouncing back as a declined-but-still-live proposal.
            if ($directTool === 'cipp_wipe_device' && is_array($mailbox)) {
                $stagedDeviceId = (string) ($mailbox['staged_device_id'] ?? '');
                $wipeAction = (string) ($mailbox['wipe_action'] ?? '');
                if ($stagedDeviceId !== '' && $wipeAction !== '' && $this->deviceWipeAlreadyExecuted($client->id, $stagedDeviceId, $wipeAction)) {
                    $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $person, $license, $run->content_hash, "Duplicate device action suppressed: device {$stagedDeviceId} ({$wipeAction}) already executed within ".self::DIRECT_DEDUP_HOURS.'h; the approval was treated as a logged no-op.', $this->approverLabel($approverId), $run->id, $approverId);
                    $run->advanceTo(TechnicianRunState::Done);

                    return new TechnicianApprovalResult('already_handled');
                }
            }

            if ($this->cooldownActive($directTool, $client->id, $person, $license, self::COOLDOWNS[$directTool] ?? 300)) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $person, $license, $run->content_hash, 'CIPP staged action cooldown active; approval refused before upstream call.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('A recent action for this target is still in cooldown; wait a few minutes and approve again.');
            }

            try {
                $this->executeUpstream($directTool, $tenant, $person, $license, $state, $mailbox);
            } catch (CippClientException $e) {
                $this->auditAttempt($run->action_type, 'error', $client->id, $ticket, $person, $license, $run->content_hash, $this->safeFailureSummary($run->action_type, $e), $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined($e->getMessage());
            }

            $this->auditAttempt($run->action_type, 'executed', $client->id, $ticket, $person, $license, $run->content_hash, "Operator-approved {$run->action_type} executed.".$this->executedAuditSuffix($directTool, $mailbox), $this->approverLabel($approverId), $run->id, $approverId);
            $run->advanceTo(TechnicianRunState::Done);

            return new TechnicianApprovalResult('executed');
        } catch (CippWriteScopeException $e) {
            $run->releaseClaim();

            return $this->declined($e->getMessage());
        } catch (\Throwable $e) {
            $run->releaseClaim();

            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function executeDirect(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->context($tool, $arguments, $clientId, $actorLabel, requireTicket: false);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var string $tenant */
        $tenant = $context['tenant'];
        /** @var ResolvedCippPerson $person */
        $person = $context['person'];
        /** @var Ticket|null $ticket */
        $ticket = $context['ticket'];
        /** @var ResolvedCippLicense|null $license */
        $license = $context['license'];
        $state = is_string($context['state'] ?? null) ? $context['state'] : null;
        $mailbox = is_array($context['mailbox'] ?? null) ? $context['mailbox'] : null;
        $reason = (string) $context['reason'];

        $contentHash = $this->contentHash($tool, $client->id, $person->person->id, $ticket?->id, $this->hashParams($tool, $license, $state, $mailbox));

        if ($this->alreadyExecuted($tool, $client->id, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, $person, $license, $contentHash, "Duplicate {$tool} suppressed before upstream call.", $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Already executed identical CIPP write recently; no upstream call was made.',
            ];
        }

        if ($this->cooldownActive($tool, $client->id, $person, $license, self::COOLDOWNS[$tool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, $person, $license, $contentHash, "{$tool} cooldown active; upstream call refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no upstream call was made."];
        }

        try {
            $this->executeUpstream($tool, $tenant, $person, $license, $state, $mailbox);
        } catch (CippClientException $e) {
            $this->auditAttempt($tool, 'error', $client->id, $ticket, $person, $license, $contentHash, $this->safeFailureSummary($tool, $e), $actorLabel);

            return ['error' => "CIPP write failed for {$tool}; no response body returned."];
        }

        $this->auditAttempt($tool, 'executed', $client->id, $ticket, $person, $license, $contentHash, "{$tool} executed: {$reason}", $actorLabel);

        return [
            'success' => true,
            'tool' => $tool,
            'person_id' => $person->person->id,
            'ticket_id' => $ticket?->id,
            'message' => 'CIPP action executed.',
        ];
    }

    /**
     * Dedicated direct path for the password reset — the only cipp_write tool that reads
     * back an upstream value (the temp password). Reuses every context() gate; skips the
     * idempotent alreadyExecuted() short-circuit (a password reset is NON-idempotent — a
     * second reset must generate a new password, not return a stale "already done"). A
     * cooldown still guards runaway repeats. The credential lives ONLY in the returned
     * result; auditAttempt() records the action + target UPN, never the password.
     *
     * @return array<string, mixed>
     */
    private function executeResetPassword(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->context($tool, $arguments, $clientId, $actorLabel, requireTicket: false);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var string $tenant */
        $tenant = $context['tenant'];
        /** @var ResolvedCippPerson $person */
        $person = $context['person'];
        /** @var Ticket|null $ticket */
        $ticket = $context['ticket'];
        $reason = (string) $context['reason'];

        try {
            $mustChange = array_key_exists('must_change', $arguments)
                ? $this->booleanValue($arguments['must_change'], 'must_change')
                : true;
        } catch (CippWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $client->id, $ticket, $person, null, $this->contentHash($tool, $client->id, $person->person->id, $ticket?->id, []), $e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        $contentHash = $this->contentHash($tool, $client->id, $person->person->id, $ticket?->id, ['must_change' => $mustChange]);

        // Shared across both paths: a held approval audits under the STAGED name, so a
        // single-name lookup would miss it here (security review psa-eerg4 R2).
        if ($this->resetCooldownActive($client->id, $person, self::COOLDOWNS[$tool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, $person, null, $contentHash, "{$tool} cooldown active; upstream call refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no reset was performed. Wait before retrying a password reset."];
        }

        try {
            $upstream = $this->client->resetUserPassword($tenant, $person->userPrincipalName, $mustChange);
        } catch (CippClientException $e) {
            $this->auditAttempt($tool, 'error', $client->id, $ticket, $person, null, $contentHash, $this->safeFailureSummary($tool, $e), $actorLabel);

            return ['error' => "CIPP password reset failed for {$tool}; no password was returned."];
        }

        // Audit records the action + target + the EFFECTIVE must_change flag (a boolean, not a
        // credential) so the immutable log distinguishes a temp reset from a permanent one. NO password.
        $mustChangeLabel = $mustChange ? 'true' : 'false';
        $this->auditAttempt($tool, 'executed', $client->id, $ticket, $person, null, $contentHash, "{$tool} executed (must_change={$mustChangeLabel}): {$reason}", $actorLabel);

        $results = is_array($upstream['body']['Results'] ?? null) ? $upstream['body']['Results'] : [];
        $password = (isset($results['copyField']) && is_string($results['copyField']) && $results['copyField'] !== '')
            ? $results['copyField']
            : null;
        $state = isset($results['state']) && is_string($results['state']) ? $results['state'] : null;

        if ($password === null) {
            return [
                'success' => true,
                'tool' => $tool,
                'person_id' => $person->person->id,
                'password_returned' => false,
                'message' => 'CIPP reported a successful reset but returned no password value. Verify in CIPP; if PwPush is configured the value may be delivered as a link instead.',
            ];
        }

        $adSynced = $state === 'warning';

        return [
            'success' => true,
            'tool' => $tool,
            'person_id' => $person->person->id,
            'user_principal_name' => $person->userPrincipalName,
            'temporary_password' => $password,
            'must_change_at_next_logon' => $mustChange,
            'ad_synced_warning' => $adSynced,
            'message' => 'Temporary password generated. Relay it to the user over a secure channel and instruct them to change it at first sign-in.'
                .($adSynced ? ' WARNING: this account appears to be directory-synced (AD-synced); a cloud password reset may not take effect if on-prem Active Directory is authoritative — verify with the on-prem/hybrid identity source.' : ''),
            'guidance' => 'If your CIPP instance has PwPush enabled, the temporary_password value may be a one-time secure link rather than the literal password.',
        ];
    }

    /** @return array<string, mixed> */
    /**
     * Approve a held password reset (psa-g4y9f). Mints the credential ON APPROVAL and
     * hands it to the approving human via TechnicianApprovalResult::$secret — the same
     * one-time channel cipp_create_user uses. The agent that staged the proposal never
     * sees it, which is the security improvement over the immediate path.
     *
     * The run was already claimed by approveStagedRun(), so a second approval of the
     * same run is short-circuited there as already_handled — that is what preserves
     * non-idempotency correctly: one approval, one new password.
     */
    private function approveResetPasswordStagedRun(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        try {
            $payload = $this->decryptRunPayload($run);
            if ($payload === null) {
                $run->releaseClaim();

                return $this->declined('The held payload could not be read; deny this proposal and re-stage it.');
            }

            $directTool = (string) ($payload['direct_tool'] ?? '');
            if ((self::STAGED_TO_DIRECT[$run->action_type] ?? null) !== $directTool) {
                $run->releaseClaim();

                return $this->declined('The held payload does not match this action type; deny this proposal and re-stage it.');
            }

            $client = Client::find((int) ($payload['client_id'] ?? 0));
            if (! $client || (int) $client->id !== (int) $run->client_id) {
                $run->releaseClaim();

                return $this->declined('The proposal\'s client could not be re-verified; deny this proposal and re-stage it.');
            }

            // Scope is re-resolved at approval time, never trusted from the payload.
            $tenant = $this->resolver->resolveCippTenant($client);
            $person = $this->resolver->resolveCippPerson($client->id, $payload['person_id'] ?? null);
            $ticket = $this->resolver->resolveTicketForHeldAction($client->id, $payload['ticket_id'] ?? null);

            $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
            $mustChange = (bool) ($params['must_change'] ?? true);
            $contentHash = $this->contentHash($run->action_type, $client->id, $person->person->id, $ticket?->id, $params);

            // APPROVAL-TIME SAFETY GATES — these are NOT redundant with the ones the
            // staging call already passed (security review psa-smh26 R1). A proposal can
            // sit held for hours: the kill-switch may have been engaged since it was
            // staged, and another reset for the same person may have run in the
            // meantime. Approving without re-checking would let a stale proposal punch
            // straight through an active emergency stop — on a credential-changing
            // operation. Mirrors the generic staged tail.
            if (TechnicianConfig::killSwitchEngaged()) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $person, null, $contentHash, 'Technician kill-switch engaged; staged password reset refused.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('Technician kill-switch engaged; the staged password reset was refused.');
            }

            if ($this->resetCooldownActive($client->id, $person, self::COOLDOWNS[$directTool] ?? 300)) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $person, null, $contentHash, 'Password reset cooldown active for this target; approval refused before upstream call.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('This user\'s password was reset very recently; wait a few minutes and approve again if a new password is still needed.');
            }

            try {
                $upstream = $this->client->resetUserPassword($tenant, $person->userPrincipalName, $mustChange);
            } catch (CippClientException $e) {
                $run->releaseClaim();
                $this->auditAttempt($run->action_type, 'error', $client->id, $ticket, $person, null, $contentHash, $this->safeFailureSummary($run->action_type, $e), $this->approverLabel($approverId), $run->id, $approverId);

                return $this->declined('CIPP password reset failed; no password was returned. The proposal is still open — retry or deny it.');
            }

            $results = is_array($upstream['body']['Results'] ?? null) ? $upstream['body']['Results'] : [];
            $password = (isset($results['copyField']) && is_string($results['copyField']) && $results['copyField'] !== '')
                ? $results['copyField']
                : null;

            // must_change is a boolean, not a credential — safe to audit. The password
            // is NEVER written here (mirrors executeResetPassword).
            $mustChangeLabel = $mustChange ? 'true' : 'false';
            $this->auditAttempt($run->action_type, 'executed', $client->id, $ticket, $person, null, $contentHash, "Operator-approved {$run->action_type} executed (must_change={$mustChangeLabel}) for {$person->userPrincipalName}. Temp password delivered once to the approver; never stored.", $this->approverLabel($approverId), $run->id, $approverId);
            $run->advanceTo(TechnicianRunState::Done);

            $message = 'Reset the Microsoft 365 password for '.$person->userPrincipalName.'.';
            $message .= $password !== null
                ? ' The temporary password is shown once here and never stored — relay it over a secure channel.'
                : ' CIPP reported success but returned no password value; if PwPush is configured the credential may be delivered as a link — verify in CIPP.';

            return new TechnicianApprovalResult(
                'executed',
                message: mb_substr($this->redactor->redactString($message), 0, 500),
                secret: $password,
            );
        } catch (CippWriteScopeException $e) {
            $run->releaseClaim();

            return $this->declined($e->getMessage());
        } catch (\Throwable $e) {
            // Anything unexpected — a Crypt decrypt failure, an audit write failure —
            // must return the run to the approval queue rather than stranding it in
            // Executing, where no one can approve or deny it. Mirrors the generic tail
            // and approveCreateUserStagedRun() (arch review psa-oqfc1 R1).
            $run->releaseClaim();

            throw $e;
        }
    }

    /**
     * Staged path for the password reset (psa-g4y9f). Mirrors stageAction() with two
     * deliberate differences, both forced by the fact that a reset mints a credential:
     *
     *  1. NO alreadyExecuted() short-circuit. stageAction() answers a repeat with
     *     "Already executed identical action recently; no new proposal was staged" —
     *     correct for an idempotent write, WRONG here: a second reset request after one
     *     already executed must be allowed to stage a fresh proposal, because the point
     *     of a reset is to mint a NEW password. The liveAwaitingRun() dedupe and the
     *     proposal cooldown are kept — an identical proposal still pending approval is
     *     genuinely the same ask, and the cooldown still stops runaway staging.
     *  2. must_change rides in the held payload, so approval executes the operator's
     *     reviewed intent rather than re-reading a default.
     *
     * No password exists at staging time and none is stored. The credential is minted
     * on approval and shown to the approving human — never returned to the agent.
     *
     * @return array<string, mixed>
     */
    private function stageResetPasswordAction(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        // requireTicket: TRUE — a proposal hangs off a ticket, unlike the direct path.
        $context = $this->context($tool, $arguments, $clientId, $actorLabel, requireTicket: true);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var ResolvedCippPerson $person */
        $person = $context['person'];
        /** @var Ticket $ticket */
        $ticket = $context['ticket'];
        $reason = (string) $context['reason'];
        $directTool = self::STAGED_TO_DIRECT[$tool];

        try {
            $mustChange = array_key_exists('must_change', $arguments)
                ? $this->booleanValue($arguments['must_change'], 'must_change')
                : true;
        } catch (CippWriteScopeException $e) {
            return ['error' => $e->getMessage()];
        }

        $params = ['must_change' => $mustChange];
        $contentHash = $this->contentHash($tool, $client->id, $person->person->id, $ticket->id, $params);

        // Deliberately NO alreadyExecuted() check here — see the docblock.
        $liveAwaitingRun = $this->liveAwaitingRun($ticket->id, $tool, $contentHash);
        if ($liveAwaitingRun !== null) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $liveAwaitingRun->id,
                'message' => 'Already staged; awaiting approval.',
            ];
        }

        if ($this->proposalCooldownActive($tool, $ticket, $person, null, self::COOLDOWNS[$directTool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, $person, null, $contentHash, "{$tool} cooldown active; staged proposal refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no proposal was staged."];
        }

        $meta = [
            'drafted_by' => $actorLabel,
            'reasons' => [$reason],
            'direct_tool' => $directTool,
            'person_id' => $person->person->id,
            'license_type_id' => null,
            'redacted_params' => $params,
            'sensitive_inputs' => $this->sensitiveInputsForStagedAction($directTool, $params),
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => $directTool,
                'client_id' => $client->id,
                'person_id' => $person->person->id,
                'ticket_id' => $ticket->id,
                'params' => $params,
            ], JSON_THROW_ON_ERROR)),
        ];

        $mustChangeLabel = $mustChange ? 'yes' : 'no';
        $proposedContent = "Reset the Microsoft 365 password for {$person->userPrincipalName}"
            ." (must change at next sign-in: {$mustChangeLabel}).\nReason: ".$reason;

        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => $tool,
                'content_hash' => $contentHash,
            ],
            [
                'client_id' => $client->id,
                'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ],
        );

        if (! $run->wasRecentlyCreated && $run->state !== TechnicianRunState::AwaitingApproval) {
            // A previously superseded/denied proposal for identical content: revive it
            // rather than dead-end as idempotent (bd psa-k4s0 Root B).
            $run->update([
                'state' => TechnicianRunState::AwaitingApproval->value,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ]);
        } elseif (! $run->wasRecentlyCreated) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $run->id,
                'message' => 'Already staged; awaiting approval.',
            ];
        }

        $this->auditAttempt($tool, 'awaiting_approval', $client->id, $ticket, $person, null, $contentHash, "MCP staged {$tool}: {$reason}", $actorLabel, $run->id);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'message' => 'Staged for cockpit approval. The temporary password is generated on approval and shown to the approver.',
        ];
    }

    private function stageAction(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->context($tool, $arguments, $clientId, $actorLabel, requireTicket: true);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var ResolvedCippPerson $person */
        $person = $context['person'];
        /** @var Ticket $ticket */
        $ticket = $context['ticket'];
        /** @var ResolvedCippLicense|null $license */
        $license = $context['license'];
        $state = is_string($context['state'] ?? null) ? $context['state'] : null;
        $mailbox = is_array($context['mailbox'] ?? null) ? $context['mailbox'] : null;
        $reason = (string) $context['reason'];
        $directTool = self::STAGED_TO_DIRECT[$tool];
        $params = $this->hashParams($directTool, $license, $state, $mailbox);
        $contentHash = $this->contentHash($tool, $client->id, $person->person->id, $ticket->id, $params);

        // The audit log is IMMUTABLE and stays authoritative ONLY for "was this exact
        // content already executed" — an 'executed' row can never go stale the way an
        // 'awaiting_approval' row can (bd psa-k4s0 Root B).
        if ($this->alreadyExecuted($tool, $client->id, $contentHash)) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $this->executedRunId($tool, $client->id, $contentHash),
                'message' => 'Already executed identical action recently; no new proposal was staged.',
            ];
        }

        // "Still awaiting approval" is decided by the LIVE runs table ONLY, never the
        // audit log — a stale 'awaiting_approval' audit row survives supersede/deny by
        // design and can never be used to infer that a run is still live (bd psa-k4s0
        // Root B). Checked before the cooldown so a legitimate identical re-send is
        // reported idempotent rather than refused as a cooldown hit.
        $liveAwaitingRun = $this->liveAwaitingRun($ticket->id, $tool, $contentHash);
        if ($liveAwaitingRun !== null) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $liveAwaitingRun->id,
                'message' => 'Already staged; awaiting approval.',
            ];
        }

        if ($this->proposalCooldownActive($tool, $ticket, $person, $license, self::COOLDOWNS[$tool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, $person, $license, $contentHash, "{$tool} cooldown active; staged proposal refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no proposal was staged."];
        }

        $meta = [
            'drafted_by' => $actorLabel,
            'reasons' => [$reason],
            'direct_tool' => $directTool,
            'person_id' => $person->person->id,
            'license_type_id' => $license?->licenseType->id,
            'redacted_params' => $params,
            'sensitive_inputs' => $this->sensitiveInputsForStagedAction($directTool, $params),
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => $directTool,
                'client_id' => $client->id,
                'person_id' => $person->person->id,
                'ticket_id' => $ticket->id,
                'params' => $params,
            ], JSON_THROW_ON_ERROR)),
        ];
        $proposedContent = $this->stagedDisplay($directTool, $person, $license, $state, $mailbox)."\nReason: ".$reason;

        // Keyed on the DB's own idempotency invariant (technician_runs_idempotency:
        // ticket_id + action_type + content_hash is UNIQUE) — a run with this EXACT
        // content either doesn't exist yet (create it) or exists but is no longer live
        // (superseded/denied, per the liveAwaitingRun() check above finding nothing), in
        // which case we revive THAT SAME row rather than attempt a second row with the
        // same key, which the DB would reject outright. firstOrCreate (rather than a bare
        // create()) also closes the TOCTOU gap against the liveAwaitingRun() check above.
        // Distinct content (e.g. forwarding for a different person) always gets its own
        // content_hash and therefore its own row — never colliding with, and never
        // superseding, an unrelated sibling (bd psa-k4s0 Root A).
        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => $tool,
                'content_hash' => $contentHash,
            ],
            [
                'client_id' => $client->id,
                'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ],
        );

        if (! $run->wasRecentlyCreated && $run->state !== TechnicianRunState::AwaitingApproval) {
            // Race winner: another request staged this exact content between the
            // liveAwaitingRun() check and this firstOrCreate() call. Never a false
            // idempotent dead end (bd psa-k4s0 Root B) — revive it as a fresh proposal.
            $run->update([
                'state' => TechnicianRunState::AwaitingApproval->value,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ]);
        } elseif (! $run->wasRecentlyCreated) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $run->id,
                'message' => 'Already staged; awaiting approval.',
            ];
        }

        $this->auditAttempt($tool, 'awaiting_approval', $client->id, $ticket, $person, $license, $contentHash, "MCP staged {$tool}: {$reason}", $actorLabel, $run->id);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'message' => 'Staged for cockpit approval.',
        ];
    }

    /**
     * Direct path for the email-security remediation writes. These have no
     * person_id, so the target scope gate differs per tool: a quarantine
     * release is only executed for an identity the SERVER finds in the
     * resolved tenant's live quarantine listing (with the typed confirm_sender
     * cross-checked against that verified row's real sender), and an allow
     * entry is a validated caller value pinned to the one resolved tenant.
     * Local guards (dedup, cooldown) run before the verification read so a
     * refused call never reaches upstream at all.
     *
     * @return array<string, mixed>
     */
    private function executeEmailSecurityDirect(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->emailSecurityContext($tool, $arguments, $clientId, $actorLabel, requireTicket: false);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var string $tenant */
        $tenant = $context['tenant'];
        /** @var Ticket|null $ticket */
        $ticket = $context['ticket'];
        /** @var array<string, mixed> $params */
        $params = $context['params'];
        $reason = (string) $context['reason'];

        $targetKey = $this->emailSecurityTargetKey($tool, $params);
        $contentHash = $this->contentHash($tool, $client->id, null, $ticket?->id, $this->emailSecurityHashParams($params));

        if ($this->alreadyExecuted($tool, $client->id, $contentHash)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: Duplicate {$tool} suppressed before upstream call.", $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Already executed identical CIPP write recently; no upstream call was made.',
            ];
        }

        if ($this->emailSecurityCooldownActive($tool, $client->id, $targetKey, self::COOLDOWNS[$tool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: {$tool} cooldown active; upstream call refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no upstream call was made."];
        }

        if ($tool === 'cipp_release_quarantine_message') {
            try {
                $row = $this->verifiedQuarantineRow($tenant, (string) $params['quarantine_identity'], (string) $context['confirm_sender']);
            } catch (CippWriteScopeException $e) {
                $this->auditAttempt($tool, 'rejected', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: ".$e->getMessage(), $actorLabel);

                return ['error' => $e->getMessage()];
            }

            if ($this->quarantineRowReleased($row)) {
                $this->auditAttempt($tool, 'executed', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: Message already released upstream; treated as satisfied without an upstream call.", $actorLabel);

                return [
                    'success' => true,
                    'idempotent' => true,
                    'already_released' => true,
                    'message' => 'Message is already released upstream; no upstream call was made.',
                ];
            }
        }

        try {
            $this->executeEmailSecurityUpstream($tool, $tenant, $ticket, $params);
        } catch (CippClientException $e) {
            $this->auditAttempt($tool, 'error', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: ".$this->safeFailureSummary($tool, $e), $actorLabel);

            return ['error' => "CIPP write failed for {$tool}; no response body returned."];
        }

        $this->auditAttempt($tool, 'executed', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: {$tool} executed for ".$this->emailSecurityAuditTarget($tool, $params).": {$reason}", $actorLabel);

        return array_merge([
            'success' => true,
            'tool' => $tool,
            'ticket_id' => $ticket?->id,
            'message' => $tool === 'cipp_release_quarantine_message'
                ? 'Quarantine release executed for all original recipients.'
                : 'Tenant allow-list entry added; it expires 45 days after its last use.',
        ], $this->emailSecurityResultEcho($tool, $params));
    }

    /**
     * Staged twin for the email-security writes. A quarantine staging performs
     * the same read-only verification lookup as the direct path (never the
     * release itself) so the cockpit proposal shows the REAL sender, subject,
     * and recipients captured server-side rather than trusting the caller's
     * description; approval re-verifies against the live quarantine before
     * executing. All stored payload values are validated local scalars —
     * nothing is re-entered at approval.
     *
     * @return array<string, mixed>
     */
    private function stageEmailSecurityAction(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->emailSecurityContext($tool, $arguments, $clientId, $actorLabel, requireTicket: true);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var string $tenant */
        $tenant = $context['tenant'];
        /** @var Ticket $ticket */
        $ticket = $context['ticket'];
        /** @var array<string, mixed> $params */
        $params = $context['params'];
        $reason = (string) $context['reason'];
        $directTool = self::STAGED_TO_DIRECT[$tool];

        $targetKey = $this->emailSecurityTargetKey($directTool, $params);
        $contentHash = $this->contentHash($tool, $client->id, null, $ticket->id, $this->emailSecurityHashParams($params));

        if ($this->alreadyExecuted($tool, $client->id, $contentHash)) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $this->executedRunId($tool, $client->id, $contentHash),
                'message' => 'Already executed identical action recently; no new proposal was staged.',
            ];
        }

        $liveAwaitingRun = $this->liveAwaitingRun($ticket->id, $tool, $contentHash);
        if ($liveAwaitingRun !== null) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $liveAwaitingRun->id,
                'message' => 'Already staged; awaiting approval.',
            ];
        }

        if ($this->emailSecurityProposalCooldownActive($tool, $ticket, $targetKey, self::COOLDOWNS[$tool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: {$tool} cooldown active; staged proposal refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no proposal was staged."];
        }

        $displayFacts = null;
        if ($directTool === 'cipp_release_quarantine_message') {
            try {
                $row = $this->verifiedQuarantineRow($tenant, (string) $params['quarantine_identity'], (string) $context['confirm_sender']);
            } catch (CippWriteScopeException $e) {
                $this->auditAttempt($tool, 'rejected', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: ".$e->getMessage(), $actorLabel);

                return ['error' => $e->getMessage()];
            }

            if ($this->quarantineRowReleased($row)) {
                $this->auditAttempt($tool, 'blocked', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: Message already released upstream; staging skipped.", $actorLabel);

                return [
                    'success' => true,
                    'idempotent' => true,
                    'already_released' => true,
                    'ticket_id' => $ticket->id,
                    'ticket_display_id' => $ticket->display_id,
                    'message' => 'Message is already released upstream; nothing was staged.',
                ];
            }

            $displayFacts = $this->quarantineDisplayFacts($row);
        }

        $meta = [
            'drafted_by' => $actorLabel,
            'reasons' => [$reason],
            'direct_tool' => $directTool,
            'redacted_params' => $this->emailSecurityHashParams($params),
            'sensitive_inputs' => [],
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => $directTool,
                'client_id' => $client->id,
                'ticket_id' => $ticket->id,
                'params' => $params,
            ], JSON_THROW_ON_ERROR)),
        ];
        $proposedContent = $this->emailSecurityStagedDisplay($directTool, $params, $displayFacts)."\nReason: ".$reason;

        // Same idempotency-revive contract as stageAction() (bd psa-k4s0): the
        // DB unique key (ticket_id + action_type + content_hash) either creates
        // a fresh run or revives the superseded/denied row it collides with.
        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => $tool,
                'content_hash' => $contentHash,
            ],
            [
                'client_id' => $client->id,
                'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ],
        );

        if (! $run->wasRecentlyCreated && $run->state !== TechnicianRunState::AwaitingApproval) {
            $run->update([
                'state' => TechnicianRunState::AwaitingApproval->value,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ]);
        } elseif (! $run->wasRecentlyCreated) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $run->id,
                'message' => 'Already staged; awaiting approval.',
            ];
        }

        $this->auditAttempt($tool, 'awaiting_approval', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: MCP staged {$tool} for ".$this->emailSecurityAuditTarget($directTool, $params).": {$reason}", $actorLabel, $run->id);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'message' => 'Staged for cockpit approval.',
        ];
    }

    /**
     * Approval replay for a held email-security write. The caller has already
     * claimed the run. Everything is revalidated from the encrypted payload
     * (tool identity, client, ticket, parameter shape); a quarantine release
     * is additionally re-verified against the LIVE tenant quarantine — a
     * message that has vanished refuses execution, and one already released
     * upstream satisfies the approved intent without an upstream call.
     */
    private function approveEmailSecurityStagedRun(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        try {
            $payload = $this->decryptRunPayload($run);
            if ($payload === null) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $directTool = (string) ($payload['direct_tool'] ?? '');
            if ((self::STAGED_TO_DIRECT[$run->action_type] ?? null) !== $directTool
                || ! in_array($directTool, self::EMAIL_SECURITY_TOOLS, true)) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $client = Client::find((int) ($payload['client_id'] ?? 0));
            if (! $client || (int) $client->id !== (int) $run->client_id) {
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $tenant = $this->resolver->resolveCippTenant($client);
            $ticket = $this->resolver->resolveTicketForHeldAction($client->id, $payload['ticket_id'] ?? null);
            $params = $this->emailSecurityStoredParams($directTool, is_array($payload['params'] ?? null) ? $payload['params'] : []);
            $targetKey = $this->emailSecurityTargetKey($directTool, $params);
            $contentHash = $run->content_hash;

            if (TechnicianConfig::killSwitchEngaged()) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: Technician kill-switch engaged; staged CIPP write refused.", $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            if ($this->emailSecurityCooldownActive($directTool, $client->id, $targetKey, self::COOLDOWNS[$directTool] ?? 300)) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: CIPP staged action cooldown active; approval refused before upstream call.", $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            if ($directTool === 'cipp_release_quarantine_message') {
                try {
                    $row = $this->verifiedQuarantineRow($tenant, (string) $params['quarantine_identity'], null);
                } catch (CippWriteScopeException $e) {
                    $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: Approval refused — ".$e->getMessage(), $this->approverLabel($approverId), $run->id, $approverId);
                    $run->releaseClaim();

                    return new TechnicianApprovalResult('gate_declined');
                }

                if ($this->quarantineRowReleased($row)) {
                    $this->auditAttempt($run->action_type, 'executed', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: Message already released upstream — approved release satisfied without an upstream call.", $this->approverLabel($approverId), $run->id, $approverId);
                    $run->advanceTo(TechnicianRunState::Done);

                    return new TechnicianApprovalResult('executed');
                }
            }

            try {
                $this->executeEmailSecurityUpstream($directTool, $tenant, $ticket, $params);
            } catch (CippClientException $e) {
                $this->auditAttempt($run->action_type, 'error', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: ".$this->safeFailureSummary($run->action_type, $e), $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return new TechnicianApprovalResult('gate_declined');
            }

            $this->auditAttempt($run->action_type, 'executed', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: Operator-approved {$run->action_type} executed for ".$this->emailSecurityAuditTarget($directTool, $params).'.', $this->approverLabel($approverId), $run->id, $approverId);
            $run->advanceTo(TechnicianRunState::Done);

            return new TechnicianApprovalResult('executed');
        } catch (CippWriteScopeException) {
            $run->releaseClaim();

            return new TechnicianApprovalResult('gate_declined');
        } catch (\Throwable $e) {
            $run->releaseClaim();

            throw $e;
        }
    }

    /**
     * Shared front door for the email-security tools: the same caller-input
     * gates as context() (upstream-identifier blocklist, required redacted
     * reason, kill-switch, client + tenant + ticket resolution) with per-tool
     * parameter validation in place of person/license resolution.
     *
     * @return array{client?: Client, tenant?: string, ticket?: Ticket|null, params?: array<string, mixed>, confirm_sender?: string|null, reason?: string, error?: string}
     */
    private function emailSecurityContext(string $tool, array $arguments, int $clientId, string $actorLabel, bool $requireTicket): array
    {
        $contentHash = $this->contentHash($tool, $clientId, null, null, $arguments);

        if ($keys = $this->upstreamIdentifierKeys($arguments)) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'Caller-supplied upstream CIPP identifiers are not accepted: '.implode(', ', $keys).'.', $actorLabel);

            return ['error' => 'Caller-supplied upstream CIPP identifiers are not accepted; provide the tool\'s own validated parameters and ticket_id only.'];
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'reason is required.', $actorLabel);

            return ['error' => 'reason is required'];
        }
        $reason = $this->safeReason($tool, $reason, $arguments);

        if (TechnicianConfig::killSwitchEngaged()) {
            $this->auditAttempt($tool, 'blocked', $clientId, null, null, null, $contentHash, 'Technician kill-switch engaged; CIPP MCP write refused.', $actorLabel);

            return ['error' => 'Technician kill-switch engaged; CIPP MCP write refused'];
        }

        $client = Client::find($clientId);
        if (! $client) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'Client not found.', $actorLabel);

            return ['error' => 'Client not found'];
        }

        $directTool = self::STAGED_TO_DIRECT[$tool] ?? $tool;

        try {
            $tenant = $this->resolver->resolveCippTenant($client);
            $ticket = $requireTicket
                ? $this->resolver->resolveTicketForHeldAction($client->id, $arguments['ticket_id'] ?? null)
                : $this->resolver->resolveOptionalTicket($client->id, $arguments['ticket_id'] ?? null);
            $params = $this->emailSecurityStoredParams($directTool, $arguments);
            $confirmSender = $directTool === 'cipp_release_quarantine_message'
                ? $this->validatedConfirmSender($arguments)
                : null;
            if ($directTool === 'cipp_add_tenant_allow_entry') {
                $this->assertConfirmEntryMatches($arguments, (string) $params['entry']);
            }
        } catch (CippWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $client->id, null, null, null, $contentHash, $e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        return [
            'client' => $client,
            'tenant' => $tenant,
            'ticket' => $ticket,
            'params' => $params,
            'confirm_sender' => $confirmSender,
            'reason' => $reason,
        ];
    }

    /**
     * Validate the per-tool parameters. Runs on the initial call (against
     * caller arguments) AND on the approval replay (against the decrypted
     * stored payload), so a tampered or drifted payload re-fails the same
     * gates instead of being trusted.
     *
     * @return array<string, mixed>
     */
    private function emailSecurityStoredParams(string $directTool, array $source): array
    {
        return match ($directTool) {
            'cipp_release_quarantine_message' => [
                'quarantine_identity' => $this->validatedQuarantineIdentity($source['quarantine_identity'] ?? null),
            ],
            'cipp_add_tenant_allow_entry' => (function () use ($source): array {
                $listType = $this->canonicalChoice($this->requiredString($source, 'list_type'), self::ALLOW_LIST_TYPES, 'list_type');

                return [
                    'list_type' => $listType,
                    'entry' => $this->validatedAllowEntry($listType, $source['entry'] ?? null),
                ];
            })(),
            default => throw new CippWriteScopeException("Unsupported email-security tool {$directTool}"),
        };
    }

    /**
     * Case-normalized copy of the params for content hashing, dedup, and the
     * stored redacted_params — so re-tries that differ only by case dedup to
     * the same proposal while the upstream call still receives the caller's
     * original (URL paths can be case-sensitive).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function emailSecurityHashParams(array $params): array
    {
        $hashed = $params;
        foreach (['quarantine_identity', 'entry'] as $key) {
            if (isset($hashed[$key]) && is_string($hashed[$key])) {
                $hashed[$key] = mb_strtolower($hashed[$key]);
            }
        }

        return $hashed;
    }

    private function validatedQuarantineIdentity(mixed $value): string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new CippWriteScopeException('quarantine_identity is required');
        }

        $identity = trim((string) $value);
        if (mb_strlen($identity) > self::QUARANTINE_IDENTITY_MAX) {
            throw new CippWriteScopeException('quarantine_identity must be '.self::QUARANTINE_IDENTITY_MAX.' characters or fewer');
        }

        if (preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\\\\[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $identity) !== 1) {
            throw new CippWriteScopeException('quarantine_identity must be the GUID\\GUID Identity value exactly as returned by cipp_list_mail_quarantine');
        }

        return $identity;
    }

    private function validatedConfirmSender(array $arguments): string
    {
        $typed = $this->requiredString($arguments, 'confirm_sender');
        if ($typed === null || mb_strlen($typed) > 254 || filter_var($typed, FILTER_VALIDATE_EMAIL) === false) {
            throw new CippWriteScopeException('confirm_sender must be the sender email address of the quarantined message as listed by cipp_list_mail_quarantine.');
        }

        return $typed;
    }

    private function assertConfirmEntryMatches(array $arguments, string $entry): void
    {
        $typed = $this->requiredString($arguments, 'confirm_entry');
        if ($typed === null || strcasecmp($typed, $entry) !== 0) {
            throw new CippWriteScopeException('The typed confirm_entry does not match entry. CIPP write cancelled.');
        }
    }

    private function validatedAllowEntry(string $listType, mixed $value): string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new CippWriteScopeException('entry is required');
        }

        $entry = trim((string) $value);
        if (mb_strlen($entry) > self::ALLOW_ENTRY_MAX) {
            throw new CippWriteScopeException('entry must be '.self::ALLOW_ENTRY_MAX.' characters or fewer');
        }

        if (preg_match('/\s/u', $entry) === 1) {
            throw new CippWriteScopeException('entry must not contain whitespace');
        }

        if ($listType === 'Sender') {
            $isEmail = filter_var($entry, FILTER_VALIDATE_EMAIL) !== false;
            $isDomain = preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/i', $entry) === 1;
            if (! $isEmail && ! $isDomain) {
                throw new CippWriteScopeException('Sender entries must be a full email address or a bare domain (wildcards are not supported).');
            }

            return $entry;
        }

        if (str_contains($entry, '://') || str_contains($entry, '@')) {
            throw new CippWriteScopeException('Url entries must not include a scheme or @; use host/path patterns like example.com/path/* or *.example.com');
        }

        if (! str_contains($entry, '.') || preg_match('/^[a-z0-9*][a-z0-9.\-*_~\/%?&=+#]*$/i', $entry) !== 1) {
            throw new CippWriteScopeException('Url entries must be a hostname or URL pattern (wildcards allowed), e.g. example.com or *.example.com/path/*');
        }

        return $entry;
    }

    /**
     * The quarantine-release scope gate: fetch the resolved tenant's LIVE
     * quarantine listing through the same credentialed client the write would
     * use and require the identity to be present in it. This is what converts
     * a caller-supplied identity string into a server-verified, tenant-scoped
     * object — a message in any other tenant (or not in quarantine at all) can
     * never be targeted. When $expectedSender is given (initial calls), it
     * must match the verified row's real sender.
     *
     * @return array<string, mixed>
     */
    private function verifiedQuarantineRow(string $tenant, string $identity, ?string $expectedSender): array
    {
        try {
            $rows = $this->client->listMailQuarantine($tenant);
        } catch (CippClientException) {
            throw new CippWriteScopeException('Could not verify the message against the tenant\'s live quarantine listing; no release was performed.');
        }

        foreach ($rows as $row) {
            $rowIdentity = (string) ($row['Identity'] ?? $row['identity'] ?? '');
            if ($rowIdentity === '' || strcasecmp($rowIdentity, $identity) !== 0) {
                continue;
            }

            if ($expectedSender !== null) {
                $sender = trim((string) ($row['SenderAddress'] ?? $row['senderAddress'] ?? ''));
                if ($sender === '' || strcasecmp($sender, $expectedSender) !== 0) {
                    throw new CippWriteScopeException('The typed confirm_sender does not match the sender of the verified quarantine message. CIPP write cancelled.');
                }
            }

            return $row;
        }

        throw new CippWriteScopeException('Quarantine message not found in this client tenant\'s live quarantine listing; pass the exact Identity value returned by cipp_list_mail_quarantine.');
    }

    private function quarantineRowReleased(array $row): bool
    {
        $status = trim((string) ($row['ReleaseStatus'] ?? $row['releaseStatus'] ?? ''));

        return strcasecmp($status, 'RELEASED') === 0;
    }

    /**
     * Human-review facts from the verified quarantine row for the cockpit
     * display. Every field is untrusted external content: control characters
     * are stripped, the subject passes the redactor, and everything is length-
     * bounded. Only the operator display carries these — the encrypted payload
     * and audit summaries stay identifier-only.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function quarantineDisplayFacts(array $row): array
    {
        $clean = function (mixed $value, int $max): string {
            $flat = is_array($value)
                ? implode(', ', array_map(strval(...), array_filter($value, 'is_scalar')))
                : (string) $value;

            return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $flat)), 0, $max);
        };

        $recipients = $row['RecipientAddress'] ?? $row['recipientAddress'] ?? '';
        $recipientList = is_array($recipients) ? array_values(array_filter($recipients, 'is_scalar')) : [$recipients];
        $shown = array_map(fn (mixed $recipient): string => $clean($recipient, 254), array_slice($recipientList, 0, 3));
        $extra = count($recipientList) - count($shown);

        return [
            'sender' => $clean($row['SenderAddress'] ?? $row['senderAddress'] ?? '', 254),
            'subject' => $clean($this->redactor->redactString((string) ($row['Subject'] ?? $row['subject'] ?? '')), self::QUARANTINE_SUBJECT_PREVIEW_MAX),
            'received' => $clean($row['ReceivedTime'] ?? $row['receivedTime'] ?? '', 40),
            'type' => $clean($row['QuarantineTypes'] ?? $row['quarantineTypes'] ?? $row['Type'] ?? '', 100),
            'recipients' => implode(', ', array_filter($shown)).($extra > 0 ? " (+{$extra} more)" : ''),
        ];
    }

    private function executeEmailSecurityUpstream(string $directTool, string $tenant, ?Ticket $ticket, array $params): void
    {
        match ($directTool) {
            'cipp_release_quarantine_message' => $this->client->releaseQuarantineMessage($tenant, (string) $params['quarantine_identity']),
            'cipp_add_tenant_allow_entry' => $this->client->addTenantAllowListEntry(
                $tenant,
                (string) $params['list_type'],
                (string) $params['entry'],
                $this->allowListNotes($ticket),
            ),
            default => throw new \InvalidArgumentException("Unsupported CIPP email-security tool {$directTool}"),
        };
    }

    /**
     * Server-built provenance for the upstream Tenant Allow/Block List Notes
     * field — technicians looking at the entry in M365 later can trace it back
     * here. Never caller-supplied.
     */
    private function allowListNotes(?Ticket $ticket): string
    {
        $notes = 'Added via '.config('app.name');

        return $ticket ? $notes.' (ticket '.$ticket->display_id.')' : $notes;
    }

    /**
     * Per-target cooldown/audit correlation key. Hash-based because the raw
     * target values are unsafe inside a SQL LIKE pattern (quarantine
     * identities contain a backslash — the LIKE escape character — and URL
     * entries can contain % and _), which would silently break cooldown
     * matching. The raw value still appears in the audit summary for humans.
     */
    private function emailSecurityTargetKey(string $tool, array $params): string
    {
        $directTool = self::STAGED_TO_DIRECT[$tool] ?? $tool;

        return $directTool === 'cipp_release_quarantine_message'
            ? 'quarantine #'.substr(hash('sha256', mb_strtolower((string) ($params['quarantine_identity'] ?? ''))), 0, 12)
            : 'allow_entry #'.substr(hash('sha256', ($params['list_type'] ?? '').'|'.mb_strtolower((string) ($params['entry'] ?? ''))), 0, 12);
    }

    /**
     * Human-readable target for audit summaries. Unlike the person-scoped
     * tools (which audit by PSA id and keep upstream identities out), the
     * audit here records the actual target value — a quarantine identity or
     * an allow entry IS the tenant configuration being changed, and an audit
     * row that hides it would be unreviewable.
     */
    private function emailSecurityAuditTarget(string $tool, array $params): string
    {
        $directTool = self::STAGED_TO_DIRECT[$tool] ?? $tool;

        return $directTool === 'cipp_release_quarantine_message'
            ? 'quarantine message '.($params['quarantine_identity'] ?? 'unknown')
            : ($params['list_type'] ?? 'unknown').' entry "'.($params['entry'] ?? '').'"';
    }

    private function emailSecurityCooldownActive(string $tool, int $clientId, string $targetKey, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('client_id', $clientId)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->whereIn('result_status', ['executed', 'awaiting_approval'])
            ->where('summary', 'like', '%'.$targetKey.'%')
            ->exists();
    }

    private function emailSecurityProposalCooldownActive(string $tool, Ticket $ticket, string $targetKey, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('ticket_id', $ticket->id)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->whereIn('result_status', ['awaiting_approval', 'executed'])
            ->where('summary', 'like', '%'.$targetKey.'%')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>|null  $facts
     */
    private function emailSecurityStagedDisplay(string $directTool, array $params, ?array $facts): string
    {
        if ($directTool === 'cipp_release_quarantine_message') {
            $facts ??= [];

            return 'Release quarantined message from '.($facts['sender'] ?? 'unknown sender')
                .' to all original recipients ('.($facts['recipients'] ?? 'unknown').').'
                .' Subject: "'.($facts['subject'] ?? '').'".'
                .' Received '.($facts['received'] ?? 'unknown').'; quarantine type '.($facts['type'] ?? 'unknown').'.'
                .' Identity '.$params['quarantine_identity'].'.'
                .' Releasing delivers mail the filter judged unsafe — confirm this is a verified false positive.';
        }

        return 'Add tenant allow-list '.$params['list_type'].' entry "'.$params['entry'].'" for the WHOLE tenant'
            .' (expires 45 days after its last use).'
            .' Matching mail will bypass spam/phish filtering for every mailbox in this tenant.';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function emailSecurityResultEcho(string $tool, array $params): array
    {
        return $tool === 'cipp_release_quarantine_message'
            ? ['quarantine_identity' => (string) $params['quarantine_identity']]
            : ['list_type' => (string) $params['list_type'], 'entry' => (string) $params['entry']];
    }

    /**
     * Direct path for the provisioning create-user write (immediate mode
     * grant only — grants start staged-only). Mirrors executeResetPassword's
     * credential contract: the CIPP-generated temp password exists ONLY in
     * the returned result; auditAttempt() records the created UPN, never the
     * password. The idempotent short-circuit cannot return the credential
     * (it was never stored), so it points at the password reset tool instead.
     *
     * @return array<string, mixed>
     */
    private function executeCreateUserDirect(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->createUserContext($tool, $arguments, $clientId, $actorLabel, requireTicket: false);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var string $tenant */
        $tenant = $context['tenant'];
        /** @var Ticket|null $ticket */
        $ticket = $context['ticket'];
        /** @var array<string, mixed> $params */
        $params = $context['params'];
        /** @var ResolvedCippLicense|null $license */
        $license = $context['license'];
        $reason = (string) $context['reason'];

        $targetKey = $this->createUserTargetKey((string) $params['staged_upn']);
        $contentHash = $this->contentHash($tool, $client->id, null, $ticket?->id, $params);

        // Both rails: the exact-content dedup AND the identity-keyed
        // double-create rail (a same-UPN create with different names is still
        // the same account being created twice).
        if ($this->alreadyExecuted($tool, $client->id, $contentHash) || $this->createUserAlreadyExecuted($client->id, $targetKey)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: Duplicate {$tool} suppressed before upstream call.", $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'An identical user creation already executed recently; no upstream call was made. The temporary password was returned once at creation — use cipp_reset_user_password if a new credential is needed.',
            ];
        }

        // Shared targetKey-in-summary cooldown helper (same semantics as the
        // email-security non-person targets).
        if ($this->emailSecurityCooldownActive($tool, $client->id, $targetKey, self::COOLDOWNS[$tool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: {$tool} cooldown active; upstream call refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no upstream call was made."];
        }

        try {
            $upstream = $this->client->createUser(
                $tenant,
                (string) $params['username'],
                $tenant,
                (string) $params['display_name'],
                (string) $params['given_name'],
                (string) $params['surname'],
                $params['usage_location'] ?? null,
                $license?->skuId,
            );
        } catch (CippClientException $e) {
            $this->auditAttempt($tool, 'error', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: ".$this->safeFailureSummary($tool, $e), $actorLabel);

            return ['error' => "CIPP user creation failed for {$tool}; no account was reported created."];
        }

        $parsed = $this->parseCreateUserResponse($upstream);
        $createdUpn = $parsed['upn'] ?? (string) $params['staged_upn'];

        $this->auditAttempt($tool, 'executed', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: {$tool} executed — created M365 user {$createdUpn}".($license !== null ? ' with license_type #'.$license->licenseType->id : '').': '.$reason, $actorLabel);

        $result = [
            'success' => true,
            'tool' => $tool,
            'ticket_id' => $ticket?->id,
            'user_principal_name' => $createdUpn,
            'must_change_at_next_logon' => true,
            'license_type_id' => $license?->licenseType->id,
        ];

        if ($parsed['warnings'] !== []) {
            $result['post_create_warnings'] = $parsed['warnings'];
        }

        if ($parsed['password'] === null) {
            $result['password_returned'] = false;
            $result['message'] = 'CIPP reported the user was created but returned no password value. Verify in CIPP; if PwPush is configured the credential may be delivered as a link instead.';

            return $result;
        }

        $result['temporary_password'] = $parsed['password'];
        $result['message'] = 'New Microsoft 365 user created. Relay the temporary password over a secure channel; the user must change it at first sign-in. It is returned only in this result and never stored.';
        $result['guidance'] = 'If your CIPP instance has PwPush enabled, the temporary_password value may be a one-time secure link rather than the literal password.';

        return $result;
    }

    /**
     * Staged twin for the provisioning create-user write — the DEFAULT path
     * (grants start staged-only). The MCP call makes no CIPP upstream call;
     * the held payload stores only validated safe scalars (username, the
     * server-composed staged_upn snapshot, names, usage location, local
     * license_type_id), and the cockpit proposal names the exact identity
     * being created plus the one-time password delivery contract. Approval
     * re-derives the tenant scope fresh and refuses on drift.
     *
     * @return array<string, mixed>
     */
    private function stageCreateUserAction(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->createUserContext($tool, $arguments, $clientId, $actorLabel, requireTicket: true);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var Ticket $ticket */
        $ticket = $context['ticket'];
        /** @var array<string, mixed> $params */
        $params = $context['params'];
        /** @var ResolvedCippLicense|null $license */
        $license = $context['license'];
        $reason = (string) $context['reason'];
        $directTool = self::STAGED_TO_DIRECT[$tool];

        $targetKey = $this->createUserTargetKey((string) $params['staged_upn']);
        $contentHash = $this->contentHash($tool, $client->id, null, $ticket->id, $params);

        if ($this->alreadyExecuted($tool, $client->id, $contentHash)) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $this->executedRunId($tool, $client->id, $contentHash),
                'message' => 'Already executed identical action recently; no new proposal was staged.',
            ];
        }

        $liveAwaitingRun = $this->liveAwaitingRun($ticket->id, $tool, $contentHash);
        if ($liveAwaitingRun !== null) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $liveAwaitingRun->id,
                'message' => 'Already staged; awaiting approval.',
            ];
        }

        if ($this->emailSecurityProposalCooldownActive($tool, $ticket, $targetKey, self::COOLDOWNS[$tool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: {$tool} cooldown active; staged proposal refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no proposal was staged."];
        }

        $meta = [
            'drafted_by' => $actorLabel,
            'reasons' => [$reason],
            'direct_tool' => $directTool,
            'license_type_id' => $license?->licenseType->id,
            'redacted_params' => $params,
            'sensitive_inputs' => [],
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => $directTool,
                'client_id' => $client->id,
                'ticket_id' => $ticket->id,
                'params' => $params,
            ], JSON_THROW_ON_ERROR)),
        ];
        $proposedContent = $this->createUserStagedDisplay($params, $license)."\nReason: ".$reason;

        // Same idempotency-revive contract as stageAction() (bd psa-k4s0): the
        // DB unique key (ticket_id + action_type + content_hash) either creates
        // a fresh run or revives the superseded/denied row it collides with.
        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => $tool,
                'content_hash' => $contentHash,
            ],
            [
                'client_id' => $client->id,
                'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ],
        );

        if (! $run->wasRecentlyCreated && $run->state !== TechnicianRunState::AwaitingApproval) {
            $run->update([
                'state' => TechnicianRunState::AwaitingApproval->value,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ]);
        } elseif (! $run->wasRecentlyCreated) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $run->id,
                'message' => 'Already staged; awaiting approval.',
            ];
        }

        $this->auditAttempt($tool, 'awaiting_approval', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: MCP staged {$tool} for new user {$params['staged_upn']}: {$reason}", $actorLabel, $run->id);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'message' => 'Staged for cockpit approval.',
        ];
    }

    /**
     * Approval replay for a held create-user write. The caller has already
     * claimed the run. Everything is revalidated from the encrypted payload
     * through the same gates as the initial call; the tenant scope is
     * re-derived FRESH and compared against the staged UPN snapshot, so a
     * client whose CIPP tenant mapping changed after staging declines instead
     * of creating an identity the operator never reviewed. The CIPP-generated
     * temp password rides back once on the approval result's secret field
     * (shown to the approver, never stored, never audited); a re-approval for
     * an identity that already executed — from this ticket or any other — is
     * a LOGGED NO-OP, never a second upstream call.
     */
    private function approveCreateUserStagedRun(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        try {
            $payload = $this->decryptRunPayload($run);
            if ($payload === null) {
                $run->releaseClaim();

                return $this->declined('The held payload could not be read; deny this proposal and re-stage it.');
            }

            $directTool = (string) ($payload['direct_tool'] ?? '');
            if ((self::STAGED_TO_DIRECT[$run->action_type] ?? null) !== $directTool
                || ! in_array($directTool, self::PROVISIONING_TOOLS, true)) {
                $run->releaseClaim();

                return $this->declined('The held payload does not match this action type; deny this proposal and re-stage it.');
            }

            $client = Client::find((int) ($payload['client_id'] ?? 0));
            if (! $client || (int) $client->id !== (int) $run->client_id) {
                $run->releaseClaim();

                return $this->declined('The proposal\'s client could not be re-verified; deny this proposal and re-stage it.');
            }

            $tenant = $this->resolver->resolveCippTenant($client);
            $ticket = $this->resolver->resolveTicketForHeldAction($client->id, $payload['ticket_id'] ?? null);
            $stored = is_array($payload['params'] ?? null) ? $payload['params'] : [];
            $params = $this->createUserParams($tenant, $stored);

            // Tenant-mapping drift rail: the freshly composed UPN must equal
            // the snapshot the operator reviewed at staging.
            $stagedUpn = trim((string) ($stored['staged_upn'] ?? ''));
            if ($stagedUpn === '' || strcasecmp((string) $params['staged_upn'], $stagedUpn) !== 0) {
                $run->releaseClaim();

                return $this->declined('The client\'s CIPP tenant mapping changed after this action was staged; deny this proposal and re-stage it against the current tenant.');
            }

            $license = isset($params['license_type_id'])
                ? $this->resolver->resolveCippLicense($client->id, $params['license_type_id'])
                : null;

            $targetKey = $this->createUserTargetKey((string) $params['staged_upn']);
            $contentHash = $run->content_hash;

            if (TechnicianConfig::killSwitchEngaged()) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: Technician kill-switch engaged; staged CIPP write refused.", $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('Technician kill-switch engaged; the staged CIPP write was refused.');
            }

            // Double-create rail (device-wipe precedent): an identity that
            // already executed leaves the queue terminally as a logged no-op.
            if ($this->createUserAlreadyExecuted($client->id, $targetKey)) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: Duplicate user creation suppressed: {$params['staged_upn']} already created within ".self::DIRECT_DEDUP_HOURS.'h; the approval was treated as a logged no-op.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->advanceTo(TechnicianRunState::Done);

                return new TechnicianApprovalResult('already_handled');
            }

            if ($this->emailSecurityCooldownActive($directTool, $client->id, $targetKey, self::COOLDOWNS[$directTool] ?? 300)) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: CIPP staged action cooldown active; approval refused before upstream call.", $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('A recent action for this target is still in cooldown; wait a few minutes and approve again.');
            }

            try {
                $upstream = $this->client->createUser(
                    $tenant,
                    (string) $params['username'],
                    $tenant,
                    (string) $params['display_name'],
                    (string) $params['given_name'],
                    (string) $params['surname'],
                    $params['usage_location'] ?? null,
                    $license?->skuId,
                );
            } catch (CippClientException $e) {
                $this->auditAttempt($run->action_type, 'error', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: ".$this->safeFailureSummary($run->action_type, $e), $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined($e->getMessage());
            }

            $parsed = $this->parseCreateUserResponse($upstream);
            $createdUpn = $parsed['upn'] ?? (string) $params['staged_upn'];

            $this->auditAttempt($run->action_type, 'executed', $client->id, $ticket, null, null, $contentHash, "{$targetKey}: Operator-approved {$run->action_type} executed — created M365 user {$createdUpn}".($license !== null ? ' with license_type #'.$license->licenseType->id : '').'. Temp password delivered once to the approver; never stored.', $this->approverLabel($approverId), $run->id, $approverId);
            $run->advanceTo(TechnicianRunState::Done);

            $message = 'Created Microsoft 365 user '.$createdUpn.'.';
            if ($parsed['warnings'] !== []) {
                $message .= ' Post-create warning: '.implode(' ', $parsed['warnings']);
            }
            $message .= $parsed['password'] !== null
                ? ' The temporary password is shown once here and never stored — relay it over a secure channel; the user must change it at first sign-in.'
                : ' CIPP returned no password value; if PwPush is configured the credential may be delivered as a link — verify in CIPP, or use the password reset tool.';

            return new TechnicianApprovalResult(
                'executed',
                message: mb_substr($this->redactor->redactString($message), 0, 500),
                secret: $parsed['password'],
            );
        } catch (CippWriteScopeException $e) {
            $run->releaseClaim();

            return $this->declined($e->getMessage());
        } catch (\Throwable $e) {
            $run->releaseClaim();

            throw $e;
        }
    }

    /**
     * Shared front door for the provisioning create-user write: the same
     * caller-input gates as context() (upstream-identifier blocklist, required
     * redacted reason, kill-switch, client + tenant + ticket resolution) with
     * new-identity parameter validation in place of person resolution. The
     * typed confirm_upn must match the SERVER-composed UPN
     * (username@<mapped tenant domain>) — a wrong client_id or a guessed
     * domain cancels before anything is staged or executed.
     *
     * @return array{client?: Client, tenant?: string, ticket?: Ticket|null, params?: array<string, mixed>, license?: ResolvedCippLicense|null, reason?: string, error?: string}
     */
    private function createUserContext(string $tool, array $arguments, int $clientId, string $actorLabel, bool $requireTicket): array
    {
        $contentHash = $this->contentHash($tool, $clientId, null, null, $arguments);

        if ($keys = $this->upstreamIdentifierKeys($arguments)) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'Caller-supplied upstream CIPP identifiers are not accepted: '.implode(', ', $keys).'.', $actorLabel);

            return ['error' => 'Caller-supplied upstream CIPP identifiers are not accepted; provide the tool\'s own validated parameters and ticket_id only.'];
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'reason is required.', $actorLabel);

            return ['error' => 'reason is required'];
        }
        $reason = $this->safeReason($tool, $reason, $arguments);

        if (TechnicianConfig::killSwitchEngaged()) {
            $this->auditAttempt($tool, 'blocked', $clientId, null, null, null, $contentHash, 'Technician kill-switch engaged; CIPP MCP write refused.', $actorLabel);

            return ['error' => 'Technician kill-switch engaged; CIPP MCP write refused'];
        }

        $client = Client::find($clientId);
        if (! $client) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'Client not found.', $actorLabel);

            return ['error' => 'Client not found'];
        }

        try {
            $tenant = $this->resolver->resolveCippTenant($client);
            $ticket = $requireTicket
                ? $this->resolver->resolveTicketForHeldAction($client->id, $arguments['ticket_id'] ?? null)
                : $this->resolver->resolveOptionalTicket($client->id, $arguments['ticket_id'] ?? null);
            $params = $this->createUserParams($tenant, $arguments);
            $license = isset($params['license_type_id'])
                ? $this->resolver->resolveCippLicense($client->id, $params['license_type_id'])
                : null;

            $typed = $this->requiredString($arguments, 'confirm_upn');
            if ($typed === null || strcasecmp($typed, (string) $params['staged_upn']) !== 0) {
                throw new CippWriteScopeException('The typed confirm_upn does not match the server-composed UPN for the new user ('.$params['staged_upn'].'). CIPP write cancelled.');
            }
        } catch (CippWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $client->id, null, null, null, $contentHash, $e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        return [
            'client' => $client,
            'tenant' => $tenant,
            'ticket' => $ticket,
            'params' => $params,
            'license' => $license,
            'reason' => $reason,
        ];
    }

    /**
     * Validate the new-identity parameters. Runs on the initial call (against
     * caller arguments) AND on the approval replay (against the decrypted
     * stored payload), so a tampered or drifted payload re-fails the same
     * gates instead of being trusted. Every returned value is a safe local
     * scalar; staged_upn is the server-composed identity
     * (username@<resolved tenant domain>) the approval replay re-derives and
     * compares against.
     *
     * @return array<string, mixed>
     */
    private function createUserParams(string $tenant, array $source): array
    {
        $username = $this->requiredString($source, 'username');
        if ($username === null || mb_strlen($username) > self::CREATE_USERNAME_MAX || preg_match(self::CREATE_USERNAME_PATTERN, $username) !== 1) {
            throw new CippWriteScopeException('username must be a plain UPN local part (letters/digits with interior . _ -, max '.self::CREATE_USERNAME_MAX.' characters) — the server appends the client\'s mapped tenant domain.');
        }

        $username = mb_strtolower($username);
        $upn = $username.'@'.mb_strtolower(trim($tenant));
        if (mb_strlen($upn) > self::CREATE_UPN_MAX || filter_var($upn, FILTER_VALIDATE_EMAIL) === false) {
            throw new CippWriteScopeException('The composed UPN ('.$upn.') is not a valid user principal name; shorten the username or fix the client CIPP tenant mapping.');
        }

        $params = [
            'username' => $username,
            'staged_upn' => $upn,
            'display_name' => $this->createUserNameField($source, 'display_name', self::CREATE_DISPLAY_NAME_MAX),
            'given_name' => $this->createUserNameField($source, 'given_name', self::CREATE_NAME_MAX),
            'surname' => $this->createUserNameField($source, 'surname', self::CREATE_NAME_MAX),
        ];

        $usageLocation = $this->requiredString($source, 'usage_location');
        if ($usageLocation !== null) {
            if (preg_match('/^[a-z]{2}$/i', $usageLocation) !== 1) {
                throw new CippWriteScopeException('usage_location must be a 2-letter ISO 3166-1 country code (e.g. US)');
            }
            $params['usage_location'] = strtoupper($usageLocation);
        }

        if (array_key_exists('license_type_id', $source) && $source['license_type_id'] !== null && $source['license_type_id'] !== '') {
            $licenseTypeId = $source['license_type_id'];
            if (! is_int($licenseTypeId) && ! (is_string($licenseTypeId) && preg_match('/^[1-9][0-9]*$/', $licenseTypeId) === 1)) {
                throw new CippWriteScopeException('license_type_id must be a positive integer');
            }
            if (! isset($params['usage_location'])) {
                throw new CippWriteScopeException('usage_location is required when license_type_id is provided — Microsoft 365 refuses license assignment for a user without a usage location.');
            }
            $params['license_type_id'] = (int) $licenseTypeId;
        }

        return $params;
    }

    /** A required upstream directory name field: bounded, control-character free. */
    private function createUserNameField(array $source, string $field, int $maxLength): string
    {
        $value = (string) $this->boundedString($source, $field, $maxLength, required: true);
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new CippWriteScopeException("{$field} must not contain control characters");
        }

        return $value;
    }

    /**
     * Read the created UPN, the one-time temp password, and any post-create
     * step warnings out of a captured AddUser response body. Source shape
     * (Invoke-AddUser.ps1): Results is a mixed list — string status lines
     * plus two {resultText, copyField} objects carrying the username and the
     * password; post-create steps (license, aliases, groups) append plain
     * strings, and a failed step reports a "Failed …" line while the create
     * itself already succeeded. Warning strings are length-bounded,
     * control-stripped, and dropped entirely if they somehow embed the
     * credential.
     *
     * @param  array<int|string, mixed>  $response
     * @return array{upn: string|null, password: string|null, warnings: array<int, string>}
     */
    private function parseCreateUserResponse(array $response): array
    {
        $results = $response['body']['Results'] ?? null;
        $results = is_array($results) ? array_values($results) : [];

        $upn = null;
        $password = null;
        $warnings = [];
        $copyFields = [];

        foreach ($results as $entry) {
            if (is_array($entry)) {
                $text = trim((string) ($entry['resultText'] ?? ''));
                $copy = isset($entry['copyField']) && is_string($entry['copyField']) && $entry['copyField'] !== '' ? $entry['copyField'] : null;
                if ($copy !== null) {
                    $copyFields[] = $copy;
                }
                if ($upn === null && str_starts_with($text, 'Username:')) {
                    $upn = $copy;
                } elseif ($password === null && str_starts_with($text, 'Password:')) {
                    $password = $copy;
                }

                continue;
            }

            if (is_string($entry) && stripos($entry, 'failed') !== false) {
                $warnings[] = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $entry)), 0, 300);
            }
        }

        // Positional fallback for a response without the labeled resultText
        // lines: Invoke-AddUser emits the username object first, password second.
        if ($upn === null && $password === null && count($copyFields) >= 2) {
            [$upn, $password] = [$copyFields[0], $copyFields[1]];
        }

        if ($password !== null) {
            $warnings = array_values(array_filter($warnings, fn (string $warning): bool => ! str_contains($warning, $password)));
        }

        return ['upn' => $upn, 'password' => $password, 'warnings' => $warnings];
    }

    /**
     * Per-target cooldown/audit correlation key for a created identity —
     * hash-based like the email-security keys so the audit LIKE matching can
     * never be confused by pattern characters. The raw UPN still appears in
     * the audit summary for humans.
     */
    private function createUserTargetKey(string $upn): string
    {
        return 'new_user #'.substr(hash('sha256', mb_strtolower($upn)), 0, 12);
    }

    /**
     * Whether this exact identity was already created recently — the
     * double-create rail (device-wipe precedent, bead psa-zjpd). Keyed on the
     * identity embedded in the executed audit summary, NOT the content hash,
     * so a duplicate staged from a different ticket (or with different
     * display names) is caught too.
     */
    private function createUserAlreadyExecuted(int $clientId, string $targetKey): bool
    {
        return TechnicianActionLog::query()
            ->whereIn('action_type', ['cipp_create_user', 'cipp_stage_create_user'])
            ->where('client_id', $clientId)
            ->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->where('summary', 'like', '%'.$targetKey.'%')
            ->exists();
    }

    /** @param  array<string, mixed>  $params */
    private function createUserStagedDisplay(array $params, ?ResolvedCippLicense $license): string
    {
        return 'Create NEW Microsoft 365 user "'.$params['display_name'].'" — UPN '.$params['staged_upn']
            .' (given name "'.$params['given_name'].'", surname "'.$params['surname'].'"'
            .(isset($params['usage_location']) ? ', usage location '.$params['usage_location'] : '')
            .($license !== null ? ', initial license license_type #'.$license->licenseType->id.' "'.$license->licenseType->name.'"' : ', no initial license')
            .').'
            .' The UPN domain is the client\'s mapped CIPP tenant domain (server-derived).'
            .' The account is created enabled, with a CIPP-generated temporary password that must be changed at first sign-in;'
            .' the password is shown once to the approver after execution and is never stored.'
            .' Approval re-derives the tenant scope and refuses if the mapping changed after staging.';
    }

    /**
     * Direct path for the group-membership write (immediate mode grant only —
     * grants start staged-only). The target user is a server-resolved PSA
     * person (ACTIVE required for an add — the psa-pgnj recipient gate; loose
     * for a remove so offboarding cleanup stays possible), and the group is
     * verified against the resolved tenant's live CIPP group listing before
     * the single write is sent with the VERIFIED name and type. Adds to
     * security-privileged types refuse here outright — held-only, whatever
     * mode was granted (PRIVILEGED_GROUP_TYPES) — so the immediate grant
     * covers collaboration-type adds and all removes. Local rails (dedup,
     * cooldown) run before the verification read so a refused call never
     * reaches upstream at all.
     *
     * @return array<string, mixed>
     */
    private function executeGroupMembershipDirect(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->groupMembershipContext($tool, $arguments, $clientId, $actorLabel, requireTicket: false);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var string $tenant */
        $tenant = $context['tenant'];
        /** @var ResolvedCippPerson $person */
        $person = $context['person'];
        /** @var Ticket|null $ticket */
        $ticket = $context['ticket'];
        /** @var array<string, mixed> $params */
        $params = $context['params'];
        $reason = (string) $context['reason'];

        $targetKey = $this->groupMembershipTargetKey($person, $params);
        $contentHash = $this->contentHash($tool, $client->id, $person->person->id, $ticket?->id, $params);

        // Both rails: the exact-content dedup AND the identity-keyed rail (the
        // same user + group + operation staged from a different ticket is
        // still the same membership change being executed twice).
        if ($this->alreadyExecuted($tool, $client->id, $contentHash) || $this->groupMembershipAlreadyExecuted($client->id, $targetKey)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, $person, null, $contentHash, "{$targetKey}: Duplicate {$tool} suppressed before upstream call.", $actorLabel);

            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Already executed identical CIPP write recently; no upstream call was made.',
            ];
        }

        // Shared targetKey-in-summary cooldown helper (same semantics as the
        // email-security and create-user non-person-only targets).
        if ($this->emailSecurityCooldownActive($tool, $client->id, $targetKey, self::COOLDOWNS[$tool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, $person, null, $contentHash, "{$targetKey}: {$tool} cooldown active; upstream call refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no upstream call was made."];
        }

        try {
            $group = $this->groupFactsFromRow($this->verifiedGroupRow($tenant, (string) $params['group_id'], (string) $context['confirm_group_name']));

            // Security-privileged ADDs are held-only whatever mode was granted
            // — the VERIFIED type decides, never the caller's description, and
            // only a cockpit approval can reach upstream (see
            // PRIVILEGED_GROUP_TYPES for why role-assignability forces this).
            if ((string) $params['operation'] === 'add' && in_array($group['type'], self::PRIVILEGED_GROUP_TYPES, true)) {
                throw new CippWriteScopeException('Adding a user to a '.$group['type'].' group is held-only — security-privileged membership can gate resources or admin roles and never executes immediately, whatever mode was granted; call cipp_set_group_membership with staged=true and a ticket_id for cockpit approval.');
            }
        } catch (CippWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $client->id, $ticket, $person, null, $contentHash, "{$targetKey}: ".$e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        try {
            $this->client->setGroupMembership($tenant, (string) $params['group_id'], $group['name'], $group['type'], $person->userId, $person->userPrincipalName, (string) $params['operation']);
        } catch (CippClientException $e) {
            $this->auditAttempt($tool, 'error', $client->id, $ticket, $person, null, $contentHash, "{$targetKey}: ".$this->safeFailureSummary($tool, $e), $actorLabel);

            return ['error' => "CIPP write failed for {$tool}; treat the membership change as not applied."];
        }

        $this->auditAttempt($tool, 'executed', $client->id, $ticket, $person, null, $contentHash, "{$targetKey}: {$tool} executed — ".$this->groupMembershipAuditDetail((string) $params['operation'], $group['name'], (string) $params['group_id']).": {$reason}", $actorLabel);

        return [
            'success' => true,
            'tool' => $tool,
            'person_id' => $person->person->id,
            'ticket_id' => $ticket?->id,
            'group_id' => (string) $params['group_id'],
            'operation' => (string) $params['operation'],
            'message' => 'CIPP group membership change executed.',
        ];
    }

    /**
     * Staged twin for the group-membership write — the DEFAULT path (grants
     * start staged-only). Staging performs the same read-only verification
     * lookup as the direct path (never the write itself) so the cockpit
     * proposal shows the group's REAL server-verified display name and type
     * rather than trusting the caller's description; the held payload stores
     * only safe local scalars plus that verified snapshot, and approval
     * re-verifies everything fresh (see approveGroupMembershipStagedRun).
     *
     * @return array<string, mixed>
     */
    private function stageGroupMembershipAction(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->groupMembershipContext($tool, $arguments, $clientId, $actorLabel, requireTicket: true);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var string $tenant */
        $tenant = $context['tenant'];
        /** @var ResolvedCippPerson $person */
        $person = $context['person'];
        /** @var Ticket $ticket */
        $ticket = $context['ticket'];
        /** @var array<string, mixed> $params */
        $params = $context['params'];
        $reason = (string) $context['reason'];
        $directTool = self::STAGED_TO_DIRECT[$tool];

        $targetKey = $this->groupMembershipTargetKey($person, $params);
        $contentHash = $this->contentHash($tool, $client->id, $person->person->id, $ticket->id, $params);

        if ($this->alreadyExecuted($tool, $client->id, $contentHash)) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $this->executedRunId($tool, $client->id, $contentHash),
                'message' => 'Already executed identical action recently; no new proposal was staged.',
            ];
        }

        $liveAwaitingRun = $this->liveAwaitingRun($ticket->id, $tool, $contentHash);
        if ($liveAwaitingRun !== null) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $liveAwaitingRun->id,
                'message' => 'Already staged; awaiting approval.',
            ];
        }

        if ($this->emailSecurityProposalCooldownActive($tool, $ticket, $targetKey, self::COOLDOWNS[$tool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, $person, null, $contentHash, "{$targetKey}: {$tool} cooldown active; staged proposal refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no proposal was staged."];
        }

        try {
            $group = $this->groupFactsFromRow($this->verifiedGroupRow($tenant, (string) $params['group_id'], (string) $context['confirm_group_name']));
        } catch (CippWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $client->id, $ticket, $person, null, $contentHash, "{$targetKey}: ".$e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        // The stored params carry the verified name/type SNAPSHOT so approval
        // can detect drift (rename, type change) against the fresh listing.
        $storedParams = array_merge($params, ['group_name' => $group['name'], 'group_type' => $group['type']]);

        $meta = [
            'drafted_by' => $actorLabel,
            'reasons' => [$reason],
            'direct_tool' => $directTool,
            'person_id' => $person->person->id,
            'redacted_params' => $storedParams,
            'sensitive_inputs' => [],
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => $directTool,
                'client_id' => $client->id,
                'person_id' => $person->person->id,
                'ticket_id' => $ticket->id,
                'params' => $storedParams,
            ], JSON_THROW_ON_ERROR)),
        ];
        $proposedContent = $this->groupMembershipStagedDisplay($person, (string) $params['operation'], $group, (string) $params['group_id'])."\nReason: ".$reason;

        // Same idempotency-revive contract as stageAction() (bd psa-k4s0): the
        // DB unique key (ticket_id + action_type + content_hash) either creates
        // a fresh run or revives the superseded/denied row it collides with.
        $run = TechnicianRun::firstOrCreate(
            [
                'ticket_id' => $ticket->id,
                'action_type' => $tool,
                'content_hash' => $contentHash,
            ],
            [
                'client_id' => $client->id,
                'state' => TechnicianRunState::AwaitingApproval,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ],
        );

        if (! $run->wasRecentlyCreated && $run->state !== TechnicianRunState::AwaitingApproval) {
            $run->update([
                'state' => TechnicianRunState::AwaitingApproval->value,
                'proposed_content' => $proposedContent,
                'proposed_meta' => $meta,
                'confidence' => null,
                'tokens_used' => 0,
            ]);
        } elseif (! $run->wasRecentlyCreated) {
            return [
                'success' => true,
                'idempotent' => true,
                'ticket_id' => $ticket->id,
                'ticket_display_id' => $ticket->display_id,
                'run_id' => $run->id,
                'message' => 'Already staged; awaiting approval.',
            ];
        }

        $this->auditAttempt($tool, 'awaiting_approval', $client->id, $ticket, $person, null, $contentHash, "{$targetKey}: MCP staged {$tool} — ".$this->groupMembershipAuditDetail((string) $params['operation'], $group['name'], (string) $params['group_id']).": {$reason}", $actorLabel, $run->id);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'message' => 'Staged for cockpit approval.',
        ];
    }

    /**
     * Approval replay for a held group-membership write. The caller has
     * already claimed the run. Everything is revalidated from the encrypted
     * payload through the same gates as the initial call; the target user is
     * re-resolved fresh (an ADD re-runs the ACTIVE gate, so a person
     * deactivated after staging declines instead of being granted group
     * access — psa-pgnj), and the group is re-verified against the LIVE
     * tenant listing with the staged name/type snapshot compared against the
     * fresh row — a renamed, re-typed, or vanished group declines instead of
     * executing against something the operator never reviewed. A re-fired
     * approval of an already-executed identical change — from this ticket or
     * any other — is a LOGGED NO-OP, never a second upstream call.
     */
    private function approveGroupMembershipStagedRun(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        try {
            $payload = $this->decryptRunPayload($run);
            if ($payload === null) {
                $run->releaseClaim();

                return $this->declined('The held payload could not be read; deny this proposal and re-stage it.');
            }

            $directTool = (string) ($payload['direct_tool'] ?? '');
            if ((self::STAGED_TO_DIRECT[$run->action_type] ?? null) !== $directTool
                || ! in_array($directTool, self::GROUP_MEMBERSHIP_TOOLS, true)) {
                $run->releaseClaim();

                return $this->declined('The held payload does not match this action type; deny this proposal and re-stage it.');
            }

            $client = Client::find((int) ($payload['client_id'] ?? 0));
            if (! $client || (int) $client->id !== (int) $run->client_id) {
                $run->releaseClaim();

                return $this->declined('The proposal\'s client could not be re-verified; deny this proposal and re-stage it.');
            }

            $tenant = $this->resolver->resolveCippTenant($client);
            $ticket = $this->resolver->resolveTicketForHeldAction($client->id, $payload['ticket_id'] ?? null);
            $stored = is_array($payload['params'] ?? null) ? $payload['params'] : [];
            $params = $this->groupMembershipParams($stored);

            $person = $this->resolver->resolveCippPerson($client->id, $payload['person_id'] ?? null);
            if ($params['operation'] === 'add') {
                // Fresh ACTIVE re-gate: adding grants access, and the person
                // may have been offboarded between staging and approval.
                $person = $this->resolver->resolveActiveCippPerson($client->id, $person->person->id, 'user');
            }

            $targetKey = $this->groupMembershipTargetKey($person, $params);
            $contentHash = $run->content_hash;

            if (TechnicianConfig::killSwitchEngaged()) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $person, null, $contentHash, "{$targetKey}: Technician kill-switch engaged; staged CIPP write refused.", $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('Technician kill-switch engaged; the staged CIPP write was refused.');
            }

            // Duplicate rail (device-wipe / create-user precedent): an
            // identical user+group+operation that already executed leaves the
            // queue terminally as a logged no-op.
            if ($this->groupMembershipAlreadyExecuted($client->id, $targetKey)) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $person, null, $contentHash, "{$targetKey}: Duplicate group membership change suppressed: identical user/group/operation already executed within ".self::DIRECT_DEDUP_HOURS.'h; the approval was treated as a logged no-op.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->advanceTo(TechnicianRunState::Done);

                return new TechnicianApprovalResult('already_handled');
            }

            if ($this->emailSecurityCooldownActive($directTool, $client->id, $targetKey, self::COOLDOWNS[$directTool] ?? 300)) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, $person, null, $contentHash, "{$targetKey}: CIPP staged action cooldown active; approval refused before upstream call.", $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('A recent action for this target is still in cooldown; wait a few minutes and approve again.');
            }

            $group = $this->groupFactsFromRow($this->verifiedGroupRow($tenant, (string) $params['group_id'], null));

            // Drift rails: the operator approved a proposal naming a specific
            // group; a changed type or name means they reviewed something else.
            $stagedType = trim((string) ($stored['group_type'] ?? ''));
            if ($stagedType === '' || strcasecmp($group['type'], $stagedType) !== 0) {
                $run->releaseClaim();

                return $this->declined('The group type changed after this action was staged; deny this proposal and re-stage it against the current group.');
            }

            $stagedName = trim((string) ($stored['group_name'] ?? ''));
            if ($stagedName === '' || strcasecmp($group['name'], $stagedName) !== 0) {
                $run->releaseClaim();

                return $this->declined('The group display name changed after this action was staged; deny this proposal and re-stage it against the current group.');
            }

            try {
                $this->client->setGroupMembership($tenant, (string) $params['group_id'], $group['name'], $group['type'], $person->userId, $person->userPrincipalName, (string) $params['operation']);
            } catch (CippClientException $e) {
                $this->auditAttempt($run->action_type, 'error', $client->id, $ticket, $person, null, $contentHash, "{$targetKey}: ".$this->safeFailureSummary($run->action_type, $e), $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined($e->getMessage());
            }

            $this->auditAttempt($run->action_type, 'executed', $client->id, $ticket, $person, null, $contentHash, "{$targetKey}: Operator-approved {$run->action_type} executed — ".$this->groupMembershipAuditDetail((string) $params['operation'], $group['name'], (string) $params['group_id']).'.', $this->approverLabel($approverId), $run->id, $approverId);
            $run->advanceTo(TechnicianRunState::Done);

            return new TechnicianApprovalResult('executed');
        } catch (CippWriteScopeException $e) {
            $run->releaseClaim();

            return $this->declined($e->getMessage());
        } catch (\Throwable $e) {
            $run->releaseClaim();

            throw $e;
        }
    }

    /**
     * Shared front door for the group-membership write: the same caller-input
     * gates as context() (upstream-identifier blocklist, required redacted
     * reason, kill-switch, client + tenant + person + ticket resolution,
     * confirm_upn friction) with group-membership parameter validation in
     * place of license/state/mailbox resolution. An ADD re-resolves the
     * target person through the ACTIVE gate (psa-pgnj): group membership
     * grants access to whatever the group carries, and a deactivated person
     * must never be added; a REMOVE deliberately stays on the loose resolver
     * (revoking an already-deactivated user's membership is routine
     * offboarding cleanup). The group itself is NOT resolved here — the live
     * verification read runs after the local dedup/cooldown rails so a
     * refused call never reaches upstream at all.
     *
     * @return array{client?: Client, tenant?: string, person?: ResolvedCippPerson, ticket?: Ticket|null, params?: array<string, mixed>, confirm_group_name?: string, reason?: string, error?: string}
     */
    private function groupMembershipContext(string $tool, array $arguments, int $clientId, string $actorLabel, bool $requireTicket): array
    {
        $contentHash = $this->contentHash($tool, $clientId, null, null, $arguments);

        if ($keys = $this->upstreamIdentifierKeys($arguments)) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'Caller-supplied upstream CIPP identifiers are not accepted: '.implode(', ', $keys).'.', $actorLabel);

            return ['error' => 'Caller-supplied upstream CIPP identifiers are not accepted; provide PSA person_id, the tool\'s own validated parameters, and ticket_id only.'];
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'reason is required.', $actorLabel);

            return ['error' => 'reason is required'];
        }
        $reason = $this->safeReason($tool, $reason, $arguments);

        if (TechnicianConfig::killSwitchEngaged()) {
            $this->auditAttempt($tool, 'blocked', $clientId, null, null, null, $contentHash, 'Technician kill-switch engaged; CIPP MCP write refused.', $actorLabel);

            return ['error' => 'Technician kill-switch engaged; CIPP MCP write refused'];
        }

        $client = Client::find($clientId);
        if (! $client) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'Client not found.', $actorLabel);

            return ['error' => 'Client not found'];
        }

        try {
            $tenant = $this->resolver->resolveCippTenant($client);
            $person = $this->resolver->resolveCippPerson($client->id, $arguments['person_id'] ?? null);
            $ticket = $requireTicket
                ? $this->resolver->resolveTicketForHeldAction($client->id, $arguments['ticket_id'] ?? null)
                : $this->resolver->resolveOptionalTicket($client->id, $arguments['ticket_id'] ?? null);
            $params = $this->groupMembershipParams($arguments);
            if ($params['operation'] === 'add') {
                // Re-resolve through the ACTIVE gate, fresh on every path.
                $person = $this->resolver->resolveActiveCippPerson($client->id, $person->person->id, 'user');
            }
            $confirmGroupName = (string) $this->boundedString($arguments, 'confirm_group_name', self::GROUP_NAME_MAX, required: true);
        } catch (CippWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $client->id, null, null, null, $contentHash, $e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        if ($error = $this->confirmUpnError($arguments, $person)) {
            $this->auditAttempt($tool, 'rejected', $client->id, $ticket, $person, null, $this->contentHash($tool, $client->id, $person->person->id, $ticket?->id, $params), $error, $actorLabel);

            return ['error' => $error];
        }

        return [
            'client' => $client,
            'tenant' => $tenant,
            'person' => $person,
            'ticket' => $ticket,
            'params' => $params,
            'confirm_group_name' => $confirmGroupName,
            'reason' => $reason,
        ];
    }

    /**
     * Validate the group-membership scalar params. Runs on the initial call
     * (against caller arguments) AND on the approval replay (against the
     * decrypted stored payload), so a tampered or drifted payload re-fails
     * the same gates instead of being trusted. group_id is pinned to GUID
     * shape — mail addresses and display names are refused so the
     * verification read can never be fed an ambiguous Exchange identity —
     * and canonicalized to lowercase so casing can never fork the
     * idempotency hash or the dedup/cooldown keys.
     *
     * @return array<string, mixed>
     */
    private function groupMembershipParams(array $source): array
    {
        $operation = $this->canonicalChoice($this->requiredString($source, 'operation'), self::GROUP_MEMBERSHIP_OPERATIONS, 'operation');

        $groupId = $this->requiredString($source, 'group_id');
        if ($groupId === null || preg_match(self::GROUP_ID_PATTERN, $groupId) !== 1) {
            throw new CippWriteScopeException('group_id must be the Microsoft 365 group id (GUID) exactly as returned by the CIPP group reads (e.g. cipp_list_groups).');
        }

        return [
            'operation' => $operation,
            'group_id' => mb_strtolower($groupId),
        ];
    }

    /**
     * The group-membership scope gate: fetch the resolved tenant's LIVE group
     * listing through the same credentialed client the write would use and
     * require the group id to be present in it (quarantine-release
     * precedent). This converts a caller-supplied GUID into a
     * server-verified, tenant-scoped object — a group in any other tenant
     * can never be targeted — and every membership guard reads the VERIFIED
     * row (field names from CIPP-API Invoke-ListGroups.ps1):
     *
     *   - dynamic-membership groups are refused (members are managed by the
     *     membership rule; the manual change would be rejected upstream) —
     *     detected by ANY of dynamicGroupBool, a DynamicMembership
     *     groupTypes entry, or a non-empty membershipRule, so projection
     *     drift cannot fail open;
     *   - on-premises-synced groups are refused (membership is mastered in
     *     AD; Microsoft 365 refuses cloud-side changes);
     *   - the group TYPE must be one CIPP's own projection derives — an
     *     absent or unrecognized type fails closed rather than guessing
     *     which upstream routing arm (Graph vs Exchange) would apply.
     *
     * When $expectedName is given (initial calls), it must match the
     * verified row's displayName.
     *
     * @return array<string, mixed>
     */
    private function verifiedGroupRow(string $tenant, string $groupId, ?string $expectedName): array
    {
        try {
            $rows = $this->client->listGroups($tenant);
        } catch (CippClientException) {
            throw new CippWriteScopeException('Could not verify the group against the tenant\'s live group listing; no membership change was made.');
        }

        foreach ($rows as $row) {
            if (! is_array($row) || strcasecmp(trim((string) ($row['id'] ?? '')), $groupId) !== 0) {
                continue;
            }

            $groupTypes = array_values(array_filter(is_array($row['groupTypes'] ?? null) ? $row['groupTypes'] : [], 'is_string'));
            $isDynamic = (bool) ($row['dynamicGroupBool'] ?? false)
                || in_array('DynamicMembership', $groupTypes, true)
                || trim((string) ($row['membershipRule'] ?? '')) !== '';
            if ($isDynamic) {
                throw new CippWriteScopeException('This group uses dynamic membership; its members are managed by the membership rule, not manually. Adjust the rule in Entra (or pick a non-dynamic group) instead.');
            }

            if (($row['onPremisesSyncEnabled'] ?? null) === true) {
                throw new CippWriteScopeException('This group is synced from on-premises Active Directory; change its membership in AD — cloud-side changes are refused by Microsoft 365.');
            }

            $type = trim((string) ($row['groupType'] ?? ''));
            $recognized = false;
            foreach (self::GROUP_TYPES as $known) {
                if (strcasecmp($type, $known) === 0) {
                    $recognized = true;
                    break;
                }
            }
            if (! $recognized) {
                throw new CippWriteScopeException('The group type could not be determined from the CIPP group listing; membership changes are refused for unrecognized group types.');
            }

            if (trim((string) ($row['displayName'] ?? '')) === '') {
                throw new CippWriteScopeException('The verified group has no display name in the CIPP group listing; refresh the CIPP group reads and retry.');
            }

            if ($expectedName !== null && strcasecmp(trim((string) $row['displayName']), $expectedName) !== 0) {
                throw new CippWriteScopeException('The typed confirm_group_name does not match the verified group display name. CIPP write cancelled.');
            }

            return $row;
        }

        throw new CippWriteScopeException('Group not found in this client tenant\'s live group listing; pass the exact group id returned by the CIPP group reads (e.g. cipp_list_groups).');
    }

    /**
     * Verified-row facts for the upstream body, audit summaries, and the
     * cockpit display. Untrusted external content: control characters are
     * stripped and every value is length-bounded. The type is canonicalized
     * to CIPP's own projection strings so the upstream routing arm can never
     * be forked by casing.
     *
     * @param  array<string, mixed>  $row
     * @return array{name: string, type: string, mail: string}
     */
    private function groupFactsFromRow(array $row): array
    {
        $clean = fn (mixed $value, int $max): string => mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($value) ? (string) $value : '')), 0, $max);

        $type = trim((string) ($row['groupType'] ?? ''));
        foreach (self::GROUP_TYPES as $known) {
            if (strcasecmp($type, $known) === 0) {
                $type = $known;
                break;
            }
        }

        return [
            'name' => $clean($row['displayName'] ?? '', self::GROUP_NAME_MAX),
            'type' => $type,
            'mail' => $clean($row['mail'] ?? '', 254),
        ];
    }

    /**
     * Per-target cooldown/audit correlation key — hash-based like the other
     * non-person-only targets so the audit LIKE matching can never be
     * confused by pattern characters. Keyed on user + group + operation so
     * bulk onboarding (one user into several groups) and offboarding group
     * cleanup (one user out of several groups) are never serialized by the
     * cooldown, and a deliberate add→remove correction is not blocked —
     * while same-operation repeats on the same pair are.
     */
    private function groupMembershipTargetKey(ResolvedCippPerson $person, array $params): string
    {
        return 'group_member #'.substr(hash('sha256', mb_strtolower((string) ($params['group_id'] ?? '')).'|'.$person->person->id.'|'.(string) ($params['operation'] ?? '')), 0, 12);
    }

    /**
     * Whether this exact user + group + operation already executed recently —
     * the double-execution rail (create-user / device-wipe precedent). Keyed
     * on the identity embedded in the executed audit summary, NOT the content
     * hash, so a duplicate staged from a different ticket is caught too.
     */
    private function groupMembershipAlreadyExecuted(int $clientId, string $targetKey): bool
    {
        return TechnicianActionLog::query()
            ->whereIn('action_type', ['cipp_set_group_membership', 'cipp_stage_set_group_membership'])
            ->where('client_id', $clientId)
            ->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->where('summary', 'like', '%'.$targetKey.'%')
            ->exists();
    }

    /** Group identity detail for audit summaries: the group name and id ARE the tenant object being changed — an audit row that hides them would be unreviewable. The person stays a PSA id via the summary prefix. */
    private function groupMembershipAuditDetail(string $operation, string $groupName, string $groupId): string
    {
        return ($operation === 'add' ? 'added to' : 'removed from').' group "'.$groupName.'" (id '.$groupId.')';
    }

    /**
     * @param  array{name: string, type: string, mail: string}  $group
     */
    private function groupMembershipStagedDisplay(ResolvedCippPerson $person, string $operation, array $group, string $groupId): string
    {
        // A membership change is a two-party decision: the approver must see
        // WHO is added to / removed from WHICH group without leaving the
        // queue. The user is named by UPN (a same-client internal address,
        // not a secret) plus PSA id; the group by its server-VERIFIED display
        // name, type, mail, and id — never the caller's description. Only the
        // display carries the UPN; the stored payload and audit stay id-only
        // for the person.
        $userLabel = $person->userPrincipalName.' (PSA person #'.$person->person->id.')';
        $groupLabel = '"'.$group['name'].'" ('.$group['type'].($group['mail'] !== '' ? ', mail '.$group['mail'] : '').', id '.$groupId.')';

        if ($operation === 'add') {
            $privilegeNote = in_array($group['type'], self::PRIVILEGED_GROUP_TYPES, true)
                ? ' This is a SECURITY group: membership can carry access-controlled resources or elevated privileges (role-assignability is not visible in the group listing) — verify what this group grants before approving. This approval is the ONLY path for security-group adds; they never execute immediately.'
                : '';

            return 'Add user '.$userLabel.' to group '.$groupLabel.'.'
                .' Membership grants the user whatever the group carries (shared data, resources, mail).'
                .$privilegeNote
                .' Approval re-verifies the group and the user\'s active status fresh before execution.';
        }

        return 'Remove user '.$userLabel.' from group '.$groupLabel.'.'
            .' The user loses whatever access the group carries.'
            .' Approval re-verifies the group fresh before execution.';
    }

    /**
     * @return array{client?: Client, tenant?: string, person?: ResolvedCippPerson, ticket?: Ticket|null, license?: ResolvedCippLicense|null, state?: string|null, mailbox?: array<string, mixed>|null, reason?: string, error?: string}
     */
    private function context(string $tool, array $arguments, int $clientId, string $actorLabel, bool $requireTicket): array
    {
        $contentHash = $this->contentHash($tool, $clientId, null, null, $arguments);

        if ($keys = $this->upstreamIdentifierKeys($arguments)) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'Caller-supplied upstream CIPP identifiers are not accepted: '.implode(', ', $keys).'.', $actorLabel);

            return ['error' => 'Caller-supplied upstream CIPP identifiers are not accepted; provide PSA person_id, license_type_id, and ticket_id only.'];
        }

        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'reason is required.', $actorLabel);

            return ['error' => 'reason is required'];
        }
        $reason = $this->safeReason($tool, $reason, $arguments);

        if (TechnicianConfig::killSwitchEngaged()) {
            $this->auditAttempt($tool, 'blocked', $clientId, null, null, null, $contentHash, 'Technician kill-switch engaged; CIPP MCP write refused.', $actorLabel);

            return ['error' => 'Technician kill-switch engaged; CIPP MCP write refused'];
        }

        $client = Client::find($clientId);
        if (! $client) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'Client not found.', $actorLabel);

            return ['error' => 'Client not found'];
        }

        try {
            $tenant = $this->resolver->resolveCippTenant($client);
            $person = $this->resolver->resolveCippPerson($client->id, $arguments['person_id'] ?? null);
            $ticket = $requireTicket
                ? $this->resolver->resolveTicketForHeldAction($client->id, $arguments['ticket_id'] ?? null)
                : $this->resolver->resolveOptionalTicket($client->id, $arguments['ticket_id'] ?? null);
            $license = $this->licenseForTool($tool, $client->id, $arguments['license_type_id'] ?? null);
            $state = $this->stateForTool($tool, $arguments['state'] ?? null);
            $mailbox = $this->mailboxParamsForTool($tool, $client->id, $arguments, person: $person);
        } catch (CippWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $client->id, null, null, null, $contentHash, $e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        if ($error = $this->confirmUpnError($arguments, $person)) {
            $this->auditAttempt($tool, 'rejected', $client->id, $ticket, $person, $license, $this->contentHash($tool, $client->id, $person->person->id, $ticket?->id, $this->hashParams($tool, $license, $state, $mailbox)), $error, $actorLabel);

            return ['error' => $error];
        }

        return [
            'client' => $client,
            'tenant' => $tenant,
            'person' => $person,
            'ticket' => $ticket,
            'license' => $license,
            'state' => $state,
            'mailbox' => $mailbox,
            'reason' => $reason,
        ];
    }

    private function executeUpstream(string $tool, string $tenant, ResolvedCippPerson $person, ?ResolvedCippLicense $license, ?string $state, ?array $mailbox): void
    {
        match ($tool) {
            'cipp_disable_user_sign_in' => $this->client->setUserSignInState($tenant, $person->userId, false),
            'cipp_enable_user_sign_in' => $this->client->setUserSignInState($tenant, $person->userId, true),
            'cipp_revoke_user_sessions' => $this->client->revokeUserSessions($tenant, $person->userId, $person->userPrincipalName),
            'cipp_remove_user_mfa_methods' => $this->client->removeUserMfaMethods($tenant, $person->userPrincipalName),
            'cipp_set_legacy_per_user_mfa' => $this->client->setLegacyPerUserMfa($tenant, $person->userPrincipalName, $person->userId, (string) $state),
            'cipp_assign_user_license' => $this->client->assignUserLicense($tenant, $person->userId, (string) $license?->skuId),
            'cipp_remove_user_license' => $this->client->removeUserLicense($tenant, $person->userId, (string) $license?->skuId),
            'cipp_convert_mailbox' => $this->client->convertMailbox($tenant, $person->userPrincipalName, (string) ($mailbox['mailbox_type'] ?? '')),
            'cipp_set_mailbox_forwarding' => $this->executeMailboxForwarding($tenant, $person, $mailbox ?? []),
            'cipp_set_mailbox_gal_visibility' => $this->client->setMailboxGalVisibility($tenant, $person->userPrincipalName, (bool) ($mailbox['hidden'] ?? false)),
            'cipp_set_mailbox_out_of_office' => $this->client->setMailboxOutOfOffice(
                $tenant,
                $person->userPrincipalName,
                (string) ($mailbox['state'] ?? ''),
                $mailbox['internal_message'] ?? null,
                $mailbox['external_message'] ?? null,
                $mailbox['start_time'] ?? null,
                $mailbox['end_time'] ?? null,
                $mailbox['timezone'] ?? null,
            ),
            'cipp_set_mailbox_delegate' => $this->client->setMailboxDelegate(
                $tenant,
                $person->userPrincipalName,
                ($mailbox['delegate_person'] ?? null) instanceof ResolvedCippPerson ? $mailbox['delegate_person']->userPrincipalName : '',
                (string) ($mailbox['permission'] ?? ''),
                (string) ($mailbox['operation'] ?? ''),
                (bool) ($mailbox['auto_map'] ?? true),
            ),
            'cipp_remove_directory_role' => $this->executeDirectoryRoleRemoval($tenant, $person, $mailbox ?? []),
            'cipp_wipe_device' => $this->executeDeviceWipe($tenant, $person, $mailbox ?? []),
            'cipp_reassign_onedrive' => $this->client->reassignOneDriveOwnership(
                $tenant,
                $person->userPrincipalName,
                ($mailbox['successor_person'] ?? null) instanceof ResolvedCippPerson ? $mailbox['successor_person']->userPrincipalName : '',
            ),
            'cipp_edit_user' => $this->client->editUser(
                $tenant,
                $person->userId,
                $person->userPrincipalName,
                $this->editUserSetFields($mailbox ?? []),
                $this->editUserClearProperties($mailbox ?? []),
                ($mailbox['manager_person'] ?? null) instanceof ResolvedCippPerson ? $mailbox['manager_person']->userPrincipalName : null,
            ),
            default => throw new \InvalidArgumentException("Unsupported CIPP write tool {$tool}"),
        };
    }

    /**
     * Execute an approved Intune device wipe/retire. The staged payload carries
     * only safe local scalars (PSA asset id, the action, the server-derived
     * device id snapshot), so the asset is re-resolved fresh here — then the
     * asset↔person pairing is re-proven, and the device identity is re-verified
     * against the staged snapshot AND against the operator's typed
     * confirm_device_id before the single device action is sent. Every guard
     * fails closed as a CippClientException: the approval is declined and
     * audited (its specific reason surfaced to the cockpit toast), and nothing
     * upstream is changed.
     */
    private function executeDeviceWipe(string $tenant, ResolvedCippPerson $person, array $params): void
    {
        $clientId = (int) ($params['client_id'] ?? 0);
        $stagedDeviceId = mb_strtolower(trim((string) ($params['staged_device_id'] ?? '')));
        $action = (string) ($params['wipe_action'] ?? '');
        if ($clientId <= 0 || $stagedDeviceId === '' || $action === '') {
            throw new CippClientException('Device action payload is incomplete; nothing was sent to the device.');
        }

        try {
            $device = $this->resolver->resolveIntuneAsset($clientId, $params['asset_id'] ?? null);
            // Re-prove the pairing at approval: the link that justified staging
            // may be gone by now — the wipe must still demonstrably target the
            // offboarded person's own device, or nothing is sent.
            $this->resolver->assertIntuneAssetBelongsToPerson($device, $person);
        } catch (CippWriteScopeException $e) {
            throw new CippClientException($e->getMessage());
        }

        if (strcasecmp($device->deviceId, $stagedDeviceId) !== 0) {
            throw new CippClientException('The asset\'s Intune device id changed after this action was staged; approval refused. Re-stage against the current device.');
        }

        $typed = trim((string) ($params['confirm_device_id'] ?? ''));
        if ($typed === '' || strcasecmp($typed, $device->deviceId) !== 0) {
            throw new CippClientException('The typed confirm_device_id does not match the target device; the action was refused.');
        }

        $this->client->wipeDevice($tenant, $device->deviceId, $action);
    }

    /**
     * Execute an approved directory-role removal. The staged payload carries
     * only the universal role TEMPLATE id and the typed role name, so the
     * tenant's activated role OBJECT id is re-resolved fresh here — then the
     * resolved display name and the target user's CURRENT membership are
     * re-verified before the single-member removal is sent. Every guard fails
     * closed as a CippClientException: the approval is declined and audited,
     * and nothing upstream is changed.
     */
    private function executeDirectoryRoleRemoval(string $tenant, ResolvedCippPerson $person, array $params): void
    {
        $templateId = (string) ($params['role_template_id'] ?? '');
        $roleName = trim((string) ($params['role_name'] ?? ''));
        if ($templateId === '' || $roleName === '') {
            throw new CippClientException('Directory role removal payload is incomplete; nothing was removed.');
        }

        $match = null;
        foreach ($this->client->listDirectoryRoles($tenant) as $role) {
            if (is_array($role) && strcasecmp(trim((string) ($role['roleTemplateId'] ?? '')), $templateId) === 0) {
                $match = $role;
                break;
            }
        }

        if ($match === null) {
            throw new CippClientException('No activated directory role in this tenant matches the approved role_template_id; nothing was removed.');
        }

        if (strcasecmp(trim((string) ($match['DisplayName'] ?? '')), $roleName) !== 0) {
            throw new CippClientException('The resolved directory role display name does not match the approved role_name; removal refused.');
        }

        $isMember = false;
        foreach (is_array($match['Members'] ?? null) ? $match['Members'] : [] as $member) {
            if (is_array($member) && strcasecmp(trim((string) ($member['id'] ?? '')), $person->userId) === 0) {
                $isMember = true;
                break;
            }
        }

        if (! $isMember) {
            throw new CippClientException('The target user does not currently hold this directory role; nothing was removed.');
        }

        $roleId = trim((string) ($match['Id'] ?? ''));
        if ($roleId === '') {
            throw new CippClientException('The resolved directory role has no object id; nothing was removed.');
        }

        $this->client->removeDirectoryRoleMember($tenant, $roleId, trim((string) $match['DisplayName']), $person->userId, $person->userPrincipalName);
    }

    private function executeMailboxForwarding(string $tenant, ResolvedCippPerson $person, array $mailbox): void
    {
        match ((string) ($mailbox['mode'] ?? '')) {
            'internal' => $this->client->setMailboxForwardingInternal(
                $tenant,
                $person->userPrincipalName,
                $mailbox['target_person'] instanceof ResolvedCippPerson ? $mailbox['target_person']->userPrincipalName : '',
                (bool) ($mailbox['keep_copy'] ?? false),
            ),
            'external' => $this->client->setMailboxForwardingExternal(
                $tenant,
                $person->userPrincipalName,
                (string) ($mailbox['external_smtp'] ?? ''),
                (bool) ($mailbox['keep_copy'] ?? false),
            ),
            'disabled' => $this->client->disableMailboxForwarding($tenant, $person->userPrincipalName),
            default => throw new \InvalidArgumentException('Unsupported mailbox forwarding mode'),
        };
    }

    /** @return array<string, mixed>|null */
    private function mailboxParamsForTool(string $tool, int $clientId, array $arguments, array $approvalInputs = [], bool $heldApproval = false, ?ResolvedCippPerson $person = null): ?array
    {
        $directTool = self::STAGED_TO_DIRECT[$tool] ?? $tool;
        $isHeld = $heldApproval || array_key_exists($tool, self::STAGED_TO_DIRECT);

        return match ($directTool) {
            'cipp_convert_mailbox' => $this->convertMailboxParams($arguments),
            'cipp_set_mailbox_forwarding' => $this->mailboxForwardingParams($clientId, $arguments, $approvalInputs, $isHeld, $heldApproval),
            'cipp_set_mailbox_gal_visibility' => $this->mailboxGalParams($arguments),
            'cipp_set_mailbox_out_of_office' => $this->mailboxOutOfOfficeParams($arguments, $approvalInputs, $isHeld, $heldApproval),
            'cipp_set_mailbox_delegate' => $this->mailboxDelegateParams($clientId, $arguments),
            'cipp_remove_directory_role' => $this->directoryRoleParams($arguments, $isHeld),
            'cipp_wipe_device' => $this->deviceWipeParams($clientId, $arguments, $approvalInputs, $isHeld, $heldApproval, $person),
            'cipp_reassign_onedrive' => $this->oneDriveReassignParams($clientId, $arguments, $isHeld),
            'cipp_edit_user' => $this->editUserParams($clientId, $arguments, $person),
            default => null,
        };
    }

    /**
     * Resolve edit-user params on the initial call AND the held approval
     * replay — the same gates both directions, so a tampered or drifted
     * payload re-fails instead of being trusted. Every returned value is a
     * safe local scalar: bounded, control-character-free field values from
     * the CIPP-form allowlist (EDIT_FIELDS), a validated clear list from the
     * vendor's own clearProperties whitelist (EDIT_CLEARABLE), and the local
     * manager person id. The manager is re-resolved FRESH on each call —
     * ACTIVE-gated (assigning a manager shapes an org relationship, mirroring
     * the delegate/successor gates) and never the target person themself. An
     * empty or non-scalar set-value is refused loudly rather than forwarded:
     * the vendor body-builder silently DROPS empty values, so accepting one
     * would silently no-op — explicit blanking must ride clear_fields.
     * (Through the HTTP layer an empty string already arrives as null —
     * ConvertEmptyStringsToNull — and is treated as omitted; this rail guards
     * the held-replay and any non-HTTP invocation path.)
     *
     * @return array<string, mixed>
     */
    private function editUserParams(int $clientId, array $arguments, ?ResolvedCippPerson $person): array
    {
        $params = [];

        foreach (self::EDIT_FIELDS as $field => [$upstreamKey, $maxLength]) {
            if (! array_key_exists($field, $arguments) || $arguments[$field] === null) {
                continue;
            }

            $value = $this->boundedString($arguments, $field, $maxLength, required: false);
            if ($value === null) {
                throw new CippWriteScopeException("{$field} must be a non-empty string when provided; to blank a field, list it in clear_fields instead.");
            }
            if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
                throw new CippWriteScopeException("{$field} must not contain control characters");
            }
            if ($field === 'usage_location') {
                if (preg_match('/^[a-z]{2}$/i', $value) !== 1) {
                    throw new CippWriteScopeException('usage_location must be a 2-letter ISO 3166-1 country code (e.g. US)');
                }
                $value = strtoupper($value);
            }

            $params[$field] = $value;
        }

        $clears = [];
        if (array_key_exists('clear_fields', $arguments) && $arguments['clear_fields'] !== null && $arguments['clear_fields'] !== []) {
            if (! is_array($arguments['clear_fields']) || ! array_is_list($arguments['clear_fields'])) {
                throw new CippWriteScopeException('clear_fields must be a list of field names');
            }
            foreach ($arguments['clear_fields'] as $field) {
                if (! is_string($field) || ! in_array($field, self::EDIT_CLEARABLE, true)) {
                    throw new CippWriteScopeException('clear_fields entries must be one of: '.implode(', ', self::EDIT_CLEARABLE));
                }
                if (array_key_exists($field, $params)) {
                    throw new CippWriteScopeException("{$field} cannot be both set and cleared in the same call");
                }
                $clears[] = $field;
            }
            // Canonical order so retries that differ only in list order dedup
            // to the same content hash.
            $clears = array_values(array_unique($clears));
            sort($clears);
            $params['clear_fields'] = $clears;
        }

        if (array_key_exists('manager_person_id', $arguments) && $arguments['manager_person_id'] !== null && $arguments['manager_person_id'] !== '') {
            $manager = $this->resolver->resolveActiveCippPerson($clientId, $arguments['manager_person_id'], 'manager');
            if ($person !== null && (int) $manager->person->id === (int) $person->person->id) {
                throw new CippWriteScopeException('The manager must be a different person than the user being edited.');
            }
            $params['manager_person_id'] = $manager->person->id;
            $params['manager_person'] = $manager;
        }

        if ($params === []) {
            throw new CippWriteScopeException('No changes provided. Supply at least one profile field, a clear_fields entry, or manager_person_id.');
        }

        return $params;
    }

    /**
     * Map the validated snake_case set-values onto the upstream UserObj keys
     * for the curated EditUser wrapper. business_phone rides as the single
     * businessPhones entry (Set-CIPPUser wraps it with @(...); the CIPP form
     * itself edits businessPhones[0] only).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function editUserSetFields(array $params): array
    {
        $set = [];
        foreach (self::EDIT_FIELDS as $field => [$upstreamKey, $maxLength]) {
            if (! array_key_exists($field, $params)) {
                continue;
            }

            $value = (string) $params[$field];
            $set[$upstreamKey] = $field === 'business_phone' ? [$value] : $value;
        }

        return $set;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<int, string>
     */
    private function editUserClearProperties(array $params): array
    {
        $clears = [];
        foreach ((array) ($params['clear_fields'] ?? []) as $field) {
            if (is_string($field) && isset(self::EDIT_FIELDS[$field])) {
                $clears[] = self::EDIT_FIELDS[$field][0];
            }
        }

        return $clears;
    }

    /**
     * Resolve device-wipe params on the initial call and the held approval
     * replay. STRUCTURALLY HELD-ONLY (directory-role precedent): an Intune
     * wipe/retire is never directly executable, whatever mode the token was
     * granted — the non-held path throws before any state is touched. The
     * caller identifies the device by PSA asset_id plus a typed
     * confirm_hostname (verified against the resolved asset); the server
     * derives the Intune device id and stores it as a lowercase snapshot so
     * approval can detect identity drift. At approval the operator's typed
     * confirm_device_id rides along for executeDeviceWipe() to verify against
     * the freshly re-resolved device.
     *
     * @return array<string, mixed>
     */
    private function deviceWipeParams(int $clientId, array $arguments, array $approvalInputs, bool $isHeld, bool $heldApproval, ?ResolvedCippPerson $person = null): array
    {
        if (! $isHeld) {
            throw new CippWriteScopeException('Device wipe is held-only; call cipp_wipe_device with staged=true and a ticket_id for cockpit approval.');
        }

        $action = $this->canonicalChoice($this->requiredString($arguments, 'wipe_action'), self::WIPE_ACTIONS, 'wipe_action');

        if ($heldApproval) {
            return [
                'client_id' => $clientId,
                'asset_id' => (int) ($arguments['asset_id'] ?? 0),
                'wipe_action' => $action,
                'staged_device_id' => mb_strtolower(trim((string) ($arguments['staged_device_id'] ?? ''))),
                'confirm_device_id' => trim((string) ($approvalInputs['confirm_device_id'] ?? '')),
            ];
        }

        $device = $this->resolver->resolveIntuneAsset($clientId, $arguments['asset_id'] ?? null);

        // The wipe destroys THE OFFBOARDED PERSON'S device, so the pairing is
        // proven here at staging — and re-proven fresh at approval
        // (executeDeviceWipe) — never assumed from the caller's arguments. An
        // unproven pairing could put person A's name on the cockpit readout
        // while the approval wipes person B's laptop.
        if ($person === null) {
            throw new CippWriteScopeException('Device wipe staging requires a resolved target person.');
        }
        $this->resolver->assertIntuneAssetBelongsToPerson($device, $person);

        $typedHostname = $this->requiredString($arguments, 'confirm_hostname');
        if ($typedHostname === null || strcasecmp($typedHostname, $device->hostname) !== 0) {
            throw new CippWriteScopeException('The typed confirm_hostname does not match the resolved asset hostname. Device wipe cancelled.');
        }

        return [
            'asset_id' => $device->asset->id,
            'wipe_action' => $action,
            'staged_device_id' => $device->deviceId,
            'device' => $device,
        ];
    }

    /**
     * Resolve OneDrive-reassignment params on the initial call and the held
     * approval replay. STRUCTURALLY HELD-ONLY: granting a successor owner
     * access to an entire OneDrive is a data-exposure write that always goes
     * through the cockpit. The successor is a second PSA person in the same
     * client (server-derived UPN, never caller-supplied) and must be ACTIVE —
     * enforced here at staging and again on the approval replay, so a
     * successor deactivated after staging declines instead of receiving the
     * departed user's data (psa-zjpd deep re-review). The offboarded owner
     * may be inactive; that is expected mid-offboarding. Every stored value
     * is a safe local scalar, and the replay re-resolves the successor fresh.
     *
     * @return array<string, mixed>
     */
    private function oneDriveReassignParams(int $clientId, array $arguments, bool $isHeld): array
    {
        if (! $isHeld) {
            throw new CippWriteScopeException('OneDrive ownership reassignment is held-only; call cipp_reassign_onedrive with staged=true and a ticket_id for cockpit approval.');
        }

        $successor = $this->resolver->resolveActiveCippPerson($clientId, $arguments['successor_person_id'] ?? null, 'successor');

        // A self-handover is meaningless for offboarding and would only muddy
        // the held proposal. person_id is present on the initial call, so this
        // rejects before staging; the held-approval replay carries no person_id
        // and never sees one.
        if (array_key_exists('person_id', $arguments) && (int) $arguments['person_id'] === (int) $successor->person->id) {
            throw new CippWriteScopeException('The successor must be a different person than the OneDrive owner.');
        }

        return [
            'successor_person_id' => $successor->person->id,
            'successor_person' => $successor,
        ];
    }

    /**
     * Resolve directory-role removal params on the initial call and the held
     * approval replay. STRUCTURALLY HELD-ONLY (external-forwarding precedent):
     * an admin-role removal is never directly executable, whatever mode the
     * token was granted — the non-held path throws before any state is touched,
     * so the upstream call can only ever be reached through a cockpit approval.
     * The role is identified by its universal Entra role TEMPLATE id (a
     * Microsoft constant surfaced by the CIPP role reads, canonicalized to
     * lowercase so casing cannot fork the idempotency hash) plus a typed
     * role_name confirmation; both are safe local scalars, and execution
     * re-resolves the tenant's activated role object from them at approval.
     *
     * @return array<string, mixed>
     */
    private function directoryRoleParams(array $arguments, bool $isHeld): array
    {
        if (! $isHeld) {
            throw new CippWriteScopeException('Directory role removal is held-only; call cipp_remove_directory_role with staged=true and a ticket_id for cockpit approval.');
        }

        $templateId = $this->requiredString($arguments, 'role_template_id');
        if ($templateId === null || preg_match(self::ROLE_TEMPLATE_ID_PATTERN, $templateId) !== 1) {
            throw new CippWriteScopeException('role_template_id must be a well-formed Entra role template GUID (see the CIPP role reads).');
        }

        return [
            'role_template_id' => mb_strtolower($templateId),
            'role_name' => $this->boundedString($arguments, 'role_name', self::ROLE_NAME_MAX, required: true),
        ];
    }

    /**
     * Resolve delegate-permission params on both the initial call and the held
     * approval replay. The trustee is a second PSA person in the same client
     * (server-derived UPN, never caller-supplied); permission/operation are
     * validated against the closed enums; auto_map defaults on and is consulted
     * only for a FullAccess grant. Every stored value is a safe local scalar, so
     * nothing needs re-entry at approval.
     *
     * A GRANT names the delegate as an access RECIPIENT, so they must be ACTIVE
     * in the PSA — enforced here at staging and again on the approval replay
     * (both calls re-resolve the delegate fresh), mirroring the OneDrive
     * successor gate (psa-zjpd; tightened by bead psa-pgnj). A REMOVE stays on
     * the loose resolver deliberately: revoking access FROM an already-
     * deactivated delegate grants nothing to anyone and is routine offboarding
     * cleanup — gating it would force reactivating a former employee just to
     * revoke them.
     *
     * @return array<string, mixed>
     */
    private function mailboxDelegateParams(int $clientId, array $arguments): array
    {
        $permission = $this->canonicalChoice($this->requiredString($arguments, 'permission'), self::DELEGATE_PERMISSIONS, 'permission');
        $operation = $this->canonicalChoice($this->requiredString($arguments, 'operation'), self::DELEGATE_OPERATIONS, 'operation');
        $delegate = $operation === 'grant'
            ? $this->resolver->resolveActiveCippPerson($clientId, $arguments['delegate_person_id'] ?? null, 'delegate')
            : $this->resolver->resolveCippPerson($clientId, $arguments['delegate_person_id'] ?? null);

        // Self-delegation is an upstream no-op that only muddies the audit trail
        // and the held proposal. person_id is present on the initial call (direct
        // + stage), so a self-delegation is rejected before it can ever stage;
        // the held-approval replay carries no person_id and never sees one.
        if (array_key_exists('person_id', $arguments) && (int) $arguments['person_id'] === (int) $delegate->person->id) {
            throw new CippWriteScopeException('The delegate must be a different person than the mailbox owner.');
        }

        // auto_map changes the upstream call only for a FullAccess grant
        // (AddFullAccess vs AddFullAccessNoAutoMap). Pin it to a constant for
        // every other permission/operation so an inert auto_map value cannot
        // fork the content hash and defeat the idempotent dedup guard.
        $autoMap = ($permission === 'full_access' && $operation === 'grant')
            ? (array_key_exists('auto_map', $arguments) ? $this->booleanValue($arguments['auto_map'], 'auto_map') : true)
            : true;

        return [
            'permission' => $permission,
            'operation' => $operation,
            'auto_map' => $autoMap,
            'delegate_person_id' => $delegate->person->id,
            'delegate_person' => $delegate,
        ];
    }

    /** @return array<string, mixed> */
    private function convertMailboxParams(array $arguments): array
    {
        return [
            'mailbox_type' => $this->canonicalChoice($this->requiredString($arguments, 'mailbox_type'), self::MAILBOX_TYPES, 'mailbox_type'),
        ];
    }

    /**
     * The INTERNAL target deliberately stays on the loose resolver (no
     * is_active gate): M365 shared/resource mailboxes have disabled backing
     * accounts, so contact sync stores them as is_active = false, and
     * forwarding a departed user's mail into a shared mailbox is a mainstream
     * offboarding flow (psa-pgnj product decision). A recipient-type-aware
     * guard is tracked separately as psa-24db.
     *
     * @return array<string, mixed>
     */
    private function mailboxForwardingParams(int $clientId, array $arguments, array $approvalInputs, bool $isHeld, bool $heldApproval): array
    {
        $mode = mb_strtolower((string) $this->requiredString($arguments, 'mode'));
        if ($mode === '') {
            throw new CippWriteScopeException('mode is required');
        }

        $allowed = $isHeld ? self::STAGED_FORWARDING_MODES : self::DIRECT_FORWARDING_MODES;
        if (! in_array($mode, $allowed, true)) {
            if ($mode === 'external') {
                throw new CippWriteScopeException('External SMTP forwarding is held-only; use cipp_stage_set_mailbox_forwarding with ticket_id for cockpit approval.');
            }

            throw new CippWriteScopeException('mode must be one of: '.implode(', ', $allowed));
        }

        $params = [
            'mode' => $mode,
            'keep_copy' => $this->booleanValue($arguments['keep_copy'] ?? false, 'keep_copy'),
        ];

        if ($mode === 'internal') {
            $target = $this->resolver->resolveCippPerson($clientId, $arguments['target_person_id'] ?? null);
            $params['target_person_id'] = $target->person->id;
            $params['target_person'] = $target;
        }

        if ($mode === 'external') {
            $source = $heldApproval ? $approvalInputs : $arguments;
            $externalSmtp = $this->externalSmtpAddress($source['external_smtp'] ?? null);
            $domain = $this->domainFromEmail($externalSmtp);
            if ($heldApproval && isset($arguments['external_domain']) && strcasecmp((string) $arguments['external_domain'], $domain) !== 0) {
                throw new CippWriteScopeException('Approved external forwarding domain does not match the staged domain');
            }

            $params['external_domain'] = $domain;
            if ($heldApproval) {
                $params['external_smtp'] = $externalSmtp;
            }
        }

        return $params;
    }

    /** @return array<string, mixed> */
    private function mailboxGalParams(array $arguments): array
    {
        return [
            'hidden' => $this->booleanValue($arguments['hidden'] ?? null, 'hidden'),
        ];
    }

    /** @return array<string, mixed> */
    private function mailboxOutOfOfficeParams(array $arguments, array $approvalInputs, bool $isHeld, bool $heldApproval): array
    {
        $state = $this->canonicalChoice($this->requiredString($arguments, 'state'), self::OOO_STATES, 'state');
        $params = ['state' => $state];

        if ($state === 'Scheduled') {
            $params['start_time'] = $this->boundedString($arguments, 'start_time', 100, required: true);
            $params['end_time'] = $this->boundedString($arguments, 'end_time', 100, required: true);
        }

        $timezone = $this->boundedString($arguments, 'timezone', 100, required: false);
        if ($timezone !== null) {
            $params['timezone'] = $timezone;
        }

        if ($state === 'Disabled') {
            return $params;
        }

        $source = $heldApproval ? $approvalInputs : $arguments;
        $internalMessage = $this->boundedString($source, 'internal_message', self::OOO_MESSAGE_MAX, required: true);
        $externalMessage = $this->boundedString($source, 'external_message', self::OOO_MESSAGE_MAX, required: true);

        $params['internal_message_length'] = mb_strlen($internalMessage);
        $params['external_message_length'] = mb_strlen($externalMessage);

        if (! $isHeld || $heldApproval) {
            $params['internal_message'] = $internalMessage;
            $params['external_message'] = $externalMessage;
        }

        return $params;
    }

    private function licenseForTool(string $tool, int $clientId, mixed $licenseTypeId): ?ResolvedCippLicense
    {
        $directTool = self::STAGED_TO_DIRECT[$tool] ?? $tool;
        if (! in_array($directTool, ['cipp_assign_user_license', 'cipp_remove_user_license'], true)) {
            return null;
        }

        return $this->resolver->resolveCippLicense($clientId, $licenseTypeId);
    }

    private function stateForTool(string $tool, mixed $state): ?string
    {
        $directTool = self::STAGED_TO_DIRECT[$tool] ?? $tool;
        if ($directTool !== 'cipp_set_legacy_per_user_mfa') {
            return null;
        }

        if (! is_string($state)) {
            throw new CippWriteScopeException('state is required');
        }

        $normalized = mb_strtolower(trim($state));
        if (! in_array($normalized, ['disabled', 'enabled', 'enforced'], true)) {
            throw new CippWriteScopeException('state must be one of: disabled, enabled, enforced');
        }

        return $normalized;
    }

    private function confirmUpnError(array $arguments, ResolvedCippPerson $person): ?string
    {
        $typed = $this->requiredString($arguments, 'confirm_upn');
        if ($typed === null || strcasecmp($typed, $person->userPrincipalName) !== 0) {
            return 'The typed confirm_upn does not match the resolved CIPP user. CIPP write cancelled.';
        }

        return null;
    }

    private function safeReason(string $tool, string $reason, array $arguments): string
    {
        $directTool = self::STAGED_TO_DIRECT[$tool] ?? $tool;
        $safe = $this->redactor->redactString($reason);

        if ($directTool === 'cipp_set_mailbox_forwarding') {
            if (isset($arguments['external_smtp']) && is_scalar($arguments['external_smtp'])) {
                $safe = str_replace((string) $arguments['external_smtp'], '[external address withheld]', $safe);
            }

            if (mb_strtolower((string) ($arguments['mode'] ?? '')) === 'external') {
                $safe = \App\Support\EmailRedactor::redact($safe);
            }
        }

        if ($directTool === 'cipp_set_mailbox_out_of_office') {
            foreach (['internal_message', 'external_message'] as $key) {
                if (isset($arguments[$key]) && is_scalar($arguments[$key])) {
                    $value = trim((string) $arguments[$key]);
                    if ($value !== '') {
                        $safe = str_replace($value, "[{$key} withheld]", $safe);
                    }
                }
            }
        }

        return $safe;
    }

    private function canonicalChoice(?string $value, array $allowed, string $field): string
    {
        if ($value === null) {
            throw new CippWriteScopeException("{$field} is required");
        }

        foreach ($allowed as $choice) {
            if (strcasecmp($value, $choice) === 0) {
                return $choice;
            }
        }

        throw new CippWriteScopeException("{$field} must be one of: ".implode(', ', $allowed));
    }

    private function booleanValue(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));
            if (in_array($normalized, ['true', '1'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', '0'], true)) {
                return false;
            }
        }

        throw new CippWriteScopeException("{$field} must be true or false");
    }

    private function boundedString(array $arguments, string $field, int $maxLength, bool $required): ?string
    {
        $value = $this->requiredString($arguments, $field);
        if ($value === null) {
            if ($required) {
                throw new CippWriteScopeException("{$field} is required");
            }

            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new CippWriteScopeException("{$field} must be {$maxLength} characters or fewer");
        }

        return $value;
    }

    private function externalSmtpAddress(mixed $value): string
    {
        if (! is_scalar($value)) {
            throw new CippWriteScopeException('external_smtp is required for external forwarding');
        }

        $email = trim((string) $value);
        if ($email === '' || mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new CippWriteScopeException('external_smtp must be a valid SMTP address');
        }

        return $email;
    }

    private function domainFromEmail(string $email): string
    {
        $domain = mb_strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '') {
            throw new CippWriteScopeException('external_smtp must include a domain');
        }

        return $domain;
    }

    /** @return array<int, string> */
    private function upstreamIdentifierKeys(array $arguments): array
    {
        $keys = [];
        foreach (self::UPSTREAM_IDENTIFIER_KEYS as $key) {
            if (array_key_exists($key, $arguments)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function alreadyExecuted(string $tool, int $clientId, string $contentHash): bool
    {
        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('client_id', $clientId)
            ->where('content_hash', $contentHash)
            ->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->exists();
    }

    /** The run_id of the most recent matching EXECUTED audit row, if any (bd psa-k4s0: never surface idempotent:true with a null run_id). */
    private function executedRunId(string $tool, int $clientId, string $contentHash): ?int
    {
        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('client_id', $clientId)
            ->where('content_hash', $contentHash)
            ->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->latest('id')
            ->value('run_id');
    }

    /**
     * Whether this exact DEVICE + action already executed recently — the
     * double-wipe rail (bead psa-zjpd). Keyed on the device identity embedded
     * in the executed audit summary (see executedAuditSuffix), NOT the content
     * hash, so duplicates staged from other tickets are caught too. The device
     * id is a validated GUID and the action a closed enum, so neither can
     * carry LIKE wildcards.
     */
    private function deviceWipeAlreadyExecuted(int $clientId, string $deviceId, string $action): bool
    {
        return TechnicianActionLog::query()
            ->whereIn('action_type', ['cipp_wipe_device', 'cipp_stage_wipe_device'])
            ->where('client_id', $clientId)
            ->where('result_status', 'executed')
            ->where('created_at', '>=', now()->subHours(self::DIRECT_DEDUP_HOURS))
            ->where('summary', 'like', '%device '.$deviceId.' ('.$action.')%')
            ->exists();
    }

    /**
     * Per-tool detail appended to the approve-path "executed" audit summary.
     * For device actions this embeds the id-only device identity + action that
     * deviceWipeAlreadyExecuted() keys the double-wipe rail on.
     */
    private function executedAuditSuffix(string $directTool, ?array $params): string
    {
        if ($directTool === 'cipp_wipe_device' && is_array($params)) {
            $deviceId = (string) ($params['staged_device_id'] ?? '');
            $action = (string) ($params['wipe_action'] ?? '');

            return ' device '.($deviceId !== '' ? $deviceId : 'unknown').' ('.($action !== '' ? $action : 'unknown').').';
        }

        return '';
    }

    /**
     * The single source of truth for "is there a live staged run awaiting approval right
     * now" — the runs table, NEVER the (immutable) audit log (bd psa-k4s0 Root B).
     */
    private function liveAwaitingRun(int $ticketId, string $tool, string $contentHash): ?TechnicianRun
    {
        return TechnicianRun::query()
            ->where('ticket_id', $ticketId)
            ->where('action_type', $tool)
            ->where('content_hash', $contentHash)
            ->where('state', TechnicianRunState::AwaitingApproval->value)
            ->first();
    }

    private function cooldownActive(string $tool, int $clientId, ResolvedCippPerson $person, ?ResolvedCippLicense $license, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('client_id', $clientId)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->whereIn('result_status', ['executed', 'awaiting_approval'])
            ->where('summary', 'like', '%'.$this->targetKey($person, $license).'%')
            ->exists();
    }

    /**
     * Password-reset cooldown, checked across BOTH execution paths (security review
     * psa-eerg4 R2).
     *
     * cooldownActive() filters action_type to one exact name. A DIRECT reset audits as
     * cipp_reset_user_password, but a HELD approval audits as
     * cipp_stage_reset_user_password (auditAttempt uses $run->action_type, which is
     * correct provenance — the audit should record which path ran). So a single-name
     * lookup is asymmetric: it catches direct→held, but a held approval is invisible to
     * a later direct reset or to another held reset from a different ticket, and a
     * second credential can be minted inside the window the cooldown exists to close.
     *
     * Matches EXECUTED rows only, deliberately: an awaiting_approval row has not minted
     * a password, and counting it would make a proposal block its own approval.
     */
    private function resetCooldownActive(int $clientId, ResolvedCippPerson $person, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()
            ->whereIn('action_type', ['cipp_reset_user_password', 'cipp_stage_reset_user_password'])
            ->where('client_id', $clientId)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->where('result_status', 'executed')
            ->where('summary', 'like', '%'.$this->targetKey($person, null).'%')
            ->exists();
    }

    private function proposalCooldownActive(string $tool, Ticket $ticket, ResolvedCippPerson $person, ?ResolvedCippLicense $license, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        return TechnicianActionLog::query()
            ->where('action_type', $tool)
            ->where('ticket_id', $ticket->id)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->whereIn('result_status', ['awaiting_approval', 'executed'])
            ->where('summary', 'like', '%'.$this->targetKey($person, $license).'%')
            ->exists();
    }

    private function auditAttempt(
        string $actionType,
        string $resultStatus,
        ?int $clientId,
        ?Ticket $ticket,
        ?ResolvedCippPerson $person,
        ?ResolvedCippLicense $license,
        string $contentHash,
        string $summary,
        string $actorLabel,
        ?int $runId = null,
        ?int $approverId = null,
    ): void {
        TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::aiActorUserId(),
            'approver_user_id' => $approverId,
            'actor_label' => $actorLabel,
            'action_type' => $actionType,
            'tier' => TechnicianTier::Approve->value,
            'result_status' => $resultStatus,
            'ticket_id' => $ticket?->id,
            'client_id' => $clientId,
            'run_id' => $runId,
            'content_hash' => $contentHash,
            'summary' => mb_substr($this->redactor->redactString($this->summaryWithTarget($summary, $person, $license)), 0, 1000),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }

    private function decryptRunPayload(TechnicianRun $run): ?array
    {
        $ciphertext = $run->proposed_meta['encrypted_payload'] ?? null;
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        $json = Crypt::decryptString($ciphertext);
        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    /** @return array<string, mixed> */
    private function hashParams(string $tool, ?ResolvedCippLicense $license, ?string $state, ?array $mailbox): array
    {
        $params = [];
        if ($license !== null) {
            $params['license_type_id'] = $license->licenseType->id;
        }
        if ($state !== null) {
            $params['state'] = $state;
        }
        if ($mailbox !== null) {
            $params = array_merge($params, $this->safeMailboxParams($mailbox));
        }

        return $params;
    }

    /** @return array<string, mixed> */
    private function safeMailboxParams(array $mailbox): array
    {
        $safe = [];
        foreach ([
            'mailbox_type',
            'mode',
            'target_person_id',
            'keep_copy',
            'external_domain',
            'hidden',
            'state',
            'internal_message_length',
            'external_message_length',
            'start_time',
            'end_time',
            'timezone',
            'permission',
            'operation',
            'auto_map',
            'delegate_person_id',
            'role_template_id',
            'role_name',
            'asset_id',
            'wipe_action',
            'staged_device_id',
            'successor_person_id',
            'display_name',
            'given_name',
            'surname',
            'job_title',
            'department',
            'company_name',
            'street_address',
            'city',
            'postal_code',
            'country',
            'mobile_phone',
            'business_phone',
            'usage_location',
            'clear_fields',
            'manager_person_id',
        ] as $key) {
            if (array_key_exists($key, $mailbox)) {
                $safe[$key] = $mailbox[$key];
            }
        }

        return $safe;
    }

    /** @return array<int, string> */
    private function sensitiveInputsForStagedAction(string $directTool, array $safeParams): array
    {
        $inputs = [];
        if ($directTool === 'cipp_set_mailbox_forwarding' && ($safeParams['mode'] ?? null) === 'external') {
            $inputs[] = 'external_smtp';
        }

        if ($directTool === 'cipp_set_mailbox_out_of_office' && in_array($safeParams['state'] ?? null, ['Enabled', 'Scheduled'], true)) {
            $inputs[] = 'internal_message';
            $inputs[] = 'external_message';
        }

        // The approver must re-type the exact Intune device id before a wipe or
        // retire executes — the strictest confirm friction on the surface.
        if ($directTool === 'cipp_wipe_device') {
            $inputs[] = 'confirm_device_id';
        }

        return $inputs;
    }

    private function contentHash(string $tool, int $clientId, ?int $personId, ?int $ticketId, array $params): string
    {
        return hash('sha256', json_encode([
            'tool' => $tool,
            'client_id' => $clientId,
            'person_id' => $personId,
            'ticket_id' => $ticketId,
            'params' => $this->canonical($this->safeHashParams($params)),
        ]));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }

    /** @return array<string, mixed> */
    private function safeHashParams(array $params): array
    {
        $safe = $params;
        foreach (self::UPSTREAM_IDENTIFIER_KEYS as $key) {
            unset($safe[$key]);
        }
        unset($safe['confirm_upn'], $safe['confirm_hostname'], $safe['confirm_device_id'], $safe['reason']);

        return $safe;
    }

    private function requiredString(array $arguments, string $key): ?string
    {
        if (! array_key_exists($key, $arguments) || ! is_scalar($arguments[$key])) {
            return null;
        }

        $value = trim((string) $arguments[$key]);

        return $value !== '' ? $value : null;
    }

    private function targetKey(?ResolvedCippPerson $person, ?ResolvedCippLicense $license): string
    {
        if ($person === null) {
            return 'person #unknown';
        }

        $key = 'person #'.$person->person->id;
        if ($license !== null) {
            $key .= ' license_type #'.$license->licenseType->id;
        }

        return $key;
    }

    private function summaryWithTarget(string $summary, ?ResolvedCippPerson $person, ?ResolvedCippLicense $license): string
    {
        if ($person === null) {
            return $summary;
        }

        return $this->targetKey($person, $license).': '.$summary;
    }

    private function stagedDisplay(string $directTool, ResolvedCippPerson $person, ?ResolvedCippLicense $license, ?string $state, ?array $mailbox): string
    {
        return match ($directTool) {
            'cipp_disable_user_sign_in' => 'Disable sign-in for PSA person #'.$person->person->id.'.',
            'cipp_enable_user_sign_in' => 'Enable sign-in for PSA person #'.$person->person->id.'.',
            'cipp_revoke_user_sessions' => 'Revoke active sessions for PSA person #'.$person->person->id.'.',
            'cipp_remove_user_mfa_methods' => 'Remove MFA methods for PSA person #'.$person->person->id.'.',
            'cipp_set_legacy_per_user_mfa' => 'Set legacy per-user MFA to '.$state.' for PSA person #'.$person->person->id.'.',
            'cipp_assign_user_license' => 'Assign license_type #'.$license?->licenseType->id.' to PSA person #'.$person->person->id.'.',
            'cipp_remove_user_license' => 'Remove license_type #'.$license?->licenseType->id.' from PSA person #'.$person->person->id.'.',
            'cipp_convert_mailbox' => 'Convert mailbox for PSA person #'.$person->person->id.' to '.($mailbox['mailbox_type'] ?? 'unknown').'. Shared mailbox conversion can change licensing obligations.',
            'cipp_set_mailbox_forwarding' => $this->mailboxForwardingDisplay($person, $mailbox ?? []),
            'cipp_set_mailbox_gal_visibility' => 'Set GAL visibility for PSA person #'.$person->person->id.' to '.((bool) ($mailbox['hidden'] ?? false) ? 'hidden' : 'visible').'.',
            'cipp_set_mailbox_out_of_office' => $this->mailboxOutOfOfficeDisplay($person, $mailbox ?? []),
            'cipp_set_mailbox_delegate' => $this->mailboxDelegateDisplay($person, $mailbox ?? []),
            'cipp_remove_directory_role' => $this->directoryRoleDisplay($person, $mailbox ?? []),
            'cipp_wipe_device' => $this->deviceWipeDisplay($person, $mailbox ?? []),
            'cipp_reassign_onedrive' => $this->oneDriveReassignDisplay($person, $mailbox ?? []),
            'cipp_edit_user' => $this->editUserDisplay($person, $mailbox ?? []),
            default => $directTool.' for PSA person #'.$person->person->id.'.',
        };
    }

    /**
     * The approver reviews EXACTLY what will be written: every set-value
     * verbatim (validated, bounded, control-character-free), every explicit
     * clear, and the manager by UPN plus PSA id (two-party display, mirroring
     * the delegate/successor readouts — only the display carries UPNs; the
     * stored payload and audit summaries stay id-only). Hybrid users get the
     * CIPP form's own on-prem warning: Entra edits to an AD-synced user can
     * be overwritten by (or conflict with) the on-prem sync.
     */
    private function editUserDisplay(ResolvedCippPerson $person, array $params): string
    {
        $changes = [];
        foreach (self::EDIT_FIELDS as $field => [$upstreamKey, $maxLength]) {
            if (array_key_exists($field, $params)) {
                $changes[] = 'set '.$field.' = "'.$params[$field].'"';
            }
        }
        foreach ((array) ($params['clear_fields'] ?? []) as $field) {
            $changes[] = 'clear '.(is_scalar($field) ? (string) $field : '');
        }

        $manager = ($params['manager_person'] ?? null) instanceof ResolvedCippPerson ? $params['manager_person'] : null;
        if ($manager !== null) {
            $changes[] = 'set manager = '.$manager->userPrincipalName.' (PSA person #'.$manager->person->id.')';
        } elseif (isset($params['manager_person_id'])) {
            $changes[] = 'set manager = PSA person #'.$params['manager_person_id'];
        }

        $display = 'Edit the Microsoft 365 profile of '.$person->userPrincipalName.' (PSA person #'.$person->person->id.'): '
            .implode('; ', $changes).'.'
            .' Null-safe partial update — only the listed fields change; everything else is left untouched, and the sign-in UPN stays pinned to the current value.';

        if ($person->person->is_hybrid) {
            $display .= ' WARNING: this user appears to be synced from on-premises Active Directory — Entra profile edits may be overwritten by (or conflict with) the on-prem sync; prefer editing in on-prem AD.';
        }

        return $display;
    }

    private function deviceWipeDisplay(ResolvedCippPerson $person, array $params): string
    {
        // The blast radius must be unmistakable in the queue: the exact device
        // (hostname + Intune id + PSA asset id), the action, and what it
        // destroys. Only the display carries the hostname — the stored payload
        // and audit summaries stay id-only.
        $device = ($params['device'] ?? null) instanceof ResolvedIntuneDevice ? $params['device'] : null;
        $hostname = $device?->hostname ?? 'unknown';
        $deviceId = $device?->deviceId ?? (string) ($params['staged_device_id'] ?? 'unknown');
        $action = (string) ($params['wipe_action'] ?? 'unknown');

        $consequence = $action === 'retire'
            ? 'Retire removes company data and unenrolls the device from Intune; personal data is kept.'
            : 'Wipe FACTORY-RESETS the device and permanently destroys local data.';

        return 'IRREVERSIBLE DEVICE '.mb_strtoupper($action).': target device hostname "'.$hostname.'" — Intune device id '.$deviceId
            .' (PSA asset #'.($params['asset_id'] ?? 'unknown').'), user '.$person->userPrincipalName.' (PSA person #'.$person->person->id.'). '
            .$consequence
            .' Held-only: approval re-verifies the device identity and that the device belongs to this person, and the approver must type the exact Intune device id to execute. A completed action is never re-issued.';
    }

    private function oneDriveReassignDisplay(ResolvedCippPerson $person, array $params): string
    {
        // A OneDrive handover is a two-party decision: the approver must see WHO
        // gains owner access to WHOSE OneDrive without leaving the queue. Name
        // both parties by UPN plus PSA id; only the display carries the UPNs.
        $successor = ($params['successor_person'] ?? null) instanceof ResolvedCippPerson ? $params['successor_person'] : null;
        $successorLabel = $successor !== null
            ? $successor->userPrincipalName.' (PSA person #'.$successor->person->id.')'
            : 'PSA successor person #'.($params['successor_person_id'] ?? 'unknown');

        return 'Reassign OneDrive ownership: grant successor '.$successorLabel.' owner (site admin) access to the entire OneDrive of '
            .$person->userPrincipalName.' (PSA person #'.$person->person->id.').'
            .' Held-only: approval re-resolves both identities before execution. The offboarded user\'s own access is not modified by this action.';
    }

    private function directoryRoleDisplay(ResolvedCippPerson $person, array $params): string
    {
        // An admin-role removal is only reviewable if the approver can see WHO
        // loses WHICH role: name the target by UPN (a same-client internal
        // address, not a secret) plus the PSA id, and the role by its typed
        // name plus the universal template GUID. Only the display carries the
        // UPN — the stored payload and audit summary stay id-only.
        return 'Remove Entra directory role "'.($params['role_name'] ?? 'unknown').'"'
            .' (template '.($params['role_template_id'] ?? 'unknown').')'
            .' from '.$person->userPrincipalName.' (PSA person #'.$person->person->id.').'
            .' Held-only: approval re-resolves the tenant role and re-verifies the user\'s membership before execution. License assignments are not touched.';
    }

    private function mailboxDelegateDisplay(ResolvedCippPerson $person, array $mailbox): string
    {
        $operation = (string) ($mailbox['operation'] ?? '');
        $permission = (string) ($mailbox['permission'] ?? '');
        $delegate = ($mailbox['delegate_person'] ?? null) instanceof ResolvedCippPerson ? $mailbox['delegate_person'] : null;

        // A mailbox-access grant is a two-party decision, so the cockpit approver
        // must be able to verify WHO gains access to WHOSE mailbox without leaving
        // the queue. Name both parties by UPN (a same-client internal address, not
        // a secret) plus the PSA id. Only the display carries the UPN — the stored
        // encrypted payload and audit summary stay id-only.
        $delegateLabel = $delegate !== null
            ? $delegate->userPrincipalName.' (PSA person #'.$delegate->person->id.')'
            : 'PSA delegate person #'.($mailbox['delegate_person_id'] ?? 'unknown');
        $ownerLabel = $person->userPrincipalName.' (PSA person #'.$person->person->id.')';

        $verb = $operation === 'grant' ? 'Grant' : 'Remove';
        $preposition = $operation === 'grant' ? 'on the mailbox of' : 'from the mailbox of';
        $display = $verb.' '.$permission.' for delegate '.$delegateLabel.' '.$preposition.' '.$ownerLabel.'.';

        if ($permission === 'full_access' && $operation === 'grant') {
            $display .= ' auto_map='.((bool) ($mailbox['auto_map'] ?? true) ? 'true' : 'false').'.';
        }

        return $display;
    }

    private function mailboxForwardingDisplay(ResolvedCippPerson $person, array $mailbox): string
    {
        return match ((string) ($mailbox['mode'] ?? '')) {
            'disabled' => 'Disable mailbox forwarding for PSA person #'.$person->person->id.'.',
            'internal' => 'Set mailbox forwarding for PSA person #'.$person->person->id.' to PSA target person #'.($mailbox['target_person_id'] ?? 'unknown').' (keep copy '.((bool) ($mailbox['keep_copy'] ?? false) ? 'true' : 'false').').',
            'external' => 'Set external SMTP mailbox forwarding for PSA person #'.$person->person->id.' to domain '.($mailbox['external_domain'] ?? 'unknown').' (full address re-entered at approval; keep copy '.((bool) ($mailbox['keep_copy'] ?? false) ? 'true' : 'false').').',
            default => 'Set mailbox forwarding for PSA person #'.$person->person->id.'.',
        };
    }

    private function mailboxOutOfOfficeDisplay(ResolvedCippPerson $person, array $mailbox): string
    {
        $display = 'Set mailbox out-of-office for PSA person #'.$person->person->id.' to '.($mailbox['state'] ?? 'unknown').'.';
        if (isset($mailbox['internal_message_length'], $mailbox['external_message_length'])) {
            $display .= ' internal_message_length='.$mailbox['internal_message_length'].'; external_message_length='.$mailbox['external_message_length'].'.';
        }
        if (($mailbox['state'] ?? null) === 'Scheduled') {
            $display .= ' start='.$mailbox['start_time'].'; end='.$mailbox['end_time'].'.';
        }

        return $display;
    }

    private function safeFailureSummary(string $tool, CippClientException $e): string
    {
        return "{$tool} failed before completion: ".mb_substr($this->redactor->redactString($e->getMessage()), 0, 300);
    }

    /**
     * A gate_declined result that carries WHY, so the cockpit toast can show
     * the operator the actual recoverable cause (typed-id mismatch, identity
     * drift, lost mapping or link, kill-switch, cooldown, upstream refusal)
     * instead of a generic dead end (psa-zjpd deep-review). Redacted and
     * bounded exactly like the audit summaries — the surfaced reason never
     * says more than the immutable log does.
     */
    private function declined(string $reason): TechnicianApprovalResult
    {
        return new TechnicianApprovalResult('gate_declined', message: mb_substr($this->redactor->redactString($reason), 0, 300));
    }

    private function approverLabel(int $approverId): string
    {
        $user = User::find($approverId);

        return $user?->email ?? $user?->name ?? "approver:{$approverId}";
    }

    /** @return array<string, mixed> */
    private static function personProperties(bool $ticket = false): array
    {
        $properties = [
            'person_id' => [
                'type' => 'integer',
                'description' => 'PSA person ID. The server verifies it belongs to client_id and derives the CIPP user id and UPN.',
            ],
            'confirm_upn' => [
                'type' => 'string',
                'description' => 'Typed UPN confirmation for defense-in-depth. The server still derives the actual upstream user identity from person_id.',
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'Specific operational reason for this CIPP write.',
            ],
        ];

        $properties['ticket_id'] = [
            'type' => 'integer',
            'description' => $ticket
                ? 'Required ticket ID for cockpit-held actions. The server verifies it belongs to client_id.'
                : 'Optional ticket ID for incident attribution. The server verifies it belongs to client_id when supplied.',
        ];

        return $properties;
    }

    /** @return array<string, mixed> */
    private static function licenseProperties(): array
    {
        return [
            'license_type_id' => [
                'type' => 'integer',
                'description' => 'Local PSA license_types.id for a CIPP M365 SKU. The server derives the upstream SKU from synced license rows.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function stateProperties(): array
    {
        return [
            'state' => [
                'type' => 'string',
                'enum' => ['disabled', 'enabled', 'enforced'],
                'description' => 'Legacy per-user MFA state to set.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function mailboxTypeProperties(): array
    {
        return [
            'mailbox_type' => [
                'type' => 'string',
                'enum' => self::MAILBOX_TYPES,
                'description' => 'Mailbox recipient type to set through the curated CIPP ExecConvertMailbox wrapper.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function forwardingProperties(bool $stage): array
    {
        $properties = [
            'mode' => [
                'type' => 'string',
                'enum' => $stage ? self::STAGED_FORWARDING_MODES : self::DIRECT_FORWARDING_MODES,
                'description' => $stage
                    ? 'Forwarding mode. External SMTP forwarding is staged only and requires approval.'
                    : 'Forwarding mode. Direct execution supports only disabled or internal.',
            ],
            'target_person_id' => [
                'type' => 'integer',
                'description' => 'Required when mode=internal. Local PSA person ID in the same client; the server derives the internal forwarding target UPN.',
            ],
            'keep_copy' => [
                'type' => 'boolean',
                'description' => 'Whether Exchange should also keep delivered mail in the source mailbox when forwarding is enabled.',
            ],
        ];

        if ($stage) {
            $properties['external_smtp'] = [
                'type' => 'string',
                'description' => 'Required when mode=external. Validated for the proposal, reduced to domain for storage/audit, then re-entered by the approver before execution.',
            ];
        }

        return $properties;
    }

    /** @return array<string, mixed> */
    private static function galVisibilityProperties(): array
    {
        return [
            'hidden' => [
                'type' => 'boolean',
                'description' => 'true hides the mailbox from the Global Address List; false makes it visible.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function resetUserPasswordProperties(): array
    {
        return [
            'must_change' => [
                'type' => 'boolean',
                'description' => 'Whether the user must change the password at next sign-in. Defaults to true (the temporary-password method). Set false only for a deliberate permanent reset.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function outOfOfficeProperties(): array
    {
        return [
            'state' => [
                'type' => 'string',
                'enum' => self::OOO_STATES,
                'description' => 'Out-of-office auto-reply state.',
            ],
            'internal_message' => [
                'type' => 'string',
                'description' => 'Required for Enabled or Scheduled. Max 2000 characters; body is sent to CIPP but only length is audited.',
            ],
            'external_message' => [
                'type' => 'string',
                'description' => 'Required for Enabled or Scheduled. Max 2000 characters; body is sent to CIPP but only length is audited.',
            ],
            'start_time' => [
                'type' => 'string',
                'description' => 'Required for Scheduled. ISO-like datetime or source-compatible timestamp string.',
            ],
            'end_time' => [
                'type' => 'string',
                'description' => 'Required for Scheduled. ISO-like datetime or source-compatible timestamp string.',
            ],
            'timezone' => [
                'type' => 'string',
                'description' => 'Optional Exchange timezone identifier.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function delegateProperties(): array
    {
        return [
            'delegate_person_id' => [
                'type' => 'integer',
                'description' => 'PSA person ID of the trustee/delegate who receives or loses the access. The server verifies it belongs to client_id and derives the delegate UPN; the mailbox owner is person_id.',
            ],
            'permission' => [
                'type' => 'string',
                'enum' => self::DELEGATE_PERMISSIONS,
                'description' => 'Delegate permission kind: full_access (open and read the mailbox), send_as (send as the mailbox), or send_on_behalf (send on behalf of the mailbox).',
            ],
            'operation' => [
                'type' => 'string',
                'enum' => self::DELEGATE_OPERATIONS,
                'description' => 'grant to add the permission for the delegate, remove to revoke it.',
            ],
            'auto_map' => [
                'type' => 'boolean',
                'description' => 'Only used when permission=full_access and operation=grant: whether the mailbox auto-maps into the delegate\'s Outlook. Defaults to true; ignored for send_as, send_on_behalf, and removals.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function directoryRoleProperties(): array
    {
        return [
            'role_template_id' => [
                'type' => 'string',
                'description' => 'Universal Microsoft Entra role TEMPLATE GUID identifying which directory role to remove (the roleTemplateId surfaced by the CIPP role reads, e.g. cipp_list_roles). The server re-resolves the tenant\'s activated role object from it at execution; the tenant role object id is never accepted from the caller.',
            ],
            'role_name' => [
                'type' => 'string',
                'description' => 'Typed role display name confirmation (e.g. "Exchange Administrator"). Verified case-insensitively against the resolved role\'s display name at execution; a mismatch refuses the removal.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function deviceWipeProperties(): array
    {
        return [
            'asset_id' => [
                'type' => 'integer',
                'description' => 'PSA asset ID of the target device. The server verifies it belongs to client_id and derives the Intune (M365) managedDevice id from the synced asset record; upstream device GUIDs are never accepted from the caller.',
            ],
            'wipe_action' => [
                'type' => 'string',
                'enum' => self::WIPE_ACTIONS,
                'description' => 'wipe FACTORY-RESETS the device and destroys local data (keepUserData/keepEnrollmentData pinned false); retire removes company data and unenrolls the device from Intune, keeping personal data.',
            ],
            'confirm_hostname' => [
                'type' => 'string',
                'description' => 'Typed hostname confirmation for defense-in-depth (read it from the PSA asset record). Verified case-insensitively against the resolved asset hostname; a mismatch cancels the call.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function successorProperties(): array
    {
        return [
            'successor_person_id' => [
                'type' => 'integer',
                'description' => 'PSA person ID of the successor who receives owner access to the offboarded user\'s OneDrive. The server verifies it belongs to client_id and derives the successor UPN; it must be an ACTIVE person (verified at staging and again at approval) and a different person than person_id.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function tool(string $name, string $description, array $properties, array $required): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'input_schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function disableSignInTool(): array
    {
        return self::tool(
            'cipp_disable_user_sign_in',
            'Disable Microsoft 365 sign-in for one server-derived CIPP user immediately. This blocks sign-in and can interrupt mail, Teams, and business app access. Requires an explicit token grant, reason, confirm_upn friction, kill-switch, dedup/cooldown, and TechnicianActionLog audit.',
            self::personProperties(),
            ['person_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageDisableSignInTool(): array
    {
        return self::tool(
            'cipp_stage_disable_user_sign_in',
            'Stage a Microsoft 365 sign-in disable for cockpit approval. The MCP call makes no CIPP upstream call; the execution payload is encrypted at rest and approval revalidates client, ticket, tenant, and person scope before execution.',
            self::personProperties(ticket: true),
            ['person_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function enableSignInTool(): array
    {
        return self::tool(
            'cipp_enable_user_sign_in',
            'Enable Microsoft 365 sign-in for one server-derived CIPP user immediately. This can restore account access. Requires an explicit token grant, reason, confirm_upn friction, kill-switch, dedup/cooldown, and TechnicianActionLog audit.',
            self::personProperties(),
            ['person_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageEnableSignInTool(): array
    {
        return self::tool(
            'cipp_stage_enable_user_sign_in',
            'Stage a Microsoft 365 sign-in enable for cockpit approval. The execution payload is encrypted at rest and approval revalidates server-derived CIPP scope before execution.',
            self::personProperties(ticket: true),
            ['person_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function revokeSessionsTool(): array
    {
        return self::tool(
            'cipp_revoke_user_sessions',
            'Revoke active Microsoft 365 sessions for one server-derived CIPP user immediately. This signs the user out of active sessions and may disrupt work. Requires an explicit token grant, reason, confirm_upn friction, kill-switch, dedup/cooldown, and audit.',
            self::personProperties(),
            ['person_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageRevokeSessionsTool(): array
    {
        return self::tool(
            'cipp_stage_revoke_user_sessions',
            'Stage Microsoft 365 session revocation for cockpit approval. The MCP call makes no CIPP upstream call; the held payload is encrypted at rest and revalidated on approval.',
            self::personProperties(ticket: true),
            ['person_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function removeMfaTool(): array
    {
        return self::tool(
            'cipp_remove_user_mfa_methods',
            'Remove MFA methods for one server-derived CIPP user immediately. This can weaken account protection until MFA is re-registered. Requires an explicit token grant, reason, confirm_upn friction, kill-switch, dedup/cooldown, and audit.',
            self::personProperties(),
            ['person_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageRemoveMfaTool(): array
    {
        return self::tool(
            'cipp_stage_remove_user_mfa_methods',
            'Stage MFA-method removal for cockpit approval. The MCP call makes no CIPP upstream call; the execution payload is encrypted at rest and approval revalidates server-derived CIPP user scope.',
            self::personProperties(ticket: true),
            ['person_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function setLegacyMfaTool(): array
    {
        return self::tool(
            'cipp_set_legacy_per_user_mfa',
            'Set legacy per-user MFA state for one server-derived CIPP user immediately. This changes authentication requirements and can lock out or weaken access. Requires explicit grant, reason, confirm_upn, kill-switch, dedup/cooldown, and audit.',
            array_merge(self::personProperties(), self::stateProperties()),
            ['person_id', 'confirm_upn', 'reason', 'state'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageSetLegacyMfaTool(): array
    {
        return self::tool(
            'cipp_stage_set_legacy_per_user_mfa',
            'Stage a legacy per-user MFA state change for cockpit approval. The MCP call makes no CIPP upstream call; the payload is encrypted at rest and approval revalidates local person and tenant mappings.',
            array_merge(self::personProperties(ticket: true), self::stateProperties()),
            ['person_id', 'ticket_id', 'confirm_upn', 'reason', 'state'],
        );
    }

    /** @return array<string, mixed> */
    private static function assignLicenseTool(): array
    {
        return self::tool(
            'cipp_assign_user_license',
            'Assign one local CIPP M365 license SKU to one server-derived user immediately. This can alter billing and app entitlements. Requires explicit grant, reason, confirm_upn, kill-switch, dedup/cooldown, and audit. Dial note: human-smoke-verify before first live grant; no replace-all or remove-all license body is supported.',
            array_merge(self::personProperties(), self::licenseProperties()),
            ['person_id', 'license_type_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageAssignLicenseTool(): array
    {
        return self::tool(
            'cipp_stage_assign_user_license',
            'Stage assignment of one local CIPP M365 license SKU for cockpit approval. This can alter billing and entitlements; the held payload is encrypted at rest and approval revalidates person, tenant, and SKU mappings.',
            array_merge(self::personProperties(ticket: true), self::licenseProperties()),
            ['person_id', 'license_type_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function removeLicenseTool(): array
    {
        return self::tool(
            'cipp_remove_user_license',
            'Remove one local CIPP M365 license SKU from one server-derived user immediately. This can remove Microsoft 365 app/service access and alter billing. Requires explicit grant, reason, confirm_upn, kill-switch, dedup/cooldown, and audit. No replace-all or remove-all license body is supported.',
            array_merge(self::personProperties(), self::licenseProperties()),
            ['person_id', 'license_type_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageRemoveLicenseTool(): array
    {
        return self::tool(
            'cipp_stage_remove_user_license',
            'Stage removal of one local CIPP M365 license SKU for cockpit approval. This can remove user access and alter billing; the held payload is encrypted at rest and approval revalidates mappings before execution.',
            array_merge(self::personProperties(ticket: true), self::licenseProperties()),
            ['person_id', 'license_type_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function convertMailboxTool(): array
    {
        return self::tool(
            'cipp_convert_mailbox',
            'Convert a server-derived Microsoft 365 mailbox immediately through CIPP. Shared mailbox conversion can change licensing obligations and mailbox behavior. Requires explicit grant, reason, confirm_upn, kill-switch, dedup/cooldown, and audit.',
            array_merge(self::personProperties(), self::mailboxTypeProperties()),
            ['person_id', 'mailbox_type', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageConvertMailboxTool(): array
    {
        return self::tool(
            'cipp_stage_convert_mailbox',
            'Stage a Microsoft 365 mailbox conversion for cockpit approval. Shared mailbox conversion can change licensing obligations; the held payload stores only local identifiers and safe parameters, then approval revalidates CIPP scope.',
            array_merge(self::personProperties(ticket: true), self::mailboxTypeProperties()),
            ['person_id', 'mailbox_type', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function setMailboxForwardingTool(): array
    {
        return self::tool(
            'cipp_set_mailbox_forwarding',
            'Set mailbox forwarding immediately through CIPP for one server-derived user. Direct execution supports internal forwarding or disabling only. External SMTP forwarding is held-only because it can create BEC and data-exfiltration risk. Requires explicit grant, reason, confirm_upn, kill-switch, cooldown, and audit.',
            array_merge(self::personProperties(), self::forwardingProperties(stage: false)),
            ['person_id', 'mode', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageSetMailboxForwardingTool(): array
    {
        return self::tool(
            'cipp_stage_set_mailbox_forwarding',
            'Stage mailbox forwarding for cockpit approval. External SMTP forwarding carries BEC and data-exfiltration risk; the external address is re-entered at approval and is not stored, while audit keeps only target type/domain. Approval revalidates local client/person scope before CIPP execution.',
            array_merge(self::personProperties(ticket: true), self::forwardingProperties(stage: true)),
            ['person_id', 'mode', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function setMailboxGalVisibilityTool(): array
    {
        return self::tool(
            'cipp_set_mailbox_gal_visibility',
            'Set Global Address List visibility immediately for one server-derived mailbox. Hiding a mailbox can affect discoverability for staff. Requires explicit grant, reason, confirm_upn, kill-switch, cooldown, and audit.',
            array_merge(self::personProperties(), self::galVisibilityProperties()),
            ['person_id', 'hidden', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageSetMailboxGalVisibilityTool(): array
    {
        return self::tool(
            'cipp_stage_set_mailbox_gal_visibility',
            'Stage a Global Address List visibility change for cockpit approval. The MCP call makes no CIPP upstream call; approval revalidates local client/person scope before execution.',
            array_merge(self::personProperties(ticket: true), self::galVisibilityProperties()),
            ['person_id', 'hidden', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function setMailboxOutOfOfficeTool(): array
    {
        return self::tool(
            'cipp_set_mailbox_out_of_office',
            'Set mailbox out-of-office state/messages/schedule immediately through CIPP. Calendar-decline options are not supported in v1. Message bodies are sent upstream but never stored or returned; audit records message lengths only. Requires explicit grant, reason, confirm_upn, kill-switch, cooldown, and audit.',
            array_merge(self::personProperties(), self::outOfOfficeProperties()),
            ['person_id', 'state', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageSetMailboxOutOfOfficeTool(): array
    {
        return self::tool(
            'cipp_stage_set_mailbox_out_of_office',
            'Stage mailbox out-of-office state/messages/schedule for cockpit approval. Message bodies are re-entered at approval and are not stored; the proposal stores message lengths only plus safe schedule metadata.',
            array_merge(self::personProperties(ticket: true), self::outOfOfficeProperties()),
            ['person_id', 'state', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function setMailboxDelegateTool(): array
    {
        return self::tool(
            'cipp_set_mailbox_delegate',
            'Grant or remove a Microsoft 365 mailbox delegate permission (FullAccess, Send-As, or Send-on-Behalf) immediately through CIPP for one server-derived mailbox owner (person_id) and one server-derived delegate (delegate_person_id, a different person). Delegate access exposes another user\'s mailbox and can enable impersonation or data exfiltration, so it is a sensitive write. confirm_upn must be the mailbox OWNER\'s UPN (person_id). Requires an explicit grant, reason, confirm_upn, kill-switch, cooldown, and audit.',
            array_merge(self::personProperties(), self::delegateProperties()),
            ['person_id', 'delegate_person_id', 'permission', 'operation', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageSetMailboxDelegateTool(): array
    {
        return self::tool(
            'cipp_stage_set_mailbox_delegate',
            'Stage a Microsoft 365 mailbox delegate permission change (FullAccess, Send-As, or Send-on-Behalf) for cockpit approval. Delegate grants expose another user\'s mailbox; the held payload stores only local PSA identifiers plus the permission/operation, and approval revalidates local client/person scope before CIPP execution. confirm_upn must be the mailbox OWNER\'s UPN (person_id); delegate_person_id is a different person in the same client.',
            array_merge(self::personProperties(ticket: true), self::delegateProperties()),
            ['person_id', 'delegate_person_id', 'permission', 'operation', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function removeDirectoryRoleTool(): array
    {
        return self::tool(
            'cipp_remove_directory_role',
            'Remove one Microsoft Entra directory (admin) role from one server-derived CIPP user through CIPP, WITHOUT touching license assignments — offboarding and least-privilege hygiene for stale admin roles. HELD-ONLY: this capability never executes immediately, whatever mode was granted — every call must use staged=true with a ticket_id and is held for cockpit approval; staged=false calls are refused. Identify the role by its universal Entra role_template_id (from the CIPP role reads) plus a typed role_name confirmation; approval re-resolves the tenant\'s activated role and re-verifies the user\'s current membership before execution. confirm_upn is the target user\'s UPN. Requires an explicit token grant, reason, kill-switch, cooldown, and TechnicianActionLog audit.',
            array_merge(self::personProperties(), self::directoryRoleProperties()),
            ['person_id', 'role_template_id', 'role_name', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function emailSecurityCommonProperties(bool $ticketRequired): array
    {
        return [
            'reason' => [
                'type' => 'string',
                'description' => 'Specific operational reason for this CIPP write.',
            ],
            'ticket_id' => [
                'type' => 'integer',
                'description' => $ticketRequired
                    ? 'Required ticket ID for cockpit-held actions. The server verifies it belongs to client_id.'
                    : 'Optional ticket ID for incident attribution. The server verifies it belongs to client_id when supplied.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function quarantineReleaseProperties(): array
    {
        return [
            'quarantine_identity' => [
                'type' => 'string',
                'description' => 'Quarantine message Identity (GUID\\GUID) exactly as returned by cipp_list_mail_quarantine. The server verifies it is present in the resolved client tenant\'s live quarantine listing before any release; identities from other tenants or expired messages are refused.',
            ],
            'confirm_sender' => [
                'type' => 'string',
                'description' => 'Typed confirmation for defense-in-depth: the quarantined message\'s sender email address. Must match the SenderAddress of the server-verified quarantine row.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function allowEntryProperties(): array
    {
        return [
            'list_type' => [
                'type' => 'string',
                'enum' => self::ALLOW_LIST_TYPES,
                'description' => 'Tenant Allow/Block List entry type: Sender (a full email address or bare domain) or Url (a hostname/URL pattern, wildcards allowed).',
            ],
            'entry' => [
                'type' => 'string',
                'description' => 'The value to allow. Sender: full email address or bare domain, no wildcards. Url: hostname or URL pattern without a scheme (wildcards allowed). Prefer the narrowest entry that fixes the false positive — a full address over a whole domain.',
            ],
            'confirm_entry' => [
                'type' => 'string',
                'description' => 'Typed confirmation — must exactly match entry. This value bypasses filtering tenant-wide, so it is retyped deliberately.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function releaseQuarantineMessageTool(): array
    {
        return self::tool(
            'cipp_release_quarantine_message',
            'Release one quarantined email message to ALL of its original recipients immediately through CIPP (Exchange Release-QuarantineMessage). Use only for a CONFIRMED false positive: releasing delivers mail the filter judged unsafe. The server verifies the identity against the resolved client tenant\'s live quarantine listing before calling — a message not present there is refused — and confirm_sender must match the verified message\'s real sender. The sender is NOT allow-listed for the future (that is cipp_add_tenant_allow_entry, if warranted). Requires an explicit token grant, reason, kill-switch, dedup/cooldown, and TechnicianActionLog audit.',
            array_merge(self::quarantineReleaseProperties(), self::emailSecurityCommonProperties(ticketRequired: false)),
            ['quarantine_identity', 'confirm_sender', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageRemoveDirectoryRoleTool(): array
    {
        return self::tool(
            'cipp_stage_remove_directory_role',
            'Stage removal of one Microsoft Entra directory (admin) role from one server-derived CIPP user for cockpit approval, WITHOUT touching license assignments (offboarding / least-privilege hygiene). The MCP call makes no CIPP upstream call; the held payload stores only local identifiers plus the universal role_template_id and typed role_name, and approval re-resolves the tenant\'s activated role by template id, re-verifies the role display name and the user\'s CURRENT membership, then executes the single-member removal. This capability is held-only — there is no immediate execution path. confirm_upn is the target user\'s UPN (person_id).',
            array_merge(self::personProperties(ticket: true), self::directoryRoleProperties()),
            ['person_id', 'role_template_id', 'role_name', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageReleaseQuarantineMessageTool(): array
    {
        return self::tool(
            'cipp_stage_release_quarantine_message',
            'Stage a quarantined-message release for cockpit approval. Staging performs a read-only verification lookup of the tenant\'s live quarantine (never the release itself), requires the identity to be present there with confirm_sender matching its real sender, and captures the verified sender/subject/recipients server-side for the approval display. The payload is encrypted at rest; approval re-verifies the message is still in quarantine (and not already released) before executing.',
            array_merge(self::quarantineReleaseProperties(), self::emailSecurityCommonProperties(ticketRequired: true)),
            ['quarantine_identity', 'confirm_sender', 'ticket_id', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function wipeDeviceTool(): array
    {
        return self::tool(
            'cipp_wipe_device',
            'Issue an IRREVERSIBLE Intune device wipe (factory reset — destroys local data) or retire (removes company data and unenrolls) for one server-derived managed device — the destructive execute half of offboarding. HELD-ONLY: this capability never executes immediately, whatever mode was granted — every call must use staged=true with a ticket_id and is held for cockpit approval, where the approver must type the exact Intune device id; staged=false calls are refused. Identify the device by PSA asset_id plus a typed confirm_hostname; the server derives the Intune device id from the synced asset and re-verifies it at approval. The asset must demonstrably belong to person_id (an asset-user link or a matching RMM last logged-on user); a person/device mismatch is refused at staging and again at approval. A completed action is never re-issued: a re-fired approval is a logged no-op. confirm_upn is the device user\'s UPN (person_id). Requires an explicit token grant, reason, kill-switch, cooldown, and TechnicianActionLog audit.',
            array_merge(self::personProperties(), self::deviceWipeProperties()),
            ['person_id', 'asset_id', 'wipe_action', 'confirm_hostname', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function addTenantAllowEntryTool(): array
    {
        return self::tool(
            'cipp_add_tenant_allow_entry',
            'Add ONE allow entry (sender address, sender domain, or URL pattern) to the Microsoft 365 Tenant Allow/Block List of the resolved client tenant immediately through CIPP. TENANT-WIDE consequence: matching mail bypasses spam/phish filtering for every mailbox in the tenant — use only to remediate a CONFIRMED false positive, with the narrowest entry that works. The list method is pinned to Allow (no block adds) and expiry is pinned to 45 days after last use (no-expiration allows are not possible through this tool). confirm_entry must retype the exact entry. Requires an explicit token grant, reason, kill-switch, dedup/cooldown, and TechnicianActionLog audit.',
            array_merge(self::allowEntryProperties(), self::emailSecurityCommonProperties(ticketRequired: false)),
            ['list_type', 'entry', 'confirm_entry', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageWipeDeviceTool(): array
    {
        return self::tool(
            'cipp_stage_wipe_device',
            'Stage an IRREVERSIBLE Intune device wipe (factory reset — destroys local data) or retire (removes company data and unenrolls) for cockpit approval — the destructive execute half of offboarding. The MCP call makes no CIPP upstream call; the held payload stores only local PSA identifiers plus the server-derived device id snapshot, and approval re-resolves the asset, re-verifies the device identity and the asset\'s link to the person, and requires the operator to TYPE the exact Intune device id before the single device action is sent. The asset must demonstrably belong to person_id (an asset-user link or a matching RMM last logged-on user); a person/device mismatch is refused. A completed action is never re-issued: a re-fired approval is a logged no-op. This capability is held-only — there is no immediate execution path. confirm_upn is the device user\'s UPN (person_id); confirm_hostname is the typed asset hostname.',
            array_merge(self::personProperties(ticket: true), self::deviceWipeProperties()),
            ['person_id', 'asset_id', 'wipe_action', 'confirm_hostname', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageAddTenantAllowEntryTool(): array
    {
        return self::tool(
            'cipp_stage_add_tenant_allow_entry',
            'Stage a tenant allow-list entry for cockpit approval. The MCP call makes no CIPP upstream call; the validated entry is stored encrypted and shown VERBATIM to the approver (an allow entry must be reviewed as-is), and approval revalidates client, ticket, tenant, and the entry value before execution. Allow entries bypass spam/phish filtering tenant-wide; expiry is pinned to 45 days after last use.',
            array_merge(self::allowEntryProperties(), self::emailSecurityCommonProperties(ticketRequired: true)),
            ['list_type', 'entry', 'confirm_entry', 'ticket_id', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function reassignOneDriveTool(): array
    {
        return self::tool(
            'cipp_reassign_onedrive',
            'Reassign OneDrive ownership for one server-derived offboarded user: grant one server-derived successor (successor_person_id, an ACTIVE and different person in the same client) owner/site-admin access to the user\'s entire OneDrive through CIPP — the data-handover half of offboarding. HELD-ONLY: this capability never executes immediately, whatever mode was granted — every call must use staged=true with a ticket_id and is held for cockpit approval; staged=false calls are refused. Exposes the entire OneDrive contents to the successor, so it is a sensitive data-exposure write. confirm_upn is the OneDrive OWNER\'s UPN (person_id). Requires an explicit token grant, reason, kill-switch, cooldown, and TechnicianActionLog audit.',
            array_merge(self::personProperties(), self::successorProperties()),
            ['person_id', 'successor_person_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageReassignOneDriveTool(): array
    {
        return self::tool(
            'cipp_stage_reassign_onedrive',
            'Stage a OneDrive ownership reassignment for cockpit approval: grant one server-derived successor owner/site-admin access to the offboarded user\'s entire OneDrive (data handover; exposes all OneDrive contents). The MCP call makes no CIPP upstream call; the held payload stores only local PSA identifiers, and approval re-resolves both identities before CIPP execution. This capability is held-only — there is no immediate execution path. confirm_upn is the OneDrive OWNER\'s UPN (person_id); successor_person_id is an ACTIVE and different person in the same client.',
            array_merge(self::personProperties(ticket: true), self::successorProperties()),
            ['person_id', 'successor_person_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function resetUserPasswordTool(): array
    {
        return self::tool(
            'cipp_reset_user_password',
            'Reset the Microsoft 365 password for one server-derived CIPP user. IMMEDIATE (staged=false) returns a newly generated temporary password in this tool result — generated by CIPP/Microsoft, never written to any log or audit record; relay it to the user over a secure channel. STAGED (staged=true, and the automatic behaviour when your token grants staged-only) returns NO PASSWORD: nothing is reset yet, the action is held for human approval, and the temporary password is generated only on approval and shown to the approving human in the cockpit — do not wait for a credential from a staged call, and tell the requester a person must approve it first. Defaults to must-change-at-next-sign-in. Requires an explicit token grant, reason, confirm_upn friction, kill-switch, cooldown, and TechnicianActionLog audit. Consequential: staged=false performs a live credential reset immediately.',
            array_merge(self::personProperties(), self::resetUserPasswordProperties()),
            ['person_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageResetUserPasswordTool(): array
    {
        return self::tool(
            'cipp_stage_reset_user_password',
            'Stage a Microsoft 365 password reset for cockpit approval. The MCP call makes NO CIPP upstream call and no password exists yet; approval revalidates client, ticket, tenant, and person scope, then generates the temporary password and shows it to the APPROVING HUMAN — it is never returned to the caller of this tool. Use this when a reset is warranted but a person should confirm it first.',
            array_merge(self::personProperties(ticket: true), self::resetUserPasswordProperties()),
            ['person_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function createUserProperties(bool $ticket = false): array
    {
        return [
            'username' => [
                'type' => 'string',
                'description' => 'UPN local part for the NEW user (letters/digits with interior dots, underscores, or hyphens; max 64 characters). The server composes the sign-in UPN as username@<the client\'s mapped CIPP tenant domain> — the domain is never caller-supplied.',
            ],
            'display_name' => [
                'type' => 'string',
                'description' => 'Display name for the new user (max 256 characters).',
            ],
            'given_name' => [
                'type' => 'string',
                'description' => 'Given (first) name for the new user (max 64 characters).',
            ],
            'surname' => [
                'type' => 'string',
                'description' => 'Surname (last name) for the new user (max 64 characters).',
            ],
            'usage_location' => [
                'type' => 'string',
                'description' => 'Optional 2-letter ISO 3166-1 usage location country code (e.g. US). Required when license_type_id is provided — Microsoft 365 refuses license assignment for a user without one.',
            ],
            'license_type_id' => [
                'type' => 'integer',
                'description' => 'Optional local PSA license_types.id for a CIPP M365 SKU to assign after creation. The server derives the upstream SKU from synced license rows; providing this makes usage_location required.',
            ],
            'confirm_upn' => [
                'type' => 'string',
                'description' => 'Typed confirmation of the FULL new UPN (username@<the client\'s mapped CIPP tenant domain>) for defense-in-depth. A mismatch — including a wrong tenant domain — cancels the call.',
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'Specific operational reason for this CIPP write.',
            ],
            'ticket_id' => [
                'type' => 'integer',
                'description' => $ticket
                    ? 'Required ticket ID for cockpit-held actions. The server verifies it belongs to client_id.'
                    : 'Optional ticket ID for incident attribution. The server verifies it belongs to client_id when supplied.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function createUserTool(): array
    {
        return self::tool(
            'cipp_create_user',
            'Create a NEW Microsoft 365 user in the resolved client tenant immediately through CIPP — privileged provisioning. The sign-in UPN domain is ALWAYS the client\'s mapped CIPP tenant domain (server-derived; upstream domains, passwords, and license SKUs are never accepted from the caller). The account is created enabled, with a CIPP-generated temporary password that must be changed at first sign-in; the password is returned only in this tool result — never stored, never audited — so relay it over a secure channel. Optionally assigns one local CIPP M365 license SKU (usage_location required with it). Requires an explicit token grant (grants start staged-only; immediate execution needs the immediate mode grant), reason, confirm_upn friction, kill-switch, dedup/cooldown, and TechnicianActionLog audit.',
            self::createUserProperties(),
            ['username', 'display_name', 'given_name', 'surname', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageCreateUserTool(): array
    {
        return self::tool(
            'cipp_stage_create_user',
            'Stage creation of a NEW Microsoft 365 user for cockpit approval — the default path for this privileged provisioning capability. The MCP call makes no CIPP upstream call; the held payload stores only validated safe scalars (username, the server-composed UPN snapshot, names, usage location, local license_type_id), and approval re-derives the client\'s mapped CIPP tenant domain fresh — a changed tenant mapping refuses execution. The CIPP-generated temporary password is shown ONCE to the approving operator and is never stored or audited. confirm_upn is the full new UPN (username@<the client\'s mapped CIPP tenant domain>).',
            self::createUserProperties(ticket: true),
            ['username', 'display_name', 'given_name', 'surname', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function editUserProperties(bool $ticket = false): array
    {
        $properties = self::personProperties(ticket: $ticket);

        $fieldDescriptions = [
            'display_name' => 'New display name (max 256 characters). Not clearable.',
            'given_name' => 'New given (first) name (max 64 characters).',
            'surname' => 'New surname / last name (max 64 characters).',
            'job_title' => 'New job title (max 128 characters).',
            'department' => 'New department (max 64 characters).',
            'company_name' => 'New company name (max 64 characters).',
            'street_address' => 'New street address (max 1024 characters).',
            'city' => 'New city (max 128 characters).',
            'state' => 'New state or province (max 128 characters).',
            'postal_code' => 'New postal code (max 40 characters).',
            'country' => 'New country (max 128 characters).',
            'mobile_phone' => 'New mobile phone number (max 64 characters).',
            'business_phone' => 'New business phone number (max 64 characters; stored as the user\'s single business phone entry).',
            'usage_location' => 'New 2-letter ISO 3166-1 usage location country code (e.g. US). Not clearable.',
        ];

        foreach ($fieldDescriptions as $field => $description) {
            $properties[$field] = [
                'type' => 'string',
                'description' => $description.' Omit to leave unchanged.',
            ];
        }

        $properties['clear_fields'] = [
            'type' => 'array',
            'items' => ['type' => 'string', 'enum' => self::EDIT_CLEARABLE],
            'description' => 'Profile fields to explicitly BLANK upstream (the vendor-whitelisted clear list). display_name and usage_location are not clearable, and a field cannot be both set and cleared in the same call. Omitted fields are never cleared.',
        ];

        $properties['manager_person_id'] = [
            'type' => 'integer',
            'description' => 'PSA person ID of the NEW manager. The server verifies it belongs to client_id, requires an ACTIVE person with a CIPP user mapping, derives the manager UPN, and refuses self-management. Omit to leave the manager unchanged; removing an existing manager is not supported here.',
        ];

        return $properties;
    }

    /** @return array<string, mixed> */
    private static function editUserTool(): array
    {
        return self::tool(
            'cipp_edit_user',
            'Edit an existing Microsoft 365 user\'s profile and directory attributes immediately through CIPP for one server-derived user — a null-safe PARTIAL update: only the fields you provide change, omitted fields are left untouched, and explicit blanking goes through clear_fields (the vendor\'s own clear whitelist). The editable surface matches the CIPP edit-user form: names, job title, department, company, address, phones, usage location, and manager (a server-derived ACTIVE person in the same client). The sign-in UPN is pinned server-side to the user\'s current UPN — this tool cannot rename an account — and passwords, licenses, aliases, and group membership are NOT accepted here (dedicated tools exist). On-prem-AD-synced (hybrid) users should be edited on-prem instead: Entra changes can be overwritten by the sync. Requires an explicit token grant (grants start staged-only; immediate execution needs the immediate mode grant), reason, confirm_upn friction, kill-switch, dedup/cooldown, and TechnicianActionLog audit.',
            self::editUserProperties(),
            ['person_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function groupMembershipProperties(): array
    {
        return [
            'group_id' => [
                'type' => 'string',
                'description' => 'Microsoft 365 group id (GUID) exactly as returned by the CIPP group reads (e.g. cipp_list_groups). The server verifies it against the resolved client tenant\'s live group listing and derives the group name and type from the verified row; groups in other tenants, dynamic-membership groups, and on-premises-synced groups are refused. Mail addresses and display names are not accepted as the group identity.',
            ],
            'operation' => [
                'type' => 'string',
                'enum' => self::GROUP_MEMBERSHIP_OPERATIONS,
                'description' => 'add to add the user to the group; remove to remove them. Adding requires the user to be ACTIVE in the PSA; removing stays possible for deactivated users (offboarding cleanup).',
            ],
            'confirm_group_name' => [
                'type' => 'string',
                'description' => 'Typed group display name confirmation for defense-in-depth. Verified case-insensitively against the server-verified group\'s displayName; a mismatch cancels the call.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function setGroupMembershipTool(): array
    {
        return self::tool(
            'cipp_set_group_membership',
            'Add one server-derived CIPP user to — or remove them from — one Microsoft 365 group (Security, Microsoft 365, Distribution List, or Mail-Enabled Security) in the resolved client tenant immediately through CIPP. The group is verified against the tenant\'s LIVE group listing and its name and type are derived server-side from the verified row; dynamic-membership groups and on-premises-synced groups are refused (their membership is rule- or AD-managed). ADD grants the user whatever access the group carries — shared data, resources, mail, and for security groups possibly privileged access — so the target user must be ACTIVE in the PSA; REMOVE stays possible for deactivated users (offboarding cleanup). Adds to Security and Mail-Enabled Security groups are HELD-ONLY: they never execute immediately, whatever mode was granted — call with staged=true and a ticket_id for cockpit approval. Immediate execution (with the immediate mode grant; grants start staged-only) covers Microsoft 365 and Distribution List adds and all removes. Requires an explicit token grant, reason, kill-switch, dedup/cooldown, and TechnicianActionLog audit. confirm_upn is the target USER\'s UPN (person_id); confirm_group_name is the group\'s display name.',
            array_merge(self::personProperties(), self::groupMembershipProperties()),
            ['person_id', 'group_id', 'operation', 'confirm_group_name', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageEditUserTool(): array
    {
        return self::tool(
            'cipp_stage_edit_user',
            'Stage an edit of an existing Microsoft 365 user\'s profile and directory attributes for cockpit approval — the default path for this capability. The MCP call makes no CIPP upstream call; the held payload stores only validated safe scalars (the field values, the clear list, and the local manager person id), the cockpit proposal lists every proposed change verbatim for review, and approval re-resolves the target user AND the manager fresh — a target that lost its CIPP mapping or a manager deactivated after staging refuses execution. Null-safe partial update: only the listed fields change and the sign-in UPN stays pinned to the current value. confirm_upn is the CURRENT UPN of the user being edited.',
            self::editUserProperties(ticket: true),
            ['person_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageSetGroupMembershipTool(): array
    {
        return self::tool(
            'cipp_stage_set_group_membership',
            'Stage a Microsoft 365 group membership change (add or remove one server-derived CIPP user) for cockpit approval — the default path for this capability, and the ONLY path for adds to Security and Mail-Enabled Security groups (those are held-only and never execute immediately). Staging verifies the group against the resolved client tenant\'s live group listing (a read, never the write itself) so the proposal shows the VERIFIED group name and type; the held payload stores only safe local scalars plus that verified snapshot, and approval re-verifies the user\'s active status (for adds) and the group\'s existence, name, and type FRESH — any drift declines instead of executing against a group the operator never reviewed. Dynamic-membership and on-premises-synced groups are refused at staging. confirm_upn is the target USER\'s UPN (person_id); confirm_group_name is the group\'s display name.',
            array_merge(self::personProperties(ticket: true), self::groupMembershipProperties()),
            ['person_id', 'group_id', 'operation', 'confirm_group_name', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }
}
