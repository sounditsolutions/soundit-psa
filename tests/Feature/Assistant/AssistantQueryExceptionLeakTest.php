<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Assistant\AssistantUserFacingException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A database fault inside AssistantService::sendMessage() must not reach the
 * caller as text.
 *
 * AssistantController::send() caught `\RuntimeException` and echoed
 * getMessage() into a 422 body, on the reasoning that the only
 * RuntimeExceptions the service throws are operator-authored strings. True of
 * the service, false of the hierarchy: `QueryException extends PDOException
 * extends RuntimeException`. sendMessage() does DB work inside that try — a
 * message count, a daily-token sum, a conversation update — so a fault on any
 * of them returned the failed statement and its bound values.
 *
 * APP_DEBUG=false does not close this: the echo is explicit, not the framework
 * handler, so the flag never gets a say.
 *
 * Same defect and remedy as the client-portal instance (psa #378). The surface
 * here is authenticated staff rather than a customer, which is why it is rated
 * and tracked separately rather than as that issue's twin.
 *
 * DRIVER CONSTRAINT — the controller does `new AssistantService` rather than
 * resolving it from the container, so the service cannot be mocked here. The
 * failure is forced for real with Schema::drop(), and DDL is not transactional
 * on MariaDB (which prod runs): it implicitly commits, so RefreshDatabase could
 * no longer roll the case back and the damage would escape into later tests.
 * These therefore skip off sqlite. A green CI here is NOT cross-driver proof.
 */
class AssistantQueryExceptionLeakTest extends TestCase
{
    use RefreshDatabase;

    private function staffUser(): User
    {
        Setting::setValue('assistant_enabled', '1');
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'sk-test-key');

        return User::factory()->create();
    }

    private function skipUnlessSqlite(): void
    {
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            $this->markTestSkipped("forces its failure with DDL and is sqlite-only; driver is {$driver}");
        }
    }

    public function test_a_database_fault_does_not_return_sql_or_bindings_to_the_caller(): void
    {
        $this->skipUnlessSqlite();
        $user = $this->staffUser();
        $conversation = AssistantConversation::create(['user_id' => $user->id, 'title' => 'Ask AI']);

        // Fails $conversation->messages()->count(), the first DB read inside the try.
        Schema::drop('assistant_messages');

        $response = $this->actingAs($user)
            ->postJson(route('assistant.message', $conversation), ['message' => 'hello']);

        $body = $response->getContent();

        $this->assertStringNotContainsString('assistant_messages', $body, 'schema text leaked');
        $this->assertStringNotContainsString('select', strtolower($body), 'the statement leaked');
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('no such table', $body);

        // And it must still FAIL. Every assertion above is satisfied by a
        // controller that silently succeeded, so pin the failure too.
        $response->assertStatus(500);
    }

    /**
     * Negative control: the operator-authored guard messages are the whole point
     * of the visible arm and must still be shown. A fix that refuses everything
     * is not a fix — and this case passes on BOTH sides of the change, which is
     * what makes it a control rather than an artefact.
     */
    public function test_an_operator_authored_guard_message_is_still_shown(): void
    {
        $user = $this->staffUser();
        $conversation = AssistantConversation::create(['user_id' => $user->id, 'title' => 'Ask AI']);

        // Use a guard raised INSIDE sendMessage(), not one the route middleware
        // catches first: clearing ai_provider trips `assistant.enabled` and
        // returns 403 without the service ever running, which would have made
        // this a control over the middleware rather than over the catch arm.
        Setting::setValue('assistant_max_messages', '1');
        AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'first',
        ]);

        $this->actingAs($user)
            ->postJson(route('assistant.message', $conversation), ['message' => 'hello'])
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($e) => str_contains((string) $e, 'message limit'));
    }

    /**
     * The hierarchy fact the bug rests on, pinned against a future refactor of
     * the arm. Only assertions a change in this repo could actually falsify: an
     * earlier third case asserted that a framework class does not extend an
     * App\ class, which nothing here can make false (psa #379 review, finding 3).
     */
    public function test_query_exception_is_a_runtime_exception(): void
    {
        $this->assertTrue(is_subclass_of(QueryException::class, \PDOException::class));
        $this->assertTrue(is_subclass_of(QueryException::class, \RuntimeException::class));
        $this->assertTrue(is_subclass_of(AssistantUserFacingException::class, \RuntimeException::class));
    }
}
