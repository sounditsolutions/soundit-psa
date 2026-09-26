<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Mcp\StaffCippWriteToolExecutor;
use App\Services\Technician\TechnicianApprovalResult;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * #3709: "not applied" is said only on positive evidence that nothing was
 * sent. Every test runs the REAL CippRestWriteClient::send() under Http::fake
 * and counts the write POSTs that left, so a sentence is tied to the traffic
 * that actually happened:
 *  - unconfirmed arm (200 without the success line): the hedge, 1 POST;
 *  - pre-send arm (credentials missing, so getToken() refuses): the definite
 *    sentence, 0 POSTs;
 *  - transport arm (the POST throws ConnectionException): the hedge and an
 *    'error' audit row, 1 POST, and on the staged path a declined result
 *    instead of a rethrow.
 * No arm may audit 'executed'.
 */
class CippWriteReverseDefaultTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'acme.onmicrosoft.com';

    private const GROUP_ID = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

    private const SKU = 'sku-from-tenant-sync';

    /** Upstream text that must never reach the operator. */
    private const UPSTREAM_MARKER = 'UPSTREAM-DETAIL-4417';

    /** 'body' | 'connection' | 'unread' */
    private string $mode = 'body';

    /**
     * When true the resolver answers with a private address, so
     * safeRequestOptions() refuses before the request is built. The group
     * tools read the live group listing first; the fake flips this after
     * serving that read, so only the write is refused.
     */
    private bool $hostPrivate = false;

    private bool $privateAfterRead = false;

    /** @var array<string, mixed> */
    private array $writeBody = [];

    private int $writeStatus = 200;

    private function configure(bool $credentials = true): User
    {
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'client-1');
        Setting::setEncrypted('cipp_client_secret', 'secret');
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        // The real client. With credentials off, getToken() refuses before any
        // request is built: the not-sent arm.
        $this->app->instance(CippRestWriteClient::class, new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test', 'tenant_id' => 'tenant-1',
            'client_id' => 'client-1', 'client_secret' => $credentials ? 'secret' : '',
        ], Cache::store(), fn (string $host): array => $this->hostPrivate ? ['10.0.0.5'] : ['93.184.216.34']));

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'login.microsoftonline.com')) {
                return Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]);
            }
            if (str_contains($url, '/api/ListUsers')) {
                return Http::response([[
                    'id' => 'contractor-oid', 'userPrincipalName' => 'contractor@acme.example',
                    'displayName' => 'Sam Contractor', 'accountEnabled' => true, 'mail' => 'contractor@acme.example',
                ]]);
            }
            if (str_contains($url, '/api/ListGroups')) {
                $this->hostPrivate = $this->hostPrivate || $this->privateAfterRead;

                return Http::response([$this->groupRow()]);
            }
            if ($request->method() === 'POST' && $this->isWrite($url)) {
                if ($this->mode === 'connection') {
                    return Http::failedConnection('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received for '.$url.' '.self::UPSTREAM_MARKER);
                }
                if ($this->mode === 'unread') {
                    // Status and headers arrive; reading the body fails.
                    $stream = \GuzzleHttp\Psr7\FnStream::decorate(\GuzzleHttp\Psr7\Utils::streamFor('{}'), [
                        '__toString' => fn () => throw new \RuntimeException('connection reset while reading '.self::UPSTREAM_MARKER),
                        'getContents' => fn () => throw new \RuntimeException('connection reset while reading '.self::UPSTREAM_MARKER),
                    ]);

                    return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $stream));
                }

                return Http::response($this->writeBody, $this->writeStatus);
            }

            return Http::response('unexpected', 599);
        });

        return $actor;
    }

    private function isWrite(string $url): bool
    {
        foreach (['/api/EditGroup', '/api/ExecSharePointPerms', '/api/EditUser', '/api/ExecBulkLicense'] as $endpoint) {
            if (str_contains($url, $endpoint)) {
                return true;
            }
        }

        return false;
    }

    private function writes(string $endpoint): int
    {
        return Http::recorded(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/api/'.$endpoint))->count();
    }

    /** @return array<string, mixed> */
    private function groupRow(): array
    {
        return [
            'id' => self::GROUP_ID, 'displayName' => 'Sales Team', 'mail' => 'sales@acme.example',
            'mailEnabled' => true, 'securityEnabled' => false, 'membershipRule' => null,
            'groupTypes' => ['Unified'], 'onPremisesSyncEnabled' => null,
            'groupType' => 'Microsoft 365', 'calculatedGroupType' => 'm365', 'dynamicGroupBool' => false,
        ];
    }

    /** @return array{client: Client, person: Person, successor: Person, ticket: Ticket, licenseType: LicenseType} */
    private function fixture(): array
    {
        $client = Client::factory()->create(['name' => 'Acme', 'cipp_tenant_domain' => self::TENANT]);
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => PersonType::User,
            'first_name' => 'Alex', 'last_name' => 'Acme', 'email' => 'alex@acme.example',
            'cipp_user_id' => 'user-123', 'cipp_upn' => 'alex@acme.example', 'is_active' => true,
        ]);
        $successor = Person::create([
            'client_id' => $client->id, 'person_type' => PersonType::User,
            'first_name' => 'Sam', 'last_name' => 'Acme', 'email' => 'sam@acme.example',
            'cipp_user_id' => 'successor-123', 'cipp_upn' => 'sam@acme.example', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->for($client)->create(['contact_id' => $person->id, 'subject' => 'Account change']);
        $licenseType = LicenseType::create([
            'name' => 'Business Premium', 'vendor' => 'cipp_m365', 'vendor_sku_id' => self::SKU, 'is_active' => true,
        ]);
        License::create([
            'license_type_id' => $licenseType->id, 'client_id' => $client->id, 'quantity' => 10, 'assigned_quantity' => 2,
            'vendor_ref' => self::SKU, 'status' => 'active', 'synced_at' => now(),
        ]);

        return compact('client', 'person', 'successor', 'ticket', 'licenseType');
    }

    private function callTool(string $name, array $arguments): TestResponse
    {
        $token = McpConfig::rotateStaffToken(allowedTools: [$name], label: 'opsbot');

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** Tool, its write endpoint, its arguments, and the two sentences it must use. */
    private function tool(string $kind, array $f, bool $staged): array
    {
        $base = ['client_id' => $f['client']->id, 'person_id' => $f['person']->id, 'confirm_upn' => 'alex@acme.example'];
        if ($staged) {
            $base['ticket_id'] = $f['ticket']->id;
        }
        [$tool, $endpoint, $args, $change, $where] = match ($kind) {
            'group' => ['cipp_set_group_membership', 'EditGroup', $base + [
                'group_id' => self::GROUP_ID, 'operation' => 'add', 'ticket_id' => $f['ticket']->id,
                'confirm_group_name' => 'Sales Team', 'reason' => 'Add the new hire to the sales group.',
            ], 'the membership change', 'the group membership'],
            'onedrive' => ['cipp_reassign_onedrive', 'ExecSharePointPerms', $base + [
                'successor_person_id' => $f['successor']->id, 'reason' => 'Offboarding: hand the OneDrive to the manager.',
            ], 'the OneDrive permission change', 'the OneDrive permissions'],
            'edit' => ['cipp_edit_user', 'EditUser', $base + [
                'job_title' => 'Operations Manager', 'reason' => 'Verified promotion on the HR ticket.',
            ], 'the user edit', "the user's current state"],
        };
        $name = $staged ? str_replace('cipp_', 'cipp_stage_', $tool) : $tool;

        return [
            'name' => $name,
            'endpoint' => $endpoint,
            'args' => $args,
            'hedge' => "CIPP write failed for {$name}; {$change} may or may not have applied — verify {$where} in CIPP before retrying.",
            'definite' => "CIPP write failed for {$name}; nothing was sent to CIPP, so {$change} was not applied.",
        ];
    }

    private function runDirect(array $t): string
    {
        $response = $this->callTool($t['name'], $t['args']);
        $response->assertOk();

        return (string) (json_decode((string) $response->json('result.content.0.text'), true)['error'] ?? $response->json('result.content.0.text'));
    }

    private function runStaged(array $t, User $approver, ?callable $beforeApproval = null): TechnicianApprovalResult
    {
        $staged = json_decode((string) $this->callTool($t['name'], $t['args'])->json('result.content.0.text'), true);
        $this->assertTrue($staged['success'] ?? false, json_encode($staged));
        if ($beforeApproval !== null) {
            $beforeApproval();
        }

        return app(StaffCippWriteToolExecutor::class)->approveStagedRun(TechnicianRun::findOrFail($staged['run_id']), $approver->id);
    }

    private function assertErrorAudited(string $operatorText, int $writes, string $endpoint, bool $urlHidden = true): void
    {
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count(), 'a failed write must never audit as executed');
        $this->assertSame(1, TechnicianActionLog::where('result_status', 'error')->count(), 'the failure must leave one error row');
        $this->assertSame($writes, $this->writes($endpoint), "write POSTs to {$endpoint}");
        $this->assertStringNotContainsString(self::UPSTREAM_MARKER, $operatorText, 'upstream text must not reach the operator');
        if ($urlHidden) {
            $this->assertStringNotContainsString('cipp.example.test', $operatorText, 'the request URL must not reach the operator');
        }
    }

    // ----- group membership, OneDrive, edit user: direct and staged -----

    /** @return array<string, array{0: string}> */
    public static function directTools(): array
    {
        // OneDrive reassignment is held-only, so it has no direct arm.
        return ['group' => ['group'], 'edit' => ['edit']];
    }

    /** @return array<string, array{0: string}> */
    public static function stagedTools(): array
    {
        return ['group' => ['group'], 'onedrive' => ['onedrive'], 'edit' => ['edit']];
    }

    private function unconfirmedBody(string $kind): array
    {
        return match ($kind) {
            'group' => ['Results' => ['Error - '.self::UPSTREAM_MARKER]],
            'onedrive' => ['Results' => ['Failed to change access for sam@acme.example on https://acme-my.sharepoint.com/personal/alex '.self::UPSTREAM_MARKER]],
            'edit' => ['Results' => ['Failed to edit user. '.self::UPSTREAM_MARKER]],
        };
    }

    private function refuseHostFor(string $kind): void
    {
        // The group tools read the live listing first, so the refusal starts
        // after that read; the others have no read before the write.
        if ($kind === 'group') {
            $this->privateAfterRead = true;
        } else {
            $this->hostPrivate = true;
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('directTools')]
    public function test_direct_unconfirmed_answer_hedges(string $kind): void
    {
        $this->configure();
        $t = $this->tool($kind, $this->fixture(), staged: false);
        $this->writeBody = $this->unconfirmedBody($kind);

        $error = $this->runDirect($t);

        $this->assertSame($t['hedge'], $error);
        $this->assertErrorAudited($error, 1, $t['endpoint']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('directTools')]
    public function test_direct_pre_send_refusal_is_definite_and_sends_nothing(string $kind): void
    {
        $this->configure();
        $t = $this->tool($kind, $this->fixture(), staged: false);
        $this->refuseHostFor($kind);

        $error = $this->runDirect($t);

        $this->assertSame($t['definite'], $error);
        $this->assertErrorAudited($error, 0, $t['endpoint']);
        $this->assertStringContainsString('failed before completion', (string) TechnicianActionLog::where('result_status', 'error')->value('summary'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('directTools')]
    public function test_direct_connection_failure_after_send_hedges_and_audits_error(string $kind): void
    {
        $this->configure();
        $t = $this->tool($kind, $this->fixture(), staged: false);
        $this->mode = 'connection';

        $error = $this->runDirect($t);

        $this->assertSame($t['hedge'], $error);
        $this->assertErrorAudited($error, 1, $t['endpoint']);
        $summary = (string) TechnicianActionLog::where('result_status', 'error')->value('summary');
        $this->assertStringContainsString('got no answer and may have been received (unknown)', $summary);
        $this->assertStringNotContainsString(self::UPSTREAM_MARKER, $summary, 'transport text carries the URL; it stays off the row');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stagedTools')]
    public function test_staged_unconfirmed_answer_hedges(string $kind): void
    {
        $approver = $this->configure();
        $t = $this->tool($kind, $this->fixture(), staged: true);
        $this->writeBody = $this->unconfirmedBody($kind);

        $result = $this->runStaged($t, $approver);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame($t['hedge'], $result->message);
        $this->assertErrorAudited((string) $result->message, 1, $t['endpoint']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stagedTools')]
    public function test_staged_pre_send_refusal_is_definite_and_sends_nothing(string $kind): void
    {
        $approver = $this->configure();
        $t = $this->tool($kind, $this->fixture(), staged: true);

        $result = $this->runStaged($t, $approver, fn () => $this->refuseHostFor($kind));

        $this->assertSame('gate_declined', $result->status);
        // The staged toast carries the local refusal reason after the sentence.
        $this->assertStringStartsWith($t['definite'].' ', (string) $result->message);
        $this->assertStringContainsString('private or reserved address', (string) $result->message);
        $this->assertErrorAudited((string) $result->message, 0, $t['endpoint'], urlHidden: false);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stagedTools')]
    public function test_staged_connection_failure_after_send_declines_with_hedge_and_error_row(string $kind): void
    {
        $approver = $this->configure();
        $t = $this->tool($kind, $this->fixture(), staged: true);
        $this->mode = 'connection';

        $result = $this->runStaged($t, $approver);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame($t['hedge'], $result->message);
        $this->assertErrorAudited((string) $result->message, 1, $t['endpoint']);
        $this->assertSame(TechnicianRunState::AwaitingApproval, TechnicianRun::sole()->state);
    }

    public function test_edit_user_partial_answer_names_the_part_cipp_confirmed(): void
    {
        $this->configure();
        $t = $this->tool('edit', $this->fixture(), staged: false);
        $this->writeBody = ['Results' => ['Success. The user has been edited.', "Failed to set alex@acme.example's manager: ".self::UPSTREAM_MARKER]];

        $error = $this->runDirect($t);

        $this->assertSame($t['hedge'].' CIPP reported the profile edit applied.', $error);
        $this->assertErrorAudited($error, 1, 'EditUser');
    }

    // ----- licence tools: the same split, through the licence sentences -----

    private function licenceArgs(array $f, bool $staged): array
    {
        return array_filter([
            'client_id' => $f['client']->id, 'person_id' => $f['person']->id,
            'license_type_id' => $f['licenseType']->id, 'confirm_upn' => 'alex@acme.example',
            'ticket_id' => $staged ? $f['ticket']->id : null, 'reason' => 'Licence change after human review.',
        ], fn ($v) => $v !== null);
    }

    public function test_licence_direct_pre_send_refusal_is_not_applied_and_sends_nothing(): void
    {
        $this->configure(credentials: false);
        $f = $this->fixture();

        $response = $this->callTool('cipp_assign_user_license', $this->licenceArgs($f, staged: false));
        $error = (string) (json_decode((string) $response->json('result.content.0.text'), true)['error'] ?? '');

        $this->assertSame('CIPP write failed for cipp_assign_user_license; the licence assignment was not applied.', $error);
        $this->assertErrorAudited($error, 0, 'ExecBulkLicense');
        $this->assertSame(0, Http::recorded()->count(), 'nothing at all left: not even a token request');
    }

    public function test_licence_direct_connection_failure_after_send_hedges_and_audits_error(): void
    {
        $this->configure();
        $f = $this->fixture();
        $this->mode = 'connection';

        $response = $this->callTool('cipp_assign_user_license', $this->licenceArgs($f, staged: false));
        $error = (string) (json_decode((string) $response->json('result.content.0.text'), true)['error'] ?? '');

        $this->assertSame("The licence assignment for cipp_assign_user_license got no answer from CIPP; it may or may not have applied — verify the user's licences in CIPP before retrying.", $error);
        $this->assertErrorAudited($error, 1, 'ExecBulkLicense');
    }

    public function test_licence_staged_connection_failure_after_send_declines_instead_of_rethrowing(): void
    {
        $approver = $this->configure();
        $f = $this->fixture();
        $staged = json_decode((string) $this->callTool('cipp_stage_remove_user_license', $this->licenceArgs($f, staged: true))
            ->json('result.content.0.text'), true);
        $this->assertTrue($staged['success'] ?? false, json_encode($staged));
        $this->mode = 'connection';

        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun(TechnicianRun::findOrFail($staged['run_id']), $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame("The licence removal for cipp_stage_remove_user_license got no answer from CIPP; it may or may not have applied — verify the user's licences in CIPP before retrying.", $result->message);
        $this->assertErrorAudited((string) $result->message, 1, 'ExecBulkLicense');
    }

    public function test_licence_target_staged_connection_failure_after_send_declines_with_error_row(): void
    {
        $approver = $this->configure();
        $f = $this->fixture();
        $args = ['client_id' => $f['client']->id, 'target_upn' => 'contractor@acme.example', 'sku_id' => self::SKU,
            'ticket_id' => $f['ticket']->id, 'reason' => 'Contractor needs a seat.'];
        $staged = json_decode((string) $this->callTool('cipp_stage_assign_tenant_user_license', $args)->json('result.content.0.text'), true);
        $this->assertTrue($staged['success'] ?? false, json_encode($staged));
        $this->mode = 'connection';

        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun(TechnicianRun::findOrFail($staged['run_id']), $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertStringContainsString('got no answer from CIPP; it may or may not have applied', (string) $result->message);
        $this->assertErrorAudited((string) $result->message, 1, 'ExecBulkLicense');
    }

    public function test_licence_body_that_cannot_be_read_after_send_hedges(): void
    {
        $this->configure();
        $f = $this->fixture();
        $this->mode = 'unread';

        $response = $this->callTool('cipp_assign_user_license', $this->licenceArgs($f, staged: false));
        $error = (string) (json_decode((string) $response->json('result.content.0.text'), true)['error'] ?? '');

        $this->assertSame("The licence assignment for cipp_assign_user_license was sent to CIPP but not confirmed; it may or may not have applied — verify the user's licences in CIPP before retrying.", $error);
        $this->assertErrorAudited($error, 1, 'ExecBulkLicense');
    }

    /**
     * A 4xx on these endpoints hedges: their 4xx semantics have not been read
     * at the vendor source the way ExecBulkLicense's were (Invoke-EditUser,
     * for one, answers 500 from a catch that may follow a write).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('directTools')]
    public function test_direct_http_4xx_hedges_on_an_unchecked_endpoint(string $kind): void
    {
        $this->configure();
        $t = $this->tool($kind, $this->fixture(), staged: false);
        $this->writeStatus = 400;
        $this->writeBody = ['Results' => self::UPSTREAM_MARKER];

        $error = $this->runDirect($t);

        $this->assertSame($t['hedge'], $error);
        $this->assertErrorAudited($error, 1, $t['endpoint']);
    }
}
