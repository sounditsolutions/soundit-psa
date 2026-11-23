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
use App\Services\Huntress\HuntressClient;
use App\Services\Huntress\HuntressClientException;
use App\Services\Huntress\HuntressEscalationAlreadyResolvedException;
use App\Services\Huntress\HuntressWriteClient;
use App\Services\Huntress\HuntressWriteScopeException;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Services\Technician\PromptFence;
use App\Services\Technician\TechnicianApprovalResult;
use App\Support\HuntressConfig;
use App\Support\TechnicianConfig;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * huntress_request: one tool for any endpoint in the Huntress API spec
 * (method, path, query, body). Dispatched through StaffHuntressActionToolExecutor,
 * so the huntress_action family's explicit-grant gate and client_id requirement
 * apply, and the grant lives on the token (McpToolModes) like the other tools.
 *
 * LANES. GET runs at once through the read client (HuntressClient), on either
 * lane. POST, PATCH and DELETE are ALWAYS held for cockpit approval, whatever
 * the grant's mode: there is no code path that sends a write from the MCP call.
 * Approval replays the stored request through HuntressWriteClient::request().
 *
 * PATH. Must match one method+path template of ENDPOINTS, which is the vendor
 * spec (api.huntress.io/v1/swagger_doc.json) reduced to a fixed list at build
 * time. Path parameters are positive integers. A full URL, a scheme or host,
 * percent-encoding, "." or ".." segments, an empty segment and any other
 * character are refused. POST /v1/siem/query is not reachable: SIEM queries go
 * through huntress_siem_query, which pins the organization filter. Query keys
 * and body keys must be the endpoint's own (from the same spec); caller
 * headers are refused.
 *
 * ORG FENCE (client_id is required). An organization id in the path, the query
 * or the body must be the client's mapped Huntress organization. An account id
 * in the path, or account_id in the body, must be the account the API key
 * belongs to (read with GET /v1/account). READ FIRST: exactly these are read
 * before anything is staged or returned, and each must belong to the mapped
 * organization or to none: an unwanted access rule, agent, incident report,
 * identity, membership, escalation, signal, report, external port, platform
 * action or SIEM log source named by id in the path (SUBJECTS), and
 * identity_id, agent_id or escalation_id in the query or the body (ID_FIELDS).
 * A read that fails, or an object whose organization cannot be classified, is a
 * refusal. Other ids are NOT read: remediation ids (the incident report in the
 * path is read), invoice, trial and reseller subscription ids, user_id,
 * entity_id and manual_completion_remediation_ids. A write that names no
 * organization is allowed and the approval says "account-wide". Reseller writes
 * are billing changes: held like every write, and the approval says so and
 * names the subscription.
 *
 * ESCALATION RESOLUTION. POST /v1/escalations/{id}/resolution goes through the
 * same contract as huntress_resolve_escalation: the escalation's organization
 * set must be exactly the client's mapped organization, checked by that tool's
 * own StaffHuntressActionToolExecutor::escalationScopeError() at the call and
 * again at approval (account-level, multi-organization and unreadable sets are
 * refused); any non-empty body is refused; approval sends the literal {}
 * through HuntressWriteClient::resolveEscalation() (request() refuses that
 * path); and the server-reported resolution_method must be one of
 * StaffHuntressActionToolExecutor::SAFE_RESOLUTION_METHODS, else the approval
 * reports a hard fault.
 *
 * GET LISTS. Where the rows name an organization (ROW_ORG, which includes
 * organization usage line items), rows whose organization is not mapped to a
 * PSA client, cannot be read, or is absent (account-level rows) are dropped
 * and counted (dropped_unmapped), as the reads do; only account-level
 * escalations are kept. Lists whose rows carry no organization (accounts,
 * invoices, subscriptions, trials, comments, remediations, known VPNs, remote
 * access connections and account usage line items) are not filtered, and the
 * answer's note says so.
 *
 * ERRORS. Status and exception class only (C-56); a vendor 4xx other than 408
 * adds the reason from the response body, fenced (#8430). Vendor strings in
 * answers are sanitised and UNTRUSTED-fenced as the reads do. Every write,
 * staged, sent or refused, writes one TechnicianActionLog row, including a
 * write refused because Huntress is not configured and an approval that throws
 * before or after the send.
 */
class StaffHuntressRequestToolExecutor
{
    public const TOOL = 'huntress_request';

    public const STAGED = 'huntress_stage_request';

    private const WRITE_METHODS = ['POST', 'PATCH', 'DELETE'];

    private const ALLOWED_ARGUMENT_KEYS = ['method', 'path', 'query', 'body', 'ticket_id', 'reason'];

    private const REASON_MAX = 500;

    private const MAX_RUN_GENERATIONS = 20;

    /** The escalation resolution template, sent only through the {}-only resolve contract. */
    private const RESOLUTION = '/v1/escalations/{id}/resolution';

    /**
     * Ids in the query or the body that name an object of a read-first family:
     * field => SUBJECTS key. Each is read first and org-checked, failing closed.
     *
     * @var array<string, string>
     */
    private const ID_FIELDS = ['identity_id' => 'identities', 'agent_id' => 'agents', 'escalation_id' => 'escalations'];

    /**
     * Every method+path of the Huntress v1 spec (swagger 2.0, host api.huntress.io),
     * except POST /v1/siem/query. q: the query parameters the spec lists; b: the
     * body properties of the endpoint's body parameter (null: no body); r: the
     * response wrapper key of a GET (list: whether it holds a list).
     *
     * @var array<string, array{q: array<int, string>, b: array<int, string>|null, r?: string, list?: bool}>
     */
    private const ENDPOINTS = [
        'GET /v1/account' => ['q' => [], 'b' => null, 'r' => 'account', 'list' => false],
        'GET /v1/accounts' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'status', 'name', 'subdomain'], 'b' => null, 'r' => 'accounts', 'list' => true],
        'POST /v1/accounts' => ['q' => [], 'b' => ['name', 'subdomain', 'phone_number', 'admin', 'additional_admin_emails', 'support_type', 'products', 'product_trials', 'product_trials_start_date', 'billing_address', 'shipping_address']],
        'GET /v1/accounts/{account_id}' => ['q' => [], 'b' => null, 'r' => 'account', 'list' => false],
        'PATCH /v1/accounts/{account_id}' => ['q' => [], 'b' => ['name', 'subdomain', 'phone_number', 'support_type', 'billing_address', 'shipping_address']],
        'DELETE /v1/accounts/{account_id}' => ['q' => [], 'b' => null],
        'GET /v1/accounts/{account_id}/agents' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'organization_id', 'platform', 'hostname', 'os', 'version'], 'b' => null, 'r' => 'agents', 'list' => true],
        'GET /v1/accounts/{account_id}/agents/{id}' => ['q' => [], 'b' => null, 'r' => 'agent', 'list' => false],
        'PATCH /v1/accounts/{account_id}/agents/{id}' => ['q' => [], 'b' => ['tags', 'tamper_protection_configured']],
        'DELETE /v1/accounts/{account_id}/agents/{id}' => ['q' => [], 'b' => null],
        'POST /v1/accounts/{account_id}/agents/{id}/isolation' => ['q' => [], 'b' => ['reason', 'strict_isolation']],
        'DELETE /v1/accounts/{account_id}/agents/{id}/isolation' => ['q' => [], 'b' => null],
        'GET /v1/accounts/{account_id}/external_ports' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'organization_id', 'protocol', 'port', 'risky_service', 'service'], 'b' => null, 'r' => 'external_ports', 'list' => true],
        'GET /v1/accounts/{account_id}/external_ports/{id}' => ['q' => [], 'b' => null, 'r' => 'external_port', 'list' => false],
        'GET /v1/accounts/{account_id}/incident_reports' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'indicator_type', 'status', 'severity', 'platform', 'organization_id', 'agent_id', 'psa_ticket_eid'], 'b' => null, 'r' => 'incident_reports', 'list' => true],
        'GET /v1/accounts/{account_id}/incident_reports/{id}' => ['q' => [], 'b' => null, 'r' => 'incident_report', 'list' => false],
        'GET /v1/accounts/{account_id}/incident_reports/{id}/comments' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction'], 'b' => null, 'r' => 'comments', 'list' => true],
        'POST /v1/accounts/{account_id}/incident_reports/{id}/comments' => ['q' => [], 'b' => ['body']],
        'POST /v1/accounts/{account_id}/incident_reports/{id}/resolution' => ['q' => [], 'b' => null],
        'GET /v1/accounts/{account_id}/incident_reports/{incident_report_id}/remediations' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'types[]', 'statuses[]'], 'b' => null, 'r' => 'remediations', 'list' => true],
        'POST /v1/accounts/{account_id}/incident_reports/{incident_report_id}/remediations/bulk_approval' => ['q' => [], 'b' => ['manual_completion_remediation_ids']],
        'POST /v1/accounts/{account_id}/incident_reports/{incident_report_id}/remediations/bulk_rejection' => ['q' => [], 'b' => ['comment', 'useful', 'name', 'phone_number', 'email', 'reason']],
        'GET /v1/accounts/{account_id}/incident_reports/{incident_report_id}/remediations/{remediation_id}' => ['q' => [], 'b' => null, 'r' => 'remediation', 'list' => false],
        'POST /v1/accounts/{account_id}/incident_reports/{incident_report_id}/remediations/{remediation_id}/completion' => ['q' => [], 'b' => ['completed']],
        'GET /v1/accounts/{account_id}/invoices' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'status', 'has_usage'], 'b' => null, 'r' => 'invoices', 'list' => true],
        'GET /v1/accounts/{account_id}/invoices/{id}' => ['q' => [], 'b' => null, 'r' => 'invoice', 'list' => false],
        'GET /v1/accounts/{account_id}/memberships' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'organization_id', 'user_id', 'permissions'], 'b' => null, 'r' => 'memberships', 'list' => true],
        'POST /v1/accounts/{account_id}/memberships' => ['q' => [], 'b' => ['email', 'first_name', 'last_name', 'permissions', 'organization_id']],
        'GET /v1/accounts/{account_id}/memberships/{id}' => ['q' => [], 'b' => null, 'r' => 'membership', 'list' => false],
        'PATCH /v1/accounts/{account_id}/memberships/{id}' => ['q' => [], 'b' => ['permissions']],
        'DELETE /v1/accounts/{account_id}/memberships/{id}' => ['q' => [], 'b' => null],
        'GET /v1/accounts/{account_id}/organizations' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'name', 'key'], 'b' => null, 'r' => 'organizations', 'list' => true],
        'POST /v1/accounts/{account_id}/organizations' => ['q' => [], 'b' => ['name', 'key']],
        'GET /v1/accounts/{account_id}/organizations/{id}' => ['q' => [], 'b' => null, 'r' => 'organization', 'list' => false],
        'PATCH /v1/accounts/{account_id}/organizations/{id}' => ['q' => [], 'b' => ['name', 'key', 'report_recipients']],
        'DELETE /v1/accounts/{account_id}/organizations/{id}' => ['q' => ['revoke_key'], 'b' => null],
        'GET /v1/accounts/{account_id}/remote_access/connections' => ['q' => ['last_seen_at_min', 'organization_id'], 'b' => null, 'r' => 'connections', 'list' => true],
        'GET /v1/accounts/{account_id}/reports' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'period_min', 'period_max', 'organization_id', 'type'], 'b' => null, 'r' => 'reports', 'list' => true],
        'GET /v1/accounts/{account_id}/reports/{id}' => ['q' => [], 'b' => null, 'r' => 'report', 'list' => false],
        'GET /v1/accounts/{account_id}/signals' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'investigated_at_min', 'investigated_at_max', 'entity_type', 'entity_id', 'organization_id', 'types', 'statuses'], 'b' => null, 'r' => 'signals', 'list' => true],
        'GET /v1/accounts/{account_id}/signals/{id}' => ['q' => [], 'b' => null, 'r' => 'signal', 'list' => false],
        'GET /v1/actor' => ['q' => [], 'b' => null, 'r' => '', 'list' => false],
        'GET /v1/agents' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'organization_id', 'platform', 'hostname', 'os', 'version'], 'b' => null, 'r' => 'agents', 'list' => true],
        'GET /v1/agents/{id}' => ['q' => [], 'b' => null, 'r' => 'agent', 'list' => false],
        'PATCH /v1/agents/{id}' => ['q' => [], 'b' => ['tags', 'tamper_protection_configured']],
        'DELETE /v1/agents/{id}' => ['q' => [], 'b' => null],
        'POST /v1/agents/{id}/isolation' => ['q' => [], 'b' => ['reason', 'strict_isolation']],
        'DELETE /v1/agents/{id}/isolation' => ['q' => [], 'b' => null],
        'GET /v1/escalations' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'status', 'severity', 'subtype', 'organization_id', 'psa_ticket_eid'], 'b' => null, 'r' => 'escalations', 'list' => true],
        'GET /v1/escalations/{id}' => ['q' => [], 'b' => null, 'r' => 'escalation', 'list' => false],
        'POST /v1/escalations/{id}/resolution' => ['q' => [], 'b' => ['determination', 'scope', 'expiration_date', 'ip_address', 'revoke_and_disable_identities']],
        'GET /v1/external_ports' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'organization_id', 'protocol', 'port', 'risky_service', 'service'], 'b' => null, 'r' => 'external_ports', 'list' => true],
        'GET /v1/external_ports/{id}' => ['q' => [], 'b' => null, 'r' => 'external_port', 'list' => false],
        'GET /v1/identities' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'organization_id', 'tenant_type', 'billable', 'enabled', 'external', 'risk_level', 'mfa_enabled', 'escalation_id'], 'b' => null, 'r' => 'identities', 'list' => true],
        'GET /v1/identities/{id}' => ['q' => [], 'b' => null, 'r' => 'identity', 'list' => false],
        'GET /v1/incident_reports' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'indicator_type', 'status', 'severity', 'platform', 'organization_id', 'agent_id', 'psa_ticket_eid'], 'b' => null, 'r' => 'incident_reports', 'list' => true],
        'GET /v1/incident_reports/{id}' => ['q' => [], 'b' => null, 'r' => 'incident_report', 'list' => false],
        'GET /v1/incident_reports/{id}/comments' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction'], 'b' => null, 'r' => 'comments', 'list' => true],
        'POST /v1/incident_reports/{id}/comments' => ['q' => [], 'b' => ['body']],
        'POST /v1/incident_reports/{id}/resolution' => ['q' => [], 'b' => null],
        'GET /v1/incident_reports/{incident_report_id}/remediations' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'types[]', 'statuses[]'], 'b' => null, 'r' => 'remediations', 'list' => true],
        'POST /v1/incident_reports/{incident_report_id}/remediations/bulk_approval' => ['q' => [], 'b' => ['manual_completion_remediation_ids']],
        'POST /v1/incident_reports/{incident_report_id}/remediations/bulk_rejection' => ['q' => [], 'b' => ['comment', 'useful', 'name', 'phone_number', 'email', 'reason']],
        'GET /v1/incident_reports/{incident_report_id}/remediations/{remediation_id}' => ['q' => [], 'b' => null, 'r' => 'remediation', 'list' => false],
        'POST /v1/incident_reports/{incident_report_id}/remediations/{remediation_id}/completion' => ['q' => [], 'b' => ['completed']],
        'GET /v1/invoices' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'status', 'has_usage'], 'b' => null, 'r' => 'invoices', 'list' => true],
        'GET /v1/invoices/{id}' => ['q' => [], 'b' => null, 'r' => 'invoice', 'list' => false],
        'GET /v1/known_vpns' => ['q' => [], 'b' => null, 'r' => 'known_vpns', 'list' => true],
        'GET /v1/memberships' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'organization_id', 'user_id', 'permissions'], 'b' => null, 'r' => 'memberships', 'list' => true],
        'POST /v1/memberships' => ['q' => [], 'b' => ['email', 'first_name', 'last_name', 'permissions', 'organization_id']],
        'GET /v1/memberships/{id}' => ['q' => [], 'b' => null, 'r' => 'membership', 'list' => false],
        'PATCH /v1/memberships/{id}' => ['q' => [], 'b' => ['permissions']],
        'DELETE /v1/memberships/{id}' => ['q' => [], 'b' => null],
        'GET /v1/organizations' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'name', 'key'], 'b' => null, 'r' => 'organizations', 'list' => true],
        'POST /v1/organizations' => ['q' => [], 'b' => ['name', 'key']],
        'GET /v1/organizations/{id}' => ['q' => [], 'b' => null, 'r' => 'organization', 'list' => false],
        'PATCH /v1/organizations/{id}' => ['q' => [], 'b' => ['name', 'key', 'report_recipients']],
        'DELETE /v1/organizations/{id}' => ['q' => ['revoke_key'], 'b' => null],
        'GET /v1/platform_actions' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'status', 'severity', 'subtype', 'organization_id'], 'b' => null, 'r' => 'platform_actions', 'list' => true],
        'GET /v1/platform_actions/{id}' => ['q' => [], 'b' => null, 'r' => 'platform_action', 'list' => false],
        'GET /v1/remote_access/connections' => ['q' => ['last_seen_at_min', 'organization_id'], 'b' => null, 'r' => 'connections', 'list' => true],
        'GET /v1/reports' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'period_min', 'period_max', 'organization_id', 'type'], 'b' => null, 'r' => 'reports', 'list' => true],
        'GET /v1/reports/{id}' => ['q' => [], 'b' => null, 'r' => 'report', 'list' => false],
        'GET /v1/reseller/invoices' => ['q' => ['limit', 'page_token', 'status'], 'b' => null, 'r' => 'invoices', 'list' => true],
        'GET /v1/reseller/invoices/{id}' => ['q' => [], 'b' => null, 'r' => 'invoice', 'list' => false],
        'GET /v1/reseller/invoices/{id}/account_usage_line_items' => ['q' => ['limit', 'page_token'], 'b' => null, 'r' => 'account_usage_line_items', 'list' => true],
        'GET /v1/reseller/invoices/{id}/organization_usage_line_items' => ['q' => ['limit', 'page_token'], 'b' => null, 'r' => 'organization_usage_line_items', 'list' => true],
        'GET /v1/reseller/subscriptions' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'product', 'status'], 'b' => null, 'r' => 'subscriptions', 'list' => true],
        'POST /v1/reseller/subscriptions' => ['q' => [], 'b' => ['account_id', 'product', 'minimum', 'purchase_order', 'billing_interval']],
        'GET /v1/reseller/subscriptions/{id}' => ['q' => [], 'b' => null, 'r' => 'subscription', 'list' => false],
        'PATCH /v1/reseller/subscriptions/{id}' => ['q' => [], 'b' => ['minimum', 'purchase_order', 'billing_interval', 'additional_units', 'auto_renew']],
        'POST /v1/reseller/subscriptions/{id}/upgrade' => ['q' => [], 'b' => ['minimum', 'purchase_order', 'on_renewal']],
        'GET /v1/siem/log_sources' => ['q' => ['limit', 'page_token', 'only_for_account', 'organization_id', 'type_code'], 'b' => null, 'r' => 'log_sources', 'list' => true],
        'GET /v1/siem/log_sources/misconfigured' => ['q' => ['limit', 'page_token', 'organization_id'], 'b' => null, 'r' => 'misconfigured_log_sources', 'list' => true],
        'GET /v1/siem/log_sources/{id}' => ['q' => [], 'b' => null, 'r' => 'log_source', 'list' => false],
        'GET /v1/signals' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'investigated_at_min', 'investigated_at_max', 'entity_type', 'entity_id', 'organization_id', 'types', 'statuses'], 'b' => null, 'r' => 'signals', 'list' => true],
        'GET /v1/signals/{id}' => ['q' => [], 'b' => null, 'r' => 'signal', 'list' => false],
        'GET /v1/trials' => ['q' => ['limit', 'page_token', 'sort_field', 'sort_direction', 'product', 'status', 'type'], 'b' => null, 'r' => 'trials', 'list' => true],
        'GET /v1/trials/{id}' => ['q' => [], 'b' => null, 'r' => 'trial', 'list' => false],
        'GET /v1/unwanted_access_rules' => ['q' => ['limit', 'page_token', 'organization_id', 'identity_id', 'type', 'status', 'scope', 'category', 'country_code', 'vpn', 'ip_address', 'logic'], 'b' => null, 'r' => 'unwanted_access_rules', 'list' => true],
        'POST /v1/unwanted_access_rules' => ['q' => [], 'b' => ['identity_id', 'organization_id', 'country_code', 'vpn', 'ip_address', 'logic', 'type', 'starts_at', 'expires_at', 'notes', 'category']],
        'GET /v1/unwanted_access_rules/{id}' => ['q' => [], 'b' => null, 'r' => 'unwanted_access_rule', 'list' => false],
        'PATCH /v1/unwanted_access_rules/{id}' => ['q' => [], 'b' => ['starts_at', 'expires_at', 'notes']],
        'DELETE /v1/unwanted_access_rules/{id}' => ['q' => [], 'b' => null],
    ];

    /** Response wrapper key => how a row names its organization. */
    private const ROW_ORG = [
        'agent' => 'organization_id', 'agents' => 'organization_id',
        'incident_report' => 'organization_id', 'incident_reports' => 'organization_id',
        'report' => 'organization_id', 'reports' => 'organization_id',
        'signal' => 'organization.id', 'signals' => 'organization.id',
        'identity' => 'organization.id', 'identities' => 'organization.id',
        'log_source' => 'organization.id', 'log_sources' => 'organization.id', 'misconfigured_log_sources' => 'organization.id',
        'membership' => 'organization.id', 'memberships' => 'organization.id',
        'organization_usage_line_items' => 'organization.id',
        'external_port' => 'organization_ids', 'external_ports' => 'organization_ids',
        'escalation' => 'organizations', 'escalations' => 'organizations',
        'platform_action' => 'organizations', 'platform_actions' => 'organizations',
        'unwanted_access_rule' => 'applied_to', 'unwanted_access_rules' => 'applied_to',
        'organization' => 'self', 'organizations' => 'self',
    ];

    /**
     * Objects a path names by id, read first: path prefix => [read endpoint, wrapper key, label].
     * The {n} in the read endpoint is the id that follows the prefix.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const SUBJECTS = [
        'unwanted_access_rules' => ['unwanted_access_rules/{n}', 'unwanted_access_rule', 'unwanted access rule'],
        'agents' => ['agents/{n}', 'agent', 'agent'],
        'incident_reports' => ['incident_reports/{n}', 'incident_report', 'incident report'],
        'identities' => ['identities/{n}', 'identity', 'identity'],
        'memberships' => ['memberships/{n}', 'membership', 'membership'],
        'escalations' => ['escalations/{n}', 'escalation', 'escalation'],
        'signals' => ['signals/{n}', 'signal', 'signal'],
        'reports' => ['reports/{n}', 'report', 'report'],
        'external_ports' => ['external_ports/{n}', 'external_port', 'external port'],
        'platform_actions' => ['platform_actions/{n}', 'platform_action', 'platform action'],
        'siem/log_sources' => ['siem/log_sources/{n}', 'log_source', 'log source'],
    ];

    public function __construct(
        private readonly HuntressWriteClient $writeClient,
        private readonly ActionRedactor $redactor,
        private readonly PromptFence $fence,
        private readonly ChetDataSurfaceTextSanitizer $sanitizer,
    ) {}

    /** @return array<string, string> */
    public static function stagedToDirectMap(): array
    {
        return [self::STAGED => self::TOOL];
    }

    public static function isStagedActionType(string $actionType): bool
    {
        return $actionType === self::STAGED;
    }

    public static function handles(string $toolName): bool
    {
        return $toolName === self::TOOL || $toolName === self::STAGED;
    }

    /** @return array<int, string> Every "METHOD /v1/path" the tool can reach. */
    public static function endpoints(): array
    {
        return array_keys(self::ENDPOINTS);
    }

    /** @return array<int, array<string, mixed>> */
    public static function definitions(): array
    {
        $properties = [
            'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PATCH', 'DELETE'], 'description' => 'HTTP method. GET runs at once; POST, PATCH and DELETE are always held for cockpit approval.'],
            'path' => ['type' => 'string', 'description' => 'Path under /v1/ exactly as in the Huntress API spec, ids filled in as positive integers, e.g. /v1/agents/9001/isolation. No host, scheme, query string, percent-encoding or "..".'],
            'query' => ['type' => 'object', 'description' => 'Query parameters the spec lists for this endpoint (strings, integers or booleans).'],
            'body' => ['type' => 'object', 'description' => 'JSON body with the fields the spec lists for this endpoint. Omit for GET and DELETE.'],
            'ticket_id' => ['type' => 'integer', 'description' => 'PSA ticket of this client a write is held on (required for POST, PATCH and DELETE).'],
            'reason' => ['type' => 'string', 'description' => 'Why this request is made (required for POST, PATCH and DELETE). Recorded in the audit log and shown to the approver.'],
        ];
        $description = 'One request to any Huntress API endpoint in the vendor spec, for one PSA client (client_id). GET runs at once. POST, PATCH and DELETE are ALWAYS held for cockpit approval whatever this token\'s grant says; the approval shows the method, path, query and body exactly as they will be sent. '
            .'An organization id in the path, query or body must be this client\'s mapped Huntress organization; an account id in the path, or account_id in the body, must be the API key\'s own account. '
            .'Read first, and refused unless it belongs to the mapped organization or to none (then the approval says account-wide): an unwanted access rule, agent, incident report, identity, membership, escalation, signal, report, external port, platform action or SIEM log source named by id in the path, and identity_id, agent_id or escalation_id in the query or body. A failed read is a refusal. '
            .'Other ids are NOT read or checked: remediation ids (the incident report in the path is read), invoice, trial and reseller subscription ids, user_id, entity_id and manual_completion_remediation_ids. '
            .'Reseller writes are billing changes and are held the same way. POST /v1/escalations/{id}/resolution takes no body: like huntress_resolve_escalation it accepts only an escalation whose organization set is exactly this client\'s mapped organization (account-level and multi-organization escalations are refused), sends the empty object {} and checks the resolution_method Huntress reports. POST /v1/siem/query is refused: use huntress_siem_query. '
            .'Lists whose rows name an organization (organization usage line items included) drop rows of organizations not mapped to a PSA client, rows whose organization cannot be read, and rows that name no organization (account level; account-level escalations alone are kept), and count them in dropped_unmapped; lists whose rows carry no organization (accounts, invoices, subscriptions, trials, comments, remediations, known VPNs, remote access connections, account usage line items) are not filtered. '
            .'For /v1/unwanted_access_rules, '.lcfirst(StaffHuntressRuleToolExecutor::STARTS_AT_RULE).' On POST, '.lcfirst(StaffHuntressRuleToolExecutor::STARTS_AT_CREATE).' '.StaffHuntressRuleToolExecutor::STARTS_AT_UPDATE.' Vendor text comes back inside UNTRUSTED fences.';

        return [
            ['name' => self::TOOL, 'description' => $description,
                'input_schema' => ['type' => 'object', 'properties' => $properties, 'required' => ['method', 'path']]],
            ['name' => self::STAGED, 'description' => $description,
                'input_schema' => ['type' => 'object', 'properties' => $properties, 'required' => ['method', 'path']]],
        ];
    }

    /** @return array<string, mixed> */
    public function execute(string $name, array $arguments, int $clientId, string $actorLabel, ?string $tokenLabel = null): array
    {
        if (! self::handles($name)) {
            return ['error' => "Unknown Huntress request tool: {$name}"];
        }
        $method = is_string($arguments['method'] ?? null) ? strtoupper(trim($arguments['method'])) : '';
        $write = $method !== 'GET';
        $hash = $this->contentHash($clientId, null, $arguments);
        $refuse = function (string $message, ?Ticket $ticket = null, string $status = 'rejected') use ($write, $clientId, $hash, $actorLabel, $arguments): array {
            if ($write) {
                $this->audit(self::STAGED, $status, $clientId, $ticket, $hash, self::requestLine($arguments).': '.$message, $actorLabel);
            }

            return ['error' => $message.' Nothing was sent.'];
        };

        if (! HuntressConfig::isEnabled() || ! HuntressConfig::isConfigured()) {
            // Ruling item 7: a write refused here is audited like any other refusal.
            if ($write) {
                $this->audit(self::STAGED, 'blocked', $clientId, null, $hash, self::requestLine($arguments).': Huntress is not configured', $actorLabel);
            }

            return ['error' => 'Huntress is not configured'];
        }

        $unknown = array_values(array_diff(array_map('strval', array_keys($arguments)), self::ALLOWED_ARGUMENT_KEYS));
        if ($unknown !== []) {
            return $refuse('Parameter'.(count($unknown) === 1 ? '' : 's').' refused: '.implode(', ', $unknown).'. Allowed: client_id, '.implode(', ', self::ALLOWED_ARGUMENT_KEYS).'. Headers, hosts and full URLs are never taken from the caller.');
        }
        if (! in_array($method, ['GET', ...self::WRITE_METHODS], true)) {
            return $refuse('method must be GET, POST, PATCH or DELETE.');
        }
        if ($write && TechnicianConfig::killSwitchEngaged()) {
            return $refuse('Technician kill-switch engaged; Huntress write refused.', null, 'blocked');
        }
        $client = Client::find($clientId);
        if (! $client) {
            return $refuse('Client not found.');
        }
        $orgId = $this->positiveInt($client->huntress_organization_id);
        if ($orgId === null) {
            return $refuse("Client {$client->id} has no mapped Huntress organization; map it in Settings > Huntress first.");
        }

        $reason = is_string($arguments['reason'] ?? null) ? mb_substr(trim($arguments['reason']), 0, self::REASON_MAX) : '';
        $ticket = null;
        if ($write) {
            if ($reason === '') {
                return $refuse('reason is required for POST, PATCH and DELETE.');
            }
            $ticket = $this->ticketFor($clientId, $arguments['ticket_id'] ?? null);
            if (is_string($ticket)) {
                return $refuse($ticket);
            }
        }

        $request = $this->assess($method, $arguments['path'] ?? null, $arguments['query'] ?? null, $arguments['body'] ?? null, $clientId, $orgId);
        if (is_string($request)) {
            return $refuse($request, $ticket instanceof Ticket ? $ticket : null);
        }

        if (! $write) {
            // GET runs at once on either lane, whatever the grant's mode.
            return $this->get($request, $orgId);
        }

        // POST, PATCH and DELETE: always held for cockpit approval, on either lane.
        // There is no immediate write path in this tool, whatever the grant says.

        return $this->stage($request, $client, $ticket, $orgId, $reason, $actorLabel, $tokenLabel);
    }

    /**
     * The exact request, after every check that can run before staging, or the
     * refusal text. Reads (GET /v1/account, each object the path names by id, and
     * each ID_FIELDS id in the query or body) go through the read client; a failed
     * read refuses. $atApproval: this is the re-check when a staged request is
     * approved, so refusal texts say so.
     *
     * @return array<string, mixed>|string
     */
    private function assess(string $method, mixed $path, mixed $query, mixed $body, int $clientId, int $orgId, bool $atApproval = false): array|string
    {
        $match = self::matchPath($method, $path);
        if (is_string($match)) {
            return $match;
        }
        [$template, $endpoint, $params] = $match;
        $spec = self::ENDPOINTS[$method.' '.$template];

        $query = self::normalizeQuery($query, $spec['q']);
        if (is_string($query)) {
            return $query;
        }
        if ($body !== null && (! is_array($body) || (array_is_list($body) && $body !== []))) {
            return 'body must be a JSON object.';
        }
        if ($body !== null && $spec['b'] === null) {
            return "{$method} {$template} takes no body.";
        }
        if ($template === self::RESOLUTION) {
            // The resolve contract of huntress_resolve_escalation: the literal {} only.
            if ($body !== null && $body !== []) {
                return 'POST '.self::RESOLUTION.' takes no body here: like huntress_resolve_escalation it sends only the empty object {} (no determination, scope, expiration_date, ip_address or revoke_and_disable_identities). Omit body.';
            }
            $body = [];
        }
        $unknownBody = $body === null ? [] : array_values(array_diff(array_map('strval', array_keys($body)), $spec['b']));
        if ($unknownBody !== []) {
            return 'Body key'.(count($unknownBody) === 1 ? '' : 's').' not in the spec for this endpoint: '.implode(', ', $unknownBody).'. Allowed: '.(implode(', ', $spec['b'] ?? []) ?: 'none').'.';
        }
        $unsafe = $this->displayRefusal(['query' => $query, 'body' => $body]);
        if ($unsafe !== null) {
            return $unsafe;
        }

        // Org fence: path, query, body.
        $scoped = false;
        foreach ($params as $param) {
            if ($param['after'] === 'organizations' && $param['value'] !== $orgId) {
                return "Organization {$param['value']} in the path is not the Huntress organization mapped to this client.";
            }
            $scoped = $scoped || $param['after'] === 'organizations';
        }
        if (array_key_exists('organization_id', $query)) {
            if ($this->positiveInt($query['organization_id']) !== $orgId) {
                return 'organization_id in the query is not the Huntress organization mapped to this client.';
            }
            $scoped = true;
        }
        if ($body !== null && array_key_exists('organization_id', $body)) {
            if (! is_int($body['organization_id']) || $body['organization_id'] !== $orgId) {
                return 'organization_id in the body is not the Huntress organization mapped to this client (it must be that integer).';
            }
            $scoped = true;
        }
        if (in_array($template, ['/v1/remote_access/connections', '/v1/accounts/{account_id}/remote_access/connections'], true) && ! array_key_exists('organization_id', $query)) {
            return 'Remote access connections carry no organization, so organization_id (this client\'s mapped organization) is required in the query.';
        }

        // #8431 for the generic path to the rule endpoints.
        if (str_starts_with($template, '/v1/unwanted_access_rules') && $body !== null && array_key_exists('starts_at', $body)
            && $body['starts_at'] !== null && $body['starts_at'] !== '') {
            $past = StaffHuntressRuleToolExecutor::startsAtRefusal($body['starts_at'], $method === 'PATCH', $atApproval);
            if ($past !== null) {
                return $past;
            }
        }

        // Account ids: in the path, and account_id in the body (POST /v1/reseller/subscriptions).
        $account = null;
        foreach ($params as $param) {
            if ($param['after'] === 'accounts') {
                $account ??= $this->ownAccountId();
                if (is_string($account)) {
                    return $account;
                }
                if ($param['value'] !== $account) {
                    return "Account {$param['value']} in the path is not the account this Huntress API key belongs to.";
                }
            }
        }
        if ($body !== null && array_key_exists('account_id', $body)) {
            if (! is_int($body['account_id']) || $body['account_id'] <= 0) {
                return 'account_id in the body must be a positive integer (the account this Huntress API key belongs to).';
            }
            $account ??= $this->ownAccountId();
            if (is_string($account)) {
                return $account;
            }
            if ($body['account_id'] !== $account) {
                return "Account {$body['account_id']} in the body is not the account this Huntress API key belongs to.";
            }
        }

        // Objects named by id: read first, unless this GET is the read of that object.
        $subjects = [];
        foreach ($params as $i => $param) {
            $subject = self::SUBJECTS[$param['after']] ?? null;
            if ($subject === null) {
                continue;
            }
            $last = $i === array_key_last($params) && str_ends_with($endpoint, '/'.$param['value']);
            if ($method === 'GET' && $last && $param['collection_is_last']) {
                continue;
            }
            $read = $template === self::RESOLUTION
                ? $this->readResolutionEscalation($param['value'], $clientId)
                : $this->readSubject($subject, $param['value'], $orgId);
            if (is_string($read)) {
                return $read;
            }
            $subjects[] = ['label' => $subject[2], 'id' => $param['value'], 'org' => $read['org'], 'row' => $read['row'], 'where' => 'path'];
            $scoped = $scoped || $read['org'] !== null;
        }

        // Ids in the query or the body that name a read-first object: read and org-check each.
        foreach (['query' => $query, 'body' => $body ?? []] as $where => $fields) {
            foreach (self::ID_FIELDS as $field => $family) {
                if (! array_key_exists($field, $fields)) {
                    continue;
                }
                $id = is_int($fields[$field]) || ($where === 'query' && is_string($fields[$field])) ? $this->positiveInt($fields[$field]) : null;
                if ($id === null) {
                    return "{$field} in the {$where} must be a positive integer.";
                }
                $subject = self::SUBJECTS[$family];
                $read = $this->readSubject($subject, $id, $orgId);
                if (is_string($read)) {
                    return $read;
                }
                $subjects[] = ['label' => $subject[2], 'id' => $id, 'org' => $read['org'], 'row' => $read['row'], 'where' => $where];
                $scoped = $scoped || $read['org'] !== null;
            }
        }

        $reseller = str_starts_with($template, '/v1/reseller/');

        return [
            'method' => $method,
            'template' => $template,
            'path' => '/v1/'.$endpoint,
            'endpoint' => $endpoint,
            'query' => $query,
            'body' => $body,
            'subjects' => $subjects,
            'account_checked' => $account !== null,
            'account_wide' => ! $scoped,
            'reseller' => $reseller,
            'resolution' => $template === self::RESOLUTION,
            'escalation_id' => $template === self::RESOLUTION ? $params[0]['value'] : null,
            'subscription' => $reseller && str_starts_with($template, '/v1/reseller/subscriptions')
                ? ($params === [] ? 'new subscription' : 'subscription '.$params[0]['value'])
                : null,
            'spec' => $spec,
        ];
    }

    /**
     * [template, endpoint without "/v1/", path parameters] or the refusal text.
     *
     * @return array{0: string, 1: string, 2: array<int, array{name: string, value: int, after: string, collection_is_last: bool}>}|string
     */
    private static function matchPath(string $method, mixed $path): array|string
    {
        if (! is_string($path) || $path === '') {
            return 'path is required, e.g. /v1/agents/9001.';
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) === 1 || str_starts_with($path, '//') || str_contains($path, '://')) {
            return 'A full URL, scheme or host is refused: give the path only, starting /v1/.';
        }
        if (str_contains($path, '%')) {
            return 'Percent-encoding is refused in the path.';
        }
        if (str_contains($path, '\\') || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            return 'Path traversal (".", ".." or a backslash) is refused.';
        }
        if (str_contains($path, '?') || str_contains($path, '#')) {
            return 'Put query parameters in query, not in the path.';
        }
        if (! str_starts_with($path, '/v1/')) {
            return 'The path must start /v1/.';
        }
        if (str_contains($path, '//') || str_ends_with($path, '/')) {
            return 'Empty path segments are refused.';
        }
        if (preg_match('#\A/v1(/[a-z0-9_]+)+\z#', $path) !== 1) {
            return 'The path may contain only lowercase letters, digits, underscores and single slashes.';
        }
        if ($method === 'POST' && $path === '/v1/siem/query') {
            return 'POST /v1/siem/query is not reachable through huntress_request: use huntress_siem_query, which pins the organization filter.';
        }

        $segments = explode('/', substr($path, 4));
        $badParam = false;
        $other = [];
        foreach (self::ENDPOINTS as $key => $_) {
            [$verb, $template] = explode(' ', $key, 2);
            $parts = explode('/', substr($template, 4));
            if (count($parts) !== count($segments)) {
                continue;
            }
            $params = [];
            $ok = true;
            foreach ($parts as $i => $part) {
                if (preg_match('/\A\{([a-z_]+)\}\z/', $part, $m) === 1) {
                    if (preg_match('/\A[1-9][0-9]{0,17}\z/', $segments[$i]) !== 1) {
                        $ok = false;
                        $badParam = $badParam || preg_match('/\A[0-9]+\z/', $segments[$i]) === 1;
                        break;
                    }
                    $after = $i > 0 ? $parts[$i - 1] : '';
                    if ($after === 'log_sources' && ($parts[$i - 2] ?? '') === 'siem') {
                        $after = 'siem/log_sources';
                    }
                    $params[] = ['name' => $m[1], 'value' => (int) $segments[$i], 'after' => $after, 'collection_is_last' => $i === count($parts) - 1];
                } elseif ($part !== $segments[$i]) {
                    $ok = false;
                    break;
                }
            }
            if (! $ok) {
                continue;
            }
            if ($verb !== $method) {
                $other[] = $verb;

                continue;
            }

            return [$template, substr($path, 4), $params];
        }
        if ($other !== []) {
            return "{$method} is not a method the Huntress spec lists for this path (it lists ".implode(', ', array_unique($other)).').';
        }

        return $badParam
            ? 'Path parameters must be positive integers without leading zeros.'
            : "{$method} {$path} does not match any endpoint in the Huntress API spec.";
    }

    /**
     * The query as it will be sent, or the refusal. Keys must be the endpoint's
     * own; values are strings, integers or booleans (sent as "true"/"false").
     *
     * @param  array<int, string>  $allowed
     * @return array<string, string|int>|string
     */
    private static function normalizeQuery(mixed $query, array $allowed): array|string
    {
        if ($query === null) {
            return [];
        }
        if (! is_array($query) || (array_is_list($query) && $query !== [])) {
            return 'query must be an object of parameter names to values.';
        }
        $unknown = array_values(array_diff(array_map('strval', array_keys($query)), $allowed));
        if ($unknown !== []) {
            return 'Query parameter'.(count($unknown) === 1 ? '' : 's').' not in the spec for this endpoint: '.implode(', ', $unknown).'. Allowed: '.(implode(', ', $allowed) ?: 'none').'.';
        }
        $out = [];
        foreach ($query as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            if (! is_string($value) && ! is_int($value)) {
                return "Query parameter {$key} must be a string, an integer or a boolean.";
            }
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * GET at once through the read client. Lists whose rows name an organization
     * (ROW_ORG) drop and count rows whose organization is not mapped to a PSA
     * client, cannot be read, or is absent (account level; account-level
     * escalations alone are kept); other lists are not filtered and the note
     * says so. A single object of a ROW_ORG family must belong to this client's
     * organization or to none.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function get(array $request, int $orgId): array
    {
        try {
            $response = $this->reader()->get($request['endpoint'], $request['query']);
        } catch (\Throwable $e) {
            return ['error' => self::failureText('GET', $request['path'], $e)];
        }
        $key = $request['spec']['r'] ?? '';
        $base = ['method' => 'GET', 'path' => $request['path'], 'query_sent' => $request['query']];
        if ($key === '') {
            return $base + ['response' => $this->clean($response)];
        }
        $payload = $response[$key] ?? null;
        $family = self::ROW_ORG[$key] ?? null;
        if (! ($request['spec']['list'] ?? false)) {
            if (! is_array($payload) || (array_is_list($payload) && $payload !== [])) {
                return ['error' => "Huntress returned an unrecognized shape for {$request['path']} (no {$key} object). This is not an empty answer."];
            }
            if ($family !== null) {
                $org = self::rowOrg($payload, $family);
                if ($org === false || ($org !== null && $org !== $orgId)) {
                    return ['error' => "The {$key} read belongs to another Huntress organization or its organization could not be read, so it is not shown."];
                }
            }

            return $base + [$key => $this->clean($payload)];
        }
        if (! is_array($payload) || ! array_is_list($payload)) {
            return ['error' => "Huntress returned an unrecognized shape for {$request['path']} (no {$key} list). This is not an empty answer."];
        }
        $mapped = $this->mappedOrgIds();
        $rows = [];
        $dropped = 0;
        foreach ($payload as $row) {
            if (! is_array($row)) {
                $dropped++;

                continue;
            }
            if ($family !== null && ! self::rowInScope($row, $family, $key, $mapped)) {
                $dropped++;

                continue;
            }
            $rows[] = $this->clean($row);
        }
        $token = $response['pagination']['next_page_token'] ?? null;

        return $base + [
            'count' => count($rows),
            $key => $rows,
            'dropped_unmapped' => $dropped,
            'next_page_token' => is_string($token) && $token !== '' ? $token : null,
            'note' => match (true) {
                $family === null => 'These rows carry no organization, so none were checked against the client mapping; only rows that are not objects are dropped and counted in dropped_unmapped.',
                $key === 'escalations' => 'Rows whose organization is not mapped to a PSA client, or whose organization cannot be read, are dropped and counted in dropped_unmapped; account-level escalations (no organization) are kept. This list may be incomplete.',
                default => 'Rows whose organization is not mapped to a PSA client, whose organization cannot be read, or that name no organization (account level) are dropped and counted in dropped_unmapped. This list may be incomplete.',
            },
        ];
    }

    /**
     * The organization a row belongs to: an int, null for account level (no
     * organization), or false when it cannot be classified.
     *
     * @param  array<string, mixed>  $row
     */
    private static function rowOrg(array $row, string $family): int|false|null
    {
        switch ($family) {
            case 'self':
                return is_int($row['id'] ?? null) ? $row['id'] : false;
            case 'organization_id':
                if (! array_key_exists('organization_id', $row)) {
                    return false;
                }

                return $row['organization_id'] === null ? null : (is_int($row['organization_id']) ? $row['organization_id'] : false);
            case 'organization.id':
                if (! array_key_exists('organization', $row)) {
                    return false;
                }
                if ($row['organization'] === null) {
                    return null;
                }

                return is_array($row['organization']) && is_int($row['organization']['id'] ?? null) ? $row['organization']['id'] : false;
            case 'applied_to':
                $applied = $row['applied_to'] ?? null;
                if (is_array($applied) && ($applied['type'] ?? null) === 'Organization' && is_int($applied['id'] ?? null)) {
                    return $applied['id'];
                }

                return is_array($applied) && ($applied['type'] ?? null) === 'Account' ? null : false;
            case 'organization_ids':
            case 'organizations':
                $list = $row[$family] ?? null;
                if (! is_array($list) || ! array_is_list($list)) {
                    return false;
                }
                $ids = [];
                foreach ($list as $item) {
                    $id = is_array($item) ? ($item['id'] ?? null) : $item;
                    if (! is_int($id)) {
                        return false;
                    }
                    $ids[$id] = true;
                }
                if ($ids === []) {
                    return null;
                }

                // Shared with more than one organization: not one client's object.
                return count($ids) === 1 ? array_key_first($ids) : false;
        }

        return false;
    }

    /**
     * Whether a list row is shown: every organization it names is mapped to a
     * PSA client. A row naming no organization is shown only for escalations
     * (account-level ones, as huntress_list_escalations does); elsewhere it is
     * dropped and counted.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, true>  $mapped
     */
    private static function rowInScope(array $row, string $family, string $key, array $mapped): bool
    {
        if (in_array($family, ['organization_ids', 'organizations'], true)) {
            $list = $row[$family] ?? null;
            if (! is_array($list) || ! array_is_list($list)) {
                return false;
            }
            if ($list === []) {
                return $key === 'escalations';
            }
            foreach ($list as $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : $item;
                if (! is_int($id) || ! isset($mapped[$id])) {
                    return false;
                }
            }

            return true;
        }
        $org = self::rowOrg($row, $family);

        return is_int($org) && isset($mapped[$org]);
    }

    /**
     * Read an object a path names by id. Its organization must be this client's,
     * or none (account level). Any failure refuses: fail closed.
     *
     * @param  array{0: string, 1: string, 2: string}  $subject
     * @return array{org: int|null, row: array<string, mixed>}|string
     */
    private function readSubject(array $subject, int $id, int $orgId): array|string
    {
        [$endpoint, $key, $label] = $subject;
        try {
            $response = $this->reader()->get(str_replace('{n}', (string) $id, $endpoint), []);
        } catch (\Throwable $e) {
            return "The {$label} {$id} could not be read first to check its organization (".self::failureDetail($e).'), so the request is refused.';
        }
        $row = $response[$key] ?? null;
        if (! is_array($row) || (array_is_list($row) && $row !== [])) {
            return "The {$label} {$id} was read in an unrecognized shape, so its organization could not be checked and the request is refused.";
        }
        $org = self::rowOrg($row, self::ROW_ORG[$key]);
        if ($org === false) {
            return "The {$label} {$id} names no organization this tool can classify (or several), so the request is refused.";
        }
        if ($org !== null && $org !== $orgId) {
            return "The {$label} {$id} belongs to Huntress organization {$org}, not the one mapped to this client.";
        }

        return ['org' => $org, 'row' => $row];
    }

    /**
     * The escalation a resolution names, read first and checked with
     * huntress_resolve_escalation's own scope rule
     * (StaffHuntressActionToolExecutor::escalationScopeError()): its organization
     * set must be exactly this client's mapped organization. Account-level,
     * multi-organization and unreadable sets are refused. Any failure refuses.
     *
     * @return array{org: int, row: array<string, mixed>}|string
     */
    private function readResolutionEscalation(int $id, int $clientId): array|string
    {
        $client = Client::find($clientId);
        $orgId = $client ? $this->positiveInt($client->huntress_organization_id) : null;
        if ($client === null || $orgId === null) {
            return 'The client or its Huntress organization mapping could not be read, so the escalation resolution is refused.';
        }
        try {
            $response = $this->reader()->get('escalations/'.$id, []);
        } catch (\Throwable $e) {
            return "The escalation {$id} could not be read first to check its organization (".self::failureDetail($e).'), so the request is refused.';
        }
        $row = $response['escalation'] ?? null;
        if (! is_array($row) || (array_is_list($row) && $row !== [])) {
            return "The escalation {$id} was read in an unrecognized shape, so its organization could not be checked and the request is refused.";
        }
        $error = app(StaffHuntressActionToolExecutor::class)->escalationScopeError($row, $client, $id);

        return $error ?? ['org' => $orgId, 'row' => $row];
    }

    /** The account the API key belongs to (GET /v1/account), or the refusal. */
    private function ownAccountId(): int|string
    {
        try {
            $id = $this->reader()->get('account', [])['account']['id'] ?? null;
        } catch (\Throwable $e) {
            return 'The Huntress account could not be read to check the account id ('.self::failureDetail($e).'), so the request is refused.';
        }

        return is_int($id) && $id > 0 ? $id : 'The Huntress account read did not carry an account id, so the account id could not be checked and the request is refused.';
    }

    /** @return array<int, true> */
    private function mappedOrgIds(): array
    {
        $ids = [];
        foreach (Client::whereNotNull('huntress_organization_id')->pluck('huntress_organization_id') as $value) {
            $id = $this->positiveInt($value);
            if ($id !== null) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /** Vendor data back to the caller: every string leaf sanitised and UNTRUSTED-fenced. */
    private function clean(mixed $value, int $depth = 6): mixed
    {
        if (is_string($value)) {
            return $this->sanitizer->sanitizeNullable('Huntress response', $value, 2000);
        }
        if (! is_array($value)) {
            return is_int($value) || is_float($value) || is_bool($value) ? $value : null;
        }
        if ($depth <= 0) {
            return '[truncated: max depth]';
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->clean($v, $depth - 1);
        }

        return $out;
    }

    private function reader(): HuntressClient
    {
        // Clamped back-off: this runs inside a synchronous MCP call or approval.
        return app(HuntressClient::class)->withClampedBackoff();
    }

    /**
     * Every write is held: store the exact request (encrypted) and show it on the card.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function stage(array $request, Client $client, Ticket $ticket, int $orgId, string $reason, string $actorLabel, ?string $tokenLabel): array
    {
        $wire = ['method' => $request['method'], 'path' => $request['path'], 'query' => $request['query'], 'body' => $request['body']];
        $hash = $this->contentHash((int) $client->id, $ticket->id, $wire);
        $slot = $this->runSlot($ticket->id, $hash);
        if ($slot === null) {
            $this->audit(self::STAGED, 'rejected', (int) $client->id, $ticket, $hash, self::requestLine($wire).': too many identical proposals', $actorLabel);

            return ['error' => 'This ticket already carries '.self::MAX_RUN_GENERATIONS.' identical Huntress request proposals; nothing was staged or sent. Raise a new ticket for a further one.'];
        }
        $live = TechnicianRun::query()->where('ticket_id', $ticket->id)->where('action_type', self::STAGED)
            ->where('content_hash', $slot)->where('state', TechnicianRunState::AwaitingApproval->value)->first();
        if ($live !== null) {
            return ['success' => true, 'idempotent' => true, 'ticket_id' => $ticket->id, 'run_id' => $live->id, 'message' => 'Already staged; awaiting approval. Nothing was sent.'];
        }

        $meta = [
            ...DraftedByToken::meta($actorLabel, $tokenLabel),
            'reasons' => [$reason],
            'direct_tool' => self::TOOL,
            'redacted_params' => ['method' => $request['method'], 'path' => $request['path']],
            'sensitive_inputs' => [],
            'encrypted_payload' => Crypt::encryptString(json_encode($wire + [
                'client_id' => $client->id, 'ticket_id' => $ticket->id, 'org_id' => $orgId,
            ], JSON_THROW_ON_ERROR)),
        ];
        $content = $this->display($request, $client)."\n".$this->fence->fence('AGENT SUPPLIED REASON', $reason);

        $run = TechnicianRun::firstOrCreate(
            ['ticket_id' => $ticket->id, 'action_type' => self::STAGED, 'content_hash' => $slot],
            ['client_id' => $client->id, 'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => $content, 'proposed_meta' => $meta, 'confidence' => null, 'tokens_used' => 0],
        );
        if (! $run->wasRecentlyCreated) {
            if (in_array($run->state, [TechnicianRunState::AwaitingApproval, TechnicianRunState::Executing, TechnicianRunState::Done], true)) {
                return ['success' => true, 'idempotent' => true, 'ticket_id' => $ticket->id, 'run_id' => $run->id, 'message' => 'This proposal is already staged or being handled; nothing new was staged and nothing was sent.'];
            }
            $moved = DB::transaction(function () use ($run, $content, $meta): bool {
                $fresh = TechnicianRun::query()->whereKey($run->getKey())->lockForUpdate()->first();
                if ($fresh === null || $fresh->state !== $run->state || $fresh->claimed_at?->toIso8601String() !== $run->claimed_at?->toIso8601String()) {
                    return false;
                }
                $fresh->update(['state' => TechnicianRunState::AwaitingApproval->value, 'proposed_content' => $content, 'proposed_meta' => $meta]);

                return true;
            });
            if (! $moved) {
                $this->audit(self::STAGED, 'rejected', (int) $client->id, $ticket, $hash, self::requestLine($wire).': run '.$run->id.' changed state while it was being staged again', $actorLabel, $run->id);

                return ['error' => "Run {$run->id} for this proposal changed state while it was being staged again. Nothing was staged and nothing was sent."];
            }
        }

        $this->audit(self::STAGED, 'awaiting_approval', (int) $client->id, $ticket, $hash, self::requestLine($wire).': MCP staged huntress_request'.($request['reseller'] ? ' (billing change)' : '').($request['account_wide'] ? ' (account-wide)' : '').': '.$reason, $actorLabel, $run->id);

        return ['success' => true, 'ticket_id' => $ticket->id, 'ticket_display_id' => $ticket->display_id, 'run_id' => $run->id,
            'method' => $request['method'], 'path' => $request['path'], 'query' => $request['query'], 'body' => $request['body'],
            'account_wide' => $request['account_wide'], 'billing_change' => $request['reseller'],
            'message' => 'Held for cockpit approval: every Huntress POST, PATCH and DELETE is approved by a person first. Nothing was sent.'];
    }

    /**
     * The approver's card: what kind of change, then the request exactly as it
     * will be sent, then what approval re-checks: the mapping, and each read
     * assess() makes again (the key's account, and each object read first,
     * named with where its id came from: path, query or body). The body, the objects read first and the agent's reason are
     * inside UNTRUSTED fences. The method, the path (ids are digits) and the
     * query string are printed unfenced on the "Request as it will be sent"
     * line, so agent-chosen query values appear there, percent-encoded; they
     * have passed displayRefusal() (no control, format or fence-altering text).
     *
     * @param  array<string, mixed>  $request
     */
    private function display(array $request, Client $client): string
    {
        $name = (string) $client->name;
        $lines = [];
        if ($request['reseller']) {
            $lines[] = 'BILLING CHANGE: this Huntress reseller request changes what the account is billed, for '.$request['subscription'].'.';
        }
        $stop = str_ends_with($name, '.') ? '' : '.';
        $lines[] = $request['account_wide']
            ? 'ACCOUNT-WIDE: this request names no Huntress organization, so it acts on the whole Huntress account, not only on '.$name.$stop
            : 'Scoped to the Huntress organization mapped to '.$name.$stop;
        if ($request['resolution']) {
            $lines[] = 'ESCALATION RESOLUTION: sent as huntress_resolve_escalation sends it, with the empty object {} as the body: no determination, scope, expiration_date, ip_address or revoke_and_disable_identities. With that body the vendor spec gives a simple resolve (direct) or a temporary one (dismiss); its default revoke_and_disable_identities = true applies only with determination unauthorized, which is not sent. The resolution_method Huntress reports is checked after the call: direct or dismiss pass; rule (attribute rules were created) or anything else is reported as a hard fault.';
        }
        $line = 'Request as it will be sent: '.$request['method'].' '.$request['path'];
        if ($request['query'] !== []) {
            $line .= '?'.http_build_query($request['query'], '', '&', PHP_QUERY_RFC3986);
        }
        $lines[] = $line;
        $lines[] = $this->fence->fence('REQUEST BODY', $request['body'] === null
            ? '(no body)'
            : ($request['body'] === [] ? '{}' : json_encode($request['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        foreach ($request['subjects'] as $subject) {
            $lines[] = $this->fence->fence('HUNTRESS '.strtoupper($subject['label']).' AS READ', json_encode($this->clean($subject['row']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $reads = [];
        if ($request['account_checked']) {
            $reads[] = 'the Huntress account this API key belongs to';
        }
        foreach ($request['subjects'] as $subject) {
            $reads[] = "the {$subject['label']} {$subject['id']} named in the {$subject['where']}";
        }
        $last = array_pop($reads);
        $lines[] = 'Approval re-checks the client mapping'
            .($last === null ? '' : ' and re-reads '.($reads === [] ? $last : implode(', ', $reads).' and '.$last))
            .' before sending.';

        return implode("\n", $lines);
    }

    /**
     * Approval: re-check the fence (mapping, account, objects named by id) and
     * send the stored request exactly, through the write client.
     */
    public function approveStagedRun(TechnicianRun $run, int $approverId): TechnicianApprovalResult
    {
        if (! self::isStagedActionType($run->action_type) || ! $run->claimForExecution()) {
            return new TechnicianApprovalResult('already_handled');
        }

        $sent = false;
        $refused = null;
        try {
            return $this->approveClaimed($run, $approverId, $sent, $refused);
        } catch (\Throwable $e) {
            if (! $sent) {
                // $sent is set just before the vendor call: this throw is from before
                // anything was sent, so the run is given back. It is audited first
                // (ruling item 7); a failing audit does not replace this throw.
                $this->safeApprovalAudit($run, $approverId, 'error', 'Approval failed before anything was sent ('.class_basename($e).'); the run was given back. Nothing was sent.');
                $run->releaseClaim();

                throw $e;
            }
            // The request was sent and handling its outcome threw: do not give the run
            // back for a second send. When Huntress's refusal (a 4xx other than 408)
            // had already been received, the request was not applied and the text says
            // so; otherwise what Huntress did is not known here. The run is closed as the
            // unknown-outcome arm closes it, and one audit row records it. Nothing on
            // this arm may throw out of it, the log line included.
            self::quietLog('handling the outcome of a sent Huntress request failed', ['run_id' => $run->id, 'exception' => $e::class]);
            $message = $refused !== null
                ? 'Huntress refused the request (HTTP '.$refused.'), so it was not applied, and handling that refusal failed ('.class_basename($e).'). The run is closed: stage a new proposal if the change is still wanted.'
                : 'The Huntress request was sent, and handling its outcome failed ('.class_basename($e).'); whether Huntress applied it is not known here. Do NOT re-approve: check it in Huntress.';
            $this->safeApprovalAudit($run, $approverId, 'error', $message);
            try {
                $closed = $run->advanceTo(TechnicianRunState::Done);
            } catch (\Throwable) {
                $closed = false;
            }

            return new TechnicianApprovalResult('executed_with_fault', message: $message.($closed ? '' : ' The run was not closed because this request no longer holds it; check the run.'));
        }
    }

    /**
     * The audit row for an approval that threw, built only from the run (its
     * unencrypted method and path, client, ticket and hash). Nothing here may
     * throw: a failing lookup or INSERT is logged and the outcome stands.
     */
    private function safeApprovalAudit(TechnicianRun $run, int $approverId, string $status, string $message): void
    {
        try {
            $params = is_array($run->proposed_meta['redacted_params'] ?? null) ? $run->proposed_meta['redacted_params'] : [];
            $this->audit(self::STAGED, $status, $run->client_id !== null ? (int) $run->client_id : null, Ticket::find((int) $run->ticket_id),
                (string) $run->content_hash, self::requestLine($params).': '.$message, $this->approverLabel($approverId), $run->id, $approverId);
        } catch (\Throwable $e) {
            self::quietLog('audit row failed for an approval that threw', ['run_id' => $run->id, 'result_status' => $status, 'exception' => $e::class]);
        }
    }

    /** A log line that cannot throw: used on the arms whose outcome must stand. */
    private static function quietLog(string $message, array $context): void
    {
        try {
            Log::error('[StaffHuntressRequestToolExecutor] '.$message, $context);
        } catch (\Throwable) {
        }
    }

    private function approveClaimed(TechnicianRun $run, int $approverId, bool &$sent, ?int &$refused): TechnicianApprovalResult
    {
        $payload = $this->decryptPayload($run);
        if ($payload === null) {
            $message = 'The held payload could not be read; deny this proposal and stage it again.';
            $this->audit(self::STAGED, 'blocked', $run->client_id !== null ? (int) $run->client_id : null, Ticket::find((int) $run->ticket_id), (string) $run->content_hash, 'huntress-request: Approval refused: '.$message, $this->approverLabel($approverId), $run->id, $approverId);
            $run->releaseClaim();

            return new TechnicianApprovalResult('gate_declined', message: $message.' Nothing was sent.');
        }
        $wire = [
            'method' => (string) ($payload['method'] ?? ''),
            'path' => (string) ($payload['path'] ?? ''),
            'query' => is_array($payload['query'] ?? null) ? $payload['query'] : [],
            'body' => is_array($payload['body'] ?? null) ? $payload['body'] : null,
        ];
        $hash = (string) $run->content_hash;
        $approver = $this->approverLabel($approverId);
        $client = Client::find((int) ($payload['client_id'] ?? 0));
        $ticket = Ticket::automationVisible()->find((int) ($payload['ticket_id'] ?? 0));
        $clientId = $client ? (int) $client->id : null;
        $decline = function (string $status, string $message, bool $sent = false) use ($run, $clientId, $ticket, $hash, $wire, $approver, $approverId): TechnicianApprovalResult {
            $summary = self::requestLine($wire).': Approval refused: '.$message;
            $sent
                ? $this->safeAudit($status, $clientId, $ticket, $hash, $summary, $approver, $run->id, $approverId)
                : $this->audit(self::STAGED, $status, $clientId, $ticket, $hash, $summary, $approver, $run->id, $approverId);
            $run->releaseClaim();

            return new TechnicianApprovalResult('gate_declined', message: $message.($sent ? '' : ' Nothing was sent.'));
        };

        if (! $client || (int) $client->id !== (int) $run->client_id || ! $ticket || (int) $ticket->client_id !== (int) $client->id) {
            return $decline('blocked', 'The client or ticket no longer matches this proposal.');
        }
        if (! in_array($wire['method'], self::WRITE_METHODS, true)) {
            return $decline('blocked', 'The held proposal is not a POST, PATCH or DELETE.');
        }
        if (! HuntressConfig::isEnabled() || ! HuntressConfig::isConfigured()) {
            return $decline('blocked', 'Huntress is not enabled or its API key pair is not saved.');
        }
        if (TechnicianConfig::killSwitchEngaged()) {
            return $decline('blocked', 'Technician kill-switch engaged.');
        }
        $orgId = $this->positiveInt($client->huntress_organization_id);
        if ($orgId === null || $orgId !== ($payload['org_id'] ?? null)) {
            return $decline('blocked', "Client {$client->id} is no longer mapped to the Huntress organization this proposal was staged for.");
        }
        $request = $this->assess($wire['method'], $wire['path'], $wire['query'] === [] ? null : $wire['query'], $wire['body'], (int) $client->id, $orgId, true);
        if (is_string($request)) {
            return $decline('blocked', $request);
        }
        if ($request['query'] !== $wire['query'] || $request['body'] !== $wire['body']) {
            return $decline('blocked', 'The held request no longer reads back as staged.');
        }

        if ($request['resolution']) {
            $sent = true;

            return $this->approveResolution($run, $request, $wire, $clientId, $ticket, $hash, $approver, $approverId, $decline, $sent, $refused);
        }

        try {
            $sent = true;
            $this->writeClient->request($request['method'], $request['endpoint'], $request['query'], $request['body']);
        } catch (HuntressWriteScopeException) {
            $sent = false;

            return $decline('error', 'The Huntress request was refused before it was sent.');
        } catch (\Throwable $e) {
            if ($e instanceof HuntressClientException && ! self::mayHaveCommitted($e)) {
                $refused = (int) $e->getCode();

                return $decline('error', StaffHuntressRuleToolExecutor::withReason(
                    'Huntress refused the request (HTTP '.(int) $e->getCode().'): '.$wire['method'].' '.$wire['path'].', '.class_basename($e).'.', $e, true), true);
            }
            $fault = 'The Huntress request '.$wire['method'].' '.$wire['path'].' failed with an unknown outcome ('.self::failureDetail($e).'); it may have been applied. Do NOT re-approve: check it in Huntress.';
            $this->safeAudit('error', $clientId, $ticket, $hash, self::requestLine($wire).': '.$fault, $approver, $run->id, $approverId);
            try {
                $closed = $run->advanceTo(TechnicianRunState::Done);
            } catch (\Throwable) {
                $closed = false;
            }

            return new TechnicianApprovalResult('executed_with_fault', message: $fault.($closed ? '' : ' The run was not closed because this request no longer holds it; check the run.'));
        }

        try {
            $closed = $run->advanceTo(TechnicianRunState::Done);
        } catch (\Throwable $e) {
            Log::error('[StaffHuntressRequestToolExecutor] closing the run failed after a Huntress request', ['run_id' => $run->id, 'exception' => $e::class]);
            $closed = false;
        }
        $this->safeAudit('executed', $clientId, $ticket, $hash, self::requestLine($wire).': Operator-approved huntress_request executed', $approver, $run->id, $approverId);
        if (! $closed) {
            return TechnicianApprovalResult::executedNotClosed('The Huntress request '.$wire['method'], 'the result in Huntress');
        }

        return new TechnicianApprovalResult('executed', message: 'Huntress request '.$wire['method'].' '.$wire['path'].' sent.');
    }

    /**
     * POST /v1/escalations/{id}/resolution on the terms of huntress_resolve_escalation:
     * the literal {} through HuntressWriteClient::resolveEscalation(), then the
     * resolution_method post-condition (StaffHuntressActionToolExecutor::SAFE_RESOLUTION_METHODS).
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $wire
     */
    private function approveResolution(TechnicianRun $run, array $request, array $wire, ?int $clientId, ?Ticket $ticket, string $hash, string $approver, int $approverId, \Closure $decline, bool &$sent, ?int &$refused): TechnicianApprovalResult
    {
        $line = self::requestLine($wire);
        $close = function () use ($run): bool {
            try {
                return $run->advanceTo(TechnicianRunState::Done);
            } catch (\Throwable $e) {
                Log::error('[StaffHuntressRequestToolExecutor] closing the run failed after an escalation resolution', ['run_id' => $run->id, 'exception' => $e::class]);

                return false;
            }
        };
        try {
            $resolution = $this->writeClient->resolveEscalation((int) $request['escalation_id']);
        } catch (HuntressWriteScopeException) {
            $sent = false;

            return $decline('error', 'The Huntress request was refused before it was sent.');
        } catch (HuntressEscalationAlreadyResolvedException) {
            $this->safeAudit('executed', $clientId, $ticket, $hash, $line.': Huntress answered 409 already resolved; nothing changed.', $approver, $run->id, $approverId);
            $closed = $close();

            return new TechnicianApprovalResult('executed', message: 'Escalation '.$request['escalation_id'].' was already resolved in Huntress; nothing changed.'.($closed ? '' : ' The run was not closed because this request no longer holds it; check the run.'));
        } catch (\Throwable $e) {
            if ($e instanceof HuntressClientException && ! self::mayHaveCommitted($e)) {
                $refused = (int) $e->getCode();

                return $decline('error', StaffHuntressRuleToolExecutor::withReason(
                    'Huntress refused the request (HTTP '.(int) $e->getCode().'): '.$wire['method'].' '.$wire['path'].', '.class_basename($e).'.', $e, true), true);
            }
            $fault = 'HARD FAULT: the Huntress request '.$wire['method'].' '.$wire['path'].' failed with an unknown outcome ('.self::failureDetail($e).'); it may have resolved the escalation, and its resolution_method was never checked. Do NOT re-approve: check the escalation, and any attribute rules created, in Huntress.';
            $this->safeAudit('error', $clientId, $ticket, $hash, $line.': '.$fault, $approver, $run->id, $approverId);
            $closed = $close();

            return new TechnicianApprovalResult('executed_with_fault', message: $fault.($closed ? '' : ' The run was not closed because this request no longer holds it; check the run.'));
        }

        $closed = $close();
        $method = is_scalar($resolution['resolution_method'] ?? null) ? (string) $resolution['resolution_method'] : '(missing)';
        if (! in_array($method, StaffHuntressActionToolExecutor::SAFE_RESOLUTION_METHODS, true)) {
            $fault = 'HARD FAULT: Huntress reported resolution_method \''.mb_substr($method, 0, 40).'\' for escalation '.$request['escalation_id'].'; a resolve with the empty object must report direct or dismiss. rule means Huntress CREATED attribute rules. The escalation IS resolved: check the created rules in Huntress and escalate to a person.';
            Log::error('[StaffHuntressRequestToolExecutor] '.$fault, ['run_id' => $run->id]);
            $this->safeAudit('error', $clientId, $ticket, $hash, $line.': '.$fault, $approver, $run->id, $approverId);

            return new TechnicianApprovalResult('executed_with_fault', message: $fault.($closed ? '' : ' The run was not closed because this request no longer holds it; check the run.'));
        }
        $this->safeAudit('executed', $clientId, $ticket, $hash, $line.': Operator-approved huntress_request executed (resolution_method '.$method.')', $approver, $run->id, $approverId);
        if (! $closed) {
            return TechnicianApprovalResult::executedNotClosed('The Huntress request '.$wire['method'], 'the result in Huntress');
        }

        return new TechnicianApprovalResult('executed', message: 'Huntress request '.$wire['method'].' '.$wire['path'].' sent; escalation resolved (resolution_method '.$method.').');
    }

    // ── helpers ──

    /**
     * A refusal for a value the approval card could not show as it is sent:
     * invalid UTF-8, a control or format character, or text the fence changes.
     */
    private function displayRefusal(mixed $value, string $at = ''): ?string
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $found = $this->displayRefusal($item, $at === '' ? (string) $key : $at.'.'.$key);
                if ($found !== null) {
                    return $found;
                }
                if (is_string($key) && ($k = $this->displayRefusal($key, $at)) !== null) {
                    return $k;
                }
            }

            return null;
        }
        if (! is_string($value)) {
            return null;
        }
        if (! mb_check_encoding($value, 'UTF-8')) {
            return "{$at} is not valid UTF-8.";
        }
        if (preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            return "{$at} contains a control or format character; the approval card could not show it as sent.";
        }
        if ($this->fence->neutralizeUntrusted($value) !== $value) {
            return "{$at} would be shown on the approval card differently from how it would be sent, so it is refused.";
        }

        return null;
    }

    /** "METHOD path" for audit rows, from the caller's raw arguments or a stored request. */
    private static function requestLine(array $arguments): string
    {
        $method = is_string($arguments['method'] ?? null) ? strtoupper(mb_substr($arguments['method'], 0, 10)) : '?';
        $path = is_string($arguments['path'] ?? null) ? mb_substr($arguments['path'], 0, 200) : '?';

        return 'huntress-request:'.$method.' '.$path;
    }

    private static function failureText(string $method, string $path, \Throwable $e): string
    {
        $text = "Huntress {$method} {$path} failed: ".self::failureDetail($e).'.';

        return $e instanceof HuntressClientException ? StaffHuntressRuleToolExecutor::withReason($text, $e, false) : $text;
    }

    /** C-56: HTTP status and exception class only. */
    private static function failureDetail(\Throwable $e): string
    {
        if (! $e instanceof HuntressClientException) {
            return class_basename($e);
        }
        $previous = $e->getPrevious();
        $status = $previous instanceof RequestException && $previous->getResponse() !== null
            ? $previous->getResponse()->getStatusCode()
            : (int) $e->getCode();

        return 'Huntress HTTP '.($status > 0 ? $status : 'no response').': '.class_basename($e);
    }

    private static function mayHaveCommitted(HuntressClientException $e): bool
    {
        $status = (int) $e->getCode();

        return ! ($status >= 400 && $status < 500 && $status !== 408);
    }

    private function ticketFor(int $clientId, mixed $value): Ticket|string
    {
        $id = $this->positiveInt($value);
        if ($id === null) {
            return 'ticket_id is required for POST, PATCH and DELETE (they are held on a ticket) and must be a positive integer.';
        }
        $ticket = Ticket::automationVisible()->find($id);
        if (! $ticket || (int) $ticket->client_id !== $clientId) {
            return 'Ticket not found or belongs to a different client.';
        }

        return $ticket;
    }

    private function runSlot(int $ticketId, string $baseHash): ?string
    {
        for ($generation = 0; $generation < self::MAX_RUN_GENERATIONS; $generation++) {
            $hash = $generation === 0 ? $baseHash : hash('sha256', $baseHash.':'.$generation);
            $existing = TechnicianRun::query()->where('ticket_id', $ticketId)->where('action_type', self::STAGED)->where('content_hash', $hash)->first();
            if ($existing === null || $existing->state !== TechnicianRunState::Done) {
                return $hash;
            }
        }

        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value > 0 ? (int) $value : null;
        }

        return null;
    }

    private function contentHash(int $clientId, ?int $ticketId, array $params): string
    {
        unset($params['reason'], $params['ticket_id']);
        ksort($params);

        return hash('sha256', json_encode(['tool' => self::STAGED, 'client_id' => $clientId, 'ticket_id' => $ticketId, 'params' => $params]));
    }

    private function decryptPayload(TechnicianRun $run): ?array
    {
        $ciphertext = $run->proposed_meta['encrypted_payload'] ?? null;
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }
        try {
            $payload = json_decode(Crypt::decryptString($ciphertext), true);
        } catch (DecryptException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    private function approverLabel(int $approverId): string
    {
        $user = User::find($approverId);

        return $user?->email ?? $user?->name ?? "approver:{$approverId}";
    }

    /** An audit row after the vendor call: a failing INSERT must not hide the outcome. */
    private function safeAudit(string $status, ?int $clientId, ?Ticket $ticket, string $hash, string $summary, string $actor, ?int $runId = null, ?int $approverId = null): void
    {
        try {
            $this->audit(self::STAGED, $status, $clientId, $ticket, $hash, $summary, $actor, $runId, $approverId);
        } catch (\Throwable $e) {
            Log::error('[StaffHuntressRequestToolExecutor] audit row failed after a Huntress request', ['run_id' => $runId, 'result_status' => $status, 'exception' => $e::class]);
        }
    }

    private function audit(string $type, string $status, ?int $clientId, ?Ticket $ticket, string $hash, string $summary, string $actor, ?int $runId = null, ?int $approverId = null): void
    {
        if ($clientId !== null && ! Client::whereKey($clientId)->exists()) {
            $clientId = null;
        }

        TechnicianActionLog::create([
            'actor_id' => TechnicianConfig::aiActorUserId(),
            'approver_user_id' => $approverId,
            'actor_label' => $actor,
            'action_type' => $type,
            'tier' => TechnicianTier::Approve->value,
            'result_status' => $status,
            'ticket_id' => $ticket?->id,
            'client_id' => $clientId,
            'run_id' => $runId,
            'content_hash' => $hash,
            'summary' => mb_substr($this->redactor->redactString($summary), 0, 1000),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
