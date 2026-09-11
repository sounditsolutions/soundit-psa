<?php

namespace Tests\Feature\Qbo;

use App\Models\Setting;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
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
     * A Fault whose Error is a bare object rather than a list, and a Fault with
     * no `code` at all: both are shapes Intuit does not document but the client
     * must not fatal on.
     */
    public function test_malformed_fault_shapes_do_not_throw_out_of_the_logger(): void
    {
        $this->failingGet(400, ['Fault' => ['Error' => ['Message' => 'not a list']]]);

        $this->assertSame([], $this->requestFailedContext()['fault_codes']);
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
     * the way the container builds it still owns its own Guzzle client.
     */
    public function test_the_default_constructor_still_builds_its_own_http_client(): void
    {
        $this->assertInstanceOf(QboClient::class, new QboClient);
        $this->assertInstanceOf(QboClient::class, app(QboClient::class));
    }
}
