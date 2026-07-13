<?php

namespace Tests\Feature\Cipp;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Ticket;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\HandlesCippTools;
use App\Services\Triage\TriageToolDefinitions;
use App\Services\Triage\TriageToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Guards the psa-202 consolidation: the auto-triage loop (TriageToolExecutor) and
 * the inline assistant chat (AssistantToolExecutor) now dispatch the same CIPP tool
 * surface through the shared HandlesCippTools trait, so a tool or fix added in one
 * place can no longer silently miss the other.
 */
class CippToolDispatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every CIPP tool defined once in TriageToolDefinitions::cippTools() must be
     * DISPATCHED by both executors. The original bug surfaced as
     * "Unknown tool: cipp_list_user_mfa_methods" from the assistant after a tool
     * was added to triage + the definitions but not the assistant's match block.
     *
     * A client with no CIPP tenant mapping makes each handler short-circuit before
     * any HTTP call, so we can assert "reached the handler" (never the "Unknown
     * tool" default arm) without touching the network.
     */
    public function test_both_executors_dispatch_every_shared_cipp_tool(): void
    {
        $client = Client::factory()->create(['cipp_tenant_domain' => null]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        $executors = [
            'triage' => new TriageToolExecutor($ticket),
            'assistant' => new AssistantToolExecutor(null, $client->id, null),
        ];

        $cippTools = array_column(TriageToolDefinitions::cippTools(), 'name');
        $this->assertNotEmpty($cippTools, 'cippTools() should define the CIPP surface');

        foreach ($cippTools as $tool) {
            foreach ($executors as $label => $executor) {
                $result = $executor->execute($tool, []);

                $this->assertIsArray($result, "{$label} returned a non-array for {$tool}");
                $this->assertStringNotContainsString(
                    'Unknown tool',
                    (string) ($result['error'] ?? ''),
                    "{$label} did not dispatch {$tool} — dispatch drift regression"
                );
            }
        }
    }

    /** Both executors must draw the CIPP bodies from the single shared trait. */
    public function test_both_executors_use_the_shared_cipp_trait(): void
    {
        $this->assertContains(HandlesCippTools::class, class_uses(TriageToolExecutor::class));
        $this->assertContains(HandlesCippTools::class, class_uses(AssistantToolExecutor::class));
    }

    /**
     * cipp_list_mailbox_rules promises ONE user's inbox rules, and the DIRECT
     * (non-relay) path must keep that promise (psa-7lgo.1).
     *
     * It used to call api/ListMailboxRules, whose only CIPP parameters are
     * tenantFilter and UseReportDB. It takes no user parameter at all, so the
     * userId we sent was silently discarded and CIPP returned EVERY mailbox's
     * cached rules in the tenant — one user's compromise investigation answered
     * with every other user's inbox rules. CIPP does not error on an unknown
     * query parameter, so a user-scoped request quietly became a tenant-wide one
     * with nothing to notice.
     *
     * This path is not a corner case: the triage executor ALWAYS takes it (it has
     * no MCP relay), and the assistant falls back to it whenever the CIPP MCP
     * relay is disabled or unconfigured.
     */
    public function test_both_executors_scope_mailbox_rules_to_the_requested_user(): void
    {
        $client = Client::factory()->create(['cipp_tenant_domain' => 'contoso.onmicrosoft.com']);
        $objectId = '11111111-1111-1111-1111-111111111111';
        Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Alice',
            'last_name' => 'Example',
            'email' => 'alice@contoso.com',
            'cipp_upn' => 'alice@contoso.com',
            'cipp_user_id' => $objectId,
            'is_active' => true,
        ]);

        $cipp = Mockery::mock(CippClient::class);
        // The tenant-wide endpoint must never be reached again, by either executor.
        $cipp->shouldNotReceive('get')->with('api/ListMailboxRules', Mockery::any());
        $cipp->shouldReceive('get')
            ->twice()
            ->with('api/ListUserMailboxRules', Mockery::on(
                fn (array $query): bool => ($query['UserID'] ?? null) === $objectId
                    && ($query['userEmail'] ?? null) === 'alice@contoso.com'
                    && ($query['TenantFilter'] ?? null) === 'contoso.onmicrosoft.com'
            ))
            ->andReturn([]);
        $this->app->instance(CippClient::class, $cipp);

        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $executors = [
            'triage' => new TriageToolExecutor($ticket),
            'assistant' => new AssistantToolExecutor(null, $client->id, null),
        ];

        foreach ($executors as $label => $executor) {
            $result = $executor->execute('cipp_list_mailbox_rules', ['user_id' => 'alice@contoso.com']);

            $this->assertIsArray($result, "{$label} returned a non-array");
            $this->assertArrayNotHasKey('error', $result, "{$label}: ".(string) ($result['error'] ?? ''));
        }
    }

    /**
     * Shared behaviour: cipp_list_audit_logs must translate a UPN to its Azure AD
     * object ID before filtering the returned events, because CIPP keys the events
     * by object ID. Filtering on the raw UPN alone drops every row. Both executors
     * must behave identically here — previously only the assistant carried the fix.
     */
    public function test_audit_logs_filter_resolves_upn_to_object_id_for_both_executors(): void
    {
        $client = Client::factory()->create(['cipp_tenant_domain' => 'contoso.onmicrosoft.com']);
        $objectId = '11111111-1111-1111-1111-111111111111';
        Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Alice',
            'last_name' => 'Example',
            'email' => 'alice@contoso.com',
            'cipp_upn' => 'alice@contoso.com',
            'cipp_user_id' => $objectId,
            'is_active' => true,
        ]);

        $events = [
            ['UserId' => $objectId, 'Operation' => 'UserLoggedIn'],
            ['UserId' => '99999999-9999-9999-9999-999999999999', 'Operation' => 'Other'],
        ];

        $cipp = Mockery::mock(CippClient::class);
        $cipp->shouldReceive('get')
            ->with('api/ListAuditLogs', Mockery::type('array'))
            ->andReturn($events);
        $this->app->instance(CippClient::class, $cipp);

        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $executors = [
            'triage' => new TriageToolExecutor($ticket),
            'assistant' => new AssistantToolExecutor(null, $client->id, null),
        ];

        foreach ($executors as $label => $executor) {
            $result = $executor->execute('cipp_list_audit_logs', ['user_id' => 'alice@contoso.com']);

            $this->assertSame(1, $result['count'], "{$label} did not resolve UPN→objectID for the audit-log filter");
            $this->assertSame($objectId, $result['events'][0]['UserId'] ?? null, "{$label} kept the wrong event");
        }
    }
}
