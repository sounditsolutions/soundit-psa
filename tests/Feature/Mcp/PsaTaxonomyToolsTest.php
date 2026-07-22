<?php

namespace Tests\Feature\Mcp;

use App\Enums\SopStatus;
use App\Models\McpAuditLog;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TicketCategory;
use App\Models\User;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Feature coverage for the so-0ftg ticket-taxonomy CRUD MCP tools (Chet's
 * secondary authoring path) in the dormant `taxonomy` group. Mirrors
 * PsaRecordsToolsTest conventions: real HTTP against /api/mcp/staff, explicit
 * per-tool grants, TechnicianActionLog + McpAuditLog assertions.
 */
class PsaTaxonomyToolsTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_TAXONOMY_TOOLS = [
        'list_ticket_categories',
        'get_ticket_category',
        'create_ticket_category',
        'update_ticket_category',
        'retire_ticket_category',
        'set_ticket_category_sop',
    ];

    private function token(array $tools, string $label = 'chet'): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: $label);
    }

    private function legacyToken(): string
    {
        return McpConfig::rotateStaffToken();
    }

    private function configureAiActor(): User
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

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

    /** Category > Subcategory > Item chain for tree-shape tests. @return array{0: TicketCategory, 1: TicketCategory, 2: TicketCategory} */
    private function seedChain(): array
    {
        $cat = TicketCategory::create(['name' => 'Security & EDR']);
        $sub = TicketCategory::create(['name' => 'Scareware', 'parent_id' => $cat->id]);
        $item = TicketCategory::create(['name' => 'Fake-AV popup', 'parent_id' => $sub->id]);

        return [$cat, $sub, $item];
    }

    public function test_registry_and_runtime_require_explicit_grants_for_taxonomy_tools(): void
    {
        $groups = McpToolRegistry::groups();
        $this->assertArrayHasKey('taxonomy', $groups);
        $this->assertTrue($groups['taxonomy']['sensitive']);
        $this->assertSame(self::ALL_TAXONOMY_TOOLS, array_column($groups['taxonomy']['tools'], 'name'));

        // Dormant by default: the legacy full-surface token cannot see them.
        $legacyNames = collect($this->tools($this->legacyToken()))->pluck('name')->all();
        foreach (self::ALL_TAXONOMY_TOOLS as $name) {
            $this->assertNotContains($name, $legacyNames);
        }

        // A granted token sees exactly what it was granted.
        $scoped = collect($this->tools($this->token(['list_ticket_categories', 'create_ticket_category'])))->keyBy('name');
        $this->assertTrue($scoped->has('list_ticket_categories'));
        $this->assertTrue($scoped->has('create_ticket_category'));
        $this->assertFalse($scoped->has('get_ticket_category'));
        $this->assertFalse($scoped->has('retire_ticket_category'));
        $this->assertFalse($scoped->has('set_ticket_category_sop'));

        // The taxonomy is global: no tool grows a client_id parameter on publish.
        foreach (['list_ticket_categories', 'create_ticket_category'] as $name) {
            $properties = (array) $scoped[$name]['inputSchema']['properties'];
            $this->assertArrayNotHasKey('client_id', $properties, "{$name} must not publish client_id");
        }
        $this->assertContains('name', $scoped['create_ticket_category']['inputSchema']['required']);
    }

    public function test_ungranted_token_cannot_call_taxonomy_tools(): void
    {
        $node = TicketCategory::create(['name' => 'Endpoint & Hardware']);

        $calls = [
            ['list_ticket_categories', []],
            ['get_ticket_category', ['category_id' => $node->id]],
            ['create_ticket_category', ['name' => 'Nope']],
            ['update_ticket_category', ['category_id' => $node->id, 'name' => 'Nope']],
            ['retire_ticket_category', ['category_id' => $node->id, 'confirm_category_name' => $node->name]],
            ['set_ticket_category_sop', ['category_id' => $node->id, 'sop_text' => 'nope']],
        ];

        // A scoped token granting a different sensitive tool AND the legacy
        // full-surface token are both denied — grant-gated, never by default.
        foreach ([$this->token(['create_ticket']), $this->legacyToken()] as $token) {
            foreach ($calls as [$tool, $arguments]) {
                $response = $this->callTool($token, $tool, $arguments);
                $response->assertOk();
                $this->assertTrue((bool) $response->json('result.isError'), "{$tool} should be denied.");
                $this->assertStringContainsString('not allowed for this token', (string) $response->json('result.content.0.text'));
            }
        }

        $this->assertSame(1, TicketCategory::query()->count());
        $this->assertSame('Endpoint & Hardware', $node->fresh()->name);
        $this->assertTrue($node->fresh()->is_active);
        $this->assertSame(0, TechnicianActionLog::query()->count());
    }

    public function test_taxonomy_tools_reject_a_supplied_client_id(): void
    {
        $node = TicketCategory::create(['name' => 'Backup & DR']);
        $token = $this->token(self::ALL_TAXONOMY_TOOLS);

        $response = $this->callTool($token, 'list_ticket_categories', ['client_id' => 7]);
        $response->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));
        $this->assertStringContainsString('taxonomy is global', (string) $response->json('result.content.0.text'));

        $write = $this->callTool($token, 'set_ticket_category_sop', [
            'category_id' => $node->id,
            'client_id' => 7,
            'sop_text' => 'Scoped? No.',
        ]);
        $write->assertOk();
        $this->assertTrue((bool) $write->json('result.isError'));
        $this->assertStringContainsString('client_id must be omitted', (string) $write->json('result.content.0.text'));
        $this->assertNull($node->fresh()->sop_text);
    }

    public function test_list_filters_and_never_returns_sop_text(): void
    {
        [$cat, $sub, $item] = $this->seedChain();
        $sub->update(['sop_text' => "# Scareware SOP\nIsolate first.", 'sop_status' => SopStatus::Reviewed]);
        $retired = TicketCategory::create(['name' => 'Legacy Fax', 'is_active' => false]);
        $stale = TicketCategory::create(['name' => 'Vendor & Third-Party']);
        DB::table('ticket_categories')->where('id', $stale->id)->update(['updated_at' => now()->subDays(60)]);

        $token = $this->token(['list_ticket_categories']);

        // Default: active only, flat rows with tree context, no SOP bodies anywhere.
        $result = $this->decodedResult($this->callTool($token, 'list_ticket_categories', []));
        $names = array_column($result['categories'], 'name');
        $this->assertContains('Security & EDR', $names);
        $this->assertContains('Fake-AV popup', $names);
        $this->assertNotContains('Legacy Fax', $names);
        $this->assertFalse($result['truncated']);
        $this->assertSame(4, $result['total']);

        $rows = collect($result['categories'])->keyBy('name');
        $this->assertSame(3, $rows['Fake-AV popup']['depth']);
        $this->assertSame('Security & EDR / Scareware / Fake-AV popup', $rows['Fake-AV popup']['path']);
        $this->assertTrue($rows['Scareware']['has_sop']);
        $this->assertSame('reviewed', $rows['Scareware']['sop_status']);
        foreach ($result['categories'] as $row) {
            $this->assertArrayNotHasKey('sop_text', $row, 'list rows must never carry the SOP body');
        }

        // Coverage-gap filter (sop_status=none) excludes the reviewed node.
        $gaps = $this->decodedResult($this->callTool($token, 'list_ticket_categories', ['sop_status' => 'none']));
        $this->assertNotContains('Scareware', array_column($gaps['categories'], 'name'));
        $this->assertContains('Security & EDR', array_column($gaps['categories'], 'name'));

        // Name search, direct children, staleness, and retired visibility.
        $search = $this->decodedResult($this->callTool($token, 'list_ticket_categories', ['search' => 'scare']));
        $this->assertSame(['Scareware'], array_column($search['categories'], 'name'));

        $children = $this->decodedResult($this->callTool($token, 'list_ticket_categories', ['parent_id' => $cat->id]));
        $this->assertSame(['Scareware'], array_column($children['categories'], 'name'));

        $staleRows = $this->decodedResult($this->callTool($token, 'list_ticket_categories', ['stale_days' => 30]));
        $this->assertSame(['Vendor & Third-Party'], array_column($staleRows['categories'], 'name'));

        $all = $this->decodedResult($this->callTool($token, 'list_ticket_categories', ['include_inactive' => true]));
        $this->assertContains('Legacy Fax', array_column($all['categories'], 'name'));

        // A cut-off list is never mistaken for the whole tree.
        $limited = $this->decodedResult($this->callTool($token, 'list_ticket_categories', ['limit' => 2]));
        $this->assertSame(2, $limited['count']);
        $this->assertSame(4, $limited['total']);
        $this->assertTrue($limited['truncated']);
    }

    public function test_get_returns_full_sop_and_tree_context(): void
    {
        [$cat, $sub, $item] = $this->seedChain();
        $editor = User::factory()->create(['name' => 'Chet Actor']);
        $sopText = "# Scareware\n\n1. Isolate the endpoint.\n2. Never call the popup number.";
        $sub->update(['sop_text' => $sopText, 'sop_status' => SopStatus::Draft, 'updated_by' => $editor->id]);

        $token = $this->token(['get_ticket_category']);
        $result = $this->decodedResult($this->callTool($token, 'get_ticket_category', ['category_id' => $sub->id]));

        $this->assertSame('Scareware', $result['name']);
        $this->assertSame($sopText, $result['sop_text']);
        $this->assertSame('draft', $result['sop_status']);
        $this->assertSame(2, $result['depth']);
        $this->assertSame('Security & EDR / Scareware', $result['path']);
        $this->assertSame([['id' => $cat->id, 'name' => 'Security & EDR']], $result['ancestors']);
        $this->assertFalse($result['is_leaf']);
        $this->assertTrue($result['has_sop']);
        $this->assertSame('Chet Actor', $result['updated_by']);
        $this->assertSame(0, $result['tickets_count']);
        $this->assertNotNull($result['updated_at']);
        $this->assertSame([['id' => $item->id, 'name' => 'Fake-AV popup', 'sop_status' => 'none', 'has_sop' => false, 'is_active' => true]], $result['children']);

        $missing = $this->callTool($token, 'get_ticket_category', ['category_id' => 999999]);
        $this->assertTrue((bool) $missing->json('result.isError'));
        $this->assertStringContainsString('not found', (string) $missing->json('result.content.0.text'));
    }

    public function test_granted_token_creates_category_with_audit_and_ai_actor(): void
    {
        $actor = $this->configureAiActor();
        $parent = TicketCategory::create(['name' => 'Email & M365 Tenant']);
        $token = $this->token(['create_ticket_category']);
        $sopText = "# Quarantine release\nCheck the verdict before releasing.";

        $response = $this->callTool($token, 'create_ticket_category', [
            'name' => 'Email security & quarantine',
            'parent_id' => $parent->id,
            'description' => 'Mesh quarantine and filtering issues.',
            'record_type_hint' => 'mixed',
            'sop_text' => $sopText,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        $result = $this->decodedResult($response);
        $node = TicketCategory::findOrFail($result['category_id']);
        $this->assertSame('Email security & quarantine', $node->name);
        $this->assertSame($parent->id, $node->parent_id);
        $this->assertSame($sopText, $node->sop_text);
        // Text with no explicit status is a draft — the gap view stays truthful.
        $this->assertSame(SopStatus::Draft, $node->sop_status);
        $this->assertSame($actor->id, $node->updated_by);
        $this->assertSame('Email & M365 Tenant / Email security & quarantine', $result['path']);

        $log = TechnicianActionLog::where('action_type', 'create_ticket_category')->firstOrFail();
        $this->assertSame('executed', $log->result_status);
        $this->assertNull($log->ticket_id);
        $this->assertNull($log->client_id);
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame('mcp-staff:chet', $log->actor_label);
        $this->assertStringContainsString('[ticket_category#'.$node->id.']', (string) $log->summary);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $log->content_hash);

        // mcp_audit_logs redaction: SOP and description bodies reduce to lengths.
        $audit = McpAuditLog::where('tool_name', 'create_ticket_category')->firstOrFail();
        $this->assertSame('success', $audit->status);
        $this->assertSame('Email security & quarantine', $audit->arguments['name']);
        $this->assertSame(mb_strlen($sopText), $audit->arguments['sop_text_length']);
        $this->assertArrayNotHasKey('sop_text', $audit->arguments);
        $this->assertArrayNotHasKey('description', $audit->arguments);
        $this->assertStringNotContainsString('Quarantine release', (string) json_encode($audit->arguments));
    }

    public function test_create_enforces_depth_cap_duplicates_and_sop_status_coupling(): void
    {
        $this->configureAiActor();
        [$cat, $sub, $item] = $this->seedChain();
        $token = $this->token(['create_ticket_category']);

        // Depth is capped at 3: an Item/Symptom node cannot have children.
        $deep = $this->callTool($token, 'create_ticket_category', ['name' => 'Too deep', 'parent_id' => $item->id]);
        $this->assertTrue((bool) $deep->json('result.isError'));
        $this->assertStringContainsString('depth is capped at 3', (string) $deep->json('result.content.0.text'));

        // Duplicate sibling names are refused case-insensitively.
        $duplicate = $this->callTool($token, 'create_ticket_category', ['name' => 'SCAREWARE', 'parent_id' => $cat->id]);
        $this->assertTrue((bool) $duplicate->json('result.isError'));
        $this->assertStringContainsString('already exists', (string) $duplicate->json('result.content.0.text'));

        // A retired same-named sibling should be reactivated, not shadowed.
        TicketCategory::create(['name' => 'Phishing & BEC', 'parent_id' => $cat->id, 'is_active' => false]);
        $shadow = $this->callTool($token, 'create_ticket_category', ['name' => 'Phishing & BEC', 'parent_id' => $cat->id]);
        $this->assertTrue((bool) $shadow->json('result.isError'));
        $this->assertStringContainsString('Reactivate it', (string) $shadow->json('result.content.0.text'));

        // A retired parent refuses new children.
        $retired = TicketCategory::create(['name' => 'Retired Parent', 'is_active' => false]);
        $underRetired = $this->callTool($token, 'create_ticket_category', ['name' => 'Orphan', 'parent_id' => $retired->id]);
        $this->assertTrue((bool) $underRetired->json('result.isError'));
        $this->assertStringContainsString('retired', (string) $underRetired->json('result.content.0.text'));

        // A status hint on an empty SOP would hide a coverage gap.
        $statusOnly = $this->callTool($token, 'create_ticket_category', ['name' => 'No Text', 'sop_status' => 'reviewed']);
        $this->assertTrue((bool) $statusOnly->json('result.isError'));
        $this->assertStringContainsString('requires SOP text', (string) $statusOnly->json('result.content.0.text'));

        // Non-allowlisted fields are refused outright.
        $sneaky = $this->callTool($token, 'create_ticket_category', ['name' => 'Sneaky', 'is_active' => false]);
        $this->assertTrue((bool) $sneaky->json('result.isError'));

        $this->assertSame(5, TicketCategory::query()->count(), 'only the two seeded-by-hand extras were added');
    }

    public function test_update_renames_reparents_and_validates_the_tree(): void
    {
        $this->configureAiActor();
        [$cat, $sub, $item] = $this->seedChain();
        $other = TicketCategory::create(['name' => 'OS & Software']);
        $token = $this->token(['update_ticket_category']);

        // Rename + metadata edit, audited.
        $rename = $this->callTool($token, 'update_ticket_category', [
            'category_id' => $sub->id,
            'name' => 'Scareware & Tech-Support Scam',
            'record_type_hint' => 'incident',
            'sort_order' => 5,
        ]);
        $this->assertFalse((bool) $rename->json('result.isError'), (string) $rename->json('result.content.0.text'));
        $sub->refresh();
        $this->assertSame('Scareware & Tech-Support Scam', $sub->name);
        $this->assertSame(5, $sub->sort_order);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'update_ticket_category',
            'result_status' => 'executed',
            'ticket_id' => null,
            'client_id' => null,
            'actor_label' => 'mcp-staff:chet',
        ]);

        // Reparent a leaf under another root: allowed.
        $move = $this->callTool($token, 'update_ticket_category', ['category_id' => $item->id, 'parent_id' => $other->id]);
        $this->assertFalse((bool) $move->json('result.isError'), (string) $move->json('result.content.0.text'));
        $this->assertSame($other->id, $item->fresh()->parent_id);

        // Cycle refusal: a node cannot move under its own descendant.
        $cycle = $this->callTool($token, 'update_ticket_category', ['category_id' => $cat->id, 'parent_id' => $sub->id]);
        $this->assertTrue((bool) $cycle->json('result.isError'));
        $this->assertStringContainsString('own descendant', (string) $cycle->json('result.content.0.text'));

        // Depth refusal accounts for the whole subtree: cat (height 2, child sub
        // remains) cannot move under a depth-2 node.
        $childOfOther = TicketCategory::create(['name' => 'Windows OS', 'parent_id' => $other->id]);
        $tooDeep = $this->callTool($token, 'update_ticket_category', ['category_id' => $cat->id, 'parent_id' => $childOfOther->id]);
        $this->assertTrue((bool) $tooDeep->json('result.isError'));
        $this->assertStringContainsString('depth is capped at 3', (string) $tooDeep->json('result.content.0.text'));

        // Reparent to null makes the node a top-level category.
        $toRoot = $this->callTool($token, 'update_ticket_category', ['category_id' => $item->id, 'parent_id' => null]);
        $this->assertFalse((bool) $toRoot->json('result.isError'), (string) $toRoot->json('result.content.0.text'));
        $this->assertNull($item->fresh()->parent_id);
        $this->assertSame(1, $item->fresh()->depth());
    }

    public function test_update_rejects_sop_fields_deactivation_and_reactivates_safely(): void
    {
        $this->configureAiActor();
        $parent = TicketCategory::create(['name' => 'Network & Connectivity', 'is_active' => false]);
        $child = TicketCategory::create(['name' => 'DNS / domain', 'parent_id' => $parent->id, 'is_active' => false]);
        $token = $this->token(['update_ticket_category']);

        // SOP content belongs to set_ticket_category_sop.
        $sop = $this->callTool($token, 'update_ticket_category', ['category_id' => $child->id, 'sop_text' => 'nope']);
        $this->assertTrue((bool) $sop->json('result.isError'));
        $this->assertStringContainsString('set_ticket_category_sop', (string) $sop->json('result.content.0.text'));

        // Deactivation belongs to retire_ticket_category (typed confirm).
        $off = $this->callTool($token, 'update_ticket_category', ['category_id' => $child->id, 'is_active' => false]);
        $this->assertTrue((bool) $off->json('result.isError'));
        $this->assertStringContainsString('retire_ticket_category', (string) $off->json('result.content.0.text'));

        // Reactivating under a retired parent is refused; reactivating the
        // parent first, then the child, works.
        $childFirst = $this->callTool($token, 'update_ticket_category', ['category_id' => $child->id, 'is_active' => true]);
        $this->assertTrue((bool) $childFirst->json('result.isError'));
        $this->assertStringContainsString('parent category', (string) $childFirst->json('result.content.0.text'));

        $parentOn = $this->callTool($token, 'update_ticket_category', ['category_id' => $parent->id, 'is_active' => true]);
        $this->assertFalse((bool) $parentOn->json('result.isError'), (string) $parentOn->json('result.content.0.text'));
        $childOn = $this->callTool($token, 'update_ticket_category', ['category_id' => $child->id, 'is_active' => true]);
        $this->assertFalse((bool) $childOn->json('result.isError'), (string) $childOn->json('result.content.0.text'));
        $this->assertTrue($child->fresh()->is_active);
    }

    public function test_retire_requires_typed_confirm_blocks_active_children_and_audits(): void
    {
        $actor = $this->configureAiActor();
        [$cat, $sub, $item] = $this->seedChain();
        $token = $this->token(['retire_ticket_category']);

        // Wrong typed confirmation → refused.
        $wrong = $this->callTool($token, 'retire_ticket_category', [
            'category_id' => $item->id,
            'confirm_category_name' => 'Wrong Name',
        ]);
        $this->assertTrue((bool) $wrong->json('result.isError'));
        $this->assertStringContainsString('confirm_category_name', (string) $wrong->json('result.content.0.text'));
        $this->assertTrue($item->fresh()->is_active);

        // Active children block retirement — bottom-up only.
        $withChildren = $this->callTool($token, 'retire_ticket_category', [
            'category_id' => $sub->id,
            'confirm_category_name' => 'Scareware',
        ]);
        $this->assertTrue((bool) $withChildren->json('result.isError'));
        $this->assertStringContainsString('active child', (string) $withChildren->json('result.content.0.text'));
        $this->assertTrue($sub->fresh()->is_active);

        // Leaf retires with the exact name (case-insensitive), audited.
        $ok = $this->callTool($token, 'retire_ticket_category', [
            'category_id' => $item->id,
            'confirm_category_name' => 'fake-av POPUP',
            'reason' => 'Merged into the parent SOP.',
        ]);
        $this->assertFalse((bool) $ok->json('result.isError'), (string) $ok->json('result.content.0.text'));
        $item->refresh();
        $this->assertFalse($item->is_active);
        $this->assertSame($actor->id, $item->updated_by);

        $log = TechnicianActionLog::where('action_type', 'retire_ticket_category')->firstOrFail();
        $this->assertNull($log->client_id);
        $this->assertStringContainsString('Merged into the parent SOP.', (string) $log->summary);

        // Retiring again is an honest error, not a silent no-op.
        $again = $this->callTool($token, 'retire_ticket_category', [
            'category_id' => $item->id,
            'confirm_category_name' => 'Fake-AV popup',
        ]);
        $this->assertTrue((bool) $again->json('result.isError'));
        $this->assertStringContainsString('already retired', (string) $again->json('result.content.0.text'));
    }

    public function test_set_sop_writes_defaults_and_redacts_the_body(): void
    {
        $actor = $this->configureAiActor();
        $node = TicketCategory::create(['name' => 'Identity & Access']);
        $token = $this->token(['set_ticket_category_sop']);
        $sopText = "# Password reset\nVerify the caller before resetting.";

        // Text on a status-none node defaults to draft.
        $write = $this->callTool($token, 'set_ticket_category_sop', ['category_id' => $node->id, 'sop_text' => $sopText]);
        $this->assertFalse((bool) $write->json('result.isError'), (string) $write->json('result.content.0.text'));
        $node->refresh();
        $this->assertSame($sopText, $node->sop_text);
        $this->assertSame(SopStatus::Draft, $node->sop_status);
        $this->assertSame($actor->id, $node->updated_by);
        $result = $this->decodedResult($write);
        $this->assertTrue($result['has_sop']);
        $this->assertNotNull($result['updated_at']);

        // An in-place correction keeps an explicit reviewed hint.
        $promote = $this->callTool($token, 'set_ticket_category_sop', ['category_id' => $node->id, 'sop_status' => 'reviewed']);
        $this->assertFalse((bool) $promote->json('result.isError'), (string) $promote->json('result.content.0.text'));
        $edit = $this->callTool($token, 'set_ticket_category_sop', ['category_id' => $node->id, 'sop_text' => $sopText."\nAlways log the ticket."]);
        $this->assertFalse((bool) $edit->json('result.isError'), (string) $edit->json('result.content.0.text'));
        $this->assertSame(SopStatus::Reviewed, $node->fresh()->sop_status);

        // draft/reviewed with no resulting text is refused.
        $contradiction = $this->callTool($token, 'set_ticket_category_sop', [
            'category_id' => $node->id,
            'sop_text' => '',
            'sop_status' => 'reviewed',
        ]);
        $this->assertTrue((bool) $contradiction->json('result.isError'));
        $this->assertStringContainsString('requires SOP text', (string) $contradiction->json('result.content.0.text'));

        // Clearing the text resets the node to a visible coverage gap.
        $clear = $this->callTool($token, 'set_ticket_category_sop', ['category_id' => $node->id, 'sop_text' => '']);
        $this->assertFalse((bool) $clear->json('result.isError'), (string) $clear->json('result.content.0.text'));
        $node->refresh();
        $this->assertNull($node->sop_text);
        $this->assertSame(SopStatus::None, $node->sop_status);

        // The SOP body never lands in the MCP audit log — length only.
        $audit = McpAuditLog::where('tool_name', 'set_ticket_category_sop')->first();
        $this->assertNotNull($audit);
        $this->assertArrayNotHasKey('sop_text', $audit->arguments);
        $this->assertSame(mb_strlen($sopText), $audit->arguments['sop_text_length']);
        $this->assertStringNotContainsString('Verify the caller', (string) json_encode($audit->arguments));

        // Neither text nor status is an error, not a silent no-op.
        $empty = $this->callTool($token, 'set_ticket_category_sop', ['category_id' => $node->id]);
        $this->assertTrue((bool) $empty->json('result.isError'));
        $this->assertStringContainsString('sop_text and/or sop_status', (string) $empty->json('result.content.0.text'));
    }

    public function test_set_sop_optimistic_concurrency_rejects_a_stale_write(): void
    {
        $this->configureAiActor();
        $node = TicketCategory::create(['name' => 'Service Requests', 'sop_text' => 'v1', 'sop_status' => SopStatus::Draft]);
        $token = $this->token(['set_ticket_category_sop']);

        // A stale timestamp (someone edited since) refuses the overwrite.
        $stale = $this->callTool($token, 'set_ticket_category_sop', [
            'category_id' => $node->id,
            'sop_text' => 'v2',
            'expected_updated_at' => now()->subDay()->toISOString(),
        ]);
        $this->assertTrue((bool) $stale->json('result.isError'));
        $this->assertStringContainsString('while you were editing', (string) $stale->json('result.content.0.text'));
        $this->assertSame('v1', $node->fresh()->sop_text);

        // Malformed timestamps are refused before any comparison.
        $malformed = $this->callTool($token, 'set_ticket_category_sop', [
            'category_id' => $node->id,
            'sop_text' => 'v2',
            'expected_updated_at' => 'not-a-timestamp',
        ]);
        $this->assertTrue((bool) $malformed->json('result.isError'));
        $this->assertStringContainsString('ISO-8601', (string) $malformed->json('result.content.0.text'));

        // The timestamp the caller last read passes.
        $fresh = $this->callTool($token, 'set_ticket_category_sop', [
            'category_id' => $node->id,
            'sop_text' => 'v2',
            'expected_updated_at' => $node->fresh()->updated_at->toISOString(),
        ]);
        $this->assertFalse((bool) $fresh->json('result.isError'), (string) $fresh->json('result.content.0.text'));
        $this->assertSame('v2', $node->fresh()->sop_text);
    }

    public function test_kill_switch_blocks_taxonomy_writes_but_not_reads(): void
    {
        $this->configureAiActor();
        $node = TicketCategory::create(['name' => 'Account & Business']);
        $token = $this->token(self::ALL_TAXONOMY_TOOLS);
        Setting::setValue('technician_kill_switch', '1');

        $writes = [
            ['create_ticket_category', ['name' => 'Blocked']],
            ['update_ticket_category', ['category_id' => $node->id, 'name' => 'Blocked']],
            ['retire_ticket_category', ['category_id' => $node->id, 'confirm_category_name' => $node->name]],
            ['set_ticket_category_sop', ['category_id' => $node->id, 'sop_text' => 'Blocked']],
        ];
        foreach ($writes as [$tool, $arguments]) {
            $response = $this->callTool($token, $tool, $arguments);
            $this->assertTrue((bool) $response->json('result.isError'), "{$tool} must refuse under the kill switch");
            $this->assertStringContainsString('kill-switch', (string) $response->json('result.content.0.text'));
        }
        $this->assertSame('Account & Business', $node->fresh()->name);
        $this->assertNull($node->fresh()->sop_text);
        $this->assertSame(1, TicketCategory::query()->count());

        // Reads stay up — the kill switch stops mutations, not visibility.
        $list = $this->decodedResult($this->callTool($token, 'list_ticket_categories', []));
        $this->assertSame(1, $list['total']);
        $get = $this->decodedResult($this->callTool($token, 'get_ticket_category', ['category_id' => $node->id]));
        $this->assertSame('Account & Business', $get['name']);
    }
}
