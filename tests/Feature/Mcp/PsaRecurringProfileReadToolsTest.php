<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\RecurringInvoiceProfile;
use App\Models\RecurringInvoiceProfileLine;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Recurring billing profile READS — list_recurring_profiles, get_recurring_profile,
 * preview_recurring_invoice. Requested by Chet 2026-08-31 behind Charlie's ask to
 * review every recurring profile before the automatic billing run; before this,
 * nothing in the MCP catalog reached RecurringInvoiceProfile at all, so the only
 * way to review a profile was through the invoices it had already generated —
 * which is blind to exactly the case the review is for: a profile edited this month
 * that has not billed yet.
 *
 * *** STAFF-CLASS AND CROSS-CLIENT, AND THE TIER IS ASSERTED BY MEASUREMENT. ***
 * These sit in psa_read alongside list_invoices/get_invoice, NOT on the
 * client-scoped tier: the due-run sweep cannot be answered by a client-fenced tool,
 * and unit_cost_override is internal cost data. #935 is why the tier is measured
 * off McpToolRegistry::groups() rather than trusted from where the entry was typed.
 *
 * Everything here is READ-ONLY. preview_recurring_invoice runs the generator's own
 * pricing path and must persist nothing — no invoice, no advanced run date.
 */
class PsaRecurringProfileReadToolsTest extends TestCase
{
    use RefreshDatabase;

    private const TOOLS = ['list_recurring_profiles', 'get_recurring_profile', 'preview_recurring_invoice'];

    /** @param  array<int, string>  $tools */
    private function token(array $tools, string $label = 'billing-review'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
    }

    private function legacyToken(): string
    {
        return McpConfig::rotateStaffToken();
    }

