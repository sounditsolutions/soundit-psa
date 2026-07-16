<?php

namespace Tests\Unit\Cipp;

use App\Models\Client;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Services\Cipp\CippMcpClient;
use App\Services\Cipp\CippMcpToolRelay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * cipp_list_tenant_mailbox_rules — the tenant-wide inbox-rule sweep (psa-4k6m).
 *
 * EVERY FIXTURE BELOW IS COPIED FROM THE VENDOR'S OWN PRODUCER, NOT FROM THE SHAPE
 * OUR PROJECTION WANTS. That is psa-7lgo rule 2, and it is load-bearing here: the
 * two shapes that make this endpoint dangerous are shapes no author would invent
 * when mocking their own code.
 *
 * Producer chain, read 2026-07-16 (re-verify there, do not infer):
 *   Invoke-ListMailboxRules.ps1 — reads the `cachembxrules` table (1-HOUR TTL), does
 *     NOT call Exchange. Healthy branch: `$NewObj = $_.Rules | ConvertFrom-Json` then
 *     adds `Tenant`. Queue branches (NEITHER of them AllTenants-gated) return
 *     `Results = @()` with `Metadata.QueueMessage`.
 *   Push-ListMailboxRulesQueue.ps1 — the true producer of the `Rules` column, and it
 *     writes THREE different shapes under one column:
 *       real rules  -> `Rules = [string]($Rule | ConvertTo-Json)`  (raw Get-InboxRule,
 *                      so Exchange PascalCase)
 *       no rules    -> `$Rules = @(@{ Name = 'No rules found' }) | ConvertTo-Json`
 *       UNREACHABLE -> `$Rules = @{ Name = "Could not connect to tenant $($_.Exception.message)" } | ConvertTo-Json`
 *
 * The last one is why this test file exists: a tenant we could not reach comes back
 * HTTP 200 as a single row that projects to a perfectly ordinary-looking inbox rule.
 * Unguarded, "we could not scan this tenant" reads to an agent as "one benign rule,
 * nothing forwarding externally" — a false all-clear on the canonical BEC persistence
 * mechanism, which is the exact failure psa-7lgo rule 3 forbids.
 */
class CippTenantMailboxRulesTest extends TestCase
{
    use RefreshDatabase;

    private function relay(array $upstreamRows): CippMcpToolRelay
    {
        $mcp = Mockery::mock(CippMcpClient::class);
        $mcp->shouldReceive('callTool')->once()->andReturn($upstreamRows);

        return new CippMcpToolRelay($mcp, app(ChetDataSurfaceTextSanitizer::class));
    }

    private function acme(): Client
    {
        return Client::create([
            'name' => 'Acme',
            'cipp_tenant_domain' => 'acme.example',
        ]);
    }

    private function execute(CippMcpToolRelay $relay): array
    {
        return $relay->execute('cipp_list_tenant_mailbox_rules', [], $this->acme(), null);
    }

    public function test_the_tenant_wide_tool_relays_to_list_mailbox_rules_not_the_per_user_endpoint(): void
    {
        $mcp = Mockery::mock(CippMcpClient::class);
        $mcp->shouldReceive('callTool')
            ->once()
            // ListMailboxRules takes tenantFilter and UseReportDB and NOTHING else —
            // it has no user parameter at all. The per-mailbox sibling
            // cipp_list_mailbox_rules maps to ListUserMailboxRules instead.
            ->with('ListMailboxRules', Mockery::any())
            ->andReturn([]);

        $relay = new CippMcpToolRelay($mcp, app(ChetDataSurfaceTextSanitizer::class));

        $relay->execute('cipp_list_tenant_mailbox_rules', [], $this->acme(), null);
    }

