<?php

namespace Tests\Feature\Qbo;

use App\Models\Setting;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * psa #1354 — the QBO Fault code must reach the log line, and nothing else from
 * the Fault may.
 *
 * QuickBooks Online returns HTTP 400 for two situations that need opposite
 * handling: a genuinely malformed request, which is a bug to fix and retry after
 * fixing, and Fault code 610 "Object Not Found", which is permanent and must
 * never be retried. `[QboClient] API request failed` logged only `status`, so
 * production could not tell the two apart: the three invoice ids failing on
 * every `qbo:sync-invoices` pass were indistinguishable, from the log alone,
 * from a transient syntax bug.
 *
 * The decoded body was already available at the log call site — it is decoded a
 * few lines below to build the exception detail — so this is a discarded value
 * being kept, not a new diagnostic surface.
 *
 * THE BOUNDARY THESE TESTS EXIST TO HOLD: a Fault entry's `Message` and
 * `Detail` are free text composed by Intuit from the request, so they can carry
 * request content; `code` is an enum. Only `code` is logged. A test that merely
 * asserted the code was present would pass while the message leaked beside it,
 * so every case below asserts on the whole context array, not just the new key.
 *
 * The error path is driven through a real Guzzle stack (MockHandler +
 * HandlerStack) rather than a throwing fake, so the BadResponseException under
 * test is the one Guzzle itself constructs.
 */
class QboClientFaultCodeLoggingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $capturedLogs = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('qbo_environment', 'sandbox');
        Setting::setValue('qbo_realm_id', '4620816365');
        Setting::setEncrypted('qbo_access_token', 'test-access-token');
        Setting::setValue('qbo_token_expires_at', now()->addHour()->toDateTimeString());

        $this->capturedLogs = [];

        Log::listen(function (MessageLogged $record): void {
            $this->capturedLogs[] = $record;
        });
    }

    /**
     * A client whose next response is $response, and a GET through it.
     *
     * @param  array<string, mixed>|string  $body
     */
    private function failingGet(int $status, array|string $body): void
    {
        $payload = is_string($body) ? $body : (string) json_encode($body);

        $http = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([
                new Response($status, ['Content-Type' => 'application/json'], $payload),
            ])),
            'timeout' => 30,
        ]);

        try {
            (new QboClient($http))->get('invoice/1042');
            $this->fail('Expected QboClientException.');
        } catch (QboClientException $e) {
            // The throw is the documented behaviour; this test is about the log.
        }
    }

    /**
     * The one `[QboClient] API request failed` context, or a failure naming what
     * was logged instead.
     *
     * Exactly one, deliberately: an assertion that found SOME matching line
     * would pass while a second, leakier one was written beside it.
     *
     * @return array<mixed>
     */
    private function requestFailedContext(): array
    {
        $matches = [];
        $seen = [];

        foreach ($this->capturedLogs as $record) {
            $seen[] = $record->level.': '.$record->message;

            if ($record->level === 'error' && $record->message === '[QboClient] API request failed') {
                $matches[] = $record->context;
            }
        }

        $this->assertCount(
            1,
            $matches,
            'Expected exactly one [QboClient] API request failed line. Logged: '.implode(' | ', $seen)
        );

        return $matches[0];
    }

    public function test_a_fault_610_object_not_found_logs_its_code(): void
    {
        $this->failingGet(400, [
            'Fault' => [
                'Error' => [[
                    'Message' => 'Object Not Found',
                    'Detail' => 'Object Not Found : Something you are trying to use has been made inactive.',
                    'code' => '610',
                ]],
                'type' => 'ValidationFault',
            ],
        ]);

        $context = $this->requestFailedContext();

        $this->assertSame(['610'], $context['fault_codes']);
        $this->assertSame(400, $context['status']);
        $this->assertSame('GET', $context['method']);
        $this->assertSame('invoice/1042', $context['path']);
    }

    public function test_a_malformed_request_fault_is_distinguishable_from_object_not_found(): void
    {
        $this->failingGet(400, [
            'Fault' => [
                'Error' => [[
                    'Message' => 'Invalid query',
                    'Detail' => 'QueryParserError: Encountered " <ID> "frobnicate "" at line 1',
                    'code' => '4000',
                ]],
                'type' => 'ValidationFault',
            ],
        ]);

        $this->assertSame(['4000'], $this->requestFailedContext()['fault_codes']);
    }

    public function test_every_code_in_a_multi_error_fault_is_logged_in_order(): void
    {
        $this->failingGet(400, [
            'Fault' => [
                'Error' => [
                    ['Message' => 'Object Not Found', 'code' => '610'],
                    ['Message' => 'Invalid Reference Id', 'code' => '2500'],
                ],
            ],
        ]);

        $this->assertSame(['610', '2500'], $this->requestFailedContext()['fault_codes']);
    }

    /**
     * The whole point of the key: 400 alone does not say whether to retry.
     */
    public function test_two_four_hundreds_with_different_fault_codes_log_different_values(): void
    {
        $this->failingGet(400, ['Fault' => ['Error' => [['code' => '610']]]]);
        $permanent = $this->requestFailedContext()['fault_codes'];

        $this->capturedLogs = [];

        $this->failingGet(400, ['Fault' => ['Error' => [['code' => '4000']]]]);
        $transient = $this->requestFailedContext()['fault_codes'];

        $this->assertNotSame($permanent, $transient);
    }

    /**
     * The redaction boundary, asserted over the whole context rather than the
     * one key: nothing from Message or Detail may appear anywhere in it.
     *
     * `error` (the Guzzle exception message) is excluded from this sweep and NOT
     * cleaned up here: Guzzle composes it with a truncated response body, so it
     * has carried Fault text since long before this change. That is a
     * pre-existing property of the line owned by the forward log-remediation
     * work, and widening this PR into it would put an unreviewed redaction change
     * on the same tip. Recorded, not silently accepted.
     */
    public function test_no_fault_message_or_detail_text_reaches_the_new_context(): void
    {
        $this->failingGet(400, [
            'Fault' => [
                'Error' => [[
                    'Message' => 'MAGIC-MESSAGE-SENTINEL',
                    'Detail' => 'MAGIC-DETAIL-SENTINEL',
                    'code' => '610',
                ]],
            ],
        ]);

        $context = $this->requestFailedContext();
        unset($context['error']);

        $encoded = (string) json_encode($context);

        $this->assertStringNotContainsString('MAGIC-MESSAGE-SENTINEL', $encoded);
        $this->assertStringNotContainsString('MAGIC-DETAIL-SENTINEL', $encoded);
        $this->assertSame(['610'], $context['fault_codes']);
    }

    public function test_a_response_with_no_fault_logs_an_empty_code_list(): void
    {
        $this->failingGet(500, ['error' => 'gateway exploded']);

        $this->assertSame([], $this->requestFailedContext()['fault_codes']);
    }

    /**
     * A non-JSON error body decodes to null, so there is no Fault to read. The
     * key must still be present and still be a list — a consumer that reads
     * `fault_codes` should never have to handle its absence.
     */
    public function test_an_unparseable_error_body_logs_an_empty_code_list(): void
    {
        $this->failingGet(502, '<html><body>Bad Gateway</body></html>');

        $context = $this->requestFailedContext();

        $this->assertArrayHasKey('fault_codes', $context);
        $this->assertSame([], $context['fault_codes']);
    }

    /**
     * The wire is not trusted. A Fault.Error whose `code` is an array must be
     * dropped, not stringified into "Array" and not allowed to put structure
     * into the log context.
     *
     * `error` is excluded from the sentinel sweep for the reason given on
     * test_no_fault_message_or_detail_text_reaches_the_new_context: Guzzle
     * composes its own exception message with a truncated echo of the response
     * body, so the sentinel is in `error` no matter what this commit does. That
     * is measured, not assumed — the first run of this test failed here, on
     * `error` alone, with `fault_codes` already correct.
     */
    public function test_a_non_scalar_code_is_dropped_rather_than_stringified(): void
    {
        $this->failingGet(400, [
            'Fault' => [
                'Error' => [
                    ['code' => ['nested' => 'MAGIC-NESTED-SENTINEL']],
                    ['code' => '610'],
                ],
            ],
        ]);

        $context = $this->requestFailedContext();
        unset($context['error']);

        $this->assertSame(['610'], $context['fault_codes']);
        $this->assertStringNotContainsString('MAGIC-NESTED-SENTINEL', (string) json_encode($context));
    }

    /**
     * A bare object with no `code` in it is not an Error entry, and a list entry
     * with no `code` has nothing to log: neither may fatal, and neither may be
     * counted as a readable code.
     *
     * Both cases are driven here rather than described: the first is the shape
     * faultErrorEntries() refuses to wrap, the second is the `$error['code'] ??
     * null` branch, and a docblock claiming a case no body drives is worth
     * nothing.
     */
    public function test_fault_shapes_carrying_no_code_do_not_throw_out_of_the_logger(): void
    {
        $this->failingGet(400, ['Fault' => ['Error' => ['Message' => 'not a list']]]);

        $this->assertSame([], $this->requestFailedContext()['fault_codes']);

        $this->capturedLogs = [];

        $this->failingGet(400, ['Fault' => ['Error' => [['Message' => 'Object Not Found']]]]);

        $context = $this->requestFailedContext();

        $this->assertSame([], $context['fault_codes']);
        $this->assertSame(1, $context['fault_error_count']);
    }

    /**
     * Single-element collapsing — an object where a list is expected — is the
     * standard XML-to-JSON translation hazard on Intuit-shaped payloads, and it
     * would otherwise lose the exact 610 this logging exists to surface.
     *
     * The sentinel sweep runs here too: wrapping a collapsed entry must not
     * widen what reaches the context.
     */
    public function test_a_fault_error_collapsed_to_a_single_object_still_logs_its_code(): void
    {
        $this->failingGet(400, [
            'Fault' => [
                'Error' => [
                    'Message' => 'MAGIC-MESSAGE-SENTINEL',
                    'Detail' => 'MAGIC-DETAIL-SENTINEL',
                    'code' => '610',
                ],
            ],
        ]);

        $context = $this->requestFailedContext();
        unset($context['error']);

        $this->assertSame(['610'], $context['fault_codes']);
        $this->assertSame(1, $context['fault_error_count']);
        $this->assertStringNotContainsString('MAGIC-MESSAGE-SENTINEL', (string) json_encode($context));
        $this->assertStringNotContainsString('MAGIC-DETAIL-SENTINEL', (string) json_encode($context));
    }

    /**
     * `is_scalar` would admit bool and float. `(string) true` is `'1'` — a value
     * shaped exactly like a real fault code — while `(string) false` is `''` and
     * would be dropped, so the two booleans would take opposite paths for no
     * stated reason. A fabricated code is worse than an absent one, because a
     * consumer is meant to branch on it.
     */
    public function test_a_boolean_or_float_code_is_dropped_rather_than_coerced(): void
    {
        $this->failingGet(400, [
            'Fault' => [
                'Error' => [
                    ['code' => true],
                    ['code' => false],
                    ['code' => 610.5],
                    ['code' => '610'],
                ],
            ],
        ]);

        $context = $this->requestFailedContext();

        $this->assertSame(['610'], $context['fault_codes']);
        $this->assertSame(4, $context['fault_error_count']);
    }

    /**
     * An int code is documented-shaped and must survive; only its stringification
     * is this method's business.
     */
    public function test_an_integer_code_is_logged_as_its_string_form(): void
    {
        $this->failingGet(400, ['Fault' => ['Error' => [['code' => 610]]]]);

        $this->assertSame(['610'], $this->requestFailedContext()['fault_codes']);
    }

    /**
     * The type check alone bounds the SHAPE of `code` and nothing else. This line
     * is written on every failure — 18 times a day on production today — so an
     * unbounded copy of wire bytes into it is a real cost even without a hostile
     * upstream.
     */
    public function test_an_overlong_code_is_truncated_rather_than_copied_whole(): void
    {
        $this->failingGet(400, [
            'Fault' => ['Error' => [['code' => '610'.str_repeat('X', 4000).'MAGIC-TAIL-SENTINEL']]],
        ]);

        $context = $this->requestFailedContext();
        unset($context['error']);

        $codes = $context['fault_codes'];

        $this->assertCount(1, $codes);
        $this->assertSame(32, mb_strlen($codes[0]));
        $this->assertStringStartsWith('610', $codes[0]);
        $this->assertStringNotContainsString('MAGIC-TAIL-SENTINEL', (string) json_encode($context));
    }

    /**
     * Cardinality is the same problem by another route: 40 entries must not write
     * 40 codes. The count still reports every entry that arrived, which is the
     * point of having it.
     */
    public function test_the_number_of_logged_codes_is_capped_while_the_count_is_not(): void
    {
        $errors = [];

        for ($i = 0; $i < 40; $i++) {
            $errors[] = ['code' => (string) (600 + $i)];
        }

        $this->failingGet(400, ['Fault' => ['Error' => $errors]]);

        $context = $this->requestFailedContext();

        $this->assertCount(25, $context['fault_codes']);
        $this->assertSame('600', $context['fault_codes'][0]);
        $this->assertSame(40, $context['fault_error_count']);
    }

    /**
     * The reason `fault_error_count` exists: an empty `fault_codes` alone cannot
     * say whether a Fault came back at all. Without the count these two lines are
     * byte-identical, and an operator reading "no codes" on a 400 is back in the
     * #1354 blind spot the key was added to close.
     */
    public function test_a_fault_with_unreadable_codes_is_distinguishable_from_no_fault_at_all(): void
    {
        $this->failingGet(400, ['Fault' => ['Error' => [['code' => ['nested' => 'x']], ['code' => []]]]]);

        $unreadable = $this->requestFailedContext();

        $this->capturedLogs = [];

        $this->failingGet(502, ['error' => 'gateway exploded']);

        $noFault = $this->requestFailedContext();

        $this->assertSame([], $unreadable['fault_codes']);
        $this->assertSame([], $noFault['fault_codes']);
        $this->assertSame(2, $unreadable['fault_error_count']);
        $this->assertSame(0, $noFault['fault_error_count']);
    }

    /**
     * The extraction now runs ABOVE the Log call, so it also runs on the path
     * where the exception carries no response at all — a DNS or TLS failure,
     * where `$responseBody` is null and the status is 0. Every other case here
     * queues a Response, so without this one that path is reasoned about in a
     * docblock and asserted nowhere.
     */
    public function test_a_transport_failure_with_no_response_logs_an_empty_code_list(): void
    {
        $http = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([
                new ConnectException('cURL error 6: Could not resolve host', new Request('GET', 'invoice/1042')),
            ])),
            'timeout' => 30,
        ]);

        try {
            (new QboClient($http))->get('invoice/1042');
            $this->fail('Expected QboClientException.');
        } catch (QboClientException $e) {
            $this->assertSame(0, $e->getCode());
        }

        $context = $this->requestFailedContext();

        $this->assertSame([], $context['fault_codes']);
        $this->assertSame(0, $context['fault_error_count']);
        $this->assertSame(0, $context['status']);
    }

    /**
     * The value the exception carries is unchanged by this commit — the caller
     * classification path Jeeves identified still works and is not replaced by
     * the log line.
     */
    public function test_the_exception_still_carries_the_decoded_body_for_callers(): void
    {
        $http = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(400, ['Content-Type' => 'application/json'], (string) json_encode([
                    'Fault' => ['Error' => [['Message' => 'Object Not Found', 'code' => '610']]],
                ])),
            ])),
            'timeout' => 30,
        ]);

        try {
            (new QboClient($http))->get('invoice/1042');
            $this->fail('Expected QboClientException.');
        } catch (QboClientException $e) {
            $this->assertSame('610', $e->getResponseBody()['Fault']['Error'][0]['code']);
            $this->assertSame(400, $e->getCode());
        }
    }

    /**
     * The injected client is a seam, not a behaviour change: a QboClient built
     * the way the container builds it still owns a client carrying the 30s
     * timeout.
     *
     * `assertInstanceOf(QboClient::class, ...)` would be true no matter which
     * Guzzle client landed in `$http` — true before this seam existed, and true
     * after a future `$app->bind(GuzzleHttp\Client::class, ...)` replaced the
     * timed-out client with someone else's. The timeout is the property that
     * matters (an untimed client turns a stalled QBO endpoint into a hung
     * `qbo:sync-invoices` run), so the timeout is what is asserted.
     */
    public function test_the_default_constructor_still_builds_a_client_with_the_thirty_second_timeout(): void
    {
        $property = new \ReflectionProperty(QboClient::class, 'http');

        foreach (['new' => new QboClient, 'container' => app(QboClient::class)] as $how => $client) {
            $http = $property->getValue($client);

            $this->assertInstanceOf(GuzzleClient::class, $http, "{$how}: not a Guzzle client");
            $this->assertSame(30, $http->getConfig('timeout'), "{$how}: lost the 30s timeout");
        }
    }
}
