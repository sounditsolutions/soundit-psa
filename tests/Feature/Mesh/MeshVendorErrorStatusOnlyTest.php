<?php

namespace Tests\Feature\Mesh;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\MeshAllowRule;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mesh\MeshAllowRuleReaper;
use App\Services\Mesh\MeshClientException;
use App\Services\Mesh\MeshWriteClient;
use App\Support\McpConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Psr\Http\Message\RequestInterface;
use Tests\Support\RecordsGuzzleRejections;
use Tests\TestCase;

/**
 * C-56 / #5248 (card 6ac4439b): a failed Mesh call is reported by its HTTP
 * status only, never by the MeshClientException message.
 *
 * Guzzle's own message quotes the request URI and up to 120 chars of the
 * response body. Since #5884 MeshWriteClient::request() no longer builds its
 * message from it, and since #5978 it is not chained; the positive controls
 * read it where the client caught it (RecordsGuzzleRejections).
 * So these tests drive the REAL client over a scripted Guzzle handler, which
 * produces exactly that message, rather than a mock throwing a tidy string.
 * Every failure body carries MARKER; each site must keep MARKER, the host and
 * the route out of what it reports, and must name the status (or say there
 * was none).
 *
 * Sites covered, one test each, in both failure modes (HTTP 503 and a
 * connect failure with no status):
 *   - mesh_remove_allow_rule: the findRuleById scope read at staging;
 *   - mesh_edit_allow_rule: the findRuleById scope read at staging;
 *   - mesh_edit_allow_rule: the confirming re-read after the PATCH;
 *   - the reaper's settle pass, PERMANENT and unexpired arms (last_error, log);
 *   - reapOne's list read (last_error, log);
 *   - reapOne's DELETE failure (log).
 *
 * Card FLzMLDxF adds, in the modes each site can reach (an HTTP status, a
 * status-less timeout, a never-sent connect failure):
 *   - the add path: both refusal messages, the three 'Mesh did not
 *     acknowledge the create (…)' messages, the failed confirming re-read, and the
 *     'Rule id re-read failed' last_error;
 *   - remove and edit execution: $deleteError and $patchError, in both the
 *     success and the fault message;
 *   - the executor's record-write and audit-write Log::error lines
 *     (exception class only, never the SQL or its bindings);
 *   - MeshWriteClient's and MeshClient's failure log lines (method, path,
 *     status, class);
 *   - #5271: an unreachable failure whose message has no '://'.
 *
 * Synthetic data only.
 */
class MeshVendorErrorStatusOnlyTest extends TestCase
{
    use RecordsGuzzleRejections;
    use RefreshDatabase;

    private const MARKER = 'SYNTHETIC-VENDOR-BODY-7f3a';

    private const HOST = 'mesh.example.test';

    /** A host-like marker in a transport message that carries no '://' (RFC 5737). */
    private const LEAK_IP = '192.0.2.10';

    private const TENANT = '9e3c1f0a-1b2c-4d5e-8f90-a1b2c3d4e5f6';

    private const RULE_ID = '0b1c2d3e-4f50-4a6b-8c7d-8e9fa0b1c2d3';

    /** What the failing call answers: 503 or 'connect' (no HTTP status). */
    private string $mode = '503';

    /** "GET list" | "PATCH" | "DELETE" — the request that fails; others answer. */
    private string $failOn = 'GET list';

    /** When true, the list read fails only once a PATCH has been sent. */
    private bool $failListAfterPatch = false;

    private bool $patched = false;

    /** When true, the list read fails only once a POST has been answered. */
    private bool $failListAfterPost = false;

    /** When true, the list read fails as well as the request named by $failOn. */
    private bool $listFailsToo = false;

    /** When true, the failing write is APPLIED upstream before it fails (Mesh commits before it answers). */
    private bool $commitBeforeFailing = false;

    private bool $posted = false;

    /** When set, the list page reports this count instead of the real one. */
    private ?int $reportedCount = null;

    /** @var array<string, array<string, mixed>> */
    private array $upstream = [];

    /** @var list<array{level: string, message: string}> */
    private array $logged = [];

    /** @var list<string> */
    private array $lastErrors = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        Http::preventStrayRequests();
        Setting::setEncrypted('mesh_api_key', 'k');

        // Every last_error ever saved, not only the final one: a later save on
        // the same request can overwrite an earlier, leaking one.
        MeshAllowRule::saved(function (MeshAllowRule $row): void {
            $this->lastErrors[] = (string) $row->last_error;
        });

