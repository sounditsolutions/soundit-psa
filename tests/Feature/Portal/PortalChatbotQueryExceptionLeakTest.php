<?php

namespace Tests\Feature\Portal;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Services\Portal\PortalChatbotUserFacingException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
 * NOTHING HERE IS MOCKED, and that is the point (psa #379 review, findings 1/2/4).
 * The first version mocked PortalChatbotService and threw a hand-built
 * QueryException at it. Three defects followed, all of the same family:
 *
 *   - `shouldReceive('sendMessage')` with no `->once()` meant Mockery raised
 *     BadMethodCallException on any unstubbed call, which the controller's
 *     \Throwable arm turned into the identical 500 and message the test
 *     asserted — so it could go green without a QueryException ever existing.
 *   - the fixture's $previous was a plain \Exception, so its message carried no
 *     SQLSTATE prefix and the SQLSTATE assertion was true by construction.
 *   - mocking the service meant none of the four converted throw sites — the
 *     actual fix — ever executed.
 *
 * Forcing a REAL fault through the REAL service closes all three at once: the
 * exception is genuine, its message is whatever the driver actually produces,
 * and the guard path below runs the shipped code.
 *
 * DRIVER CONSTRAINT — the failure is forced with Schema::drop(), and DDL is not
 * transactional on MariaDB (which prod runs): it implicitly commits, so
 * RefreshDatabase could no longer roll the case back and the damage would escape
 * into later tests. That case therefore skips off sqlite. A green CI here is NOT
 * cross-driver proof of the redaction; it is proof on sqlite only.
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

    /** The regression. A real QueryException carries statement text and bindings. */
    public function test_a_database_fault_does_not_return_sql_or_bindings_to_the_portal_user(): void
    {
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            $this->markTestSkipped("forces its failure with DDL and is sqlite-only; driver is {$driver}");
        }

        $person = $this->portalPerson();

        // Fails $conversation->messages()->count() — the first DB read inside
        // sendMessage(), and reached before any AI call, so no provider is
        // involved in producing this exception.
        Schema::drop('portal_chat_messages');

        $response = $this->actingAs($person, 'portal')
            ->postJson(route('portal.chatbot.send'), ['message' => 'hello']);

        $body = $response->getContent();

        $this->assertStringNotContainsString('portal_chat_messages', $body, 'schema text leaked');
        $this->assertStringNotContainsString('select', strtolower($body), 'the statement leaked');
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('no such table', $body);

        // And the request must still FAIL. Every assertion above is satisfied by
        // a controller that silently succeeded, so pin the failure too.
        $response->assertStatus(500);
        $response->assertJson(['error' => 'Something went wrong. Please try again.']);
    }

    /**
     * Negative control, end to end through the real service: an operator-authored
     * guard message must still reach the user. This executes one of the four
     * converted throw sites — the fix itself — rather than a mock standing in
     * for it, and it passes on BOTH sides of the change, which is what makes it
     * a control rather than an artefact.
     */
    public function test_an_operator_authored_guard_message_is_still_shown_to_the_user(): void
    {
        $person = $this->portalPerson();

        // The chatbot stays enabled (the controller 404s otherwise), but the AI
        // provider is not one this service supports — so isAvailable() is false
        // and sendMessage() raises its "currently unavailable" guard for real.
        Setting::setValue('ai_provider', 'openai');

        $this->actingAs($person, 'portal')
            ->postJson(route('portal.chatbot.send'), ['message' => 'hello'])
            ->assertStatus(422)
            ->assertJson(['error' => 'The assistant is currently unavailable. Please try again later or open a ticket.']);
    }

    /**
     * The hierarchy fact the whole bug rests on, pinned so a future refactor of
     * the catch arm cannot reintroduce it on a wrong assumption. Only the two
     * assertions a change in this repo could actually falsify — a third,
     * asserting a framework class does not extend an App\ class, cannot fail and
     * was removed.
     */
    public function test_query_exception_is_a_runtime_exception(): void
    {
        $this->assertTrue(is_subclass_of(QueryException::class, \PDOException::class));
        $this->assertTrue(is_subclass_of(QueryException::class, \RuntimeException::class));
        $this->assertTrue(is_subclass_of(PortalChatbotUserFacingException::class, \RuntimeException::class));
    }
}
