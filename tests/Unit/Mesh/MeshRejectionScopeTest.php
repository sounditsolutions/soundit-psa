<?php

namespace Tests\Unit\Mesh;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\RecordsGuzzleRejections;
use Tests\TestCase;

/**
 * #6057: lastRejection() is call-scoped. With two rejections recorded it
 * fails rather than return the earlier or the later one, so a positive
 * control cannot read a rejection the asserted call did not produce;
 * after forgetRejections() it returns the one that follows.
 *
 * G-5: MockHandler only. Synthetic data.
 */
class MeshRejectionScopeTest extends TestCase
{
    use RecordsGuzzleRejections;

    private function failTwice(): GuzzleClient
    {
        return new GuzzleClient(['handler' => $this->recordRejections(HandlerStack::create(new MockHandler([
            new Response(503, [], 'first'),
            new Response(502, [], 'second'),
        ])))]);
    }

    public function test_two_rejections_are_refused_not_guessed(): void
    {
        $http = $this->failTwice();
        foreach ([1, 2] as $_) {
            try {
                $http->get('https://mesh-6057.example.test/x');
            } catch (ServerException) {
            }
        }

        try {
            $this->lastRejection();
            $failed = false;
        } catch (AssertionFailedError) {
            $failed = true;
        }
        $this->assertTrue($failed, 'two rejections: lastRejection() must refuse to pick one');
    }

    public function test_after_forget_it_returns_the_call_that_follows(): void
    {
        $http = $this->failTwice();
        try {
            $http->get('https://mesh-6057.example.test/x');
        } catch (ServerException) {
        }
        $this->forgetRejections();
        try {
            $http->get('https://mesh-6057.example.test/x');
        } catch (ServerException) {
        }

        $this->assertSame(502, $this->lastRejection()->getResponse()->getStatusCode());
    }
}