    /** @param  array<string, mixed>  $arguments */
    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function tools(string $token): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->json('result.tools') ?? [];
    }

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    private function contract(Client $client, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'client_id' => $client->id,
            'name' => 'Managed Services Agreement',
            'type' => 'managed',
            'status' => 'active',
            'billing_source' => 'psa',
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 15,
            'start_date' => now()->subYear()->toDateString(),
        ], $overrides));
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function profile(Contract $contract, array $overrides = [], array $lines = []): RecurringInvoiceProfile
    {
        $profile = RecurringInvoiceProfile::create(array_merge([
            'contract_id' => $contract->id,
            'name' => 'Monthly Managed',
            'is_active' => true,
            'billing_period' => 'monthly',
            'billing_day' => 1,
            'payment_terms_days' => 15,
            'next_run_date' => today()->toDateString(),
            'last_run_date' => today()->subMonth()->toDateString(),
        ], $overrides));

        foreach ($lines as $i => $line) {
            RecurringInvoiceProfileLine::create(array_merge([
                'profile_id' => $profile->id,
                'description' => 'Managed Workstation',
                'unit_price' => 95.00,
                'quantity_type' => 'fixed',
                'fixed_quantity' => 3,
                'is_taxable' => true,
                'sort_order' => $i,
            ], $line));
        }

        return $profile->fresh(['lines']);
    }

    // ── registry / dormancy / TIER ─────────────────────────────────────────

    public function test_registry_lists_the_reads_in_psa_read_and_they_ship_dormant(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('psa_read', $groups);
        $this->assertTrue($groups['psa_read']['sensitive'], 'recurring billing profiles are client financial data');

        $names = array_column($groups['psa_read']['tools'], 'name');
        foreach (self::TOOLS as $name) {
            $this->assertContains($name, $names);
        }

        // SHIPS DORMANT: a legacy (no-grant) full-surface token cannot see them.
        $legacyNames = collect($this->tools($this->legacyToken()))->pluck('name')->all();
        foreach (self::TOOLS as $name) {
            $this->assertNotContains($name, $legacyNames);
        }

        $granted = collect($this->tools($this->token(self::TOOLS)))->keyBy('name');
        foreach (self::TOOLS as $name) {
            $this->assertTrue($granted->has($name));
        }

        // Cross-client by default: client_id is never required on the sweeping tools.
        $this->assertNotContains('client_id', $granted['list_recurring_profiles']['inputSchema']['required'] ?? []);
        $this->assertNotContains('client_id', $granted['preview_recurring_invoice']['inputSchema']['required'] ?? []);
        $this->assertContains('profile_id', $granted['get_recurring_profile']['inputSchema']['required']);
    }

    /**
     * #935: verify_device_absent landed on the CLIENT-SCOPED tier by accident, and
     * nothing caught it because the registry entry looked staff-class where it was
     * typed. The tier a tool actually lands on is a measurement.
     */
    public function test_the_reads_are_not_on_the_client_scoped_tier(): void
    {
        $clientTierNames = array_column(McpToolRegistry::groups()['client']['tools'], 'name');

        foreach (self::TOOLS as $name) {
            $this->assertNotContains(
                $name,
                $clientTierNames,
                "{$name} must not reach the client-scoped tier — the due-run sweep is cross-client and unit_cost_override is internal cost data",
            );
        }
    }

    /**
     * A grant has to be LEGIBLE (Charlie's standing requirement behind the invoice
     * reads): the description names the internal cost fields outright rather than
     * hiding them behind "financial details", and says plainly that the reach is
     * cross-client.
     */
    public function test_descriptions_name_the_internal_cost_fields_and_the_cross_client_reach(): void
    {
        $granted = collect($this->tools($this->token(self::TOOLS)))->keyBy('name');

        $detail = mb_strtolower($granted['get_recurring_profile']['description']);
        $this->assertStringContainsString('unit_cost_override', $detail);
        $this->assertStringContainsString('internal cost data', $detail);

        foreach (['list_recurring_profiles', 'preview_recurring_invoice'] as $name) {
            $this->assertStringContainsString('cross-client', mb_strtolower($granted[$name]['description']));
        }

        // The one thing a caller must not get wrong on the preview.
        $this->assertStringContainsString('exactly one', mb_strtolower($granted['preview_recurring_invoice']['description']));
    }

    // ── scope ──────────────────────────────────────────────────────────────

    public function test_list_is_cross_client_when_client_id_omitted_and_scoped_when_provided(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $profileA = $this->profile($this->contract($clientA));
        $profileB = $this->profile($this->contract($clientB));

        $token = $this->token(['list_recurring_profiles']);

        $all = $this->decodedResult($this->callTool($token, 'list_recurring_profiles', []));
        $ids = collect($all['profiles'])->pluck('id')->all();
        $this->assertContains($profileA->id, $ids);
        $this->assertContains($profileB->id, $ids);

        $scoped = $this->decodedResult($this->callTool($token, 'list_recurring_profiles', ['client_id' => $clientA->id]));
        $scopedIds = collect($scoped['profiles'])->pluck('id')->all();
        $this->assertContains($profileA->id, $scopedIds);
        $this->assertNotContains($profileB->id, $scopedIds);
    }

    /**
     * A profile outside the caller's scope answers with the SAME wording as one
     * that does not exist, so a fenced caller cannot tell the two apart and probe
     * for other clients' profile ids.
     */
    public function test_get_refuses_another_clients_profile_with_an_indistinguishable_not_found(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $profileB = $this->profile($this->contract($clientB));

        $token = $this->token(['get_recurring_profile']);

        $crossClient = $this->decodedResult($this->callTool($token, 'get_recurring_profile', [
            'client_id' => $clientA->id,
            'profile_id' => $profileB->id,
        ]));
        $absent = $this->decodedResult($this->callTool($token, 'get_recurring_profile', [
            'client_id' => $clientA->id,
            'profile_id' => 987654,
        ]));

        $this->assertSame('Recurring profile not found', $crossClient['error'] ?? null);
        $this->assertSame($absent['error'] ?? null, $crossClient['error'] ?? null);
    }

    // ── the due predicate is the generator's own ───────────────────────────

    public function test_due_only_matches_the_generators_own_due_scope_exactly(): void
    {
        $client = Client::factory()->create();
        $activeContract = $this->contract($client);
        // Not billed through the PSA-side generator at all.
        $expiredContract = $this->contract($client, ['status' => 'expired']);

        $due = $this->profile($activeContract, ['name' => 'Due now', 'next_run_date' => today()->toDateString()]);
        $future = $this->profile($activeContract, ['name' => 'Not yet', 'next_run_date' => today()->addWeek()->toDateString()]);
        $inactive = $this->profile($activeContract, ['name' => 'Paused', 'is_active' => false, 'next_run_date' => today()->subDay()->toDateString()]);
        $offContract = $this->profile($expiredContract, ['name' => 'Expired contract', 'next_run_date' => today()->subDay()->toDateString()]);

        $token = $this->token(['list_recurring_profiles']);
        $result = $this->decodedResult($this->callTool($token, 'list_recurring_profiles', ['due_only' => true]));

        $ids = collect($result['profiles'])->pluck('id')->sort()->values()->all();
        $expected = RecurringInvoiceProfile::due()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame($expected, $ids, 'due_only must return exactly RecurringInvoiceProfile::due()');
        $this->assertContains($due->id, $ids);
        foreach ([$future->id, $inactive->id, $offContract->id] as $excluded) {
            $this->assertNotContains($excluded, $ids);
        }

        // is_due on an unfiltered listing carries the same verdict.
        $unfiltered = $this->decodedResult($this->callTool($token, 'list_recurring_profiles', []));
        $byId = collect($unfiltered['profiles'])->keyBy('id');
        $this->assertTrue($byId[$due->id]['is_due']);
        $this->assertFalse($byId[$future->id]['is_due']);
    }

    // ── the blind spot the request exists to close ─────────────────────────

    /**
     * *** THE POINT OF THE REQUEST. *** An edit to a profile LINE does not touch
     * the profile's own updated_at, so a reviewer comparing last_run_date against
     * the profile timestamp alone would score an edited-but-unbilled profile as
     * unchanged since its last invoice — the exact class of problem Charlie asked
     * to have caught before the run.
     */
    public function test_a_line_edit_after_the_last_run_is_visible_via_lines_updated_at(): void
    {
        $client = Client::factory()->create();
        $profile = $this->profile(
            $this->contract($client),
            ['last_run_date' => today()->subMonth()->toDateString()],
            [['description' => 'Managed Workstation']],
        );

        // Age the profile header past the last run, then edit only a line.
        $profile->forceFill(['updated_at' => today()->subMonths(2)])->saveQuietly();
        $line = $profile->lines()->first();
        $line->forceFill(['unit_price' => 105.00, 'updated_at' => today()->subDays(3)])->save();

        $result = $this->decodedResult($this->callTool(
            $this->token(['list_recurring_profiles']),
            'list_recurring_profiles',
            ['client_id' => $client->id],
        ));

        $row = collect($result['profiles'])->firstWhere('id', $profile->id);

        $this->assertNotNull($row['lines_updated_at']);
        $this->assertTrue(
            \Carbon\Carbon::parse($row['lines_updated_at'])->gt(\Carbon\Carbon::parse($row['updated_at'])),
            'a line edit must surface even though it leaves the profile header untouched',
        );
        $this->assertTrue(
            \Carbon\Carbon::parse($row['lines_updated_at'])->gt(\Carbon\Carbon::parse($row['last_run_date'])),
            'the edit landed after the last generated invoice and has therefore not billed yet',
        );
    }

    // ── detail ─────────────────────────────────────────────────────────────

    public function test_get_returns_the_internal_rate_cards_and_the_line_shape(): void
    {
        $client = Client::factory()->create();
        $profile = $this->profile(
            $this->contract($client),
            ['skip_zero_invoices' => true, 'auto_push_mode' => 'push'],
            [[
                'description' => 'Managed Workstation',
                'unit_price' => 95.00,
                'unit_cost_override' => 32.00,
                'prepaid_time_override' => 30,
                'pricing_tiers' => [['up_to' => 10, 'unit_price' => 95.00], ['up_to' => null, 'unit_price' => 80.00]],
            ]],
        );

        $result = $this->decodedResult($this->callTool(
            $this->token(['get_recurring_profile']),
            'get_recurring_profile',
            ['profile_id' => $profile->id],
        ));

        $this->assertSame($profile->id, $result['id']);
        $this->assertSame($client->id, $result['client_id']);
        $this->assertTrue($result['skip_zero_invoices']);
        $this->assertTrue($result['effective_skip_zero_invoices']);
        $this->assertSame('push', $result['auto_push_mode']);

        $line = $result['lines'][0];
        $this->assertEquals(32.00, $line['unit_cost_override']);
        $this->assertSame(30, $line['prepaid_time_override']);
        $this->assertTrue($line['is_tiered']);
        $this->assertCount(2, $line['pricing_tiers']);
        $this->assertNotNull($line['updated_at']);
    }

    /**
     * A NULL skip_zero_invoices means "inherit the system setting", which is not
     * the same answer as false. Returning only a boolean would make a reviewer
     * believe the profile had been configured when it had not.
     */
    public function test_an_inherited_skip_zero_setting_is_reported_as_null_not_false(): void
    {
        $client = Client::factory()->create();
        $profile = $this->profile($this->contract($client), ['skip_zero_invoices' => null]);

        $result = $this->decodedResult($this->callTool(
            $this->token(['get_recurring_profile']),
            'get_recurring_profile',
            ['profile_id' => $profile->id],
        ));

        $this->assertNull($result['skip_zero_invoices']);
        $this->assertArrayHasKey('effective_skip_zero_invoices', $result);
        $this->assertIsBool($result['effective_skip_zero_invoices']);
    }

    // ── preview: one profile ───────────────────────────────────────────────

    public function test_preview_by_id_prices_the_profile_and_persists_nothing(): void
    {
        $client = Client::factory()->create();
        $profile = $this->profile(
            $this->contract($client),
            ['next_run_date' => today()->toDateString()],
            [['description' => 'Managed Workstation', 'unit_price' => 95.00, 'fixed_quantity' => 3]],
        );

        $result = $this->decodedResult($this->callTool(
            $this->token(['preview_recurring_invoice']),
            'preview_recurring_invoice',
            ['profile_id' => $profile->id],
        ));

        $this->assertEqualsWithDelta(285.00, $result['subtotal'], 0.001);
        $this->assertFalse($result['would_skip']);
        $this->assertCount(1, $result['lines']);
        $this->assertArrayHasKey('quantity_source', $result['lines'][0]);

        // READ-ONLY: no invoice, and the run date has not moved.
        $this->assertSame(0, Invoice::count());
        $this->assertSame(
            $profile->next_run_date->toDateString(),
            $profile->fresh()->next_run_date->toDateString(),
        );
    }

    /**
     * previewInvoice() dereferences $profile->contract->client unguarded, and both
     * models soft-delete. A profile orphaned by a deleted contract must come back
     * as a named refusal — it is a real finding for a pre-run review — and must not
     * turn the whole call into a 500.
     */
    public function test_a_profile_orphaned_by_a_deleted_contract_errors_by_name_rather_than_crashing(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client);
        $profile = $this->profile($contract, [], [['description' => 'Managed Workstation']]);

        $contract->delete();

        $response = $this->callTool(
            $this->token(['preview_recurring_invoice']),
            'preview_recurring_invoice',
            ['profile_id' => $profile->id],
        );

        $response->assertOk();
        $result = $this->decodedResult($response);
        $this->assertStringContainsString('no contract', $result['error'] ?? '');
        $this->assertStringContainsString((string) $profile->id, $result['error'] ?? '');
    }

    // ── preview: the whole-run sweep ───────────────────────────────────────

    public function test_due_only_sweep_previews_exactly_the_run_and_excludes_would_skip_from_the_billable_total(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client);

        $billing = $this->profile(
            $contract,
            ['name' => 'Bills', 'next_run_date' => today()->toDateString()],
            [['description' => 'Managed Workstation', 'unit_price' => 95.00, 'fixed_quantity' => 3]],
        );
        // No lines + skip-zero on => renders, but produces no invoice on the run.
        $skipping = $this->profile($contract, [
            'name' => 'Skips',
            'next_run_date' => today()->toDateString(),
            'skip_zero_invoices' => true,
        ]);
        $notDue = $this->profile($contract, ['name' => 'Later', 'next_run_date' => today()->addWeek()->toDateString()]);

        $result = $this->decodedResult($this->callTool(
            $this->token(['preview_recurring_invoice']),
            'preview_recurring_invoice',
            ['due_only' => true],
        ));

        $previewed = collect($result['previews'])->pluck('profile_id')->sort()->values()->all();
        $expected = RecurringInvoiceProfile::due()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame($expected, $previewed, 'the sweep must cover exactly the profiles billing:generate would');
        $this->assertNotContains($notDue->id, $previewed);
        $this->assertSame(2, $result['due_total']);
        $this->assertFalse($result['truncated']);
        $this->assertSame(1, $result['would_skip_count']);

        // Only the profile that will actually invoice counts toward the run total.
        $this->assertEqualsWithDelta(285.00, $result['billable_subtotal'], 0.001);
        $this->assertTrue(collect($result['previews'])->firstWhere('profile_id', $skipping->id)['would_skip']);
        $this->assertFalse(collect($result['previews'])->firstWhere('profile_id', $billing->id)['would_skip']);

        $this->assertSame(0, Invoice::count());
    }

    /**
     * A truncated billing-run preview is a WRONG answer to "what bills tomorrow",
     * not a shorter one. due_total is counted independently of the rows rendered,
     * and the shortfall is announced rather than left to be inferred.
     */
    public function test_a_truncated_sweep_announces_itself_and_says_the_total_is_partial(): void
    {
        $client = Client::factory()->create();
        $contract = $this->contract($client);
        foreach (range(1, 3) as $i) {
            $this->profile(
                $contract,
                ['name' => "Profile {$i}", 'next_run_date' => today()->toDateString()],
                [['description' => 'Managed Workstation', 'unit_price' => 95.00, 'fixed_quantity' => 1]],
            );
        }

        $result = $this->decodedResult($this->callTool(
            $this->token(['preview_recurring_invoice']),
            'preview_recurring_invoice',
            ['due_only' => true, 'limit' => 2],
        ));

        $this->assertSame(3, $result['due_total']);
        $this->assertSame(2, $result['previewed']);
        $this->assertTrue($result['truncated']);
        $this->assertStringContainsString('NOT the total of the billing run', $result['warning'] ?? '');
    }

    // ── argument discipline ────────────────────────────────────────────────

    public function test_preview_refuses_both_shapes_and_refuses_neither(): void
    {
        $client = Client::factory()->create();
        $profile = $this->profile($this->contract($client));
        $token = $this->token(['preview_recurring_invoice']);

        $neither = $this->decodedResult($this->callTool($token, 'preview_recurring_invoice', []));
        $this->assertStringContainsString('profile_id', $neither['error'] ?? '');
        $this->assertStringContainsString('due_only', $neither['error'] ?? '');

        $both = $this->decodedResult($this->callTool($token, 'preview_recurring_invoice', [
            'profile_id' => $profile->id,
            'due_only' => true,
        ]));
        $this->assertStringContainsString('not both', $both['error'] ?? '');
    }

    /**
     * A caller sending due_only as the STRING "false" — which several MCP clients
     * do — must not be handed a cross-client sweep of the whole billing run.
     * PHP's (bool) cast reads the non-empty string "false" as true, so the flag is
     * parsed rather than cast.
     */
    public function test_the_string_false_does_not_trigger_a_whole_run_sweep(): void
    {
        $client = Client::factory()->create();
        $this->profile(
            $this->contract($client),
            ['next_run_date' => today()->toDateString()],
            [['description' => 'Managed Workstation']],
        );

        $result = $this->decodedResult($this->callTool(
            $this->token(['preview_recurring_invoice']),
            'preview_recurring_invoice',
            ['due_only' => 'false'],
        ));

        // Falls through to "neither shape chosen", not to a sweep.
        $this->assertArrayNotHasKey('previews', $result);
        $this->assertStringContainsString('due_only=true', $result['error'] ?? '');
    }

    public function test_a_malformed_profile_id_errors_rather_than_reading_profile_zero(): void
    {
        $token = $this->token(['get_recurring_profile']);

        foreach (['0', '-3', 'abc'] as $bad) {
            $result = $this->decodedResult($this->callTool($token, 'get_recurring_profile', ['profile_id' => $bad]));
            $this->assertStringContainsString('positive integer', $result['error'] ?? '', "profile_id '{$bad}' must be refused");
        }
    }

    // ── the reads stay reads ───────────────────────────────────────────────

    public function test_a_grant_for_one_read_does_not_carry_the_others(): void
    {
        $client = Client::factory()->create();
        $profile = $this->profile($this->contract($client));

        $token = $this->token(['list_recurring_profiles']);

        $denied = $this->callTool($token, 'get_recurring_profile', ['profile_id' => $profile->id]);
        $this->assertTrue($denied->json('result.isError'));

        $allowed = $this->callTool($token, 'list_recurring_profiles', []);
        $this->assertFalse($allowed->json('result.isError'));
    }
}
