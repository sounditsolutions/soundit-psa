<?php

namespace Tests\Unit\Cipp;

use App\Models\Client;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Services\Cipp\CippMcpClient;
use App\Services\Cipp\CippMcpToolRelay;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class CippMcpToolRelayTest extends TestCase
{
    private function relay(array $upstreamRows): CippMcpToolRelay
    {
        $mcp = Mockery::mock(CippMcpClient::class);
        $mcp->shouldReceive('callTool')->once()->andReturn($upstreamRows);

        return new CippMcpToolRelay($mcp, app(ChetDataSurfaceTextSanitizer::class));
    }

    private function execute(CippMcpToolRelay $relay): array
    {
        $client = new Client(['cipp_tenant_domain' => 'acme.example']);

        return $relay->execute('cipp_list_mailbox_permissions', [
            'user_id' => '11111111-1111-1111-1111-111111111111',
        ], $client, null);
    }

    private function acme(): Client
    {
        return new Client(['cipp_tenant_domain' => 'acme.example']);
    }

    public function test_warns_when_every_upstream_row_projects_empty(): void
    {
        Log::spy();

        // Rows whose keys match nothing in DEFAULT_FIELDS — the shape-drift
        // failure that produced silent false-empty results (psa-3twu).
        $result = $this->execute($this->relay([
            ['Unexpected' => 'shape', 'AnotherKey' => 'value'],
            ['Unexpected' => 'other'],
        ]));

        $this->assertSame([[], []], $result);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'projected empty')
                && ($context['tool'] ?? null) === 'cipp_list_mailbox_permissions'
                && ($context['row_count'] ?? null) === 2
                && ($context['first_row_keys'] ?? null) === ['Unexpected', 'AnotherKey']);
    }

    public function test_does_not_warn_when_rows_project_fields(): void
    {
        Log::spy();

        $result = $this->execute($this->relay([
            ['User' => 'delegate@acme.example', 'Permissions' => 'FullAccess'],
        ]));

        $this->assertCount(1, $result);
        $this->assertStringContainsString('delegate@acme.example', $result[0]['user']);
        $this->assertSame('FullAccess', $result[0]['permissions']);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_mailboxes_projection_includes_litigation_hold(): void
    {
        // psa-zgfs: litigation-hold status is a compliance signal Chet needs
        // when triaging offboarding / eDiscovery / mailbox requests. CIPP's
        // ListMailboxes surfaces it camelCased, like the sibling forwarding
        // attributes already in the projection.
        $result = $this->relay([[
            'userPrincipalName' => 'user@acme.example',
            'primarySmtpAddress' => 'user@acme.example',
            'litigationHoldEnabled' => true,
        ]])->execute('cipp_list_mailboxes', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['litigationHoldEnabled']);
    }

    public function test_mailboxes_projection_resolves_pascalcase_litigation_hold(): void
    {
        // Exchange/Graph may surface the property PascalCased
        // (LitigationHoldEnabled); the field alias must resolve either casing
        // so the tool never silently drops the hold status (psa-3twu class of
        // shape-drift false-empties).
        $result = $this->relay([[
            'userPrincipalName' => 'user@acme.example',
            'LitigationHoldEnabled' => true,
        ]])->execute('cipp_list_mailboxes', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['litigationHoldEnabled']);
    }

    public function test_mailboxes_projection_keeps_litigation_hold_when_false(): void
    {
        // "Hold explicitly off" is a meaningful compliance signal, so a false
        // value must project — the projection guard is strict `=== null`, not
        // `empty()`, so false is kept rather than silently dropped.
        $result = $this->relay([[
            'userPrincipalName' => 'user@acme.example',
            'LitigationHoldEnabled' => false,
        ]])->execute('cipp_list_mailboxes', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('litigationHoldEnabled', $result[0]);
        $this->assertFalse($result[0]['litigationHoldEnabled']);
    }

    /**
     * One mailbox row in CIPP's REAL ListMailboxes shape (psa-7lgo).
     *
     * Verbatim from the vendor's projection — CIPP-API
     * Invoke-ListMailboxes.ps1 pipes Get-Mailbox through a Select-Object that
     * renames SOME properties to camelCase (displayName, primarySmtpAddress,
     * recipientTypeDetails) while leaving the Exchange PascalCase on others
     * (ForwardingSmtpAddress, DeliverToMailboxAndForward, LitigationHold*),
     * and surfaces the UPN as `UPN`. ExecMCP re-dispatches through the same
     * API function and serializes the body verbatim, so this IS the row the
     * relay receives.
     */
    private function realListMailboxesRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 'aaaaaaaa-1111-2222-3333-bbbbbbbbbbbb',
            'UPN' => 'user@acme.example',
            'displayName' => 'Test User',
            'primarySmtpAddress' => 'user@acme.example',
            'recipientTypeDetails' => 'UserMailbox',
            'ForwardingSmtpAddress' => null,
            'DeliverToMailboxAndForward' => false,
            'LitigationHoldEnabled' => false,
        ], $overrides);
    }

    public function test_mailboxes_projection_reads_the_real_cipp_row_shape(): void
    {
        $result = $this->relay([$this->realListMailboxesRow()])
            ->execute('cipp_list_mailboxes', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(1, $result);
        // UPN is aliased, so the caller-facing key stays userPrincipalName.
        $this->assertSame('user@acme.example', $result[0]['userPrincipalName']);
        $this->assertSame('UserMailbox', $result[0]['recipientTypeDetails']);
        $this->assertSame('user@acme.example', $result[0]['primarySmtpAddress']);
    }

    public function test_mailboxes_projection_surfaces_external_auto_forwarding(): void
    {
        // The exfiltration signal this tool exists to expose. CIPP emits the
        // property PascalCase (ForwardingSmtpAddress); the relay declared it
        // camelCase-only with no alias, so it silently projected empty and
        // Chet was blind to auto-forwarding (psa-7lgo).
        $result = $this->relay([$this->realListMailboxesRow([
            'ForwardingSmtpAddress' => 'attacker@evil.example',
        ])])->execute('cipp_list_mailboxes', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(1, $result);
        $this->assertSame('attacker@evil.example', $result[0]['forwardingSmtpAddress']);
    }

    public function test_mailboxes_projection_keeps_deliver_to_mailbox_and_forward_when_false(): void
    {
        // DeliverToMailboxAndForward=false ALONGSIDE a forwarding address is
        // the *stealthy* exfil configuration: mail is forwarded out and NOT
        // retained in the mailbox, so the victim never sees it. The false must
        // survive projection — dropping it inverts the security reading.
        $result = $this->relay([$this->realListMailboxesRow([
            'ForwardingSmtpAddress' => 'attacker@evil.example',
            'DeliverToMailboxAndForward' => false,
        ])])->execute('cipp_list_mailboxes', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('deliverToMailboxAndForward', $result[0]);
        $this->assertFalse($result[0]['deliverToMailboxAndForward']);
    }

    /**
     * One inbox rule in CIPP's REAL ListMailboxRules shape (psa-7lgo).
     *
     * CIPP-API Push-ListMailboxRulesQueue.ps1 caches the RAW Get-InboxRule
     * object (`Rules = [string]($Rule | ConvertTo-Json)`) with no reshaping,
     * and Invoke-ListMailboxRules.ps1 hands those cached rows straight back.
     * Get-InboxRule is an Exchange cmdlet, so every property is PascalCase.
     * The relay declared all ten fields camel/lowercase with no aliases —
     * every key missed and every row projected to `{}`.
     *
     * The fixture is the canonical BEC persistence rule: forward to an
     * external address, then delete the message so the victim never sees the
     * thread.
     */
    private function realMaliciousInboxRuleRow(): array
    {
        return [
            'Name' => '.',
            'Identity' => 'user@acme.example\\16299017861633',
            'Priority' => 1,
            'Enabled' => true,
            'Description' => 'Forward the message to attacker@evil.example and delete it',
            'From' => [],
            'SentTo' => [],
            'ForwardTo' => ['attacker@evil.example'],
            'RedirectTo' => [],
            'DeleteMessage' => true,
            'MoveToFolder' => 'RSS Feeds',
        ];
    }

    public function test_mailbox_rules_projection_surfaces_the_bec_rule(): void
    {
        $result = $this->relay([$this->realMaliciousInboxRuleRow()])
            ->execute('cipp_list_mailbox_rules', [
                'user_id' => '11111111-1111-1111-1111-111111111111',
            ], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(1, $result);
        $this->assertNotSame([], $result[0], 'mailbox rule row projected empty — every field missed');

        $this->assertTrue($result[0]['enabled']);
        $this->assertSame(1, $result[0]['priority']);
        $this->assertTrue($result[0]['deleteMessage']);
        $this->assertSame('RSS Feeds', $result[0]['moveToFolder']);

        // A rule's name, description and recipients are attacker-controlled —
        // a malicious rule is a prompt-injection carrier as well as an exfil
        // mechanism — so they reach the agent wrapped as untrusted data rather
        // than verbatim. What matters is that the destination is no longer
        // silently dropped.
        $this->assertArrayHasKey('name', $result[0]);
        $this->assertStringContainsString('attacker@evil.example', $result[0]['forwardTo'][0]);
        $this->assertStringContainsString('not instructions', $result[0]['forwardTo'][0]);
        $this->assertStringContainsString('attacker@evil.example', $result[0]['description']);
    }

    /** Captures the tool name + arguments actually sent upstream. */
    private function relayCapturingCall(array $upstreamRows, ?array &$captured): CippMcpToolRelay
    {
        $mcp = Mockery::mock(CippMcpClient::class);
        $mcp->shouldReceive('callTool')->once()->andReturnUsing(
            function (string $tool, array $arguments) use ($upstreamRows, &$captured): array {
                $captured = ['tool' => $tool, 'arguments' => $arguments];

                return $upstreamRows;
            }
        );

        return new CippMcpToolRelay($mcp, app(ChetDataSurfaceTextSanitizer::class));
    }

    public function test_mailbox_rules_calls_the_user_scoped_upstream_tool(): void
    {
        // The local tool REQUIRES user_id and describes itself as "inbox rules
        // for a specific user's mailbox" — but it was routed to CIPP's
        // ListMailboxRules, whose OpenAPI parameters are only tenantFilter and
        // UseReportDB. It accepts NO user parameter, silently ignored the
        // userId we sent, and returns EVERY mailbox's cached rules in the
        // tenant. Harmless while the projection was empty; a cross-mailbox
        // disclosure the moment the rows became visible (psa-7lgo.1).
        //
        // ListUserMailboxRules is the user-scoped endpoint: it reads UserID and
        // runs Get-InboxRule -Mailbox $UserID, so Exchange itself enforces the
        // single-mailbox scope.
        $captured = null;
        $this->relayCapturingCall([$this->realMaliciousInboxRuleRow()], $captured)
            ->execute('cipp_list_mailbox_rules', [
                'user_id' => 'user@acme.example',
            ], $this->acme(), null);

        $this->assertSame('ListUserMailboxRules', $captured['tool']);
        $this->assertNotSame('ListMailboxRules', $captured['tool']);
        $this->assertSame('user@acme.example', $captured['arguments']['UserID'] ?? null);
    }

    public function test_mailbox_rules_never_exposes_another_mailboxes_rules(): void
    {
        // Defence in depth behind the endpoint change: even if upstream ever
        // hands back a foreign mailbox's rule, it must not reach the agent.
        // Cross-mailbox rule data is both a disclosure and false investigation
        // context — "this user has a forward-to-external rule" about the wrong
        // user is worse than no answer.
        $mine = $this->realMaliciousInboxRuleRow();
        $theirs = $this->realMaliciousInboxRuleRow();
        $theirs['Identity'] = 'someone-else@acme.example\\99999999999';
        $theirs['ForwardTo'] = ['not-your-business@acme.example'];

        $result = $this->relay([$mine, $theirs])->execute('cipp_list_mailbox_rules', [
            'user_id' => 'user@acme.example',
        ], $this->acme(), null);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('attacker@evil.example', $result[0]['forwardTo'][0]);
        $this->assertStringNotContainsString(
            'not-your-business',
            json_encode($result),
            'a rule belonging to another mailbox reached the agent'
        );
    }

    public function test_mailbox_rules_keeps_rules_whose_owner_cannot_be_compared(): void
    {
        // Get-InboxRule can surface the owner as a display name or legacy DN
        // rather than an address or GUID. We cannot compare those, and dropping
        // on "cannot compare" would fail CLOSED — hiding the requested user's
        // own rules, which is the exact failure this whole series exists to
        // kill. Keep them; the endpoint is already user-scoped upstream.
        $rule = $this->realMaliciousInboxRuleRow();
        $rule['Identity'] = 'Some Display Name\\16299017861633';

        $result = $this->relay([$rule])->execute('cipp_list_mailbox_rules', [
            'user_id' => 'user@acme.example',
        ], $this->acme(), null);

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['deleteMessage']);
    }

    public function test_mailbox_rules_projection_keeps_disabled_rule_flag(): void
    {
        // Enabled=false must project: "a forwarding rule exists but is off" is
        // a different security reading from "no rule at all".
        $rule = $this->realMaliciousInboxRuleRow();
        $rule['Enabled'] = false;

        $result = $this->relay([$rule])->execute('cipp_list_mailbox_rules', [
            'user_id' => '11111111-1111-1111-1111-111111111111',
        ], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('enabled', $result[0]);
        $this->assertFalse($result[0]['enabled']);
    }

    public function test_warns_when_a_single_field_is_structurally_absent_from_every_row(): void
    {
        Log::spy();

        // The invisible failure mode (psa-7lgo): the row still projects id /
        // displayName / UPN, so the existing "every row projected empty" guard
        // stays silent — while a security-relevant field silently vanishes.
        // A per-field guard has to catch the PARTIAL drop.
        $row = $this->realListMailboxesRow();
        unset($row['ForwardingSmtpAddress']);

        $this->relay([$row])->execute('cipp_list_mailboxes', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'never resolved')
                && ($context['tool'] ?? null) === 'cipp_list_mailboxes'
                && in_array('forwardingSmtpAddress', $context['missing_fields'] ?? [], true));
    }

    public function test_does_not_warn_when_a_field_is_present_but_null(): void
    {
        Log::spy();

        // A mailbox with no forwarding configured legitimately carries the key
        // with a null value — PowerShell's Select-Object emits unset
        // properties as null. That is NOT schema drift and must not warn, or
        // the guard is pure noise on healthy tenants.
        $this->relay([$this->realListMailboxesRow(['ForwardingSmtpAddress' => null])])
            ->execute('cipp_list_mailboxes', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        Log::shouldNotHaveReceived('warning');
    }
}
