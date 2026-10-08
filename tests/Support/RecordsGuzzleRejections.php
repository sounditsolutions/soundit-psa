<?php

namespace Tests\Support;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;

/**
 * #5978: the Mesh clients no longer chain Guzzle's exception, so a positive
 * control can no longer read the raw vendor text from getPrevious(). This
 * records every rejection at the OUTSIDE of a HandlerStack (unshifted, so it
 * sits outside http_errors and sees the ServerException that middleware
 * builds) and passes it on unchanged. The recorded exception is exactly what
 * the client's catch receives.
 */
trait RecordsGuzzleRejections
{
    /** @var list<\Throwable> */
    protected array $guzzleRejections = [];

    protected function recordRejections(HandlerStack $stack): HandlerStack
    {
        $stack->unshift(fn (callable $next) => function (RequestInterface $request, array $options) use ($next) {
            try {
                // A MockHandler callable that throws does so synchronously.
                $promise = $next($request, $options);
            } catch (\Throwable $e) {
                $promise = Create::rejectionFor($e);
            }

            return $promise->then(null, function ($reason) {
                if ($reason instanceof \Throwable) {
                    $this->guzzleRejections[] = $reason;
                }

                return Create::rejectionFor($reason);
            });
        }, 'record_rejections');

        return $stack;
    }

    /**
     * #6057: forget what earlier calls in this test recorded, so the next
     * lastRejection() can only be a rejection of the call that follows.
     */
    protected function forgetRejections(): void
    {
        $this->guzzleRejections = [];
    }

    /**
     * The last raw Guzzle exception the client caught; fails if there was
     * none. Test-wide unless forgetRejections() ran before the asserted
     * call (#6057).
     */
    protected function lastRejection(): \Throwable
    {
        $this->assertNotSame([], $this->guzzleRejections, 'positive control: Guzzle rejected the request');

        return $this->guzzleRejections[array_key_last($this->guzzleRejections)];
    }
}
