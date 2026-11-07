<?php

namespace Tests\Feature\Ai;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Agent\SendReplyTool;
use App\Services\Agent\TechnicianAgentSurface;
use App\Services\Ai\AiClient;
use App\Services\Technician\TechnicianDraft;
use App\Services\Technician\TechnicianReplyDrafter;
use App\Services\Triage\TriageToolDefinitions;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AiClientArgumentGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
        Log::spy();
    }

    // Both entry points: refusal reaches the model, never progress/executor; declared input runs.
    public function test_unknown_keys_are_refused_on_both_wrappers_and_declared_keys_run(): void
    {
        foreach ([false, true] as $chat) {
            $history = $calls = $progress = [];
            $ai = $this->ai([['mesh_search_email_logs', ['query' => 'PRIVATE-VALUE']], ['mesh_search_email_logs', ['from' => 'fixture@example.test']]], $history);
            $tools = TriageToolDefinitions::meshTools();
            $this->invokeLoop($ai, $chat, $tools, function ($name, $input) use (&$calls) {
                $calls[] = [$name, $input];

                return ['ok' => true];
            }, [], $progress);
            $this->assertSame([['mesh_search_email_logs', ['from' => 'fixture@example.test']]], $calls);
            if ($chat) {
                $this->assertSame(['mesh_search_email_logs'], $progress);
            }
            $error = $this->results($history)[0]['content'];
            $this->assertStringContainsString('query', $error);
            $this->assertStringContainsString('accepts only: from, size, status, subject, to', $error);
            $this->assertStringContainsString('REFUSED', $error);
            $this->assertStringNotContainsString('PRIVATE-VALUE', $error);
        }
        Log::shouldHaveReceived('warning')->with('[AiClient] Undeclared tool arguments', ['tool' => 'mesh_search_email_logs', 'keys' => ['query'], 'action' => 'refused'])->twice();
    }

    public function test_published_send_reply_on_non_technician_chat_has_no_implicit_exemption(): void
    {
        $history = $calls = $progress = [];
        $ai = $this->ai([['send_reply', ['reason' => 'x', 'to' => 'PRIVATE', 'body' => 'PRIVATE']]], $history);
        $this->invokeLoop($ai, true, [SendReplyTool::definition()], function ($name, $input) use (&$calls) {
            $calls[] = $input;

            return [];
        }, [], $progress);
        $this->assertSame([], $calls);
        $this->assertSame([], $progress);
        $this->assertStringContainsString('body, to', $this->results($history)[0]['content']);
        Log::shouldHaveReceived('warning')->with('[AiClient] Undeclared tool arguments', ['tool' => 'send_reply', 'keys' => ['body', 'to'], 'action' => 'refused'])->once();
    }

    public function test_technician_strips_to_exactly_reason_and_holds_server_draft(): void
    {
        User::factory()->create();
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create(['status' => TicketStatus::InProgress]);
        TicketNote::create(['ticket_id' => $ticket->id, 'author_name' => 'Fixture', 'who_type' => WhoType::EndUser, 'ai_authored' => false, 'body' => 'Update?', 'note_type' => NoteType::Reply, 'is_private' => false, 'noted_at' => now()]);
        $this->mock(TechnicianReplyDrafter::class, fn ($m) => $m->shouldReceive('draft')->once()->andReturn(new TechnicianDraft('Server body.', 'fixture@example.test', 100)));
        $surface = TechnicianAgentSurface::forTicket($ticket);
        $reply = collect($surface->tools())->firstWhere('name', 'send_reply');
        $this->assertIsArray($reply['input_schema']['properties']);
        $this->assertSame(['reason'], array_keys($reply['input_schema']['properties']));
        $history = $calls = $progress = [];
        $ai = $this->ai([['send_reply', ['reason' => 'x', 'to' => 'smuggled@example.test', 'body' => 'SMUGGLED']]], $history);
        $executor = $surface->executor();
        $this->invokeLoop($ai, false, $surface->tools(), function ($name, $input) use (&$calls, $executor) {
            $calls[] = $input;

            return $executor($name, $input);
        }, $surface->ignoreUndeclaredArgumentsFor(), $progress);
        $this->assertSame([['reason' => 'x']], $calls);
        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->sole();
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->state);
        $this->assertSame('Server body.', $run->proposed_content);
        $this->assertSame('fixture@example.test', $run->proposed_meta['to']);
        Log::shouldHaveReceived('warning')->with('[AiClient] Undeclared tool arguments', ['tool' => 'send_reply', 'keys' => ['body', 'to'], 'action' => 'ignored'])->once();
    }

    public function test_technician_propose_close_is_not_exempt(): void
    {
        $surface = TechnicianAgentSurface::forTicket(Ticket::factory()->create());
        $history = $calls = $progress = [];
        $ai = $this->ai([['propose_close', ['reason' => 'x', 'extra' => 'PRIVATE']]], $history);
        $this->invokeLoop($ai, false, $surface->tools(), function ($name, $input) use (&$calls) {
            $calls[] = $input;

            return [];
        }, $surface->ignoreUndeclaredArgumentsFor(), $progress);
        $this->assertSame([], $calls);
        $this->assertStringContainsString('extra', $this->results($history)[0]['content']);
    }

    public function test_all_thirteen_real_empty_maps_refuse_keys_but_accept_empty_calls(): void
    {
        Setting::setEncrypted('controld_api_key', 'fixture-only');
        Setting::setValue('controld_stats_endpoint', 'https://example.test');
        $tools = [];
        foreach ((new \ReflectionClass(TriageToolDefinitions::class))->getMethods() as $method) {
            if (str_ends_with($method->name, 'Tools') && ! in_array($method->name, ['getTools', 'readTools'])) {
                foreach ($method->invoke(null) as $tool) {
                    $tools[$tool['name']] = $tool;
                }
            }
        }
        $this->assertCount(59, $tools);
        $empty = array_values(array_filter($tools, fn ($tool) => isset($tool['input_schema']['properties']) && (array) $tool['input_schema']['properties'] === []));
        $this->assertCount(13, $empty);
        $modelCalls = [];
        foreach ($empty as $tool) {
            $modelCalls[] = [$tool['name'], ['bogus' => 'PRIVATE']];
            $modelCalls[] = [$tool['name'], []];
        }
        $history = $calls = $progress = [];
        $ai = $this->ai($modelCalls, $history);
        $this->invokeLoop($ai, false, $empty, function ($name, $input) use (&$calls) {
            $calls[] = [$name, $input];

            return [];
        }, [], $progress);
        $this->assertSame(array_map(fn ($tool) => [$tool['name'], []], $empty), $calls);
        foreach ($this->results($history) as $i => $result) {
            if ($i % 2 === 0) {
                $this->assertStringContainsString('accepts no arguments', $result['content']);
            }
        }
    }

    public function test_unresolved_and_explicitly_open_schemas_pass_through_even_when_exempt(): void
    {
        foreach ([['type' => 'object'], ['properties' => null], ['properties' => ['reason' => []], 'additionalProperties' => true], ['properties' => [], 'additionalProperties' => ['type' => 'string']], ['properties' => [], 'additionalProperties' => null]] as $schema) {
            foreach ([false, true] as $chat) {
                $history = $calls = $progress = [];
                $input = ['reason' => 'x', 'extra' => 'PRIVATE'];
                $ai = $this->ai([['send_reply', $input]], $history);
                $this->invokeLoop($ai, $chat, [['name' => 'send_reply', 'input_schema' => $schema]], function ($name, $args) use (&$calls) {
                    $calls[] = $args;

                    return [];
                }, ['send_reply'], $progress);
                $this->assertSame([$input], $calls);
            }
        }
        Log::shouldNotHaveReceived('warning');
    }

    public function test_only_top_level_keys_are_checked_and_closed_stdclass_maps_work(): void
    {
        $history = $calls = $progress = [];
        $schema = ['name' => 'nested', 'input_schema' => ['properties' => (object) ['payload' => ['type' => 'object']], 'additionalProperties' => false]];
        $ai = $this->ai([['nested', ['payload' => ['nested-extra' => 'value']]], ['nested', ['extra' => 'value']]], $history);
        $this->invokeLoop($ai, true, [$schema], function ($name, $args) use (&$calls) {
            $calls[] = $args;

            return [];
        }, [], $progress);
        $this->assertSame([['payload' => ['nested-extra' => 'value']]], $calls);
        $this->assertStringContainsString('extra', $this->results($history)[1]['content']);
    }

    public function test_refusal_sorts_and_caps_unknown_key_names_without_values(): void
    {
        $history = $calls = $progress = [];
        $input = [];
        for ($i = 12; $i >= 1; $i--) {
            $input[sprintf('key%02d', $i)] = 'PRIVATE';
        }
        $ai = $this->ai([['empty', $input]], $history);
        $this->invokeLoop($ai, false, [['name' => 'empty', 'input_schema' => ['properties' => []]]], function () use (&$calls) {
            $calls[] = true;
        }, [], $progress);
        $error = $this->results($history)[0]['content'];
        $this->assertSame([], $calls);
        $this->assertStringContainsString('key01, key02, key03, key04, key05, key06, key07, key08, key09, key10', $error);
        $this->assertStringNotContainsString('key11', $error);
        $this->assertStringNotContainsString('PRIVATE', $error);
    }

    public function test_loop_cipp_refusal_names_user_id_and_preserves_empty_call(): void
    {
        $tools = array_values(array_filter(TriageToolDefinitions::cippTools(), fn ($tool) => $tool['name'] === 'cipp_list_oauth_apps'));
        $this->assertCount(1, $tools);
        $history = $calls = $progress = [];
        $ai = $this->ai([['cipp_list_oauth_apps', ['user_id' => 'PRIVATE']], ['cipp_list_oauth_apps', []]], $history);
        $this->invokeLoop($ai, false, $tools, function ($name, $args) use (&$calls) {
            $calls[] = $args;

            return [];
        }, [], $progress);
        $this->assertSame([[]], $calls);
        $this->assertStringContainsString('user_id. cipp_list_oauth_apps accepts no arguments', $this->results($history)[0]['content']);
    }

    private function invokeLoop(AiClient $ai, bool $chat, array $tools, callable $executor, array $ignore, array &$progress): void
    {
        if ($chat) {
            $ai->runChatWithTools('sys', [['role' => 'user', 'content' => 'fixture']], $tools, $executor, onToolCall: function ($name) use (&$progress) {
                $progress[] = $name;
            }, ignoreUndeclaredArgumentsFor: $ignore);
        } else {
            $ai->runToolLoop('sys', 'fixture', $tools, $executor, ignoreUndeclaredArgumentsFor: $ignore);
        }
    }

    private function ai(array $calls, array &$history): AiClient
    {
        $content = [];
        foreach ($calls as $i => [$name, $input]) {
            $content[] = ['type' => 'tool_use', 'id' => 'tool_'.$i, 'name' => $name, 'input' => $input];
        }
        $response = fn ($blocks) => new Response(200, ['Content-Type' => 'application/json'], json_encode(['content' => $blocks, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]));
        $stack = HandlerStack::create(new MockHandler([$response($content), $response([['type' => 'text', 'text' => 'done']])]));
        $stack->push(Middleware::history($history));

        return new AiClient(http: new GuzzleClient(['handler' => $stack]));
    }

    private function results(array $history): array
    {
        $this->assertCount(2, $history);
        $body = json_decode((string) $history[1]['request']->getBody(), true);

        return $body['messages'][2]['content'];
    }
}
