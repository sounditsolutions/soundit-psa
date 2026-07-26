<?php

namespace Tests\Feature\Servosity;

use App\Services\Servosity\ServosityClient;
use App\Services\Servosity\ServosityClientException;
use App\Services\Servosity\ServosityShapeDriftException;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The client seam is where vendor request failures are sanitized
 * (psa-z30dv.6): a Guzzle exception message embeds the full request URL (the
 * configured base host, query strings) and can quote the response body. None
 * of that may reach application logs or the thrown exception's message — only
 * bounded structural fields (our own relative endpoint, exception class,
 * code). The raw exception stays chained for a debugger via getPrevious().
 */
class ServosityClientSanitizationTest extends TestCase
{
    public function test_a_failed_request_logs_and_throws_without_the_vendor_url(): void
    {
        Log::spy();

        // A base URL that refuses connections instantly; the Guzzle
        // ConnectException message embeds it verbatim.
        $client = new ServosityClient([
            'api_token' => 'fixture-token',
            'base_url' => 'http://internal-vault.invalid:1',
        ]);

        try {
            $client->get('companies/summary-ng/', ['page' => 1]);
            $this->fail('expected ServosityClientException');
        } catch (ServosityClientException $e) {
            $this->assertStringNotContainsString('internal-vault', $e->getMessage(), 'the configured host must not leak through the exception message');
            $this->assertStringContainsString('companies/summary-ng/', $e->getMessage(), 'our own relative endpoint is safe and useful');
            $this->assertNotNull($e->getPrevious(), 'the raw exception stays chained for debuggers');
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                if (! str_contains($message, 'request failed')) {
                    return false;
                }
                $this->assertEqualsCanonicalizing(['method', 'endpoint', 'exception', 'code'], array_keys($context));
                $this->assertStringNotContainsString('internal-vault', json_encode($context), 'the configured host must not leak into log context');

                return true;
            })
            ->atLeast()->once();
    }

    public function test_invalid_json_is_rejected_as_shape_drift_without_echoing_the_body(): void
    {
        // psa-z30dv.7/.11: the old `json_decode(...) ?? []` collapse turned an
        // unparseable body into a clean empty list. It must throw instead —
        // typed as shape drift (so the read surface reports schema_drift) but
        // still a ServosityClientException subclass (so every legacy vendor-
        // failure catch contains it) — and the message may name only OUR
        // endpoint, never response content.
        try {
            ServosityClient::decodeJson('SECRET-RESPONSE-BODY{not-json', 'companies/summary-ng/');
            $this->fail('expected ServosityShapeDriftException');
        } catch (ServosityShapeDriftException $e) {
            $this->assertInstanceOf(ServosityClientException::class, $e, 'legacy catches must still contain a not-JSON answer');
            $this->assertStringContainsString('companies/summary-ng/', $e->getMessage());
            $this->assertStringNotContainsString('SECRET-RESPONSE-BODY', $e->getMessage(), 'response bodies must never cross into exception messages or logs');
        }
    }

    public function test_invalid_json_drift_labels_redact_numeric_vendor_ids(): void
    {
        // psa-z30dv.14 (R4 LOW): the shape-drift log line carries this
        // message verbatim, and numeric path segments are vendor identifiers
        // (company / DR account ids) — the label must name the seam, never
        // the identifier.
        try {
            ServosityClient::decodeJson('{broken', 'backup-jobs/987654/');
            $this->fail('expected ServosityShapeDriftException');
        } catch (ServosityShapeDriftException $e) {
            $this->assertStringContainsString('backup-jobs/{id}/', $e->getMessage(), 'the seam stays nameable');
            $this->assertStringNotContainsString('987654', $e->getMessage(), 'the vendor id must be redacted');
        }
    }
}
