<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Mcp\StaffCippWriteToolExecutor;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CippWriteMailboxDelegateTargetUpnTest extends TestCase
{
    use RefreshDatabase;

    private array $args;

    private array $rows;

    private int $listingStatus = 200;

    private User $actor;

    private Person $delegate;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'synthetic-tenant');
        Setting::setValue('cipp_client_id', 'synthetic-client');
        Setting::setEncrypted('cipp_client_secret', 'synthetic-value');
        $this->actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $this->actor->id);
        $client = Client::factory()->create(['cipp_tenant_domain' => 'contoso.example']);
        $this->delegate = Person::create([
            'client_id' => $client->id, 'person_type' => PersonType::User,
            'first_name' => 'Synthetic', 'last_name' => 'Delegate', 'email' => 'delegate@contoso.example',
            'cipp_upn' => 'delegate@contoso.example', 'cipp_user_id' => 'synthetic-delegate', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->for($client)->create();
        $this->args = [
            'client_id' => $client->id, 'ticket_id' => $ticket->id, 'staged' => true,
            'target_mailbox_upn' => 'shared@contoso.example', 'delegate_person_id' => $this->delegate->id,
            'permission' => 'full_access', 'operation' => 'grant', 'reason' => 'Synthetic coverage',
        ];
        // CIPP-API Invoke-ListMailboxes.ps1 live projection.
        $this->rows = [['UPN' => 'shared@contoso.example', 'primarySmtpAddress' => 'shared@contoso.example', 'recipientTypeDetails' => 'SharedMailbox']];
        $this->token = McpConfig::rotateStaffToken(allowedTools: ['cipp_set_mailbox_delegate'], label: 'synthetic');
        $this->app->instance(CippRestWriteClient::class, new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test', 'tenant_id' => 'synthetic-tenant',
            'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-value',
        ], Cache::store(), fn () => ['93.184.216.34']));
        $this->fake();
    }

    private function fake(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/synthetic-tenant/oauth2/v2.0/token' => Http::response(['access_token' => 'synthetic-token']),
            'https://cipp.example.test/api/ListMailboxes*' => fn () => Http::response($this->rows, $this->listingStatus),
            'https://cipp.example.test/api/ExecEditMailboxPermissions' => Http::response(['Results' => ['Success']]),
        ]);
    }

    private function callTarget(array $args): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'cipp_set_mailbox_delegate', 'arguments' => $args],
        ])->json();
    }

    private function stage(): TechnicianRun
    {
        $reply = $this->callTarget($this->args);
        $this->assertFalse($reply['result']['isError'] ?? false, json_encode($reply));
        $data = json_decode($reply['result']['content'][0]['text'], true);
        $this->assertArrayHasKey('run_id', $data, json_encode($reply));

        return TechnicianRun::findOrFail($data['run_id']);
    }

    private function refused(array $args, string $text = ''): void
    {
        $reply = $this->callTarget($args);
        $this->assertTrue(isset($reply['error']) || ($reply['result']['isError'] ?? false), json_encode($reply));
        if ($text !== '') {
            $this->assertStringContainsString($text, json_encode($reply));
        }
        $this->assertSame(0, TechnicianRun::count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/Exec'));
    }

    public function test_shared_stages_case_insensitively_and_approval_uses_fresh_listing(): void
    {
        $this->args['target_mailbox_upn'] = 'SHARED@CONTOSO.EXAMPLE';
        $run = $this->stage();
        $this->assertSame('SharedMailbox', $this->evidence($run)['verified_mailbox']['recipient_type_details']);
        $this->assertStringContainsString('shared@contoso.example (SharedMailbox)', $run->proposed_content);
        $this->actingAs($this->actor)->get(route('cockpit.index'))
            ->assertOk()->assertSee('shared@contoso.example (SharedMailbox)');
        $this->actingAs($this->actor)->post(route('cockpit.approve', $run))->assertRedirect();
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/ExecEditMailboxPermissions') && $request->data() === [
            'TenantFilter' => 'contoso.example', 'UserID' => 'shared@contoso.example',
            'AddFullAccess' => [['value' => 'delegate@contoso.example', 'label' => 'delegate@contoso.example']],
            'AddFullAccessNoAutoMap' => [], 'RemoveFullAccess' => [], 'AddSendAs' => [],
            'RemoveSendAs' => [], 'AddSendOnBehalf' => [], 'RemoveSendOnBehalf' => [],
        ]);
    }

    public function test_target_evidence_is_encrypted_and_redacted_dedup_is_keyed(): void
    {
        $this->args['target_mailbox_upn'] = '  SHARED@CONTOSO.EXAMPLE  ';
        $run = $this->stage();
        $meta = $run->proposed_meta;
        unset($meta['encrypted_payload']);
        $plain = json_encode($meta, JSON_THROW_ON_ERROR);
        foreach (['@', 'contoso.example', 'SharedMailbox', 'verified_delegate_upn', 'verified_tenant', 'verified_mailbox', 'target_mailbox_upn'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $plain);
        }
        $params = $run->proposed_meta['redacted_params'];
        $this->assertSame([
            'permission' => 'full_access', 'operation' => 'grant', 'auto_map' => true,
            'delegate_person_id' => $this->delegate->id,
            'target_mailbox_key' => hash_hmac('sha256', 'shared@contoso.example', (string) config('app.key')),
        ], $params);
        $evidence = $this->evidence($run);
        $this->assertSame('shared@contoso.example', $evidence['target_mailbox_upn']);
        $this->assertSame('delegate@contoso.example', $evidence['verified_delegate_upn']);
        $this->assertSame('contoso.example', $evidence['verified_tenant']);
        $this->assertSame('SharedMailbox', $evidence['verified_mailbox']['recipient_type_details']);
        $this->args['target_mailbox_upn'] = 'shared@contoso.example';
        $this->assertSame($run->id, $this->stage()->id);
        $owner = $this->delegate->replicate();
        $owner->email = $owner->cipp_upn = 'shared@contoso.example';
        $owner->cipp_user_id = 'synthetic-owner';
        $owner->is_active = false;
        $owner->save();
        $personArgs = $this->args;
        unset($personArgs['target_mailbox_upn']);
        $reply = $this->callTarget($personArgs + ['person_id' => $owner->id, 'confirm_upn' => 'shared@contoso.example']);
        $this->assertFalse($reply['result']['isError'] ?? false, json_encode($reply));
        $personData = json_decode($reply['result']['content'][0]['text'], true);
        $personRun = TechnicianRun::findOrFail($personData['run_id']);
        $this->assertNotSame($run->id, $personRun->id);
        $this->assertNotSame($run->content_hash, $personRun->content_hash);
        $this->args['target_mailbox_upn'] = $this->rows[0]['UPN'] = $this->rows[0]['primarySmtpAddress'] = 'other@contoso.example';
        $other = $this->stage();
        $this->assertNotSame($run->content_hash, $other->content_hash);
    }

    private function evidence(TechnicianRun $run): array
    {
        return json_decode(\Illuminate\Support\Facades\Crypt::decryptString($run->proposed_meta['encrypted_payload']), true, flags: JSON_THROW_ON_ERROR)['params'];
    }

    public function test_remove_with_inactive_delegate_stages_and_executes(): void
    {
        $this->delegate->update(['is_active' => false]);
        $this->args['operation'] = 'remove';
        $run = $this->stage();
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $this->actor->id);
        $this->assertSame('executed', $result->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/ExecEditMailboxPermissions')
            && $request['UserID'] === 'shared@contoso.example'
            && $request['RemoveFullAccess'] === [['value' => 'delegate@contoso.example', 'label' => 'delegate@contoso.example']]
            && $request['AddFullAccess'] === []);
    }

    public function test_kill_switch_at_stage_blocks_once_before_any_read(): void
    {
        Setting::setValue('technician_kill_switch', '1');
        $reply = $this->callTarget($this->args);
        $this->assertTrue($reply['result']['isError']);
        $data = json_decode($reply['result']['content'][0]['text'], true);
        $this->assertSame('Technician kill-switch engaged; CIPP MCP write refused', $data['error']);
        $this->assertSame(0, TechnicianRun::count());
        Http::assertNothingSent();
        $audit = TechnicianActionLog::sole();
        $this->assertSame('blocked', $audit->result_status);
        $this->assertSame('cipp_stage_set_mailbox_delegate', $audit->action_type);
        $this->assertSame($this->args['client_id'], $audit->client_id);
        $this->assertSame('Technician kill-switch engaged; CIPP MCP write refused.', $audit->summary);
    }

    public function test_kill_switch_at_approval_blocks_once_and_releases_claim(): void
    {
        $run = $this->stage();
        $auditId = TechnicianActionLog::max('id');
        Http::assertSentCount(2);
        Setting::setValue('technician_kill_switch', '1');

        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $this->actor->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame('Technician kill-switch engaged; the staged CIPP write was refused.', $result->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertNotNull($run->fresh()->claimed_at);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/Exec'));
        $audits = TechnicianActionLog::where('id', '>', $auditId)->get();
        $this->assertCount(1, $audits);
        $audit = $audits->sole();
        $this->assertSame('blocked', $audit->result_status);
        $this->assertSame($run->id, $audit->run_id);
        $this->assertSame($run->client_id, $audit->client_id);
        $this->assertSame($run->content_hash, $audit->content_hash);
        $this->assertSame($this->actor->id, $audit->approver_user_id);
        $this->assertSame('shared@contoso.example: Technician kill-switch engaged; staged CIPP write refused.', $audit->summary);
    }

    public function test_room_and_equipment_are_allowed(): void
    {
        foreach (['RoomMailbox', 'EquipmentMailbox'] as $type) {
            $this->rows[0]['recipientTypeDetails'] = $type;
            $run = $this->stage();
            $this->assertSame($type, $this->evidence($run)['verified_mailbox']['recipient_type_details']);
        }
    }

    public function test_user_mailbox_is_refused(): void
    {
        $this->rows[0]['recipientTypeDetails'] = 'UserMailbox';
        $this->refused($this->args, 'person_id');
    }

    public function test_unknown_and_out_of_tenant_are_refused(): void
    {
        foreach (['unknown@contoso.example', 'shared@other.example'] as $upn) {
            $this->refused(array_replace($this->args, ['target_mailbox_upn' => $upn]), 'person_id');
        }
    }

    public function test_empty_listing_is_refused(): void
    {
        $this->rows = [];
        $this->refused($this->args, 'person_id');
    }

    public function test_listing_error_duplicates_and_missing_or_unknown_type_are_refused(): void
    {
        $this->listingStatus = 503;
        $this->refused($this->args, 'status 503');
        $audit = TechnicianActionLog::latest('id')->first();
        $this->assertSame('error', $audit->result_status);
        $this->assertStringContainsString('status 503', $audit->summary);
        $this->assertStringNotContainsString('person_id', $audit->summary);
        $this->assertStringNotContainsString('contoso.example', $audit->summary);
        $this->listingStatus = 200;
        $this->rows[] = $this->rows[0];
        $this->refused($this->args, 'person_id');
        array_pop($this->rows);
        unset($this->rows[0]['recipientTypeDetails']);
        $this->refused($this->args, 'person_id');
        $this->rows[0]['recipientTypeDetails'] = 'UnknownMailbox';
        $this->refused($this->args, 'person_id');
    }

    public function test_active_mapping_refuses_and_inactive_mapping_is_recorded(): void
    {
        $mapped = $this->delegate->replicate();
        $mapped->email = $mapped->cipp_upn = 'shared@contoso.example';
        $mapped->cipp_user_id = 'synthetic-shared';
        $mapped->save();
        $this->refused($this->args, 'person_id');
        $mapped->update(['is_active' => false]);
        $run = $this->stage();
        $this->assertSame($mapped->id, $this->evidence($run)['verified_mailbox']['inactive_person_id']);
    }

    public function test_active_mapping_on_primary_smtp_refuses_and_inactive_is_recorded(): void
    {
        $this->args['target_mailbox_upn'] = 'shared@tenant.onmicrosoft.example';
        $this->rows[0]['UPN'] = 'shared@tenant.onmicrosoft.example';
        $mapped = $this->delegate->replicate();
        $mapped->email = $mapped->cipp_upn = 'shared@contoso.example';
        $mapped->cipp_user_id = 'synthetic-shared';
        $mapped->save();
        $this->refused($this->args, 'active PSA person');
        $mapped->update(['is_active' => false]);
        $run = $this->stage();
        $this->assertSame($mapped->id, $this->evidence($run)['verified_mailbox']['inactive_person_id']);
    }

    public function test_active_mapping_on_alias_or_object_id_refuses_and_inactive_is_recorded(): void
    {
        $mapped = $this->delegate->replicate();
        $mapped->email = $mapped->cipp_upn = 'frontdesk@contoso.example';
        $mapped->cipp_user_id = 'synthetic-shared-object';
        $mapped->save();
        // Projected alias list.
        $this->rows[0]['AdditionalEmailAddresses'] = 'reception@contoso.example, FrontDesk@contoso.example';
        $this->refused($this->args, 'active PSA person');
        // Raw EXO proxy addresses.
        unset($this->rows[0]['AdditionalEmailAddresses']);
        $this->rows[0]['EmailAddresses'] = ['SMTP:shared@contoso.example', 'smtp:frontdesk@contoso.example'];
        $this->refused($this->args, 'active PSA person');
        // Object id with no shared address.
        unset($this->rows[0]['EmailAddresses']);
        $mapped->update(['email' => 'elsewhere@contoso.example', 'cipp_upn' => 'elsewhere@contoso.example']);
        $this->rows[0]['ExternalDirectoryObjectId'] = 'SYNTHETIC-SHARED-OBJECT';
        $this->refused($this->args, 'active PSA person');
        $mapped->update(['is_active' => false]);
        $run = $this->stage();
        $this->assertSame($mapped->id, $this->evidence($run)['verified_mailbox']['inactive_person_id']);
    }

    public function test_listing_failure_at_approval_declines_as_error_not_type_refusal(): void
    {
        $run = $this->stage();
        $auditId = TechnicianActionLog::max('id');
        $this->listingStatus = 503;

        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $this->actor->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertStringContainsString('status 503', $result->message);
        $this->assertStringNotContainsString('person_id', $result->message);
        $this->assertStringNotContainsString('contoso.example', $result->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/Exec'));
        $audit = TechnicianActionLog::where('id', '>', $auditId)->sole();
        $this->assertSame('error', $audit->result_status);
        $this->assertSame($run->id, $audit->run_id);
        $this->assertStringContainsString('status 503', $audit->summary);
        $this->assertStringNotContainsString('contoso.example', $audit->summary);
    }

    public function test_both_and_neither_shape_are_refused(): void
    {
        $this->refused(array_replace($this->args, ['person_id' => $this->delegate->id]));
        $args = $this->args;
        unset($args['target_mailbox_upn']);
        $this->refused($args);
        Http::assertNothingSent();
    }

    public function test_direct_executor_refuses_target_upn(): void
    {
        $result = app(StaffCippWriteToolExecutor::class)->execute('cipp_set_mailbox_delegate', $this->args, $this->args['client_id'], 'synthetic');
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, TechnicianRun::count());
        Http::assertNothingSent();
    }

    public function test_approval_declines_type_drift_and_disappearance(): void
    {
        $run = $this->stage();
        $this->rows[0]['recipientTypeDetails'] = 'UserMailbox';
        $this->actingAs($this->actor)->post(route('cockpit.approve', $run))->assertRedirect();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->rows = [];
        $this->actingAs($this->actor)->post(route('cockpit.approve', $run))->assertRedirect();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/Exec'));
    }

    public function test_inactive_delegate_and_self_delegation_are_refused(): void
    {
        $this->delegate->update(['is_active' => false]);
        $this->refused($this->args);
        $this->delegate->update(['cipp_upn' => 'shared@contoso.example']);
        $this->refused(array_replace($this->args, ['operation' => 'remove']), 'Self-delegation');
    }

    public function test_active_mapping_and_empty_listing_at_approval_decline(): void
    {
        $run = $this->stage();
        $this->delegate->update(['email' => 'shared@contoso.example']);
        $this->actingAs($this->actor)->post(route('cockpit.approve', $run))->assertRedirect();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->delegate->update(['email' => 'delegate@contoso.example']);
        $this->rows = [];
        $this->actingAs($this->actor)->post(route('cockpit.approve', $run))->assertRedirect();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/Exec'));
    }

    public function test_staged_only_token_still_requires_explicit_true(): void
    {
        $this->token = McpConfig::rotateStaffToken(allowedTools: ['cipp_set_mailbox_delegate:staged'], label: 'synthetic-held');
        $this->refused(array_replace($this->args, ['staged' => false]));
        Http::assertNothingSent();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $this->stage()->state);
    }

    public function test_execute_at_refuses_before_any_read(): void
    {
        $this->refused(array_replace($this->args, ['execute_at' => now()->addHour()->toIso8601String()]), 'person_id');
        Http::assertNothingSent();
    }

    public function test_staged_absent_or_false_refuses_before_any_read(): void
    {
        $args = $this->args;
        unset($args['staged']);
        $this->refused($args, 'staged');
        $this->refused(array_replace($args, ['staged' => false]), 'staged');
        Http::assertNothingSent();
    }
}
