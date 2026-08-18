<?php

namespace Tests\Feature\AppRiver;

use App\Models\Setting;
use App\Services\AppRiver\AppRiverClient;
use App\Services\AppRiver\AppRiverClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The detail read is where the seat count comes from, so an unreadable body here
 * is the corruption path getSubscriptions()' envelope guard does not cover.
 *
 * request() returns [] for an empty, unparseable or literal-null body.
 * extractLicenseCounts([]) then yields nulls, the licence row is written
 * quantity 0 / status 'active', nothing is counted unobserved, and the run exits
 * SUCCESS having zeroed a live seat count. getSubscriptionDetail() now refuses it.
 *
 * The discrimination is the point, not the refusal: `{}` is a readable answer and
 * must still be returned, and only $jsonKind separates it from a body that never
 * parsed — both decode to the same [].
 */
class AppRiverDetailEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    private function clientReturning(string $json): AppRiverClient
    {
        Setting::setEncrypted('appriver_access_token', 'live-token');
        Setting::setValue('appriver_token_expires_at', now()->addHour()->toDateTimeString());

        return new AppRiverClient([
            'base_url' => 'https://appriver.test',
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, [], $json),
            ])),
        ]);
    }

    public function test_an_empty_detail_body_raises_rather_than_reading_as_no_licences(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/empty or unparseable/');

        $this->clientReturning('')->getSubscriptionDetail('customer-1', 'sub-1');
    }

    public function test_an_unparseable_detail_body_raises(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/empty or unparseable/');

        $this->clientReturning('<html><body>502 Bad Gateway</body></html>')
            ->getSubscriptionDetail('customer-1', 'sub-1');
    }

    public function test_a_literal_null_detail_body_raises(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/empty or unparseable/');

        $this->clientReturning('null')->getSubscriptionDetail('customer-1', 'sub-1');
    }

    /**
     * The guard must key on $jsonKind, not on the decoded value. `{}` and an empty
     * body are the same [] afterwards; if this raised, the guard would be refusing a
     * readable answer and the discrimination would be fictional.
     */
    public function test_an_empty_object_detail_body_is_readable_and_is_not_refused(): void
    {
        $this->assertSame(
            [],
            $this->clientReturning('{}')->getSubscriptionDetail('customer-1', 'sub-1'),
            '{} is an envelope we read and understood; only an unparseable body is refused.'
        );
    }

    public function test_a_real_detail_payload_is_returned_intact(): void
    {
        $detail = $this->clientReturning(
            '{"SubscriptionKey":"sub-1","ReadonlySubscriptionDetails":[{"Name":"SubscriptionQuantity","Value":"12"}]}'
        )->getSubscriptionDetail('customer-1', 'sub-1');

        $this->assertSame('sub-1', $detail['SubscriptionKey']);
        $this->assertSame(
            '12',
            $detail['ReadonlySubscriptionDetails'][0]['Value'],
            'The guard must not disturb the payload it lets through.'
        );
    }

    /**
     * The carve-out, asserted so it cannot be "tidied" into symmetry later: an empty
     * success body is a legitimate answer to a write, and refusing it would fail
     * seat updates the vendor actually accepted.
     */
    public function test_the_write_lane_still_accepts_an_empty_success_body(): void
    {
        $this->assertSame(
            [],
            $this->clientReturning('')->updateSubscriptionQuantity('customer-1', 'sub-1', 5),
            'patch() is deliberately outside this guard.'
        );
    }
}
