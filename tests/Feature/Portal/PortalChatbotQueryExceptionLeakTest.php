<?php

namespace Tests\Feature\Portal;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Services\Portal\PortalChatbotService;
use App\Services\Portal\PortalChatbotUserFacingException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A database fault inside sendMessage() must not reach the customer.
 *
 * The controller used to catch `\RuntimeException` and echo getMessage() into
 * the 422 body, on the reasoning that the only RuntimeExceptions this service
 * throws are four operator-authored strings. True of the service, false of the
 * hierarchy: `QueryException extends PDOException extends RuntimeException`, so
 * a fault on any of sendMessage()'s DB operations returned the failed statement
 * and its bound values to a portal user.
 *
 * APP_DEBUG=false does not close this. The echo is explicit, not the framework
 * handler, so the flag never gets a say.
 *
 * The two halves below have to move together. Pinning only the leak would be
 * satisfied by a controller that shows the user nothing ever; pinning only the
 * guard messages is what the old code already did.
 */
class PortalChatbotQueryExceptionLeakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('portal_enabled', '1');
        Setting::setValue('portal_chatbot_enabled', '1');
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'sk-test-key');
    }

    private function portalPerson(): Person
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
            'company_wide_access' => true,
            'password' => 'secret-portal-pw',
        ]);
    }

    /** The regression. A QueryException carries statement text and bindings in getMessage(). */
    public function test_a_database_fault_does_not_return_sql_or_bindings_to_the_portal_user(): void
    {
        $person = $this->portalPerson();

        $sql = 'update `portal_chat_conversations` set `last_message_at` = ? where `id` = ?';
        $binding = 'pat-secret-binding-value';

        $this->mock(PortalChatbotService::class, function ($mock) use ($sql, $binding) {
            $mock->shouldReceive('sendMessage')->andThrow(
                new QueryException('mysql', $sql, [$binding, 7], new \Exception('Duplicate entry'))
            );
        });

        $response = $this->actingAs($person, 'portal')
            ->postJson(route('portal.chatbot.send'), ['message' => 'hello']);

        $body = $response->getContent();

        // Nothing about the statement, its bindings, or the driver reaches the user.
        $this->assertStringNotContainsString($binding, $body);
        $this->assertStringNotContainsString('portal_chat_conversations', $body);
        $this->assertStringNotContainsString('update ', $body);
        $this->assertStringNotContainsString('Duplicate entry', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);

        // And the request still fails — a leak fixed by silently succeeding would
        // pass every assertion above.
        $response->assertStatus(500);
        $response->assertJson(['error' => 'Something went wrong. Please try again.']);
    }

    /**
     * Negative control: the four operator-authored guard messages are the whole
     * point of the visible arm and must still be shown. A fix that refuses
     * everything is not a fix.
     */
    public function test_an_operator_authored_guard_message_is_still_shown_to_the_user(): void
    {
        $person = $this->portalPerson();
        $message = 'The assistant is currently unavailable. Please try again later or open a ticket.';

        $this->mock(PortalChatbotService::class, function ($mock) use ($message) {
            $mock->shouldReceive('sendMessage')->andThrow(new PortalChatbotUserFacingException($message));
        });

        $this->actingAs($person, 'portal')
            ->postJson(route('portal.chatbot.send'), ['message' => 'hello'])
            ->assertStatus(422)
            ->assertJson(['error' => $message]);
    }

    /**
     * The hierarchy fact the whole bug rests on, pinned so a future refactor of
     * the catch arm cannot quietly reintroduce it on a wrong assumption.
     */
    public function test_query_exception_is_a_runtime_exception(): void
    {
        $this->assertTrue(is_subclass_of(QueryException::class, \PDOException::class));
        $this->assertTrue(is_subclass_of(QueryException::class, \RuntimeException::class));
        $this->assertFalse(is_subclass_of(QueryException::class, PortalChatbotUserFacingException::class));
    }
}
