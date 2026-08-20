<?php

namespace App\Services\Mcp;

use App\Enums\TechnicianRunState;
use App\Enums\TechnicianTier;
use App\Models\Client;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Services\Cipp\CippClientException;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Cipp\CippToolContract;
use App\Services\Cipp\CippWriteScopeException;
use App\Services\Cipp\CippWriteScopeResolver;
use App\Services\Cipp\ResolvedCippLicense;
use App\Services\Cipp\ResolvedCippPerson;
use App\Services\Cipp\ResolvedIntuneDevice;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Services\Technician\TechnicianApprovalResult;
use App\Support\CippConfig;
use App\Support\TechnicianConfig;
use Illuminate\Contracts\Encryption\DecryptException;
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
        'cipp_stage_assign_tenant_user_license' => 'cipp_assign_tenant_user_license',
        'cipp_stage_remove_user_license' => 'cipp_remove_user_license',
        'cipp_stage_convert_mailbox' => 'cipp_convert_mailbox',
        'cipp_stage_set_mailbox_forwarding' => 'cipp_set_mailbox_forwarding',
        'cipp_stage_set_mailbox_gal_visibility' => 'cipp_set_mailbox_gal_visibility',
        'cipp_stage_set_mailbox_out_of_office' => 'cipp_set_mailbox_out_of_office',
        'cipp_stage_set_mailbox_delegate' => 'cipp_set_mailbox_delegate',
        'cipp_stage_remove_directory_role' => 'cipp_remove_directory_role',
        'cipp_stage_remove_mailbox_rule' => 'cipp_remove_mailbox_rule',
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

    /**
     * Tenant-scoped licence writes. The licence target for a tenant user with
     * NO PSA person record is its OWN verb, not a second argument shape on
     * cipp_assign_user_license — a new name arrives in nobody's allowed_tools
     * until it is granted, so the blocklist allowance this family needs
     * (target_upn/sku_id, see licenseTargetContext()) cannot ride into any
     * existing grant of the person-keyed tool. cipp_assign_user_license keeps
     * its original person-only contract and the strict identifier blocklist.
     *
     * @var array<int, string>
     */
    private const LICENSE_TARGET_TOOLS = [
        'cipp_assign_tenant_user_license',
    ];

    /**
     * Staged writes whose TARGET can legitimately come back, so the 24h
     * executed-content dedup must NOT answer a re-stage with "already executed".
     *
     * That dedup hashes only the safe local scalars — for the inbox-rule removal
     * that is {tool, client, person, ticket, rule_name}, because the rule's
     * upstream Identity does not exist at stage time by design — and nothing in
     * it can tell the rule that WAS removed apart from a NEW rule an attacker
     * re-planted under the same name minutes later. Re-planting a same-named
     * forwarding rule is ordinary BEC behaviour, so short-circuiting there would
     * report a live rule as already handled WITHOUT a live read and WITHOUT a
     * removal, on the one verb that exists to clean up after a takeover — the
     * false all-clear this module forbids.
     *
     * Only the executed-content rail is skipped, exactly as
     * stageResetPasswordAction() skips it for a credential mint that must stay
     * repeatable: the liveAwaitingRun() dedupe still collapses an identical
     * proposal that is still pending approval, and the per-target proposal
     * cooldown still stops runaway staging. Both of those refuse honestly rather
     * than claiming the work is already done.
     *
     * A LICENCE SEAT IS RECREATABLE THE SAME WAY, and it is the clearer case.
     * assign -> remove -> re-assign is an ordinary supported sequence (a
     * mis-click reversed, a suspension lifted, a contractor coming back), and
     * NO log-derived key can see the removal: the person-keyed
     * cipp_remove_user_license audits under a DIFFERENT target key, and a
     * removal made in the CIPP portal audits nowhere at all, so "an executed row
     * exists" never meant "the seat is still assigned". Answering the
     * re-assignment with success/idempotent — or, on the staged path,
     * TERMINATING the operator-approved run as Done/already_handled — reports a
     * seat as granted while the user holds no licence: a false success on a
     * billing write, and on the staged path one the operator cannot even
     * re-approve. Unlike a device wipe, the write is harmless to repeat
     * (assigning a SKU the user already holds is an upstream no-op), so the
     * family lets the call through and keeps the per-target cooldown, which
     * refuses honestly instead of claiming the work is already done.
     *
     * @var array<int, string>
     */
    private const RECREATABLE_TARGET_STAGED_TOOLS = [
        'cipp_stage_remove_mailbox_rule',
        'cipp_stage_assign_tenant_user_license',
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
        'cipp_assign_tenant_user_license' => 300,
        'cipp_stage_assign_tenant_user_license' => 300,
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
        'cipp_remove_mailbox_rule' => 300,
        'cipp_stage_remove_mailbox_rule' => 300,
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

    /**
     * Upper bound for the caller-typed upstream SKU id on the tenant-scoped
     * licence target. A GUID is 36 characters; the bound is loose because
     * CIPP also emits SKU part numbers, and resolveCippLicenseBySku() is what
     * decides whether the value matches anything — this only stops an
     * unbounded string reaching a LIKE-free exact match and the audit trail.
     */
    private const LICENSE_SKU_MAX = 128;

    /**
     * The ONLY keys the tenant-scoped licence family lifts out of
     * UPSTREAM_IDENTIFIER_KEYS, named here so the relaxation is one grep away
     * rather than buried at a call site. Both are validated and re-derived
     * server-side before anything reaches upstream; every other blocklisted
     * key still refuses on this family, and this list is never subtracted from
     * the global one.
     *
     * @var array<int, string>
     */
    private const LICENSE_TARGET_ALLOWED_KEYS = ['target_upn', 'sku_id'];

    /**
     * The person-keyed licence tool's own keys. A tenant-target licence call
     * that carries a VALUE in any of these is refused outright by
     * licenseTargetContext(): the two tools name two different users, and
     * silently ignoring the person half would drop it — confirm_upn included —
     * without a word. Mutual exclusion has to be enforced, not merely
     * described in the tool text.
     *
     * @var array<int, string>
     */
    private const LICENSE_PERSON_SHAPE_KEYS = ['person_id', 'license_type_id', 'confirm_upn'];

    /** @var array<int, string> */
    private const WIPE_ACTIONS = ['wipe', 'retire'];

    private const ROLE_TEMPLATE_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const ROLE_NAME_MAX = 200;

    /**
     * The RAW upstream bound on an Exchange inbox rule name (New-InboxRule caps
     * Name at 256 characters). Documentation of the upstream shape only — it is
     * deliberately NOT the bound on what a caller may type; see
     * RULE_NAME_INPUT_MAX.
     */
    private const RULE_NAME_MAX = 256;

    /**
     * The per-field character bound CippToolContract::fence() sanitizes projected
     * text with. Mirrored here so the approve-time match can rebuild the exact
     * fenced form of a rule name the per-mailbox read handed the agent (see
     * mailboxRuleNameMatches).
     */
    private const FENCED_FIELD_MAX = 1000;

    /**
     * The bound on the caller-typed rule_name, and it is NOT RULE_NAME_MAX.
     *
     * What a caller can type is what the reads SHOWED them, and the per-mailbox
     * projection shows the FENCED form — which is produced by bounding the raw
     * name to FENCED_FIELD_MAX and only THEN defanging it, so the defanging can
     * push it back over any raw-length bound. PromptFence's role-marker rewrite
     * is the expanding one ("user:" -> "[user]:", 5 chars to 7): a hostile
     * 256-character name of packed role markers fences to ~358. Bounding the
     * input at 256 would have refused that string before it ever reached the
     * match, so the ONE class of rule this verb exists to remove — the long
     * attacker-authored name — would be un-typeable, and the operator would be
     * told the rule does not exist (the psa-4k6m.8 false-all-clear polarity,
     * one level up). FENCED_FIELD_MAX is the width the projection itself is
     * bounded to and leaves ~2.8x headroom over the worst-case expansion of a
     * raw-capped name, so every name these reads can display is typeable.
     * Payload/audit bloat is bounded by this constant, not by the raw cap.
     */
    private const RULE_NAME_INPUT_MAX = self::FENCED_FIELD_MAX;

    /**
     * The bound BOTH decline surfaces cut at: declined() truncates the cockpit
     * toast to it and safeFailureSummary() truncates the immutable audit summary
     * to it. Named because a message that QUOTES untrusted text has to be BUILT
     * to fit it — a blind cut at this width lands inside the quotation and takes
     * its closing delimiter with it (see fencedDeclineMessage).
     */
    private const DECLINE_MESSAGE_MAX = 300;

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
        'ruleId',
        'RuleId',
        'ruleName',
        'RuleName',
        'InboxRuleId',
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
        private readonly ChetDataSurfaceTextSanitizer $textSanitizer,
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
            self::assignTenantLicenseTool(),
            self::stageAssignTenantLicenseTool(),
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
            self::removeMailboxRuleTool(),
            self::stageRemoveMailboxRuleTool(),
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

        // Tenant-scoped licence assignment is its own verb pair, routed by NAME
        // like every other family. It was briefly a second argument shape on
        // cipp_assign_user_license, selected by whether target_upn carried a
        // value — which meant the blocklist relaxation the tenant shape needs
        // rode inside every EXISTING grant of the person-keyed tool, and the
        // person tool's required list had to drop to ['reason'] because a
        // schema cannot require keys of a shape the caller is not using. The
        // split restores the person tool's original contract and puts the new
        // capability behind a new grant.
        if (in_array(self::STAGED_TO_DIRECT[$name] ?? $name, self::LICENSE_TARGET_TOOLS, true)) {
            return isset(self::STAGED_TO_DIRECT[$name])
                ? $this->stageLicenseTargetAction($name, $arguments, $clientId, $actorLabel)
                : $this->executeLicenseTargetDirect($name, $arguments, $clientId, $actorLabel);
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

        if (in_array(self::STAGED_TO_DIRECT[$run->action_type] ?? '', self::LICENSE_TARGET_TOOLS, true)) {
            return $this->approveLicenseTargetStagedRun($run, $approverId);
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
            if ($directTool === 'cipp_assign_user_license') {
                // Fresh ACTIVE re-gate at approval (#405), mirroring context():
                // the person may have been offboarded — and their address
                // reassigned — between staging and approval, and a licence
                // grant to the stored object id would land on the departed
                // user. Same direction as the group-membership 'add' re-gate.
                $person = $this->resolver->resolveActiveCippPerson($client->id, $person->person->id, 'user');
            }
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

        // RECREATABLE_TARGET_STAGED_TOOLS skip the executed-content rail below, so
        // nothing else stops a same-content re-stage from landing on the run that
        // ALREADY removed a rule under this name: firstOrCreate would return that
        // terminal Done row and the revive branch would flip it back to
        // AwaitingApproval and overwrite its proposed_content/proposed_meta,
        // destroying the run-level record of the removal that ran — exactly the
        // history an approver needs to judge that the rule was RE-PLANTED. A
        // re-stage whose key is already spent therefore gets its OWN row.
        if (in_array($tool, self::RECREATABLE_TARGET_STAGED_TOOLS, true)) {
            $unspent = $this->unspentContentHash($tool, $ticket->id, $contentHash);

            if ($unspent === null) {
                $this->auditAttempt($tool, 'blocked', $client->id, $ticket, $person, $license, $contentHash, "{$tool} re-stage refused; this ticket already holds the maximum number of runs for this exact content.", $actorLabel);

                return ['error' => "{$tool} could not be staged: this ticket already holds the maximum number of runs for this exact content; stage the removal on a new ticket."];
            }

            $contentHash = $unspent;
        }

        // The audit log is IMMUTABLE and stays authoritative ONLY for "was this exact
        // content already executed" — an 'executed' row can never go stale the way an
        // 'awaiting_approval' row can (bd psa-k4s0 Root B). Skipped for the verbs whose
        // target can legitimately be re-created under identical safe scalars
        // (RECREATABLE_TARGET_STAGED_TOOLS): there "identical content" no longer means
        // "the same upstream object", and answering already-executed would report a
        // re-planted inbox rule as removed without reading the mailbox at all.
        if (! in_array($tool, self::RECREATABLE_TARGET_STAGED_TOOLS, true) && $this->alreadyExecuted($tool, $client->id, $contentHash)) {
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

    /**
     * Per-target cooldown for the tenant-scoped licence family, checked across
     * BOTH execution paths for the same reason resetCooldownActive() is
     * (security review psa-eerg4 R2).
     *
     * emailSecurityCooldownActive() filters action_type to ONE exact name. A
     * direct call audits as cipp_assign_tenant_user_license, but an approval
     * audits as cipp_stage_assign_tenant_user_license — auditAttempt() uses
     * $run->action_type, which is the right provenance and the wrong thing to
     * filter on. A single-name lookup is therefore asymmetric: it catches
     * direct-then-approve, while the write an approval just made is invisible to
     * the NEXT approval and to a later direct call.
     *
     * That asymmetry costs more here than anywhere else in this class. This
     * family carries no executed-content rail and no identity dedup at all — a
     * licence seat is a recreatable target (RECREATABLE_TARGET_STAGED_TOOLS) and
     * no log-derived key can see a removal between two grants — so this cooldown
     * is the ONLY runaway guard on a billing write. Blind on the staged path it
     * is not a guard, it is a comment.
     *
     * EXECUTED rows only, deliberately, for resetCooldownActive()'s reason: the
     * staging call leaves an awaiting_approval row under the STAGED name
     * carrying this same target key, and counting it would make every proposal
     * block its own approval. Nothing is lost on the direct name — staging never
     * audits under it — and runaway STAGING is refused by the ticket-scoped
     * proposal cooldown, which is a different question.
     */
    private function licenseTargetCooldownActive(string $tool, int $clientId, string $targetKey, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return false;
        }

        // Both names come from the alias map rather than being written out, so a
        // renamed verb cannot leave this guard filtering on a name that nothing
        // audits under.
        $directTool = self::STAGED_TO_DIRECT[$tool] ?? $tool;
        $names = array_values(array_unique(array_merge(
            [$directTool],
            array_keys(self::STAGED_TO_DIRECT, $directTool, true),
        )));

        return TechnicianActionLog::query()
            ->whereIn('action_type', $names)
            ->where('client_id', $clientId)
            ->where('created_at', '>=', now()->subSeconds($cooldownSeconds))
            ->where('result_status', 'executed')
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
     * Direct path for the tenant-scoped licence assignment.
     *
     * Shaped on executeGroupMembershipDirect(): the VERIFICATION READ FIRST,
     * then both dedup rails (exact-content AND identity-keyed, because the same
     * user + SKU assigned from a different reason is still the same entitlement
     * change), then the targetKey cooldown, then upstream. The read leads
     * because BOTH rails are keyed on the object id it returns and never on the
     * caller's typed address — see licenseTargetKey().
     *
     * The verified user is resolved INSIDE the try so a scope refusal is audited
     * as 'rejected' with the operator-readable reason rather than escaping as a
     * 500 — the group-membership contract.
     *
     * @return array<string, mixed>
     */
    private function executeLicenseTargetDirect(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->licenseTargetContext($tool, $arguments, $clientId, $actorLabel, requireTicket: false);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var string $tenant */
        $tenant = $context['tenant'];
        /** @var Ticket|null $ticket */
        $ticket = $context['ticket'];
        /** @var ResolvedCippLicense $license */
        $license = $context['license'];
        /** @var array{target_upn: string, sku_id: string} $params */
        $params = $context['params'];
        $reason = (string) $context['reason'];

        // THE VERIFICATION READ RUNS BEFORE EITHER DEDUP RAIL, because both are
        // keyed on the object id it returns and nothing else can produce one.
        // Keyed on the caller's typed address instead, a UPN reassigned to a NEW
        // user object inside DIRECT_DEDUP_HOURS collided with the OLD object's
        // executed row: this method answered success/idempotent, made no upstream
        // call, and the new starter never got the seat — a false success on a
        // billing write, the same class the resolved-SKU keying already fixed for
        // the other half of this key. The read is idempotent and cheap next to a
        // wrong seat; the ordering IS the fix (see licenseTargetKey()).
        //
        // A pre-verification refusal is audited under the CLAIM key, because at
        // that point a claim is all there is. Different prefix, so no dedup or
        // cooldown LIKE built from the identity key can ever match such a row.
        $claimKey = $this->licenseTargetClaimKey($params);
        $claimHash = $this->contentHash($tool, $client->id, null, $ticket?->id, ['license_target_claim' => $claimKey]);

        try {
            $user = $this->verifiedTenantUser($client->id, $tenant, $params['target_upn']);
        } catch (CippWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $client->id, $ticket, null, $license, $claimHash, "{$claimKey}: ".$e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        $targetKey = $this->licenseTargetKey($user['user_id'], $license);
        $contentHash = $this->contentHash($tool, $client->id, null, $ticket?->id, $this->licenseTargetHashParams($user['user_id'], $license));

        // HELD-ONLY WHEN THE PSA HAS A PERSON RECORD FOR THIS TARGET, whatever
        // mode was granted — the PRIVILEGED_GROUP_TYPES rule applied to this
        // family. An ACTIVE mapping already refused inside the verification
        // above; what can reach here is a DEACTIVATED one, and is_active=false is
        // NOT proof that the human is gone: CippContactSyncService's stale sweep
        // deactivates every mapped person outside the client's
        // cipp_sync_group_id filter, so a current, enabled employee the PSA fully
        // maps reads as inactive here while the tenant listing shows them present
        // and enabled. The person-keyed tool refuses them
        // (resolveActiveCippPerson), so this shape must still be able to grant
        // the seat — but not immediately and not person-anonymously. A human
        // approves a card that names the person instead.
        $mappedPersonId = $user['mapped_inactive_person_id'] ?? null;
        if ($mappedPersonId !== null) {
            $message = 'That target_upn is mapped to PSA person #'.$mappedPersonId.', who is currently deactivated in the PSA. A licence for a target the PSA holds a person record for is held-only, whatever mode was granted — call cipp_assign_tenant_user_license with staged=true and a ticket_id for cockpit approval, where an approver sees which person record this address belongs to. If that person is a current employee (a deactivated row can simply mean they are outside the client\'s CIPP sync group), reactivate them and use cipp_assign_user_license instead. Nothing was written.';
            $this->auditAttempt($tool, 'rejected', $client->id, $ticket, null, $license, $contentHash, "{$targetKey}: ".$message, $actorLabel);

            return ['error' => $message];
        }

        // NO "already executed" rail on this verb either — see
        // RECREATABLE_TARGET_STAGED_TOOLS. Both keys this path can build (the
        // content hash and the identity key) come from the same (verified user,
        // resolved SKU) pair, and neither can see that the seat was REMOVED in
        // between: cipp_remove_user_license audits under a person-keyed target
        // key, and a removal made in the CIPP portal audits nowhere. Suppressing
        // the re-assignment as a duplicate answered success/idempotent with no
        // upstream call while the user held no licence — a false success on a
        // billing write. The write is harmless to repeat (assigning a SKU the
        // user already holds is an upstream no-op), so it goes through, and the
        // cooldown below is the runaway guard: it refuses honestly rather than
        // reporting work that never happened as done. It is THIS family's
        // cooldown, not the single-name one: an approval audits under the STAGED
        // action_type, so a lookup filtered on the direct name alone is blind to
        // the write an approval just made (see licenseTargetCooldownActive()).
        if ($this->licenseTargetCooldownActive($tool, $client->id, $targetKey, self::COOLDOWNS[$tool] ?? 300)) {
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, null, $license, $contentHash, "{$targetKey}: {$tool} cooldown active; upstream call refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no upstream call was made."];
        }

        try {
            $this->client->assignUserLicense($tenant, $user['user_id'], (string) $license->skuId);
        } catch (CippClientException $e) {
            $this->auditAttempt($tool, 'error', $client->id, $ticket, null, $license, $contentHash, "{$targetKey}: ".$this->safeFailureSummary($tool, $e), $actorLabel);

            return ['error' => "CIPP write failed for {$tool}; treat the licence assignment as not applied."];
        }

        $this->auditAttempt($tool, 'executed', $client->id, $ticket, null, $license, $contentHash, "{$targetKey}: {$tool} executed — ".$this->licenseTargetAuditDetail($user, $license).": {$reason}", $actorLabel);

        return [
            'success' => true,
            'tool' => $tool,
            'target_upn' => $user['upn'],
            'ticket_id' => $ticket?->id,
            'message' => 'CIPP licence assignment executed.',
        ];
    }

    /**
     * Staged twin — the DEFAULT path, since grants start staged-only.
     *
     * Staging performs the same read-only verification lookup as the direct
     * path (never the write) so the cockpit proposal names the SERVER-verified
     * user rather than the caller's typed address, and stores that snapshot so
     * approval can detect drift. The held payload carries only local scalars
     * plus the verified snapshot; approval re-verifies everything fresh.
     *
     * @return array<string, mixed>
     */
    private function stageLicenseTargetAction(string $tool, array $arguments, int $clientId, string $actorLabel): array
    {
        $context = $this->licenseTargetContext($tool, $arguments, $clientId, $actorLabel, requireTicket: true);
        if (isset($context['error'])) {
            return ['error' => $context['error']];
        }

        /** @var Client $client */
        $client = $context['client'];
        /** @var string $tenant */
        $tenant = $context['tenant'];
        /** @var Ticket $ticket */
        $ticket = $context['ticket'];
        /** @var ResolvedCippLicense $license */
        $license = $context['license'];
        /** @var array{target_upn: string, sku_id: string} $params */
        $params = $context['params'];
        $reason = (string) $context['reason'];
        $directTool = self::STAGED_TO_DIRECT[$tool];

        // Verification leads here for the same reason it leads on the direct
        // path: the content hash and the identity key are both built from the
        // OBJECT ID, so a UPN re-pointed at a new user stages its own run instead
        // of being handed the previous object's run under idempotent: true. The
        // pre-verification refusal keys on the claim, which is all there is yet.
        $claimKey = $this->licenseTargetClaimKey($params);
        $claimHash = $this->contentHash($tool, $client->id, null, $ticket->id, ['license_target_claim' => $claimKey]);

        try {
            $user = $this->verifiedTenantUser($client->id, $tenant, $params['target_upn']);
        } catch (CippWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $client->id, $ticket, null, $license, $claimHash, "{$claimKey}: ".$e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        $targetKey = $this->licenseTargetKey($user['user_id'], $license);
        $contentHash = $this->contentHash($tool, $client->id, null, $ticket->id, $this->licenseTargetHashParams($user['user_id'], $license));

        // NO executed-content rail on this verb: the target is RECREATABLE
        // (RECREATABLE_TARGET_STAGED_TOOLS). A seat that was granted, removed and
        // needs granting again is an ordinary re-stage, and neither an audit row
        // nor a content hash can tell it apart from a repeat — so answering
        // "already executed" here refuses a real grant with a success.
        //
        // Skipping it means the firstOrCreate below can land on a run this ticket
        // has already SPENT (the Done row for the earlier grant), which the revive
        // branch would flip back to AwaitingApproval and overwrite — destroying
        // the cockpit record of the assignment that ran, on exactly the re-grant
        // this exemption exists to allow. A spent key is therefore walked forward,
        // the same way stageAction() does it for the rule removal.
        if (in_array($tool, self::RECREATABLE_TARGET_STAGED_TOOLS, true)) {
            $unspent = $this->unspentContentHash($tool, $ticket->id, $contentHash);

            if ($unspent === null) {
                $this->auditAttempt($tool, 'blocked', $client->id, $ticket, null, $license, $contentHash, "{$targetKey}: {$tool} re-stage refused; this ticket already holds the maximum number of runs for this exact content.", $actorLabel);

                return ['error' => "{$tool} could not be staged: this ticket already holds the maximum number of runs for this exact content; stage the assignment on a new ticket."];
            }

            $contentHash = $unspent;
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
            $this->auditAttempt($tool, 'blocked', $client->id, $ticket, null, $license, $contentHash, "{$targetKey}: {$tool} cooldown active; staged proposal refused.", $actorLabel);

            return ['error' => "{$tool} cooldown active for this target; no proposal was staged."];
        }

        // The stored params carry the verified SNAPSHOT of ALL THREE material
        // facts — the user, the licence AND the PSA mapping — so approval can
        // detect any of them drifting against a fresh resolution: the UPN
        // reassigned to a different object, the licence row re-synced (or a
        // second active row won an unordered first()) to a different SKU, or the
        // nightly contact sync creating (or clearing) the person record the card
        // names. The approver signs off on one seat for one person; every half
        // has to still be the one on the card.
        $storedParams = array_merge($params, [
            'verified_user_id' => $user['user_id'],
            'verified_display_name' => $user['display_name'],
            // The RESOLVED SKU, not the caller's sku_id claim: the claim only
            // ever selected a local licence type, and the resolved value is what
            // the card names and what reaches upstream.
            'verified_sku_id' => (string) $license->skuId,
            // The PSA MAPPING the card asserts, in both directions: the id of
            // the mapped-but-deactivated person the approver is told to open, or
            // null for the card's positive claim that no person record exists.
            // Frozen here because there is nothing to compare against at
            // approval otherwise, and a card that DENIES a mapping would then
            // execute after one appeared — granting the seat with no human ever
            // shown the record, which is the whole reason the id is named.
            'verified_mapped_person_id' => $user['mapped_inactive_person_id'],
        ]);

        $meta = [
            'drafted_by' => $actorLabel,
            'reasons' => [$reason],
            'direct_tool' => $directTool,
            'person_id' => null,
            'redacted_params' => $storedParams,
            'sensitive_inputs' => [],
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'direct_tool' => $directTool,
                'family' => 'license_target',
                'client_id' => $client->id,
                'person_id' => null,
                'ticket_id' => $ticket->id,
                'params' => $storedParams,
            ], JSON_THROW_ON_ERROR)),
        ];
        $proposedContent = $this->licenseTargetStagedDisplay($user, $license)."\nReason: ".$reason;

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

        $this->auditAttempt($tool, 'awaiting_approval', $client->id, $ticket, null, $license, $contentHash, "{$targetKey}: MCP staged {$tool} — ".$this->licenseTargetAuditDetail($user, $license).": {$reason}", $actorLabel, $run->id);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'run_id' => $run->id,
            'message' => 'Staged for cockpit approval.',
        ];
    }

    /**
     * Approval replay for a held tenant-scoped licence assignment. Everything
     * is revalidated from the ENCRYPTED payload through the same gates as the
     * initial call, and the user is re-verified against the LIVE tenant
     * listing: a UPN that now resolves to a different object id declines
     * rather than assigning a paid seat to somebody the operator never
     * reviewed. A re-fired approval of an identical already-executed
     * assignment is a LOGGED NO-OP, never a second upstream call.
     */
    private function approveLicenseTargetStagedRun(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        try {
            // THE THREE EARLIEST REFUSALS AUDIT TOO. Found by enumerating every
            // early exit in this family rather than waiting for the next panel to
            // name the next one: r1 through r4 each reported ONE site of a class
            // and I fixed only that site, four times running. These three are the
            // same class as r4's diff:3 — a tampered, unreadable or
            // wrong-client held payload is precisely the approval you would want
            // to find in the log later, and it was the quietest thing this method
            // could do. client_id falls back to the run's own, because the
            // payload's is what failed to verify.
            $payload = $this->decryptRunPayload($run);
            if ($payload === null) {
                $this->auditAttempt($run->action_type, 'rejected', (int) $run->client_id, null, null, null, $run->content_hash, 'Staged licence assignment refused at approval: the held payload could not be decrypted.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('The held payload could not be read; deny this proposal and re-stage it.');
            }

            $directTool = (string) ($payload['direct_tool'] ?? '');
            if ((self::STAGED_TO_DIRECT[$run->action_type] ?? null) !== $directTool
                || (string) ($payload['family'] ?? '') !== 'license_target') {
                $this->auditAttempt($run->action_type, 'rejected', (int) $run->client_id, null, null, null, $run->content_hash, 'Staged licence assignment refused at approval: the held payload does not match this action type or family.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('The held payload does not match this action type; deny this proposal and re-stage it.');
            }

            $client = Client::find((int) ($payload['client_id'] ?? 0));
            if (! $client || (int) $client->id !== (int) $run->client_id) {
                $this->auditAttempt($run->action_type, 'rejected', (int) $run->client_id, null, null, null, $run->content_hash, 'Staged licence assignment refused at approval: the held payload\'s client could not be re-verified against the run.', $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('The proposal\'s client could not be re-verified; deny this proposal and re-stage it.');
            }

            $contentHash = $run->content_hash;

            // AUDITED, because the drift rails below promise that every refusal
            // reaching this method leaves a row. These re-resolutions can all
            // refuse AT APPROVAL — a cleared tenant mapping, a moved ticket, a
            // de-synced SKU — and falling through to the outer catch declined
            // correctly while writing nothing, which is indistinguishable in
            // TechnicianActionLog from the approval never having been attempted.
            //
            // The row carries WHATEVER WAS ALREADY RESOLVED, not null. The body
            // below is sequential, so a failure in licenseTargetParams() or the
            // SKU resolution happens with the ticket already in hand — passing
            // null there discards a fact the log needs to be searchable by, and
            // "which resolution refused is unknown" is only true of the ones
            // that had not run yet. $ticket stays null when the ticket itself
            // is what refused, which is the honest value in that case.
            $ticket = null;
            try {
                $tenant = $this->resolver->resolveCippTenant($client);
                $ticket = $this->resolver->resolveTicketForHeldAction($client->id, $payload['ticket_id'] ?? null);
                $stored = is_array($payload['params'] ?? null) ? $payload['params'] : [];
                $params = $this->licenseTargetParams($stored);
                $license = $this->resolver->resolveCippLicenseBySku($client->id, $params['sku_id']);
            } catch (CippWriteScopeException $e) {
                $this->auditAttempt($run->action_type, 'rejected', $client->id, $ticket, null, null, $contentHash, 'Staged licence assignment refused at approval before any upstream call: '.$e->getMessage(), $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined($e->getMessage());
            }

            // The kill switch below runs before ANYTHING reaches upstream —
            // including the verification READ — so it audits under the CLAIM key:
            // at that point no server-derived identity exists yet. Different
            // prefix, so no dedup or cooldown LIKE built from licenseTargetKey()
            // can ever match a row carrying it.
            $claimKey = $this->licenseTargetClaimKey($params);

            if (TechnicianConfig::killSwitchEngaged()) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, null, $license, $contentHash, "{$claimKey}: Technician kill-switch engaged; staged CIPP write refused.", $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('Technician kill-switch engaged; the staged CIPP write was refused.');
            }

            // The re-verification refusals are audited for the same reason the
            // drift declines below are, and this is the one that matters most:
            // an account disabled BETWEEN staging and approval is the exact
            // event this re-gate exists to surface, and an empty or degraded
            // tenant listing at approval time must be distinguishable in the
            // log from the approval never having been attempted.
            try {
                $user = $this->verifiedTenantUser($client->id, $tenant, $params['target_upn']);
            } catch (CippWriteScopeException $e) {
                $this->auditAttempt($run->action_type, 'rejected', $client->id, $ticket, null, $license, $contentHash, "{$claimKey}: tenant user re-verification refused the approval before any upstream call — ".$e->getMessage(), $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined($e->getMessage());
            }

            // Built from the FRESHLY VERIFIED user AND the FRESHLY RESOLVED
            // licence, so neither half can close this approval as a duplicate of
            // a write that assigned a different SKU or a different user object; a
            // re-synced entitlement or a re-pointed address falls through to the
            // drift rail that exists to judge it. That is also why the read and
            // BOTH drift rails now precede the already-executed check, which sits
            // below them: keyed on the claim and run first, it closed the approval
            // as 'already_handled' — a logged no-op, run marked Done, no seat
            // assigned — whenever the address had been reassigned inside
            // DIRECT_DEDUP_HOURS, while the approval card promises the opposite,
            // that approval declines if the address now points at a different
            // object.
            //
            // Moving it past the USER rail alone fixed only half of that, because
            // this key's OTHER half is the FRESHLY RESOLVED SKU. A vendor_ref
            // re-sync between staging and approval points the dedup at an
            // entitlement the operator never approved, so an executed row for that
            // (user, NEW SKU) pair closed the run as 'already_handled' — Done and
            // therefore not even re-approvable, with an audit row claiming an
            // identical user/SKU had already executed — instead of the licence
            // drift decline the card promises. Both verifications first, then both
            // drift rails, THEN the dedup: by the time it runs, the resolved SKU
            // is provably the staged one, so the key it asks about is the seat the
            // operator actually approved.
            $targetKey = $this->licenseTargetKey($user['user_id'], $license);

            // Drift rail: the operator approved a proposal naming ONE object.
            // A UPN can be reassigned; if it now points somewhere else the
            // approval is for a different person and must decline.
            $stagedUserId = trim((string) ($stored['verified_user_id'] ?? ''));
            if ($stagedUserId === '' || strcasecmp($user['user_id'], $stagedUserId) !== 0) {
                // AUDITED, like every other refusal that can reach this point.
                // A drift decline is the most interesting thing that can happen
                // on this path — it means the target moved between the operator
                // reading the card and approving it — and it was the only
                // refusal here leaving no row in TechnicianActionLog.
                $this->auditAttempt($run->action_type, 'rejected', $client->id, $ticket, null, $license, $contentHash, "{$targetKey}: user drift at approval — the address now resolves to a different object id than the one staged; approval declined before any upstream call.", $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('The user behind that address changed after this action was staged; deny this proposal and re-stage it against the current user.');
            }

            // The SAME rail for the LICENCE, and it needs its own: the user rail
            // cannot see this drift, because the user object is unchanged.
            // resolveCippLicenseBySku() re-resolves the SKU fresh above, from an
            // unordered first() over the client's active licence rows, so a
            // vendor_ref re-sync or a second active row for this licence type
            // between staging and approval sends a DIFFERENT — possibly costlier
            // — SKU upstream than the one on the approved card. The approval
            // gate's invariant is that the operator reviewed exactly what
            // executes; on a billing write the SKU is half of what they reviewed.
            $stagedSkuId = trim((string) ($stored['verified_sku_id'] ?? ''));
            if ($stagedSkuId === '' || strcasecmp((string) $license->skuId, $stagedSkuId) !== 0) {
                // Audited for the same reason as the user rail, and this one
                // carries money: a silent decline here means nobody can later
                // tell that a SKU re-sync changed what would have been billed.
                $this->auditAttempt($run->action_type, 'rejected', $client->id, $ticket, null, $license, $contentHash, "{$targetKey}: licence drift at approval — the SKU now resolves to a different licence than the one on the approved proposal; approval declined before any upstream call.", $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('The licence this SKU maps to changed after this action was staged, so approving it would assign a different licence than the one named on the proposal; deny this proposal and re-stage it.');
            }

            // AND THE SAME RAIL FOR THE PSA MAPPING, which needs its own too:
            // neither rail above can see this drift, because the user object and
            // the SKU are both unchanged. The card makes a POSITIVE claim about
            // the mapping in both directions — it either names the deactivated
            // person the approver is told to open before approving, or states
            // that the PSA holds no person record for this address or object id
            // — and that sentence is what the whole held-only treatment of a
            // mapped target rests on. cipp:sync-contacts creates the row and the
            // group-filtered stale sweep deactivates it, so a mapping can appear
            // (or be cleared) between the operator reading the card and
            // approving it: without this rail, a card reading 'no person record'
            // grants the seat with no human ever shown the record that now
            // exists, and the executed row names a person the approver was told
            // in writing did not exist.
            //
            // A payload staged before this snapshot existed reads as null and so
            // declines against any live mapping, which is the loud direction: the
            // operator re-stages and reads the current card.
            $stagedMapped = $stored['verified_mapped_person_id'] ?? null;
            $stagedMappedPersonId = is_numeric($stagedMapped) ? (int) $stagedMapped : null;
            $freshMappedPersonId = $user['mapped_inactive_person_id'];
            if ($stagedMappedPersonId !== $freshMappedPersonId) {
                $stagedMappedLabel = $stagedMappedPersonId === null ? 'no PSA person record' : 'PSA person #'.$stagedMappedPersonId;
                $freshMappedLabel = $freshMappedPersonId === null ? 'no PSA person record' : 'PSA person #'.$freshMappedPersonId;
                // Audited like the other two rails: the person named on the card
                // is the fact the approver acted on, so a silent decline would
                // leave no record that it had moved under them.
                $this->auditAttempt($run->action_type, 'rejected', $client->id, $ticket, null, $license, $contentHash, "{$targetKey}: PSA mapping drift at approval — the approved card named {$stagedMappedLabel} for this address, the PSA now holds {$freshMappedLabel}; approval declined before any upstream call.", $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('The PSA person record mapped to that address changed after this action was staged, so the proposal you approved describes a different mapping than the one that exists now; deny this proposal and re-stage it.');
            }

            // AND NO DEDUP AT ALL HERE, for the reason the direct path states:
            // the identity key cannot see a removal between two grants, so it
            // could not tell a duplicate approval from a legitimate re-grant. On
            // this path that rail did not merely mis-answer — it TERMINATED the
            // approved run (advanceTo(Done)), so the operator could not even
            // re-approve the grant it had declined to perform, and the log said
            // an identical user/SKU had already executed. A human approved this
            // seat after reading a card naming the user and the SKU; the only
            // rails allowed to stop it are the ones that can PROVE something
            // changed (the two drift rails above) or that refuse honestly (the
            // cooldown below). A re-fired approval of THIS run is already
            // impossible without them: claimForExecution() fails on a Done run.
            //
            // Which is exactly why this cooldown has to span BOTH action_type
            // names: the row an approval writes carries the STAGED one, so the
            // single-name lookup that used to sit here could not see the
            // preceding approval's own executed write, and back-to-back approvals
            // of duplicate proposals reached upstream with no rail observing the
            // first (see licenseTargetCooldownActive()).
            if ($this->licenseTargetCooldownActive($directTool, $client->id, $targetKey, self::COOLDOWNS[$directTool] ?? 300)) {
                $this->auditAttempt($run->action_type, 'blocked', $client->id, $ticket, null, $license, $contentHash, "{$targetKey}: CIPP staged action cooldown active; approval refused before upstream call.", $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                return $this->declined('A recent action for this target is still in cooldown; wait a few minutes and approve again.');
            }

            try {
                $this->client->assignUserLicense($tenant, $user['user_id'], (string) $license->skuId);
            } catch (CippClientException $e) {
                $this->auditAttempt($run->action_type, 'error', $client->id, $ticket, null, $license, $contentHash, "{$targetKey}: ".$this->safeFailureSummary($run->action_type, $e), $this->approverLabel($approverId), $run->id, $approverId);
                $run->releaseClaim();

                // SANITIZED, exactly as the direct path's identical catch is.
                // safeFailureSummary() exists because a CIPP relay error body
                // can embed the request URL with tenant and credential query
                // parameters; applying it to the AUDIT ROW while handing the raw
                // exception message to declined() surfaces upstream text to the
                // approver that the immutable log deliberately does not carry.
                // declined() redacts and bounds what it is given, but it cannot
                // know this reason came from upstream — so the generic sentence
                // is what it gets, and the specific cause stays in the row.
                return $this->declined("CIPP write failed for {$run->action_type}; treat the licence assignment as not applied.");
            }

            $this->auditAttempt($run->action_type, 'executed', $client->id, $ticket, null, $license, $contentHash, "{$targetKey}: Operator-approved {$run->action_type} executed — ".$this->licenseTargetAuditDetail($user, $license).'.', $this->approverLabel($approverId), $run->id, $approverId);
            $run->advanceTo(TechnicianRunState::Done);

            return new TechnicianApprovalResult('executed');
        } catch (CippWriteScopeException $e) {
            // The sweep-up arm, audited for completeness: every scope refusal
            // inside the body already audits before returning, so reaching here
            // means a throw from somewhere that did not, and a decline with no
            // row is the one outcome this method must never produce.
            $this->auditAttempt($run->action_type, 'rejected', (int) $run->client_id, null, null, null, $run->content_hash, 'Staged licence assignment refused at approval: '.$e->getMessage(), $this->approverLabel($approverId), $run->id, $approverId);
            $run->releaseClaim();

            return $this->declined($e->getMessage());
        } catch (\Throwable $e) {
            $run->releaseClaim();

            throw $e;
        }
    }

    /**
     * Shared front door for the tenant-scoped licence write: the same
     * caller-input gates as context() — upstream-identifier blocklist,
     * required redacted reason, kill-switch, client + tenant + ticket
     * resolution — with target_upn/sku_id validation in place of person and
     * license_type_id resolution.
     *
     * THE DELIBERATE RELAXATION, and it is the reviewable line in this family:
     * BOTH of this family's parameters — target_upn AND sku_id — are already in
     * UPSTREAM_IDENTIFIER_KEYS, so the standard blocklist call refuses every
     * call this family exists to serve. They are allowed HERE ONLY, by name,
     * and are never removed from the global list; every other tool keeps
     * refusing both, and every OTHER blocklisted key still refuses here.
     *
     * What the relaxation preserves is the direction of trust: the caller
     * supplies a CLAIM and the server VALIDATES it against its own reads —
     * neither value reaches upstream as the caller typed it.
     * resolveCippLicenseBySku() matches the SKU claim against synced licence
     * rows and answers with local objects, with the client-entitlement gate
     * untouched — a SKU this client has no active local licence row for is
     * still refused. verifiedTenantUser() matches the address claim against the
     * resolved tenant's live listing and answers with the object id the SERVER
     * read.
     *
     * WHAT THAT VALIDATION DOES NOT ESTABLISH: the live-listing check proves
     * the address EXISTS and is unambiguous in this tenant — it closes absent,
     * ambiguous, cross-tenant, disabled and ACTIVELY-PSA-mapped targets. Only
     * ACTIVE mappings: a mapped but DEACTIVATED person is deliberately NOT
     * closed here — verifiedTenantUser() hands that person id back as
     * mapped_inactive_person_id, and each caller carries its own held-only
     * rail for it (executeLicenseTargetDirect()). A further verb on this front
     * door must carry one too. Nor does the check
     * establish that the address is the one the operator MEANT: two real,
     * enabled, unmapped addresses in the same tenant pass every gate here, and
     * unlike the person-path front door there is no opaque-id/confirm_upn
     * cross-check to catch the substitution. Wrong-but-real is closed by
     * nothing in this method. That gap is why the direct tenant verbs stay
     * ungranted absent a need the staged twin cannot meet (#525).
     *
     * (Measured the hard way: the first cut of this family allowed only
     * sku_id, on a reading of the key list that had been truncated before
     * target_upn. Every call refused. The tests below are what caught it.)
     *
     * @return array{client?: Client, tenant?: string, ticket?: Ticket|null, license?: ResolvedCippLicense, params?: array{target_upn: string, sku_id: string}, reason?: string, error?: string}
     */
    private function licenseTargetContext(string $tool, array $arguments, int $clientId, string $actorLabel, bool $requireTicket): array
    {
        $contentHash = $this->contentHash($tool, $clientId, null, null, $arguments);

        if ($keys = $this->upstreamIdentifierKeysAllowing($arguments, self::LICENSE_TARGET_ALLOWED_KEYS)) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'Caller-supplied upstream CIPP identifiers are not accepted: '.implode(', ', $keys).'.', $actorLabel);

            // The offending keys are named in the RESULT here, not only in the
            // audit: they are the caller's own argument names, they carry
            // nothing sensitive, and an agent that cannot see which key it was
            // refused for retries blind.
            return ['error' => 'Caller-supplied upstream CIPP identifiers are not accepted: '.implode(', ', $keys).'. Provide target_upn, sku_id, and ticket_id only.'];
        }

        // A REAL person-shape value on the tenant verb refuses, enforced rather
        // than documented. This tool's schema declares no person keys, so a
        // person_id / license_type_id / confirm_upn carrying a value here is a
        // call that named TWO different users — one by PSA person, one by
        // tenant address — and silently ignoring the person half would land a
        // billing write on the address with the person tool's typed-confirmation
        // rail bypassed and an audit summary naming only the target that won.
        // There is no safe way to pick one, so refuse.
        // PRESENCE IS NOT THE SAME AS A VALUE: a client templating from an old
        // cached merged schema, or defensively filling every slot, emits
        // "person_id": null alongside a perfectly unambiguous tenant-shape
        // call. That is a filled-in template, not a second target — a key
        // counts as sent only when it carries something that could name a
        // person (sentValue), the reading all three verify seats of the merged
        // era converged on.
        $mixedShape = array_values(array_filter(
            self::LICENSE_PERSON_SHAPE_KEYS,
            fn (string $key): bool => $this->sentValue($arguments, $key),
        ));
        if ($mixedShape !== []) {
            $this->auditAttempt($tool, 'rejected', $clientId, null, null, null, $contentHash, 'Person-shape keys on the tenant-target licence tool: '.implode(', ', $mixedShape).'.', $actorLabel);

            return ['error' => 'This tool targets a tenant user with no PSA person record, by target_upn + sku_id only. For a PSA-mapped person use cipp_assign_user_license with person_id + license_type_id + confirm_upn. This call sent '.implode(', ', $mixedShape).'; nothing was written. Drop the person-shape keys or switch tools.'];
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
            $params = $this->licenseTargetParams($arguments);
            $license = $this->resolver->resolveCippLicenseBySku($client->id, $params['sku_id']);
        } catch (CippWriteScopeException $e) {
            $this->auditAttempt($tool, 'rejected', $client->id, null, null, null, $contentHash, $e->getMessage(), $actorLabel);

            return ['error' => $e->getMessage()];
        }

        return [
            'client' => $client,
            'tenant' => $tenant,
            'ticket' => $ticket,
            'license' => $license,
            'params' => $params,
            'reason' => $reason,
        ];
    }

    /**
     * The upstream-identifier blocklist minus a named allowance. Separate from
     * upstreamIdentifierKeys() on purpose: the global helper stays the default
     * everywhere, and any relaxation has to name the key it is relaxing at the
     * call site, where a reviewer will see it.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    private function upstreamIdentifierKeysAllowing(array $arguments, array $allowed): array
    {
        // VALUE-tests, unlike the global helper, and only this family calls it.
        // A client templating from the merged-era schema, or defensively
        // filling slots, can still send nulls for keys it is not using; those
        // are filled-in template slots, not attempts to drive upstream
        // identity. Every other tool keeps the stricter presence test — a
        // narrow schema means a blocklisted key appearing at all is already
        // the signal.
        $keys = [];
        foreach (self::UPSTREAM_IDENTIFIER_KEYS as $key) {
            if (! in_array($key, $allowed, true) && $this->sentValue($arguments, $key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Identity-keyed dedup/cooldown key for the licence target: the SAME user
     * and SKU is the same entitlement change however it was described, so it
     * dedups across reasons and tickets the way the group-membership and
     * create-user targets do.
     *
     * HASHED, like groupMembershipTargetKey() and every other non-person
     * target, and for the same reason: this key is matched with SQL LIKE, and
     * FILTER_VALIDATE_EMAIL admits both '_' and '%' in a UPN local part (an
     * underscore is ordinary in real addresses). Interpolated raw, a perfectly
     * valid address becomes a wildcard that matches a DIFFERENT user's audit
     * row — suppressing a real write as a duplicate with success/idempotent, or
     * closing an approved run as already-handled.
     *
     * KEYED ON THE SERVER-VERIFIED OBJECT ID AND THE RESOLVED SKU, NEVER ON
     * EITHER CALLER CLAIM. Both halves are the values that actually execute, and
     * both can drift from the claim that named them without the claim changing a
     * byte:
     *
     *  - the USER — a UPN is reassignable, which is precisely why this family
     *    carries a drift rail at approval. Hashing the typed address made an
     *    assignment to a NEW object collide with the OLD object's executed row:
     *    the 24h identity dedup that used to sit on this key suppressed a real
     *    write and answered success/idempotent while no seat was assigned, and
     *    the staged path closed the approval as 'already_handled' BEFORE the
     *    drift rail written for exactly that could see it. That dedup is GONE —
     *    a licence seat is a recreatable target, see
     *    RECREATABLE_TARGET_STAGED_TOOLS — but this key still selects the
     *    per-target COOLDOWN and prefixes every audit row, so both halves must
     *    still be the values that actually execute.
     *  - the SKU — the claim only ever SELECTS a local licence type; what
     *    reaches upstream, and what is billed, is $license->skuId read out of
     *    the client's active licence row (License.vendor_ref), which a CIPP
     *    re-sync rewrites.
     *
     * One class of defect — a false success on a billing write — so one rule:
     * the key is built from what executes. That is why EVERY call site resolves
     * the user BEFORE computing this key; verifiedTenantUser() is the only thing
     * that can produce the object id, so it has to run first. Rows written
     * before an identity exists are keyed by licenseTargetClaimKey() instead,
     * which cannot be confused with this key: the prefix differs.
     *
     * Lowercased for the same reason licenseTargetParams() lowercases its
     * halves: vendor_ref and the tenant's object id are both stored RAW, and
     * casing must not fork the dedup key or the cooldown.
     */
    private function licenseTargetKey(string $verifiedUserId, ResolvedCippLicense $license): string
    {
        $resolvedSku = mb_strtolower(trim((string) $license->skuId));
        $identity = mb_strtolower(trim($verifiedUserId));

        return 'cipp-license-target #'.substr(hash('sha256', $identity.'|'.$resolvedSku), 0, 12);
    }

    /**
     * The CLAIM-keyed twin, for audit rows written BEFORE the server holds an
     * identity to key on: a pre-verification refusal, or the kill-switch decline
     * at approval. It keeps those rows searchable and keeps the "<key>: message"
     * shape the rest of the family uses — and it is NEVER a dedup or cooldown
     * key, nor can it become one by accident: the prefix differs, so no LIKE
     * built from licenseTargetKey() can match a row carrying this one. Both
     * halves are already lowercased by licenseTargetParams(), and hashing them
     * keeps LIKE wildcards out of the summary for the same reason the identity
     * key is hashed.
     *
     * @param  array{target_upn: string, sku_id: string}  $params
     */
    private function licenseTargetClaimKey(array $params): string
    {
        return 'cipp-license-claim #'.substr(hash('sha256', $params['target_upn'].'|'.$params['sku_id']), 0, 12);
    }

    /**
     * The hashable projection of the licence-target params for contentHash().
     *
     * contentHash() runs safeHashParams(), which strips EVERY key in
     * UPSTREAM_IDENTIFIER_KEYS — and BOTH of this family's params are on that
     * list (that is exactly what LICENSE_TARGET_ALLOWED_KEYS lifts). Hashing
     * them directly leaves an EMPTY params array, so every tenant-scoped
     * assignment for a client/ticket collapses onto one content hash: a second,
     * distinct user or SKU is suppressed as an exact-content duplicate with no
     * upstream call, and the staged firstOrCreate collides with an unrelated
     * run. The target is carried here under a key that is NOT blocklisted, in
     * the same collapsed identity form the dedup rail already uses.
     *
     * @return array<string, string>
     */
    /**
     * Was this key actually SENT, as opposed to merely present?
     *
     * The single answer to that question for the licence family. In the
     * merged-schema era the mutual-exclusion guard and the dispatch asked it
     * differently (null-as-unsent vs array_key_exists), which made the
     * person-keyed path unreachable for any client that filled the published
     * template; dispatch is by tool name now, but the guard and the
     * upstream-identifier blocklist still both ask, and they go through this
     * one helper so they cannot drift apart again.
     *
     * A filled-in template is not an argument: null and a whitespace-only string
     * are both "not sent". Anything else is the caller naming a target.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function sentValue(array $arguments, string $key): bool
    {
        if (! array_key_exists($key, $arguments)) {
            return false;
        }

        $value = $arguments[$key];

        return $value !== null && (! is_string($value) || trim($value) !== '');
    }

    private function licenseTargetHashParams(string $verifiedUserId, ResolvedCippLicense $license): array
    {
        return ['license_target' => $this->licenseTargetKey($verifiedUserId, $license)];
    }

    /**
     * @param  array{user_id: string, upn: string, display_name: string, mapped_inactive_person_id?: int|null}  $user
     */
    private function licenseTargetAuditDetail(array $user, ResolvedCippLicense $license): string
    {
        // NAMED WHEN THE PSA HAS A RECORD. Every licenseTarget audit row passes
        // person = null to auditAttempt() — this shape has no ResolvedCippPerson
        // to link — so for a mapped-but-deactivated target the row said nothing
        // about a person the PSA fully maps, on a billing write. The id goes in
        // the summary, which is what this log is searchable by.
        $mappedPersonId = $user['mapped_inactive_person_id'] ?? null;

        return 'assigned license_type #'.$license->licenseType->id.' (SKU '.$license->skuId.') to tenant user '.$user['upn']
            .($mappedPersonId !== null ? ' (mapped to DEACTIVATED PSA person #'.$mappedPersonId.')' : '');
    }

    /**
     * @param  array{user_id: string, upn: string, display_name: string, mapped_inactive_person_id?: int|null}  $user
     */
    private function licenseTargetStagedDisplay(array $user, ResolvedCippLicense $license): string
    {
        // A licence assignment is a billing decision: the approver must see WHO
        // and WHICH SKU without leaving the queue. The user is named by its
        // SERVER-VERIFIED UPN and display name — never the caller's typed
        // address — and the licence by the local row the entitlement gate
        // matched, so "which seat am I paying for" is answerable on the card.
        $userLabel = $user['upn'].($user['display_name'] !== '' ? ' ('.$user['display_name'].')' : '');

        // THE APPROVER IS TOLD WHICH PERSON RECORD THIS IS, when there is one.
        // This card is the only human gate on the write, and "deactivated in the
        // PSA" is not the same fact as "gone": the contact sync deactivates any
        // mapped person outside the client's sync group, so the address can
        // belong to a current employee the PSA fully maps. Naming the id is what
        // lets the approver check that instead of assuming a leaver.
        $mappedPersonId = $user['mapped_inactive_person_id'] ?? null;
        $mappingNote = $mappedPersonId === null
            ? ' The PSA holds no person record mapped to this address or object id.'
            : ' THIS ADDRESS IS MAPPED TO PSA PERSON #'.$mappedPersonId.', WHO IS DEACTIVATED IN THE PSA — open that record before approving: a person reads as deactivated here merely for being outside the client\'s CIPP sync group, and this held path is the only shape that can grant them a seat.';

        return 'Assign licence "'.$license->licenseType->name.'" (SKU '.$license->skuId.') to tenant user '.$userLabel.'.'
            .' This consumes a paid seat and grants the Microsoft 365 apps and services that SKU carries.'
            .' The target is NOT mapped to an ACTIVE PSA person: the PSA\'s person records were checked for this address AND for this object id, and a target mapped to an active person is refused rather than staged (it belongs on the person-keyed tool). A mapped but DEACTIVATED person is served here, because the person-keyed tool refuses them and no other shape could grant the seat.'.$mappingNote.' The user itself was verified against the tenant\'s live user listing.'
            .' Approval re-verifies the user and the licence mapping fresh, and declines if that address now points at a different user object, or if this SKU now maps to a different licence than the one named here.';
    }

    /**
     * Validate the tenant-scoped licence-target scalar params. Runs on the
     * initial call (against caller arguments) AND on the approval replay
     * (against the decrypted stored payload), so a tampered or drifted
     * payload re-fails the same gates instead of being trusted — the
     * groupMembershipParams() contract.
     *
     * target_upn is a CLAIM, never an identity: it is bounded, shape-checked
     * and canonicalized here, and only verifiedTenantUser() can turn it into
     * an object id, by reading it back out of the resolved tenant. The
     * parameter is deliberately NOT named userPrincipalName / Username /
     * cipp_upn — those are refused outright by UPSTREAM_IDENTIFIER_KEYS, and
     * a caller who reaches for one should keep hitting that wall.
     *
     * Both values are lowercased so casing can never fork the idempotency
     * hash or the dedup/cooldown keys.
     *
     * @param  array<string, mixed>  $source
     * @return array{target_upn: string, sku_id: string}
     */
    private function licenseTargetParams(array $source): array
    {
        $upn = $this->boundedString($source, 'target_upn', self::CREATE_UPN_MAX, required: true) ?? '';
        if (filter_var($upn, FILTER_VALIDATE_EMAIL) === false) {
            throw new CippWriteScopeException('target_upn must be the user\'s full Microsoft 365 user principal name (e.g. person@contoso.com).');
        }

        $sku = $this->boundedString($source, 'sku_id', self::LICENSE_SKU_MAX, required: true) ?? '';

        return [
            'target_upn' => mb_strtolower($upn),
            'sku_id' => mb_strtolower($sku),
        ];
    }

    /**
     * The tenant-scope gate for the licence target: fetch the resolved
     * tenant's LIVE user listing through the same credentialed client the
     * licence write uses, and require the typed UPN to be present in it.
     * This is the quarantine-release / verifiedGroupRow() precedent applied
     * to a user: a UPN the caller typed can only ever become an object id the
     * SERVER read out of the resolved tenant, so a user in any other tenant
     * can never be targeted.
     *
     * AN EMPTY LISTING IS A REFUSAL, NOT "no such user". CippRestWriteClient
     * ::listUsers() is queue-guarded at the source, but its docblock is
     * explicit that the polarity of an empty result belongs to the caller —
     * so it is decided here, once, in the direction that cannot lose:
     * "we read nothing" and "this tenant has no such user" are the same shape
     * and opposite conclusions, and only one of them is safe to act on.
     *
     * Zero-match and multi-match both refuse, and a matched row with no
     * object id refuses rather than falling through to an empty target.
     *
     * AND SO DOES A TARGET THAT IS MAPPED TO AN ACTIVE PSA PERSON. This shape's
     * premise is a tenant user the person-keyed path cannot express; a target
     * mapped to an ACTIVE person belongs there instead, with its typed
     * confirmation and person-scoped gates, so $clientId is taken here purely to
     * prove that. A mapped but DEACTIVATED person is NOT diverted — that path
     * refuses them outright, so diverting would leave the seat unassignable by
     * every shape — but it is REPORTED on the returned row rather than read as
     * "no PSA record": is_active=false does not prove the human is gone (the
     * contact sync deactivates every mapped person outside the client's
     * cipp_sync_group_id filter, current employees included), so that target is
     * held-only and its person is named on the card and in the audit (see
     * CippWriteScopeResolver::assertNoPsaPersonMapping()).
     *
     * @return array{user_id: string, upn: string, display_name: string, mapped_inactive_person_id: int|null}
     */
    private function verifiedTenantUser(int $clientId, string $tenant, string $targetUpn): array
    {
        try {
            $rows = $this->client->listUsers($tenant);
        } catch (CippClientException) {
            throw new CippWriteScopeException('Could not verify the user against the tenant\'s live user listing; no licence change was made.');
        }

        if ($rows === []) {
            throw new CippWriteScopeException('The tenant\'s live user listing came back empty, which cannot be distinguished from an unread listing; no licence change was made. Retry, and check the CIPP relay if it persists.');
        }

        // Every field read below hedges BOTH casings. This is a RAW CIPP row
        // (CippRestWriteClient::listUsers()), not a CippToolContract projection,
        // so the contract's alias table never runs on it — and CIPP demonstrably
        // sends PascalCase for this object: CippContactSyncService::syncUser()
        // has hedged `accountEnabled`/`AccountEnabled` and `id`/`Id` since it was
        // written. Reading one casing here would turn a casing flip into "no such
        // user", which is a safe refusal wearing a wrong cause.
        $matches = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowUpn = trim((string) ($row['userPrincipalName'] ?? $row['UserPrincipalName'] ?? ''));
            if ($rowUpn !== '' && strcasecmp($rowUpn, $targetUpn) === 0) {
                $matches[] = $row;
            }
        }

        if ($matches === []) {
            throw new CippWriteScopeException('No user with that target_upn exists in this client tenant\'s live user listing; check the address and retry.');
        }
        if (count($matches) > 1) {
            throw new CippWriteScopeException('That target_upn matches more than one user in the tenant\'s live listing; resolve the duplicate upstream before assigning a licence.');
        }

        $row = $matches[0];
        $userId = trim((string) ($row['id'] ?? $row['Id'] ?? $row['objectId'] ?? $row['ObjectId'] ?? ''));
        if ($userId === '') {
            throw new CippWriteScopeException('The verified user has no object id in the CIPP user listing; refresh the CIPP user reads and retry.');
        }

        // THE UNMAPPED PREMISE, ENFORCED RATHER THAN ASSERTED. The tool text
        // and the staged approval card both say the target is NOT mapped to a
        // PSA person, and nothing checked it: naming a mapped person's ADDRESS
        // instead of their person_id skipped confirm_upn and every
        // person-scoped gate on an immediate billing write, and wrote an audit
        // row whose person linkage is null. A documented contract the code does
        // not enforce is not a contract — the same conclusion the mixed-shape
        // guard reached about the two shapes.
        //
        // Checked HERE because every path — direct, staging and the approval
        // replay — comes through this method, so the approval re-gate is the
        // same code rather than a second copy that can drift: a target that
        // becomes mapped between staging and approval declines. Matched on the
        // object id the SERVER read as well as the address, so a UPN rename
        // cannot walk around it.
        // The INACTIVE half of that answer comes BACK rather than being
        // discarded: an active mapping throws in there, and a mapped-but-
        // deactivated person rides on the returned row so the caller can hold the
        // write for a human and name them.
        $mappedInactivePersonId = $this->resolver->assertNoPsaPersonMapping($clientId, $targetUpn, $userId);

        // ACTIVE gate, psa-pgnj shape. A licence is a PAID SEAT: assigning one
        // to a disabled account spends money on somebody who has left. Because
        // every path — direct, staging, and the approval replay — comes through
        // here, the approval re-gate is the same code rather than a second copy
        // that can drift, which is what matters: an account disabled BETWEEN
        // staging and approval declines instead of executing.
        //
        // Absence refuses too, with a DISTINCT and self-diagnosing message. The
        // earlier draft here let an absent accountEnabled through, reasoning that
        // refusing on it would fail every call the moment CIPP stopped emitting
        // the field. That priced the wrong pair: fail-open buys availability and
        // pays with a QUIET wrong outcome — a paid seat on someone who left,
        // reported as a successful assignment and indistinguishable from one.
        // Refusing pays with a LOUD outage that names its own cause and is a
        // five-minute fix. Measured on a live tenant (12 of 12 accounts, the
        // three disabled ones included), the field is always projected, so the
        // absent branch is not currently reachable and the availability cost is
        // near zero today. Unable-to-assess is a refusal — and it survives the
        // "you'll break the feature" objection precisely because the refusal
        // says which field went missing.
        $hasEnabledKey = array_key_exists('accountEnabled', $row) || array_key_exists('AccountEnabled', $row);
        $enabled = $row['accountEnabled'] ?? $row['AccountEnabled'] ?? null;
        if ($enabled === false) {
            throw new CippWriteScopeException('That user\'s Microsoft 365 account is disabled in the tenant; a licence would spend a paid seat on a disabled account. Re-enable the account first, or assign the licence to the person who is actually using it.');
        }
        if (! is_bool($enabled)) {
            // Absent and present-but-unusable are different problems with
            // different fixes — allowlist/shape drift versus a per-user data
            // condition no retry clears — so the refusal says which it met
            // rather than making the reader guess.
            throw new CippWriteScopeException($hasEnabledKey
                ? 'The tenant listing carries accountEnabled for that user but not as a true/false value, so this tool cannot tell whether the account is enabled and will not assume it is. No licence was assigned.'
                : 'The tenant listing did not carry accountEnabled for that user, so this tool cannot tell whether the account is enabled and will not assume it is. No licence was assigned. If every assignment is failing this way, the CIPP user listing has stopped returning the field and that is the thing to fix.');
        }

        // Hedged HERE TOO, because the comment above is a contract over the
        // WHOLE method, not just its match loop. An enabled PascalCase row —
        // a casing the module's own evidence says CIPP really sends — reaches
        // this point already ACCEPTED: the row matched, the object id resolved
        // and the enabled gate passed. Reading only camelCase then makes this an
        // undefined-key read after the row was admitted: an ErrorException on
        // the direct path (after the upstream write), or, surviving that, an
        // empty UPN and display name on the result, the audit line and the
        // cockpit approval card — the only human gate on a billing write,
        // naming nobody.
        $rowUpn = $row['userPrincipalName'] ?? $row['UserPrincipalName'] ?? '';
        $rowDisplayName = $row['displayName'] ?? $row['DisplayName'] ?? null;

        return [
            'user_id' => $userId,
            // NEVER an active person (that threw above): this is the id of a
            // mapped but DEACTIVATED person, or null when the PSA has no complete
            // mapping for this target at all.
            'mapped_inactive_person_id' => $mappedInactivePersonId,
            'upn' => trim((string) $rowUpn),
            // UNTRUSTED EXTERNAL CONTENT: the tenant controls displayName, and
            // it flows into the cockpit approval card, the audit summary and the
            // stored payload. Control characters are stripped and the value is
            // length-bounded — the groupFactsFromRow() contract — so a profile
            // edit cannot forge reason/authorisation lines above the real ones
            // on the only human gate this family has, nor blow up the stored
            // proposal text with one row.
            'display_name' => mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($rowDisplayName) ? (string) $rowDisplayName : '')), 0, self::CREATE_DISPLAY_NAME_MAX),
        ];
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
            if ((self::STAGED_TO_DIRECT[$tool] ?? $tool) === 'cipp_assign_user_license') {
                // ACTIVE gate on the entitlement grant (#405): a freed M365
                // address stays on the departed person's DEACTIVATED row
                // (the stale sweep never clears cipp_upn/cipp_user_id), and
                // confirm_upn compares against that same stored column — so
                // the friction rail passes while the write lands on the old
                // occupant's object id. Requiring an active person here makes
                // a licence assignment to a reassigned address refuse instead.
                $person = $this->resolver->resolveActiveCippPerson($client->id, $person->person->id, 'user');
            }
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
            'cipp_remove_mailbox_rule' => $this->executeMailboxRuleRemoval($tenant, $person, $mailbox ?? []),
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

    /**
     * Execute an approved inbox-rule removal. The staged payload carries only
     * the typed rule_name (a safe local scalar) — no upstream rule Identity
     * exists at stage time, and none is ever accepted from a caller — so the
     * rule is resolved FRESH here against the mailbox's LIVE inbox-rule
     * listing: rows another mailbox provably owns are dropped, the stored name
     * must match exactly ONE remaining rule (case-insensitively, against the
     * raw upstream name OR the fenced form the reads actually show the agent),
     * the match's Identity prefix must not comparably name another mailbox,
     * and a SECOND live read of the same mailbox must re-show the matched
     * Identity under the approved name before it is sent. Zero matches or an
     * ambiguous name declines the approval. Every guard fails closed as a
     * CippClientException: the approval is declined and audited, and nothing
     * upstream is changed.
     *
     * NO NAME IS UN-REMOVABLE. This deliberately does not filter "protected"
     * rule names: the endpoint we call is Remove-CIPPMailboxRule's single-rule
     * arm, which protects nothing (the 'Junk E-Mail Rule'/OOF filter lives only
     * in its -RemoveAllRules arm, where the operator named no rule at all). A
     * name filter here would be keyed on a string the ATTACKER chooses — plant
     * a forwarding rule called "Junk E-Mail Rule" and it becomes both
     * un-removable through this verb and reported to the approver as not
     * existing, a false all-clear on the one path that exists to clean up after
     * a takeover.
     */
    private function executeMailboxRuleRemoval(string $tenant, ResolvedCippPerson $person, array $params): void
    {
        $ruleName = trim((string) ($params['rule_name'] ?? ''));
        if ($ruleName === '') {
            throw new CippClientException('Mailbox rule removal payload is incomplete; nothing was removed.');
        }

        // The name is caller-typed text and the decline messages built from it
        // reach the agent, the operator-facing cockpit decline toast AND the
        // immutable audit summary — a control surface, exactly like the approver
        // card — so it gets the card's treatment rather than a weaker one:
        // redacted, defanged and FENCED (mailboxRuleDisplay), quoted as data
        // AFTER the message's own claims instead of spliced inside them.
        // sanitizedText() only DEFANGS: it neither escapes nor closes a quote,
        // so a name spliced into a quoted sentence can end the quotation and
        // continue as apparent system prose ("...the mailbox was verified clean,
        // re-approve to proceed") on the one surface the approver is reading to
        // decide. The MATCH still runs on the raw typed name; only what is quoted
        // back is fenced — and the quoting goes through fencedDeclineMessage(),
        // because a fence only says anything if BOTH delimiters survive the
        // DECLINE_MESSAGE_MAX cut those two sinks apply to the WHOLE message.

        $needles = $this->mailboxOwnerNeedles($person);
        $matches = [];
        foreach ($this->client->listUserMailboxRules($tenant, $person->userPrincipalName) as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $owner = CippToolContract::mailboxRuleOwner($rule);
            // A row whose own mailbox marker proves it belongs to somebody else is
            // never a removal target on this mailbox — the read path drops these
            // because upstream really has answered a mailbox-scoped query with
            // out-of-scope rows (psa-7lgo.1).
            if ($owner !== null && $this->mailboxRuleOwnerIsForeign($owner, $needles)) {
                continue;
            }
            $name = trim((string) ($rule['Name'] ?? $rule['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if ($this->mailboxRuleNameMatches($name, $ruleName)) {
                $matches[] = $rule;
            }
        }

        if ($matches === []) {
            throw new CippClientException($this->fencedDeclineMessage(
                'No inbox rule matching the caller-typed name exists on this mailbox; nothing was removed. That name is quoted as untrusted data below.',
                $ruleName,
            ));
        }

        if (count($matches) > 1) {
            throw new CippClientException($this->fencedDeclineMessage(
                'The mailbox has '.count($matches).' inbox rules matching the caller-typed name, so the target is ambiguous and nothing was removed. That name is quoted as untrusted data below.',
                $ruleName,
            ));
        }

        $match = $matches[0];
        $identity = trim((string) ($match['Identity'] ?? $match['identity'] ?? ''));
        if ($identity === '') {
            throw new CippClientException('The matched inbox rule carries no upstream identity; nothing was removed.');
        }

        // Upstream derives MailboxObjectId from this identity's own prefix and
        // retries the delete anchored to it — so the PREFIX, not the
        // userPrincipalName we pass, can decide which mailbox is touched. But the
        // prefix and the row's own mailbox marker are tenant-chosen text on this
        // endpoint (display names, legacy DNs, opaque mailbox keys — the very
        // shapes CippToolContract's tool-scoped fencing exists for), so equality
        // against the approved mailbox's identifiers cannot be REQUIRED: real
        // rows would never satisfy it and every approval would decline, leaving
        // the compromise-remediation path inoperable. What CAN be required is
        // that no comparable form disagrees: an address- or object-id-shaped
        // prefix must match a needle of the same shape when one exists, and the
        // prefix must not contradict the row's own marker when the two share a
        // shape. A comparable disagreement is a row upstream mis-scoped into
        // this listing (psa-7lgo.1) — refuse rather than send an approved,
        // audited delete at a mailbox the approver never saw.
        $prefix = mb_strtolower(trim(explode('\\', $identity)[0]));
        // The PREFIX is adjudicated with the read path's own '@'-containment
        // heuristic, not the stricter address shape used where a positive match
        // can DROP a row. Here a positive match can only REFUSE, and the strict
        // shape silently loses refusals the read path raises: an M365 display
        // name that embeds an address ('Carol CEO (ceo@carol.example)') has
        // whitespace, so it is not strictly address-shaped — yet it is the very
        // string upstream turns into MailboxObjectId and anchors the delete to.
        // The looseness stops at COMPARABILITY: such a prefix is judged on the
        // address it CARRIES, never on the whole string — otherwise a tenant
        // using the ordinary 'Alex Kilo (alex@contoso.com)' convention for the
        // APPROVED mailbox has every removal on it refused, permanently, with an
        // audited claim that the rule names another mailbox, which is false.
        if ($this->mailboxRuleOwnerIsForeign($prefix, $needles, strictShape: false)) {
            throw new CippClientException('The matched inbox rule\'s upstream identity names another mailbox, so the removal would not land on the approved mailbox; nothing was removed.');
        }

        $owner = CippToolContract::mailboxRuleOwner($match);
        if ($owner !== null && $this->mailboxRuleMarkersDisagree($owner, $prefix)) {
            throw new CippClientException('The matched inbox rule\'s upstream identity contradicts the mailbox the rule reports as its own; nothing was removed.');
        }

        // Comparable agreement proves nothing for the display-name/legacy-DN/
        // opaque shapes above, and the listing that produced the match is
        // already stale by delete time. So re-read: this exact Identity must
        // still appear under the approved name in a fresh live read before the
        // delete is sent. A rule that vanished, was renamed, or dropped out of
        // this mailbox's scope in the window is refused rather than fired at.
        //
        // BE PRECISE ABOUT WHAT THIS BUYS, because the approver text is built
        // from it: this proves PERSISTENCE, not MEMBERSHIP. It is the same
        // UPN-keyed ListUserMailboxRules call as the first read, so an upstream
        // listing mis-scoped to another mailbox (psa-7lgo.1) is re-served
        // identically and confirms itself. Membership would need a read that
        // cannot be mis-scoped the same way — a per-rule fetch keyed on the
        // approved mailbox — and CIPP exposes NO such endpoint: measured
        // 2026-08-19 against CIPP-API, the only mailbox-rule reads are this
        // UserID-keyed live listing and the cache-backed tenant-wide
        // ListMailboxRules, which takes no user and answers a cold cache with a
        // queue marker (psa-4k6m). Requiring the cache-backed read would make
        // approvals decline whenever the cache is cold, i.e. exactly when a
        // takeover is being cleaned up. The honest resolution is therefore
        // route (b): the checks stay as strong as the endpoints allow, and
        // mailboxRuleDisplay()/both tool descriptions say what was NOT verified
        // instead of implying it was.
        $confirmed = false;
        foreach ($this->client->listUserMailboxRules($tenant, $person->userPrincipalName) as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            if (trim((string) ($rule['Identity'] ?? $rule['identity'] ?? '')) !== $identity) {
                continue;
            }

            // The persistence proof is held to the SAME ownership guards as the
            // first read, because it is the evidence the approver text leans on
            // hardest. A second read that POSITIVELY marks this row as another
            // mailbox's — or whose marker now contradicts the identity the
            // delete anchors to — is evidence AGAINST the removal; accepting it
            // as the proof would fire an approved, audited delete at a mailbox
            // we have affirmative evidence is not the approved one, on exactly
            // the upstream-drift class (psa-7lgo.1) the first read refuses.
            $confirmOwner = CippToolContract::mailboxRuleOwner($rule);
            if ($confirmOwner !== null && $this->mailboxRuleOwnerIsForeign($confirmOwner, $needles)) {
                throw new CippClientException('A second live read reports the matched inbox rule as belonging to a different mailbox; nothing was removed.');
            }
            if ($confirmOwner !== null && $this->mailboxRuleMarkersDisagree($confirmOwner, $prefix)) {
                throw new CippClientException('A second live read reports a mailbox for the matched inbox rule that contradicts the identity the removal would anchor to; nothing was removed.');
            }

            $name = trim((string) ($rule['Name'] ?? $rule['name'] ?? ''));
            if ($name !== '' && $this->mailboxRuleNameMatches($name, $ruleName)) {
                $confirmed = true;
                break;
            }
        }

        if (! $confirmed) {
            throw new CippClientException('A second live read of the mailbox no longer shows the matched inbox rule under the approved name, so it cannot be proven to still exist on the approved mailbox; nothing was removed.');
        }

        $this->client->removeMailboxRule($tenant, $person->userPrincipalName, $identity, trim((string) ($match['Name'] ?? $match['name'] ?? $ruleName)));
    }

    /**
     * Whether an upstream rule name is the one the approver signed off on.
     *
     * The per-mailbox read this tool's schema points callers at projects the
     * rule NAME as untrusted free text (CippToolContract::isFreeTextField), so
     * what an agent can copy back is the FENCED form — NFKC-folded, role
     * markers defanged, "ignore previous instructions" rewritten. Matching the
     * raw upstream name alone would break that read->write round trip on
     * precisely the attacker-authored names this verb exists to remove, and
     * decline with a false "no such rule" (the psa-4k6m.8 failure class, which
     * already broke quarantine release once). Both forms are accepted; if two
     * different rules collide on one of them the unique-match gate declines as
     * ambiguous rather than picking one.
     */
    private function mailboxRuleNameMatches(string $upstreamName, string $approvedName): bool
    {
        // The fold is mb_strtolower, NOT strcasecmp: strcasecmp folds ASCII A-Z
        // only, so a rule named 'Überwachung Weiterleitung' typed back as
        // 'überwachung weiterleitung' misses on BOTH branches (the fenced form is
        // NFKC-folded but not case-folded), and the approval declines with "no inbox
        // rule matching the caller-typed name exists on this mailbox" while the rule
        // is live — the false all-clear polarity this module forbids, on input the
        // schema's "matched case-insensitively" contract explicitly admits. Every
        // other comparison on this path already folds with mb_strtolower; this one
        // must not be the exception.
        $approved = mb_strtolower($approvedName);

        if (mb_strtolower($upstreamName) === $approved) {
            return true;
        }

        return mb_strtolower(trim($this->textSanitizer->sanitizedText($upstreamName, self::FENCED_FIELD_MAX))) === $approved;
    }

    /**
     * A decline message that quotes the caller-typed rule name as fenced data and
     * STILL FITS the bound both of its sinks cut at.
     *
     * The fence's property is structural: the CLOSING delimiter is what tells a
     * reader where the untrusted quotation ended. declined() and
     * safeFailureSummary() truncate the WHOLE message at DECLINE_MESSAGE_MAX, so
     * a message merely assembled and handed over loses that delimiter for any
     * name past a few dozen characters — and the approver-facing toast and the
     * immutable audit row are left holding an OPEN fence whose tail is text the
     * caller chose. neutralize() collapses runs of three or more '=' but passes a
     * two-'=' near-miss terminator through verbatim, and with the genuine
     * terminator cut away there is nothing left to contrast it with, so that tail
     * reads as one of this system's own statements on the one surface the
     * approver decides from. RULE_NAME_INPUT_MAX is 1000 and boundedString()
     * filters no line breaks, so such a name is well inside what a caller may
     * type.
     *
     * So the message is BUILT to the bound rather than cut to it: the prose and
     * both delimiters always survive, and only the untrusted span is shortened
     * (marked with an ellipsis). The fit is measured on the REDACTED message,
     * because redaction is what the sinks apply first and a placeholder can be
     * longer than what it replaced.
     */
    private function fencedDeclineMessage(string $prose, string $ruleName): string
    {
        $name = $this->redactor->redactString($ruleName);
        $budget = mb_strlen($name);

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $quoted = $budget >= mb_strlen($name) ? $name : mb_substr($name, 0, $budget).'…';
            $message = $prose."\n".$this->textSanitizer->sanitize('CALLER TYPED RULE NAME', $quoted, self::RULE_NAME_INPUT_MAX);
            $overflow = mb_strlen($this->redactor->redactString($message)) - self::DECLINE_MESSAGE_MAX;

            if ($overflow <= 0) {
                return $message;
            }

            if ($budget === 0) {
                break;
            }

            // $overflow is measured in OUTPUT space while $budget counts INPUT
            // characters, and neutralize() collapses '='-runs — so trimming inside
            // a collapsed run can remove zero output characters and the loop would
            // stall. Halving the budget as a floor makes every pass shrink it
            // geometrically: 2^12 > RULE_NAME_INPUT_MAX, so 12 attempts always
            // reach a fitting quotation and the bare-prose fallback below is
            // unreachable for in-contract input.
            $budget = max(0, min($budget - $overflow, intdiv($budget, 2)));
        }

        // Even an empty quotation does not fit: quote nothing rather than emit a
        // fence the bound would cut in half.
        return $prose;
    }

    /**
     * The approved mailbox's own identifiers, lowercased, for adjudicating whose
     * mailbox a rule is on.
     *
     * ADDRESSES ONLY, and both of them: CIPP hands the mailbox back as a UPN or as
     * the mailbox's primary SMTP address, and those need not be the same string (an
     * onmicrosoft UPN, or a rename that left the UPN behind). Holding only the UPN
     * would adjudicate the user's OWN rows as another mailbox's and decline with
     * "no inbox rule named X exists on this mailbox" while the rule is still live —
     * a false all-clear on the one path that cleans up after a takeover.
     *
     * The person's AAD object id is deliberately NOT a needle, and that omission is
     * a correctness constraint rather than an oversight. A rule's mailbox marker
     * and its Identity prefix are EXCHANGE identifiers: where either is a GUID it
     * is the mailbox's ExchangeGuid, a DIFFERENT namespace from the Entra user
     * objectId, and the same mailbox legitimately carries one of each. Two unequal
     * GUIDs drawn from those two namespaces prove nothing, so admitting the object
     * id would adjudicate every row on the approved mailbox as foreign on any
     * tenant whose Identity prefix is a GUID (the ordinary Exchange shape): rows
     * dropped on the first read and reported to the approver as a rule that does
     * not exist, or the approval refused with an immutable audit row claiming the
     * rule names another mailbox — tenant-wide, on the remediation path. Hence
     * mailboxRuleOwnerIsForeign() treats no GUID marker as comparable to a needle
     * at all, and this list is what keeps that true.
     *
     * @return array<int, string>
     */
    private function mailboxOwnerNeedles(ResolvedCippPerson $person): array
    {
        $needles = array_map(
            fn (string $value): string => mb_strtolower(trim($value)),
            [$person->userPrincipalName, (string) $person->person->email],
        );

        return array_values(array_unique(array_filter($needles, fn (string $needle): bool => $needle !== '')));
    }

    /**
     * Whether a rule's mailbox marker PROVES it is another mailbox's. Compare
     * like with like (CippToolContract::mailboxRuleIsForeign): an address is
     * adjudicated only by an address, and NOTHING ELSE adjudicates against this
     * mailbox at all — an alias, a display name, or a GUID proves nothing either
     * way, because the GUID a marker or Identity prefix carries is Exchange's
     * mailbox key and not the Entra objectId (see mailboxOwnerNeedles, which
     * therefore carries no object-id needle), and guessing would drop the
     * target user's own rules. Those unprovable rows survive this filter; the
     * one row actually removed is then held to a persistence re-read (a second
     * live read of the approved mailbox must re-show its exact Identity under
     * the approved name) plus the comparable Identity-prefix cross-checks.
     * Neither is a MEMBERSHIP proof and no CIPP endpoint can supply one — see
     * the re-read comment in executeMailboxRuleRemoval. The approver text names
     * that gap rather than papering over it.
     *
     * $strictShape picks WHICH address heuristic decides comparability, and the
     * choice is a polarity decision, not a style one: strict where a positive
     * match can DROP a row (a dropped row is reported to the approver as a rule
     * that does not exist), loose where it can only REFUSE. See
     * mailboxRuleLooksLikeAddress().
     *
     * @param  array<int, string>  $needles
     */
    private function mailboxRuleOwnerIsForeign(string $owner, array $needles, bool $strictShape = true): bool
    {
        $ownerIsAddress = $strictShape
            ? self::mailboxRuleLooksLikeAddress($owner)
            : self::mailboxRuleMayBeAddress($owner);

        // A marker that is not address-shaped is comparable to NOTHING here. It may
        // well be a GUID, but the GUID Exchange stamps into a mailbox marker or an
        // Identity prefix is the mailbox's ExchangeGuid, while the only GUID this
        // mailbox could offer as a needle would be the Entra user objectId — two
        // namespaces, two different GUIDs for the very same mailbox, so inequality
        // between them proves nothing. Adjudicating across them would make every row
        // on the approved mailbox foreign wherever the prefix is a GUID: dropped on
        // the first read (surfacing to the approver as "no such rule exists" while
        // the rule forwards mail) or refused with a false audited claim, tenant-wide,
        // on the compromise-remediation path. A GUID pair drawn from the SAME
        // upstream row is still adjudicated — by mailboxRuleMarkersDisagree(), where
        // both sides are Exchange's own and the vocabulary genuinely matches.
        if (! $ownerIsAddress) {
            return false;
        }

        $comparable = array_values(array_filter(
            $needles,
            fn (string $needle): bool => $strictShape
                ? self::mailboxRuleLooksLikeAddress($needle)
                : self::mailboxRuleMayBeAddress($needle),
        ));

        if ($comparable === []) {
            return false;
        }

        // WHAT the marker claims, in the vocabulary the needles are written in.
        // A marker that IS an address, or an object id, claims itself and this is
        // plain equality. Under the LOOSE shape a marker qualifies by merely
        // CONTAINING an '@' — and such a marker is typically a display name that
        // EMBEDS an address ('alex kilo (alex@contoso.com)', the ordinary M365
        // convention). Equality on that whole string can never hold, so comparable
        // -and-unequal would be the verdict for EVERY row on the approved mailbox:
        // the removal refused permanently, and the immutable log carrying a
        // 'names another mailbox' claim about a rule that is on this one. So the
        // claim is the address(es) the marker CARRIES; a marker carrying none
        // ('support @ acme') claims nothing, and something that proves nothing
        // either way must not refuse.
        $claims = $ownerIsAddress && ! $strictShape && ! self::mailboxRuleLooksLikeAddress($owner)
            ? self::mailboxRuleEmbeddedAddresses($owner)
            : [$owner];

        foreach ($claims as $claim) {
            if (in_array($claim, $comparable, true)) {
                return false;
            }
        }

        return $claims !== [];
    }

    /**
     * Whether a mailbox marker is ADDRESS-shaped strongly enough to be
     * adjudicated against this mailbox's addresses.
     *
     * Containing an '@' is NOT that test. Markers on this endpoint are routinely
     * free-form, tenant-chosen display names ('CEO Office', 'Alex Kilo'), and a
     * tenant may perfectly well name a mailbox "Support @ Acme". Under an
     * '@'-containment rule that display name becomes comparable to the approved
     * mailbox's addresses, matches none, and adjudicates EVERY row on the
     * mailbox as somebody else's: zero matches, and the approval declines with
     * "no inbox rule named X exists on this mailbox" while the rule is live —
     * the false all-clear polarity this module forbids, on valid input. The same
     * shape fed to mailboxRuleMarkersDisagree() can refuse a correctly matched
     * row outright.
     *
     * So require the shape an address actually has — one '@', no whitespace, a
     * dotted domain — and let every other shape stay UNCOMPARABLE, which proves
     * nothing either way and therefore drops nothing.
     *
     * WHICH TEST APPLIES IS DECIDED BY POLARITY, because "uncomparable" is NOT
     * uniformly the safe direction. Where a positive shape match can DROP a row
     * — the first-read filter, and the owner-MARKER checks, whose subject is
     * this same free-form display-name field — strict is safe: a dropped row is
     * reported to the approver as a rule that does not exist, the false
     * all-clear this module forbids. Where a positive match can only REFUSE —
     * the Identity PREFIX the delete is anchored to — strict silently REMOVES a
     * refusal the read path's '@'-containment heuristic
     * (CippToolContract::mailboxRuleIsForeign) would have raised, e.g. a
     * display name that embeds an address, 'Carol CEO (ceo@carol.example)',
     * naming a mailbox the approver never saw. Those sites therefore keep the
     * looser heuristic, via mailboxRuleMayBeAddress(). An earlier version of
     * this docblock justified the divergence by saying a kept row "still has to
     * survive the identity-prefix cross-checks": that was circular, because
     * those cross-checks were themselves loosened by this very test.
     *
     * The residual is stated, not hidden: an owner MARKER that embeds an
     * address stays uncomparable on both reads, because the identical shape is
     * a legitimate mailbox display name ("Support @ Acme") and refusing on it
     * would decline valid approvals on the remediation path. The approver card
     * already discloses that marker checks settle nothing here.
     */
    private static function mailboxRuleLooksLikeAddress(string $value): bool
    {
        return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $value) === 1;
    }

    /**
     * The read path's own '@'-containment heuristic
     * (CippToolContract::mailboxRuleIsForeign), kept for the sites where a
     * positive shape match can only REFUSE and can never drop a row: the
     * Identity-prefix cross-check, and the PREFIX side of the marker/prefix
     * contradiction check. Upstream derives MailboxObjectId from that prefix and
     * anchors the delete to it, so a prefix that textually names another
     * mailbox's address is exactly the psa-7lgo.1 drift this verb must refuse —
     * and refusing costs an honest decline, never a false all-clear.
     */
    private static function mailboxRuleMayBeAddress(string $value): bool
    {
        return str_contains($value, '@');
    }

    /**
     * The address-shaped tokens a loosely-comparable marker CARRIES, lowercased
     * and de-duplicated.
     *
     * mailboxRuleMayBeAddress() admits any string containing an '@', which is how
     * a display name that embeds a mailbox address stays adjudicable at all — but
     * that string as a whole is NOT an address, so it can only be compared
     * through the addresses inside it. Anything outside the address grammar
     * (whitespace, the wrapping punctuation of 'Name (addr@domain)') is excluded
     * from a token, so 'alex kilo (alex@contoso.com)' yields the mailbox's own
     * address and agrees, 'carol ceo (ceo@carol.example)' yields somebody else's
     * and refuses, and 'support @ acme' yields nothing and settles nothing.
     *
     * @return array<int, string>
     */
    private static function mailboxRuleEmbeddedAddresses(string $value): array
    {
        if (preg_match_all('/[^\s@<>()\[\],;:"\']+@[^\s@<>()\[\],;:"\']+\.[^\s@<>()\[\],;:"\']+/u', $value, $matches) === false) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $address): string => rtrim(mb_strtolower($address), '.'),
            $matches[0],
        )));
    }

    /**
     * Whether a matched rule's own mailbox marker and its Identity prefix are
     * MUTUALLY comparable shapes that disagree. Upstream anchors the delete to
     * the prefix while the marker is what the row claims as its mailbox — when
     * the marker is address-shaped and the prefix carries an address, or both
     * are object ids, they describe the same thing in the same vocabulary and
     * must agree. The object-id pair is comparable HERE and deliberately not
     * against the approved mailbox's needles: both sides come from the SAME
     * upstream row, so both are Exchange identifiers for whatever mailbox that
     * row names, whereas a needle GUID would be the Entra user objectId — a
     * different namespace from the mailbox's ExchangeGuid, where inequality
     * proves nothing and refusing on it would brick the verb (see
     * mailboxOwnerNeedles). The two sides use DIFFERENT address tests on purpose (see
     * mailboxRuleLooksLikeAddress): the marker is the free-form display-name
     * field and must be strictly address-shaped before it adjudicates anything,
     * while the prefix — the string the delete is anchored to — need only look
     * like it carries an address, because the outcome here is a refusal and
     * never a dropped row. Mixed or opaque shapes (a display
     * name against a mailbox key, a legacy DN against anything) prove nothing
     * either way and must not refuse: for those nothing downstream can settle
     * the question either, which is why the approver text says so. Both inputs arrive lowercased
     * (mailboxRuleOwner / the prefix derivation), so comparison is direct.
     */
    private function mailboxRuleMarkersDisagree(string $owner, string $prefix): bool
    {
        if (self::mailboxRuleLooksLikeAddress($owner) && self::mailboxRuleMayBeAddress($prefix)) {
            // Same asymmetry as mailboxRuleOwnerIsForeign: the prefix qualifies by
            // '@'-containment, so what it CLAIMS is the address(es) it carries. A
            // display name that embeds the marker's own address AGREES with it,
            // and one carrying no address at all settles nothing — reading either
            // as a contradiction would refuse correctly matched rows forever.
            $claims = self::mailboxRuleLooksLikeAddress($prefix)
                ? [$prefix]
                : self::mailboxRuleEmbeddedAddresses($prefix);

            return $claims !== [] && ! in_array($owner, $claims, true);
        }

        if (CippToolContract::looksLikeObjectId($owner) && CippToolContract::looksLikeObjectId($prefix)) {
            return $owner !== $prefix;
        }

        return false;
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
            'cipp_remove_mailbox_rule' => $this->mailboxRuleParams($arguments, $isHeld),
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
     * Resolve inbox-rule removal params on the initial call and the held
     * approval replay. STRUCTURALLY HELD-ONLY (directory-role precedent):
     * deleting a mailbox rule is never directly executable, whatever mode the
     * token was granted — the non-held path throws before any state is touched,
     * so the upstream call can only ever be reached through a cockpit approval.
     * The rule is identified by its NAME alone: the per-mailbox listing
     * projection (CippToolContract::DEFAULT_FIELDS['cipp_list_mailbox_rules'])
     * exposes only names, and caller-supplied upstream identifiers
     * (ruleId/Identity) are banned — so execution resolves the rule's upstream
     * Identity from a LIVE listing at approval and refuses zero or multiple
     * matches. The name is a safe local scalar and is deliberately NOT run
     * through a safeReason-style redaction: unlike an external SMTP address or
     * an OOO body it is stored in redacted_params by design, because the
     * approver must review the exact rule that will be deleted.
     *
     * @return array<string, mixed>
     */
    private function mailboxRuleParams(array $arguments, bool $isHeld): array
    {
        if (! $isHeld) {
            throw new CippWriteScopeException('Mailbox rule removal is held-only; call cipp_remove_mailbox_rule with staged=true and a ticket_id for cockpit approval.');
        }

        return [
            'rule_name' => $this->boundedString($arguments, 'rule_name', self::RULE_NAME_INPUT_MAX, required: true),
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

    /**
     * The blocklisted keys the caller actually SENT.
     *
     * VALUE-KEYED, through sentValue(), for the reason the dispatch and the
     * mixed-shape guard already are: cipp_assign_user_license publishes the
     * MERGED property set of both target shapes, and BOTH tenant-shape keys
     * (target_upn, sku_id) are members of UPSTREAM_IDENTIFIER_KEYS. Keying on
     * array_key_exists() therefore refused the person-keyed path outright for
     * any client that fills the published template — it routed correctly to the
     * person path and was then refused here, which is the exact unreachability
     * the family exists to fix, reappearing one gate later.
     *
     * An empty template slot is not a supplied identifier. Any REAL value still
     * refuses, on every tool, which is the property this list exists for — and
     * safeHashParams() still strips these keys on presence, so nothing about the
     * hashing contract moves.
     *
     * @return array<int, string>
     */
    /**
     * THE GLOBAL BLOCKLIST, AND IT PRESENCE-TESTS ON PURPOSE.
     *
     * An earlier rework changed this to sentValue() so the licence family would
     * stop refusing empty template slots — a LOCAL problem fixed by widening a
     * GLOBAL guard, which is the scope error this branch has now made in three
     * different shapes. Every other write tool publishes a narrow schema and has
     * no reason to send a blocklisted key at all, so for them the mere PRESENCE
     * of one is the signal: a caller reaching for tenantFilter or userId is
     * driving upstream identity whatever value it carries, and the tripwire
     * should fire before anyone asks whether the value was empty.
     *
     * The licence family, which alone publishes a merged two-shape schema, gets
     * the value-testing variant below — scoped to itself, by name.
     */
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
     * A content hash for a re-stage that cannot land on a run this ticket has
     * already SPENT. Used ONLY by RECREATABLE_TARGET_STAGED_TOOLS — the verbs whose
     * executed-content rail stageAction() skips.
     *
     * Every other staged verb is protected by that rail: identical content that
     * already executed short-circuits before firstOrCreate is ever reached, so the
     * only non-live run its key can return is one that never executed (superseded,
     * denied, withdrawn) and reviving THAT row in place is correct. With the rail
     * skipped the protection is gone, and firstOrCreate on the UNIQUE (ticket_id,
     * action_type, content_hash) key hands back the very run that removed a rule
     * under this name — a terminal Done row the revive branch would flip back to
     * AwaitingApproval and overwrite. TechnicianRun::update() carries no transition
     * guard, so the cockpit record of a completed destructive removal would simply
     * be gone, on exactly the re-planted-rule re-stage this verb exists to support.
     *
     * A spent key is therefore walked forward DETERMINISTICALLY instead: the same
     * re-stage always derives the same hash, so liveAwaitingRun()'s idempotency
     * still collapses a repeat of a pending proposal, while every spent run keeps
     * its row. The walk is bounded and refuses honestly when exhausted rather than
     * recycling a spent run.
     */
    private function unspentContentHash(string $tool, int $ticketId, string $contentHash): ?string
    {
        // Live (the idempotent re-send) or retired without ever executing — the two
        // cases the firstOrCreate branches are written for. Anything else has spent
        // the run: it executed, is executing, or is parked to execute.
        $revivable = [
            TechnicianRunState::AwaitingApproval->value,
            TechnicianRunState::Superseded->value,
            TechnicianRunState::Denied->value,
            TechnicianRunState::Withdrawn->value,
        ];

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $spent = TechnicianRun::query()
                ->where('ticket_id', $ticketId)
                ->where('action_type', $tool)
                ->where('content_hash', $contentHash)
                ->whereNotIn('state', $revivable)
                ->exists();

            if (! $spent) {
                return $contentHash;
            }

            $contentHash = hash('sha256', $contentHash.'|re-stage');
        }

        return null;
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
            // PREFIX-anchored, not '%...%' (security review psa-6dnfd R3). targetKey()
            // returns "person #<id>" with no delimiter, and auditSummary() prefixes the
            // summary with "<targetKey>: ". An unanchored LIKE therefore matches across
            // people — "person #1" matches "person #10:" — and also matches a target key
            // that merely appears inside another entry's free-text reason. Both would
            // falsely block a reset for the wrong user. Anchoring to the real prefix
            // "person #<id>:" makes the match exact and index-friendly.
            ->where('summary', 'like', $this->targetKey($person, null).':%')
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

        // A CORRUPT CIPHERTEXT MUST RETURN NULL, NOT ESCAPE. Crypt::decryptString()
        // throws DecryptException on a tampered or key-rotated payload, and that is
        // not a CippWriteScopeException — so it fell straight past every audited
        // refusal in approveStagedRun() to the outer \Throwable arm, which releases
        // the claim and rethrows: no audit row, and a framework-level error instead
        // of the operator-readable "deny this proposal and re-stage it" that the
        // null branch below exists to produce. The one refusal that most wants a
        // row was the one that could not leave one.
        //
        // I got this wrong in the r5 driver notes: I wrote that the null branch was
        // "unreachable that way" and treated it as merely unpinnable. It was not
        // unpinnable, it was UNHANDLED. Chet read it correctly.
        try {
            $json = Crypt::decryptString($ciphertext);
        } catch (DecryptException) {
            return null;
        }

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
            'rule_name',
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
            'cipp_remove_mailbox_rule' => $this->mailboxRuleDisplay($person, $mailbox ?? []),
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

    private function mailboxRuleDisplay(ResolvedCippPerson $person, array $params): string
    {
        // A rule removal is only reviewable if the approver can see WHOSE
        // mailbox loses WHICH rule: name the target by UPN (a same-client
        // internal address, not a secret) plus the PSA id, and the rule by its
        // typed name. Only the display carries the UPN — the stored payload and
        // audit summary stay id-only.
        //
        // And it is only reviewable if this text is TRUE. For a held-only
        // destructive delete the approver text IS the control, so it states the
        // limit of the checks as plainly as it states the checks: an earlier
        // draft said the matched rule's "own mailbox marker names this mailbox",
        // which the code does not and cannot establish (see
        // executeMailboxRuleRemoval). An approver who reads a check that did not
        // run is worse off than one who reads none.
        // The rule NAME is the one span of caller-typed — i.e. prompt-injectable
        // — text on this card, and this card IS the control. Spliced raw into
        // the sentence, up to RULE_NAME_INPUT_MAX characters of it could forge
        // their own "VERIFIED:" clause above the real NOT VERIFIED disclosure,
        // and the approver would read a membership proof that never ran. So it
        // is not spliced into the sentence at all: it is redacted, defanged and
        // FENCED — the treatment every other untrusted string on this surface
        // gets — and quoted AFTER the claims, where nothing inside it can read
        // as one of them.
        $fencedRuleName = $this->textSanitizer->sanitize(
            'CALLER TYPED RULE NAME',
            (string) ($params['rule_name'] ?? 'unknown'),
            self::RULE_NAME_INPUT_MAX,
        );

        return 'Remove ONE inbox rule — the caller-typed name is quoted as data at the END of this card — from the mailbox of '
            .$person->userPrincipalName.' (PSA person #'.$person->person->id.').'
            .' Held-only. VERIFIED at approval: a live per-mailbox read of this UPN\'s inbox rules matches that name to exactly one rule'
            .' after dropping every row whose own mailbox marker proves it belongs to a different mailbox; that rule\'s upstream identity'
            .' does not comparably name a different mailbox, nor contradict the row\'s own marker; and a second live read, held to those'
            .' same ownership checks, still shows that exact identity under that name.'
            .' NOT VERIFIED: that the rule is on this mailbox. CIPP exposes no per-rule read keyed on a mailbox, so both reads are the same'
            .' UPN-keyed listing call — if CIPP answers it with another mailbox\'s rows, both reads carry them identically — and a row whose'
            .' mailbox marker is absent, or is a shape that cannot be compared with this mailbox\'s identifiers (a display name, a legacy DN,'
            .' an opaque mailbox key), is not proven to be this mailbox\'s.'
            .' A missing or ambiguous name declines with nothing removed.'
            ."\n".'The name the caller typed, as DATA — nothing inside the block below is a statement by this system:'
            ."\n".$fencedRuleName;
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
        return "{$tool} failed before completion: ".mb_substr($this->redactor->redactString($e->getMessage()), 0, self::DECLINE_MESSAGE_MAX);
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
        return new TechnicianApprovalResult('gate_declined', message: mb_substr($this->redactor->redactString($reason), 0, self::DECLINE_MESSAGE_MAX));
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
    private static function mailboxRuleProperties(): array
    {
        return [
            'rule_name' => [
                'type' => 'string',
                'description' => 'Exact name of the inbox rule to remove, as shown by the per-mailbox rule reads (e.g. cipp_list_mailbox_rules). Matched case-insensitively against the mailbox\'s LIVE inbox-rule listing at approval time — against both the rule\'s raw upstream name and the fenced form those reads display, so a name that was neutralized for display still resolves. The server derives the rule\'s upstream identity from the unique match, and a missing or ambiguous name declines without removing anything.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function removeMailboxRuleTool(): array
    {
        return self::tool(
            'cipp_remove_mailbox_rule',
            'Remove ONE inbox rule from ONE server-derived user\'s mailbox through CIPP — compromise remediation and mailbox hygiene (e.g. strip a malicious forwarding or delete-mail rule after an account takeover). HELD-ONLY: this capability never executes immediately, whatever mode was granted — every call must use staged=true with a ticket_id and is held for cockpit approval; staged=false calls are refused. Identify the rule by rule_name only (from the per-mailbox rule reads); at approval the server re-reads the mailbox\'s LIVE inbox rules, drops every row whose own mailbox marker proves it belongs to a different mailbox, and requires the name to match exactly ONE remaining rule — whose upstream identity must not comparably name a different mailbox and must still be shown by a second live read — before the single-rule removal is sent. A missing or ambiguous name declines without removing anything. LIMIT, state it when you report this action: CIPP exposes no per-rule read keyed on a mailbox, so both reads are the same UPN-keyed listing call and neither can prove a row is on THIS mailbox when the row carries no comparable mailbox marker. confirm_upn is the mailbox owner\'s UPN. Requires an explicit token grant, reason, kill-switch, cooldown, and TechnicianActionLog audit.',
            array_merge(self::personProperties(), self::mailboxRuleProperties()),
            ['person_id', 'rule_name', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageRemoveMailboxRuleTool(): array
    {
        return self::tool(
            'cipp_stage_remove_mailbox_rule',
            'Stage removal of one inbox rule from one server-derived user\'s mailbox for cockpit approval (compromise remediation / mailbox hygiene). The MCP call makes no CIPP upstream call; the held payload stores only local identifiers plus the typed rule_name, and approval re-reads the mailbox\'s LIVE inbox rules, drops every row whose own mailbox marker proves it belongs to a different mailbox, and executes the single-rule removal only when the name matches exactly ONE remaining rule whose upstream identity does not comparably name a different mailbox and is still shown by a second live read — a missing or ambiguous name declines with nothing removed. LIMIT, state it when you report this action: CIPP exposes no per-rule read keyed on a mailbox, so both reads are the same UPN-keyed listing call and neither can prove a row is on THIS mailbox when the row carries no comparable mailbox marker. This capability is held-only — there is no immediate execution path. confirm_upn is the mailbox owner\'s UPN (person_id).',
            array_merge(self::personProperties(ticket: true), self::mailboxRuleProperties()),
            ['person_id', 'rule_name', 'ticket_id', 'confirm_upn', 'reason'],
        );
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
            'Assign one local CIPP M365 license SKU to one server-derived user immediately. This can alter billing and app entitlements. Requires explicit grant, reason, confirm_upn, kill-switch, dedup/cooldown, and audit. The target must be an ACTIVE PSA person; for a tenant user with no PSA person record use cipp_assign_tenant_user_license (its own grant). Dial note: human-smoke-verify before first live grant; no replace-all or remove-all license body is supported.',
            array_merge(self::personProperties(), self::licenseProperties()),
            ['person_id', 'license_type_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageAssignLicenseTool(): array
    {
        return self::tool(
            'cipp_stage_assign_user_license',
            'Stage assignment of one local CIPP M365 license SKU for cockpit approval. This can alter billing and entitlements; the held payload is encrypted at rest and approval revalidates person, tenant, and SKU mappings. The target must be an ACTIVE PSA person; for a tenant user with no PSA person record use cipp_stage_assign_tenant_user_license (its own grant).',
            array_merge(self::personProperties(ticket: true), self::licenseProperties()),
            ['person_id', 'license_type_id', 'ticket_id', 'confirm_upn', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function assignTenantLicenseTool(): array
    {
        return self::tool(
            'cipp_assign_tenant_user_license',
            'Assign one CIPP M365 license SKU to one tenant user with NO ACTIVE PSA person record, immediately. This can alter billing and app entitlements. The server verifies target_upn against the resolved client tenant\'s live user listing and derives the object id from it — an address that is absent, ambiguous, in another tenant, on a disabled account, or mapped to an ACTIVE PSA person is refused (that person belongs on cipp_assign_user_license with its typed confirmation; a mapped but deactivated person is served here, but HELD-ONLY: a target the PSA holds a person record for never executes immediately, whatever mode was granted — re-call with staged=true and a ticket_id) — and matches sku_id against this client\'s synced licence rows. Requires an explicit token grant, reason, kill-switch, a per-target cooldown, and TechnicianActionLog audit. A licence can legitimately be removed and re-assigned, so a repeat grant is sent upstream rather than answered as an already-executed duplicate. Dial note: human-smoke-verify before first live grant; no replace-all or remove-all license body is supported.',
            array_merge(self::licenseTargetProperties(), self::licenseTargetCommonProperties(ticketRequired: false)),
            ['target_upn', 'sku_id', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function stageAssignTenantLicenseTool(): array
    {
        return self::tool(
            'cipp_stage_assign_tenant_user_license',
            'Stage assignment of one CIPP M365 license SKU to one tenant user with NO ACTIVE PSA person record, for cockpit approval. This can alter billing and entitlements; the held payload is encrypted at rest, and approval re-verifies target_upn against the tenant\'s live user listing fresh — declining if the address now points at a different user object, a disabled account, or a person who is mapped AND active in the PSA — before anything is written. Requires ticket_id and reason.',
            array_merge(self::licenseTargetProperties(), self::licenseTargetCommonProperties(ticketRequired: true)),
            ['target_upn', 'sku_id', 'ticket_id', 'reason'],
        );
    }

    /** @return array<string, mixed> */
    private static function licenseTargetCommonProperties(bool $ticketRequired): array
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

    /**
     * Tenant-scoped target properties for the licence assignment. Deliberately
     * NOT named userPrincipalName / Username / cipp_upn / skuId: those are in
     * UPSTREAM_IDENTIFIER_KEYS and are refused outright on every tool, and a
     * caller reaching for one should keep hitting that wall.
     *
     * @return array<string, mixed>
     */
    private static function licenseTargetProperties(): array
    {
        return [
            'target_upn' => [
                'type' => 'string',
                'description' => 'Full Microsoft 365 user principal name of the target, for a tenant user with no PSA person record (e.g. person@contoso.com). The server verifies this address against the tenant\'s live user listing and derives the object id from it; an address that is absent, ambiguous, or in another tenant is refused. Use cipp_assign_user_license with person_id instead when the user is mapped to a PSA person — an address (or object id) that IS mapped to a PSA person is refused here, because that target belongs on the person-keyed tool with its typed confirmation.',
            ],
            'sku_id' => [
                'type' => 'string',
                'description' => 'Upstream Microsoft 365 SKU id as returned by the CIPP licence reads (e.g. cipp_list_licenses). Accepted on this tool only. The server matches it against this client\'s synced licence rows and refuses a SKU the client has no active local licence row for; use license_type_id with person_id on cipp_assign_user_license for the PSA-person shape.',
            ],
        ];
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
