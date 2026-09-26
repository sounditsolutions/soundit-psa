<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\MeshAllowRule;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\StaffMeshAdminToolExecutor;
use App\Services\Mesh\MeshWriteClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #3929 remainder: the duplicate and no-change arms of the Mesh allow-rule
 * approvals.
 *
 * executeEditAllowRule() answers "this proposal changes nothing" only after
 * editAllowRuleTarget() has read the tenant's rules (findRuleById), so its
 * "no upstream call was made" was false; it now names the PATCH that was not
 * sent. The alreadyExecuted() arms of all three verbs run before any read;
 * their sentence is true and is pinned here unchanged.
 *
 * The real MeshWriteClient runs on an injected Guzzle client: a routing
 * handler plays the rule endpoint and Guzzle's history middleware records
 * every request. Each case approves a first card, which is recorded sending
 * its write (the seam's positive control), then approves a second card for
 * the same write and asserts that card's exact request log.
 */
class MeshAlreadyWordingTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    private const RULES = 'https://mesh.invalid/api/rule-allows-blocks/';

    private const LIST_READ = 'GET '.self::RULES.'?_from=0&_size=200';

    /** @var array<int,mixed> */
    private array $history = [];

    /** @var array<string, array<string, mixed>> live upstream rows by id */
    private array $rows = [];

    private int $nextId = 1;

    private User $approver;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        // A fixed instant, so every expiry below is a literal.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-01 12:00:00', 'UTC'));

        Setting::setEncrypted('mesh_api_key', 'k');
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $this->approver = User::factory()->create();
        $this->client = Client::factory()->create(['name' => 'Acme', 'mesh_customer_id' => self::TENANT]);

        $handler = function (RequestInterface $request) {
            $method = $request->getMethod();
            $path = ltrim($request->getUri()->getPath(), '/');
            $id = trim(substr($path, strlen('api/rule-allows-blocks/')), '/');
            if ($method === 'GET' && $id === '') {
                return $this->reply(200, ['results' => array_values($this->rows)]);
            }
            if ($method === 'POST' && $id === '') {
                $body = json_decode((string) $request->getBody(), true);
                $newId = 'rule-'.$this->nextId++;
                $this->rows[$newId] = [
                    'id' => $newId, 'sender' => $body['sender'], 'comment' => $body['comment'],
                    'ab' => true, 'customer_id' => self::TENANT, 'date_expiry' => $body['date_expiry'] ?? null,
                ];

                return $this->reply(201, ['detail' => 'Allow/Block Rules added', 'added_for' => [self::TENANT]]);
            }
            if ($method === 'PATCH' && isset($this->rows[$id])) {
                $body = json_decode((string) $request->getBody(), true);
                $this->rows[$id]['date_expiry'] = $body['date_expiry'];

                return $this->reply(200, $this->rows[$id]);
            }
            if ($method === 'DELETE' && isset($this->rows[$id])) {
                unset($this->rows[$id]);

                return $this->reply(204, []);
            }
            if ($method === 'GET' && ! isset($this->rows[$id])) {
                return $this->reply(404, ['detail' => 'Not found.']);
            }

            return $this->reply(599, ['unrouted' => "{$method} {$path}"]);
        };
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));
        $guzzle = new GuzzleClient(['handler' => $stack, 'base_uri' => 'https://mesh.invalid/']);
        $this->app->instance(MeshWriteClient::class, new MeshWriteClient(['api_key' => 'k'], $guzzle));
    }

    private function reply(int $status, array $body)
    {
        return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
    }

    /** @return list<string> */
    private function requests(): array
    {
        return array_map(
            fn (array $t) => $t['request']->getMethod().' '.(string) $t['request']->getUri(),
            $this->history,
        );
    }

    private function stage(string $tool, array $args): TechnicianRun
    {
        $ticket = Ticket::factory()->for($this->client)->create(['subject' => 'Allow rule change']);
        $result = app(StaffMeshAdminToolExecutor::class)->execute($tool, $args + [
            'ticket_id' => $ticket->id,
            'reason' => 'wording test',
        ], $this->client->id, 'wording-test');
        $this->assertArrayHasKey('run_id', $result, "{$tool}: staging must succeed: ".json_encode($result));

        return TechnicianRun::findOrFail($result['run_id']);
    }

    private function approve(TechnicianRun $run): \App\Services\Technician\TechnicianApprovalResult
    {
        return app(StaffMeshAdminToolExecutor::class)->approveStagedRun($run, $this->approver->id);
    }

    private function latestBlocked(string $tool): string
    {
        return (string) TechnicianActionLog::query()
            ->where('action_type', $tool)->where('result_status', 'blocked')
            ->latest('id')->value('summary');
    }

    private function trackedRule(): void
    {
        $this->rows['rule-x'] = [
            'id' => 'rule-x', 'sender' => 'billing@vendor.example', 'comment' => 'PSA allow ABCDEFGHIJ',
            'ab' => true, 'customer_id' => self::TENANT, 'date_expiry' => '2026-10-31',
        ];
        MeshAllowRule::create([
            'client_id' => $this->client->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => 'billing@vendor.example',
            'comment' => 'PSA allow ABCDEFGHIJ',
            'mesh_rule_id' => 'rule-x',
            'expires_at' => '2026-10-31 12:00:00',
            'state' => MeshAllowRule::STATE_ACTIVE,
            'created_by_actor' => 'test',
        ]);
    }

    private function editArgs(): array
    {
        return ['rule_id' => 'rule-x', 'confirm_sender' => 'billing@vendor.example', 'expires_at' => '2026-11-30T12:00:00+00:00'];
    }

    /** FALSE arm, fixed: the no-change answer is given after the tenant's rules were read. */
    public function test_edit_no_change_answer_follows_a_rule_read_and_names_the_patch_not_sent(): void
    {
        $this->trackedRule();
        $first = $this->stage('mesh_stage_edit_allow_rule', $this->editArgs());
        $second = $this->stage('mesh_stage_edit_allow_rule', $this->editArgs());

        $this->history = [];
        $this->assertSame('executed', $this->approve($first)->status);
        $this->assertContains('PATCH '.self::RULES.'rule-x/', $this->requests(), 'the recorder must see the first approval\'s PATCH');

        // Past the 24h dedup window, so only the row-level sameInstant() arm can answer.
        $this->travel(30)->hours();
        $this->history = [];
        $result = $this->approve($second);

        $this->assertSame([self::LIST_READ], $this->requests(), 'the rule is read before the arm; no PATCH follows');
        $expected = "Rule 'rule-x' (sender 'billing@vendor.example') already expires Mon, Nov 30, 2026 12:00 PM UTC in the PSA; this proposal changes nothing and no PATCH was sent.";
        $this->assertSame('executed_with_fault', $result->status);
        $this->assertSame($expected, $result->message);
        $this->assertSame($expected, $this->latestBlocked('mesh_edit_allow_rule'));
    }

    /** TRUE arm: the edit dedup answers before any read. */
    public function test_edit_duplicate_inside_the_window_sends_nothing_and_keeps_its_sentence(): void
    {
        $this->trackedRule();
        $first = $this->stage('mesh_stage_edit_allow_rule', $this->editArgs());
        $second = $this->stage('mesh_stage_edit_allow_rule', $this->editArgs());

        $this->history = [];
        $this->assertSame('executed', $this->approve($first)->status);
        $this->assertContains('PATCH '.self::RULES.'rule-x/', $this->requests());

        $this->history = [];
        $result = $this->approve($second);

        $this->assertSame([], $this->requests(), 'the duplicate must send nothing');
        $expected = "Rule 'rule-x' was already set to this expiry for this client recently; no upstream call was made.";
        $this->assertSame($expected, $result->message);
        $this->assertSame($expected, $this->latestBlocked('mesh_edit_allow_rule'));
    }

    /** TRUE arm: the removal dedup answers before any read. */
    public function test_remove_duplicate_sends_nothing_and_keeps_its_sentence(): void
    {
        $this->trackedRule();
        $args = ['rule_id' => 'rule-x', 'confirm_sender' => 'billing@vendor.example'];
        $first = $this->stage('mesh_stage_remove_allow_rule', $args);
        $second = $this->stage('mesh_stage_remove_allow_rule', $args);

        $this->history = [];
        $this->assertSame('executed', $this->approve($first)->status);
        $this->assertContains('DELETE '.self::RULES.'rule-x/', $this->requests(), 'the recorder must see the first approval\'s DELETE');

        $this->history = [];
        $result = $this->approve($second);

        $this->assertSame([], $this->requests(), 'the duplicate must send nothing');
        $expected = "Rule 'rule-x' was already removed for this client recently; no upstream call was made.";
        $this->assertSame($expected, $result->message);
        $this->assertSame($expected, $this->latestBlocked('mesh_remove_allow_rule'));
    }

    /** TRUE arm: the create dedup answers before any read. */
    public function test_add_duplicate_sends_nothing_and_keeps_its_sentence(): void
    {
        $args = ['sender' => 'billing@vendor.example', 'confirm_domain' => 'vendor.example'];
        $first = $this->stage('mesh_stage_add_allow_rule', $args);
        $second = $this->stage('mesh_stage_add_allow_rule', $args);

        $this->history = [];
        $this->assertSame('executed', $this->approve($first)->status);
        $this->assertContains('POST '.self::RULES, $this->requests(), 'the recorder must see the first approval\'s create');

        $this->history = [];
        $result = $this->approve($second);

        $this->assertSame([], $this->requests(), 'the duplicate must send nothing');
        $this->assertSame('This allow rule was already created recently; no upstream call was made.', $result->message);
        $this->assertSame('Duplicate Mesh allow rule suppressed before upstream call.', $this->latestBlocked('mesh_add_allow_rule'));
    }
}
