<?php

namespace Tests\Feature\Servosity;

use App\Services\Servosity\ServosityClient;
use App\Services\Servosity\ServosityClientException;
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
}
