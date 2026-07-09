<?php

namespace Tests\Feature\Portal;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\PortalChatConversation;
use App\Models\PortalChatMessage;
use App\Models\Setting;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Route + controller behaviour for the client-portal AI chatbot (psa-2ab):
 * feature gating, portal auth, per-contact conversation ownership, and the
 * send → persist → reply round trip (AiClient mocked).
 */
class PortalChatbotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('portal_enabled', '1');
    }

    private function enableChatbotWithAi(): void
    {
        Setting::setValue('portal_chatbot_enabled', '1');
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'sk-test-key');
    }

    private function portalPerson(bool $companyWide = true): Person
    {
        $client = Client::create(['name' => 'Acme Corp']);

        return Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Pat',
            'last_name' => 'Portal',
            'email' => 'pat'.uniqid().'@example.test',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => $companyWide,
            'password' => 'secret-portal-pw',
        ]);
    }

    private function fakeAi(string $reply): void
    {
        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('resetTokenCounters')->andReturnNull();
        $ai->shouldReceive('runChatWithTools')->andReturn(
            new AiResponse(text: $reply, inputTokens: 120, outputTokens: 30),
        );
        $ai->shouldReceive('cumulativeInputTokens')->andReturn(120);
        $ai->shouldReceive('cumulativeOutputTokens')->andReturn(30);
    }

    public function test_page_is_404_when_chatbot_disabled(): void
    {
        $person = $this->portalPerson();
        // portal_chatbot_enabled defaults to off.

        $this->actingAs($person, 'portal')
            ->get(route('portal.chatbot'))
            ->assertNotFound();
    }

    public function test_unauthenticated_visitor_is_redirected_to_login(): void
    {
        Setting::setValue('portal_chatbot_enabled', '1');

        $this->get(route('portal.chatbot'))
            ->assertRedirect(route('portal.login'));
    }

    public function test_page_loads_when_enabled_and_ai_configured(): void
    {
        $this->enableChatbotWithAi();
        $person = $this->portalPerson();

        $this->actingAs($person, 'portal')
            ->get(route('portal.chatbot'))
            ->assertOk()
            ->assertSee('Ask AI')
            ->assertSee('chatbot-messages', false);
    }

    public function test_page_shows_unavailable_state_when_ai_not_configured(): void
    {
        Setting::setValue('portal_chatbot_enabled', '1'); // enabled, but no AI key
        $person = $this->portalPerson();

        $this->actingAs($person, 'portal')
            ->get(route('portal.chatbot'))
            ->assertOk()
            ->assertSee("isn't available", false);
    }

    public function test_send_returns_reply_and_persists_the_conversation(): void
    {
        $this->enableChatbotWithAi();
        $this->fakeAi('You have 2 open tickets.');
        $person = $this->portalPerson();

        $response = $this->actingAs($person, 'portal')
            ->postJson(route('portal.chatbot.send'), ['message' => 'How many open tickets do I have?']);

        $response->assertOk()
            ->assertJson(['reply' => 'You have 2 open tickets.'])
            ->assertJsonStructure(['reply', 'conversation_id']);

        $conversation = PortalChatConversation::first();
        $this->assertNotNull($conversation);
        $this->assertSame($person->client_id, $conversation->client_id);
        $this->assertSame($person->id, $conversation->person_id);

        // Both the user turn and the assistant turn are stored.
        $this->assertSame(2, PortalChatMessage::count());
        $this->assertDatabaseHas('portal_chat_messages', ['role' => 'user', 'content' => 'How many open tickets do I have?']);
        $this->assertDatabaseHas('portal_chat_messages', ['role' => 'assistant', 'content' => 'You have 2 open tickets.']);
    }

    public function test_send_continues_an_existing_owned_conversation(): void
    {
        $this->enableChatbotWithAi();
        $this->fakeAi('Follow-up answer.');
        $person = $this->portalPerson();

        $conversation = PortalChatConversation::create([
            'client_id' => $person->client_id,
            'person_id' => $person->id,
        ]);

        $this->actingAs($person, 'portal')
            ->postJson(route('portal.chatbot.send'), [
                'message' => 'And what about invoices?',
                'conversation_id' => $conversation->id,
            ])
            ->assertOk()
            ->assertJson(['conversation_id' => $conversation->id]);

        $this->assertSame(1, PortalChatConversation::count());
    }

    public function test_send_cannot_touch_another_contacts_conversation(): void
    {
        $this->enableChatbotWithAi();
        $this->fakeAi('should not run');

        $me = $this->portalPerson();
        $otherPerson = $this->portalPerson(); // different client + person
        $foreign = PortalChatConversation::create([
            'client_id' => $otherPerson->client_id,
            'person_id' => $otherPerson->id,
        ]);

        $this->actingAs($me, 'portal')
            ->postJson(route('portal.chatbot.send'), [
                'message' => 'Show me their data',
                'conversation_id' => $foreign->id,
            ])
            ->assertForbidden();

        // Nothing was written to the foreign conversation.
        $this->assertSame(0, $foreign->messages()->count());
    }

    public function test_send_is_404_when_chatbot_disabled(): void
    {
        $person = $this->portalPerson(); // chatbot off

        $this->actingAs($person, 'portal')
            ->postJson(route('portal.chatbot.send'), ['message' => 'hi'])
            ->assertNotFound();
    }

    public function test_send_returns_422_when_ai_not_configured(): void
    {
        Setting::setValue('portal_chatbot_enabled', '1'); // enabled but no AI key
        $person = $this->portalPerson();

        $this->actingAs($person, 'portal')
            ->postJson(route('portal.chatbot.send'), ['message' => 'hi'])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
    }

    public function test_failed_ai_call_persists_nothing_and_leaves_conversation_usable(): void
    {
        $this->enableChatbotWithAi();
        $person = $this->portalPerson();

        // First call throws, second call succeeds — proves no orphan user turn
        // is left behind to break user/assistant alternation on retry.
        $ai = $this->mock(AiClient::class);
        $ai->shouldReceive('resetTokenCounters')->andReturnNull();
        $ai->shouldReceive('cumulativeInputTokens')->andReturn(10);
        $ai->shouldReceive('cumulativeOutputTokens')->andReturn(5);
        $ai->shouldReceive('runChatWithTools')->andReturnUsing(
            function () {
                throw new \RuntimeException('upstream timeout');
            },
            fn () => new AiResponse(text: 'Recovered answer.', inputTokens: 10, outputTokens: 5),
        );

        // First send fails cleanly (mapped to 422) and writes nothing.
        $this->actingAs($person, 'portal')
            ->postJson(route('portal.chatbot.send'), ['message' => 'first try'])
            ->assertStatus(422);
        $this->assertSame(0, PortalChatMessage::count());

        // Retry in a fresh conversation succeeds and stores a clean pair.
        $this->actingAs($person, 'portal')
            ->postJson(route('portal.chatbot.send'), ['message' => 'second try'])
            ->assertOk()
            ->assertJson(['reply' => 'Recovered answer.']);
        $this->assertSame(2, PortalChatMessage::count());
    }

    public function test_send_validates_message_is_required(): void
    {
        $this->enableChatbotWithAi();
        $person = $this->portalPerson();

        $this->actingAs($person, 'portal')
            ->postJson(route('portal.chatbot.send'), ['message' => ''])
            ->assertStatus(422);
    }
}
