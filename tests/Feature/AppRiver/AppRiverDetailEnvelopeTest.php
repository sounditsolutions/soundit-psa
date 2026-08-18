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
 * The discrimination is on whether the body can support a seat write, not on how it
 * decoded: `{}` and an in-band 200 error envelope parse perfectly and carry no counts,
 * so they zero the same seats as a body that never parsed and are refused alongside it.
 * Only a payload carrying the ReadonlySubscriptionDetails section is returned.
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
     * `{}` parses, and that is worth nothing here: it carries no counts, so returning it
     * writes quantity 0 / status 'active' over a live subscription and exits SUCCESS —
     * the identical corruption as an unparseable body.
     */
    public function test_an_empty_object_detail_body_is_refused(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/empty or unparseable/');

        $this->clientReturning('{}')->getSubscriptionDetail('customer-1', 'sub-1');
    }

    /**
     * A throttling or soft-error envelope served with a 200 is the realistic form of the
     * count-less body: perfectly readable, and it still cannot say how many seats exist.
     */
    public function test_a_readable_envelope_without_licence_counts_is_refused(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/no ReadonlySubscriptionDetails/');

        $this->clientReturning('{"Message":"Request limit exceeded"}')
            ->getSubscriptionDetail('customer-1', 'sub-1');
    }

    /**
     * Present-but-empty is count-less too — extractLicenseCounts() loops over nothing and
     * yields the same nulls.
     */
    public function test_an_empty_readonly_details_list_is_refused(): void
    {
        $this->expectException(AppRiverClientException::class);
        $this->expectExceptionMessageMatches('/no ReadonlySubscriptionDetails/');

        $this->clientReturning('{"SubscriptionKey":"sub-1","ReadonlySubscriptionDetails":[]}')
            ->getSubscriptionDetail('customer-1', 'sub-1');
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
