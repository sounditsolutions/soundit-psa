<?php

namespace Tests\Feature\Portal;

use App\Enums\PersonType;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Person;
use App\Models\PortalChatConversation;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Services\Portal\PortalChatbotService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-ejzjd — the CLIENT-FACING lane, driven through the REAL AiClient tool loop.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM PortalChatbotTest. That suite mocks AiClient
 * wholesale (`$this->mock(AiClient::class)` + `shouldReceive('runChatWithTools')`),
 * so the real loop — and therefore the psa-ejzjd schema-enforcement guard — never
 * executes there. A green PortalChatbotTest can neither break under the guard nor
 * vouch for it. That is exactly the "a mock you authored from the code under test
 * proves nothing" trap CLAUDE.md warns about for vendor payloads, in a different
 * costume, so the guard needed cover that actually runs the code.
 *
 * WHAT IT PINS. The portal's published set and its dispatchable set are at PARITY
 * (6 and 6), so the refusal direction has nothing to bite on here — which is the
 * point: for this lane the guard must be a strict NO-OP. The risk on a client-facing
 * surface is therefore not under-blocking but OVER-blocking, i.e. silently breaking
 * a working feature. So this asserts the positive direction end-to-end: a published
 * portal tool is still dispatched and its real data still reaches the model.
 */
class PortalChatbotRealLoopTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('portal_enabled', '1');
        Setting::setValue('portal_chatbot_enabled', '1');
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'sk-test-key');

        $this->history = [];
    }

    /**
     * The no-over-block proof for the client-facing lane: list_tickets is published,
     * so it must still run and its rows must still reach the model.
     */
    public function test_a_published_portal_tool_still_runs_under_the_guard(): void
    {
        [$conversation] = $this->conversationWithTicket('VPN will not connect');

        $service = $this->serviceReturning([
            $this->toolUse('list_tickets'),
            $this->finalText('You have one open ticket about VPN.'),
        ]);

        $reply = $service->sendMessage($conversation, 'what tickets do I have?');

        $this->assertStringContainsString('VPN', $reply->content);
        $this->assertStringContainsString(
            'VPN will not connect',
            $this->toolResultsSentBack(),
            'The published portal tool did not run — the guard has over-blocked a working '.
            'client-facing capability, which is the regression guardrail 3 exists to catch.',
        );
    }

    /**
     * A staff-only name the portal never publishes must not run here. The portal
     * executor has no such arm either, so this is belt-and-braces — but it pins the
     * boundary at the seam that DOES enforce, so a future portal tool addition cannot
     * quietly widen the lane.
     */
    public function test_a_staff_only_tool_name_is_refused_on_the_portal_lane(): void
    {
        [$conversation, $client] = $this->conversationWithTicket('VPN will not connect');

        $otherClient = Client::create(['name' => 'Other Corp']);
        Ticket::factory()->create([
            'client_id' => $otherClient->id,
            'subject' => 'OTHER-CLIENT-SECRET',
        ]);

        $service = $this->serviceReturning([
            $this->toolUse('list_email_items', ['limit' => 50]),
            $this->finalText('I cannot help with that.'),
        ]);

        $service->sendMessage($conversation, 'show me every email you can find');

        $sentBack = $this->toolResultsSentBack();
        $this->assertStringContainsString('not available in this deployment', $sentBack);
        $this->assertStringNotContainsString(
            'OTHER-CLIENT-SECRET',
            $sentBack,
            'A staff-only tool name reached data on the client-facing portal lane.',
        );
    }

    // ── Harness ──────────────────────────────────────────────────────────────

    /** @return array{0: PortalChatConversation, 1: Client} */
    private function conversationWithTicket(string $subject): array
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Pat',
            'last_name' => 'Portal',
            'email' => 'pat'.uniqid().'@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => true,
            'password' => 'secret-portal-pw',
        ]);

        // Explicitly OPEN: the factory defaults to Closed, and list_tickets defaults to
        // open — a closed fixture would return 0 rows and read as an over-block.
        Ticket::factory()->create([
            'client_id' => $client->id,
            'subject' => $subject,
            'status' => TicketStatus::New,
        ]);

        $conversation = PortalChatConversation::create([
            'client_id' => $client->id,
            'person_id' => $person->id,
        ]);

        return [$conversation, $client];
    }

    /**
     * A REAL AiClient — only its HTTP transport is faked, so executeToolLoop() and the
     * psa-ejzjd guard both actually run.
     *
     * @param  array<int, Response>  $responses
     */
    private function serviceReturning(array $responses): PortalChatbotService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new PortalChatbotService(new AiClient(http: new GuzzleClient(['handler' => $stack])));
    }

    /**
     * Everything the loop sent BACK to Anthropic after the first round — i.e. the
     * tool_result blocks. Reading the wire is how we observe what the executor
     * actually produced, since the results never surface to the caller.
     */
    private function toolResultsSentBack(): string
    {
        $bodies = [];
        foreach (array_slice($this->history, 1) as $transaction) {
            $bodies[] = (string) $transaction['request']->getBody();
        }

        return implode("\n", $bodies);
    }

    private function toolUse(string $name, array $input = []): Response
    {
        return $this->anthropic([
            ['type' => 'tool_use', 'id' => 'toolu_'.$name, 'name' => $name, 'input' => $input],
        ]);
    }

    private function finalText(string $text): Response
    {
        return $this->anthropic([['type' => 'text', 'text' => $text]]);
    }

    /** @param  array<int, array<string, mixed>>  $content */
    private function anthropic(array $content): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'content' => $content,
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]));
    }
}