    public function test_a_real_rule_projects_its_exchange_pascal_case_fields(): void
    {
        // Raw Get-InboxRule output as ConvertTo-Json would emit it. PascalCase
        // throughout — CIPP caches the raw object and hands it straight back.
        $result = $this->execute($this->relay([[
            'Identity' => 'attacker@acme.example\\16983452431237',
            'MailboxOwnerId' => 'attacker@acme.example',
            'Name' => 'zz',
            'Enabled' => true,
            'Priority' => 1,
            'ForwardTo' => ['evil@external.example'],
            'DeleteMessage' => true,
            'MoveToFolder' => 'RSS Subscriptions',
            'Tenant' => 'acme.example',
        ]]));

        $this->assertCount(1, $result);
        $rule = $result[0];

        // The malicious-rule signal must survive projection: forward-to-external +
        // delete + hide-in-an-obscure-folder is the classic BEC persistence combo.
        // Rule names and folder names are attacker-CONTROLLED tenant text, so they
        // arrive fenced by the untrusted-text sanitizer rather than raw — assert both
        // that the value survived and that the fence is there, since the fence is the
        // prompt-injection defence and a regression that dropped it would be silent.
        $this->assertStringContainsString('zz', $rule['name']);
        $this->assertStringContainsString('UNTRUSTED', $rule['name']);
        $this->assertStringContainsString('evil@external.example', $rule['forwardTo'][0]);
        $this->assertStringContainsString('RSS Subscriptions', $rule['moveToFolder']);

        // Booleans are structural, not text — they must NOT be stringified, or the
        // agent cannot reason about "deletes the message" at all.
        $this->assertTrue($rule['deleteMessage']);

        // Tenant-wide is useless without knowing WHOSE mailbox the rule sits on.
        // The cache path carries no UserPrincipalName (only the report-DB path adds
        // one), so the owner must come off the raw Get-InboxRule object.
        $this->assertStringContainsString('attacker@acme.example', $rule['mailboxOwnerId']);
    }

    public function test_the_no_rules_found_sentinel_becomes_a_genuine_empty_result(): void
    {
        // Push-ListMailboxRulesQueue.ps1 stores a PHANTOM RULE when a tenant is clean:
        //     $Rules = @(@{ Name = 'No rules found' }) | ConvertTo-Json
        // That is a real all-clear and must be reported as an ABSENCE, not as a rule
        // named "No rules found" that an agent can count, quote, or reason about.
        $result = $this->execute($this->relay([[
            'Name' => 'No rules found',
            'Tenant' => 'acme.example',
        ]]));

        $this->assertSame([], $result);
    }

    public function test_the_could_not_connect_sentinel_hard_errors_and_never_reads_as_a_rule(): void
    {
        // *** THE ONE THAT MATTERS. ***
        // Push-ListMailboxRulesQueue.ps1 stores a tenant-connection FAILURE as a rule:
        //     $Rules = @{ Name = "Could not connect to tenant $($_.Exception.message)" }
        // HTTP 200. One row. It projects to an ordinary-looking inbox rule with no
        // forwarding and no delete — i.e. it reads as CLEAN. An agent cannot tell
        // "we never scanned this tenant" from "this tenant is fine". It must scream.
        $result = $this->execute($this->relay([[
            'Name' => 'Could not connect to tenant The remote server returned an error: (401) Unauthorized.',
            'Tenant' => 'acme.example',
        ]]));

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('could not', mb_strtolower($result['error']));
        // and it must NOT have leaked through as data
        $this->assertArrayNotHasKey('rules', $result);
    }

    public function test_the_error_sentinel_is_detected_among_real_rules_too(): void
    {
        // AllTenants/multi-mailbox aggregation can mix a failed tenant's sentinel in
        // with real rows. A partial scan reported as a complete one is still a false
        // all-clear for the tenant that failed.
        $result = $this->execute($this->relay([
            ['Name' => 'legit', 'Enabled' => true, 'MailboxOwnerId' => 'a@acme.example', 'Tenant' => 'acme.example'],
            ['Name' => 'Could not connect to tenant timeout', 'Tenant' => 'other.example'],
        ]));

        $this->assertArrayHasKey('error', $result);
    }
}