        Event::listen(MessageLogged::class, function (MessageLogged $e): void {
            $this->logged[] = ['level' => $e->level, 'message' => $e->message.' '.json_encode($e->context)];
        });
    }

    /** @return array<string, array{string}> */
    public static function modes(): array
    {
        return ['HTTP 503' => ['503'], 'connect failure, no status' => ['connect']];
    }

    // ---- the executor: findRuleById callers -------------------------------------

    /** @return array{client: Client, ticket: Ticket} */
    private function fixture(): array
    {
        $client = Client::factory()->create(['name' => 'Acme', 'mesh_customer_id' => self::TENANT]);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Allow rule change']);

        return compact('client', 'ticket');
    }

    private function callTool(string $name, array $arguments): TestResponse
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ["{$name}:staged"], label: 'opsbot');

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, mixed> */
    private function args(array $fixture, array $extra = []): array
    {
        return array_merge([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'rule_id' => self::RULE_ID,
            'confirm_sender' => 'billing@vendor.example.test',
            'reason' => 'Synthetic reason long enough to be accepted by the verb.',
        ], $extra);
    }

    private function tracked(array $fixture): MeshAllowRule
    {
        return MeshAllowRule::create([
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'mesh_customer_id' => self::TENANT,
            'sender' => 'billing@vendor.example.test',
            'comment' => 'PSA allow STATUSONLY',
            'mesh_rule_id' => self::RULE_ID,
            'expires_at' => now()->addDays(30)->startOfMinute(),
            'state' => MeshAllowRule::STATE_ACTIVE,
            'created_by_actor' => 'test',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_remove_scope_read_failure_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->bindClient();
        $this->assertTheVendorMessageCarriesTheLeak();
        $fixture = $this->fixture();

        $text = (string) $this->callTool('mesh_remove_allow_rule', $this->args($fixture))->json('result.content.0.text');

        $this->assertStringContainsString('scope could not be checked and nothing was removed', $text);
        $this->assertStatusOnly($text, 'remove tool output');
        $this->assertSame(0, TechnicianRun::count(), 'still fail-closed');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_edit_scope_read_failure_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->bindClient();
        $this->assertTheVendorMessageCarriesTheLeak();
        $fixture = $this->fixture();
        $this->tracked($fixture);

        $text = (string) $this->callTool('mesh_edit_allow_rule', $this->args($fixture, [
            'expires_at' => now()->addDays(60)->startOfMinute()->toIso8601String(),
        ]))->json('result.content.0.text');

        $this->assertStringContainsString('scope could not be checked and nothing was changed', $text);
        $this->assertStatusOnly($text, 'edit tool output');
        $this->assertSame(0, TechnicianRun::count(), 'still fail-closed');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_edit_confirming_reread_failure_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->failListAfterPatch = true;
        $this->seedRule('billing@vendor.example.test', 'PSA allow STATUSONLY');
        $this->bindClient();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);

        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $newExpiry = now()->addDays(60)->startOfMinute();
        $this->callTool('mesh_edit_allow_rule', $this->args($fixture, ['expires_at' => $newExpiry->toIso8601String()]))->assertOk();
        $run = TechnicianRun::where('action_type', 'mesh_stage_edit_allow_rule')->firstOrFail();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');
        $this->assertTrue($this->patched, 'positive control: the PATCH was sent before the re-read failed');
        $this->assertTheVendorMessageCarriesTheLeak();

        $error = (string) session('error');
        $this->assertStringContainsString('could NOT be measured', $error);
        $this->assertStatusOnly($error, 'edit approval error');

        $audit = TechnicianActionLog::where('action_type', 'mesh_edit_allow_rule')->where('result_status', 'executed_with_fault')->sole();
        $this->assertStatusOnly((string) $audit->summary, 'edit audit summary');
        $this->assertTrue($record->fresh()->expires_at->equalTo($newExpiry), 'the authoritative change is still kept');
    }

    // ---- the reaper (#5248) -----------------------------------------------------

    private function reaperRow(string $comment, ?\Illuminate\Support\Carbon $expiresAt, array $overrides = []): MeshAllowRule
    {
        return MeshAllowRule::create(array_merge([
            'mesh_customer_id' => self::TENANT,
            'sender' => 'reap@sender.example.test',
            'comment' => $comment,
            'mesh_rule_id' => null,
            'expires_at' => $expiresAt,
            'state' => MeshAllowRule::STATE_UNRESOLVED,
            'scope_proved' => true,
            'last_error' => null,
        ], $overrides));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_settle_pass_list_failure_reports_the_status_only_in_both_arms(string $mode): void
    {
        $this->mode = $mode;
        $this->bindClient();
        $this->assertTheVendorMessageCarriesTheLeak();
        $permanent = $this->reaperRow('PSA allow PERMANENT1', null);
        $dated = $this->reaperRow('PSA allow UNEXPIRED1', now()->addMonths(3));

        app(MeshAllowRuleReaper::class)->reap();

        $permanent->refresh();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $permanent->state);
        $this->assertStringContainsString('upstream id of this PERMANENT rule', (string) $permanent->last_error);
        $this->assertStatusOnly((string) $permanent->last_error, 'settle PERMANENT last_error');

        $dated->refresh();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $dated->state);
        $this->assertStringContainsString('upstream id of this unexpired rule', (string) $dated->last_error);
        $this->assertStatusOnly((string) $dated->last_error, 'settle unexpired last_error');

        $log = $this->loggedBy('[MeshAllowRuleReaper]');
        $this->assertStringContainsString('upstream id of this PERMANENT rule', $log);
        $this->assertStringContainsString('upstream id of this unexpired rule', $log);
        $this->assertStatusOnly($log, 'settle log');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_reap_list_failure_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->bindClient();
        $this->assertTheVendorMessageCarriesTheLeak();
        $expired = $this->reaperRow('PSA allow EXPIRED001', now()->subDay());

        $counts = app(MeshAllowRuleReaper::class)->reap();

        $this->assertSame(1, $counts['failed']);
        $expired->refresh();
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $expired->state);
        $this->assertStringContainsString("Could not read the tenant's rule list to resolve the upstream id: ", (string) $expired->last_error);
        $this->assertStatusOnly((string) $expired->last_error, 'reapOne last_error');

        $log = $this->loggedBy('[MeshAllowRuleReaper]');
        $this->assertStringContainsString('not reaped', $log);
        $this->assertStatusOnly($log, 'reapOne log');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_reap_delete_failure_logs_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->failOn = 'DELETE';
        $this->seedRule('reap@sender.example.test', 'PSA allow EXPIRED002');
        $this->bindClient();
        $this->reaperRow('PSA allow EXPIRED002', now()->subDay(), ['mesh_rule_id' => self::RULE_ID]);

        $counts = app(MeshAllowRuleReaper::class)->reap();

        // The DELETE never landed, so the rule is still readable: still a
        // failure, never a reap.
        $this->assertSame(1, $counts['failed']);
        $this->assertSame(0, $counts['reaped']);

        $log = $this->loggedBy('[MeshAllowRuleReaper] DELETE failed');
        $this->assertStatusOnly($log, 'reapOne DELETE log');
        $this->assertStringContainsString('the DELETE', $log);
    }

    // ---- the add path (card FLzMLDxF) ------------------------------------------

    private const SENDER = 'billing@vendor.example.test';

    private function aiActor(): User
    {
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        return $actor;
    }

    private function stagedAdd(array $fixture): TechnicianRun
    {
        $this->callTool('mesh_stage_add_allow_rule', [
            'client_id' => $fixture['client']->id,
            'ticket_id' => $fixture['ticket']->id,
            'sender' => self::SENDER,
            'confirm_domain' => 'vendor.example.test',
            'reason' => 'Synthetic reason long enough to be accepted by the verb.',
        ])->assertOk();

        return TechnicianRun::where('action_type', 'mesh_stage_add_allow_rule')->firstOrFail();
    }

    /** @return array<string, array{string}> */
    public static function refusedCreates(): array
    {
        return ['HTTP 400 (vendor validation text)' => ['400'], 'HTTP 401' => ['401'], 'never sent (bare connect, #5271)' => ['bare-connect']];
    }

    /**
     * Both determinate-refusal arms of the create: the 400 ('Mesh refused the
     * allow rule') and every other answer that cannot sit on a committed rule
     * ('The allow rule was not created'), including the never-sent connect
     * failure, where Mesh refused nothing and the text must not say it did.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedCreates')]
    public function test_a_refused_create_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->failOn = 'POST';
        $this->bindClient();
        $actor = $this->aiActor();
        $run = $this->stagedAdd($this->fixture());

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertSame(0, MeshAllowRule::count(), 'still a determinate refusal: no phantom row');
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'still approvable after correction');
        $summary = (string) TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'rejected')->sole()->summary;
        $this->assertStringContainsString($mode === '400' ? 'Mesh refused the allow rule: ' : 'The allow rule was not created: ', $summary);
        if ($mode !== '400') {
            // G-14: on the never-sent arm Mesh received nothing, so no arm but the 400 claims a refusal.
            $this->assertStringNotContainsString('refused', $summary);
        }
        $this->assertStatusOnly($summary, 'create refusal audit');
        if ($mode === 'bare-connect') {
            $this->assertStringContainsString('nothing was sent', $summary, 'the never-sent arm still says so');
        }
        $log = $this->loggedBy('[MeshWriteClient]');
        if ($mode === '400') {
            // The 400 arm logs its own fixed line (already status-only).
            $this->assertStringContainsString('refused by Mesh (400)', $log);
            $this->assertStringNotContainsString(self::MARKER, $log);
        } else {
            $this->assertStatusOnlyLog($log, 'client log');
        }
    }

    /** @return array<string, array{string}> */
    public static function unansweredCreates(): array
    {
        return ['HTTP 502' => ['502'], 'timeout, no status' => ['timeout']];
    }

    /**
     * 'Mesh did not acknowledge the create (…)' with the rule found, and with the
     * rule not found because the re-read failed too ('that read failed too').
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unansweredCreates')]
    public function test_an_unanswered_create_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->failOn = 'POST';
        $this->commitBeforeFailing = true;
        $this->bindClient();
        $actor = $this->aiActor();
        $run = $this->stagedAdd($this->fixture());

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');

        $error = (string) session('error');
        $this->assertStringContainsString('Mesh did not acknowledge the create (', $error);
        // G-14: on the 5xx arm Mesh DID answer, so the text must not deny it,
        // neither in the parenthesis nor in the scope sentence that follows.
        $this->assertStringNotContainsString('never answered', $error);
        $this->assertStringNotContainsString('no create response', $error);
        $this->assertStringContainsString('There was no successful create response, so its scope was never confirmed', $error);
        $this->assertStringContainsString('found the rule live', $error, 'positive control: the found arm');
        $this->assertStatusOnly($error, 'unanswered create, found');
        $record = MeshAllowRule::sole();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state, 'reconciled, never declared');
        $this->assertStatusOnly((string) $record->last_error, 'unanswered create last_error');
        $this->assertStatusOnly((string) TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed_with_fault')->sole()->summary, 'unanswered create audit');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unansweredCreates')]
    public function test_an_unanswered_create_whose_reread_failed_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->failOn = 'POST';
        $this->listFailsToo = true;
        $this->bindClient();
        $actor = $this->aiActor();
        $run = $this->stagedAdd($this->fixture());

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');

        $error = (string) session('error');
        $this->assertStringContainsString('Mesh did not acknowledge the create (', $error);
        $this->assertStringNotContainsString('never answered', $error);
        $this->assertStringContainsString('(that read failed too: ', $error, 'positive control: the re-read-failed arm');
        $this->assertStringContainsString('UNMEASURED', $error);
        $this->assertStatusOnly($error, 'unanswered create, re-read failed');
        $this->assertStatusOnly((string) MeshAllowRule::sole()->last_error, 'unmeasured create last_error');
    }

    /** 'Mesh did not acknowledge the create (…), and the PSA record … could not be written'. */
    #[\PHPUnit\Framework\Attributes\DataProvider('unansweredCreates')]
    public function test_an_unanswered_create_with_an_unwritable_record_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->failOn = 'POST';
        $this->commitBeforeFailing = true;
        $this->bindClient();
        $actor = $this->aiActor();
        $run = $this->stagedAdd($this->fixture());
        MeshAllowRule::creating(fn () => throw self::queryFailure());

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');

        $error = (string) session('error');
        $this->assertStringContainsString('Mesh did not acknowledge the create (', $error);
        $this->assertStringNotContainsString('never answered', $error);
        $this->assertStringContainsString('could not be written', $error, 'positive control: the record-unwritable arm');
        $this->assertStatusOnly($error, 'unanswered create, record unwritable');
        $this->assertSame(0, MeshAllowRule::count());
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state, 'still spent, never re-approvable');
        $this->assertStatusOnly((string) TechnicianActionLog::where('action_type', 'mesh_add_allow_rule')->where('result_status', 'executed_with_fault')->sole()->summary, 'record-unwritable audit');
    }

    /** 'Rule id re-read failed: …' after a clean 201. */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_failed_rule_id_reread_after_a_created_rule_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode;
        $this->failListAfterPost = true;
        $this->bindClient();
        $actor = $this->aiActor();
        $run = $this->stagedAdd($this->fixture());

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertTrue($this->posted, 'positive control: the create was answered before the re-read failed');
        $record = MeshAllowRule::sole();
        $this->assertSame(MeshAllowRule::STATE_UNRESOLVED, $record->state);
        // The id-unresolved fault overwrites last_error right after the
        // catch, so the catch's own write is read from the save history.
        $history = implode("\n", $this->lastErrors);
        $this->assertStringContainsString('Rule id re-read failed: ', $history, 'positive control: the catch wrote last_error');
        $this->assertStatusOnly($history, 'rule id re-read last_error');
        $this->assertStatusOnlyLog($this->loggedBy('[MeshWriteClient]'), 'client log');
    }

    // ---- remove / edit execution: $deleteError, $patchError (card FLzMLDxF) ------

    private function stagedVerb(string $verb, array $fixture, array $extra = []): TechnicianRun
    {
        $this->callTool($verb, $this->args($fixture, $extra))->assertOk();

        return TechnicianRun::where('action_type', str_replace('mesh_', 'mesh_stage_', $verb))->firstOrFail();
    }

    /** The DELETE threw over a rule that IS gone: success, "did not answer cleanly (…)". */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_delete_that_failed_over_a_removed_rule_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode === 'connect' ? 'timeout' : $mode;
        $this->failOn = 'DELETE';
        $this->commitBeforeFailing = true;
        $this->seedRule(self::SENDER, 'PSA allow STATUSONLY');
        $this->bindClient();
        $actor = $this->aiActor();
        $fixture = $this->fixture();
        $this->tracked($fixture);
        $run = $this->stagedVerb('mesh_remove_allow_rule', $fixture);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $summary = (string) TechnicianActionLog::where('action_type', 'mesh_remove_allow_rule')->where('result_status', 'executed')->sole()->summary;
        $this->assertStringContainsString('The DELETE call itself did not answer cleanly (', $summary);
        $this->assertStatusOnly($summary, 'remove success summary');
        $this->assertStatusOnlyLog($this->loggedBy('[MeshWriteClient]'), 'client log');
    }

    /** The DELETE failed and the rule is still there: fault, "The DELETE call reported: …". */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_delete_that_failed_over_a_live_rule_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode === 'connect' ? 'timeout' : $mode;
        $this->failOn = 'DELETE';
        $this->seedRule(self::SENDER, 'PSA allow STATUSONLY');
        $this->bindClient();
        $actor = $this->aiActor();
        $fixture = $this->fixture();
        $record = $this->tracked($fixture);
        $run = $this->stagedVerb('mesh_remove_allow_rule', $fixture);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('error');

        $error = (string) session('error');
        $this->assertStringContainsString('The DELETE call reported: ', $error);
        $this->assertStatusOnly($error, 'remove fault');
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $record->fresh()->state, 'still left in the reaper queue');
        $this->assertStatusOnly((string) $record->fresh()->last_error, 'remove fault last_error');
        $this->assertStatusOnly((string) TechnicianActionLog::where('action_type', 'mesh_remove_allow_rule')->where('result_status', 'executed_with_fault')->sole()->summary, 'remove fault audit');
    }

    /** The PATCH threw and the display took: success, "did not answer cleanly (…)". */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_patch_that_failed_over_a_display_that_took_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode === 'connect' ? 'timeout' : $mode;
        $this->failOn = 'PATCH';
        $this->commitBeforeFailing = true;
        $this->seedRule(self::SENDER, 'PSA allow STATUSONLY');
        $this->bindClient();
        $actor = $this->aiActor();
        $fixture = $this->fixture();
        $this->tracked($fixture);
        $run = $this->stagedVerb('mesh_edit_allow_rule', $fixture, ['expires_at' => now()->addDays(60)->startOfMinute()->toIso8601String()]);

        $this->actingAs($actor)->post(route('cockpit.approve', $run))->assertSessionHas('success');

        $summary = (string) TechnicianActionLog::where('action_type', 'mesh_edit_allow_rule')->where('result_status', 'executed')->sole()->summary;
        $this->assertStringContainsString('The PATCH call itself did not answer cleanly (', $summary);
        $this->assertStatusOnly($summary, 'edit success summary');
    }

    /** The PATCH failed and the display did not take: fault, "The PATCH call reported: …". */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_patch_that_failed_over_a_stale_display_reports_the_status_only(string $mode): void
    {
        $this->mode = $mode === 'connect' ? 'timeout' : $mode;
        $this->failOn = 'PATCH';
        $this->seedRule(self::SENDER, 'PSA allow STATUSONLY');
        $this->bindClient();
        $actor = $this->aiActor();
        $fixture = $this->fixture();
        $this->tracked($fixture);
        $run = $this->stagedVerb('mesh_edit_allow_rule', $fixture, ['expires_at' => now()->addDays(60)->startOfMinute()->toIso8601String()]);

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $summary = (string) TechnicianActionLog::where('action_type', 'mesh_edit_allow_rule')->where('result_status', 'executed_with_fault')->sole()->summary;
        $this->assertStringContainsString('The PATCH call reported: ', $summary);
        $this->assertStatusOnly($summary, 'edit fault summary');
    }

    // ---- the executor's DB-write Log::error lines (card FLzMLDxF) ------------------

    private const SQL_MARKER = 'SYNTHETIC-SQL-BINDING-5e1d';

    private static function queryFailure(): \Illuminate\Database\QueryException
    {
        return new \Illuminate\Database\QueryException('testing', 'insert into t (sender) values (?)', [self::SQL_MARKER], new \PDOException('SQLSTATE[HY000]: synthetic '.self::SQL_MARKER));
    }

    /**
     * The PSA row write and the audit row write both fail with a
     * QueryException whose message carries the SQL bindings: the two
     * Log::error lines name the exception class, never its message.
     */
    public function test_the_db_write_failure_logs_name_the_class_not_the_sql(): void
    {
        $this->mode = '503';
        $this->bindClient();
        $actor = $this->aiActor();
        $run = $this->stagedAdd($this->fixture());
        $this->assertStringContainsString(self::SQL_MARKER, self::queryFailure()->getMessage(), 'positive control: the message carries the bindings');

        MeshAllowRule::creating(fn () => throw self::queryFailure());
        TechnicianActionLog::creating(function (TechnicianActionLog $log): void {
            if ($log->action_type === 'mesh_add_allow_rule') {
                throw self::queryFailure();
            }
        });

        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $this->assertTrue($this->posted, 'positive control: the create landed before the record write failed');
        $record = $this->loggedBy('PSA record could not be written');
        $audit = $this->loggedBy('audit row could not be written');
        foreach (['record write log' => $record, 'audit write log' => $audit] as $where => $log) {
            $this->assertStringContainsString('QueryException', $log, "{$where}: names the class");
            $this->assertStringNotContainsString(self::SQL_MARKER, $log, "{$where}: SQL bindings leaked");
            $this->assertStringNotContainsString('insert into', $log, "{$where}: SQL leaked");
            $this->assertStringNotContainsString(self::SENDER, $log, "{$where}: client data leaked");
        }
    }

    // ---- MeshClient (read client) log line (card FLzMLDxF) -------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_the_read_client_logs_method_path_status_and_class_only(string $mode): void
    {
        $this->mode = $mode;
        $client = new \App\Services\Mesh\MeshClient(['api_key' => 'k', 'base_url' => 'https://'.self::HOST]);
        // No network: the client builds its own Guzzle, so swap in a scripted one.
        $guzzle = new GuzzleClient([
            'base_uri' => 'https://'.self::HOST.'/',
            'handler' => $this->recordRejections(HandlerStack::create(fn (RequestInterface $r) => $this->failure($r))),
            'http_errors' => true,
        ]);
        (new \ReflectionProperty($client, 'http'))->setValue($client, $guzzle);

        try {
            $client->get('api/customers/', ['_size' => 1, 'filter' => self::MARKER]);
            $this->fail('the scripted read was expected to fail');
        } catch (MeshClientException $e) {
            $this->assertNull($e->getPrevious(), 'the Guzzle exception is not chained (#5978)');
            $this->assertStringContainsString(self::HOST, $this->lastRejection()->getMessage(), 'positive control: the raw message the client caught carries the host');
            $this->assertSame('Mesh API error: GET api/customers/ failed with '.($this->mode === '503' ? 'HTTP 503 (GuzzleHttp\Exception\ServerException)' : 'no HTTP status (cURL errno 7, '.ConnectException::class.')'), $e->getMessage(), 'the rethrown message is status-only (#5761)');
            $this->assertStringNotContainsString(self::HOST, $e->getMessage(), 'the rethrown message carries no host (#5761)');
        }

        $log = $this->loggedBy('[MeshClient]');
        $this->assertStringContainsString('GET api/customers/', $log, 'the method and path are kept');
        $this->assertStatusOnlyLog($log, 'read client log');
    }

    /** The client log lines: method, path, status (or none) and class; nothing else. */
    private function assertStatusOnlyLog(string $log, string $where): void
    {
        $this->assertStringNotContainsString(self::MARKER, $log, "{$where}: vendor body or query leaked");
        $this->assertStringNotContainsString(self::HOST, $log, "{$where}: host leaked");
        $this->assertStringNotContainsString(self::LEAK_IP, $log, "{$where}: host leaked");
        $this->assertStringNotContainsString('?', $log, "{$where}: a query string leaked");
        $this->assertStringContainsString('Exception', $log, "{$where}: the exception class is named");
        if (is_numeric($this->mode)) {
            $this->assertStringContainsString('failed with HTTP '.$this->mode, $log, "{$where}: the status is the report");
        } else {
            $this->assertMatchesRegularExpression('/no HTTP status|failed at the resolve, connect or TLS stage/', $log, "{$where}: a status-less failure says so");
        }
    }

    /** MeshWriteClient's two log lines ("failed with" and the never-sent one, #5982). */
    #[\PHPUnit\Framework\Attributes\DataProvider('clientLogModes')]
    public function test_the_write_client_logs_method_path_status_and_class_only(string $mode): void
    {
        $this->mode = $mode;
        $this->bindClient();
        $this->assertTheVendorMessageCarriesTheLeak();

        $log = $this->loggedBy('[MeshWriteClient]');
        $this->assertStringContainsString('GET api/rule-allows-blocks/', $log, 'the method and path are kept');
        $this->assertStatusOnlyLog($log, 'write client log');
        if (in_array($mode, ['connect', 'bare-connect'], true)) {
            $this->assertStringContainsString('failed at the resolve, connect or TLS stage (cURL errno 7', $log);
            $this->assertStringNotContainsString('could not connect', $log, '#5982: not every never-sent errno is a connect failure');
        }
    }

    /** @return array<string, array{string}> */
    public static function clientLogModes(): array
    {
        return ['HTTP 503' => ['503'], 'connect, never sent' => ['connect'], 'timeout, no status' => ['timeout']];
    }

    // ---- failures the client detects itself (G-14) ------------------------------

    /**
     * Every page answered HTTP 200, but the client itself refused the read
     * (rows seen fewer than the count Mesh reported). That message is written
     * by the PSA and quotes no vendor text, so it is reported as written —
     * never as a status-less transport failure that did not happen.
     */
    public function test_a_client_detected_list_failure_keeps_its_own_diagnosis(): void
    {
        $this->failOn = 'none';
        $this->reportedCount = 5;
        $this->seedRule('billing@vendor.example.test', 'PSA allow STATUSONLY');
        $this->bindClient();

        try {
            $this->app->make(MeshWriteClient::class)->listCustomerRules(self::TENANT);
            $this->fail('positive control: a short read against a larger count was expected to be refused');
        } catch (MeshClientException $e) {
            $this->assertSame(0, $e->getCode(), 'positive control: a client-detected failure carries no status');
            $this->assertStringContainsString('Mesh reported 5', $e->getMessage());
        }

        $fixture = $this->fixture();
        $text = (string) $this->callTool('mesh_remove_allow_rule', $this->args($fixture))->json('result.content.0.text');
        $this->assertStringContainsString('scope could not be checked and nothing was removed', $text);
        $this->assertStringContainsString('Mesh reported 5', $text);
        $this->assertStringNotContainsString('without an HTTP status', $text);
        $this->assertSame(0, TechnicianRun::count(), 'still fail-closed');

        $expired = $this->reaperRow('PSA allow EXPIRED003', now()->subDay());
        $counts = app(MeshAllowRuleReaper::class)->reap();
        $this->assertSame(1, $counts['failed']);
        $expired->refresh();
        $this->assertSame(MeshAllowRule::STATE_REAP_FAILED, $expired->state);
        $this->assertStringContainsString("Could not read the tenant's rule list to resolve the upstream id: ", (string) $expired->last_error);
        $this->assertStringContainsString('Mesh reported 5', (string) $expired->last_error);
        $this->assertStringNotContainsString('without an HTTP status', (string) $expired->last_error);
    }

    /** Each arm of statusPhrase(), including the ones no scripted vendor reaches. */
    public function test_status_phrase_reduces_only_upstream_failures_to_their_status(): void
    {
        $status = new MeshClientException('Mesh API error: '.self::MARKER, 503);
        $this->assertSame('Mesh answered the DELETE with HTTP 503', $status->statusPhrase('the DELETE'));

        $wrapped = new MeshClientException('Mesh API error: cURL error 7 '.self::MARKER, 0);
        $this->assertSame('the DELETE failed without an HTTP status from Mesh', $wrapped->statusPhrase('the DELETE'));

        $chained = new MeshClientException(self::MARKER, 0, new \RuntimeException(self::MARKER));
        $this->assertSame('the DELETE failed without an HTTP status from Mesh', $chained->statusPhrase('the DELETE'));

        $uri = new MeshClientException('read of https://'.self::HOST.'/x refused', 0);
        $this->assertSame('the DELETE failed without an HTTP status from Mesh', $uri->statusPhrase('the DELETE'));

        // Classified by construction (#5271): only a throw site that says it
        // wrote the message itself has it passed through.
        $own = new MeshClientException('Mesh API key is not configured; nothing was sent.', clientDetected: true, nothingSent: true);
        $this->assertSame('Mesh API key is not configured; nothing was sent.', $own->statusPhrase('the DELETE'));
        $this->assertTrue($own->nothingWasSent());

        // Unflagged text is upstream, whatever it says: no prefix, no '://'.
        $bare = new MeshClientException('connect to '.self::LEAK_IP.' port 443 failed '.self::MARKER, 0);
        $this->assertSame('the DELETE failed without an HTTP status from Mesh', $bare->statusPhrase('the DELETE'));
        $this->assertFalse($bare->nothingWasSent());
    }

    /**
     * #5271: request()'s never-sent arm, driven through the REAL client with a
     * connect failure whose message has NO '://' and carries a host-like
     * marker. Under the old message denylist this text was passed through;
     * by construction it is status-less upstream, and still says that
     * nothing was sent, which is the only fact callers branch on.
     */
    public function test_an_unreachable_failure_without_a_uri_reports_no_vendor_text(): void
    {
        $this->mode = 'bare-connect';
        $this->failOn = 'DELETE';
        $this->bindClient();

        try {
            $this->app->make(MeshWriteClient::class)->deleteRule(self::RULE_ID);
            $this->fail('the scripted DELETE was expected to fail');
        } catch (MeshClientException $e) {
            $this->assertNull($e->getPrevious(), 'the Guzzle exception is not chained (#5978)');
            $this->assertStringNotContainsString('://', $this->lastRejection()->getMessage(), 'positive control: the transport message names no URI');
            $this->assertStringContainsString(self::LEAK_IP, $this->lastRejection()->getMessage(), 'positive control: the raw message carries the host');
            $this->assertStringNotContainsString(self::LEAK_IP, $e->getMessage(), 'the client message carries no host (#5884)');
            $this->assertSame(0, $e->getCode());
            $this->assertTrue($e->nothingWasSent(), 'the never-sent arm still says so, structurally');

            $phrase = $e->statusPhrase('the DELETE');
            $this->assertSame('the DELETE failed without an HTTP status from Mesh; nothing was sent', $phrase);
        }

        $log = $this->loggedBy('[MeshWriteClient]');
        $this->assertStringNotContainsString(self::LEAK_IP, $log, 'the client log carries no host');
        $this->assertStringNotContainsString(self::MARKER, $log);
        $this->assertStringContainsString('nothing was sent', $log);
    }

    // ---- the scripted vendor ----------------------------------------------------

    private function failure(RequestInterface $request)
    {
        if ($this->mode === 'bare-connect') {
            // #5271: a connect failure worded with no URI at all, as a custom
            // handler or a future Guzzle may word it.
            return Create::rejectionFor(new ConnectException(
                'connect to '.self::LEAK_IP.' port 443 failed: Connection refused '.self::MARKER,
                $request,
                null,
                ['errno' => 7],
            ));
        }

        if ($this->mode === 'connect') {
            // errno 7 (couldn't connect) is one MeshWriteClient reads as
            // never-sent; its own message still names the URI and the marker.
            return Create::rejectionFor(new ConnectException(
                'cURL error 7: Failed to connect to '.self::HOST.' '.self::MARKER.' for '.$request->getUri(),
                $request,
                null,
                ['errno' => 7],
            ));
        }

        if ($this->mode === 'timeout') {
            // errno 28: the request was sent and no answer came. Status 0,
            // NOT never-sent, so a create is reconciled rather than refused.
            return Create::rejectionFor(new ConnectException(
                'cURL error 28: Operation timed out '.self::MARKER.' for '.$request->getUri(),
                $request,
                null,
                ['errno' => 28],
            ));
        }

        return Create::promiseFor(new Response((int) $this->mode, ['Content-Type' => 'application/json'], json_encode([
            'detail' => self::MARKER,
            'errors' => ['Invalid sender: '.self::MARKER],
        ])));
    }

    private function bindClient(): void
    {
        $handler = function (RequestInterface $request) {
            $method = $request->getMethod();
            $path = ltrim($request->getUri()->getPath(), '/');
            $isList = $method === 'GET' && $path === 'api/rule-allows-blocks/';

            if ($isList && $this->failOn === 'GET list' && (! $this->failListAfterPatch || $this->patched)
                && (! $this->failListAfterPost || $this->posted)) {
                return $this->failure($request);
            }
            if ($isList && $this->listFailsToo) {
                return $this->failure($request);
            }
            if ($method === $this->failOn) {
                if ($this->commitBeforeFailing) {
                    $this->apply($request);
                }

                return $this->failure($request);
            }

            if ($method === 'POST') {
                $this->apply($request);
                $this->posted = true;

                return Create::promiseFor(new Response(201, [], json_encode(['added_for' => [self::TENANT]])));
            }

            if ($isList) {
                // Paged as Mesh pages: rows from `_from` on. Re-serving the
                // first page at every offset would let the walk pad itself up
                // to any reported count, and no short read would ever happen.
                parse_str($request->getUri()->getQuery(), $query);

                return Create::promiseFor(new Response(200, [], json_encode([
                    'count' => $this->reportedCount ?? count($this->upstream),
                    'next' => null,
                    'previous' => null,
                    'results' => array_slice(array_values($this->upstream), (int) ($query['_from'] ?? 0)),
                ])));
            }

            $id = trim(substr($path, strlen('api/rule-allows-blocks/')), '/');

            if ($method === 'PATCH') {
                $this->patched = true;

                return Create::promiseFor(new Response(200, [], json_encode($this->upstream[$id] ?? [])));
            }
            if ($method === 'DELETE') {
                unset($this->upstream[$id]);

                return Create::promiseFor(new Response(200, [], '{}'));
            }

            return Create::promiseFor(isset($this->upstream[$id])
                ? new Response(200, [], json_encode($this->upstream[$id]))
                : new Response(404, [], '{"detail":"Not found."}'));
        };

        $guzzle = new GuzzleClient([
            'base_uri' => 'https://'.self::HOST.'/',
            'handler' => $this->recordRejections(HandlerStack::create($handler)),
            'http_errors' => true,
        ]);

        $this->app->instance(MeshWriteClient::class, new MeshWriteClient(['api_key' => 'k'], $guzzle));
    }

    /** What a write does to the scripted tenant when Mesh commits it. */
    private function apply(RequestInterface $request): void
    {
        $method = $request->getMethod();
        $id = trim(substr(ltrim($request->getUri()->getPath(), '/'), strlen('api/rule-allows-blocks/')), '/');
        $body = json_decode((string) $request->getBody(), true) ?: [];

        if ($method === 'POST') {
            $this->seedRule((string) ($body['sender'] ?? ''), (string) ($body['comment'] ?? ''));
        } elseif ($method === 'DELETE') {
            unset($this->upstream[$id]);
        } elseif ($method === 'PATCH' && isset($this->upstream[$id])) {
            $this->upstream[$id] = array_merge($this->upstream[$id], $body);
            $this->patched = true;
        }
    }

    private function seedRule(string $sender, string $comment): void
    {
        $this->upstream[self::RULE_ID] = [
            'id' => self::RULE_ID,
            'sender' => $sender,
            'comment' => $comment,
            'ab' => true,
            'active' => true,
            'organization_level' => true,
            'customer_id' => null,
            'customer' => ['id' => self::TENANT, 'name' => 'Tenant'],
            'date_expiry' => null,
        ];
    }

    // ---- assertions -------------------------------------------------------------

    /**
     * Positive control: the transport really produced a message carrying the
     * leak. Since #5884 that message is only the chained Guzzle exception's;
     * MeshWriteClient's own message carries neither the body nor the host.
     */
    private function assertTheVendorMessageCarriesTheLeak(): void
    {
        $client = $this->app->make(MeshWriteClient::class);
        // #6057: only this read's rejection can be read below.
        $this->forgetRejections();
        try {
            $client->listCustomerRules(self::TENANT);
            $this->fail('the scripted list read was expected to fail');
        } catch (MeshClientException $e) {
            $this->assertNull($e->getPrevious(), 'the Guzzle exception is not chained (#5978)');
            $raw = $this->lastRejection()->getMessage();
            $this->assertStringContainsString(self::MARKER, $raw, 'positive control: the raw message carries the vendor body');
            $this->assertStringContainsString(self::HOST, $raw, 'positive control: the raw message carries the host');
            $this->assertStringNotContainsString(self::MARKER, $e->getMessage(), 'the client message carries no vendor body (#5884)');
            $this->assertStringNotContainsString(self::HOST, $e->getMessage(), 'the client message carries no host (#5884)');
        }
    }

    private function assertStatusOnly(string $text, string $where): void
    {
        $this->assertStringNotContainsString(self::MARKER, $text, "{$where}: vendor body leaked");
        $this->assertStringNotContainsString(self::HOST, $text, "{$where}: request host leaked");
        $this->assertStringNotContainsString('rule-allows-blocks', $text, "{$where}: request route leaked");
        $this->assertStringNotContainsString('Mesh API', $text, "{$where}: the exception message was used");
        $this->assertStringNotContainsString(self::LEAK_IP, $text, "{$where}: transport host leaked");
        $this->assertStringNotContainsString('_size', $text, "{$where}: request query leaked");

        if (is_numeric($this->mode)) {
            $this->assertStringContainsString('with HTTP '.$this->mode, $text, "{$where}: the status is the report");
        } else {
            $this->assertStringContainsString('failed without an HTTP status from Mesh', $text, "{$where}: a status-less failure says so");
            $this->assertStringNotContainsString('HTTP 0', $text, $where);
        }
    }

    /** Every record the named component wrote, at any level, joined. */
    private function loggedBy(string $prefix): string
    {
        $lines = array_filter($this->logged, fn (array $r) => str_contains($r['message'], $prefix));
        $this->assertNotSame([], $lines, "positive control: {$prefix} logged something");

        return implode("\n", array_map(fn (array $r) => $r['level'].' '.$r['message'], $lines));
    }
}
