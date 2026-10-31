<?php

namespace Tests\Feature\Mcp;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\PhoneCallResolutionProposal;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\PhoneCallResolutionService;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneCallReplaceContactTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $ticket = Ticket::factory()->create();
        $old = Person::create(['client_id' => $ticket->client_id, 'first_name' => 'Synthetic Old', 'is_active' => true]);
        $new = Person::create(['client_id' => $ticket->client_id, 'first_name' => 'Synthetic New', 'is_active' => true]);
        $call = PhoneCall::create(['call_uuid' => 'synthetic-replacement', 'direction' => CallDirection::Outbound,
            'from_number' => '+15555550100', 'status' => CallStatus::Completed, 'started_at' => now(),
            'person_confirmed' => true, 'ticket_id' => $ticket->id, 'is_billable' => false,
            'transcription' => 'SYNTHETIC BODY MUST NOT ENTER AUDIT']);
        $call->forceFill(['client_id' => $ticket->client_id, 'person_id' => $old->id, 'duration' => 120])->save();
        $this->assertSame($old->id, $call->fresh()->person_id);
        $this->assertSame($ticket->client_id, $call->fresh()->client_id);

        return [$call, $old, $new, $ticket, ['phone_call_id' => $call->id, 'client_id' => $ticket->client_id,
            'contact_id' => $new->id, 'reason' => 'Synthetic correction', 'replace_confirmed' => true]];
    }

    private function callTool(array $args, string $grant = 'resolve_phone_call:immediate', string $name = 'resolve_phone_call'): TestResponse
    {
        $token = McpConfig::rotateStaffToken(allowedTools: [$grant], label: 'synthetic-correction');

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args],
        ]);
    }

    private function decoded(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertNull($response->json('error'), json_encode($response->json()));
        $this->assertFalse((bool) $response->json('result.isError'), json_encode($response->json()));

        return json_decode($response->json('result.content.0.text'), true);
    }

    private function untouched(PhoneCall $call, array $before): void
    {
        foreach ($before as $key => $value) {
            if (! in_array($key, ['client_id', 'person_id', 'person_confirmed', 'updated_at'], true)) {
                $this->assertSame($value, $call->fresh()->getAttributes()[$key], $key);
            }
        }
        $this->assertDatabaseCount('ticket_notes', 0);
        $this->assertDatabaseCount('prepay_transactions', 0);
    }

    public function test_immediate_replaces_confirmed_contact_at_same_client(): void
    {
        [$call, $old, $new, $ticket, $args] = $this->fixture();
        $before = $call->fresh()->getAttributes();
        $ticketBefore = $ticket->fresh()->getAttributes();
        $result = $this->decoded($this->callTool($args));
        $this->assertFalse($result['staged']);
        $this->assertSame($new->id, $call->fresh()->person_id);
        $this->assertSame($ticket->client_id, $call->fresh()->client_id);
        $this->assertTrue($call->fresh()->person_confirmed);
        $this->untouched($call, $before);
        $this->assertSame($ticketBefore, $ticket->fresh()->getAttributes());
        $log = TechnicianActionLog::sole();
        $this->assertSame('executed', $log->result_status);
        $this->assertSame("Phone call #{$call->id} client #{$ticket->client_id} contact #{$old->id} -> #{$new->id} (replace_confirmed): Synthetic correction", $log->summary);
        $this->assertStringNotContainsString('SYNTHETIC BODY', $log->toJson());
        $audit = McpAuditLog::where('tool_name', 'resolve_phone_call')->sole();
        $this->assertTrue($audit->arguments['replace_confirmed']);
        $this->assertStringNotContainsString('SYNTHETIC BODY', $audit->toJson());
        $this->assertDatabaseCount('phone_call_resolution_proposals', 0);
    }

    public function test_existing_time_entry_and_billable_call_are_untouched(): void
    {
        [$call, , , $ticket, $args] = $this->fixture();
        $call->update(['is_billable' => true, 'billable_minutes' => 1]);
        $note = TicketNote::create(['ticket_id' => $ticket->id, 'body' => 'Synthetic time entry',
            'time_minutes' => 15, 'billable_minutes' => 10, 'is_billable' => true, 'is_private' => true]);
        $before = $call->fresh()->getAttributes();
        $noteBefore = $note->fresh()->getAttributes();
        $result = $this->decoded($this->callTool($args, 'resolve_phone_call'));
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->postJson(route('phone-call-resolutions.approve', $result['proposal_id']))->assertOk();
        foreach ($before as $key => $value) {
            if (! in_array($key, ['client_id', 'person_id', 'person_confirmed', 'updated_at'], true)) {
                $this->assertSame($value, $call->fresh()->getAttributes()[$key], $key);
            }
        }
        $this->assertSame($noteBefore, $note->fresh()->getAttributes());
        $this->assertDatabaseCount('ticket_notes', 1);
        $this->assertDatabaseCount('prepay_transactions', 0);
    }

    public function test_omitted_flag_preserves_old_identity_refusal(): void
    {
        [$call, , , , $args] = $this->fixture();
        unset($args['replace_confirmed']);
        $before = $call->fresh()->getAttributes();
        $response = $this->callTool($args);
        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('Refusing to replace a different resolved or confirmed caller identity.', $response->json('result.content.0.text'));
        $this->assertSame($before, $call->fresh()->getAttributes());
    }

    public function test_false_flag_preserves_old_identity_refusal(): void
    {
        [$call, , , , $args] = $this->fixture();
        $args['replace_confirmed'] = false;
        $before = $call->fresh()->getAttributes();
        $response = $this->callTool($args);
        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('Refusing to replace a different resolved or confirmed caller identity.', $response->json('result.content.0.text'));
        $this->assertSame($before, $call->fresh()->getAttributes());
        $this->assertFalse(McpAuditLog::where('tool_name', 'resolve_phone_call')->sole()->arguments['replace_confirmed']);
    }

    public static function refusalCases(): array
    {
        return array_combine(['cross_client', 'no_client', 'no_person', 'same_person'], array_map(fn ($v) => [$v], ['cross_client', 'no_client', 'no_person', 'same_person']));
    }

    #[DataProvider('refusalCases')]
    public function test_replace_scope_refusals(string $case): void
    {
        [$call, $old, , , $args] = $this->fixture();
        $call->forceFill(match ($case) {
            'cross_client' => ['client_id' => Client::factory()->create()->id, 'ticket_id' => null],
            'no_client' => ['client_id' => null],
            'no_person' => ['person_id' => null],
            'same_person' => ['person_id' => $args['contact_id']],
        })->save();
        $before = $call->fresh()->getAttributes();
        $response = $this->callTool($args);
        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('replace_confirmed changes only a different existing contact at the call\'s own client; use resolve_phone_call without it for unresolved calls.', $response->json('result.content.0.text'));
        $this->assertSame($before, $call->fresh()->getAttributes());
        $this->assertDatabaseCount('technician_action_logs', 0);
        $this->assertDatabaseCount('phone_call_resolution_proposals', 0);
    }

    public static function stagedGrants(): array
    {
        return [['resolve_phone_call', 'resolve_phone_call'], ['resolve_phone_call:staged', 'resolve_phone_call'], ['resolve_phone_call:immediate', 'stage_resolve_phone_call']];
    }

    #[DataProvider('stagedGrants')]
    public function test_staged_lanes_hold_bind_and_approve(string $grant, string $name): void
    {
        [$call, $old, $new, , $args] = $this->fixture();
        $before = $call->fresh()->getAttributes();
        $result = $this->decoded($this->callTool($args + ['staged' => false], $grant, $name));
        $this->assertTrue($result['staged']);
        $this->assertSame($before, $call->fresh()->getAttributes());
        $proposal = PhoneCallResolutionProposal::findOrFail($result['proposal_id']);
        $this->assertTrue($proposal->payload['replace_confirmed']);
        $this->assertSame($old->id, $proposal->payload['replaces_person_id']);
        $this->assertSame(hash('sha256', json_encode($proposal->payload, JSON_THROW_ON_ERROR)), $proposal->content_hash);
        $this->assertSame('awaiting_approval', TechnicianActionLog::sole()->result_status);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->postJson(route('phone-call-resolutions.approve', $proposal->id))->assertOk()->assertJsonPath('person_id', $new->id);
        $this->assertTrue($call->fresh()->person_confirmed);
        $this->untouched($call, $before);
        $this->assertStringContainsString("contact #{$old->id} -> #{$new->id} (replace_confirmed)", TechnicianActionLog::where('result_status', 'executed')->sole()->summary);
        $this->assertStringNotContainsString('SYNTHETIC BODY', TechnicianActionLog::all()->toJson());
        $this->assertStringNotContainsString('SYNTHETIC BODY', McpAuditLog::all()->toJson());
    }

    public static function staleCases(): array
    {
        return array_map(fn ($v) => [$v], ['person_id', 'client_id', 'person_confirmed', 'ticket_id', 'replaces_person_id', 'replace_confirmed']);
    }

    #[DataProvider('staleCases')]
    public function test_stale_at_approval(string $key): void
    {
        [$call, $old, , , $args] = $this->fixture();
        $result = $this->decoded($this->callTool($args, 'resolve_phone_call'));
        $proposal = PhoneCallResolutionProposal::findOrFail($result['proposal_id']);
        $this->assertSame($old->id, $proposal->payload['replaces_person_id']);
        if (in_array($key, ['replaces_person_id', 'replace_confirmed'], true)) {
            $payload = $proposal->payload;
            $payload[$key] = $key === 'replace_confirmed' ? false : $old->id + 100;
            $proposal->update(['payload' => $payload]);
        } else {
            $call->forceFill([$key => match ($key) {
                'person_id' => Person::create(['client_id' => $call->client_id, 'first_name' => 'Synthetic Third', 'is_active' => true])->id,
                'client_id' => Client::factory()->create()->id,
                'person_confirmed' => false,
                'ticket_id' => null,
            }])->save();
        }
        $before = $call->fresh()->getAttributes();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->postJson(route('phone-call-resolutions.approve', $proposal->id))->assertStatus(409);
        $this->assertSame('stale', $proposal->fresh()->state);
        $this->assertSame($before, $call->fresh()->getAttributes());
        $this->assertDatabaseMissing('technician_action_logs', ['result_status' => 'executed']);
    }

    public static function targetFailures(): array
    {
        return [['inactive_client'], ['inactive_contact'], ['ownership'], ['ticket_client'], ['missing_ticket']];
    }

    #[DataProvider('targetFailures')]
    public function test_replacement_revalidates_targets_at_execution_and_approval(string $case): void
    {
        [$call, , $new, $ticket, $args] = $this->fixture();
        $result = $this->decoded($this->callTool($args, 'resolve_phone_call'));
        match ($case) {
            'inactive_client' => Client::findOrFail($ticket->client_id)->update(['is_active' => false]),
            'inactive_contact' => $new->update(['is_active' => false]),
            'ownership' => $new->update(['client_id' => Client::factory()->create()->id]),
            'ticket_client' => $ticket->update(['client_id' => Client::factory()->create()->id]),
            'missing_ticket' => $ticket->delete(),
        };
        $before = $call->fresh()->getAttributes();
        $this->assertTrue($this->callTool($args)->json('result.isError'));
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->postJson(route('phone-call-resolutions.approve', $result['proposal_id']))->assertStatus(409);
        $this->assertSame($before, $call->fresh()->getAttributes());
        $this->assertDatabaseMissing('technician_action_logs', ['result_status' => 'executed']);
    }

    public function test_invalid_boolean_and_unknown_key_are_refused(): void
    {
        [$call, , , , $args] = $this->fixture();
        $before = $call->fresh()->getAttributes();
        foreach (['resolve_phone_call', 'stage_resolve_phone_call'] as $name) {
            $response = $this->callTool(array_replace($args, ['replace_confirmed' => 'true']), name: $name);
            $this->assertSame(-32602, $response->json('error.code'));
            $this->assertSame('Caller resolution requires a positive integer client_id and boolean staged and replace_confirmed when supplied.', $response->json('error.message'));
        }
        $response = $this->callTool($args + ['unexpected' => true]);
        $this->assertTrue($response->json('result.isError'));
        $serviceArgs = $args;
        unset($serviceArgs['client_id']);
        $this->assertArrayHasKey('error', app(PhoneCallResolutionService::class)->execute(array_replace($serviceArgs, ['replace_confirmed' => 'true']), $args['client_id'], 'synthetic', false));
        $this->assertSame($before, $call->fresh()->getAttributes());
        $this->assertDatabaseCount('technician_action_logs', 0);
    }

    public function test_cockpit_replacement_line_is_escaped_and_absent_for_ordinary_proposals(): void
    {
        [, $old, , , $args] = $this->fixture();
        $this->decoded($this->callTool($args, 'resolve_phone_call'));
        $proposal = PhoneCallResolutionProposal::sole();
        $render = fn () => view('cockpit.partials.phone-call-resolutions', ['phoneCallResolutions' => PhoneCallResolutionProposal::all(), 'canApprovePhoneCallResolution' => true])->render();
        $this->assertStringContainsString("Replaces contact #{$old->id} at the same client.", $render());
        $payload = $proposal->payload;
        $payload['replaces_person_id'] = '<script>synthetic</script>';
        $proposal->update(['payload' => $payload]);
        $html = $render();
        $this->assertStringContainsString('Replaces contact #&lt;script&gt;synthetic&lt;/script&gt; at the same client.', $html);
        $this->assertStringNotContainsString('<script>synthetic</script>', $html);
        unset($payload['replace_confirmed'], $payload['replaces_person_id']);
        $proposal->update(['payload' => $payload]);
        $this->assertStringNotContainsString('Replaces contact #', $render());
    }

    public function test_schema_advertises_optional_boolean_for_both_names(): void
    {
        foreach ([false, true] as $internal) {
            $tool = McpToolRegistry::resolvePhoneCallTool($internal);
            $this->assertSame(['type' => 'boolean', 'default' => false], $tool['input_schema']['properties']['replace_confirmed']);
            $this->assertNotContains('replace_confirmed', $tool['input_schema']['required']);
            $this->assertStringContainsString('replace_confirmed=true corrects a different existing contact at the call\'s own client (same client only, never unresolved calls).', $tool['description']);
        }
    }
}
